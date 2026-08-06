<?php
/**
 * ajax/notifications_read.php
 * Marks all of the current user's notifications as read (called when the
 * notification bell dropdown is opened).
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$pdo = Database::getConnection();
$user = current_user();

$stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :u AND is_read = 0");
$stmt->execute(['u' => $user['id']]);

json_response(['success' => true]);
