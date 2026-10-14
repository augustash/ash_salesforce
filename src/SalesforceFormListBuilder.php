<?php

namespace Drupal\ash_salesforce;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists pasted Salesforce forms with what was read from each.
 */
class SalesforceFormListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Salesforce form'),
      'kind' => $this->t('Creates'),
      'host' => $this->t('Org'),
      'fields' => $this->t('Fields'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ash_salesforce\Entity\SalesforceForm $entity */
    try {
      $form = \Drupal::service('ash_salesforce.form_parser')->parse($entity->getHtml());
      $row = [
        'kind' => $form->kind === 'case' ? $this->t('Cases') : $this->t('Leads'),
        'host' => parse_url($form->action, PHP_URL_HOST),
        'fields' => count($form->fields),
      ];
    }
    catch (\InvalidArgumentException) {
      $row = ['kind' => $this->t('Unreadable'), 'host' => '', 'fields' => 0];
    }
    return ['label' => $entity->label()] + $row + parent::buildRow($entity);
  }

}
