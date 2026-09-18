<?php

declare(strict_types=1);

namespace Drupal\Tests\webprofiler\Kernel;

use Drupal\Component\DependencyInjection\Dumper\OptimizedPhpArrayDumper;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webprofiler\Config\ConfigFactoryWrapper;
use Drupal\webprofiler\State\StateWrapper;

/**
 * Tests that service closure arguments survive WebProfiler processing.
 *
 * WebProfiler alters the container in several ways: it swaps the class of
 * core services (config.factory, access_manager, ...), decorates others
 * (state, entity_type.manager, ...) and registers its own compiler passes.
 * Services defined with a "!service_closure" argument must still receive a
 * \Closure that lazily resolves to the (wrapped or decorated) service.
 *
 * @group webprofiler
 * @see https://www.drupal.org/project/webprofiler/issues/3579658
 */
class ServiceClosureTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'tracer',
    'webprofiler',
    'webprofiler_service_closure_test',
  ];

  /**
   * Tests that service closure arguments resolve to closures at runtime.
   */
  public function testServiceClosureArgumentsResolveToClosures(): void {
    $consumer = $this->container->get('webprofiler_service_closure_test.consumer');

    // The closure over config.factory must resolve to the WebProfiler
    // wrapper, proving that the class swap done in
    // WebprofilerServiceProvider::alter() is visible through the closure.
    self::assertInstanceOf(ConfigFactoryWrapper::class, ($consumer->configFactoryClosure)());

    // The closure over state must resolve to the WebProfiler decorator.
    self::assertInstanceOf(StateWrapper::class, ($consumer->stateClosure)());
  }

  /**
   * Tests a http_middleware service with a service closure argument.
   *
   * This mirrors the scenario from the issue report: the StackedKernelPass
   * prepends the inner kernel reference to the middleware arguments, and the
   * service closure argument must keep its type.
   */
  public function testServiceClosureMiddleware(): void {
    if (!$this->container->hasAlias('webprofiler_service_closure_test.middleware.http_middleware_inner')) {
      $this->markTestSkipped('The stacked kernel is not active in this environment.');
    }

    $middleware = $this->container->get('webprofiler_service_closure_test.middleware');

    self::assertInstanceOf(\Closure::class, $middleware->configFactoryClosure);
    self::assertInstanceOf(ConfigFactoryWrapper::class, ($middleware->configFactoryClosure)());
  }

  /**
   * Tests that service closure arguments survive the container dumping.
   *
   * Kernel tests use the container builder directly, so this test runs the
   * dumper used by the DrupalKernel on production sites and checks that the
   * dumped definition keeps the service_closure marker instead of a plain
   * service reference.
   */
  public function testServiceClosureArgumentsSurviveContainerDumping(): void {
    /** @var \Drupal\Core\DependencyInjection\ContainerBuilder $builder */
    $builder = $this->container;

    $dumper = new OptimizedPhpArrayDumper($builder);
    $definition = $dumper->getArray();

    $service = $definition['services']['webprofiler_service_closure_test.consumer'];
    $service = \is_string($service) ? \unserialize($service) : $service;

    $arguments = $service['arguments']->value;

    self::assertSame('service_closure', $arguments[0]->type);
    self::assertSame('config.factory', $arguments[0]->id);

    // The state service is decorated by WebProfiler and the compiler rewrites
    // the reference through aliases, so only the marker type is asserted; the
    // referenced id differs between core versions.
    self::assertSame('service_closure', $arguments[1]->type);
  }

}
