/**
 * assets/js/documents.js
 * Powers documents.php: DataTable rendering, filters, and the
 * Create/Edit/Route modals, all driven through the AJAX endpoints.
 */

const PRIORITY_BADGE = { Urgent: 'bg-danger', High: 'bg-warning text-dark', Normal: 'bg-info text-dark', Low: 'bg-secondary' };
const STATUS_BADGE = {
  Completed: 'bg-success', Overdue: 'bg-danger', 'In Transit': 'bg-primary',
  'Pending Routing': 'bg-warning text-dark', Received: 'bg-info text-dark', Draft: 'bg-secondary',
};

let documentModal, routeModal, documentsTable;

document.addEventListener('DOMContentLoaded', () => {
  documentModal = new bootstrap.Modal(document.getElementById('documentModal'));
  routeModal = new bootstrap.Modal(document.getElementById('routeModal'));

  documentsTable = $('#documentsTable').DataTable({
    ajax: {
      url: 'ajax/documents_list.php?archived=' + (SHOW_ARCHIVED ? '1' : '0'),
      dataSrc: 'data',
    },
    order: [[6, 'desc']],
    dom: "<'d-flex justify-content-between align-items-center mb-2'fB>rt<'d-flex justify-content-between align-items-center mt-2'ip>",
    buttons: ['csv', 'excel', 'print'],
    columns: [
      { data: 'tracking_number', render: (d, t, row) => t === 'display'
          ? `<a href="document_view.php?id=${row.id}" class="tracking-chip text-decoration-none">${escapeHtml(d)}</a>` : d },
      { data: 'title', render: (d, t) => t === 'display' ? escapeHtml(d) : d },
      { data: 'doc_type', render: (d) => escapeHtml(d) },
      { data: 'priority', render: (d, t) => t === 'display'
          ? `<span class="badge ${PRIORITY_BADGE[d] || 'bg-secondary'}">${escapeHtml(d)}</span>` : d },
      { data: 'status', render: (d, t) => t === 'display'
          ? `<span class="badge ${STATUS_BADGE[d] || 'bg-secondary'}">${escapeHtml(d)}</span>` : d },
      { data: 'holder_name', render: (d) => escapeHtml(d) },
      { data: 'created_at', render: (d) => escapeHtml(d) },
      {
        data: null, orderable: false, className: 'text-end',
        render: (row) => {
          const archived = row.is_archived === 1;
          let actions = `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu dropdown-menu-end">`;
          actions += `<li><a class="dropdown-item" href="document_view.php?id=${row.id}"><i class="bi bi-eye me-2"></i>View</a></li>`;
          if (!archived) {
            actions += `<li><a class="dropdown-item action-edit" href="#" data-id="${row.id}"><i class="bi bi-pencil me-2"></i>Edit</a></li>`;
            actions += `<li><a class="dropdown-item action-route" href="#" data-id="${row.id}"><i class="bi bi-signpost-split me-2"></i>Route</a></li>`;
            actions += `<li><a class="dropdown-item action-complete" href="#" data-id="${row.id}"><i class="bi bi-check-circle me-2"></i>Mark Completed</a></li>`;
            actions += `<li><hr class="dropdown-divider"></li>`;
            actions += `<li><a class="dropdown-item text-danger action-archive" href="#" data-id="${row.id}"><i class="bi bi-archive me-2"></i>Archive</a></li>`;
          } else {
            actions += `<li><a class="dropdown-item text-success action-restore" href="#" data-id="${row.id}"><i class="bi bi-arrow-counterclockwise me-2"></i>Restore</a></li>`;
          }
          actions += `</ul></div>`;
          return actions;
        },
      },
    ],
  });

  // Filters
  $('#filterStatus, #filterPriority').on('change', function () {
    const url = 'ajax/documents_list.php?archived=' + (SHOW_ARCHIVED ? '1' : '0')
      + '&status=' + encodeURIComponent($('#filterStatus').val())
      + '&priority=' + encodeURIComponent($('#filterPriority').val());
    documentsTable.ajax.url(url).load();
  });

  // New Document button
  const btnNew = document.getElementById('btnNewDocument');
  if (btnNew) {
    btnNew.addEventListener('click', () => {
      document.getElementById('documentForm').reset();
      document.getElementById('documentId').value = '';
      document.getElementById('documentModalLabel').innerHTML = '<i class="bi bi-file-earmark-plus me-2"></i>New Document';
      document.getElementById('attachmentWrapper').style.display = '';
      documentModal.show();
    });
  }

  // Edit action (delegated)
  $('#documentsTable').on('click', '.action-edit', function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    fetch('document_view.php?id=' + id + '&format=json')
      .then((r) => r.json())
      .then((res) => {
        if (!res.success) { notify('error', res.message || 'Unable to load document.'); return; }
        const d = res.document;
        document.getElementById('documentForm').reset();
        document.getElementById('documentId').value = d.id;
        document.getElementById('fieldTitle').value = d.title;
        document.getElementById('fieldPriority').value = d.priority;
        document.getElementById('fieldType').value = d.doc_type;
        document.getElementById('fieldDueDate').value = d.due_date_raw || '';
        document.getElementById('fieldDescription').value = d.description || '';
        document.getElementById('attachmentWrapper').style.display = 'none';
        document.getElementById('documentModalLabel').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Document';
        documentModal.show();
      })
      .catch(() => notify('error', 'Unable to load document details.'));
  });

  // Save (create/update)
  document.getElementById('documentForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await apiPost('ajax/document_save.php', fd);
    if (res.success) {
      notify('success', res.message);
      documentModal.hide();
      documentsTable.ajax.reload(null, false);
    }
  });

  // Route action
  $('#documentsTable').on('click', '.action-route', function (e) {
    e.preventDefault();
    document.getElementById('routeForm').reset();
    document.getElementById('routeDocumentId').value = $(this).data('id');
    loadUsersDropdown();
    routeModal.show();
  });

  document.getElementById('routeForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await apiPost('ajax/document_route.php', fd);
    if (res.success) {
      notify('success', res.message);
      routeModal.hide();
      documentsTable.ajax.reload(null, false);
    }
  });

  // Mark completed
  $('#documentsTable').on('click', '.action-complete', async function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const confirmed = await confirmAction('Mark as Completed?', 'This document will be flagged as completed.', 'Yes, mark completed');
    if (!confirmed) return;
    const res = await apiPost('ajax/document_archive.php', { document_id: id, action: 'complete' });
    if (res.success) { notify('success', res.message); documentsTable.ajax.reload(null, false); }
  });

  // Archive
  $('#documentsTable').on('click', '.action-archive', async function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const confirmed = await confirmAction('Archive this document?', 'It will be moved out of the active list. You can restore it later.', 'Yes, archive');
    if (!confirmed) return;
    const res = await apiPost('ajax/document_archive.php', { document_id: id, action: 'archive' });
    if (res.success) { notify('success', res.message); documentsTable.ajax.reload(null, false); }
  });

  // Restore
  $('#documentsTable').on('click', '.action-restore', async function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const res = await apiPost('ajax/document_archive.php', { document_id: id, action: 'restore' });
    if (res.success) { notify('success', res.message); documentsTable.ajax.reload(null, false); }
  });
});

function loadUsersDropdown() {
  const select = document.getElementById('routeToUser');
  select.innerHTML = '<option value="">Loading users…</option>';
  fetch('ajax/users_list.php')
    .then((r) => r.json())
    .then((res) => {
      select.innerHTML = '<option value="">Select recipient…</option>';
      (res.data || []).forEach((u) => {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = `${u.full_name} — ${u.department_name || u.role}`;
        select.appendChild(opt);
      });
    })
    .catch(() => { select.innerHTML = '<option value="">Failed to load users</option>'; });
}
