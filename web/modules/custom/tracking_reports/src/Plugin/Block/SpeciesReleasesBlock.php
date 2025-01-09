<?php

namespace Drupal\tracking_reports\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\tracking_reports\TrackingSearchManager;
use Drupal\Core\Link;
use Drupal\Core\Cache\Cache;

/**
 * Provides a block displaying species releases.
 *
 * @Block(
 *   id = "species_releases_block",
 *   admin_label = @Translation("Species Release"),
 *   category = @Translation("Tracking Reports")
 * )
 */
class SpeciesReleasesBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The tracking search manager.
   *
   * @var \Drupal\tracking_reports\TrackingSearchManager
   */
  protected $trackingSearchManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new SpeciesReleasesBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\tracking_reports\TrackingSearchManager $tracking_search_manager
   *   The tracking search manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
    TrackingSearchManager $tracking_search_manager,
    RouteMatchInterface $route_match
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->trackingSearchManager = $tracking_search_manager;
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
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('tracking_reports.search_manager'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');
    if (!$node || $node->bundle() !== 'species') {
      return [];
    }

    // Build query for releases related to this species.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_release')
      ->condition('field_species_ref', $node->id())
      ->sort('field_release_date', 'DESC')
      ->accessCheck(FALSE);

    $release_ids = $query->execute();
    if (empty($release_ids)) {
      return [
        '#markup' => $this->t('No release records found.'),
      ];
    }

    $release_nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($release_ids);
    $rows = [];

    foreach ($release_nodes as $release) {
      $species_entity = $release->field_species_ref->entity;
      if (!$species_entity) {
        continue;
      }

      $rescue = $this->getMostRecentRescue($species_entity->id());
      if (!$rescue) {
        continue;
      }

      $rows[] = [
        'data' => $this->buildRowData($species_entity, $rescue, $release),
      ];
    }

    // Build table.
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Name'),
        $this->t('Sex'),
        $this->t('Species ID'),
        $this->t('Rescue Date'),
        $this->t('Release Date'),
        $this->t('Cause of Rescue'),
        $this->t('Rescue Weight, Length'),
        $this->t('Release Weight, Length'),
        $this->t('Rescue County'),
        $this->t('Release County'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No release records found.'),
      '#cache' => [
        'contexts' => ['url.path'],
        'tags' => Cache::mergeTags(['node_list:species_release'], $node->getCacheTags()),
        'max-age' => Cache::PERMANENT,
      ],
    ];

    return $build;
  }

  /**
   * Gets the most recent rescue event for a species.
   *
   * @param int $species_id
   *   The species ID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The most recent rescue node or NULL if none found.
   */
  protected function getMostRecentRescue($species_id) {
    $rescue_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_rescue')
      ->condition('field_species_ref', $species_id)
      ->condition('field_rescue_date', NULL, 'IS NOT NULL')
      ->sort('field_rescue_date', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $results = $rescue_query->execute();
    if (!empty($results)) {
      return $this->entityTypeManager->getStorage('node')->load(reset($results));
    }
    return NULL;
  }

  /**
   * Builds row data for the table.
   *
   * @param \Drupal\node\NodeInterface $species_entity
   *   The species node entity.
   * @param \Drupal\node\NodeInterface $rescue
   *   The rescue node entity.
   * @param \Drupal\node\NodeInterface $release
   *   The release node entity.
   *
   * @return array
   *   An array representing the table row data.
   */
  protected function buildRowData($species_entity, $rescue, $release) {
    // Get the name from species_name paragraphs.
    $name = 'N/A';
    if (!$species_entity->get('field_names')->isEmpty()) {
      $paragraphs = $species_entity->get('field_names')->referencedEntities();
      foreach ($paragraphs as $para) {
        if (!$para->get('field_primary')->isEmpty() && $para->get('field_primary')->value) {
          if (!$para->get('field_name')->isEmpty()) {
            $name = $para->get('field_name')->value;
            break;
          }
        }
      }
    }

    return [
      $name,
      !$species_entity->field_sex->isEmpty() ? $species_entity->field_sex->entity->label() : 'N/A',
      $this->trackingSearchManager->getPrimarySpeciesId($species_entity->id()) ?? 'N/A',
      !$rescue->field_rescue_date->isEmpty()
        ? $this->dateFormatter->format(strtotime($rescue->field_rescue_date->value), 'custom', 'm/d/Y')
        : 'N/A',
      !$release->field_release_date->isEmpty()
        ? Link::createFromRoute(
          $this->dateFormatter->format(strtotime($release->field_release_date->value), 'custom', 'm/d/Y'),
          'entity.node.canonical',
          ['node' => $release->id()]
        )
        : 'N/A',
      $this->getRescueCauseDetail($rescue),
      $this->getMetricsString($rescue->field_weight ?? NULL, $rescue->field_length ?? NULL),
      $this->getPreReleaseMetrics($species_entity->id()),
      !$rescue->field_county->isEmpty() ? $rescue->field_county->entity->label() : 'N/A',
      !$release->field_county->isEmpty() ? $release->field_county->entity->label() : 'N/A',
    ];
  }

  /**
   * Gets rescue cause detail.
   *
   * @param \Drupal\node\NodeInterface $rescue_node
   *   The rescue node.
   *
   * @return string
   *   The rescue cause detail or 'N/A'.
   */
  protected function getRescueCauseDetail($rescue_node) {
    if (!$rescue_node->field_primary_cause->isEmpty()) {
      $cause_term = $rescue_node->field_primary_cause->entity;
      if ($cause_term && !$cause_term->field_rescue_cause_detail->isEmpty()) {
        return $cause_term->field_rescue_cause_detail->value;
      }
    }
    return 'N/A';
  }

  /**
   * Gets formatted metrics string for weight/length.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface|null $weight
   *   The weight field.
   * @param \Drupal\Core\Field\FieldItemListInterface|null $length
   *   The length field.
   *
   * @return string
   *   The formatted metrics string.
   */
  protected function getMetricsString($weight, $length) {
    $weight_val = ($weight && !$weight->isEmpty()) ? $weight->value : 'N/A';
    $length_val = ($length && !$length->isEmpty()) ? $length->value : 'N/A';

    if ($weight_val === 'N/A' && $length_val === 'N/A') {
      return 'N/A';
    }
    return $weight_val . ' kg, ' . $length_val . ' cm';
  }

  /**
   * Gets the pre-release metrics if available.
   *
   * @param int $species_id
   *   The species ID.
   *
   * @return string
   *   The formatted pre-release metrics or 'N/A'.
   */
  protected function getPreReleaseMetrics($species_id) {
    $prerelease_query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'species_prerelease')
      ->condition('field_species_ref', $species_id)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $results = $prerelease_query->execute();
    if (!empty($results)) {
      $prerelease = $this->entityTypeManager->getStorage('node')->load(reset($results));
      return $this->getMetricsString($prerelease->field_weight ?? NULL, $prerelease->field_length ?? NULL);
    }
    return 'N/A';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(
      parent::getCacheContexts(),
      ['route', 'url.path']
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $node = $this->routeMatch->getParameter('node');
    if ($node && $node->bundle() === 'species') {
      return Cache::mergeTags(
        parent::getCacheTags(),
        $node->getCacheTags()
      );
    }
    return parent::getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::PERMANENT;
  }

}