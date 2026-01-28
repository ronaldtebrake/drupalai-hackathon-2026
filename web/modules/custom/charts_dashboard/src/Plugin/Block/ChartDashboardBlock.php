<?php

namespace Drupal\charts_dashboard\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a 'Chart Dashboard' block.
 *
 * @Block(
 *   id = "charts_dashboard_block",
 *   admin_label = @Translation("Charts Dashboard (single chart)"),
 * )
 */
class ChartDashboardBlock extends BlockBase implements BlockPluginInterface {

  public function defaultConfiguration() {
    return [
      'dashboard_id' => 'sample',
    ] + parent::defaultConfiguration();
  }

  public function build() {
    $dashboard_id = $this->configuration['dashboard_id'] ?? 'sample';

    $build = [];
    $build['#theme'] = 'charts_dashboard_block';
    $build['#dashboard_id'] = $dashboard_id;
    $build['#attached']['library'][] = 'charts_dashboard/dashboard';
    $build['#attached']['drupalSettings']['charts_dashboard'][$dashboard_id] = [
      'dataUrl' => (function () use ($dashboard_id) {
        try {
          return Url::fromRoute('charts_dashboard.data', ['dashboard' => $dashboard_id])->toString();
        }
        catch (\Exception $e) {
          return Url::fromUserInput('/charts-dashboard/data/' . $dashboard_id)->toString();
        }
      })(),
    ];

    return $build;
  }

  public function blockForm($form, FormStateInterface $form_state) {
    $options = [];
    $storage = \Drupal::entityTypeManager()->getStorage('charts_dashboard_dashboard');
    if ($storage) {
      $items = $storage->loadMultiple();
      foreach ($items as $id => $entity) {
        $options[$id] = $entity->label();
      }
    }

    if (empty($options)) {
      $config_storage = \Drupal::service('config.storage');
      $list = $config_storage->listAll('charts_dashboard.dashboard');
      foreach ($list as $full) {
        $parts = explode('.', $full);
        $id = end($parts);
        $cfg = \Drupal::config($full);
        $options[$id] = $cfg->get('label') ?: $id;
      }
    }

    $form['dashboard_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Dashboard configuration'),
      '#options' => $options ?: ['sample' => $this->t('Sample')],
      '#default_value' => $this->configuration['dashboard_id'] ?? 'sample',
      '#description' => $this->t('Choose which dashboard config this block should render.'),
    ];

    return $form;
  }

  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['dashboard_id'] = $form_state->getValue('dashboard_id');
  }
}
