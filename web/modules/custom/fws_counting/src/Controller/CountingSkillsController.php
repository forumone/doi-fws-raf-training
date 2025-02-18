<?php

namespace Drupal\fws_counting\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller for the counting skills page.
 */
class CountingSkillsController extends ControllerBase {

  /**
   * Displays the counting skills page.
   */
  public function content() {
    $config = $this->config('fws_counting.settings');

    $build = [
      '#theme' => 'counting_skills_page',
      '#intro_text' => $config->get('intro_text'),
      '#form' => $this->formBuilder()->getForm('Drupal\fws_counting\Form\CountingExperienceForm'),
      '#citation' => $config->get('citation'),
      '#credits' => $config->get('credits'),
    ];

    return $build;
  }

}
