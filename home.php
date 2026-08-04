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
$stats = $documentModel->getStats();

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
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#e8f0fe;color:var(--accent);"><i class="bi bi-files"></i></div>
      <div><div class="kpi-value"><?= number_format($stats['total']) ?></div><div class="kpi-label">Total Documents</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#fff4e5;color:var(--accent-2);"><i class="bi bi-signpost-split"></i></div>
      <div><div class="kpi-value"><?= number_format($stats['pending_routing']) ?></div><div class="kpi-label">Pending Routing</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#fdeceb;color:var(--danger);"><i class="bi bi-exclamation-triangle"></i></div>
      <div><div class="kpi-value"><?= number_format($stats['overdue']) ?></div><div class="kpi-label">Overdue</div></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-panel">
      <div class="card-panel-header">Scan a Document Barcode</div>
      <div class="p-3">

        <style>
          /* Compact, transparent scanner frame — overrides assets/css/style.css
             defaults for #scannerFrame just for this page. */
          #scannerFrame.scanner-frame {
            max-width: 320px;
            aspect-ratio: 1 / 1;
            margin: 0 auto;
            background: transparent;
          }

          /* Thin light-blue border while idle, before scanning starts */
          #scannerFrame.scanner-frame:has(#scannerOverlayIdle:not(.d-none)) {
            border: 1px solid #7ec8e3;
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
            <button type="button" class="btn btn-primary" id="btnRetryScan">Try Again</button>
          </div>
        </div>

        <div class="text-muted small mt-2">
          Point your camera at a document's printed barcode. Works best in Chrome/Edge.
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

  <div class="col-lg-5">
    <div class="card-panel h-100">
      <div class="card-panel-header">Quick Links</div>
      <div class="p-3 d-flex flex-column gap-2">
        <a href="documents.php" class="btn btn-outline-secondary text-start"><i class="bi bi-file-earmark-text me-2"></i>All Documents</a>
        <a href="dashboard.php" class="btn btn-outline-secondary text-start"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
        <a href="relief_dashboard.php" class="btn btn-outline-secondary text-start"><i class="bi bi-bar-chart-line me-2"></i>Relief Dashboard</a>
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
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>';
$extraScripts .= '<script src="assets/js/home.js"></script>';
include __DIR__ . '/includes/footer.php';
?>
