<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\webprofiler\Profiler\Profiler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Hook implementations for webprofiler.
 */
class WebprofilerHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'webprofiler.profiler')]
    private readonly Profiler $profiler,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'webprofiler_toolbar_js' => [
        'template' => 'Profiler/toolbar_js',
        'variables' => [
          'excluded_ajax_paths' => '^\/.*\/profiler\/[a-z0-9]*',
          'token' => '',
          'request' => NULL,
          'csp_script_nonce' => '',
          'csp_style_nonce' => '',
        ],
      ],
      'webprofiler_toolbar' => [
        'template' => 'Profiler/toolbar',
        'variables' => [
          'request' => NULL,
          'profile' => NULL,
          'templates' => '',
          'profiler_url' => '',
          'token' => '',
          'csp_script_nonce' => '',
          'csp_style_nonce' => '',
        ],
      ],
      'webprofiler_toolbar_redirect' => [
        'template' => 'Profiler/toolbar_redirect',
        'variables' => [
          'location' => '',
        ],
      ],
      'webprofiler_dashboard' => [
        'template' => 'Profiler/dashboard',
        'variables' => [
          'collectors' => [],
          'token' => '',
          'profile' => NULL,
        ],
      ],
      'webprofiler_dashboard_panel' => [
        'template' => 'Profiler/dashboard_panel',
        'variables' => [
          'name' => '',
          'template' => '',
          'profile' => NULL,
        ],
      ],
      'webprofiler_dashboard_section' => [
        'template' => 'Profiler/dashboard_section',
        'variables' => [
          'title' => '',
          'data' => [],
        ],
      ],
      'webprofiler_dashboard_tabs' => [
        'template' => 'Profiler/dashboard_tabs',
        'variables' => [
          'tabs' => [],
        ],
      ],
      'webprofiler_dashboard_frontend' => [
        'template' => 'Profiler/frontend',
        'variables' => [
          'performance' => [],
          'cwv' => [],
        ],
      ],
      'webprofiler_dashboard_libraries' => [
        'template' => 'Profiler/libraries',
        'variables' => [
          'libraries' => [],
        ],
      ],
      'webprofiler_dashboard_components' => [
        'template' => 'Profiler/components',
        'variables' => [
          'components' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_cache_flush().
   */
  #[Hook('cache_flush')]
  public function cacheFlush(): void {
    $config = $this->configFactory->get('webprofiler.settings');
    $purge_on_cache_clear = (bool) $config->get('purge_on_cache_clear');
    if ($purge_on_cache_clear) {
      $this->profiler->purge();
    }
  }

}
