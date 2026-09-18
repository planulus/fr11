<?php

namespace Drupal\Tests\webprofiler\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the AccessManagerWrapper scalar type conversion.
 *
 * @group webprofiler
 */
#[Group('webprofiler')]
#[RunTestsInSeparateProcesses]
class AccessManagerWrapperTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'tracer',
    'webprofiler',
  ];

  /**
   * The access manager.
   *
   * @var \Drupal\Core\Access\AccessManagerInterface
   */
  protected AccessManagerInterface $accessManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->accessManager = $this->container->get('access_manager');
  }

  /**
   * Tests that scalar arguments are properly converted.
   */
  public function testScalarArgumentConversion(): void {
    // Verify the access manager is our wrapper.
    self::assertInstanceOf(
      'Drupal\webprofiler\Access\AccessManagerWrapper',
      $this->accessManager
    );

    // Use reflection to test the convertScalarArguments method.
    $reflection = new \ReflectionClass($this->accessManager);
    $method = $reflection->getMethod('convertScalarArguments');

    // Create a mock callable with typed parameters.
    $callable = [new MockAccessCheck(), 'access'];

    // Test with string arguments that should be converted to int.
    $arguments = [
      '123',
      '456',
      'test_hash',
      $this->createMock(AccountInterface::class),
    ];
    $converted = $method->invoke($this->accessManager, $callable, $arguments);

    // First argument should be converted to int.
    self::assertIsInt($converted[0]);
    self::assertSame(123, $converted[0]);

    // Second argument should be converted to int.
    self::assertIsInt($converted[1]);
    self::assertSame(456, $converted[1]);

    // Third argument should remain a string.
    self::assertIsString($converted[2]);
    self::assertSame('test_hash', $converted[2]);

    // Fourth argument should remain an object.
    self::assertInstanceOf(AccountInterface::class, $converted[3]);
  }

  /**
   * Tests that non-scalar arguments are not affected.
   */
  public function testNonScalarArgumentsPreserved(): void {
    $reflection = new \ReflectionClass($this->accessManager);
    $method = $reflection->getMethod('convertScalarArguments');

    $callable = function (AccountInterface $account, array $options = []) {
      return TRUE;
    };

    $account = $this->createMock(AccountInterface::class);
    $options = ['key' => 'value'];
    $arguments = [$account, $options];

    $converted = $method->invoke($this->accessManager, $callable, $arguments);

    // Both arguments should remain unchanged.
    self::assertSame($account, $converted[0]);
    self::assertSame($options, $converted[1]);
  }

  /**
   * Tests conversion of different scalar types.
   */
  public function testDifferentScalarTypes(): void {
    $reflection = new \ReflectionClass($this->accessManager);
    $method = $reflection->getMethod('convertScalarArguments');

    // Test float conversion.
    $callable = function (float $value) {
      return $value;
    };
    $converted = $method->invoke($this->accessManager, $callable, ['123.45']);
    self::assertIsFloat($converted[0]);
    self::assertSame(123.45, $converted[0]);

    // Test bool conversion.
    $callable = function (bool $flag) {
      return $flag;
    };
    $converted = $method->invoke($this->accessManager, $callable, ['1']);
    self::assertIsBool($converted[0]);
    self::assertTrue($converted[0]);
  }

}

/**
 * Mock access check class for testing.
 */
class MockAccessCheck {

  /**
   * Mock access check method with typed parameters.
   *
   * @param int $uid
   *   The user ID.
   * @param int $timestamp
   *   The timestamp.
   * @param string $hash
   *   The hash.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(
    int $uid,
    int $timestamp,
    string $hash,
    AccountInterface $account,
  ): AccessResultInterface {
    return AccessResult::neutral();
  }

}
