(function (Drupal, drupalSettings) {
  "use strict";
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof drupalSettings.charts_dashboard === 'undefined') {
      return;
    }

    function renderChartOnCanvas(canvas, payload) {
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
              backgroundColor: payload.backgroundColor || 'rgba(54, 162, 235, 0.5)',
              borderColor: payload.borderColor || 'rgba(54, 162, 235, 1)',
              borderWidth: 1
            }]
          },
          options: payload.options || {
            responsive: true,
            maintainAspectRatio: false,
          }
        });
      }
      catch (e) {
        console.error('Chart render error:', e);
      }
    }

    Object.keys(drupalSettings.charts_dashboard).forEach(function (dashboardId) {
      var settings = drupalSettings.charts_dashboard[dashboardId];
      var sel = '#charts-chart-' + dashboardId;
      var canvas = document.querySelector(sel);
      if (!canvas) return;

      // If inline data is provided in settings (demo mode), use it directly.
      if (settings.inline && typeof settings.inline === 'object') {
        renderChartOnCanvas(canvas, settings.inline);
        return;
      }

      var dataUrl = settings.dataUrl;
      if (!dataUrl) return;

      fetch(dataUrl, { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (payload) {
          if (!payload) return;
          if (payload.error) {
            console.error('Charts Dashboard error:', payload.error);
            return;
          }
          renderChartOnCanvas(canvas, payload);
        })
        .catch(function (err) {
          console.error('Failed to load chart data:', err);
        });
    });
  });
})(Drupal, drupalSettings);
