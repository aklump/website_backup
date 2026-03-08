<?php

namespace AKlump\WebsiteBackup\Command;

use AKlump\WebsiteBackup\Config\ConfigLoader;
use AKlump\WebsiteBackup\Helper\GetInstalledInRoot;
use AKlump\WebsiteBackup\Service\BackupOptions;
use AKlump\WebsiteBackup\Service\BackupService;
use AKlump\WebsiteBackup\Traits\CommandTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupLocalCommand extends Command {
use CommandTrait;
  protected static $defaultName = 'backup:local';

  protected function configure(): void {
    $this
      ->setDescription('Backs up the website to a local directory.')
      ->addOption('config', NULL, InputOption::VALUE_REQUIRED, 'Path to the configuration file.')
      ->addOption('env-file', NULL, InputOption::VALUE_REQUIRED, 'Path to the environment file.')
      ->addOption('dir', NULL, InputOption::VALUE_REQUIRED, 'Local backup directory. If omitted, directories.local from config.yml is used.')
      ->addOption('latest', NULL, InputOption::VALUE_NONE, 'Create a "latest" symlink when saving locally.')
      ->addOption('database', NULL, InputOption::VALUE_NONE, 'Backup only the database.')
      ->addOption('files', NULL, InputOption::VALUE_NONE, 'Backup only the files.')
      ->addOption('notify', NULL, InputOption::VALUE_NONE, 'Send email notifications for this backup run.')
      ->addOption('gzip', NULL, InputOption::VALUE_NONE, 'Compress the local backup as .tar.gz.')
      ->addOption('encrypt', NULL, InputOption::VALUE_NONE, 'Encrypt the local archive (requires --gzip).');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $root = (new GetInstalledInRoot())();
    $config_path = $input->getOption('config');
    $env_path = $input->getOption('env-file');
    $loader = new ConfigLoader($root, $config_path, $env_path);
    $config = $loader->load();

    $dir = $input->getOption('dir') ?: ($config['directories']['local'] ?? NULL);
    if (!$dir) {
      throw new \RuntimeException('No local backup directory was provided. Use --dir or set directories.local in config.yml.');
    }
    if (!is_dir($dir)) {
      if (!@mkdir($dir, 0700, TRUE) && !is_dir($dir)) {
        throw new \RuntimeException(sprintf('The directory specified by --dir could not be created: %s', $dir));
      }
    }
    if (!is_writable($dir)) {
      throw new \RuntimeException(sprintf('The directory is not writable: %s', $dir));
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


    $options = $this->getCommonBackupOptions($input);
    if ($input->getOption('latest')) {
      $options |= BackupOptions::LATEST;
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
