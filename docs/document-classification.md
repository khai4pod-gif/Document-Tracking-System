# Document classification

How the system works out what kind of document was uploaded, and what it
would take to replace today's implementation with the trained model the
paper describes.

Audience: whoever picks this up next.

## The contract

`DocumentClassifier` (`classes/DocumentClassifier.php`) is deliberately
narrow — text in, `{type, confidence, source, reasons}` out. Nothing else
in the application knows how the answer was reached, which is what makes
the implementation replaceable:

```php
interface DocumentClassifier {
    public function classify(string $text): array;
    public function name(): string;   // stored in documents.detection_source
}
```

Today `RuleBasedClassifier` implements it with weighted keyword rules.
`LinearSvmClassifier` will implement the same interface, and no caller
changes.

## What the problem actually is

Single-label classification over a small, imbalanced, heavily templated
in-house corpus, where a wrong answer costs more than no answer: a memo
filed as a relief manifest is routed to the wrong office and has to be
chased. The target is therefore **high precision with an honest
abstention rate**, not headline accuracy. File it when sure, ask when
not, and be able to explain either outcome.

Five types are classified — Memo, Letter, Report, Relief Manifest,
Special Order. The other six in the `doc_type` ENUM share most of their
vocabulary with each other, have never been used, and stay selectable by
hand. Training on them would cost accuracy on the types that matter.

## Pipeline

### 1. Extraction

`includes/text_extract.php`. PDF through `smalot/pdfparser` (a
hand-rolled extractor returns zero characters from the subset-font,
hex-encoded PDFs this office actually produces); DOCX read straight out
of the zip; `.doc` refused rather than mangled, because mangled text
poisons the training corpus.

**Open gap — scanned documents.** A scan is a PDF with no text layer.
The parser succeeds and returns nothing, and because
`ajax/document_save.php` only warns when `error !== null`, an empty
extraction passes silently and the type is then judged from the title
alone, with nothing said to the user. In an office that receives paper,
this is routine rather than exceptional.

Fix, in order of preference:

1. Text-density check — under ~100 characters per page, treat the file
   as an image, not a document.
2. Then either run OCR (Tesseract as a subprocess at upload time), or
   record `source = 'scanned'`, skip the prediction entirely, and ask
   for the type. Either beats guessing from a title.

### 2. Normalisation

Lowercase, collapse whitespace. Do not hand-write rules to strip the
agency letterhead: a phrase that appears in every document carries no
information by construction, and IDF removes it for free. That is the
argument for TF-IDF over raw counts.

### 3. Features

Word unigrams and bigrams, sublinear term frequency, `min_df=2`, English
and Filipino stopwords. Bigrams earn their place here — "special order"
and "distribution list" are types; "order" and "list" are not.

Worth adding alongside the bag of words, because they capture layout the
tokens cannot: presence of a salutation, presence of a signature block,
the document's first non-empty line, and line-level table density
(manifests are tables, letters are prose).

### 4. Model

`LinearSVC`, one-vs-rest — the standard choice for sparse
high-dimensional text with a few hundred examples, and what the paper
commits to. Wrap it in `CalibratedClassifierCV` so the output is a
probability rather than a raw margin. This is not a formality: it is what
makes a confidence threshold defensible.

Today's stand-in scores `margin x evidence density` — the margin over the
runner-up, multiplied by how much evidence there was per unit of length,
multiplied rather than averaged so that a strong margin cannot paper over
absent evidence. It is a reasonable heuristic. It is not a probability
and should not be described as one.

### 5. Decision policy

Keep the accept-or-ask structure. Choose the threshold from a
precision-recall curve on held-out data — "at t = 0.62, precision is 0.97
and 71% of documents are filed automatically" — rather than by feel, and
report **coverage alongside accuracy**. A classifier that abstains on
everything is perfectly accurate and useless.

## Training data

The schema already collects it. `documents.detected_type` sits beside
`doc_type` rather than replacing it, so every row where the two differ is
a human correction; `document_text` keeps what the classifier read.

- **Bootstrap**: the rules label the first pass, humans correct it, the
  corrections are gold.
- **Volume**: aim for 50-100 examples per type across the five live
  types — 250-500 documents. Below that, say so rather than reporting a
  flattering number from forty files.
- **Split by time, not at random.** Government documents are templated;
  two memos from the same office in the same month are near-duplicates.
  A random split puts near-copies on both sides of the line and inflates
  the score badly. Hold out everything created after a cut-off date.
- Stratified k-fold on the training portion for tuning, given the class
  imbalance.

## Serving it from PHP

No Python in the runtime. Train offline with scikit-learn, export the
TF-IDF vocabulary, the IDF weights, the per-class coefficients and the
intercepts as JSON. Inference is a sparse dot product, which PHP does
perfectly well.

`LinearSvmClassifier implements DocumentClassifier`;
`detection_source` flips from `rules` to `svm`, so a change of model is
visible in the data rather than only in the git history. Keep the rules
as the fallback for cold start, unreadable text, or a missing model file
— a documented fallback is a strength, not an embarrassment.

## Measuring it

For the paper: per-class precision, recall and F1, macro-F1, a confusion
matrix over the five types, coverage at the chosen threshold, and the
rule classifier as the baseline. "The SVM beats the keyword baseline by
X points of macro-F1" is a result; "we used an SVM" is not.

In production: the correction rate over time. When it drifts upward,
retrain.

## What not to do

- Do not call an LLM API for this. It puts a network dependency and a
  per-document cost inside a government workflow, and the study commits
  to SVM.
- Do not classify on the title alone — that is the failure mode the
  scanned-PDF gap silently produces today.
- Do not report plain accuracy on an imbalanced set.
