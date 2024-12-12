<?php

namespace Drupal\fws_search\Plugin\search_api\processor;

use Drupal\search_api\Plugin\PluginFormTrait;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Excludes IRIS occurrences that are not preset.
 *
 * @SearchApiProcessor(
 *   id = "exclude_not_present_species_from_index",
 *   label = @Translation("FWS Search Exclude Not Present IRIS Species"),
 *   description = @Translation("Excludes items with field_iris_occurrence  which is not present or probably present."),
 *   stages = {
 *     "alter_items" = -50
 *   }
 * )
 */
class ExcludeNotPresentSpeciesFromIndex extends ProcessorPluginBase {

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
      if ($object->hasField('field_iris_occurrence')) {
        $value = $object->get('field_iris_occurrence')->getValue();
        if($value[0]){
          $target_id = $value[0]['target_id'];
          if($target_id) {
            $name = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($target_id)->getName();
            if($name != 'Present' && $name != 'Probably Present') {
              unset($items[$item_id]);
              continue;
            }
          }
        }
      }
    }
  }
}