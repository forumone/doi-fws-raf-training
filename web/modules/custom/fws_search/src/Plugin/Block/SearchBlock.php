<?php

namespace Drupal\fws_search\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a small block using a search form - used to replace what core does with default search module on
 *
 * @Block(
 *   id = "fws_search_form_block",
 *   admin_label = @Translation("FWS Main Search Block"),
 * )
 */
class SearchBlock extends BlockBase
{

    /**
     * {@inheritdoc}
     */
    public function build() {

        $form = \Drupal::formBuilder()->getForm('Drupal\fws_search\Form\SearchBlockForm');

        return $form;
    }
    /**
     * @return int
     */
    public function getCacheMaxAge() {
        return 0;
    }

}