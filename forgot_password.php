<?php
/**
 * forgot_password.php
 * Sends a single-use reset link to a registered address.
 *
 * The outcome message never varies. Whether an address belongs to an account
 * is not something an unauthenticated visitor should be able to discover, so
 * an unknown address, a deactivated account, a throttled request and a
 * successful send all read the same. Delivery problems are logged rather
 * than shown, for the same reason.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

// Already signed in? Nothing to reset.
if (is_logged_in()) {
    redirect('home.php');
}

$submitted = false;
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $identifier = trim((string)($_POST['identifier'] ?? ''));

    if ($identifier === '') {
        $errors[] = 'Enter the username or e-mail address on your account.';
    } else {
        $submitted = true;
        $pdo = Database::getConnection();

        // Matched on either field so a user need not remember which they were
        // given. Only active accounts are eligible.
        $stmt = $pdo->prepare(
            "SELECT * FROM users
              WHERE (username = :u OR email = :e) AND is_active = 1
              LIMIT 1"
        );
        $stmt->execute(['u' => $identifier, 'e' => $identifier]);
        $user = $stmt->fetch();

        if ($user) {
            $reset = new PasswordReset($pdo);

            if ($reset->cooldownRemaining((int)$user['id']) === 0) {
                try {
                    $token = $reset->issue(
                        (int)$user['id'],
                        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
                    );
                    send_password_reset($user, $token);
                } catch (Throwable $e) {
                    // Reported the same as success: a mail failure would
                    // otherwise confirm the address exists.
                    error_log('[PASSWORD RESET ERROR] ' . $e->getMessage());
                }
            }
        }
    }
}

$pageTitle = 'Forgot Password';
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
  :root{ --navy-900:#071f40; --navy-800:#0b2e5e; --navy-700:#12407e; --accent-2:#c8952b; }
  *{font-family:'Inter',system-ui,-apple-system,sans-serif;}
  body{
    min-height:100vh;
    background:linear-gradient(135deg,var(--navy-900) 0%,var(--navy-800) 55%,var(--navy-700) 100%);
    display:flex;align-items:center;justify-content:center;padding:24px;
  }
  .auth-card{
    width:100%;max-width:460px;background:#fff;border-radius:18px;
    padding:40px 38px;box-shadow:0 30px 80px rgba(0,0,0,.35);
  }
  .auth-title{font-weight:800;color:var(--navy-900);font-size:1.4rem;margin-bottom:6px;}
  .auth-sub{color:#586173;font-size:.9rem;margin-bottom:26px;}
  .form-label{font-weight:600;font-size:.85rem;color:var(--navy-900);}
  .btn-auth{background:var(--navy-800);border:0;font-weight:700;padding:12px;border-radius:10px;}
  .btn-auth:hover{background:var(--navy-700);}
  .auth-back{font-size:.85rem;color:var(--navy-700);text-decoration:none;font-weight:600;}
</style>
</head>
<body>

<div class="auth-card">
  <div class="auth-title">Forgot your password?</div>

  <?php if ($submitted && empty($errors)): ?>
    <div class="auth-sub">
      Check your inbox for the next step.
    </div>
    <div class="alert alert-success" role="alert">
      If that username or e-mail belongs to an active account, a reset link is on its way.
      It expires in <?= (int)round(PASSWORD_RESET_TTL_SECONDS / 60) ?> minutes and can only be used once.
    </div>
    <p class="text-muted" style="font-size:.83rem;">
      Nothing arrived? Check the spam folder, or ask an administrator to reset it for you.
    </p>
    <a href="login.php" class="auth-back">&larr; Back to sign in</a>

  <?php else: ?>
    <div class="auth-sub">
      Enter the username or e-mail on your account and we will send a link to choose a new password.
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" role="alert">
        <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3">
        <label for="identifier" class="form-label">Username or e-mail</label>
        <input type="text" class="form-control" id="identifier" name="identifier"
               autocomplete="username" required autofocus
               value="<?= e((string)($_POST['identifier'] ?? '')) ?>">
      </div>
      <button type="submit" class="btn btn-auth btn-primary w-100 text-white">Send reset link</button>
    </form>

    <div class="mt-3">
      <a href="login.php" class="auth-back">&larr; Back to sign in</a>
    </div>
  <?php endif; ?>
</div>

</body>
</html>
