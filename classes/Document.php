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

    // -------------------------------------------------------------
    // DASHBOARD / KPI QUERIES
    // -------------------------------------------------------------

    public function getStats(): array
    {
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'Pending Routing' THEN 1 ELSE 0 END) AS pending_routing,
                    SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE()
                              AND status NOT IN ('Completed') THEN 1 ELSE 0 END) AS overdue
                FROM documents
                WHERE is_archived = 0";
        $row = $this->pdo->query($sql)->fetch();

        return [
            'total'           => (int)($row['total'] ?? 0),
            'pending_routing' => (int)($row['pending_routing'] ?? 0),
            'completed'       => (int)($row['completed'] ?? 0),
            'overdue'         => (int)($row['overdue'] ?? 0),
        ];
    }

    public function getRecent(int $limit = 8): array
    {
        $sql = "SELECT d.*, u.full_name AS holder_name
                FROM documents d
                LEFT JOIN users u ON u.id = d.current_holder_id
                WHERE d.is_archived = 0
                ORDER BY d.created_at DESC
                LIMIT :lim";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getRecentActivity(int $limit = 10): array
    {
        $sql = "SELECT l.*, u.full_name AS actor_name, d.tracking_number, d.title
                FROM document_logs l
                JOIN users u ON u.id = l.user_id
                JOIN documents d ON d.id = l.document_id
                ORDER BY l.created_at DESC
                LIMIT :lim";
        $stmt = $this->pdo->prepare($sql);
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
            $where[] = 'd.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['priority'])) {
            $where[] = 'd.priority = :priority';
            $params['priority'] = $filters['priority'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(d.title LIKE :search OR d.tracking_number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT d.id, d.tracking_number, d.title, d.doc_type, d.priority, d.status,
                       d.due_date, d.created_at, d.is_archived,
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

    // -------------------------------------------------------------
    // CREATE / UPDATE / ARCHIVE
    // -------------------------------------------------------------

    public function create(array $data, int $userId): array
    {
        $trackingNumber = generate_tracking_number($this->pdo);

        $sql = "INSERT INTO documents
                    (tracking_number, title, doc_type, priority, description,
                     status, origin_department_id, created_by, current_holder_id, due_date, created_at)
                VALUES
                    (:tn, :title, :type, :priority, :description,
                     'Draft', :dept, :creator, :holder, :due, NOW())";
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
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        log_document_action($this->pdo, $newId, $userId, 'Created', "Document \"{$data['title']}\" created.");

        return ['id' => $newId, 'tracking_number' => $trackingNumber];
    }

    public function update(int $id, array $data, int $userId): bool
    {
        $sql = "UPDATE documents SET
                    title = :title,
                    doc_type = :type,
                    priority = :priority,
                    description = :description,
                    due_date = :due,
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

    public function archive(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE documents SET is_archived = 1, updated_at = NOW() WHERE id = :id");
        $ok = $stmt->execute(['id' => $id]);
        if ($ok) {
            log_document_action($this->pdo, $id, $userId, 'Archived', 'Document archived (soft delete).');
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
                ORDER BY l.created_at ASC";
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
}
