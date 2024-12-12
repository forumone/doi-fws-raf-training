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
 * @SearchApiProcessor(
 *  id = "fws_search_content_type_label",
 *  label = @Translation("FWS Type (label)"),
 *  description = @Translation("An item's type label."),
 *  stages = {
 *      "add_properties" = 20,
 *  },
 * )
 */
class ContentTypeLabel extends ProcessorPluginBase {
    public static $key = 'fws_search_content_type_label';
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
                'label' => "Type (label)",
                'description' => 'An entity type label rather than machine name.',
                'type' => 'string',
                'is_list' => FALSE,
                'processor_id' => $this->getPluginId(),
            ]),
        ];
    }
    /**
     * {@inheritdoc}
     */
    public function addFieldValues(ItemInterface $item) {
        $log = \Drupal::logger('fws_search');
        foreach ($item->getFields() as $field) {
            if(self::$key === $field->getPropertyPath()) {
                /** @var \Drupal\Core\Entity\EntityInterface  */
                $entity = $item->getOriginalObject()->getValue();
                $field->addValue($this->getBundleLabel($entity));
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