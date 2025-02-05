<?php

namespace Drupal\fws_sighting\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a block to display the sighting count.
 *
 * @Block(
 *   id = "fws_sighting_count",
 *   admin_label = @Translation("Sighting Count"),
 *   category = @Translation("FWS")
 * )
 */
class SightingCountBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#theme' => 'fws_sighting_count',
      '#attached' => [
        'library' => [
          'fws_sighting/sighting-count',
        ],
      ],
    ];
  }

}
