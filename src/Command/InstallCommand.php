<?php

namespace AKlump\WebsiteBackup\Command;

use AKlump\WebsiteBackup\Config\ConfigLoader;
use AKlump\WebsiteBackup\Helper\GetShortPath;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InstallCommand extends Command {

  protected static $defaultName = 'install';

  protected function configure(): void {
    $this
      ->setDescription('Installs website backup.')
      ->addOption('config', NULL, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Path to the configuration file.')
      ->addOption('env-file', NULL, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Path to the environment file.')
      ->addOption('force', 'f', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Force the installation without confirmation.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);

    $env_file_path = $input->getOption('env-file');

    if (!$input->getOption('force')) {
      $message = 'Are you sure you want to proceed with the installation? This will create configuration and .env files in the current directory.';
      if ($env_file_path) {
        $message = sprintf('Are you sure you want to proceed with the installation? This will create configuration in the current directory and use %s for environment tokens.', $env_file_path);
      }
      if (!$io->confirm($message, TRUE)) {
        $io->note('Installation aborted.');

        return Command::SUCCESS;
      }
    }

    $get_short_path = new GetShortPath();
    $cwd = getcwd();
    $app_root = dirname(__DIR__, 2);
    $install_config = $app_root . '/install/config.yml';

    if (!file_exists($install_config)) {
      $io->error(sprintf('Install configuration file not found at %s', $get_short_path($install_config)));

      return Command::FAILURE;
    }

    $config_file_path = $input->getOption('config');
    if ($config_file_path) {
      $target_config = $config_file_path;
      if (!preg_match('/\.ya?ml$/i', $target_config)) {
        $target_config .= '.yml';
      }
      if (!str_starts_with($target_config, '/')) {
        $target_config = $cwd . '/' . $target_config;
      }
      $target_dir = dirname($target_config);
    }
    else {
      $target_dir = $cwd . '/bin/config';
      $target_config = $target_dir . '/website_backup.yml';
    }

    if (!is_dir($target_dir)) {
      mkdir($target_dir, 0700, TRUE);
    }

    if (file_exists($target_config)) {
      $io->error(sprintf('The file %s already exists.', $get_short_path($target_config)));

      return Command::FAILURE;
    }

    if (!copy($install_config, $target_config)) {
      $io->error(sprintf('Failed to copy %s to %s', $get_short_path($install_config), $get_short_path($target_config)));

      return Command::FAILURE;
    }

    $io->success(sprintf('Copied %s to %s', $get_short_path($install_config), $get_short_path($target_config)));

    // Scan for environment tokens
    $content = file_get_contents($install_config);
    preg_match_all(ConfigLoader::ENV_TOKEN_PATTERN, $content, $matches);
    $tokens = array_unique($matches[1]);

    if (!empty($tokens)) {
      $env_file = $env_file_path ?? ($cwd . '/.env');
      if (!str_starts_with($env_file, '/')) {
        $env_file = $cwd . '/' . $env_file;
      }

      $existing_env = [];
      if (file_exists($env_file)) {
        $existing_env_content = file_get_contents($env_file);
        preg_match_all('/^([^#\s=]+)=/m', $existing_env_content, $env_matches);
        $existing_env = $env_matches[1];
      }

      $to_append = "";
      foreach ($tokens as $token) {
        if (!in_array($token, $existing_env)) {
          $to_append .= sprintf("%s=\n", $token);
        }
      }

      if ($to_append !== "") {
        $prefix = "";
        if (file_exists($env_file)) {
          $existing_env_content = file_get_contents($env_file);
          if ($existing_env_content !== "" && substr($existing_env_content, -1) !== "\n") {
            $prefix = "\n";
          }
        }
        else {
          // Ensure directory for env file exists
          $env_dir = dirname($env_file);
          if (!is_dir($env_dir)) {
            mkdir($env_dir, 0700, TRUE);
          }
        }
        if (file_put_contents($env_file, $prefix . $to_append, FILE_APPEND)) {
          $io->success(sprintf('Appended tokens to %s', $get_short_path($env_file)));
        }
        else {
          $io->error(sprintf('Failed to write to %s', $get_short_path($env_file)));

          return Command::FAILURE;
        }
      }
      else {
        $io->note('No new environment tokens to append.');
      }
    }

    return Command::SUCCESS;
  }
}
