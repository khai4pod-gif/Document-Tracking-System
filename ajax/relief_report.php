<?php
/**
 * ajax/relief_report.php
 * Data source for relief_reports.php. Takes a filter set plus a view name
 * and returns the rows for that view, along with the totals shown in the
 * summary strip. Every filter is validated against known values, so a
 * hand-edited query string cannot reach a state the interface cannot show.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo    = Database::getConnection();
$relief = new Relief($pdo);

$view = (string)($_GET['view'] ?? 'distributions');
if (!in_array($view, Relief::REPORT_VIEWS, true)) {
    $view = 'distributions';
}

$preset = (string)($_GET['preset'] ?? 'month');
if (!in_array($preset, Relief::REPORT_PRESETS, true)) {
    $preset = 'month';
}

[$dateFrom, $dateTo] = Relief::resolveReportDates(
    $preset,
    $_GET['date_from'] ?? null,
    $_GET['date_to'] ?? null
);

$granularity = (string)($_GET['granularity'] ?? 'month');
if (!in_array($granularity, Relief::REPORT_GRANULARITY, true)) {
    $granularity = 'month';
}

$statuses = ['Draft', 'Pending Approval', 'Approved', 'Completed', 'Cancelled'];
$status   = (string)($_GET['status'] ?? '');

$filters = [
    'date_from'    => $dateFrom,
    'date_to'      => $dateTo,
    'center_id'    => (int)($_GET['center_id'] ?? 0) ?: null,
    'target_area'  => trim((string)($_GET['target_area'] ?? '')) ?: null,
    'category'     => trim((string)($_GET['category'] ?? '')) ?: null,
    'inventory_id' => (int)($_GET['inventory_id'] ?? 0) ?: null,
    'status'       => in_array($status, $statuses, true) ? $status : null,
    'granularity'  => $granularity,
];

$rows = match ($view) {
    'goods'   => $relief->reportGoods($filters),
    'centres' => $relief->reportByCentre($filters),
    'trend'   => $relief->reportTrend($filters),
    default   => $relief->reportDistributions($filters),
};

// Summary strip — computed from the same filter set regardless of view, so
// switching views never changes the headline totals.
$events = $relief->reportDistributions($filters);

echo json_encode([
    'success' => true,
    'view'    => $view,
    'data'    => $rows,
    'summary' => [
        'events'        => count($events),
        'units'         => array_sum(array_map(static fn($r) => (int)$r['units'], $events)),
        'beneficiaries' => $relief->reportBeneficiaries($filters),
        'centres'       => count(array_unique(array_column($events, 'center_name'))),
    ],
    'applied' => [
        'preset'      => $preset,
        'date_from'   => $dateFrom,
        'date_to'     => $dateTo,
        'granularity' => $granularity,
    ],
], JSON_UNESCAPED_UNICODE);
