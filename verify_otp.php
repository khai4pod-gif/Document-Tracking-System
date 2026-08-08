<?php
/**
 * verify_otp.php
 * Second factor of the login flow: confirms the one-time passcode that
 * login.php emailed. Reached only with a half-authenticated session
 * ($_SESSION['pending_2fa']); the account is not signed in until the
 * code checks out here.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

// Already fully signed in — nothing to verify.
if (is_logged_in()) {
    redirect('home.php');
}

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending || empty($pending['user_id'])) {
    redirect('login.php');
}

// The half-authenticated state is short-lived.
if ((time() - (int)$pending['started_at']) > OTP_PENDING_TTL) {
    unset($_SESSION['pending_2fa']);
    flash_set('error', 'Your sign-in session expired. Please log in again.');
    redirect('login.php');
}

$pdo = Database::getConnection();
$otp = new LoginOtp($pdo);
$userId = (int)$pending['user_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$stmt = $pdo->prepare(
    "SELECT id, username, email, full_name, role, department_id, is_active
     FROM users WHERE id = :id LIMIT 1"
);
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

// Account vanished or was deactivated mid-flow.
if (!$user || (int)$user['is_active'] !== 1) {
    unset($_SESSION['pending_2fa']);
    $otp->clearFor($userId);
    flash_set('error', 'This account is no longer active. Please contact your administrator.');
    redirect('login.php');
}

$errors  = [];
$notices = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();
    $action = (string)($_POST['action'] ?? 'verify');

    if ($action === 'resend') {
        $wait = $otp->resendCooldownRemaining($userId);
        if ($wait > 0) {
            $errors[] = 'Please wait ' . $wait . ' more second' . ($wait === 1 ? '' : 's') . ' before requesting another code.';
        } else {
            try {
                send_login_otp($user, $otp->issue($userId, $ip));
                $notices[] = 'A new verification code is on its way to your email.';
            } catch (Throwable $mailError) {
                error_log('[LOGIN OTP RESEND ERROR] ' . $mailError->getMessage());
                $otp->clearFor($userId);
                $errors[] = 'We could not send a new code right now. Please try again in a moment.';
            }
        }
    } else {
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));

        if ($code === '' || strlen($code) !== OTP_LENGTH) {
            $errors[] = 'Please enter the ' . OTP_LENGTH . '-digit code from your email.';
        } else {
            $result = $otp->verify($userId, $code);

            if ($result['ok']) {
                // Second factor cleared — promote to a real session.
                session_regenerate_id(true);
                unset($_SESSION['pending_2fa']);

                $_SESSION['user'] = [
                    'id'            => (int)$user['id'],
                    'username'      => $user['username'],
                    'full_name'     => $user['full_name'],
                    'role'          => $user['role'],
                    'department_id' => $user['department_id'] !== null ? (int)$user['department_id'] : null,
                ];
                $_SESSION['last_activity'] = time();

                $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
                    ->execute(['id' => $user['id']]);

                flash_set('success', 'Welcome back, ' . $user['full_name'] . '!');
                redirect('home.php');
            }

            $errors[] = $result['message'];
        }
    }
}

/** Masks the delivery address so the page never reveals a full email. */
function mask_email(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false) {
        return 'your registered email';
    }
    $name   = substr($email, 0, $at);
    $domain = substr($email, $at);
    $keep   = $name === '' ? '' : $name[0];
    $tail   = strlen($name) > 2 ? substr($name, -1) : '';

    return $keep . str_repeat('•', max(strlen($name) - 2, 2)) . $tail . $domain;
}

$pageTitle = 'Verify Sign-In';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e(APP_SHORT_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --navy-900:#071f40;
    --navy-800:#0b2e5e;
    --navy-700:#12407e;
    --accent:#0b2e5e;
    --accent-2:#c8952b;
  }
  *{font-family:'Inter',system-ui,-apple-system,sans-serif;}
  body{
    min-height:100vh;
    background:linear-gradient(135deg,var(--navy-900) 0%,var(--navy-800) 55%,var(--navy-700) 100%);
    display:flex;align-items:center;justify-content:center;
    padding:24px;
  }
  .otp-shell{
    width:100%;max-width:440px;background:#fff;border-radius:20px;
    box-shadow:0 30px 80px rgba(0,0,0,.35);padding:40px 36px;
  }
  .logo-row{display:flex;align-items:center;gap:14px;margin-bottom:24px;}
  .logo-row img{height:44px;width:auto;object-fit:contain;}
  .logo-row .logo-divider{width:1px;height:36px;background:#d7dee8;}
  h1{font-weight:800;color:var(--navy-900);font-size:1.45rem;margin-bottom:6px;}
  .subtitle{color:#6b7686;font-size:.92rem;margin-bottom:26px;line-height:1.55;}
  .otp-input{
    border-radius:12px;border:1.5px solid #e2e6ec;padding:.85rem 1rem;
    font-size:1.6rem;font-weight:700;letter-spacing:.5rem;text-align:center;
  }
  .otp-input:focus{border-color:var(--accent);box-shadow:0 0 0 .2rem rgba(11,46,94,.15);}
  .btn-verify{
    background:var(--accent);border:none;border-radius:10px;padding:.7rem 1rem;
    font-weight:700;color:#fff;transition:.2s;
  }
  .btn-verify:hover{background:#071f40;color:#fff;}
  .btn-link-plain{
    background:none;border:none;padding:0;color:var(--navy-800);
    font-weight:600;font-size:.86rem;text-decoration:underline;
  }
  .btn-link-plain:disabled{color:#9aa3b0;text-decoration:none;cursor:not-allowed;}
  .alert{border-radius:10px;font-size:.88rem;}
  .meta{font-size:.8rem;color:#8a92a0;}
</style>
</head>
<body>

<div class="otp-shell">
  <div class="logo-row">
    <img src="assets/img/dswd-logo.png" alt="DSWD Logo">
    <span class="logo-divider"></span>
    <img src="assets/img/bagong-pilipinas-logo.png" alt="Bagong Pilipinas Logo">
  </div>

  <h1>Check your email</h1>
  <div class="subtitle">
    We sent a <?= (int)OTP_LENGTH ?>-digit verification code to
    <strong><?= e(mask_email((string)$user['email'])) ?></strong>.
    Enter it below to finish signing in as <strong><?= e($user['username']) ?></strong>.
  </div>

  <?php foreach ($errors as $error): ?>
    <div class="alert alert-danger py-2"><?= e($error) ?></div>
  <?php endforeach; ?>
  <?php foreach ($notices as $notice): ?>
    <div class="alert alert-success py-2"><?= e($notice) ?></div>
  <?php endforeach; ?>

  <form method="post" autocomplete="off" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="verify">
    <div class="mb-3">
      <input type="text" name="code" id="code" class="form-control otp-input"
             inputmode="numeric" pattern="[0-9]*" maxlength="<?= (int)OTP_LENGTH ?>"
             placeholder="<?= str_repeat('•', (int)OTP_LENGTH) ?>"
             autocomplete="one-time-code" autofocus required>
    </div>
    <button type="submit" class="btn btn-verify w-100 mb-3">Verify &amp; Continue</button>
  </form>

  <div class="d-flex justify-content-between align-items-center">
    <form method="post" class="m-0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="resend">
      <?php $cooldown = $otp->resendCooldownRemaining($userId); ?>
      <button type="submit" class="btn-link-plain" <?= $cooldown > 0 ? 'disabled' : '' ?>>
        <?= $cooldown > 0 ? 'Resend in ' . $cooldown . 's' : 'Resend code' ?>
      </button>
    </form>
    <a href="login.php" class="meta text-decoration-none">Cancel</a>
  </div>

  <div class="meta mt-3">
    The code expires in <?= (int)round(OTP_TTL_SECONDS / 60) ?> minutes.
  </div>
</div>

<script>
  // Digits only, and submit automatically once the code is complete.
  (function () {
    const input = document.getElementById('code');
    const max = <?= (int)OTP_LENGTH ?>;
    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D/g, '').slice(0, max);
      if (input.value.length === max) input.form.submit();
    });
  })();

  // Keep the resend countdown ticking without a page reload.
  (function () {
    const btn = document.querySelector('button.btn-link-plain[disabled]');
    if (!btn) return;
    let left = parseInt((btn.textContent.match(/\d+/) || [0])[0], 10);
    const tick = setInterval(() => {
      left -= 1;
      if (left <= 0) {
        clearInterval(tick);
        btn.disabled = false;
        btn.textContent = 'Resend code';
      } else {
        btn.textContent = 'Resend in ' + left + 's';
      }
    }, 1000);
  })();
</script>

</body>
</html>
