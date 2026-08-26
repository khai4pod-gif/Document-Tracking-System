/**
 * assets/js/document_view.js
 * Powers document_view.php: routing, acknowledgment, completion,
 * and attachment / cloud-link add and delete actions.
 */

function getDocumentId() {
  const params = new URLSearchParams(window.location.search);
  return params.get('id');
}

document.addEventListener('DOMContentLoaded', () => {
  const routeModalEl = document.getElementById('routeModal');
  const attachmentModalEl = document.getElementById('attachmentModal');
  const linkModalEl = document.getElementById('linkModal');
  const routeModal = routeModalEl ? new bootstrap.Modal(routeModalEl) : null;
  const attachmentModal = attachmentModalEl ? new bootstrap.Modal(attachmentModalEl) : null;
  const linkModal = linkModalEl ? new bootstrap.Modal(linkModalEl) : null;

  const btnRoute = document.getElementById('btnRouteDoc');
  if (btnRoute) {
    btnRoute.addEventListener('click', () => {
      loadUsersDropdown();
      routeModal.show();
    });
  }

  const routeForm = document.getElementById('routeForm');
  if (routeForm) {
    routeForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const res = await apiPost('ajax/document_route.php', fd);
      if (res.success) {
        notify('success', res.message);
        setTimeout(() => window.location.reload(), 900);
      }
    });
  }

  const btnComplete = document.getElementById('btnMarkComplete');
  if (btnComplete) {
    btnComplete.addEventListener('click', async () => {
      const confirmed = await confirmAction('Mark as Completed?', 'This document will be flagged as completed.', 'Yes, mark completed');
      if (!confirmed) return;
      const res = await apiPost('ajax/document_archive.php', { document_id: getDocumentId(), action: 'complete' });
      if (res.success) { notify('success', res.message); setTimeout(() => window.location.reload(), 900); }
    });
  }

  const btnApprove = document.getElementById('btnApproveDoc');
  if (btnApprove) {
    btnApprove.addEventListener('click', async () => {
      const confirmed = await confirmAction('Approve this document?', 'It will become eligible for routing.', 'Yes, approve');
      if (!confirmed) return;
      const res = await apiPost('ajax/document_approve.php', { document_id: getDocumentId(), decision: 'approve' });
      if (res.success) { notify('success', res.message); setTimeout(() => window.location.reload(), 900); }
    });
  }

  const btnReject = document.getElementById('btnRejectDoc');
  if (btnReject) {
    btnReject.addEventListener('click', async () => {
      const confirmed = await confirmAction('Reject this document?', 'The submitter will need to revise and resubmit.', 'Yes, reject');
      if (!confirmed) return;
      const res = await apiPost('ajax/document_approve.php', { document_id: getDocumentId(), decision: 'reject' });
      if (res.success) { notify('success', res.message); setTimeout(() => window.location.reload(), 900); }
    });
  }

  const btnAck = document.getElementById('btnAcknowledge');
  if (btnAck) {
    btnAck.addEventListener('click', async () => {
      const res = await apiPost('ajax/document_receive.php', { route_id: btnAck.dataset.routeId });
      if (res.success) { notify('success', res.message); setTimeout(() => window.location.reload(), 900); }
    });
  }

  const btnUpload = document.getElementById('btnUploadAttachment');
  if (btnUpload) {
    btnUpload.addEventListener('click', () => attachmentModal.show());
  }

  const attachmentForm = document.getElementById('attachmentForm');
  if (attachmentForm) {
    attachmentForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const res = await apiPost('ajax/attachment_upload.php', fd);
      if (res.success) {
        notify('success', res.message);
        setTimeout(() => window.location.reload(), 900);
      }
    });
  }

  document.querySelectorAll('.btn-delete-attachment').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const confirmed = await confirmAction('Remove this attachment?', 'This action cannot be undone.', 'Yes, remove it');
      if (!confirmed) return;
      const res = await apiPost('ajax/attachment_delete.php', { attachment_id: btn.dataset.id });
      if (res.success) {
        notify('success', res.message);
        btn.closest('li').remove();
      }
    });
  });

  const btnAddLink = document.getElementById('btnAddLink');
  if (btnAddLink) {
    btnAddLink.addEventListener('click', () => {
      document.getElementById('linkForm').reset();
      linkModal.show();
    });
  }

  const linkForm = document.getElementById('linkForm');
  if (linkForm) {
    linkForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const res = await apiPost('ajax/link_save.php', fd);
      if (res.success) {
        notify('success', res.message);
        setTimeout(() => window.location.reload(), 900);
      }
    });
  }

  // ---- Document timeline: per-office internal actions ----
  const hopToggles = Array.from(document.querySelectorAll('.dt-toggle'));

  function setHopOpen(toggle, open) {
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    const panel = toggle.nextElementSibling;
    if (panel) panel.hidden = !open;
  }

  hopToggles.forEach((toggle) => {
    toggle.addEventListener('click', () => {
      setHopOpen(toggle, toggle.getAttribute('aria-expanded') !== 'true');
    });
  });

  const btnExpandAll = document.getElementById('btnExpandAllHops');
  if (btnExpandAll && hopToggles.length) {
    btnExpandAll.addEventListener('click', () => {
      const expand = btnExpandAll.dataset.expanded !== '1';
      hopToggles.forEach((toggle) => setHopOpen(toggle, expand));
      btnExpandAll.dataset.expanded = expand ? '1' : '0';
      btnExpandAll.textContent = expand ? 'Collapse All' : 'Expand All';
    });
  }

  document.querySelectorAll('.btn-delete-link').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const confirmed = await confirmAction('Remove this link?', 'The linked file itself is not deleted.', 'Yes, remove it');
      if (!confirmed) return;
      const res = await apiPost('ajax/link_delete.php', { link_id: btn.dataset.id });
      if (res.success) {
        notify('success', res.message);
        btn.closest('li').remove();
      }
    });
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
