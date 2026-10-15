<?php

namespace Drupal\Tests\ash_salesforce\Functional;

use Drupal\ash_salesforce\Entity\Mapping;
use Drupal\contact\Entity\ContactForm;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests pasting a Salesforce form and mapping a site form onto it.
 *
 * Runs without JavaScript: every wizard button is a plain submit that AJAX
 * only enhances, so the same steps work with scripts off.
 *
 * @group aai
 * @group ash_salesforce
 */
#[Group('aai')]
#[Group('ash_salesforce')]
class MappingWizardTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['contact', 'ash_salesforce'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ContactForm::create(['id' => 'feedback', 'label' => 'Website feedback', 'recipients' => []])->save();
    $this->container->get('entity_display.repository')->getFormDisplay('contact_message', 'feedback')
      ->setComponent('subject', ['type' => 'string_textfield'])
      ->setComponent('message', ['type' => 'string_textarea'])
      ->save();
    $this->drupalLogin($this->drupalCreateUser(['administer ash_salesforce']));
  }

  /**
   * A form is pasted, mapped field by field, and saved.
   */
  public function testWizard(): void {
    $this->drupalGet('admin/config/services/ash-salesforce/settings');
    $this->submitForm(['site_label' => 'Metro'], 'Save configuration');

    $this->drupalGet('admin/config/services/ash-salesforce/forms/add');
    $this->submitForm([
      'label' => 'UAT',
      'id' => 'uat',
      'html' => 'Not a Salesforce form',
    ], 'Save');
    $this->assertSession()->pageTextContains('This is not a Salesforce Web-to-Case or Web-to-Lead form.');
    $this->submitForm(['html' => file_get_contents(__DIR__ . '/../../fixtures/web-to-case.html')], 'Save');
    // What was read is shown back, so a bad paste is obvious.
    $this->assertSession()->pageTextContains('Read from the form: Cases to mspmac--uat.sandbox.my.salesforce.com');
    $this->assertSession()->pageTextContains('00NVE00000BWFSD');

    $this->drupalGet('admin/config/services/ash-salesforce/add');
    $this->submitForm([
      'label' => 'Feedback',
      'id' => 'feedback',
      'source' => 'contact:feedback',
    ], 'Start mapping');

    // Name has no full-name target in this org, so nothing is suggested.
    $this->assertSession()->pageTextContains('Field 1 of 4');
    $this->assertSession()->elementNotExists('css', '.is-suggested');
    $this->submitForm([], 'target___none');

    // Email is suggested, and offered first.
    $this->assertSession()->pageTextContains('Field 2 of 4');
    $this->assertSession()->elementAttributeContains('css', '.ash-sf-target.is-suggested input', 'name', 'target__email');
    $this->assertSession()->elementAttributeContains('css', '.ash-sf-target:first-child input', 'name', 'target__email');
    $this->submitForm([], 'target__email');
    $this->submitForm([], 'target__subject');
    // A picked Salesforce field isn't offered again.
    $this->assertSession()->buttonNotExists('target__subject');
    $this->assertSession()->elementAttributeContains('css', '.ash-sf-target.is-suggested input', 'name', 'target__description');
    $this->submitForm([], 'target__description');

    // Fixed values: picklists by label, standard fields the form left out.
    $this->assertSession()->fieldExists('constants[origin]');
    $this->assertSession()->fieldNotExists('constants[email]');
    // The origin is suggested as site label + form label; a picklist isn't.
    $this->assertSession()->fieldValueEquals('constants[origin]', 'Metro Website feedback');
    $this->assertSession()->checkboxChecked('edit-constants-recordtype-');
    $this->submitForm([
      'constants[recordType]' => 'NSC Cases',
      'constants[origin]' => 'Website feedback',
    ], 'Continue');

    $this->assertSession()->pageTextContains('Not sent');
    $this->assertSession()->pageTextContains('Website feedback');
    // Revisiting one field returns straight to the summary.
    $this->submitForm([], 'change__mail');
    $this->assertSession()->pageTextContains('Current');
    $this->submitForm([], 'target__phone');
    $this->assertSession()->pageTextContains('Website feedback');
    $this->submitForm([], 'Save');

    $mapping = Mapping::load('feedback');
    $this->assertSame('contact:feedback', implode(':', $mapping->getSource()));
    $this->assertSame('uat', $mapping->getSalesforceForm());
    $this->assertSame([
      'name' => Mapping::NONE,
      'mail' => 'phone',
      'subject' => 'subject',
      'message' => 'description',
    ], $mapping->getFields());
    $this->assertSame(['recordType' => 'NSC Cases', 'origin' => 'Website feedback'], $mapping->getConstants());
    $this->assertTrue($mapping->status());

    // Editing opens on the summary.
    $this->drupalGet('admin/config/services/ash-salesforce/feedback');
    $this->assertSession()->buttonExists('change__mail');

    // An existing mapping's origin is the editor's: cleared stays cleared.
    $this->submitForm([], 'change_constants');
    $this->submitForm(['constants[origin]' => ''], 'Continue');
    $this->submitForm([], 'Save');
    $this->drupalGet('admin/config/services/ash-salesforce/feedback');
    $this->submitForm([], 'change_constants');
    $this->assertSession()->fieldValueEquals('constants[origin]', '');
  }

}
