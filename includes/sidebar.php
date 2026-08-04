<?php
/**
 * includes/sidebar.php
 * Role-aware sidebar navigation. Included by header.php.
 */
$__current = basename($_SERVER['PHP_SELF']);
$__role = current_user()['role'] ?? 'department';

function nav_active(string $file, string $current): string
{
    return $file === $current ? 'active' : '';
}
?>
<style>
  /* --- DSWD Theme ------------------------------------------------- */
  :root {
    --dswd-navy: #0b2e5e;
    --dswd-navy-dark: #071f40;
    --dswd-navy-light: #12407e;
    --dswd-gold: #c8952b;
    --dswd-gold-light: #f6e8cf;

    /* Feed the agency palette into the color variables the rest of
       the app (dashboard KPI cards, badges, buttons) already reads. */
    --accent: var(--dswd-navy);
    --accent-2: var(--dswd-gold);
  }

  .sidebar {
    background: var(--dswd-navy-dark);
  }

  .sidebar-brand {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding-bottom: 0.75rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.12);
  }

  .sidebar-brand .sidebar-logo {
    height: 32px;
    width: 32px;
    object-fit: contain;
    border-radius: 50%;
    background: #ffffff;
    padding: 2px;
    flex-shrink: 0;
  }

  .sidebar-nav .nav-link {
    border-left: 3px solid transparent;
    color: rgba(255, 255, 255, 0.75);
  }

  .sidebar-nav .nav-link:hover {
    background: rgba(255, 255, 255, 0.06);
    color: #ffffff;
  }

  .sidebar-nav .nav-link.active {
    background: var(--dswd-navy-light);
    border-left-color: var(--dswd-gold);
    color: #ffffff;
    font-weight: 600;
  }

  .nav-section-label {
    color: var(--dswd-gold-light);
    opacity: 0.7;
    letter-spacing: 0.06em;
  }

  .sidebar-footer-card {
    background: rgba(255, 255, 255, 0.06);
    color: rgba(255, 255, 255, 0.7);
  }
</style>
<aside class="sidebar" id="appSidebar">
  <div class="sidebar-brand">
    <img src="assets/img/dswd-logo.png" alt="DSWD Logo" class="sidebar-logo">
    <span class="brand-text"><?= e(APP_SHORT_NAME) ?></span>
  </div>

  <nav class="sidebar-nav">
    <a href="home.php" class="nav-link <?= nav_active('home.php', $__current) ?>">
      <i class="bi bi-house-door"></i><span>Home</span>
    </a>

    <div class="nav-section-label">Document Tracking</div>
    <a href="dashboard.php" class="nav-link <?= nav_active('dashboard.php', $__current) ?>">
      <i class="bi bi-speedometer2"></i><span>Dashboard</span>
    </a>
    <a href="documents.php" class="nav-link <?= nav_active('documents.php', $__current) ?>">
      <i class="bi bi-file-earmark-text"></i><span>All Documents</span>
    </a>

    <div class="nav-section-label">Disaster Relief</div>
    <a href="relief_dashboard.php" class="nav-link <?= nav_active('relief_dashboard.php', $__current) ?>">
      <i class="bi bi-bar-chart-line"></i><span>Relief Dashboard</span>
    </a>
    <?php if (in_array($__role, ['admin', 'logistics'], true)): ?>
    <a href="distributions.php" class="nav-link <?= nav_active('distributions.php', $__current) ?>">
      <i class="bi bi-truck"></i><span>Distributions</span>
    </a>
    <a href="inventory.php" class="nav-link <?= nav_active('inventory.php', $__current) ?>">
      <i class="bi bi-boxes"></i><span>Relief Inventory</span>
    </a>
    <a href="evacuation_centers.php" class="nav-link <?= nav_active('evacuation_centers.php', $__current) ?>">
      <i class="bi bi-geo-alt"></i><span>Evacuation Centers</span>
    </a>
    <?php endif; ?>

    <?php if ($__role === 'admin'): ?>
    <div class="nav-section-label">Administration</div>
    <a href="users.php" class="nav-link <?= nav_active('users.php', $__current) ?>">
      <i class="bi bi-people"></i><span>User Management</span>
    </a>
    <a href="departments.php" class="nav-link <?= nav_active('departments.php', $__current) ?>">
      <i class="bi bi-building"></i><span>Departments</span>
    </a>
    <a href="documents.php?archived=1" class="nav-link">
      <i class="bi bi-archive"></i><span>Archived Documents</span>
    </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-footer-card">
      <i class="bi bi-info-circle"></i>
      <span>v1.0 · Secure Session Active</span>
    </div>
  </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
