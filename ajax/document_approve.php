<?php
/**
 * ajax/document_approve.php
 * Approves or rejects a document that's awaiting sign-off (approval_status
 * = 'Pending'). Only admins and the 'approver' role may act here.
 * POST params: document_id, decision = approve|reject, remarks (optional)
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auto_routing.php';
require_role(['admin', 'approver']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$documentId = (int)($_POST['document_id'] ?? 0);
$decision   = (string)($_POST['decision'] ?? '');
$remarks    = trim((string)($_POST['remarks'] ?? ''));

if ($documentId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    json_response(['success' => false, 'message' => 'Invalid request parameters.'], 422);
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);

$doc = $documentModel->find($documentId);
if (!$doc) {
    json_response(['success' => false, 'message' => 'Document not found.'], 404);
}
if ($doc['approval_status'] !== 'Pending') {
    json_response(['success' => false, 'message' => 'This document is not awaiting approval.'], 422);
}

$decisionLabel = $decision === 'approve' ? 'Approved' : 'Rejected';
$ok = $documentModel->decideApproval($documentId, (int)current_user()['id'], $decisionLabel, $remarks ?: null);

if ($ok) {
    $message = "Document {$decisionLabel} successfully.";

    // The decision goes straight back to whoever raised the document —
    // the third and last automatic hop, with nothing to click.
    $back = autoRouteToCreator(
        $documentModel, $doc, $decisionLabel, (int)current_user()['id'], $pdo, $remarks
    );
    if ($back['routed']) {
        $message .= ' Returned to ' . $back['to'] . '.';
    } elseif ($back['reason'] !== null) {
        // It stays with the approver, and they are told why rather than
        // being left to assume the creator has it.
        $message .= ' It was not returned to the creator because ' . $back['reason'] . '.';
    }

    json_response(['success' => true, 'message' => $message]);
}

json_response(['success' => false, 'message' => 'Unable to record the decision. Please try again.'], 500);
