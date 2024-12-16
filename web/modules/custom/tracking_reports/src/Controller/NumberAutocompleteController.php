<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tracking_reports\TrackingSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for tracking number autocomplete.
 */
class NumberAutocompleteController extends ControllerBase {

  /**
   * The tracking search manager.
   *
   * @var \Drupal\tracking_reports\TrackingSearchManager
   */
  protected $searchManager;

  /**
   * Constructs a NumberAutocompleteController object.
   *
   * @param \Drupal\tracking_reports\TrackingSearchManager $search_manager
   *   The tracking search manager.
   */
  public function __construct(TrackingSearchManager $search_manager) {
    $this->searchManager = $search_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tracking_reports.search_manager')
    );
  }

  /**
   * Handler for autocomplete request.
   */
  public function handleAutocomplete(Request $request) {
    $string = $request->query->get('q');
    $matches = [];

    if ($string) {
      $matches = $this->searchManager->getTrackingNumberMatches($string);
    }

    return new JsonResponse($matches);
  }

}
