<?php

declare(strict_types=1);

namespace Drupal\tracer\EventDispatcher;

use Drupal\tracer\TracerInterface;
use Psr\EventDispatcher\StoppableEventInterface;
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
  protected array $calledListeners;

  /**
   * An array of all the events that have not been dispatched.
   *
   * @var array
   */
  protected array $notCalledListeners;

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
   * {@inheritdoc}
   */
  public function addListener($eventName, $listener, $priority = 0): void {
    $this->inner->addListener($eventName, $listener, $priority);

    $this->notCalledListeners[$eventName][$priority][] = ['callable' => $listener];
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
   * Add listener to the called listeners array.
   *
   * @param array|\Closure $callable
   *   The event's callable.
   * @param string $event_name
   *   The event's name.
   * @param int $priority
   *   The event's priority.
   */
  private function addCalledListener(array|\Closure $callable, string $event_name, int $priority): void {
    if ($callable instanceof \Closure) {
      // Use reflection to extract closure details for tracking.
      $reflection = new \ReflectionFunction($callable);
      $this->calledListeners[$event_name][$priority][] = [
        'class' => $reflection->getClosureScopeClass()?->getName() ?? 'Closure',
        'method' => $reflection->getName() !== '{closure}'
          ? $reflection->getName()
          : \sprintf('{closure:%s(%d)}', \basename($reflection->getFileName()), $reflection->getStartLine()),
      ];

      // Remove matching closure from notCalledListeners.
      if (isset($this->notCalledListeners[$event_name][$priority])) {
        foreach ($this->notCalledListeners[$event_name][$priority] as $key => $listener) {
          if ($listener['callable'] instanceof \Closure && $listener['callable'] === $callable) {
            unset($this->notCalledListeners[$event_name][$priority][$key]);
            break;
          }
        }
      }

      return;
    }

    $class = \is_string($callable[0]) ? $callable[0] : \get_class($callable[0]);
    $this->calledListeners[$event_name][$priority][] = [
      'class' => $class,
      'method' => $callable[1],
    ];

    if (!isset($this->notCalledListeners[$event_name])) {
      return;
    }

    if (!isset($this->notCalledListeners[$event_name][$priority])) {
      return;
    }

    foreach ($this->notCalledListeners[$event_name][$priority] as $key => $listener) {
      if ($listener['callable'][0] instanceof \Closure) {
        $listener['callable'][0] = $listener['callable'][0]();
      }

      if (\is_callable($listener['callable'], TRUE, $listenerCallableName) && \is_callable($callable, TRUE, $definitionCallableName)) {
        if ($listenerCallableName == $definitionCallableName) {
          unset($this->notCalledListeners[$event_name][$priority][$key]);
        }
      }
      else {
        $listener_class = \is_string($listener['callable'][0]) ? $listener['callable'][0] : \get_class($listener['callable'][0]);
        $definition_class = \is_string($callable[0]) ? $callable[0] : \get_class($callable[0]);
        if ($listener_class == $definition_class && $listener['callable'][1] == $callable[1]) {
          unset($this->notCalledListeners[$event_name][$priority][$key]);
        }
      }
    }
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
