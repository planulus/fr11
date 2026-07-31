<?php

declare(strict_types=1);

namespace Drupal\ai\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validates a structured output schema.
 *
 * @Constraint(
 *   id = "ValidStructuredOutputSchema",
 *   label = @Translation("Valid Structured Output Schema", context = "Validation"),
 *   type = { "array" }
 * )
 */
class ValidStructuredOutputSchema extends Constraint {

  /**
   * The validation message.
   *
   * @var string
   */
  public string $message = 'Invalid structured output schema: @errors';

  /**
   * Whether to check AI provider-specific constraints.
   *
   * @var bool
   */
  public bool $checkAiConstraints = TRUE;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    mixed $options = NULL,
    ?string $message = NULL,
    ?bool $checkAiConstraints = NULL,
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct($options, $groups, $payload);
    $this->message = $message ?? $this->message;
    $this->checkAiConstraints = $checkAiConstraints ?? $this->checkAiConstraints;
  }

}
