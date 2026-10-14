<?php

namespace Drupal\Tests\ash_salesforce\Unit;

use Drupal\ash_salesforce\Salesforce\FormParser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests reading Salesforce-generated forms.
 *
 * The Case fixture is the Web-to-Case form MSP's admin generated, unedited,
 * so the generator's quirks (unquoted attributes, bare-text labels, a
 * textarea with no id) are the real ones.
 *
 * @group aai
 * @group ash_salesforce
 */
#[Group('aai')]
#[Group('ash_salesforce')]
class FormParserTest extends UnitTestCase {

  /**
   * A Web-to-Case form yields its endpoint, org and fields.
   */
  public function testWebToCase(): void {
    $form = (new FormParser())->parse(file_get_contents(__DIR__ . '/../../fixtures/web-to-case.html'));

    $this->assertSame('case', $form->kind);
    $this->assertSame('https://mspmac--uat.sandbox.my.salesforce.com/servlet/servlet.WebToCase?encoding=UTF-8&orgId=00DVE000009EVUz', $form->action);
    // The org ID rides along; retURL and the commented-out debug inputs don't.
    $this->assertSame(['orgid' => '00DVE000009EVUz'], $form->params);

    $this->assertSame(['first_name', 'last_name', 'email', 'phone', 'subject', 'description', 'recordType'], array_keys($form->fields));
    // Custom fields: labelled by bare text, keyed by label, posted by org ID.
    $this->assertSame('00NVE00000BWFSD', $form->fields['first_name']->name);
    $this->assertSame('First Name', $form->fields['first_name']->label);
    $this->assertSame(40, $form->fields['first_name']->maxlength);
    // The textarea has no id; its label is matched by name.
    $this->assertSame('Description', $form->fields['description']->label);
    $this->assertNull($form->fields['description']->maxlength);
    $this->assertSame(['012VE000007QZCb' => 'NSC Cases', '012VE000007QZCc' => 'Social'], $form->fields['recordType']->options);

    // Standard fields the form left out are still offered for fixed values.
    $this->assertSame(['origin', 'type', 'reason', 'priority'], array_keys($form->standard));
  }

  /**
   * A Web-to-Lead form keeps its own org parameter and standard fields.
   */
  public function testWebToLead(): void {
    $form = (new FormParser())->parse(<<<'HTML'
      <form action="https://webto.salesforce.com/servlet/servlet.WebToLead?encoding=UTF-8&orgId=00D000000000001" method="POST">
      <input type=hidden name="oid" value="00D000000000001">
      <label for="first_name">First Name</label><input  id="first_name" maxlength="40" name="first_name" size="20" type="text" /><br>
      <label for="company">Company</label><input  id="company" maxlength="40" name="company" size="20" type="text" /><br>
      <label for="lead_source">Lead Source</label><select  id="lead_source" name="lead_source"><option value="">--None--</option><option value="Web">Web</option></select><br>
      Hangar Size:<select  id="00N000000000AAA" name="00N000000000AAA" title="Hangar Size"><option value="">--None--</option><option value="Small">Small</option></select><br>
      <input type="submit" name="submit">
      </form>
      HTML);

    $this->assertSame('lead', $form->kind);
    $this->assertSame(['oid' => '00D000000000001'], $form->params);
    $this->assertSame(['first_name', 'company', 'lead_source', 'hangar_size'], array_keys($form->fields));
    // Lead source is in the form, so it isn't offered a second time.
    $this->assertSame([], $form->standard);
  }

  /**
   * Two custom fields with one label keep separate keys.
   */
  public function testDuplicateLabels(): void {
    $form = (new FormParser())->parse(<<<'HTML'
      <form action="https://example.my.salesforce.com/servlet/servlet.WebToCase?encoding=UTF-8&orgId=X">
      Notes:<input name="00N000000000AAA" type="text" /><br>
      Notes:<input name="00N000000000BBB" type="text" /><br>
      </form>
      HTML);

    $this->assertSame(['notes', 'notes_00N000000000BBB'], array_keys($form->fields));
  }

  /**
   * Anything but a Salesforce Web-to form is refused.
   */
  public function testRejectsOtherForms(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new FormParser())->parse('<form action="https://example.com/servlet/servlet.WebToCase"><input name="email"></form>');
  }

}
