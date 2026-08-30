<?php
/**
 * relief_reports.php
 * Filterable reporting for relief operations — by centre, target area, date
 * range and granularity, item and category. Four views over one filter set;
 * see ajax/relief_report.php and Relief::buildReportScope().
 *
 * Read access matches the other relief reports: any signed-in user, since
 * relief operations are agency-wide.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$pdo    = Database::getConnection();
$relief = new Relief($pdo);
$options = $relief->reportFilterOptions();

$pageTitle = 'Relief Reports';
$pageIcon  = 'bi-clipboard-data';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading">Relief Reports</div>
    <div class="section-sub">Filter relief operations by centre, area, period, item or category.</div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-secondary" id="btnPrintReport">
      <i class="bi bi-printer me-1"></i> Print / PDF
    </button>
  </div>
</div>

<!-- ===================== Filters ===================== -->
<div class="card-panel mb-3 report-filters">
  <div class="card-panel-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-funnel me-2"></i>Filters</span>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnResetFilters">Reset</button>
  </div>
  <div class="p-3">
    <div class="row g-3">
      <div class="col-sm-6 col-lg-3">
        <label class="form-label small text-muted mb-1" for="fPreset">Period</label>
        <select id="fPreset" class="form-select form-select-sm">
          <option value="today">Today</option>
          <option value="week">This week</option>
          <option value="month" selected>This month</option>
          <option value="quarter">This quarter</option>
          <option value="year">This year</option>
          <option value="all">All time</option>
          <option value="custom">Custom range…</option>
        </select>
      </div>

      <div class="col-sm-6 col-lg-3 d-none" id="customRangeWrap">
        <label class="form-label small text-muted mb-1">Custom range</label>
        <div class="d-flex gap-1">
          <input type="date" id="fDateFrom" class="form-control form-control-sm" aria-label="From date">
          <input type="date" id="fDateTo" class="form-control form-control-sm" aria-label="To date">
        </div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <label class="form-label small text-muted mb-1" for="fGranularity">Group trend by</label>
        <select id="fGranularity" class="form-select form-select-sm">
          <option value="day">Daily</option>
          <option value="week">Weekly</option>
          <option value="month" selected>Monthly</option>
        </select>
      </div>

      <div class="col-sm-6 col-lg-3">
        <label class="form-label small text-muted mb-1" for="fCentre">Evacuation centre</label>
        <select id="fCentre" class="form-select form-select-sm">
          <option value="">All centres</option>
          <?php foreach ($options['centres'] as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-6 col-lg-3">
        <label class="form-label small text-muted mb-1" for="fArea">Target area</label>
        <select id="fArea" class="form-select form-select-sm">
          <option value="">All areas</option>
          <?php foreach ($options['areas'] as $a): ?>
            <option value="<?= e($a) ?>"><?= e($a) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-6 col-lg-3">
        <label class="form-label small text-muted mb-1" for="fCategory">Item category</label>
        <select id="fCategory" class="form-select form-select-sm">
          <option value="">All categories</option>
          <?php foreach ($options['categories'] as $cat): ?>
            <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-6 col-lg-3">
        <label class="form-label small text-muted mb-1" for="fItem">Item</label>
        <select id="fItem" class="form-select form-select-sm">
          <option value="">All items</option>
          <?php foreach ($options['items'] as $i): ?>
            <option value="<?= (int)$i['id'] ?>" data-category="<?= e($i['category']) ?>">
              <?= e($i['item_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-sm-6 col-lg-3">
        <label class="form-label small text-muted mb-1" for="fStatus">Event status</label>
        <select id="fStatus" class="form-select form-select-sm">
          <option value="">All except cancelled</option>
          <option>Draft</option>
          <option>Pending Approval</option>
          <option>Approved</option>
          <option>Completed</option>
          <option>Cancelled</option>
        </select>
      </div>
    </div>
  </div>
</div>

<!-- ===================== Summary strip ===================== -->
<div class="row g-3 mb-3" id="reportSummary">
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#e8f0fe;color:var(--accent);"><i class="bi bi-truck"></i></div>
      <div><div class="kpi-value" id="sumEvents">0</div><div class="kpi-label">Distribution events</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#e6f7ef;color:var(--success);"><i class="bi bi-box-seam"></i></div>
      <div><div class="kpi-value" id="sumUnits">0</div><div class="kpi-label">Units released</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#fff4e5;color:var(--accent-2);"><i class="bi bi-people"></i></div>
      <div><div class="kpi-value" id="sumBeneficiaries">0</div><div class="kpi-label">Beneficiaries served</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#f0eef5;color:#6b5f8c;"><i class="bi bi-geo-alt"></i></div>
      <div><div class="kpi-value" id="sumCentres">0</div><div class="kpi-label">Centres served</div></div>
    </div>
  </div>
</div>

<!-- ===================== Results ===================== -->
<div class="card-panel">
  <div class="card-panel-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <div>Report</div>
      <div class="text-muted small fw-normal" id="reportScope">This month · all centres</div>
    </div>
    <div class="perf-tabs" id="reportViews">
      <button type="button" class="perf-tab active" data-view="distributions">Distributions</button>
      <button type="button" class="perf-tab" data-view="goods">Goods released</button>
      <button type="button" class="perf-tab" data-view="centres">By centre</button>
      <button type="button" class="perf-tab" data-view="trend">Trend</button>
      <button type="button" class="perf-tab" data-view="movement">Item movement</button>
    </div>
  </div>
  <div class="p-3">
    <div id="reportEmpty" class="text-center text-muted py-5 d-none">
      No relief activity matches these filters. Widen the period or clear a filter above.
    </div>
    <div class="table-responsive" id="reportTableWrap">
      <table class="table table-sm mb-0" id="reportTable" style="width:100%;">
        <thead><tr></tr></thead>
        <tbody></tbody>
        <tfoot><tr></tr></tfoot>
      </table>
    </div>
  </div>
</div>

<style>
  .report-filters .form-label { font-weight: 600; }
  #reportTable tfoot td { font-weight: 700; border-top: 2px solid var(--border); }
  .num-cell { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }

  @media print {
    .sidebar, .topbar, .report-filters, #reportViews, #btnPrintReport,
    .dt-buttons, .dataTables_filter, .dataTables_paginate, .dataTables_info,
    .dataTables_length { display: none !important; }
    .main-content, .content-area { margin: 0 !important; padding: 0 !important; }
    .card-panel { border: none !important; box-shadow: none !important; }
    .kpi-card { border: 1px solid #999 !important; }
    a[href]:after { content: none !important; }
  }
</style>

<?php
$extraScripts = '<script src="' . e(asset('assets/js/relief_reports.js')) . '"></script>';
include __DIR__ . '/includes/footer.php';
?>
