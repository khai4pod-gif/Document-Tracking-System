<?php
/**
 * ajax/inventory_list.php
 * Returns { data: [...] } consumed by the Inventory DataTable.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$relief = new Relief($pdo);
$rows = $relief->listInventory();

$data = array_map(static function (array $r): array {
    return [
        'id'                  => (int)$r['id'],
        'item_name'           => $r['item_name'],
        'category'            => $r['category'],
        'unit'                => $r['unit'],
        'quantity_available'  => (int)$r['quantity_available'],
        'quantity_distributed'=> (int)$r['quantity_distributed'],
        'reorder_level'       => (int)$r['reorder_level'],
        'low_stock'           => (int)$r['quantity_available'] <= (int)$r['reorder_level'],
    ];
}, $rows);

echo json_encode(['data' => $data]);
