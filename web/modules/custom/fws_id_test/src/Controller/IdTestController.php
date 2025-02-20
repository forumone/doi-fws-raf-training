<?php

namespace Drupal\fws_id_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Extension\ExtensionPathResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Constructs a new IdTestController object.
   *
   * @param \Drupal\Core\Extension\ExtensionPathResolver $extension_path_resolver
   *   The extension path resolver.
   */
  public function __construct(ExtensionPathResolver $extension_path_resolver) {
    $this->extensionPathResolver = $extension_path_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('extension.path.resolver')
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
    // Check if we have the required session values.
    $session = \Drupal::service('session');
    $difficulty = $session->get('fws_id_test.difficulty');
    $species_groups = $session->get('fws_id_test.species_group');
    $regions = $session->get('fws_id_test.region');

    // If any required values are missing, redirect back to start.
    if (!$difficulty || empty($species_groups) || empty($regions)) {
      $this->messenger()->addError($this->t('Please select your test options before starting.'));
      return new RedirectResponse('/id-test/start');
    }

    // Load the term labels for display.
    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');

    // Load difficulty term.
    $difficulty_term = $term_storage->load($difficulty);
    $difficulty_label = $difficulty_term ? $difficulty_term->label() : '';

    // Load species group terms.
    $species_group_terms = $term_storage->loadMultiple(array_keys($species_groups));
    $species_group_labels = array_map(function ($term) {
      return $term->label();
    }, $species_group_terms);

    // Load region terms.
    $region_terms = $term_storage->loadMultiple(array_keys($regions));
    $region_labels = array_map(function ($term) {
      return $term->label();
    }, $region_terms);

    // For now, just return a placeholder message.
    return [
      '#markup' => $this->t('Test page with:<br>
        Experience Level: @difficulty<br>
        Species Groups: @groups<br>
        Regions/Habitats: @regions', [
          '@difficulty' => $difficulty_label,
          '@groups' => implode(', ', $species_group_labels),
          '@regions' => implode(', ', $region_labels),
        ]),
    ];
  }

}
