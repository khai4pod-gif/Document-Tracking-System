<?php
/**
 * ajax/distribution_status.php
 * Updates a distribution's status (e.g. Approved, Completed, Cancelled) —
 * typically used once the linked DTS manifest has been approved.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin', 'logistics', 'approver']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$id     = (int)($_POST['id'] ?? 0);
$status = (string)($_POST['status'] ?? '');

if ($id <= 0 || $status === '') {
    json_response(['success' => false, 'message' => 'Invalid request parameters.'], 422);
}

$pdo = Database::getConnection();
$relief = new Relief($pdo);

try {
    $ok = $relief->updateDistributionStatus($id, $status);
} catch (RuntimeException $e) {
    // Reinstating a cancelled distribution can fail if the stock it needs has
    // since gone out on another event — that is a normal outcome, not a fault.
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[DISTRIBUTION STATUS ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while updating the distribution.'], 500);
}

json_response([
    'success' => $ok,
    'message' => $ok ? 'Distribution status updated.' : 'Invalid status or distribution not found.',
]);
