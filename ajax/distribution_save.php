<?php
/**
 * ajax/distribution_save.php
 * Creates a relief distribution event: validates stock, deducts
 * inventory, records line items, and optionally generates + links a
 * Relief Manifest document into the DTS for routing/approval.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_role(['admin', 'logistics', 'approver']);
csrf_protect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$centerId       = (int)($_POST['evacuation_center_id'] ?? 0);
$distDate       = trim((string)($_POST['distribution_date'] ?? ''));
$beneficiaries  = (int)($_POST['total_beneficiaries'] ?? 0);
$remarks        = trim((string)($_POST['remarks'] ?? ''));
$generateManifest = !empty($_POST['generate_manifest']);
$itemsRaw       = $_POST['items'] ?? []; // [{inventory_id, quantity}, ...] as JSON string

$errors = [];
if ($centerId <= 0) {
    $errors[] = 'Please select an evacuation center.';
}
if ($distDate === '' || !DateTime::createFromFormat('Y-m-d', $distDate)) {
    $errors[] = 'A valid distribution date is required.';
}
if ($beneficiaries < 0) {
    $errors[] = 'Beneficiaries served cannot be negative.';
}

$items = [];
if (is_string($itemsRaw)) {
    $decoded = json_decode($itemsRaw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $row) {
            $invId = (int)($row['inventory_id'] ?? 0);
            $qty   = (int)($row['quantity'] ?? 0);
            if ($invId > 0 && $qty > 0) {
                $items[] = ['inventory_id' => $invId, 'quantity' => $qty];
            }
        }
    }
}
if (empty($items)) {
    $errors[] = 'Add at least one relief item with a quantity greater than zero.';
}

if (!empty($errors)) {
    json_response(['success' => false, 'message' => implode(' ', $errors)], 422);
}

$pdo = Database::getConnection();
$relief = new Relief($pdo);
$documentModel = new Document($pdo);
$userId = (int)current_user()['id'];

try {
    $result = $relief->createDistribution([
        'evacuation_center_id' => $centerId,
        'distribution_date'    => $distDate,
        'total_beneficiaries'  => $beneficiaries,
        'remarks'              => $remarks,
        'items'                => $items,
    ], $userId, $generateManifest, $documentModel);

    $message = 'Distribution ' . $result['reference_no'] . ' recorded successfully.';
    if ($result['document_id']) {
        $message .= ' A Relief Manifest document has been created and is ready for routing.';
    }

    json_response(['success' => true, 'message' => $message, 'id' => $result['id']]);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[DISTRIBUTION SAVE ERROR] ' . $e->getMessage());
    json_response(['success' => false, 'message' => 'A system error occurred while recording the distribution.'], 500);
}
