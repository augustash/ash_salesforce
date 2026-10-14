<?php

namespace Drupal\ash_salesforce\Source;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\FieldConfigInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Core contact form messages.
 *
 * The sender's name and email come from the message form itself; subject,
 * message and added fields only count while the form display shows them, so
 * a retired field kept for its stored data is left out.
 */
class ContactSource implements SourceInterface, ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'contact';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t('Contact form');
  }

  /**
   * {@inheritdoc}
   */
  public function forms(): array {
    $forms = [];
    foreach ($this->entityTypeManager->getStorage('contact_form')->loadMultiple() as $id => $form) {
      // The personal form mails a user; it's not an inquiry.
      if ($id !== 'personal') {
        $forms[$id] = $form->label();
      }
    }
    asort($forms, SORT_NATURAL | SORT_FLAG_CASE);
    return $forms;
  }

  /**
   * {@inheritdoc}
   */
  public function elements(string $bundle): array {
    $elements = [
      'name' => ['label' => (string) $this->t('Your name'), 'type' => 'string'],
      'mail' => ['label' => (string) $this->t('Your email address'), 'type' => 'email'],
    ];
    $display = $this->entityTypeManager->getStorage('entity_form_display')->load("contact_message.$bundle.default");
    $definitions = $this->entityFieldManager->getFieldDefinitions('contact_message', $bundle);
    $components = $display ? $display->getComponents() : [];
    uasort($components, fn($a, $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    foreach (array_keys($components) as $name) {
      $definition = $definitions[$name] ?? NULL;
      if ($definition && (in_array($name, ['subject', 'message'], TRUE) || $definition instanceof FieldConfigInterface)) {
        $elements[$name] = ['label' => (string) $definition->getLabel(), 'type' => $definition->getType()];
      }
    }
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function bundleOf(EntityInterface $entity, bool $update): ?string {
    return $update ? NULL : $entity->bundle();
  }

  /**
   * {@inheritdoc}
   */
  public function values(EntityInterface $entity): array {
    /** @var \Drupal\contact\MessageInterface $entity */
    $values = [];
    foreach (array_keys($this->elements($entity->bundle())) as $name) {
      $values[$name] = trim(match ($name) {
        'name' => (string) $entity->getSenderName(),
        'mail' => (string) $entity->getSenderMail(),
        default => $entity->hasField($name) ? $entity->get($name)->getString() : '',
      });
    }
    return $values;
  }

}
