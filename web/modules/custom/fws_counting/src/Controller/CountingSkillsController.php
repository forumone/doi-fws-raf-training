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
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h1',
        '#value' => $config->get('page_title'),
      ],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $config->get('intro_text'),
      ],
      'form' => [
        '#type' => 'form',
        '#form_id' => 'fws_counting_experience_form',
      ],
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
    ];

    return $build;
  }
}
