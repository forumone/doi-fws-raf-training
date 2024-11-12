<?php
namespace Drupal\fws_search\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class FwsSearchConfigForm extends ConfigFormBase {

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return 'fws_search_config';
    }

    /**
     * {@inheritdoc}
     */
    public function getEditableConfigNames() {
      return [
        'fws_search.config',
      ];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state) {
        $config = $this->config('fws_search.config');

        $form['backreferences'] = [
            '#type' => 'textarea',
            '#rows' => 20,
            '#default_value' => $config->get('backreferences'),
            '#title' => 'Backreferences config',
            '#description' => $this->t('Configuration of entity types/bundles that have other entities referencing them and need to be indexed.'),
        ];

        return parent::buildForm($form,$form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $config = $this->config('fws_search.config');
        $config->set('backreferences',$form_state->getValue('backreferences'));
        $config->save();
        parent::submitForm($form,$form_state);
    }
}