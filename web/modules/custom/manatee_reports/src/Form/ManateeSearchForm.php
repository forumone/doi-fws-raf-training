<?php

namespace Drupal\manatee_reports\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\manatee_reports\ManateeSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a combined manatee search form and results.
 */
class ManateeSearchForm extends FormBase {

  /**
   * The manatee search manager.
   *
   * @var \Drupal\manatee_reports\ManateeSearchManager
   */
  protected $searchManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ManateeSearchForm.
   */
  public function __construct(
    ManateeSearchManager $search_manager,
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
      $container->get('manatee_reports.search_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'manatee_search_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'manatee_reports/manatee_reports';
    $form['#prefix'] = '<div class="manatee-search-form">';
    $form['#suffix'] = '</div>';

    $form['search_description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('* indicates a wildcard search. For example, if you enter Bob, you will get a list containing Bob, Bob 2, New Bob, Bobber, etc.'),
      '#attributes' => ['class' => ['search-description']],
    ];

    // Individual Manatee Search section.
    $form['individual_search_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Search for Individual Manatee'),
    ];

    $form['individual_search_description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Enter only one field below to identify the animal and click Next to see information about 1 manatee.'),
    ];

    $form['individual_search']['manatee_info'] = [
      '#type' => 'details',
      '#title' => $this->t('Manatee Information'),
      '#open' => TRUE,
    ];

    $individual_fields = [
      'mlog' => [
        'title' => 'MLog',
        'required' => FALSE,
        'maxlength' => 64,
      ],
      'animal_id' => [
        'title' => 'Animal ID *',
        'required' => FALSE,
        'maxlength' => 64,
      ],
      'manatee_name' => [
        'title' => 'Manatee Name *',
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
      $form['individual_search']['manatee_info'][$key] = [
        '#type' => 'textfield',
        '#title' => $this->t($field['title']),
        '#required' => $field['required'],
        '#maxlength' => $field['maxlength'],
        '#size' => 64,
        '#wrapper_attributes' => ['class' => ['form-item']],
        '#attributes' => ['class' => ['manatee-search-field']],
      ];
    }

    $form['individual_search']['manatee_info']['tag_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Tag Type'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getTagTypes(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    // List Search section.
    $form['list_search_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Search for a List of Manatee(s)'),
    ];

    $form['list_search_description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Enter as many fields below as needed to describe the manatee(s) that you are interested in and click Next'),
    ];

    // Location Information.
    $form['list_search']['location'] = [
      '#type' => 'details',
      '#title' => $this->t('Location Information'),
      '#open' => TRUE,
    ];

    $form['list_search']['location']['county'] = [
      '#type' => 'select',
      '#title' => $this->t('County'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getCounties(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['list_search']['location']['waterway'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Waterway *'),
      '#maxlength' => 128,
      '#size' => 64,
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['list_search']['location']['state'] = [
      '#type' => 'select',
      '#title' => $this->t('State'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getStates(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    // Event Information.
    $form['list_search']['event'] = [
      '#type' => 'details',
      '#title' => $this->t('Event Information'),
      '#open' => TRUE,
    ];

    $form['list_search']['event']['event_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Event'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getEventTypes(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['list_search']['event']['date_range'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['date-range-container']],
    ];

    $form['list_search']['event']['date_range']['from'] = [
      '#type' => 'date',
      '#title' => $this->t('Occurring from'),
      '#date_date_format' => 'Y-m-d',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['list_search']['event']['date_range']['to'] = [
      '#type' => 'date',
      '#title' => $this->t('to'),
      '#date_date_format' => 'Y-m-d',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    // Event Detail Information.
    $form['list_search']['event_detail'] = [
      '#type' => 'details',
      '#title' => $this->t('Event Detail Information'),
      '#open' => TRUE,
    ];

    $form['list_search']['event_detail']['rescue_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Rescue Type'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getRescueTypes(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['list_search']['event_detail']['rescue_cause'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rescue Cause *'),
      '#maxlength' => 128,
      '#size' => 64,
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['list_search']['event_detail']['organization'] = [
      '#type' => 'select',
      '#title' => $this->t('Organization'),
      '#options' => ['All' => $this->t('All')] + $this->searchManager->getOrganizations(),
      '#default_value' => 'All',
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['list_search']['event_detail']['cause_of_death'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cause of Death *'),
      '#maxlength' => 128,
      '#size' => 64,
      '#wrapper_attributes' => ['class' => ['form-item']],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['form-actions']],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
      '#button_type' => 'primary',
    ];

    // Add results container.
    $form['search_results'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['search-results-container']],
    ];

    // Show results if form has been submitted.
    if ($form_state->get('show_results')) {
      $conditions = $this->processSearchParameters($form_state->getValues());
      $form['search_results'] = $this->buildSearchResults($conditions);
    }

    return $form;
  }

  /**
   * Process search parameters from form values.
   */
  protected function processSearchParameters(array $values) {
    $conditions = [];

    // Individual search parameters.
    if (!empty($values['mlog'])) {
      $conditions[] = [
        'field' => 'field_mlog',
        'value' => $values['mlog'],
      ];
    }

    if (!empty($values['animal_id'])) {
      $conditions[] = [
        'type' => 'manatee_animal_id',
        'field' => 'field_animal_id',
        'value' => $values['animal_id'],
        'operator' => 'CONTAINS',
      ];
    }

    if (!empty($values['manatee_name'])) {
      $conditions[] = [
        'type' => 'manatee_name',
        'field' => 'field_name',
        'value' => $values['manatee_name'],
        'operator' => 'CONTAINS',
      ];
    }

    if (!empty($values['tag_id'])) {
      $conditions[] = [
        'type' => 'manatee_tag',
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
        ->condition('type', 'manatee_rescue')
        ->condition('field_county', $values['county'])
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($rescue_query)) {
        $manatee_ids = [];
        $rescues = $this->entityTypeManager->getStorage('node')->loadMultiple($rescue_query);
        foreach ($rescues as $rescue) {
          if (!$rescue->field_animal->isEmpty()) {
            $manatee_ids[] = $rescue->field_animal->target_id;
          }
        }

        if (!empty($manatee_ids)) {
          $conditions[] = [
            'field' => 'nid',
            'value' => $manatee_ids,
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

    if (!empty($values['rescue_cause'])) {
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
        'field' => 'field_cause_of_death',
        'value' => $values['cause_of_death'],
      ];
    }

    return $conditions;
  }

  /**
   * Get MLog value for a manatee.
   */
  protected function getMlog($manatee) {
    return !$manatee->field_mlog->isEmpty() ? $manatee->field_mlog->value : 'N/A';
  }

  /**
   * Get primary name for a manatee.
   */
  protected function getPrimaryName($manatee_id) {
    $name_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_name')
      ->condition('field_animal', $manatee_id)
      ->condition('field_primary', 1)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($name_query)) {
      $name_node = $this->entityTypeManager->getStorage('node')->load(reset($name_query));
      if ($name_node && !$name_node->field_name->isEmpty()) {
        return $name_node->field_name->value;
      }
    }
    return 'N/A';
  }

  /**
   * Get animal ID for a manatee.
   */
  protected function getAnimalId($manatee_id) {
    $id_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'manatee_animal_id')
      ->condition('field_animal', $manatee_id)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($id_query)) {
      $id_node = $this->entityTypeManager->getStorage('node')->load(reset($id_query));
      if ($id_node && !$id_node->field_animal_id->isEmpty()) {
        return $id_node->field_animal_id->value;
      }
    }
    return 'N/A';
  }

  /**
   * Get latest event for a manatee.
   */
  protected function getLatestEvent($manatee_id, $specific_type = NULL) {
    $event_types = [
      'manatee_birth' => 'field_birth_date',
      'manatee_rescue' => 'field_rescue_date',
      'transfer' => 'field_transfer_date',
      'manatee_release' => 'field_release_date',
      'manatee_death' => 'field_death_date',
    ];

    if ($specific_type) {
      if (isset($event_types[$specific_type])) {
        $date_field = $event_types[$specific_type];
        $query = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', $specific_type)
          ->condition('field_animal', $manatee_id)
          ->condition($date_field, NULL, 'IS NOT NULL')
          ->sort($date_field, 'DESC')
          ->range(0, 1)
          ->accessCheck(FALSE);

        $results = $query->execute();
        if (!empty($results)) {
          $node = $this->entityTypeManager->getStorage('node')->load(reset($results));
          if ($node && !$node->get($date_field)->isEmpty()) {
            $date_value = $node->get($date_field)->value;
            $formatted_date = date('m/d/Y', strtotime($date_value));
            return [
              'type' => ucfirst(str_replace(['manatee_', '_'], ['', ' '], $specific_type)),
              'date' => $formatted_date,
            ];
          }
        }
        return ['type' => 'N/A', 'date' => 'N/A'];
      }
    }

    $events = [];
    foreach ($event_types as $type => $date_field) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $type)
        ->condition('field_animal', $manatee_id)
        ->condition($date_field, NULL, 'IS NOT NULL')
        ->sort($date_field, 'DESC')
        ->range(0, 1)
        ->accessCheck(FALSE);

      $results = $query->execute();

      if (!empty($results)) {
        $node = $this->entityTypeManager->getStorage('node')->load(reset($results));
        if ($node && !$node->get($date_field)->isEmpty()) {
          $date_value = $node->get($date_field)->value;
          $formatted_date = date('m/d/Y', strtotime($date_value));
          $events[] = [
            'type' => str_replace('manatee_', '', $type),
            'date' => $formatted_date,
          ];
        }
      }
    }

    if (empty($events)) {
      return ['type' => 'N/A', 'date' => 'N/A'];
    }

    usort($events, function ($a, $b) {
      return strcmp($b['date'], $a['date']);
    });

    $latest = $events[0];
    return [
      'type' => ucfirst(str_replace('_', ' ', $latest['type'])),
      'date' => $latest['date'],
    ];
  }

  /**
   * Build search results render array.
   */
  protected function buildSearchResults($conditions) {
    $items_per_page = 20;
    $manatee_ids = $this->searchManager->searchManatees($conditions);
    $total_items = count($manatee_ids);

    $pager = \Drupal::service('pager.manager')->createPager($total_items, $items_per_page);
    $current_page = $pager->getCurrentPage();

    $page_manatee_ids = array_slice($manatee_ids, $current_page * $items_per_page, $items_per_page);

    if (empty($page_manatee_ids)) {
      return [
        '#markup' => $this->t('No manatees found matching your search criteria.'),
      ];
    }

    $manatees = $this->entityTypeManager->getStorage('node')->loadMultiple($page_manatee_ids);

    $rows = [];
    foreach ($manatees as $manatee) {
      $manatee_id = $manatee->id();
      $event_type = $this->getRequest()->query->get('event_type');
      $latest_event = $this->getLatestEvent($manatee_id, $event_type);

      $mlog = $this->getMlog($manatee);
      $mlog_link = Link::createFromRoute(
        $mlog,
        'entity.node.canonical',
        ['node' => $manatee_id]
      );

      $rows[] = [
        'data' => [
          ['data' => $mlog_link],
          ['data' => $this->getPrimaryName($manatee_id)],
          ['data' => $this->getAnimalId($manatee_id)],
          ['data' => $latest_event['type']],
          ['data' => $latest_event['date']],
        ],
      ];
    }

    $header = [
      $this->t('MLog'),
      $this->t('Name'),
      $this->t('Animal ID'),
      $this->t('Event'),
      $this->t('Last Event'),
    ];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['manatee-search-results']],
      'count' => [
        '#markup' => $this->t('@count manatees found', ['@count' => $total_items]),
        '#prefix' => '<div class="results-count">',
        '#suffix' => '</div>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No results found'),
        '#attributes' => ['class' => ['manatee-report-table']],
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
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
    $form_state->set('show_results', TRUE);
    $form_state->setRebuild(TRUE);
  }

}
