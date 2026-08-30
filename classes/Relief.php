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

    // -----------------------------------------------------------------
    // STOCK LEDGER
    // Every change to a stock level is recorded here, so the inventory can
    // be audited and a balance read back for a past date. Callers own the
    // surrounding transaction.
    // -----------------------------------------------------------------

    public const MOVEMENT_TYPES = ['Opening', 'Receipt', 'Release', 'Return', 'Adjustment', 'Write-off'];

    /**
     * Writes one ledger row. $quantity is signed — positive brings stock in,
     * negative takes it out — and balance_after is read from the item so the
     * ledger records the position that actually resulted, rather than one
     * this method calculated separately and could get wrong.
     */
    private function recordStockMovement(
        int $inventoryId,
        string $type,
        int $quantity,
        ?int $userId = null,
        ?int $distributionId = null,
        ?string $remarks = null,
        ?string $reference = null
    ): void {
        $balance = $this->pdo->prepare("SELECT quantity_available FROM relief_inventory WHERE id = :id");
        $balance->execute(['id' => $inventoryId]);
        $after = (int)$balance->fetchColumn();

        $stmt = $this->pdo->prepare(
            "INSERT INTO relief_stock_movements
                (inventory_id, movement_type, quantity, balance_after,
                 distribution_id, reference, remarks, moved_by, moved_at)
             VALUES (:inv, :type, :qty, :after, :dist, :ref, :remarks, :user, NOW())"
        );
        $stmt->execute([
            'inv'     => $inventoryId,
            'type'    => $type,
            'qty'     => $quantity,
            'after'   => $after,
            'dist'    => $distributionId,
            'ref'     => $reference !== null ? mb_substr($reference, 0, 120) : null,
            'remarks' => $remarks !== null ? mb_substr($remarks, 0, 500) : null,
            'user'    => $userId,
        ]);
    }

    /** Ledger for one item, newest first — powers the inventory history modal. */
    public function listStockMovements(int $inventoryId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.*, u.full_name AS moved_by_name, d.reference_no AS distribution_ref
               FROM relief_stock_movements m
               LEFT JOIN users u ON u.id = m.moved_by
               LEFT JOIN distributions d ON d.id = m.distribution_id
              WHERE m.inventory_id = :inv
              ORDER BY m.moved_at DESC, m.id DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':inv', $inventoryId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * View E — opening balance, movement by type, and closing balance for
     * each item over the report period.
     *
     * Opening is the balance carried by the last movement before the period
     * starts; closing is the last one within it. Where an item has no
     * movement in either window the balance carries forward unchanged, so a
     * quiet item still reports its true position rather than zero.
     */
    public function reportItemMovement(array $filters): array
    {
        $from = $filters['date_from'] ?? null;
        $to   = $filters['date_to'] ?? null;

        $params = [];
        $windowStart = '';
        $windowEnd   = '';
        if ($from !== null) {
            $windowStart = ' AND m.moved_at >= :fromA';
            $params['fromA'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $windowEnd = ' AND m.moved_at <= :toA';
            $params['toA'] = $to . ' 23:59:59';
        }

        $itemWhere = [];
        if (!empty($filters['category'])) {
            $itemWhere[] = 'ri.category = :category';
            $params['category'] = $filters['category'];
        }
        if (!empty($filters['inventory_id'])) {
            $itemWhere[] = 'ri.id = :inventoryId';
            $params['inventoryId'] = (int)$filters['inventory_id'];
        }
        $itemWhere = $itemWhere ? 'WHERE ' . implode(' AND ', $itemWhere) : '';

        $sql = "SELECT ri.id, ri.item_name, ri.category, ri.unit,
                       ri.quantity_available, ri.reorder_level,
                       -- In and out cover every movement type, so
                       -- opening + in - out always equals closing. Splitting
                       -- by type alone would not: a Return or an Adjustment
                       -- moves the balance but is neither a receipt nor a
                       -- release, and the columns would silently not add up.
                       COALESCE(SUM(CASE WHEN m.quantity > 0 THEN m.quantity ELSE 0 END), 0) AS moved_in,
                       COALESCE(SUM(CASE WHEN m.quantity < 0 THEN -m.quantity ELSE 0 END), 0) AS moved_out,
                       -- Net of goods that actually left: releases less
                       -- anything handed back when a distribution was cancelled.
                       COALESCE(SUM(CASE WHEN m.movement_type IN ('Release','Return')
                                         THEN -m.quantity ELSE 0 END), 0) AS released,
                       COUNT(m.id) AS movements
                  FROM relief_inventory ri
                  LEFT JOIN relief_stock_movements m
                         ON m.inventory_id = ri.id {$windowStart}{$windowEnd}
                  {$itemWhere}
                 GROUP BY ri.id
                 ORDER BY ri.category, ri.item_name";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Opening balance: the position left by the last movement before the
        // window. Without a start bound there is no "before", so opening is
        // the item's first recorded balance.
        $openingStmt = $from !== null
            ? $this->pdo->prepare(
                "SELECT balance_after FROM relief_stock_movements
                  WHERE inventory_id = :inv AND moved_at < :start
                  ORDER BY moved_at DESC, id DESC LIMIT 1")
            : $this->pdo->prepare(
                "SELECT (balance_after - quantity) FROM relief_stock_movements
                  WHERE inventory_id = :inv
                  ORDER BY moved_at ASC, id ASC LIMIT 1");

        $closingStmt = $this->pdo->prepare(
            "SELECT balance_after FROM relief_stock_movements
              WHERE inventory_id = :inv" . ($to !== null ? " AND moved_at <= :end" : '') . "
              ORDER BY moved_at DESC, id DESC LIMIT 1"
        );

        // Distinguishes "no ledger at all" from "ledger starts after this
        // period", which need different closing balances.
        $everStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM relief_stock_movements WHERE inventory_id = :inv"
        );

        foreach ($rows as &$row) {
            $args = ['inv' => (int)$row['id']];
            if ($from !== null) {
                $args['start'] = $from . ' 00:00:00';
            }
            $openingStmt->execute($args);
            $opening = $openingStmt->fetchColumn();

            $closeArgs = ['inv' => (int)$row['id']];
            if ($to !== null) {
                $closeArgs['end'] = $to . ' 23:59:59';
            }
            $closingStmt->execute($closeArgs);
            $closing = $closingStmt->fetchColumn();

            if ($closing !== false) {
                $row['closing'] = (int)$closing;
            } else {
                // Nothing recorded up to the period end. Either the item has
                // no ledger at all — in which case its balance has never
                // changed and today's quantity is the answer — or every
                // movement it has came later, so at the end of this period it
                // did not yet hold anything.
                $everStmt->execute(['inv' => (int)$row['id']]);
                $row['closing'] = (int)$everStmt->fetchColumn() > 0 ? 0 : (int)$row['quantity_available'];
            }

            if ($opening !== false) {
                $row['opening'] = (int)$opening;
            } elseif ((int)$row['movements'] === 0) {
                // Nothing moved in the window either, so it opened where it
                // closed. Reporting zero here would make a quiet item look as
                // though it appeared from nowhere, and the row would not balance.
                $row['opening'] = (int)$row['closing'];
            } else {
                $row['opening'] = 0;
            }
        }
        unset($row);

        return $rows;
    }

    // -----------------------------------------------------------------
    // REPORTS
    // Four views over one filtered join. The WHERE clause is built once in
    // buildReportScope() so the views cannot drift on what a filter means.
    // -----------------------------------------------------------------

    public const REPORT_VIEWS       = ['distributions', 'goods', 'centres', 'trend', 'movement'];
    public const REPORT_GRANULARITY = ['day', 'week', 'month'];
    public const REPORT_PRESETS     = ['today', 'week', 'month', 'quarter', 'year', 'all', 'custom'];

    /**
     * Resolves a date preset to a concrete from/to pair. 'custom' passes the
     * caller's own dates through; 'all' removes the date bound entirely.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function resolveReportDates(string $preset, ?string $from, ?string $to): array
    {
        $valid = static fn(?string $d): ?string =>
            ($d !== null && $d !== '' && DateTime::createFromFormat('Y-m-d', $d)) ? $d : null;

        return match ($preset) {
            'today'   => [date('Y-m-d'), date('Y-m-d')],
            'week'    => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))],
            'month'   => [date('Y-m-01'), date('Y-m-t')],
            'quarter' => [
                date('Y-m-01', mktime(0, 0, 0, (intdiv((int)date('n') - 1, 3) * 3) + 1, 1)),
                date('Y-m-t',  mktime(0, 0, 0, (intdiv((int)date('n') - 1, 3) * 3) + 3, 1)),
            ],
            'year'    => [date('Y-01-01'), date('Y-12-31')],
            'custom'  => [$valid($from), $valid($to)],
            default   => [null, null],   // 'all'
        };
    }

    /**
     * Turns a filter set into WHERE fragments and bound parameters. Shared by
     * every report view, so a filter means the same thing in all of them.
     *
     * @return array{where: string, params: array<string,mixed>}
     */
    private function buildReportScope(array $f): array
    {
        $where  = [];
        $params = [];

        if (!empty($f['date_from'])) {
            $where[] = 'd.distribution_date >= :dateFrom';
            $params['dateFrom'] = $f['date_from'];
        }
        if (!empty($f['date_to'])) {
            $where[] = 'd.distribution_date <= :dateTo';
            $params['dateTo'] = $f['date_to'];
        }
        if (!empty($f['center_id'])) {
            $where[] = 'd.evacuation_center_id = :centerId';
            $params['centerId'] = (int)$f['center_id'];
        }
        if (!empty($f['target_area'])) {
            $where[] = 'c.target_area = :targetArea';
            $params['targetArea'] = $f['target_area'];
        }
        if (!empty($f['category'])) {
            $where[] = 'ri.category = :category';
            $params['category'] = $f['category'];
        }
        if (!empty($f['inventory_id'])) {
            $where[] = 'di.inventory_id = :inventoryId';
            $params['inventoryId'] = (int)$f['inventory_id'];
        }

        if (!empty($f['status'])) {
            $where[] = 'd.status = :status';
            $params['status'] = $f['status'];
        } else {
            // Cancelled events released nothing, and their stock has been
            // returned, so they are out unless explicitly asked for.
            $where[] = "d.status <> 'Cancelled'";
        }

        return ['where' => $where ? 'WHERE ' . implode(' AND ', $where) : '', 'params' => $params];
    }

    /** The joined source every report view groups differently. */
    private const REPORT_FROM = "
        FROM distributions d
        JOIN evacuation_centers c ON c.id = d.evacuation_center_id
        LEFT JOIN distribution_items di ON di.distribution_id = d.id
        LEFT JOIN relief_inventory ri ON ri.id = di.inventory_id";

    /** View A — one row per distribution event. */
    public function reportDistributions(array $filters): array
    {
        $scope = $this->buildReportScope($filters);
        $sql = "SELECT d.id, d.reference_no, d.distribution_date, d.status, d.remarks,
                       d.total_beneficiaries, c.name AS center_name, c.target_area,
                       u.full_name AS distributed_by_name,
                       COUNT(DISTINCT di.id) AS line_items,
                       COALESCE(SUM(di.quantity),0) AS units
                " . self::REPORT_FROM . "
                JOIN users u ON u.id = d.distributed_by
                {$scope['where']}
                GROUP BY d.id
                ORDER BY d.distribution_date DESC, d.id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($scope['params']);
        return $stmt->fetchAll();
    }

    /** View B — one row per released line item. */
    public function reportGoods(array $filters): array
    {
        $scope = $this->buildReportScope($filters);
        // An event with no line items has nothing to show in this view; the
        // LEFT JOIN would otherwise yield a row of nulls.
        $where = $scope['where'] === ''
            ? 'WHERE di.id IS NOT NULL'
            : $scope['where'] . ' AND di.id IS NOT NULL';

        $sql = "SELECT d.reference_no, d.distribution_date, d.status,
                       c.name AS center_name, c.target_area,
                       ri.item_name, ri.category, ri.unit,
                       di.quantity, d.total_beneficiaries
                " . self::REPORT_FROM . "
                {$where}
                ORDER BY d.distribution_date DESC, d.reference_no, ri.category, ri.item_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($scope['params']);
        return $stmt->fetchAll();
    }

    /**
     * View C — one row per evacuation centre.
     *
     * Beneficiaries are counted from distinct events, not from the joined
     * rows: an event with three line items would otherwise have its
     * beneficiary figure counted three times.
     */
    public function reportByCentre(array $filters): array
    {
        $scope = $this->buildReportScope($filters);

        $goods = $this->pdo->prepare(
            "SELECT c.id, c.name AS center_name, c.target_area, c.capacity,
                    COUNT(DISTINCT d.id) AS events,
                    COALESCE(SUM(di.quantity),0) AS units,
                    COUNT(DISTINCT ri.category) AS categories
             " . self::REPORT_FROM . "
             {$scope['where']}
             GROUP BY c.id"
        );
        $goods->execute($scope['params']);
        $rows = $goods->fetchAll();

        $people = $this->pdo->prepare(
            "SELECT t.center_id, COALESCE(SUM(t.total_beneficiaries),0) AS beneficiaries FROM (
                 SELECT DISTINCT d.id, d.evacuation_center_id AS center_id, d.total_beneficiaries
                 " . self::REPORT_FROM . "
                 {$scope['where']}
             ) t GROUP BY t.center_id"
        );
        $people->execute($scope['params']);
        $byCentre = array_column($people->fetchAll(), 'beneficiaries', 'center_id');

        foreach ($rows as &$row) {
            $row['beneficiaries'] = (int)($byCentre[$row['id']] ?? 0);
        }
        unset($row);

        usort($rows, static fn($a, $b) => (int)$b['units'] <=> (int)$a['units']
            ?: strcmp($a['center_name'], $b['center_name']));

        return $rows;
    }

    /** View D — totals per day, week or month. */
    public function reportTrend(array $filters): array
    {
        $granularity = in_array($filters['granularity'] ?? '', self::REPORT_GRANULARITY, true)
            ? $filters['granularity'] : 'month';

        $bucket = match ($granularity) {
            'day'  => "DATE_FORMAT(d.distribution_date, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(d.distribution_date, '%x-W%v')",
            default => "DATE_FORMAT(d.distribution_date, '%Y-%m')",
        };

        $scope = $this->buildReportScope($filters);
        $sql = "SELECT {$bucket} AS bucket,
                       COUNT(DISTINCT d.id) AS events,
                       COUNT(DISTINCT d.evacuation_center_id) AS centres,
                       COALESCE(SUM(di.quantity),0) AS units
                " . self::REPORT_FROM . "
                {$scope['where']}
                GROUP BY bucket
                ORDER BY bucket ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($scope['params']);
        return $stmt->fetchAll();
    }

    /** Beneficiary total for the filtered set, counted once per event. */
    public function reportBeneficiaries(array $filters): int
    {
        $scope = $this->buildReportScope($filters);
        $sql = "SELECT COALESCE(SUM(t.total_beneficiaries),0) FROM (
                    SELECT DISTINCT d.id, d.total_beneficiaries
                    " . self::REPORT_FROM . "
                    {$scope['where']}
                ) t";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($scope['params']);
        return (int)$stmt->fetchColumn();
    }

    /** Everything the filter bar needs to populate its dropdowns. */
    public function reportFilterOptions(): array
    {
        return [
            'centres'   => $this->pdo->query(
                "SELECT id, name, target_area FROM evacuation_centers ORDER BY name"
            )->fetchAll(),
            'areas'     => $this->pdo->query(
                "SELECT DISTINCT target_area FROM evacuation_centers ORDER BY target_area"
            )->fetchAll(PDO::FETCH_COLUMN),
            'categories'=> $this->pdo->query(
                "SELECT DISTINCT category FROM relief_inventory ORDER BY category"
            )->fetchAll(PDO::FETCH_COLUMN),
            'items'     => $this->pdo->query(
                "SELECT id, item_name, category, unit FROM relief_inventory ORDER BY category, item_name"
            )->fetchAll(),
        ];
    }

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

    public function createInventoryItem(array $data, ?int $userId = null): int
    {
        $this->pdo->beginTransaction();
        try {
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
            $id = (int)$this->pdo->lastInsertId();

            $this->recordStockMovement(
                $id, 'Opening', (int)$data['quantity_available'], $userId, null,
                'Item added to inventory.'
            );

            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Editing an item can change its stock level, which used to overwrite the
     * quantity with no record of who changed it or by how much. The difference
     * is now logged as an Adjustment; edits that leave the quantity alone add
     * no ledger row.
     */
    public function updateInventoryItem(int $id, array $data, ?int $userId = null): bool
    {
        $this->pdo->beginTransaction();
        try {
            $before = $this->pdo->prepare("SELECT quantity_available FROM relief_inventory WHERE id = :id FOR UPDATE");
            $before->execute(['id' => $id]);
            $previous = $before->fetchColumn();

            if ($previous === false) {
                $this->pdo->rollBack();
                return false;
            }

            $sql = "UPDATE relief_inventory SET
                        item_name = :name, category = :cat, unit = :unit,
                        quantity_available = :qty, reorder_level = :reorder, updated_at = NOW()
                    WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $ok = $stmt->execute([
                'name'    => $data['item_name'],
                'cat'     => $data['category'],
                'unit'    => $data['unit'],
                'qty'     => $data['quantity_available'],
                'reorder' => $data['reorder_level'],
                'id'      => $id,
            ]);

            $delta = (int)$data['quantity_available'] - (int)$previous;
            if ($ok && $delta !== 0) {
                $this->recordStockMovement(
                    $id,
                    $delta > 0 ? 'Receipt' : 'Adjustment',
                    $delta,
                    $userId,
                    null,
                    $delta > 0
                        ? 'Stock increased from ' . $previous . ' to ' . (int)$data['quantity_available'] . ' on the inventory screen.'
                        : 'Stock reduced from ' . $previous . ' to ' . (int)$data['quantity_available'] . ' on the inventory screen.',
                    $data['reference'] ?? null
                );
            }

            $this->pdo->commit();
            return $ok;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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

            // Needed by the manifest title and by the ledger remarks below,
            // so read once rather than only inside the manifest branch.
            $center = $this->pdo->prepare("SELECT name FROM evacuation_centers WHERE id = :id");
            $center->execute(['id' => $data['evacuation_center_id']]);
            $centerName = $center->fetchColumn() ?: 'Evacuation Center';

            $documentId = null;
            if ($generateManifest && $documentModel) {
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
                $this->recordStockMovement(
                    (int)$item['inventory_id'], 'Release', -$qty, $userId, $distributionId,
                    'Released to ' . $centerName, $referenceNo
                );
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
    public function updateDistributionStatus(int $id, string $status, ?int $userId = null): bool
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
    private function moveDistributionStock(int $distributionId, string $direction, ?int $userId = null): void
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

        $reference = $this->pdo->prepare("SELECT reference_no FROM distributions WHERE id = :id");
        $reference->execute(['id' => $distributionId]);
        $referenceNo = (string)$reference->fetchColumn();

        foreach ($lines as $line) {
            $qty = (int)$line['quantity'];
            $move->execute([
                'qty'  => $qty,
                'qty2' => $qty,
                'id'   => (int)$line['inventory_id'],
            ]);

            $this->recordStockMovement(
                (int)$line['inventory_id'],
                $direction === 'issue' ? 'Release' : 'Return',
                $direction === 'issue' ? -$qty : $qty,
                $userId,
                $distributionId,
                $direction === 'issue'
                    ? 'Distribution reinstated after cancellation.'
                    : 'Returned to stock — distribution cancelled.',
                $referenceNo
            );
        }
    }
}
