<?php

namespace App\Command;

use App\Config\ConfigLoader;
use App\Helper\GetInstalledInRoot;
use App\Service\BackupOptions;
use App\Service\BackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupCommand extends Command {

  protected static $defaultName = 'backup';

  protected function configure(): void {
    $this
      ->setDescription('Backs up the website.')
      ->setAliases(['bu'])
      ->addOption('local', NULL, InputOption::VALUE_REQUIRED, 'Save the backup locally to the specified directory.')
      ->addOption('latest', NULL, InputOption::VALUE_NONE, 'Create a "latest" symlink when saving locally.')
      ->addOption('database', NULL, InputOption::VALUE_NONE, 'Backup only the database.')
      ->addOption('files', NULL, InputOption::VALUE_NONE, 'Backup only the files.')
      ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the backup without confirmation.')
      ->addOption('notify', NULL, InputOption::VALUE_NONE, 'Send email notifications for this backup run.')
      ->addOption('gzip', NULL, InputOption::VALUE_NONE, 'Compress the local backup as .tar.gz (only with --local).')
      ->addOption('encrypt', NULL, InputOption::VALUE_NONE, 'Encrypt the local archive (only with --local and --gzip).');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new \Symfony\Component\Console\Style\SymfonyStyle($input, $output);
    $root = (new GetInstalledInRoot())();
    $loader = new ConfigLoader($root);
    $config = $loader->load();

    $local = $input->getOption('local');
    if ($local && !is_dir($local)) {
      throw new \RuntimeException(sprintf('The local path does not exist or is not a directory: %s', $local));
    }

    $gzip = $input->getOption('gzip');
    if ($gzip && !$local) {
      throw new \RuntimeException('The --gzip option may only be used with --local.');
    }

    $encrypt = $input->getOption('encrypt');
    if ($encrypt && (!$local || !$gzip)) {
      throw new \RuntimeException('The --encrypt option may only be used with --local and --gzip.');
    }

    // Confirmation for S3 backups
    if (!$local && !$input->getOption('force')) {
      if (!$io->confirm('You are about to backup to S3. This may overwrite or prune existing backups. Do you wish to proceed?', TRUE)) {
        $io->note('Backup aborted.');

        return Command::SUCCESS;
      }
    }

    $loader->validate($config, (bool) $local, $input->getOption('notify'), (bool) $encrypt);

    $options = 0;
    // Defaults to both database and files if neither is specified
    if (!$input->getOption('files') && !$input->getOption('database')) {
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
    $service->run($options, $local ?: '');

    return Command::SUCCESS;
  }
}
