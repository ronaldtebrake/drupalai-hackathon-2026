// charts.js

(function (Chart, settings) {
  var chartColors = ["#32926255", "#5574a655", "#b7732255", "#16d62055", '#24356755', '#77445355'];
  var labelColor = '#000000';
  var valueColor = '#606060'

  Drupal.behaviors.chartBehavior = {
    attach: function (context, settings) {
      if (!settings.charts) return;
      Object.keys(settings.charts).forEach(function (key) {
        var props = settings.charts[key];
        // Dummy simple chart creation for charts.js usage.
        var ctx = props.canvas;
        if (!ctx) return;
        new Chart(ctx, {
          type: props.type || 'bar',
          data: {
            labels: props.labels || [],
            datasets: [{
              data: props.values || [],
              backgroundColor: props.backgroundColor || chartColors,
            }]
          },
          options: props.options || {}
        });
      });
    }
  };
})(Chart, drupalSettings);
