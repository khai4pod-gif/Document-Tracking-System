<?php

declare(strict_types=1);

final class ReliefTest extends TestCase
{
    private Relief $relief;
    private Document $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relief = new Relief($this->pdo());
        $this->doc = new Document($this->pdo());
    }

    private function baseDistributionData(array $overrides = []): array
    {
        return array_merge([
            'evacuation_center_id' => $this->center,
            'distribution_date'    => date('Y-m-d'),
            'total_beneficiaries'  => 50,
            'remarks'              => null,
            'items'                => [
                ['inventory_id' => $this->itemFood, 'quantity' => 10],
            ],
        ], $overrides);
    }

    private function stock(int $inventoryId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT quantity_available, quantity_distributed FROM relief_inventory WHERE id = :id'
        );
        $stmt->execute(['id' => $inventoryId]);
        return $stmt->fetch();
    }

    /** Ledger rows for one item, oldest first — the order they were written in. */
    private function movements(int $inventoryId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM relief_stock_movements WHERE inventory_id = :id ORDER BY id ASC'
        );
        $stmt->execute(['id' => $inventoryId]);
        return $stmt->fetchAll();
    }

    private function baseInventoryData(array $overrides = []): array
    {
        return array_merge([
            'item_name'          => 'Emergency Blankets',
            'category'           => 'Shelter',
            'unit'               => 'piece',
            'quantity_available' => 200,
            'reorder_level'      => 20,
        ], $overrides);
    }

    public function testCreateDistributionDeductsStockAndRecordsLineItems(): void
    {
        $before = $this->stock($this->itemFood);

        $result = $this->relief->createDistribution(
            $this->baseDistributionData(), $this->logistics, false, null
        );

        $after = $this->stock($this->itemFood);
        $this->assertSame((int)$before['quantity_available'] - 10, (int)$after['quantity_available']);
        $this->assertSame((int)$before['quantity_distributed'] + 10, (int)$after['quantity_distributed']);

        $dist = $this->relief->findDistribution($result['id']);
        $this->assertCount(1, $dist['items']);
        $this->assertSame(10, (int)$dist['items'][0]['quantity']);
        $this->assertSame('Completed', $dist['status']);
        $this->assertNull($dist['document_id']);
    }

    public function testCreateDistributionThrowsAndRollsBackOnInsufficientStock(): void
    {
        $before = $this->stock($this->itemFood);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        try {
            $this->relief->createDistribution(
                $this->baseDistributionData(['items' => [
                    ['inventory_id' => $this->itemFood, 'quantity' => 999],
                ]]),
                $this->logistics, false, null
            );
        } finally {
            $after = $this->stock($this->itemFood);
            $this->assertSame($before, $after, 'Stock must be untouched after a rolled-back failure.');
            $this->assertCount(0, $this->relief->listDistributions());
        }
    }

    public function testCreateDistributionThrowsWhenNoItemsGiven(): void
    {
        $this->expectException(RuntimeException::class);
        $this->relief->createDistribution(
            $this->baseDistributionData(['items' => []]), $this->logistics, false, null
        );
    }

    public function testCreateDistributionWithManifestLinksAPendingApprovalDocument(): void
    {
        $result = $this->relief->createDistribution(
            $this->baseDistributionData(), $this->logistics, true, $this->doc
        );

        $dist = $this->relief->findDistribution($result['id']);
        $this->assertSame('Pending Approval', $dist['status']);
        $this->assertNotNull($dist['document_id']);

        $linkedDoc = $this->doc->find((int)$dist['document_id']);
        $this->assertSame('Relief Manifest', $linkedDoc['doc_type']);
        $this->assertSame('High', $linkedDoc['priority']);
    }

    public function testCreateDistributionWithoutManifestHasNoLinkedDocumentAndIsCompleted(): void
    {
        $result = $this->relief->createDistribution(
            $this->baseDistributionData(), $this->logistics, false, null
        );

        $dist = $this->relief->findDistribution($result['id']);
        $this->assertSame('Completed', $dist['status']);
        $this->assertNull($dist['document_id']);
    }

    public function testCreateDistributionSkipsZeroQuantityLineItems(): void
    {
        $result = $this->relief->createDistribution(
            $this->baseDistributionData(['items' => [
                ['inventory_id' => $this->itemFood, 'quantity' => 10],
                ['inventory_id' => $this->itemWater, 'quantity' => 0],
            ]]),
            $this->logistics, false, null
        );

        $dist = $this->relief->findDistribution($result['id']);
        $this->assertCount(1, $dist['items']);
        $this->assertSame($this->itemFood, (int)$dist['items'][0]['inventory_id']);

        // The zero-quantity item must be untouched.
        $water = $this->stock($this->itemWater);
        $this->assertSame(50, (int)$water['quantity_available']);
    }

    /**
     * The up-front availability check validates each line item against the
     * current stock independently, without tracking a running total across
     * items in the same request. Two line items for the same product that
     * each individually pass, but jointly exceed stock, are not caught by
     * that check — this test characterizes what actually happens instead
     * (MySQL's strict mode rejects the resulting negative UNSIGNED update),
     * so a regression here would surface as this test failing rather than
     * as silent stock corruption in production.
     */
    public function testCreateDistributionRejectsDuplicateLineItemsThatJointlyExceedStock(): void
    {
        $before = $this->stock($this->itemFood); // 100 available

        $threw = false;
        try {
            $this->relief->createDistribution(
                $this->baseDistributionData(['items' => [
                    ['inventory_id' => $this->itemFood, 'quantity' => 60],
                    ['inventory_id' => $this->itemFood, 'quantity' => 60],
                ]]),
                $this->logistics, false, null
            );
        } catch (Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Combined 120 units against 100 in stock must not silently succeed.');
        $after = $this->stock($this->itemFood);
        $this->assertSame($before, $after, 'A failed request must not partially deduct stock.');
    }

    public function testUpdateDistributionStatusRejectsUnknownValue(): void
    {
        $result = $this->relief->createDistribution($this->baseDistributionData(), $this->logistics, false, null);
        $this->assertFalse($this->relief->updateDistributionStatus($result['id'], 'Definitely Not A Status'));
        $this->assertSame('Completed', $this->relief->findDistribution($result['id'])['status']);
    }

    public function testUpdateDistributionStatusAcceptsAValidValue(): void
    {
        $result = $this->relief->createDistribution($this->baseDistributionData(), $this->logistics, true, $this->doc);
        $this->assertTrue($this->relief->updateDistributionStatus($result['id'], 'Approved'));
        $this->assertSame('Approved', $this->relief->findDistribution($result['id'])['status']);
    }

    public function testUpdateDistributionStatusCancellingReturnsStock(): void
    {
        $before = $this->stock($this->itemFood); // 100 available, 0 distributed
        $result = $this->relief->createDistribution(
            $this->baseDistributionData(['items' => [['inventory_id' => $this->itemFood, 'quantity' => 10]]]),
            $this->logistics, false, null
        );
        $this->assertSame(90, (int)$this->stock($this->itemFood)['quantity_available']);

        $this->assertTrue($this->relief->updateDistributionStatus($result['id'], 'Cancelled'));

        $this->assertSame($before, $this->stock($this->itemFood), 'Cancelling must credit the exact quantity back.');
    }

    public function testUpdateDistributionStatusCancellingTwiceIsANoOpForStock(): void
    {
        $result = $this->relief->createDistribution($this->baseDistributionData(), $this->logistics, false, null);
        $this->relief->updateDistributionStatus($result['id'], 'Cancelled');
        $afterFirstCancel = $this->stock($this->itemFood);

        // Already Cancelled -> Cancelled again must not double-credit the stock.
        $this->assertTrue($this->relief->updateDistributionStatus($result['id'], 'Cancelled'));

        $this->assertSame($afterFirstCancel, $this->stock($this->itemFood));
    }

    public function testUpdateDistributionStatusReinstatingDeductsStockAgain(): void
    {
        $result = $this->relief->createDistribution(
            $this->baseDistributionData(['items' => [['inventory_id' => $this->itemFood, 'quantity' => 10]]]),
            $this->logistics, false, null
        );
        $this->relief->updateDistributionStatus($result['id'], 'Cancelled');
        $this->assertSame(100, (int)$this->stock($this->itemFood)['quantity_available']);

        $this->assertTrue($this->relief->updateDistributionStatus($result['id'], 'Approved'));

        $this->assertSame(90, (int)$this->stock($this->itemFood)['quantity_available']);
        $this->assertSame(10, (int)$this->stock($this->itemFood)['quantity_distributed']);
    }

    public function testUpdateDistributionStatusBetweenTwoNonCancelledStatusesLeavesStockAlone(): void
    {
        $result = $this->relief->createDistribution($this->baseDistributionData(), $this->logistics, true, $this->doc);
        $afterCreate = $this->stock($this->itemFood);

        $this->relief->updateDistributionStatus($result['id'], 'Approved');
        $this->assertTrue($this->relief->updateDistributionStatus($result['id'], 'Completed'));

        $this->assertSame($afterCreate, $this->stock($this->itemFood));
    }

    public function testUpdateDistributionStatusRefusesToReinstateWithoutEnoughStock(): void
    {
        // Distribution A takes all 100 units, then gets cancelled (stock returns to 100).
        $distA = $this->relief->createDistribution(
            $this->baseDistributionData(['items' => [['inventory_id' => $this->itemFood, 'quantity' => 100]]]),
            $this->logistics, false, null
        );
        $this->relief->updateDistributionStatus($distA['id'], 'Cancelled');
        $this->assertSame(100, (int)$this->stock($this->itemFood)['quantity_available']);

        // That stock is then genuinely committed to distribution B.
        $this->relief->createDistribution(
            $this->baseDistributionData(['items' => [['inventory_id' => $this->itemFood, 'quantity' => 100]]]),
            $this->logistics, false, null
        );
        $this->assertSame(0, (int)$this->stock($this->itemFood)['quantity_available']);

        // Reinstating A now has nothing left to draw on.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot reinstate/');
        try {
            $this->relief->updateDistributionStatus($distA['id'], 'Approved');
        } finally {
            $this->assertSame('Cancelled', $this->relief->findDistribution($distA['id'])['status']);
            $this->assertSame(0, (int)$this->stock($this->itemFood)['quantity_available'], 'A refused reinstate must not partially deduct.');
        }
    }

    public function testCreateInventoryItemWritesAnOpeningLedgerEntry(): void
    {
        $id = $this->relief->createInventoryItem($this->baseInventoryData(['quantity_available' => 200]), $this->logistics);

        $rows = $this->movements($id);
        $this->assertCount(1, $rows);
        $this->assertSame('Opening', $rows[0]['movement_type']);
        $this->assertSame(200, (int)$rows[0]['quantity']);
        $this->assertSame(200, (int)$rows[0]['balance_after']);
    }

    public function testUpdateInventoryItemWritesReceiptWhenQuantityIncreases(): void
    {
        $id = $this->relief->createInventoryItem($this->baseInventoryData(['quantity_available' => 200]), $this->logistics);

        $this->assertTrue($this->relief->updateInventoryItem(
            $id, $this->baseInventoryData(['quantity_available' => 250]), $this->logistics
        ));

        $rows = $this->movements($id);
        $this->assertCount(2, $rows); // Opening, then Receipt
        $this->assertSame('Receipt', $rows[1]['movement_type']);
        $this->assertSame(50, (int)$rows[1]['quantity']);
        $this->assertSame(250, (int)$rows[1]['balance_after']);
    }

    public function testUpdateInventoryItemWritesAdjustmentWhenQuantityDecreases(): void
    {
        $id = $this->relief->createInventoryItem($this->baseInventoryData(['quantity_available' => 200]), $this->logistics);

        $this->relief->updateInventoryItem(
            $id, $this->baseInventoryData(['quantity_available' => 150]), $this->logistics
        );

        $rows = $this->movements($id);
        $this->assertCount(2, $rows);
        $this->assertSame('Adjustment', $rows[1]['movement_type']);
        $this->assertSame(-50, (int)$rows[1]['quantity']);
        $this->assertSame(150, (int)$rows[1]['balance_after']);
    }

    public function testUpdateInventoryItemWritesNoLedgerRowWhenQuantityIsUnchanged(): void
    {
        $id = $this->relief->createInventoryItem($this->baseInventoryData(['quantity_available' => 200]), $this->logistics);

        // Only the name changes; quantity_available is passed through as-is.
        $this->relief->updateInventoryItem(
            $id, $this->baseInventoryData(['quantity_available' => 200, 'item_name' => 'Renamed']), $this->logistics
        );

        $this->assertCount(1, $this->movements($id), 'An edit that leaves the quantity alone must add no ledger row.');
    }

    public function testUpdateInventoryItemReturnsFalseForANonexistentItem(): void
    {
        $this->assertFalse($this->relief->updateInventoryItem(999999, $this->baseInventoryData()));
    }

    public function testCreateDistributionWritesAReleaseEntryPerLineItem(): void
    {
        $result = $this->relief->createDistribution(
            $this->baseDistributionData(['items' => [
                ['inventory_id' => $this->itemFood, 'quantity' => 10],
                ['inventory_id' => $this->itemWater, 'quantity' => 5],
            ]]),
            $this->logistics, false, null
        );

        $foodRows = $this->movements($this->itemFood);
        $this->assertCount(1, $foodRows);
        $this->assertSame('Release', $foodRows[0]['movement_type']);
        $this->assertSame(-10, (int)$foodRows[0]['quantity']);
        $this->assertSame(90, (int)$foodRows[0]['balance_after']);
        $this->assertSame($result['id'], (int)$foodRows[0]['distribution_id']);

        $waterRows = $this->movements($this->itemWater);
        $this->assertCount(1, $waterRows);
        $this->assertSame(-5, (int)$waterRows[0]['quantity']);
        $this->assertSame(45, (int)$waterRows[0]['balance_after']);
    }

    public function testCancellingAndReinstatingWriteMatchingLedgerEntries(): void
    {
        $result = $this->relief->createDistribution(
            $this->baseDistributionData(['items' => [['inventory_id' => $this->itemFood, 'quantity' => 10]]]),
            $this->logistics, false, null
        );

        $this->relief->updateDistributionStatus($result['id'], 'Cancelled', $this->admin);
        $rows = $this->movements($this->itemFood);
        $this->assertCount(2, $rows); // Release, then Return
        $this->assertSame('Return', $rows[1]['movement_type']);
        $this->assertSame(10, (int)$rows[1]['quantity']);
        $this->assertSame(100, (int)$rows[1]['balance_after']);
        $this->assertSame($result['id'], (int)$rows[1]['distribution_id']);

        $this->relief->updateDistributionStatus($result['id'], 'Approved', $this->admin);
        $rows = $this->movements($this->itemFood);
        $this->assertCount(3, $rows); // + Release again
        $this->assertSame('Release', $rows[2]['movement_type']);
        $this->assertSame(-10, (int)$rows[2]['quantity']);
        $this->assertSame(90, (int)$rows[2]['balance_after']);
    }

    public function testRepeatingACancelledStatusWritesNoLedgerRow(): void
    {
        $result = $this->relief->createDistribution($this->baseDistributionData(), $this->logistics, false, null);
        $this->relief->updateDistributionStatus($result['id'], 'Cancelled', $this->admin);
        $countAfterFirstCancel = count($this->movements($this->itemFood));

        $this->relief->updateDistributionStatus($result['id'], 'Cancelled', $this->admin);

        $this->assertCount($countAfterFirstCancel, $this->movements($this->itemFood));
    }
}
