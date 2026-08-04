<?php
/**
 * inventory.php
 * Relief inventory (stock) management: CRUD via DataTable + modal.
 */

declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_role(['admin', 'logistics', 'approver']);

$pageTitle = 'Relief Inventory';
$pageIcon  = 'bi-boxes';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-end justify-content-between mb-4 gap-2">
  <div>
    <div class="section-heading">Relief Inventory</div>
    <div class="section-sub">Track stock levels for food, medical, shelter, and hygiene relief packs.</div>
  </div>
  <button class="btn btn-primary" id="btnNewItem"><i class="bi bi-plus-lg me-1"></i> Add Item</button>
</div>

<div class="card-panel">
  <div class="card-panel-header">Inventory Records</div>
  <div class="p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle w-100" id="inventoryTable">
        <thead>
          <tr>
            <th>Item</th><th>Category</th><th>Available</th><th>Distributed</th><th>Reorder Level</th><th>Status</th><th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="itemModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="itemForm">
        <div class="modal-header">
          <h5 class="modal-title" id="itemModalLabel"><i class="bi bi-box-seam me-2"></i>Add Inventory Item</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="itemId">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Item Name <span class="text-danger">*</span></label>
              <input type="text" name="item_name" id="fieldItemName" class="form-control" maxlength="150" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Category</label>
              <select name="category" id="fieldCategory" class="form-select">
                <option>Food</option><option>Medical</option><option>Shelter</option>
                <option>Water</option><option>Hygiene</option><option>Other</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Unit</label>
              <input type="text" name="unit" id="fieldUnit" class="form-control" placeholder="e.g. pack, kit, unit" maxlength="30" value="pack" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Quantity Available</label>
              <input type="number" name="quantity_available" id="fieldQtyAvail" class="form-control" min="0" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Reorder Level</label>
              <input type="number" name="reorder_level" id="fieldReorder" class="form-control" min="0" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraScripts = '<script src="assets/js/inventory.js"></script>';
include __DIR__ . '/includes/footer.php';
?>
