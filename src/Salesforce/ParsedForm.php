<?php

namespace Drupal\ash_salesforce\Salesforce;

/**
 * What a Salesforce-generated form tells us about its org.
 */
final class ParsedForm {

  /**
   * Constructs a ParsedForm.
   *
   * @param string $kind
   *   Either 'case' or 'lead'.
   * @param string $action
   *   The servlet URL to post to.
   * @param array $params
   *   Hidden inputs posted with every record, such as the org ID.
   * @param \Drupal\ash_salesforce\Salesforce\Field[] $fields
   *   The form's fields, keyed by mapping key, in form order.
   * @param \Drupal\ash_salesforce\Salesforce\Field[] $standard
   *   Standard fields the form left out, offered as constants.
   */
  public function __construct(
    public readonly string $kind,
    public readonly string $action,
    public readonly array $params,
    public readonly array $fields,
    public readonly array $standard,
  ) {}

  /**
   * A field a mapping can name, from the form or the standard extras.
   */
  public function field(string $key): ?Field {
    return $this->fields[$key] ?? $this->standard[$key] ?? NULL;
  }

}
