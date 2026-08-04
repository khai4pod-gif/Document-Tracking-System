<?php
/**
 * ajax/centers_list.php
 * Returns { data: [...] } consumed by the Evacuation Centers DataTable.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$relief = new Relief($pdo);
$rows = $relief->listCenters();

$data = array_map(static function (array $r): array {
    return [
        'id'             => (int)$r['id'],
        'name'           => $r['name'],
        'target_area'    => $r['target_area'],
        'address'        => $r['address'],
        'capacity'       => $r['capacity'] !== null ? (int)$r['capacity'] : null,
        'contact_person' => $r['contact_person'],
        'contact_number' => $r['contact_number'],
        'is_active'      => (int)$r['is_active'],
    ];
}, $rows);

echo json_encode(['data' => $data]);
