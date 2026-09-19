<?php
/**
 * ajax/document_receive.php
 * Lets the current holder acknowledge receipt of a routed document.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auto_routing.php';
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

// Read the route before acknowledging: receiveRoute() flips its status, and
// the automatic hand-on needs to know who this route was addressed to.
$routeStmt = $pdo->prepare("SELECT * FROM document_routes WHERE id = :id LIMIT 1");
$routeStmt->execute(['id' => $routeId]);
$route = $routeStmt->fetch();

$ok = $documentModel->receiveRoute($routeId, (int)current_user()['id']);

if ($ok) {
    $message = 'Document receipt acknowledged.';

    // Acknowledging at the Office of the Secretary is what sends the
    // document on to the originating office's approver. Nothing is clicked;
    // this is the second and last automatic hop.
    if ($route) {
        $onward = autoRouteToApprover($documentModel, $route, $pdo);
        if ($onward['routed']) {
            $message .= ' Forwarded to ' . $onward['to'] . ' for approval.';
        } elseif ($onward['reason'] !== null) {
            // It stays with whoever acknowledged it, and they are told why
            // rather than being left to wonder where it went.
            $message .= ' It was not forwarded because ' . $onward['reason'] . '.';
        }
    }

    json_response(['success' => true, 'message' => $message]);
}

json_response(['success' => false, 'message' => 'Unable to acknowledge receipt. This document may not be assigned to you.'], 403);
