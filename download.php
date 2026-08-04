<?php
/**
 * download.php
 * Streams an attachment to an authenticated user. Files are never linked
 * to directly — every download passes through this authorization gate.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$attachmentId = (int)($_GET['attachment'] ?? 0);
if ($attachmentId <= 0) {
    http_response_code(400);
    die('Invalid attachment reference.');
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);
$attachment = $documentModel->findAttachment($attachmentId);

if (!$attachment || !is_file($attachment['file_path'])) {
    http_response_code(404);
    die('File not found.');
}

// Any authenticated user may download (shared records system).
// Tighten here with department/role checks if stricter access control is required.

$path = $attachment['file_path'];
$size = filesize($path);

header('Content-Description: File Transfer');
header('Content-Type: ' . $attachment['mime_type']);
header('Content-Disposition: attachment; filename="' . basename($attachment['original_name']) . '"');
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($path);
exit;
