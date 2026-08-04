<?php
/**
 * ajax/inventory_delete.php
 * Deletes an inventory item. Blocked by the database's foreign key if
 * the item is referenced by any past distribution — that is by design,
 * to preserve historical distribution records.
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
    json_response(['success' => false, 'message' => 'Invalid item reference.'], 422);
}

$pdo = Database::getConnection();
$relief = new Relief($pdo);

try {
    $ok = $relief->deleteInventoryItem($id);
    json_response(['success' => $ok, 'message' => $ok ? 'Inventory item deleted.' : 'Item not found.']);
} catch (PDOException $e) {
    if ((int)$e->getCode() === 23000) {
        json_response(['success' => false, 'message' => 'This item cannot be deleted because it is referenced in existing distribution records.'], 409);
    }
    error_log('[INVENTORY DELETE ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred.'], 500);
}
