<?php

declare(strict_types=1);

namespace Drupal\webprofiler\DataCollector;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\monolog\Logger\LoggerInterfacesAdapter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;

/**
 * Collects logs data.
 */
class LogsDataCollector extends DataCollector implements HasPanelInterface, LateDataCollectorInterface {

  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * LogsDataCollector constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\Logger\LogMessageParserInterface $parser
   *   The log message parser.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly LogMessageParserInterface $parser,
  ) {
    $this->data['logs'] = [];
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'logs';
  }

  /**
   * {@inheritdoc}
   */
  public function collect(Request $request, Response $response, ?\Throwable $exception = NULL): void {
  }

  /**
   * {@inheritdoc}
   */
  public function lateCollect() {
    $logger = $this->logger;

    if (
      $logger instanceof LoggerInterfacesAdapter and
      ($adapted_logger = $logger->getAdaptedLogger()) instanceof DebugLoggerInterface
    ) {
      $this->data['logs'] = \array_map(
        static function ($log) {
          unset($log['context']['exception']);
          unset($log['context']['backtrace']);

          return $log;
        },
        $adapted_logger->getLogs(),
      );
    }
  }

  /**
   * Reset the collected data.
   */
  public function reset(): void {
    $this->data = [];
  }

  /**
   * Return the number of logs.
   *
   * @return int
   *   The number of logs.
   */
  public function getLogsCount(): int {
    return \count($this->data['logs']);
  }

  /**
   * {@inheritdoc}
   */
  public function getPanel(): array {
    return [
      '#theme' => 'webprofiler_dashboard_section',
      '#data' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Timestamp'),
          $this->t('Priority'),
          $this->t('Channel'),
          $this->t('Message'),
          $this->t('Context'),
        ],
        '#rows' => \array_map(function ($log) {
          return [
            $log['timestamp_rfc3339'],
            $log['priorityName'],
            $log['channel'],
            $this->processContext($log['message'], $log['context']),
            \json_encode($log['context']),
          ];
        }, $this->data['logs']),
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
   * Process the context.
   *
   * @param string $message
   *   The message.
   * @param array $context
   *   The context.
   *
   * @return string
   *   The processed context.
   */
  private function processContext(string $message, array $context): string {
    $message_placeholders = $this
      ->parser
      ->parseMessagePlaceholders(
        $message,
        $context,
      );

    // Replace the placeholders in the message.
    return \count($message_placeholders) === 0
      ? $message
      : \strtr($message, $message_placeholders);
  }

}
