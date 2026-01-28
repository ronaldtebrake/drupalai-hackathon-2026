<?php

namespace Drupal\charts_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SettingsForm extends ConfigFormBase {
  public function getFormId() {
    return 'charts_dashboard_settings_form';
  }

  protected function getEditableConfigNames() {
    return ['charts_dashboard.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('charts_dashboard.settings');

    $form['default_chart_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default chart type'),
      '#options' => [
        'bar' => $this->t('Bar'),
        'line' => $this->t('Line'),
        'pie' => $this->t('Pie'),
      ],
      '#default_value' => $config->get('default_chart_type') ?: 'bar',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('charts_dashboard.settings')
      ->set('default_chart_type', $form_state->getValue('default_chart_type'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
