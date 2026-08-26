<?php
/**
 * ajax/document_lookup.php
 * Resolves a scanned barcode value or a manual search query to a
 * document. Used by home.php's scanner and quick-search box.
 *
 * - Exact tracking number match (from a barcode scan) -> single redirect.
 * - Partial match on tracking number or title -> may return several
 *   results for the caller to disambiguate.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '') {
    json_response(['success' => false, 'message' => 'Please enter a tracking number or title to search.'], 422);
}

$pdo = Database::getConnection();
$documentModel = new Document($pdo);
$user = current_user();

// 1) Exact tracking number match (the normal case for a barcode scan).
$exact = $pdo->prepare(
    "SELECT id, tracking_number, title FROM documents
     WHERE tracking_number = :q AND is_archived = 0 LIMIT 1"
);
$exact->execute(['q' => $query]);
$exactRow = $exact->fetch();

if ($exactRow) {
    // Scanning a slip for another office's document should say so plainly
    // rather than open a page that immediately refuses — the physical slip
    // is in the reader's hands, so its existence is not the secret.
    $doc = $documentModel->find((int)$exactRow['id']);
    if (!$documentModel->isAccessibleTo($doc, $user)) {
        json_response([
            'success' => false,
            'message' => 'That document belongs to another office and is not available to you.',
        ], 403);
    }

    json_response([
        'success'  => true,
        'multiple' => false,
        'id'       => (int)$exactRow['id'],
        'tracking_number' => $exactRow['tracking_number'],
    ]);
}

// 2) Partial match, scoped exactly like the documents list. Searching
//    without that scope let any signed-in account enumerate every title and
//    tracking number in the agency by typing a single letter, even though
//    opening the results was already blocked.
$filters = ['archived' => false, 'search' => $query];
if (!in_array($user['role'], ['admin', 'logistics', 'approver'], true)) {
    $filters['scope'] = [
        'department_id' => $user['department_id'],
        'user_id'       => (int)$user['id'],
    ];
}

$rows = array_slice($documentModel->listForTable($filters), 0, 10);

if (empty($rows)) {
    json_response(['success' => false, 'message' => 'No document found matching "' . $query . '".'], 404);
}

if (count($rows) === 1) {
    json_response([
        'success'  => true,
        'multiple' => false,
        'id'       => (int)$rows[0]['id'],
        'tracking_number' => $rows[0]['tracking_number'],
    ]);
}

json_response([
    'success'  => true,
    'multiple' => true,
    'results'  => array_map(static fn($r) => [
        'id' => (int)$r['id'], 'tracking_number' => $r['tracking_number'],
        'title' => $r['title'], 'status' => $r['status'],
    ], $rows),
]);
