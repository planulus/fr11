<?php

declare(strict_types=1);

namespace Drupal\Tests\tracer\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\tracer\EventDispatcher\TraceableEventDispatcher;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the traceable event dispatcher.
 *
 * @group tracer
 */
class TraceableEventDispatcherTest extends KernelTestBase {

  /**
   * The modules to enable.
   *
   * @var array
   */
  protected static $modules = ['tracer'];

  /**
   * Tests that the dispatcher decorates the event_dispatcher service.
   */
  public function testEventDispatcherIsDecorated(): void {
    self::assertInstanceOf(TraceableEventDispatcher::class, $this->container->get('event_dispatcher'));
  }

  /**
   * Tests that the dispatcher can be serialized.
   *
   * Serialization has to succeed for any object graph reaching the dispatcher
   * to be cacheable, for instance the element info cached during a site install
   * from existing configuration.
   */
  public function testDispatcherIsSerializable(): void {
    /** @var \Drupal\tracer\EventDispatcher\TraceableEventDispatcher $dispatcher */
    $dispatcher = $this->container->get('event_dispatcher');

    $restored = \unserialize(\serialize($dispatcher));

    self::assertInstanceOf(TraceableEventDispatcher::class, $restored);
    self::assertSame($dispatcher->getCalledListeners(), $restored->getCalledListeners());
    self::assertSame($dispatcher->getNotCalledListeners(), $restored->getNotCalledListeners());

    // The decorated dispatcher is excluded from serialization and restored on
    // wakeup, so delegated calls have to keep working.
    self::assertTrue($restored->hasListeners(KernelEvents::REQUEST));
  }

  /**
   * Tests that no callable is kept in the collected listener data.
   *
   * A callable would make the dispatcher unserializable, and listeners
   * registered from the container are Closures.
   */
  public function testCollectedListenersHoldNoCallable(): void {
    /** @var \Drupal\tracer\EventDispatcher\TraceableEventDispatcher $dispatcher */
    $dispatcher = $this->container->get('event_dispatcher');

    $listeners = $this->flatten($dispatcher->getNotCalledListeners());
    self::assertNotEmpty($listeners);

    $services = 0;
    foreach ($listeners as $listener) {
      self::assertArrayNotHasKey('callable', $listener);

      if (isset($listener['service'])) {
        $services++;
        self::assertIsString($listener['service'][0]);
        self::assertIsString($listener['service'][1]);
        continue;
      }

      self::assertIsString($listener['class']);
      self::assertIsString($listener['method']);
    }

    // Listeners registered from the container are described by service ID.
    self::assertGreaterThan(0, $services);
  }

  /**
   * Tests that a dispatched listener stops being reported as not called.
   */
  public function testCalledListenerIsRemovedFromNotCalled(): void {
    /** @var \Drupal\tracer\EventDispatcher\TraceableEventDispatcher $dispatcher */
    $dispatcher = $this->container->get('event_dispatcher');

    $event_name = 'tracer_test_event';
    $listener = [$this->container->get('module_handler'), 'getModuleList'];
    $dispatcher->addListener($event_name, $listener);

    self::assertCount(1, $dispatcher->getNotCalledListeners()[$event_name][0]);

    $dispatcher->dispatch(new \stdClass(), $event_name);

    self::assertCount(1, $dispatcher->getCalledListeners()[$event_name][0]);
    self::assertEmpty($dispatcher->getNotCalledListeners()[$event_name][0]);
  }

  /**
   * Flattens collected listeners into a single list.
   *
   * @param array $collected
   *   Listeners keyed by event name and then by priority.
   *
   * @return array
   *   The listener descriptions.
   */
  private function flatten(array $collected): array {
    $listeners = [];
    foreach ($collected as $priorities) {
      foreach ($priorities as $priority_listeners) {
        foreach ($priority_listeners as $listener) {
          $listeners[] = $listener;
        }
      }
    }

    return $listeners;
  }

}
