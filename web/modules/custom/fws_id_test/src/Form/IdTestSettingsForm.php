<?php

namespace Drupal\fws_id_test\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure FWS ID Test settings.
 */
class IdTestSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_id_test_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['fws_id_test.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fws_id_test.settings');

    $form['intro_text'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Introduction Text'),
      '#default_value' => $config->get('intro_text.value'),
      '#format' => $config->get('intro_text.format') ?: 'basic_html',
      '#description' => $this->t('Enter the introductory text that will be displayed at the top of the ID test configuration page.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('fws_id_test.settings')
      ->set('intro_text', $form_state->getValue('intro_text'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
