<?php

namespace Drupal\fws_sighting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for handling observation map redirects.
 */
class ObservationsMapController extends ControllerBase {

  /**
   * Redirects to the observations map, defaulting to the latest year filter.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the observations map.
   */
  public function redirectToLatestYear() {
    // Query for the latest year term.
    $query = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', 'year')
      ->sort('name', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $tids = $query->execute();

    if (!empty($tids)) {
      $tid = reset($tids);
      // Get the base URL for the current site.
      $base_url = \Drupal::request()->getBasePath();
      // Redirect to the observations map with the filter, preserving the subsite path.
      return new RedirectResponse($base_url . '/observations-map?field_year_target_id=' . $tid);
    }

    // Fallback if no terms found, still using base path.
    return new RedirectResponse($base_url . '/observations-map');
  }

}
