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
 * Get the date from content referenced in the paragraph
 * 
 * @SearchApiProcessor(
 *  id = "fws_search_date_from_paragraph",
 *  label = @Translation("FWS Date from Paragraphs"),
 *  description = @Translation("Get's the date from content referenced in a paragraph (currently works with 7 paragraph types)."),
 *  stages = {
 *      "add_properties" = 20,
 *  },
 * )
 */
class DateFromParagraph extends ProcessorPluginBase {
    public static $key = 'fws_search_date_from_paragraph';
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
                'label' => "Date from Paragraphs",
                'description' => 'Index the date from referenced content in paragraphs for filtering.',
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
            if($property_path == "fws_search_date_from_paragraph"){
                $bundle = $entity->bundle();
                $date_fields = array (
                    "federal_register_document" => "field_publication_date",
                    "image" => "field_document_publication_date",
                    "laws_agreements_treaties" => "field_date_enacted",
                    "promotable_document_reference" => "field_document_publication_date",
                    "promotable_news_story" => "field_post_date",
                    "promotable_service_reference" => "field_post_date",
                    "promotable_press_release" => "field_post_date",
                    "testimony" => "field_post_date"
                );
                $fields = array(
                    "federal_register_document" => "field_fed_reg_doc_media",
                    "image" => "field_image",
                    "laws_agreements_treaties" => "field_laws_agreements_treaties",
                    "promotable_document_reference" => "field_document_ref_single",
                    "promotable_news_story" => "field_news_story",
                    "promotable_service_reference" => "field_service_reference",
                    "promotable_press_release" => "field_press_release",
                    "testimony" => "field_testimony_reference"
                );
                if(array_key_exists($bundle,$fields)){
                    $type = "node";
                    if($bundle == "image" || $bundle == "promotable_document_reference" || $bundle == "federal_register_document"){
                        $type = "media";
                    }
                    if($entity->get($fields[$bundle])){
                        $node = \Drupal::entityTypeManager()->getStorage($type)->load($entity->get($fields[$bundle])->target_id);
                        if($node && $node->get($date_fields[$bundle])){
                            $date_field = $node->get($date_fields[$bundle]);
                            if($date_field && count($date_field) > 0 && $date_field[0]){
                                $field->addValue($date_field[0]->value);
                            }
                        }
                    }
                }
            }
        }
    }
}