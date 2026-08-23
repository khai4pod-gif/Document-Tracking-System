<?php
/**
 * document_view.php
 * Full detail view for a single document: metadata, attachments,
 * routing history, and the complete audit-trail timeline.
 * Also serves ?format=json (used by the Edit modal on documents.php).
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$pdo = Database::getConnection();
$documentModel = new Document($pdo);
$doc = $documentModel->find($id);

if (!$doc) {
    if (($_GET['format'] ?? '') === 'json') {
        json_response(['success' => false, 'message' => 'Document not found.'], 404);
    }
    http_response_code(404);
    die('Document not found.');
}

if (!$documentModel->isAccessibleTo($doc, current_user())) {
    if (($_GET['format'] ?? '') === 'json') {
        json_response(['success' => false, 'message' => 'Access denied: this document belongs to another department.'], 403);
    }
    http_response_code(403);
    die('Access denied: this document belongs to another department.');
}

// ---- JSON mode (consumed by the Edit modal) ----
if (($_GET['format'] ?? '') === 'json') {
    json_response([
        'success' => true,
        'document' => [
            'id'           => (int)$doc['id'],
            'title'        => $doc['title'],
            'doc_type'     => $doc['doc_type'],
            'priority'     => $doc['priority'],
            'description'  => $doc['description'],
            'due_date_raw' => $doc['due_date'],
        ],
    ]);
}

$attachments = $documentModel->getAttachments($id);
$links = $documentModel->getLinks($id);
$routes = $documentModel->getRoutes($id);
$logs = $documentModel->getLogs($id);
$currentUserId = (int)current_user()['id'];

// Is there a pending route addressed to the current user?
$pendingRouteForMe = null;
foreach ($routes as $r) {
    if ($r['status'] === 'Pending' && (int)$r['to_user_id'] === $currentUserId) {
        $pendingRouteForMe = $r;
        break;
    }
}

$logIcons = [
    'Created' => 'bi-file-earmark-plus', 'Updated' => 'bi-pencil-square', 'Routed' => 'bi-signpost-split',
    'Received' => 'bi-inbox', 'Completed' => 'bi-check-circle', 'Archived' => 'bi-archive',
    'Restored' => 'bi-arrow-counterclockwise', 'Attachment Added' => 'bi-paperclip', 'Attachment Removed' => 'bi-x-circle',
    'Approved' => 'bi-patch-check', 'Rejected' => 'bi-x-octagon',
];

$canApprove = in_array(current_user()['role'], ['admin', 'approver'], true);
$isPendingApproval = $doc['approval_status'] === 'Pending';
$canRoute = user_can_route(current_user(), $pdo);
// Approval gates closing the document out, not moving it along.
$blocksCompletion = in_array($doc['approval_status'], ['Pending', 'Rejected'], true);

$pageTitle = $doc['tracking_number'];
$pageIcon  = 'bi-file-earmark-text';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-start justify-content-between mb-4 gap-2">
  <div>
    <a href="documents.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to Documents</a>
    <div class="section-heading mt-1"><?= e($doc['title']) ?></div>
    <div class="d-flex align-items-center gap-2 mt-1">
      <span class="tracking-chip"><?= e($doc['tracking_number']) ?></span>
      <span class="badge <?= badge_class_for_priority($doc['priority']) ?>"><?= e($doc['priority']) ?></span>
      <span class="badge <?= badge_class_for_status($doc['status']) ?>"><?= e($doc['status']) ?></span>
      <?php if ($doc['approval_status'] !== 'Not Required'): ?>
        <span class="badge <?= badge_class_for_approval($doc['approval_status']) ?>"><?= e($doc['approval_status']) ?> Approval</span>
      <?php endif; ?>
      <?php if ((int)$doc['is_archived'] === 1): ?><span class="badge bg-dark">Archived</span><?php endif; ?>
    </div>
    <div class="mt-2 bg-white d-inline-block p-2 rounded border text-center">
      <div id="trackingQRCode"></div>
      <div class="small text-muted mt-1"><?= e($doc['tracking_number']) ?></div>
    </div>
  </div>

  <div class="d-flex gap-2 flex-wrap">
    <?php if ((int)$doc['is_archived'] === 0): ?>
      <?php if ($isPendingApproval && $canApprove): ?>
        <button class="btn btn-success btn-sm" id="btnApproveDoc"><i class="bi bi-patch-check me-1"></i> Approve</button>
        <button class="btn btn-outline-danger btn-sm" id="btnRejectDoc"><i class="bi bi-x-octagon me-1"></i> Reject</button>
      <?php endif; ?>
      <?php if ($pendingRouteForMe): ?>
        <button class="btn btn-success btn-sm" id="btnAcknowledge" data-route-id="<?= (int)$pendingRouteForMe['id'] ?>">
          <i class="bi bi-inbox-fill me-1"></i> Acknowledge Receipt
        </button>
      <?php endif; ?>
      <?php if (!$canRoute): ?>
        <button class="btn btn-outline-primary btn-sm" disabled title="Your account does not have permission to route documents.">
          <i class="bi bi-signpost-split me-1"></i> Route
        </button>
      <?php else: ?>
        <button class="btn btn-outline-primary btn-sm" id="btnRouteDoc"><i class="bi bi-signpost-split me-1"></i> Route</button>
      <?php endif; ?>
      <?php if ($blocksCompletion): ?>
        <button class="btn btn-outline-success btn-sm" disabled title="This document must be approved before it can be marked completed.">
          <i class="bi bi-check-circle me-1"></i> Mark Completed
        </button>
      <?php else: ?>
        <button class="btn btn-outline-success btn-sm" id="btnMarkComplete"><i class="bi bi-check-circle me-1"></i> Mark Completed</button>
      <?php endif; ?>
      <button class="btn btn-outline-secondary btn-sm" id="btnAddLink"><i class="bi bi-link-45deg me-1"></i> Add Link</button>
      <button class="btn btn-outline-secondary btn-sm" id="btnUploadAttachment"><i class="bi bi-paperclip me-1"></i> Add Attachment</button>
    <?php endif; ?>
    <button class="btn btn-outline-dark btn-sm" id="btnPrintSlip" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
  </div>
</div>

<!-- ===================== Print-only Routing & Tracking Slip ===================== -->
<div class="print-slip">
  <div class="print-slip__header">
    <div class="print-slip__brand">
      <div class="print-slip__brand-text">
        <img src="assets/img/dswd-logo.png" alt="DSWD Logo" class="topbar-logo"><div class="print-slip__brand-sub">Department of Social Welfare and Development</div>
      </div>
    </div>
    <div class="print-slip__brand print-slip__brand--right">
      <div class="print-slip__brand-text text-end">
        <div class="print-slip__brand-name">PASPAS</div>
        <div class="print-slip__brand-sub">WORK FASTER</div>
      </div>
    </div>
  </div>
  <div class="print-slip__rule"></div>

  <div class="print-slip__title">Routing and Tracking Slip</div>

  <div class="print-slip__top">
    <div class="print-slip__qr">
      <div id="printQRCode"></div>
      <div class="print-slip__qr-caption"><?= e($doc['tracking_number']) ?></div>
    </div>
    <table class="print-slip__meta">
      <tr><th>Subject / Title</th><td><?= e($doc['title']) ?></td></tr>
      <tr><th>DRN</th><td class="print-slip__drn"><?= e($doc['tracking_number']) ?></td></tr>
      <tr><th>Created By</th><td><?= e($doc['creator_name']) ?></td></tr>
      <tr><th>Originating Office</th><td><?= e($doc['origin_department_name'] ?? '—') ?></td></tr>
      <tr><th>Transaction Type</th><td><?= e($doc['doc_type']) ?></td></tr>
      <tr><th>Date Created</th><td><?= date('F j, Y', strtotime($doc['created_at'])) ?></td></tr>
    </table>
  </div>

  <table class="print-slip__routing">
    <thead>
      <tr>
        <th colspan="3">From</th>
        <th>Action / Notes</th>
        <th colspan="3">To</th>
      </tr>
      <tr>
        <th>Office</th><th>Date</th><th>Time</th>
        <th></th>
        <th>Office</th><th>Date</th><th>Time</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $slipRowCount = max(count($routes), 8);
      for ($i = 0; $i < $slipRowCount; $i++):
          $r = $routes[$i] ?? null;
      ?>
        <tr>
          <?php if ($r): ?>
            <td class="text-uppercase fw-semibold" colspan="1"><?= e($r['from_name']) ?></td>
            <td><?= date('d M y', strtotime($r['routed_at'])) ?></td>
            <td><?= date('g:i A', strtotime($r['routed_at'])) ?></td>
            <td>
              <?php if ($r['action_required']): ?>
                <?php $slipAction = route_action_parts((string)$r['action_required']); ?>
                <div class="print-slip__action">
                  <?php if ($slipAction['code'] !== ''): ?>
                    <span class="print-slip__action-code"><?= e($slipAction['code']) ?></span>
                  <?php endif; ?>
                  <?= e($slipAction['label']) ?>
                </div>
              <?php endif; ?>
              <?php if ($r['status'] === 'Pending'): ?>
                <span class="print-slip__status">Pending Acceptance</span>
              <?php elseif ($r['status'] === 'Returned'): ?>
                <span class="print-slip__status print-slip__status--returned">Returned</span>
              <?php else: ?>
                <span class="print-slip__status print-slip__status--received">Received</span>
              <?php endif; ?>
              <?php if ($r['remarks']): ?><div><?= e($r['remarks']) ?></div><?php endif; ?>
            </td>
            <td class="text-uppercase fw-semibold"><?= e($r['to_name']) ?></td>
            <td><?= $r['received_at'] ? date('d M y', strtotime($r['received_at'])) : '—' ?></td>
            <td><?= $r['received_at'] ? date('g:i A', strtotime($r['received_at'])) : '—' ?></td>
          <?php else: ?>
            <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td>
          <?php endif; ?>
        </tr>
      <?php endfor; ?>
    </tbody>
  </table>
</div>

<style>
.print-slip { display: none; }

@media print {
  body * { visibility: hidden; }
  .print-slip, .print-slip * { visibility: visible; }
  .print-slip {
    display: block;
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    padding: 24px 32px;
    font-family: Arial, Helvetica, sans-serif;
    color: #1a1a2e;
  }

  .print-slip__header { display: flex; justify-content: space-between; align-items: center; }
  .print-slip__brand { display: flex; align-items: center; gap: 10px; }
  .print-slip__brand--right { justify-content: flex-end; }
  .print-slip__brand-mark {
    width: 34px; height: 34px; border-radius: 6px; background: #1c3f94; color: #fff;
    display: flex; align-items: center; justify-content: center; font-size: 8px; font-weight: 700;
  }
  .print-slip__brand-name { font-weight: 800; font-size: 15px; letter-spacing: .02em; }
  .print-slip__brand-sub { font-size: 8.5px; color: #555; }

  .print-slip__rule { border-top: 3px solid #1c3f94; margin: 8px 0 14px; }

  .print-slip__title {
    text-align: center; font-weight: 800; font-size: 13px; letter-spacing: .06em;
    text-transform: uppercase; margin-bottom: 16px;
  }

  .print-slip__top { display: flex; gap: 18px; margin-bottom: 18px; }
  .print-slip__qr {
    width: 150px; flex-shrink: 0; border: 1px solid #ccc; border-radius: 6px;
    padding: 10px; text-align: center;
  }
  .print-slip__qr-caption { font-size: 8px; letter-spacing: .03em; margin-top: 6px; word-break: break-all; }

  .print-slip__meta { flex: 1; border-collapse: collapse; font-size: 11px; }
  .print-slip__meta th, .print-slip__meta td { border-bottom: 1px solid #e3e3e8; padding: 6px 4px; text-align: left; vertical-align: top; }
  .print-slip__meta th {
    width: 34%; color: #555; font-weight: 700; text-transform: uppercase; font-size: 9.5px; letter-spacing: .03em;
  }
  .print-slip__meta td { font-weight: 700; }
  .print-slip__drn { color: #1c3f94; }

  .print-slip__routing { width: 100%; border-collapse: collapse; font-size: 10px; table-layout: fixed; }
  .print-slip__routing th, .print-slip__routing td {
    border: 1px solid #999; padding: 6px 6px; text-align: left; vertical-align: top;
  }
  .print-slip__routing thead th {
    text-transform: uppercase; font-size: 9.5px; letter-spacing: .03em; text-align: center; background: #f2f3f7;
  }
  .print-slip__routing tbody td { height: 30px; }
  .print-slip__action {
    font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: .02em;
    line-height: 1.25; margin-bottom: 3px;
  }
  .print-slip__action-code {
    display: inline-block; font-weight: 700; font-size: 9px;
    border: 1px solid #333; border-radius: 2px; padding: 0 3px; margin-right: 3px;
  }
  .print-slip__status {
    display: inline-block; font-size: 8.5px; font-weight: 700; text-transform: uppercase;
    background: #fdecc8; color: #8a5b00; padding: 1px 6px; border-radius: 3px;
  }
  .print-slip__status--received { background: #d9f2e3; color: #1a7a45; }
  .print-slip__status--returned { background: #fadada; color: #a4262c; }
}
</style>

<div class="row g-3">
  <!-- Left column -->
  <div class="col-lg-8">

    <div class="card-panel mb-3">
      <div class="card-panel-header">Document Details</div>
      <div class="p-3">
        <div class="row g-3">
          <div class="col-sm-6"><div class="text-muted small">Type</div><div class="fw-semibold"><?= e($doc['doc_type']) ?></div></div>
          <div class="col-sm-6"><div class="text-muted small">Origin Office</div><div class="fw-semibold"><?= e($doc['origin_department_name'] ?? '—') ?></div></div>
          <div class="col-sm-6"><div class="text-muted small">Created By</div><div class="fw-semibold"><?= e($doc['creator_name']) ?></div></div>
          <div class="col-sm-6"><div class="text-muted small">Current Holder</div><div class="fw-semibold"><?= e($doc['holder_name'] ?? '—') ?></div></div>
          <div class="col-sm-6"><div class="text-muted small">Due Date</div><div class="fw-semibold"><?= $doc['due_date'] ? date('M d, Y', strtotime($doc['due_date'])) : '—' ?></div></div>
          <div class="col-sm-6"><div class="text-muted small">Date Created</div><div class="fw-semibold"><?= date('M d, Y g:i A', strtotime($doc['created_at'])) ?></div></div>
          <div class="col-12">
            <div class="text-muted small">Description</div>
            <div><?= $doc['description'] ? nl2br(e($doc['description'])) : '<span class="text-muted">No description provided.</span>' ?></div>
          </div>
          <?php if ((int)$doc['is_archived'] === 1 && !empty($doc['conclusion_remarks'])): ?>
          <div class="col-12">
            <div class="text-muted small">Conclusion Remarks</div>
            <div class="alert alert-secondary py-2 px-3 mb-0 mt-1">
              <i class="bi bi-archive me-1"></i><?= nl2br(e($doc['conclusion_remarks'])) ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card-panel mb-3">
      <div class="card-panel-header d-flex justify-content-between">
        <span>Attachments &amp; Links</span>
        <span class="badge bg-secondary"><?= count($attachments) + count($links) ?></span>
      </div>
      <div class="p-3">
        <?php if (empty($attachments) && empty($links)): ?>
          <div class="text-muted text-center py-3">No attachments or links added yet.</div>
        <?php endif; ?>

        <?php if (!empty($links)): ?>
          <ul class="list-group list-group-flush mb-2" id="linkList">
            <?php foreach ($links as $l): ?>
              <?php $host = parse_url($l['url'], PHP_URL_HOST) ?: $l['url']; ?>
              <li class="list-group-item d-flex justify-content-between align-items-center px-0" data-link-id="<?= (int)$l['id'] ?>">
                <div class="d-flex align-items-center gap-2" style="min-width:0;">
                  <i class="bi bi-link-45deg fs-5 text-primary"></i>
                  <div style="min-width:0;">
                    <div class="fw-semibold small text-truncate" title="<?= e($l['url']) ?>"><?= e($host) ?></div>
                    <div class="text-muted text-truncate" style="font-size:.72rem;" title="<?= e($l['url']) ?>">
                      Added by <?= e($l['added_by_name']) ?> on <?= date('M d, Y', strtotime($l['added_at'])) ?>
                    </div>
                  </div>
                </div>
                <div class="d-flex gap-2 flex-shrink-0">
                  <a href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer"
                     class="btn btn-sm btn-outline-primary" title="Open link"><i class="bi bi-box-arrow-up-right"></i></a>
                  <?php if ((int)$doc['is_archived'] === 0): ?>
                  <button class="btn btn-sm btn-outline-danger btn-delete-link" data-id="<?= (int)$l['id'] ?>"><i class="bi bi-trash"></i></button>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (!empty($attachments)): ?>
          <ul class="list-group list-group-flush" id="attachmentList">
            <?php foreach ($attachments as $a): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center px-0" data-attachment-id="<?= (int)$a['id'] ?>">
                <div class="d-flex align-items-center gap-2">
                  <i class="bi bi-file-earmark-pdf fs-5 text-danger"></i>
                  <div>
                    <div class="fw-semibold small"><?= e($a['original_name']) ?></div>
                    <div class="text-muted" style="font-size:.72rem;">
                      <?= round($a['file_size'] / 1024, 1) ?> KB · Uploaded by <?= e($a['uploader_name']) ?> on <?= date('M d, Y', strtotime($a['uploaded_at'])) ?>
                    </div>
                  </div>
                </div>
                <div class="d-flex gap-2">
                  <a href="download.php?attachment=<?= (int)$a['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i></a>
                  <?php if ((int)$doc['is_archived'] === 0): ?>
                  <button class="btn btn-sm btn-outline-danger btn-delete-attachment" data-id="<?= (int)$a['id'] ?>"><i class="bi bi-trash"></i></button>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-panel">
      <div class="card-panel-header">Routing History</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>From</th><th>To</th><th>Action Required</th><th>Status</th><th>Routed</th><th>Received</th></tr></thead>
          <tbody>
            <?php if (empty($routes)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">This document has not been routed yet.</td></tr>
            <?php else: foreach ($routes as $r): ?>
              <tr>
                <td><?= e($r['from_name']) ?></td>
                <td><?= e($r['to_name']) ?></td>
                <td><?= e($r['action_required']) ?><?php if ($r['remarks']): ?><div class="text-muted small"><?= e($r['remarks']) ?></div><?php endif; ?></td>
                <td><span class="badge <?= $r['status'] === 'Received' ? 'bg-success' : ($r['status'] === 'Returned' ? 'bg-danger' : 'bg-warning text-dark') ?>"><?= e($r['status']) ?></span></td>
                <td><?= date('M d, Y g:i A', strtotime($r['routed_at'])) ?></td>
                <td><?= $r['received_at'] ? date('M d, Y g:i A', strtotime($r['received_at'])) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Right column: Audit trail -->
  <div class="col-lg-4">
    <div class="card-panel">
      <div class="card-panel-header">Audit Trail</div>
      <div class="p-3" style="max-height:640px;overflow-y:auto;">
        <?php if (empty($logs)): ?>
          <div class="text-muted text-center py-4">No history recorded.</div>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($logs as $log): ?>
              <div class="timeline-item">
                <span class="timeline-dot"><i class="bi <?= $logIcons[$log['action']] ?? 'bi-dot' ?>"></i></span>
                <div class="timeline-title"><?= e($log['action']) ?></div>
                <div class="timeline-detail"><?= e($log['actor_name']) ?> <span class="text-muted">(<?= e(role_label($log['actor_role'])) ?>)</span></div>
                <?php if ($log['details']): ?><div class="timeline-detail text-muted"><?= e($log['details']) ?></div><?php endif; ?>
                <div class="timeline-meta"><?= date('M d, Y g:i:s A', strtotime($log['created_at'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Route Modal -->
<div class="modal fade" id="routeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="routeForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-signpost-split me-2"></i>Route Document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
          <div class="mb-3">
            <label class="form-label">Route To <span class="text-danger">*</span></label>
            <select name="to_user_id" id="routeToUser" class="form-select" required><option value="">Loading users…</option></select>
          </div>
          <div class="mb-3">
            <label class="form-label">Action Required <span class="text-danger">*</span></label>
            <select name="action_required" class="form-select" required>
              <option value="">Select action required…</option>
              <?php foreach (route_action_options() as $__action): ?>
                <option value="<?= e($__action) ?>"><?= e($__action) ?></option>
              <?php endforeach; ?>
            </select>
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

<!-- Add Cloud Link Modal -->
<div class="modal fade" id="linkModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="linkForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-link-45deg me-2"></i>Add Cloud Link</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
          <label class="form-label">Link <span class="text-danger">*</span></label>
          <input type="url" name="url" id="fieldLinkUrl" class="form-control"
                 placeholder="https://drive.google.com/..." maxlength="<?= MAX_CLOUD_LINK_LENGTH ?>" required>
          <div class="form-text">
            Paste a share link from Google Drive, OneDrive, SharePoint, or any other web address.
            Make sure the recipients have permission to open it.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Link</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Upload Attachment Modal -->
<div class="modal fade" id="attachmentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="attachmentForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-paperclip me-2"></i>Add Attachment</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
          <label class="form-label">File <span class="text-muted small">(PDF or Word, max 10MB)</span></label>
          <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i> Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>';
$extraScripts .= '<script>
document.addEventListener("DOMContentLoaded", function () {
  new QRCode(document.getElementById("trackingQRCode"), {
    text: ' . json_encode($doc['tracking_number']) . ',
    width: 128,
    height: 128,
    correctLevel: QRCode.CorrectLevel.M,
  });
  new QRCode(document.getElementById("printQRCode"), {
    text: ' . json_encode($doc['tracking_number']) . ',
    width: 120,
    height: 120,
    correctLevel: QRCode.CorrectLevel.M,
  });
});
</script>';
$extraScripts .= '<script src="' . e(asset('assets/js/document_view.js')) . '"></script>';
include __DIR__ . '/includes/footer.php';
?>
