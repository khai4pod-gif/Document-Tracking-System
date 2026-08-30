/**
 * assets/js/relief_reports.js
 * Powers relief_reports.php: the filter bar, the four report views, and the
 * CSV / Excel / print output. One filter set drives every view — switching
 * view re-renders the same filtered result, grouped differently.
 */

let reportTable = null;
let currentView = 'distributions';

/** Column definitions per view: label, row key, and whether it is numeric. */
const REPORT_COLUMNS = {
  distributions: [
    { label: 'Reference', key: 'reference_no', mono: true },
    { label: 'Date', key: 'distribution_date', date: true },
    { label: 'Centre', key: 'center_name' },
    { label: 'Target area', key: 'target_area' },
    { label: 'Status', key: 'status', badge: true },
    { label: 'Lines', key: 'line_items', num: true },
    { label: 'Units', key: 'units', num: true, total: true },
    { label: 'Beneficiaries', key: 'total_beneficiaries', num: true, total: true },
    { label: 'Released by', key: 'distributed_by_name' },
  ],
  goods: [
    { label: 'Reference', key: 'reference_no', mono: true },
    { label: 'Date', key: 'distribution_date', date: true },
    { label: 'Centre', key: 'center_name' },
    { label: 'Item', key: 'item_name' },
    { label: 'Category', key: 'category' },
    { label: 'Quantity', key: 'quantity', num: true, total: true },
    { label: 'Unit', key: 'unit' },
    { label: 'Status', key: 'status', badge: true },
  ],
  centres: [
    { label: 'Centre', key: 'center_name' },
    { label: 'Target area', key: 'target_area' },
    { label: 'Capacity', key: 'capacity', num: true },
    { label: 'Events', key: 'events', num: true, total: true },
    { label: 'Units received', key: 'units', num: true, total: true },
    { label: 'Categories', key: 'categories', num: true },
    { label: 'Beneficiaries', key: 'beneficiaries', num: true, total: true },
  ],
  trend: [
    { label: 'Period', key: 'bucket' },
    { label: 'Events', key: 'events', num: true, total: true },
    { label: 'Centres served', key: 'centres', num: true },
    { label: 'Units released', key: 'units', num: true, total: true },
  ],
  // Opening + In − Out = Closing, by construction. "Released" is the net of
  // goods that actually left, shown alongside rather than in place of Out,
  // which also carries adjustments and write-offs.
  movement: [
    { label: 'Item', key: 'item_name' },
    { label: 'Category', key: 'category' },
    { label: 'Unit', key: 'unit' },
    { label: 'Opening', key: 'opening', num: true, total: true },
    { label: 'Stock in', key: 'moved_in', num: true, total: true },
    { label: 'Stock out', key: 'moved_out', num: true, total: true },
    { label: 'Closing', key: 'closing', num: true, total: true },
    { label: 'of which released', key: 'released', num: true, total: true },
    { label: 'Movements', key: 'movements', num: true, total: true },
  ],
};

const DIST_STATUS_BADGE = {
  Draft: 'bg-secondary',
  'Pending Approval': 'bg-warning text-dark',
  Approved: 'bg-info text-dark',
  Completed: 'bg-success',
  Cancelled: 'bg-danger',
};

document.addEventListener('DOMContentLoaded', () => {
  const filterIds = ['fPreset', 'fDateFrom', 'fDateTo', 'fGranularity',
                     'fCentre', 'fArea', 'fCategory', 'fItem', 'fStatus'];

  filterIds.forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => { syncCustomRange(); loadReport(); });
  });

  // Choosing a category narrows the item list, so the two can't contradict.
  document.getElementById('fCategory').addEventListener('change', filterItemsByCategory);

  document.getElementById('reportViews').addEventListener('click', (e) => {
    const btn = e.target.closest('.perf-tab');
    if (!btn) return;
    document.querySelectorAll('#reportViews .perf-tab').forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
    currentView = btn.dataset.view;
    loadReport();
  });

  document.getElementById('btnResetFilters').addEventListener('click', () => {
    document.getElementById('fPreset').value = 'month';
    document.getElementById('fGranularity').value = 'month';
    ['fDateFrom', 'fDateTo', 'fCentre', 'fArea', 'fCategory', 'fItem', 'fStatus']
      .forEach((id) => { document.getElementById(id).value = ''; });
    filterItemsByCategory();
    syncCustomRange();
    loadReport();
  });

  document.getElementById('btnPrintReport').addEventListener('click', () => window.print());

  syncCustomRange();
  loadReport();
});

/** The custom date inputs only make sense for the custom preset. */
function syncCustomRange() {
  const isCustom = document.getElementById('fPreset').value === 'custom';
  document.getElementById('customRangeWrap').classList.toggle('d-none', !isCustom);
}

function filterItemsByCategory() {
  const category = document.getElementById('fCategory').value;
  const items = document.getElementById('fItem');
  let selectionStillValid = items.value === '';

  Array.from(items.options).forEach((opt) => {
    if (!opt.value) return;
    const show = !category || opt.dataset.category === category;
    opt.hidden = !show;
    if (show && opt.value === items.value) selectionStillValid = true;
  });

  if (!selectionStillValid) items.value = '';
}

function reportQuery() {
  const val = (id) => document.getElementById(id).value;
  return new URLSearchParams({
    view: currentView,
    preset: val('fPreset'),
    date_from: val('fDateFrom'),
    date_to: val('fDateTo'),
    granularity: val('fGranularity'),
    center_id: val('fCentre'),
    target_area: val('fArea'),
    category: val('fCategory'),
    inventory_id: val('fItem'),
    status: val('fStatus'),
  }).toString();
}

function loadReport() {
  fetch('ajax/relief_report.php?' + reportQuery())
    .then((r) => r.json())
    .then((res) => {
      if (!res.success) return;
      renderSummary(res.summary);
      renderScope(res.applied);
      renderTable(res.data);
    })
    .catch(() => notify('error', 'Could not load the report.'));
}

function renderSummary(s) {
  document.getElementById('sumEvents').textContent = Number(s.events).toLocaleString();
  document.getElementById('sumUnits').textContent = Number(s.units).toLocaleString();
  document.getElementById('sumBeneficiaries').textContent = Number(s.beneficiaries).toLocaleString();
  document.getElementById('sumCentres').textContent = Number(s.centres).toLocaleString();
}

/** Restates the active filters, so a printed page explains itself. */
function renderScope(applied) {
  const text = (id) => {
    const el = document.getElementById(id);
    return el.value ? el.options[el.selectedIndex].textContent.trim() : null;
  };

  const parts = [];
  parts.push(applied.date_from && applied.date_to
    ? applied.date_from + ' to ' + applied.date_to
    : 'All time');

  parts.push(text('fCentre') || 'all centres');
  const extras = [text('fArea'), text('fCategory'), text('fItem'), text('fStatus')].filter(Boolean);
  if (extras.length) parts.push(extras.join(' · '));

  document.getElementById('reportScope').textContent = parts.join(' · ');
}

function renderTable(rows) {
  const columns = REPORT_COLUMNS[currentView];
  const empty = document.getElementById('reportEmpty');
  const wrap = document.getElementById('reportTableWrap');

  empty.classList.toggle('d-none', rows.length > 0);
  wrap.classList.toggle('d-none', rows.length === 0);

  // DataTables can't change its column set, so rebuild on every view switch.
  if (reportTable) { reportTable.destroy(); reportTable = null; }

  const head = columns.map((c) =>
    `<th class="${c.num ? 'num-cell' : ''}">${escapeHtml(c.label)}</th>`).join('');
  document.querySelector('#reportTable thead tr').innerHTML = head;

  const body = rows.map((row) => '<tr>' + columns.map((c) => {
    const raw = row[c.key];
    if (c.num) return `<td class="num-cell">${Number(raw || 0).toLocaleString()}</td>`;
    if (c.badge) {
      return `<td><span class="badge ${DIST_STATUS_BADGE[raw] || 'bg-secondary'}">${escapeHtml(raw)}</span></td>`;
    }
    if (c.date) return `<td>${escapeHtml(formatReportDate(raw))}</td>`;
    if (c.mono) return `<td><span class="tracking-chip">${escapeHtml(raw)}</span></td>`;
    return `<td>${escapeHtml(raw === null || raw === undefined ? '—' : String(raw))}</td>`;
  }).join('') + '</tr>').join('');
  document.querySelector('#reportTable tbody').innerHTML = body;

  const foot = columns.map((c, i) => {
    if (i === 0) return `<td>${rows.length} row${rows.length === 1 ? '' : 's'}</td>`;
    if (!c.total) return '<td></td>';
    const sum = rows.reduce((acc, r) => acc + Number(r[c.key] || 0), 0);
    return `<td class="num-cell">${sum.toLocaleString()}</td>`;
  }).join('');
  document.querySelector('#reportTable tfoot tr').innerHTML = foot;

  if (rows.length === 0) return;

  reportTable = $('#reportTable').DataTable({
    paging: rows.length > 25,
    pageLength: 25,
    searching: true,
    info: true,
    order: [],
    dom: "<'d-flex justify-content-between align-items-center mb-2'fB>rt<'d-flex justify-content-between align-items-center mt-2'ip>",
    buttons: [
      { extend: 'csv', title: reportFileName() },
      { extend: 'excel', title: reportFileName() },
    ],
  });
}

function reportFileName() {
  const scope = document.getElementById('reportScope').textContent.replace(/[^\w]+/g, '-');
  return 'relief-' + currentView + '-' + scope;
}

/** "2026-08-27" -> "27 Aug 2026"; week buckets and month buckets pass through. */
function formatReportDate(value) {
  if (!value) return '—';
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value));
  if (!m) return String(value);
  const names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  return Number(m[3]) + ' ' + names[Number(m[2]) - 1] + ' ' + m[1];
}
