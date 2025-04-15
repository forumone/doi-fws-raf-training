<?php

namespace Drupal\fws_print\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityViewBuilderInterface;

/**
 * Controller for printing permit nodes.
 */
class PrintController extends ControllerBase {

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The entity view builder.
   *
   * @var \Drupal\Core\Entity\EntityViewBuilderInterface
   */
  protected $viewBuilder;

  /**
   * Constructs a PrintController object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityViewBuilderInterface $view_builder
   *   The entity view builder.
   */
  public function __construct(RendererInterface $renderer, EntityViewBuilderInterface $view_builder) {
    $this->renderer = $renderer;
    $this->viewBuilder = $view_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('entity_type.manager')->getViewBuilder('node')
    );
  }

  /**
   * Renders a node in print view mode.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to print.
   *
   * @return array
   *   A render array for the print view.
   */
  public function printNode(NodeInterface $node) {
    // Check if this is a permit_3186a node.
    if ($node->bundle() !== 'permit_3186a') {
      throw $this->createNotFoundException('This content type cannot be printed.');
    }

    // Build the render array for the print view.
    $build = $this->viewBuilder->view($node, 'print');

    // Add our custom libraries.
    $build['#attached']['library'][] = 'fws_raf/global-styling';
    $build['#attached']['library'][] = 'fws_print/print-styles';

    // Add print-specific attributes to the page
    $build['#attributes']['class'][] = 'node-view-mode-print';

    // Also try to add it via Javascript for better compatibility
    $build['#attached']['library'][] = 'fws_print/print-body-class';
    $build['#attached']['drupalSettings']['fwsPrint'] = [
      'addBodyClass' => TRUE,
    ];

    // Log that the controller is rendering with these classes
    \Drupal::logger('fws_print')->notice('Controller adding print view mode classes');

    return $build;
  }

}
