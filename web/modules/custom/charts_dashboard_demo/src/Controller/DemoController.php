<?php

namespace Drupal\charts_dashboard_demo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;

/**
 * Controller for the Charts Dashboard demo page.
 */
class DemoController extends ControllerBase {

  /**
   * Render the demo page.
   */
  public function demo() : array {
    $build = [];

    // Sample inline chart configurations.
    $demo_configs = [
      'demo_bar' => [
        'label' => $this->t('Monthly Sales'),
        'description' => $this->t('Sales per month (in thousands).'),
        'chart_type' => 'bar',
        'data' => [
          'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
          'values' => [12, 19, 3, 5, 2, 3],
        ],
      ],
      'demo_line' => [
        'label' => $this->t('Weekly Visitors'),
        'description' => $this->t('Unique visitors per weekday.'),
        'chart_type' => 'line',
        'data' => [
          'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
          'values' => [120, 150, 180, 170, 160, 200, 220],
        ],
      ],
      'demo_pie' => [
        'label' => $this->t('Market Share'),
        'description' => $this->t('Distribution by product.'),
        'chart_type' => 'pie',
        'data' => [
          'labels' => ['Product A', 'Product B', 'Product C'],
          'values' => [45, 25, 30],
        ],
      ],
    ];

    // Header with image if available.
    $module_handler = \Drupal::service('extension.list.module');
    $module_path = $module_handler->getPath('charts_dashboard_demo');
    $full_image_path = DRUPAL_ROOT . '/' . $module_path . '/Dashboard.png';
    if (file_exists($full_image_path)) {
      $img_url = '/' . $module_path . '/Dashboard.png';
      $header_markup = '<div class="charts-demo-header"><img src="' . $img_url . '" alt="Dashboard example" /></div>';
      $build['header'] = [
        '#type' => 'markup',
        '#markup' => Markup::create($header_markup),
      ];
    }
    else {
      $build['header'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('<h1>' . $this->t('Charts Dashboard Demo') . '</h1>'),
      ];
    }

    // Attach library.
    $build['#attached']['library'][] = 'charts_dashboard_demo/demo';

    // Provide drupalSettings payloads.
    $build['#attached']['drupalSettings']['charts_dashboard_demo'] = [];

    // Grid container.
    $grid = [
      '#type' => 'container',
      '#attributes' => ['class' => ['charts-demo-page']],
    ];
    $grid['inner'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['charts-demo-grid']],
    ];

    foreach ($demo_configs as $id => $cfg) {
      $card = [
        '#type' => 'container',
        '#attributes' => ['class' => ['chart-card']],
      ];

      $card['title'] = [
        '#type' => 'markup',
        '#markup' => '<div class="chart-title">' . $cfg['label'] . '</div>',
      ];
      $card['desc'] = [
        '#type' => 'markup',
        '#markup' => '<div class="chart-desc">' . $cfg['description'] . '</div>',
      ];
      $card['canvas'] = [
        '#type' => 'html_tag',
        '#tag' => 'canvas',
        '#attributes' => [
          'id' => 'charts-demo-' . $id,
          'class' => ['charts-demo-chart'],
          'role' => 'img',
          'aria-label' => $cfg['label'],
        ],
      ];

      $grid['inner'][$id] = $card;

      // Attach settings for JS.
      $build['#attached']['drupalSettings']['charts_dashboard_demo'][$id] = [
        'label' => $cfg['label'],
        'description' => $cfg['description'],
        'chart_type' => $cfg['chart_type'],
        'labels' => $cfg['data']['labels'],
        'values' => $cfg['data']['values'],
        'options' => [
          'responsive' => TRUE,
          // Use TRUE to avoid infinite resize loops when CSS provides height.
          'maintainAspectRatio' => TRUE,
        ],
      ];
    }

    $build['grid'] = $grid;

    // Minimal caching metadata — this is a purely client-side demo.
    $build['#cache'] = ['max-age' => 0];

    return $build;
  }
}
