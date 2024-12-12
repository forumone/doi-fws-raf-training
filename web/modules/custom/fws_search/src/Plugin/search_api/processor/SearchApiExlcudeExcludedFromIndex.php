<?php

namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Excludes entities marked as 'field_include_in_image_library' (excluded) from being indexed.
 *
 * @SearchApiProcessor(
 *   id = "search_api_exclude_excluded_from_index",
 *   label = @Translation("FWS Search Exclude Excluded items"),
 *   description = @Translation("Excludes items with field_include_in_image_library set to true."),
 *   stages = {
 *     "alter_items" = -50
 *   }
 * )
 */
class SearchApiExlcudeExcludedFromIndex extends ProcessorPluginBase {

  use PluginFormTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function alterIndexedItems(array &$items) {

    /** @var \Drupal\search_api\Item\ItemInterface $item */
    foreach ($items as $item_id => $item) {
      $object = $item->getOriginalObject()->getValue();
      $bundle = $object->bundle();

      // TODO: Change this to be more generic and allow controlling the field and
      // value to exclude by

      // Remove if the field_include_in_image_library is set to true.
      if ($object->hasField('field_include_in_image_library')) {
        $value = $object->get('field_include_in_image_library')->getValue();
        if (isset($value[0]['value']) && $value[0]['value'] == 1) {
          unset($items[$item_id]);
          continue;
        }
      }
    }
  }
}