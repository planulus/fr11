<?php

declare(strict_types=1);

namespace Drupal\tracer;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Defines a service provider for the Tracer module.
 */
class TracerServiceProvider extends ServiceProviderBase {

  /**
   * Alter the container to replace services.
   */
  public function alter(ContainerBuilder $container): void {
    // Decorate the event dispatcher service with a traceable one.
    $container->setDefinition(
      'tracer.debug.event_dispatcher',
      new Definition('Drupal\tracer\EventDispatcher\TraceableEventDispatcher'),
    )
      ->setDecoratedService('event_dispatcher')
      ->setArguments([
        new Reference('tracer.debug.event_dispatcher.inner'),
      ])
      ->setPublic(FALSE);

    // Replace the controller resolver service with a traceable one.
    $container->getDefinition('http_kernel.basic')
      ->replaceArgument(1, new Reference('tracer.debug.controller_resolver'));
  }

}
