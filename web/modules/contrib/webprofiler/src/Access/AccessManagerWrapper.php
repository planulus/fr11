<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Access;

use Drupal\Component\Utility\ArgumentsResolverInterface;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Access\AccessManager;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\webprofiler\DataCollector\RequestDataCollector;

/**
 * Attaches access check services to routes and runs them on request.
 */
class AccessManagerWrapper extends AccessManager {

  /**
   * The Request data collector.
   *
   * @var \Drupal\webprofiler\DataCollector\RequestDataCollector
   */
  private RequestDataCollector $dataCollector;

  /**
   * {@inheritdoc}
   */
  protected function performCheck(
    $service_id,
    ArgumentsResolverInterface $arguments_resolver,
  ): AccessResultInterface {
    $callable = $this->checkProvider->loadCheck($service_id);
    $arguments = $arguments_resolver->getArguments($callable);

    // Convert scalar arguments to match parameter type hints for
    // PHP 8+ compatibility.
    $arguments = $this->convertScalarArguments($callable, $arguments);

    $service_access = \call_user_func_array($callable, $arguments);

    if (!$service_access instanceof AccessResultInterface) {
      throw new AccessException("Access error in $service_id. Access services must return an object that implements AccessResultInterface.");
    }

    $this->dataCollector->addAccessCheck($service_id, $callable);

    return $service_access;
  }

  /**
   * Set the data collector.
   *
   * @param \Drupal\webprofiler\DataCollector\RequestDataCollector $dataCollector
   *   The data collector to set.
   */
  public function setDataCollector(RequestDataCollector $dataCollector): void {
    $this->dataCollector = $dataCollector;
  }

  /**
   * Converts scalar arguments to match parameter type hints.
   *
   * Route parameters are always strings, but access check methods may have
   * scalar type hints (int, float, bool). This method converts arguments
   * to match the expected types to prevent TypeErrors in PHP 8+.
   *
   * @param callable $callable
   *   The callable to invoke.
   * @param array<int, mixed> $arguments
   *   The arguments to pass to the callable.
   *
   * @return array<int, mixed>
   *   The converted arguments.
   */
  protected function convertScalarArguments(callable $callable, array $arguments): array {
    // Get reflection of the callable.
    if (\is_array($callable)) {
      $reflection = new \ReflectionMethod($callable[0], $callable[1]);
    }
    elseif (\is_string($callable) && \str_contains($callable, "::")) {
      $reflection = \ReflectionMethod::createFromMethodName($callable);
    }
    else {
      $reflection = new \ReflectionFunction($callable);
    }

    $parameters = $reflection->getParameters();
    $converted_arguments = [];

    foreach ($arguments as $index => $argument) {
      // If we don't have parameter info for this argument, keep it as is.
      if (!isset($parameters[$index])) {
        $converted_arguments[] = $argument;
        continue;
      }

      $parameter = $parameters[$index];
      $type = $parameter->getType();

      // Only convert if we have a type hint and it's a builtin scalar type.
      // Note: Union types (e.g., int|null) are intentionally not handled here
      // as they are rare in access checks and would require more complex logic.
      if ($type instanceof \ReflectionNamedType && $type->isBuiltin() && \is_scalar($argument)) {
        $type_name = $type->getName();

        // Convert based on the type hint.
        $converted_arguments[] = match ($type_name) {
          'int' => (int) $argument,
          'float' => (float) $argument,
          'bool' => (bool) $argument,
          'string' => (string) $argument,
          default => $argument,
        };
      }
      else {
        // Keep the argument as is if it's not a scalar type hint.
        $converted_arguments[] = $argument;
      }
    }

    return $converted_arguments;
  }

}
