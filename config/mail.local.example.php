<?php
/**
 * config/mail.local.example.php
 *
 * Template for machine-specific mail credentials.
 *
 *   1. Copy this file to  config/mail.local.php
 *   2. Fill in the values below
 *   3. Never commit mail.local.php — .gitignore already excludes it,
 *      because this repository is public and a committed password is a
 *      published password.
 *
 * Anything left undefined here falls back to the Mailpit defaults in
 * config/config.php, so a missing file simply means "catch mail locally".
 *
 * ---------------------------------------------------------------------
 * Gmail / Google Workspace
 * ---------------------------------------------------------------------
 * A normal account password will NOT work — Google requires an
 * "App Password" for SMTP:
 *
 *   1. Enable 2-Step Verification on the Google account
 *      (https://myaccount.google.com/security)
 *   2. Visit https://myaccount.google.com/apppasswords
 *   3. Generate a password for "Mail" and paste the 16 characters below
 *      (spaces are fine to remove)
 *
 * Gmail rewrites the From address to the authenticated account, so set
 * MAIL_FROM_ADDRESS to the same address you authenticate with.
 *
 * Port 587 with 'tls' (STARTTLS) is the usual choice. Port 465 with
 * 'ssl' also works if your network blocks 587.
 */

declare(strict_types=1);

define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_ENCRYPTION', 'tls');          // 'tls' for 587, 'ssl' for 465

define('MAIL_USERNAME', 'you@gmail.com');  // the full Google account address
define('MAIL_PASSWORD', 'your-app-password-here');

define('MAIL_FROM_ADDRESS', 'you@gmail.com'); // must match MAIL_USERNAME for Gmail
define('MAIL_FROM_NAME', 'DSWD Document Tracking System');

// Real SMTP over the internet is slower than a local catcher.
define('MAIL_TIMEOUT', 20);
