<?php

namespace Drupal\fws_sighting\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure FWS Sighting settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_sighting_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['fws_sighting.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fws_sighting.settings');

    $form['auto_state_lookup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically lookup state from coordinates'),
      '#description' => $this->t('When enabled, the state field will be automatically populated based on the location coordinates. Disable this during bulk imports to improve performance.'),
      '#default_value' => $config->get('auto_state_lookup') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('fws_sighting.settings')
      ->set('auto_state_lookup', $form_state->getValue('auto_state_lookup'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
