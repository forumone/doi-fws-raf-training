<?php

namespace Drupal\tracking_reports\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * A simple form to filter Current Captives by facility using GET params.
 */
class FacilityFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'facility_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Read the currently selected facility from ?facility=___ in the URL.
    $selected_facility = \Drupal::request()->query->get('facility', 'all');

    // Build facility options. Load only active taxonomy terms from 'org'.
    $facility_options = ['all' => $this->t('- All Facilities -')];

    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

    // Load all active org terms that have a house species field.
    $org_terms = $storage->loadByProperties([
      'vid' => 'org',
      'field_active' => TRUE,
      'field_house_species'  => TRUE,
    ]);

    foreach ($org_terms as $term) {
      if ($term->hasField('field_organization') && !$term->field_organization->isEmpty()) {
        $org_val = $term->field_organization->value;
        $facility_options[$org_val] = $org_val;
      }
    }

    // We want GET submission so it appends ?facility= to the URL.
    $form['#method'] = 'get';

    // Build a select field that auto-submits on change.
    $form['facility'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by Facility'),
      '#options' => $facility_options,
      '#default_value' => $selected_facility,
      '#attributes' => [
        // When the user changes the select value, auto-submit.
        'onchange' => 'this.form.submit();',
      ],
    ];

    // We still need a "submit" element for the form to be valid, but we can
    // hide it to avoid confusion.
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Apply Filter'),
        '#attributes' => [
          'style' => 'display: none;',
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Typically no validation needed for a simple select filter.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // On submit, redirect to the same page with ?facility=[value].
    $selected = $form_state->getValue('facility', 'all');
    $form_state->setRedirect('<current>', [], ['query' => ['facility' => $selected]]);
  }

}
