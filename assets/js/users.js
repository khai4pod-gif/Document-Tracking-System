/**
 * assets/js/users.js
 * Powers users.php: DataTable + Create/Edit/Toggle/Reset Password actions.
 */

let userModal, resetPasswordModal, usersTable, USERS_CACHE = [];

const ROLE_LABEL = { admin: 'Administrator', department: 'Department User', logistics: 'Logistics / Relief', approver: 'Approver' };
const ROLE_BADGE = { admin: 'bg-primary', department: 'bg-info text-dark', logistics: 'bg-warning text-dark', approver: 'bg-success' };

document.addEventListener('DOMContentLoaded', () => {
  userModal = new bootstrap.Modal(document.getElementById('userModal'));
  resetPasswordModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));

  usersTable = $('#usersTable').DataTable({
    ajax: {
      url: 'ajax/users_admin_list.php',
      dataSrc: (json) => { USERS_CACHE = json.data; return json.data; },
    },
    order: [[0, 'asc']],
    columns: [
      { data: 'full_name', render: (d) => escapeHtml(d) },
      { data: 'username', render: (d) => escapeHtml(d) },
      { data: 'email', render: (d) => escapeHtml(d) },
      { data: 'role', render: (d) => `<span class="badge ${ROLE_BADGE[d] || 'bg-secondary'}">${ROLE_LABEL[d] || escapeHtml(d)}</span>` },
      { data: 'department_name', render: (d) => d ? escapeHtml(d) : '—' },
      { data: null, render: (row) => row.role !== 'department'
          ? '<span class="text-muted small">—</span>'
          : (row.can_route ? '<span class="badge bg-success">Allowed</span>' : '<span class="badge bg-secondary">Restricted</span>') },
      { data: 'last_login_at' },
      { data: 'is_active', render: (d) => d ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' },
      {
        data: null, orderable: false, className: 'text-end',
        render: (row) => {
          const isSelf = row.id === CURRENT_USER_ID;
          let actions = `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu dropdown-menu-end">`;
          actions += `<li><a class="dropdown-item action-edit-user" href="#" data-id="${row.id}"><i class="bi bi-pencil me-2"></i>Edit</a></li>`;
          actions += `<li><a class="dropdown-item action-reset-pwd" href="#" data-id="${row.id}" data-name="${escapeHtml(row.full_name)}"><i class="bi bi-key me-2"></i>Reset Password</a></li>`;
          if (!isSelf) {
            actions += `<li><hr class="dropdown-divider"></li>`;
            actions += `<li><a class="dropdown-item ${row.is_active ? 'text-danger' : 'text-success'} action-toggle-user" href="#" data-id="${row.id}"><i class="bi bi-power me-2"></i>${row.is_active ? 'Deactivate' : 'Activate'}</a></li>`;
          }
          actions += `</ul></div>`;
          return actions;
        },
      },
    ],
  });

  document.getElementById('toggleFieldPassword').addEventListener('click', () => {
    const pwd = document.getElementById('fieldPassword');
    pwd.type = pwd.type === 'password' ? 'text' : 'password';
  });

  document.getElementById('toggleFieldConfirmPassword').addEventListener('click', () => {
    const pwd = document.getElementById('fieldConfirmPassword');
    pwd.type = pwd.type === 'password' ? 'text' : 'password';
  });

  function toggleCanRouteWrapper() {
    const isDept = document.getElementById('fieldRole').value === 'department';
    document.getElementById('canRouteWrapper').style.display = isDept ? '' : 'none';
  }
  document.getElementById('fieldRole').addEventListener('change', toggleCanRouteWrapper);

  document.getElementById('btnNewUser').addEventListener('click', () => {
    document.getElementById('userForm').reset();
    document.getElementById('userId').value = '';
    document.getElementById('fieldPassword').required = true;
    document.getElementById('passwordWrapper').style.display = '';
    document.getElementById('fieldConfirmPassword').required = true;
    document.getElementById('confirmPasswordWrapper').style.display = '';
    document.getElementById('confirmPasswordError').style.display = 'none';
    document.getElementById('fieldCanRoute').checked = true;
    toggleCanRouteWrapper();
    document.getElementById('userModalLabel').innerHTML = '<i class="bi bi-person-plus me-2"></i>New User';
    userModal.show();
  });

  $('#usersTable').on('click', '.action-edit-user', function (e) {
    e.preventDefault();
    const id = parseInt($(this).data('id'), 10);
    const u = USERS_CACHE.find((i) => i.id === id);
    if (!u) return;
    document.getElementById('userForm').reset();
    document.getElementById('userId').value = u.id;
    document.getElementById('fieldFullName').value = u.full_name;
    document.getElementById('fieldUsername').value = u.username;
    document.getElementById('fieldEmail').value = u.email;
    document.getElementById('fieldRole').value = u.role;
    document.getElementById('fieldDepartment').value = u.department_id || '';
    document.getElementById('fieldCanRoute').checked = !!u.can_route;
    toggleCanRouteWrapper();
    document.getElementById('fieldPassword').required = false;
    document.getElementById('passwordWrapper').style.display = 'none';
    document.getElementById('fieldConfirmPassword').required = false;
    document.getElementById('confirmPasswordWrapper').style.display = 'none';
    document.getElementById('confirmPasswordError').style.display = 'none';
    document.getElementById('userModalLabel').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit User';
    userModal.show();
  });

  document.getElementById('userForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const pwd = document.getElementById('fieldPassword');
    const confirmPwd = document.getElementById('fieldConfirmPassword');
    const confirmError = document.getElementById('confirmPasswordError');
    if (document.getElementById('passwordWrapper').style.display !== 'none' && pwd.value !== confirmPwd.value) {
      confirmError.style.display = '';
      confirmPwd.focus();
      return;
    }
    confirmError.style.display = 'none';

    const fd = new FormData(e.target);
    const res = await apiPost('ajax/user_save.php', fd);
    if (res.success) {
      notify('success', res.message);
      userModal.hide();
      usersTable.ajax.reload(null, false);
    }
  });

  $('#usersTable').on('click', '.action-toggle-user', async function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const confirmed = await confirmAction('Change account status?', 'This will immediately affect the user\'s ability to sign in.', 'Yes, proceed');
    if (!confirmed) return;
    const res = await apiPost('ajax/user_toggle.php', { id });
    if (res.success) { notify('success', res.message); usersTable.ajax.reload(null, false); }
  });

  $('#usersTable').on('click', '.action-reset-pwd', function (e) {
    e.preventDefault();
    document.getElementById('resetPasswordForm').reset();
    document.getElementById('resetUserId').value = $(this).data('id');
    document.getElementById('resetUserName').textContent = $(this).data('name');
    resetPasswordModal.show();
  });

  document.getElementById('resetPasswordForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await apiPost('ajax/user_reset_password.php', fd);
    if (res.success) {
      notify('success', res.message);
      resetPasswordModal.hide();
    }
  });
});
