<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Access;

use Drupal\Core\Session\CalculatedPermissionsItemInterface;
use Drupal\webprofiler\DataCollector\UserDataCollector;

/**
 * Wraps the calculated permissions item.
 */
class CalculatedPermissionsItemWrapper implements CalculatedPermissionsItemInterface {

  /**
   * CalculatedPermissionsItemWrapper constructor.
   *
   * @param \Drupal\Core\Session\CalculatedPermissionsItemInterface $calculatedPermissionsItem
   *   The calculated permissions item.
   * @param \Drupal\webprofiler\DataCollector\UserDataCollector $userDataCollector
   *   The user data collector.
   */
  public function __construct(
    private readonly CalculatedPermissionsItemInterface $calculatedPermissionsItem,
    private readonly UserDataCollector $userDataCollector,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getScope(): string {
    return $this->calculatedPermissionsItem->getScope();
  }

  /**
   * {@inheritdoc}
   */
  public function getIdentifier(): string|int {
    return $this->calculatedPermissionsItem->getIdentifier();
  }

  /**
   * {@inheritdoc}
   */
  public function getPermissions(): array {
    return $this->calculatedPermissionsItem->getPermissions();
  }

  /**
   * {@inheritdoc}
   */
  public function isAdmin(): bool {
    return $this->calculatedPermissionsItem->isAdmin();
  }

  /**
   * {@inheritdoc}
   */
  public function hasPermission(string $permission): bool {
    $has_permission = $this->calculatedPermissionsItem->hasPermission($permission);

    $this->userDataCollector->addPermission($permission, $this->calculatedPermissionsItem, $has_permission);

    return $has_permission;
  }

}
