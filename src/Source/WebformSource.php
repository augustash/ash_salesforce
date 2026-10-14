<?php

namespace Drupal\ash_salesforce\Source;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\WebformInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submissions.
 *
 * A composite element (name, address) is offered part by part, as
 * 'element:part', since Salesforce keeps first and last name apart.
 */
class WebformSource implements SourceInterface, ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Input elements that never carry anything worth sending.
   */
  protected const SKIP = ['captcha', 'webform_terms_of_service', 'password', 'password_confirm', 'webform_actions'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected WebformElementManagerInterface $elementManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.webform.element'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'webform';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->t('Webform');
  }

  /**
   * {@inheritdoc}
   */
  public function forms(): array {
    $forms = [];
    foreach ($this->entityTypeManager->getStorage('webform')->loadMultiple() as $id => $webform) {
      $forms[$id] = $webform->label();
    }
    asort($forms, SORT_NATURAL | SORT_FLAG_CASE);
    return $forms;
  }

  /**
   * {@inheritdoc}
   */
  public function elements(string $bundle): array {
    $webform = $this->entityTypeManager->getStorage('webform')->load($bundle);
    if (!$webform instanceof WebformInterface) {
      return [];
    }
    $elements = [];
    foreach ($webform->getElementsInitializedFlattenedAndHasValue() as $key => $element) {
      $plugin = $this->elementManager->getElementInstance($element);
      if (in_array($element['#type'] ?? '', self::SKIP, TRUE) || !$plugin->isInput($element) || !$this->onForm($element)) {
        continue;
      }
      $label = (string) ($element['#admin_title'] ?? $element['#title'] ?? $key);
      if ($plugin->isComposite() && !empty($element['#webform_composite_elements'])) {
        foreach ($element['#webform_composite_elements'] as $part => $sub) {
          if (isset($sub['#type']) && ($sub['#access'] ?? TRUE) !== FALSE) {
            $elements["$key:$part"] = [
              'label' => $label . ': ' . ($sub['#title'] ?? $part),
              'type' => $sub['#type'],
            ];
          }
        }
        continue;
      }
      $elements[$key] = ['label' => $label, 'type' => $element['#type']];
    }
    return $elements;
  }

  /**
   * Whether a visitor submitting the form sees the element.
   *
   * An element retired from the form but kept for its past submissions is
   * hidden from create and left out here.
   */
  protected function onForm(array $element): bool {
    if (($element['#access'] ?? TRUE) === FALSE) {
      return FALSE;
    }
    $rules = array_intersect_key($element, array_flip([
      '#access_create_roles',
      '#access_create_users',
      '#access_create_permissions',
    ]));
    return !$rules || array_filter($rules);
  }

  /**
   * {@inheritdoc}
   */
  public function bundleOf(EntityInterface $entity, bool $update): ?string {
    /** @var \Drupal\webform\WebformSubmissionInterface $entity */
    if ($entity->isDraft()) {
      return NULL;
    }
    if ($update) {
      // Only the save that completes a draft is new to Salesforce.
      $original = method_exists($entity, 'getOriginal') ? $entity->getOriginal() : ($entity->original ?? NULL);
      if (!$original || !$original->isDraft()) {
        return NULL;
      }
    }
    return $entity->getWebform()->id();
  }

  /**
   * {@inheritdoc}
   */
  public function values(EntityInterface $entity): array {
    /** @var \Drupal\webform\WebformSubmissionInterface $entity */
    $data = $entity->getData();
    $values = [];
    foreach (array_keys($this->elements($entity->getWebform()->id())) as $key) {
      [$element, $part] = array_pad(explode(':', $key, 2), 2, NULL);
      $value = $part === NULL ? ($data[$element] ?? '') : ($data[$element][$part] ?? '');
      $values[$key] = $this->flatten($value);
    }
    return $values;
  }

  /**
   * Joins a multi-value element's values.
   */
  protected function flatten(mixed $value): string {
    if (is_array($value)) {
      return implode(', ', array_filter(array_map(fn($v) => $this->flatten($v), $value), 'strlen'));
    }
    return trim((string) $value);
  }

}
