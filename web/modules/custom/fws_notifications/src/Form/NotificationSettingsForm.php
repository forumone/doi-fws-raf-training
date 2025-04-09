<?php

namespace Drupal\fws_notifications\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure FWS Notifications settings.
 */
class NotificationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['fws_notifications.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_notifications_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fws_notifications.settings');

    $form['notification_emails'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notification Email Addresses'),
      '#description' => $this->t('Enter email addresses to receive notifications, one per line.'),
      '#default_value' => $config->get('notification_emails') ? implode("\n", $config->get('notification_emails')) : '',
      '#rows' => 5,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $emails = $form_state->getValue('notification_emails');
    if (!empty($emails)) {
      $emails_array = explode("\n", $emails);
      $emails_array = array_map('trim', $emails_array);
      $emails_array = array_filter($emails_array);

      foreach ($emails_array as $email) {
        if (!\Drupal::service('email.validator')->isValid($email)) {
          $form_state->setErrorByName('notification_emails', $this->t('The email address %email is not valid.', ['%email' => $email]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $emails = $form_state->getValue('notification_emails');
    $emails_array = [];

    if (!empty($emails)) {
      $emails_array = explode("\n", $emails);
      $emails_array = array_map('trim', $emails_array);
      $emails_array = array_filter($emails_array);
    }

    $this->config('fws_notifications.settings')
      ->set('notification_emails', $emails_array)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
