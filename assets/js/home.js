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
  renderStatusTrendChart();
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
      plugins: { legend: { display: false } },
      cutout: '65%',
    },
  });
}

function renderStatusTrendChart() {
  const el = document.getElementById('statusTrendChart');
  if (!el || typeof Chart === 'undefined') return;

  // Only plot statuses that actually occurred in the window — a flat zero
  // line for a status that never happened just adds legend noise.
  const datasets = Object.keys(STATUS_TREND_SERIES)
    .filter((status) => STATUS_TREND_SERIES[status].some((n) => n > 0))
    .map((status) => ({
      label: status,
      data: STATUS_TREND_SERIES[status],
      borderColor: STATUS_TREND_COLORS[status],
      backgroundColor: STATUS_TREND_COLORS[status],
      pointBackgroundColor: STATUS_TREND_COLORS[status],
      pointRadius: 3,
      tension: 0.35,
    }));

  new Chart(el, {
    type: 'line',
    data: { labels: STATUS_TREND_LABELS, datasets },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } } },
      scales: {
        y: { beginAtZero: true, ticks: { precision: 0 } },
        x: { grid: { display: false } },
      },
    },
  });
}

function startScanner() {
  const frame = document.getElementById('scannerFrame');
  const idleOverlay = document.getElementById('scannerOverlayIdle');
  const errorOverlay = document.getElementById('scannerOverlayError');

  idleOverlay.classList.add('d-none');
  errorOverlay.classList.add('d-none');
  frame.classList.add('scanning');

  html5QrCode = new Html5Qrcode('scannerViewport');

  const config = {
    fps: 10,
    qrbox: { width: 260, height: 180 },
    experimentalFeatures: { useBarCodeDetectorIfSupported: true },
  };

  html5QrCode.start({ facingMode: 'environment' }, config, onScanSuccess, () => {
    // Called continuously while no code is found in the current frame — ignore.
  }).catch((err) => showScannerError(err));
}

function onScanSuccess(decodedText) {
  if (html5QrCode) {
    html5QrCode.stop().then(() => html5QrCode.clear()).catch(() => {});
  }
  document.getElementById('scannerFrame').classList.remove('scanning');
  notify('success', 'Barcode scanned — looking up document…');
  lookupDocument(decodedText);
}

function showScannerError(err) {
  const frame = document.getElementById('scannerFrame');
  const errorOverlay = document.getElementById('scannerOverlayError');
  const errorText = document.getElementById('scannerErrorText');

  frame.classList.remove('scanning');
  errorOverlay.classList.remove('d-none');

  const message = String(err || '');
  if (message.toLowerCase().includes('permission') || message.toLowerCase().includes('notallowed')) {
    errorText.textContent = 'Camera permission denied. Please allow camera access.';
  } else if (message.toLowerCase().includes('notfound')) {
    errorText.textContent = 'No camera was found on this device.';
  } else {
    errorText.textContent = 'Unable to access the camera. Please try again.';
  }
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
