<?php
/**
 * ajax/user_toggle.php
 * Activates/deactivates a user account. An admin cannot deactivate
 * their own account, to prevent accidental self-lockout.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    json_response(['success' => false, 'message' => 'Invalid user reference.'], 422);
}

if ($id === (int)current_user()['id']) {
    json_response(['success' => false, 'message' => 'You cannot deactivate your own account.'], 422);
}

$pdo = Database::getConnection();
$userManager = new UserManager($pdo);
$ok = $userManager->toggleActive($id);

json_response(['success' => $ok, 'message' => $ok ? 'Account status updated.' : 'User not found.']);
