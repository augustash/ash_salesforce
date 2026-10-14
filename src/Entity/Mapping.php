<?php

namespace Drupal\ash_salesforce\Entity;

use Drupal\ash_salesforce\Form\MappingForm;
use Drupal\ash_salesforce\MappingListBuilder;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Which site form fields a Salesforce form receives.
 *
 * Fields and constants are keyed by the parsed form's stable keys, never by
 * org-specific IDs, so pointing a mapping at another org's form (sandbox to
 * production) is a single setting.
 */
#[ConfigEntityType(
  id: 'ash_salesforce_mapping',
  label: new TranslatableMarkup('Salesforce form mapping'),
  label_collection: new TranslatableMarkup('Salesforce form mappings'),
  label_singular: new TranslatableMarkup('Salesforce form mapping'),
  label_plural: new TranslatableMarkup('Salesforce form mappings'),
  config_prefix: 'mapping',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  handlers: [
    'list_builder' => MappingListBuilder::class,
    'form' => [
      'add' => MappingForm::class,
      'edit' => MappingForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/config/services/ash-salesforce',
    'add-form' => '/admin/config/services/ash-salesforce/add',
    'edit-form' => '/admin/config/services/ash-salesforce/{ash_salesforce_mapping}',
    'delete-form' => '/admin/config/services/ash-salesforce/{ash_salesforce_mapping}/delete',
  ],
  admin_permission: 'administer ash_salesforce',
  config_export: ['id', 'label', 'source', 'salesforce_form', 'fields', 'constants'],
)]
class Mapping extends ConfigEntityBase {

  /**
   * Picked for a site form field that shouldn't be sent.
   */
  public const NONE = '_none';

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The label.
   */
  protected ?string $label = NULL;

  /**
   * The site form, as 'source:bundle', e.g. 'webform:contact'.
   */
  protected string $source = '';

  /**
   * The Salesforce form to post to.
   */
  protected string $salesforce_form = '';

  /**
   * Salesforce field key per site form field, or NONE.
   *
   * A site form field missing here hasn't been decided yet.
   */
  protected array $fields = [];

  /**
   * Fixed values per Salesforce field key; a picklist's by option label.
   */
  protected array $constants = [];

  /**
   * The source plugin ID and bundle.
   *
   * @return string[]
   *   The source ID and the bundle, empty strings when unset.
   */
  public function getSource(): array {
    return array_pad(explode(':', $this->source, 2), 2, '');
  }

  /**
   * The Salesforce form ID.
   */
  public function getSalesforceForm(): string {
    return $this->salesforce_form;
  }

  /**
   * Salesforce field key per site form field.
   */
  public function getFields(): array {
    return $this->fields;
  }

  /**
   * Picks the Salesforce field a site form field goes to.
   */
  public function setField(string $element, string $target): static {
    // A Salesforce field takes one value, so it can only be picked once.
    if ($target !== self::NONE) {
      $this->fields = array_filter($this->fields, fn($t) => $t !== $target);
    }
    $this->fields[$element] = $target;
    return $this;
  }

  /**
   * Fixed values per Salesforce field key.
   */
  public function getConstants(): array {
    return $this->constants;
  }

  /**
   * Sets the fixed values, dropping empty ones.
   */
  public function setConstants(array $constants): static {
    $this->constants = array_filter(array_map('trim', $constants), 'strlen');
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    if ($this->salesforce_form && ($form = SalesforceForm::load($this->salesforce_form))) {
      $this->addDependency('config', $form->getConfigDependencyName());
    }
    [$source] = $this->getSource();
    $module = ['webform' => 'webform', 'contact' => 'contact'][$source] ?? NULL;
    if ($module) {
      $this->addDependency('module', $module);
    }
    return $this;
  }

}
