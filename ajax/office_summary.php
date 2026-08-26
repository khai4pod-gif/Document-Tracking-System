<?php
/**
 * ajax/office_summary.php
 * Office-wide document tracking figures for the current user's department,
 * scoped to a due-date period. Powers the tab switching on the Home page
 * without a full reload — see Document::getOfficeSummary().
 *
 * Accounts with no department get agency-wide figures.
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

$departmentId = !empty(current_user()['department_id']) ? (int)current_user()['department_id'] : null;

echo json_encode([
    'success' => true,
    'period'  => $period,
    'summary' => $documentModel->getOfficeSummary($departmentId, $period),
    'trend'   => $documentModel->getOfficeComplianceTrend($departmentId),
]);
