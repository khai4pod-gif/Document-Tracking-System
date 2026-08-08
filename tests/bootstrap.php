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
