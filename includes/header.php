<?php
/**
 * includes/header.php
 * Expects: $pageTitle (string), optionally $pageIcon (bootstrap-icons class).
 * Must be included AFTER require_login() has already run on the page.
 */

declare(strict_types=1);

$__user = current_user();
$__flash = flash_get();

// Unread notification count for the bell icon.
$__pdo = Database::getConnection();
$__notifStmt = $__pdo->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = :u AND is_read = 0");
$__notifStmt->execute(['u' => $__user['id']]);
$__unreadCount = (int)$__notifStmt->fetch()['cnt'];

$__notifListStmt = $__pdo->prepare(
    "SELECT id, message, link, is_read, created_at FROM notifications
     WHERE user_id = :u ORDER BY created_at DESC LIMIT 6"
);
$__notifListStmt->execute(['u' => $__user['id']]);
$__notifications = $__notifListStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle ?? 'Dashboard') ?> · <?= e(APP_SHORT_NAME) ?></title>

<!-- Installable on a phone: "Add to Home Screen" opens it full-screen without
     browser chrome, which matters on a small display. theme-color tints the
     status bar so it does not read as a stray browser tab. -->
<link rel="manifest" href="<?= e(BASE_URL) ?>manifest.json">
<meta name="theme-color" content="#0b2e5e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="DTS">
<link rel="apple-touch-icon" href="<?= e(asset('assets/img/dswd-logo.png')) ?>">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?= e(asset('assets/css/style.css')) ?>" rel="stylesheet">
<style>
  .topbar-logo {
    height: 26px;
    width: auto;
    object-fit: contain;
    margin-right: 0.5rem;
    vertical-align: middle;
  }
</style>
</head>
<body data-flash-type="<?= e($__flash['type'] ?? '') ?>" data-flash-message="<?= e($__flash['message'] ?? '') ?>">

<div class="app-shell">

  <!-- Sidebar -->
  <?php include __DIR__ . '/sidebar.php'; ?>

  <!-- Main column -->
  <div class="app-main">

    <!-- Topbar -->
    <nav class="topbar">
      <button class="btn-icon d-lg-none" id="sidebarToggleMobile" type="button" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
      </button>
      <button class="btn-icon d-none d-lg-inline-flex" id="sidebarToggleDesktop" type="button" aria-label="Collapse menu">
        <i class="bi bi-layout-sidebar-inset"></i>
      </button>

      <div class="topbar-title d-none d-md-flex align-items-center">
        <img src="assets/img/dswd-logo.png" alt="DSWD Logo" class="topbar-logo">
        <?= e($pageTitle ?? '') ?>
      </div>

      <div class="topbar-actions ms-auto">
        <!-- Notifications -->
        <div class="dropdown" id="notifDropdown">
          <button class="btn-icon position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-bell"></i>
            <?php if ($__unreadCount > 0): ?>
              <span class="notif-dot" id="notifBadge"><?= $__unreadCount > 9 ? '9+' : $__unreadCount ?></span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end notif-menu p-0">
            <div class="notif-header">Notifications</div>
            <?php if (empty($__notifications)): ?>
              <div class="notif-empty">You're all caught up.</div>
            <?php else: ?>
              <?php foreach ($__notifications as $n): ?>
                <a href="<?= e($n['link'] ?? '#') ?>" class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
                  <div class="notif-msg"><?= e($n['message']) ?></div>
                  <div class="notif-time"><?= date('M d, g:i A', strtotime($n['created_at'])) ?></div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- User menu -->
        <div class="dropdown">
          <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar"><?= e(strtoupper(substr($__user['full_name'], 0, 1))) ?></span>
            <span class="d-none d-md-flex flex-column align-items-start lh-sm">
              <strong style="font-size:.85rem;"><?= e($__user['full_name']) ?></strong>
              <small class="text-muted" style="font-size:.72rem;"><?= e(role_label($__user['role'])) ?></small>
            </span>
            <i class="bi bi-chevron-down small"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text text-muted small">Signed in as <strong><?= e($__user['username']) ?></strong></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
          </ul>
        </div>
      </div>
    </nav>

    <!-- Page content -->
    <main class="app-content">
