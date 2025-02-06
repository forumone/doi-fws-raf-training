<?php

namespace Drupal\fws_sighting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for sighting count endpoints.
 */
class SightingCountController extends ControllerBase {

  /**
   * Returns the total count of cranes.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string|null $year
   *   The year term ID to count sightings for, or null for all years.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing the count.
   */
  public function getCount(Request $request, $year = NULL) {
    \Drupal::logger('fws_sighting')->notice('Getting count with year ID: @year', ['@year' => $year ?: 'all']);

    // Build the base query.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'sighting')
      ->condition('status', 1)
      ->accessCheck(TRUE);

    // Add year filter if provided and not "null" string.
    if ($year !== NULL && $year !== 'null' && $year !== '' && $year !== 'All') {
      // Load the term to verify it exists.
      $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($year);
      if (!$term || $term->bundle() !== 'year') {
        \Drupal::logger('fws_sighting')->warning('No valid year term found for ID: @year', ['@year' => $year]);
        return new JsonResponse(['count' => 0, 'error' => 'Year not found']);
      }
      $query->condition('field_year.target_id', $year);
    }

    // Add date range conditions if provided.
    $start_date = $request->query->get('start_date');
    $end_date = $request->query->get('end_date');

    if ($start_date) {
      \Drupal::logger('fws_sighting')->notice('Filtering by start date: @date', ['@date' => $start_date]);
      $query->condition('field_date_time.value', $start_date, '>=');
    }

    if ($end_date) {
      \Drupal::logger('fws_sighting')->notice('Filtering by end date: @date', ['@date' => $end_date]);
      $query->condition('field_date_time.value', $end_date, '<=');
    }

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
