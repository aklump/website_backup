<?php

namespace App\Service;

class DatabaseDumper {

  private $processRunner;

  public function __construct(ProcessRunner $processRunner) {
    $this->processRunner = $processRunner;
  }

  public function dump(array $db_config, string $output_path, array $cache_tables = []): void {
    $host = $db_config['host'] ?? '';
    $user = $db_config['user'] ?? '';
    $password = $db_config['password'] ?? '';
    $name = $db_config['name'] ?? '';
    $port = $db_config['port'] ?? '';

    $mysqldump_bin = 'mysqldump'; // Could be made configurable

    $common_args = [
      $mysqldump_bin,
      '--host=' . $host,
      '--user=' . $user,
      '--password=' . $password,
    ];
    if ($port) {
      $common_args[] = '--port=' . $port;
    }

    if (empty($cache_tables)) {
      $args = array_merge($common_args, [
        $name,
        '--result-file=' . $output_path,
      ]);
      $process = $this->processRunner->run($args);
      if (!$process->isSuccessful()) {
        throw new \RuntimeException('mysqldump failed: ' . $process->getErrorOutput());
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
        throw new \RuntimeException('mysqldump structure export failed: ' . $process->getErrorOutput());
      }

      // 2. Export data for non-cache tables
      // Need to find which tables are NOT cache tables.
      $tables = $this->getNonCacheTables($common_args, $name, $cache_tables);
      if (!empty($tables)) {
        $args = array_merge($common_args, ['--no-create-info', $name], $tables);
        // We need to append to the file. mysqldump doesn't have an append option for result-file easily that I recall,
        // so we use shell redirection or a temporary file.
        // Using Symfony Process and redirecting output manually or using a shell.
        // Actually, we can just run it and append output to file in PHP.

        $process = $this->processRunner->run($args);
        if (!$process->isSuccessful()) {
          throw new \RuntimeException('mysqldump data export failed: ' . $process->getErrorOutput());
        }
        file_put_contents($output_path, $process->getOutput(), FILE_APPEND);
      }
    }
  }

  private function getNonCacheTables(array $common_args, string $db_name, array $cache_tables): array {
    $mysql_bin = 'mysql'; // Could be made configurable
    $args = array_merge(
      array_slice($common_args, 1, 3), // --host, --user, --password
      isset($common_args[4]) ? [$common_args[4]] : [], // --port
      [
        $db_name,
        '-s',
        '-N',
        '-e',
        $this->buildTableQuery($db_name, $cache_tables),
      ]
    );
    array_unshift($args, $mysql_bin);

    $process = $this->processRunner->run($args);
    if (!$process->isSuccessful()) {
      throw new \RuntimeException('Failed to list tables: ' . $process->getErrorOutput());
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
