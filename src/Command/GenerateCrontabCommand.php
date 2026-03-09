<?php

namespace AKlump\WebsiteBackup\Command;

use AKlump\WebsiteBackup\Config\ConfigLoader;
use AKlump\WebsiteBackup\Helper\GetInstalledInRoot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class GenerateCrontabCommand extends Command {

  protected static $defaultName = 'generate:crontab';

  protected function configure(): void {
    $this
      ->setDescription('Helps generate a crontab entry for S3 backups.')
      ->setAliases(['gc'])
      ->addOption('config', NULL, InputOption::VALUE_REQUIRED, 'Path to the configuration file.')
      ->addOption('env-file', NULL, InputOption::VALUE_REQUIRED, 'Path to the environment file.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $io->title('Crontab Generator');
    $io->note('This must be run on the same system as your crontab');

    $root = (new GetInstalledInRoot())();
    if (!$root) {
      $io->error('Could not find the application root; did you run install yet? Make sure bin/config/website_backup.yml exists.');

      return Command::FAILURE;
    }
    $project_root = rtrim($root, '/') . '/';
    $config_path = $input->getOption('config');
    $env_path = $input->getOption('env-file');
    $loader = new ConfigLoader($root, $config_path, $env_path);

    $tmpdir_default = sys_get_temp_dir();
    $tmpdir = $io->ask('Confirm the temporary directory', $tmpdir_default, function ($value) {
      return $this->directoryValidator($value);
    });

    $php_path_default = dirname(PHP_BINARY);
    $io->note([
      sprintf('Current PHP path: %s', $php_path_default),
      'In some cases it is necessary to include this in your crontab, but it is not recommended unless you experience issues with PHP versions.',
    ]);
    $php_path = $io->ask(sprintf('Enter path to PHP, or leave blank to skip', $php_path_default), NULL, function ($value) {
      return trim($value) ? $this->directoryValidator($value) : '';
    });

    $parts = [];
    $parts[] = sprintf('TMPDIR="%s"', $tmpdir);

    // Only hardcode PHP if it is set.
    if ($php_path) {
      $parts[] = sprintf('PATH="%s:$PATH"', $php_path);
    }

    // TODO This is brittle; move it
    $binary = $project_root . 'vendor/bin/website-backup';

    $command = sprintf('0 3 * * * %s %s --config %s --env-file %s backup:s3 -f --notify --quiet',
      implode(' ', $parts),
      $binary,
      $loader->getConfigPath(),
      $loader->getEnvPath(),
    );

    $io->section('Generated Crontab Entry');
    $io->writeln(trim($command));
    $io->newLine();
    $io->note('Copy the line above adjusting the interval as desired, and paste it into your crontab (crontab -e).');

    return Command::SUCCESS;
  }

  private function directoryValidator($value): string {
    $value = trim((string) $value);
    if ($value === '') {
      throw new \RuntimeException('This value is required.');
    }
    if (!is_dir($value)) {
      throw new \RuntimeException('Directory does not exist.');
    }

    return $value;
  }

}
