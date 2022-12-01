<?php

namespace Drupal\graphql\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\graphql\Event\OperationEvent;
use GraphQL\Error\Error;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Save persisted queries to cache.
 */
class ApqSubscriber implements EventSubscriberInterface {

  /**
   * The cache to store persisted queries.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Page cache kill switch.
   *
   * @var \Drupal\Core\PageCache\ResponsePolicy\KillSwitch
   */
  private KillSwitch $cacheKillSwitch;

  /**
   * Constructs a ApqSubscriber object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache to store persisted queries.
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $cacheKillSwitch
   *   Page cache kill switch.
   */
  public function __construct(CacheBackendInterface $cache, KillSwitch $cacheKillSwitch) {
    $this->cache = $cache;
    $this->cacheKillSwitch = $cacheKillSwitch;
  }

  /**
   * Handle operation start events.
   *
   * @param \Drupal\graphql\Event\OperationEvent $event
   *   The kernel event object.
   *
   * @throws \GraphQL\Error\Error
   */
  public function onBeforeOperation(OperationEvent $event): void {
    if (!array_key_exists('automatic_persisted_query', $event->getContext()->getServer()->getPersistedQueryInstances() ?? [])) {
      return;
    }
    $query = $event->getContext()->getOperation()->query;
    $queryHash = $event->getContext()->getOperation()->extensions['persistedQuery']['sha256Hash'] ?? '';

    if (is_string($query) && is_string($queryHash) && $queryHash !== '') {
      $computedQueryHash = hash('sha256', $query);
      if ($queryHash !== $computedQueryHash) {
        throw new Error('Provided sha does not match query');
      }
      $event->getContext()->addCacheContexts(['url.query_args:variables']);
      $this->cache->set($queryHash, $query);
    }
  }

  /**
   * Add cache-tag to PersistedQueryNotFound responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public function onResponse(ResponseEvent $event) {
    $body = Json::decode($event->getResponse()->getContent());
    if (empty($body['errors'])) {
      return;
    }
    foreach ($body['errors'] as $error) {
      if (isset($error['message']) && $error['message'] === 'PersistedQueryNotFound') {
        $this->cacheKillSwitch->trigger();
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      OperationEvent::GRAPHQL_OPERATION_BEFORE => 'onBeforeOperation',
      KernelEvents::RESPONSE => ['onResponse', 101],
    ];
  }

}
