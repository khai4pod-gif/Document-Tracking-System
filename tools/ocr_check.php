<?php
/**
 * tools/ocr_check.php
 *
 * Answers one question from the command line: can this machine read a
 * scanned document? Run it after installing Tesseract, before trusting
 * the application to classify scans.
 *
 *   php tools/ocr_check.php "uploads/documents/<file>.pdf"
 */

declare(strict_types=1);
require __DIR__ . '/../includes/text_extract.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: php tools/ocr_check.php <path-to-pdf>\n");
    exit(1);
}

$binary = ocrBinary();
printf("tesseract : %s\n", $binary ?? 'NOT INSTALLED — scans cannot be read');

$images = extractEmbeddedJpegs($path);
printf("page images: %d lifted out of the PDF\n", count($images));
foreach ($images as $image) {
    $size = @getimagesize($image);
    printf("  %s  %s\n", basename($image), $size ? "{$size[0]}x{$size[1]}" : 'unreadable');
    @unlink($image);
}

if ($binary === null) {
    echo "\nInstall Tesseract, then run this again:\n";
    echo "  winget install --id UB-Mannheim.TesseractOCR\n";
    exit(2);
}

$result = extractDocumentText($path, 'application/pdf');
printf("\nsource    : %s\nerror     : %s\ncharacters: %d\n",
    $result['source'], $result['error'] ?? 'none', mb_strlen($result['text']));
echo "\n--- first 500 characters ---\n" . mb_substr($result['text'], 0, 500) . "\n";
