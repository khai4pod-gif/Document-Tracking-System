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

// PasswordReset reads these directly (see classes/PasswordReset.php).
if (!defined('PASSWORD_RESET_TTL_SECONDS')) {
    define('PASSWORD_RESET_TTL_SECONDS', 30 * 60);
}
if (!defined('PASSWORD_RESET_COOLDOWN')) {
    define('PASSWORD_RESET_COOLDOWN', 120);
}

// Document::route()'s MAIL_ON_ROUTE check sits outside emailRouteRecipient()'s
// own try/catch, so an undefined constant here is a fatal Error thrown after
// route() has already committed — its catch block then calls rollBack() on a
// transaction that no longer exists, which itself throws. Defined false
// (rather than mirroring production's true) so tests never exercise the mail
// path at all — no need for Mailpit/SMTP to be reachable during a test run.
if (!defined('MAIL_ON_ROUTE')) {
    define('MAIL_ON_ROUTE', false);
}
// Relief::notifyOversight() reads both of these inside its own try/catch, so
// they're safe by construction either way — defined for the same reason as
// above, and RELIEF_NOTIFY_USERNAMES = [] means the function returns before
// MAIL_ON_DISTRIBUTION would even be reached.
if (!defined('RELIEF_NOTIFY_USERNAMES')) {
    define('RELIEF_NOTIFY_USERNAMES', []);
}
if (!defined('MAIL_ON_DISTRIBUTION')) {
    define('MAIL_ON_DISTRIBUTION', false);
}

// Document::route() reads this before its transaction even begins (not
// inside any try/catch) to validate the optional transmittal_mode field —
// an undefined constant there is an immediate fatal Error on every route()
// call. Must match config/config.php's TRANSMITTAL_MODES exactly, since
// callers pass real values from that list.
if (!defined('TRANSMITTAL_MODES')) {
    define('TRANSMITTAL_MODES', ['Hard Copy', 'Soft Copy', 'Email']);
}
