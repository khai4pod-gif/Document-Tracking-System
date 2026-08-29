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
$user = current_user();

$filters = [
    'archived' => (isset($_GET['archived']) && $_GET['archived'] === '1'),
    'status'   => $_GET['status'] ?? '',
    'priority' => $_GET['priority'] ?? '',
    'search'   => $_GET['search'] ?? '',
    // Compliance drill-through from the Home analytics cards. Validated
    // against the model's own lists so a hand-edited URL can't reach a state
    // the interface has no way to represent or clear.
    'compliance' => in_array($_GET['compliance'] ?? '', Document::COMPLIANCE_FILTERS, true)
        ? $_GET['compliance'] : '',
    'period'     => in_array($_GET['period'] ?? '', Document::PERFORMANCE_PERIODS, true)
        ? $_GET['period'] : '',
];

if (!in_array($user['role'], ['admin', 'logistics', 'approver'], true)) {
    $filters['scope'] = ['department_id' => $user['department_id'], 'user_id' => (int)$user['id']];
}

$rows = $documentModel->listForTable($filters);

$data = array_map(static function (array $r): array {
    return [
        'id'              => (int)$r['id'],
        'tracking_number' => $r['tracking_number'],
        'title'           => $r['title'],
        'doc_type'        => $r['doc_type'],
        'priority'        => $r['priority'],
        'status'          => $r['status'],
        'approval_status' => $r['approval_status'],
        'holder_name'     => $r['holder_name'] ?? '—',
        'creator_name'    => $r['creator_name'],
        'created_by'      => (int)$r['created_by'],
        // created_at is pre-formatted for display, which sorts as text
        // ("Jul" before "Aug"). created_at_ts carries the real ordering.
        'created_at'      => date('M d, Y g:i A', strtotime($r['created_at'])),
        'created_at_ts'   => strtotime($r['created_at']),
        'due_date'        => $r['due_date'] ? date('M d, Y', strtotime($r['due_date'])) : null,
        'is_archived'     => (int)$r['is_archived'],
    ];
}, $rows);

echo json_encode(['data' => $data]);
