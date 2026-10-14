<?php

namespace Drupal\ash_salesforce\Plugin\QueueWorker;

use Drupal\ash_salesforce\PermanentFailureException;
use Drupal\ash_salesforce\Salesforce\Client;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends queued submissions to Salesforce.
 *
 * Normally drained at the end of the submitting request; cron picks up
 * whatever failed there.
 */
#[QueueWorker(
  id: 'ash_salesforce',
  title: new TranslatableMarkup('Salesforce form submissions'),
  cron: ['time' => 30],
)]
class SalesforceWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Client $client,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ash_salesforce.client'),
      $container->get('logger.channel.ash_salesforce'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    try {
      $this->client->send($data['action'], $data['payload']);
    }
    catch (PermanentFailureException $e) {
      // Dropped from the queue; the submission itself is still in Drupal.
      $this->logger->error('Salesforce rejected @submission for mapping @mapping: @error', [
        '@submission' => $data['submission'],
        '@mapping' => $data['mapping'],
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
