<?php
/**
 * classes/Relief.php
 * Data access and business logic for the Disaster Relief Distribution
 * module: inventory, evacuation centers, distributions, and analytics.
 */

declare(strict_types=1);

class Relief
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ===============================================================
    // DASHBOARD / ANALYTICS
    // ===============================================================

    public function getStats(): array
    {
        $avail = $this->pdo->query(
            "SELECT COALESCE(SUM(quantity_available),0) FROM relief_inventory"
        )->fetchColumn();

        // Summed from the recorded line items, not from relief_inventory's
        // running quantity_distributed counter. The counter also carries any
        // opening balance loaded with the stock, which has no distribution
        // behind it — so the tile disagreed with the category chart beside it
        // and with the goods breakdown report. Every figure on the dashboard
        // now traces back to an actual distribution event, and cancelled
        // events drop out of all of them alike.
        $distributed = $this->pdo->query(
            "SELECT COALESCE(SUM(di.quantity),0)
               FROM distribution_items di
               JOIN distributions d ON d.id = di.distribution_id
              WHERE d.status <> 'Cancelled'"
        )->fetchColumn();

        $centers = $this->pdo->query(
            "SELECT COUNT(*) AS cnt FROM evacuation_centers WHERE is_active = 1"
        )->fetch();

        $beneficiaries = $this->pdo->query(
            "SELECT COALESCE(SUM(total_beneficiaries),0) AS total
             FROM distributions WHERE status != 'Cancelled'"
        )->fetch();

        return [
            'packs_available'   => (int)$avail,
            'packs_distributed' => (int)$distributed,
            'active_centers'    => (int)$centers['cnt'],
            'total_beneficiaries' => (int)$beneficiaries['total'],
        ];
    }

    /** Monthly distribution totals for the last N months (line chart). */
    public function getTrendData(int $months = 6): array
    {
        $sql = "SELECT DATE_FORMAT(distribution_date, '%Y-%m') AS ym,
                       SUM(total_beneficiaries) AS beneficiaries,
                       COUNT(*) AS runs
                FROM distributions
                WHERE status != 'Cancelled'
                  AND distribution_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
                GROUP BY ym
                ORDER BY ym ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':months', $months, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Goods breakdown by category (doughnut chart). */
    public function getCategoryBreakdown(): array
    {
        $sql = "SELECT ri.category, COALESCE(SUM(di.quantity),0) AS total_qty
                FROM relief_inventory ri
                LEFT JOIN distribution_items di ON di.inventory_id = ri.id
                LEFT JOIN distributions d ON d.id = di.distribution_id AND d.status != 'Cancelled'
                GROUP BY ri.category
                ORDER BY total_qty DESC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function getLowStockItems(): array
    {
        $sql = "SELECT * FROM relief_inventory WHERE quantity_available <= reorder_level ORDER BY quantity_available ASC";
        return $this->pdo->query($sql)->fetchAll();
    }

    /**
     * One row per distributed line item, joined back to its parent
     * distribution event and center — used by the Goods Breakdown
     * Report's "Distribution Events by Category" section.
     */
    public function getDistributionsByCategory(): array
    {
        $sql = "SELECT ri.category, ri.item_name, di.quantity,
                       dist.reference_no, dist.distribution_date, dist.total_beneficiaries,
                       ec.name AS center_name
                FROM distribution_items di
                JOIN distributions dist ON dist.id = di.distribution_id AND dist.status != 'Cancelled'
                JOIN relief_inventory ri ON ri.id = di.inventory_id
                JOIN evacuation_centers ec ON ec.id = dist.evacuation_center_id
                ORDER BY ri.category ASC, dist.distribution_date DESC";
        return $this->pdo->query($sql)->fetchAll();
    }

    // ===============================================================
    // INVENTORY
    // ===============================================================

    public function listInventory(): array
    {
        return $this->pdo->query("SELECT * FROM relief_inventory ORDER BY item_name ASC")->fetchAll();
    }

    public function createInventoryItem(array $data): int
    {
        $sql = "INSERT INTO relief_inventory (item_name, category, unit, quantity_available, reorder_level, created_at)
                VALUES (:name, :cat, :unit, :qty, :reorder, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'name'    => $data['item_name'],
            'cat'     => $data['category'],
            'unit'    => $data['unit'],
            'qty'     => $data['quantity_available'],
            'reorder' => $data['reorder_level'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateInventoryItem(int $id, array $data): bool
    {
        $sql = "UPDATE relief_inventory SET
                    item_name = :name, category = :cat, unit = :unit,
                    quantity_available = :qty, reorder_level = :reorder, updated_at = NOW()
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'name'    => $data['item_name'],
            'cat'     => $data['category'],
            'unit'    => $data['unit'],
            'qty'     => $data['quantity_available'],
            'reorder' => $data['reorder_level'],
            'id'      => $id,
        ]);
    }

    public function deleteInventoryItem(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM relief_inventory WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    // ===============================================================
    // EVACUATION CENTERS
    // ===============================================================

    public function listCenters(): array
    {
        return $this->pdo->query("SELECT * FROM evacuation_centers ORDER BY is_active DESC, name ASC")->fetchAll();
    }

    public function createCenter(array $data): int
    {
        $sql = "INSERT INTO evacuation_centers
                    (name, target_area, address, capacity, contact_person, contact_number, created_at)
                VALUES (:name, :area, :address, :capacity, :cp, :cn, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'name'     => $data['name'],
            'area'     => $data['target_area'],
            'address'  => $data['address'] ?: null,
            'capacity' => $data['capacity'] ?: null,
            'cp'       => $data['contact_person'] ?: null,
            'cn'       => $data['contact_number'] ?: null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateCenter(int $id, array $data): bool
    {
        $sql = "UPDATE evacuation_centers SET
                    name = :name, target_area = :area, address = :address,
                    capacity = :capacity, contact_person = :cp, contact_number = :cn
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'name'     => $data['name'],
            'area'     => $data['target_area'],
            'address'  => $data['address'] ?: null,
            'capacity' => $data['capacity'] ?: null,
            'cp'       => $data['contact_person'] ?: null,
            'cn'       => $data['contact_number'] ?: null,
            'id'       => $id,
        ]);
    }

    public function toggleCenterStatus(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE evacuation_centers SET is_active = NOT is_active WHERE id = :id"
        );
        return $stmt->execute(['id' => $id]);
    }

    // ===============================================================
    // DISTRIBUTIONS
    // ===============================================================

    public function listDistributions(): array
    {
        $sql = "SELECT dist.*, ec.name AS center_name, ec.target_area,
                       u.full_name AS distributed_by_name,
                       doc.tracking_number, doc.status AS document_status
                FROM distributions dist
                JOIN evacuation_centers ec ON ec.id = dist.evacuation_center_id
                JOIN users u ON u.id = dist.distributed_by
                LEFT JOIN documents doc ON doc.id = dist.document_id
                ORDER BY dist.distribution_date DESC, dist.id DESC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function findDistribution(int $id): ?array
    {
        $sql = "SELECT dist.*, ec.name AS center_name, ec.target_area,
                       u.full_name AS distributed_by_name,
                       doc.tracking_number, doc.status AS document_status
                FROM distributions dist
                JOIN evacuation_centers ec ON ec.id = dist.evacuation_center_id
                JOIN users u ON u.id = dist.distributed_by
                LEFT JOIN documents doc ON doc.id = dist.document_id
                WHERE dist.id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $itemsStmt = $this->pdo->prepare(
            "SELECT di.*, ri.item_name, ri.unit, ri.category
             FROM distribution_items di
             JOIN relief_inventory ri ON ri.id = di.inventory_id
             WHERE di.distribution_id = :id"
        );
        $itemsStmt->execute(['id' => $id]);
        $row['items'] = $itemsStmt->fetchAll();

        return $row;
    }

    /**
     * Creates a distribution event with line items, deducts stock,
     * and optionally generates + links an official Relief Manifest
     * document into the DTS for routing/approval.
     *
     * @param array $data ['evacuation_center_id','distribution_date','total_beneficiaries','remarks','items'=>[['inventory_id','quantity'],...]]
     */
    public function createDistribution(array $data, int $userId, bool $generateManifest, ?Document $documentModel): array
    {
        if (empty($data['items'])) {
            throw new RuntimeException('At least one relief item with a quantity is required.');
        }

        $this->pdo->beginTransaction();
        try {
            // Validate stock availability up front.
            foreach ($data['items'] as $item) {
                $stmt = $this->pdo->prepare("SELECT item_name, quantity_available FROM relief_inventory WHERE id = :id FOR UPDATE");
                $stmt->execute(['id' => $item['inventory_id']]);
                $inv = $stmt->fetch();
                if (!$inv) {
                    throw new RuntimeException('One of the selected inventory items no longer exists.');
                }
                if ((int)$inv['quantity_available'] < (int)$item['quantity']) {
                    throw new RuntimeException("Insufficient stock for \"{$inv['item_name']}\" — only {$inv['quantity_available']} available.");
                }
            }

            $referenceNo = generate_reference_number($this->pdo);

            $documentId = null;
            if ($generateManifest && $documentModel) {
                $center = $this->pdo->prepare("SELECT name FROM evacuation_centers WHERE id = :id");
                $center->execute(['id' => $data['evacuation_center_id']]);
                $centerName = $center->fetchColumn() ?: 'Evacuation Center';

                $manifestDoc = $documentModel->create([
                    'title'                => "Relief Distribution Manifest — {$referenceNo} ({$centerName})",
                    'doc_type'             => 'Relief Manifest',
                    'priority'             => 'High',
                    'description'          => "Relief distribution manifest for {$centerName} dated {$data['distribution_date']}. Beneficiaries: {$data['total_beneficiaries']}.",
                    'due_date'             => null,
                    'origin_department_id' => null,
                ], $userId);
                $documentId = $manifestDoc['id'];
            }

            $distStmt = $this->pdo->prepare(
                "INSERT INTO distributions
                    (reference_no, evacuation_center_id, document_id, distributed_by,
                     distribution_date, total_beneficiaries, status, remarks, created_at)
                 VALUES
                    (:ref, :center, :doc, :user, :date, :beneficiaries, :status, :remarks, NOW())"
            );
            $distStmt->execute([
                'ref'          => $referenceNo,
                'center'       => $data['evacuation_center_id'],
                'doc'          => $documentId,
                'user'         => $userId,
                'date'         => $data['distribution_date'],
                'beneficiaries'=> $data['total_beneficiaries'],
                'status'       => $generateManifest ? 'Pending Approval' : 'Completed',
                'remarks'      => $data['remarks'] ?: null,
            ]);
            $distributionId = (int)$this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                "INSERT INTO distribution_items (distribution_id, inventory_id, quantity) VALUES (:dist, :inv, :qty)"
            );
            $deductStmt = $this->pdo->prepare(
                "UPDATE relief_inventory
                 SET quantity_available = quantity_available - :qty,
                     quantity_distributed = quantity_distributed + :qty2
                 WHERE id = :id"
            );

            foreach ($data['items'] as $item) {
                $qty = (int)$item['quantity'];
                if ($qty <= 0) {
                    continue;
                }
                $itemStmt->execute(['dist' => $distributionId, 'inv' => $item['inventory_id'], 'qty' => $qty]);
                $deductStmt->execute(['qty' => $qty, 'qty2' => $qty, 'id' => $item['inventory_id']]);
            }

            $this->pdo->commit();
            return ['id' => $distributionId, 'reference_no' => $referenceNo, 'document_id' => $documentId];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Changes a distribution's status, moving stock with it.
     *
     * Cancelling used to update the status row alone: the quantities deducted
     * when the event was recorded stayed deducted, while every report dropped
     * the cancelled event from its totals — so inventory and reporting
     * disagreed permanently, and goods that were never released stayed
     * unavailable. Cancelling now returns the stock, and reinstating a
     * cancelled event takes it out again.
     *
     * Driven by the transition, not the target, so repeating a status is a
     * no-op for stock rather than returning the same goods twice.
     *
     * @throws RuntimeException if reinstating would oversell current stock.
     */
    public function updateDistributionStatus(int $id, string $status): bool
    {
        $valid = ['Draft', 'Pending Approval', 'Approved', 'Completed', 'Cancelled'];
        if (!in_array($status, $valid, true)) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            // Locked so two concurrent changes can't both decide stock moves.
            $stmt = $this->pdo->prepare("SELECT status FROM distributions WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $id]);
            $current = $stmt->fetchColumn();

            if ($current === false) {
                $this->pdo->rollBack();
                return false;
            }

            $wasCancelled = ($current === 'Cancelled');
            $nowCancelled = ($status === 'Cancelled');

            if (!$wasCancelled && $nowCancelled) {
                $this->moveDistributionStock($id, 'return');
            } elseif ($wasCancelled && !$nowCancelled) {
                $this->moveDistributionStock($id, 'issue');
            }

            $upd = $this->pdo->prepare(
                "UPDATE distributions SET status = :status, updated_at = NOW() WHERE id = :id"
            );
            $upd->execute(['status' => $status, 'id' => $id]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Applies a distribution's line items to inventory in either direction.
     * 'issue' deducts (the event is live), 'return' credits back (cancelled).
     * Caller owns the transaction.
     */
    private function moveDistributionStock(int $distributionId, string $direction): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT di.inventory_id, di.quantity, ri.item_name, ri.quantity_available
               FROM distribution_items di
               JOIN relief_inventory ri ON ri.id = di.inventory_id
              WHERE di.distribution_id = :id
              FOR UPDATE"
        );
        $stmt->execute(['id' => $distributionId]);
        $lines = $stmt->fetchAll();

        if ($direction === 'issue') {
            // Stock may have been released elsewhere while this sat cancelled.
            foreach ($lines as $line) {
                if ((int)$line['quantity_available'] < (int)$line['quantity']) {
                    throw new RuntimeException(
                        "Cannot reinstate this distribution — \"{$line['item_name']}\" needs "
                        . "{$line['quantity']} but only {$line['quantity_available']} remain in stock."
                    );
                }
            }
        }

        $sign = $direction === 'issue' ? '-' : '+';
        $inverse = $direction === 'issue' ? '+' : '-';

        $move = $this->pdo->prepare(
            "UPDATE relief_inventory
                SET quantity_available   = quantity_available {$sign} :qty,
                    quantity_distributed = quantity_distributed {$inverse} :qty2,
                    updated_at = NOW()
              WHERE id = :id"
        );

        foreach ($lines as $line) {
            $move->execute([
                'qty'  => (int)$line['quantity'],
                'qty2' => (int)$line['quantity'],
                'id'   => (int)$line['inventory_id'],
            ]);
        }
    }
}
