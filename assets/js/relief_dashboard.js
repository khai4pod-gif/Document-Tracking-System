/**
 * assets/js/relief_dashboard.js
 * Renders the distribution trend line chart and the goods-by-category
 * doughnut chart using data injected by relief_dashboard.php.
 */

document.addEventListener('DOMContentLoaded', () => {
  const navy = '#122a4d';
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
          backgroundColor: ['#2f80ed', '#f2994a', '#1e9e6b', '#e0473f', '#5b5fc7', '#8a93a3'],
          borderWidth: 2,
          borderColor: '#fff',
        }],
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { color: navy, boxWidth: 12, padding: 14 } } },
        cutout: '65%',
      },
    });
  }
});
