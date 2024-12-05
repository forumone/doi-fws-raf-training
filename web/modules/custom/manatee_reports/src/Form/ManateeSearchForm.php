<?php

namespace Drupal\manatee_reports\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\manatee_reports\ManateeSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a manatee search form.
 */
class ManateeSearchForm extends FormBase {

  protected $searchManager;

  public function __construct(ManateeSearchManager $search_manager) {
    $this->searchManager = $search_manager;
  }

  /**
   *
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('manatee_reports.search_manager')
    );
  }

  /**
   *
   */
  public function getFormId() {
    return 'manatee_search_form';
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#method'] = 'get';
    $form['#action'] = '/manatee/search/results';
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
      '#options' => array_merge(
        ['All' => $this->t('All')],
        $this->searchManager->getCounties()
      ),
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
      '#options' => array_merge(
        ['All' => $this->t('All')],
        $this->searchManager->getStates()
      ),
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
      '#options' => array_merge(
        ['All' => $this->t('All')],
        $this->searchManager->getEventTypes()
      ),
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

    $form['list_search']['event']['date_range']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('To search for just one day, enter the same date in both "from" date and "to" date'),
      '#attributes' => ['class' => ['date-range-description']],
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
      '#options' => array_merge(
        ['All' => $this->t('All')],
        $this->searchManager->getRescueTypes()
      ),
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
      '#options' => array_merge(
        ['All' => $this->t('All')],
        $this->searchManager->getOrganizations()
      ),
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
      '#value' => $this->t('Next'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   *
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $individual_fields = [
      'mlog',
      'animal_id',
      'manatee_name',
      'tag_id',
    ];

    $filled_fields = 0;
    foreach ($individual_fields as $field) {
      if (!empty($form_state->getValue(['individual_search', 'manatee_info', $field]))) {
        $filled_fields++;
      }
    }

    if ($filled_fields > 1) {
      $form_state->setErrorByName(
        'individual_search',
        $this->t('Please enter only one field for individual manatee search.')
      );
    }

    $from_date = $form_state->getValue(['list_search', 'event', 'date_range', 'from']);
    $to_date = $form_state->getValue(['list_search', 'event', 'date_range', 'to']);

    if (!empty($from_date) && !empty($to_date)) {
      $from = strtotime($from_date);
      $to = strtotime($to_date);

      if ($from > $to) {
        $form_state->setErrorByName(
          'list_search][event][date_range][to',
          $this->t('The end date must be later than or equal to the start date.')
        );
      }
    }
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $query = [];

    // Individual search parameters.
    $individual_fields = [
      'mlog',
      'animal_id',
      'manatee_name',
      'tag_id',
      'tag_type',
    ];

    foreach ($individual_fields as $field) {
      $value = $form_state->getValue(['individual_search', 'manatee_info', $field]);
      if (!empty($value) && $value !== 'All') {
        $query[$field] = $value;
      }
    }

    // List search parameters.
    $list_search_mapping = [
      'location' => [
        'county',
        'waterway',
        'state',
      ],
      'event' => [
        'event_type',
        'date_range' => ['from', 'to'],
      ],
      'event_detail' => [
        'rescue_type',
        'rescue_cause',
        'organization',
        'cause_of_death',
      ],
    ];

    foreach ($list_search_mapping as $section => $fields) {
      foreach ($fields as $key => $field) {
        if (is_array($field)) {
          foreach ($field as $subfield) {
            $value = $form_state->getValue(['list_search', $section, 'date_range', $subfield]);
            if (!empty($value)) {
              $query["date_$subfield"] = $value;
            }
          }
        }
        else {
          $value = $form_state->getValue(['list_search', $section, $field]);
          if (!empty($value) && $value !== 'All') {
            $query[$field] = $value;
          }
        }
      }
    }

    $form_state->setRedirect('manatee_reports.search_results', [], ['query' => $query]);
  }

}
