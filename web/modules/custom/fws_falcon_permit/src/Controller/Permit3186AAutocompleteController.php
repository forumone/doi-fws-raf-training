<?php

namespace Drupal\fws_falcon_permit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\fws_falcon_permit\Permit3186ASearchHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for autocomplete functionality.
 */
class Permit3186AAutocompleteController extends ControllerBase {

  /**
   * The search helper.
   *
   * @var \Drupal\fws_falcon_permit\Permit3186ASearchHelper
   */
  protected $searchHelper;

  /**
   * Constructs a new Permit3186AAutocompleteController.
   */
  public function __construct(Permit3186ASearchHelper $search_helper) {
    $this->searchHelper = $search_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('fws_falcon_permit.search_helder')
    );
  }

  /**
   * Handler for autocomplete request.
   */
  public function handle(Request $request, $field) {
    $string = $request->query->get('q');
    $matches = [];

    if ($string) {
      $matches = $this->searchHelper->getFieldValueContains($field, $string);
    }

    return new JsonResponse($matches);
  }

}
