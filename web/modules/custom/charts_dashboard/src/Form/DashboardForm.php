<?php

namespace Drupal\charts_dashboard\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityForm;

class DashboardForm extends EntityForm {
  public function form(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $entity->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine name'),
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\\charts_dashboard\\Entity\\Dashboard::load'
      ],
    ];

    $form['chart_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Chart type'),
      '#options' => [
        'bar' => $this->t('Bar'),
        'line' => $this->t('Line'),
        'pie' => $this->t('Pie'),
        'doughnut' => $this->t('Doughnut'),
      ],
      '#default_value' => $entity->get('chart_type') ?? 'bar',
    ];

    $form['source_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Data source type'),
      '#options' => [
        'inline' => $this->t('Inline (JSON in this form)'),
        'rest' => $this->t('External REST endpoint'),
        'views' => $this->t('Views (coming soon)'),
      ],
      '#default_value' => $entity->get('source_type') ?? 'inline',
    ];

    $form['data'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Inline data (JSON)'),
      '#default_value' => $entity->get('data') ? json_encode($entity->get('data')) : '',
      '#description' => $this->t('Provide a JSON object with keys "labels" (array) and "values" (array).'),
      '#states' => [
        'visible' => [
          ':input[name="source_type"]' => ['value' => 'inline'],
        ],
      ],
    ];

    $form['rest_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('REST settings'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="source_type"]' => ['value' => 'rest'],
        ],
      ],
    ];
    $rest = $entity->get('rest_settings') ?? [];
    $form['rest_settings']['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Endpoint URL'),
      '#default_value' => $rest['url'] ?? '',
    ];
    $form['rest_settings']['ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#default_value' => $rest['ttl'] ?? 300,
    ];

    return parent::form($form, $form_state);
  }

  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;
    $data = $form_state->getValue('data');
    $decoded = [];
    if (!empty($data)) {
      $decoded = json_decode($data, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->messenger()->addError($this->t('Invalid JSON in inline data.'));
        return;
      }
    }
    $entity->set('chart_type', $form_state->getValue('chart_type'));
    $entity->set('data', $decoded);
    $entity->set('source_type', $form_state->getValue('source_type'));
    $rest_settings = [
      'url' => $form_state->getValue(['rest_settings', 'url']),
      'ttl' => intval($form_state->getValue(['rest_settings', 'ttl']) ?: 300),
    ];
    $entity->set('rest_settings', $rest_settings);

    $status = $entity->save();
    if ($status) {
      $this->messenger()->addStatus($this->t('Saved dashboard %name.', ['%name' => $entity->label()]));
    }
    $form_state->setRedirect('charts_dashboard.dashboard_collection');
  }
}
