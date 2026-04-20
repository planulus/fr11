<?php

declare(strict_types=1);

namespace Drupal\webprofiler\Drush\Commands;

use Drupal\webprofiler\Profiler\Profiler;
use Drupal\webprofiler\Twig\Extension\DatabaseExtension;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for exporting database data.
 */
final class ExportDatabaseDataCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs an ExportDatabaseDataCommands object.
   */
  public function __construct(
    #[Autowire('webprofiler.profiler')]
    private readonly Profiler $profiler,
    #[Autowire('webprofiler.twig.database_extension')]
    private readonly DatabaseExtension $databaseExtension,
  ) {
    parent::__construct();
  }

  /**
   * Exports database data as a CSV file.
   */
  #[CLI\Command(name: 'webprofiler:export-database-data', aliases: ['wedd'])]
  #[CLI\Argument(name: 'token', description: 'Profile token.')]
  #[CLI\Argument(name: 'folder', description: 'Folder to save the file.')]
  #[CLI\Usage(name: 'webprofiler:export-database-data 2bf680 /var/www/html', description: 'Exports database data for the profile with token 2bf680 to the /var/www/html folder.')]
  public function exportDatabaseData(string $token, string $folder): void {
    // Check if the folder exists and is writable.
    if (!\is_dir($folder) || !\is_writable($folder)) {
      $this->logger()->error(\dt('The folder is not writable.'));

      return;
    }

    $profile = $this->profiler->loadProfile($token);
    if ($profile === NULL) {
      $this->logger()->error(\dt('Profile not found.'));

      return;
    }

    try {
      /** @var \Drupal\webprofiler\DataCollector\DatabaseDataCollector $collector */
      $collector = $profile->getCollector('database');

      $data = $collector->getQueries();
      $filename = $folder . '/database_data_' . $token . '.csv';
      $file = \fopen($filename, 'w');
      \fputcsv($file, [
        'Query',
        'Database',
        'Target',
        'Time',
        'Caller class',
        'Caller function',
      ], "\t");

      foreach ($data as $row) {
        \fputcsv($file, [
          $this->databaseExtension->queryExecutable(['query' => $row['query'], 'args' => $row['args']]),
          $row['database'],
          $row['target'],
          $row['time'],
          $row['caller']['class'],
          $row['caller']['function'],
        ], "\t");
      }
      \fclose($file);
    }
    catch (\Exception $e) {
      $this->logger()->error(
        \dt('An error occurred while exporting database data.'),
      );

      return;
    }

    $this->logger()->success(\dt('Database data exported to @filename', [
      '@filename' => $filename,
    ]));
  }

}
