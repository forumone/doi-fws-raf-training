<?php

namespace Drupal\tracking_reports\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\tracking_reports\TrackingSearchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for autocomplete functionality.
 */
class TrackingAutocompleteController extends ControllerBase {

  /**
   * The tracking search manager.
   *
   * @var \Drupal\tracking_reports\TrackingSearchManager
   */
  protected $searchManager;

  /**
   * Constructs a new TrackingAutocompleteController.
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
   * Handler for species ID autocomplete request.
   */
  public function handleSpeciesIdAutocomplete(Request $request) {
    $string = $request->query->get('q');
    $matches = $this->searchManager->getSpeciesIdMatches($string);
    return new JsonResponse($matches);
  }

  /**
   * Handles species name autocomplete.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with autocomplete suggestions.
   */
  public function handleSpeciesNameAutocomplete(Request $request) {
    $results = [];
    $input = $request->query->get('q');

    if ($input) {
      $matches = $this->searchManager->getSpeciesNameMatches($input);
      foreach ($matches as $match) {
        $results[] = ['value' => $match['label'], 'label' => $match['label']];
      }
    }

    return new JsonResponse($results);
  }

  /**
   * Handler for autocomplete request.
   */
  public function handleNumberAutocomplete(Request $request) {
    $string = $request->query->get('q');
    $matches = [];

    if ($string) {
      $matches = $this->searchManager->getTrackingNumberMatches($string);
    }

    return new JsonResponse($matches);
  }

  /**
   * Handler for Tag ID autocomplete request.
   */
  public function handleTagIdAutocomplete(Request $request) {
    $input = $request->query->get('q');
    $matches = $this->searchManager->getTagIdMatches($input);
    $results = [];

    foreach ($matches as $match) {
      $results[] = [
        'value' => $match['value'],
        'label' => $match['label'],
      ];
    }

    return new JsonResponse($results);
  }

}
