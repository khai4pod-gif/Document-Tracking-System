<?php
/**
 * relief_dashboard.php
 * Analytics dashboard for disaster relief operations: stock levels,
 * active centers, beneficiaries served, distribution trends, and
 * goods breakdown by category.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$pdo = Database::getConnection();
$relief = new Relief($pdo);

$stats     = $relief->getStats();
$trend     = $relief->getTrendData(6);
$breakdown = $relief->getCategoryBreakdown();
$lowStock  = $relief->getLowStockItems();

$distributedPct = $stats['packs_available'] + $stats['packs_distributed'] > 0
    ? round($stats['packs_distributed'] / ($stats['packs_available'] + $stats['packs_distributed']) * 100)
    : 0;

$pageTitle = 'Relief Distribution Dashboard';
$pageIcon  = 'bi-bar-chart-line';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading">Disaster Relief Monitoring</div>
    <div class="section-sub">Operational analytics for relief packs, evacuation centers, and beneficiaries served.</div>
  </div>
  <div class="d-flex gap-2">
    <a href="distributions.php" class="btn btn-primary"><i class="bi bi-truck me-1"></i> New Distribution</a>
  </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#e8f0fe;color:var(--accent);"><i class="bi bi-box-seam"></i></div>
      <div>
        <div class="kpi-value"><?= number_format($stats['packs_available']) ?></div>
        <div class="kpi-label">Packs Available</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#fff4e5;color:var(--accent-2);"><i class="bi bi-send-check"></i></div>
      <div>
        <div class="kpi-value"><?= number_format($stats['packs_distributed']) ?></div>
        <div class="kpi-label">Packs Distributed (<?= $distributedPct ?>%)</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#e6f7ef;color:var(--success);"><i class="bi bi-geo-alt"></i></div>
      <div>
        <div class="kpi-value"><?= number_format($stats['active_centers']) ?></div>
        <div class="kpi-label">Active Evacuation Centers</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="kpi-card">
      <div class="kpi-icon" style="background:#eef1fb;color:#5b5fc7;"><i class="bi bi-people"></i></div>
      <div>
        <div class="kpi-value"><?= number_format($stats['total_beneficiaries']) ?></div>
        <div class="kpi-label">Beneficiaries Served</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <!-- Trend chart -->
  <div class="col-lg-7">
    <div class="card-panel h-100">
      <div class="card-panel-header">Distribution Trend (Last 6 Months)</div>
      <div class="p-3"><canvas id="trendChart" height="260"></canvas></div>
    </div>
  </div>
  <!-- Category breakdown -->
  <div class="col-lg-5">
    <div class="card-panel h-100">
      <div class="card-panel-header d-flex justify-content-between align-items-center">
        <span>Goods Breakdown by Category</span>
        <a href="goods_breakdown_report.php" class="small text-decoration-none" target="_blank">
          <i class="bi bi-file-earmark-bar-graph me-1"></i>Full Report
        </a>
      </div>
      <div class="p-3">
        <div class="category-breakdown">
          <div class="category-breakdown__chart">
            <canvas id="categoryChart" width="180" height="180"></canvas>
            <div class="category-breakdown__center">
              <?php $catTotal = array_sum(array_column($breakdown, 'total_qty')) ?: 1; ?>
              <div class="category-breakdown__total"><?= number_format($catTotal) ?></div>
              <div class="category-breakdown__total-label">Total Units</div>
            </div>
          </div>
          <ul class="category-breakdown__list">
            <?php
            $catColors = ['#4361ee', '#f4a261', '#2a9d8f', '#e76f51', '#5b5fc7', '#e9c46a', '#9d4edd', '#118ab2'];
            foreach ($breakdown as $i => $c):
              $qty = (int)$c['total_qty'];
              $pct = round($qty / $catTotal * 100);
              $color = $catColors[$i % count($catColors)];
            ?>
              <li class="category-breakdown__item">
                <div class="category-breakdown__item-top">
                  <span class="category-breakdown__dot" style="background:<?= $color ?>;"></span>
                  <span class="category-breakdown__name"><?= e($c['category']) ?></span>
                  <span class="category-breakdown__pct"><?= $pct ?>%</span>
                </div>
                <div class="category-breakdown__bar-track">
                  <div class="category-breakdown__bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>;"></div>
                </div>
                <div class="category-breakdown__qty"><?= number_format($qty) ?> units</div>
              </li>
            <?php endforeach; ?>
            <?php if (empty($breakdown)): ?>
              <li class="text-center text-muted py-4">No category data available.</li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
.category-breakdown { display: flex; flex-direction: column; align-items: center; gap: 1.25rem; }
.category-breakdown__chart { position: relative; width: 180px; height: 180px; }
.category-breakdown__center {
  position: absolute; inset: 0; display: flex; flex-direction: column;
  align-items: center; justify-content: center; pointer-events: none;
}
.category-breakdown__total { font-size: 1.4rem; font-weight: 700; line-height: 1; }
.category-breakdown__total-label { font-size: 0.72rem; color: #8a8f98; margin-top: 2px; }
.category-breakdown__list { list-style: none; margin: 0; padding: 0; width: 100%; }
.category-breakdown__item { margin-bottom: 0.9rem; }
.category-breakdown__item:last-child { margin-bottom: 0; }
.category-breakdown__item-top { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.3rem; }
.category-breakdown__dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.category-breakdown__name { font-size: 0.85rem; font-weight: 600; flex: 1; }
.category-breakdown__pct { font-size: 0.8rem; font-weight: 600; color: #8a8f98; }
.category-breakdown__bar-track { height: 6px; border-radius: 999px; background: #f0f1f5; overflow: hidden; }
.category-breakdown__bar-fill { height: 100%; border-radius: 999px; }
.category-breakdown__qty { font-size: 0.72rem; color: #8a8f98; margin-top: 3px; }
</style>

<div class="row g-3 mb-3">
  <div class="col-lg-12">
    <div class="card-panel">
      <div class="card-panel-header">Goods Breakdown by Category (Line)</div>
      <div class="p-3"><canvas id="categoryLineChart" height="140"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-12">
    <div class="card-panel">
      <div class="card-panel-header">Category Ranking (Units on Hand)</div>
      <div class="p-3"><canvas id="categoryRankChart" height="140"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-12">
    <div class="card-panel">
      <div class="card-panel-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle text-warning me-1"></i> Low Stock Alerts</span>
        <a href="inventory.php" class="small text-decoration-none">Manage Inventory <i class="bi bi-arrow-right"></i></a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Item</th><th>Category</th><th>Available</th><th>Reorder Level</th><th>Status</th></tr></thead>
          <tbody>
            <?php if (empty($lowStock)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">All inventory levels are healthy.</td></tr>
            <?php else: foreach ($lowStock as $item): ?>
              <tr>
                <td class="fw-semibold"><?= e($item['item_name']) ?></td>
                <td><?= e($item['category']) ?></td>
                <td><?= number_format((int)$item['quantity_available']) ?> <?= e($item['unit']) ?></td>
                <td><?= number_format((int)$item['reorder_level']) ?></td>
                <td><span class="badge <?= (int)$item['quantity_available'] === 0 ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= (int)$item['quantity_available'] === 0 ? 'Out of Stock' : 'Low Stock' ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$trendLabels = array_map(static fn($t) => date('M Y', strtotime($t['ym'] . '-01')), $trend);
$trendData   = array_map(static fn($t) => (int)$t['beneficiaries'], $trend);
$catLabels   = array_map(static fn($c) => $c['category'], $breakdown);
$catData     = array_map(static fn($c) => (int)$c['total_qty'], $breakdown);

$extraScripts = '<script>
const TREND_LABELS = ' . json_encode($trendLabels) . ';
const TREND_DATA = ' . json_encode($trendData) . ';
const CATEGORY_LABELS = ' . json_encode($catLabels) . ';
const CATEGORY_DATA = ' . json_encode($catData) . ';
</script>
<script src="assets/js/relief_dashboard.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  const el = document.getElementById("categoryRankChart");
  if (!el || typeof Chart === "undefined") return;

  const sorted = CATEGORY_LABELS.map((label, i) => ({ label, value: CATEGORY_DATA[i] }))
    .sort((a, b) => b.value - a.value);
  const palette = ["#4361ee", "#f4a261", "#2a9d8f", "#e76f51", "#5b5fc7", "#e9c46a", "#9d4edd", "#118ab2"];

  new Chart(el.getContext("2d"), {
    type: "bar",
    data: {
      labels: sorted.map(d => d.label),
      datasets: [{
        data: sorted.map(d => d.value),
        backgroundColor: sorted.map((_, i) => palette[i % palette.length]),
        borderRadius: 6,
        maxBarThickness: 28
      }]
    },
    options: {
      indexAxis: "y",
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { beginAtZero: true, grid: { color: "#f0f1f5" } },
        y: { grid: { display: false } }
      }
    }
  });
});
</script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  const el = document.getElementById("categoryLineChart");
  if (!el || typeof Chart === "undefined") return;

  new Chart(el.getContext("2d"), {
    type: "line",
    data: {
      labels: CATEGORY_LABELS,
      datasets: [{
        label: "Units on Hand",
        data: CATEGORY_DATA,
        borderColor: "#4361ee",
        backgroundColor: "rgba(67, 97, 238, 0.12)",
        pointBackgroundColor: "#4361ee",
        pointRadius: 5,
        pointHoverRadius: 7,
        borderWidth: 2,
        tension: 0.3,
        fill: true
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, grid: { color: "#f0f1f5" } }
      }
    }
  });
});
</script>';
include __DIR__ . '/includes/footer.php';
?>
