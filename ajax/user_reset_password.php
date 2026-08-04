<?php
/**
 * ajax/user_reset_password.php
 * Lets an admin set a new password for a user account.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$id       = (int)($_POST['id'] ?? 0);
$password = (string)($_POST['password'] ?? '');

if ($id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid user reference.'], 422);
}
if (mb_strlen($password) < 8) {
    json_response(['success' => false, 'message' => 'Password must be at least 8 characters long.'], 422);
}

$pdo = Database::getConnection();
$userManager = new UserManager($pdo);

if (!$userManager->findUser($id)) {
    json_response(['success' => false, 'message' => 'User not found.'], 404);
}

$ok = $userManager->resetPassword($id, $password);
json_response(['success' => $ok, 'message' => $ok ? 'Password reset successfully.' : 'Failed to reset password.']);
