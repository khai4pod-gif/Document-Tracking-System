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
define('BASE_URL', '/');                       // relief-dts.test's DocumentRoot IS this project folder
define('UPLOAD_DIR', __DIR__ . '/../uploads/documents/');
define('MANIFEST_UPLOAD_DIR', __DIR__ . '/../uploads/manifests/');
define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024);  // 10 MB
define('MAX_CLOUD_LINKS', 20);                 // per document; mirrored by the UI counter
define('MAX_CLOUD_LINK_LENGTH', 2048);         // matches document_links.url

// The fixed set of routing actions offered when a document is sent onward.
// Stored in document_routes.action_required as "CODE - LABEL"; see
// route_action_options() / is_valid_route_action() in includes/functions.php.
define('ROUTE_ACTIONS', [
    'FYI'  => 'FOR YOUR INFORMATION',
    'FYR'  => 'FOR YOUR REFERENCE',
    'MEMO' => 'MEMORANDUM',
    'RA'   => 'REQUEST FOR APPROVAL',
    'RD'   => 'REQUEST FOR DOCUMENTS',
    'RP'   => 'REQUEST FOR PAYMENT',
]);
define('DEFAULT_ROUTE_ACTION', 'FYI - FOR YOUR INFORMATION');
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

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../includes/functions.php';
