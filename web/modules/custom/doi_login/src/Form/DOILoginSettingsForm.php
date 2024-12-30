<?php
/**
 * @file
 * Contains \Drupal\doi_login\DOILoginSettingsForm
 */
namespace Drupal\doi_login\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Database\Query;

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
    if($checked === NULL){
      $checked = 1;
    }
    $form['doi_login_password_disable'] = array(
      '#type' => 'checkbox',
      '#title' => 'Disable Request new password link',
      '#default_value' => $checked,
      '#description' => 'If checked, Request new password link will be disabled.'
    );
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('doi_login.settings_form')
      ->set('doi_login_password_disable', $form_state->getValue('doi_login_password_disable'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}