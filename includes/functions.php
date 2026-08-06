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

/** Generates a unique, human-readable document tracking number, e.g. DOC-2026-000123 */
function generate_tracking_number(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt FROM documents WHERE tracking_number LIKE :prefix"
    );
    $stmt->execute(['prefix' => "DOC-{$year}-%"]);
    $count = (int)$stmt->fetch()['cnt'] + 1;
    return sprintf('DOC-%s-%06d', $year, $count);
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
