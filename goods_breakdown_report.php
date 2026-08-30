<?php
/**
 * goods_breakdown_report.php
 * Printable / exportable analytics report of the full goods breakdown
 * by category — summary table plus chart. Uses the browser's print
 * dialog ("Save as PDF") so it needs no extra PDF library.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_login();

$pdo    = Database::getConnection();
$relief = new Relief($pdo);

$breakdown = $relief->getCategoryBreakdown();
$stats     = $relief->getStats();

// One row per distributed line item, joined back to its parent event.
// Distributions record a beneficiary count per event rather than named
// individuals, so the figures below are totals, not a register of people.
$distributionEvents = $relief->getDistributionsByCategory();

// Group by category so it lines up with the breakdown table above it.
$eventsByCategory = [];
foreach ($distributionEvents as $row) {
    $eventsByCategory[$row['category']][] = $row;
}

$totalUnits = array_sum(array_column($breakdown, 'total_qty')) ?: 1;
$catColors  = ['#4361ee', '#f4a261', '#2a9d8f', '#e76f51', '#5b5fc7', '#e9c46a', '#9d4edd', '#118ab2'];

// Sort by quantity, highest first, for the table and chart
usort($breakdown, static fn($a, $b) => (int)$b['total_qty'] <=> (int)$a['total_qty']);

$catLabels = array_map(static fn($c) => $c['category'], $breakdown);
$catData   = array_map(static fn($c) => (int)$c['total_qty'], $breakdown);

$generatedAt = date('F j, Y g:i A');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Goods Breakdown Report</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<style>
  body { background: #f4f5f8; color: #212529; }
  .report-page { max-width: 900px; margin: 2rem auto; background: #fff; padding: 2.5rem; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  .report-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #212529; padding-bottom: 1rem; margin-bottom: 1.5rem; }
  .report-title { font-size: 1.5rem; font-weight: 700; margin: 0; }
  .report-sub { color: #6c757d; font-size: 0.9rem; margin-top: 2px; }
  .report-meta { text-align: right; font-size: 0.82rem; color: #6c757d; }
  .summary-strip { display: flex; gap: 1rem; margin-bottom: 1.75rem; flex-wrap: wrap; }
  .summary-box { flex: 1; min-width: 140px; background: #f8f9fb; border-radius: 8px; padding: 0.9rem 1rem; }
  .summary-box .value { font-size: 1.25rem; font-weight: 700; }
  .summary-box .label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; letter-spacing: .03em; }
  .section-title { font-weight: 700; font-size: 1.05rem; margin: 1.75rem 0 0.75rem; }
  table.report-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
  table.report-table th { text-align: left; border-bottom: 2px solid #dee2e6; padding: 0.5rem 0.6rem; font-size: 0.78rem; text-transform: uppercase; letter-spacing: .02em; color: #6c757d; }
  table.report-table td { padding: 0.55rem 0.6rem; border-bottom: 1px solid #eef0f3; vertical-align: middle; }
  .cat-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; }
  .report-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-bottom: 1rem; }
  .chart-wrap { position: relative; height: 340px; margin-bottom: 1rem; }
  .beneficiary-group { margin-bottom: 1.5rem; page-break-inside: avoid; }
  .beneficiary-group__title { font-weight: 600; font-size: 0.92rem; margin-bottom: 0.4rem; padding-bottom: 0.3rem; border-bottom: 1px solid #eef0f3; }
  .beneficiary-table { font-size: 0.85rem; }
  .beneficiary-table th { font-size: 0.72rem; }
  @media print {
    body { background: #fff; }
    .report-actions { display: none !important; }
    .report-page { box-shadow: none; margin: 0; max-width: 100%; padding: 0; }
    .beneficiary-group { break-inside: avoid; }
  }
</style>
</head>
<body>

<div class="container">
  <div class="report-actions">
    <a href="relief_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
    <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</button>
  </div>

  <div class="report-page">
    <div class="report-header">
      <div>
        <p class="report-title">Goods Breakdown Analytics Report</p>
        <p class="report-sub">Relief Distribution Program — Inventory by Category</p>
      </div>
      <div class="report-meta">
        Generated <?= e($generatedAt) ?><br>
        <?= count($breakdown) ?> categor<?= count($breakdown) === 1 ? 'y' : 'ies' ?> reported
      </div>
    </div>

    <div class="summary-strip">
      <div class="summary-box">
        <div class="value"><?= number_format($totalUnits) ?></div>
        <div class="label">Total Units on Hand</div>
      </div>
      <div class="summary-box">
        <div class="value"><?= number_format($stats['packs_available']) ?></div>
        <div class="label">Packs Available</div>
      </div>
      <div class="summary-box">
        <div class="value"><?= number_format($stats['packs_distributed']) ?></div>
        <div class="label">Packs Distributed</div>
      </div>
      <div class="summary-box">
        <div class="value"><?= number_format($stats['total_beneficiaries']) ?></div>
        <div class="label">Beneficiaries Served</div>
      </div>
    </div>

    <div class="section-title">Distribution by Category</div>
    <div class="chart-wrap"><canvas id="reportChart"></canvas></div>

    <div class="section-title">Full Breakdown</div>
    <table class="report-table">
      <thead>
        <tr>
          <th style="width:4%">#</th>
          <th>Category</th>
          <th class="text-end">Units on Hand</th>
          <th class="text-end">Share of Total</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($breakdown)): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No category data available.</td></tr>
        <?php else: foreach ($breakdown as $i => $c):
          $qty = (int)$c['total_qty'];
          $pct = round($qty / $totalUnits * 100, 1);
          $color = $catColors[$i % count($catColors)];
        ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><span class="cat-dot" style="background:<?= $color ?>;"></span><?= e($c['category']) ?></td>
            <td class="text-end"><?= number_format($qty) ?></td>
            <td class="text-end"><?= $pct ?>%</td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($breakdown)): ?>
      <tfoot>
        <tr>
          <td></td>
          <td class="fw-semibold">Total</td>
          <td class="text-end fw-semibold"><?= number_format($totalUnits) ?></td>
          <td class="text-end fw-semibold">100%</td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>

    <div class="section-title">Distribution Events by Category</div>
    <?php if (empty($distributionEvents)): ?>
      <p class="text-muted small">
        No goods have been distributed yet.
      </p>
    <?php else: foreach ($eventsByCategory as $category => $rows): ?>
      <div class="beneficiary-group">
        <div class="beneficiary-group__title">
          <?= e($category) ?>
          <span class="text-muted">
            (<?= count($rows) ?> distribution<?= count($rows) === 1 ? '' : 's' ?>,
            <?= number_format(array_sum(array_column($rows, 'total_beneficiaries'))) ?> beneficiaries)
          </span>
        </div>
        <table class="report-table beneficiary-table">
          <thead>
            <tr>
              <th>Reference #</th>
              <th>Center / Area</th>
              <th>Item</th>
              <th class="text-end">Quantity</th>
              <th class="text-end">Beneficiaries</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= e($row['reference_no']) ?></td>
                <td><?= e($row['center_name']) ?></td>
                <td><?= e($row['item_name']) ?></td>
                <td class="text-end"><?= number_format((int)$row['quantity']) ?></td>
                <td class="text-end"><?= number_format((int)$row['total_beneficiaries']) ?></td>
                <td><?= e(date('M j, Y', strtotime($row['distribution_date']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
const REPORT_LABELS = <?= json_encode($catLabels) ?>;
const REPORT_DATA   = <?= json_encode($catData) ?>;
const REPORT_COLORS = <?= json_encode(array_map(
  static fn($i) => $catColors[$i % count($catColors)],
  array_keys($catLabels)
)) ?>;

document.addEventListener('DOMContentLoaded', function () {
  const el = document.getElementById('reportChart');
  if (!el || typeof Chart === 'undefined') return;

  new Chart(el.getContext('2d'), {
    type: 'bar',
    data: {
      labels: REPORT_LABELS,
      datasets: [{
        data: REPORT_DATA,
        backgroundColor: REPORT_COLORS,
        borderRadius: 6,
        maxBarThickness: 34
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { beginAtZero: true, grid: { color: '#eef0f3' } },
        y: { grid: { display: false } }
      }
    }
  });
});
</script>
</body>
</html>
