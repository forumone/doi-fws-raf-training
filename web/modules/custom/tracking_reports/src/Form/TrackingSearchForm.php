<?php

namespace Drupal\tracking_reports\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tracking_reports\TrackingSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a combined tracking search form and results.
 */
class TrackingSearchForm extends FormBase {

  /**
   * The tracking search manager.
   *
   * @var \Drupal\tracking_reports\TrackingSearchManager
   */
  protected $searchManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new TrackingSearchForm.
   */
  public function __construct(
    TrackingSearchManager $search_manager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->searchManager = $search_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tracking_reports.search_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tracking_search_form';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'tracking_reports/tracking_reports';
    $form['#attached']['library'][] = 'bootstrap/accordion';

    $form['#prefix'] = '<div class="tracking-search-form panel-group" id="tracking-search-accordion">';
    $form['#suffix'] = '</div>';

    // Get current page from URL query.
    $current_page = \Drupal::request()->query->get('page');

    // Check if there has been a POST or if there are any query params.
    $request = \Drupal::request();
    $collapsed = FALSE;

    if ($request->isMethod('POST') || $request->query->all()) {
      $collapsed = TRUE;
    }

    // Main Filter Options Panel.
    $form['filter_options'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['panel', 'panel-default']],
    ];

    $form['filter_options']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['panel-heading']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#attributes' => ['class' => ['panel-title']],
        'link' => [
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#attributes' => [
            'data-toggle' => 'collapse',
            'data-parent' => '#tracking-search-accordion',
            'href' => '#filter-options-collapse',
            'class' => ['accordion-toggle', $collapsed ? 'collapse' : ''],
          ],
          '#value' => $this->t('Filter Options') . ' <i class="fa fa-caret-down" style="float: right;"></i>',
        ],
      ],
    ];

    $form['filter_options']['collapse'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'filter-options-collapse',
        'class' => ['panel-collapse', 'collapse', $collapsed ? '' : 'in'],
      ],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['panel-body']],
      ],
    ];

    // Search description within the panel body.
    $form['filter_options']['collapse']['body']['search_description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('* indicates a wildcard search. For  ample, if you enter Bob, you will get a list containing Bob, Bob 2, New Bob, Bobber, etc.'),
      '#attributes' => ['class' => ['search-description']],
    ];

    // Individual Species Search section.
    $form['filter_options']['collapse']['body']['individual_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Search for Individual Species'),
    ];

    $form['filter_options']['collapse']['body']['individual_description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Enter only one field below to identify the species and click Next to see information.'),
    ];

    // Individual search fields.
    $individual_fields = [
      'number' => [
        'title' => 'Tracking Number',
        'required' => FALSE,
        'maxlength' => 64,
      ],
      'species_id' => [
        'title' => 'Species ID *',
        'required' => FALSE,
        'maxlength' => 64,
      ],
      'species_name' => [
        'title' => 'Species Given Name *',
        'required' => FALSE,
        'maxlength' => 128,
      ],
      'tag_id' => [
        'title' => 'Tag ID',
        'required' => FALSE,
        'maxlength' => 64,
      ],
    ];

    foreach ($individual_fields as $key => $field) {
      $form['filter_options']['collapse']['body'][$key] = [
        '#type' => 'textfield',
        '#title' => $this->t($field['title']),
        '#required' => $field['required'],
        '#maxlength' => $field['maxlength'],
        '#size' => 64,
        '#wrapper_attributes' => ['class' => ['form-item']],
        '#attributes' => ['class' => ['tracking-search-field']],
      ];
    }

    $form['filter_options']['collapse']['body']['tag_type'] = [
      '#type' => 'select2',
      '#title' => $this->t('Tag Type'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getTagTypes(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    // List Search section.
    $form['filter_options']['collapse']['body']['list_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Search for a List of Species'),
    ];

    $form['filter_options']['collapse']['body']['list_description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Enter as many fields below as needed to describe the species that you are interested in and click Next'),
    ];

    // Location Information.
    $form['filter_options']['collapse']['body']['location'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['well']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Location Information'),
      ],
    ];

    $form['filter_options']['collapse']['body']['location']['county'] = [
      '#type' => 'select2',
      '#title' => $this->t('County'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getCounties(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['filter_options']['collapse']['body']['location']['waterway'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Waterway *'),
      '#maxlength' => 128,
      '#size' => 64,
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['filter_options']['collapse']['body']['location']['state'] = [
      '#type' => 'select2',
      '#title' => $this->t('State'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getStates(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    // Event Information.
    $form['filter_options']['collapse']['body']['event'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['well']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Event Information'),
      ],
    ];

    $form['filter_options']['collapse']['body']['event']['event_type'] = [
      '#type' => 'select2',
      '#title' => $this->t('Event'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getEventTypes(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['filter_options']['collapse']['body']['event']['date_range'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['date-range-container']],
    ];

    $form['filter_options']['collapse']['body']['event']['date_range']['from'] = [
      '#type' => 'date',
      '#title' => $this->t('Occurring from'),
      '#date_date_format' => 'Y-m-d',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['filter_options']['collapse']['body']['event']['date_range']['to'] = [
      '#type' => 'date',
      '#title' => $this->t('to'),
      '#date_date_format' => 'Y-m-d',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    // Event Detail Information.
    $form['filter_options']['collapse']['body']['event_detail'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['well']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Event Detail Information'),
      ],
    ];

    $form['filter_options']['collapse']['body']['event_detail']['rescue_type'] = [
      '#type' => 'select2',
      '#title' => $this->t('Rescue Type'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getRescueTypes(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['filter_options']['collapse']['body']['event_detail']['rescue_cause'] = [
      '#type' => 'select2',
      '#title' => $this->t('Rescue Cause'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getRescueCauses(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['filter_options']['collapse']['body']['event_detail']['organization'] = [
      '#type' => 'select2',
      '#title' => $this->t('Organization'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getOrganizations(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['filter_options']['collapse']['body']['event_detail']['cause_of_death'] = [
      '#type' => 'select2',
      '#title' => $this->t('Cause of Death'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getDeathCauses(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    // Actions.
    $form['filter_options']['collapse']['body']['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['form-actions']],
    ];

    $form['filter_options']['collapse']['body']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#button_type' => 'primary',
    ];

    // Check if we need to show results.
    if ($form_state->get('show_results') || $current_page !== NULL) {
      // Restore form values from tempstore if paginating.
      if ($current_page !== NULL && !$form_state->get('show_results')) {
        $tempstore = \Drupal::service('tempstore.private')->get('tracking_reports');
        $stored_values = $tempstore->get('search_values');
        if ($stored_values) {
          $form_state->setValues($stored_values);
          $form_state->set('show_results', TRUE);
        }
      }

      // Process search parameters and show results.
      if ($form_state->getValues()) {
        $conditions = $this->processSearchParameters($form_state->getValues());
        $form['search_results'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['search-results-container']],
          'results' => $this->searchManager->buildSearchResults($conditions),
        ];
      }
    }

    return $form;
  }

  /**
   * Process search parameters from form values.
   */
  protected function processSearchParameters(array $values) {
    $conditions = [];

    // Individual search parameters.
    if (!empty($values['number'])) {
      $conditions[] = [
        'field' => 'field_number',
        'value' => $values['number'],
      ];
    }

    if (!empty($values['species_id'])) {
      $conditions[] = [
        'type' => 'species_id',
        'field' => 'field_species_ref',
        'value' => $values['species_id'],
        'operator' => 'CONTAINS',
      ];
    }

    if (!empty($values['species_name'])) {
      $conditions[] = [
        'type' => 'species_name',
        'field' => 'field_name',
        'value' => $values['species_name'],
        'operator' => 'CONTAINS',
      ];
    }

    if (!empty($values['tag_id'])) {
      $conditions[] = [
        'type' => 'species_tag',
        'field' => 'field_tag_id',
        'value' => $values['tag_id'],
        'operator' => 'CONTAINS',
      ];
    }

    // Location parameters.
    if (!empty($values['waterway'])) {
      $conditions[] = [
        'field' => 'field_waterway',
        'value' => $values['waterway'],
        'operator' => 'CONTAINS',
      ];
    }

    if (!empty($values['state']) && $values['state'] !== 'All') {
      $conditions[] = [
        'field' => 'field_state',
        'value' => $values['state'],
      ];
    }

    // County handling.
    if (!empty($values['county']) && $values['county'] !== 'All') {
      $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', 'species_rescue')
        ->condition('field_county', $values['county'])
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($rescue_query)) {
        $species_ids = [];
        $rescues = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_query);
        foreach ($rescues as $rescue) {
          if (!$rescue->field_species_ref->isEmpty()) {
            $species_ids[] = $rescue->field_species_ref->target_id;
          }
        }

        if (!empty($species_ids)) {
          $conditions[] = [
            'field' => 'nid',
            'value' => $species_ids,
            'operator' => 'IN',
          ];
        }
        else {
          $conditions[] = [
            'field' => 'nid',
            'value' => 0,
          ];
        }
      }
    }

    // Event parameters.
    if (!empty($values['event_type']) && $values['event_type'] !== 'All') {
      $condition = [
        'field' => 'type',
        'value' => $values['event_type'],
      ];

      if (!empty($values['from'])) {
        $condition['from'] = $values['from'];
      }

      if (!empty($values['to'])) {
        $condition['to'] = $values['to'];
      }

      $conditions[] = $condition;
    }

    // Event detail parameters.
    if (!empty($values['rescue_type']) && $values['rescue_type'] !== 'All') {
      $conditions[] = [
        'field' => 'field_rescue_type',
        'value' => $values['rescue_type'],
      ];
    }

    if (!empty($values['rescue_cause']) && $values['rescue_cause'] !== 'All') {
      $conditions[] = [
        'field' => 'field_rescue_cause',
        'value' => $values['rescue_cause'],
      ];
    }

    if (!empty($values['organization']) && $values['organization'] !== 'All') {
      $conditions[] = [
        'field' => 'field_organization',
        'value' => $values['organization'],
      ];
    }

    if (!empty($values['cause_of_death'])) {
      $conditions[] = [
        'field' => 'field_cause_id',
        'value' => $values['cause_of_death'],
      ];
    }

    return $conditions;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $from_date = $form_state->getValue('from');
    $to_date = $form_state->getValue('to');
    $event_type = $form_state->getValue('event_type');

    if ((!empty($from_date) || !empty($to_date)) && ($event_type === 'All' || empty($event_type))) {

      $form_state->setErrorByName(
        'event_type',
        $this->t('Event type is required when specifying a date range.')
      );
    }

    if (!empty($from_date) && !empty($to_date)) {
      $from = strtotime($from_date);
      $to = strtotime($to_date);

      if ($from > $to) {
        $form_state->setErrorByName(
          'to',
          $this->t('The end date must be later than or equal to the start date.')
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Store form values in tempstore for pagination.
    $tempstore = \Drupal::service('tempstore.private')->get('tracking_reports');
    $tempstore->set('search_values', $form_state->getValues());

    $form_state->set('show_results', TRUE);
    $form_state->setRebuild(TRUE);
  }

}
