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
 *  id = "fws_search_national_processor",
 *  label = @Translation("FWS National Processor"),
 *  description = @Translation("Adds all states and regions for indexing if content is national."),
 *  stages = {
 *      "add_properties" = 20,
 *  },
 * )
 */
class NationalProcessor extends ProcessorPluginBase {
    public static $key = 'fws_search_national_processor';
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
        //Only add this for fields with name field_age_range
        if($datasource) {
            $entity_type = $datasource->getEntityTypeId();
            $bundles = $datasource->getBundles();
            $bundle_keys = array_keys($bundles);
            $bundle = reset($bundle_keys);
            $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $bundle);
            foreach($definitions as $definition) {
                $property_name = $definition->getName();
                if($property_name === 'field_state') {
                    $path = 'fws_search_national_processor__' . $property_name;
                    $properties[$path] = new ProcessorProperty([
                        'label' => "States - if national then add all states",
                        'description' => 'If content is set to national then index all states and regions.',
                        'type' => 'string',
                        'is_list' => TRUE,
                        'processor_id' => $this->getPluginId()
                    ]);
                }
            }
        }
        return $properties;
    }
    /**
     * {@inheritdoc}
     */
    public function addFieldValues(ItemInterface $item) {
        $entity = $item->getOriginalObject()->getValue();
        if(!($entity instanceof ContentEntityInterface)) {
            return;
        }
        
        $national_field = 'field_national_program_flag';
        
        foreach ($item->getFields() as $field) {
            $property_path = $field->getPropertyPath();
            $pp_parts = explode('__',$property_path);
            if($pp_parts[0] === 'fws_search_national_processor') {
                $property_name = $pp_parts[1]; // eg the field
                $entity_field = $entity->get($national_field);
                $state_field = $entity->get($property_name);
                
                // This tells us if it is a national program
                if($entity_field != NULL && $entity_field->value == "1"){
                    // Get all the states from taxonomy
                    $query = \Drupal::entityQuery('taxonomy_term');
                    $query->accessCheck(TRUE);
                    $query->condition('vid', 'state');
                    $tids = $query->execute();
                    $terms = \Drupal\taxonomy\Entity\Term::loadMultiple($tids);
                    //Dump all the states in the field
                    foreach ($terms as $term) {
                        $field->addValue($term->name->value);
                    }
                } else {
                    // Not National, just add the states from the state field
                    foreach($state_field->getValue() as $tid){
                        $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid['target_id']);
                        $title = $term->name->value;
                        $field->addValue($title);
                    }
                }
            }
        }
    }
}