<?php
/**
 * @file
 * Definition of Drupal\doi_login\Services\DOILoginFlood.
 */

namespace Drupal\doi_login\Services;

use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the database flood backend. This is the default Drupal backend.
 */
class DOILoginFlood {

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * Constructs a new UserLoginForm.
   *
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   */
  public function __construct(FloodInterface $flood) {
    $this->flood = $flood;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('flood')
    );
  }

 /**
  * Implements Drupal\doi_login\DOILoginFlood::register().
  */
  public function doi_loginregister($name, $window = 3600, $identifier = NULL) {
    $this->flood->register($name, $window = 3600, $identifier);
  }

  /**
   * Implements Drupal\doi_login\DOILoginFlood::clear().
   */
  public function doi_loginclear($name, $identifier = NULL) {
    $this->flood->clear($name, $identifier);
  }
}