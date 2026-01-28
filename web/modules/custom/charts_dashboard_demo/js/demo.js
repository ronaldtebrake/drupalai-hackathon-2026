(function (Drupal, drupalSettings) {
  "use strict";
  document.addEventListener('DOMContentLoaded', function () {
    var settings = drupalSettings.charts_dashboard_demo || {};
    Object.keys(settings).forEach(function (id) {
      var payload = settings[id];
      var sel = '#charts-demo-' + id;
      var canvas = document.querySelector(sel);
      if (!canvas || !payload) return;

      try {
        var ctx = canvas.getContext('2d');
        new Chart(ctx, {
          type: payload.chart_type || 'bar',
          data: {
            labels: payload.labels || [],
            datasets: [{
              label: payload.label || 'Dataset',
              data: payload.values || [],
              backgroundColor: payload.backgroundColor || [
                'rgba(54, 162, 235, 0.5)',
                'rgba(255, 99, 132, 0.5)',
                'rgba(255, 205, 86, 0.5)',
                'rgba(75, 192, 192, 0.5)'
              ],
              borderColor: payload.borderColor || 'rgba(54, 162, 235, 1)',
              borderWidth: 1
            }]
          },
          options: payload.options || {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true } }
          }
        });
      } catch (e) {
        console.error('Chart render error:', e);
      }
    });
  });
})(Drupal, drupalSettings);
