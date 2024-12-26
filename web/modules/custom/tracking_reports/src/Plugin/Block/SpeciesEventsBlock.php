<?php

namespace Drupal\tracking_reports\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\tracking_reports\TrackingSearchManager;

/**
 * Provides a block displaying species events in chronological order.
 *
 * @Block(
 *   id = "species_events_block",
 *   admin_label = @Translation("Species Events Timeline"),
 *   category = @Translation("Tracking Reports")
 * )
 */
class SpeciesEventsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The tracking search manager.
   *
   * @var \Drupal\tracking_reports\TrackingSearchManager
   */
  protected $searchManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new SpeciesEventsBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\tracking_reports\TrackingSearchManager $search_manager
   *   The tracking search manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TrackingSearchManager $search_manager,
    RouteMatchInterface $route_match
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->searchManager = $search_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tracking_reports.search_manager'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get the current node.
    $node = $this->routeMatch->getParameter('node');
    
    // Return empty if not on a species node.
    if (!$node || $node->bundle() !== 'species') {
      return [];
    }

    // Use the buildSearchResults method with just the species ID.
    return $this->searchManager->buildSearchResults(NULL, $node->id());
  }

}