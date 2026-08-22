<?php
/**
 * ajax/distributions_list.php
 * Returns { data: [...] } consumed by the Distributions DataTable.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$relief = new Relief($pdo);
$rows = $relief->listDistributions();

$data = array_map(static function (array $r): array {
    return [
        'id'                  => (int)$r['id'],
        'reference_no'        => $r['reference_no'],
        'center_name'         => $r['center_name'],
        'target_area'         => $r['target_area'],
        // Formatted for display, which sorts as text ("Jul" before "Aug");
        // the _ts field carries the real ordering.
        'distribution_date'    => date('M d, Y', strtotime($r['distribution_date'])),
        'distribution_date_ts' => strtotime($r['distribution_date']),
        'total_beneficiaries' => (int)$r['total_beneficiaries'],
        'status'              => $r['status'],
        'distributed_by_name' => $r['distributed_by_name'],
        'tracking_number'     => $r['tracking_number'],
        'document_status'     => $r['document_status'],
    ];
}, $rows);

echo json_encode(['data' => $data]);
