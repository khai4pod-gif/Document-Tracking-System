<?php
/**
 * ajax/department_save.php
 * Create or update a department/office record.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$id          = (int)($_POST['id'] ?? 0);
$name        = trim((string)($_POST['name'] ?? ''));
$code        = strtoupper(trim((string)($_POST['code'] ?? '')));
$description = trim((string)($_POST['description'] ?? ''));

$errors = [];
if ($name === '' || mb_strlen($name) > 150) {
    $errors[] = 'Department name is required and must be under 150 characters.';
}
if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
    $errors[] = 'Code must be 2-20 characters: letters, numbers, dashes, or underscores.';
}

if (!empty($errors)) {
    json_response(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();

// Uniqueness check on code
$check = $pdo->prepare("SELECT COUNT(*) AS cnt FROM departments WHERE code = :code" . ($id > 0 ? " AND id != :id" : ""));
$params = ['code' => $code];
if ($id > 0) {
    $params['id'] = $id;
}
$check->execute($params);
if ((int)$check->fetch()['cnt'] > 0) {
    json_response(['success' => false, 'message' => 'That department code is already in use.'], 409);
}

try {
    if ($id > 0) {
        $stmt = $pdo->prepare(
            "UPDATE departments SET name = :name, code = :code, description = :desc WHERE id = :id"
        );
        $ok = $stmt->execute(['name' => $name, 'code' => $code, 'desc' => $description ?: null, 'id' => $id]);
        json_response(['success' => $ok, 'message' => $ok ? 'Department updated.' : 'Update failed.']);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO departments (name, code, description, is_active, created_at) VALUES (:name, :code, :desc, 1, NOW())"
    );
    $stmt->execute(['name' => $name, 'code' => $code, 'desc' => $description ?: null]);
    json_response(['success' => true, 'message' => 'Department created.', 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    error_log('[DEPARTMENT SAVE ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while saving the department.'], 500);
}
