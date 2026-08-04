<?php
/**
 * ajax/center_save.php
 * Create or update an evacuation center / target area.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin', 'logistics']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$id       = (int)($_POST['id'] ?? 0);
$name     = trim((string)($_POST['name'] ?? ''));
$area     = trim((string)($_POST['target_area'] ?? ''));
$address  = trim((string)($_POST['address'] ?? ''));
$capacity = $_POST['capacity'] !== '' ? (int)$_POST['capacity'] : null;
$cp       = trim((string)($_POST['contact_person'] ?? ''));
$cn       = trim((string)($_POST['contact_number'] ?? ''));

$errors = [];
if ($name === '' || mb_strlen($name) > 150) {
    $errors[] = 'Center name is required and must be under 150 characters.';
}
if ($area === '' || mb_strlen($area) > 150) {
    $errors[] = 'Target area is required and must be under 150 characters.';
}
if ($capacity !== null && $capacity < 0) {
    $errors[] = 'Capacity cannot be negative.';
}

if (!empty($errors)) {
    json_response(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();
$relief = new Relief($pdo);

$data = [
    'name' => $name, 'target_area' => $area, 'address' => $address,
    'capacity' => $capacity, 'contact_person' => $cp, 'contact_number' => $cn,
];

try {
    if ($id > 0) {
        $ok = $relief->updateCenter($id, $data);
        json_response(['success' => $ok, 'message' => $ok ? 'Evacuation center updated.' : 'Update failed.']);
    }
    $newId = $relief->createCenter($data);
    json_response(['success' => true, 'message' => 'Evacuation center added.', 'id' => $newId]);
} catch (Throwable $e) {
    error_log('[CENTER SAVE ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while saving the center.'], 500);
}
