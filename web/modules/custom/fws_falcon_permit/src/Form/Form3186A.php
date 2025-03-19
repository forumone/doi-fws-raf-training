<?php

namespace Drupal\fws_falcon_permit\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form for reporting falcon activities (Form 3-186A).
 */
class Form3186A extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_falcon_permit_3_186a';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['activity_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select which activity you are reporting'),
      '#description' => $this->t('Then click next to complete the sections listed in parentheses () for that activity.'),
      '#options' => [
        1 => $this->t('1. transferred a bird to another permittee (or to another permit you hold) (1, 2, 3, 6)'),
        2 => $this->t('2. released a bird or lost a bird due to its escape, theft, or death (1, 2, 6)'),
        3 => $this->t('3. acquired bird from another permittee, other than a rehabilitator, (1, 2, 3, 6)'),
        4 => $this->t('4. acquired bird from a rehabilitation permittee (1, 2, 3, 6)'),
        5 => $this->t('5. captured a bird from the wild or recaptured a previously captive (banded) bird (1, 2, 4, 6)'),
        6 => $this->t('6. re-banded a bird, either wild or captive-bred, for which the band was lost or removed (1, 2, 5, 6)'),
      ],
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Next'),
      '#attributes' => [
        'class' => ['btn btn-primary'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Store the selected activity in session and redirect to appropriate section.
    $_SESSION['fws_falcon_permit_activity'] = $form_state->getValue('activity_type');
    $form_state->setRedirect('fws_falcon_permit.form_3_186a_section1');
  }

  /**
   * Submit handler for the back button.
   */
  public function backSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('<front>');
  }

  /**
   * Submit handler for the exit button.
   */
  public function exitSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('<front>');
  }

}
