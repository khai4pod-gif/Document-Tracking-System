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

if ($documentId <= 0 || !in_array($action, ['archive', 'restore', 'complete'], true)) {
    json_response(['success' => false, 'message' => 'Invalid request parameters.'], 422);
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);
$userId = (int)current_user()['id'];

$existing = $documentModel->find($documentId);
if (!$existing) {
    json_response(['success' => false, 'message' => 'Document not found.'], 404);
}

$ok = match ($action) {
    'archive'  => $documentModel->archive($documentId, $userId),
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
