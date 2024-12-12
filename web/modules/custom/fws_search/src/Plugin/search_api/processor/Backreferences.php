<?php

namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\fws_search\Plugin\search_api\processor\Property\EntityProcessorProperty;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\search_api\Utility\Utility;
use Drupal\node\NodeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\search_api\Processor\ProcessorProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;
use \Symfony\Component\Yaml\Yaml;

/**
 * Supports indexing properties from reverse references for fws_search.
 * https://www.drupal.org/docs/8/modules/search-api/developer-documentation/create-custom-fields-using-a-custom-processor
 *
 * @SearchApiProcessor(
 *   id = "fws_search_backreferences",
 *   label = @Translation("FWS search back-references"),
 *   description = @Translation("Supports reverse references needed by FWS Search indexes"),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 * )
 */
class Backreferences extends ProcessorPluginBase {
  private static $backreferenceDefinitions = null;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|null
   */
  protected $entityTypeManager;

  /**
   * The fields helper.
   *
   * @var \Drupal\search_api\Utility\FieldsHelperInterface|null
   */
  protected $fieldsHelper;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $processor->setEntityTypeManager($container->get('entity_type.manager'));
    $processor->setFieldsHelper($container->get('search_api.fields_helper'));

    return $processor;
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
   * Retrieves the fields helper.
   *
   * @return \Drupal\search_api\Utility\FieldsHelperInterface
   *   The fields helper.
   */
  public function getFieldsHelper() {
    return $this->fieldsHelper ?: \Drupal::service('search_api.fields_helper');
  }

  /**
   * Sets the fields helper.
   *
   * @param \Drupal\search_api\Utility\FieldsHelperInterface $fields_helper
   *   The new fields helper.
   *
   * @return $this
   */
  public function setFieldsHelper(FieldsHelperInterface $fields_helper) {
    $this->fieldsHelper = $fields_helper;
    return $this;
  }

  /**
   * @return array
   */
  private static function backreferenceDefinitions() {
    if (!self::$backreferenceDefinitions) {
        $config = \Drupal::config('fws_search.config');
        $backrefs = $config->get('backreferences');
        if ($backrefs) {
            self::$backreferenceDefinitions = Yaml::parse($backrefs);
        } else {
            self::$backreferenceDefinitions = [];
        }
    }
    return self::$backreferenceDefinitions;
  }

  /**
   * @return array;
   */
  private static function backreferenceDefinitionsForDataSource(DataSourceInterface $datasource = NULL) {
    $definitions = [];
    if($datasource) {
      $back_ref_defs = self::backreferenceDefinitions();
      $supported_entity_types = array_keys($back_ref_defs);
      $entity_type_id = $datasource->getEntityTypeId();
      if(in_array($entity_type_id,$supported_entity_types)) {
        $supported_bundles = $back_ref_defs[$entity_type_id];
        $datasource_bundles = array_keys($datasource->getBundles());
        foreach($datasource_bundles as $ds_bundle) {
            if(isset($supported_bundles[$ds_bundle])) {
              $definitions[$ds_bundle] = $supported_bundles[$ds_bundle];
            }
        }
      }
    }
    return $definitions;
  }

  /**
   * @return boolean
   */
  private static function supportsDataSource(DataSourceInterface $datasource) {
    return count(self::backreferenceDefinitionsForDataSource($datasource)) > 0;
  }

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    foreach ($index->getDatasources() as $datasource) {
        if(self::supportsDataSource($datasource)) {
            return TRUE;
        }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];
    foreach(self::backreferenceDefinitionsForDataSource($datasource) as $source_bundle => $backreferences) {
      $source_type = $datasource->getEntityTypeId();
      $source = $source_type.'_'.$source_bundle;
      foreach($backreferences as $backreference) {
        $reference_field = $backreference['reference_field'];
        $destination = $backreference['type'].'_'.$backreference['bundle'];
        $property = $backreference['property'];
        $key = 'fws_search__'.$source.'__'.$reference_field.'__'.$destination.'__'.$property;
        $label = 'FWS Search Backreference: '.$backreference['label'];
        $definition = [
          'label' => $label,
          'description' => $label,
          'type' => $backreference['property_type'],
          // all reverse references are multi-valued since it's not possible to control how many entities may
          // reference another
          'is_list' => TRUE,
          'processor_id' => $this->getPluginId(),
        ];
        $properties[$key] = new ProcessorProperty($definition);
      }
      //dd($properties);
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

    /** @var \Drupal\search_api\Item\FieldInterface[][] $to_extract */
    $to_extract = [];
    foreach ($item->getFields() as $field) {
      $datasource = $field->getDatasource();
      $property_path = $field->getPropertyPath();
      $pp_parts = explode('__',$property_path);
      if(count($pp_parts) === 5 && $pp_parts[0] === 'fws_search') {
        $source = $pp_parts[1];
        list($source_type,$source_bundle) = explode('_',$source);
        if($source_type === $entity->getEntityTypeId() && $source_bundle === $entity->bundle()) {
          $reference_field = $pp_parts[2];
          $destination = $pp_parts[3];
          list($dest_type,$dest_bundle) = explode('_',$destination);
          $property = $pp_parts[4];
          // do the search and populate the field....
          $eids = \Drupal::entityQuery($dest_type)
            ->accessCheck(TRUE)
            ->condition('type',$dest_bundle)
            ->condition($reference_field,$entity->id())
            ->execute();
          if(count($eids) > 0) {
            $dest_entities = $this->getEntityTypeManager()
              ->getStorage($dest_type)
              ->loadMultiple(array_values($eids));
            $values = [];
            foreach($dest_entities as $dest_entity) {
              if($dest_entity instanceof ContentEntityInterface) {
                // this only deals with single valued properties for now
                $v = $dest_entity->get($property)->value;
                if($v) {
                  $field->addValue($v);
                }
                /* $v below is an array but seems to be the same
                foreach($dest_entity->get($property)->getValue() as $v) {
                  $field->addValue($v);
                }*/
              }
            }
          }
        }
      }
    }
  }

}
