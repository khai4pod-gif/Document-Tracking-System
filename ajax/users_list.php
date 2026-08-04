<?php
/**
 * ajax/users_list.php
 * Returns active users for populating the "Route To" dropdown.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$stmt = $pdo->prepare(
    "SELECT u.id, u.full_name, u.role, d.name AS department_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.is_active = 1 AND u.id != :me
     ORDER BY u.full_name ASC"
);
$stmt->execute(['me' => current_user()['id']]);

echo json_encode(['data' => $stmt->fetchAll()]);
