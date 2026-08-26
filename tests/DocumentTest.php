<?php

declare(strict_types=1);

final class DocumentTest extends TestCase
{
    private Document $doc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doc = new Document($this->pdo());
    }

    private function baseDocData(array $overrides = []): array
    {
        return array_merge([
            'title'                => 'Sample Memo',
            'doc_type'              => 'Memo',
            'priority'              => 'Normal',
            'description'           => 'A test document.',
            'due_date'              => null,
            'origin_department_id'  => $this->deptA,
            'creator_role'          => 'admin',
        ], $overrides);
    }

    public function testCreateSetsApprovalNotRequiredForNonDepartmentCreator(): void
    {
        $result = $this->doc->create($this->baseDocData(['creator_role' => 'admin']), $this->admin);

        $row = $this->doc->find($result['id']);
        $this->assertSame('Not Required', $row['approval_status']);
        $this->assertSame('Draft', $row['status']);
    }

    public function testCreateSetsApprovalPendingForDepartmentCreator(): void
    {
        $result = $this->doc->create($this->baseDocData(['creator_role' => 'department']), $this->deptUserA);

        $row = $this->doc->find($result['id']);
        $this->assertSame('Pending', $row['approval_status']);
    }

    public function testCreateGeneratesSequentialTrackingNumbersPrefixedByDepartment(): void
    {
        // baseDocData() defaults origin_department_id to deptA, whose code
        // is RECORDS — tracking numbers are prefixed by that department's
        // code, not a fixed "DOC" prefix.
        $year = date('Y');
        $first = $this->doc->create($this->baseDocData(), $this->admin);
        $second = $this->doc->create($this->baseDocData(), $this->admin);

        $this->assertSame("RECORDS-{$year}-000001", $first['tracking_number']);
        $this->assertSame("RECORDS-{$year}-000002", $second['tracking_number']);
    }

    public function testCreateFallsBackToDocPrefixWithNoDepartment(): void
    {
        $year = date('Y');
        $result = $this->doc->create($this->baseDocData(['origin_department_id' => null]), $this->admin);

        $this->assertSame("DOC-{$year}-000001", $result['tracking_number']);
    }

    public function testGetStatusBreakdownIncludesEveryStatusAtZeroWithNoDocuments(): void
    {
        $counts = $this->doc->getStatusBreakdown();

        $this->assertSame([
            'Draft' => 0, 'Pending Routing' => 0, 'In Transit' => 0,
            'Received' => 0, 'Completed' => 0, 'Overdue' => 0,
        ], $counts);
    }

    public function testGetStatusBreakdownCountsByActualStatus(): void
    {
        $draft = $this->doc->create($this->baseDocData(), $this->admin);
        $this->doc->create($this->baseDocData(), $this->admin); // second Draft

        $routed = $this->doc->create($this->baseDocData(), $this->admin);
        $this->doc->route($routed['id'], [
            'to_user_id' => $this->deptUserA, 'from_department_id' => null,
            'to_department_id' => null, 'action_required' => 'Review', 'remarks' => null,
        ], $this->admin); // -> In Transit

        $completed = $this->doc->create($this->baseDocData(), $this->admin);
        $this->doc->markCompleted($completed['id'], $this->admin);

        $counts = $this->doc->getStatusBreakdown();

        $this->assertSame(2, $counts['Draft']);
        $this->assertSame(1, $counts['In Transit']);
        $this->assertSame(1, $counts['Completed']);
        $this->assertSame(0, $counts['Overdue']);
    }

    public function testGetStatusBreakdownScopesToCreatorAndExcludesArchived(): void
    {
        $mine = $this->doc->create($this->baseDocData(), $this->deptUserA);
        $this->doc->create($this->baseDocData(), $this->deptUserB); // someone else's

        $archived = $this->doc->create($this->baseDocData(), $this->deptUserA);
        $this->doc->archive($archived['id'], $this->deptUserA, 'Closed out.');

        $counts = $this->doc->getStatusBreakdown($this->deptUserA);

        // Only $mine counts: deptUserB's document is out of scope and the
        // archived one is excluded regardless of creator.
        $this->assertSame(1, $counts['Draft']);
        $this->assertSame(1, array_sum($counts));
    }

    /** Backdates a document's created_at directly — create() always stamps NOW(). */
    private function backdate(int $documentId, string $monthsAgo): void
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE documents SET created_at = DATE_SUB(NOW(), INTERVAL :m MONTH) WHERE id = :id"
        );
        $stmt->execute(['m' => $monthsAgo, 'id' => $documentId]);
    }

    public function testGetStatusTrendGroupsByMonthAndStatus(): void
    {
        $thisMonthDraft = $this->doc->create($this->baseDocData(), $this->admin);

        $lastMonthCompleted = $this->doc->create($this->baseDocData(), $this->admin);
        $this->doc->markCompleted($lastMonthCompleted['id'], $this->admin);
        $this->backdate((int)$lastMonthCompleted['id'], '1');

        $trend = $this->doc->getStatusTrend();

        $thisYm = date('Y-m');
        // Read back what MySQL actually stored rather than predicting it in
        // PHP — DATE_SUB and PHP's strtotime can disagree by a month near
        // month-end days (e.g. Jan 31 minus a month), so this stays correct
        // regardless of what day the suite happens to run on.
        $lastYm = $this->pdo()
            ->query("SELECT DATE_FORMAT(created_at, '%Y-%m') FROM documents WHERE id = {$lastMonthCompleted['id']}")
            ->fetchColumn();

        $this->assertSame([$lastYm, $thisYm], $trend['labels']);
        $this->assertSame(1, $trend['series']['Draft'][array_search($thisYm, $trend['labels'], true)]);
        $this->assertSame(0, $trend['series']['Draft'][array_search($lastYm, $trend['labels'], true)]);
        $this->assertSame(1, $trend['series']['Completed'][array_search($lastYm, $trend['labels'], true)]);
    }

    public function testGetStatusTrendExcludesMonthsOutsideTheWindow(): void
    {
        $old = $this->doc->create($this->baseDocData(), $this->admin);
        $this->backdate((int)$old['id'], '9'); // outside the default 6-month window

        $trend = $this->doc->getStatusTrend(null, 6);

        $this->assertNotContains(date('Y-m', strtotime('-9 months')), $trend['labels']);
        $this->assertSame(0, array_sum($trend['series']['Draft']));
    }

    public function testGetStatusTrendScopesToCreator(): void
    {
        $this->doc->create($this->baseDocData(), $this->deptUserA);
        $this->doc->create($this->baseDocData(), $this->deptUserB);

        $trend = $this->doc->getStatusTrend($this->deptUserA);

        $this->assertSame(1, array_sum($trend['series']['Draft']));
    }

    public function testGetPerformanceSummarySplitsForActionAndPendingReceipt(): void
    {
        $notYetAcknowledged = $this->doc->create($this->baseDocData(), $this->admin);
        $this->doc->route($notYetAcknowledged['id'], [
            'to_user_id' => $this->deptUserA, 'from_department_id' => null,
            'to_department_id' => null, 'action_required' => 'Review', 'remarks' => null,
        ], $this->admin); // -> In Transit, held by deptUserA

        $acknowledged = $this->doc->create($this->baseDocData(), $this->admin);
        $this->doc->route($acknowledged['id'], [
            'to_user_id' => $this->deptUserA, 'from_department_id' => null,
            'to_department_id' => null, 'action_required' => 'Review', 'remarks' => null,
        ], $this->admin);
        $routeId = $this->doc->getRoutes($acknowledged['id'])[0]['id'];
        $this->doc->receiveRoute((int)$routeId, $this->deptUserA); // -> Received

        $summary = $this->doc->getPerformanceSummary($this->deptUserA, 'as_of_today');

        $this->assertSame(2, $summary['assigned']['total']);
        $this->assertSame(1, $summary['assigned']['for_action']); // Received
        $this->assertSame(1, $summary['assigned']['pending_receipt']); // In Transit
    }

    public function testGetPerformanceSummaryBucketsBacklogDueTodayAndNoDeadline(): void
    {
        $overdue = $this->doc->create($this->baseDocData(['due_date' => date('Y-m-d', strtotime('-1 day'))]), $this->admin);
        $this->routeTo($overdue['id'], $this->deptUserA);

        $dueToday = $this->doc->create($this->baseDocData(['due_date' => date('Y-m-d')]), $this->admin);
        $this->routeTo($dueToday['id'], $this->deptUserA);

        $noDeadline = $this->doc->create($this->baseDocData(['due_date' => null]), $this->admin);
        $this->routeTo($noDeadline['id'], $this->deptUserA);

        $summary = $this->doc->getPerformanceSummary($this->deptUserA, 'as_of_today');

        $this->assertSame(3, $summary['active']['total']);
        $this->assertSame(1, $summary['active']['backlog']);
        $this->assertSame(1, $summary['active']['due_today']);
        $this->assertSame(1, $summary['active']['no_deadline']);
    }

    public function testGetPerformanceSummaryBucketsDueWithin3Days(): void
    {
        $soon = $this->doc->create($this->baseDocData(['due_date' => date('Y-m-d', strtotime('+2 days'))]), $this->admin);
        $this->routeTo($soon['id'], $this->deptUserA);

        // 'year' rather than 'as_of_today', which excludes future due dates by design.
        $summary = $this->doc->getPerformanceSummary($this->deptUserA, 'year');

        $this->assertSame(1, $summary['active']['due_3days']);
    }

    public function testGetPerformanceSummaryClassifiesComplianceAgainstCompletionLog(): void
    {
        $onTime = $this->doc->create($this->baseDocData(['due_date' => date('Y-m-d', strtotime('+1 day'))]), $this->deptUserA);
        $this->doc->markCompleted($onTime['id'], $this->deptUserA);

        $late = $this->doc->create($this->baseDocData(['due_date' => date('Y-m-d', strtotime('-1 day'))]), $this->deptUserA);
        $this->doc->markCompleted($late['id'], $this->deptUserA);

        $undated = $this->doc->create($this->baseDocData(['due_date' => null]), $this->deptUserA);
        $this->doc->markCompleted($undated['id'], $this->deptUserA);

        // 'year' rather than 'as_of_today', which would exclude $onTime's future due date.
        $summary = $this->doc->getPerformanceSummary($this->deptUserA, 'year');

        $this->assertSame(3, $summary['resolved']['total']);
        $this->assertSame(1, $summary['resolved']['compliant']);
        $this->assertSame(1, $summary['resolved']['non_compliant']);
        $this->assertSame(1, $summary['resolved']['exempt']);
        $this->assertSame(50.0, $summary['compliance_rate']);
    }

    public function testGetPerformanceSummaryExcludesOtherUsersAndArchivedDocuments(): void
    {
        $notMine = $this->doc->create($this->baseDocData(), $this->admin);
        $this->routeTo($notMine['id'], $this->deptUserB);

        $archived = $this->doc->create($this->baseDocData(), $this->deptUserA);
        $this->doc->archive($archived['id'], $this->deptUserA, 'Closed out.');

        $summary = $this->doc->getPerformanceSummary($this->deptUserA, 'as_of_today');

        $this->assertSame(0, $summary['assigned']['total']);
        $this->assertSame(0, $summary['resolved']['total']);
        $this->assertNull($summary['compliance_rate']);
    }

    /** Routes a document to $userId with placeholder required fields. */
    private function routeTo(int $documentId, int $userId): void
    {
        $this->doc->route($documentId, [
            'to_user_id' => $userId, 'from_department_id' => null,
            'to_department_id' => null, 'action_required' => 'Review', 'remarks' => null,
        ], $this->admin);
    }

    public function testCreateWritesAnAuditLogEntry(): void
    {
        $result = $this->doc->create($this->baseDocData(), $this->admin);
        $logs = $this->doc->getLogs($result['id']);

        $this->assertCount(1, $logs);
        $this->assertSame('Created', $logs[0]['action']);
    }

    public function testUpdateResetsRejectedApprovalBackToPending(): void
    {
        $result = $this->doc->create($this->baseDocData(['creator_role' => 'department']), $this->deptUserA);
        $this->doc->decideApproval($result['id'], $this->approver, 'Rejected', 'Missing signature.');

        $before = $this->doc->find($result['id']);
        $this->assertSame('Rejected', $before['approval_status']);

        $this->doc->update($result['id'], $this->baseDocData(['title' => 'Resubmitted Memo']), $this->deptUserA);

        $after = $this->doc->find($result['id']);
        $this->assertSame('Pending', $after['approval_status']);
        $this->assertNull($after['approved_by']);
        $this->assertNull($after['approved_at']);
        $this->assertSame('Resubmitted Memo', $after['title']);
    }

    public function testUpdateLeavesNonRejectedApprovalStatusAlone(): void
    {
        $result = $this->doc->create($this->baseDocData(['creator_role' => 'department']), $this->deptUserA);
        $this->doc->decideApproval($result['id'], $this->approver, 'Approved');

        $this->doc->update($result['id'], $this->baseDocData(['title' => 'Edited After Approval']), $this->deptUserA);

        $after = $this->doc->find($result['id']);
        $this->assertSame('Approved', $after['approval_status']);
        $this->assertNotNull($after['approved_by']);
    }

    public function testArchiveAndRestoreToggleTheFlagAndLog(): void
    {
        $result = $this->doc->create($this->baseDocData(), $this->admin);

        $this->assertTrue($this->doc->archive($result['id'], $this->admin, 'Closed out — filed with Records.'));
        $archived = $this->doc->find($result['id']);
        $this->assertSame(1, (int)$archived['is_archived']);
        $this->assertSame('Closed out — filed with Records.', $archived['conclusion_remarks']);

        $this->assertTrue($this->doc->restore($result['id'], $this->admin));
        $this->assertSame(0, (int)$this->doc->find($result['id'])['is_archived']);

        $actions = array_column($this->doc->getLogs($result['id']), 'action');
        $this->assertSame(['Created', 'Archived', 'Restored'], $actions);
    }

    public function testDecideApprovalOnlyAffectsPendingDocuments(): void
    {
        // Not Required -> decideApproval should be a no-op.
        $result = $this->doc->create($this->baseDocData(['creator_role' => 'admin']), $this->admin);
        $this->assertFalse($this->doc->decideApproval($result['id'], $this->approver, 'Approved'));
        $this->assertSame('Not Required', $this->doc->find($result['id'])['approval_status']);
    }

    public function testDecideApprovalRejectsInvalidDecisionValue(): void
    {
        $result = $this->doc->create($this->baseDocData(['creator_role' => 'department']), $this->deptUserA);
        $this->assertFalse($this->doc->decideApproval($result['id'], $this->approver, 'Maybe'));
        $this->assertSame('Pending', $this->doc->find($result['id'])['approval_status']);
    }

    public function testDecideApprovalCannotBeAppliedTwice(): void
    {
        $result = $this->doc->create($this->baseDocData(['creator_role' => 'department']), $this->deptUserA);
        $this->assertTrue($this->doc->decideApproval($result['id'], $this->approver, 'Approved'));
        // Second call: status is no longer 'Pending', so this must be rejected.
        $this->assertFalse($this->doc->decideApproval($result['id'], $this->approver, 'Rejected'));
        $this->assertSame('Approved', $this->doc->find($result['id'])['approval_status']);
    }

    public function testRouteIsAtomicAndUpdatesHolderAndNotifies(): void
    {
        $result = $this->doc->create($this->baseDocData(), $this->admin);

        $ok = $this->doc->route($result['id'], [
            'to_user_id'         => $this->deptUserA,
            'from_department_id' => $this->deptB,
            'to_department_id'   => $this->deptA,
            'action_required'    => 'Review and endorse',
            'remarks'            => null,
        ], $this->admin);

        $this->assertTrue($ok);

        $doc = $this->doc->find($result['id']);
        $this->assertSame('In Transit', $doc['status']);
        $this->assertSame($this->deptUserA, (int)$doc['current_holder_id']);

        $routes = $this->doc->getRoutes($result['id']);
        $this->assertCount(1, $routes);
        $this->assertSame('Pending', $routes[0]['status']);

        $notifCount = $this->pdo()
            ->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$this->deptUserA}")
            ->fetchColumn();
        $this->assertSame('1', (string)$notifCount);

        $actions = array_column($this->doc->getLogs($result['id']), 'action');
        $this->assertContains('Routed', $actions);
    }

    public function testRouteRollsBackEverythingWhenTheRecipientIsInvalid(): void
    {
        $result = $this->doc->create($this->baseDocData(), $this->admin);
        $before = $this->doc->find($result['id']);

        // No user with this id exists -> the FK on document_routes.to_user_id
        // must fail, and route() is expected to catch it and roll back.
        $nonexistentUserId = 999999;
        $ok = $this->doc->route($result['id'], [
            'to_user_id'         => $nonexistentUserId,
            'from_department_id' => null,
            'to_department_id'   => null,
            'action_required'    => 'Review',
            'remarks'            => null,
        ], $this->admin);

        $this->assertFalse($ok);

        $after = $this->doc->find($result['id']);
        $this->assertSame($before['status'], $after['status']);
        $this->assertSame($before['current_holder_id'], $after['current_holder_id']);
        $this->assertCount(0, $this->doc->getRoutes($result['id']));

        // The initial 'Created' log must still be the only entry — no
        // partial 'Routed' log left behind by the rolled-back attempt.
        $actions = array_column($this->doc->getLogs($result['id']), 'action');
        $this->assertSame(['Created'], $actions);
    }

    public function testReceiveRouteOnlyAllowsTheIntendedRecipient(): void
    {
        $result = $this->doc->create($this->baseDocData(), $this->admin);
        $this->doc->route($result['id'], [
            'to_user_id' => $this->deptUserA, 'from_department_id' => null,
            'to_department_id' => null, 'action_required' => 'Review', 'remarks' => null,
        ], $this->admin);
        $routeId = $this->doc->getRoutes($result['id'])[0]['id'];

        // Wrong recipient.
        $this->assertFalse($this->doc->receiveRoute((int)$routeId, $this->deptUserB));
        $this->assertSame('Pending', $this->doc->getRoutes($result['id'])[0]['status']);

        // Correct recipient.
        $this->assertTrue($this->doc->receiveRoute((int)$routeId, $this->deptUserA));
        $this->assertSame('Received', $this->doc->getRoutes($result['id'])[0]['status']);
        $this->assertSame('Received', $this->doc->find($result['id'])['status']);
    }

    public function testIsAccessibleToAdminLogisticsAndApproverAlwaysTrue(): void
    {
        $result = $this->doc->create($this->baseDocData(['origin_department_id' => $this->deptA]), $this->admin);
        $doc = $this->doc->find($result['id']);

        foreach ([$this->admin, $this->logistics, $this->approver] as $userId) {
            $user = $this->fetchUser($userId);
            $this->assertTrue($this->doc->isAccessibleTo($doc, $user));
        }
    }

    public function testIsAccessibleToSharedDocTypeIsAlwaysReadable(): void
    {
        $result = $this->doc->create($this->baseDocData([
            'origin_department_id' => $this->deptA,
            'doc_type' => 'Relief Manifest',
        ]), $this->admin);
        $doc = $this->doc->find($result['id']);

        // deptUserB belongs to an unrelated department, but Relief
        // Manifests are exempt from department scoping.
        $user = $this->fetchUser($this->deptUserB);
        $this->assertTrue($this->doc->isAccessibleTo($doc, $user));
    }

    public function testIsAccessibleToDepartmentUserSeesTheirOwnOriginDocument(): void
    {
        $result = $this->doc->create($this->baseDocData(['origin_department_id' => $this->deptA]), $this->admin);
        $doc = $this->doc->find($result['id']);

        $user = $this->fetchUser($this->deptUserA);
        $this->assertTrue($this->doc->isAccessibleTo($doc, $user));
    }

    public function testIsAccessibleToDepartmentUserIsDeniedForUnrelatedDocument(): void
    {
        $result = $this->doc->create($this->baseDocData(['origin_department_id' => $this->deptA]), $this->admin);
        $doc = $this->doc->find($result['id']);

        // deptUserB's department never originated, held, or routed this
        // document — access must be denied.
        $user = $this->fetchUser($this->deptUserB);
        $this->assertFalse($this->doc->isAccessibleTo($doc, $user));
    }

    public function testIsAccessibleToDepartmentUserSeesDocumentRoutedThroughTheirDepartment(): void
    {
        $result = $this->doc->create($this->baseDocData(['origin_department_id' => $this->deptA]), $this->admin);
        $this->doc->route($result['id'], [
            'to_user_id' => $this->deptUserB, 'from_department_id' => $this->deptA,
            'to_department_id' => $this->deptB, 'action_required' => 'Review', 'remarks' => null,
        ], $this->admin);

        $doc = $this->doc->find($result['id']);
        $user = $this->fetchUser($this->deptUserB);
        $this->assertTrue($this->doc->isAccessibleTo($doc, $user));
    }

    private function fetchUser(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }
}
