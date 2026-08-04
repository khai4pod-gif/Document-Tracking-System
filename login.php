<?php
/**
 * login.php
 * Secure authentication entry point for the DTS-DRDS system.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

// Already authenticated? Skip straight to the dashboard.
if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$oldUsername = '';

// ---------------------------------------------------------------------
// Handle login submission
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $oldUsername = $username;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($username === '' || $password === '') {
        $errors[] = 'Please enter both your username and password.';
    } else {
        $pdo = Database::getConnection();

        // ---- Brute-force throttling: max 5 failed attempts in 10 minutes ----
        $throttleStmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM login_attempts
             WHERE username = :u AND ip_address = :ip
               AND success = 0 AND attempted_at > (NOW() - INTERVAL 10 MINUTE)"
        );
        $throttleStmt->execute(['u' => $username, 'ip' => $ip]);
        $recentFailures = (int)$throttleStmt->fetch()['cnt'];

        if ($recentFailures >= 5) {
            $errors[] = 'Too many failed attempts. Please try again in a few minutes.';
        } else {
            $stmt = $pdo->prepare(
                "SELECT id, username, password_hash, full_name, role, department_id, is_active
                 FROM users WHERE username = :u LIMIT 1"
            );
            $stmt->execute(['u' => $username]);
            $user = $stmt->fetch();

            $logStmt = $pdo->prepare(
                "INSERT INTO login_attempts (username, ip_address, success, attempted_at)
                 VALUES (:u, :ip, :success, NOW())"
            );

            if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
                // Successful login
                $logStmt->execute(['u' => $username, 'ip' => $ip, 'success' => 1]);

                session_regenerate_id(true); // prevent session fixation
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
            } else {
                $logStmt->execute(['u' => $username, 'ip' => $ip, 'success' => 0]);
                $errors[] = 'Invalid credentials. Please check your username and password.';
            }
        }
    }
}

$pageTitle = 'Login';
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
    --paper:#f5f7fa;
  }
  *{font-family:'Inter',system-ui,-apple-system,sans-serif;}
  body{
    min-height:100vh;
    background:linear-gradient(135deg,var(--navy-900) 0%,var(--navy-800) 55%,var(--navy-700) 100%);
    display:flex;align-items:center;justify-content:center;
    padding:24px;
  }
  .login-shell{
    width:100%;max-width:960px;
    background:#fff;border-radius:20px;overflow:hidden;
    box-shadow:0 30px 80px rgba(0,0,0,.35);
    display:grid;grid-template-columns:1.05fr 1fr;
  }
  @media (max-width:860px){ .login-shell{grid-template-columns:1fr;} .brand-panel{display:none;} }

  .brand-panel{
    background:radial-gradient(circle at 30% 20%,var(--navy-700),var(--navy-900) 70%);
    color:#fff;padding:48px 40px;position:relative;overflow:hidden;
    display:flex;flex-direction:column;justify-content:space-between;
  }
  .brand-panel::after{
    content:"";position:absolute;inset:0;
    background:radial-gradient(circle at 85% 85%, rgba(47,128,237,.35), transparent 55%);
  }
  .brand-logo{
    display:flex;align-items:center;gap:12px;font-weight:800;font-size:1.15rem;letter-spacing:.3px;position:relative;z-index:1;
  }
  .brand-logo .mark{
    width:42px;height:42px;border-radius:12px;background:var(--accent);
    display:flex;align-items:center;justify-content:center;font-size:1.3rem;
  }
  .brand-copy{position:relative;z-index:1;margin-top:32px;}
  .brand-copy h2{font-weight:700;font-size:1.6rem;line-height:1.3;margin-bottom:14px;}
  .brand-copy p{color:rgba(255,255,255,.75);font-size:.95rem;line-height:1.6;}
  .illustration{position:relative;z-index:1;margin-top:24px;}
  .brand-footer{position:relative;z-index:1;font-size:.78rem;color:rgba(255,255,255,.55);}

  .form-panel{padding:52px 44px;display:flex;flex-direction:column;justify-content:center;}
  .form-panel h1{font-weight:800;color:var(--navy-900);font-size:1.6rem;margin-bottom:4px;}
  .form-panel .subtitle{color:#6b7686;font-size:.92rem;margin-bottom:28px;}
  .form-label{font-weight:600;font-size:.85rem;color:var(--navy-900);}
  .form-control{
    border-radius:10px;border:1.5px solid #e2e6ec;padding:.65rem .9rem;font-size:.94rem;
  }
  .form-control:focus{border-color:var(--accent);box-shadow:0 0 0 .2rem rgba(11,46,94,.15);}
  .btn-login{
    background:var(--accent);border:none;border-radius:10px;padding:.7rem 1rem;
    font-weight:700;letter-spacing:.2px;transition:.2s;
  }
  .btn-login:hover{background:#071f40;}
  .role-chip{
    display:inline-flex;align-items:center;gap:6px;font-size:.72rem;font-weight:600;
    background:#eef3fb;color:var(--navy-800);padding:4px 10px;border-radius:20px;margin-right:6px;
  }
  .alert{border-radius:10px;font-size:.88rem;}
  .demo-box{
    background:var(--paper);border:1px dashed #d7dee8;border-radius:12px;padding:14px 16px;font-size:.78rem;color:#586173;margin-top:24px;
  }
  .demo-box code{color:var(--navy-800);}

  .logo-row{
    display:flex;align-items:center;gap:14px;margin-bottom:24px;
  }
  .logo-row img{height:44px;width:auto;object-fit:contain;}
  .logo-row .logo-divider{width:1px;height:36px;background:#d7dee8;}
  .brand-panel .logo-row .logo-divider{background:rgba(255,255,255,.25);}
  .brand-panel .logo-row{margin-bottom:0;}
  .brand-app-name{
    position:relative;z-index:1;font-weight:800;font-size:1.05rem;letter-spacing:.3px;
    color:#fff;margin-top:14px;
  }
</style>
</head>
<body>

<div class="login-shell">

  <!-- Brand / Illustration Panel -->
  <div class="brand-panel">
    <div class="logo-row">
      <img src="assets/img/dswd-logo.png" alt="DSWD Logo">
      <span class="logo-divider"></span>
      <img src="assets/img/bagong-pilipinas-logo.png" alt="Bagong Pilipinas Logo">
    </div>
    <div class="brand-app-name"><?= e(APP_SHORT_NAME) ?></div>

    <div class="brand-copy">
      <h2>Coordinated response.<br>Accountable records.</h2>
      <p>A unified platform for routing official documents and managing
      disaster relief distribution — built for transparency, speed, and
      accountability across every office and relief operation.</p>

      <div class="illustration">
        <svg width="100%" height="180" viewBox="0 0 360 180" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="60" cy="140" r="70" fill="white" fill-opacity="0.04"/>
          <circle cx="300" cy="40" r="50" fill="white" fill-opacity="0.05"/>
          <rect x="40" y="60" width="90" height="70" rx="10" fill="#0b2e5e" fill-opacity="0.85"/>
          <rect x="55" y="75" width="60" height="8" rx="4" fill="white" fill-opacity="0.85"/>
          <rect x="55" y="90" width="45" height="8" rx="4" fill="white" fill-opacity="0.6"/>
          <rect x="55" y="105" width="55" height="8" rx="4" fill="white" fill-opacity="0.6"/>
          <path d="M150 110 L185 80 L220 110" stroke="#c8952b" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
          <rect x="230" y="70" width="80" height="60" rx="10" fill="white" fill-opacity="0.9"/>
          <path d="M245 100 h50 M245 112 h30" stroke="#0b2e5e" stroke-width="5" stroke-linecap="round"/>
          <circle cx="285" cy="85" r="6" fill="#c8952b"/>
        </svg>
      </div>
    </div>

    <div class="brand-footer">© <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</div>
  </div>

  <!-- Login Form Panel -->
  <div class="form-panel">
    <div class="logo-row">
      <img src="assets/img/dswd-logo.png" alt="DSWD Logo">
      <span class="logo-divider"></span>
      <img src="assets/img/bagong-pilipinas-logo.png" alt="Bagong Pilipinas Logo">
    </div>
    <h1>Welcome back</h1>
    <p class="subtitle">Sign in to access document tracking and relief operations.</p>

    <div class="mb-3">
      <span class="role-chip">👤 Admin</span>
      <span class="role-chip">🏢 Department</span>
      <span class="role-chip">🚚 Logistics</span>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" role="alert">
        <?php foreach ($errors as $err): ?>
          <div><?= e($err) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['timeout'])): ?>
      <div class="alert alert-warning">Your session expired due to inactivity. Please sign in again.</div>
    <?php endif; ?>

    <form method="POST" action="login.php" novalidate>
      <?= csrf_field() ?>

      <div class="mb-3">
        <label for="username" class="form-label">Username</label>
        <input type="text" class="form-control" id="username" name="username"
               value="<?= e($oldUsername) ?>" placeholder="e.g. admin" required autofocus>
      </div>

      <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <div class="input-group">
          <input type="password" class="form-control" id="password" name="password"
                 placeholder="••••••••" required>
          <button class="btn btn-outline-secondary" type="button" id="togglePwd" tabindex="-1">👁</button>
        </div>
      </div>

      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="remember" name="remember">
        <label class="form-check-label" for="remember" style="font-size:.85rem;color:#586173;">
          Keep me signed in on this device
        </label>
      </div>

      <button type="submit" class="btn btn-login btn-primary w-100 text-white">Sign In</button>
    </form>

    <div class="demo-box">
      <strong>Demo accounts</strong> (password: <code>Passw0rd!123</code>)<br>
      <code>admin</code> · <code>drms_user</code> · <code>logistics1</code>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.getElementById('togglePwd').addEventListener('click', function () {
    const pwd = document.getElementById('password');
    pwd.type = pwd.type === 'password' ? 'text' : 'password';
  });
</script>
</body>
</html>
