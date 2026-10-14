<?php

namespace Drupal\ash_salesforce;

use Drupal\ash_salesforce\Salesforce\Field;

/**
 * Suggests the Salesforce field a site form field most likely goes to.
 *
 * Only a suggestion: the mapping screen shows it first and still waits for a
 * click, so a wrong guess costs nothing. It guesses only when exactly one
 * target shares the field's concept, rather than ranking near misses.
 */
class Guesser {

  /**
   * Words that name a concept, in the order they're tested.
   *
   * First and last name come before name, so 'first_name' isn't read as a
   * plain name.
   */
  protected const CONCEPTS = [
    'first_name' => ['first', 'firstname', 'fname', 'given'],
    'last_name' => ['last', 'lastname', 'lname', 'surname', 'family'],
    'email' => ['email', 'mail', 'e-mail'],
    'phone' => ['phone', 'telephone', 'tel', 'mobile', 'cell'],
    'subject' => ['subject', 'title', 'topic', 'regarding'],
    'description' => ['description', 'message', 'body', 'comments', 'comment', 'details', 'inquiry', 'question'],
    'company' => ['company', 'organization', 'organisation', 'business', 'employer'],
    'name' => ['name', 'fullname'],
  ];

  /**
   * The key of the one target sharing the field's concept, or NULL.
   *
   * @param string $key
   *   The site form field's machine name.
   * @param string $label
   *   Its label.
   * @param \Drupal\ash_salesforce\Salesforce\Field[] $targets
   *   The Salesforce fields still free to pick.
   */
  public function guess(string $key, string $label, array $targets): ?string {
    $concept = $this->concept($key . ' ' . $label);
    if (!$concept) {
      return NULL;
    }
    $matches = array_filter($targets, fn(Field $target) => $this->concept($target->key . ' ' . $target->label) === $concept);
    return count($matches) === 1 ? array_key_first($matches) : NULL;
  }

  /**
   * The first concept any word of a name belongs to.
   */
  protected function concept(string $text): ?string {
    // Split camelCase and snake_case alike: 'firstName', 'first_name'.
    $text = strtolower(preg_replace('/([a-z])([A-Z])/', '$1 $2', $text));
    $words = preg_split('/[^a-z0-9-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    foreach (self::CONCEPTS as $concept => $synonyms) {
      if (array_intersect($words, $synonyms)) {
        return $concept;
      }
    }
    return NULL;
  }

}
