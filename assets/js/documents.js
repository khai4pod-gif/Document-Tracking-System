/**
 * assets/js/documents.js
 * Powers documents.php: DataTable rendering, filters, and the
 * Create/Edit modal, all driven through the AJAX endpoints.
 */

const PRIORITY_BADGE = { Urgent: 'bg-danger', High: 'bg-warning text-dark', Normal: 'bg-info text-dark', Low: 'bg-secondary' };
const STATUS_BADGE = {
  Completed: 'bg-success', Overdue: 'bg-danger', 'In Transit': 'bg-primary',
  'Pending Routing': 'bg-warning text-dark', Received: 'bg-info text-dark', Draft: 'bg-secondary',
};
const APPROVAL_BADGE = {
  Pending: 'bg-warning text-dark', Approved: 'bg-success', Rejected: 'bg-danger',
};

// Index of the CREATED column in the table definition below.
const CREATED_COL = 7;

/** The list endpoint URL for the filters currently selected. */
function documentsListUrl() {
  return 'ajax/documents_list.php?archived=' + (SHOW_ARCHIVED ? '1' : '0')
    + '&status=' + encodeURIComponent($('#filterStatus').val() || '')
    + '&priority=' + encodeURIComponent($('#filterPriority').val() || '');
}

let documentModal, documentsTable;

document.addEventListener('DOMContentLoaded', () => {
  documentModal = new bootstrap.Modal(document.getElementById('documentModal'));

  documentsTable = $('#documentsTable').DataTable({
    // Built from the dropdowns, which the page may have pre-selected from the
    // query string — so a dashboard tile linking to ?status=Overdue lands on
    // an already-filtered table.
    ajax: { url: documentsListUrl(), dataSrc: 'data' },
    order: [[CREATED_COL, 'desc']],
    dom: "<'d-flex justify-content-between align-items-center mb-2'fB>rt<'d-flex justify-content-between align-items-center mt-2'ip>",
    buttons: ['csv', 'excel', 'print'],
    createdRow: (row, data) => {
      row.dataset.id = data.id;
      row.style.cursor = 'pointer';
    },
    columns: [
      { data: 'tracking_number', render: (d, t, row) => t === 'display'
          ? `<a href="document_view.php?id=${row.id}" class="tracking-chip text-decoration-none">${escapeHtml(d)}</a>` : d },
      { data: 'title', render: (d, t) => t === 'display' ? escapeHtml(d) : d },
      { data: 'doc_type', render: (d) => escapeHtml(d) },
      { data: 'priority', render: (d, t) => t === 'display'
          ? `<span class="badge ${PRIORITY_BADGE[d] || 'bg-secondary'}">${escapeHtml(d)}</span>` : d },
      { data: 'status', render: (d, t) => t === 'display'
          ? `<span class="badge ${STATUS_BADGE[d] || 'bg-secondary'}">${escapeHtml(d)}</span>` : d },
      { data: 'approval_status', render: (d, t) => {
          if (t !== 'display') return d;
          if (!d || d === 'Not Required') return '<span class="text-muted small">—</span>';
          return `<span class="badge ${APPROVAL_BADGE[d] || 'bg-secondary'}">${escapeHtml(d)}</span>`;
        } },
      { data: 'holder_name', render: (d) => escapeHtml(d) },
      {
        // Display the friendly date, but sort on the raw timestamp — the
        // formatted string orders alphabetically ("Jul" before "Aug").
        data: 'created_at',
        render: (d, t, row) => (t === 'sort' || t === 'type' ? row.created_at_ts : escapeHtml(d)),
      },
      {
        data: null, orderable: false, className: 'text-end',
        render: (row) => {
          const archived = row.is_archived === 1;
          let actions = `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu dropdown-menu-end">`;
          actions += `<li><a class="dropdown-item" href="document_view.php?id=${row.id}"><i class="bi bi-eye me-2"></i>View</a></li>`;
          if (!archived) {
            if (row.created_by === CURRENT_USER_ID) {
              actions += `<li><a class="dropdown-item action-edit" href="#" data-id="${row.id}"><i class="bi bi-pencil me-2"></i>Edit</a></li>`;
            } else {
              actions += `<li><span class="dropdown-item disabled" title="Only the document's creator can edit it."><i class="bi bi-pencil me-2"></i>Edit</span></li>`;
            }
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

  // Clicking anywhere in a row opens the document — except the tracking-
  // number link and the Actions dropdown, which already handle their own
  // clicks (and shouldn't also trigger a navigation underneath them).
  $('#documentsTable tbody').on('click', 'tr', function (e) {
    if ($(e.target).closest('a, .dropdown').length) return;
    const id = this.dataset.id;
    if (id) window.location.href = 'document_view.php?id=' + id;
  });

  // Filters
  $('#filterStatus, #filterPriority').on('change', function () {
    documentsTable.ajax.url(documentsListUrl()).load();
  });

  // New Document button
  const btnNew = document.getElementById('btnNewDocument');
  if (btnNew) {
    btnNew.addEventListener('click', () => {
      document.getElementById('documentForm').reset();
      document.getElementById('documentId').value = '';
      document.getElementById('documentModalLabel').innerHTML = '<i class="bi bi-file-earmark-plus me-2"></i>New Document';
      document.getElementById('attachmentWrapper').style.display = '';
      // Routing is offered on create only; existing documents use the Route action.
      const routeWrapper = document.getElementById('routeOnCreateWrapper');
      if (routeWrapper) {
        routeWrapper.style.display = '';
        loadUsersDropdown('fieldRouteTo', 'Do not route yet — save as draft');
      }
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
        const routeWrapper = document.getElementById('routeOnCreateWrapper');
        if (routeWrapper) routeWrapper.style.display = 'none';
        document.getElementById('documentModalLabel').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Edit Document';
        documentModal.show();
      })
      .catch(() => notify('error', 'Unable to load document details.'));
  });

  // Save (create/update)
  document.getElementById('documentForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const isNew = document.getElementById('documentId').value === '';
    const fd = new FormData(e.target);
    const res = await apiPost('ajax/document_save.php', fd);
    if (res.success) {
      notify('success', res.message);
      documentModal.hide();
      if (isNew) {
        // Put the new document where the user will see it: newest first,
        // back on page one, even if they had sorted by another column.
        documentsTable.order([CREATED_COL, 'desc']).ajax.reload(null, true);
      } else {
        documentsTable.ajax.reload(null, false);
      }
    }
  });

  // Archive
  $('#documentsTable').on('click', '.action-archive', async function (e) {
    e.preventDefault();
    const id = $(this).data('id');
    const remarks = await confirmWithRemarks({
      title: 'Archive this document?',
      text: 'It will be moved out of the active list. You can restore it later.',
      confirmText: 'Yes, archive',
      label: 'Conclusion remarks',
      placeholder: 'e.g. transmitted to PIO for posting; awaiting external endorsement',
      help: "Required · what your office did and why you're closing",
      maxLength: 500,
    });
    if (remarks === null) return;
    const res = await apiPost('ajax/document_archive.php', {
      document_id: id, action: 'archive', conclusion_remarks: remarks,
    });
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

/**
 * Fills a <select> with the active registered users returned by
 * ajax/users_list.php. Shared by the Route modal and the "Route" block
 * inside the New Document modal, hence the configurable placeholder.
 */
function loadUsersDropdown(selectId, placeholder) {
  const select = document.getElementById(selectId);
  if (!select) return;
  select.innerHTML = '<option value="">Loading users…</option>';
  fetch('ajax/users_list.php')
    .then((r) => r.json())
    .then((res) => {
      select.innerHTML = '';
      const blank = document.createElement('option');
      blank.value = '';
      blank.textContent = placeholder;
      select.appendChild(blank);
      (res.data || []).forEach((u) => {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = `${u.full_name} — ${u.department_name || u.role}`;
        select.appendChild(opt);
      });
    })
    .catch(() => { select.innerHTML = '<option value="">Failed to load users</option>'; });
}
