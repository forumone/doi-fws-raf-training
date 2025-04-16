<?php

namespace Drupal\fws_falcon_permit\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\fws_falcon_permit\Permit3186ASearchHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a search form and results.
 */
class Permit3186ASearchForm extends FormBase {

  /**
   * The search helper.
   *
   * @var \Drupal\fws_falcon_permit\Permit3186ASearchHelper
   */
  protected $searchHelper;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new TrackingSearchForm.
   */
  public function __construct(Permit3186ASearchHelper $search_helper, EntityTypeManagerInterface $entity_type_manager) {
    $this->searchHelper = $search_helper;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('fws_falcon_permit.search_helder'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'permit_3186a_search_form';
  }

  /**
   * Get filter values.
   *
   * @return array
   *   An array contain input and value filters.
   */
  protected function getFilterValues() {
    $query_params = $this->getRequest()->query->all();
    if (empty($query_params)) {
      return [];
    }

    $filters = array_keys($this->buildFilterForm([]));
    $filter_values = [];
    foreach ($filters as $filter) {
      if (isset($query_params[$filter])) {
        $filter_values[$filter] = $query_params[$filter];
      }
    }

    return $filter_values;
  }

  /**
   * Build filter form.
   *
   * @param array $form
   *   Form elements renderable.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   Form elements renderable.
   */
  protected function buildFilterForm(array $form, ?FormStateInterface $form_state = NULL) {
    $form['record_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Record Number'),
      '#maxlength' => 64,
      '#size' => 64,
      '#autocomplete_route_name' => 'fws_falcon_permit.permit_3186a_autocomplete',
      '#autocomplete_route_parameters' => [
        'field' => 'field_recno',
      ],
    ];

    $form['authorized'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Auth. CD/FEDID'),
      '#maxlength' => 64,
      '#size' => 64,
    ];

    $form['transaction_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Transaction No.'),
      '#maxlength' => 64,
      '#size' => 64,
      '#autocomplete_route_name' => 'fws_falcon_permit.permit_3186a_autocomplete',
      '#autocomplete_route_parameters' => [
        'field' => 'field_question_no',
      ],
    ];

    $form['species_code'] = [
      '#type' => 'select',
      '#title' => $this->t('Species Code'),
      '#options' => $this->searchHelper->getTaxonomyTermOptions('species_code'),
      '#empty_option' => $this->t('- None -'),
      '#empty_value' => '',
    ];

    $form['species_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Species Name'),
      '#options' => $this->searchHelper->getTaxonomyTermOptions('species'),
      '#empty_option' => $this->t('- None -'),
      '#empty_value' => '',
    ];

    $form['species_band_no'] = [
      '#type' => 'textfield',
      '#title' => $this->t('USFWS Band Number'),
    ];

    $form['species_band_old_no'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Old Band Number'),
    ];

    $form['species_band_new_no'] = [
      '#type' => 'textfield',
      '#title' => $this->t('New Band Number'),
    ];

    $form['sender_first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender First name'),
    ];

    $form['sender_last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender Last name'),
    ];

    $form['recipient_first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient First name'),
    ];

    $form['recipient_last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient Last name'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $filter_values = $this->getFilterValues();
    $form = $this->buildFilterForm($form, $form_state);

    foreach ($filter_values as $filter => $value) {
      if (isset($form[$filter])) {
        $form[$filter]['#default_value'] = $value;
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#button_type' => 'primary',
    ];

    // Determine if we should show results.
    $pagination = $this->getRequest()->query->get('page');
    if (!empty($filter_values) || isset($pagination)) {
      $form['search_results'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['search-results-container']],
        'results' => $this->buildSearchResults($filter_values),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->cleanValues();
    $values = array_filter($form_state->getValues());

    $form_state->setRedirect('<current>', [], ['query' => $values]);
  }

  /**
   * Build search results.
   *
   * @param array $filter_values
   *   An array filter with input and value.
   *
   * @return array
   *   The renderable.
   */
  protected function buildSearchResults(array $filter_values) {
    $nids = $this->searchHelper->getSearchResults($filter_values);
    if (empty($nids)) {
      return [
        '#markup' => '<div class="no-results">' . $this->t('No results found.') . '</div>',
      ];
    }

    $rows = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($nids) as $node) {
      $row = [
        'id' => $node->id(),
        'link' => $node->toLink(),
      ];

      $rows[] = $row;
    }

    return [
      'table' => [
        '#type' => 'table',
        '#header' => [
          'id' => $this->t('#'),
          'link' => $this->t('Title'),
        ],
        '#rows' => $rows,
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

}
