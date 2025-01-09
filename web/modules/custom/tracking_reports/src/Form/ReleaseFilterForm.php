<?php

namespace Drupal\tracking_reports\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class ReleaseFilterForm extends FormBase {
  public function getFormId() {
    return 'release_filter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $query_params = \Drupal::request()->query->all();

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter Options'),
      '#open' => TRUE,
    ];

    $form['filters']['search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Keyword Search'),
      '#description' => $this->t('Search by name, species ID, number, rescue cause, or county'),
      '#size' => 30,
      '#default_value' => $query_params['search'] ?? '',
    ];

    $form['filters']['date_range'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    $form['filters']['date_range']['release_date_from'] = [
      '#type' => 'date',
      '#title' => $this->t('Release Date (From)'),
      '#default_value' => $query_params['release_date_from'] ?? '',
    ];

    $form['filters']['date_range']['release_date_to'] = [
      '#type' => 'date',
      '#title' => $this->t('Release Date (To)'),
      '#default_value' => $query_params['release_date_to'] ?? '',
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Filter'),
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $current_route = \Drupal::routeMatch()->getRouteName();
    $query = [];
    
    foreach ($form_state->getValues() as $key => $value) {
      if (!empty($value) && !in_array($key, ['form_build_id', 'form_token', 'form_id', 'op', 'submit'])) {
        $query[$key] = $value;
      }
    }

    $form_state->setRedirect($current_route, [], ['query' => $query]);
  }

}
