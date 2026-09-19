/**
 * assets/js/document_view.js
 * Powers document_view.php: acknowledgment, approval, completion, and
 * attachment / cloud-link add and delete actions.
 *
 * Routing is deliberately absent — a document moves by itself (upload ->
 * Office of the Secretary -> that office's approver -> back to the
 * creator), so there is no recipient to pick and no Route action here.
 */

function getDocumentId() {
  const params = new URLSearchParams(window.location.search);
  return params.get('id');
}

document.addEventListener('DOMContentLoaded', () => {
  const attachmentModalEl = document.getElementById('attachmentModal');
  const linkModalEl = document.getElementById('linkModal');
  const attachmentModal = attachmentModalEl ? new bootstrap.Modal(attachmentModalEl) : null;
  const linkModal = linkModalEl ? new bootstrap.Modal(linkModalEl) : null;

  const btnComplete = document.getElementById('btnMarkComplete');
  if (btnComplete) {
    btnComplete.addEventListener('click', async () => {
      const confirmed = await confirmAction('Mark as Completed?', 'This document will be flagged as completed.', 'Yes, mark completed');
      if (!confirmed) return;
      const res = await apiPost('ajax/document_archive.php', { document_id: getDocumentId(), action: 'complete' });
      if (res.success) { notify('success', res.message); setTimeout(() => window.location.reload(), 900); }
    });
  }

  // Both decisions offer an optional note. The document returns to its
  // creator either way, so this is the approver's one chance to say what
  // needs doing — especially on a rejection, where "no" without a reason
  // leaves the creator guessing at the revision.
  //
  // `=== null` means cancelled; an empty string means confirmed with no
  // note, which is allowed.
  const btnApprove = document.getElementById('btnApproveDoc');
  if (btnApprove) {
    btnApprove.addEventListener('click', async () => {
      const remarks = await confirmWithRemarks({
        title: 'Approve this document?',
        text: 'It will be returned to the creator as approved.',
        confirmText: 'Yes, approve',
        label: 'Message to the creator',
        placeholder: 'Any instruction or guidance to go with the approval…',
        help: 'Shown to the creator with the document.',
        optional: true,
        icon: 'question',
      });
      if (remarks === null) return;
      const res = await apiPost('ajax/document_approve.php', {
        document_id: getDocumentId(), decision: 'approve', remarks,
      });
      if (res.success) { notify('success', res.message); setTimeout(() => window.location.reload(), 900); }
    });
  }

  const btnReject = document.getElementById('btnRejectDoc');
  if (btnReject) {
    btnReject.addEventListener('click', async () => {
      const remarks = await confirmWithRemarks({
        title: 'Reject this document?',
        text: 'It will be returned to the creator for revision.',
        confirmText: 'Yes, reject',
        label: 'Message to the creator',
        placeholder: 'What needs to be corrected before resubmitting…',
        help: 'Shown to the creator with the document.',
        optional: true,
      });
      if (remarks === null) return;
      const res = await apiPost('ajax/document_approve.php', {
        document_id: getDocumentId(), decision: 'reject', remarks,
      });
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

// loadUsersDropdown() was removed with the Route action: it filled the
// recipient <select>, and there is no recipient to choose any more.

