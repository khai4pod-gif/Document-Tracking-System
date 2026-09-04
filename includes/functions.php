<?php
/**
 * includes/functions.php
 * Shared helper functions used across the whole application.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Output / escaping helper
// ---------------------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------
// CSRF PROTECTION
// ---------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Call at the top of every state-changing POST handler.
 * Halts execution with a 403 on failure.
 */
function csrf_protect(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        http_response_code(403);
        if (is_ajax_request()) {
            json_response(['success' => false, 'message' => 'Invalid or expired security token. Please refresh the page.'], 403);
        }
        die('Invalid security token. Please go back and try again.');
    }
}

function is_ajax_request(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------
// FLASH MESSAGES (one-time toast notifications after redirects)
// ---------------------------------------------------------------------
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// ---------------------------------------------------------------------
// AUTH GUARDS
// ---------------------------------------------------------------------
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }

    // Idle timeout enforcement
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        redirect('login.php?timeout=1');
    }
    $_SESSION['last_activity'] = time();
}

/**
 * @param string[] $roles Allowed roles, e.g. ['admin','logistics']
 */
function require_role(array $roles): void
{
    require_login();
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('Access denied: you do not have permission to view this page.');
    }
}

/**
 * Whether this user's dashboard shows agency-wide figures, or only the
 * documents they created themselves.
 *
 * The Office of the Administrator and the Office of the Secretary oversee
 * every other office, so their staff keep the full picture. Role 'admin' is
 * included as well so a system administrator assigned to some other office
 * still keeps the overview.
 */
function user_sees_all_documents(array $user, PDO $pdo): bool
{
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    if (empty($user['department_id'])) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT code FROM departments WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $user['department_id']]);
    $code = $stmt->fetchColumn();

    return $code !== false && in_array((string)$code, OVERSIGHT_DEPARTMENT_CODES, true);
}

/**
 * Only Department accounts are ever gated by the can_route flag; every
 * other role can always route. Queried fresh (not from the session) so
 * an admin revoking access takes effect on the user's very next request.
 */
function user_can_route(array $user, PDO $pdo): bool
{
    if ($user['role'] !== 'department') {
        return true;
    }
    $stmt = $pdo->prepare("SELECT can_route FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $user['id']]);
    return (int)$stmt->fetchColumn() === 1;
}

function redirect(string $path): void
{
    header('Location: ' . BASE_URL . ltrim($path, '/'));
    exit;
}

// ---------------------------------------------------------------------
// MISC HELPERS
// ---------------------------------------------------------------------

/**
 * Human-readable elapsed time for the document timeline, e.g. "14d 5h 37m",
 * "5h 37m", "37m". Minutes are always shown so a fresh hop never reads as
 * an empty span.
 */
function format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);

    $days    = intdiv($seconds, 86400);
    $hours   = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($days > 0 || $hours > 0) {
        $parts[] = $hours . 'h';
    }
    $parts[] = $minutes . 'm';

    return implode(' ', $parts);
}

/**
 * The tracking-number prefix for a department: its code, reduced to the
 * characters that are safe inside an identifier.
 *
 * departments.code is editable from the admin UI, so it cannot be assumed
 * clean — a code like "PR/S 1" would otherwise produce an unparseable
 * tracking number. Anything left empty after stripping falls back to DOC.
 */
function tracking_prefix_for_department(PDO $pdo, ?int $departmentId): string
{
    if ($departmentId === null || $departmentId <= 0) {
        return 'DOC';
    }

    $stmt = $pdo->prepare("SELECT code FROM departments WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $departmentId]);
    $code = (string)$stmt->fetchColumn();

    $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    if ($clean === '') {
        return 'DOC';
    }

    // The whole number must fit tracking_number's VARCHAR(30), and
    // "-YYYY-NNNNNN" already costs 12 characters.
    return substr($clean, 0, 18);
}

/**
 * Generates a unique, human-readable document tracking number.
 *
 * The prefix is the creating department's code, so an office's documents are
 * recognisable at a glance: DMS-2026-000001, PRS-2026-000014. Documents with
 * no originating department — relief manifests, unassigned accounts — fall
 * back to DOC. Each prefix carries its own sequence within the year.
 */
function generate_tracking_number(PDO $pdo, ?int $departmentId = null): string
{
    $year   = date('Y');
    $prefix = tracking_prefix_for_department($pdo, $departmentId);

    // MAX of the numeric suffix rather than COUNT: if a row is ever deleted,
    // COUNT would hand out a number that has already been issued and collide
    // with the UNIQUE index on tracking_number.
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(tracking_number, '-', -1) AS UNSIGNED))
         FROM documents
         WHERE tracking_number LIKE :prefix"
    );
    $stmt->execute(['prefix' => "{$prefix}-{$year}-%"]);
    $next = (int)$stmt->fetchColumn() + 1;

    return sprintf('%s-%s-%06d', $prefix, $year, $next);
}

/** Generates a unique reference number for relief distributions, e.g. REL-2026-000045 */
function generate_reference_number(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt FROM distributions WHERE reference_no LIKE :prefix"
    );
    $stmt->execute(['prefix' => "REL-{$year}-%"]);
    $count = (int)$stmt->fetch()['cnt'] + 1;
    return sprintf('REL-%s-%06d', $year, $count);
}

/** Records an immutable audit-trail entry for a document. */
/**
 * The routing actions offered in the "Action Required" dropdowns, as the
 * "CODE - LABEL" strings that get written to document_routes.action_required.
 *
 * @return string[]
 */
function route_action_options(): array
{
    $options = [];
    foreach (ROUTE_ACTIONS as $code => $label) {
        $options[] = $code . ' - ' . $label;
    }
    return $options;
}

/**
 * Whitelist check for a submitted action. The field is a fixed dropdown, so
 * anything outside the list arrived by hand-crafted POST rather than the UI.
 *
 * Routes created before the dropdown existed hold free text; this is only
 * applied to new submissions, never to stored history.
 */
function is_valid_route_action(string $action): bool
{
    return in_array($action, route_action_options(), true);
}

/**
 * Splits a stored action into its code and label so the printed routing slip
 * can set the code apart from the wording.
 *
 * Routes created before the dropdown existed hold arbitrary free text with no
 * code; those come back with an empty code and the original text as the label.
 *
 * @return array{code: string, label: string}
 */
function route_action_parts(string $action): array
{
    $bits = explode(' - ', $action, 2);
    if (count($bits) === 2 && isset(ROUTE_ACTIONS[$bits[0]])) {
        return ['code' => $bits[0], 'label' => $bits[1]];
    }
    return ['code' => '', 'label' => $action];
}

/**
 * Validates a user-supplied cloud link, returning the trimmed URL or null if
 * it is blank, over-long, or not a usable web address.
 *
 * The scheme check is the part that matters: these URLs are rendered straight
 * into an href on the document view, so without it a `javascript:` value
 * stored here would become a working XSS payload for anyone who clicks it.
 */
function sanitize_cloud_link(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || mb_strlen($url) > MAX_CLOUD_LINK_LENGTH) {
        return null;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    return $url;
}

function log_document_action(PDO $pdo, int $documentId, int $userId, string $action, ?string $details = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO document_logs (document_id, user_id, action, details, created_at)
         VALUES (:doc, :user, :action, :details, NOW())"
    );
    $stmt->execute([
        'doc'     => $documentId,
        'user'    => $userId,
        'action'  => $action,
        'details' => $details,
    ]);
}

function badge_class_for_priority(string $priority): string
{
    return match ($priority) {
        'Urgent' => 'bg-danger',
        'High'   => 'bg-warning text-dark',
        'Normal' => 'bg-info text-dark',
        default  => 'bg-secondary',
    };
}

/**
 * Cache-busting URL for a local asset. Apache serves these static files
 * without a Cache-Control header, so a browser can hold on to a stale
 * copy after an edit; appending the file's mtime forces a fresh fetch
 * whenever the file actually changes.
 */
function asset(string $path): string
{
    $full = __DIR__ . '/../' . ltrim($path, '/');
    return $path . '?v=' . (is_file($full) ? filemtime($full) : time());
}

/**
 * Human-readable label for a role. Purely presentational — the stored
 * value (and every permission check) still uses the raw role key, so
 * renaming a label here never affects access control.
 */
function role_label(string $role): string
{
    return match ($role) {
        'admin'      => 'Administrator',
        'department' => 'Department',
        'logistics'  => 'Logistics',
        'approver'   => 'Approver',
        default      => ucfirst($role),
    };
}

function badge_class_for_status(string $status): string
{
    return match ($status) {
        'Completed'        => 'bg-success',
        'Overdue'          => 'bg-danger',
        'In Transit'       => 'bg-primary',
        'Pending Routing'  => 'bg-warning text-dark',
        'Received'         => 'bg-info text-dark',
        default            => 'bg-secondary', // Draft
    };
}

function badge_class_for_approval(string $approvalStatus): string
{
    return match ($approvalStatus) {
        'Pending'  => 'bg-warning text-dark',
        'Approved' => 'bg-success',
        'Rejected' => 'bg-danger',
        default    => 'bg-secondary', // Not Required
    };
}

// ---------------------------------------------------------------------
// LOGIN OTP DELIVERY
// ---------------------------------------------------------------------

/**
 * Emails a login passcode to the user.
 *
 * @throws RuntimeException if the message could not be sent — the caller
 *         is expected to fail the login rather than let it through.
 */
function send_login_otp(array $user, string $code): void
{
    $minutes = (int)round(OTP_TTL_SECONDS / 60);
    $appName = APP_SHORT_NAME;

    $text = "Hello {$user['full_name']},\r\n\r\n"
        . "Your verification code is: {$code}\r\n\r\n"
        . "It expires in {$minutes} minutes and can only be used once.\r\n\r\n"
        . "If you did not try to sign in, someone may have your password — "
        . "please change it as soon as you can.\r\n\r\n"
        . "-- {$appName}";

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#1a1a2e;">'
        . '<div style="background:#0b2e5e;color:#fff;padding:18px 24px;border-radius:10px 10px 0 0;">'
        . '<div style="font-size:16px;font-weight:700;">' . e($appName) . '</div>'
        . '<div style="font-size:12px;opacity:.8;">Sign-in verification</div>'
        . '</div>'
        . '<div style="border:1px solid #e3e3e8;border-top:0;border-radius:0 0 10px 10px;padding:24px;">'
        . '<p style="margin:0 0 16px;">Hello <strong>' . e($user['full_name']) . '</strong>,</p>'
        . '<p style="margin:0 0 12px;">Use this verification code to finish signing in:</p>'
        . '<div style="font-size:32px;font-weight:800;letter-spacing:8px;text-align:center;'
        . 'background:#f5f7fa;border:1px dashed #c8952b;border-radius:10px;padding:18px;margin:0 0 16px;">'
        . e($code) . '</div>'
        . '<p style="margin:0 0 12px;font-size:13px;color:#586173;">'
        . 'The code expires in ' . $minutes . ' minutes and can only be used once.</p>'
        . '<p style="margin:0;font-size:13px;color:#586173;">'
        . 'If you did not try to sign in, someone may have your password — please change it as soon as you can.</p>'
        . '</div></div>';

    (new Mailer())->send(
        (string)$user['email'],
        (string)$user['full_name'],
        'Your ' . $appName . ' verification code: ' . $code,
        $html,
        $text
    );
}

/**
 * Emails a single-use password reset link.
 *
 * Throws on delivery failure, like send_login_otp(). The caller reports a
 * generic outcome either way — whether an address is registered is not
 * something an unauthenticated visitor should be able to discover.
 */
function send_password_reset(array $user, string $token): void
{
    $minutes = (int)round(PASSWORD_RESET_TTL_SECONDS / 60);
    $appName = APP_SHORT_NAME;
    $link    = app_url('reset_password.php?token=' . urlencode($token));

    $text = "Hello {$user['full_name']},\r\n\r\n"
        . "We received a request to reset your password. Open the link below to choose a new one:\r\n\r\n"
        . "{$link}\r\n\r\n"
        . "The link expires in {$minutes} minutes and can only be used once.\r\n\r\n"
        . "If you did not request this, you can ignore this message — your password stays as it is.\r\n\r\n"
        . "-- {$appName}";

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#1a1a2e;">'
        . '<div style="background:#0b2e5e;color:#fff;padding:18px 24px;border-radius:10px 10px 0 0;">'
        . '<div style="font-size:16px;font-weight:700;">' . e($appName) . '</div>'
        . '<div style="font-size:12px;opacity:.8;">Password reset</div>'
        . '</div>'
        . '<div style="border:1px solid #e3e3e8;border-top:0;border-radius:0 0 10px 10px;padding:24px;">'
        . '<p style="margin:0 0 16px;">Hello <strong>' . e($user['full_name']) . '</strong>,</p>'
        . '<p style="margin:0 0 18px;">We received a request to reset your password. '
        . 'Choose a new one using the button below.</p>'
        . '<p style="text-align:center;margin:0 0 18px;">'
        . '<a href="' . e($link) . '" style="display:inline-block;background:#0b2e5e;color:#fff;'
        . 'text-decoration:none;font-weight:700;padding:12px 26px;border-radius:8px;">Reset my password</a>'
        . '</p>'
        . '<p style="margin:0 0 12px;font-size:12px;color:#586173;word-break:break-all;">'
        . 'If the button does not work, paste this into your browser:<br>' . e($link) . '</p>'
        . '<p style="margin:0 0 12px;font-size:13px;color:#586173;">'
        . 'The link expires in ' . $minutes . ' minutes and can only be used once.</p>'
        . '<p style="margin:0;font-size:13px;color:#586173;">'
        . 'If you did not request this, you can ignore this message — your password stays as it is.</p>'
        . '</div></div>';

    (new Mailer())->send(
        (string)$user['email'],
        (string)$user['full_name'],
        $appName . ' password reset',
        $html,
        $text
    );
}

/**
 * Absolute URL for a path in this application, for use in emails where a
 * relative link is meaningless. Falls back to the configured mail host name
 * when there is no request context, such as a CLI run.
 */
function app_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? (defined('APP_HOST') ? APP_HOST : 'localhost');

    return $scheme . '://' . $host . BASE_URL . ltrim($path, '/');
}
