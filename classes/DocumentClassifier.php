<?php
/**
 * classes/DocumentClassifier.php
 *
 * Works out what kind of document was uploaded, so the creator does not
 * have to say.
 *
 * The contract is deliberately narrow — text in, {type, confidence,
 * reasons} out — because the implementation behind it is meant to be
 * replaced. Today it is a set of weighted keyword rules, which needs no
 * training data and can explain every decision it makes. When enough
 * labelled documents have accumulated, LinearSvmClassifier implements the
 * same interface and nothing else in the application changes.
 *
 * On the SVM, for whoever picks this up: a linear model's *inference* is
 * a dot product. Train it offline with scikit-learn, export the TF-IDF
 * vocabulary, the IDF weights and the per-class coefficients as JSON, and
 * PHP can score it directly. Python belongs on a developer's machine at
 * retraining time, not in this application's runtime.
 */

declare(strict_types=1);

/**
 * @phpstan-type Prediction array{type:string,confidence:float,source:string,reasons:string[]}
 */
interface DocumentClassifier
{
    /**
     * @return array{type:string,confidence:float,source:string,reasons:string[]}
     *         confidence is 0.0-1.0; `reasons` explains the decision to a
     *         human and is shown in the UI.
     */
    public function classify(string $text): array;

    /** Identifier stored in documents.detection_source. */
    public function name(): string;
}

/**
 * Keyword rules, scored and normalised.
 *
 * Ten of the eleven doc_type values are classified. "Other" is not a type
 * to be recognised but the answer when nothing matches, so it has no
 * rules of its own.
 *
 * Only phrases that name a form carry real weight. The procurement forms
 * in particular share their everyday vocabulary — quantity, unit cost,
 * supplies — and scoring those would only teach the classifier to confuse
 * them with one another.
 */
final class RuleBasedClassifier implements DocumentClassifier
{
    /**
     * Phrase => weight, per type.
     *
     * Multi-word phrases score higher than single words because they are
     * far less likely to appear by accident: a relief manifest mentions
     * "relief" once in a title but "distribution list" only when it is
     * one.
     */
    private const RULES = [
        'Memo' => [
            'memorandum' => 5.0, 'memo' => 4.0, 'memorandum circular' => 6.0,
            'for the information' => 2.5, 'all concerned' => 2.5,
            'this memorandum' => 3.0, 'advisory' => 1.5,
        ],
        'Letter' => [
            'dear sir' => 5.0, 'dear madam' => 5.0, 'dear ma\'am' => 5.0,
            'very truly yours' => 5.0, 'respectfully yours' => 4.5,
            'sincerely yours' => 4.5, 'letter' => 2.0,
            'we would like to request' => 3.0, 'greetings' => 1.5,
        ],
        'Report' => [
            'report' => 3.5, 'accomplishment report' => 6.0,
            'monitoring report' => 6.0, 'narrative report' => 6.0,
            'findings' => 2.5, 'summary of' => 2.0, 'recommendation' => 2.0,
            'conclusion' => 2.0, 'as of the period' => 2.5, 'statistics' => 2.0,
        ],
        'Relief Manifest' => [
            'relief manifest' => 7.0, 'manifest' => 4.0,
            'family food pack' => 5.0, 'ffp' => 3.0,
            'relief goods' => 5.0, 'distribution list' => 4.5,
            'evacuation center' => 3.5, 'beneficiaries' => 2.5,
            'barangay' => 1.5, 'quantity' => 1.5, 'sacks' => 2.0,
        ],
        'Special Order' => [
            'special order' => 7.0, 'hereby designated' => 4.0,
            'is hereby ordered' => 4.0, 'travel order' => 4.0,
        ],
        // The procurement and personnel forms below were once left out on
        // the grounds that they had never been used. That is no longer
        // true — TMS-2026-000012 is a PPMP — and it made the system file
        // them as "Other", which is the one answer that helps nobody.
        // They share generic procurement vocabulary with each other, so
        // only the phrases that name the form itself carry real weight.
        'PPMP' => [
            'project procurement management plan' => 8.0, 'ppmp' => 6.0,
            'indicative ppmp' => 6.0, 'annual procurement plan' => 4.0,
            'mode of procurement' => 2.0, 'estimated budget' => 1.5,
        ],
        'Purchase Request' => [
            'purchase request' => 7.0, 'pr no' => 3.0,
            'requisitioning office' => 3.5, 'requested by' => 1.5,
            'stock no' => 1.5,
        ],
        'Purchase Order' => [
            'purchase order' => 7.0, 'po no' => 3.0,
            'place of delivery' => 3.5, 'delivery term' => 3.0,
            'supplier' => 2.0,
        ],
        'ORs/DV' => [
            'disbursement voucher' => 7.0, 'official receipt' => 6.0,
            'obligation request' => 3.5, 'dv no' => 3.0, 'payee' => 2.5,
        ],
        'Leave Application' => [
            'application for leave' => 7.0, 'leave application' => 7.0,
            'csc form no. 6' => 6.0, 'vacation leave' => 4.0,
            'sick leave' => 4.0, 'days applied for' => 3.0,
        ],
    ];

    /**
     * Below this the caller should ask rather than assert. Set where the
     * top type is clearly ahead of the runner-up rather than merely
     * first — a narrow win on a handful of common words is a guess.
     */
    public const ACCEPT_THRESHOLD = 0.45;

    public function name(): string
    {
        return 'rules';
    }

    public function classify(string $text): array
    {
        $haystack = ' ' . mb_strtolower($text) . ' ';

        if (trim($haystack) === '') {
            return ['type' => 'Other', 'confidence' => 0.0, 'source' => $this->name(),
                    'reasons' => ['there was no readable text to classify']];
        }

        $scores  = [];
        $matched = [];

        foreach (self::RULES as $type => $phrases) {
            $score = 0.0;
            foreach ($phrases as $phrase => $weight) {
                $hits = substr_count($haystack, ' ' . $phrase . ' ')
                      + substr_count($haystack, ' ' . $phrase . ',')
                      + substr_count($haystack, ' ' . $phrase . '.');
                // Diminishing returns: a word repeated forty times is not
                // forty times the evidence, and long documents would
                // otherwise drown short ones on raw counts alone.
                if ($hits > 0) {
                    $score += $weight * (1 + log($hits, 2));
                    $matched[$type][] = $phrase . ($hits > 1 ? " (\u{00d7}{$hits})" : '');
                }
            }
            $scores[$type] = $score;
        }

        arsort($scores);
        $top       = array_key_first($scores);
        $topScore  = $scores[$top];
        $ordered   = array_values($scores);
        $runnerUp  = $ordered[1] ?? 0.0;

        if ($topScore <= 0.0) {
            return ['type' => 'Other', 'confidence' => 0.0, 'source' => $this->name(),
                    'reasons' => ['no recognisable phrases for any known document type']];
        }

        // Confidence is the margin over the runner-up, not the raw score:
        // what matters is how much better the winner is than the next
        // candidate, which is also what a margin classifier measures.
        $margin = ($topScore - $runnerUp) / $topScore;

        // A clear margin over nothing much is still nothing much, so the
        // margin is only half the answer. The other half is DENSITY: how
        // much evidence there was per page, not in total. A long document
        // has far more chances to mention a phrase in passing, so the
        // score it must reach rises with its length — slowly, because a
        // forty-page memo is still a memo.
        //
        // This is what stops a research paper that happens to discuss
        // relief goods twice in 15,000 characters from being filed as a
        // relief manifest: two matches is dense evidence in a covering
        // letter and almost none in a thesis.
        $expected = 8.0 * max(1.0, sqrt(mb_strlen($text) / 1200));
        $evidence = min(1.0, $topScore / $expected);

        // Multiplied, not averaged: both have to hold. Averaging lets a
        // strong margin paper over absent evidence, which is exactly how
        // a confident wrong answer gets made. The cost is coverage — the
        // classifier asks more often than it used to on documents with
        // nothing but a short title to go on — and that is the right way
        // round: asking is a small cost, filing a thesis as a relief
        // manifest is not.
        $confidence = round($margin * $evidence, 3);

        return [
            'type'       => $top,
            'confidence' => $confidence,
            'source'     => $this->name(),
            'reasons'    => array_slice($matched[$top] ?? [], 0, 5),
        ];
    }
}

/**
 * Reconciles two independent readings of the same document: the file that
 * was uploaded, and the title (with the description) the encoder typed.
 *
 * Classifying one blob of both lets the longer voice drown the shorter —
 * six words of title against fifteen thousand characters of body — so a
 * title that plainly says MEMORANDUM stops counting for anything the
 * moment a file is attached. Read apart, both stay audible, and they are
 * allowed to disagree out loud: two readings that agree are the strongest
 * evidence available here, and two that contradict each other are exactly
 * the case where the system should ask instead of filing.
 *
 * @param float $conflictFloor Confidence each reading must reach on its
 *        own before it is allowed to contradict the other. Most titles
 *        are vague and most bodies are long, so a weak reading does not
 *        get to veto a strong one.
 *
 * @return array{type:string,confidence:float,source:string,reasons:string[],
 *               agreement:string,document:?array,title:?array}
 *         agreement: 'agree' | 'conflict' | 'document-only' | 'title-only' | 'none'
 */
function verifyDocumentType(
    DocumentClassifier $classifier,
    string $documentText,
    string $typedText,
    float $conflictFloor = RuleBasedClassifier::ACCEPT_THRESHOLD
): array {
    $doc = trim($documentText) === '' ? null : $classifier->classify($documentText);
    $ttl = trim($typedText) === ''    ? null : $classifier->classify($typedText);

    // 'Other' at zero confidence is the classifier saying it found
    // nothing, not a verdict of its own.
    $usable = static function (?array $v): bool {
        return $v !== null && $v['confidence'] > 0.0 && $v['type'] !== 'Other';
    };

    $base = ['source' => $classifier->name(), 'document' => $doc, 'title' => $ttl];

    if (!$usable($doc) && !$usable($ttl)) {
        return $base + [
            'type'       => 'Other',
            'confidence' => 0.0,
            'agreement'  => 'none',
            'reasons'    => ['neither the document nor the title matched a known type'],
        ];
    }

    if ($usable($doc) !== $usable($ttl)) {
        $only = $usable($doc) ? $doc : $ttl;
        return $base + [
            'type'       => $only['type'],
            'confidence' => $only['confidence'],
            'agreement'  => $usable($doc) ? 'document-only' : 'title-only',
            'reasons'    => $only['reasons'],
        ];
    }

    if ($doc['type'] === $ttl['type']) {
        // Two independent sources agreeing is worth more than either on
        // its own. Combined the way independent evidence combines rather
        // than averaged — averaging would let the weaker reading drag a
        // strong one down, and agreement should never cost confidence.
        $confidence = 1.0 - (1.0 - $doc['confidence']) * (1.0 - $ttl['confidence']);
        return $base + [
            'type'       => $doc['type'],
            'confidence' => round($confidence, 3),
            'agreement'  => 'agree',
            'reasons'    => array_slice(
                array_values(array_unique(array_merge($doc['reasons'], $ttl['reasons']))),
                0,
                6
            ),
        ];
    }

    $preferDoc = $doc['confidence'] >= $ttl['confidence'];

    if ($doc['confidence'] >= $conflictFloor && $ttl['confidence'] >= $conflictFloor) {
        // Each side is strong enough to have been filed on its own, and
        // they name different types. Nothing is asserted: zero confidence
        // puts it below every threshold, so the caller asks.
        return $base + [
            'type'       => $preferDoc ? $doc['type'] : $ttl['type'],
            'confidence' => 0.0,
            'agreement'  => 'conflict',
            'reasons'    => [
                'the document reads as ' . $doc['type'],
                'the title reads as ' . $ttl['type'],
            ],
        ];
    }

    $stronger = $preferDoc ? $doc : $ttl;
    return $base + [
        'type'       => $stronger['type'],
        'confidence' => $stronger['confidence'],
        'agreement'  => $preferDoc ? 'document-only' : 'title-only',
        'reasons'    => $stronger['reasons'],
    ];
}
