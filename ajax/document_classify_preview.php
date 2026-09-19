<?php
/**
 * ajax/document_classify_preview.php
 *
 * Reads a document the user has just attached — but has not yet saved —
 * and reports what type it looks like.
 *
 * This exists so the create form can show the answer before the user
 * commits, instead of telling them afterwards what the system decided on
 * their behalf. Nothing is written: the upload is read from PHP's
 * temporary file and left there for the request to clean up, and the real
 * classification still happens in document_save.php when the document is
 * actually created. A preview that disagreed with the saved result would
 * be worse than no preview, so both go through the same two functions.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/text_extract.php';
require_once __DIR__ . '/../classes/DocumentClassifier.php';
require_login();
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$title       = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));

$fileText = '';
$source   = 'fields';
$warning  = null;

if (!empty($_FILES['attachment']['name']) && (int)$_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['attachment'];

    // The same checks FileUploader makes, minus the move — the file is
    // only being read here, so it must not be promoted out of the temp
    // directory. Rejecting on the declared type would be trivially
    // bypassed, hence finfo on the contents.
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        json_response(['success' => false, 'message' => 'The file could not be uploaded for checking.'], 400);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        json_response(['success' => false, 'message' => 'Invalid upload.'], 400);
    }
    if ((int)$file['size'] > MAX_UPLOAD_BYTES) {
        json_response(['success' => false, 'message' => 'File is too large to check.'], 413);
    }

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!$realMime || !isset(ALLOWED_UPLOAD_MIMES[$realMime])) {
        json_response(['success' => false, 'message' => 'Only PDF and Word documents can be read.'], 415);
    }

    $extracted = extractDocumentText($file['tmp_name'], $realMime);
    if ($extracted['text'] !== '') {
        $fileText = $extracted['text'];
        $source   = $extracted['source'];
    } elseif ($extracted['source'] === 'scanned') {
        $source  = 'scanned';
        $warning = 'This file is a scan — there is no text inside it to read, so only the title was used. Please check the type.';
    } elseif ($extracted['source'] === 'unsupported') {
        // .doc has no reliable pure-PHP reader; say so rather than
        // silently judging the file by its title alone.
        $warning = 'This file format cannot be read, so the type was judged from the title and description only.';
    } elseif ($extracted['error'] !== null) {
        $warning = 'The file could not be read (' . $extracted['error'] . '), so the type was judged from the title and description only.';
    }
}

// Two readings, reconciled: the file on its own and the typed fields on
// their own. Classifying them as one blob lets the longer one drown the
// shorter, and hides the case worth surfacing — the two disagreeing.
    $uploadName = (string)($_FILES['attachment']['name'] ?? '');
$classifier = new RuleBasedClassifier();
$prediction = verifyDocumentType(
    $classifier,
    normaliseExtractedText($fileText),
    normaliseExtractedText(trim($title . ' ' . $description . ' ' . filenameEvidence($uploadName)))
);

$reading = static function (?array $v): ?array {
    return $v === null ? null : ['type' => $v['type'], 'confidence' => $v['confidence']];
};

json_response([
    'success'    => true,
    'type'       => $prediction['type'],
    'confidence' => $prediction['confidence'],
    'confident'  => $prediction['confidence'] >= RuleBasedClassifier::ACCEPT_THRESHOLD,
    'threshold'  => RuleBasedClassifier::ACCEPT_THRESHOLD,
    'reasons'    => $prediction['reasons'],
    'agreement'  => $prediction['agreement'],
    'document'   => $reading($prediction['document']),
    'title'      => $reading($prediction['title']),
    'read_from'  => $source,
    'chars'      => mb_strlen($fileText),
    'warning'    => $warning,
]);
