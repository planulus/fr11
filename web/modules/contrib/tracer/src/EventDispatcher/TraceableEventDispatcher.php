<?php

declare(strict_types=1);

namespace Drupal\tracer\EventDispatcher;

use Drupal\tracer\TracerInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Decorates the Symfony event dispatcher to trace events.
 */
class TraceableEventDispatcher implements EventDispatcherTraceableInterface {

  /**
   * An array of all the events that have been dispatched.
   *
   * @var array
   */
  protected array $calledListeners = [];

  /**
   * An array of all the events that have not been dispatched.
   *
   * @var array
   */
  protected array $notCalledListeners = [];

  /**
   * The span used to trace the Controller invocation.
   *
   * @var object|null
   */
  private ?object $controllerSpan;

  /**
   * The tracer instance.
   *
   * @var \Drupal\tracer\TracerInterface|null
   */
  private ?TracerInterface $tracer;

  /**
   * Class names of listener services, keyed by service ID.
   *
   * @var array<string, string|null>
   */
  private array $listenerServiceClasses = [];

  /**
   * Constructs a traceable event dispatcher.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $inner
   *   The decorated event_dispatcher service.
   */
  public function __construct(
    protected EventDispatcherInterface $inner,
  ) {
    $this->controllerSpan = NULL;
    $this->tracer = NULL;
  }

  /**
   * Limits serialization to the collected debug data.
   *
   * The decorated dispatcher holds listener callables, among them Closures
   * created by the container for lazily instantiated listener services, and
   * Closures cannot be serialized. Any object graph reaching this dispatcher
   * would therefore fail to serialize, which breaks caching of render element
   * info and, with it, installing a site from existing configuration.
   *
   * @return array
   *   The names of the properties to serialize.
   */
  public function __sleep(): array {
    return ['calledListeners', 'notCalledListeners'];
  }

  /**
   * Restores the properties left out of serialization.
   */
  public function __wakeup(): void {
    $this->controllerSpan = NULL;
    $this->tracer = NULL;
    $this->listenerServiceClasses = [];

    // The decorated dispatcher is private, so it cannot be fetched from the
    // container by ID. Read it from the decorator held by the container, which
    // is registered publicly as event_dispatcher. When this module is not
    // installed, that service is the undecorated dispatcher.
    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
     *
     * @phpstan-ignore-next-line
     */
    $dispatcher = \Drupal::service('event_dispatcher');
    $this->inner = $dispatcher instanceof self ? $dispatcher->inner : $dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public function addListener($eventName, $listener, $priority = 0): void {
    $this->inner->addListener($eventName, $listener, $priority);

    $this->notCalledListeners[$eventName][$priority][] = $this->describeListener($listener);
  }

  /**
   * Trace the start and stop of the event processing.
   */
  public function dispatch(object $event, ?string $eventName = NULL): object {
    $eventName = $eventName ?? \get_class($event);

    $this->beforeDispatch($eventName, $event);

    $listeners = $this->getListeners($eventName);
    $this->callListeners($listeners, $eventName, $event);

    $this->afterDispatch($eventName, $event);

    return $event;
  }

  /**
   * {@inheritdoc}
   */
  public function getCalledListeners(): array {
    return $this->calledListeners;
  }

  /**
   * {@inheritdoc}
   */
  public function getNotCalledListeners(): array {
    return $this->notCalledListeners;
  }

  /**
   * Called before dispatching the event.
   *
   * @param string $eventName
   *   The event's name.
   * @param object $event
   *   The event's object.
   */
  protected function beforeDispatch(string $eventName, object $event): void {
    switch ($eventName) {
      case KernelEvents::VIEW:
      case KernelEvents::RESPONSE:
        // Stop only if a controller has been executed.
        if ($this->controllerSpan != NULL) {
          $this->getTracer()->stop($this->controllerSpan);
        }
        break;
    }
  }

  /**
   * Called after dispatching the event.
   *
   * @param string $eventName
   *   The event's name.
   * @param object $event
   *   The event's object.
   */
  protected function afterDispatch(string $eventName, object $event): void {
    if ($eventName == KernelEvents::CONTROLLER_ARGUMENTS) {
      $this->controllerSpan = $this->getTracer()->start('controller', 'todo');
    }
  }

  /**
   * Triggers the listeners of an event.
   *
   * This method can be overridden to add functionality that is executed
   * for each listener.
   *
   * @param callable[] $listeners
   *   The event listeners.
   * @param string $eventName
   *   The name of the event to dispatch.
   * @param object $event
   *   The event object to pass to the event handlers/listeners.
   */
  protected function callListeners(iterable $listeners, string $eventName, object $event): void {
    $stoppable = $event instanceof StoppableEventInterface;

    foreach ($listeners as $listener) {
      if ($stoppable && $event->isPropagationStopped()) {
        break;
      }

      $priority = $this->getListenerPriority($eventName, $listener);
      $span = $this->getTracer()->start('event', $eventName, ['priority' => $priority]);
      $listener($event, $eventName, $this);
      $this->getTracer()->stop($span);

      $this->addCalledListener($listener, $eventName, $priority);
    }
  }

  /**
   * Describes a listener as plain strings.
   *
   * Storing the callable itself would keep a Closure in the collected data and
   * make this dispatcher unserializable, so only a description is kept.
   *
   * Listeners registered from the container are an array whose first element is
   * a Closure returning the listener service. The service is not instantiated
   * here: doing so at registration time forces every listener service to be
   * built, and can raise a circular reference exception while the container is
   * still being initialized. The service ID is read from the Closure instead,
   * and the class is resolved later, only for the listeners that have to be
   * matched against a called one.
   *
   * @param callable|array $listener
   *   The listener callable.
   *
   * @return array
   *   Either a 'service' key holding a [service ID, method] pair, or a 'class'
   *   and a 'method' key.
   */
  private function describeListener(callable|array $listener): array {
    if ($listener instanceof \Closure) {
      $reflection = new \ReflectionFunction($listener);

      return [
        'class' => $reflection->getClosureScopeClass()?->getName() ?? 'Closure',
        'method' => $reflection->getName() !== '{closure}'
          ? $reflection->getName()
          : \sprintf('{closure:%s(%d)}', \basename((string) $reflection->getFileName()), $reflection->getStartLine()),
      ];
    }

    if (\is_array($listener) && \count($listener) === 2) {
      [$target, $method] = $listener;

      if ($target instanceof \Closure) {
        $service_id = $this->serviceIdFromClosure($target);

        return $service_id !== NULL
          ? ['service' => [$service_id, (string) $method]]
          : ['class' => 'Closure', 'method' => (string) $method];
      }

      return [
        'class' => \is_string($target) ? $target : \get_class($target),
        'method' => (string) $method,
      ];
    }

    return [
      'class' => 'function',
      'method' => \is_string($listener) ? $listener : \get_debug_type($listener),
    ];
  }

  /**
   * Reads the service ID captured by a lazy listener Closure.
   *
   * @param \Closure $closure
   *   The Closure returning a listener service.
   *
   * @return string|null
   *   The service ID, or NULL when the Closure does not hold one.
   */
  private function serviceIdFromClosure(\Closure $closure): ?string {
    $variables = (new \ReflectionFunction($closure))->getStaticVariables();

    // The compiled container captures the whole argument value object.
    // @see \Drupal\Component\DependencyInjection\Container::resolveServicesAndParameters()
    if (isset($variables['argument']->id)) {
      return (string) $variables['argument']->id;
    }

    // The uncompiled ContainerBuilder captures the reference itself.
    // @see \Symfony\Component\DependencyInjection\ContainerBuilder::createService()
    if (isset($variables['reference'])) {
      return (string) $variables['reference'];
    }

    return NULL;
  }

  /**
   * Returns the class of a listener service.
   *
   * @param string $service_id
   *   The service ID.
   *
   * @return string|null
   *   The class name, or NULL when the service cannot be resolved.
   */
  private function listenerServiceClass(string $service_id): ?string {
    if (\array_key_exists($service_id, $this->listenerServiceClasses)) {
      return $this->listenerServiceClasses[$service_id];
    }

    /**
     * @var \Symfony\Component\DependencyInjection\ContainerInterface $container
     *
     * @phpstan-ignore-next-line
     */
    $container = \Drupal::getContainer();
    $service = $container->get($service_id, ContainerInterface::NULL_ON_INVALID_REFERENCE);
    $this->listenerServiceClasses[$service_id] = $service !== NULL ? \get_class($service) : NULL;

    return $this->listenerServiceClasses[$service_id];
  }

  /**
   * Add listener to the called listeners array.
   *
   * @param callable|array $callable
   *   The event's callable.
   * @param string $event_name
   *   The event's name.
   * @param int $priority
   *   The event's priority.
   */
  private function addCalledListener(callable|array $callable, string $event_name, int $priority): void {
    $called = $this->describeListener($callable);
    $this->calledListeners[$event_name][$priority][] = $called;

    if (!isset($this->notCalledListeners[$event_name][$priority])) {
      return;
    }

    foreach ($this->notCalledListeners[$event_name][$priority] as $key => $listener) {
      if ($this->listenersMatch($listener, $called)) {
        unset($this->notCalledListeners[$event_name][$priority][$key]);
        break;
      }
    }
  }

  /**
   * Tells whether two listener descriptions point to the same listener.
   *
   * A listener is registered as a service ID but is dispatched as the resolved
   * object, so the two descriptions can differ: the service is resolved to its
   * class before comparing, and only once the method already matches.
   *
   * @param array $registered
   *   The description built when the listener was registered.
   * @param array $called
   *   The description built when the listener was called.
   *
   * @return bool
   *   TRUE when both descriptions point to the same listener.
   */
  private function listenersMatch(array $registered, array $called): bool {
    if (isset($registered['service'])) {
      if (isset($called['service'])) {
        return $registered['service'] === $called['service'];
      }

      [$service_id, $method] = $registered['service'];

      return $method === $called['method']
        && $this->listenerServiceClass($service_id) === $called['class'];
    }

    if (isset($called['service'])) {
      [$service_id, $method] = $called['service'];

      return $method === $registered['method']
        && $this->listenerServiceClass($service_id) === $registered['class'];
    }

    return $registered['class'] === $called['class']
      && $registered['method'] === $called['method'];
  }

  /**
   * Delegate call to the decorated class.
   */
  public function addSubscriber(EventSubscriberInterface $subscriber): void {
    $this->inner->addSubscriber($subscriber);
  }

  /**
   * Delegate call to the decorated class.
   */
  public function removeListener(string $eventName, callable $listener): void {
    $this->inner->removeListener($eventName, $listener);
  }

  /**
   * Delegate call to the decorated class.
   */
  public function removeSubscriber(EventSubscriberInterface $subscriber): void {
    $this->inner->removeSubscriber($subscriber);
  }

  /**
   * Delegate call to the decorated class.
   */
  public function getListeners(?string $eventName = NULL): array {
    return $this->inner->getListeners($eventName);
  }

  /**
   * Delegate call to the decorated class.
   */
  public function getListenerPriority(string $eventName, callable $listener): ?int {
    return $this->inner->getListenerPriority($eventName, $listener);
  }

  /**
   * Delegate call to the decorated class.
   */
  public function hasListeners(?string $eventName = NULL): bool {
    return $this->inner->hasListeners($eventName);
  }

  /**
   * Get the tracer instance.
   *
   * @return \Drupal\tracer\TracerInterface
   *   The tracer instance.
   */
  private function getTracer(): TracerInterface {
    if ($this->tracer != NULL) {
      return $this->tracer;
    }

    /**
     * @var \Drupal\tracer\TracerFactory $factory
     *
     * @phpstan-ignore-next-line
     */
    $factory = \Drupal::service('tracer.tracer_factory');
    $this->tracer = $factory->getTracer();

    return $this->tracer;
  }

}
