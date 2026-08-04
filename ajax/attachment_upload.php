<?php
/**
 * ajax/attachment_upload.php
 * Uploads an additional PDF/Word attachment to an existing document.
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

$pdo = Database::getConnection();
$documentModel = new Document($pdo);

$doc = $documentModel->find($documentId);
if (!$doc) {
    json_response(['success' => false, 'message' => 'Document not found.'], 404);
}

if (empty($_FILES['attachment']['name'])) {
    json_response(['success' => false, 'message' => 'Please choose a file to upload.'], 422);
}

try {
    $uploader = new FileUploader(UPLOAD_DIR);
    $fileMeta = $uploader->upload($_FILES['attachment']);
    $attachmentId = $documentModel->addAttachment($documentId, $fileMeta, (int)current_user()['id']);

    json_response(['success' => true, 'message' => 'Attachment uploaded successfully.', 'attachment_id' => $attachmentId]);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[ATTACHMENT UPLOAD ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while uploading the file.'], 500);
}
