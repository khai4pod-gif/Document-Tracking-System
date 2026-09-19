<?php
/**
 * documents.php
 * Document management: DataTable listing + Create/Edit/Route modals (AJAX).
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auto_routing.php';
require_login();

$showArchived = isset($_GET['archived']) && $_GET['archived'] === '1';

// Filters can arrive in the URL so the dashboard tiles can link straight to a
// filtered list. Validated against the same options the dropdowns offer, so a
// hand-edited query string can't put the page into a state the UI can't show.
$statusOptions   = ['Draft', 'Pending Routing', 'In Transit', 'Received', 'Completed', Document::DERIVED_OVERDUE];
$priorityOptions = ['Low', 'Normal', 'High', 'Urgent'];

$filterStatus   = (string)($_GET['status'] ?? '');
$filterPriority = (string)($_GET['priority'] ?? '');
if (!in_array($filterStatus, $statusOptions, true)) {
    $filterStatus = '';
}
if (!in_array($filterPriority, $priorityOptions, true)) {
    $filterPriority = '';
}

// Compliance drill-through from the Home analytics cards, which carry their
// period along so the list shows exactly the set the number counted.
$filterCompliance = (string)($_GET['compliance'] ?? '');
$filterPeriod     = (string)($_GET['period'] ?? '');
if (!in_array($filterCompliance, Document::COMPLIANCE_FILTERS, true)) {
    $filterCompliance = '';
    $filterPeriod = '';
}
if (!in_array($filterPeriod, Document::PERFORMANCE_PERIODS, true)) {
    $filterPeriod = '';
}

$complianceLabels = [
    'compliant'     => 'Compliant',
    'non_compliant' => 'Non-Compliant',
    'exempt'        => 'Exempt',
];
$periodLabels = [
    'as_of_today' => 'as of today', 'today' => 'today', 'week' => 'this week',
    'month'       => 'this month',  'quarter' => 'this quarter', 'year' => 'this year',
];
$hasFilter = $filterStatus !== '' || $filterPriority !== '' || $filterCompliance !== '';
$canRoute = user_can_route(current_user(), Database::getConnection());

$pageTitle = $showArchived ? 'Archived Documents' : 'Documents';
$pageIcon  = $showArchived ? 'bi-archive' : 'bi-file-earmark-text';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading"><?= e($pageTitle) ?></div>
    <div class="section-sub"><?= $showArchived ? 'Documents that have been soft-deleted from active workflows.' : 'Create, edit, route, and track official documents.' ?></div>
  </div>
  <?php if (!$showArchived): ?>
  <button class="btn btn-primary" id="btnNewDocument">
    <i class="bi bi-plus-lg me-1"></i> New Document
  </button>
  <?php endif; ?>
</div>

<div class="card-panel">
  <div class="card-panel-header d-flex flex-wrap gap-2 align-items-center">
    <span class="me-auto">Document Records</span>
    <select class="form-select form-select-sm" style="width:auto;" id="filterStatus">
      <option value="">All Statuses</option>
      <?php foreach ($statusOptions as $__opt): ?>
        <option<?= $filterStatus === $__opt ? ' selected' : '' ?>><?= e($__opt) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="form-select form-select-sm" style="width:auto;" id="filterPriority">
      <option value="">All Priorities</option>
      <?php foreach ($priorityOptions as $__opt): ?>
        <option<?= $filterPriority === $__opt ? ' selected' : '' ?>><?= e($__opt) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($filterCompliance !== ''): ?>
      <span class="badge rounded-pill text-bg-light border align-self-center">
        <i class="bi bi-funnel me-1"></i><?= e($complianceLabels[$filterCompliance]) ?>
        <?php if ($filterPeriod !== ''): ?>
          &middot; completed <?= e($periodLabels[$filterPeriod] ?? $filterPeriod) ?>
        <?php endif; ?>
      </span>
    <?php endif; ?>
    <?php if ($hasFilter): ?>
      <a href="documents.php<?= $showArchived ? '?archived=1' : '' ?>"
         class="btn btn-sm btn-outline-secondary" title="Clear filters">
        <i class="bi bi-x-lg"></i>
      </a>
    <?php endif; ?>
  </div>
  <div class="p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle w-100" id="documentsTable">
        <thead>
          <tr>
            <th>Tracking #</th>
            <th>Title</th>
            <th>Type</th>
            <th>Priority</th>
            <th>Status</th>
            <th>Approval</th>
            <th>Current Holder</th>
            <th>Created</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ===================== Create / Edit Document Modal ===================== -->
<div class="modal fade" id="documentModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="documentForm">
        <div class="modal-header">
          <h5 class="modal-title" id="documentModalLabel"><i class="bi bi-file-earmark-plus me-2"></i>New Document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="document_id" id="documentId" value="">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="fieldTitle" class="form-control" maxlength="255" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Priority</label>
              <select name="priority" id="fieldPriority" class="form-select">
                <option>Low</option>
                <option selected>Normal</option>
                <option>High</option>
                <option>Urgent</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Document Type</label>
              <?php
              // The creator does not choose a type. The system reads the
              // attached file and says what it is; the select below stays in
              // the page because it is still the answer in the two cases the
              // system cannot settle on its own — a file it is unsure about,
              // and a creator who disagrees — and it is hidden until then.
              ?>
              <div id="typeDetected" class="type-detect" data-state="idle">
                <div class="type-detect__head">
                  <span class="type-detect__icon"><i class="bi bi-stars"></i></span>
                  <div class="type-detect__body">
                    <div class="type-detect__value" id="typeDetectedValue">Detected automatically</div>
                    <div class="type-detect__note" id="typeDetectedNote">Attach the document and the system will identify its type.</div>
                  </div>
                  <span class="type-detect__conf" id="typeDetectedConf" hidden></span>
                </div>
                <div class="type-detect__why" id="typeDetectedWhy" hidden></div>
                <button type="button" class="type-detect__override" id="btnOverrideType" hidden>
                  <i class="bi bi-pencil me-1"></i>Set the type myself
                </button>
              </div>

              <div id="typeManual" hidden>
                <select name="doc_type" id="fieldType" class="form-select">
                  <option>Memo</option>
                  <option>Letter</option>
                  <option>Report</option>
                  <option>Purchase Request</option>
                  <option>Relief Manifest</option>
                  <option>Special Order</option>
                  <option>ORs/DV</option>
                  <option>PPMP</option>
                  <option>Purchase Order</option>
                  <option>Leave Application</option>
                  <option selected>Other</option>
                </select>
                <div class="form-text" id="typeManualHelp"></div>
              </div>
              <!-- Set when the creator overrules the system, so the server
                   keeps their choice instead of replacing it. -->
              <input type="hidden" name="doc_type_overridden" id="fieldTypeOverridden" value="">
            </div>
            <div class="col-md-6">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" id="fieldDueDate" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="fieldDescription" class="form-control" rows="3" maxlength="2000"></textarea>
            </div>
<?php
            // The recipient is no longer a choice: saving sends the document
            // to the Office of the Secretary, and acknowledging it there
            // sends it on to this office's approver. So this block states
            // where it will go instead of asking.
            $__pdo  = Database::getConnection();
            $__osec = osec_office($__pdo);
            $__mine = approverForDocument(
                ['origin_department_id' => current_user()['department_id'] ?? null],
                $__pdo
            );
            ?>
            <div class="col-12" id="routeOnCreateWrapper">
              <label class="form-label mb-2">Where this goes</label>
              <div class="route-card">
                <div class="route-card__sub mb-2">
                  <i class="bi bi-signpost-split me-1"></i>
                  Routing is automatic — there is nothing to choose here.
                </div>
                <ol class="auto-route-steps mb-0 ps-3">
                  <li>
                    <strong>Office of the Secretary</strong>
                    <?php if (!empty($__osec['receiver_name'])): ?>
                      <span class="text-muted">— <?= e($__osec['receiver_name']) ?> acknowledges receipt</span>
                    <?php else: ?>
                      <span class="text-danger">— no receiving user assigned yet, so it will stay with you</span>
                    <?php endif; ?>
                  </li>
                  <li>
                    <?php if ($__mine['user'] !== null): ?>
                      <strong><?= e($__mine['user']['full_name']) ?></strong>
                      <span class="text-muted">— approver for <?= e($__mine['user']['office_name']) ?></span>
                    <?php else: ?>
                      <span class="text-danger">
                        No approver yet — <?= e((string)$__mine['reason']) ?>.
                        It will wait at the Office of the Secretary.
                      </span>
                    <?php endif; ?>
                  </li>
                </ol>

                <div class="mt-3 pt-3 border-top">
                  <label class="form-label small text-muted mb-1" for="fieldTransmittal">
                    Mode of transmittal
                  </label>
                  <select class="form-select form-select-sm" name="transmittal_mode" id="fieldTransmittal">
                    <?php foreach (TRANSMITTAL_MODES as $__mode): ?>
                      <option value="<?= e($__mode) ?>"<?= $__mode === DEFAULT_TRANSMITTAL_MODE ? ' selected' : '' ?>>
                        <?= e($__mode) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-text">
                    How the document itself reaches the next office. It is recorded on every
                    hop of the document history.
                  </div>
                </div>
              </div>
            </div>
            <div class="col-12" id="attachmentWrapper">
              <label class="form-label mb-2">Attachments</label>

              <div class="cloud-link-card">
                <div class="cloud-link-card__header">
                  <span class="cloud-link-card__icon"><i class="bi bi-link-45deg"></i></span>
                  <span class="cloud-link-card__title">Cloud Link</span>
                  <span class="badge cloud-link-card__badge"><i class="bi bi-star-fill me-1"></i>RECOMMENDED</span>
                  <a href="#" class="cloud-link-card__howto ms-auto" id="btnHowToShare">
                    <i class="bi bi-question-circle me-1"></i>How to share <i class="bi bi-chevron-down small"></i>
                  </a>
                </div>
                <div class="cloud-link-card__sub">Easier to update later. No file size limits. Always accessible.</div>

                <div id="cloudLinkRows" class="cloud-link-rows"></div>

                <button type="button" class="cloud-link-add" id="btnAddCloudLink">
                  <i class="bi bi-plus-lg me-1"></i> Add Another Link <span class="text-muted" id="cloudLinkCount">(1 / 20)</span>
                </button>
              </div>

              <div class="mt-3">
                <label class="form-label small text-muted">Upload the document file <span class="text-danger">*</span> <span class="text-muted">(PDF or Word, max 10MB)</span></label>
                <input type="file" name="attachment" id="fieldAttachment" class="form-control" accept=".pdf,.doc,.docx">
                <div class="form-text">Required &#8212; the system reads the file to work out the document type.</div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnSaveDocument">
            <i class="bi bi-save me-1"></i> Save Document
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.route-card {
  background: #f7f9fc;
  border: 1px solid #e3e9f2;
  border-radius: 10px;
  padding: 1rem 1.1rem;
}
.route-card__sub { font-size: 0.85rem; color: #5b6472; margin-bottom: 0.9rem; }

/* ---- Detected document type -------------------------------------
   Four states, set on data-state: idle (nothing read yet), working
   (reading the file), done (a confident answer) and unsure (an answer
   the system will not stand behind, so the select is revealed).
   Colour carries the difference because the wording alone is easy to
   skim past on a form this long. */
.type-detect {
  border: 1px solid #e3e9f2;
  background: #f7f9fc;
  border-radius: 10px;
  padding: 0.6rem 0.75rem;
}
.type-detect[data-state="done"]   { border-color: #c6dbff; background: #eef4ff; }
.type-detect[data-state="unsure"] { border-color: #ffe2ab; background: #fff8ec; }
.type-detect__head { display: flex; align-items: flex-start; gap: 0.55rem; }
.type-detect__icon {
  width: 26px; height: 26px; border-radius: 50%; background: #fff;
  border: 1px solid #e3e9f2; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  color: #4361ee; font-size: 0.85rem; line-height: 1;
}
.type-detect[data-state="unsure"] .type-detect__icon { color: #b8860b; }
.type-detect__body { min-width: 0; flex: 1 1 auto; }
.type-detect__value { font-weight: 600; font-size: 0.95rem; color: #1f2937; }
.type-detect[data-state="done"] .type-detect__value { color: #1d3c8c; }
.type-detect__note { font-size: 0.78rem; color: #5b6472; margin-top: 0.1rem; }
.type-detect__conf {
  flex-shrink: 0; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.02em;
  background: #fff; border: 1px solid #c6dbff; color: #1d3c8c;
  border-radius: 999px; padding: 0.1rem 0.45rem; white-space: nowrap;
}
.type-detect__why {
  font-size: 0.74rem; color: #5b6472; margin-top: 0.45rem;
  padding-top: 0.4rem; border-top: 1px dashed #dbe3ee;
  overflow-wrap: anywhere;
}
.type-detect__override {
  background: none; border: 0; padding: 0; margin-top: 0.4rem;
  font-size: 0.78rem; color: #4361ee; text-decoration: underline;
}
.type-detect__override:hover { color: #2f4bd1; }
.cloud-link-card {
  background: #eef4ff;
  border: 1px solid #dbe6fd;
  border-radius: 10px;
  padding: 1rem 1.1rem;
}
.cloud-link-card__header { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.cloud-link-card__icon {
  width: 30px; height: 30px; border-radius: 50%; background: #fff;
  display: flex; align-items: center; justify-content: center;
  color: #4361ee; font-size: 1rem; flex-shrink: 0;
}
.cloud-link-card__title { font-weight: 600; font-size: 0.95rem; }
.cloud-link-card__badge {
  background: #ffe9b3; color: #8a5b00; font-weight: 600;
  font-size: 0.68rem; letter-spacing: .02em; padding: 0.3em 0.55em;
}
.cloud-link-card__howto { font-size: 0.82rem; text-decoration: none; white-space: nowrap; }
.cloud-link-card__sub { font-size: 0.85rem; color: #5b6472; margin: 0.35rem 0 0.9rem 2.35rem; }
.cloud-link-rows { display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 0.75rem; }
.cloud-link-row { display: flex; align-items: center; gap: 0.5rem; }
.cloud-link-row input {
  flex: 1; background: #fff; border: 1px solid #dbe6fd; border-radius: 8px;
  padding: 0.55rem 0.8rem; font-size: 0.9rem;
}
.cloud-link-row input:focus { outline: none; border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67,97,238,.12); }
.cloud-link-row__remove {
  background: none; border: none; color: #8a8f98; font-size: 1.1rem;
  line-height: 1; padding: 0.2rem 0.4rem; flex-shrink: 0;
}
.cloud-link-row__remove:hover { color: #dc3545; }
.cloud-link-add {
  width: 100%; background: transparent; border: 1.5px dashed #a9c0fb; border-radius: 8px;
  color: #4361ee; font-weight: 600; font-size: 0.9rem; padding: 0.6rem; text-align: center;
}
.cloud-link-add:hover { background: #e3ecfe; }
.cloud-link-add:disabled { opacity: 0.5; cursor: not-allowed; }
</style>

<script>
(function () {
  // Kept in step with the server-side caps enforced in document_save.php.
  const MAX_LINKS = <?= (int)MAX_CLOUD_LINKS ?>;
  const MAX_LINK_LENGTH = <?= (int)MAX_CLOUD_LINK_LENGTH ?>;
  const rowsEl = document.getElementById('cloudLinkRows');
  const addBtn = document.getElementById('btnAddCloudLink');
  const countEl = document.getElementById('cloudLinkCount');

  function updateCount() {
    const n = rowsEl.children.length;
    countEl.textContent = `(${n} / ${MAX_LINKS})`;
    addBtn.disabled = n >= MAX_LINKS;
  }

  function addRow() {
    if (rowsEl.children.length >= MAX_LINKS) return;
    const row = document.createElement('div');
    row.className = 'cloud-link-row';
    row.innerHTML = `
      <input type="url" name="cloud_links[]" maxlength="${MAX_LINK_LENGTH}" placeholder="https://drive.google.com/...">
      <button type="button" class="cloud-link-row__remove" aria-label="Remove link"><i class="bi bi-x-lg"></i></button>
    `;
    row.querySelector('.cloud-link-row__remove').addEventListener('click', function () {
      if (rowsEl.children.length > 1) {
        row.remove();
      } else {
        row.querySelector('input').value = '';
      }
      updateCount();
    });
    rowsEl.appendChild(row);
    updateCount();
  }

  addBtn.addEventListener('click', addRow);

  // seed with one empty row on load
  if (rowsEl.children.length === 0) addRow();
})();
</script>

<?php
$extraScripts = <<<'HTML'
<script>
const SHOW_ARCHIVED = ARCHIVED_FLAG;
const CAN_ROUTE = CAN_ROUTE_FLAG;
const CURRENT_USER_ID = CURRENT_USER_ID_FLAG;
// Set from the query string only — these have no dropdown; they arrive when
// a Home analytics card links through, and the chip in the header shows them.
const FILTER_COMPLIANCE = 'COMPLIANCE_FLAG';
const FILTER_PERIOD = 'PERIOD_FLAG';
</script>
HTML;
$extraScripts = str_replace('ARCHIVED_FLAG', $showArchived ? 'true' : 'false', $extraScripts);
$extraScripts = str_replace('CAN_ROUTE_FLAG', $canRoute ? 'true' : 'false', $extraScripts);
$extraScripts = str_replace('CURRENT_USER_ID_FLAG', (string)(int)current_user()['id'], $extraScripts);
$extraScripts = str_replace('COMPLIANCE_FLAG', $filterCompliance, $extraScripts);
$extraScripts = str_replace('PERIOD_FLAG', $filterPeriod, $extraScripts);
$extraScripts .= '<script src="' . e(asset('assets/js/documents.js')) . '"></script>';
include __DIR__ . '/includes/footer.php';
?>
