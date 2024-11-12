<?php
namespace Drupal\fws_search\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for the SearchAppConfig add and edit forms.
 */
class SearchAppConfigForm extends EntityForm {

  /**
   * @var \Drupal\fws_search\SearchAppConfigIfc
   */
  protected $entity;

  /**
   * Constructs an SearchAppConfigForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entityTypeManager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $config = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $config->label(),
      '#description' => $this->t("Label for the config."),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $config->id(),
      '#machine_name' => [
        'exists' => [$this, 'exist'],
      ],
      '#description' => $this->t("The machine name for this search app."),
      '#disabled' => !$config->isNew(),
    ];
    $form['index'] = [
        '#type' => 'select',
        '#title' => $this->t('Index'),
        '#default_value' => $config->index,
        '#description' => $this->t("The machine name of the corresponding search index."),
        '#required' => TRUE,
        '#options' => \Drupal::entityQuery('search_api_index')->accessCheck(TRUE)->execute(),
    ];
    $form['root'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Root'),
        '#maxlength' => 255,
        '#default_value' => $config->root,
        '#description' => $this->t("The root element of the app."),
        '#required' => TRUE,
    ];
$yaml_description = <<<DESC
<p>YAML for configuring this search app.</p>
<p>TODO provide correct and useful reference documentation here.</p>
<pre>
top:
    - { type: block, config: { uuid: fc42fef8-d615-4920-8eff-ae6c6b36b946 } }
    - { filter: \$keywords, type: keywords }
    - { filter: az_title, type: azList }
left:
    - { filter: bool, type: boolean }
    - { filter: type, type: selectMultiple }
    - { type: label, config: { label: "Pick one or more regions" } }
    - { filter: region, type: region }
bottom:
    - { filter: \$skip, type: pager }
serviceDefaults:
    # the app's page size
    \$top: 10
    # the default sort order
    \$orderby: title desc
</pre>
<p>Note: When configuring a component of type <code>block</code> you can specify the block id (number) for the <code>uuid</code> value.  On save it will be replaced with the block's <code>uuid</code>.</p>
DESC;
    $form['config'] = [
        '#type' => 'textarea',
        '#title' => $this->t('SearchApp Config (YAML)'),
        '#default_value' => $config->config,
        '#description' => $yaml_description,
        '#rows' => 25,
        '#required' => FALSE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $config = $this->entity;
    $status = $config->save();

    if ($status) {
      $this->messenger()->addMessage($this->t('Saved the %label Search app config.', [
        '%label' => $config->label(),
      ]));
    }
    else {
      $this->messenger()->addMessage($this->t('The %label Search app config was not saved.', [
        '%label' => $config->label(),
      ]), MessengerInterface::TYPE_ERROR);
    }

    $form_state->setRedirect('entity.fws_search_app_config.collection');
  }

  /**
   * Helper function to check whether an SearchAppConfig entity exists.
   */
  public function exist($id) {
    $entity = $this->entityTypeManager->getStorage('fws_search_app_config')->getQuery()
      ->accessCheck(TRUE)
      ->condition('id', $id)
      ->execute();
    return (bool) $entity;
  }

}