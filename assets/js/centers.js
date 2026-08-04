/**
 * assets/js/centers.js
 * Powers evacuation_centers.php: DataTable + Add/Edit/Toggle actions.
 */

let centerModal, centersTable, CENTERS_CACHE = [];

document.addEventListener('DOMContentLoaded', () => {
  centerModal = new bootstrap.Modal(document.getElementById('centerModal'));

  centersTable = $('#centersTable').DataTable({
    ajax: {
      url: 'ajax/centers_list.php',
      dataSrc: (json) => { CENTERS_CACHE = json.data; return json.data; },
    },
    order: [[0, 'asc']],
    columns: [
      { data: 'name', render: (d) => escapeHtml(d) },
      { data: 'target_area', render: (d) => escapeHtml(d) },
      { data: 'capacity', render: (d) => d !== null ? d.toLocaleString() : '—' },
      { data: null, render: (row) => {
          const person = row.contact_person ? escapeHtml(row.contact_person) : '—';
          const number = row.contact_number ? escapeHtml(row.contact_number) : '';
          return `${person}${number ? '<br><span class="text-muted small">' + number + '</span>' : ''}`;
        } },
      { data: 'is_active', render: (d) => d ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' },
      {
        data: null, orderable: false, className: 'text-end',
        render: (row) => `
          <button class="btn btn-sm btn-outline-secondary action-edit-center" data-id="${row.id}"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-sm btn-outline-${row.is_active ? 'warning' : 'success'} action-toggle-center" data-id="${row.id}">
            <i class="bi bi-power"></i></button>`,
      },
    ],
  });

  document.getElementById('btnNewCenter').addEventListener('click', () => {
    document.getElementById('centerForm').reset();
    document.getElementById('centerId').value = '';
    document.getElementById('centerModalLabel').innerHTML = '<i class="bi bi-geo-alt me-2"></i>Add Evacuation Center';
    centerModal.show();
  });

  $('#centersTable').on('click', '.action-edit-center', function () {
    const id = parseInt($(this).data('id'), 10);
    const c = CENTERS_CACHE.find((i) => i.id === id);
    if (!c) return;
    document.getElementById('centerId').value = c.id;
    document.getElementById('fieldCenterName').value = c.name;
    document.getElementById('fieldTargetArea').value = c.target_area;
    document.getElementById('fieldAddress').value = c.address || '';
    document.getElementById('fieldCapacity').value = c.capacity ?? '';
    document.getElementById('fieldContactPerson').value = c.contact_person || '';
    document.getElementById('fieldContactNumber').value = c.contact_number || '';
    document.getElementById('centerModalLabel').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Evacuation Center';
    centerModal.show();
  });

  $('#centersTable').on('click', '.action-toggle-center', async function () {
    const id = $(this).data('id');
    const res = await apiPost('ajax/center_toggle.php', { id });
    if (res.success) { notify('success', res.message); centersTable.ajax.reload(null, false); }
  });

  document.getElementById('centerForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await apiPost('ajax/center_save.php', fd);
    if (res.success) {
      notify('success', res.message);
      centerModal.hide();
      centersTable.ajax.reload(null, false);
    }
  });
});
