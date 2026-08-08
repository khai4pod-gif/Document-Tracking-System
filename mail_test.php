<?php
/**
 * mail_test.php
 * Administrator tool for checking outgoing mail without going through a
 * login. Sends a test message using the same Mailer the OTP flow uses and
 * reports the raw SMTP error when delivery fails, which is what makes a
 * misconfigured Gmail App Password diagnosable.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_role(['admin']);

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $recipient = trim((string)($_POST['recipient'] ?? ''));

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $result = ['ok' => false, 'message' => 'Please enter a valid email address.'];
    } else {
        $started = microtime(true);
        try {
            (new Mailer())->send(
                $recipient,
                current_user()['full_name'],
                'Test message from ' . APP_SHORT_NAME,
                '<div style="font-family:Arial,Helvetica,sans-serif;">'
                    . '<p>This is a test message from <strong>' . e(APP_SHORT_NAME) . '</strong>.</p>'
                    . '<p>If you are reading this, outgoing mail is configured correctly and '
                    . 'login verification codes will reach their recipients.</p>'
                    . '<p style="color:#586173;font-size:13px;">Sent ' . date('M d, Y g:i:s A') . '</p></div>',
                "This is a test message from " . APP_SHORT_NAME . ".\r\n\r\n"
                    . "If you are reading this, outgoing mail is configured correctly and "
                    . "login verification codes will reach their recipients.\r\n\r\n"
                    . 'Sent ' . date('M d, Y g:i:s A')
            );

            $elapsed = number_format((microtime(true) - $started) * 1000);
            $result = [
                'ok'      => true,
                'message' => 'Message accepted by ' . MAIL_HOST . ' in ' . $elapsed . ' ms. Check the inbox for ' . $recipient . '.',
            ];
        } catch (Throwable $e) {
            error_log('[MAIL TEST ERROR] ' . $e->getMessage());
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

$usingMailpit = MAIL_HOST === '127.0.0.1' || strtolower(MAIL_HOST) === 'localhost';
$hasLocalFile = is_file(__DIR__ . '/config/mail.local.php');

// The session only carries identity fields, so look up the address to
// prefill the form with.
$emailStmt = Database::getConnection()->prepare("SELECT email FROM users WHERE id = :id LIMIT 1");
$emailStmt->execute(['id' => current_user()['id']]);
$myEmail = (string)($emailStmt->fetchColumn() ?: '');

$pageTitle = 'Mail Delivery Test';
$pageIcon  = 'bi-envelope-check';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading">Mail Delivery Test</div>
    <div class="section-sub">Send a test message to confirm login verification codes can actually be delivered.</div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-panel">
      <div class="card-panel-header">Send a test message</div>
      <div class="p-3">

        <?php if ($result !== null): ?>
          <div class="alert <?= $result['ok'] ? 'alert-success' : 'alert-danger' ?>">
            <div class="fw-semibold mb-1">
              <i class="bi <?= $result['ok'] ? 'bi-check-circle' : 'bi-exclamation-triangle' ?> me-1"></i>
              <?= $result['ok'] ? 'Delivered' : 'Delivery failed' ?>
            </div>
            <div style="font-size:.88rem;white-space:pre-wrap;"><?= e($result['message']) ?></div>
          </div>
        <?php endif; ?>

        <?php if ($usingMailpit): ?>
          <div class="alert alert-info" style="font-size:.88rem;">
            <i class="bi bi-info-circle me-1"></i>
            Mail is currently going to <strong>Mailpit</strong>, the local catcher — nothing reaches a real
            inbox. Read captured messages at
            <a href="http://localhost:8025" target="_blank" rel="noopener">localhost:8025</a>.
            To send real email, copy <code>config/mail.local.example.php</code> to
            <code>config/mail.local.php</code> and fill in your SMTP details.
          </div>
        <?php endif; ?>

        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <div class="col-sm-8">
            <label class="form-label">Send to</label>
            <input type="email" name="recipient" class="form-control"
                   value="<?= e($myEmail) ?>"
                   placeholder="you@example.com" required>
          </div>
          <div class="col-sm-4">
            <button type="submit" class="btn btn-primary w-100">
              <i class="bi bi-send me-1"></i> Send Test
            </button>
          </div>
        </form>

      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card-panel">
      <div class="card-panel-header">Current configuration</div>
      <div class="p-3">
        <table class="table table-sm mb-0">
          <tr><th class="text-muted small">Source</th>
              <td><?= $hasLocalFile ? 'config/mail.local.php' : 'defaults in config.php' ?></td></tr>
          <tr><th class="text-muted small">Host</th><td><?= e(MAIL_HOST) ?></td></tr>
          <tr><th class="text-muted small">Port</th><td><?= (int)MAIL_PORT ?></td></tr>
          <tr><th class="text-muted small">Encryption</th>
              <td><?= MAIL_ENCRYPTION === '' ? '<span class="text-muted">none</span>' : e(strtoupper(MAIL_ENCRYPTION)) ?></td></tr>
          <tr><th class="text-muted small">Authentication</th>
              <td><?= MAIL_USERNAME === ''
                    ? '<span class="text-muted">none</span>'
                    : e(MAIL_USERNAME) . ' <span class="badge bg-secondary">password set</span>' ?></td></tr>
          <tr><th class="text-muted small">From</th>
              <td><?= e(MAIL_FROM_ADDRESS) ?></td></tr>
          <tr><th class="text-muted small">Timeout</th><td><?= (int)MAIL_TIMEOUT ?>s</td></tr>
        </table>
        <div class="text-muted mt-2" style="font-size:.78rem;">
          The SMTP password is never displayed on this page.
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
