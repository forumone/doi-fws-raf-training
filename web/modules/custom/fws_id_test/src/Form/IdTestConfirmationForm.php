<?php

namespace Drupal\fws_id_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a confirmation form for the species ID test.
 */
class IdTestConfirmationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_id_test_confirmation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $session = \Drupal::service('session');
    $difficulty = $session->get('fws_id_test.difficulty');
    $species_groups = $session->get('fws_id_test.species_group');
    $regions = $session->get('fws_id_test.region');

    // Load the term labels for display.
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

    // Load difficulty term.
    $difficulty_term = $term_storage->load($difficulty);
    $difficulty_label = $difficulty_term ? $difficulty_term->label() : '';

    // Load species group terms.
    $species_group_terms = $term_storage->loadMultiple(array_keys($species_groups));
    $species_group_labels = array_map(function ($term) {
      return $term->label();
    }, $species_group_terms);

    // Load region terms.
    $region_terms = $term_storage->loadMultiple(array_keys($regions));
    $region_labels = array_map(function ($term) {
      return $term->label();
    }, $region_terms);

    $form['confirmation_message'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<h3>Parameters chosen:</h3>
        <p><strong>Experience Level:</strong> @difficulty<br>
        <strong>Species Groups:</strong> @groups<br>
        <strong>Geographic Regions:</strong> @regions</p>
        <p>You will be presented with 10 video clips. After you answer a multiple choice question
        for all 10 clips, summary statistics will be presented.</p>', [
          '@difficulty' => $difficulty_label,
          '@groups' => implode(', ', $species_group_labels),
          '@regions' => implode(', ', $region_labels),
        ]),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('Back'),
      '#url' => Url::fromRoute('fws_id_test.start'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Begin Test'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Redirect to the test page.
    $form_state->setRedirect('fws_id_test.test');
  }

}
