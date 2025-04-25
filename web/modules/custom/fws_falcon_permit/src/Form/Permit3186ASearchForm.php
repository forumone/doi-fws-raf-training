<?php

namespace Drupal\fws_falcon_permit\Form;

use Drupal\Core\Url;
use Drupal\Core\Form\FormBase;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
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
    return 'fws_falcon_permit_search';
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

    $form['transfer_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type of Transfer'),
      '#options' => $this->searchHelper->getTaxonomyTermOptions('type_of_acquisition'),
      '#empty_option' => $this->t('- None -'),
      '#empty_value' => '',
    ];

    $form['recipient_first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient First name'),
    ];

    $form['recipient_last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient Last name'),
    ];

    $form['ownership'] = [
      '#title' => $this->t('Falcon/Raptor'),
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#selection_settings' => [
        'include_anonymous' => FALSE,
        'filter' => [
          'role' => ['falconer'],
        ],
      ],
      '#maxlength' => 64,
      '#size' => 64,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $filter_values = $this->getFilterValues();
    $form = $this->buildFilterForm($form, $form_state);

    $default_values = $filter_values;
    $default_values['ownership'] = NULL;
    if (isset($filter_values['ownership'])) {
      $user = $this->entityTypeManager
        ->getStorage('user')
        ->load($filter_values['ownership']);
      if ($user instanceof UserInterface && $user->isActive()) {
        $default_values['ownership'] = $user;
      }
    }
    foreach ($default_values as $filter => $value) {
      if (isset($form[$filter])) {
        $form[$filter]['#default_value'] = $value;
      }
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
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
        '#weight' => 110,
      ];

      // Add Export CSV button at the top of the results table
      $form['search_results']['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['results-actions']],
        '#weight' => -10,
      ];

      $form['search_results']['actions']['export_csv'] = [
        '#type' => 'submit',
        '#value' => $this->t('Export CSV'),
        '#submit' => ['::exportCsvSubmit'],
        '#button_type' => 'secondary',
        '#attributes' => ['class' => ['button--export-csv']],
      ];

      $form['search_results']['results'] = $this->buildSearchResults($filter_values);

      if (!isset($form['search_results']['results']['table'])) {
        $form['search_results']['actions']['export_csv']['#access'] = FALSE;
      }
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
   * Submit handler for the Export CSV button.
   */
  public function exportCsvSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->cleanValues();
    $filter_values = array_filter($form_state->getValues());
    $nids = $this->searchHelper->getSearchResults($filter_values);

    $filename = 'permit_3186a_export_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    $this->writeHeadersToOutput($output);

    // Process nodes in chunks to reduce memory usage
    $chunk_size = 50;
    $chunks = array_chunk($nids, $chunk_size);

    foreach ($chunks as $chunk) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($chunk);
      $this->writeNodesToOutput($nodes, $output);
      $this->entityTypeManager->getStorage('node')->resetCache($chunk);
    }

    fclose($output);
    exit();
  }

  /**
   * Write CSV headers to the output stream.
   */
  protected function writeHeadersToOutput($output) {
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'permit_3186a');

    $headers = [];

    foreach ($field_definitions as $field_name => $definition) {
      // Only include custom fields (those starting with 'field_')
      if (strpos($field_name, 'field_') === 0) {
        $headers[] = $definition->getLabel();
      }
    }

    fputcsv($output, $headers);
  }

  /**
   * Write nodes to the CSV output stream.
   */
  protected function writeNodesToOutput($nodes, $output) {
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', 'permit_3186a');

    // Create a list of field names to export (only custom fields)
    $field_names = [];
    foreach ($field_definitions as $field_name => $definition) {
      if (strpos($field_name, 'field_') === 0) {
        $field_names[] = $field_name;
      }
    }

    // Add data rows
    foreach ($nodes as $node) {
      $row = [];

      foreach ($field_names as $field_name) {
        if ($node->hasField($field_name)) {
          $field = $node->get($field_name);

          if ($field->isEmpty()) {
            $row[] = '';
          }
          elseif ($field->getFieldDefinition()->getType() == 'entity_reference') {
            $labels = [];
            foreach ($field as $item) {
              if ($item->entity && method_exists($item->entity, 'label')) {
                $labels[] = $item->entity->label();
              }
            }
            $row[] = implode(', ', $labels);
          }
          elseif ($field->getFieldDefinition()->getType() == 'datetime') {
            if (!empty($field->value)) {
              $date = new \DateTime($field->value);
              $row[] = $date->format('Y-m-d');
            } else {
              $row[] = '';
            }
          }
          elseif ($field->getFieldDefinition()->getType() == 'boolean') {
            $row[] = $field->value ? 'Yes' : 'No';
          }
          elseif (in_array($field->getFieldDefinition()->getType(), ['string', 'string_long', 'text', 'text_long', 'text_with_summary'])) {
            $row[] = $field->value;
          }
          else {
            $row[] = $field->value;
          }
        }
        else {
          $row[] = '';
        }
      }

      fputcsv($output, $row);
    }
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
        'id' => $node->field_recno->value,
        'state' => $node->field_owner_state->entity ? $node->field_owner_state->entity->label() : '',
        'species_name' => $node->field_species_name->entity ? $node->field_species_name->entity->label() : '',
        'sender_first_name' => $node->field_sender_first_name->value,
        'sender_last_name' => $node->field_sender_last_name->value,
        'recipient_first_name' => $node->field_recipient_first_name->value,
        'recipient_last_name' => $node->field_recipient_last_name->value,
        'date_created' => !empty(trim($node->field_dt_create->value)) ? date('Y-m-d', strtotime($node->field_dt_create->value)) : '',
        'actions' => [],
      ];

      // Define operations as options for the select field
      $operations = [
        '' => $this->t('- Select operation -'),
        'view' => $this->t('View'),
      ];

      // Add Edit option if user has access
      if ($node->access('update') && $node->hasLinkTemplate('edit-form')) {
        $operations['edit'] = $this->t('Edit');
      }

      // Add Delete option if user has access
      if ($node->access('delete') && $node->hasLinkTemplate('delete-form')) {
        $operations['delete'] = $this->t('Delete');
      }

      // Create a select element
      $select = [
        '#type' => 'select',
        '#title' => $this->t('Operations'),
        '#title_display' => 'invisible',
        '#options' => $operations,
        '#name' => 'operation_' . $node->id(),
        '#attributes' => [
          'class' => ['operation-select'],
          'data-node-id' => $node->id(),
        ],
      ];

      // Add select to row
      $row['actions'] = ['data' => $select];

      $rows[] = $row;
    }

    return [
      '#attached' => [
        'library' => ['fws_falcon_permit/permit_operations'],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'id' => $this->t('Permit Number'),
          'state' => $this->t('State'),
          'species_name' => $this->t('Species Name'),
          'sender_first_name' => $this->t('Sender First Name'),
          'sender_last_name' => $this->t('Sender Last Name'),
          'recipient_first_name' => $this->t('Recipient First Name'),
          'recipient_last_name' => $this->t('Recipient Last Name'),
          'date_created' => $this->t('Date'),
          'actions' => $this->t('Operations'),
        ],
        '#rows' => $rows,
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

}
