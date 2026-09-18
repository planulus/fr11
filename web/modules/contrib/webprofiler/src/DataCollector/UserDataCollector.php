<?php

declare(strict_types=1);

namespace Drupal\webprofiler\DataCollector;

use Drupal\Core\Authentication\AuthenticationCollectorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\CalculatedPermissionsItemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collects users data.
 */
class UserDataCollector extends DataCollector implements HasPanelInterface {

  use StringTranslationTrait;
  use PanelTrait;

  /**
   * UserDataCollector constructor.
   *
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityManager
   *   The entity manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Authentication\AuthenticationCollectorInterface $providerCollector
   *   The authentication collector.
   */
  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AuthenticationCollectorInterface $providerCollector,
  ) {
    $this->data['permissions'] = [];
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'user';
  }

  /**
   * Reset the collected data.
   */
  public function reset(): void {
    $this->data = [];
  }

  /**
   * {@inheritdoc}
   */
  public function collect(Request $request, Response $response, ?\Throwable $exception = NULL): void {
    $this->data['name'] = $this->currentUser->getDisplayName();
    $this->data['authenticated'] = $this->currentUser->isAuthenticated();

    $this->data['roles'] = [];
    $storage = $this->entityManager->getStorage('user_role');
    foreach ($this->currentUser->getRoles() as $role) {
      $entity = $storage->load($role);
      if ($entity != NULL) {
        $this->data['roles'][] = $entity->label();
      }
    }

    foreach ($this->providerCollector->getSortedProviders() as $provider_id => $provider) {
      if ($provider->applies($request)) {
        $this->data['provider'] = $provider_id;
      }
    }

    $this->data['anonymous'] = $this->configFactory->get('user.settings')
      ->get('anonymous');
  }

  /**
   * Return the user name.
   *
   * @return string
   *   The user name.
   */
  public function getUserName(): string {
    return $this->data['name'];
  }

  /**
   * Return if the user is authenticated.
   *
   * @return bool
   *   TRUE if the user is authenticated.
   */
  public function getAuthenticated(): bool {
    return $this->data['authenticated'];
  }

  /**
   * Return the user roles.
   *
   * @return array
   *   The user roles.
   */
  public function getRoles(): array {
    return $this->data['roles'];
  }

  /**
   * Return the user provider.
   *
   * @return string
   *   The user provider.
   */
  public function getProvider(): string {
    return $this->data['provider'];
  }

  /**
   * Return the anonymous user name.
   *
   * @return string
   *   The anonymous user name.
   */
  public function getAnonymous(): string {
    return $this->data['anonymous'];
  }

  /**
   * Add a permission.
   *
   * @param string $permission
   *   The permission.
   * @param \Drupal\Core\Session\CalculatedPermissionsItemInterface $item
   *   The calculated permissions item.
   * @param bool $has_permission
   *   TRUE if the user has the permission.
   */
  public function addPermission(
    string $permission,
    CalculatedPermissionsItemInterface $item,
    bool $has_permission,
  ): void {
    $scope = $item->getScope();
    $identifier = $item->getIdentifier();
    $this->data['permissions'][$scope][$identifier][$permission] = [
      'item' => $item,
      'has_permission' => $has_permission,
    ];
  }

  /**
   * Return the permissions count.
   *
   * @return string
   *   The permissions count.
   */
  public function getPermissionsCount(): string {
    $count = 0;
    foreach ($this->data['permissions'] as $scopes) {
      foreach ($scopes as $permissions) {
        foreach ($permissions as $permission) {
          $count++;
        }
      }
    }

    return (string) $count;
  }

  /**
   * {@inheritdoc}
   */
  public function getPanel(): array {
    return [
      '#theme' => 'webprofiler_dashboard_tabs',
      '#tabs' => [
        [
          'label' => $this->t('User'),
          'content' => $this->renderUser($this->data),
        ],
        [
          'label' => $this->t('Checked permissions'),
          'content' => $this->renderPermissions($this->data['permissions']),
        ],
      ],
    ];
  }

  /**
   * Render user data.
   *
   * @param array $data
   *   Collected data.
   *
   * @return array
   *   The render array for the user data.
   */
  private function renderUser(array $data): array {
    return [
      '#theme' => 'webprofiler_dashboard_section',
      '#data' => [
        '#type' => 'table',
        '#header' => [$this->t('Name'), $this->t('Value')],
        '#rows' => [
          [$this->t('Name'), $data['name']],
          [$this->t('Authenticated'), $data['authenticated'] ? $this->t('Yes') : $this->t('No')],
          [$this->t('Roles'), \implode(', ', $data['roles'])],
          [$this->t('Provider'), $data['provider']],
          [$this->t('Anonymous name'), $data['anonymous']],

        ],
        '#attributes' => [
          'class' => [
            'webprofiler__table',
          ],
        ],
        '#sticky' => TRUE,
      ],
    ];
  }

  /**
   * Render a list of permissions.
   *
   * @param array $data
   *   A list of permissions.
   *
   * @return array
   *   The render array of the list of permissions.
   */
  private function renderPermissions(array $data): array {
    $rows = [];

    foreach ($data as $scope => $scope_data) {
      foreach ($scope_data as $identifier => $permissions) {

        \ksort($permissions);

        foreach ($permissions as $permission => $permission_data) {
          $rows[] = [
            $scope,
            $identifier,
            $permission,
            $permission_data['has_permission'] ? $this->t('Yes') : $this->t('No'),
            $permission_data['item']->isAdmin() ? $this->t('Yes') : $this->t('No'),
          ];
        }
      }
    }

    return [
      '#theme' => 'webprofiler_dashboard_section',
      '#data' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Scope'),
          $this->t('Identifier'),
          $this->t('Permission'),
          $this->t('Granted?'),
          $this->t('Is Admin?'),
        ],
        '#rows' => $rows,
        '#attributes' => [
          'class' => [
            'webprofiler__table',
          ],
        ],
        '#sticky' => TRUE,
      ],
    ];
  }

}
