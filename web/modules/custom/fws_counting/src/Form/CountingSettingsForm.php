<?php

namespace Drupal\fws_counting\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure FWS Counting settings.
 */
class CountingSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_counting_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['fws_counting.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fws_counting.settings');

    $form['page_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page Title'),
      '#default_value' => $config->get('page_title'),
      '#required' => TRUE,
    ];

    $form['intro_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Introduction Text'),
      '#default_value' => $config->get('intro_text'),
      '#required' => TRUE,
    ];

    $form['citation'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Citation'),
      '#default_value' => $config->get('citation'),
      '#required' => TRUE,
    ];

    $form['credits'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Credits'),
      '#default_value' => $config->get('credits'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('fws_counting.settings')
      ->set('page_title', $form_state->getValue('page_title'))
      ->set('intro_text', $form_state->getValue('intro_text'))
      ->set('citation', $form_state->getValue('citation'))
      ->set('credits', $form_state->getValue('credits'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
