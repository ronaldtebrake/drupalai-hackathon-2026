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

    // Demo chart configurations - only the requested samples remain.
    $demo_configs = [
      // New charts requested by user:
      'issue_trends' => [
        'label' => $this->t('Issue Trends Over Time'),
        'description' => $this->t('Issues across categories over time (May-Dec).'),
        'chart_type' => 'line',
        // labels used by all datasets.
        'labels' => ['May', 'Jun', 'Jul', 'Aug', 'Sep', 'Dec'],
        // Provide datasets array for multiple series. Each dataset is a Chart.js dataset object.
        'datasets' => [
          [
            'label' => $this->t('Accessibility'),
            'data' => [10, 15, 30, 23, 48, 33],
            'borderColor' => 'rgba(75, 192, 192, 1)',
            'backgroundColor' => 'rgba(75, 192, 192, 0.15)',
            'tension' => 0.3,
          ],
          [
            'label' => $this->t('Editorial Style'),
            'data' => [30, 58, 29, 15, 47, 38],
            'borderColor' => 'rgba(255, 99, 132, 1)',
            'backgroundColor' => 'rgba(255, 99, 132, 0.15)',
            'tension' => 0.3,
          ],
          [
            'label' => $this->t('SEO & Discoverability'),
            'data' => [16, 24, 19, 31, 60, 55],
            'borderColor' => 'rgba(54, 162, 235, 1)',
            'backgroundColor' => 'rgba(54, 162, 235, 0.15)',
            'tension' => 0.3,
          ],
          [
            'label' => $this->t('Structure & AI'),
            'data' => [17, 27, 37, 19, 36, 27],
            'borderColor' => 'rgba(255, 205, 86, 1)',
            'backgroundColor' => 'rgba(255, 205, 86, 0.15)',
            'tension' => 0.3,
          ],
          [
            'label' => $this->t('Workflow'),
            'data' => [6, 18, 24, 29, 30, 41],
            'borderColor' => 'rgba(153, 102, 255, 1)',
            'backgroundColor' => 'rgba(153, 102, 255, 0.15)',
            'tension' => 0.3,
          ],
        ],
      ],

      'issues_by_category' => [
        'label' => $this->t('Issues by Category'),
        'description' => $this->t('Count of open issues per category.'),
        'chart_type' => 'bar',
        // Provide labels and a datasets entry so we can specify per-bar colors.
        'labels' => [
          $this->t('Accessibility'),
          $this->t('Editorial Style'),
          $this->t('SEO & Discoverability'),
          $this->t('Structure & IA'),
          $this->t('Accuracy'),
          $this->t('Workflow Issues'),
        ],
        'datasets' => [
          [
            'label' => $this->t('Open issues'),
            'data' => [75, 62, 48, 38, 25, 20],
            'backgroundColor' => [
              'rgba(76, 175, 80, 0.8)',    // Accessibility - green
              'rgba(33, 150, 243, 0.8)',   // Editorial Style - blue
              'rgba(155, 89, 182, 0.8)',   // SEO & Discoverability - purple
              'rgba(255, 152, 0, 0.8)',    // Structure & IA - orange
              'rgba(158, 158, 158, 0.8)',  // Accuracy - gray
              'rgba(0, 150, 136, 0.8)',    // Workflow Issues - teal
            ],
            'borderColor' => [
              'rgba(255,255,255,0.9)',
              'rgba(255,255,255,0.9)',
              'rgba(255,255,255,0.9)',
              'rgba(255,255,255,0.9)',
              'rgba(255,255,255,0.9)',
              'rgba(255,255,255,0.9)',
            ],
            'borderWidth' => 1,
          ],
        ],
      ],

      'comment_quality_pie' => [
        'label' => $this->t('Comment Quality'),
        'description' => $this->t('Distribution of comment quality.'),
        'chart_type' => 'pie',
        // Provide labels at the top level so the JS uses them with datasets.
        'labels' => [$this->t('Helpful'), $this->t('Minor'), $this->t('Needs Improvements')],
        // Use datasets with explicit per-slice colors for the pie chart.
        'datasets' => [
          [
            'label' => $this->t('Comments'),
            'data' => [45, 25, 35],
            'backgroundColor' => [
              'rgba(76, 175, 80, 0.85)',   // green - Helpful
              'rgba(255, 152, 0, 0.85)',   // orange - Minor
              'rgba(244, 67, 54, 0.85)',   // red - Needs Improvements
            ],
            'borderColor' => [
              'rgba(255,255,255,0.9)',
              'rgba(255,255,255,0.9)',
              'rgba(255,255,255,0.9)',
            ],
            'borderWidth' => 1,
          ],
        ],
      ],
    ];

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
      // Render card markup with canvas element.
      $card = [];
      $card['#type'] = 'container';
      $card['#attributes']['class'] = ['chart-card'];
      $card['title'] = ['#markup' => '<div class="chart-title">' . $cfg['label'] . '</div>'];
      $card['desc'] = ['#markup' => '<div class="chart-desc">' . $cfg['description'] . '</div>'];
      $card['canvas'] = [
        '#type' => 'html_tag',
        '#tag' => 'canvas',
        '#attributes' => ['id' => 'charts-demo-' . $id, 'class' => ['charts-demo-chart']],
      ];

      $grid['inner'][$id] = $card;

      // Provide inline settings for the JS so it can render without XHR.
      // If datasets key exists, include it; otherwise include single labels/values.
      if (!empty($cfg['datasets'])) {
        $build['#attached']['drupalSettings']['charts_dashboard_demo'][$id] = [
          'label' => $cfg['label'],
          'description' => $cfg['description'],
          'chart_type' => $cfg['chart_type'],
          'labels' => $cfg['labels'] ?? [],
          'datasets' => $cfg['datasets'],
          'options' => [
            'responsive' => TRUE,
            'maintainAspectRatio' => TRUE,
          ],
        ];
      }
      else {
        $build['#attached']['drupalSettings']['charts_dashboard_demo'][$id] = [
          'label' => $cfg['label'],
          'description' => $cfg['description'],
          'chart_type' => $cfg['chart_type'],
          'labels' => $cfg['data']['labels'] ?? [],
          'values' => $cfg['data']['values'] ?? [],
          'options' => $cfg['data']['options'] ?? ['responsive' => TRUE, 'maintainAspectRatio' => TRUE],
        ];
      }
    }

    $build['grid'] = $grid;

    // Minimal caching metadata — this is a purely client-side demo.
    $build['#cache'] = ['max-age' => 0];

    return $build;
  }
}
