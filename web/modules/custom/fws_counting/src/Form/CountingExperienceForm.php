<?php

namespace Drupal\fws_counting\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form for selecting counting experience and flock size ranges.
 */
class CountingExperienceForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_counting_experience_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Load difficulty terms sorted by field_difficulty_level.
    $difficulty_vid = 'species_counting_difficulty';
    $query = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', $difficulty_vid)
      ->sort('field_difficulty_level', 'ASC');
    $term_ids = $query->execute();
    $difficulty_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($term_ids);

    $difficulty_options = [];
    foreach ($difficulty_terms as $term) {
      $difficulty_options[$term->id()] = $term->label();
    }

    $form['#theme'] = 'counting_experience_form';

    $form['experience_level'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select your Experience Level'),
      '#options' => $difficulty_options,
      '#required' => TRUE,
    ];

    // Load size range terms sorted by field_size_range_id.
    $size_vid = 'size_range';
    $query = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', $size_vid)
      ->sort('field_size_range_id', 'ASC');
    $term_ids = $query->execute();
    $size_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($term_ids);

    $size_options = [];
    foreach ($size_terms as $term) {
      $size_options[$term->id()] = $term->label();
    }

    $form['size_ranges'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Next, select flock size ranges'),
      '#options' => $size_options,
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#attributes' => ['class' => ['btn btn-primary']],
      '#value' => $this->t('Next'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $experience_level = $form_state->getValue('experience_level');
    $size_ranges = array_filter($form_state->getValue('size_ranges'));

    // Store the selected values in tempstore.
    $tempstore = \Drupal::service('tempstore.private')->get('fws_counting');
    $tempstore->set('experience_level', $experience_level);
    $tempstore->set('size_range', $size_ranges);

    // Store the values in the session for later use.
    $_SESSION['counting_experience'] = $experience_level;
    $_SESSION['size_ranges'] = $size_ranges;

    // Redirect to the confirmation page.
    $form_state->setRedirect('fws_counting.confirmation');
  }

}
