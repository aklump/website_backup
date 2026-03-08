<?php

namespace AKlump\WebsiteBackup\Command;

use AKlump\WebsiteBackup\Config\ConfigLoader;
use AKlump\WebsiteBackup\Helper\GetInstalledInRoot;
use AKlump\WebsiteBackup\Service\BackupOptions;
use AKlump\WebsiteBackup\Service\BackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupLocalCommand extends Command {

  protected static $defaultName = 'backup:local';

  protected function configure(): void {
    $this
      ->setDescription('Backs up the website to a local directory.')
      ->addOption('dir', NULL, InputOption::VALUE_REQUIRED, 'Save the backup to the specified existing directory.')
      ->addOption('latest', NULL, InputOption::VALUE_NONE, 'Create a "latest" symlink when saving locally.')
      ->addOption('database', NULL, InputOption::VALUE_NONE, 'Backup only the database.')
      ->addOption('files', NULL, InputOption::VALUE_NONE, 'Backup only the files.')
      ->addOption('notify', NULL, InputOption::VALUE_NONE, 'Send email notifications for this backup run.')
      ->addOption('gzip', NULL, InputOption::VALUE_NONE, 'Compress the local backup as .tar.gz.')
      ->addOption('encrypt', NULL, InputOption::VALUE_NONE, 'Encrypt the local archive (requires --gzip).');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $root = (new GetInstalledInRoot())();
    $loader = new ConfigLoader($root);
    $config = $loader->load();

    $dir = $input->getOption('dir');
    if (!$dir) {
      throw new \RuntimeException('The --dir option is required.');
    }
    if (!is_dir($dir)) {
      throw new \RuntimeException(sprintf('The directory specified by --dir does not exist or is not a directory: %s', $dir));
    }

    $gzip = $input->getOption('gzip');
    $encrypt = $input->getOption('encrypt');
    if ($encrypt && !$gzip) {
      throw new \RuntimeException('The --encrypt option may only be used with --gzip.');
    }

    if ($input->getOption('database') && $input->getOption('files')) {
      throw new \RuntimeException('The --database and --files options cannot be used together.');
    }

    $loader->validate($config, TRUE, $input->getOption('notify'), (bool) $encrypt);

    $options = 0;
    if ($input->getOption('database')) {
      $options |= BackupOptions::DATABASE;
    }
    if ($input->getOption('files')) {
      $options |= BackupOptions::FILES;
    }
    if ($input->getOption('latest')) {
      $options |= BackupOptions::LATEST;
    }
    if ($input->getOption('notify')) {
      $options |= BackupOptions::NOTIFY;
    }
    if ($gzip) {
      $options |= BackupOptions::GZIP;
    }
    if ($encrypt) {
      $options |= BackupOptions::ENCRYPT;
    }

    $service = new BackupService($config, $output);
    $service->run($options, $dir);

    return Command::SUCCESS;
  }
}
