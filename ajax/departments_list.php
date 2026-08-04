<?php
/**
 * ajax/departments_list.php
 * Returns { data: [...] } consumed by the Departments DataTable.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin']);
header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$sql = "SELECT d.id, d.name, d.code, d.description, d.is_active,
               (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS user_count
        FROM departments d
        ORDER BY d.name ASC";
$rows = $pdo->query($sql)->fetchAll();

$data = array_map(static function (array $r): array {
    return [
        'id'          => (int)$r['id'],
        'name'        => $r['name'],
        'code'        => $r['code'],
        'description' => $r['description'],
        'is_active'   => (int)$r['is_active'],
        'user_count'  => (int)$r['user_count'],
    ];
}, $rows);

echo json_encode(['data' => $data]);
