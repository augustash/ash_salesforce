<?php

namespace Drupal\Tests\ash_salesforce\Kernel;

use Drupal\ash_salesforce\Entity\Mapping;
use Drupal\ash_salesforce\Entity\SalesforceForm;
use Drupal\contact\Entity\ContactForm;
use Drupal\contact\Entity\Message;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the hand-off from a saved submission to Salesforce.
 *
 * Covers the seam we own: which submissions are sent, what's posted, and that
 * an outage leaves a submission queued rather than lost. Salesforce is a
 * Guzzle mock; what Web-to-Case does with a record is the client's to test.
 *
 * @group aai
 * @group ash_salesforce
 */
#[Group('aai')]
#[Group('ash_salesforce')]
class DispatchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path',
    'path_alias',
    'field',
    'filter',
    'contact',
    'webform',
    'ash_salesforce',
  ];

  /**
   * Queued responses for the mocked Salesforce endpoint.
   */
  protected MockHandler $responses;

  /**
   * Requests the mocked endpoint received.
   */
  protected array $sent = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('contact_message');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'contact', 'webform']);
    $this->installEntitySchema('webform_submission');

    SalesforceForm::create([
      'id' => 'uat',
      'label' => 'UAT',
      'html' => file_get_contents(__DIR__ . '/../../fixtures/web-to-case.html'),
    ])->save();

    // Webform ships its own 'contact' form, so ours is 'inquiry'.
    Webform::create([
      'id' => 'inquiry',
      'title' => 'Inquiry',
      'elements' => <<<'YAML'
        first_name:
          '#type': textfield
          '#title': 'First Name'
        last_name:
          '#type': textfield
          '#title': 'Last Name'
        email:
          '#type': email
          '#title': Email
        topics:
          '#type': checkboxes
          '#title': Topics
          '#options':
            parking: Parking
            lost: 'Lost and found'
        message:
          '#type': textarea
          '#title': Message
        YAML,
    ])->save();

    Mapping::create([
      'id' => 'contact',
      'label' => 'Contact',
      'source' => 'webform:inquiry',
      'salesforce_form' => 'uat',
      'fields' => [
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'email' => 'email',
        'topics' => 'subject',
        'message' => Mapping::NONE,
      ],
      'constants' => ['recordType' => 'NSC Cases', 'origin' => 'MAC Website'],
    ])->save();

    $this->responses = new MockHandler();
    $stack = HandlerStack::create($this->responses);
    $stack->push(Middleware::history($this->sent));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * A webform submission reaches Salesforce at the end of the request.
   */
  public function testWebformSubmissionIsSent(): void {
    $this->responses->append(new Response(200));
    $this->submitWebform(['first_name' => str_repeat('a', 50)]);
    $this->assertSame(1, $this->queue()->numberOfItems());

    $this->terminate();

    $this->assertCount(1, $this->sent);
    $request = $this->sent[0]['request'];
    $this->assertSame('mspmac--uat.sandbox.my.salesforce.com', $request->getUri()->getHost());
    parse_str((string) $request->getBody(), $body);
    $this->assertSame([
      'orgid' => '00DVE000009EVUz',
      // Capped at the Case field's 40 characters, posted by its org ID.
      '00NVE00000BWFSD' => str_repeat('a', 40),
      '00NVE00000BWFaH' => 'Lovelace',
      'email' => 'ada@example.com',
      // A multi-value element is joined.
      'subject' => 'parking, lost',
      // The picklist constant is stored by label and posted as this org's ID.
      'recordType' => '012VE000007QZCb',
      'origin' => 'MAC Website',
    ], $body);
    $this->assertSame(0, $this->queue()->numberOfItems());
  }

  /**
   * Pointing the mapping at another org's form posts that org's IDs.
   *
   * This is the per-environment switch: settings.php overrides only
   * salesforce_form, and the mapping's keys carry across.
   */
  public function testAnotherOrgsFormUsesItsIds(): void {
    $html = strtr(file_get_contents(__DIR__ . '/../../fixtures/web-to-case.html'), [
      'mspmac--uat.sandbox.my' => 'mspmac.my',
      '00DVE000009EVUz' => '00D8V000000hsXw',
      '00NVE00000BWFSD' => '00N8V00000PRODF',
      '012VE000007QZCb' => '0128V000000PROD',
    ]);
    SalesforceForm::create(['id' => 'prod', 'label' => 'Prod', 'html' => $html])->save();
    Mapping::load('contact')->set('salesforce_form', 'prod')->save();
    $this->responses->append(new Response(200));

    $this->submitWebform();
    $this->terminate();

    parse_str((string) $this->sent[0]['request']->getBody(), $body);
    $this->assertSame('mspmac.my.salesforce.com', $this->sent[0]['request']->getUri()->getHost());
    $this->assertSame('00D8V000000hsXw', $body['orgid']);
    $this->assertSame('Ada', $body['00N8V00000PRODF']);
    $this->assertSame('0128V000000PROD', $body['recordType']);
  }

  /**
   * Drafts, unmapped forms and disabled mappings send nothing.
   */
  public function testOnlyFinishedMappedSubmissionsAreQueued(): void {
    $this->submitWebform([], TRUE);
    $this->assertSame(0, $this->queue()->numberOfItems());

    Webform::create(['id' => 'other', 'title' => 'Other'])->save();
    WebformSubmission::create(['webform_id' => 'other', 'data' => []])->save();
    $this->assertSame(0, $this->queue()->numberOfItems());

    Mapping::load('contact')->disable()->save();
    $this->submitWebform();
    $this->assertSame(0, $this->queue()->numberOfItems());
  }

  /**
   * A draft is sent once, on the save that completes it.
   */
  public function testCompletedDraftIsSent(): void {
    $submission = $this->submitWebform([], TRUE);
    $submission->set('in_draft', FALSE)->save();
    $this->assertSame(1, $this->queue()->numberOfItems());

    // Later edits to a finished submission aren't new to Salesforce.
    $submission->save();
    $this->assertSame(1, $this->queue()->numberOfItems());
  }

  /**
   * A contact form message is sent the same way.
   */
  public function testContactMessageIsSent(): void {
    ContactForm::create(['id' => 'feedback', 'label' => 'Feedback', 'recipients' => []])->save();
    $this->container->get('entity_display.repository')->getFormDisplay('contact_message', 'feedback')
      ->setComponent('subject', ['type' => 'string_textfield'])
      ->setComponent('message', ['type' => 'string_textarea'])
      ->save();
    Mapping::create([
      'id' => 'feedback',
      'label' => 'Feedback',
      'source' => 'contact:feedback',
      'salesforce_form' => 'uat',
      'fields' => ['name' => 'first_name', 'mail' => 'email', 'subject' => 'subject', 'message' => 'description'],
    ])->save();
    $this->responses->append(new Response(200));

    // Through the form: without contact_storage nothing is saved, so the
    // form's submit is the only moment a message exists.
    // The mail handler loads the sender, here the anonymous user.
    User::create(['uid' => 0, 'name' => ''])->save();
    $message = Message::create(['contact_form' => 'feedback']);
    $form_object = $this->container->get('entity_type.manager')->getFormObject('contact_message', 'default')->setEntity($message);
    $form_state = (new FormState())->setValues([
      'name' => 'Ada',
      'mail' => 'ada@example.com',
      'subject' => [['value' => 'Lost and found']],
      'message' => [['value' => 'I left my umbrella at gate C4.']],
      'op' => 'Send message',
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->terminate();

    parse_str((string) $this->sent[0]['request']->getBody(), $body);
    $this->assertSame([
      'orgid' => '00DVE000009EVUz',
      '00NVE00000BWFSD' => 'Ada',
      'email' => 'ada@example.com',
      'subject' => 'Lost and found',
      'description' => 'I left my umbrella at gate C4.',
    ], $body);
  }

  /**
   * A Salesforce outage leaves the submission for cron instead of losing it.
   */
  public function testServerErrorKeepsSubmissionQueued(): void {
    $this->responses->append(new Response(503), new Response(200));
    $this->submitWebform();

    $this->terminate();
    $this->assertSame(1, $this->queue()->numberOfItems());

    $this->container->get('cron')->run();
    $this->assertCount(2, $this->sent);
    $this->assertSame(0, $this->queue()->numberOfItems());
  }

  /**
   * A 4xx would fail identically forever, so it isn't retried.
   */
  public function testClientErrorIsNotRetried(): void {
    $this->responses->append(new Response(404));
    $this->submitWebform();

    $this->terminate();

    $this->assertCount(1, $this->sent);
    $this->assertSame(0, $this->queue()->numberOfItems());
  }

  /**
   * Saves an inquiry webform submission.
   */
  protected function submitWebform(array $data = [], bool $draft = FALSE): WebformSubmission {
    $submission = WebformSubmission::create([
      'webform_id' => 'inquiry',
      'in_draft' => $draft,
      'data' => $data + [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'topics' => ['parking', 'lost'],
        'message' => 'Not sent.',
      ],
    ]);
    $submission->save();
    return $submission;
  }

  /**
   * Runs the end-of-request drain.
   */
  protected function terminate(): void {
    $this->container->get('ash_salesforce.queue_runner')->onTerminate();
  }

  /**
   * The Salesforce queue.
   */
  protected function queue() {
    return $this->container->get('queue')->get('ash_salesforce', TRUE);
  }

}
