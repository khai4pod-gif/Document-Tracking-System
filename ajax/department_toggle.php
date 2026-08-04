<?php
/**
 * ajax/department_toggle.php
 * Toggles a department between active and inactive. Departments are
 * never hard-deleted — users/documents reference them with
 * ON DELETE SET NULL, so deactivating preserves historical integrity
 * while removing it from active dropdowns.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid department reference.'], 422);
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare("UPDATE departments SET is_active = NOT is_active WHERE id = :id");
$ok = $stmt->execute(['id' => $id]);

json_response(['success' => $ok, 'message' => $ok ? 'Department status updated.' : 'Department not found.']);
