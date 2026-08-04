<?php
/**
 * classes/UserManager.php
 * Admin-only data access for managing user accounts: create, update,
 * activate/deactivate, and password resets.
 */

declare(strict_types=1);

class UserManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function listUsers(): array
    {
        $sql = "SELECT u.id, u.username, u.email, u.full_name, u.role, u.can_route, u.is_active,
                       u.last_login_at, u.created_at, d.name AS department_name, u.department_id
                FROM users u
                LEFT JOIN departments d ON d.id = u.department_id
                ORDER BY u.full_name ASC";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function findUser(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, username, email, full_name, role, department_id, can_route, is_active
             FROM users WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function usernameOrEmailExists(string $username, string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) AS cnt FROM users WHERE (username = :u OR email = :e)";
        $params = ['u' => $username, 'e' => $email];
        if ($excludeId !== null) {
            $sql .= " AND id != :id";
            $params['id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetch()['cnt'] > 0;
    }

    public function createUser(array $data): int
    {
        $sql = "INSERT INTO users (username, email, password_hash, full_name, role, department_id, can_route, is_active, created_at)
                VALUES (:username, :email, :password, :name, :role, :dept, :canRoute, 1, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'username' => $data['username'],
            'email'    => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'name'     => $data['full_name'],
            'role'     => $data['role'],
            'dept'     => $data['department_id'] ?: null,
            'canRoute' => !empty($data['can_route']) ? 1 : 0,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateUser(int $id, array $data): bool
    {
        $sql = "UPDATE users SET
                    username = :username, email = :email, full_name = :name,
                    role = :role, department_id = :dept, can_route = :canRoute, updated_at = NOW()
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'username' => $data['username'],
            'email'    => $data['email'],
            'name'     => $data['full_name'],
            'role'     => $data['role'],
            'dept'     => $data['department_id'] ?: null,
            'canRoute' => !empty($data['can_route']) ? 1 : 0,
            'id'       => $id,
        ]);
    }

    public function resetPassword(int $id, string $newPassword): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id");
        return $stmt->execute([
            'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id'   => $id,
        ]);
    }

    public function toggleActive(int $id): bool
    {
        $stmt = $this->pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    public function listDepartments(): array
    {
        return $this->pdo->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
    }
}
