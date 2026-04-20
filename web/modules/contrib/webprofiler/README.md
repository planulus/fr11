[[_TOC_]]

## Introduction

WebProfiler module extract, collect, store, and display profiling information for Drupal.

WebProfiler creates a profile file for every request containing all the collected information.
This information is then rendered on a toolbar on every HTML response and a dedicated back office dashboard.

WebProfiler replaces a lot of Drupal subsystems to collect profiling information, which can lead to some
performance issues. For this reason, WebProfiler must not be used in production.

## Installation

WebProfiler can be downloaded and installed like any other Drupal module.

## Collect time metrics

To enable the collection of time metrics, you need to add this line to the `settings.php` file:

```php
$settings['tracer_plugin'] = \Drupal\webprofiler\Plugin\Tracer\StopwatchTracer::class;
```

A better solution to trace Drupal internals is to use the `tracer` plugin to send
data to an external trace database like [Grafana Tempo](https://grafana.com/oss/tempo/). You can
find more information on [this](https://www.youtube.com/watch?v=6UKIbbbflAs) YouTube video.

## Configuration

After enabling the module, only some widgets are displayed; you can enable all the others on the
WebProfiler settings page (`/admin/config/development/devel/webprofiler`).

## Disable custom error handler

WebProfiler uses a custom error handler to collect errors and exceptions like Symfony does. You may
need to deactivate it if you are using a custom error handler in your project or if you are using a
module that does it (like [Ignition Error Pages](https://www.drupal.org/project/ignition)).

To deactivate the custom error handler, you need to add this line to the `settings.php` file:

```php
$settings['webprofiler_error_page_disabled'] = TRUE;
```

Remember to clear the cache.

## Choose a different folder to store profiles

Profiles are stored in the `profiler` folder under the public's files directory. You can change this folder by setting
the `webprofiler.file_profiler_storage_dns` parameter in a services file, like:

```yml
parameters:
  webprofiler.file_profiler_storage_dns: 'file:/tmp/profiler'
```

## Export database queries

WebProfiler can export database queries to a file using a Drush command:

```bash
drush webprofiler:export-database-data <token> <output_folder>
```

The CSV file generated is tab-separated and contains the following columns:

* Query: the SQL query.
* Database: the database connection name.
* Target: the target of the query.
* Time: the time spent executing the query.
* Caller class: the class that called the query.
* Caller function: the function that called the query.
