<?php
/**
 * tests/bootstrap.php
 * Loaded by PHPUnit before any test runs. Deliberately does not touch
 * config/config.php — that file starts a session and opens a connection
 * to the dev database. Tests get their own PDO (see TestCase) pointed at
 * a dedicated dts_drds_test database, created from schema.sql.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Constants the classes/functions under test reference indirectly.
if (!defined('APP_SHORT_NAME')) {
    define('APP_SHORT_NAME', 'DTS-DRDS Test');
}

// Must match config/config.php's APP_TIMEZONE. PHP defaults to UTC here
// (this host's php.ini) while MySQL runs 8 hours ahead, so any test whose
// due-date bucketing compares PHP's notion of "today" against a MySQL
// CURDATE()/NOW() — exactly what getPerformanceSummary() and
// getOfficeSummary() do — would silently disagree with the database near
// midnight without this. See config/config.php for the production fix
// this mirrors, and config/db_connect.php for the matching DB-side pin
// applied to TestCase's own connection.
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Asia/Manila');
}
date_default_timezone_set(APP_TIMEZONE);
