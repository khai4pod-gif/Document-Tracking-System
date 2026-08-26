/**
 * assets/js/home.js
 * Powers home.php: camera-based barcode/QR scanning (via html5-qrcode)
 * and the manual tracking-number/title quick search, both resolving
 * through ajax/document_lookup.php.
 */

let html5QrCode = null;
let resultsModal = null;

const STATUS_BADGE = {
  Completed: 'bg-success', Overdue: 'bg-danger', 'In Transit': 'bg-primary',
  'Pending Routing': 'bg-warning text-dark', Received: 'bg-info text-dark', Draft: 'bg-secondary',
};

document.addEventListener('DOMContentLoaded', () => {
  resultsModal = new bootstrap.Modal(document.getElementById('searchResultsModal'));

  document.getElementById('btnStartScan').addEventListener('click', startScanner);
  document.getElementById('btnRetryScan').addEventListener('click', startScanner);

  document.getElementById('btnQuickSearch').addEventListener('click', runQuickSearch);
  document.getElementById('quickSearchInput').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); runQuickSearch(); }
  });

  renderStatusReportChart();
  initPerformancePanel();
  initOfficePanel();
});

function renderStatusReportChart() {
  const el = document.getElementById('statusReportChart');
  if (!el || typeof Chart === 'undefined') return;

  new Chart(el, {
    type: 'doughnut',
    data: {
      labels: STATUS_LABELS,
      datasets: [{
        data: STATUS_DATA,
        backgroundColor: STATUS_COLORS,
        borderWidth: 2,
        borderColor: '#fff',
      }],
    },
    options: {
      responsive: true,
      // A doughnut defaults to aspectRatio 1, which makes it as tall as the
      // card is wide. The wrapper sets the height instead.
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      cutout: '65%',
    },
  });
}

/* ---------------- Individual Performance panel ---------------- */

let complianceChart = null;

const PERF_PERIOD_LABELS = {
  as_of_today: 'As of Today',
  today: 'Today',
  week: 'This Week',
  month: 'This Month',
  quarter: 'This Quarter',
  year: 'This Year',
};

function initPerformancePanel() {
  const tabs = document.getElementById('perfTabs');
  if (!tabs) return;

  tabs.addEventListener('click', (e) => {
    const btn = e.target.closest('.perf-tab');
    if (!btn) return;
    tabs.querySelectorAll('.perf-tab').forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
    loadPerformanceSummary(btn.dataset.period);
  });

  loadPerformanceSummary('today');
}

function loadPerformanceSummary(period) {
  fetch('ajax/performance_summary.php?period=' + encodeURIComponent(period))
    .then((r) => r.json())
    .then((res) => {
      if (!res.success) return;
      renderPerformanceSummary(res.summary);
      const subtitle = document.getElementById('perfSubtitle');
      if (subtitle) subtitle.textContent = PERF_PERIOD_LABELS[res.period] || 'Today';
    })
    .catch(() => notify('error', 'Could not load performance data.'));
}

function renderPerformanceSummary(s) {
  document.getElementById('perfAssignedTotal').textContent = s.assigned.total;
  document.getElementById('perfForAction').textContent = s.assigned.for_action;
  document.getElementById('perfPendingReceipt').textContent = s.assigned.pending_receipt;

  document.getElementById('perfActiveTotal').textContent = s.active.total;
  document.getElementById('perfBacklog').textContent = s.active.backlog;
  document.getElementById('perfDueToday').textContent = s.active.due_today;
  document.getElementById('perfDue3Days').textContent = s.active.due_3days;
  document.getElementById('perfNoDeadline').textContent = s.active.no_deadline;

  document.getElementById('perfResolvedTotal').textContent = s.resolved.total;
  document.getElementById('perfCompliant').textContent = s.resolved.compliant;
  document.getElementById('perfNonCompliant').textContent = s.resolved.non_compliant;
  document.getElementById('perfExempt').textContent = s.resolved.exempt;

  renderComplianceGauge(s.compliance_rate);
}

function renderComplianceGauge(rate) {
  const el = document.getElementById('complianceGauge');
  const pctEl = document.getElementById('perfCompliancePct');
  const tagEl = document.getElementById('perfComplianceTag');
  if (!el || typeof Chart === 'undefined') return;

  const value = rate === null ? 0 : rate;
  const color = rate === null ? '#c3c9d4' : rate >= 90 ? '#1e9e6b' : rate >= 70 ? '#f2994a' : '#e0473f';
  const tag = rate === null ? 'No Data' : rate >= 90 ? 'Excellent' : rate >= 70 ? 'Fair' : 'Needs Attention';

  pctEl.textContent = rate === null ? '—' : rate + '%';
  pctEl.style.color = color;
  tagEl.textContent = tag;
  tagEl.style.color = color;

  if (complianceChart) complianceChart.destroy();
  complianceChart = new Chart(el, {
    type: 'doughnut',
    data: {
      datasets: [{
        data: [value, 100 - value],
        backgroundColor: [color, '#eef1f5'],
        borderWidth: 0,
      }],
    },
    options: {
      responsive: true,
      cutout: '75%',
      plugins: { legend: { display: false }, tooltip: { enabled: false } },
    },
  });
}

/* ---------------- Office document tracking ---------------- */

let officeGaugeChart = null;
let officeTrendChart = null;

/** Shared with the individual gauge: same thresholds, same wording. */
function complianceStyle(rate) {
  if (rate === null) return { color: '#c3c9d4', tag: 'No Data' };
  if (rate >= 90) return { color: '#1e9e6b', tag: 'Excellent' };
  if (rate >= 70) return { color: '#f2994a', tag: 'Fair' };
  return { color: '#e0473f', tag: 'Needs Attention' };
}

function initOfficePanel() {
  const tabs = document.getElementById('officeTabs');
  if (!tabs) return;

  tabs.addEventListener('click', (e) => {
    const btn = e.target.closest('.perf-tab');
    if (!btn) return;
    tabs.querySelectorAll('.perf-tab').forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
    loadOfficeSummary(btn.dataset.period);
  });

  loadOfficeSummary('today');
}

function loadOfficeSummary(period) {
  fetch('ajax/office_summary.php?period=' + encodeURIComponent(period))
    .then((r) => r.json())
    .then((res) => {
      if (!res.success) return;
      renderOfficeSummary(res.summary);
      renderOfficeTrend(res.trend);
      const subtitle = document.getElementById('officeSubtitle');
      if (subtitle) subtitle.textContent = PERF_PERIOD_LABELS[res.period] || 'Today';
    })
    .catch(() => notify('error', 'Could not load office analytics.'));
}

function renderOfficeSummary(s) {
  const set = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  };

  set('offTotal', s.total.total);
  set('offInternal', s.total.created_internally);
  set('offRoutedIn', s.total.routed_in);
  set('offTotalExempt', s.total.exempt);

  set('offDeferred', s.deferred);
  set('offForReceipt', s.for_receipt);

  set('offResolved', s.resolved.total);
  set('offCompliant', s.resolved.compliant);
  set('offNonCompliant', s.resolved.non_compliant);
  set('offResolvedExempt', s.resolved.exempt);

  set('offActive', s.active.total);
  set('offBacklog', s.active.backlog);
  set('offDueToday', s.active.due_today);
  set('offDue3Days', s.active.due_3days);
  set('offNoDeadline', s.active.no_deadline);

  set('offExemptNote', s.resolved.exempt + ' exempt excluded');

  renderOfficeGauge(s.compliance_rate);
}

function renderOfficeGauge(rate) {
  const el = document.getElementById('officeComplianceGauge');
  const pctEl = document.getElementById('offCompliancePct');
  const tagEl = document.getElementById('offComplianceTag');
  if (!el || typeof Chart === 'undefined') return;

  const { color, tag } = complianceStyle(rate);
  const value = rate === null ? 0 : rate;

  pctEl.textContent = rate === null ? '—' : rate + '%';
  pctEl.style.color = color;
  tagEl.textContent = tag;
  tagEl.style.color = color;

  if (officeGaugeChart) officeGaugeChart.destroy();
  officeGaugeChart = new Chart(el, {
    type: 'doughnut',
    data: {
      datasets: [{
        data: [value, 100 - value],
        backgroundColor: [color, '#eef1f5'],
        borderWidth: 0,
      }],
    },
    options: {
      responsive: true,
      cutout: '75%',
      plugins: { legend: { display: false }, tooltip: { enabled: false } },
    },
  });
}

function renderOfficeTrend(trend) {
  const el = document.getElementById('officeComplianceTrend');
  const empty = document.getElementById('offTrendEmpty');
  if (!el || typeof Chart === 'undefined') return;

  const labels = (trend && trend.labels) || [];
  const rates = (trend && trend.rates) || [];
  const hasData = rates.some((r) => r !== null);

  const wrap = document.getElementById('offTrendWrap');
  if (empty) empty.classList.toggle('d-none', hasData);
  if (wrap) wrap.classList.toggle('d-none', !hasData);
  if (!hasData) {
    if (officeTrendChart) { officeTrendChart.destroy(); officeTrendChart = null; }
    return;
  }

  if (officeTrendChart) officeTrendChart.destroy();
  officeTrendChart = new Chart(el, {
    type: 'line',
    data: {
      labels: labels.map(monthLabel),
      datasets: [{
        label: 'Compliance rate',
        data: rates,
        borderColor: '#1e9e6b',
        backgroundColor: 'rgba(30,158,107,.12)',
        pointBackgroundColor: '#1e9e6b',
        pointRadius: 4,
        borderWidth: 2,
        fill: true,
        tension: 0.3,
        spanGaps: false,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (c) => c.parsed.y + '% compliant' } },
      },
      scales: {
        y: {
          beginAtZero: true,
          max: 100,
          ticks: { callback: (v) => v + '%' },
          grid: { color: '#eef1f5' },
        },
        x: { grid: { display: false } },
      },
    },
  });
}

/** "2026-08" -> "Aug 2026" */
function monthLabel(ym) {
  const [year, month] = String(ym).split('-');
  const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return (names[Number(month) - 1] || ym) + ' ' + year;
}

/**
 * Reasons the camera can never start, checked before we try. Browsers only
 * expose getUserMedia on a secure origin, so over plain http:// on a named
 * host the API is simply absent — retrying can't help, and the generic
 * "unable to access the camera" message sends people hunting for a
 * permission prompt that will never appear.
 *
 * Returns null when there's nothing blocking it up front.
 */
function cameraBlockedReason() {
  if (typeof Html5Qrcode === 'undefined') {
    return {
      text: 'The scanner could not load.',
      hint: 'Check the internet connection and reload the page — the scanner library is served from a CDN.',
      retry: true,
    };
  }
  if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    return {
      text: 'The browser blocks the camera on this connection.',
      hint: 'Cameras only work on a secure origin. Open this site over https://, or reach it as http://localhost/ instead of a .test address. Searching by tracking number below works either way.',
      retry: false,
    };
  }
  return null;
}

function startScanner() {
  const frame = document.getElementById('scannerFrame');
  const idleOverlay = document.getElementById('scannerOverlayIdle');
  const errorOverlay = document.getElementById('scannerOverlayError');

  const blocked = cameraBlockedReason();
  if (blocked) {
    idleOverlay.classList.add('d-none');
    showScannerError(null, blocked);
    return;
  }

  idleOverlay.classList.add('d-none');
  errorOverlay.classList.add('d-none');
  frame.classList.add('scanning');

  html5QrCode = new Html5Qrcode('scannerViewport');
  tryStartScanner(0);
}

/**
 * Start configurations, best first.
 *
 * Devices disagree about what they'll accept: a webcam may reject a
 * resolution or a facing mode that a phone takes happily, and html5-qrcode
 * surfaces every such rejection as the same opaque failure. Rather than bet
 * on one config, walk down the list until one starts.
 */
function scannerAttempts() {
  const base = { fps: 10, qrbox: qrboxSize };

  // Only look for the format we actually print — scanning every 1D barcode
  // too wastes frames and invites misreads. Omitted entirely (not set to
  // undefined) when the library build doesn't expose the enum.
  if (typeof Html5QrcodeSupportedFormats !== 'undefined') {
    base.formatsToSupport = [Html5QrcodeSupportedFormats.QR_CODE];
  }

  const sharper = Object.assign({}, base, {
    // A sharper stream resolves printed modules that blur at the default
    // webcam resolution. Belongs in videoConstraints — the first argument
    // to start() is a camera identifier, not general media constraints.
    videoConstraints: {
      facingMode: 'environment',
      width: { ideal: 1280 },
      height: { ideal: 720 },
    },
  });

  return [
    { camera: { facingMode: 'environment' }, config: sharper },
    { camera: { facingMode: 'environment' }, config: base },
    { camera: { facingMode: 'user' }, config: base },
    // Plainest form the library accepts anywhere.
    { camera: { facingMode: 'environment' }, config: { fps: 10, qrbox: 250 } },
  ];
}

function tryStartScanner(index) {
  const attempts = scannerAttempts();
  if (index >= attempts.length) {
    showScannerError('camera start failed');
    return;
  }

  const { camera, config } = attempts[index];
  html5QrCode.start(camera, config, onScanSuccess, () => {
    // Called continuously while no code is found in the current frame — ignore.
  }).catch((err) => {
    // A refused permission won't be fixed by different constraints, so stop.
    const message = String(err || '').toLowerCase();
    if (message.includes('permission') || message.includes('notallowed')) {
      showScannerError(err);
      return;
    }
    tryStartScanner(index + 1);
  });
}

/** Square scan box covering most of the viewfinder, whatever its size. */
function qrboxSize(viewfinderWidth, viewfinderHeight) {
  const smaller = Math.min(viewfinderWidth, viewfinderHeight);
  const size = Math.max(180, Math.floor(smaller * 0.8));
  return { width: size, height: size };
}

function onScanSuccess(decodedText) {
  if (html5QrCode) {
    html5QrCode.stop().then(() => html5QrCode.clear()).catch(() => {});
  }
  document.getElementById('scannerFrame').classList.remove('scanning');
  notify('success', 'Barcode scanned — looking up document…');
  lookupDocument(decodedText);
}

function showScannerError(err, preset) {
  const frame = document.getElementById('scannerFrame');
  const errorOverlay = document.getElementById('scannerOverlayError');
  const errorText = document.getElementById('scannerErrorText');
  const errorHint = document.getElementById('scannerErrorHint');
  const retryBtn = document.getElementById('btnRetryScan');

  frame.classList.remove('scanning');
  errorOverlay.classList.remove('d-none');

  let info = preset;
  if (!info) {
    const message = String(err || '').toLowerCase();
    if (message.includes('permission') || message.includes('notallowed')) {
      info = {
        text: 'Camera permission denied.',
        hint: 'Allow camera access for this site in the browser address bar, then try again.',
        retry: true,
      };
    } else if (message.includes('notfound') || message.includes('notreadable')) {
      info = {
        text: 'No usable camera was found.',
        hint: 'Another app may be holding the camera. Close it and try again.',
        retry: true,
      };
    } else {
      info = { text: 'Unable to access the camera. Please try again.', hint: '', retry: true };
    }
  }

  errorText.textContent = info.text;
  if (errorHint) {
    errorHint.textContent = info.hint || '';
    errorHint.classList.toggle('d-none', !info.hint);
  }
  // Retrying can't fix an insecure origin, so don't offer it there.
  if (retryBtn) retryBtn.classList.toggle('d-none', !info.retry);
}

function runQuickSearch() {
  const value = document.getElementById('quickSearchInput').value.trim();
  if (!value) {
    notify('error', 'Enter a tracking number or title to search.');
    return;
  }
  lookupDocument(value);
}

function lookupDocument(query) {
  fetch('ajax/document_lookup.php?q=' + encodeURIComponent(query))
    .then((r) => r.json())
    .then((res) => {
      if (!res.success) {
        notify('error', res.message || 'No matching document found.');
        return;
      }
      if (!res.multiple) {
        window.location.href = 'document_view.php?id=' + res.id;
        return;
      }
      renderResultsModal(res.results);
    })
    .catch(() => notify('error', 'Something went wrong while searching. Please try again.'));
}

function renderResultsModal(results) {
  const body = document.getElementById('searchResultsBody');
  body.innerHTML = '<div class="list-group">' + results.map((r) => `
    <a href="document_view.php?id=${r.id}" class="list-group-item list-group-item-action">
      <div class="d-flex justify-content-between">
        <span class="tracking-chip">${escapeHtml(r.tracking_number)}</span>
        <span class="badge ${STATUS_BADGE[r.status] || 'bg-secondary'}">${escapeHtml(r.status)}</span>
      </div>
      <div class="mt-1 small">${escapeHtml(r.title)}</div>
    </a>`).join('') + '</div>';
  resultsModal.show();
}
