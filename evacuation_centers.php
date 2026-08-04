<?php
/**
 * evacuation_centers.php
 * Manage evacuation centers / target areas used by relief distributions.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_role(['admin', 'logistics', 'approver']);

$pageTitle = 'Evacuation Centers';
$pageIcon  = 'bi-geo-alt';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading">Evacuation Centers</div>
    <div class="section-sub">Manage target areas and evacuation centers for relief operations.</div>
  </div>
  <button class="btn btn-primary" id="btnNewCenter"><i class="bi bi-plus-lg me-1"></i> Add Center</button>
</div>

<div class="card-panel">
  <div class="card-panel-header">Center Records</div>
  <div class="p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle w-100" id="centersTable">
        <thead>
          <tr><th>Name</th><th>Target Area</th><th>Capacity</th><th>Contact</th><th>Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="centerModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form id="centerForm">
        <div class="modal-header">
          <h5 class="modal-title" id="centerModalLabel"><i class="bi bi-geo-alt me-2"></i>Add Evacuation Center</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="centerId">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Center Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="fieldCenterName" class="form-control" maxlength="150" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Target Area <span class="text-danger">*</span></label>
              <input type="text" name="target_area" id="fieldTargetArea" class="form-control" maxlength="150" required>
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <input type="text" name="address" id="fieldAddress" class="form-control" maxlength="255">
            </div>
            <div class="col-md-4">
              <label class="form-label">Capacity</label>
              <input type="number" name="capacity" id="fieldCapacity" class="form-control" min="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">Contact Person</label>
              <input type="text" name="contact_person" id="fieldContactPerson" class="form-control" maxlength="150">
            </div>
            <div class="col-md-4">
              <label class="form-label">Contact Number</label>
              <input type="text" name="contact_number" id="fieldContactNumber" class="form-control" maxlength="30">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Center</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script src="assets/js/centers.js"></script>';
include __DIR__ . '/includes/footer.php';
?>
