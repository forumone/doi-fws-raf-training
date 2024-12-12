<?php

namespace Drupal\fws_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Builds a small form with a textbox and a submit button. This is to emulate how the search
 * block form works in standard drupal. But since we are using Search API for all the searching,
 * it was recommended to turn off the standard search module. So this is replacing that search block.
 */
class SearchBlockForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_search_block_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    //redirect to the main search page
    $form['#action'] = '/search';
    $form['#method'] = 'get';
  
    $form['keys'] = [
        '#type' => 'search',
        '#title' => $this->t('Search'),
        '#title_display' => 'invisible',
        '#size' => 15,
        '#default_value' => '',
        '#attributes' => ['title' => $this->t('Enter the terms you wish to search for.')],
        '#input_group_button' => true,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Search'),
        // Prevent op from showing up in the query string.
        '#name' => '',
        '#icon_only' => true,
    ];

    return $form;

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This form submits to the search page, so processing happens there.
  }
}