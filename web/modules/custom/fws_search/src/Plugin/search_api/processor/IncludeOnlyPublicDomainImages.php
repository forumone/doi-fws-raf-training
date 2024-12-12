<?php

namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Excludes IRIS occurrences that are not preset.
 *
 * @SearchApiProcessor(
 *   id = "include_only_public_domain_images",
 *   label = @Translation("FWS Image Search Include only Public Domain"),
 *   description = @Translation("Ecludes all media image entities that are not set to public domain"),
 *   stages = {
 *     "alter_items" = -50
 *   }
 * )
 */
class IncludeOnlyPublicDomainImages extends ProcessorPluginBase {

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
      //Present
      //Probably Present
      if ($object->hasField('field_creative_commons_license')) {
        $value = $object->get('field_creative_commons_license')->getValue();
        if(isset($value[0]['target_id'])){
          $target_id = $value[0]['target_id'];
          if($target_id) {
            $name = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($target_id)->getName();
            if($name != 'Public Domain') {
              unset($items[$item_id]);
              continue;
            }
          }
        }
        //this else captures excluding items that don't have a license set
        else {
            unset($items[$item_id]);
            continue;
        }
      }
    }
  }
}