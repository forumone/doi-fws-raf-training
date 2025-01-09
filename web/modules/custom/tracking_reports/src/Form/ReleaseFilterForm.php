<?php

namespace Drupal\tracking_reports\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form for filtering release reports.
 */
class ReleaseFilterForm extends FormBase {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a ReleaseFilterForm object.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   */
  public function __construct(DateFormatterInterface $date_formatter) {
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'release_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Attach the tracking_reports library to the form
    $form['#attached']['library'][] = 'tracking_reports/tracking_reports';

    $query_params = \Drupal::request()->query->all();

    // Determine the prior year.
    $current_year = (int) date('Y');
    $prior_year = $current_year - 1;

    // Set default date ranges to the prior year if not provided
    $release_date_from = isset($query_params['release_date_from']) && !empty($query_params['release_date_from']) ? $query_params['release_date_from'] : "$prior_year-01-01";
    $release_date_to = isset($query_params['release_date_to']) && !empty($query_params['release_date_to']) ? $query_params['release_date_to'] : "$prior_year-12-31";

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filter Options'),
      '#open' => TRUE,
    ];

    $form['filters']['search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search'),
      '#description' => '',
      '#size' => 30,
      '#default_value' => isset($query_params['search']) ? $query_params['search'] : '',
    ];

    $form['filters']['date_range'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];

    $form['filters']['date_range']['release_date_from'] = [
      '#type' => 'date',
      '#title' => $this->t('Release Date (From)'),
      '#default_value' => $release_date_from,
      '#required' => TRUE,
      '#attributes' => [
        // When the user changes the select value, auto-submit.
        'onchange' => 'this.form.submit();',
      ],
    ];

    $form['filters']['date_range']['release_date_to'] = [
      '#type' => 'date',
      '#title' => $this->t('Release Date (To)'),
      '#default_value' => $release_date_to,
      '#required' => TRUE,
      '#attributes' => [
        // When the user changes the select value, auto-submit.
        'onchange' => 'this.form.submit();',
      ],
    ];

    $form['filters']['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Filter'),
      ],
      '#attributes' => [
        'style' => 'display: none;',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Validate that 'release_date_from' is not after 'release_date_to'
    $date_from = $form_state->getValue('release_date_from');
    $date_to = $form_state->getValue('release_date_to');

    if (strtotime($date_from) > strtotime($date_to)) {
      $form_state->setErrorByName('release_date_from', $this->t('The "Release Date From" cannot be later than the "Release Date To".'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $current_route = \Drupal::routeMatch()->getRouteName();
    $query = [];

    // Extract filter values
    $search = $form_state->getValue('search');
    $release_date_from = $form_state->getValue('release_date_from');
    $release_date_to = $form_state->getValue('release_date_to');

    // Only add to query if not empty
    if (!empty($search)) {
      $query['search'] = $search;
    }
    if (!empty($release_date_from)) {
      $query['release_date_from'] = $release_date_from;
    }
    if (!empty($release_date_to)) {
      $query['release_date_to'] = $release_date_to;
    }

    // Preserve existing sort parameters
    $current_sort = \Drupal::request()->query->get('sort');
    $current_direction = \Drupal::request()->query->get('direction');
    if ($current_sort) {
      $query['sort'] = $current_sort;
    }
    if ($current_direction) {
      $query['direction'] = $current_direction;
    }

    $form_state->setRedirect($current_route, [], ['query' => $query]);
  }

}
