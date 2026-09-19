<?php
/**
 * ajax/document_save.php
 * Handles both CREATE and UPDATE of a document (multipart/form-data,
 * since creation may include an initial file attachment).
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auto_routing.php';
require_once __DIR__ . '/../includes/text_extract.php';
require_once __DIR__ . '/../classes/DocumentClassifier.php';
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
    $fileText   = '';
    $textSource = 'fields';
    if (!empty($_FILES['attachment']['name'])) {
        try {
            $uploader = new FileUploader(UPLOAD_DIR);
            $fileMeta = $uploader->upload($_FILES['attachment']);
            $documentModel->addAttachment($newId, $fileMeta, (int)$user['id']);

            // Read the file so it can be classified. A failure here must not
            // cost the user their upload: the document and its attachment
            // are already saved, so the classifier simply falls back to the
            // typed fields and says so.
            $extracted = extractDocumentText($fileMeta['file_path'], $fileMeta['mime_type']);
            if ($extracted['text'] !== '') {
                $fileText   = $extracted['text'];
                $textSource = $extracted['source'];
            } elseif ($extracted['source'] === 'scanned') {
                // Recorded as a scan rather than as 'fields': the corpus
                // should not hold a row whose "document text" is really
                // just the title typed above it.
                $textSource = 'scanned';
                $notes[] = 'the file is a scan with no text in it, so the type was judged from the title alone — please check it';
            } elseif ($extracted['error'] !== null) {
                $notes[] = 'the uploaded file could not be read for classification ('
                         . $extracted['error'] . '), so the type was judged from the title and description';
            }
        } catch (RuntimeException $e) {
            $notes[] = 'the attachment failed (' . $e->getMessage() . ')';
        }
    }

    // Classify, and keep the text that produced the verdict. The stored
    // text is what a future model trains on, so it is written whether the
    // prediction is used or not.
    $classifyText = buildClassificationText($title, $description, $fileText);
    storeDocumentText($pdo, $newId, $classifyText, $textSource);
    $uploadName = (string)($_FILES['attachment']['name'] ?? '');

    $classifier = new RuleBasedClassifier();
    // The file and the title are read separately and then reconciled, so a
    // six-word title still counts for something against a fifteen thousand
    // character body — and so the two can be seen to disagree.
    $prediction = verifyDocumentType(
        $classifier,
        normaliseExtractedText($fileText),
        normaliseExtractedText(trim($title . ' ' . $description . ' ' . filenameEvidence($uploadName)))
    );

    if ($prediction['agreement'] === 'conflict') {
        // Nothing is filed on a contradiction. Say why, so the creator is
        // not left wondering which reading the system took.
        $notes[] = 'the document reads as ' . $prediction['document']['type']
                 . ' but the title reads as ' . $prediction['title']['type']
                 . ', so the type was left as you set it';
    }

    $pdo->prepare(
        "UPDATE documents
            SET detected_type = :type, detection_confidence = :conf,
                detection_source = :src, detected_at = NOW()
          WHERE id = :id"
    )->execute([
        'type' => $prediction['type'],
        'conf' => $prediction['confidence'],
        'src'  => $prediction['source'],
        'id'   => $newId,
    ]);

    // The prediction only becomes the document's type when it is confident
    // AND the creator did not override it. Below the threshold the typed
    // choice stands, which is why the form still submits one.
    if ($prediction['confidence'] >= RuleBasedClassifier::ACCEPT_THRESHOLD
        && empty($_POST['doc_type_overridden'])) {
        $pdo->prepare("UPDATE documents SET doc_type = :t WHERE id = :id")
            ->execute(['t' => $prediction['type'], 'id' => $newId]);
    }

    // Every new document goes to the Office of the Secretary by itself —
    // no recipient is chosen on the form. From there, acknowledging it
    // sends it on to the originating office's approver (see
    // includes/auto_routing.php and ajax/document_receive.php).
    // How the document itself travels onward. Anything unrecognised is
    // dropped rather than stored, so a tampered form leaves the hop
    // unrecorded instead of inventing a mode for it.
    $transmittalMode = (string)($_POST['transmittal_mode'] ?? DEFAULT_TRANSMITTAL_MODE);
    if (!in_array($transmittalMode, TRANSMITTAL_MODES, true)) {
        $transmittalMode = null;
    }

    $auto = autoRouteToSecretary($documentModel, $newId, $user, $pdo, $transmittalMode);
    $routedTo = $auto['routed'] ? $auto['to'] : null;
    if (!$auto['routed'] && $auto['reason'] !== null) {
        // The document still exists and is visible to its creator; it just
        // has not moved. Saying why beats leaving it looking filed.
        $notes[] = 'it was not sent to the Office of the Secretary because ' . $auto['reason'];
    }

    // The old "Route" block that let the creator pick a recipient has been
    // removed: the destination is no longer a choice, so accepting
    // route_to_user_id from a stale cached form would put the document
    // somewhere the automatic chain never looks for it.

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
