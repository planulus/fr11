<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Compiler;

use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\webprofiler\DataCollector\TemplateAwareDataCollectorInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Register data collectors services.
 */
class ProfilerPass implements CompilerPassInterface {

  /**
   * {@inheritDoc}
   */
  public function process(ContainerBuilder $container): void {
    if (FALSE === $container->hasDefinition('webprofiler.profiler')) {
      return;
    }

    $definition = $container->getDefinition('webprofiler.profiler');

    $collectors = new \SplPriorityQueue();
    $order = PHP_INT_MAX;
    foreach ($container->findTaggedServiceIds('data_collector', TRUE) as $id => $attributes) {
      $priority = $attributes[0]['priority'] ?? 0;
      $template = NULL;

      $collector_class = $container->findDefinition($id)->getClass();
      $is_template_aware = \is_subclass_of($collector_class, TemplateAwareDataCollectorInterface::class);
      if (isset($attributes[0]['template']) || $is_template_aware) {
        $id_for_template = $attributes[0]['id'] ?? $collector_class;
        if (!$id_for_template) {
          throw new InvalidArgumentException(\sprintf('Data collector service "%s" must have an id attribute in order to specify a template.', $id));
        }
        if (!isset($attributes[0]['label'])) {
          throw new InvalidArgumentException(\sprintf('Data collector service "%s" must have a label attribute', $id));
        }
        $template =
          [
            $id_for_template,
            $attributes[0]['template'] ?? $collector_class::getTemplate(),
            $attributes[0]['label'] ?? '',
          ];
      }

      $collectors->insert([$id, $template], [$priority, --$order]);
    }

    $templates = [];
    foreach ($collectors as $collector) {
      $definition->addMethodCall('add', [new Reference($collector[0])]);
      $templates[$collector[0]] = $collector[1];
    }

    $container->setParameter('webprofiler.templates', $templates);

    // Set a parameter with the storage dns.
    if ($container->hasParameter('webprofiler.file_profiler_storage_dns')) {
      $path = $container->getParameter('webprofiler.file_profiler_storage_dns');
    }
    else {
      // Fall back to the public:// directory.
      $path = 'file:' . DRUPAL_ROOT . '/' . PublicStream::basePath() . '/profiler';
    }
    $container->setParameter('webprofiler.file_profiler_storage_dns', $path);
  }

}
