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
            // Previously 430px; reduce by 25px to 405px.
            canvas.style.height = (payload.height || '405px');
          }

          // Prepare datasets: prefer payload.datasets (multi-series) otherwise single dataset from payload.values.
          var datasets = [];
          if (payload.datasets && Array.isArray(payload.datasets)) {
            datasets = payload.datasets.map(function (ds) {
              // Ensure Chart.js expects 'data' property not 'values'. If caller used 'data' keep it.
              var d = Object.assign({}, ds);
              if (d.values && !d.data) {
                d.data = d.values;
                delete d.values;
              }
              return d;
            });
          }
          else if (payload.values && Array.isArray(payload.values)) {
            datasets = [{
              label: payload.label || 'Dataset',
              data: payload.values,
              backgroundColor: payload.backgroundColor || 'rgba(54, 162, 235, 0.5)',
              borderColor: payload.borderColor || 'rgba(54, 162, 235, 1)',
              borderWidth: 1
            }];
          }

          // Build Chart config
          var chartConfig = {
            type: payload.chart_type || 'bar',
            data: {
              labels: payload.labels || [],
              datasets: datasets
            },
            options: payload.options || {
              responsive: true,
              maintainAspectRatio: true,
            }
          };

          // Create and store the Chart instance directly.
          window.chartsDashboardDemoCharts[id] = new Chart(ctx, chartConfig);
        }
        catch (err) {
          console.error('Chart render error for', id, err);
        }
      });
    }
  };
})(Drupal, drupalSettings);
