<?php
/**
 * ajax/performance_summary.php
 * Individual performance figures for the current user, scoped to a
 * due-date period. Powers the tab-switching on the Home page without a
 * full page reload — see Document::getPerformanceSummary().
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$period = (string)($_GET['period'] ?? 'today');
if (!in_array($period, Document::PERFORMANCE_PERIODS, true)) {
    $period = 'today';
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);
$summary = $documentModel->getPerformanceSummary((int)current_user()['id'], $period);

echo json_encode(['success' => true, 'period' => $period, 'summary' => $summary]);
