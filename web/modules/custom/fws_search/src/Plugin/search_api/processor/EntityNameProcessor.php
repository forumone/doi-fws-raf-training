<?php
namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Entity\ContentEntityInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @SearchApiProcessor(
 *  id = "fws_search_entity_name_processor",
 *  label = @Translation("FWS Entity Name Processor"),
 *  description = @Translation("Processes a term name, node title, and media label instead of the id."),
 *  stages = {
 *      "add_properties" = 20,
 *  },
 * )
 */
class EntityNameProcessor extends ProcessorPluginBase {
    public static $key = 'fws_search_entity_name_processor';
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
        $properties = [];
        if($datasource) {
            $entity_type = $datasource->getEntityTypeId();
            $bundles = $datasource->getBundles();
            foreach(array_keys($bundles) as $bundle){
                $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $bundle);
                foreach($definitions as $definition) {
                    if($definition->getType() === 'entity_reference') {
                        $property_name = $definition->getName();
                        $path = 'fws_search_entity_name__'.$property_name;
                        $properties[$path] = new ProcessorProperty([
                            'label' => 'FWS Search Entity Name - '.$property_name,
                            'description' => 'Index the name of the entity reference: '.$property_name,
                            'type' => 'string',
                            'is_list' => TRUE,
                            'processor_id' => $this->getPluginId(),
                        ]);
                    }
                }
            }
        }
        return $properties;
    }

    /**
     * For each item get the label for media, the name for taxonomy and title for content node
     * {@inheritdoc}
     */
    public function addFieldValues(ItemInterface $item) {
        $entity = $item->getOriginalObject()->getValue();
        if(!($entity instanceof ContentEntityInterface)) {
            return;
        }        
        foreach ($item->getFields() as $field) {
            // if the processor field is in the list of fields then add in bundle/type
            $property_path = $field->getPropertyPath();
            $pp_parts = explode('__',$property_path);
            if($pp_parts[0] === 'fws_search_entity_name') {
                $property_name = $pp_parts[1];
                if($entity->hasField($property_name)) {
                    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList */
                    $entity_field = $entity->get($property_name);
                    foreach ($entity_field->referencedEntities() as $item) {
                        if($item->title != null) {
                            $field->addValue($item->get("title")->value);
                        }else if($item->name != null) {
                            $field->addValue($item->get("name")->value);
                        }else {
                            //dd($item);
                        }        
                    }
                }
            }
        }
    }
}