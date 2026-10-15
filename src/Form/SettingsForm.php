<?php

namespace Drupal\ash_salesforce\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Site-wide settings for Salesforce form mappings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ash_salesforce_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ash_salesforce.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['site_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site label'),
      '#description' => $this->t('A short name for this site, such as "Metro". A new mapping suggests the site label and the form name as its origin, such as "Metro Contact Form", so a Salesforce org that takes records from several sites can tell them apart. Leave empty to use the site name, %name.', [
        '%name' => $this->config('system.site')->get('name'),
      ]),
      '#config_target' => 'ash_salesforce.settings:site_label',
      '#maxlength' => 40,
      '#size' => 20,
    ];
    return parent::buildForm($form, $form_state);
  }

}
