<?php
/**
 * config/config.php
 * Global application configuration. Must be the first file included
 * on every page (sets up session, error handling, and constants).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Error reporting (turn OFF display_errors in a real production server;
// kept visible here for development convenience).
// ---------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---------------------------------------------------------------------
// Timezone. Pinned rather than inherited: PHP defaults to whatever
// date.timezone says in php.ini (UTC on this stack) while MySQL runs on
// SYSTEM, so the two drifted eight hours apart. That is not just a wrong
// greeting — every elapsed figure on the document timeline is measured by
// subtracting a MySQL timestamp from PHP's clock, and anything newer than
// the offset came out negative and was clamped to zero.
//
// Set here, before anything reads a date, and applied to the database
// connection as a matching fixed offset so the pair agrees on any host.
// ---------------------------------------------------------------------
define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------
// Secure session configuration — must run BEFORE session_start().
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // Enable the line below automatically when served over HTTPS.
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_name('DTS_DRDS_SESSION');
    session_start();
}

// ---------------------------------------------------------------------
// Database credentials — adjust to match your environment.
// ---------------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'dts_drds');
define('DB_USER', 'root');
define('DB_PASS', ''); // <-- put your actual database password here
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------
// Application constants
// ---------------------------------------------------------------------
define('APP_NAME', 'Document Tracking & Disaster Relief Distribution System');
define('APP_SHORT_NAME', 'Office of the Secretary - Document Tracking System');
/**
 * Where the app is mounted, worked out from the request rather than fixed.
 *
 * It is served two ways: as a vhost root (http://document-tracking-system.test/)
 * and from a subfolder (http://localhost/Document-Tracking-System/). The
 * subfolder form matters because browsers only grant camera access on a
 * secure origin, and localhost counts as one while a plain-http .test host
 * does not — so barcode scanning needs that URL until SSL is enabled.
 *
 * Only redirect() consumes this; every link, asset and fetch is relative.
 */
$__docRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), "/\\"));
$__appRoot = str_replace('\\', '/', dirname(__DIR__));
$__baseUrl = '/';

// Windows paths differ in case between Apache and PHP, so compare loosely.
if ($__docRoot !== '' && strncasecmp($__appRoot, $__docRoot, strlen($__docRoot)) === 0) {
    $__suffix = trim(substr($__appRoot, strlen($__docRoot)), '/');
    if ($__suffix !== '') {
        $__baseUrl = '/' . $__suffix . '/';
    }
}

define('BASE_URL', $__baseUrl);
unset($__docRoot, $__appRoot, $__baseUrl, $__suffix);
define('UPLOAD_DIR', __DIR__ . '/../uploads/documents/');
define('MANIFEST_UPLOAD_DIR', __DIR__ . '/../uploads/manifests/');
define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024);  // 10 MB
define('MAX_CLOUD_LINKS', 20);                 // per document; mirrored by the UI counter
define('MAX_CLOUD_LINK_LENGTH', 2048);         // matches document_links.url

// The fixed set of routing actions offered when a document is sent onward.
// Stored in document_routes.action_required as "CODE - LABEL"; see
// route_action_options() / is_valid_route_action() in includes/functions.php.
define('ROUTE_ACTIONS', [
    'FAA'  => 'FOR APPROPRIATE ACTION',
    'FYI'  => 'FOR INFORMATION/REFERENCE',
    'MEMO' => 'MEMORANDUM',
    'RA'   => 'REQUEST FOR APPROVAL',
    'RD'   => 'REQUEST FOR DOCUMENTS',
    'RP'   => 'REQUEST FOR PAYMENT',
]);
define('DEFAULT_ROUTE_ACTION', 'FYI - FOR INFORMATION/REFERENCE');

// How a document physically reached the next office. Recorded per hop and
// printed on every routing line of the document history, because "who had
// it when" is only half the answer when the paper and the scan travel
// separately.
define('TRANSMITTAL_MODES', ['Hard Copy', 'Soft Copy', 'Email']);
define('DEFAULT_TRANSMITTAL_MODE', 'Hard Copy');

// The per-office Document Timeline on the document view — the card list
// that groups the journey by the office holding the document and counts
// the time spent in each.
//
// Switched off in favour of the flat Document History register below it,
// which is the form the panel asked for. Everything that builds it is
// still in place and still maintained: the hops are computed on every
// document view, the styles and the expand/collapse behaviour are intact,
// and setting this back to true is the whole of turning it on again. It
// is the view that answers "how long did each office hold this", so it is
// worth keeping for the turnaround-time work.
define('SHOW_DOCUMENT_TIMELINE', false);

// Departments whose staff keep agency-wide dashboard figures. Every other
// office sees only the documents its own account created.
// Matched on departments.code — see user_sees_all_documents().
define('OVERSIGHT_DEPARTMENT_CODES', [
    'ADMIN',  // Office of the Administrator
    'MAIN',   // Office of the Secretary
]);
define('ALLOWED_UPLOAD_MIMES', [
    'application/pdf'                                                          => 'pdf',
    'application/msword'                                                       => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
]);

// Session idle timeout (seconds) — auto logout for security.
define('SESSION_IDLE_TIMEOUT', 30 * 60);

// ---------------------------------------------------------------------
// Outgoing mail (used for login OTP delivery).
//
// Real SMTP credentials belong in config/mail.local.php, which is
// gitignored and therefore never published. Copy mail.local.example.php
// to mail.local.php and fill it in — see that file for a Gmail example.
//
// Whatever that file does not define falls back to the values below,
// which target Mailpit (Laragon runs it on 127.0.0.1:1025). With those
// defaults nothing leaves this machine; every message is readable at
// http://localhost:8025.
// ---------------------------------------------------------------------
if (is_file(__DIR__ . '/mail.local.php')) {
    require __DIR__ . '/mail.local.php';
}

defined('MAIL_HOST')         || define('MAIL_HOST', '127.0.0.1');
defined('MAIL_PORT')         || define('MAIL_PORT', 1025);
defined('MAIL_ENCRYPTION')   || define('MAIL_ENCRYPTION', '');  // '', 'tls' (STARTTLS), or 'ssl'
defined('MAIL_USERNAME')     || define('MAIL_USERNAME', '');    // empty = no SMTP authentication
defined('MAIL_PASSWORD')     || define('MAIL_PASSWORD', '');
defined('MAIL_FROM_ADDRESS') || define('MAIL_FROM_ADDRESS', 'no-reply@relief-dts.local');
defined('MAIL_FROM_NAME')    || define('MAIL_FROM_NAME', APP_SHORT_NAME);
defined('MAIL_TIMEOUT')      || define('MAIL_TIMEOUT', 10);     // seconds

// ---------------------------------------------------------------------
// Login OTP (multi-factor authentication)
// ---------------------------------------------------------------------
define('OTP_LENGTH', 6);
define('OTP_TTL_SECONDS', 10 * 60);  // code validity window
define('OTP_MAX_ATTEMPTS', 5);       // wrong guesses before the code dies
define('OTP_RESEND_COOLDOWN', 60);   // seconds between resend requests
define('OTP_PENDING_TTL', 15 * 60);  // how long the half-authenticated state lives

// Self-service password reset. The window is deliberately short: the link is
// a bearer token sitting in an inbox, and anything that reaches the mailbox
// can use it.
define('PASSWORD_RESET_TTL_SECONDS', 30 * 60); // link validity window
define('PASSWORD_RESET_COOLDOWN', 120);        // seconds between requests per account
define('PASSWORD_MIN_LENGTH', 8);              // matches the admin-set minimum

// Email the recipient when a document is routed to them, alongside the in-app
// notification. Set false to fall back to the bell alone — routing itself is
// unaffected either way, since the mail is sent after the record is committed.
define('MAIL_ON_ROUTE', true);

// Accounts alerted whenever a relief distribution is recorded, so oversight
// sees relief movement without having to watch the module.
//
// Named accounts rather than a whole office on purpose: the Office of the
// Secretary has six active users, and only these two follow relief closely.
// Add a username here to widen it; an entry that matches no active account is
// skipped without error.
define('RELIEF_NOTIFY_USERNAMES', ['osec', 'osec_approver']);

// Email those offices as well as ringing their bell. Set false for the bell
// alone; recording a distribution is unaffected either way, since the mail
// goes out only after the distribution is committed.
define('MAIL_ON_DISTRIBUTION', true);

// ---------------------------------------------------------------------
// Autoload core classes (simple manual autoloader — no Composer needed).
// ---------------------------------------------------------------------
spl_autoload_register(function (string $class) {
    $paths = [
        __DIR__ . '/../classes/' . $class . '.php',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});


// Composer's autoloader, when it has been installed (it carries
// smalot/pdfparser, used to read uploaded PDFs).
//
// It has to be loaded HERE, before includes/functions.php below, and not
// lazily at the point of first use. composer.json lists
// includes/functions.php under autoload.files, and Composer includes
// those with a plain `require` guarded only against its own repeat
// calls — so if this file loads functions.php first and something
// pulls in the autoloader afterwards, functions.php is read a second
// time and every helper in it is a fatal redeclaration.
$__autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($__autoload)) {
    require_once $__autoload;
}
unset($__autoload);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
