<?php

namespace Drupal\ash_salesforce\Source;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * The site form sources whose modules are installed.
 *
 * Resolved at runtime rather than declared as services, because a service
 * naming a missing module's service breaks the container.
 */
class SourceManager {

  /**
   * Source classes by the module that provides their forms.
   */
  protected const SOURCES = [
    'webform' => WebformSource::class,
    'contact' => ContactSource::class,
  ];

  public function __construct(
    protected ClassResolverInterface $classResolver,
    protected ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * The available sources, keyed by ID.
   *
   * @return \Drupal\ash_salesforce\Source\SourceInterface[]
   *   The sources.
   */
  public function all(): array {
    $sources = [];
    foreach (self::SOURCES as $module => $class) {
      if ($this->moduleHandler->moduleExists($module)) {
        $sources[$module] = $this->classResolver->getInstanceFromDefinition($class);
      }
    }
    return $sources;
  }

  /**
   * One source, if its module is installed.
   */
  public function get(string $id): ?SourceInterface {
    return $this->all()[$id] ?? NULL;
  }

  /**
   * The source an entity type's submissions belong to.
   */
  public function forEntityType(string $entity_type_id): ?SourceInterface {
    $id = ['webform_submission' => 'webform', 'contact_message' => 'contact'][$entity_type_id] ?? NULL;
    return $id ? $this->get($id) : NULL;
  }

}
