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
define('ALLOWED_UPLOAD_MIMES', [
    'application/pdf'                                                          => 'pdf',
    'application/msword'                                                       => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
]);

// Session idle timeout (seconds) — auto logout for security.
define('SESSION_IDLE_TIMEOUT', 30 * 60);

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
