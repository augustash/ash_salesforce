<?php

namespace Drupal\ash_salesforce\EventSubscriber;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Drains the Salesforce queue after the response is sent.
 *
 * Queueing first means a submission survives a Salesforce outage; draining on
 * terminate means it normally reaches Salesforce seconds later rather than at
 * the next cron, and the visitor never waits on Salesforce.
 */
class QueueRunner implements EventSubscriberInterface {

  /**
   * Cap per request, so a backlog doesn't hold a PHP worker.
   */
  protected const BATCH = 5;

  /**
   * Whether this request queued a submission.
   */
  protected bool $pending = FALSE;

  public function __construct(
    protected QueueFactory $queueFactory,
    protected QueueWorkerManagerInterface $workerManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

  /**
   * Marks the queue as worth draining at the end of this request.
   */
  public function flag(): void {
    $this->pending = TRUE;
  }

  /**
   * Sends queued submissions, stopping at the first failure.
   */
  public function onTerminate(): void {
    if (!$this->pending) {
      return;
    }
    $this->pending = FALSE;
    $queue = $this->queueFactory->get('ash_salesforce', TRUE);
    $worker = $this->workerManager->createInstance('ash_salesforce');

    for ($i = 0; $i < static::BATCH && ($item = $queue->claimItem()); $i++) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      catch (\Throwable $e) {
        // Salesforce is likely down; leave the rest for cron.
        $queue->releaseItem($item);
        $this->logger->warning('Salesforce send failed, queued for cron: @error', ['@error' => $e->getMessage()]);
        return;
      }
    }
  }

}
