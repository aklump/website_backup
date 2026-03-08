<?php

namespace AKlump\WebsiteBackup\Command;

use AKlump\WebsiteBackup\Config\ConfigLoader;
use AKlump\WebsiteBackup\Helper\GetInstalledInRoot;
use AKlump\WebsiteBackup\Service\ManifestService;
use AKlump\WebsiteBackup\Service\ProcessRunner;
use AKlump\WebsiteBackup\Service\S3Service;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class HealthcheckCommand extends Command {

  protected static $defaultName = 'healthcheck';

  protected function configure(): void {
    $this
      ->setDescription('Performs a health check of the application and configuration.')
      ->addOption('config', NULL, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Path to the configuration file.')
      ->addOption('env-file', NULL, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Path to the environment file.')
      ->setAliases(['hc']);
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $io->title('Website Backup Health Check');

    $root = (new GetInstalledInRoot())();
    if (!$root && !$input->getOption('config')) {
      $io->error('Could not find the application root; did you run install yet? Make sure bin/config/website_backup.yml exists.');
      return Command::FAILURE;
    }

    $config_path = $input->getOption('config');
    $env_path = $input->getOption('env-file');
    $loader = new ConfigLoader($root, $config_path, $env_path);
    $all_passed = true;

    // 1. Configuration Check
    $output->writeln('<comment>Checking Configuration</comment>');
    $output->writeln('<comment>----------------------</comment>');
    try {
      $config = $loader->load();
      $loader->validate($config);
      $output->writeln(' <info>✓</info> Configuration is present and valid.');

      // Check for zero retention
      $retention = $config['aws_retention'] ?? [];
      if ($retention) {
        $all_zero = TRUE;
        foreach (['keep_all_for_days', 'keep_latest_daily_for_days', 'keep_latest_monthly_for_months', 'keep_latest_yearly_for_years'] as $key) {
          if (!empty($retention[$key])) {
            $all_zero = FALSE;
            break;
          }
        }
        if ($all_zero) {
          $output->writeln(' <comment>!</comment> All retention settings are set to 0. This will cause the S3 bucket to continue to grow in size as no backups will ever be pruned.');
        }
      }
    } catch (\Exception $e) {
      $output->writeln(' <error>✗</error> Configuration check failed: ' . $e->getMessage());
      $all_passed = false;
      // If config fails, we might not be able to proceed with other checks.
      if (empty($config)) {
        $io->error('Fatal: Could not load configuration.');
        return Command::FAILURE;
      }
    }

    // 2. Manifest Check
    $output->writeln('');
    $output->writeln('<comment>Checking Manifest</comment>');
    $output->writeln('<comment>-----------------</comment>');
    $manifest_service = new ManifestService($config['path_to_app'], '', $config['manifest']);
    foreach ($manifest_service->getManifestItems() as $item) {
      $paths = $manifest_service->resolve($item);
      if (empty($paths)) {
        $output->writeln(sprintf(' <info>?</info> %s — No files found matching this pattern.', $item));
      } else {
        $output->writeln(sprintf(' <info>✓</info> %s', $item));
      }
    }

    // 3. Database Connectivity Check
    if (!empty($config['database']['url'])) {
      $output->writeln('');
      $output->writeln('<comment>Checking Database Connectivity</comment>');
      $output->writeln('<comment>------------------------------</comment>');
      try {
        $this->checkDatabase($config['database']);
        $output->writeln(' <info>✓</info> Successfully connected to the database.');
      } catch (\Exception $e) {
        $output->writeln(' <error>✗</error> Database connectivity failed: ' . $e->getMessage());
        $output->writeln('   <comment>Note: Make sure your database URL is correct and that "mysql" client is installed.</comment>');
        $all_passed = false;
      }
    }

    // 4. S3 Connectivity Check
    if (!empty($config['aws_bucket']) && !empty($config['aws_access_key_id']) && !empty($config['aws_secret_access_key'])) {
      $output->writeln('');
      $output->writeln('<comment>Checking S3 Connectivity</comment>');
      $output->writeln('<comment>------------------------</comment>');
      try {
        $this->checkS3($config);
        $output->writeln(' <info>✓</info> Successfully connected to S3 and bucket is accessible.');
      } catch (\Exception $e) {
        $output->writeln(' <error>✗</error> S3 connectivity failed: ' . $e->getMessage());
        $output->writeln('   <comment>Note: Check your AWS credentials, region, and bucket name.</comment>');
        $all_passed = false;
      }
    }
    elseif (!empty($config['aws_bucket'])) {
      $output->writeln('');
      $output->writeln('<comment>Checking S3 Connectivity</comment>');
      $output->writeln('<comment>------------------------</comment>');
      $output->writeln(' <error>✗</error> S3 connectivity check skipped because AWS credentials are missing.');
      $all_passed = false;
    }

    // 5. System Tools Check
    $output->writeln('');
    $output->writeln('<comment>Checking System Tools</comment>');
    $output->writeln('<comment>---------------------</comment>');
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
          $output->writeln(sprintf(' <info>✓</info> %s', $tool));
          if ($tool === 'openssl') {
            $openssl_available = true;
          }
        }
        else {
          $output->writeln(sprintf(' <error>✗</error> %s — %s', $tool, $description));
        }
      } catch (\Exception $e) {
        $output->writeln(sprintf(' <error>✗</error> %s — %s', $tool, $description));
      }
    }

    // 6. Encryption Settings Check
    $s3_encryption_enabled = !empty($config['encryption']['s3']) && $config['encryption']['s3'] === TRUE;
    if ($s3_encryption_enabled) {
      $output->writeln('');
      $output->writeln('<comment>Checking Encryption Settings</comment>');
      $output->writeln('<comment>----------------------------</comment>');
      try {
        if (empty($config['encryption']['password'])) {
          throw new \RuntimeException('Encryption password is not configured but S3 encryption is enabled.');
        }

        if (!$openssl_available) {
          throw new \RuntimeException('OpenSSL is not available but S3 encryption is enabled.');
        }

        $output->writeln(' <info>✓</info> Encryption password is set and OpenSSL is available.');
      } catch (\Exception $e) {
        $output->writeln(' <error>✗</error> Encryption check failed: ' . $e->getMessage());
        $all_passed = false;
      }
    }

    // 7. Security Checks
    $output->writeln('');
    $output->writeln('<comment>Checking Security</comment>');
    $output->writeln('<comment>-----------------</comment>');
    try {
      $temp_file_factory = new \AKlump\WebsiteBackup\Service\TemporaryFileFactory();
      $temp_path = $temp_file_factory->create(sys_get_temp_dir(), 'test', 'wb_hc_security_');
      if (file_exists($temp_path)) {
        $perms = fileperms($temp_path) & 0777;
        if ($perms !== 0600) {
          throw new \RuntimeException(sprintf('Temporary file created with insecure permissions: %o (expected 0600)', $perms));
        }
        $temp_file_factory->cleanup($temp_path);
        $output->writeln(' <info>✓</info> Secure temporary file creation is working correctly.');
      } else {
        throw new \RuntimeException('Failed to create temporary security test file.');
      }
    } catch (\Exception $e) {
      $output->writeln(' <error>✗</error> Security check failed: ' . $e->getMessage());
      $all_passed = false;
    }

    $output->writeln('');
    if ($all_passed) {
      $io->success('All health checks passed!');
      return Command::SUCCESS;
    } else {
      $io->error('Some health checks failed.');
      return Command::FAILURE;
    }
  }

  private function checkDatabase(array $db_config): void {
    $name = $db_config['name'] ?? '';

    $create_mysql_temp_config = new \AKlump\WebsiteBackup\Helper\CreateMysqlTempConfig();
    $temp_file_factory = new \AKlump\WebsiteBackup\Service\TemporaryFileFactory();
    $temp_config = $create_mysql_temp_config($db_config);

    try {
      $args = [
        'mysql',
        '--defaults-extra-file=' . $temp_config,
        '-e',
        'SELECT 1',
        $name,
      ];

      $process_runner = new ProcessRunner();
      $process = $process_runner->run($args);

      if (!$process->isSuccessful()) {
        throw new \RuntimeException($process_runner->redact($process->getErrorOutput() ?: $process->getOutput()));
      }
    }
    finally {
      $temp_file_factory->cleanup($temp_config);
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
