<?php
/**
 * ajax/document_save.php
 * Handles both CREATE and UPDATE of a document (multipart/form-data,
 * since creation may include an initial file attachment).
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);
$user = current_user();

$documentId = (int)($_POST['document_id'] ?? 0);
$title      = trim((string)($_POST['title'] ?? ''));
$docType    = (string)($_POST['doc_type'] ?? 'Other');
$priority   = (string)($_POST['priority'] ?? 'Normal');
$description = trim((string)($_POST['description'] ?? ''));
$dueDate    = trim((string)($_POST['due_date'] ?? ''));

$validTypes     = ['Memo', 'Letter', 'Report', 'Purchase Request', 'Relief Manifest', 'Special Order', 'ORs/DV', 'PPMP', 'Purchase Order', 'Leave Application', 'Other'];
$validPriorities = ['Low', 'Normal', 'High', 'Urgent'];

$errors = [];
if ($title === '' || mb_strlen($title) > 255) {
    $errors[] = 'Title is required and must be under 255 characters.';
}
if (!in_array($docType, $validTypes, true)) {
    $errors[] = 'Invalid document type selected.';
}
if (!in_array($priority, $validPriorities, true)) {
    $errors[] = 'Invalid priority level selected.';
}
if ($dueDate !== '' && !DateTime::createFromFormat('Y-m-d', $dueDate)) {
    $errors[] = 'Invalid due date format.';
}

if (!empty($errors)) {
    json_response(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$data = [
    'title'                 => $title,
    'doc_type'              => $docType,
    'priority'              => $priority,
    'description'           => $description,
    'due_date'              => $dueDate ?: null,
    'origin_department_id'  => $user['department_id'],
    'creator_role'          => $user['role'],
];

try {
    if ($documentId > 0) {
        // ---- UPDATE ----
        $existing = $documentModel->find($documentId);
        if (!$existing) {
            json_response(['success' => false, 'message' => 'Document not found.'], 404);
        }
        if (!$documentModel->isAccessibleTo($existing, $user)) {
            json_response(['success' => false, 'message' => 'Access denied: this document belongs to another department.'], 403);
        }
        $ok = $documentModel->update($documentId, $data, (int)$user['id']);
        if (!$ok) {
            json_response(['success' => false, 'message' => 'Unable to update the document.'], 500);
        }
        json_response(['success' => true, 'message' => 'Document updated successfully.', 'id' => $documentId]);
    }

    // ---- CREATE ----
    $result = $documentModel->create($data, (int)$user['id']);
    $newId = $result['id'];

    // Optional initial attachment
    if (!empty($_FILES['attachment']['name'])) {
        try {
            $uploader = new FileUploader(UPLOAD_DIR);
            $fileMeta = $uploader->upload($_FILES['attachment']);
            $documentModel->addAttachment($newId, $fileMeta, (int)$user['id']);
        } catch (RuntimeException $e) {
            // Document was created successfully; report the attachment issue separately.
            json_response([
                'success' => true,
                'message' => 'Document created (tracking #' . $result['tracking_number'] . '), but the attachment failed: ' . $e->getMessage(),
                'id' => $newId,
            ]);
        }
    }

    json_response([
        'success' => true,
        'message' => 'Document created — tracking number ' . $result['tracking_number'],
        'id' => $newId,
    ]);
} catch (Throwable $e) {
    error_log('[DOCUMENT SAVE ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while saving the document.'], 500);
}
