<?php

namespace Drupal\fws_sighting\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 *
 */
class ObservationsMapController extends ControllerBase {

  /**
   *
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
      // Redirect to the observations map with the filter.
      return new RedirectResponse('/observations-map?field_year_target_id=' . $tid);
    }

    // Fallback if no terms found.
    return new RedirectResponse('/observations-map');
  }

}
