/**
 * assets/js/inventory.js
 * Powers inventory.php: DataTable + Add/Edit/Delete modal actions.
 */

let itemModal, inventoryTable, INVENTORY_CACHE = [];

document.addEventListener('DOMContentLoaded', () => {
  itemModal = new bootstrap.Modal(document.getElementById('itemModal'));

  inventoryTable = $('#inventoryTable').DataTable({
    ajax: {
      url: 'ajax/inventory_list.php',
      dataSrc: (json) => { INVENTORY_CACHE = json.data; return json.data; },
    },
    order: [[0, 'asc']],
    columns: [
      { data: 'item_name', render: (d) => escapeHtml(d) },
      { data: 'category', render: (d) => escapeHtml(d) },
      { data: 'quantity_available', render: (d, t, row) => t === 'display' ? `${d.toLocaleString()} ${escapeHtml(row.unit)}` : d },
      { data: 'quantity_distributed', render: (d, t, row) => t === 'display' ? `${d.toLocaleString()} ${escapeHtml(row.unit)}` : d },
      { data: 'reorder_level', render: (d) => d.toLocaleString() },
      { data: 'low_stock', render: (d, t, row) => t === 'display'
          ? (row.quantity_available === 0 ? '<span class="badge bg-danger">Out of Stock</span>' : (d ? '<span class="badge bg-warning text-dark">Low Stock</span>' : '<span class="badge bg-success">Healthy</span>'))
          : d },
      {
        data: null, orderable: false, className: 'text-end',
        render: (row) => `
          <button class="btn btn-sm btn-outline-secondary action-edit-item" data-id="${row.id}"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-sm btn-outline-danger action-delete-item" data-id="${row.id}"><i class="bi bi-trash"></i></button>`,
      },
    ],
  });

  document.getElementById('btnNewItem').addEventListener('click', () => {
    document.getElementById('itemForm').reset();
    document.getElementById('itemId').value = '';
    document.getElementById('itemModalLabel').innerHTML = '<i class="bi bi-box-seam me-2"></i>Add Inventory Item';
    itemModal.show();
  });

  $('#inventoryTable').on('click', '.action-edit-item', function () {
    const id = parseInt($(this).data('id'), 10);
    const item = INVENTORY_CACHE.find((i) => i.id === id);
    if (!item) return;
    document.getElementById('itemId').value = item.id;
    document.getElementById('fieldItemName').value = item.item_name;
    document.getElementById('fieldCategory').value = item.category;
    document.getElementById('fieldUnit').value = item.unit;
    document.getElementById('fieldQtyAvail').value = item.quantity_available;
    document.getElementById('fieldReorder').value = item.reorder_level;
    document.getElementById('itemModalLabel').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Inventory Item';
    itemModal.show();
  });

  $('#inventoryTable').on('click', '.action-delete-item', async function () {
    const id = $(this).data('id');
    const confirmed = await confirmAction('Delete this inventory item?', 'This cannot be undone unless it has distribution history.', 'Yes, delete');
    if (!confirmed) return;
    const res = await apiPost('ajax/inventory_delete.php', { id });
    if (res.success) { notify('success', res.message); inventoryTable.ajax.reload(null, false); }
  });

  document.getElementById('itemForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await apiPost('ajax/inventory_save.php', fd);
    if (res.success) {
      notify('success', res.message);
      itemModal.hide();
      inventoryTable.ajax.reload(null, false);
    }
  });
});
