<?php
namespace Drupal\charts_dashboard\Controller;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;

class DemoController extends ControllerBase {
  public function demo() {
    $build = [];

    // Demo inline chart configurations.
    $demo_configs = [
      'demo_bar' => [
        'label' => $this->t('Monthly Sales'),
        'description' => $this->t('Sales per month (in thousands).'),
        'chart_type' => 'bar',
        'data' => [
          'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
          'values' => [12, 19, 3, 5, 2, 3],
        ],
        'source_type' => 'inline',
      ],
      'demo_line' => [
        'label' => $this->t('Weekly Visitors'),
        'description' => $this->t('Unique visitors per weekday.'),
        'chart_type' => 'line',
        'data' => [
          'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
          'values' => [120, 150, 180, 170, 160, 200, 220],
        ],
        'source_type' => 'inline',
      ],
      'demo_pie' => [
        'label' => $this->t('Market Share'),
        'description' => $this->t('Distribution by product.'),
        'chart_type' => 'pie',
        'data' => [
          'labels' => ['Product A', 'Product B', 'Product C'],
          'values' => [45, 25, 30],
        ],
        'source_type' => 'inline',
      ],
    ];

    // Build header.
    $build['header'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('<h1>' . $this->t('Charts Dashboard Demo') . '</h1><p>' . $this->t('A demonstration of Chart.js visualizations.') . '</p>'),
      '#weight' => -10,
    ];

    // Page wrapper and grid.
    $build['#attached']['library'][] = 'charts_dashboard/dashboard';
    $build['#attached']['drupalSettings']['charts_dashboard'] = [];

    $grid = ['#type' => 'container', '#attributes' => ['class' => ['charts-dashboard-page']]];
    $grid['inner'] = ['#type' => 'container', '#attributes' => ['class' => ['charts-dashboard-grid']]];

    foreach ($demo_configs as $id => $cfg) {
      // Render card markup with canvas element.
      $card = [];
      $card['#type'] = 'container';
      $card['#attributes']['class'] = ['chart-card'];
      $card['title'] = ['#markup' => '<div class="chart-title">' . $cfg['label'] . '</div>'];
      $card['desc'] = ['#markup' => '<div class="chart-desc">' . $cfg['description'] . '</div>'];
      $card['canvas'] = [
        '#type' => 'html_tag',
        '#tag' => 'canvas',
        '#attributes' => ['id' => 'charts-chart-' . $id, 'class' => ['charts-dashboard-chart']],
      ];

      $grid['inner'][$id] = $card;

      // Provide inline settings for the JS so it can render without XHR.
      $build['#attached']['drupalSettings']['charts_dashboard'][$id] = [
        'inline' => [
          'label' => $cfg['label'],
          'description' => $cfg['description'],
          'chart_type' => $cfg['chart_type'],
          'labels' => $cfg['data']['labels'],
          'values' => $cfg['data']['values'],
        ],
      ];
    }

    $build['grid'] = $grid;

    return $build;
  }
}
