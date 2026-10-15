<?php

namespace Drupal\ash_salesforce\Entity;

use Drupal\ash_salesforce\Form\SalesforceFormForm;
use Drupal\ash_salesforce\SalesforceFormListBuilder;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A Web-to-Case or Web-to-Lead form, as the client's Salesforce generated it.
 *
 * One per org: field IDs and picklist values differ between a sandbox and
 * production, so each gets its own, and a mapping points at whichever the
 * environment should post to.
 *
 * Declared twice: Drupal 10 reads only the annotation, and entity type
 * attributes arrived in 11.1. Keep the two in step until 10 is dropped.
 *
 * @ConfigEntityType(
 *   id = "ash_salesforce_form",
 *   label = @Translation("Salesforce form"),
 *   label_collection = @Translation("Salesforce forms"),
 *   label_singular = @Translation("Salesforce form"),
 *   label_plural = @Translation("Salesforce forms"),
 *   config_prefix = "form",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   handlers = {
 *     "list_builder" = "Drupal\ash_salesforce\SalesforceFormListBuilder",
 *     "form" = {
 *       "add" = "Drupal\ash_salesforce\Form\SalesforceFormForm",
 *       "edit" = "Drupal\ash_salesforce\Form\SalesforceFormForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "collection" = "/admin/config/services/ash-salesforce/forms",
 *     "add-form" = "/admin/config/services/ash-salesforce/forms/add",
 *     "edit-form" = "/admin/config/services/ash-salesforce/forms/{ash_salesforce_form}",
 *     "delete-form" = "/admin/config/services/ash-salesforce/forms/{ash_salesforce_form}/delete",
 *   },
 *   admin_permission = "administer ash_salesforce",
 *   config_export = {
 *     "id",
 *     "label",
 *     "html",
 *   },
 * )
 */
#[ConfigEntityType(
  id: 'ash_salesforce_form',
  label: new TranslatableMarkup('Salesforce form'),
  label_collection: new TranslatableMarkup('Salesforce forms'),
  label_singular: new TranslatableMarkup('Salesforce form'),
  label_plural: new TranslatableMarkup('Salesforce forms'),
  config_prefix: 'form',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
  ],
  handlers: [
    'list_builder' => SalesforceFormListBuilder::class,
    'form' => [
      'add' => SalesforceFormForm::class,
      'edit' => SalesforceFormForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'collection' => '/admin/config/services/ash-salesforce/forms',
    'add-form' => '/admin/config/services/ash-salesforce/forms/add',
    'edit-form' => '/admin/config/services/ash-salesforce/forms/{ash_salesforce_form}',
    'delete-form' => '/admin/config/services/ash-salesforce/forms/{ash_salesforce_form}/delete',
  ],
  admin_permission: 'administer ash_salesforce',
  config_export: ['id', 'label', 'html'],
)]
class SalesforceForm extends ConfigEntityBase {

  /**
   * The machine name.
   */
  protected ?string $id = NULL;

  /**
   * The label.
   */
  protected ?string $label = NULL;

  /**
   * The generated HTML, kept as pasted so it can be re-read or compared.
   */
  protected string $html = '';

  /**
   * The generated HTML.
   */
  public function getHtml(): string {
    return $this->html;
  }

}
