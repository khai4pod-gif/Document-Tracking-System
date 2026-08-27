<?php
/**
 * home.php
 * Landing page: greets the user and offers a fast way into a specific
 * document — either by scanning its barcode with the device camera, or
 * by typing its tracking number / title.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$pdo = Database::getConnection();
$documentModel = new Document($pdo);
// Same scoping rule as the dashboard: oversight offices see agency-wide
// figures, everyone else sees only the documents they created.
$seesAll = user_sees_all_documents(current_user(), $pdo);
$scopeCreatorId = $seesAll ? null : (int)current_user()['id'];
$stats = $documentModel->getStats($scopeCreatorId);
$statusBreakdown = $documentModel->getStatusBreakdown($scopeCreatorId);
$statusTotal = array_sum($statusBreakdown);
// Office analytics follow the same oversight rule as the dashboard tiles:
// the Administrator's and the Secretary's offices consolidate every office,
// everyone else sees their own. Accounts with no department also consolidate.
$officeDeptId = null;
$officeName   = 'All Offices';

if (!$seesAll && !empty(current_user()['department_id'])) {
    $officeDeptId = (int)current_user()['department_id'];
    $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $officeDeptId]);
    $officeName = (string)($stmt->fetchColumn() ?: 'My Office');
}

$statusColors = [
    'Draft'            => '#8a93a3',
    'Pending Routing'  => '#f2994a',
    'In Transit'       => '#2f80ed',
    'Received'         => '#5b5fc7',
    'Completed'        => '#1e9e6b',
    'Overdue'          => '#e0473f',
];
$pageTitle = 'Home';
$pageIcon  = 'bi-house-door';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
  <div class="section-heading"><?= e($greeting) ?>, <?= e(explode(' ', $__user['full_name'])[0]) ?></div>
  <div class="section-sub"><?= date('l, F j, Y') ?></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-sm-4">
    <a class="kpi-card" href="documents.php" title="View all documents">
      <div class="kpi-icon" style="background:#e8f0fe;color:var(--accent);"><i class="bi bi-files"></i></div>
      <div><div class="kpi-value"><?= number_format($stats['total']) ?></div><div class="kpi-label"><?= $seesAll ? 'Total Documents' : 'My Documents' ?></div></div>
    </a>
  </div>
  <div class="col-sm-4">
    <a class="kpi-card" href="documents.php?status=Pending+Routing" title="View documents pending routing">
      <div class="kpi-icon" style="background:#fff4e5;color:var(--accent-2);"><i class="bi bi-signpost-split"></i></div>
      <div><div class="kpi-value"><?= number_format($stats['pending_routing']) ?></div><div class="kpi-label">Pending Routing</div></div>
    </a>
  </div>
  <div class="col-sm-4">
    <a class="kpi-card" href="documents.php?status=Overdue" title="View overdue documents">
      <div class="kpi-icon" style="background:#fdeceb;color:var(--danger);"><i class="bi bi-exclamation-triangle"></i></div>
      <div><div class="kpi-value"><?= number_format($stats['overdue']) ?></div><div class="kpi-label">Overdue</div></div>
    </a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-panel">
      <div class="card-panel-header">Scan a Document Barcode</div>
      <div class="p-3">

        <style>
          /* Transparent scanner frame spanning the full card width, so it
             lines up with the search field beneath it instead of floating
             as a narrow square. Overrides assets/css/style.css defaults for
             #scannerFrame just for this page. */
          #scannerFrame.scanner-frame {
            width: 100%;
            max-width: none;
            margin: 0;
            /* Camera preview is landscape, so a landscape frame wastes less
               room than the old square and crops less of the picture. */
            aspect-ratio: 16 / 9;
            min-height: 220px;
            max-height: 420px;
            background: transparent;
          }

          /* Thin light-blue border while idle, before scanning starts */
          #scannerFrame.scanner-frame:has(#scannerOverlayIdle:not(.d-none)) {
            border: 1px solid #7ec8e3;
          }

          /* Legend swatch for the status report list */
          .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
          }
        </style>

        <div id="scannerFrame" class="scanner-frame">
          <div id="scannerViewport"></div>

          <div id="scannerOverlayIdle" class="scanner-overlay">
            <div class="scanner-icon"><i class="bi bi-camera"></i></div>
            <button type="button" class="btn btn-primary" id="btnStartScan">
              <i class="bi bi-camera-fill me-1"></i> Start Scanner
            </button>
          </div>

          <div id="scannerOverlayError" class="scanner-overlay d-none">
            <div class="scanner-icon"><i class="bi bi-camera-video-off"></i></div>
            <div class="scanner-error-text" id="scannerErrorText">Camera permission denied. Please allow camera access.</div>
            <div class="scanner-error-hint d-none" id="scannerErrorHint"></div>
            <button type="button" class="btn btn-primary" id="btnRetryScan">Try Again</button>
          </div>
        </div>

        <div class="text-muted small mt-2">
          Point your camera at the QR code on a printed routing slip, filling most of the
          frame and holding it steady. Works best in Chrome/Edge.
        </div>

        <hr class="my-3">

        <label class="form-label small text-muted mb-1">Or search by tracking number or title</label>
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
          <input type="text" class="form-control" id="quickSearchInput" placeholder="e.g. DOC-2026-000123 or document title…">
          <button class="btn btn-primary" id="btnQuickSearch">Search</button>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5 d-flex flex-column gap-3">
    <div class="card-panel">
      <div class="card-panel-header">Quick Links</div>
      <div class="p-3 d-flex flex-column gap-2">
        <a href="documents.php" class="btn btn-outline-secondary text-start"><i class="bi bi-file-earmark-text me-2"></i>All Documents</a>
        <a href="dashboard.php" class="btn btn-outline-secondary text-start"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
        <a href="relief_dashboard.php" class="btn btn-outline-secondary text-start"><i class="bi bi-bar-chart-line me-2"></i>Relief Dashboard</a>
      </div>
    </div>

    <div class="card-panel">
      <div class="card-panel-header d-flex justify-content-between align-items-center">
        <span><?= $seesAll ? 'Document Status Report' : 'My Document Status Report' ?></span>
        <span class="text-muted small"><?= number_format($statusTotal) ?> total</span>
      </div>
      <div class="p-3">
        <?php if ($statusTotal === 0): ?>
          <div class="text-center text-muted py-4">No documents yet.</div>
        <?php else: ?>
          <div style="position:relative;height:190px;">
            <canvas id="statusReportChart"></canvas>
          </div>
          <div class="d-flex flex-column gap-1 mt-3">
            <?php foreach ($statusBreakdown as $status => $count): ?>
              <div class="d-flex justify-content-between align-items-center small">
                <span><span class="status-dot" style="background:<?= e($statusColors[$status]) ?>"></span> <?= e($status) ?></span>
                <span class="fw-semibold"><?= number_format($count) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-0">
  <div class="col-12">
    <div class="card-panel">
      <div class="card-panel-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <div>Individual Performance</div>
          <div class="text-muted small fw-normal" id="perfSubtitle">By due date &middot; This Month</div>
        </div>
        <div class="perf-tabs" id="perfTabs">
          <button type="button" class="perf-tab" data-period="as_of_today">As of Today</button>
          <button type="button" class="perf-tab" data-period="today">Today</button>
          <button type="button" class="perf-tab" data-period="week">This Week</button>
          <button type="button" class="perf-tab active" data-period="month">Month</button>
          <button type="button" class="perf-tab" data-period="quarter">Quarter</button>
          <button type="button" class="perf-tab" data-period="year">Year</button>
        </div>
      </div>
      <div class="p-3">
        <div id="perfEmptyNote" class="alert alert-light border text-muted small d-none py-2 px-3"></div>
        <div class="row g-3">
          <div class="col-md-6 col-xl-3">
            <div class="perf-card">
              <div class="perf-card-body">
                <div class="perf-card-title"><i class="bi bi-file-earmark-text" style="color:var(--accent);"></i> Documents Assigned</div>
                <div class="perf-value" id="perfAssignedTotal">0</div>
                <div class="perf-sub-row">
                  <div class="perf-sub">For Action<b id="perfForAction">0</b></div>
                  <div class="perf-sub">Pending for Receipt<b id="perfPendingReceipt">0</b></div>
                </div>
              </div>
              <div class="perf-accent" style="background:var(--accent);"></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="perf-card">
              <div class="perf-card-body">
                <div class="perf-card-title"><i class="bi bi-hourglass-split" style="color:var(--accent-2);"></i> Active Documents (Pending)</div>
                <div class="perf-value" id="perfActiveTotal">0</div>
                <div class="perf-sub-row">
                  <div class="perf-sub">Backlog<b id="perfBacklog" style="color:var(--danger);">0</b></div>
                  <div class="perf-sub">Due Today<b id="perfDueToday">0</b></div>
                  <div class="perf-sub">Due in 3 Days<b id="perfDue3Days">0</b></div>
                  <div class="perf-sub">No Deadline<b id="perfNoDeadline">0</b></div>
                </div>
              </div>
              <div class="perf-accent" style="background:var(--accent-2);"></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="perf-card">
              <div class="perf-card-body">
                <div class="perf-card-title"><i class="bi bi-check-circle" style="color:var(--success);"></i> Resolved Documents</div>
                <div class="perf-value" id="perfResolvedTotal">0</div>
                <div class="perf-sub-row">
                  <div class="perf-sub">Compliant<b id="perfCompliant" style="color:var(--success);">0</b></div>
                  <div class="perf-sub">Non-Compliant<b id="perfNonCompliant" style="color:var(--danger);">0</b></div>
                  <div class="perf-sub">Exempt<b id="perfExempt">0</b></div>
                </div>
              </div>
              <div class="perf-accent" style="background:var(--success);"></div>
            </div>
          </div>

          <div class="col-md-6 col-xl-3">
            <div class="perf-card">
              <div class="perf-card-body text-center">
                <div class="perf-card-title justify-content-center"><i class="bi bi-pie-chart" style="color:var(--success);"></i> Compliance Rate</div>
                <div class="text-muted small mb-3">Compliant ÷ (Compliant + Non-Compliant)<br>exempt excluded</div>
                <div class="perf-gauge">
                  <canvas id="complianceGauge"></canvas>
                  <div class="perf-gauge-label">
                    <span class="pct" id="perfCompliancePct">—</span>
                    <span class="tag" id="perfComplianceTag"></span>
                  </div>
                </div>
              </div>
              <div class="perf-accent" style="background:var(--success);"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===================== Office Document Tracking ===================== -->
<div class="row g-3 mt-0">
  <div class="col-12">
    <div class="card-panel">
      <div class="card-panel-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <div>Office Document Tracking — <?= e(strtoupper($officeName)) ?></div>
          <div class="text-muted small fw-normal" id="officeSubtitle">By due date &middot; This Month</div>
        </div>
        <div class="perf-tabs" id="officeTabs">
          <button type="button" class="perf-tab" data-period="as_of_today">As of Today</button>
          <button type="button" class="perf-tab" data-period="today">Today</button>
          <button type="button" class="perf-tab" data-period="week">This Week</button>
          <button type="button" class="perf-tab active" data-period="month">Month</button>
          <button type="button" class="perf-tab" data-period="quarter">Quarter</button>
          <button type="button" class="perf-tab" data-period="year">Year</button>
        </div>
      </div>

      <div class="p-3">
        <div id="offEmptyNote" class="alert alert-light border text-muted small d-none py-2 px-3"></div>
        <div class="row g-3">
          <div class="col-md-6 col-xl-4">
            <div class="perf-card">
              <div class="perf-card-body">
                <div class="perf-card-title"><i class="bi bi-file-earmark-text" style="color:var(--accent);"></i> Total Documents</div>
                <div class="perf-value" id="offTotal">0</div>
                <div class="perf-sub-row">
                  <div class="perf-sub">Created Internally<b id="offInternal" style="color:var(--success);">0</b></div>
                  <div class="perf-sub">Routed In<b id="offRoutedIn" style="color:var(--accent);">0</b></div>
                  <div class="perf-sub">No Deadline<b id="offTotalExempt">0</b></div>
                </div>
              </div>
              <div class="perf-accent" style="background:var(--accent);"></div>
            </div>
          </div>

          <div class="col-md-6 col-xl-4">
            <div class="perf-card">
              <div class="perf-card-body">
                <div class="perf-card-title"><i class="bi bi-pause-circle" style="color:#8557d3;"></i> Deferred Documents</div>
                <div class="perf-value" id="offDeferred">0</div>
                <div class="text-muted small">Archived without being completed</div>
              </div>
              <div class="perf-accent" style="background:#8557d3;"></div>
            </div>
          </div>

          <div class="col-md-6 col-xl-4">
            <div class="perf-card">
              <div class="perf-card-body">
                <div class="perf-card-title"><i class="bi bi-inbox" style="color:var(--accent);"></i> Office for Receipt</div>
                <div class="perf-value" id="offForReceipt">0</div>
                <div class="text-muted small">Routed here, not yet acknowledged</div>
              </div>
              <div class="perf-accent" style="background:var(--accent);"></div>
            </div>
          </div>

          <div class="col-md-6 col-xl-4">
            <div class="perf-card">
              <div class="perf-card-body">
                <div class="perf-card-title"><i class="bi bi-check-circle" style="color:var(--success);"></i> Resolved Documents</div>
                <div class="perf-value" id="offResolved">0</div>
                <div class="perf-sub-row">
                  <div class="perf-sub">Compliant<b id="offCompliant" style="color:var(--success);">0</b></div>
                  <div class="perf-sub">Non-Compliant<b id="offNonCompliant" style="color:var(--danger);">0</b></div>
                  <div class="perf-sub">Exempt<b id="offResolvedExempt">0</b></div>
                </div>
              </div>
              <div class="perf-accent" style="background:var(--success);"></div>
            </div>
          </div>

          <div class="col-md-6 col-xl-4">
            <div class="perf-card">
              <div class="perf-card-body">
                <div class="perf-card-title"><i class="bi bi-hourglass-split" style="color:var(--accent-2);"></i> Active Documents (Pending)</div>
                <div class="perf-value" id="offActive">0</div>
                <div class="perf-sub-row">
                  <div class="perf-sub">Backlog<b id="offBacklog" style="color:var(--danger);">0</b></div>
                  <div class="perf-sub">Due Today<b id="offDueToday">0</b></div>
                  <div class="perf-sub">Due in 3 Days<b id="offDue3Days">0</b></div>
                  <div class="perf-sub">No Deadline<b id="offNoDeadline">0</b></div>
                </div>
              </div>
              <div class="perf-accent" style="background:var(--accent-2);"></div>
            </div>
          </div>

          <div class="col-md-6 col-xl-4">
            <div class="perf-card">
              <div class="perf-card-body text-center">
                <div class="perf-card-title justify-content-center"><i class="bi bi-pie-chart" style="color:var(--success);"></i> Overall Compliance Rate</div>
                <div class="text-muted small mb-3">
                  Compliant ÷ (Compliant + Non-Compliant) ·
                  <span id="offExemptNote">exempt excluded</span>
                </div>
                <div class="perf-gauge">
                  <canvas id="officeComplianceGauge"></canvas>
                  <div class="perf-gauge-label">
                    <span class="pct" id="offCompliancePct">—</span>
                    <span class="tag" id="offComplianceTag"></span>
                  </div>
                </div>
              </div>
              <div class="perf-accent" style="background:var(--success);"></div>
            </div>
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-12">
            <div class="perf-card">
              <div class="perf-card-body">
                <div class="perf-card-title"><i class="bi bi-graph-up" style="color:var(--success);"></i> Compliance Rate over Time</div>
                <div class="text-muted small mb-2">By month completed · last 6 months</div>
                <div id="offTrendEmpty" class="text-center text-muted py-4 d-none">
                  No completed documents with a deadline yet.
                </div>
                <div id="offTrendWrap" style="position:relative;height:260px;">
                  <canvas id="officeComplianceTrend"></canvas>
                </div>
              </div>
              <div class="perf-accent" style="background:var(--success);"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Multiple-match results modal -->
<div class="modal fade" id="searchResultsModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-list-ul me-2"></i>Multiple Matches Found</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="searchResultsBody"></div>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script>
const STATUS_LABELS = ' . json_encode(array_keys($statusBreakdown)) . ';
const STATUS_DATA = ' . json_encode(array_values($statusBreakdown)) . ';
const STATUS_COLORS = ' . json_encode(array_values($statusColors)) . ';
</script>';
$extraScripts .= '<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>';
$extraScripts .= '<script src="' . e(asset('assets/js/home.js')) . '"></script>';
include __DIR__ . '/includes/footer.php';
?>
