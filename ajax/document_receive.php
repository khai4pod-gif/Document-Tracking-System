<?php
/**
 * ajax/document_receive.php
 * Lets the current holder acknowledge receipt of a routed document.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$routeId = (int)($_POST['route_id'] ?? 0);
if ($routeId <= 0) {
    json_response(['success' => false, 'message' => 'Invalid route reference.'], 422);
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);

$ok = $documentModel->receiveRoute($routeId, (int)current_user()['id']);

if ($ok) {
    json_response(['success' => true, 'message' => 'Document receipt acknowledged.']);
}

json_response(['success' => false, 'message' => 'Unable to acknowledge receipt. This document may not be assigned to you.'], 403);
