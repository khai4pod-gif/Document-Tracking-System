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
    + '&priority=' + encodeURIComponent($('#filterPriority').val() || '')
    + '&compliance=' + encodeURIComponent(FILTER_COMPLIANCE || '')
    + '&period=' + encodeURIComponent(FILTER_PERIOD || '');
}

let documentModal, documentsTable;

document.addEventListener('DOMContentLoaded', () => {
  documentModal = new bootstrap.Modal(document.getElementById('documentModal'));
  typeDetectInit();
  saveGateInit();

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
      typeDetectReset('create');
      saveGateApply();
      document.getElementById('attachmentWrapper').style.display = '';
      // The block states where the document will go rather than asking, so
      // there is no recipient picker left to populate.
      const routeWrapper = document.getElementById('routeOnCreateWrapper');
      if (routeWrapper) {
        routeWrapper.style.display = '';
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
        typeDetectReset('edit');
        saveGateApply();
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


/* ===================== Save gate =====================
 * A new document cannot be saved without the document itself. The type is
 * read from the file, so an entry with nothing attached has nothing to
 * classify and nothing to hand on. Editing is exempt: the file was
 * uploaded when the document was created, and the upload field is hidden
 * on that path.
 */

function saveGateInit() {
  const file = document.getElementById('fieldAttachment');
  if (file) file.addEventListener('change', saveGateApply);
  saveGateApply();
}

function saveGateApply() {
  const btn = document.getElementById('btnSaveDocument');
  if (!btn) return;
  const idField = document.getElementById('documentId');
  const file    = document.getElementById('fieldAttachment');
  const isEdit  = !!(idField && idField.value !== '');
  const hasFile = !!(file && file.files && file.files.length);
  const ready   = isEdit || hasFile;

  btn.disabled = !ready;
  btn.title = ready ? '' : 'Attach the document file first.';

  // Only a field the user can see may be required: on edit the upload is
  // hidden, and a hidden required control blocks the submit with an error
  // no one can reach.
  if (file) file.required = !isEdit;
}


/* ===================== Automatic document type =====================
 * The creator does not pick the type — the system reads the document
 * and says what it is. This is the form-side half of that: it asks
 * ajax/document_classify_preview.php what the attached file looks like
 * and shows the answer *before* the document is saved.
 *
 * The preview and the real classification run the same extractor and
 * the same classifier on the server, so what the form shows is what
 * gets stored. The <select> is still in the page, hidden, for the two
 * cases the system will not decide on its own: a file it cannot read
 * confidently, and a creator who disagrees with it.
 */

const TD = {};          // cached elements, filled by typeDetectInit()
let tdToken = 0;        // guards against a slow reply overwriting a fast one
let tdTextTimer = null;

function typeDetectInit() {
  TD.card = document.getElementById('typeDetected');
  if (!TD.card) return;
  TD.icon     = TD.card.querySelector('.type-detect__icon');
  TD.value    = document.getElementById('typeDetectedValue');
  TD.note     = document.getElementById('typeDetectedNote');
  TD.conf     = document.getElementById('typeDetectedConf');
  TD.why      = document.getElementById('typeDetectedWhy');
  TD.override = document.getElementById('btnOverrideType');
  TD.manual   = document.getElementById('typeManual');
  TD.select   = document.getElementById('fieldType');
  TD.help     = document.getElementById('typeManualHelp');
  TD.flag     = document.getElementById('fieldTypeOverridden');
  TD.file     = document.getElementById('fieldAttachment');
  TD.title    = document.getElementById('fieldTitle');
  TD.desc     = document.getElementById('fieldDescription');

  if (TD.file) TD.file.addEventListener('change', () => typeDetectRun());

  // Re-reading a 10 MB upload every time a letter is typed in the title
  // would be absurd, so the title and description only re-trigger the
  // check when there is no file to read — which is exactly the case
  // where they are the only evidence there is.
  const onText = () => {
    if (TD.file && TD.file.files && TD.file.files.length) return;
    if (TD.flag.value === '1') return;               // creator has taken over
    clearTimeout(tdTextTimer);
    tdTextTimer = setTimeout(() => typeDetectRun(), 600);
  };
  if (TD.title) TD.title.addEventListener('input', onText);
  if (TD.desc) TD.desc.addEventListener('input', onText);

  if (TD.override) {
    TD.override.addEventListener('click', () => typeDetectHandOver('Set by you.'));
  }
  // Touching the select at all is a decision: from then on the server
  // keeps what the creator chose instead of replacing it.
  if (TD.select) {
    TD.select.addEventListener('change', () => { TD.flag.value = '1'; });
  }
}

/** @param {'create'|'edit'} mode */
function typeDetectReset(mode) {
  if (!TD.card) return;
  clearTimeout(tdTextTimer);
  tdToken++;                                   // abandon any reply in flight
  TD.flag.value = '';
  TD.why.hidden = true;
  TD.why.textContent = '';
  TD.conf.hidden = true;

  if (mode === 'edit') {
    // Existing documents were classified when they were created. Editing
    // one is a correction, so the choice belongs to the creator here.
    TD.card.hidden = true;
    TD.manual.hidden = false;
    TD.help.textContent = 'Set when the document was created. Change it if it was read wrongly.';
    return;
  }

  TD.card.hidden = false;
  TD.card.dataset.state = 'idle';
  TD.icon.innerHTML = '<i class="bi bi-stars"></i>';
  TD.value.textContent = 'Detected automatically';
  TD.note.textContent = 'Attach the document and the system will identify its type.';
  TD.manual.hidden = true;
  TD.help.textContent = '';
  TD.override.hidden = true;
}

/** Reveal the select and stop the system overruling it. */
function typeDetectHandOver(note) {
  TD.flag.value = '1';
  TD.manual.hidden = false;
  TD.override.hidden = true;
  TD.help.textContent = note || '';
  TD.select.focus();
}

async function typeDetectRun() {
  if (!TD.card || TD.card.hidden) return;

  const title = (TD.title ? TD.title.value : '').trim();
  const hasFile = !!(TD.file && TD.file.files && TD.file.files.length);
  if (!hasFile && title.length < 3) return;     // nothing to go on yet

  const token = ++tdToken;
  TD.card.dataset.state = 'working';
  TD.icon.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
  TD.value.textContent = hasFile ? 'Reading the document…' : 'Checking…';
  TD.note.textContent = hasFile ? TD.file.files[0].name : '';
  TD.conf.hidden = true;
  TD.why.hidden = true;

  const fd = new FormData();
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('title', title);
  fd.append('description', TD.desc ? TD.desc.value : '');
  if (hasFile) fd.append('attachment', TD.file.files[0]);

  let res;
  try {
    const r = await fetch('ajax/document_classify_preview.php', {
      method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    res = await r.json();
  } catch (e) {
    res = null;
  }
  if (token !== tdToken) return;                // a newer check has started

  // A failed check must not block the document. Hand the choice back
  // rather than leaving the creator staring at a spinner.
  if (!res || !res.success) {
    TD.card.dataset.state = 'unsure';
    TD.icon.innerHTML = '<i class="bi bi-exclamation-triangle"></i>';
    TD.value.textContent = 'Could not read the document';
    TD.note.textContent = (res && res.message) || 'The check did not complete. Please choose the type below.';
    typeDetectHandOver('');
    return;
  }

  typeDetectRender(res);
}

function typeDetectRender(res) {
  const pct = Math.round((res.confidence || 0) * 100);
  const fromFile = res.read_from === 'pdf' || res.read_from === 'docx' || res.read_from === 'ocr';
  const readNote = fromFile
    ? 'Read from ' + res.read_from.toUpperCase() + ' — ' + Number(res.chars).toLocaleString() + ' characters.'
    : 'Read from the title and description.';

  if (res.reasons && res.reasons.length) {
    TD.why.textContent = 'Matched: ' + res.reasons.join(', ');
    TD.why.hidden = false;
  } else {
    TD.why.hidden = true;
  }

  // The file and the title name different types, and each reading was
  // strong enough to have been filed on its own. Nothing is asserted:
  // both readings are shown and the choice goes back to the creator.
  if (res.agreement === 'conflict' && res.document && res.title) {
    TD.card.dataset.state = 'unsure';
    TD.icon.innerHTML = '<i class="bi bi-exclamation-triangle"></i>';
    TD.value.textContent = 'The document and the title disagree';
    TD.note.textContent = 'The contents read as ' + res.document.type
      + ', the title and file name read as ' + res.title.type + '. Please choose the correct type.';
    TD.why.hidden = true;
    TD.conf.hidden = true;
    TD.manual.hidden = false;
    TD.override.hidden = true;
    TD.help.textContent = 'Please choose the type.';
    return;
  }

  if (res.confident) {
    TD.card.dataset.state = 'done';
    TD.icon.innerHTML = '<i class="bi bi-check-lg"></i>';
    TD.value.textContent = res.type;
    TD.note.textContent = res.warning
      ? res.warning
      : (res.agreement === 'agree' ? 'The contents and the name agree. ' : '') + readNote;
    TD.conf.textContent = pct + '% confident';
    TD.conf.hidden = false;
    // Post the same answer the server will reach, so the saved document
    // matches what the creator was shown even if the two ever diverge.
    TD.select.value = res.type;
    TD.manual.hidden = true;
    TD.flag.value = '';
    TD.override.hidden = false;
    return;
  }

  // Not confident enough to assert. Saying so and asking is honest;
  // asserting a coin-flip and being wrong is how people stop trusting it.
  TD.card.dataset.state = 'unsure';
  TD.icon.innerHTML = '<i class="bi bi-question-lg"></i>';
  TD.value.textContent = 'Not sure what this document is';
  TD.note.textContent = res.warning
    ? res.warning
    : readNote + ' Closest match was ' + res.type + ' (' + pct + '%), which is too weak to use.';
  TD.manual.hidden = false;
  TD.override.hidden = true;
  TD.help.textContent = 'Please choose the type.';
}
