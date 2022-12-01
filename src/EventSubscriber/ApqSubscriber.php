<?php

namespace Drupal\graphql\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\graphql\Event\OperationEvent;
use GraphQL\Error\Error;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
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
   * Constructs a ApqSubscriber object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache to store persisted queries.
   */
  public function __construct(CacheBackendInterface $cache) {
    $this->cache = $cache;
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

      Cache::invalidateTags([$this->getCacheTag($queryHash)]);
      $this->cache->set($queryHash, $query);
    }
  }

  /**
   * Add cache-tag to PersistedQueryNotFound responses.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The event to process.
   */
  public function onRespond(ResponseEvent $event) {
    if (!$this->isPersistedQueryNotFoundRespond($event->getResponse())) {
      return;
    }

    $extensions = Json::decode($event->getRequest()->query->get('extensions'));
    $queryHash = $extensions['persistedQuery']['sha256Hash'] ?? '';

    if ($queryHash !== '') {
      $response = $event->getResponse();
      if ($response instanceof CacheableResponseInterface) {
        $response->getCacheableMetadata()->addCacheTags([$this->getCacheTag($queryHash)]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      OperationEvent::GRAPHQL_OPERATION_BEFORE => 'onBeforeOperation',
      KernelEvents::RESPONSE => 'onRespond',
    ];
  }

  /**
   * Get query's hash cache-tag.
   *
   * @param string $hash
   *   Hash from GraphQL Query.
   *
   * @return string
   *   Cache tag form query's hash.
   */
  protected function getCacheTag(string $hash): string {
    return 'apq:' . $hash;
  }

  /**
   * Test if response is PersistedQueryNotFound error.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response to test.
   *
   * @return bool
   *   True if response is PersistedQueryNotFound message.
   */
  protected function isPersistedQueryNotFoundRespond(Response $response): bool {
    $body = Json::decode($response->getContent());
    if (empty($body['errors'])) {
      return FALSE;
    }
    foreach ($body['errors'] as $error) {
      if (isset($error['message']) && $error['message'] === 'PersistedQueryNotFound') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
