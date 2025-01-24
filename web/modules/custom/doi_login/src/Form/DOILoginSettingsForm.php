<?php

namespace Drupal\doi_login\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure hello settings for this site.
 */
class DOILoginSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'doi_login_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'doi_login.settings_form',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('doi_login.settings_form');
    $checked = $config->get('doi_login_password_disable');
    if ($checked === NULL) {
      $checked = 1;
    }

    $hide_standard_login = $config->get('hide_standard_login');
    if ($hide_standard_login === NULL) {
      $hide_standard_login = 1;
    }

    $form['hide_standard_login'] = [
      '#type' => 'checkbox',
      '#title' => 'Hide standard login form',
      '#default_value' => $hide_standard_login,
      '#description' => 'If checked, the standard username/password login form will be hidden and only DOI login will be available.',
      '#weight' => -1,
    ];

    $form['doi_login_password_disable'] = [
      '#type' => 'checkbox',
      '#title' => 'Disable Request new password link',
      '#default_value' => $checked,
      '#description' => 'If checked, Request new password link will be disabled.',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('doi_login.settings_form')
      ->set('doi_login_password_disable', $form_state->getValue('doi_login_password_disable'))
      ->set('hide_standard_login', $form_state->getValue('hide_standard_login'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
