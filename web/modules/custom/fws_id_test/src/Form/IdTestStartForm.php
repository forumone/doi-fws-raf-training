<?php

namespace Drupal\fws_id_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form for starting a species ID test.
 */
class IdTestStartForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_id_test_start_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attributes']['class'][] = 'fws-id-test-start-form';

    $form['species_id_difficulty'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select Your Experience Level'),
      '#required' => TRUE,
      '#options' => $this->getDifficultyOptions(),
    ];

    $form['species_group'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select Species Group(s)'),
      '#required' => TRUE,
      '#options' => $this->getSpeciesGroupOptions(),
      '#description' => $this->t('Select one or more species groups to test.'),
    ];

    $form['geographic_region'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select Geographic Regions or Habitats'),
      '#required' => TRUE,
      '#options' => $this->getRegionOptions(),
      '#description' => $this->t('Select one or more regions or habitats to test.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Gets the difficulty level options from taxonomy.
   */
  protected function getDifficultyOptions() {
    $query = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'species_id_difficulty')
      ->sort('field_difficulty_level', 'ASC');
    $term_ids = $query->execute();
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($term_ids);

    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }
    return $options;
  }

  /**
   * Gets the species group options from taxonomy.
   */
  protected function getSpeciesGroupOptions() {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'species_group']);

    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }
    return $options;
  }

  /**
   * Gets the region options from taxonomy.
   */
  protected function getRegionOptions() {
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'geographic_region']);

    $options = [];

    // Make sure to display "ALL" option first.
    foreach ($terms as $term) {
      if (str_starts_with(strtoupper($term->label()), 'ALL')) {
        $options = [$term->id() => $term->label()] + $options;
      }
      else {
        $options[$term->id()] = $term->label();
      }
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Filter out unselected values from checkboxes.
    $species_groups = array_filter($form_state->getValue('species_group'));
    $regions = array_filter($form_state->getValue('geographic_region'));

    // Filter out any "ALL" options.
    $species_groups_filtered = [];
    $regions_filtered = [];

    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

    // Filter out ALL from species groups.
    foreach ($species_groups as $tid => $value) {
      $term = $term_storage->load($tid);
      if ($term && !str_starts_with(strtoupper($term->label()), 'ALL')) {
        $species_groups_filtered[$tid] = $value;
      }
    }

    // Filter out ALL from regions.
    foreach ($regions as $tid => $value) {
      $term = $term_storage->load($tid);
      if ($term && !str_starts_with(strtoupper($term->label()), 'ALL')) {
        $regions_filtered[$tid] = $value;
      }
    }

    // Redirect to the confirmation page with query parameters.
    $form_state->setRedirect('fws_id_test.confirm', [], [
      'query' => [
        'difficulty' => $form_state->getValue('species_id_difficulty'),
        'species_groups' => array_values(array_keys($species_groups_filtered)),
        'regions' => array_values(array_keys($regions_filtered)),
      ],
    ]);
  }

}
