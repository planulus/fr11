<?php

declare(strict_types=1);

namespace Drupal\webprofiler\DataCollector;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * Collects data about calls to AI providers during a request.
 *
 * Data is gathered by listening to the events dispatched by the AI module
 * provider proxy: every request opens a call on the pre generate event and
 * closes it on the post generate event, on the post streaming event for
 * streamed responses or on the exception event when the provider fails.
 *
 * The AI module is an optional dependency, so its classes are never referenced
 * directly: events are subscribed by name and their payload is inspected with
 * duck typing.
 *
 * @see \Drupal\ai\Plugin\ProviderProxy
 */
class AiDataCollector extends DataCollector implements HasPanelInterface, EventSubscriberInterface {

  use StringTranslationTrait, PanelTrait;

  /**
   * The name of the event dispatched before a request to a provider.
   */
  private const EVENT_PRE_GENERATE = 'ai.pre_generate_response';

  /**
   * The name of the event dispatched after a request to a provider.
   */
  private const EVENT_POST_GENERATE = 'ai.post_generate_response';

  /**
   * The name of the event dispatched when a streamed response is consumed.
   */
  private const EVENT_POST_STREAMING = 'ai.post_streaming_response';

  /**
   * The name of the event dispatched when a provider call throws.
   */
  private const EVENT_EXCEPTION = 'Drupal\ai\Event\AiExceptionEvent';

  /**
   * The interface implemented by streamed chat responses.
   */
  private const STREAMED_ITERATOR_INTERFACE = 'Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface';

  /**
   * The interface implemented by guardrails that scan a streamed response.
   *
   * The guardrails event subscriber hands these guardrails to the streamed
   * iterator instead of running them, so they never record a result on the
   * input.
   */
  private const STREAMABLE_GUARDRAIL_INTERFACE = 'Drupal\ai\Guardrail\StreamableGuardrailInterface';

  /**
   * The interface implemented by guardrails backed by a model.
   *
   * The guardrails event subscriber skips these guardrails when the call is
   * made by another model backed guardrail.
   */
  private const NON_DETERMINISTIC_GUARDRAIL_INTERFACE = 'Drupal\ai\Guardrail\NonDeterministicGuardrailInterface';

  /**
   * AiDataCollector constructor.
   */
  public function __construct() {
    $this->data['calls'] = [];
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after every other subscriber, so that guardrails, failover and any
    // other third party manipulation of input, output and configuration are
    // part of the collected data.
    return [
      self::EVENT_PRE_GENERATE => ['onPreGenerate', -1000],
      self::EVENT_POST_GENERATE => ['onPostGenerate', -1000],
      self::EVENT_POST_STREAMING => ['onPostStreaming', -1000],
      self::EVENT_EXCEPTION => ['onException', -1000],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'ai';
  }

  /**
   * Reset the collected data.
   */
  public function reset(): void {
    $this->data = ['calls' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function collect(Request $request, Response $response, ?\Throwable $exception = NULL): void {
    foreach ($this->data['calls'] as $id => $call) {
      // A call is still open when a guardrail, or another subscriber, forced an
      // output during the pre generate event, or when a streamed response has
      // never been consumed.
      if ($call['status'] === 'pending') {
        $this->data['calls'][$id]['status'] = $call['streamed'] ? 'unconsumed' : 'short_circuited';
      }
    }

    $this->data['calls'] = $this->addDepth($this->data['calls']);
    $this->data['summary'] = $this->summarize($this->data['calls']);
  }

  /**
   * Opens a call when a request to a provider is about to be sent.
   *
   * @param object $event
   *   The pre generate response event.
   */
  public function onPreGenerate(object $event): void {
    $id = $event->getRequestThreadId();

    $this->data['calls'][$id] = [
      'index' => \count($this->data['calls']) + 1,
      'id' => $id,
      'parent_id' => $event->getRequestParentId(),
      'depth' => 0,
      'provider' => $event->getProviderId(),
      'model' => $event->getModelId(),
      'operation_type' => $event->getOperationType(),
      'status' => 'pending',
      'streamed' => FALSE,
      'start' => \microtime(TRUE),
      'duration' => NULL,
      'tags' => $event->getTags(),
      'configuration' => $this->snapshot($event->getConfiguration()),
      'metadata' => $this->snapshot($event->getAllMetadata()),
      'debug_data' => $this->snapshot($event->getDebugData()),
      'input' => $this->collectInput($event->getInput()),
      'output' => NULL,
      'guardrail_sets' => $this->collectGuardrailSets($event->getInput()),
      'guardrails' => $this->collectGuardrails($event->getInput()),
      'error' => NULL,
    ];
  }

  /**
   * Closes a call when the provider returned a response.
   *
   * @param object $event
   *   The post generate response event.
   */
  public function onPostGenerate(object $event): void {
    $id = $event->getRequestThreadId();
    if (!isset($this->data['calls'][$id])) {
      return;
    }

    $output = $event->getOutput();
    $normalized = \method_exists($output, 'getNormalized') ? $output->getNormalized() : NULL;
    $streamed_interface = self::STREAMED_ITERATOR_INTERFACE;
    $streamed = $normalized instanceof $streamed_interface;

    $this->data['calls'][$id]['streamed'] = $streamed;
    $this->data['calls'][$id]['input'] = $this->collectInput($event->getInput());
    $this->data['calls'][$id]['guardrails'] = $this->collectGuardrails($event->getInput());
    $this->data['calls'][$id]['metadata'] = $this->snapshot($event->getAllMetadata());
    $this->data['calls'][$id]['debug_data'] = $this->snapshot($event->getDebugData());
    $this->data['calls'][$id]['output'] = $this->collectOutput($output);

    // A streamed response carries the token usage only once the iterator has
    // been consumed: the call is closed by the post streaming event.
    if ($streamed) {
      return;
    }

    $this->closeCall($id, 'completed');
  }

  /**
   * Closes a call when a streamed response has been fully consumed.
   *
   * @param object $event
   *   The post streaming response event.
   */
  public function onPostStreaming(object $event): void {
    $id = $event->getRequestThreadId();
    if (!isset($this->data['calls'][$id])) {
      return;
    }

    $this->data['calls'][$id]['output'] = $this->collectOutput($event->getOutput());
    $this->data['calls'][$id]['guardrails'] = $this->collectGuardrails($event->getInput());
    $this->closeCall($id, 'completed');
  }

  /**
   * Closes a call when the provider threw an exception.
   *
   * @param object $event
   *   The AI exception event.
   */
  public function onException(object $event): void {
    $id = $event->getRequestThreadId();
    if (!isset($this->data['calls'][$id])) {
      return;
    }

    $exception = $event->exception;
    $this->data['calls'][$id]['error'] = [
      'class' => \get_class($exception),
      'message' => $event->getMessage(),
      'code' => $exception->getCode(),
      'file' => $exception->getFile(),
      'line' => $exception->getLine(),
    ];
    $this->data['calls'][$id]['guardrails'] = $this->collectGuardrails($event->getInput());

    // A subscriber can recover from the failure with a replacement output, in
    // which case the caller never sees the exception.
    $recovered = $event->getForcedOutputObject();
    if ($recovered !== NULL) {
      $this->data['calls'][$id]['output'] = $this->collectOutput($recovered);
    }

    $this->closeCall($id, $recovered !== NULL ? 'recovered' : 'error');
  }

  /**
   * Returns the number of calls to AI providers.
   *
   * @return int
   *   The number of calls to AI providers.
   */
  public function getCallsCount(): int {
    return \count($this->getCalls());
  }

  /**
   * Returns the collected calls.
   *
   * @return array
   *   The collected calls.
   */
  public function getCalls(): array {
    return $this->data['calls'] ?? [];
  }

  /**
   * Returns the number of failed calls.
   *
   * @return int
   *   The number of failed calls.
   */
  public function getErrorsCount(): int {
    return $this->getSummaryValue('errors', 0);
  }

  /**
   * Returns the total time spent waiting for AI providers, in milliseconds.
   *
   * @return float
   *   The total time spent waiting for AI providers.
   */
  public function getTotalDuration(): float {
    return (float) $this->getSummaryValue('duration', 0);
  }

  /**
   * Returns the total number of tokens used by all the calls.
   *
   * @return int
   *   The total number of tokens.
   */
  public function getTotalTokens(): int {
    return $this->getSummaryValue('tokens', 0);
  }

  /**
   * Returns the total number of input tokens used by all the calls.
   *
   * @return int
   *   The total number of input tokens.
   */
  public function getTotalInputTokens(): int {
    return $this->getSummaryValue('input_tokens', 0);
  }

  /**
   * Returns the total number of output tokens used by all the calls.
   *
   * @return int
   *   The total number of output tokens.
   */
  public function getTotalOutputTokens(): int {
    return $this->getSummaryValue('output_tokens', 0);
  }

  /**
   * Returns the number of guardrails that recorded a result during the request.
   *
   * @return int
   *   The number of guardrails that recorded a result.
   */
  public function getGuardrailsCount(): int {
    return $this->getSummaryValue('guardrails', 0);
  }

  /**
   * Returns the number of guardrails configured on the calls of the request.
   *
   * A guardrail configured on two calls is counted twice: the count follows
   * the number of times the guardrails event subscriber considered it.
   *
   * @return int
   *   The number of configured guardrails.
   */
  public function getGuardrailsConfiguredCount(): int {
    return $this->getSummaryValue('guardrails_configured', 0);
  }

  /**
   * Returns the number of guardrails that passed a call unchanged.
   *
   * @return int
   *   The number of guardrails that passed a call.
   */
  public function getGuardrailsPassCount(): int {
    return $this->getSummaryValue('guardrails_pass', 0);
  }

  /**
   * Returns the number of guardrails that asked to stop a call.
   *
   * @return int
   *   The number of guardrails that asked to stop a call.
   */
  public function getGuardrailsStopCount(): int {
    return $this->getSummaryValue('guardrails_stop', 0);
  }

  /**
   * Returns the number of tools returned by the providers.
   *
   * @return int
   *   The number of tool calls.
   */
  public function getToolCallsCount(): int {
    return $this->getSummaryValue('tool_calls', 0);
  }

  /**
   * Returns the providers used during the request, keyed by provider id.
   *
   * @return array
   *   The number of calls per provider.
   */
  public function getProviders(): array {
    return $this->getSummaryValue('providers', []);
  }

  /**
   * Returns the models used during the request, keyed by model id.
   *
   * @return array
   *   The number of calls per model.
   */
  public function getModels(): array {
    return $this->getSummaryValue('models', []);
  }

  /**
   * {@inheritdoc}
   */
  public function getPanel(): array {
    if ($this->getCallsCount() === 0) {
      return [
        '#markup' => '<p>' . $this->t('No calls to AI providers collected') . '</p>',
      ];
    }

    return [
      '#theme' => 'webprofiler_dashboard_tabs',
      '#tabs' => [
        [
          'label' => $this->t('Metrics'),
          'content' => $this->renderMetrics(),
        ],
        [
          'label' => $this->t('Calls'),
          'content' => $this->renderCalls(),
        ],
        [
          'label' => $this->t('Guardrails'),
          'content' => $this->renderGuardrails(),
        ],
        [
          'label' => $this->t('Tools'),
          'content' => $this->renderTools(),
        ],
      ],
    ];
  }

  /**
   * Converts a value into a serializable representation.
   *
   * Values are snapshot while the request is running, so they must survive the
   * serialization of the profile. PanelTrait keeps the cloner it builds, and a
   * cloner holds closures, so the implementation of the base class is used
   * instead of the one of the trait.
   *
   * @param mixed $var
   *   The value to convert.
   *
   * @return \Symfony\Component\VarDumper\Cloner\Data
   *   The converted value.
   */
  private function snapshot(mixed $var): Data {
    return parent::cloneVar($var);
  }

  /**
   * Closes a call, storing its final status and duration.
   *
   * @param string $id
   *   The request thread id.
   * @param string $status
   *   The final status of the call.
   */
  private function closeCall(string $id, string $status): void {
    $this->data['calls'][$id]['status'] = $status;
    $this->data['calls'][$id]['duration'] = (\microtime(TRUE) - $this->data['calls'][$id]['start']) * 1000;
  }

  /**
   * Extracts the interesting parts of a request input.
   *
   * @param mixed $input
   *   The input of the call.
   *
   * @return array
   *   The collected input data.
   */
  private function collectInput(mixed $input): array {
    $data = [
      'class' => \is_object($input) ? \get_class($input) : \get_debug_type($input),
      'string' => NULL,
      'system_prompt' => NULL,
      'messages' => [],
      'tools' => [],
      'json_schema' => NULL,
      'raw' => $this->snapshot($input),
    ];

    if (!\is_object($input)) {
      return $data;
    }

    if (\method_exists($input, 'toString')) {
      $data['string'] = $input->toString();
    }

    if (\method_exists($input, 'getSystemPrompt')) {
      $data['system_prompt'] = $input->getSystemPrompt();
    }

    if (\method_exists($input, 'getMessages')) {
      foreach ($input->getMessages() as $message) {
        $data['messages'][] = $this->collectMessage($message);
      }
    }

    if (\method_exists($input, 'getChatTools')) {
      $tools = $input->getChatTools();
      if ($tools !== NULL && \method_exists($tools, 'renderToolsArray')) {
        $data['tools'] = $tools->renderToolsArray();
      }
    }

    if (\method_exists($input, 'getChatStructuredJsonSchema')) {
      $schema = $input->getChatStructuredJsonSchema();
      $data['json_schema'] = $schema === [] ? NULL : $schema;
    }

    return $data;
  }

  /**
   * Extracts the interesting parts of a chat message.
   *
   * @param object $message
   *   The chat message.
   *
   * @return array
   *   The collected message data.
   */
  private function collectMessage(object $message): array {
    $data = [
      'role' => \method_exists($message, 'getRole') ? $message->getRole() : '',
      'text' => \method_exists($message, 'getText') ? $message->getText() : '',
      'files' => [],
      'tools' => [],
    ];

    if (\method_exists($message, 'getFiles')) {
      foreach ($message->getFiles() as $file) {
        $data['files'][] = \method_exists($file, 'getFileName') ? $file->getFileName() : \get_class($file);
      }
    }

    if (\method_exists($message, 'getRenderedTools')) {
      $data['tools'] = $message->getRenderedTools();
    }

    return $data;
  }

  /**
   * Extracts the interesting parts of a response output.
   *
   * @param mixed $output
   *   The output of the call.
   *
   * @return array
   *   The collected output data.
   */
  private function collectOutput(mixed $output): array {
    $data = [
      'class' => \is_object($output) ? \get_class($output) : \get_debug_type($output),
      'text' => NULL,
      'tools' => [],
      'token_usage' => [],
      'rate_limits' => [],
      'finish_reasons' => [],
      'metadata' => NULL,
      'raw' => NULL,
    ];

    if (!\is_object($output)) {
      return $data;
    }

    if (\method_exists($output, 'getNormalized')) {
      $normalized = $output->getNormalized();
      $streamed_interface = self::STREAMED_ITERATOR_INTERFACE;

      // A streamed response is an iterator that must not be consumed here: its
      // text is collected when the post streaming event fires.
      if ($normalized instanceof $streamed_interface) {
        return $data;
      }

      if (\is_object($normalized)) {
        $message = $this->collectMessage($normalized);
        $data['text'] = $message['text'];
        $data['tools'] = $message['tools'];
      }
      elseif (\is_array($normalized)) {
        // Operations like embeddings return a plain array of values.
        $data['text'] = $this->t('@count values', ['@count' => \count($normalized)]);
      }
    }

    if (\method_exists($output, 'getTokenUsage')) {
      $data['token_usage'] = \array_filter(
        (array) $output->getTokenUsage(),
        static fn ($value) => $value !== NULL,
      );
    }

    if (\method_exists($output, 'getRateLimits')) {
      $limits = $output->getRateLimits();
      if (!\method_exists($limits, 'empty') || !$limits->empty()) {
        $data['rate_limits'] = \array_filter(
          (array) $limits,
          static fn ($value) => $value !== NULL,
        );
      }
    }

    if (\method_exists($output, 'getMetadata')) {
      $data['metadata'] = $this->snapshot($output->getMetadata());
    }

    if (\method_exists($output, 'getRawOutput')) {
      $raw = $output->getRawOutput();
      $data['raw'] = $this->snapshot($raw);
      $data['finish_reasons'] = $this->extractFinishReasons($raw);

      if (\is_array($raw) && isset($raw['model']) && $raw['model'] !== '') {
        $data['response_model'] = $raw['model'];
      }
    }

    return $data;
  }

  /**
   * Extracts the finish reasons from the raw output of a provider.
   *
   * Providers use different shapes: OpenAI compatible ones report a
   * finish_reason per choice, Anthropic compatible ones a single stop_reason.
   *
   * @param mixed $raw
   *   The raw output of the provider.
   *
   * @return array
   *   The finish reasons, empty when none can be determined.
   */
  private function extractFinishReasons(mixed $raw): array {
    if (!\is_array($raw)) {
      return [];
    }

    if (isset($raw['choices']) && \is_array($raw['choices']) && $raw['choices'] !== []) {
      $reasons = \array_values(
        \array_filter(
          \array_column($raw['choices'], 'finish_reason'),
          static fn ($reason) => $reason !== NULL && $reason !== '',
        ),
      );

      if ($reasons !== []) {
        return $reasons;
      }
    }

    if (isset($raw['stop_reason']) && $raw['stop_reason'] !== '') {
      return [$raw['stop_reason']];
    }

    return [];
  }

  /**
   * Extracts the results of the guardrails applied to a call.
   *
   * Results are stored by the guardrails event subscriber in the debug data of
   * the input, keyed by the mode the guardrail ran in.
   *
   * @param mixed $input
   *   The input of the call.
   *
   * @return array
   *   The collected guardrail results.
   */
  private function collectGuardrails(mixed $input): array {
    if (!\is_object($input) || !\method_exists($input, 'getAllGuardrailResults')) {
      return [];
    }

    $guardrails = [];
    foreach ($input->getAllGuardrailResults() as $mode => $results) {
      foreach ($results as $result) {
        $type = \explode('\\', \get_class($result));

        $guardrails[] = [
          'mode' => $mode,
          'label' => (string) $result->getGuardrailLabel(),
          'type' => \end($type),
          'message' => $result->getMessage(),
          'score' => \method_exists($result, 'getScore') ? $result->getScore() : NULL,
          'stop' => $result->stop(),
          'context' => $this->snapshot($result->getContext()),
        ];
      }
    }

    return $guardrails;
  }

  /**
   * Extracts the guardrail sets attached to the input of a call.
   *
   * The sets describe the guardrails the guardrails event subscriber is asked
   * to run. Comparing them with the recorded results tells which guardrails
   * ran, and which ones were skipped or ran without recording a result.
   *
   * @param mixed $input
   *   The input of the call.
   *
   * @return array
   *   The guardrail sets, each with its guardrails keyed by mode.
   */
  private function collectGuardrailSets(mixed $input): array {
    if (!\is_object($input) || !\method_exists($input, 'getGuardrailSets')) {
      return [];
    }

    $sets = [];
    foreach ($input->getGuardrailSets() as $set) {
      $collected = [
        'id' => (string) $set->id(),
        'label' => (string) $set->label(),
        'stop_threshold' => \method_exists($set, 'getStopThreshold') ? $set->getStopThreshold() : NULL,
        'pre' => [],
        'post' => [],
      ];

      // Loading the guardrails of a set instantiates their plugins, which a
      // broken configuration can make fail: the profile must survive it.
      foreach (['pre' => 'getPreGenerateGuardrails', 'post' => 'getPostGenerateGuardrails'] as $mode => $method) {
        try {
          $guardrails = \is_callable([$set, $method]) ? \call_user_func([$set, $method]) : [];
        }
        catch (\Throwable) {
          continue;
        }

        foreach ($guardrails as $guardrail) {
          if (!\is_object($guardrail)) {
            continue;
          }

          $collected[$mode][] = [
            'plugin_id' => \method_exists($guardrail, 'getPluginId') ? (string) $guardrail->getPluginId() : \get_class($guardrail),
            'label' => \method_exists($guardrail, 'label') ? (string) $guardrail->label() : \get_class($guardrail),
            'streamable' => \is_a($guardrail, self::STREAMABLE_GUARDRAIL_INTERFACE),
            'non_deterministic' => \is_a($guardrail, self::NON_DETERMINISTIC_GUARDRAIL_INTERFACE),
          ];
        }
      }

      $sets[] = $collected;
    }

    return $sets;
  }

  /**
   * Pairs the guardrails configured on a call with the results they recorded.
   *
   * The guardrails event subscriber runs the guardrails of every set in
   * configuration order and records one result per guardrail that ran, so the
   * results of a mode are consumed in order while walking the configured
   * guardrails of that mode. A configured guardrail without a result did not
   * run: it was skipped, or it scanned the stream without recording a result.
   * A result without a configured guardrail was recorded by third party code.
   *
   * @param array $call
   *   The collected call.
   *
   * @return array
   *   One entry per configured guardrail and per unmatched result, with the
   *   set, the guardrail, the mode, the status and the result when recorded.
   */
  private function pairGuardrails(array $call): array {
    $results = [];
    foreach ($call['guardrails'] as $result) {
      $results[$result['mode']][] = $result;
    }

    $entries = [];
    foreach (['pre', 'post'] as $mode) {
      $position = 0;

      foreach ($call['guardrail_sets'] ?? [] as $set) {
        foreach ($set[$mode] as $guardrail) {
          $result = $results[$mode][$position] ?? NULL;
          if ($result !== NULL && $result['label'] === $guardrail['label']) {
            $position++;
          }
          else {
            $result = NULL;
          }

          $entries[] = [
            'set' => $set['label'],
            'label' => $guardrail['label'],
            'plugin_id' => $guardrail['plugin_id'],
            'mode' => $mode,
            'status' => $result !== NULL
              ? $this->guardrailResultStatus($result)
              : $this->guardrailSkipStatus($guardrail, $mode, $call),
            'result' => $result,
          ];
        }
      }

      foreach (\array_slice($results[$mode] ?? [], $position) as $result) {
        $entries[] = [
          'set' => NULL,
          'label' => $result['label'],
          'plugin_id' => NULL,
          'mode' => $mode,
          'status' => $this->guardrailResultStatus($result),
          'result' => $result,
        ];
      }
    }

    return $entries;
  }

  /**
   * Derives the status of a guardrail from the result it recorded.
   *
   * @param array $result
   *   The collected result.
   *
   * @return string
   *   One of pass, stop, rewrite or other.
   */
  private function guardrailResultStatus(array $result): string {
    return match ($result['type']) {
      'PassResult' => 'pass',
      'StopResult' => 'stop',
      'RewriteInputResult', 'RewriteOutputResult' => 'rewrite',
      default => $result['stop'] ? 'stop' : 'other',
    };
  }

  /**
   * Derives the status of a configured guardrail that recorded no result.
   *
   * @param array $guardrail
   *   The configured guardrail.
   * @param string $mode
   *   The mode the guardrail is configured for.
   * @param array $call
   *   The collected call.
   *
   * @return string
   *   One of streamed, nested or skipped.
   */
  private function guardrailSkipStatus(array $guardrail, string $mode, array $call): string {
    if ($mode === 'post' && $call['streamed'] && $guardrail['streamable']) {
      return 'streamed';
    }

    if ($guardrail['non_deterministic'] && $call['parent_id'] !== NULL) {
      return 'nested';
    }

    return 'skipped';
  }

  /**
   * Adds the nesting depth to every call.
   *
   * A call made by an agent, or by a guardrail backed by a model, declares the
   * call it originates from as its parent.
   *
   * @param array $calls
   *   The collected calls.
   *
   * @return array
   *   The collected calls, with their depth.
   */
  private function addDepth(array $calls): array {
    foreach ($calls as $id => $call) {
      $depth = 0;
      $parent = $call['parent_id'];

      // Stop on unknown parents and on cycles, that a buggy provider could
      // introduce by reusing request thread ids.
      $seen = [$id => TRUE];
      while ($parent !== NULL && isset($calls[$parent]) && !isset($seen[$parent])) {
        $seen[$parent] = TRUE;
        $depth++;
        $parent = $calls[$parent]['parent_id'];
      }

      $calls[$id]['depth'] = $depth;
    }

    return $calls;
  }

  /**
   * Computes the request wide totals.
   *
   * @param array $calls
   *   The collected calls.
   *
   * @return array
   *   The request wide totals.
   */
  private function summarize(array $calls): array {
    $summary = [
      'errors' => 0,
      'duration' => 0,
      'tokens' => 0,
      'input_tokens' => 0,
      'output_tokens' => 0,
      'reasoning_tokens' => 0,
      'cached_tokens' => 0,
      'guardrails' => 0,
      'guardrails_configured' => 0,
      'guardrails_pass' => 0,
      'guardrails_stop' => 0,
      'tool_calls' => 0,
      'providers' => [],
      'models' => [],
      'operation_types' => [],
    ];

    foreach ($calls as $call) {
      if ($call['status'] === 'error') {
        $summary['errors']++;
      }

      $summary['duration'] += $call['duration'] ?? 0;

      $usage = $call['output']['token_usage'] ?? [];
      $summary['tokens'] += $usage['total'] ?? (($usage['input'] ?? 0) + ($usage['output'] ?? 0));
      $summary['input_tokens'] += $usage['input'] ?? 0;
      $summary['output_tokens'] += $usage['output'] ?? 0;
      $summary['reasoning_tokens'] += $usage['reasoning'] ?? 0;
      $summary['cached_tokens'] += $usage['cached'] ?? 0;

      $summary['guardrails'] += \count($call['guardrails']);
      $summary['guardrails_pass'] += \count(
        \array_filter($call['guardrails'], fn (array $guardrail) => $this->guardrailResultStatus($guardrail) === 'pass'),
      );
      $summary['guardrails_stop'] += \count(
        \array_filter($call['guardrails'], static fn (array $guardrail) => $guardrail['stop']),
      );
      foreach ($call['guardrail_sets'] ?? [] as $set) {
        $summary['guardrails_configured'] += \count($set['pre']) + \count($set['post']);
      }

      $summary['tool_calls'] += \count($call['output']['tools'] ?? []);

      $summary['providers'][$call['provider']] = ($summary['providers'][$call['provider']] ?? 0) + 1;
      $summary['models'][$call['model']] = ($summary['models'][$call['model']] ?? 0) + 1;
      $summary['operation_types'][$call['operation_type']] = ($summary['operation_types'][$call['operation_type']] ?? 0) + 1;
    }

    return $summary;
  }

  /**
   * Returns a value from the request wide totals.
   *
   * @param string $key
   *   The key of the value.
   * @param mixed $default
   *   The value to return when the totals are missing.
   *
   * @return mixed
   *   The value.
   */
  private function getSummaryValue(string $key, mixed $default): mixed {
    return $this->data['summary'][$key] ?? $default;
  }

  /**
   * Renders the request wide totals.
   *
   * @return array
   *   The render array of the totals.
   */
  private function renderMetrics(): array {
    $summary = $this->data['summary'] ?? [];

    $metrics = [
      (string) $this->t('Calls') => $this->getCallsCount(),
      (string) $this->t('Failed calls') => $this->getErrorsCount(),
      (string) $this->t('Total time') => $this->renderTime($this->getTotalDuration()),
      (string) $this->t('Total tokens') => $this->getTotalTokens(),
      (string) $this->t('Input tokens') => $this->getTotalInputTokens(),
      (string) $this->t('Output tokens') => $this->getTotalOutputTokens(),
      (string) $this->t('Reasoning tokens') => $summary['reasoning_tokens'] ?? 0,
      (string) $this->t('Cached tokens') => $summary['cached_tokens'] ?? 0,
      (string) $this->t('Guardrails configured') => $this->getGuardrailsConfiguredCount(),
      (string) $this->t('Guardrails that ran') => $this->getGuardrailsCount(),
      (string) $this->t('Guardrails that passed') => $this->getGuardrailsPassCount(),
      (string) $this->t('Guardrails asking to stop') => $this->getGuardrailsStopCount(),
      (string) $this->t('Tool calls') => $this->getToolCallsCount(),
    ];

    return [
      $this->renderTable($metrics, (string) $this->t('Totals'), static fn ($value) => $value),
      $this->renderTable(
        $this->getProviders(),
        (string) $this->t('Calls per provider'),
        static fn ($value) => $value,
      ),
      $this->renderTable(
        $this->getModels(),
        (string) $this->t('Calls per model'),
        static fn ($value) => $value,
      ),
      $this->renderTable(
        $summary['operation_types'] ?? [],
        (string) $this->t('Calls per operation type'),
        static fn ($value) => $value,
      ),
    ];
  }

  /**
   * Renders the list of calls.
   *
   * @return array
   *   The render array of the list of calls.
   */
  private function renderCalls(): array {
    $sections = [];

    foreach ($this->getCalls() as $call) {
      // Calls made on behalf of another call, by an agent or by a guardrail
      // backed by a model, are marked with their nesting level.
      $title = $this->t('@nesting#@index @provider / @model (@operation) @duration', [
        '@nesting' => \str_repeat('↳ ', $call['depth']),
        '@index' => $call['index'],
        '@provider' => $call['provider'],
        '@model' => $call['model'],
        '@operation' => $call['operation_type'],
        '@duration' => $call['duration'] !== NULL ? $this->renderTime($call['duration']) : '',
      ]);

      $rows = [
        (string) $this->t('Status') => $this->renderStatus($call),
        (string) $this->t('Provider') => $call['provider'],
        (string) $this->t('Model') => $call['model'],
        (string) $this->t('Operation type') => $call['operation_type'],
        (string) $this->t('Duration') => $call['duration'] !== NULL ? $this->renderTime($call['duration']) : $this->t('n/a'),
        (string) $this->t('Streamed') => $call['streamed'] ? $this->t('Yes') : $this->t('No'),
        (string) $this->t('Request thread id') => $call['id'],
        (string) $this->t('Tags') => \implode(', ', $call['tags']),
      ];

      if ($call['parent_id'] !== NULL) {
        $rows[(string) $this->t('Parent request thread id')] = $call['parent_id'];
      }

      if (isset($call['output']['response_model'])) {
        $rows[(string) $this->t('Model that answered')] = $call['output']['response_model'];
      }

      $finish_reasons = $call['output']['finish_reasons'] ?? [];
      if ($finish_reasons !== []) {
        $rows[(string) $this->t('Finish reasons')] = \implode(', ', $finish_reasons);
      }

      $sections[] = $this->renderTable($rows, (string) $title, static fn ($value) => $value);

      $usage = $call['output']['token_usage'] ?? [];
      if ($usage !== []) {
        $sections[] = $this->renderTable($usage, (string) $this->t('Token usage'), static fn ($value) => $value);
      }

      $limits = $call['output']['rate_limits'] ?? [];
      if ($limits !== []) {
        $sections[] = $this->renderTable($limits, (string) $this->t('Rate limits'), static fn ($value) => $value);
      }

      if ($call['error'] !== NULL) {
        $sections[] = $this->renderTable(
          [
            (string) $this->t('Exception') => $call['error']['class'],
            (string) $this->t('Message') => $call['error']['message'],
            (string) $this->t('Code') => $call['error']['code'],
            (string) $this->t('Thrown in') => $call['error']['file'] . ':' . $call['error']['line'],
          ],
          (string) $this->t('Error'),
          static fn ($value) => $value,
        );
      }

      $sections[] = $this->renderMessages($call['input']['messages'], (string) $this->t('Messages'));

      $input_rows = [];
      if (($call['input']['system_prompt'] ?? '') !== '') {
        $input_rows[(string) $this->t('System prompt')] = $call['input']['system_prompt'];
      }
      if ($input_rows !== []) {
        $sections[] = $this->renderTable($input_rows, (string) $this->t('Prompt'), static fn ($value) => $value);
      }

      $payloads = \array_filter(
        [
          (string) $this->t('Input') => $call['input']['raw'],
          (string) $this->t('Output') => $call['output']['raw'] ?? NULL,
          (string) $this->t('Output metadata') => $call['output']['metadata'] ?? NULL,
          (string) $this->t('Configuration') => $call['configuration'],
          (string) $this->t('Metadata') => $call['metadata'],
          (string) $this->t('Debug data') => $call['debug_data'],
        ],
        static fn ($value) => $value !== NULL,
      );

      $sections[] = $this->renderTable($payloads, (string) $this->t('Payloads'));
    }

    return $sections;
  }

  /**
   * Renders the label of the status of a call.
   *
   * @param array $call
   *   The call.
   *
   * @return string
   *   The label of the status.
   */
  private function renderStatus(array $call): string {
    return match ($call['status']) {
      'completed' => (string) $this->t('Completed'),
      'error' => (string) $this->t('Failed'),
      'recovered' => (string) $this->t('Failed, recovered by a subscriber'),
      'short_circuited' => (string) $this->t('Answered without calling the provider'),
      'unconsumed' => (string) $this->t('Streamed response never consumed'),
      default => (string) $this->t('Pending'),
    };
  }

  /**
   * Renders a list of chat messages.
   *
   * @param array $messages
   *   The messages to render.
   * @param string $label
   *   The list's label.
   *
   * @return array
   *   The render array of the list of messages.
   */
  private function renderMessages(array $messages, string $label): array {
    if ($messages === []) {
      return [];
    }

    $rows = [];
    foreach ($messages as $message) {
      $rows[] = [
        $message['role'],
        $message['text'],
        \implode(', ', $message['files']),
        [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '{{ data|raw }}',
            '#context' => [
              'data' => $this->dumpData($this->snapshot($message['tools'])),
            ],
          ],
        ],
      ];
    }

    return [
      '#theme' => 'webprofiler_dashboard_section',
      '#title' => $label,
      '#data' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Role'),
          $this->t('Text'),
          $this->t('Files'),
          $this->t('Tools'),
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

  /**
   * Renders the guardrails configured on the calls of the request.
   *
   * Every configured guardrail is listed, whether it passed, stopped or
   * rewrote the call, or never recorded a result.
   *
   * @return array
   *   The render array of the list of guardrails.
   */
  private function renderGuardrails(): array {
    $rows = [];
    foreach ($this->getCalls() as $call) {
      foreach ($this->pairGuardrails($call) as $entry) {
        $result = $entry['result'];

        $rows[] = [
          $call['index'],
          $entry['set'] ?? $this->t('n/a'),
          $entry['label'],
          $entry['plugin_id'] ?? '',
          $entry['mode'],
          $this->renderGuardrailStatus($entry['status']),
          $result['score'] ?? '',
          $result !== NULL ? $result['message'] : $this->renderGuardrailSkipReason($entry['status']),
          $result !== NULL ? [
            'data' => [
              '#type' => 'inline_template',
              '#template' => '{{ data|raw }}',
              '#context' => [
                'data' => $this->dumpData($result['context']),
              ],
            ],
          ] : '',
        ];
      }
    }

    if ($rows === []) {
      return [
        '#markup' => '<p>' . $this->t('No guardrail set is attached to the calls of this request') . '</p>',
      ];
    }

    return [
      '#theme' => 'webprofiler_dashboard_section',
      '#data' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Call'),
          $this->t('Set'),
          $this->t('Guardrail'),
          $this->t('Plugin'),
          $this->t('Mode'),
          $this->t('Status'),
          $this->t('Score'),
          $this->t('Message'),
          $this->t('Context'),
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

  /**
   * Renders the status of a guardrail.
   *
   * @param string $status
   *   The status computed by ::pairGuardrails().
   *
   * @return string
   *   The human readable status.
   */
  private function renderGuardrailStatus(string $status): string {
    return match ($status) {
      'pass' => (string) $this->t('Passed'),
      'stop' => (string) $this->t('Asked to stop'),
      'rewrite' => (string) $this->t('Rewrote the call'),
      'streamed' => (string) $this->t('Scanned the stream'),
      'nested' => (string) $this->t('Skipped'),
      'skipped' => (string) $this->t('Skipped'),
      default => (string) $this->t('Ran'),
    };
  }

  /**
   * Explains why a configured guardrail recorded no result.
   *
   * @param string $status
   *   The status computed by ::pairGuardrails().
   *
   * @return string
   *   The explanation.
   */
  private function renderGuardrailSkipReason(string $status): string {
    return match ($status) {
      'streamed' => (string) $this->t('Streaming guardrails run inside the streamed response iterator and record no result'),
      'nested' => (string) $this->t('Model backed guardrails do not run on calls made by another model backed guardrail'),
      default => (string) $this->t('Did not run: an earlier guardrail stopped the call, or the guardrail was skipped by the guardrails event subscriber'),
    };
  }

  /**
   * Renders the tools offered to the providers and the tools they asked for.
   *
   * @return array
   *   The render array of the list of tools.
   */
  private function renderTools(): array {
    $offered = [];
    $called = [];

    foreach ($this->getCalls() as $call) {
      foreach ($call['input']['tools'] as $tool) {
        $offered[] = [
          $call['index'],
          $tool['function']['name'] ?? '',
          $tool['function']['description'] ?? '',
          [
            'data' => [
              '#type' => 'inline_template',
              '#template' => '{{ data|raw }}',
              '#context' => [
                'data' => $this->dumpData($this->snapshot($tool['function']['parameters'] ?? [])),
              ],
            ],
          ],
        ];
      }

      foreach ($call['output']['tools'] ?? [] as $tool) {
        $called[] = [
          $call['index'],
          $tool['function']['name'] ?? '',
          $tool['id'] ?? '',
          $tool['function']['arguments'] ?? '',
        ];
      }
    }

    $build = [];

    $build[] = $offered === []
      ? ['#markup' => '<p>' . $this->t('No tools offered to the providers') . '</p>']
      : [
        '#theme' => 'webprofiler_dashboard_section',
        '#title' => $this->t('Offered tools'),
        '#data' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Call'),
            $this->t('Name'),
            $this->t('Description'),
            $this->t('Parameters'),
          ],
          '#rows' => $offered,
          '#attributes' => ['class' => ['webprofiler__table']],
          '#sticky' => TRUE,
        ],
      ];

    $build[] = $called === []
      ? ['#markup' => '<p>' . $this->t('No tools requested by the providers') . '</p>']
      : [
        '#theme' => 'webprofiler_dashboard_section',
        '#title' => $this->t('Requested tools'),
        '#data' => [
          '#type' => 'table',
          '#header' => [
            $this->t('Call'),
            $this->t('Name'),
            $this->t('Id'),
            $this->t('Arguments'),
          ],
          '#rows' => $called,
          '#attributes' => ['class' => ['webprofiler__table']],
          '#sticky' => TRUE,
        ],
      ];

    return $build;
  }

}
