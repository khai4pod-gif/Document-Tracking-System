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

// 1) Exact tracking number match (the normal case for a barcode scan).
$exact = $pdo->prepare(
    "SELECT id, tracking_number, title FROM documents
     WHERE tracking_number = :q AND is_archived = 0 LIMIT 1"
);
$exact->execute(['q' => $query]);
$exactRow = $exact->fetch();

if ($exactRow) {
    json_response([
        'success'  => true,
        'multiple' => false,
        'id'       => (int)$exactRow['id'],
        'tracking_number' => $exactRow['tracking_number'],
    ]);
}

// 2) Partial match on tracking number or title.
$partial = $pdo->prepare(
    "SELECT id, tracking_number, title, status FROM documents
     WHERE is_archived = 0 AND (tracking_number LIKE :q1 OR title LIKE :q2)
     ORDER BY created_at DESC LIMIT 10"
);
$partial->execute(['q1' => '%' . $query . '%', 'q2' => '%' . $query . '%']);
$rows = $partial->fetchAll();

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
