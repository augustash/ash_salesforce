<?php

namespace Drupal\ash_salesforce\Salesforce;

/**
 * One field of a Salesforce-generated form.
 */
final class Field {

  /**
   * Constructs a Field.
   *
   * @param string $key
   *   The key mappings use, stable across orgs.
   * @param string $name
   *   The parameter name to post, which for a custom field is its org's ID.
   * @param string $label
   *   The label Salesforce gave it.
   * @param string $type
   *   The input type: text, email, select, textarea and so on.
   * @param int|null $maxlength
   *   Salesforce drops a record whose value overruns this, without saying so.
   * @param array $options
   *   A picklist's labels, keyed by the value to post.
   */
  public function __construct(
    public readonly string $key,
    public readonly string $name,
    public readonly string $label,
    public readonly string $type,
    public readonly ?int $maxlength,
    public readonly array $options,
  ) {}

  /**
   * Resolves a constant, stored by option label, to the value to post.
   *
   * Option values are IDs unique to each org, so mappings store the label.
   * Returns NULL when this org's picklist has no such option.
   */
  public function resolve(string $value): ?string {
    if (!$this->options) {
      return $value;
    }
    $found = array_search($value, $this->options, TRUE);
    if ($found !== FALSE) {
      return (string) $found;
    }
    return isset($this->options[$value]) ? $value : NULL;
  }

  /**
   * Cuts a value to the field's length.
   */
  public function fit(string $value): string {
    return $this->maxlength ? mb_substr($value, 0, $this->maxlength) : $value;
  }

}
