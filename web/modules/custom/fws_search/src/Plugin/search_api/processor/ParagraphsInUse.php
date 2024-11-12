<?php
namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @SearchApiProcessor(
 *  id = "fws_search_paragraphs_in_use",
 *  label = @Translation("FWS Paragraphs in use"),
 *  description = @Translation("Prevent indexing of unreferenced paragraphs."),
 *  stages = {
 *      "alter_items" = 0,
 *  },
 * )
 */
class ParagraphsInUse extends ProcessorPluginBase {
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
    public static function supportsIndex(IndexInterface $index) {
        foreach ($index->getDatasources() as $datasource) {
            if($datasource->getEntityTypeId() === 'paragraph') {
                return TRUE;
            }
        }
        return FALSE;
    }

    /**
     * {@inheritdoc}
     * @param \Drupal\search_api\Item\ItemInterface[] $items
     */
    public function alterIndexedItems(array &$items) {
        // $log = \Drupal::logger('fws_search');
        // $log->info("alterIndexedItems");
        $paragraph_bundles = [];
        $paragraph_ids = [];
        foreach($items as $item_id => $item) {
            /** @var \Drupal\Core\Entity\ContentEntityInterface  */
            $entity = $item->getOriginalObject()->getValue();
            // in case indexing paragraphs in addition to other content
            if($entity->getEntityTypeId() === 'paragraph') {
                $paragraph_ids[$item_id] = $entity->id();
                $bundle = $entity->bundle();
                if(!in_array($bundle,$paragraph_bundles)) {
                    $paragraph_bundles[] = $bundle;
                }
            }
        }
        // $log->info("paragraph_ids ".join(',',$paragraph_ids));
        // $log->info('paragraph_bundles '.join(',',$paragraph_bundles));
        if(count($paragraph_ids) > 0) {
            $ids_with_references = $this->testParagraphIdsForReferences($paragraph_bundles,$paragraph_ids);
            // $log->info('ids_with_references '.join(',',$ids_with_references));
            foreach($paragraph_ids as $item_id => $paragraph_id) {
                if(!in_array($paragraph_id,$ids_with_references)) {
                    // $log->info("paragraph ${item_id} has no reference from a published node, not indexing.");
                    unset($items[$item_id]);
                }
            }
        }
    }

    /**
     * To avoid the terrible performance of testing paragraphs for references one at a time
     * this function tests a whole page of indexed paragraphs for references at once at
     * the expense of skipping the entity abstractions and dealing directly with Drupal's
     * table structure via SQL.
     * 
     * @param array $paragraph_bundles List of paragraph bundles the list of paragraph_ids correspond to.
     * @param array $paragraph_ids 
     *      Array keyed by item id to paragraph id.
     *      [
     *        'entity:paragraph/9875:en' => 9875,
     *        'entity:paragraph/9874:en' => 9874
     *         ... etc ....
     *      ]
     *  @return array
     *      Array just like $paragraph_ids but only for those that have valid references in the system
     */
    public function testParagraphIdsForReferences(array $paragraph_bundles,array $paragraph_ids) {
        /*
         The code below is a bit obscure so here's an example of the kind of SQL it might generate
         The list of fields to check is based on the contents of $paragraph_bundles
            USE drupal;
            SELECT nodes.entity_id,nodes.target_id FROM 
            (
                SELECT entity_id,target_id
                FROM node as n
                INNER JOIN node_field_data as nfd ON n.nid=nfd.nid
                INNER JOIN
                (
                    SELECT f.entity_id,f.<field_1>_target_id as target_id FROM node__<field_1> AS f
                    UNION
                    SELECT f.entity_id,f.<field_2>_target_id as target_id FROM node__<field_2> AS f
                    UNION
                    ...
                ) AS fields ON fields.entity_id = n.nid
                WHERE nfd.status = 1
            ) AS nodes
            WHERE nodes.target_id IN(<$paragraph_ids>)
         */
        $fields_by_paragraph_bundle = $this->getFieldsByParagraphBundle();
        $cx = \Drupal\Core\Database\Database::getConnection(); 
        $fields = [];
        // collect the possible fields that point to the paragraph bundles in question
        foreach($paragraph_bundles as $paragraph_bundle) {
            foreach($fields_by_paragraph_bundle[$paragraph_bundle] as $field) {
                if(!in_array($field,$fields)) {
                    $fields[] = $field;
                }
            }
        }
        // build a union of all field tables exposing consistent collumn names (entity_id,target_id)
        /** @var \Drupal\Core\Database\Query\SelectInterface */
        $field_queries = NULL;
        foreach($fields as $field) {
            $fq = $cx->select("node__${field}","f");
            $fq->addField('f','entity_id');
            $fq->addField('f',"${field}_target_id",'target_id');
            if($field_queries === NULL) {
                $field_queries = $fq;
            } else {
                $field_queries->union($fq);
            }
        }
        // join that result to published nodes
        $publishedFieldsQuery = $cx->select('node','n');
        $publishedFieldsQuery->fields(NULL,['entity_id','target_id']);
        $publishedFieldsQuery->join('node_field_data','nfd','n.nid=nfd.nid');
        $publishedFieldsQuery->join($field_queries,'fields','fields.entity_id=n.nid');
        $publishedFieldsQuery->condition('nfd.status',1);
        // select nodes that have references pointing to the paragraphs
        $query = $cx->select($publishedFieldsQuery,'nodes');
        $query->fields('nodes',['entity_id','target_id']);
        $query->condition('nodes.target_id',$paragraph_ids,'IN');
        $results = $query->execute()->fetchAll();
        // we don't care _who_ points to the paragraphs, just which have pointers to them so
        // return the list of target_ids
        $target_ids = [];
        foreach($results as $row) {
            $target_ids[] = $row->target_id;
        }
        $have_references = [];
        foreach($paragraph_ids as $item_id => $paragraph_id) {
            if(in_array($paragraph_id,$target_ids)) {
                $have_references[$item_id] = $paragraph_id;
            }
        }
        return $have_references;
    }

    // the implementation below that considers each paragraph individually and runs an
    //  entity query per is, not surprisingly, very slow
    // /**
    //  * {@inheritdoc}
    //  * @param \Drupal\search_api\Item\ItemInterface[] $items
    //  */
    // public function alterIndexedItems(array &$items) {
    //     $log = \Drupal::logger('fws_search');
    //     foreach($items as $item_id => $item) {
    //         if(!$this->hasValidReference($item)) {
    //             $log->info("paragraph ${item_id} has no reference from a published node, not indexing.");
    //             unset($items[$item_id]);
    //         }
    //     }
    // }

    // /**
    //  * Queries against all fields pointing to paragraphs of the item's type
    //  * to see if there are any valid references.  A reference may be invalid because
    //  * either the paragraph is dangling in the database (no references to it) or
    //  * it is only pointed to by an unpublished entity.
    //  * 
    //  * This only takes into account references to paragraphs from nodes.
    //  * 
    //  * @param \Drupal\search_api\Item\ItemInterface $item
    //  * @return boolean TRUE if there are valid references.
    //  */
    // private function hasValidReference(ItemInterface $item) {
    //     /** @var \Drupal\Core\Entity\ContentEntityInterface  */
    //     $entity = $item->getOriginalObject()->getValue();
    //     $id = $entity->id();
    //     $bundle = $entity->bundle();
    //     $fields_by_paragraph_bundle = $this->getFieldsByParagraphBundle();
    //     $query = $this->getEntityTypeManager()->getStorage('node')->getQuery()
    //         ->condition('status',1);
    //     $group = $query->orConditionGroup();
    //     foreach($fields_by_paragraph_bundle[$bundle] as $field_name) {
    //         $group->condition($field_name,$id);
    //     }
    //     $query->condition($group);
    //     $results = $query->execute();
    //     return count($results) > 0;
    // }

    /**
     * Introspects all fields on content-types that are references to paragraph entities
     * and based on their configuration builds a map of string => string[] where the key
     * is the paragraph bundle and the value is an array of field names that can reference it.
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
                                if(!isset($map[$paragraph_bundle])) {
                                    $map[$paragraph_bundle] = [];
                                }
                                if(!in_array($field_name,$map[$paragraph_bundle])) {
                                    $map[$paragraph_bundle][] = $field_name;            
                                }                
                            }
                        }
                    }
                }
            }
        }
        return $map;
    }

    /**
     * Invoked from hook_ENTITY_TYPE_save/update when a node that references paragraphs
     * has been edited to tell search_api that its related paragraphs should be re-indexed
     * in case the references changed.
     * 
     * This is brute force in that it doesn't try to determine IF any references
     * actually changed it simply notifies search_api to re-consider those entities
     * to catch any possible changes.
     * 
     * Note: this doesn't focus on a specific search index so if an item is part of
     * multiple search indexes it will be re-indexed for each regardless of whether
     * that's technically necessary.
     * 
     * Note: The addition of paragraphs (hook_ENTITY_TYPE_save) already works properly
     * so technically this should only have to be called on hook_ENTITY_TYPE_update
     * to pick up any deleted paragraphs (though its coded for both uses).
     * 
     * @param \Drupal\node\Entity\ContentEntityInterface $entity
     */
    public static function paragraphParentSaved(ContentEntityInterface $entity) {
        self::requeueParagraphs($entity);
        /** @var \Drupal\Core\Entity\ContentEntityInterface */
        $original = $entity->original;
        if($original) {
            self::requeueParagraphs($original);
        }
    }

    /**
     * Chases all outbound paragraph references and tells the search_api to re-consider/update
     * them in indexes.
     * 
     * @param \Drupal\node\Entity\ContentEntityInterface $entity
     */
    private static function requeueParagraphs(ContentEntityInterface $entity) {
        $tracking_manager =  \Drupal::getContainer()->get('search_api.entity_datasource.tracking_manager');
        $field_defs = $entity->getFieldDefinitions();
        foreach($field_defs as $field_name => $field_def) {
            if($field_def->getType() === 'entity_reference_revisions') {
                $settings = $field_def->getSettings();
                if(isset($settings['target_type']) && $settings['target_type'] == 'paragraph') {
                    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList */
                    $field = $entity->get($field_name);
                    if($field && !$field->isEmpty()) {
                        foreach($field->referencedEntities() as $paragraph) {
                            $tracking_manager->entityUpdate($paragraph);
                        }
                    }
                }
            }
        }
    }
}