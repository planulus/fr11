<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Access;

use Drupal\Core\Session\AccessPolicyInterface;
use Drupal\Core\Session\CalculatedPermissionsInterface;
use Drupal\Core\Session\CalculatedPermissionsItemInterface;
use Drupal\webprofiler\DataCollector\UserDataCollector;

/**
 * Wraps the calculated permissions.
 */
class CalculatedPermissionsWrapper implements CalculatedPermissionsInterface {

  /**
   * CalculatedPermissionsWrapper constructor.
   *
   * @param \Drupal\Core\Session\CalculatedPermissionsInterface $calculatedPermissions
   *   The calculated permissions.
   * @param \Drupal\webprofiler\DataCollector\UserDataCollector $userDataCollector
   *   The user data collector.
   */
  public function __construct(
    private readonly CalculatedPermissionsInterface $calculatedPermissions,
    private readonly UserDataCollector $userDataCollector,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return $this->calculatedPermissions->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->calculatedPermissions->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->calculatedPermissions->getCacheMaxAge();
  }

  /**
   * {@inheritdoc}
   */
  public function getItem(string $scope = AccessPolicyInterface::SCOPE_DRUPAL, int|string $identifier = AccessPolicyInterface::SCOPE_DRUPAL): CalculatedPermissionsItemInterface|false {
    $item = $this->calculatedPermissions->getItem($scope, $identifier);

    return $item ? new CalculatedPermissionsItemWrapper($item, $this->userDataCollector) : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getItems(): array {
    $items = $this->calculatedPermissions->getItems();

    return \array_map(function ($item) {
      return new CalculatedPermissionsItemWrapper($item, $this->userDataCollector);
    }, $items);
  }

  /**
   * {@inheritdoc}
   */
  public function getScopes(): array {
    return $this->calculatedPermissions->getScopes();
  }

  /**
   * {@inheritdoc}
   */
  public function getItemsByScope(string $scope = AccessPolicyInterface::SCOPE_DRUPAL): array {
    return $this->calculatedPermissions->getItemsByScope($scope);
  }

}
