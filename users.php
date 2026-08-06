<?php
/**
 * users.php
 * Admin-only: manage user accounts, roles, departments, and passwords.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_role(['admin']);

$pdo = Database::getConnection();
$userManager = new UserManager($pdo);
$departments = $userManager->listDepartments();

$pageTitle = 'User Management';
$pageIcon  = 'bi-people';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading">User Management</div>
    <div class="section-sub">Create and manage accounts for Administrator, Department, and Logistics/Relief users.</div>
  </div>
  <button class="btn btn-primary" id="btnNewUser"><i class="bi bi-person-plus me-1"></i> New User</button>
</div>

<div class="card-panel">
  <div class="card-panel-header">Accounts</div>
  <div class="p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle w-100" id="usersTable">
        <thead>
          <tr>
            <th>Name</th><th>Username</th><th>Email</th><th>Role</th>
            <th>Department</th><th>Routing</th><th>Last Login</th><th>Status</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ===================== Create / Edit User Modal ===================== -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form id="userForm">
        <div class="modal-header">
          <h5 class="modal-title" id="userModalLabel"><i class="bi bi-person-plus me-2"></i>New User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="userId">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" id="fieldFullName" class="form-control" maxlength="150" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" id="fieldUsername" class="form-control" maxlength="50" pattern="[a-zA-Z0-9._-]{3,50}" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" id="fieldEmail" class="form-control" maxlength="150" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Role <span class="text-danger">*</span></label>
              <select name="role" id="fieldRole" class="form-select" required>
                <option value="admin">Administrator</option>
                <option value="department" selected>Department User</option>
                <option value="logistics">Logistics / Relief Officer</option>
                <option value="approver">Approver</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Department / Office</label>
              <select name="department_id" id="fieldDepartment" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($departments as $dept): ?>
                  <option value="<?= (int)$dept['id'] ?>"><?= e($dept['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 d-flex align-items-end" id="canRouteWrapper" style="display:none;">
              <div class="form-check">
                <input type="checkbox" name="can_route" id="fieldCanRoute" class="form-check-input" checked>
                <label class="form-check-label" for="fieldCanRoute">Allow Routing</label>
                <div class="form-text">If unchecked, this Department account can create and view documents but cannot route them to other users/offices.</div>
              </div>
            </div>
            <div class="col-md-6" id="passwordWrapper">
              <label class="form-label">Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" name="password" id="fieldPassword" class="form-control" minlength="8" placeholder="Minimum 8 characters">
                <button class="btn btn-outline-secondary" type="button" id="toggleFieldPassword" tabindex="-1">👁</button>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===================== Reset Password Modal ===================== -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="resetPasswordForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="resetUserId">
          <p class="text-muted small">Set a new password for <strong id="resetUserName"></strong>.</p>
          <label class="form-label">New Password <span class="text-danger">*</span></label>
          <input type="password" name="password" class="form-control" minlength="8" placeholder="Minimum 8 characters" required>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i> Reset Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script>const CURRENT_USER_ID = ' . (int)current_user()['id'] . ';</script>';
$extraScripts .= '<script src="' . e(asset('assets/js/users.js')) . '"></script>';
include __DIR__ . '/includes/footer.php';
?>
