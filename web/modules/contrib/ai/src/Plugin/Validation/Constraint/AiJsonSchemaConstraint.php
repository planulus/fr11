<?php

declare(strict_types=1);

namespace Drupal\ai\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validates against a JSON Schema.
 *
 * @Constraint(
 *   id = "AiValidator",
 *   label = @Translation("AI Validator", context = "Validation"),
 *   type = { "string" }
 * )
 */
class AiJsonSchemaConstraint extends Constraint {

  /**
   * The validation message.
   *
   * @var string
   */
  public string $message = 'The content does not match the required schema: @error';

  /**
   * The schema to validate against.
   *
   * @var string|array|object
   */
  public string|array|object $schema;

}
