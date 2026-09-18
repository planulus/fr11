<?php

declare(strict_types=1);

namespace Drupal\webprofiler_service_closure_test;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A HTTP middleware that receives a service closure argument.
 *
 * Mirrors the ban module middleware from the report of issue #3579658, where
 * the inner kernel is prepended by the StackedKernelPass and the config
 * factory is injected as a service closure.
 */
class ServiceClosureMiddleware implements HttpKernelInterface {

  public function __construct(
    protected readonly HttpKernelInterface $httpKernel,
    public readonly ?\Closure $configFactoryClosure = NULL,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    return $this->httpKernel->handle($request, $type, $catch);
  }

}
