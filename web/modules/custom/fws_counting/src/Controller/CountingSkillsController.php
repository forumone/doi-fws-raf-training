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
      '#type' => 'container',
      '#attributes' => ['class' => ['counting-skills-page']],
      'content' => [
        'intro' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $config->get('intro_text'),
        ],
        'form' => $this->formBuilder()->getForm('Drupal\fws_counting\Form\CountingExperienceForm'),
        'citation' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $config->get('citation'),
        ],
        'credits' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $config->get('credits'),
        ],
      ],
    ];

    return $build;
  }
}
