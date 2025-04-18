<?php

namespace Drupal\fws_goose\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;

/**
 * Provides a simplified certification form for inline use with permit nodes.
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
  public function buildForm(array $form, FormStateInterface $form_state, $node_id = NULL) {
    // Don't show any certification text here as it's already displayed in the template.
    $form['certify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I Certify'),
      '#required' => TRUE,
    ];

    $form['node_id'] = [
      '#type' => 'hidden',
      '#value' => $node_id,
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
    $node_id = $form_state->getValue('node_id');

    if (empty($node_id)) {
      $this->messenger->addError($this->t('No permit specified for certification.'));
      return;
    }

    $node = Node::load($node_id);

    if (!$node || $node->bundle() !== 'permit') {
      $this->messenger->addError($this->t('Could not load permit to process certification.'));
      \Drupal::logger('fws_goose')->error('Could not load permit node @nid during certification, or it was not a permit type.', ['@nid' => $node_id]);
      return;
    }

    // Update the node's certification field
    // We'll first check if the field exists before setting it.
    $was_updated = FALSE;

    // Use the correct field names.
    if ($node->hasField('field_applicant_signed')) {
      $node->set('field_applicant_signed', TRUE);
      $was_updated = TRUE;
    }

    // Also update the timestamp field if it exists.
    if ($node->hasField('field_dt_applicant_signed')) {
      $node->set('field_dt_applicant_signed', \Drupal::time()->getRequestTime());
      $was_updated = TRUE;
    }

    if ($was_updated) {
      $node->save();

      // Get the owner info if needed for messaging.
      $owner = $node->getOwner();
      $owner_name = $owner ? $owner->getDisplayName() : $this->t('Unknown');

      // Optionally, also update the user's certification field to maintain backward compatibility.
      if ($owner && $owner->hasField('field_applicant_agree_to_certify')) {
        $owner->set('field_applicant_agree_to_certify', TRUE);
        $owner->save();
        \Drupal::logger('fws_goose')->notice('Updated user @uid certification status for backward compatibility.', ['@uid' => $owner->id()]);
      }

      // Send notification email using this node.
      if (function_exists('fws_goose_send_certification_notification')) {
        fws_goose_send_certification_notification($node);
      }

      $this->messenger->addStatus($this->t('Your certification has been recorded. Your registration is now complete.'));
    }
    else {
      $this->messenger->addError($this->t('There was a problem updating the permit certification status. Required fields may be missing.'));
      \Drupal::logger('fws_goose')->error('Permit node @nid does not have expected certification fields.', ['@nid' => $node_id]);
    }

    // Redirect to the node page.
    $form_state->setRedirect('entity.node.canonical', ['node' => $node_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate that we have a node ID.
    if (empty($form_state->getValue('node_id'))) {
      $form_state->setErrorByName('node_id', $this->t('No permit specified for certification.'));
      return;
    }

    // Add more specific validation if needed.
    if (!$form_state->getValue('certify')) {
      $form_state->setErrorByName('certify', $this->t('You must check the certification box to complete your registration.'));
    }

    // Validate that the node exists and is the correct type.
    $node_id = $form_state->getValue('node_id');
    $node = Node::load($node_id);
    if (!$node || $node->bundle() !== 'permit') {
      $form_state->setErrorByName('node_id', $this->t('Invalid permit selected for certification.'));
    }
  }

}
