<?php
/**
 * classes/Document.php
 * Encapsulates all data access and business logic for the
 * Document Tracking System (documents, routing, attachments, logs).
 */

declare(strict_types=1);

class Document
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 'Overdue' is a derived status, not a stored one: no code path writes it
     * to documents.status. It means a dated, unfinished document whose due
     * date has passed. Kept here as one definition so the dashboard tiles and
     * the documents list can never disagree about what counts as overdue.
     */
    public const DERIVED_OVERDUE = 'Overdue';
    private const OVERDUE_SQL =
        "(d.due_date IS NOT NULL AND d.due_date < CURDATE() AND d.status <> 'Completed')";

    // -------------------------------------------------------------
    // DASHBOARD / KPI QUERIES
    // -------------------------------------------------------------

    /**
     * KPI figures for the dashboard. Passing $creatorId narrows every count
     * to documents that user created; null counts agency-wide.
     */
    public function getStats(?int $creatorId = null): array
    {
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'Pending Routing' THEN 1 ELSE 0 END) AS pending_routing,
                    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE()
                              AND status NOT IN ('Completed') THEN 1 ELSE 0 END) AS overdue
                FROM documents
                WHERE is_archived = 0";

        $params = [];
        if ($creatorId !== null) {
            $sql .= " AND created_by = :creator";
            $params['creator'] = $creatorId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'total'           => (int)($row['total'] ?? 0),
            'pending_routing' => (int)($row['pending_routing'] ?? 0),
            'completed'       => (int)($row['completed'] ?? 0),
            'overdue'         => (int)($row['overdue'] ?? 0),
        ];
    }

    /**
     * Count of non-archived documents per status, for the home-page status
     * report. Every status appears even at zero, in the same order used
     * throughout the UI. $creatorId narrows to that user's own documents;
     * null counts agency-wide.
     *
     * @return array<string,int>
     */
    public function getStatusBreakdown(?int $creatorId = null): array
    {
        $sql = "SELECT status, COUNT(*) AS cnt FROM documents WHERE is_archived = 0";
        $params = [];
        if ($creatorId !== null) {
            $sql .= " AND created_by = :creator";
            $params['creator'] = $creatorId;
        }
        $sql .= " GROUP BY status";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $counts = array_fill_keys(
            ['Draft', 'Pending Routing', 'In Transit', 'Received', 'Completed', 'Overdue'],
            0
        );
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int)$row['cnt'];
        }
        return $counts;
    }

    /**
     * Monthly document counts per status, for the home-page status trend
     * line chart. Only months with at least one document appear (same
     * convention as Relief::getTrendData()) — gaps aren't zero-filled.
     * $creatorId narrows to that user's own documents; null is agency-wide.
     *
     * @return array{labels: string[], series: array<string,int[]>}
     */
    public function getStatusTrend(?int $creatorId = null, int $months = 6): array
    {
        $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, status, COUNT(*) AS cnt
                FROM documents
                WHERE is_archived = 0
                  AND created_at >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)";
        if ($creatorId !== null) {
            $sql .= " AND created_by = :creator";
        }
        $sql .= " GROUP BY ym, status ORDER BY ym ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':months', $months, PDO::PARAM_INT);
        if ($creatorId !== null) {
            $stmt->bindValue(':creator', $creatorId, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $labels = [];
        foreach ($rows as $row) {
            if (!in_array($row['ym'], $labels, true)) {
                $labels[] = $row['ym'];
            }
        }

        $statuses = ['Draft', 'Pending Routing', 'In Transit', 'Received', 'Completed', 'Overdue'];
        $series = array_fill_keys($statuses, array_fill(0, count($labels), 0));
        foreach ($rows as $row) {
            $series[$row['status']][array_search($row['ym'], $labels, true)] = (int)$row['cnt'];
        }

        return ['labels' => $labels, 'series' => $series];
    }

    public const PERFORMANCE_PERIODS = ['as_of_today', 'today', 'week', 'month', 'quarter', 'year'];

    /**
     * A due-date filter fragment for one of PERFORMANCE_PERIODS. A NULL due
     * date always matches — undated work doesn't belong to any period, so
     * it stays visible no matter which one is selected.
     */
    private function dueDatePeriodClause(string $period, string $alias = ''): string
    {
        $col = ($alias !== '' ? $alias . '.' : '') . 'due_date';

        return match ($period) {
            'as_of_today' => "({$col} IS NULL OR {$col} <= CURDATE())",
            'week'        => "({$col} IS NULL OR YEARWEEK({$col}, 3) = YEARWEEK(CURDATE(), 3))",
            'month'       => "({$col} IS NULL OR (YEAR({$col}) = YEAR(CURDATE()) AND MONTH({$col}) = MONTH(CURDATE())))",
            'quarter'     => "({$col} IS NULL OR (YEAR({$col}) = YEAR(CURDATE()) AND QUARTER({$col}) = QUARTER(CURDATE())))",
            'year'        => "({$col} IS NULL OR YEAR({$col}) = YEAR(CURDATE()))",
            default       => "({$col} IS NULL OR {$col} = CURDATE())", // 'today'
        };
    }

    /**
     * Splits a set of completed documents into compliant / non-compliant /
     * exempt against their due dates. Shared by the individual and office
     * summaries so both judge compliance the same way.
     *
     * Each row needs due_date, updated_at and completed_at. A document with
     * no due date is exempt — there is nothing to be late against.
     *
     * @return array{compliant:int, non_compliant:int, exempt:int}
     */
    private function tallyCompliance(array $rows): array
    {
        $compliant = 0;
        $nonCompliant = 0;
        $exempt = 0;

        foreach ($rows as $row) {
            if ($row['due_date'] === null) {
                $exempt++;
                continue;
            }
            $completedAt   = $row['completed_at'] ?? $row['updated_at'];
            $completedDate = (new DateTimeImmutable($completedAt))->setTime(0, 0);
            if ($completedDate <= new DateTimeImmutable($row['due_date'])) {
                $compliant++;
            } else {
                $nonCompliant++;
            }
        }

        return ['compliant' => $compliant, 'non_compliant' => $nonCompliant, 'exempt' => $exempt];
    }

    /**
     * Individual performance summary for one user's assigned work, scoped
     * to a due-date period (see PERFORMANCE_PERIODS / dueDatePeriodClause).
     *
     * "Assigned" / "Active" are the same underlying set — documents this
     * user currently holds that aren't finished — sliced two ways: by
     * whether they've acknowledged receipt yet, and by how urgent the due
     * date is. "Resolved" are the documents they've completed, judged
     * against the completion timestamp recorded in document_logs (not
     * documents.updated_at, which can move for reasons unrelated to
     * completion, e.g. a later title edit).
     *
     * @return array{
     *   assigned: array{total:int, for_action:int, pending_receipt:int},
     *   active: array{total:int, backlog:int, due_today:int, due_3days:int, no_deadline:int},
     *   resolved: array{total:int, compliant:int, non_compliant:int, exempt:int},
     *   compliance_rate: ?float,
     * }
     */
    public function getPerformanceSummary(int $userId, string $period = 'today'): array
    {
        $periodSql = $this->dueDatePeriodClause($period);

        $pendingStmt = $this->pdo->prepare(
            "SELECT status, due_date FROM documents
             WHERE current_holder_id = :user AND is_archived = 0
               AND status IN ('In Transit', 'Received')
               AND {$periodSql}"
        );
        $pendingStmt->execute(['user' => $userId]);
        $pending = $pendingStmt->fetchAll();

        $forAction = 0;
        $pendingReceipt = 0;
        $backlog = 0;
        $dueToday = 0;
        $due3Days = 0;
        $noDeadline = 0;
        $today = new DateTimeImmutable('today');
        $in3Days = $today->modify('+3 days');

        foreach ($pending as $row) {
            if ($row['status'] === 'Received') {
                $forAction++;
            } else {
                $pendingReceipt++;
            }

            if ($row['due_date'] === null) {
                $noDeadline++;
                continue;
            }
            $due = new DateTimeImmutable($row['due_date']);
            if ($due < $today) {
                $backlog++;
            } elseif ($due == $today) {
                $dueToday++;
            } elseif ($due <= $in3Days) {
                $due3Days++;
            }
        }

        $resolvedStmt = $this->pdo->prepare(
            "SELECT d.due_date, d.updated_at,
                    (SELECT MAX(l.created_at) FROM document_logs l
                      WHERE l.document_id = d.id AND l.action = 'Completed') AS completed_at
             FROM documents d
             WHERE d.current_holder_id = :user AND d.is_archived = 0
               AND d.status = 'Completed'
               AND {$periodSql}"
        );
        $resolvedStmt->execute(['user' => $userId]);
        $resolved = $resolvedStmt->fetchAll();

        // Shared with getOfficeSummary() so both panels judge lateness alike.
        $tally = $this->tallyCompliance($resolved);
        $compliant = $tally['compliant'];
        $nonCompliant = $tally['non_compliant'];
        $exempt = $tally['exempt'];

        $complianceDenominator = $compliant + $nonCompliant;

        return [
            'assigned' => [
                'total'            => count($pending),
                'for_action'       => $forAction,
                'pending_receipt'  => $pendingReceipt,
            ],
            'active' => [
                'total'       => count($pending),
                'backlog'     => $backlog,
                'due_today'   => $dueToday,
                'due_3days'   => $due3Days,
                'no_deadline' => $noDeadline,
            ],
            'resolved' => [
                'total'          => count($resolved),
                'compliant'      => $compliant,
                'non_compliant'  => $nonCompliant,
                'exempt'         => $exempt,
            ],
            'compliance_rate' => $complianceDenominator > 0 ? round($compliant / $complianceDenominator * 100, 1) : null,
        ];
    }

    /**
     * Office-wide counterpart to getPerformanceSummary(): the same period
     * slicing and the same compliance test, but scoped to a department
     * rather than one person. Passing null covers every office.
     *
     * "Belongs to this office" means the document either originated here or
     * was routed here at some point — that pair is exhaustive and disjoint,
     * so created_internally + routed_in always equals total.
     *
     * Active and resolved counts follow the document's current holder, so a
     * document that has moved on is counted by the office now holding it,
     * not by every office it has passed through.
     *
     * Deferred is the nearest thing this system has to "cancelled": archived
     * without ever reaching Completed.
     *
     * @return array{
     *   total: array{total:int, created_internally:int, routed_in:int, exempt:int},
     *   deferred: int,
     *   for_receipt: int,
     *   active: array{total:int, backlog:int, due_today:int, due_3days:int, no_deadline:int},
     *   resolved: array{total:int, compliant:int, non_compliant:int, exempt:int},
     *   compliance_rate: ?float,
     * }
     */
    public function getOfficeSummary(?int $departmentId, string $period = 'today'): array
    {
        $periodDoc = $this->dueDatePeriodClause($period, 'd');
        $scoped    = $departmentId !== null;

        // ---- Total: originated here, or routed here at some point ----
        $belongs = $scoped
            ? "(d.origin_department_id = :dept
                 OR EXISTS (SELECT 1 FROM document_routes r
                             WHERE r.document_id = d.id AND r.to_department_id = :deptRoute))"
            : '1 = 1';

        $stmt = $this->pdo->prepare(
            "SELECT d.origin_department_id, d.due_date
               FROM documents d
              WHERE d.is_archived = 0 AND {$belongs} AND {$periodDoc}"
        );
        $stmt->execute($scoped ? ['dept' => $departmentId, 'deptRoute' => $departmentId] : []);
        $owned = $stmt->fetchAll();

        $createdInternally = 0;
        $totalExempt = 0;
        foreach ($owned as $row) {
            if (!$scoped || (int)$row['origin_department_id'] === $departmentId) {
                $createdInternally++;
            }
            if ($row['due_date'] === null) {
                $totalExempt++;
            }
        }

        // ---- Deferred: archived without ever being completed ----
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM documents d
              WHERE d.is_archived = 1 AND d.status <> 'Completed'
                AND {$belongs} AND {$periodDoc}"
        );
        $stmt->execute($scoped ? ['dept' => $departmentId, 'deptRoute' => $departmentId] : []);
        $deferred = (int)$stmt->fetchColumn();

        // ---- Office for receipt: routed here, not yet acknowledged ----
        $receiptWhere = $scoped ? 'r.to_department_id = :dept' : 'r.to_department_id IS NOT NULL';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM document_routes r
               JOIN documents d ON d.id = r.document_id
              WHERE r.status = 'Pending' AND d.is_archived = 0
                AND {$receiptWhere} AND {$periodDoc}"
        );
        $stmt->execute($scoped ? ['dept' => $departmentId] : []);
        $forReceipt = (int)$stmt->fetchColumn();

        // ---- Active: held by this office and still moving ----
        $holderWhere = $scoped ? 'u.department_id = :dept' : '1 = 1';
        $stmt = $this->pdo->prepare(
            "SELECT d.status, d.due_date
               FROM documents d
               JOIN users u ON u.id = d.current_holder_id
              WHERE d.is_archived = 0 AND d.status IN ('In Transit', 'Received')
                AND {$holderWhere} AND {$periodDoc}"
        );
        $stmt->execute($scoped ? ['dept' => $departmentId] : []);
        $active = $stmt->fetchAll();

        $backlog = 0;
        $dueToday = 0;
        $due3Days = 0;
        $noDeadline = 0;
        $today   = new DateTimeImmutable('today');
        $in3Days = $today->modify('+3 days');

        foreach ($active as $row) {
            if ($row['due_date'] === null) {
                $noDeadline++;
                continue;
            }
            $due = new DateTimeImmutable($row['due_date']);
            if ($due < $today) {
                $backlog++;
            } elseif ($due == $today) {
                $dueToday++;
            } elseif ($due <= $in3Days) {
                $due3Days++;
            }
        }

        // ---- Resolved: completed and held by this office ----
        $stmt = $this->pdo->prepare(
            "SELECT d.due_date, d.updated_at,
                    (SELECT MAX(l.created_at) FROM document_logs l
                      WHERE l.document_id = d.id AND l.action = 'Completed') AS completed_at
               FROM documents d
               JOIN users u ON u.id = d.current_holder_id
              WHERE d.is_archived = 0 AND d.status = 'Completed'
                AND {$holderWhere} AND {$periodDoc}"
        );
        $stmt->execute($scoped ? ['dept' => $departmentId] : []);
        $resolved = $stmt->fetchAll();

        $tally = $this->tallyCompliance($resolved);
        $denominator = $tally['compliant'] + $tally['non_compliant'];

        return [
            'total' => [
                'total'              => count($owned),
                'created_internally' => $createdInternally,
                'routed_in'          => count($owned) - $createdInternally,
                'exempt'             => $totalExempt,
            ],
            'deferred'    => $deferred,
            'for_receipt' => $forReceipt,
            'active' => [
                'total'       => count($active),
                'backlog'     => $backlog,
                'due_today'   => $dueToday,
                'due_3days'   => $due3Days,
                'no_deadline' => $noDeadline,
            ],
            'resolved' => array_merge(['total' => count($resolved)], $tally),
            'compliance_rate' => $denominator > 0
                ? round($tally['compliant'] / $denominator * 100, 1)
                : null,
        ];
    }

    /**
     * Month-by-month compliance rate for an office, for the trend chart.
     * Documents are grouped by when they were completed, not when they were
     * raised. Months with no completed, dated work yield a null rate so the
     * line breaks rather than dropping to a misleading zero.
     *
     * @return array{labels: string[], rates: (float|null)[]}
     */
    public function getOfficeComplianceTrend(?int $departmentId, int $months = 6): array
    {
        $holderWhere = $departmentId !== null ? 'u.department_id = :dept' : '1 = 1';

        $sql = "SELECT DATE_FORMAT(COALESCE(cl.completed_at, d.updated_at), '%Y-%m') AS ym,
                       d.due_date, d.updated_at,
                       cl.completed_at
                  FROM documents d
                  JOIN users u ON u.id = d.current_holder_id
                  LEFT JOIN (
                        SELECT document_id, MAX(created_at) AS completed_at
                          FROM document_logs WHERE action = 'Completed' GROUP BY document_id
                  ) cl ON cl.document_id = d.id
                 WHERE d.is_archived = 0 AND d.status = 'Completed'
                   AND {$holderWhere}
                   AND COALESCE(cl.completed_at, d.updated_at) >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
                 ORDER BY ym ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':months', $months, PDO::PARAM_INT);
        if ($departmentId !== null) {
            $stmt->bindValue(':dept', $departmentId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $byMonth = [];
        foreach ($stmt->fetchAll() as $row) {
            $byMonth[$row['ym']][] = $row;
        }

        $labels = [];
        $rates  = [];
        foreach ($byMonth as $ym => $rows) {
            $tally = $this->tallyCompliance($rows);
            $denominator = $tally['compliant'] + $tally['non_compliant'];
            $labels[] = $ym;
            $rates[]  = $denominator > 0 ? round($tally['compliant'] / $denominator * 100, 1) : null;
        }

        return ['labels' => $labels, 'rates' => $rates];
    }

    /** $creatorId narrows the list to that user's own documents; null lists all. */
    public function getRecent(int $limit = 8, ?int $creatorId = null): array
    {
        $sql = "SELECT d.*, u.full_name AS holder_name
                FROM documents d
                LEFT JOIN users u ON u.id = d.current_holder_id
                WHERE d.is_archived = 0";
        if ($creatorId !== null) {
            $sql .= " AND d.created_by = :creator";
        }
        $sql .= " ORDER BY d.created_at DESC LIMIT :lim";

        $stmt = $this->pdo->prepare($sql);
        if ($creatorId !== null) {
            $stmt->bindValue(':creator', $creatorId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * $creatorId limits the feed to activity on that user's own documents,
     * so the timeline never names a document they cannot open.
     */
    public function getRecentActivity(int $limit = 10, ?int $creatorId = null): array
    {
        $sql = "SELECT l.*, u.full_name AS actor_name, d.tracking_number, d.title
                FROM document_logs l
                JOIN users u ON u.id = l.user_id
                JOIN documents d ON d.id = l.document_id";
        if ($creatorId !== null) {
            $sql .= " WHERE d.created_by = :creator";
        }
        $sql .= " ORDER BY l.created_at DESC LIMIT :lim";

        $stmt = $this->pdo->prepare($sql);
        if ($creatorId !== null) {
            $stmt->bindValue(':creator', $creatorId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------
    // LISTING (DataTables source)
    // -------------------------------------------------------------

    public function listForTable(array $filters = []): array
    {
        $where  = [];
        $params = [];

        $where[] = 'd.is_archived = :archived';
        $params['archived'] = !empty($filters['archived']) ? 1 : 0;

        if (!empty($filters['status'])) {
            if ($filters['status'] === self::DERIVED_OVERDUE) {
                // Nothing ever writes 'Overdue' to documents.status — it is
                // derived from the due date. Matching the literal value would
                // return an empty list beneath a dashboard tile reporting a
                // non-zero count, so use the same test the tile counts with.
                $where[] = self::OVERDUE_SQL;
            } else {
                $where[] = 'd.status = :status';
                $params['status'] = $filters['status'];
            }
        }
        if (!empty($filters['priority'])) {
            $where[] = 'd.priority = :priority';
            $params['priority'] = $filters['priority'];
        }
        if (!empty($filters['search'])) {
            // Two placeholders, not one reused: prepare emulation is off, and
            // a named parameter can only be bound once per statement.
            $where[] = '(d.title LIKE :searchTitle OR d.tracking_number LIKE :searchTracking)';
            $params['searchTitle']    = '%' . $filters['search'] . '%';
            $params['searchTracking'] = '%' . $filters['search'] . '%';
        }

        // Restrict non-admin/logistics users to documents tied to their
        // department, plus the agency-wide shared types (see SHARED_DOC_TYPES).
        if (!empty($filters['scope'])) {
            $scope = $filters['scope'];
            $sharedPlaceholders = [];
            foreach (self::SHARED_DOC_TYPES as $i => $type) {
                $sharedPlaceholders[]        = ':sharedType' . $i;
                $params['sharedType' . $i]   = $type;
            }
            $sharedSql = ' OR d.doc_type IN (' . implode(', ', $sharedPlaceholders) . ')';

            if (!empty($scope['department_id'])) {
                // The first two terms mirror isAccessibleTo(): a user always
                // sees what they created or hold, even after moving office.
                $where[] = '(d.created_by = :scopeOwnUser
                              OR d.current_holder_id = :scopeOwnHolder
                              OR d.origin_department_id = :scopeDept
                              OR holder.department_id = :scopeDeptHolder
                              OR EXISTS (
                                  SELECT 1 FROM document_routes r
                                  WHERE r.document_id = d.id
                                    AND (r.from_department_id = :scopeDeptFrom OR r.to_department_id = :scopeDeptTo)
                              )' . $sharedSql . ')';
                $params['scopeOwnUser']    = $scope['user_id'];
                $params['scopeOwnHolder']  = $scope['user_id'];
                $params['scopeDept']       = $scope['department_id'];
                $params['scopeDeptHolder'] = $scope['department_id'];
                $params['scopeDeptFrom']   = $scope['department_id'];
                $params['scopeDeptTo']     = $scope['department_id'];
            } else {
                $where[] = '(d.created_by = :scopeUser OR d.current_holder_id = :scopeUserHolder' . $sharedSql . ')';
                $params['scopeUser']       = $scope['user_id'];
                $params['scopeUserHolder'] = $scope['user_id'];
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT d.id, d.tracking_number, d.title, d.doc_type, d.priority, d.status,
                       d.due_date, d.created_at, d.is_archived, d.approval_status, d.created_by,
                       creator.full_name AS creator_name,
                       holder.full_name AS holder_name
                FROM documents d
                JOIN users creator ON creator.id = d.created_by
                LEFT JOIN users holder ON holder.id = d.current_holder_id
                {$whereSql}
                ORDER BY d.created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------
    // SINGLE DOCUMENT
    // -------------------------------------------------------------

    public function find(int $id): ?array
    {
        $sql = "SELECT d.*, creator.full_name AS creator_name,
                       holder.full_name AS holder_name,
                       dept.name AS origin_department_name
                FROM documents d
                JOIN users creator ON creator.id = d.created_by
                LEFT JOIN users holder ON holder.id = d.current_holder_id
                LEFT JOIN departments dept ON dept.id = d.origin_department_id
                WHERE d.id = :id
                LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Document types that belong to agency-wide relief operations rather
     * than to one office, so they stay readable across departments.
     */
    public const SHARED_DOC_TYPES = ['Relief Manifest'];

    /**
     * Admins and logistics staff can access every document. Everyone else
     * can access what they created or currently hold, plus documents that
     * originated in their department, are held by someone in their
     * department, or have passed through it via routing.
     *
     * Relief Manifests are exempt: the distribution they belong to is
     * already visible agency-wide (tracking number included), so every
     * office can open and route the manifest that goes with it.
     */
    public function isAccessibleTo(array $doc, array $user): bool
    {
        if (in_array($user['role'], ['admin', 'logistics', 'approver'], true)) {
            return true;
        }

        if (in_array($doc['doc_type'] ?? '', self::SHARED_DOC_TYPES, true)) {
            return true;
        }

        // Checked before the department rules, and regardless of which
        // department the user is in now: moving someone to another office
        // must not hide the documents they created in the old one. The
        // dashboard counts a user's own documents on exactly this basis.
        if ((int)($doc['created_by'] ?? 0) === (int)$user['id']
            || (int)($doc['current_holder_id'] ?? 0) === (int)$user['id']) {
            return true;
        }

        $deptId = $user['department_id'] ?? null;
        if ($deptId === null) {
            return false;
        }

        if ((int)($doc['origin_department_id'] ?? 0) === (int)$deptId) {
            return true;
        }

        $stmt = $this->pdo->prepare("SELECT department_id FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $doc['current_holder_id']]);
        $holderDept = $stmt->fetchColumn();
        if ($holderDept !== false && $holderDept !== null && (int)$holderDept === (int)$deptId) {
            return true;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM document_routes
             WHERE document_id = :doc AND (from_department_id = :dept1 OR to_department_id = :dept2)"
        );
        $stmt->execute(['doc' => $doc['id'], 'dept1' => $deptId, 'dept2' => $deptId]);
        return (int)$stmt->fetch()['cnt'] > 0;
    }

    // -------------------------------------------------------------
    // CREATE / UPDATE / ARCHIVE
    // -------------------------------------------------------------

    /**
     * @param array $data Must include 'creator_role' so the approval gate
     *                     can be applied: documents created by a
     *                     'department' user start life as 'Pending'. They
     *                     may be routed freely, but cannot be marked
     *                     completed until an admin/approver signs off.
     */
    public function create(array $data, int $userId): array
    {
        // The tracking prefix comes from the originating office, so each
        // department's documents are identifiable from the number alone.
        $originDeptId = !empty($data['origin_department_id']) ? (int)$data['origin_department_id'] : null;
        $trackingNumber = generate_tracking_number($this->pdo, $originDeptId);
        $approvalStatus = ($data['creator_role'] ?? '') === 'department' ? 'Pending' : 'Not Required';

        $sql = "INSERT INTO documents
                    (tracking_number, title, doc_type, priority, description,
                     status, origin_department_id, created_by, current_holder_id, due_date,
                     approval_status, created_at)
                VALUES
                    (:tn, :title, :type, :priority, :description,
                     'Draft', :dept, :creator, :holder, :due, :approval, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'tn'          => $trackingNumber,
            'title'       => $data['title'],
            'type'        => $data['doc_type'],
            'priority'    => $data['priority'],
            'description' => $data['description'] ?: null,
            'dept'        => $data['origin_department_id'] ?: null,
            'creator'     => $userId,
            'holder'      => $userId,
            'due'         => $data['due_date'] ?: null,
            'approval'    => $approvalStatus,
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        log_document_action($this->pdo, $newId, $userId, 'Created', "Document \"{$data['title']}\" created.");

        return ['id' => $newId, 'tracking_number' => $trackingNumber];
    }

    /**
     * Editing a previously-rejected document is treated as a resubmission:
     * its approval gate resets to 'Pending' (and the old decision is
     * cleared) so it goes back through the approver before it can be
     * routed again. Documents in any other approval state are unaffected.
     */
    public function update(int $id, array $data, int $userId): bool
    {
        $sql = "UPDATE documents SET
                    title = :title,
                    doc_type = :type,
                    priority = :priority,
                    description = :description,
                    due_date = :due,
                    approved_by = CASE WHEN approval_status = 'Rejected' THEN NULL ELSE approved_by END,
                    approved_at = CASE WHEN approval_status = 'Rejected' THEN NULL ELSE approved_at END,
                    approval_status = CASE WHEN approval_status = 'Rejected' THEN 'Pending' ELSE approval_status END,
                    updated_at = NOW()
                WHERE id = :id AND is_archived = 0";
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute([
            'title'       => $data['title'],
            'type'        => $data['doc_type'],
            'priority'    => $data['priority'],
            'description' => $data['description'] ?: null,
            'due'         => $data['due_date'] ?: null,
            'id'          => $id,
        ]);

        if ($ok) {
            log_document_action($this->pdo, $id, $userId, 'Updated', 'Document metadata updated.');
        }
        return $ok;
    }

    /**
     * Archiving is how a document is closed out, so it records why. The
     * conclusion remarks are kept on the document (for the detail view) and
     * repeated in the audit log (for the timeline).
     */
    public function archive(int $id, int $userId, string $conclusionRemarks): bool
    {
        $remarks = mb_substr(trim($conclusionRemarks), 0, 500);

        $stmt = $this->pdo->prepare(
            "UPDATE documents
                SET is_archived = 1, conclusion_remarks = :remarks, updated_at = NOW()
              WHERE id = :id"
        );
        $ok = $stmt->execute(['remarks' => $remarks, 'id' => $id]);

        if ($ok) {
            // document_logs.details is VARCHAR(500) and the prefix eats into it.
            log_document_action(
                $this->pdo, $id, $userId, 'Archived',
                mb_substr('Archived — ' . $remarks, 0, 500)
            );
        }
        return $ok;
    }

    public function restore(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE documents SET is_archived = 0, updated_at = NOW() WHERE id = :id");
        $ok = $stmt->execute(['id' => $id]);
        if ($ok) {
            log_document_action($this->pdo, $id, $userId, 'Restored', 'Document restored from archive.');
        }
        return $ok;
    }

    public function markCompleted(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE documents SET status = 'Completed', updated_at = NOW() WHERE id = :id"
        );
        $ok = $stmt->execute(['id' => $id]);
        if ($ok) {
            log_document_action($this->pdo, $id, $userId, 'Completed', 'Document marked as completed.');
        }
        return $ok;
    }

    /**
     * Admin/approver signs off on (or rejects) a document that's awaiting
     * approval. Only has an effect while approval_status is 'Pending'.
     */
    public function decideApproval(int $id, int $approverId, string $decision, ?string $remarks = null): bool
    {
        if (!in_array($decision, ['Approved', 'Rejected'], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE documents SET approval_status = :decision, approved_by = :approver, approved_at = NOW(), updated_at = NOW()
             WHERE id = :id AND approval_status = 'Pending'"
        );
        $stmt->execute(['decision' => $decision, 'approver' => $approverId, 'id' => $id]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $action = $decision === 'Approved' ? 'Approved' : 'Rejected';
        log_document_action($this->pdo, $id, $approverId, $action, $remarks ?: null);
        return true;
    }

    // -------------------------------------------------------------
    // ROUTING / WORKFLOW
    // -------------------------------------------------------------

    public function route(int $documentId, array $data, int $fromUserId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO document_routes
                    (document_id, from_user_id, to_user_id, from_department_id, to_department_id,
                     action_required, remarks, status, routed_at)
                 VALUES
                    (:doc, :from_user, :to_user, :from_dept, :to_dept, :action, :remarks, 'Pending', NOW())"
            );
            $stmt->execute([
                'doc'       => $documentId,
                'from_user' => $fromUserId,
                'to_user'   => $data['to_user_id'],
                'from_dept' => $data['from_department_id'] ?: null,
                'to_dept'   => $data['to_department_id'] ?: null,
                'action'    => $data['action_required'],
                'remarks'   => $data['remarks'] ?: null,
            ]);

            $update = $this->pdo->prepare(
                "UPDATE documents SET status = 'In Transit', current_holder_id = :holder, updated_at = NOW()
                 WHERE id = :id"
            );
            $update->execute(['holder' => $data['to_user_id'], 'id' => $documentId]);

            log_document_action(
                $this->pdo, $documentId, $fromUserId, 'Routed',
                "Routed to user #{$data['to_user_id']} — Action required: {$data['action_required']}"
            );

            $notif = $this->pdo->prepare(
                "INSERT INTO notifications (user_id, message, link, created_at)
                 VALUES (:user, :message, :link, NOW())"
            );
            $notif->execute([
                'user'    => $data['to_user_id'],
                'message' => 'A document has been routed to you for action.',
                'link'    => 'document_view.php?id=' . $documentId,
            ]);

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[ROUTE ERROR] ' . $e->getMessage());
            return false;
        }
    }

    public function receiveRoute(int $routeId, int $userId): bool
    {
        $stmt = $this->pdo->prepare("SELECT * FROM document_routes WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $routeId]);
        $route = $stmt->fetch();

        if (!$route || (int)$route['to_user_id'] !== $userId) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                "UPDATE document_routes SET status = 'Received', received_at = NOW() WHERE id = :id"
            );
            $update->execute(['id' => $routeId]);

            $docUpdate = $this->pdo->prepare(
                "UPDATE documents SET status = 'Received', updated_at = NOW() WHERE id = :id"
            );
            $docUpdate->execute(['id' => $route['document_id']]);

            log_document_action($this->pdo, (int)$route['document_id'], $userId, 'Received', 'Document received by assignee.');

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[RECEIVE ERROR] ' . $e->getMessage());
            return false;
        }
    }

    public function getRoutes(int $documentId): array
    {
        $sql = "SELECT r.*, fu.full_name AS from_name, tu.full_name AS to_name,
                       fd.name AS from_dept_name, td.name AS to_dept_name
                FROM document_routes r
                JOIN users fu ON fu.id = r.from_user_id
                JOIN users tu ON tu.id = r.to_user_id
                LEFT JOIN departments fd ON fd.id = r.from_department_id
                LEFT JOIN departments td ON td.id = r.to_department_id
                WHERE r.document_id = :doc
                ORDER BY r.routed_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['doc' => $documentId]);
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------
    // AUDIT TRAIL
    // -------------------------------------------------------------

    public function getLogs(int $documentId): array
    {
        $sql = "SELECT l.*, u.full_name AS actor_name, u.role AS actor_role
                FROM document_logs l
                JOIN users u ON u.id = l.user_id
                WHERE l.document_id = :doc
                ORDER BY l.created_at ASC, l.id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['doc' => $documentId]);
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------
    // ATTACHMENTS
    // -------------------------------------------------------------

    public function addAttachment(int $documentId, array $fileMeta, int $userId): int
    {
        $sql = "INSERT INTO document_attachments
                    (document_id, original_name, stored_name, file_path, mime_type, file_size, uploaded_by, uploaded_at)
                VALUES (:doc, :orig, :stored, :path, :mime, :size, :user, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'doc'    => $documentId,
            'orig'   => $fileMeta['original_name'],
            'stored' => $fileMeta['stored_name'],
            'path'   => $fileMeta['file_path'],
            'mime'   => $fileMeta['mime_type'],
            'size'   => $fileMeta['file_size'],
            'user'   => $userId,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        log_document_action($this->pdo, $documentId, $userId, 'Attachment Added', $fileMeta['original_name']);
        return $id;
    }

    public function getAttachments(int $documentId): array
    {
        $sql = "SELECT a.*, u.full_name AS uploader_name
                FROM document_attachments a
                JOIN users u ON u.id = a.uploaded_by
                WHERE a.document_id = :doc
                ORDER BY a.uploaded_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['doc' => $documentId]);
        return $stmt->fetchAll();
    }

    public function findAttachment(int $attachmentId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM document_attachments WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $attachmentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function deleteAttachment(int $attachmentId, int $userId): bool
    {
        $attachment = $this->findAttachment($attachmentId);
        if (!$attachment) {
            return false;
        }

        $stmt = $this->pdo->prepare("DELETE FROM document_attachments WHERE id = :id");
        $ok = $stmt->execute(['id' => $attachmentId]);

        if ($ok) {
            (new FileUploader(dirname($attachment['file_path'])))->delete($attachment['file_path']);
            log_document_action(
                $this->pdo, (int)$attachment['document_id'], $userId,
                'Attachment Removed', $attachment['original_name']
            );
        }
        return $ok;
    }

    // -----------------------------------------------------------------
    // Cloud links — pointers to files hosted elsewhere (Drive, OneDrive,
    // SharePoint) instead of copies uploaded into uploads/documents/.
    // -----------------------------------------------------------------

    /**
     * Links reuse the 'Attachment Added' / 'Attachment Removed' log actions
     * rather than adding values to the document_logs enum, so the audit
     * trail stays complete without a migration on an existing table. The
     * "Cloud link:" prefix in details is what tells the two apart.
     *
     * Expects a URL already vetted by sanitize_cloud_link().
     */
    public function addLink(int $documentId, string $url, int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO document_links (document_id, url, added_by, added_at)
             VALUES (:doc, :url, :user, NOW())"
        );
        $stmt->execute(['doc' => $documentId, 'url' => $url, 'user' => $userId]);
        $id = (int)$this->pdo->lastInsertId();

        // document_logs.details is VARCHAR(500) and a URL may be far longer.
        log_document_action(
            $this->pdo, $documentId, $userId,
            'Attachment Added', mb_substr('Cloud link: ' . $url, 0, 500)
        );
        return $id;
    }

    public function getLinks(int $documentId): array
    {
        $sql = "SELECT l.*, u.full_name AS added_by_name
                FROM document_links l
                JOIN users u ON u.id = l.added_by
                WHERE l.document_id = :doc
                ORDER BY l.added_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['doc' => $documentId]);
        return $stmt->fetchAll();
    }

    public function countLinks(int $documentId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM document_links WHERE document_id = :doc");
        $stmt->execute(['doc' => $documentId]);
        return (int)$stmt->fetchColumn();
    }

    public function findLink(int $linkId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM document_links WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $linkId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function deleteLink(int $linkId, int $userId): bool
    {
        $link = $this->findLink($linkId);
        if (!$link) {
            return false;
        }

        $stmt = $this->pdo->prepare("DELETE FROM document_links WHERE id = :id");
        $ok = $stmt->execute(['id' => $linkId]);

        if ($ok) {
            log_document_action(
                $this->pdo, (int)$link['document_id'], $userId,
                'Attachment Removed', mb_substr('Cloud link: ' . $link['url'], 0, 500)
            );
        }
        return $ok;
    }
}
