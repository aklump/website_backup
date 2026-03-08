<?php

namespace AKlump\WebsiteBackup\Traits;

use AKlump\WebsiteBackup\Service\BackupOptions;
use Symfony\Component\Console\Input\InputInterface;

trait CommandTrait {

  public function getCommonBackupOptions(INputInterface $input): int {
    $options = 0;
    if (!$input->getOption('database') && !$input->getOption('files')) {
      $options |= BackupOptions::DATABASE;
      $options |= BackupOptions::FILES;
    }
    else {
      if ($input->getOption('database')) {
        $options |= BackupOptions::DATABASE;
      }
      if ($input->getOption('files')) {
        $options |= BackupOptions::FILES;
      }
    }
    if ($input->getOption('notify')) {
      $options |= BackupOptions::NOTIFY;
    }

    return $options;
  }
}
