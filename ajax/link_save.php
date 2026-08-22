<?php
/**
 * ajax/link_save.php
 * Attaches an additional cloud link (Drive/OneDrive/SharePoint/...) to an
 * existing document. The file equivalent is attachment_upload.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$documentId = (int)($_POST['document_id'] ?? 0);
if ($documentId <= 0) {
    json_response(['success' => false, 'message' => 'Invalid document reference.'], 422);
}

$link = sanitize_cloud_link((string)($_POST['url'] ?? ''));
if ($link === null) {
    json_response([
        'success' => false,
        'message' => 'Please enter a valid link starting with http:// or https://.',
    ], 422);
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);

$doc = $documentModel->find($documentId);
if (!$doc) {
    json_response(['success' => false, 'message' => 'Document not found.'], 404);
}
if (!$documentModel->isAccessibleTo($doc, current_user())) {
    json_response(['success' => false, 'message' => 'Access denied: this document belongs to another department.'], 403);
}
if ((int)$doc['is_archived'] === 1) {
    json_response(['success' => false, 'message' => 'Archived documents cannot be modified.'], 422);
}
if ($documentModel->countLinks($documentId) >= MAX_CLOUD_LINKS) {
    json_response([
        'success' => false,
        'message' => 'This document already has the maximum of ' . MAX_CLOUD_LINKS . ' cloud links.',
    ], 422);
}

try {
    $linkId = $documentModel->addLink($documentId, $link, (int)current_user()['id']);
    json_response(['success' => true, 'message' => 'Cloud link added.', 'link_id' => $linkId]);
} catch (Throwable $e) {
    error_log('[LINK SAVE ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while saving the link.'], 500);
}
