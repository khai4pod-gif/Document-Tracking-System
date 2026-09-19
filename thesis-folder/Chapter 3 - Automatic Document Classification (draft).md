DRAFT SUBSECTION — for Chapter 3, under "Project Development"
Paste the prose below into the document. The bracketed notes are for the
proponents and must not be pasted.

---

Automatic Document Classification Module

The Automatic Document Classification Module was developed to determine
the type of an uploaded document without asking the encoder to declare
it. When a document is attached, the system reads the file and proposes
a type, which the encoder may accept or override before saving. Text is
extracted from Portable Document Format files through a dedicated parser
library and from Word documents by reading the document body directly
from the file package. Files in the legacy binary Word format are
reported as unreadable rather than processed, because the mangled text
such files produce would corrupt the corpus on which the model is
trained.

The extracted text is converted into numerical features using Term
Frequency-Inverse Document Frequency (TF-IDF) weighting over word
unigrams and bigrams. Two-word terms were retained because they carry
the type distinction that single words lose: the phrases "special order"
and "distribution list" identify a document type, whereas the words
"order" and "list" do not. Inverse document frequency also removes
recurring letterhead text automatically, since a phrase that appears in
every document contributes no discriminating information. The resulting
feature vectors are classified by a linear Support Vector Machine using a
one-versus-rest scheme, which is suited to sparse, high-dimensional text
representations produced from a limited number of training examples. The
model is trained offline, and its vocabulary, inverse document frequency
weights and per-class coefficients are exported so that the application
performs classification directly, without requiring a separate runtime
service.

Five document types are classified automatically: Memo, Letter, Report,
Relief Manifest and Special Order. The remaining types available in the
system are retained for manual selection but excluded from training,
because they share most of their vocabulary with one another and have not
been used in practice; including them would reduce accuracy on the types
the office actually files.

A prediction is accepted only when the classifier is sufficiently
confident. Below the acceptance threshold the system does not assert a
type; the create form asks the encoder to choose one instead. This design
follows from the operational cost of an error, since a document filed
under the wrong type is routed to the wrong office and must be traced and
corrected, whereas a request for confirmation costs only a moment of the
encoder's time. The threshold was selected from the precision-recall
behaviour of the model on held-out data rather than assigned arbitrarily.
[Insert the threshold value and the precision and coverage observed at
that threshold.]

Every classification is recorded for accountability and for continued
improvement. The predicted type is stored alongside, and not in place of,
the type the document is finally filed under, together with the
confidence score, the identifier of the model that produced it and the
time of the decision. The text that was read is retained in a separate
table. Any record in which the predicted type differs from the final type
therefore represents a human correction, which serves both as the measure
of accuracy in operation and as additional labelled data for retraining.

The model was trained and evaluated on documents collected from the
office. To prevent an optimistic result, the corpus was divided by date
rather than at random: documents created before a cut-off date were used
for training and all later documents were reserved for testing. This was
necessary because official documents are highly templated, and a random
division would place near-identical documents on both sides of the split
and overstate performance. Performance was measured by precision, recall
and F1-score for each type, by the macro-averaged F1-score across types,
and by the proportion of documents classified without asking the encoder.
The keyword-based classifier used before the model was trained was
retained as a baseline for comparison. [Insert the evaluation table and
confusion matrix, and state the macro-F1 of the model against the
baseline.]

Two limitations are acknowledged. Scanned documents contain no
machine-readable text, and the module cannot classify them from their
contents; such documents are identified and referred to the encoder for
manual selection. Documents in the legacy binary Word format are likewise
excluded, for the reason stated above.

---

NOTES FOR THE PROPONENTS (do not paste)

1. The prose above describes a trained linear SVM. As of this draft the
   deployed classifier is the keyword baseline, with the SVM interface
   and the training corpus in place but the model not yet trained. If it
   is not trained before submission, change paragraph two to state that
   the module currently applies weighted keyword rules while the corpus
   for the Support Vector Machine model is accumulated, and move the SVM
   to Recommendations. Do not leave the claim standing untrained — the
   panel will ask to see it.
2. Two bracketed placeholders must be filled with real figures: the
   acceptance threshold with its precision and coverage, and the
   evaluation table with the confusion matrix.
3. Classification does not yet appear in Figure 7 (the dataflow
   diagram). It needs a numbered process, with the extracted text going
   in, the proposed type and confidence returning to the encoder, the
   prediction written to D1 and the document text written to a new store.
