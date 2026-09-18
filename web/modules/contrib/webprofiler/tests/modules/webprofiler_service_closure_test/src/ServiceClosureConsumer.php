<?php

declare(strict_types=1);

namespace Drupal\webprofiler_service_closure_test;

/**
 * Consumes services injected as service closures.
 */
class ServiceClosureConsumer {

  public function __construct(
    public readonly \Closure $configFactoryClosure,
    public readonly \Closure $stateClosure,
  ) {
  }

}
