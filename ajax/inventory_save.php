<?php
/**
 * ajax/inventory_save.php
 * Create or update a relief_inventory item.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin', 'logistics']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$id       = (int)($_POST['id'] ?? 0);
$itemName = trim((string)($_POST['item_name'] ?? ''));
$category = (string)($_POST['category'] ?? 'Other');
$unit     = trim((string)($_POST['unit'] ?? 'pack'));
$qtyAvail = (int)($_POST['quantity_available'] ?? 0);
$reorder  = (int)($_POST['reorder_level'] ?? 0);

$validCategories = ['Food', 'Medical', 'Shelter', 'Water', 'Hygiene', 'Other'];

$errors = [];
if ($itemName === '' || mb_strlen($itemName) > 150) {
    $errors[] = 'Item name is required and must be under 150 characters.';
}
if (!in_array($category, $validCategories, true)) {
    $errors[] = 'Invalid category selected.';
}
if ($qtyAvail < 0 || $reorder < 0) {
    $errors[] = 'Quantities cannot be negative.';
}
if ($unit === '' || mb_strlen($unit) > 30) {
    $errors[] = 'Unit is required and must be under 30 characters.';
}

if (!empty($errors)) {
    json_response(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();
$relief = new Relief($pdo);

$data = [
    'item_name'           => $itemName,
    'category'            => $category,
    'unit'                => $unit,
    'quantity_available'  => $qtyAvail,
    'reorder_level'       => $reorder,
];

try {
    if ($id > 0) {
        $ok = $relief->updateInventoryItem($id, $data);
        json_response(['success' => $ok, 'message' => $ok ? 'Inventory item updated.' : 'Update failed.']);
    }

    $newId = $relief->createInventoryItem($data);
    json_response(['success' => true, 'message' => 'Inventory item added.', 'id' => $newId]);
} catch (Throwable $e) {
    error_log('[INVENTORY SAVE ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while saving the item.'], 500);
}
