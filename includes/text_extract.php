<?php
/**
 * includes/text_extract.php
 *
 * Pulls readable text out of an uploaded document so it can be
 * classified — and, just as importantly, stored as training data for the
 * model that will eventually replace the rules.
 *
 * PDF goes through smalot/pdfparser. That is not over-engineering: the
 * files this office actually uploads store their text as hex-encoded
 * glyph IDs against subset fonts with ToUnicode CMaps, and a hand-rolled
 * extractor returns literally zero characters from them. The library
 * reads the same file as 15,800 characters.
 *
 * DOCX needs no library — it is a zip holding word/document.xml — so it
 * is read directly, the same way the Excel importer reads a workbook.
 */

declare(strict_types=1);

/** Longest text kept per document. Far beyond what any classifier needs. */
const EXTRACT_MAX_CHARS = 200000;

/**
 * Below this many characters a PDF is treated as having no text layer
 * rather than very little text. Office copiers produce PDFs that parse
 * perfectly and contain nothing but a photograph of the page.
 */
const SCANNED_TEXT_FLOOR = 25;

/**
 * @return array{text:string,source:string,error:?string}
 *         source: 'pdf', 'docx', 'ocr', 'scanned', 'unsupported' or 'missing'
 */
function extractDocumentText(string $path, string $mimeType): array
{
    if (!is_file($path)) {
        return ['text' => '', 'source' => 'missing', 'error' => 'the file is not on disk'];
    }

    try {
        if ($mimeType === 'application/pdf') {
            $text = extractPdfText($path);

            // A PDF straight off an office copier is a photograph of a
            // page: it parses perfectly and holds no text whatsoever.
            // Letting that fall through as "read from the title" hides
            // the reason from the creator and leaves the corpus with a
            // row that looks like a document and contains nothing.
            if (mb_strlen(trim($text)) < SCANNED_TEXT_FLOOR) {
                // No text layer. If OCR is installed, read the page
                // images; if it is not, say so plainly rather than let
                // the title stand in for the document.
                $ocr = ocrPdfText($path);
                if ($ocr !== '') {
                    return ['text' => $ocr, 'source' => 'ocr', 'error' => null];
                }

                return [
                    'text'   => '',
                    'source' => 'scanned',
                    'error'  => ocrBinary() === null
                        ? 'the file has no text layer and OCR is not installed on this server'
                        : 'the file has no text layer and OCR could not read it',
                ];
            }

            return ['text' => $text, 'source' => 'pdf', 'error' => null];
        }
        if ($mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return ['text' => extractDocxText($path), 'source' => 'docx', 'error' => null];
        }
        // .doc (the old binary format) has no reliable pure-PHP reader.
        // Rather than emit mangled text that would poison the training
        // set, it is reported as unsupported and the typed fields carry
        // the classification on their own.
        return ['text' => '', 'source' => 'unsupported', 'error' => 'no text reader for ' . $mimeType];
    } catch (Throwable $e) {
        error_log('[TEXT EXTRACT] ' . $path . ' — ' . $e->getMessage());
        return ['text' => '', 'source' => 'error', 'error' => $e->getMessage()];
    }
}

function extractPdfText(string $path): string
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('vendor/autoload.php is missing — run composer install');
    }
    require_once $autoload;

    $parser = new \Smalot\PdfParser\Parser();
    $pdf    = $parser->parseFile($path);

    return normaliseExtractedText($pdf->getText());
}

/**
 * DOCX is a zip; the body lives in word/document.xml.
 *
 * Paragraph and tab tags become whitespace before the tags are stripped,
 * otherwise the last word of one paragraph runs into the first word of
 * the next and invents terms that were never in the document.
 */
function extractDocxText(string $path): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ext/zip is not enabled');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('the .docx could not be opened');
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        throw new RuntimeException('word/document.xml is missing from the .docx');
    }

    $xml = preg_replace('#<w:(p|br|tab|tr)\b[^>]*/?>#', ' ', $xml);
    $xml = str_replace(['</w:p>', '</w:tr>'], ' ', $xml);

    return normaliseExtractedText(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

/** Collapse whitespace, drop control characters, cap the length. */
function normaliseExtractedText(string $text): string
{
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);

    return mb_strlen($text) > EXTRACT_MAX_CHARS
        ? mb_substr($text, 0, EXTRACT_MAX_CHARS)
        : $text;
}

/**
 * Everything the classifier reads for one document: the typed fields
 * always, plus the file's text when there is a file.
 *
 * Both are included on purpose. Most documents in this system have no
 * attachment at all, so a classifier that only read files could not
 * classify them; and the title is often the clearest signal there is
 * ("Memorandum re: ..."), so it is worth reading even when a file exists.
 */
function buildClassificationText(string $title, string $description, string $fileText): string
{
    return normaliseExtractedText(trim($title . ' ' . $description . ' ' . $fileText));
}

/**
 * Saves the text used to classify a document. One row per document.
 */
function storeDocumentText(PDO $pdo, int $documentId, string $text, string $source): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO document_text (document_id, content, source, char_count, extracted_at)
         VALUES (:id, :content, :source, :chars, NOW())
         ON DUPLICATE KEY UPDATE
            content = VALUES(content), source = VALUES(source),
            char_count = VALUES(char_count), extracted_at = NOW()"
    );
    $stmt->execute([
        'id'      => $documentId,
        'content' => $text,
        'source'  => $source,
        'chars'   => mb_strlen($text),
    ]);
}

/**
 * Registry codes that appear as a segment of an office file name, and the
 * words they stand for. Expanded rather than mapped to a type directly so
 * that the ordinary rules do the judging: the name is evidence, not a
 * verdict — a memo transmitting a purchase request is coded MEM either way.
 */
const FILENAME_TYPE_CODES = [
    'mem'  => 'memorandum',
    'mc'   => 'memorandum circular',
    'so'   => 'special order',
    'to'   => 'travel order',
    'pr'   => 'purchase request',
    'po'   => 'purchase order',
    'dv'   => 'disbursement voucher',
    'or'   => 'official receipt',
    'ppmp' => 'project procurement management plan',
    'app'  => 'annual procurement plan',
    'rpt'  => 'report',
    'ltr'  => 'letter',
    'la'   => 'application for leave',
];

/**
 * Turns an upload's file name into text worth classifying.
 *
 * The office names files by convention — SB-AS-PSAMD-MEM-26-05-421838-S —
 * so it has already said what the document is before anyone opens it.
 * That matters most for a scan, where the name may be the only readable
 * text in the upload.
 *
 * Codes are only expanded inside a name that looks like a registry name:
 * several hyphenated segments, the code itself in capitals. Without that
 * guard "Report to Director.pdf" would read "to" as a travel order.
 */
function filenameEvidence(string $originalName): string
{
    $stem = pathinfo($originalName, PATHINFO_FILENAME);
    if ($stem === '') {
        return '';
    }

    $segments  = preg_split('/[^A-Za-z0-9]+/', $stem, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $isRegistry = substr_count($stem, '-') >= 2;

    $words = [];
    foreach ($segments as $segment) {
        // Serials and dates say nothing about the kind of document and
        // only dilute the little text a file name has.
        if (ctype_digit($segment)) {
            continue;
        }

        $lower   = mb_strtolower($segment);
        $words[] = $lower;

        if ($isRegistry && $segment === mb_strtoupper($segment) && isset(FILENAME_TYPE_CODES[$lower])) {
            $words[] = FILENAME_TYPE_CODES[$lower];
        }
    }

    return implode(' ', $words);
}

/* ===================== OCR for scanned uploads =====================
 *
 * A copier writes each page of a scan as a JPEG inside the PDF, so the
 * page images can be lifted out whole with no rasteriser — no
 * Ghostscript, no poppler, one dependency instead of two. Tesseract then
 * reads the image.
 *
 * Everything here degrades quietly: with Tesseract absent the scan is
 * reported as unreadable exactly as it was before, and the application
 * runs unchanged. That is deliberate — OCR is an improvement to
 * classification, never a condition of filing a document.
 */

/** Pages read per document. The type is decided on the first page; the
 *  rest is read for the corpus, and costs about a second each. */
const OCR_MAX_PAGES = 3;

/** Below this width an embedded image is a logo or a signature, not a page. */
const OCR_MIN_IMAGE_WIDTH = 600;

/** Tesseract language data to use. 'eng' covers English and, in practice,
 *  the English-language forms this office files. */
const OCR_LANGUAGE = 'eng';

/**
 * Path to the Tesseract binary, or null when it is not installed.
 *
 * Looked up rather than hard-coded so the same code runs on a developer's
 * machine, on the office server, and on a machine with no OCR at all.
 * Define TESSERACT_BIN in config/config.php to point at a specific one.
 */
function ocrBinary(): ?string
{
    static $resolved = false;
    static $binary = null;

    if ($resolved) {
        return $binary;
    }
    $resolved = true;

    $candidates = [];
    if (defined('TESSERACT_BIN') && TESSERACT_BIN !== '') {
        $candidates[] = TESSERACT_BIN;
    }
    $candidates[] = 'C:\Program Files\Tesseract-OCR\tesseract.exe';
    $candidates[] = 'C:\Program Files (x86)\Tesseract-OCR\tesseract.exe';
    $candidates[] = '/usr/bin/tesseract';
    $candidates[] = '/usr/local/bin/tesseract';

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $binary = $candidate;
        }
    }

    // Otherwise whatever is on PATH, if anything.
    $lookup = PHP_OS_FAMILY === 'Windows' ? 'where tesseract 2>NUL' : 'command -v tesseract 2>/dev/null';
    $found  = @shell_exec($lookup);
    if (is_string($found)) {
        $first = trim(strtok($found, "\r\n") ?: '');
        if ($first !== '' && is_file($first)) {
            return $binary = $first;
        }
    }

    return $binary = null;
}

/**
 * Lifts the page images out of a scanned PDF.
 *
 * JPEG streams are stored whole, so they are found by their own markers
 * and validated by decoding the header. Pages stored any other way
 * (CCITT fax, JPEG 2000) are not handled: those fall back to being
 * reported as unreadable rather than guessed at.
 *
 * @return string[] Paths to temporary image files. The caller deletes them.
 */
function extractEmbeddedJpegs(string $pdfPath, int $maxPages = OCR_MAX_PAGES): array
{
    $raw = @file_get_contents($pdfPath);
    if ($raw === false || $raw === '') {
        return [];
    }

    $paths  = [];
    $offset = 0;

    while (count($paths) < $maxPages) {
        $start = strpos($raw, "\xFF\xD8\xFF", $offset);
        if ($start === false) {
            break;
        }
        $end = strpos($raw, "\xFF\xD9", $start);
        if ($end === false) {
            break;
        }

        $jpeg   = substr($raw, $start, $end - $start + 2);
        $offset = $end + 2;

        // A JPEG escapes FF inside its own data, so the end marker found
        // above is the real one — but decode the header anyway rather
        // than hand Tesseract something that only looks like an image.
        $info = @getimagesizefromstring($jpeg);
        if (!$info || $info[0] < OCR_MIN_IMAGE_WIDTH) {
            continue;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dts_ocr_');
        if ($tmp === false) {
            break;
        }
        file_put_contents($tmp, $jpeg);
        $paths[] = $tmp;
    }

    return $paths;
}

/**
 * Reads a scanned PDF with OCR. Returns '' when OCR is unavailable, the
 * pages cannot be lifted out, or the scan yields nothing legible.
 */
function ocrPdfText(string $pdfPath): string
{
    $binary = ocrBinary();
    if ($binary === null) {
        return '';
    }

    $images = extractEmbeddedJpegs($pdfPath);
    if ($images === []) {
        return '';
    }

    $pages = [];
    foreach ($images as $image) {
        $command = escapeshellarg($binary) . ' ' . escapeshellarg($image)
                 . ' stdout -l ' . escapeshellarg(OCR_LANGUAGE) . ' 2>&1';
        $output = @shell_exec($command);
        @unlink($image);

        if (is_string($output) && trim($output) !== '') {
            $pages[] = $output;
        }
    }

    $text = trim(implode("\n", $pages));

    // OCR on a blank or failed page returns a handful of stray marks.
    // Hold it to the same floor a text layer has to clear.
    return mb_strlen($text) < SCANNED_TEXT_FLOOR ? '' : mb_substr($text, 0, EXTRACT_MAX_CHARS);
}
