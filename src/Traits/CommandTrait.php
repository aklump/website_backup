<?php

namespace AKlump\WebsiteBackup\Traits;

use AKlump\WebsiteBackup\Service\BackupOptions;
use Symfony\Component\Console\Input\InputInterface;

trait CommandTrait {

  public function getCommonBackupOptions(InputInterface $input): int {
    $options = 0;
    $database = $input->getOption('database');
    $files = $input->getOption('files');

    if ($database) {
      $options |= BackupOptions::DATABASE;
    }
    if ($files) {
      $options |= BackupOptions::FILES;
    }
    if (!$database && !$files) {
      // If neither is specified, it's a full backup.
      $options |= BackupOptions::DATABASE;
      $options |= BackupOptions::FILES;
    }
    if ($input->getOption('notify')) {
      $options |= BackupOptions::NOTIFY;
    }

    return $options;
  }
}
