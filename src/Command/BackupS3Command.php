<?php

namespace AKlump\WebsiteBackup\Command;

use AKlump\WebsiteBackup\Config\ConfigLoader;
use AKlump\WebsiteBackup\Helper\GetInstalledInRoot;
use AKlump\WebsiteBackup\Service\BackupService;
use AKlump\WebsiteBackup\Traits\CommandTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BackupS3Command extends Command {

  use CommandTrait;

  protected static $defaultName = 'backup:s3';

  protected function configure(): void {
    $this
      ->setDescription('Backs up the website to S3.')
      ->addOption('config', NULL, InputOption::VALUE_REQUIRED, 'Path to the configuration file.')
      ->addOption('env-file', NULL, InputOption::VALUE_REQUIRED, 'Path to the environment file.')
      ->addOption('database', NULL, InputOption::VALUE_NONE, 'Backup only the database.')
      ->addOption('files', NULL, InputOption::VALUE_NONE, 'Backup only the files.')
      ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the backup without confirmation.')
      ->addOption('notify', NULL, InputOption::VALUE_NONE, 'Send email notifications for this backup run.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $root = (new GetInstalledInRoot())();
    $config_path = $input->getOption('config');
    $env_path = $input->getOption('env-file');
    $loader = new ConfigLoader($root, $config_path, $env_path);
    $config = $loader->load();

    if ($input->getOption('database') && $input->getOption('files')) {
      throw new \RuntimeException('The --database and --files options cannot be used together.');
    }

    // Confirmation for S3 backups
    if (!$input->getOption('force')) {
      if (!$io->confirm('You are about to backup to S3. This may overwrite or prune existing backups. Do you wish to proceed?', TRUE)) {
        $io->note('Backup aborted.');

        return Command::SUCCESS;
      }
    }

    $loader->validate($config, FALSE, $input->getOption('notify'), FALSE);

    $options = $this->getCommonBackupOptions($input);

    $service = new BackupService($config, $output);
    $service->run($options, '');

    return Command::SUCCESS;
  }
}
