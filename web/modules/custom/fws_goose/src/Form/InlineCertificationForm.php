<?php

namespace Drupal\fws_goose\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a simplified certification form for inline use.
 */
class InlineCertificationForm extends FormBase {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new InlineCertificationForm.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fws_goose_inline_certification_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $user_id = NULL) {
    // Don't show any certification text here as it's already displayed in the template.
    $form['certify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I Certify'),
      '#required' => TRUE,
    ];

    $form['user_id'] = [
      '#type' => 'hidden',
      '#value' => $user_id ?? $this->currentUser()->id(),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('SUBMIT CERTIFICATION'),
      '#attributes' => [
        'class' => ['btn', 'btn-primary'],
        'style' => 'text-transform: uppercase; padding: 10px 20px; font-weight: bold;',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user_id = $form_state->getValue('user_id');

    // Load the user account.
    $user = User::load($user_id);

    if ($user && $user->hasField('field_applicant_agree_to_certify')) {
      $user->set('field_applicant_agree_to_certify', TRUE);
      $user->save();

      $this->messenger->addStatus($this->t('Your certification has been recorded. Your registration is now complete.'));
    }
    else {
      $this->messenger->addError($this->t('There was a problem updating your certification.'));
    }

    $form_state->setRedirect('entity.user.canonical', ['user' => $this->currentUser()->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Add more specific validation if needed.
    if (!$form_state->getValue('certify')) {
      $form_state->setErrorByName('certify', $this->t('You must check the certification box to complete your registration.'));
    }
  }

}
