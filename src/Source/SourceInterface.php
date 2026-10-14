<?php

namespace Drupal\ash_salesforce\Source;

use Drupal\Core\Entity\EntityInterface;

/**
 * A kind of site form whose submissions can go to Salesforce.
 */
interface SourceInterface {

  /**
   * The source ID used in mappings, e.g. 'webform'.
   */
  public function id(): string;

  /**
   * The source's name for people.
   */
  public function label(): string;

  /**
   * The forms of this kind, labels keyed by bundle.
   */
  public function forms(): array;

  /**
   * A form's fields worth mapping, in form order.
   *
   * Leaves out what is never data: buttons, markup, containers, CAPTCHAs.
   *
   * @return array
   *   Keyed by field key, each with 'label' and 'type'.
   */
  public function elements(string $bundle): array;

  /**
   * The form a saved submission came from, or NULL when it isn't ready.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The submission entity.
   * @param bool $update
   *   Whether this save updated an existing submission.
   */
  public function bundleOf(EntityInterface $entity, bool $update): ?string;

  /**
   * A submission's values as strings, keyed like elements().
   */
  public function values(EntityInterface $entity): array;

}
