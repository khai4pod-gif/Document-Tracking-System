<?php
/**
 * distributions.php
 * Record and track relief distribution events, with the option to
 * generate an official Relief Manifest document routed through the DTS.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_role(['admin', 'logistics', 'approver']);

$pageTitle = 'Relief Distributions';
$pageIcon  = 'bi-truck';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading">Relief Distributions</div>
    <div class="section-sub">Record relief goods distributed to evacuation centers and generate manifests for approval.</div>
  </div>
  <button class="btn btn-primary" id="btnNewDistribution"><i class="bi bi-plus-lg me-1"></i> New Distribution</button>
</div>

<div class="card-panel">
  <div class="card-panel-header">Distribution Records</div>
  <div class="p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle w-100" id="distributionsTable">
        <thead>
          <tr>
            <th>Reference #</th><th>Center / Area</th><th>Date</th><th>Beneficiaries</th>
            <th>Status</th><th>Manifest</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- ===================== New Distribution Modal ===================== -->
<div class="modal fade" id="distributionModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <form id="distributionForm">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-truck me-2"></i>New Relief Distribution</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-5">
              <label class="form-label">Evacuation Center <span class="text-danger">*</span></label>
              <select name="evacuation_center_id" id="fieldCenter" class="form-select" required>
                <option value="">Loading centers…</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Distribution Date <span class="text-danger">*</span></label>
              <input type="date" name="distribution_date" id="fieldDistDate" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Total Beneficiaries <span class="text-danger">*</span></label>
              <input type="number" name="total_beneficiaries" id="fieldBeneficiaries" class="form-control" min="0" required>
            </div>
          </div>

          <label class="form-label">Relief Items <span class="text-danger">*</span></label>
          <div id="itemRows" class="mb-2"></div>
          <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="btnAddItemRow">
            <i class="bi bi-plus-lg me-1"></i> Add Item
          </button>

          <div class="mb-3">
            <label class="form-label">Remarks</label>
            <textarea name="remarks" class="form-control" rows="2" placeholder="Optional notes about this distribution"></textarea>
          </div>

          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="fieldGenerateManifest" name="generate_manifest" value="1" checked>
            <label class="form-check-label" for="fieldGenerateManifest">
              Generate &amp; attach an official Relief Distribution Manifest to the DTS for routing/approval
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Record Distribution</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===================== View Distribution Modal ===================== -->
<div class="modal fade" id="viewDistributionModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clipboard-data me-2"></i>Distribution Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewDistributionBody">
        <div class="text-center text-muted py-4">Loading…</div>
      </div>
      <div class="modal-footer" id="viewDistributionFooter"></div>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script src="assets/js/distributions.js"></script>';
include __DIR__ . '/includes/footer.php';
?>
