# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Releases are published on [drupal.org](https://www.drupal.org/project/webprofiler/releases).
The `11.x` and `10.x` branches are maintained in parallel: `11.x` targets Drupal 11,
`10.x` targets Drupal 10.

## [Unreleased]

## [11.2.3] - 2026-09-09

### Changed

- The Guardrails tab of the AI collector lists every guardrail configured on a
  call, with its set, plugin and status, including guardrails that passed and
  guardrails that never recorded a result.

### Fixed

- The AI collector no longer reports that no guardrails ran when guardrails ran
  and passed: the empty state now only appears when no guardrail set is
  attached to the calls of the request.

## [11.2.2] - 2026-08-14

### Added

- AI provider call collector.

### Fixed

- Decorators no longer drift silently from core service definitions.
- Placeholder sending is delegated to core BigPipe.
- `ajax_page_state` is set on request attributes.
- Chatbot toolbar offset is scoped by container class.

## [11.2.1] - 2026-08-11

### Fixed

- Automated Drupal 12 compatibility fixes now pass CI.
- Project Update Bot fixes from run 12-884047.

## [11.2.0] - 2026-08-10

### Added

- Symfony Messenger collector.
- Permissions collector.
- Panel for time and memory collectors.
- Timestamp and context are printed in the log collector pane.

### Fixed

- `EntityTypeManagerWrapper` no longer breaks compatibility with other modules.
- AI assistant chat placement when the WebProfiler toolbar is visible.
- Serialization of `Closure` on kernel terminate.
- Non-attachment responses are guarded in the attachments processor.
- `TypeError` in `SimplePassReset`.
- The dashboard method accepts a profiler token directly and the limit is read from the report controller.

### Changed

- `symfony/stopwatch` `^8` is allowed.

## [11.1.1] - 2025-05-27

### Fixed

- PHP 8.4 nullable type warnings.

## [11.1.0] - 2024-12-31

### Added

- Compatibility with Drupal 11.1.
- Support for runtime loaders in Twig 3.9.

### Fixed

- `webprofiler:export-database-data` command.
- Log context handling.

## [11.0.0] - 2024-08-12

### Added

- Drupal 11 support.

## [10.3.1] - 2025-05-27

### Fixed

- PHP 8.4 nullable type warnings.

## [10.3.0] - 2024-12-31

### Added

- Features backported from the Drupal 11 branch.
- Support for runtime loaders in Twig 3.9.

## [10.1.10] - 2024-08-12

### Changed

- The branch supports Drupal 10 only.

## [10.1.9] - 2024-08-12

### Changed

- Dependencies updated.
- `renderInIsolation` replaces the deprecated `renderPlain`.

## [10.1.8] - 2024-05-06

### Changed

- `html_response.attachments_processor` is decorated instead of replaced.
- Generated decorators live in the original namespace.
- Stale decorators are cleared.

### Fixed

- Decorator generator return type hints.

## [10.1.7] - 2024-04-11

### Fixed

- `ServiceCircularReferenceException` after updating to 10.1.5.

## [10.1.6] - 2024-04-11

### Added

- Support for nullable, variadic and by-reference parameters in `ConfigEntityStorageDecoratorGenerator`.

### Fixed

- PHP fatal error when running `drush cr`.
- Theme data collector issue and a performance issue on sites with many routes.
- Link formatter.

### Changed

- Config entity storage decorator generation improved.
- `previousnext/coding-standard` bumped to 1.0.

## [10.1.5] - 2024-02-01

### Added

- Configuration for `ddev-drupal-contrib`.

### Changed

- Improved Drupal 10.2 support.
- Core and `nikic/php-parser` version constraints fixed.

## [10.1.4] - 2024-01-23

### Added

- Support for Drupal 10.2 and later.

## [10.1.3] - 2023-11-07

### Changed

- Drupal version compatibility is enforced.

## [10.1.2] - 2023-11-06

### Changed

- Database events are used to collect queries.
- More data is collected by the controller data collector.
- `DependencySerializationTrait` rehydrates services after serialization.
- GitLab CI pipeline set up.

### Fixed

- Data collector serialization.

## [10.1.1] - 2023-09-06

### Added

- Single directory component (SDC) collector.
- Libraries collector.
- Option to disable exception pages.

### Fixed

- Potential XSS vulnerability in the `abbr_class` Twig filter.
- Response content type is checked before string operations.
- Enable icon rendered with zero width and height.
- Reports page.

## [10.1.0] - 2023-06-20

### Added

- Logs data collector.
- Exception data collector.
- Twig filters, functions and globals collector.
- Form ID and form class on the form panel.
- More theme data collected, theme panel split into tabs.

### Changed

- Updated to Symfony 6.2.
- `config` renamed to `configs` to bypass Symfony CSS.
- Backtrace removed from logs.

### Fixed

- Error during module uninstall.
- Error template.
- PHP 8.2 compatibility issues.
- Serialization errors when installing from configuration.
- Mini icon rendering.

## [10.0.0] - 2022-12-13

### Fixed

- Minitoolbar SVG not displayed in Firefox.

## [10.0.0-rc2] - 2022-11-28

### Fixed

- Data type mistake in the default configuration.

## [10.0.0-rc1] - 2022-11-24

### Added

- Views, theme, routing, state, translations, mail and frontend data collectors.
- BigPipe response data collection.
- Core Web Vitals in the toolbar.
- Report page.
- Support for the o11y module and new icons.
- WebProfiler classes on the `body` element.

### Changed

- `PerformanceTimingDataCollector` renamed to `FrontendDataCollector`.
- Performance timing data is sent with `navigator.sendBeacon`.
- Database panel JavaScript sped up.

### Fixed

- Loops in `EventsDataCollector`.
- Query sorting and query highlighting.

## [9.0.2] - 2022-10-07

### Fixed

- PHP 7.2 compatibility.

## [9.0.1] - 2022-10-07

### Added

- Invokable controller class support.

### Fixed

- The `spaceless` tag is removed.
- An empty array is returned when there are no translated or untranslated strings.
- The correct entity type manager is passed to `getFormObject`.
- `TraceableEventDispatcher::dispatch` matches the upstream signature.

## [9.0.0] - 2022-08-25

### Added

- WebProfiler split back out of Devel into its own project.
- User, HTTP, form, extensions and events data collectors.
- Toolbar item for Devel menu links.
- Decorators generated with `nette/php-generator`.
- The selected panel is opened automatically.

## Earlier releases

The `8.x-1.x` and `8.x-2.x` branches predate the current codebase, which was
maintained inside Devel before returning to its own project. Release notes are
available on drupal.org.

- `8.x-2.0-rc3` - 2015-11-02
- `8.x-2.0-rc2` - 2015-10-29
- `8.x-2.0-rc1` - 2015-10-20
- `8.x-2.0-beta16` - 2015-10-05
- `8.x-2.0-beta15` - 2015-09-19
- `8.x-1.1-beta15` - 2015-08-23
- `8.x-1.1-beta13` - 2015-08-03
- `8.x-1.1-beta12` - 2015-06-23
- `8.x-1.1-beta11` - 2015-05-27
- `8.x-1.1-beta10` - 2015-04-17
- `8.x-1.1-beta9` - 2015-03-26
- `8.x-1.1-beta7` - 2015-02-26
- `8.x-1.1-beta6` - 2015-01-28
- `8.x-1.1-beta4` - 2014-12-17
- `8.x-1.1-beta3` - 2014-11-07
- `8.x-1.1-beta2` - 2014-10-13
- `8.x-1.1-beta1` - 2014-09-30

[Unreleased]: https://git.drupalcode.org/project/webprofiler/-/compare/11.2.3...11.0.x
[11.2.3]: https://git.drupalcode.org/project/webprofiler/-/compare/11.2.2...11.2.3
[11.2.2]: https://git.drupalcode.org/project/webprofiler/-/compare/11.2.1...11.2.2
[11.2.1]: https://git.drupalcode.org/project/webprofiler/-/compare/11.2.0...11.2.1
[11.2.0]: https://git.drupalcode.org/project/webprofiler/-/compare/11.1.1...11.2.0
[11.1.1]: https://git.drupalcode.org/project/webprofiler/-/compare/11.1.0...11.1.1
[11.1.0]: https://git.drupalcode.org/project/webprofiler/-/compare/11.0.0...11.1.0
[11.0.0]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.10...11.0.0
[10.3.1]: https://git.drupalcode.org/project/webprofiler/-/compare/10.3.0...10.3.1
[10.3.0]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.10...10.3.0
[10.1.10]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.9...10.1.10
[10.1.9]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.8...10.1.9
[10.1.8]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.7...10.1.8
[10.1.7]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.6...10.1.7
[10.1.6]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.5...10.1.6
[10.1.5]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.4...10.1.5
[10.1.4]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.3...10.1.4
[10.1.3]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.2...10.1.3
[10.1.2]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.1...10.1.2
[10.1.1]: https://git.drupalcode.org/project/webprofiler/-/compare/10.1.0...10.1.1
[10.1.0]: https://git.drupalcode.org/project/webprofiler/-/compare/10.0.0...10.1.0
[10.0.0]: https://git.drupalcode.org/project/webprofiler/-/compare/10.0.0-rc2...10.0.0
[10.0.0-rc2]: https://git.drupalcode.org/project/webprofiler/-/compare/10.0.0-rc1...10.0.0-rc2
[10.0.0-rc1]: https://git.drupalcode.org/project/webprofiler/-/compare/9.0.2...10.0.0-rc1
[9.0.2]: https://git.drupalcode.org/project/webprofiler/-/compare/9.0.1...9.0.2
[9.0.1]: https://git.drupalcode.org/project/webprofiler/-/compare/9.0.0...9.0.1
[9.0.0]: https://git.drupalcode.org/project/webprofiler/-/tags/9.0.0
