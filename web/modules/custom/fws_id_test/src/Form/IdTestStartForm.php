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
    $form['species_id_difficulty'] = [
      '#type' => 'select2',
      '#title' => $this->t('Select Your Experience Level'),
      '#required' => TRUE,
      '#options' => $this->getDifficultyOptions(),
      '#description' => $this->t('Choose your experience level for species identification.'),
    ];

    $form['species_group'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select Species Group(s):'),
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
    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'species_id_difficulty']);

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
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Store the selected values in the user's session for the next step.
    $session = \Drupal::service('session');
    $session->set('fws_id_test.difficulty', $form_state->getValue('species_id_difficulty'));
    // Filter out unselected values from checkboxes.
    $session->set('fws_id_test.species_group', array_filter($form_state->getValue('species_group')));
    $session->set('fws_id_test.region', array_filter($form_state->getValue('geographic_region')));

    // Redirect to the test page.
    $form_state->setRedirect('fws_id_test.test');
  }

}
