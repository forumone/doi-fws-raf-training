<?php

namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Some mime types, for example images, excel, word, have multiple mime types
 * for the same type of document.  So we are going to combine the mime types
 * and then search the combination so that it makes it easier on the front end
 *
 * @SearchApiProcessor(
 *   id = "fws_search_mimetype",
 *   label = @Translation("FWS Search Mime Type"),
 *   description = @Translation("Index mime types combined together"),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 * )
 */
class MimeTypeProcessor extends ProcessorPluginBase
{
    // Groupings of mimetypes
    public $mimes = [
        "image" => "Image",
        "audio" => "Audio",
        "video" => "Video",
        "fws_remote_video" => "Video",
        "text"  => "Text",
        "rtf" => "Text",
        "postscript" => "Illustrator",
        "msword" => "Word",
        "vnd.openxmlformats-officedocument.wordprocessingml.document" => "Word",
        "vnd.ms-excel" => "Excel",
        "vnd.openxmlformats-officedocument.spreadsheetml.sheet" => "Excel",
        "vnd.ms-powerpoint" => "Powerpoint",
        "vnd.openxmlformats-officedocument.presentationml.presentation" => "Powerpoint",
        "pdf" => "PDF",
        "zip" => "Compressed File"
    ];
    /**
     * {@inheritdoc}
     */
    public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
        $properties = [];
        if($datasource) {
            $entity_type = $datasource->getEntityTypeId();
            $bundles = $datasource->getBundles();
            foreach(array_keys($bundles) as $bundle){
                $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $bundle);
                foreach($definitions as $definition) {
                    $property_name = $definition->getName();
                    if($property_name === 'field_mime_type') {
                        // $property_name = $definition->getName();
                        $path = 'fws_search_mimetype__'.$property_name;
                        $properties[$path] = new ProcessorProperty([
                            'label' => 'FWS Search Mime Type '.$property_name,
                            'description' => 'Index mime types with special cases of multiple types for the same document for '.$property_name,
                            'type' => 'string',
                            'is_list' => false,
                            'processor_id' => $this->getPluginId(),
                        ]);
                        return $properties;
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
            if($pp_parts[0] === 'fws_search_mimetype') {
                $property_name = $pp_parts[1]; // eg the field
                if($entity->hasField($pp_parts[1])) {
                    $entity_field = $entity->get($property_name);
                    if($entity_field[0] &&  $entity_field[0]->value != null){
                        $value = $entity_field[0]->value;
                        $field_mime_parts = explode('/',$value);
        
                        if(isset($field_mime_parts[0]) && isset($this->mimes[$field_mime_parts[0]])){
                            $field->addValue($this->mimes[$field_mime_parts[0]]);
                        }else if(isset($field_mime_parts[1]) && isset($this->mimes[$field_mime_parts[1]])){
                            $field->addValue($this->mimes[$field_mime_parts[1]]);
                        }else{
                            $field->addValue("Other");
                        }
                    }
                }
                else if(array_key_exists($entity->bundle(), $this->mimes)) {
                    $field->addValue($this->mimes[$entity->bundle()]);
                }
                

                // /** @var $term \Drupal\taxonomy\TermInterface */
                // foreach ($entity_field->referencedEntities() as $term) {
                //     // Get a list of parents names in reverse and add each to tree
                //     $parent_names = $this->getParentName($term);
                //     $current_parent_path = "";
                //     if(count($parent_names) > 0){
                //         foreach (array_reverse($parent_names) as $parent_name) {
                //             $current_parent_path .= $parent_name;
                //             $results[$current_parent_path] = $current_parent_path;
                //             $current_parent_path .= '~';
                //         }
                //     }
                //     // put the end leaf name in the list
                //     $name = $term->getName();
                //     $current_parent_path = $current_parent_path . $name;
                //     $results[$current_parent_path] = $current_parent_path;               
                // }
                // foreach ($results as $value) {
                //     $field->addValue($value);
                // }
            }
        }
    }

    /**
     * Takes a taxonomy term and returns a list of parent name's (strings)
     * First name is the most recent parent of the term, and last name in list
     * is an ancestor above that.
     */
    // public function getParentName($term) {
    //     /** @var \Drupal\Core\Field\EntityReferenceFieldItemList */
    //     $parents = $term->get('parent');
    //     $names = [];
    //     if(!!$parents){
    //         foreach ($parents->referencedEntities() as $parent) {
    //             $names[] = $parent->getName();
    //             return array_merge($names,$this->getParentName($parent));
    //         }
    //     }
    //     return $names;
    // }
}
