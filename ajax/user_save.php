<?php
/**
 * ajax/user_save.php
 * Create or update a user account. Password is only set on create;
 * updates go through ajax/user_reset_password.php instead.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$id        = (int)($_POST['id'] ?? 0);
$username  = trim((string)($_POST['username'] ?? ''));
$email     = trim((string)($_POST['email'] ?? ''));
$fullName  = trim((string)($_POST['full_name'] ?? ''));
$role      = (string)($_POST['role'] ?? 'department');
$deptId    = $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
$password  = (string)($_POST['password'] ?? '');
// The routing toggle only applies to Department accounts; other roles always retain full access.
$canRoute  = $role === 'department' ? !empty($_POST['can_route']) : true;

$validRoles = ['admin', 'department', 'logistics', 'approver'];

$errors = [];
if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
    $errors[] = 'Username must be 3-50 characters (letters, numbers, dots, dashes, underscores only).';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email address is required.';
}
if ($fullName === '' || mb_strlen($fullName) > 150) {
    $errors[] = 'Full name is required and must be under 150 characters.';
}
if (!in_array($role, $validRoles, true)) {
    $errors[] = 'Invalid role selected.';
}
if ($id === 0 && mb_strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}

if (!empty($errors)) {
    json_response(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();
$userManager = new UserManager($pdo);

if ($userManager->usernameOrEmailExists($username, $email, $id > 0 ? $id : null)) {
    json_response(['success' => false, 'message' => 'That username or email is already in use.'], 409);
}

$data = [
    'username' => $username, 'email' => $email, 'full_name' => $fullName,
    'role' => $role, 'department_id' => $deptId, 'password' => $password,
    'can_route' => $canRoute,
];

try {
    if ($id > 0) {
        $ok = $userManager->updateUser($id, $data);
        json_response(['success' => $ok, 'message' => $ok ? 'User account updated.' : 'Update failed.']);
    }
    $newId = $userManager->createUser($data);
    json_response(['success' => true, 'message' => 'User account created.', 'id' => $newId]);
} catch (Throwable $e) {
    error_log('[USER SAVE ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while saving the account.'], 500);
}
