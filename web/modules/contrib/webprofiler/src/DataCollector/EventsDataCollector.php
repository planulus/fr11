<?php

declare(strict_types=1);

namespace Drupal\webprofiler\DataCollector;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tracer\EventDispatcher\EventDispatcherTraceableInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

/**
 * Collects events data.
 */
class EventsDataCollector extends DataCollector implements LateDataCollectorInterface, HasPanelInterface {

  use StringTranslationTrait, DataCollectorTrait, PanelTrait;

  /**
   * EventsDataCollector constructor.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   */
  public function __construct(
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'events';
  }

  /**
   * {@inheritdoc}
   */
  public function collect(Request $request, Response $response, ?\Throwable $exception = NULL): void {
    $this->data = [
      'called_listeners' => [],
      'called_listeners_count' => 0,
      'not_called_listeners' => [],
      'not_called_listeners_count' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function lateCollect() {
    if ($this->eventDispatcher instanceof EventDispatcherTraceableInterface) {
      [$called, $count_called] = $this->normalizeListeners(
        $this->eventDispatcher->getCalledListeners(),
      );
      $this->data['called_listeners'] = $called;
      $this->data['called_listeners_count'] = $count_called;

      [$not_called, $count_not_called] = $this->normalizeListeners(
        $this->eventDispatcher->getNotCalledListeners(),
      );
      $this->data['not_called_listeners'] = $not_called;
      $this->data['not_called_listeners_count'] = $count_not_called;
    }
  }

  /**
   * Normalize a tracer listeners tree into serializable class/method data.
   *
   * The tracer module records listeners either as ['class' => …, 'method' => …]
   * (already-called listeners) or as ['callable' => …] holding the raw callable
   * (not-called listeners, and the listener that is executing while the profile
   * is collected on kernel terminate). The raw callable is frequently a
   * \Closure, which cannot be serialized, so storing it verbatim makes the
   * profiler fail to write the profile ("Serialization of 'Closure' is not
   * allowed"). Reduce every entry to class/method names so the collected data
   * stays serializable.
   *
   * @param array $listeners
   *   A tracer listeners tree keyed by event name and priority.
   *
   * @return array
   *   A tuple of [normalized listeners tree, total listener count].
   */
  private function normalizeListeners(array $listeners): array {
    $normalized = [];
    $count = 0;

    foreach ($listeners as $event_name => $events) {
      foreach ($events as $priority => $entries) {
        foreach ($entries as $entry) {
          $count++;

          if (isset($entry['callable'])) {
            $info = $this->normalizeListener($entry['callable']);
          }
          else {
            $info = [
              'class' => $entry['class'] ?? 'Closure',
              'method' => $entry['method'] ?? '',
            ];
          }

          $clazz = $this->getMethodData($info['class'], $info['method']);
          if ($clazz !== NULL) {
            $info['clazz'] = $clazz;
          }
          else {
            // Closures (and callables that cannot be reflected) are rendered
            // as a plain "Closure" label; there is no source link to build.
            $info['class'] = 'Closure';
          }

          $normalized[$event_name][$priority][] = $info;
        }
      }
    }

    return [$normalized, $count];
  }

  /**
   * Reset the collected data.
   */
  public function reset(): void {
    $this->data = [];
  }

  /**
   * Return an array of all the events that have been dispatched.
   *
   * @return array
   *   An array of all the events that have been dispatched.
   */
  public function getCalledListeners(): array {
    return $this->data['called_listeners'];
  }

  /**
   * Return an array of all the events that have not been dispatched.
   *
   * @return array
   *   An array of all the events that have not been dispatched.
   */
  public function getNotCalledListeners(): array {
    return $this->data['not_called_listeners'];
  }

  /**
   * Return the count of the events that have been dispatched.
   *
   * @return int
   *   The count of the events that have been dispatched.
   */
  public function getCalledListenersCount(): int {
    return $this->data['called_listeners_count'];
  }

  /**
   * Return the count of the events that have not been dispatched.
   *
   * @return int
   *   The count of the events that have not been dispatched.
   */
  public function getNotCalledListenersCount(): int {
    return $this->data['not_called_listeners_count'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPanel(): array {
    $tabs = [
      [
        'label' => 'Called listeners',
        'content' => $this->renderListeners($this->getCalledListeners(), 'Called listeners', TRUE),
      ],
      [
        'label' => 'Not called listeners',
        'content' => $this->renderListeners($this->getNotCalledListeners(), 'Not called listeners', FALSE),
      ],
    ];

    return [
      '#theme' => 'webprofiler_dashboard_tabs',
      '#tabs' => $tabs,
    ];
  }

  /**
   * Render a list of listeners.
   *
   * @param array $listeners
   *   The list of listeners to render.
   * @param string $label
   *   The list's label.
   * @param bool $called
   *   TRUE if the table is for called listeners, FALSE otherwise.
   *
   * @return array
   *   The render array of the list of blocks.
   */
  private function renderListeners(array $listeners, string $label, bool $called): array {
    if (\count($listeners) == 0) {
      return [
        $label => [
          '#markup' => '<p>' . $this->t('No @label listeners collected',
              ['@label' => $label]) . '</p>',
        ],
      ];
    }

    $rows = [];
    foreach ($listeners as $name => $priorities) {
      foreach ($priorities as $priority => $subscribers) {
        foreach ($subscribers as $subscriber) {
          $rows[] = [
            $name,
            [
              'data' => [
                '#type' => 'inline_template',
                '#template' => '{{ data|raw }}',
                '#context' => [
                  'data' => $this->classLink($subscriber),
                ],
              ],
              'class' => 'webprofiler__value',
            ],
            $priority,
          ];
        }
      }
    }

    return [
      $label => [
        '#theme' => 'webprofiler_dashboard_section',
        '#data' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Called listeners'),
            $called ? $this->t('Class') : $this->t('Service'),
            $this->t('Priority'),
          ],
          '#rows' => $rows,
          '#attributes' => [
            'class' => [
              'webprofiler__table',
            ],
          ],
          '#sticky' => TRUE,
        ],
      ],
    ];
  }

  /**
   * Render the link to a class.
   *
   * The class can be a regular class, a service or a closure.
   *
   * @param array $subscriber
   *   Event subscriber data.
   *
   * @return array
   *   A render array of the link to the class.
   */
  private function classLink(array $subscriber): array {
    if (isset($subscriber['class'])) {
      if ($subscriber['class'] == 'Closure') {
        return [
          '#markup' => $this->t('Closure'),
        ];
      }
      else {
        return $this->renderClassLinkFromMethodData($subscriber['clazz']);
      }
    }

    return [
      '#markup' => \sprintf('%s::%s', $subscriber['service'][0], $subscriber['service'][1]),
    ];
  }

  /**
   * Normalize a listener callable into serializable class/method data.
   *
   * The tracer module stores not-called listeners as the raw callable, which
   * for most core listeners is a lazy-loading \Closure. Closures cannot be
   * serialized, so storing them verbatim makes the profiler fail to write the
   * profile ("Serialization of 'Closure' is not allowed"). Extract the class
   * and method names instead so the collected data stays serializable.
   *
   * @param mixed $callable
   *   The listener callable.
   *
   * @return array
   *   An array with 'class' and 'method' keys.
   */
  private function normalizeListener(mixed $callable): array {
    if ($callable instanceof \Closure) {
      $reflection = new \ReflectionFunction($callable);

      return [
        'class' => $reflection->getClosureScopeClass()?->getName() ?? 'Closure',
        'method' => $reflection->getName(),
      ];
    }

    if (\is_array($callable) && isset($callable[0], $callable[1])) {
      return [
        'class' => \is_object($callable[0]) ? \get_class($callable[0]) : (string) $callable[0],
        'method' => (string) $callable[1],
      ];
    }

    if (\is_string($callable) && \str_contains($callable, '::')) {
      [$class, $method] = \explode('::', $callable, 2);

      return ['class' => $class, 'method' => $method];
    }

    return ['class' => 'Closure', 'method' => ''];
  }

}
