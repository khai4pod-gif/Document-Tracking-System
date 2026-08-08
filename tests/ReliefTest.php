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
}
