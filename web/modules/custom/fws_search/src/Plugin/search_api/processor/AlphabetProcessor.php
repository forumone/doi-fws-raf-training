<?php

namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Extracts first character of a field for alphabetic filtering purposes.
 * 
 * This is intended for indexes containing a single type of entity and will allow
 * string typed properties from the entity to have their first character (uppercase)
 * indexed.
 * 
 * Since the primary use case for this type of filtering is users (family name) and
 * the user.name field is complex (contains given/family, etc.) this special cases that
 * situation only allowing given or family to be pulled out of user.name.
 *
 * @SearchApiProcessor(
 *   id = "fws_search_alphabet",
 *   label = @Translation("FWS Search Alphabet"),
 *   description = @Translation("Extracts first character of property for alphabet controls"),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 * )
 */
class AlphabetProcessor extends ProcessorPluginBase
{
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
                if('string' === $definition->getType()) {
                    $property_name = $definition->getName();
                    $path = 'fws_search_alphabet__'.$property_name;
                    $properties[$path] = new ProcessorProperty([
                        'label' => 'FWS Search Alphabet '.$property_name,
                        'description' => 'First character of '.$property_name,
                        'type' => 'string',
                        'is_list' => FALSE,
                        'processor_id' => $this->getPluginId(),
                    ]);
                }
                // the primary use case actually involves a complex field and is specific to ordering of users based on first/last name
                else if ('user' === $entity_type && 'user' === $bundle && 'name' === $definition->getType()) {
                    $property_name = $definition->getName();
                    $path = 'fws_search_alphabet__'.$property_name;
                    foreach(['given','family'] as $sub_property_name) {
                        $properties[$path.'__'.$sub_property_name] = new ProcessorProperty([
                            'label' => 'FWS Search Alphabet '.$property_name.'.'.$sub_property_name,
                            'description' => 'First character of '.$property_name.'.'.$sub_property_name,
                            'type' => 'string',
                            'is_list' => FALSE,
                            'processor_id' => $this->getPluginId(),
                        ]);
                    }
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
        foreach($item->getFields() as $field) {
            $property_path = $field->getPropertyPath();
            $pp_parts = explode('__',$property_path);
            if($pp_parts[0] === 'fws_search_alphabet') {
                $property_name = $pp_parts[1];
                $sub_property_name = count($pp_parts) > 2 ? $pp_parts[2] : NULL;
                $entity_field = $entity->get($property_name);
                $property_value = $entity_field->value;
                if($sub_property_name) {
                    $first = $entity_field->get(0);
                    if($first) {
                        $property_value = $first->{$sub_property_name};
                    }
                }
                if(is_string($property_value)) {
                    $alpha = strtoupper(mb_substr($property_value, 0, 1));
                    $field->addValue($alpha);
                }
            }
        }
    }
}
