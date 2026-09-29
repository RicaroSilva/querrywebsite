/* Dashboard: activity chart */
(function () {
  const el = document.getElementById('activity-chart');
  const dataEl = document.getElementById('activity-data');
  if (!el || !dataEl || !window.Chart) return;
  const data = JSON.parse(dataEl.textContent);
  const labels = Object.keys(data).map((d) => d.slice(8, 10) + '/' + d.slice(5, 7));
  let chart;
  const draw = () => {
    const css = getComputedStyle(document.documentElement);
    const c = (v) => css.getPropertyValue(v).trim();
    chart && chart.destroy();
    chart = new Chart(el, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'Sucesso', data: Object.values(data).map((d) => d.success), backgroundColor: c('--accent'), borderRadius: 4, maxBarThickness: 18 },
          { label: 'Erro', data: Object.values(data).map((d) => d.error), backgroundColor: c('--danger'), borderRadius: 4, maxBarThickness: 18 },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
        scales: {
          x: { stacked: true, grid: { display: false }, ticks: { color: c('--text-3'), font: { size: 10 } }, border: { display: false } },
          y: { stacked: true, beginAtZero: true, grid: { color: c('--border') }, ticks: { color: c('--text-3'), precision: 0, font: { size: 10 } }, border: { display: false } },
        },
      },
    });
  };
  draw();
  document.addEventListener('qd:theme', draw);
})();
