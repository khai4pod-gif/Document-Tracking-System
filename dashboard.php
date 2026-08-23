<?php
/**
 * dashboard.php
 * Document Tracking System dashboard: KPI overview + recent activity.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = Database::getConnection();
$documentModel = new Document($pdo);

// Oversight offices (Administrator / Secretary) see agency-wide figures;
// every other account sees only the documents it created.
$seesAll = user_sees_all_documents(current_user(), $pdo);
$scopeCreatorId = $seesAll ? null : (int)current_user()['id'];

$stats  = $documentModel->getStats($scopeCreatorId);
$recent = $documentModel->getRecent(8, $scopeCreatorId);
$activity = $documentModel->getRecentActivity(10, $scopeCreatorId);

$pageTitle = 'Dashboard';
$pageIcon  = 'bi-speedometer2';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading">Welcome back, <?= e(explode(' ', $__user['full_name'])[0]) ?> 👋</div>
    <div class="section-sub">
      <?= $seesAll
            ? "Here's what's happening across document tracking today."
            : "Here's what's happening with the documents you created." ?>
    </div>
  </div>
  <a href="documents.php" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i> New Document
  </a>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#e9eef6;color:var(--accent);"><i class="bi bi-files"></i></div>
      <div>
        <div class="kpi-value"><?= number_format($stats['total']) ?></div>
        <div class="kpi-label"><?= $seesAll ? 'Total Documents' : 'My Documents' ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#f6e8cf;color:var(--accent-2);"><i class="bi bi-signpost-split"></i></div>
      <div>
        <div class="kpi-value"><?= number_format($stats['pending_routing']) ?></div>
        <div class="kpi-label">Pending Routing</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#e6f7ef;color:var(--success);"><i class="bi bi-check-circle"></i></div>
      <div>
        <div class="kpi-value"><?= number_format($stats['completed']) ?></div>
        <div class="kpi-label">Completed</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#fdeceb;color:var(--danger);"><i class="bi bi-exclamation-triangle"></i></div>
      <div>
        <div class="kpi-value"><?= number_format($stats['overdue']) ?></div>
        <div class="kpi-label">Overdue</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Recent Documents -->
  <div class="col-lg-7">
    <div class="card-panel h-100">
      <div class="card-panel-header d-flex justify-content-between align-items-center">
        <span><?= $seesAll ? 'Recent Documents' : 'My Recent Documents' ?></span>
        <a href="documents.php" class="small text-decoration-none">View all <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead>
            <tr>
              <th>Tracking #</th>
              <th>Title</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Holder</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recent)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No documents yet.</td></tr>
            <?php else: foreach ($recent as $doc): ?>
              <tr>
                <td><a href="document_view.php?id=<?= (int)$doc['id'] ?>" class="tracking-chip text-decoration-none"><?= e($doc['tracking_number']) ?></a></td>
                <td><?= e($doc['title']) ?></td>
                <td><span class="badge <?= badge_class_for_priority($doc['priority']) ?>"><?= e($doc['priority']) ?></span></td>
                <td><span class="badge <?= badge_class_for_status($doc['status']) ?>"><?= e($doc['status']) ?></span></td>
                <td><?= e($doc['holder_name'] ?? '—') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Activity Feed -->
  <div class="col-lg-5">
    <div class="card-panel h-100">
      <div class="card-panel-header">Recent Activity</div>
      <div class="p-3">
        <?php if (empty($activity)): ?>
          <div class="text-center text-muted py-4">No activity recorded yet.</div>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($activity as $log): ?>
              <div class="timeline-item">
                <span class="timeline-dot"><i class="bi bi-clock-history"></i></span>
                <div class="timeline-title"><?= e($log['actor_name']) ?> <span class="fw-normal text-muted">— <?= e($log['action']) ?></span></div>
                <div class="timeline-detail"><?= e($log['title']) ?> <span class="tracking-chip"><?= e($log['tracking_number']) ?></span></div>
                <div class="timeline-meta"><?= date('M d, Y g:i A', strtotime($log['created_at'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
