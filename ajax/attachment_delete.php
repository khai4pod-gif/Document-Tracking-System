<?php
/**
 * ajax/attachment_delete.php
 * Removes an attachment (file + database record) from a document.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$attachmentId = (int)($_POST['attachment_id'] ?? 0);
if ($attachmentId <= 0) {
    json_response(['success' => false, 'message' => 'Invalid attachment reference.'], 422);
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);

$ok = $documentModel->deleteAttachment($attachmentId, (int)current_user()['id']);

if ($ok) {
    json_response(['success' => true, 'message' => 'Attachment removed.']);
}

json_response(['success' => false, 'message' => 'Attachment not found or could not be removed.'], 404);
