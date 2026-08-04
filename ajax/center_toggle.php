<?php
/**
 * ajax/center_toggle.php
 * Toggles an evacuation center between active and inactive
 * (soft deactivation — centers are never hard-deleted since they
 * may be referenced by historical distributions).
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin', 'logistics']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid center reference.'], 422);
}

$pdo = Database::getConnection();
$relief = new Relief($pdo);
$ok = $relief->toggleCenterStatus($id);

json_response(['success' => $ok, 'message' => $ok ? 'Center status updated.' : 'Center not found.']);
