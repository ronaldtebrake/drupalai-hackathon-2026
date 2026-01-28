<?php

namespace Drupal\charts_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns chart data for dashboards.
 */
class DataController extends ControllerBase {

  public function data($dashboard) {
    $config_factory = $this->configFactory();
    $config = NULL;
    $config_names = [
      'charts_dashboard.dashboard.' . $dashboard,
      'charts_dashboard_dashboard.' . $dashboard,
    ];
    foreach ($config_names as $name) {
      $cfg = $config_factory->get($name);
      if ($cfg && $cfg->get()) {
        $config = $cfg;
        break;
      }
    }

    if (!$config || !$config->get('label')) {
      return new JsonResponse(['error' => 'Dashboard not found'], 404);
    }

    $source_type = $config->get('source_type') ?: 'inline';

    if ($source_type === 'inline') {
      $chart_type = $config->get('chart_type') ?: 'bar';
      $data = $config->get('data') ?: [];
      $labels = $data['labels'] ?? [];
      $values = $data['values'] ?? [];

      return new JsonResponse([
        'label' => $config->get('label'),
        'description' => $config->get('description'),
        'chart_type' => $chart_type,
        'labels' => $labels,
        'values' => $values,
      ]);
    }

    if ($source_type === 'rest') {
      $rest = $config->get('rest_settings') ?: [];
      $url = $rest['url'] ?? NULL;
      if (!$url) {
        return new JsonResponse(['error' => 'REST endpoint not configured'], 400);
      }

      $cid = 'charts_dashboard:rest:' . md5($url);
      $cache = \Drupal::cache()->get($cid);
      $ttl = intval($rest['ttl'] ?? 300);
      if ($cache && $cache->data) {
        $payload = $cache->data;
      }
      else {
        try {
          $client = \Drupal::httpClient();
          $options = ['timeout' => 5, 'headers' => []];
          if (!empty($rest['headers']) && is_array($rest['headers'])) {
            foreach ($rest['headers'] as $h) {
              if (strpos($h, ':') !== FALSE) {
                [$hk, $hv] = array_map('trim', explode(':', $h, 2));
                $options['headers'][$hk] = $hv;
              }
            }
          }
          $response = $client->get($url, $options);
          $body = (string) $response->getBody();
          $payload = json_decode($body, TRUE);
          if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON from REST endpoint'], 502);
          }
          \Drupal::cache()->set($cid, $payload, time() + $ttl);
        }
        catch (\Exception $e) {
          return new JsonResponse(['error' => 'Failed to fetch REST endpoint: ' . $e->getMessage()], 502);
        }
      }

      $labels = $payload['labels'] ?? [];
      $values = $payload['values'] ?? [];

      return new JsonResponse([
        'label' => $config->get('label'),
        'description' => $config->get('description'),
        'chart_type' => $config->get('chart_type') ?: 'bar',
        'labels' => $labels,
        'values' => $values,
      ]);
    }

    if ($source_type === 'views') {
      $views_settings = $config->get('views_settings') ?: [];
      $view_name = $views_settings['view_name'] ?? NULL;
      $display_id = $views_settings['display_id'] ?? NULL;
      $label_field = $views_settings['label_field'] ?? NULL;
      $value_field = $views_settings['value_field'] ?? NULL;

      if (!$view_name || !$display_id || !$label_field || !$value_field) {
        return new JsonResponse(['error' => 'Views settings incomplete. Configure view_name, display_id, label_field, value_field.'], 400);
      }

      try {
        $view = \Drupal::service('views.view.repository')->getView($view_name);
        if (!$view) {
          return new JsonResponse(['error' => 'View not found: ' . $view_name], 404);
        }
      }
      catch (\Exception $e) {
        return new JsonResponse(['error' => 'Failed to load view: ' . $e->getMessage()], 500);
      }

      $view_obj = \Drupal\views\Views::getView($view_name);
      if (!$view_obj) {
        return new JsonResponse(['error' => 'Unable to load view object: ' . $view_name], 500);
      }
      $view_obj->setDisplay($display_id);
      $view_obj->execute();

      $labels = [];
      $values = [];
      foreach ($view_obj->result as $row) {
        $label = NULL;
        $value = NULL;
        if (isset($row->{$label_field})) {
          $label = $row->{$label_field};
        }
        elseif (isset($row->_entity) && $row->_entity->hasField($label_field)) {
          $label = $row->_entity->get($label_field)->value;
        }

        if (isset($row->{$value_field})) {
          $value = $row->{$value_field};
        }
        elseif (isset($row->_entity) && $row->_entity->hasField($value_field)) {
          $value = $row->_entity->get($value_field)->value;
        }

        if ($label !== NULL && $value !== NULL) {
          $labels[] = (string) $label;
          $values[] = (float) $value;
        }
      }

      return new JsonResponse([
        'label' => $config->get('label'),
        'description' => $config->get('description'),
        'chart_type' => $config->get('chart_type') ?: 'bar',
        'labels' => $labels,
        'values' => $values,
      ]);
    }

    return new JsonResponse(['error' => 'Unsupported data source type: ' . $source_type], 400);
  }
}
