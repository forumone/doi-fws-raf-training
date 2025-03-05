<?php

namespace Drupal\fws_id_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ExtensionPathResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller for the species ID test page.
 */
class IdTestController extends ControllerBase {

  /**
   * The extension path resolver.
   *
   * @var \Drupal\Core\Extension\ExtensionPathResolver
   */
  protected $extensionPathResolver;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new IdTestController object.
   *
   * @param \Drupal\Core\Extension\ExtensionPathResolver $extension_path_resolver
   *   The extension path resolver.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    ExtensionPathResolver $extension_path_resolver,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack,
  ) {
    $this->extensionPathResolver = $extension_path_resolver;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('extension.path.resolver'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('request_stack')
    );
  }

  /**
   * Displays the species ID test start page.
   */
  public function content() {
    $module_path = '/' . $this->extensionPathResolver->getPath('module', 'fws_id_test');

    $build = [
      '#theme' => 'id_test_page',
      '#form' => $this->formBuilder()->getForm('Drupal\fws_id_test\Form\IdTestStartForm'),
      '#module_path' => $module_path,
      '#attached' => [
        'library' => [
          'fws_id_test/id_test',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Displays the species ID test page.
   */
  public function test() {
    // Get parameters from the URL query.
    $request = $this->requestStack->getCurrentRequest();
    $query = $request->query;

    $difficulty = $query->get('difficulty');
    $species_groups = $query->all()['species_groups'] ?? [];
    $regions = $query->all()['regions'] ?? [];

    // Validate that we have the required parameters.
    if (empty($difficulty) || empty($species_groups) || empty($regions)) {
      $this->messenger()->addError($this->t('Missing required parameters. Please start the test from the beginning.'));
      return $this->redirect('fws_id_test.start');
    }

    // Load the term labels for display.
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Load difficulty term.
    $difficulty_term = $term_storage->load($difficulty);
    $difficulty_label = $difficulty_term ? $difficulty_term->label() : '';

    // Load species group terms.
    $species_group_terms = $term_storage->loadMultiple($species_groups);
    $species_group_labels = array_map(function ($term) {
      return $term->label();
    }, $species_group_terms);

    // Load region terms.
    $region_terms = $term_storage->loadMultiple($regions);
    $region_labels = array_map(function ($term) {
      return $term->label();
    }, $region_terms);

    // First, get species that match our criteria (species groups and regions).
    $species_query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'species')
      ->condition('field_species_group', $species_groups, 'IN')
      ->condition('field_region', $regions, 'IN');
    $species_ids = $species_query->execute();

    if (empty($species_ids)) {
      $this->messenger()->addError($this->t('No species found matching the selected criteria. Please modify your selection.'));
      return $this->redirect('fws_id_test.start');
    }

    // Then get videos that reference these species and match the difficulty.
    $query = $this->entityTypeManager->getStorage('media')->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('bundle', 'species_video')
      ->condition('field_species', $species_ids, 'IN');

    // Get all matching media IDs.
    $media_ids = $query->execute();

    // If we don't have enough videos, show an error and redirect back.
    if (count($media_ids) < 10) {
      $this->messenger()->addError($this->t('Not enough videos available for the selected criteria. Please modify your selection.'));
      return $this->redirect('fws_id_test.start');
    }
    // Randomly select 10 media IDs.
    $random_media_ids = array_rand($media_ids, 10);
    $selected_media_ids = array_intersect_key($media_ids, array_flip($random_media_ids));

    // Load the full media entities.
    $videos = $this->entityTypeManager->getStorage('media')->loadMultiple($selected_media_ids);

    // Prepare video data for template.
    $prepared_videos = [];
    foreach ($videos as $video) {
      $video_file = $video->get('field_video_file')->entity;
      if ($video_file) {
        $species_choices = [];
        if ($video->hasField('field_species_choices') && !$video->get('field_species_choices')->isEmpty()) {
          foreach ($video->get('field_species_choices') as $choice) {
            if ($choice->entity) {
              $species_choices[] = $choice->entity->label();
            }
          }
        }

        $prepared_videos[] = [
          'url' => $video_file->createFileUrl(),
          'species' => $video->get('field_species')->entity ? $video->get('field_species')->entity->label() : '',
          'choices' => $species_choices,
        ];
      }
    }

    // Return the render array.
    $render_array = [
      '#theme' => 'id_test_quiz',
      '#videos' => $prepared_videos,
      '#experience_level' => $difficulty_label,
      '#species_groups' => $species_group_labels,
      '#geographic_regions' => $region_labels,
      '#attached' => [
        'library' => [
          'fws_id_test/id_test',
        ],
        'drupalSettings' => [
          'fws_id_test' => [
            'quiz' => [
              'videos' => $prepared_videos,
            ],
          ],
        ],
      ],
    ];

    return $render_array;
  }

}
