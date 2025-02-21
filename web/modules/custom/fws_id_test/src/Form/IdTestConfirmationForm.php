<?php

namespace Drupal\fws_id_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a confirmation form for the species ID test.
 */
class IdTestConfirmationForm extends FormBase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new IdTestConfirmationForm.
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
    return 'fws_id_test_confirmation_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $query = $this->requestStack->getCurrentRequest()->query;

    $difficulty = $query->get('difficulty');
    $species_groups = $query->all()['species_groups'] ?? [];
    $regions = $query->all()['regions'] ?? [];

    // Load the term labels for display.
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

    // Load difficulty term.
    $difficulty_term = $term_storage->load($difficulty);
    $difficulty_label = $difficulty_term ? $difficulty_term->label() : '';

    // Load species group terms.
    $species_group_terms = $term_storage->loadMultiple($species_groups);
    $species_group_labels = array_map(function ($term) {
      return $term->label();
    }, $species_group_terms);

    // Load region terms.
    $region_terms = $term_storage->loadMultiple($regions);
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

    // Store the parameters as hidden fields to pass them to the next step.
    $form['difficulty'] = [
      '#type' => 'hidden',
      '#value' => $difficulty,
    ];

    $form['species_groups'] = [
      '#type' => 'hidden',
      '#value' => implode(',', $species_groups),
    ];

    $form['regions'] = [
      '#type' => 'hidden',
      '#value' => implode(',', $regions),
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
    // Redirect to the test page with query parameters.
    $form_state->setRedirect('fws_id_test.test', [], [
      'query' => [
        'difficulty' => $form_state->getValue('difficulty'),
        'species_groups' => explode(',', $form_state->getValue('species_groups')),
        'regions' => explode(',', $form_state->getValue('regions')),
      ],
    ]);
  }

}
