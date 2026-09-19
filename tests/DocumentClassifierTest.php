<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/text_extract.php';

/**
 * Pure-logic tests for the document classifier and text-extraction helpers
 * — no database involved, unlike DocumentTest/ReliefTest. Extends PHPUnit's
 * TestCase directly (fully qualified) rather than the project's own
 * tests/TestCase.php, which opens a DB connection this suite doesn't need
 * and whose unqualified class name would collide with this one anyway.
 */
final class DocumentClassifierTest extends \PHPUnit\Framework\TestCase
{
    private RuleBasedClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new RuleBasedClassifier();
    }

    public function testClassifyReturnsOtherAtZeroConfidenceForEmptyText(): void
    {
        $result = $this->classifier->classify('');

        $this->assertSame('Other', $result['type']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertSame('rules', $result['source']);
    }

    public function testClassifyReturnsOtherWhenNoPhrasesMatchAnyType(): void
    {
        $result = $this->classifier->classify('The quick brown fox jumps over the lazy dog.');

        $this->assertSame('Other', $result['type']);
        $this->assertSame(0.0, $result['confidence']);
    }

    public function testClassifyRecognisesAClearMemo(): void
    {
        $result = $this->classifier->classify(
            'MEMORANDUM. This memorandum is for the information of all concerned personnel.'
        );

        $this->assertSame('Memo', $result['type']);
        $this->assertGreaterThan(0.0, $result['confidence']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function testClassifyRecognisesAReliefManifestOverLetterVocabulary(): void
    {
        $result = $this->classifier->classify(
            'RELIEF MANIFEST. Distribution list of family food pack for beneficiaries '
            . 'at the evacuation center in Barangay San Isidro. Quantity: 500 sacks.'
        );

        $this->assertSame('Relief Manifest', $result['type']);
    }

    /**
     * The exact scenario the class's own docblock names: a long document
     * that mentions relief-adjacent words in passing must not be filed as a
     * Relief Manifest just because the words appear somewhere in it. Score
     * is scaled by density (evidence per unit of length), not raw count.
     */
    public function testClassifyDoesNotFileALongDocumentAsReliefManifestOnSparseMentions(): void
    {
        $padding = str_repeat(
            'This section of the paper discusses methodology and related literature in general terms. ',
            200
        );
        $text = $padding . ' The study briefly touches on relief goods and barangay logistics once. ' . $padding;

        $result = $this->classifier->classify($text);

        $this->assertLessThan(
            RuleBasedClassifier::ACCEPT_THRESHOLD,
            $result['confidence'],
            'A ~19,000 character document with two incidental mentions must score below the accept threshold.'
        );
    }

    public function testClassifyGivesDiminishingReturnsForRepeatedPhrases(): void
    {
        $once = $this->classifier->classify('This is a memorandum for the record.');
        $many = $this->classifier->classify(str_repeat('memorandum memorandum memorandum memorandum ', 20));

        // log2-scaled repetition still outscores a single mention...
        $this->assertGreaterThan($once['confidence'], $many['confidence']);
    }

    public function testVerifyDocumentTypeReturnsNoneWhenBothReadingsAreEmpty(): void
    {
        $result = verifyDocumentType($this->classifier, '', '');

        $this->assertSame('none', $result['agreement']);
        $this->assertSame('Other', $result['type']);
        $this->assertSame(0.0, $result['confidence']);
    }

    public function testVerifyDocumentTypeAgreementBoostsConfidenceOverEitherAlone(): void
    {
        $memoText = 'MEMORANDUM. This memorandum is for the information of all concerned. Advisory notice.';

        $docOnly = $this->classifier->classify($memoText);
        $result  = verifyDocumentType($this->classifier, $memoText, 'Memorandum for all concerned');

        $this->assertSame('agree', $result['agreement']);
        $this->assertSame('Memo', $result['type']);
        $this->assertGreaterThanOrEqual($docOnly['confidence'], $result['confidence']);
    }

    public function testVerifyDocumentTypeFlagsAConflictBetweenStrongDisagreeingReadings(): void
    {
        $documentText = 'PURCHASE ORDER. PO No. 2026-001. Place of delivery: main office. Supplier: Acme Corp. Delivery term: 30 days.';
        $titleText    = 'Application for Leave. CSC Form No. 6. Vacation leave for 5 days applied for.';

        $result = verifyDocumentType($this->classifier, $documentText, $titleText);

        $this->assertSame('conflict', $result['agreement']);
        $this->assertSame(0.0, $result['confidence'], 'A genuine conflict must never assert a type with confidence.');
    }

    public function testVerifyDocumentTypeUsesTitleOnlyWhenTheDocumentHasNoReading(): void
    {
        $result = verifyDocumentType(
            $this->classifier,
            '',
            'MEMORANDUM for the information of all concerned — this memorandum applies agency-wide.'
        );

        $this->assertSame('title-only', $result['agreement']);
        $this->assertSame('Memo', $result['type']);
    }

    public function testNormaliseExtractedTextCollapsesWhitespaceAndStripsControlCharacters(): void
    {
        $dirty = "Hello\x00World\r\n\r\n   with   \t\textra\x1Fspace";

        $this->assertSame('Hello World with extra space', normaliseExtractedText($dirty));
    }

    public function testNormaliseExtractedTextCapsLength(): void
    {
        $long = str_repeat('a', EXTRACT_MAX_CHARS + 500);

        $this->assertSame(EXTRACT_MAX_CHARS, mb_strlen(normaliseExtractedText($long)));
    }

    public function testFilenameEvidenceExpandsKnownCodesInARegistryStyleName(): void
    {
        $evidence = filenameEvidence('SB-AS-PSAMD-MEM-26-05-421838-S');

        $this->assertStringContainsString('memorandum', $evidence);
    }

    public function testFilenameEvidenceDoesNotExpandCodesInAnOrdinaryFilename(): void
    {
        // Fewer than two hyphens -> not registry-shaped -> "to" must not
        // become "travel order", exactly the false positive the guard
        // exists to prevent (see filenameEvidence's docblock).
        $evidence = filenameEvidence('Report to Director.pdf');

        $this->assertStringNotContainsString('travel order', $evidence);
    }

    public function testFilenameEvidenceSkipsPureNumericSegments(): void
    {
        $evidence = filenameEvidence('SB-AS-2026-05-421838');

        $this->assertStringNotContainsString('2026', $evidence);
        $this->assertStringNotContainsString('421838', $evidence);
    }

    public function testFilenameEvidenceReturnsEmptyStringForNoFilename(): void
    {
        $this->assertSame('', filenameEvidence(''));
    }

    public function testBuildClassificationTextCombinesTitleDescriptionAndFileText(): void
    {
        $text = buildClassificationText('Memo Title', 'A short description.', 'Body text from the file.');

        $this->assertSame('Memo Title A short description. Body text from the file.', $text);
    }

    public function testExtractDocumentTextReportsMissingForANonexistentFile(): void
    {
        $result = extractDocumentText('/tmp/does-not-exist-' . uniqid() . '.pdf', 'application/pdf');

        $this->assertSame('', $result['text']);
        $this->assertSame('missing', $result['source']);
        $this->assertNotNull($result['error']);
    }

    public function testExtractDocumentTextReportsUnsupportedForLegacyDocFormat(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dts_test_');
        file_put_contents($tmp, 'not a real .doc file, content is irrelevant here');

        try {
            $result = extractDocumentText($tmp, 'application/msword');

            $this->assertSame('', $result['text']);
            $this->assertSame('unsupported', $result['source']);
        } finally {
            unlink($tmp);
        }
    }

    public function testExtractDocxTextReadsWordDocumentXml(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ext-zip is not enabled in this php.ini — see extractDocxText()\'s own guard.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dts_test_') . '.docx';

        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml',
            '<w:document xmlns:w="ns"><w:body>'
            . '<w:p><w:r><w:t>MEMORANDUM</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>For the information of all concerned.</w:t></w:r></w:p>'
            . '</w:body></w:document>'
        );
        $zip->close();

        try {
            $text = extractDocxText($tmp);
            $this->assertSame('MEMORANDUM For the information of all concerned.', $text);

            // Round-trips through the real classification path too.
            $result = $this->classifier->classify($text);
            $this->assertSame('Memo', $result['type']);
        } finally {
            unlink($tmp);
        }
    }

    public function testExtractDocxTextThrowsWhenDocumentXmlIsMissing(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ext-zip is not enabled in this php.ini — see extractDocxText()\'s own guard.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dts_test_') . '.docx';

        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE);
        $zip->addFromString('not-the-right-file.txt', 'irrelevant');
        $zip->close();

        try {
            $this->expectException(RuntimeException::class);
            extractDocxText($tmp);
        } finally {
            unlink($tmp);
        }
    }
}
