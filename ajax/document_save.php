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
        if ((int)$existing['created_by'] !== (int)$user['id']) {
            json_response(['success' => false, 'message' => 'Only the document\'s creator can edit it.'], 403);
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

    // Anything that went wrong *after* the document itself was created. The
    // document still exists, so these are reported as warnings on a successful
    // response rather than as a failure.
    $notes = [];

    // Optional cloud links from the "Cloud Link" rows on the create form.
    // Blank rows are expected (the form always seeds one) and are skipped
    // silently; only genuinely malformed entries are reported back.
    $submittedLinks = $_POST['cloud_links'] ?? [];
    if (is_array($submittedLinks)) {
        $savedLinks = 0;
        $rejectedLinks = 0;

        foreach ($submittedLinks as $rawLink) {
            if (!is_string($rawLink) || trim($rawLink) === '') {
                continue;
            }
            if ($savedLinks >= MAX_CLOUD_LINKS) {
                $rejectedLinks++;
                continue;
            }
            $link = sanitize_cloud_link($rawLink);
            if ($link === null) {
                $rejectedLinks++;
                continue;
            }
            $documentModel->addLink($newId, $link, (int)$user['id']);
            $savedLinks++;
        }

        if ($rejectedLinks > 0) {
            $notes[] = $rejectedLinks . ' cloud link(s) were skipped — only http/https addresses are accepted, up to '
                . MAX_CLOUD_LINKS . ' per document';
        }
    }

    // Optional initial attachment
    if (!empty($_FILES['attachment']['name'])) {
        try {
            $uploader = new FileUploader(UPLOAD_DIR);
            $fileMeta = $uploader->upload($_FILES['attachment']);
            $documentModel->addAttachment($newId, $fileMeta, (int)$user['id']);
        } catch (RuntimeException $e) {
            $notes[] = 'the attachment failed (' . $e->getMessage() . ')';
        }
    }

    // Optional immediate routing, driven by the "Route" block on the create form.
    $routedTo = null;
    $routeToUserId = (int)($_POST['route_to_user_id'] ?? 0);

    if ($routeToUserId > 0) {
        // Leaving the action dropdown alone falls back to the neutral default
        // rather than silently dropping the recipient choice; a value that
        // isn't on the list did not come from the form.
        $actionRequired = trim((string)($_POST['route_action_required'] ?? ''));
        if ($actionRequired === '') {
            $actionRequired = DEFAULT_ROUTE_ACTION;
        }

        $stmt = $pdo->prepare(
            "SELECT full_name, department_id FROM users WHERE id = :id AND is_active = 1 LIMIT 1"
        );
        $stmt->execute(['id' => $routeToUserId]);
        $recipient = $stmt->fetch();

        // Each guard explains itself; routing only happens once all of them pass.
        $skipReason = match (true) {
            !user_can_route($user, $pdo)
                => 'your account does not have permission to route documents',
            $routeToUserId === (int)$user['id']
                => 'a document cannot be routed to yourself',
            !$recipient
                => 'the selected recipient no longer exists or is inactive',
            !is_valid_route_action($actionRequired)
                => 'the action required was not one of the listed options',
            default => null,
        };

        if ($skipReason !== null) {
            $notes[] = 'routing was skipped because ' . $skipReason;
        } else {
            $routed = $documentModel->route($newId, [
                'to_user_id'         => $routeToUserId,
                'from_department_id' => $user['department_id'],
                'to_department_id'   => $recipient['department_id'],
                'action_required'    => $actionRequired,
                'remarks'            => mb_substr(trim((string)($_POST['route_remarks'] ?? '')), 0, 1000),
            ], (int)$user['id']);

            if ($routed) {
                $routedTo = $recipient['full_name'];
            } else {
                $notes[] = 'the document could not be routed — use the Route action to send it';
            }
        }
    }

    $message = 'Document created — tracking number ' . $result['tracking_number'];
    if ($routedTo !== null) {
        $message .= ', routed to ' . $routedTo;
    }
    if (!empty($notes)) {
        $message .= '. Note: ' . implode('; ', $notes);
    }

    json_response([
        'success' => true,
        'message' => $message,
        'id' => $newId,
    ]);
} catch (Throwable $e) {
    error_log('[DOCUMENT SAVE ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while saving the document.'], 500);
}
