<?php

namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;

use Drupal\fws_facility\MapLocationFields;
use Drupal\taxonomy\TermInterface;

/**
 *
 * @SearchApiProcessor(
 *   id = "fws_search_geo_title",
 *   label = @Translation("FWS Search LatLon Title"),
 *   description = @Translation("Pairs a geo field with the entity title"),
 *   stages = {
 *     "add_properties" = 20,
 *   },
 * )
 */
class GeoTitleProcessor extends ProcessorPluginBase {
    use MapLocationFields;

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
                if('geofield' === $definition->getType()) {
                    $property_name = $definition->getName();
                    $path = 'fws_search_geo_title__'.$property_name;
                    $properties[$path] = new ProcessorProperty([
                        'label' => 'FWS Search LatLon Title '.$property_name,
                        'description' => 'LatLon plus Title '.$property_name,
                        'type' => 'string',
                        'is_list' => FALSE,
                        'processor_id' => $this->getPluginId(),
                    ]);
                }
            }
        }
        return $properties;
    }

    // in new class, functions should take in 'fieldable' interface
    // only need entity type and type
    private function getPopupViewMode($entity) {
        static $has_viewmode_cache = [];
        $type_id = $entity->getEntityTypeId();
        $bundle = $entity->getType();
        $key = $type_id.':'.$bundle;
        if(!array_key_exists($key,$has_viewmode_cache)) {
            /** @var  \Drupal\Core\Entity\EntityDisplayRepositoryInterface */
            $entity_display_repository = \Drupal::service('entity_display.repository');
            // actually just view_mode => title but just those that are enabled for the given type/bundle
            $enabled_view_modes = $entity_display_repository->getViewModeOptionsByBundle($type_id,$bundle);
            if (!isset($has_viewmode_cache[$key]) && $entity instanceof ParagraphInterface) {
                $has_viewmode_cache[$key] = array_key_exists('summary', $enabled_view_modes)
                ? 'summary'
                : NULL;
            }
        }
        return $has_viewmode_cache[$key];
    }

    private function getPopupPreview($entity) {
        if(($popup_view_mode = $this->getPopupViewMode($entity)) !== NULL) {
            $view_builder = \Drupal::entityTypeManager()->getViewBuilder($entity->getEntityTypeId());
            $build = $view_builder->view($entity,$popup_view_mode);
            $renderer = \Drupal::service('renderer');
            $popup_preview = $renderer->renderPlain($build);
            // shouldn't be necessary in production but dev bloats markup with lots of comments, new lines, etc.
            $sans_comments = preg_replace('/<!--(.|\s)*?-->/', '', $popup_preview);
            $sans_newlines = trim(preg_replace('/\s\s+/', ' ', $sans_comments));
            return $sans_newlines;
        }
    }

    protected function addFieldValue(
        ContentEntityInterface $entity,
        FieldItemListInterface $entity_field,
        FieldInterface $field,
        string $title,
        string $url
    ) {
        $geodata = $entity_field->getValue();
        if($geodata) {
            $obj = [
                "title" => $title,
                "geodata" => $geodata[0],
                "url" => $url,
            ];
            if($entity instanceof ParagraphInterface && ($popup_preview = $this->getPopupPreview($entity)) !== NULL) {
                $obj['popupPreview'] = $popup_preview;
                $parent = $entity->getParentEntity();
                if ($parent && $parent->bundle() === 'facility') {
                    $obj['iconUrl'] = $this->facilityIconUrl($parent);
                }
            } else if($entity instanceof TermInterface) {
                $obj['popupPreview'] = $entity->get('name')->value;
            }
            $field->addValue(json_encode($obj));
        }
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
            if($pp_parts[0] === 'fws_search_geo_title') {
                $property_name = $pp_parts[1];
                $this->addFieldValue($entity, $entity->get($property_name), $field, $entity->get('title')->value, $entity->toUrl()->toString());
            }
        }
    }
}
