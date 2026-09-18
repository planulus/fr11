<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Access;

use Drupal\Core\Session\AccessPolicyInterface;
use Drupal\Core\Session\AccessPolicyProcessorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\CalculatedPermissionsInterface;
use Drupal\webprofiler\DataCollector\UserDataCollector;

/**
 * Wraps the access policy processor.
 */
class AccessPolicyProcessorWrapper implements AccessPolicyProcessorInterface {

  /**
   * AccessPolicyProcessorWrapper constructor.
   *
   * @param \Drupal\Core\Session\AccessPolicyProcessorInterface $accessPolicyProcessor
   *   The access policy processor.
   * @param \Drupal\webprofiler\DataCollector\UserDataCollector $userDataCollector
   *   The user data collector.
   */
  public function __construct(
    private readonly AccessPolicyProcessorInterface $accessPolicyProcessor,
    private readonly UserDataCollector $userDataCollector,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function addAccessPolicy(AccessPolicyInterface $access_policy): void {
    $this->accessPolicyProcessor->addAccessPolicy($access_policy);
  }

  /**
   * {@inheritdoc}
   */
  public function processAccessPolicies(AccountInterface $account, string $scope = AccessPolicyInterface::SCOPE_DRUPAL): CalculatedPermissionsInterface {
    $calculated_permission = $this->accessPolicyProcessor->processAccessPolicies($account, $scope);

    return new CalculatedPermissionsWrapper($calculated_permission, $this->userDataCollector);
  }

}
