<?php

namespace Drupal\charts_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;

class DashboardController extends ControllerBase {
  public function collection() {
    $build = [];
    $build['#type'] = 'markup';
    $build['#markup'] = $this->t('Use the list to manage dashboards.');
    return $build;
  }
}
