<?php
namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Get the state from content referenced in the paragraph
 * 
 * @SearchApiProcessor(
 *  id = "fws_search_state_from_paragraph",
 *  label = @Translation("FWS State from Paragraphs"),
 *  description = @Translation("Get's the state from content referenced in a paragraph (currently works with programs, facility, service, and staff profiless)."),
 *  stages = {
 *      "add_properties" = 20,
 *  },
 * )
 */
class StateFromParagraph extends ProcessorPluginBase {
    public static $key = 'fws_search_state_from_paragraph';
    /**
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface|null
     */
    private $entityTypeManager;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
        /** @var static $processor */
        $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        return $processor->setEntityTypeManager($container->get('entity_type.manager'));
    }
    /**
     * Retrieves the entity type manager.
     *
     * @return \Drupal\Core\Entity\EntityTypeManagerInterface
     *   The entity type manager.
     */
    public function getEntityTypeManager() {
        return $this->entityTypeManager ?: \Drupal::entityTypeManager();
    }

    /**
     * Sets the entity type manager.
     *
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The new entity type manager.
     *
     * @return $this
     */
    public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager) {
        $this->entityTypeManager = $entity_type_manager;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
        // allow to apply generically or to any data source
        return [
            self::$key => new ProcessorProperty([
                'label' => "States from Paragraphs",
                'description' => 'Index the states from referenced content in paragraphs for filtering.',
                'type' => 'string',
                'is_list' => TRUE,
                'processor_id' => $this->getPluginId(),
            ]),
        ];
    }
    /**
     * {@inheritdoc}
     */
    public function addFieldValues(ItemInterface $item) {
        $log = \Drupal::logger('fws_search');
        $entity = $item->getOriginalObject()->getValue();
        foreach ($item->getFields() as $field) {
            $property_path = $field->getPropertyPath();
            if($property_path == "fws_search_state_from_paragraph"){
                $bundle = $entity->bundle();
                if( $bundle == "facility" || $bundle == "program"
                    || $bundle == "service" || $bundle == "staff_profile"){
                    $field_name =  'field_'. $bundle .'_reference';
                    if($bundle == "staff_profile"){
                        $field_name = 'field_'. $bundle;
                    }
                    if($entity->get($field_name)){
                        $node = \Drupal::entityTypeManager()->getStorage('node')->load($entity->get($field_name)->target_id);
                        if($node && $node->field_state && $node->field_state->target_id){
                            $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($node->field_state->target_id);
                            if($term){
                                $field->addValue($term->name->value);
                            }
                        }
                    }
                }
            }
        }
    }
}