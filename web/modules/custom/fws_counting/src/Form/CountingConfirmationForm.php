<?php

namespace Drupal\fws_counting\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a confirmation form for the counting experience test.
 */
class CountingConfirmationForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_counting_confirmation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get the parameters from the previous form from tempstore.
    $tempstore = \Drupal::service('tempstore.private')->get('fws_counting');
    $experience_level = $tempstore->get('experience_level');
    $size_range = $tempstore->get('size_range');

    if (!$experience_level || !$size_range) {
      // Redirect back to the first form if we don't have the required data.
      return new RedirectResponse(Url::fromRoute('fws_counting.test_skills')->toString());
    }

    // Load the term data.
    $experience_term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->load($experience_level);

    $size_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple((array) $size_range);

    // Build the size range text.
    $size_ranges = [];
    foreach ($size_terms as $term) {
      $size_ranges[] = $term->label();
    }
    $size_range_text = implode(' AND ', $size_ranges);

    // Get the viewing time based on the difficulty level
    $difficulty_level = $experience_term->get('field_difficulty_level')->value;
    $viewing_time = match ((int) $difficulty_level) {
      1 => 10,
      2 => 6,
      3 => 3,
      default => 6, // Default to 6 seconds if level is not set
    };

    $form['parameters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['parameters-chosen']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Parameters chosen:'),
      ],
      'experience' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Experience Level: @level', [
          '@level' => $experience_term->label(),
        ]),
      ],
      'size' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Flock Size Range: @range', [
          '@range' => $size_range_text,
        ]),
      ],
    ];

    $form['instructions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['test-instructions']],
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('You will be given 10 images. Each image will display for @time seconds.
          After you enter your estimate, the next image comes up on the screen.
          A summary statistics for the session will be shown after the 10th iteration.', [
            '@time' => $viewing_time,
          ]),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back'),
        '#url' => Url::fromRoute('fws_counting.test_skills'),
        '#attributes' => ['class' => ['button']],
      ],
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Begin Test'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('fws_counting.quiz');
  }

}
