<?php

declare(strict_types=1);

namespace Drupal\webprofiler\DataCollector;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webprofiler\DumpTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Component\Messenger\TraceableMessageBus;
use Symfony\Component\VarDumper\Caster\ClassStub;

/**
 * Messenger data collector.
 */
class MessengerDataCollector extends DataCollector implements LateDataCollectorInterface, HasPanelInterface {

  use StringTranslationTrait;
  use DumpTrait;

  /**
   * The traceable buses.
   *
   * @var \Symfony\Component\Messenger\TraceableMessageBus[]
   */
  private array $traceableBuses = [];

  /**
   * MessengerDataCollector constructor.
   */
  public function __construct() {
    $this->data['messages'] = [];
    $this->data['buses'] = [];
  }

  /**
   * Register a traceable bus.
   */
  public function registerBus(string $name, TraceableMessageBus $bus): void {
    $this->traceableBuses[$name] = $bus;
  }

  /**
   * {@inheritdoc}
   */
  public function collect(
    Request $request,
    Response $response,
    ?\Throwable $exception = NULL,
  ): void {
    // Noop. Everything is collected live by the traceable buses & cloned as
    // late as possible.
  }

  /**
   * {@inheritdoc}
   */
  public function lateCollect(): void {
    $this->data = [
      'messages' => [],
      'buses' => \array_keys($this->traceableBuses),
    ];

    $messages = [];
    foreach ($this->traceableBuses as $busName => $bus) {
      foreach ($bus->getDispatchedMessages() as $message) {
        $debugRepresentation = $this->cloneVar($this->collectMessage($busName,
          $message));
        $messages[] = [$debugRepresentation, $message['callTime']];
      }
    }

    // Order by call time.
    \usort($messages, static fn($a, $b) => $a[1] <=> $b[1]);

    // Keep the messages clones only.
    $this->data['messages'] = \array_column($messages, 0);
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'messenger';
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    $this->data = [
      'messages' => [],
      'buses' => [],
    ];
    foreach ($this->traceableBuses as $traceableBus) {
      $traceableBus->reset();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getCasters(): array {
    $casters = parent::getCasters();

    // Unset the default caster truncating collectors data.
    unset($casters['*']);

    return $casters;
  }

  /**
   * Collects a message.
   *
   * @param string $busName
   *   The bus name.
   * @param array $tracedMessage
   *   The traced message.
   *
   * @return array
   *   The collected message.
   */
  private function collectMessage(
    string $busName,
    array $tracedMessage,
  ): array {
    $message = $tracedMessage['message'];

    $debugRepresentation = [
      'bus' => $busName,
      'stamps' => $tracedMessage['stamps'] ?? NULL,
      'stamps_after_dispatch' => $tracedMessage['stamps_after_dispatch'] ?? NULL,
      'message' => [
        'type' => new ClassStub($message::class),
        'value' => $message,
      ],
      'caller' => $tracedMessage['caller'],
    ];

    if (isset($tracedMessage['exception'])) {
      $exception = $tracedMessage['exception'];

      $debugRepresentation['exception'] = [
        'type' => $exception::class,
        'value' => $exception,
      ];
    }

    return $debugRepresentation;
  }

  /**
   * Returns the number of messages.
   *
   * @param string|null $bus
   *   The bus name.
   *
   * @return int
   *   The number of messages.
   */
  public function getExceptionsCount(?string $bus = NULL): int {
    $count = 0;
    foreach ($this->getMessages($bus) as $message) {
      $count += (int) isset($message['exception']);
    }

    return $count;
  }

  /**
   * Returns the messages.
   *
   * @param string|null $bus
   *   The bus name.
   *
   * @return array
   *   The messages.
   */
  public function getMessages(?string $bus = NULL): array {
    if (NULL === $bus) {
      return $this->data['messages'];
    }

    return \array_filter($this->data['messages'],
      static fn($message) => $bus === $message['bus']);
  }

  /**
   * Return the buses.
   *
   * @return array
   *   The buses.
   */
  public function getBuses(): array {
    return $this->data['buses'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPanel(): array {
    if (\count($this->getMessages()) == 0) {
      return [
        '#markup' => $this->t('No messages have been collected'),
      ];
    }

    $rows = [];
    foreach ($this->getMessages() as $message) {
      $rows[] = [
        [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{{ data|raw }}',
            '#context' => [
              'data' => $this->dumpData($message),
            ],
          ],
          'class' => 'webprofiler__value',
        ],
      ];
    }

    return [
      '#theme' => 'webprofiler_dashboard_section',
      '#title' => 'Messages',
      '#data' => [
        '#type' => 'table',
        '#rows' => $rows,
        '#attributes' => [
          'class' => [
            'webprofiler__table',
          ],
        ],
      ],
    ];
  }

}
