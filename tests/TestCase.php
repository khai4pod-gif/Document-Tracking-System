<?php
/**
 * tests/TestCase.php
 * Base class for integration tests that hit a real MySQL database
 * (dts_drds_test — see PRODUCTION_CHECKLIST / README for setup).
 * Every test starts from the same known fixture set, rebuilt from
 * scratch in setUp() so tests never depend on execution order.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected static PDO $pdo;

    /** IDs of the baseline fixture rows, filled in by seed(). */
    protected int $deptA;
    protected int $deptB;
    protected int $admin;
    protected int $deptUserA;
    protected int $deptUserB;
    protected int $logistics;
    protected int $approver;
    protected int $center;
    protected int $itemFood;
    protected int $itemWater;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $name = getenv('TEST_DB_NAME') ?: 'dts_drds_test';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') ?: '';

        // Same offset config/db_connect.php pins the app's connection to —
        // must match, or CURDATE()/NOW() here disagree with PHP's clock.
        $offset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');

        self::$pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4', time_zone = '{$offset}'",
            ]
        );
    }

    protected function pdo(): PDO
    {
        return self::$pdo;
    }

    protected function setUp(): void
    {
        $this->resetSchema();
        $this->seed();
    }

    /** Truncates every table so each test starts from a clean slate. */
    private function resetSchema(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = [
            'notifications', 'document_logs', 'document_routes', 'document_attachments',
            'distribution_items', 'distributions', 'documents',
            'relief_inventory', 'evacuation_centers',
            'login_otps', 'login_attempts', 'users', 'departments',
        ];
        foreach ($tables as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Minimal, deterministic baseline every test can rely on. */
    private function seed(): void
    {
        $pdo = self::$pdo;

        $pdo->exec("INSERT INTO departments (name, code, is_active) VALUES
            ('Records Management Office', 'RECORDS', 1),
            ('Logistics & Relief Operations', 'LOGISTICS', 1)");
        $this->deptA = 1;
        $this->deptB = 2;

        $hash = password_hash('Passw0rd!123', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, full_name, role, department_id, can_route, is_active)
             VALUES (:u, :e, :h, :f, :r, :d, 1, 1)"
        );
        $insertUser = function (string $u, string $role, ?int $dept) use ($stmt, $hash): int {
            $stmt->execute([
                'u' => $u, 'e' => "{$u}@test.local", 'h' => $hash,
                'f' => ucfirst($u), 'r' => $role, 'd' => $dept,
            ]);
            return (int)self::$pdo->lastInsertId();
        };

        $this->admin      = $insertUser('admin', 'admin', null);
        $this->deptUserA  = $insertUser('clerkA', 'department', $this->deptA);
        $this->deptUserB  = $insertUser('clerkB', 'department', $this->deptB);
        $this->logistics  = $insertUser('logistics1', 'logistics', null);
        $this->approver   = $insertUser('approver1', 'approver', null);

        $pdo->exec("INSERT INTO evacuation_centers (name, target_area, is_active) VALUES
            ('San Isidro Elementary School', 'Barangay San Isidro', 1)");
        $this->center = 1;

        $itemStmt = $pdo->prepare(
            "INSERT INTO relief_inventory (item_name, category, unit, quantity_available, quantity_distributed, reorder_level)
             VALUES (:name, :cat, :unit, :qty, 0, :reorder)"
        );
        $itemStmt->execute(['name' => 'Family Food Pack', 'cat' => 'Food', 'unit' => 'pack', 'qty' => 100, 'reorder' => 20]);
        $this->itemFood = (int)$pdo->lastInsertId();
        $itemStmt->execute(['name' => 'Bottled Water (6L)', 'cat' => 'Water', 'unit' => 'pack', 'qty' => 50, 'reorder' => 10]);
        $this->itemWater = (int)$pdo->lastInsertId();
    }
}
