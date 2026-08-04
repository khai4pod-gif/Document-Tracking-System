<?php
/**
 * departments.php
 * Admin-only: manage departments/offices referenced by users and documents.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_role(['admin']);

$pageTitle = 'Departments';
$pageIcon  = 'bi-building';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading">Departments &amp; Offices</div>
    <div class="section-sub">Manage the offices used for user assignments and document routing.</div>
  </div>
  <button class="btn btn-primary" id="btnNewDept"><i class="bi bi-plus-lg me-1"></i> Add Department</button>
</div>

<div class="card-panel">
  <div class="card-panel-header">Department Records</div>
  <div class="p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle w-100" id="deptTable">
        <thead>
          <tr><th>Name</th><th>Code</th><th>Description</th><th>Users</th><th>Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="deptModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="deptForm">
        <div class="modal-header">
          <h5 class="modal-title" id="deptModalLabel"><i class="bi bi-building me-2"></i>Add Department</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="deptId">
          <div class="mb-3">
            <label class="form-label">Department Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="fieldDeptName" class="form-control" maxlength="150" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Code <span class="text-danger">*</span></label>
            <input type="text" name="code" id="fieldDeptCode" class="form-control text-uppercase" maxlength="20" placeholder="e.g. RECORDS" required>
          </div>
          <div class="mb-1">
            <label class="form-label">Description</label>
            <textarea name="description" id="fieldDeptDescription" class="form-control" rows="2" maxlength="255"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Department</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script src="assets/js/departments.js"></script>';
include __DIR__ . '/includes/footer.php';
?>
