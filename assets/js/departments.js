/**
 * assets/js/departments.js
 * Powers departments.php: DataTable + Add/Edit/Toggle actions.
 */

let deptModal, deptTable, DEPT_CACHE = [], APPROVER_CACHE = [];

/**
 * Fills the approver picker. Rebuilt on every load so an approver account
 * created or deactivated elsewhere shows up without a hard refresh.
 */
function renderApproverOptions(selectedId) {
  const sel = document.getElementById('fieldDeptApprover');
  if (!sel) return;
  sel.innerHTML = '<option value="">— No approver assigned —</option>';
  APPROVER_CACHE.forEach((a) => {
    const opt = document.createElement('option');
    opt.value = a.id;
    opt.textContent = a.full_name + (a.username ? ' (' + a.username + ')' : '');
    if (selectedId && Number(selectedId) === Number(a.id)) opt.selected = true;
    sel.appendChild(opt);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  deptModal = new bootstrap.Modal(document.getElementById('deptModal'));

  deptTable = $('#deptTable').DataTable({
    ajax: {
      url: 'ajax/departments_list.php',
      dataSrc: (json) => {
        DEPT_CACHE = json.data;
        APPROVER_CACHE = json.approvers || [];
        return json.data;
      },
    },
    order: [[0, 'asc']],
    columns: [
      { data: 'name', render: (d) => escapeHtml(d) },
      { data: 'code', render: (d) => `<span class="tracking-chip">${escapeHtml(d)}</span>` },
      { data: 'description', render: (d) => d ? escapeHtml(d) : '<span class="text-muted">—</span>' },
      {
        data: 'approver_name',
        render: (d, t, row) => {
          if (!d) return '<span class="text-muted">— not set —</span>';
          // Flagged when inactive: documents auto-routed to this office
          // would land on an account nobody is signing in to.
          const warn = row.approver_active === 0
            ? ' <span class="badge bg-danger ms-1">inactive</span>' : '';
          return escapeHtml(d) + warn;
        },
      },
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
    renderApproverOptions(null);
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
    renderApproverOptions(d.approver_user_id);
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
