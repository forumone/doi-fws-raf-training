<?php

namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\Validator\Constraints\IsNull;

/**
 *
 * @SearchApiProcessor(
 *   id = "fws_search_address_state",
 *   label = @Translation("FWS Search Address State"),
 *   description = @Translation("Gets a state name from a address state code"),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 * )
 */
class AddressStateProcessor extends ProcessorPluginBase {
    /**
     * {@inheritdoc}
     */
    public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
      $properties = [];
      if($datasource) {
          $entity_type = $datasource->getEntityTypeId();
          $bundles = $datasource->getBundles();
          $bundle_keys = array_keys($bundles);
          $bundle = reset($bundle_keys);
          $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $bundle);
          foreach($definitions as $definition) {
              $property_name = $definition->getName();
              if('field_address' === $property_name) {
                  $path = 'fws_search_address_state__'.$property_name;
                  $properties[$path] = new ProcessorProperty([
                      'label' => "Address state name",
                      'description' => "If an event has an address with a state, then get the state's name and index it",
                      'type' => 'string',
                      'is_list' => FALSE,
                      'processor_id' => $this->getPluginId(),
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

    foreach ($item->getFields() as $field) {
      $property_path = $field->getPropertyPath();
      $pp_parts = explode('__',$property_path);
      if($pp_parts[0] === 'fws_search_address_state') {
        $property_name = $pp_parts[1]; // the field, should be field_address
        $address_field = $entity->get($property_name);
        if($address_field && $state_code = $address_field->administrative_area) {
          $address_subdivision = \Drupal::service('address.subdivision_repository')->get($state_code, ['US']);
          if($address_subdivision) {
            $field->addValue($address_subdivision->getName());
          } 
        }
      }
    }
  }
}