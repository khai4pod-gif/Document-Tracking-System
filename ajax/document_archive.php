<?php
/**
 * ajax/document_archive.php
 * Handles soft-delete (archive), restore, and mark-completed state changes.
 * POST params: document_id, action = archive|restore|complete
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$documentId = (int)($_POST['document_id'] ?? 0);
$action     = (string)($_POST['action'] ?? '');
$conclusionRemarks = trim((string)($_POST['conclusion_remarks'] ?? ''));

if ($documentId <= 0 || !in_array($action, ['archive', 'restore', 'complete'], true)) {
    json_response(['success' => false, 'message' => 'Invalid request parameters.'], 422);
}

// Archiving closes a document out, so the reason is mandatory. The dialog
// enforces this too; this is the check that actually counts.
if ($action === 'archive') {
    if ($conclusionRemarks === '') {
        json_response([
            'success' => false,
            'message' => 'Conclusion remarks are required — say what your office did and why you are closing this document.',
        ], 422);
    }
    if (mb_strlen($conclusionRemarks) > 500) {
        json_response([
            'success' => false,
            'message' => 'Conclusion remarks must be 500 characters or fewer.',
        ], 422);
    }
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);
$userId = (int)current_user()['id'];

$existing = $documentModel->find($documentId);
if (!$existing) {
    json_response(['success' => false, 'message' => 'Document not found.'], 404);
}
if (!$documentModel->isAccessibleTo($existing, current_user())) {
    json_response(['success' => false, 'message' => 'Access denied: this document belongs to another department.'], 403);
}

// Approval is the checkpoint at the END of the workflow: a document may be
// routed freely, but cannot be closed out until an approver has signed off.
if ($action === 'complete' && in_array($existing['approval_status'], ['Pending', 'Rejected'], true)) {
    $reason = $existing['approval_status'] === 'Pending'
        ? 'This document is still awaiting approval and cannot be marked completed yet.'
        : 'This document was rejected. It must be revised and approved before it can be marked completed.';
    json_response(['success' => false, 'message' => $reason], 422);
}

$ok = match ($action) {
    'archive'  => $documentModel->archive($documentId, $userId, $conclusionRemarks),
    'restore'  => $documentModel->restore($documentId, $userId),
    'complete' => $documentModel->markCompleted($documentId, $userId),
};

$messages = [
    'archive'  => 'Document archived successfully.',
    'restore'  => 'Document restored successfully.',
    'complete' => 'Document marked as completed.',
];

if ($ok) {
    json_response(['success' => true, 'message' => $messages[$action]]);
}

json_response(['success' => false, 'message' => 'The action could not be completed.'], 500);
