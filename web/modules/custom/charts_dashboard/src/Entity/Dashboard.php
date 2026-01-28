<?php

namespace Drupal\charts_dashboard\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the dashboard configuration entity.
 *
 * @ConfigEntityType(
 *   id = "charts_dashboard_dashboard",
 *   label = @Translation("Charts Dashboard"),
 *   handlers = {
 *     "list_builder" = "Drupal\\charts_dashboard\\Entity\\DashboardListBuilder",
 *     "form" = {
 *       "add" = "Drupal\\charts_dashboard\\Form\\DashboardForm",
 *       "edit" = "Drupal\\charts_dashboard\\Form\\DashboardForm",
 *       "delete" = "Drupal\\charts_dashboard\\Form\\DashboardDeleteForm"
 *     }
 *   },
 *   config_prefix = "dashboard",
 *   admin_permission = "administer charts dashboards",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "chart_type",
 *     "data"
 *   },
 *   links = {
 *     "collection" = "/admin/config/system/charts-dashboard/manage",
 *     "add-form" = "/admin/config/system/charts-dashboard/manage/add",
 *     "edit-form" = "/admin/config/system/charts-dashboard/manage/{charts_dashboard_dashboard}/edit",
 *     "delete-form" = "/admin/config/system/charts-dashboard/manage/{charts_dashboard_dashboard}/delete"
 *   }
 * )
 */
class Dashboard extends ConfigEntityBase implements DashboardInterface {

  protected $id;
  protected $label;
  protected $description;
  protected $chart_type;
  protected $data = [];
  protected $source_type;
  protected $views_settings = [];
  protected $rest_settings = [];

  public function getLabel() {
    return $this->label;
  }

  public function getChartType() {
    return $this->get('chart_type') ?? $this->chart_type ?? 'bar';
  }

  public function getData() {
    return $this->data;
  }
}
