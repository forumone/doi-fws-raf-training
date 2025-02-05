<?php

namespace Drupal\fws_sighting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for sighting count endpoints.
 */
class SightingCountController extends ControllerBase {

  /**
   * Returns the total count of cranes for a given year.
   *
   * @param string $year
   *   The year term ID to count sightings for.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing the count.
   */
  public function getCount($year) {
    \Drupal::logger('fws_sighting')->notice('Getting count for year term ID: @year', ['@year' => $year]);

    // Load the term to verify it exists.
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($year);
    if (!$term || $term->bundle() !== 'year') {
      \Drupal::logger('fws_sighting')->warning('No valid year term found for ID: @year', ['@year' => $year]);
      return new JsonResponse(['count' => 0, 'error' => 'Year not found']);
    }

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'sighting')
      ->condition('field_year.target_id', $year)
      ->condition('status', 1)
      ->accessCheck(TRUE);

    $nids = $query->execute();
    \Drupal::logger('fws_sighting')->notice('Found nodes: @nids', ['@nids' => print_r($nids, TRUE)]);

    // Get the sum of all crane counts for the matching nodes.
    $total_count = 0;
    if (!empty($nids)) {
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadMultiple($nids);

      foreach ($nodes as $node) {
        if ($node->hasField('field_bird_count') && !$node->get('field_bird_count')->isEmpty()) {
          $count = (int) $node->get('field_bird_count')->value;
          $total_count += $count;
          \Drupal::logger('fws_sighting')->notice('Node @nid has @count cranes', [
            '@nid' => $node->id(),
            '@count' => $count,
          ]);
        }
        else {
          \Drupal::logger('fws_sighting')->notice('Node @nid has no bird count field or value', [
            '@nid' => $node->id(),
          ]);
        }
      }
    }

    \Drupal::logger('fws_sighting')->notice('Total count: @count', ['@count' => $total_count]);
    return new JsonResponse(['count' => $total_count]);
  }

}
