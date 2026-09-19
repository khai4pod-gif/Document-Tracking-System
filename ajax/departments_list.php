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
$sql = "SELECT d.id, d.name, d.code, d.description, d.is_active, d.approver_user_id,
               a.full_name AS approver_name, a.is_active AS approver_active,
               (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS user_count
        FROM departments d
        LEFT JOIN users a ON a.id = d.approver_user_id
        ORDER BY d.name ASC";
$rows = $pdo->query($sql)->fetchAll();

$data = array_map(static function (array $r): array {
    return [
        'id'               => (int)$r['id'],
        'name'             => $r['name'],
        'code'             => $r['code'],
        'description'      => $r['description'],
        'is_active'        => (int)$r['is_active'],
        'user_count'       => (int)$r['user_count'],
        'approver_user_id' => $r['approver_user_id'] !== null ? (int)$r['approver_user_id'] : null,
        'approver_name'    => $r['approver_name'],
        // A deactivated approver is still shown, flagged: documents for that
        // office would otherwise stall with nothing on screen to explain it.
        'approver_active'  => $r['approver_active'] !== null ? (int)$r['approver_active'] : null,
    ];
}, $rows);

// Candidates for the modal's approver picker. Only approver accounts are
// offered — handing the last hop to someone who cannot approve would leave
// the document with nowhere to go.
$approvers = $pdo->query(
    "SELECT u.id, u.full_name, u.username, d.name AS department_name
       FROM users u
       LEFT JOIN departments d ON d.id = u.department_id
      WHERE u.role = 'approver' AND u.is_active = 1
      ORDER BY u.full_name ASC"
)->fetchAll();

echo json_encode([
    'data'      => $data,
    'approvers' => array_map(static fn(array $u): array => [
        'id'         => (int)$u['id'],
        'full_name'  => $u['full_name'],
        'username'   => $u['username'],
        'department' => $u['department_name'],
    ], $approvers),
]);
