/**
 * assets/js/departments.js
 * Powers departments.php: DataTable + Add/Edit/Toggle actions.
 */

let deptModal, deptTable, DEPT_CACHE = [];

document.addEventListener('DOMContentLoaded', () => {
  deptModal = new bootstrap.Modal(document.getElementById('deptModal'));

  deptTable = $('#deptTable').DataTable({
    ajax: {
      url: 'ajax/departments_list.php',
      dataSrc: (json) => { DEPT_CACHE = json.data; return json.data; },
    },
    order: [[0, 'asc']],
    columns: [
      { data: 'name', render: (d) => escapeHtml(d) },
      { data: 'code', render: (d) => `<span class="tracking-chip">${escapeHtml(d)}</span>` },
      { data: 'description', render: (d) => d ? escapeHtml(d) : '<span class="text-muted">—</span>' },
      { data: 'user_count', render: (d) => d.toLocaleString() },
      { data: 'is_active', render: (d) => d ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' },
      {
        data: null, orderable: false, className: 'text-end',
        render: (row) => `
          <button class="btn btn-sm btn-outline-secondary action-edit-dept" data-id="${row.id}"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-sm btn-outline-${row.is_active ? 'warning' : 'success'} action-toggle-dept" data-id="${row.id}">
            <i class="bi bi-power"></i></button>`,
      },
    ],
  });

  document.getElementById('btnNewDept').addEventListener('click', () => {
    document.getElementById('deptForm').reset();
    document.getElementById('deptId').value = '';
    document.getElementById('deptModalLabel').innerHTML = '<i class="bi bi-building me-2"></i>Add Department';
    deptModal.show();
  });

  $('#deptTable').on('click', '.action-edit-dept', function () {
    const id = parseInt($(this).data('id'), 10);
    const d = DEPT_CACHE.find((i) => i.id === id);
    if (!d) return;
    document.getElementById('deptId').value = d.id;
    document.getElementById('fieldDeptName').value = d.name;
    document.getElementById('fieldDeptCode').value = d.code;
    document.getElementById('fieldDeptDescription').value = d.description || '';
    document.getElementById('deptModalLabel').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Department';
    deptModal.show();
  });

  $('#deptTable').on('click', '.action-toggle-dept', async function () {
    const id = $(this).data('id');
    const res = await apiPost('ajax/department_toggle.php', { id });
    if (res.success) { notify('success', res.message); deptTable.ajax.reload(null, false); }
  });

  document.getElementById('deptForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await apiPost('ajax/department_save.php', fd);
    if (res.success) {
      notify('success', res.message);
      deptModal.hide();
      deptTable.ajax.reload(null, false);
    }
  });
});
