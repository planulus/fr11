<?php

declare(strict_types=1);

namespace Drupal\Tests\webprofiler\Unit;

use Drupal\Core\Access\AccessManager;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\Core\Config\Entity\ImportableEntityStorageInterface;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\Core\Template\ComponentNodeVisitor;
use Drupal\Core\Theme\ThemeNegotiator;
use Drupal\Tests\UnitTestCase;
use Drupal\webprofiler\Entity\ConfigEntityStorageDecorator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the places where WebProfiler duplicates Drupal core code.
 *
 * Some collectors cannot wrap a core method: they need to run instrumentation
 * in the middle of it, so the method body is copied into a subclass instead of
 * calling the parent. Those copies do not fail when core changes, they silently
 * keep running the old logic.
 *
 * Each case below records a hash of the core method the copy was taken from. A
 * failure means core changed and the copy has to be reviewed, not that the
 * hash is wrong.
 *
 * @group webprofiler
 */
#[Group('webprofiler')]
class CoreCopyDriftTest extends UnitTestCase {

  /**
   * The core methods whose body is duplicated inside WebProfiler.
   *
   * Keyed by test case name, each value holds the core class, the core method,
   * the hash of its normalized source, and the copy that shadows it.
   *
   * @return array<string, array{0: class-string, 1: string, 2: string, 3: string}>
   *   The copied core methods.
   */
  public static function copiedMethodProvider(): array {
    return [
      'AccessManager::performCheck' => [
        AccessManager::class,
        'performCheck',
        '18ba2c6394c38e587a9e6209c2c2f05a791ad00870f604e82380aeeabe9a9157',
        'Drupal\webprofiler\Access\AccessManagerWrapper::performCheck()',
      ],
      'TranslationManager::doTranslate' => [
        TranslationManager::class,
        'doTranslate',
        'a2d056fb88619228fdc48fd7fa1e6f9ed47dbbc268eb0884d0320c84b59a0f04',
        'Drupal\webprofiler\StringTranslation\TranslationManagerWrapper::doTranslate()',
      ],
      'ThemeNegotiator::determineActiveTheme' => [
        ThemeNegotiator::class,
        'determineActiveTheme',
        '000ae4c6a6b44146192913e33abcd7d36f2d951bea3396b1d983d78710f968f0',
        'Drupal\webprofiler\Theme\ThemeNegotiatorWrapper::determineActiveTheme()',
      ],
      'ComponentNodeVisitor::getComponent' => [
        ComponentNodeVisitor::class,
        'getComponent',
        'ba808415a2743a4c68a6ec720846ae7934d761479bfb0beb1c11172f1ff6d87e',
        'Drupal\webprofiler\Twig\ComponentNodeVisitor::getComponent()',
      ],
    ];
  }

  /**
   * Tests that the core methods WebProfiler copies have not changed.
   *
   * @param class-string $class
   *   The core class holding the original method.
   * @param string $method
   *   The original method name.
   * @param string $expected_hash
   *   The hash of the method source as of the last review of the copy.
   * @param string $copy
   *   The WebProfiler method that duplicates the original.
   */
  #[DataProvider('copiedMethodProvider')]
  public function testCopiedCoreMethodIsUnchanged(string $class, string $method, string $expected_hash, string $copy): void {
    $this->assertSame(
      $expected_hash,
      $this->hashMethodSource($class, $method),
      \sprintf(
        '%s::%s() changed in Drupal core. Review %s, port the change, then update the hash in %s::copiedMethodProvider().',
        $class,
        $method,
        $copy,
        self::class,
      ),
    );
  }

  /**
   * Tests that the hand written config entity storage decorator is complete.
   *
   * ConfigEntityStorageDecorator declares one delegating method per method of
   * the interfaces it implements. Core adding a method leaves a decorator that
   * no longer satisfies the interface, and core removing one leaves a method
   * that raises a fatal error when called, so both directions are checked.
   */
  public function testConfigEntityStorageDecoratorMatchesCoreInterfaces(): void {
    $interfaces = [
      ConfigEntityStorageInterface::class,
      EntityStorageInterface::class,
      ImportableEntityStorageInterface::class,
      EntityHandlerInterface::class,
    ];

    $expected = [];
    foreach ($interfaces as $interface) {
      foreach ((new \ReflectionClass($interface))->getMethods() as $method) {
        $expected[$method->getName()] = TRUE;
      }
    }
    $expected = \array_keys($expected);

    $declared = [];
    $reflection = new \ReflectionClass(ConfigEntityStorageDecorator::class);
    foreach ($reflection->getMethods() as $method) {
      if ($method->getDeclaringClass()->getName() !== ConfigEntityStorageDecorator::class) {
        continue;
      }
      if ($method->isConstructor()) {
        continue;
      }
      $declared[] = $method->getName();
    }

    \sort($expected);
    \sort($declared);

    $this->assertSame(
      $expected,
      $declared,
      'ConfigEntityStorageDecorator no longer mirrors the core storage interfaces. Add the delegating methods core gained, and remove the ones core dropped.',
    );
  }

  /**
   * The decorators that repeat the argument list of a core service.
   *
   * @return array<string, array{0: string, 1: string}>
   *   The core service ID and the ID of the decorator repeating its arguments.
   */
  public static function copiedServiceProvider(): array {
    return [
      'state' => ['state', 'webprofiler.debug.state'],
      'plugin.manager.mail' => ['plugin.manager.mail', 'webprofiler.debug.mail_manager'],
      'entity_type.manager' => ['entity_type.manager', 'webprofiler.debug.entity_type.manager'],
    ];
  }

  /**
   * Tests that decorators still pass the arguments core services expect.
   *
   * These decorators subclass the decorated service, so they have to construct
   * the parent as well as hold the inner service, which means repeating the
   * core argument list verbatim. Core adding, removing or reordering an
   * argument breaks the decorator at container build time.
   *
   * @param string $core_id
   *   The core service ID whose arguments are repeated.
   * @param string $decorator_id
   *   The WebProfiler service ID repeating them.
   */
  #[DataProvider('copiedServiceProvider')]
  public function testDecoratorRepeatsCoreServiceArguments(string $core_id, string $decorator_id): void {
    // Core declares services with tags such as !tagged_iterator.
    $core = Yaml::parseFile($this->root . '/core/core.services.yml', Yaml::PARSE_CUSTOM_TAGS)['services'][$core_id];
    $decorator = Yaml::parseFile(\dirname(__DIR__, 3) . '/webprofiler.services.yml')['services'][$decorator_id];

    $this->assertSame($core_id, $decorator['decorates'], \sprintf('%s no longer decorates %s.', $decorator_id, $core_id));

    $core_arguments = $core['arguments'];
    foreach ($core_arguments as $argument) {
      $this->assertIsString(
        $argument,
        \sprintf('An argument of the core %s service is no longer a plain service reference, so %s needs to be reviewed by hand.', $core_id, $decorator_id),
      );
    }

    $repeated = \array_values(\array_filter(
      $decorator['arguments'],
      static fn ($argument): bool => \in_array($argument, $core_arguments, TRUE),
    ));
    $this->assertSame(
      $core_arguments,
      $repeated,
      \sprintf('The arguments of the core %s service changed. Update the arguments of %s in webprofiler.services.yml to match.', $core_id, $decorator_id),
    );

    $extra = \array_values(\array_diff($decorator['arguments'], $core_arguments));
    foreach ($extra as $argument) {
      $this->assertTrue(
        $argument === '@' . $decorator_id . '.inner' || \str_starts_with($argument, '@webprofiler.'),
        \sprintf('%s passes the unexpected argument %s.', $decorator_id, $argument),
      );
    }
    $this->assertContains(
      '@' . $decorator_id . '.inner',
      $extra,
      \sprintf('%s no longer receives the decorated service.', $decorator_id),
    );
  }

  /**
   * Hashes the source of a method, ignoring formatting and comments.
   *
   * @param class-string $class
   *   The class holding the method.
   * @param string $method
   *   The method name.
   *
   * @return string
   *   The hash of the normalized method source.
   */
  private function hashMethodSource(string $class, string $method): string {
    $reflection = new \ReflectionMethod($class, $method);
    $lines = \file($reflection->getFileName(), \FILE_IGNORE_NEW_LINES);
    $source = \array_slice(
      $lines,
      $reflection->getStartLine() - 1,
      $reflection->getEndLine() - $reflection->getStartLine() + 1,
    );

    $normalized = [];
    foreach ($source as $line) {
      $line = \trim($line);
      if ($line === '') {
        continue;
      }
      if (\str_starts_with($line, '//') || \str_starts_with($line, '*') || \str_starts_with($line, '/*')) {
        continue;
      }
      $normalized[] = $line;
    }

    return \hash('sha256', \implode("\n", $normalized));
  }

}
