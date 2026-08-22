<?php
/**
 * ajax/document_route.php
 * Routes a document to another user/office with an action-required note.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$documentId    = (int)($_POST['document_id'] ?? 0);
$toUserId      = (int)($_POST['to_user_id'] ?? 0);
$actionRequired = trim((string)($_POST['action_required'] ?? ''));
$remarks       = trim((string)($_POST['remarks'] ?? ''));

if ($documentId <= 0 || $toUserId <= 0 || $actionRequired === '') {
    json_response(['success' => false, 'message' => 'Please select a recipient and the action required.'], 422);
}
if (!is_valid_route_action($actionRequired)) {
    json_response(['success' => false, 'message' => 'Please choose an action required from the list.'], 422);
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);
$fromUser = current_user();

if (!user_can_route($fromUser, $pdo)) {
    json_response(['success' => false, 'message' => 'Your account does not have permission to route documents. Contact an administrator.'], 403);
}

$doc = $documentModel->find($documentId);
if (!$doc) {
    json_response(['success' => false, 'message' => 'Document not found.'], 404);
}
if (!$documentModel->isAccessibleTo($doc, $fromUser)) {
    json_response(['success' => false, 'message' => 'Access denied: this document belongs to another department.'], 403);
}
if ((int)$doc['is_archived'] === 1) {
    json_response(['success' => false, 'message' => 'Archived documents cannot be routed.'], 422);
}

// Look up the destination user's department for routing metadata.
$stmt = $pdo->prepare("SELECT department_id FROM users WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $toUserId]);
$toUser = $stmt->fetch();
if (!$toUser) {
    json_response(['success' => false, 'message' => 'Selected recipient does not exist.'], 422);
}

$ok = $documentModel->route($documentId, [
    'to_user_id'          => $toUserId,
    'from_department_id'  => $fromUser['department_id'],
    'to_department_id'    => $toUser['department_id'],
    'action_required'     => $actionRequired,
    'remarks'             => $remarks,
], (int)$fromUser['id']);

if ($ok) {
    json_response(['success' => true, 'message' => 'Document routed successfully.']);
}

json_response(['success' => false, 'message' => 'Failed to route the document. Please try again.'], 500);
