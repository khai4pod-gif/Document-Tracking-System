/**
 * assets/js/distributions.js
 * Powers distributions.php: DataTable, dynamic relief-item rows,
 * distribution creation, and the read-only detail/status modal.
 */

let distributionModal, viewDistributionModal, distributionsTable;
let INVENTORY_OPTIONS = [];
let itemRowCounter = 0;

const STATUS_BADGE_DIST = {
  Draft: 'bg-secondary', 'Pending Approval': 'bg-warning text-dark',
  Approved: 'bg-info text-dark', Completed: 'bg-success', Cancelled: 'bg-danger',
};

document.addEventListener('DOMContentLoaded', () => {
  // The creation modal is only rendered for relief staff; read-only
  // monitoring accounts (Department) get the view modal alone.
  const distributionModalEl = document.getElementById('distributionModal');
  distributionModal = distributionModalEl ? new bootstrap.Modal(distributionModalEl) : null;
  viewDistributionModal = new bootstrap.Modal(document.getElementById('viewDistributionModal'));

  distributionsTable = $('#distributionsTable').DataTable({
    ajax: { url: 'ajax/distributions_list.php', dataSrc: 'data' },
    order: [[2, 'desc']],
    columns: [
      { data: 'reference_no', render: (d) => `<span class="tracking-chip">${escapeHtml(d)}</span>` },
      { data: null, render: (row) => `${escapeHtml(row.center_name)}<br><span class="text-muted small">${escapeHtml(row.target_area)}</span>` },
      {
        // Display the friendly date, but sort on the raw timestamp — the
        // formatted string orders alphabetically ("Jul" before "Aug").
        data: 'distribution_date',
        render: (d, t, row) => (t === 'sort' || t === 'type' ? row.distribution_date_ts : d),
      },
      { data: 'total_beneficiaries', render: (d) => d.toLocaleString() },
      { data: 'status', render: (d) => `<span class="badge ${STATUS_BADGE_DIST[d] || 'bg-secondary'}">${escapeHtml(d)}</span>` },
      { data: 'tracking_number', render: (d) => d ? `<span class="tracking-chip">${escapeHtml(d)}</span>` : '<span class="text-muted small">Not generated</span>' },
      {
        data: null, orderable: false, className: 'text-end',
        render: (row) => `<button class="btn btn-sm btn-outline-primary action-view-dist" data-id="${row.id}"><i class="bi bi-eye"></i> View</button>`,
      },
    ],
  });

  $('#distributionsTable').on('click', '.action-view-dist', function () {
    openViewModal($(this).data('id'));
  });

  const btnNewDistribution = document.getElementById('btnNewDistribution');
  if (btnNewDistribution) {
    btnNewDistribution.addEventListener('click', () => {
      document.getElementById('distributionForm').reset();
      document.getElementById('itemRows').innerHTML = '';
      itemRowCounter = 0;
      loadCentersDropdown();
      loadInventoryOptions().then(() => addItemRow());
      distributionModal.show();
    });
  }

  const btnAddItemRow = document.getElementById('btnAddItemRow');
  if (btnAddItemRow) {
    btnAddItemRow.addEventListener('click', () => addItemRow());
  }

  const distributionForm = document.getElementById('distributionForm');
  if (distributionForm) {
    distributionForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      const items = [];
      document.querySelectorAll('.item-row').forEach((row) => {
        const invId = row.querySelector('.item-select').value;
        const qty = row.querySelector('.item-qty').value;
        if (invId && qty && parseInt(qty, 10) > 0) {
          items.push({ inventory_id: invId, quantity: qty });
        }
      });
      if (items.length === 0) {
        notify('error', 'Please add at least one relief item with a quantity.');
        return;
      }

      const fd = new FormData(e.target);
      fd.append('items', JSON.stringify(items));

      const res = await apiPost('ajax/distribution_save.php', fd);
      if (res.success) {
        notify('success', res.message);
        distributionModal.hide();
        distributionsTable.ajax.reload(null, false);
      }
    });
  }
});

function loadCentersDropdown() {
  const select = document.getElementById('fieldCenter');
  select.innerHTML = '<option value="">Loading centers…</option>';
  fetch('ajax/centers_list.php')
    .then((r) => r.json())
    .then((res) => {
      const active = (res.data || []).filter((c) => c.is_active === 1);
      select.innerHTML = '<option value="">Select evacuation center…</option>';
      active.forEach((c) => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.name} — ${c.target_area}`;
        select.appendChild(opt);
      });
    })
    .catch(() => { select.innerHTML = '<option value="">Failed to load centers</option>'; });
}

async function loadInventoryOptions() {
  try {
    const res = await fetch('ajax/inventory_list.php').then((r) => r.json());
    INVENTORY_OPTIONS = res.data || [];
  } catch (e) {
    INVENTORY_OPTIONS = [];
  }
}

function addItemRow() {
  itemRowCounter += 1;
  const rowId = `item-row-${itemRowCounter}`;
  const wrapper = document.createElement('div');
  wrapper.className = 'row g-2 align-items-center item-row mb-2';
  wrapper.id = rowId;

  const options = INVENTORY_OPTIONS.map((i) =>
    `<option value="${i.id}" data-available="${i.quantity_available}" data-unit="${escapeHtml(i.unit)}">${escapeHtml(i.item_name)} (${i.quantity_available.toLocaleString()} ${escapeHtml(i.unit)} available)</option>`
  ).join('');

  wrapper.innerHTML = `
    <div class="col-md-7">
      <select class="form-select item-select" required>
        <option value="">Select item…</option>
        ${options}
      </select>
    </div>
    <div class="col-md-3">
      <input type="number" class="form-control item-qty" min="1" placeholder="Quantity" required>
    </div>
    <div class="col-md-2 d-flex">
      <button type="button" class="btn btn-outline-danger btn-remove-row w-100"><i class="bi bi-trash"></i></button>
    </div>`;

  wrapper.querySelector('.btn-remove-row').addEventListener('click', () => wrapper.remove());
  document.getElementById('itemRows').appendChild(wrapper);
}

function openViewModal(id) {
  const body = document.getElementById('viewDistributionBody');
  const footer = document.getElementById('viewDistributionFooter');
  body.innerHTML = '<div class="text-center text-muted py-4">Loading…</div>';
  footer.innerHTML = '';
  viewDistributionModal.show();

  fetch('ajax/distribution_view.php?id=' + id)
    .then((r) => r.json())
    .then((res) => {
      if (!res.success) { body.innerHTML = `<div class="text-danger text-center py-4">${escapeHtml(res.message)}</div>`; return; }
      const d = res.distribution;

      let itemsHtml = '<table class="table table-sm mb-0"><thead><tr><th>Item</th><th>Category</th><th class="text-end">Quantity</th></tr></thead><tbody>';
      (d.items || []).forEach((i) => {
        itemsHtml += `<tr><td>${escapeHtml(i.item_name)}</td><td>${escapeHtml(i.category)}</td><td class="text-end">${parseInt(i.quantity, 10).toLocaleString()} ${escapeHtml(i.unit)}</td></tr>`;
      });
      itemsHtml += '</tbody></table>';

      body.innerHTML = `
        <div class="row g-3 mb-3">
          <div class="col-sm-6"><div class="text-muted small">Reference #</div><div class="fw-semibold">${escapeHtml(d.reference_no)}</div></div>
          <div class="col-sm-6"><div class="text-muted small">Status</div><span class="badge ${STATUS_BADGE_DIST[d.status] || 'bg-secondary'}">${escapeHtml(d.status)}</span></div>
          <div class="col-sm-6"><div class="text-muted small">Evacuation Center</div><div class="fw-semibold">${escapeHtml(d.center_name)} (${escapeHtml(d.target_area)})</div></div>
          <div class="col-sm-6"><div class="text-muted small">Distribution Date</div><div class="fw-semibold">${escapeHtml(d.distribution_date)}</div></div>
          <div class="col-sm-6"><div class="text-muted small">Beneficiaries Served</div><div class="fw-semibold">${parseInt(d.total_beneficiaries, 10).toLocaleString()}</div></div>
          <div class="col-sm-6"><div class="text-muted small">Recorded By</div><div class="fw-semibold">${escapeHtml(d.distributed_by_name)}</div></div>
          ${d.tracking_number ? `<div class="col-12"><div class="text-muted small">Linked DTS Manifest</div><a href="document_view.php?id=${d.document_id}" class="tracking-chip text-decoration-none">${escapeHtml(d.tracking_number)}</a> <span class="badge bg-light text-dark border">${escapeHtml(d.document_status)}</span></div>` : ''}
          ${d.remarks ? `<div class="col-12"><div class="text-muted small">Remarks</div><div>${escapeHtml(d.remarks)}</div></div>` : ''}
        </div>
        <div class="text-muted small mb-1">Relief Items Distributed</div>
        ${itemsHtml}
      `;

      // Read-only monitoring accounts see the details but cannot change status.
      if (!CAN_MANAGE_RELIEF) {
        footer.innerHTML = '<button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>';
        return;
      }

      footer.innerHTML = `
        <select class="form-select form-select-sm w-auto" id="statusSelect">
          ${['Draft', 'Pending Approval', 'Approved', 'Completed', 'Cancelled'].map((s) => `<option value="${s}" ${s === d.status ? 'selected' : ''}>${s}</option>`).join('')}
        </select>
        <button class="btn btn-sm btn-primary" id="btnUpdateStatus" data-id="${d.id}">Update Status</button>`;

      document.getElementById('btnUpdateStatus').addEventListener('click', async () => {
        const status = document.getElementById('statusSelect').value;
        const res2 = await apiPost('ajax/distribution_status.php', { id, status });
        if (res2.success) {
          notify('success', res2.message);
          distributionsTable.ajax.reload(null, false);
          viewDistributionModal.hide();
        }
      });
    })
    .catch(() => { body.innerHTML = '<div class="text-danger text-center py-4">Failed to load details.</div>'; });
}
