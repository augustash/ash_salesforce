<?php

namespace Drupal\ash_salesforce;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists mappings with the form they send and where to.
 */
class MappingListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'label' => $this->t('Mapping'),
      'source' => $this->t('Site form'),
      'salesforce_form' => $this->t('Salesforce form'),
      'status' => $this->t('Sending'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\ash_salesforce\Entity\Mapping $entity */
    [$source_id, $bundle] = $entity->getSource();
    $source = \Drupal::service('ash_salesforce.sources')->get($source_id);
    $salesforce_form = \Drupal::entityTypeManager()->getStorage('ash_salesforce_form')->load($entity->getSalesforceForm());
    return [
      'label' => $entity->label(),
      'source' => $source ? $source->label() . ': ' . ($source->forms()[$bundle] ?? $bundle) : $entity->getSource()[0],
      'salesforce_form' => $salesforce_form?->label() ?? $this->t('Missing: @id', ['@id' => $entity->getSalesforceForm()]),
      'status' => $entity->status() ? $this->t('Yes') : $this->t('No'),
    ] + parent::buildRow($entity);
  }

}
