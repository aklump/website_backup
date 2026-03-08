<?php

namespace App\Command;

use App\Config\ConfigLoader;
use App\Helper\GetInstalledInRoot;
use App\Service\ProcessRunner;
use App\Service\S3Service;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class HealthcheckCommand extends Command {

  protected static $defaultName = 'healthcheck';

  protected function configure(): void {
    $this
      ->setDescription('Performs a health check of the application and configuration.')
      ->setAliases(['hc', 'st']);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $io->title('Website Backup Health Check');

    $root = (new GetInstalledInRoot())();
    if (!$root) {
      $io->error('Could not find the application root; did you run install yet? Make sure bin/config/website_backup.yml exists.');
      return Command::FAILURE;
    }

    $loader = new ConfigLoader($root);
    $all_passed = true;

    // 1. Configuration Check
    $io->section('Checking Configuration');
    try {
      $config = $loader->load();
      $loader->validate($config);
      $io->success('Configuration is present and valid.');

      // Check for zero retention
      $retention = $config['aws_retention'] ?? [];
      if (isset($retention['keep_daily_for_days']) && isset($retention['keep_monthly_for_months'])) {
        if ($retention['keep_daily_for_days'] === 0 && $retention['keep_monthly_for_months'] === 0) {
          $io->warning('Retention is set to 0 for both daily and monthly. This will cause the S3 bucket to continue to grow in size as no backups will ever be pruned.');
        }
      }
    } catch (\Exception $e) {
      $io->error('Configuration check failed: ' . $e->getMessage());
      $all_passed = false;
      // If config fails, we might not be able to proceed with other checks.
      if (empty($config)) {
        return Command::FAILURE;
      }
    }

    // 2. Database Connectivity Check
    if (!empty($config['database'])) {
      $io->section('Checking Database Connectivity');
      try {
        $this->checkDatabase($config['database']);
        $io->success('Successfully connected to the database.');
      } catch (\Exception $e) {
        $io->error('Database connectivity failed: ' . $e->getMessage());
        $io->note('Make sure your database credentials and host are correct and that "mysql" client is installed.');
        $all_passed = false;
      }
    }

    // 3. S3 Connectivity Check
    if (!empty($config['aws_bucket'])) {
      $io->section('Checking S3 Connectivity');
      try {
        $this->checkS3($config);
        $io->success('Successfully connected to S3 and bucket is accessible.');
      } catch (\Exception $e) {
        $io->error('S3 connectivity failed: ' . $e->getMessage());
        $io->note('Check your AWS credentials, region, and bucket name.');
        $all_passed = false;
      }
    }

    // 4. System Tools Check
    $io->section('Checking System Tools');
    $tools = [
      'tar' => 'Required for creating and extracting archives.',
      'openssl' => 'Required for archive encryption and decryption.',
      'mysql' => 'Required for database connectivity checks.',
      'mysqldump' => 'Required for database backups.',
    ];
    $process_runner = new ProcessRunner();
    $openssl_available = false;

    foreach ($tools as $tool => $description) {
      try {
        $process = $process_runner->run([$tool, $tool === 'openssl' ? 'version' : '--version']);
        if ($process->isSuccessful()) {
          $io->success(sprintf('%s is installed.', ucfirst($tool)));
          if ($tool === 'openssl') {
            $openssl_available = true;
          }
        }
        else {
          $io->note(sprintf('%s is not installed or not in the PATH. %s', ucfirst($tool), $description));
        }
      } catch (\Exception $e) {
        $io->note(sprintf('%s is not installed or not in the PATH. %s', ucfirst($tool), $description));
      }
    }

    // 5. Encryption Settings Check
    $s3_encryption_enabled = !empty($config['encryption']['s3']) && $config['encryption']['s3'] === TRUE;
    if ($s3_encryption_enabled) {
      $io->section('Checking Encryption Settings');
      try {
        if (empty($config['encryption']['password'])) {
          throw new \RuntimeException('Encryption password is not configured but S3 encryption is enabled.');
        }

        if (!$openssl_available) {
          throw new \RuntimeException('OpenSSL is not available but S3 encryption is enabled.');
        }

        $io->success('Encryption password is set and OpenSSL is available.');
      } catch (\Exception $e) {
        $io->error('Encryption check failed: ' . $e->getMessage());
        $all_passed = false;
      }
    }

    if ($all_passed) {
      $io->success('All health checks passed!');
      return Command::SUCCESS;
    } else {
      $io->warning('Some health checks failed.');
      return Command::FAILURE;
    }
  }

  private function checkDatabase(array $db_config): void {
    $host = $db_config['host'] ?? 'localhost';
    $user = $db_config['user'] ?? '';
    $password = $db_config['password'] ?? '';
    $name = $db_config['name'] ?? '';
    $port = $db_config['port'] ?? '';

    $args = ['mysql', '--host=' . $host, '--user=' . $user, '--password=' . $password];
    if ($port) {
      $args[] = '--port=' . $port;
    }
    $args[] = '-e';
    $args[] = 'SELECT 1';
    $args[] = $name;

    $process_runner = new ProcessRunner();
    $process = $process_runner->run($args);

    if (!$process->isSuccessful()) {
      throw new \RuntimeException($process->getErrorOutput() ?: $process->getOutput());
    }
  }

  private function checkS3(array $config): void {
    $s3 = new S3Service(
      $config['aws_region'],
      $config['aws_bucket'],
      $config['aws_access_key_id'],
      $config['aws_secret_access_key']
    );

    $s3->checkConnection();
  }
}
