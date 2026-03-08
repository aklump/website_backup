<?php

namespace App\Service;

class DatabaseDumper {

  private $processRunner;

  private $tempFileFactory;

  private $createMysqlTempConfig;

  public $temp_dir;

  public function __construct(ProcessRunner $processRunner) {
    $this->processRunner = $processRunner;
    $this->tempFileFactory = new TemporaryFileFactory();
    $this->createMysqlTempConfig = new \App\Helper\CreateMysqlTempConfig();
  }

  public function dump(array $db_config, string $output_path, array $cache_tables = []): void {
    $name = $db_config['name'] ?? '';
    $mysqldump_bin = 'mysqldump'; // Could be made configurable

    $temp_config = ($this->createMysqlTempConfig)($db_config, $this->temp_dir);
    try {
      $common_args = [
        $mysqldump_bin,
        '--defaults-extra-file=' . $temp_config,
      ];

      if (empty($cache_tables)) {
        $args = array_merge($common_args, [
          $name,
          '--result-file=' . $output_path,
        ]);
        $process = $this->processRunner->run($args);
        if (!$process->isSuccessful()) {
          throw new \RuntimeException('mysqldump failed: ' . $this->processRunner->redact($process->getErrorOutput()));
        }
      }
      else {
        // 1. Export structure only for all tables
        $args = array_merge($common_args, [
          '--no-data',
          $name,
          '--result-file=' . $output_path,
        ]);
        $process = $this->processRunner->run($args);
        if (!$process->isSuccessful()) {
          throw new \RuntimeException('mysqldump structure export failed: ' . $this->processRunner->redact($process->getErrorOutput()));
        }

        // 2. Export data for non-cache tables
        // Need to find which tables are NOT cache tables.
        $tables = $this->getNonCacheTables($temp_config, $name, $cache_tables);
        if (!empty($tables)) {
          $args = array_merge($common_args, [
            '--no-create-info',
            $name,
          ], $tables);
          // We need to append to the file. mysqldump doesn't have an append option for result-file easily that I recall,
          // so we use shell redirection or a temporary file.
          // Using Symfony Process and redirecting output manually or using a shell.
          // Actually, we can just run it and append output to file in PHP.

          $process = $this->processRunner->run($args);
          if (!$process->isSuccessful()) {
            throw new \RuntimeException('mysqldump data export failed: ' . $this->processRunner->redact($process->getErrorOutput()));
          }
          file_put_contents($output_path, $process->getOutput(), FILE_APPEND);
        }
      }
    }
    finally {
      $this->tempFileFactory->cleanup($temp_config);
    }
  }

  private function getNonCacheTables(string $temp_config, string $db_name, array $cache_tables): array {
    $mysql_bin = 'mysql'; // Could be made configurable
    $args = [
      $mysql_bin,
      '--defaults-extra-file=' . $temp_config,
      $db_name,
      '-s',
      '-N',
      '-e',
      $this->buildTableQuery($db_name, $cache_tables),
    ];

    $process = $this->processRunner->run($args);
    if (!$process->isSuccessful()) {
      throw new \RuntimeException('Failed to list tables: ' . $this->processRunner->redact($process->getErrorOutput()));
    }

    return array_filter(explode("\n", trim($process->getOutput())));
  }

  private function buildTableQuery(string $db_name, array $cache_tables): string {
    $query = "SELECT table_name FROM information_schema.tables WHERE table_schema = '$db_name'";
    foreach ($cache_tables as $table) {
      $pattern = str_replace('*', '%', $table);
      $query .= " AND table_name NOT LIKE '$pattern'";
    }

    return $query;
  }
}
