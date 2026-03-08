<?php

namespace AKlump\WebsiteBackup\Traits;

use AKlump\WebsiteBackup\Service\BackupOptions;
use Symfony\Component\Console\Input\InputInterface;

trait CommandTrait {

  public function getCommonBackupOptions(InputInterface $input): int {
    $options = 0;
    if ($input->getOption('database')) {
      $options |= BackupOptions::DATABASE;
    }
    if ($input->getOption('files')) {
      $options |= BackupOptions::FILES;
    }
    if (!$input->getOption('database') && !$input->getOption('files')) {
      // Default to both if none specified, BUT the service and commands
      // now prohibit both.  Wait, if they prohibit both, how do we do a full backup?
      // Re-reading previous requirements: "The backup command must reject using --database and --files together."
      // If so, then a "full" backup is when NEITHER is specified.
      $options |= BackupOptions::DATABASE;
      $options |= BackupOptions::FILES;
    }
    if ($input->getOption('notify')) {
      $options |= BackupOptions::NOTIFY;
    }

    return $options;
  }
}
