<?php

namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Process the mime type of a document or image referenced from a paragraph. 
 *
 * @SearchApiProcessor(
 *   id = "fws_search_mimetype_for_paragraph",
 *   label = @Translation("FWS Search Mime Type For Paragraph"),
 *   description = @Translation("Get document or image file type from paragraph"),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 * )
 */
class MimeTypeForParagraphProcessor extends ProcessorPluginBase
{
    public static $key = 'fws_search_mimetype_for_paragraph';
    // Groupings of mimetypes
    public $mimes = [
        "image" => "Image",
        "audio" => "Audio",
        "video" => "Video",
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
        // allow to apply generically or to any data source
        return [
            self::$key => new ProcessorProperty([
                'label' => "File Type for document or image from Paragraphs",
                'description' => 'Index the file type of document or image from referenced content in paragraphs for filtering.',
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
        $entity = $item->getOriginalObject()->getValue();
        if(!($entity instanceof ContentEntityInterface)) {
            return;
        }
    
        foreach($item->getFields() as $field) {
            $property_path = $field->getPropertyPath();
            if($property_path === 'fws_search_mimetype_for_paragraph') {
                $bundle = $entity->bundle();
                if($bundle == "image"){
                    $field->addValue("Image");
                }else if ( $bundle == "promotable_document_reference"){
                    $node = \Drupal::entityTypeManager()->getStorage('media')->load($entity->field_document_ref_single->target_id);
                    if($node && $node->field_mime_type && count($node->field_mime_type) > 0 && $node->field_mime_type[0] && $node->field_mime_type[0]->value != null){
                        $value = $node->field_mime_type[0]->value;
                        $field_mime_parts = explode('/',$value);
                        if(count($field_mime_parts) > 0 && isset($field_mime_parts[0]) && isset($this->mimes[$field_mime_parts[0]])){
                            $field->addValue($this->mimes[$field_mime_parts[0]]);
                        }else if(count($field_mime_parts) > 1 && isset($field_mime_parts[1]) && isset($this->mimes[$field_mime_parts[1]])){
                            $field->addValue($this->mimes[$field_mime_parts[1]]);
                        }else{
                            $field->addValue("Other");
                        }
                    }
                }
            }
        }
    }
}
