<?php

namespace Drupal\ash_salesforce;

use Drupal\ash_salesforce\Entity\Mapping;
use Drupal\ash_salesforce\EventSubscriber\QueueRunner;
use Drupal\ash_salesforce\Salesforce\FormParser;
use Drupal\ash_salesforce\Salesforce\ParsedForm;
use Drupal\ash_salesforce\Source\SourceManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Queues a saved submission for each mapping that wants it.
 */
class Dispatcher {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SourceManager $sources,
    protected FormParser $parser,
    protected QueueFactory $queueFactory,
    protected QueueRunner $queueRunner,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Queues a just-saved submission.
   *
   * The payload is built now and queued as-is, so a retry sends what was
   * submitted even if the submission or the mapping changes meanwhile.
   */
  public function dispatch(EntityInterface $entity, bool $update): void {
    $source = $this->sources->forEntityType($entity->getEntityTypeId());
    $bundle = $source?->bundleOf($entity, $update);
    if ($bundle === NULL) {
      return;
    }
    $mappings = $this->entityTypeManager->getStorage('ash_salesforce_mapping')->loadByProperties([
      'source' => $source->id() . ':' . $bundle,
      'status' => TRUE,
    ]);
    if (!$mappings) {
      return;
    }

    $values = $source->values($entity);
    $queue = $this->queueFactory->get('ash_salesforce', TRUE);
    foreach ($mappings as $mapping) {
      $form = $this->form($mapping);
      if (!$form) {
        continue;
      }
      $queue->createItem([
        'mapping' => $mapping->id(),
        'submission' => $entity->getEntityTypeId() . ':' . ($entity->id() ?? $entity->uuid()),
        'action' => $form->action,
        'payload' => $this->payload($mapping, $form, $values),
      ]);
      $this->queueRunner->flag();
    }
  }

  /**
   * Builds the POST body for one mapping.
   */
  public function payload(Mapping $mapping, ParsedForm $form, array $values): array {
    $payload = $form->params;
    foreach ($mapping->getFields() as $element => $key) {
      if ($key === Mapping::NONE || ($values[$element] ?? '') === '') {
        continue;
      }
      if ($field = $form->field($key)) {
        $payload[$field->name] = $field->fit($values[$element]);
      }
      else {
        $this->missing($mapping, $key);
      }
    }
    foreach ($mapping->getConstants() as $key => $value) {
      $field = $form->field($key);
      $resolved = $field?->resolve($value);
      if ($resolved === NULL) {
        $this->missing($mapping, "$key = $value");
        continue;
      }
      $payload[$field->name] = $field->fit($resolved);
    }
    return $payload;
  }

  /**
   * The parsed Salesforce form a mapping posts to.
   */
  public function form(Mapping $mapping): ?ParsedForm {
    $entity = $this->entityTypeManager->getStorage('ash_salesforce_form')->load($mapping->getSalesforceForm());
    if (!$entity) {
      $this->logger->error('Mapping @mapping points at Salesforce form @form, which does not exist; nothing was sent.', [
        '@mapping' => $mapping->id(),
        '@form' => $mapping->getSalesforceForm(),
      ]);
      return NULL;
    }
    try {
      return $this->parser->parse($entity->getHtml());
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->error('Salesforce form @form could not be read; nothing was sent: @error', [
        '@form' => $entity->id(),
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Logs a mapped field this org's form doesn't have.
   *
   * Usually a form pasted from another org that lacks a field, or a picklist
   * option, the mapping relies on. The rest of the record still goes.
   */
  protected function missing(Mapping $mapping, string $what): void {
    $this->logger->warning('Mapping @mapping sends @what, which its Salesforce form does not have; left out.', [
      '@mapping' => $mapping->id(),
      '@what' => $what,
    ]);
  }

}
