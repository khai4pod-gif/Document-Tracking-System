<?php
/**
 * reset_password.php
 * Redeems a reset link and sets a new password.
 *
 * The token is resolved on load so an expired or spent link shows the reason
 * instead of a form that cannot work, and again on submit so a link consumed
 * between the two cannot be redeemed twice.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    redirect('home.php');
}

$pdo   = Database::getConnection();
$reset = new PasswordReset($pdo);

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$user  = $reset->resolve($token);

$errors = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    csrf_protect();

    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if (mb_strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Your new password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if (empty($errors)) {
        try {
            // Returns false if the link was spent in the meantime; treated as
            // an expired link rather than a system fault.
            $done = $reset->redeem($token, $password);
            if (!$done) {
                $user = null;
            }
        } catch (Throwable $e) {
            error_log('[PASSWORD RESET REDEEM ERROR] ' . $e->getMessage());
            $errors[] = 'We could not update your password just now. Please try the link again.';
        }
    }
}

$pageTitle = 'Reset Password';
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
  :root{ --navy-900:#071f40; --navy-800:#0b2e5e; --navy-700:#12407e; }
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

  <?php if ($done): ?>
    <div class="auth-title">Password updated</div>
    <div class="auth-sub">You can now sign in with your new password.</div>
    <div class="alert alert-success" role="alert">
      Your password has been changed and the reset link has been used up.
    </div>
    <a href="login.php" class="btn btn-auth btn-primary w-100 text-white">Go to sign in</a>

  <?php elseif (!$user): ?>
    <div class="auth-title">This link is no longer valid</div>
    <div class="auth-sub">Reset links expire quickly and can only be used once.</div>
    <div class="alert alert-warning" role="alert">
      It may have expired, been used already, or been replaced by a newer request.
      Request a fresh one to continue.
    </div>
    <a href="forgot_password.php" class="btn btn-auth btn-primary w-100 text-white">Request a new link</a>
    <div class="mt-3"><a href="login.php" class="auth-back">&larr; Back to sign in</a></div>

  <?php else: ?>
    <div class="auth-title">Choose a new password</div>
    <div class="auth-sub">
      Signed in as <strong><?= e($user['full_name']) ?></strong>
      (<?= e($user['username']) ?>). Pick something you have not used here before.
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" role="alert">
        <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="reset_password.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">

      <div class="mb-3">
        <label for="password" class="form-label">New password</label>
        <div class="input-group">
          <input type="password" class="form-control" id="password" name="password"
                 autocomplete="new-password" required minlength="<?= (int)PASSWORD_MIN_LENGTH ?>">
          <button class="btn btn-outline-secondary" type="button" id="togglePwd" tabindex="-1">👁</button>
        </div>
        <div class="form-text">At least <?= (int)PASSWORD_MIN_LENGTH ?> characters.</div>
      </div>

      <div class="mb-3">
        <label for="password_confirm" class="form-label">Confirm new password</label>
        <input type="password" class="form-control" id="password_confirm" name="password_confirm"
               autocomplete="new-password" required minlength="<?= (int)PASSWORD_MIN_LENGTH ?>">
      </div>

      <button type="submit" class="btn btn-auth btn-primary w-100 text-white">Update password</button>
    </form>

    <div class="mt-3"><a href="login.php" class="auth-back">&larr; Back to sign in</a></div>
  <?php endif; ?>

</div>

<script>
  const toggle = document.getElementById('togglePwd');
  if (toggle) {
    toggle.addEventListener('click', function () {
      const pwd = document.getElementById('password');
      pwd.type = pwd.type === 'password' ? 'text' : 'password';
    });
  }
</script>
</body>
</html>
