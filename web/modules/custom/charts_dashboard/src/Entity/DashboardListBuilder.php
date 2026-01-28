<?php

namespace Drupal\charts_dashboard\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Link;

class DashboardListBuilder extends ConfigEntityListBuilder {
  public function buildHeader() {
    $header['label'] = $this->t('Label');
    $header['chart_type'] = $this->t('Chart type');
    return $header + parent::buildHeader();
  }

  public function buildRow($entity) {
    $row['label'] = $entity->label();
    $row['chart_type'] = $entity->getChartType();
    return $row + parent::buildRow($entity);
  }
}
