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
 *  id = "fws_search_media_and_content_type_processor",
 *  label = @Translation("FWS Media and Content Type Processor"),
 *  description = @Translation("Combines media and content types into one field."),
 *  stages = {
 *      "add_properties" = 20,
 *  },
 * )
 */
class MediaAndContenttypeProcessor extends ProcessorPluginBase {
    public static $key = 'fws_search_media_and_content_type_processor';
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
        if(!$datasource) {
            $path = 'fws_search_media_and_content_type_processor';// . $property_name;
            $properties[$path] = new ProcessorProperty([
                'label' => "Media and Content Type Pretty Name Index",
                'description' => 'Requires aggregate of bundle and type called combined_type and returns the name for each.',
                'type' => 'string',
                'is_list' => FALSE,
                'processor_id' => $this->getPluginId()
            ]);
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
            // if the processor field is in the list of fields then add in bundle/type
            $property_path = $field->getPropertyPath();
            //$pp_parts = explode('__',$property_path);
            if($property_path === 'fws_search_media_and_content_type_processor') {
                $value = $this->getBundleLabel($entity);
                //Correct content types with poor names
                if($value == "Basic Single Page") {
                    $value = "Page";
                } else if ($value == "One Column Page") {
                    $value = "Primary Page";
                }
                
                $field->addValue($value);
            }
        }
    }

    private function getBundleLabel(EntityInterface $entity) {
        static $labels = [];
        $entity_type_id = $entity->getEntityTypeId();
        $bundle = $entity->bundle();
        $key = "${entity_type_id}:${bundle}";
        if(!isset($labels[$key])) {
            $etype = $entity->getEntityType();
            $bundle_storage = $this->getEntityTypeManager()->getStorage($etype->getBundleEntityType());
            /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface */
            $bundle_config = $bundle_storage->load($bundle);
            if($bundle_config->get('name')){
                $labels[$key] = $bundle_config->get('name');
            }else if($bundle_config->get('label')){
                $labels[$key] = $bundle_config->get('label');
            }
        }
        return $labels[$key];
    }
}