<?php

namespace Drupal\fws_lock_updates\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure FWS Lock Updates settings.
 */
class LockUpdatesSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['fws_lock_updates.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_lock_updates_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fws_lock_updates.settings');

    $form['lock_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of seconds before content is locked'),
      '#description' => $this->t('After this many seconds, content owners will no longer be able to edit their content. Default is 2592000 (30 days).'),
      '#default_value' => $config->get('lock_seconds') ?: 2592000, // 30 days in seconds
      '#min' => 1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('fws_lock_updates.settings')
      ->set('lock_seconds', $form_state->getValue('lock_seconds'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
