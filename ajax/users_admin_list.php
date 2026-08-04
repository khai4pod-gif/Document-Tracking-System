<?php
/**
 * ajax/users_admin_list.php
 * Returns { data: [...] } consumed by the User Management DataTable.
 * (Distinct from ajax/users_list.php, which only feeds the DTS "Route To" dropdown.)
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin']);
header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$userManager = new UserManager($pdo);
$rows = $userManager->listUsers();

$data = array_map(static function (array $r): array {
    return [
        'id'               => (int)$r['id'],
        'username'         => $r['username'],
        'email'            => $r['email'],
        'full_name'        => $r['full_name'],
        'role'             => $r['role'],
        'can_route'        => (int)$r['can_route'],
        'department_id'    => $r['department_id'] !== null ? (int)$r['department_id'] : null,
        'department_name'  => $r['department_name'],
        'is_active'        => (int)$r['is_active'],
        'last_login_at'    => $r['last_login_at'] ? date('M d, Y g:i A', strtotime($r['last_login_at'])) : 'Never',
        'created_at'       => date('M d, Y', strtotime($r['created_at'])),
    ];
}, $rows);

echo json_encode(['data' => $data]);
