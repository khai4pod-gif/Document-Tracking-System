<?php
/**
 * documents.php
 * Document management: DataTable listing + Create/Edit/Route modals (AJAX).
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$showArchived = isset($_GET['archived']) && $_GET['archived'] === '1';
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
      <option>Draft</option>
      <option>Pending Routing</option>
      <option>In Transit</option>
      <option>Received</option>
      <option>Completed</option>
      <option>Overdue</option>
    </select>
    <select class="form-select form-select-sm" style="width:auto;" id="filterPriority">
      <option value="">All Priorities</option>
      <option>Low</option>
      <option>Normal</option>
      <option>High</option>
      <option>Urgent</option>
    </select>
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
            </div>
            <div class="col-md-6">
              <label class="form-label">Due Date</label>
              <input type="date" name="due_date" id="fieldDueDate" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="fieldDescription" class="form-control" rows="3" maxlength="2000"></textarea>
            </div>
            <div class="col-12" id="attachmentWrapper">
              <label class="form-label mb-2">Attachments <span class="text-muted small">(Optional)</span></label>

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
                <label class="form-label small text-muted">Or upload a file instead <span class="text-muted">(PDF or Word, max 10MB)</span></label>
                <input type="file" name="attachment" id="fieldAttachment" class="form-control" accept=".pdf,.doc,.docx">
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

<!-- ===================== Route Document Modal ===================== -->
<div class="modal fade" id="routeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="routeForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-signpost-split me-2"></i>Route Document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="document_id" id="routeDocumentId">
          <div class="mb-3">
            <label class="form-label">Route To <span class="text-danger">*</span></label>
            <select name="to_user_id" id="routeToUser" class="form-select" required>
              <option value="">Loading users…</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Action Required <span class="text-danger">*</span></label>
            <input type="text" name="action_required" class="form-control" placeholder="e.g. Review and approve" required maxlength="255">
          </div>
          <div class="mb-1">
            <label class="form-label">Remarks</label>
            <textarea name="remarks" class="form-control" rows="3" placeholder="Optional notes for the recipient"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Route Document</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
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
  const MAX_LINKS = 20;
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
      <input type="url" name="cloud_links[]" placeholder="https://drive.google.com/...">
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
</script>
HTML;
$extraScripts = str_replace('ARCHIVED_FLAG', $showArchived ? 'true' : 'false', $extraScripts);
$extraScripts = str_replace('CAN_ROUTE_FLAG', $canRoute ? 'true' : 'false', $extraScripts);
$extraScripts .= '<script src="assets/js/documents.js"></script>';
include __DIR__ . '/includes/footer.php';
?>
