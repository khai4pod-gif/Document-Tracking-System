<?php
/**
 * config/db_connect.php
 * PDO database connection using the Singleton pattern so the whole
 * request lifecycle shares a single, safely-configured connection.
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    /** Prevent direct instantiation. */
    private function __construct()
    {
    }

    /** Prevent cloning of the singleton instance. */
    private function __clone()
    {
    }

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );

            // Pin the session to the application's timezone as a numeric
            // offset. A named zone would need MySQL's timezone tables loaded,
            // which they usually aren't on a default install; the offset is
            // derived from APP_TIMEZONE so the two can't be edited apart.
            $offset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4', time_zone = '{$offset}'",
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Never leak DB credentials or raw exception details to the client.
                error_log('[DB CONNECTION ERROR] ' . $e->getMessage());
                http_response_code(500);
                die('A system error occurred. Please try again later or contact the administrator.');
            }
        }

        return self::$instance;
    }
}
