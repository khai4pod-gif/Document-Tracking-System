<?php
/**
 * ajax/distribution_view.php
 * Returns full detail (including line items) for one distribution —
 * used to populate the read-only detail modal on distributions.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid distribution reference.'], 422);
}

$pdo = Database::getConnection();
$relief = new Relief($pdo);
$dist = $relief->findDistribution($id);

if (!$dist) {
    json_response(['success' => false, 'message' => 'Distribution not found.'], 404);
}

json_response(['success' => true, 'distribution' => $dist]);
