<?php
namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\search_api\IndexInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @SearchApiProcessor(
 *  id = "fws_search_paragraph_weight",
 *  label = @Translation("FWS Paragraph Weight"),
 *  description = @Translation("For indexes indexing paragraphs, this exposes the weight of the parent field to allow searches to preserve the order the paragraphs were added/ordered on the parent field"),
 *  stages = {
 *      "add_properties" = 20,
 *  },
 * )
 */
class ParagraphWeight extends ProcessorPluginBase {
    public static $key = 'fws_search_paragraph_weight';
    /**
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface|null
     */
    private $entityTypeManager;

    /**
     * @var \Drupal\Core\Entity\EntityFieldManagerInterface
     */
    private $entityFieldManager;

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
        /** @var static $processor */
        $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
        return $processor
            ->setEntityTypeManager($container->get('entity_type.manager'))
            ->setEntityFieldManager($container->get('entity_field.manager'));
    }
    
    /**
     * @return \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    public function getEntityTypeManager() {
        return $this->entityTypeManager ?: \Drupal::entityTypeManager();
    }

    /**
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     * @return $this
     */
    public function setEntityTypeManager(EntityTypeManagerInterface $entity_type_manager) {
        $this->entityTypeManager = $entity_type_manager;
        return $this;
    }

    /**
     * @return \Drupal\Core\Entity\EntityFieldManagerInterface
     */
    public function getEntityFieldManager() {
        return $this->entityFieldManager;
    }

    /**
     * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
     * @return $this
     */
    public function setEntityFieldManager(EntityFieldManagerInterface $entity_field_manager) {
        $this->entityFieldManager = $entity_field_manager;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
        $properties = [];
        //Only add this for fields with name field_age_range
        if($datasource) {
            $fields = self::getFieldsByParagraphBundle();
            $entity_type = $datasource->getEntityTypeId();

            //add each paragraph field as an option to use their weights
            foreach($fields as $field) {
                $key = 'fws_search__'.$entity_type.'__'.$field.'__weight';
                $label = 'FWS Search Paragraph Weight: '.$field;
                $definition = [
                    'label' => $label,
                    'description' => $label,
                    'type' => 'integer', //number_integer
                    'is_list' => false,
                    'processor_id' => $this->getPluginId(),
                ];
                $properties[$key] = new ProcessorProperty($definition);
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

        foreach ($item->getFields() as $field) {
            $property_path = $field->getPropertyPath();
            $pp_parts = explode('__',$property_path);
            if(count($pp_parts) > 3 && $pp_parts[0] === 'fws_search' 
                && $pp_parts[1] === 'paragraph' && $pp_parts[3] === 'weight') {
                    $node = \Drupal::entityTypeManager()->getStorage('node')->load($entity->parent_id->value);
                    if($node && $node->hasField($pp_parts[2])){
                        $weight = $this->findObjectByTargetId($node->get($pp_parts[2]),$entity->id->value);
                        if($weight != -1){
                            $field->addValue($weight);
                        }
                    }
            }
        }
     }

    /**
     * Find object in an array of objects
    */
    private function findObjectByTargetId($array,$id){
        if($array == NULL || count($array) == 0){
            return -1;
        }
        foreach ( $array as $key=>$element ) {
            if ( $id == $element->target_id ) {
                return $key;
            }
        }
        return -1;
    }

     /**
     * I stole this from the ParagraphsInUse.php as a test. I didn't need anyting but the field list of paragraph
     * fields, so i modified it a bit, but of course this should be edited. 
     * 
     * @return array keyed by paragraph bundle type to field name.
     */
    private function getFieldsByParagraphBundle() {
        static $map = NULL;
        if(!$map) {
            $node_type_storage = $this->getEntityTypeManager()->getStorage('node_type');
            $content_types = $node_type_storage->loadMultiple();
            $map = [];
            foreach(array_keys($content_types) as $bundle) {
                $field_defs = $this->getEntityFieldManager()->getFieldDefinitions('node',$bundle);
                foreach($field_defs as $field_name => $field_def) {
                    if($field_def->getType() === 'entity_reference_revisions') {
                        $settings = $field_def->getSettings();
                        if(isset($settings['target_type']) && $settings['target_type'] == 'paragraph' &&
                            isset($settings['handler_settings']) && isset($settings['handler_settings']['target_bundles'])) {
                            $paragraph_bundles = $settings['handler_settings']['target_bundles'];                        
                            foreach($paragraph_bundles as $paragraph_bundle) {
                                // if(!isset($map[$paragraph_bundle])) {
                                //     $map[$paragraph_bundle] = [];
                                // }
                                if(!in_array($field_name,$map)) {
                                    $map[] = $field_name;            
                                }                
                            }
                        }
                    }
                }
            }
        }
        return $map;
    }
}