<?php
/**
 * ajax/documents_list.php
 * Returns { data: [...] } consumed by DataTables on documents.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$documentModel = new Document($pdo);

$filters = [
    'archived' => (isset($_GET['archived']) && $_GET['archived'] === '1'),
    'status'   => $_GET['status'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'search'   => $_GET['search'] ?? '',
];

$rows = $documentModel->listForTable($filters);

$data = array_map(static function (array $r): array {
    return [
        'id'              => (int)$r['id'],
        'tracking_number' => $r['tracking_number'],
        'title'           => $r['title'],
        'doc_type'        => $r['doc_type'],
        'priority'        => $r['priority'],
        'status'          => $r['status'],
        'holder_name'     => $r['holder_name'] ?? '—',
        'creator_name'    => $r['creator_name'],
        'created_at'      => date('M d, Y g:i A', strtotime($r['created_at'])),
        'due_date'        => $r['due_date'] ? date('M d, Y', strtotime($r['due_date'])) : null,
        'is_archived'     => (int)$r['is_archived'],
    ];
}, $rows);

echo json_encode(['data' => $data]);
