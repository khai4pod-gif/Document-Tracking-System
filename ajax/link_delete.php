<?php
/**
 * ajax/link_delete.php
 * Removes a cloud link from a document. The file equivalent is
 * attachment_delete.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$linkId = (int)($_POST['link_id'] ?? 0);
if ($linkId <= 0) {
    json_response(['success' => false, 'message' => 'Invalid link reference.'], 422);
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);

$link = $documentModel->findLink($linkId);
if (!$link) {
    json_response(['success' => false, 'message' => 'Link not found or could not be removed.'], 404);
}

$doc = $documentModel->find((int)$link['document_id']);
if (!$doc || !$documentModel->isAccessibleTo($doc, current_user())) {
    json_response(['success' => false, 'message' => 'Access denied: this document belongs to another department.'], 403);
}

$ok = $documentModel->deleteLink($linkId, (int)current_user()['id']);

if ($ok) {
    json_response(['success' => true, 'message' => 'Cloud link removed.']);
}

json_response(['success' => false, 'message' => 'Link not found or could not be removed.'], 404);
