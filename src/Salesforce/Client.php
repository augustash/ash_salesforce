<?php

namespace Drupal\ash_salesforce\Salesforce;

use Drupal\ash_salesforce\PermanentFailureException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

/**
 * Posts records to Web-to-Case and Web-to-Lead.
 *
 * Web-to-Case rather than the API: it needs no connected app or login, and
 * Salesforce runs the org's assignment and auto-response rules for it. The
 * cost is that it answers 200 even when it drops a record; the reason goes
 * only to the debug address on the client's side.
 */
class Client {

  public function __construct(
    protected ClientInterface $httpClient,
  ) {}

  /**
   * Posts one record.
   *
   * @throws \Drupal\ash_salesforce\PermanentFailureException
   *   On a 4xx: the same request would fail the same way.
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   On a network or server error, which is worth retrying.
   */
  public function send(string $action, array $payload): void {
    try {
      $this->httpClient->request('POST', $action, [
        'form_params' => $payload,
        'timeout' => 15,
        // It redirects to retURL when one is posted; we post none.
        'allow_redirects' => FALSE,
      ]);
    }
    catch (ClientException $e) {
      throw new PermanentFailureException($e->getMessage(), $e->getCode(), $e);
    }
  }

}
