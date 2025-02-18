<?php

namespace Drupal\fws_counting\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Term;

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
    // Load difficulty terms
    $difficulty_vid = 'species_counting_difficulty';
    $difficulty_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($difficulty_vid);

    $difficulty_options = [];
    foreach ($difficulty_terms as $term) {
      $difficulty_options[$term->tid] = $term->name;
    }

    $form['#theme'] = 'counting_experience_form';
    
    $form['experience_level'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select your Experience Level'),
      '#options' => $difficulty_options,
      '#required' => TRUE,
    ];

    // Load size range terms
    $size_vid = 'size_range';
    $size_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree($size_vid);

    $size_options = [];
    foreach ($size_terms as $term) {
      $size_options[$term->tid] = $term->name;
    }

    $form['size_ranges'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Next, select flock size ranges'),
      '#options' => $size_options,
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
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
    
    // Store the values in the session for later use
    $_SESSION['counting_experience'] = $experience_level;
    $_SESSION['size_ranges'] = $size_ranges;

    // Redirect to the next step (you can modify this as needed)
    $form_state->setRedirect('fws_counting.form');
  }
}
