<?php

declare(strict_types=1);

namespace Drupal\webprofiler\State;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\State;
use Drupal\Core\State\StateInterface;
use Drupal\webprofiler\DataCollector\StateDataCollector;

/**
 * Wrap the state service to collect which keys are loaded.
 *
 * The class extends State because the decorated service is typed as the
 * concrete class, but every method delegates to the decorated service so that
 * only one \Drupal\Core\Cache\CacheCollector owns the 'state' cache entry. The
 * key value factory, cache backend and lock the parent constructor requires
 * are therefore never used to read or write anything.
 */
class StateWrapper extends State {

  /**
   * StateWrapper constructor.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $key_value_factory
   *   The key value store to use.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend to use.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend to use.
   * @param \Drupal\Core\State\StateInterface $state
   *   The original state service.
   * @param \Drupal\webprofiler\DataCollector\StateDataCollector $dataCollector
   *   The state data collector.
   */
  public function __construct(
    KeyValueFactoryInterface $key_value_factory,
    CacheBackendInterface $cache,
    LockBackendInterface $lock,
    private readonly StateInterface $state,
    private readonly StateDataCollector $dataCollector,
  ) {
    parent::__construct($key_value_factory, $cache, $lock);
  }

  /**
   * {@inheritdoc}
   */
  public function get($key, $default = NULL) {
    $this->dataCollector->addState($key);

    return $this->state->get($key, $default);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $keys) {
    foreach ($keys as $key) {
      $this->dataCollector->addState($key);
    }

    return $this->state->getMultiple($keys);
  }

  /**
   * {@inheritdoc}
   */
  public function set($key, $value) {
    $this->state->set($key, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function setMultiple(array $data) {
    $this->state->setMultiple($data);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($key) {
    $this->state->delete($key);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMultiple(array $keys) {
    $this->state->deleteMultiple($keys);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache() {
    $this->state->resetCache();
  }

  /**
   * {@inheritdoc}
   */
  public function getValuesSetDuringRequest(string $key): ?array {
    return $this->state->getValuesSetDuringRequest($key);
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    // The 'needs_destruction' tag on the core service definition is read before
    // the decoration is resolved, so the kernel destructs the 'state' service,
    // which is this wrapper. Without this, the keys the decorated service
    // collected while reading are never written to the cache entry, and every
    // request resolves every state key against the key value store.
    if ($this->state instanceof DestructableInterface) {
      $this->state->destruct();
    }
  }

}
