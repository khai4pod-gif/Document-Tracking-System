/**
 * assets/js/app.js
 * Shared front-end utilities: CSRF-aware fetch wrapper, toast notifications,
 * sidebar collapse/toggle, and flash-message bootstrapping.
 */

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

/** Escapes a string for safe insertion into HTML (used by all DataTable renderers). */
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/** SweetAlert2 toast (top-right, auto-dismiss). */
const Toast = Swal.mixin({
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timer: 3800,
  timerProgressBar: true,
});

function notify(type, message) {
  Toast.fire({ icon: type, title: message });
}

/**
 * POST FormData (works for both plain fields and file uploads) with CSRF
 * token auto-attached. Returns the parsed JSON response.
 */
async function apiPost(url, formData) {
  if (!(formData instanceof FormData)) {
    const fd = new FormData();
    Object.entries(formData || {}).forEach(([k, v]) => fd.append(k, v));
    formData = fd;
  }
  if (!formData.has('csrf_token')) {
    formData.append('csrf_token', CSRF_TOKEN);
  }

  const res = await fetch(url, {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });

  let data;
  try {
    data = await res.json();
  } catch (e) {
    notify('error', 'Unexpected server response. Please try again.');
    throw e;
  }

  if (!res.ok || data.success === false) {
    notify('error', data.message || 'Something went wrong.');
  }
  return data;
}

function confirmAction(title, text, confirmText = 'Yes, proceed') {
  return Swal.fire({
    title, text,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#2f80ed',
    cancelButtonColor: '#8a93a3',
    confirmButtonText: confirmText,
  }).then((r) => r.isConfirmed);
}

/**
 * Confirmation dialog that also collects a required free-text reason.
 * Resolves to the trimmed text, or null if the user cancelled — so callers
 * check `=== null` rather than truthiness (an empty string never gets past
 * the validator anyway).
 */
async function confirmWithRemarks({
  title,
  text = '',
  confirmText = 'Yes, proceed',
  label,
  placeholder = '',
  help = '',
  maxLength = 500,
}) {
  const res = await Swal.fire({
    title,
    icon: 'warning',
    html: `
      ${text ? `<div class="swal-remarks__intro">${escapeHtml(text)}</div>` : ''}
      <div class="swal-remarks">
        <label class="swal-remarks__label" for="swalRemarks">
          ${escapeHtml(label)} <span class="swal-remarks__req">*</span>
        </label>
        <textarea id="swalRemarks" class="swal-remarks__input" rows="3"
                  maxlength="${maxLength}" placeholder="${escapeHtml(placeholder)}"></textarea>
        <div class="swal-remarks__foot">
          <span class="swal-remarks__help">${escapeHtml(help)}</span>
          <span class="swal-remarks__count"><span id="swalRemarksCount">0</span>/${maxLength}</span>
        </div>
      </div>
    `,
    showCancelButton: true,
    confirmButtonColor: '#2f80ed',
    cancelButtonColor: '#8a93a3',
    confirmButtonText: confirmText,
    focusConfirm: false,
    didOpen: () => {
      const ta = document.getElementById('swalRemarks');
      const counter = document.getElementById('swalRemarksCount');
      ta.addEventListener('input', () => { counter.textContent = ta.value.length; });
      ta.focus();
    },
    preConfirm: () => {
      const value = document.getElementById('swalRemarks').value.trim();
      if (!value) {
        Swal.showValidationMessage('Please enter the conclusion remarks.');
        return false;
      }
      return value;
    },
  });

  return res.isConfirmed ? res.value : null;
}

/* ---------------- Sidebar toggle ---------------- */
document.addEventListener('DOMContentLoaded', () => {
  const body = document.body;

  const desktopToggle = document.getElementById('sidebarToggleDesktop');
  if (desktopToggle) {
    if (localStorage.getItem('sidebarCollapsed') === '1') {
      body.classList.add('sidebar-collapsed');
    }
    desktopToggle.addEventListener('click', () => {
      body.classList.toggle('sidebar-collapsed');
      localStorage.setItem('sidebarCollapsed', body.classList.contains('sidebar-collapsed') ? '1' : '0');
    });
  }

  const mobileToggle = document.getElementById('sidebarToggleMobile');
  const backdrop = document.getElementById('sidebarBackdrop');
  if (mobileToggle) {
    mobileToggle.addEventListener('click', () => body.classList.toggle('sidebar-mobile-open'));
  }
  if (backdrop) {
    backdrop.addEventListener('click', () => body.classList.remove('sidebar-mobile-open'));
  }

  // Flash message -> toast (set by PHP after redirects)
  const flashType = body.dataset.flashType;
  const flashMessage = body.dataset.flashMessage;
  if (flashType && flashMessage) {
    notify(flashType, flashMessage);
  }

  // Notification bell — mark all as read once the dropdown is opened.
  const notifDropdown = document.getElementById('notifDropdown');
  if (notifDropdown) {
    notifDropdown.addEventListener('shown.bs.dropdown', () => {
      const badge = document.getElementById('notifBadge');
      if (!badge) return; // already all read
      apiPost('ajax/notifications_read.php', {}).then((data) => {
        if (data.success) {
          badge.remove();
          notifDropdown.querySelectorAll('.notif-item.unread').forEach((el) => el.classList.remove('unread'));
        }
      });
    }, { once: true });
  }
});
