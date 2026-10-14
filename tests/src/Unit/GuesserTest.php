<?php

namespace Drupal\Tests\ash_salesforce\Unit;

use Drupal\ash_salesforce\Guesser;
use Drupal\ash_salesforce\Salesforce\Field;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the suggested Salesforce field for a site form field.
 *
 * @group aai
 * @group ash_salesforce
 */
#[Group('aai')]
#[Group('ash_salesforce')]
class GuesserTest extends UnitTestCase {

  /**
   * Common site form names find their Salesforce field.
   *
   * @dataProvider provideGuesses
   */
  #[DataProvider('provideGuesses')]
  public function testGuess(string $key, string $label, ?string $expected): void {
    $this->assertSame($expected, (new Guesser())->guess($key, $label, $this->targets()));
  }

  /**
   * Site form fields and the target each should suggest.
   */
  public static function provideGuesses(): array {
    return [
      'snake case' => ['first_name', 'First Name', 'first_name'],
      'camel case' => ['lastName', 'Surname', 'last_name'],
      'label only' => ['field_1', 'Your email address', 'email'],
      'message is the description' => ['message', 'Message', 'description'],
      'title is the subject' => ['title', 'Title', 'subject'],
      // First/last come before plain name, so a name part isn't a full name.
      'composite part' => ['name:first', 'Your Name: First', 'first_name'],
      // No full-name target exists, so it's left to a click.
      'no matching target' => ['name', 'Your Name', NULL],
      'unrelated' => ['hangar', 'Hangar size', NULL],
    ];
  }

  /**
   * Nothing is guessed when two targets share the concept.
   */
  public function testAmbiguous(): void {
    $targets = $this->targets() + ['phone_2' => new Field('phone_2', '00N2', 'Mobile Phone', 'text', NULL, [])];
    $this->assertNull((new Guesser())->guess('phone', 'Phone', $targets));
  }

  /**
   * Targets shaped like MSP's Web-to-Case form.
   */
  protected function targets(): array {
    $targets = [];
    foreach ([
      'first_name' => ['00N1', 'First Name'],
      'last_name' => ['00N2', 'Last Name'],
      'email' => ['email', 'Email'],
      'phone' => ['phone', 'Phone'],
      'subject' => ['subject', 'Subject'],
      'description' => ['description', 'Description'],
      'recordType' => ['recordType', 'Case Record Type'],
    ] as $key => [$name, $label]) {
      $targets[$key] = new Field($key, $name, $label, 'text', NULL, []);
    }
    return $targets;
  }

}
