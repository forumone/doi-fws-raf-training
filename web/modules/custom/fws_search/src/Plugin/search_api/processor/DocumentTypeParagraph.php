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
 * Get the document type from the document referenced in the paragraph
 * 
 * @SearchApiProcessor(
 *  id = "fws_search_doc_type_paragraph",
 *  label = @Translation("FWS Document Type For Paragraphs"),
 *  description = @Translation("Get's the document type from a document referenced in a paragraph."),
 *  stages = {
 *      "add_properties" = 20,
 *  },
 * )
 */
class DocumentTypeParagraph extends ProcessorPluginBase {
    public static $key = 'fws_search_doc_type_paragraph';
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
                'label' => "Document Type for Paragraphs",
                'description' => 'An document type label rather than machine name for Paragraphs.',
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
             if($property_path == "fws_search_doc_type_paragraph"){
                $bundle = $entity->bundle();
                if($bundle == "promotable_document_reference"){
                    if($entity->field_document_ref_single){
                        $doc = \Drupal::entityTypeManager()->getStorage('media')->load($entity->field_document_ref_single->target_id);
                        if($doc && $doc->field_document_type && count($doc->field_document_type) > 0 &&  $doc->field_document_type[0]){
                                $results = $this->getArrayOfTaxHierarchy($doc->field_document_type);
                                foreach ($results as $value) {
                                    $field->addValue($value);
                                }
                            }
                        }
                    }
             }
        }
    }
    /**
     * Loops through a field with a taxonomy and gets all it's parents
     * and puts them in an array for search and the heirarchy filter
     */
    public function getArrayOfTaxHierarchy($field){
        /** @var $term \Drupal\taxonomy\TermInterface */
        foreach ($field->referencedEntities() as $term) {
            // Get a list of parents names in reverse and add each to tree
            $parent_names = $this->getParentName($term);
            $current_parent_path = "";
            if(count($parent_names) > 0){
                foreach (array_reverse($parent_names) as $parent_name) {
                    $current_parent_path .= $parent_name;
                    $results[$current_parent_path] = $current_parent_path;
                    $current_parent_path .= '~';
                }
            }
            // put the end leaf name in the list
            $name = $term->getName();
            $current_parent_path = $current_parent_path . $name;
            $results[$current_parent_path] = $current_parent_path; 
        } 
        return($results);
    }
    /**
     * Takes a taxonomy term and returns a list of parent name's (strings)
     * First name is the most recent parent of the term, and last name in list
     * is an ancestor above that.
     */
    public function getParentName($term) {
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemList */
        $parents = $term->get('parent');
        $names = [];
        if(!!$parents){
            foreach ($parents->referencedEntities() as $parent) {
                $names[] = $parent->getName();
                return array_merge($names,$this->getParentName($parent));
            }
        }
        return $names;
    }
}