<?php

namespace Drupal\fws_counting\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for the counting experience test.
 */
class CountingConfirmationForm extends FormBase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new CountingConfirmationForm.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')
    );
  }

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
    $form['#attached']['library'][] = 'fws_counting/quiz';

    // Get the parameters from the query.
    $query = $this->requestStack->getCurrentRequest()->query;
    $experience_level = $query->get('experience_level');
    $size_range = $query->all()['size_range'] ?? [];

    if (!$experience_level || empty($size_range)) {
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
    $size_range_text = implode(', ', $size_ranges);

    // Get the viewing time based on the difficulty level.
    $difficulty_level = $experience_term->get('field_difficulty_level')->value;
    $viewing_time = match ((int) $difficulty_level) {
      1 => 10,
      2 => 6,
      3 => 3,
      // Default to 6 seconds if level is not set.
      default => 6,
    };

    $form['parameters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['parameters-chosen']],
      'content' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['quiz__parameters']],
        'info' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['quiz__info']],
          'experience' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#attributes' => ['class' => ['quiz__parameter']],
            '#value' => $this->t('<b>Experience Level:</b> @level', [
              '@level' => $experience_term->label(),
            ]),
          ],
          'size' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#attributes' => ['class' => ['quiz__parameter']],
            '#value' => $this->t('<b>Flock Size Range(s)</b>: @range', [
              '@range' => $size_range_text,
            ]),
          ],
        ],
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
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions']],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back'),
        '#url' => Url::fromRoute('fws_counting.test_skills'),
        '#attributes' => ['class' => ['btn btn-default']],
      ],
      'submit' => [
        '#type' => 'submit',
        '#attributes' => ['class' => ['btn btn-primary']],
        '#value' => $this->t('Begin Test'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get the parameters from query.
    $query = $this->requestStack->getCurrentRequest()->query;
    $experience_level = $query->get('experience_level');
    $size_range = $query->all()['size_range'] ?? [];

    // Convert size_range array to comma-separated string.
    $size_range_string = implode(',', (array) $size_range);

    // Redirect to the quiz page with parameters.
    $form_state->setRedirect('fws_counting.quiz', [
      'experience_level' => $experience_level,
      'size_range' => $size_range_string,
    ]);
  }

}
