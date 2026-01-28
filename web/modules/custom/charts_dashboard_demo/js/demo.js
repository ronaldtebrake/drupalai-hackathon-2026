(function (Drupal, drupalSettings) {
  "use strict";

  // Store chart instances globally so we can destroy them if re-attaching behaviors.
  window.chartsDashboardDemoCharts = window.chartsDashboardDemoCharts || {};

  Drupal.behaviors.chartsDashboardDemo = {
    attach: function (context, settings) {
      // Reference settings to avoid unused parameter warnings from static analyzers.
      void(settings);

      var cfgs = drupalSettings.charts_dashboard_demo || {};

      Object.keys(cfgs).forEach(function (id) {
        var payload = cfgs[id];
        var sel = '#charts-demo-' + id;
        var canvas = (context.querySelector && context.querySelector(sel)) || (context.matches && context.matches(sel) ? context : null);
        if (!canvas) return;

        // If a Chart instance for this id already exists, destroy it before creating a new one.
        try {
          var existing = window.chartsDashboardDemoCharts[id];
          if (existing && typeof existing.destroy === 'function') {
            existing.destroy();
          }
        }
        catch (e) {
          // Ignore destroy errors and continue.
        }

        try {
          var ctx = canvas.getContext('2d');
          // Ensure canvas has an explicit pixel height to avoid ResizeObserver loops.
          if (!canvas.style.height) {
            canvas.style.height = (payload.height || '180px');
          }

          // Create and store the Chart instance directly to avoid redundant locals.
          window.chartsDashboardDemoCharts[id] = new Chart(ctx, {
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
              // Maintain aspect ratio true is safer when we provide an explicit
              // canvas height via CSS or style to avoid infinite resize loops.
              maintainAspectRatio: true,
              plugins: { legend: { display: true } }
            }
          });
        }
        catch (err) {
          console.error('Chart render error for', id, err);
        }
      });
    }
  };
})(Drupal, drupalSettings);
