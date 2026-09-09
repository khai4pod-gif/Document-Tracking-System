/**
 * assets/js/relief_dashboard.js
 * Renders the distribution trend line chart and the goods-by-category
 * doughnut chart using data injected by relief_dashboard.php.
 */

document.addEventListener('DOMContentLoaded', () => {
  const gridColor = '#eef1f5';

  // ---- Trend line chart ----
  const trendCtx = document.getElementById('trendChart');
  if (trendCtx) {
    new Chart(trendCtx, {
      type: 'line',
      data: {
        labels: TREND_LABELS.length ? TREND_LABELS : ['No data'],
        datasets: [{
          label: 'Beneficiaries Served',
          data: TREND_DATA.length ? TREND_DATA : [0],
          borderColor: '#2f80ed',
          backgroundColor: 'rgba(47,128,237,0.12)',
          tension: 0.35,
          fill: true,
          pointBackgroundColor: '#2f80ed',
          pointRadius: 4,
        }],
      },
      options: {
        responsive: true,
        // Draw once, in place. By default the line sweeps up from the axis on
        // every load and replays that sweep on each resize, so the panel never
        // settled while the window was being adjusted.
        animation: false,
        // Hovering a point re-ran a short animation of its own; without this the
        // line still twitched under the cursor.
        transitions: { active: { animation: { duration: 0 } } },
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: '#7c8698' } },
          x: { grid: { display: false }, ticks: { color: '#7c8698' } },
        },
      },
    });
  }

  // ---- Category doughnut chart ----
  const catCtx = document.getElementById('categoryChart');
  if (catCtx) {
    new Chart(catCtx, {
      type: 'doughnut',
      data: {
        labels: CATEGORY_LABELS.length ? CATEGORY_LABELS : ['No data'],
        datasets: [{
          data: CATEGORY_DATA.length ? CATEGORY_DATA : [1],
          // Shared with the list below the ring and the ranking chart, so one
          // category reads as one colour across the whole page.
          backgroundColor: (typeof CATEGORY_COLORS !== 'undefined' && CATEGORY_COLORS.length)
            ? CATEGORY_COLORS
            : ['#4361ee', '#f4a261', '#2a9d8f', '#e76f51', '#5b5fc7', '#e9c46a'],
          borderWidth: 2,
          borderColor: '#fff',
        }],
      },
      options: {
        responsive: true,
        // The container is a fixed square; let it drive the size rather than the
        // canvas attributes, so the ring stays centred in it.
        maintainAspectRatio: false,
        // Draw once, in place. The ring used to spin up from zero on load, which
        // also meant the total in the middle appeared before the ring around it.
        animation: false,
        transitions: { active: { animation: { duration: 0 } } },
        // No built-in legend. The list underneath already names every category
        // with its colour, share and unit count, and drawing a second legend
        // inside the canvas both duplicated that and stole space from the
        // bottom — which pushed the ring upward while the total sat centred on
        // the box, so the two overlapped.
        plugins: { legend: { display: false } },
        cutout: '65%',
      },
    });
  }
});
