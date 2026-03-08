<?php

namespace AKlump\WebsiteBackup\Service;

use AKlump\WebsiteBackup\Helper\AssertSafeBackupArtifactPath;
use AKlump\WebsiteBackup\Helper\RemoveDirectoryTree;
use AKlump\WebsiteBackup\Helper\RemoveFileOrSymlink;
use AKlump\WebsiteBackup\Helper\GetShortPath;
use AKlump\WebsiteBackup\Helper\S3LinkBuilder;
use Symfony\Component\Console\Output\OutputInterface;

class BackupService {

  private $config;

  private $output;

  private $processRunner;

  private $databaseDumper;

  private $getShortPath;

  private $emailService;

  private $tempDirectoryFactory;

  private $s3Factory;

  public function __construct(array $config, OutputInterface $output) {
    $this->config = $config;
    $this->output = $output;
    $this->processRunner = new ProcessRunner();
    $this->databaseDumper = new DatabaseDumper($this->processRunner);
    $this->getShortPath = new GetShortPath();
    $this->emailService = new EmailService($output);
    $this->tempDirectoryFactory = new TempDirectoryFactory();
    $this->s3Factory = function (string $region, string $bucket, string $key, string $secret) {
      return new S3Service($region, $bucket, $key, $secret);
    };
  }

  public function setS3Factory(callable $factory): void {
    $this->s3Factory = $factory;
  }

  public function run(int $options = 0, $local_path = ''): void {
    $this->validateOptions($options, (bool) $local_path);
    $start_time = microtime(TRUE);
    try {
      $object_name = $this->doRun($options, $local_path);
      if ($options & BackupOptions::NOTIFY) {
        $this->sendNotification(TRUE, $local_path, $options, $start_time, $object_name, NULL);
      }
    }
    catch (\Exception $e) {
      if ($options & BackupOptions::NOTIFY) {
        $this->sendNotification(FALSE, $local_path, $options, $start_time, NULL, $e);
      }
      throw $e;
    }
  }

  private function doRun(int $options = 0, $local_path = ''): ?string {
    $this->output->writeln('<info>Backing Up Your Website</info>');

    $latest = (bool) ($options & BackupOptions::LATEST);
    $has_database = (bool) ($options & BackupOptions::DATABASE);
    $has_files = (bool) ($options & BackupOptions::FILES);
    $gzip = (bool) ($options & BackupOptions::GZIP);
    $encrypt = (bool) ($options & BackupOptions::ENCRYPT);

    $object_name_base = $this->config['object_name'] ?? $this->config['aws_bucket'];
    $timestamp = date('Ymd\THisO');
    $full_object_name = $object_name_base . '--' . $timestamp;
    $latest_symlink_name = $object_name_base . '--latest';
    $temp_work_dir = $this->tempDirectoryFactory->create();
    try {
      $staging_dir = $temp_work_dir . '/' . $full_object_name;
      if (!mkdir($staging_dir, 0700)) {
        throw new \RuntimeException(sprintf('Could not create the local object directory: %s', $staging_dir));
      }
      $this->output->writeln(sprintf('Staging in: %s', ($this->getShortPath)($staging_dir)), OutputInterface::VERBOSITY_DEBUG);
      $this->databaseDumper->setTempDir($temp_work_dir);

      // 1. Database export
      if (($options & BackupOptions::DATABASE)) {
        if (!empty($this->config['database']['handler'])) {
          $this->output->writeln(sprintf('<comment>Exporting database (via %s)</comment>', $this->config['database']['handler']));
          $dumpfile = ($this->config['database']['dumpfile'] ?? 'database-backup') . '.' . ($this->config['database']['name'] ?? 'db') . '.sql';
          $output_path = $staging_dir . '/' . $dumpfile;
          $start = microtime(TRUE);
          $this->databaseDumper->dump($this->config['database'], $output_path, $this->config['database']['cache_tables'] ?? []);
          $elapsed = round(microtime(TRUE) - $start, 2);
          $this->output->writeln(sprintf(' <info>*</info> %s seconds', $elapsed));
        }
        else {
          $this->output->writeln('<comment>Skipping database export (no handler configured)</comment>', OutputInterface::VERBOSITY_VERBOSE);
        }
      }

      // 2. File copying
      if ($options & BackupOptions::FILES) {
        $this->output->writeln('<comment>Cherry-picking files</comment>');
        $start = microtime(TRUE);
        $config_path = $this->config['__config_path'] ?? '';
        $config_dir = $config_path ? dirname($config_path) : getcwd();
        $project_root = $this->config['__project_root'] ?? '';
        $manifest_service = new ManifestService($config_dir, $staging_dir, $this->config['manifest'], $project_root);
        $commands = $manifest_service->getCommands();
        foreach ($commands as $cmd) {
          $this->output->writeln(sprintf(' <info>*</info> %s', implode(' ', $cmd)), OutputInterface::VERBOSITY_DEBUG);
          $process = $this->processRunner->run($cmd);
          if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Command failed: %s', implode(' ', $cmd)));
          }
        }
        $elapsed = round(microtime(TRUE) - $start, 2);
        $this->output->writeln(sprintf(' <info>*</info> %s seconds', $elapsed));
      }

      // 3. Output
      if ($local_path) {
        $this->output->writeln('<comment>Saving locally</comment>');

        if ($gzip) {
          $this->output->writeln('<comment>Compressing object</comment>');
          $start = microtime(TRUE);
          $archive_name = $full_object_name . '.tar.gz';
          $archive_path = $temp_work_dir . '/' . $archive_name;

          $process = $this->processRunner->run([
            'tar',
            '-czf',
            $archive_name,
            $full_object_name,
          ], $temp_work_dir);
          if (!$process->isSuccessful()) {
            throw new \RuntimeException('Could not compress object.');
          }
          $elapsed = round(microtime(TRUE) - $start, 2);
          $this->output->writeln(sprintf(' <info>*</info> %s seconds', $elapsed));

          $final_artifact_path = $archive_path;
          $final_artifact_name = $archive_name;

          if ($encrypt) {
            $this->output->writeln('<comment>Encrypting object</comment>');
            $start = microtime(TRUE);
            $encrypted_name = $archive_name . '.enc';
            $encrypted_path = $temp_work_dir . '/' . $encrypted_name;

            $process = $this->processRunner->run(
              [
                'openssl',
                'enc',
                '-aes-256-cbc',
                '-pbkdf2',
                '-salt',
                '-in',
                $archive_name,
                '-out',
                $encrypted_name,
                '-pass',
                'env:WEBSITE_BACKUP_ENCRYPTION_PASSWORD',
              ],
              $temp_work_dir,
              ['WEBSITE_BACKUP_ENCRYPTION_PASSWORD' => $this->config['encryption']['password']]
            );

            if (!$process->isSuccessful()) {
              throw new \RuntimeException('Could not encrypt object.');
            }
            $elapsed = round(microtime(TRUE) - $start, 2);
            $this->output->writeln(sprintf(' <info>*</info> %s seconds', $elapsed));

            // Remove plaintext archive
            unlink($archive_path);

            $final_artifact_path = $encrypted_path;
            $final_artifact_name = $encrypted_name;
          }

          $final_artifact_name_for_notify = $final_artifact_name;

          $destination = rtrim($local_path, '/') . '/' . $final_artifact_name;
          if (file_exists($destination) || is_link($destination)) {
            (new RemoveFileOrSymlink())($destination);
          }
          rename($final_artifact_path, $destination);
          $this->output->writeln(sprintf(' <info>*</info> %s', ($this->getShortPath)($destination)));

          if ($latest) {
            $this->output->writeln(' <info>*</info> latest symlink created.');
            $symlink_path = rtrim($local_path, '/') . '/' . $latest_symlink_name . ($encrypt ? '.tar.gz.enc' : '.tar.gz');
            (new RemoveFileOrSymlink())($symlink_path);
            $cwd = getcwd();
            chdir($local_path);
            symlink($final_artifact_name, basename($symlink_path));
            chdir($cwd);
          }
        }
        else {
          $destination = rtrim($local_path, '/') . '/' . $full_object_name;
          if (is_dir($destination)) {
            (new AssertSafeBackupArtifactPath())($destination, $local_path, $object_name_base);
            (new RemoveDirectoryTree())($destination);
          }
          rename($staging_dir, $destination);
          $final_artifact_name_for_notify = $full_object_name;
          $this->output->writeln(sprintf(' <info>*</info> %s', ($this->getShortPath)($destination)));

          if ($latest) {
            $this->output->writeln(' <info>*</info> latest symlink created.');
            $symlink_path = rtrim($local_path, '/') . '/' . $latest_symlink_name;
            (new RemoveFileOrSymlink())($symlink_path);
            $cwd = getcwd();
            chdir($local_path);
            symlink($full_object_name, $latest_symlink_name);
            chdir($cwd);
          }
        }
      }
      else {
        // Compress
        $this->output->writeln('<comment>Compressing object</comment>');
        $start = microtime(TRUE);
        $archive_name = $full_object_name . '.tar.gz';
        $archive_path = $temp_work_dir . '/' . $archive_name;

        $process = $this->processRunner->run([
          'tar',
          '-czf',
          $archive_name,
          $full_object_name,
        ], $temp_work_dir);
        if (!$process->isSuccessful()) {
          throw new \RuntimeException('Could not compress object.');
        }
        $elapsed = round(microtime(TRUE) - $start, 2);
        $this->output->writeln(sprintf(' <info>*</info> %s seconds', $elapsed));

        $final_artifact_path = $archive_path;
        $final_artifact_name = $archive_name;

        if (!empty($this->config['encryption']['s3'])) {
          $this->output->writeln('<comment>Encrypting object</comment>');
          $start = microtime(TRUE);
          $final_artifact_name = $archive_name . '.enc';
          $final_artifact_path = $temp_work_dir . '/' . $final_artifact_name;

          $process = $this->processRunner->run(
            [
              'openssl',
              'enc',
              '-aes-256-cbc',
              '-pbkdf2',
              '-salt',
              '-in',
              $archive_name,
              '-out',
              $final_artifact_name,
              '-pass',
              'env:WEBSITE_BACKUP_ENCRYPTION_PASSWORD',
            ],
            $temp_work_dir,
            ['WEBSITE_BACKUP_ENCRYPTION_PASSWORD' => $this->config['encryption']['password']]
          );

          if (!$process->isSuccessful()) {
            throw new \RuntimeException('Could not encrypt object.');
          }
          $elapsed = round(microtime(TRUE) - $start, 2);
          $this->output->writeln(sprintf(' <info>*</info> %s seconds', $elapsed));

          // Remove plaintext archive
          unlink($archive_path);
        }

        // S3 Upload
        $this->output->writeln(sprintf('<comment>Sending to bucket "%s" on S3</comment>', $this->config['aws_bucket']));
        $this->output->writeln(sprintf(' <info>*</info> using key: ...%s', substr($this->config['aws_access_key_id'], -6)));
        $this->output->writeln(sprintf(' <info>*</info> object: %s', $final_artifact_name));

        $final_artifact_name_for_notify = $final_artifact_name;

        $s3 = ($this->s3Factory)(
          $this->config['aws_region'],
          $this->config['aws_bucket'],
          $this->config['aws_access_key_id'],
          $this->config['aws_secret_access_key']
        );
        $s3->upload($final_artifact_name, $final_artifact_path);

        $s3_link_builder = new S3LinkBuilder();
        $s3_uri = $s3_link_builder->buildS3Uri($this->config['aws_bucket'], $final_artifact_name);
        $console_url = $s3_link_builder->buildConsoleUrl($this->config['aws_bucket'], $this->config['aws_region'], $final_artifact_name);

        $this->output->writeln('<info>Upload complete.</info>');
        $this->output->writeln(sprintf('S3 URI: %s', $s3_uri));
        $this->output->writeln(sprintf('AWS Console: %s', $console_url));

        // Prune
        $s3->pruneByRetention($this->config['aws_retention']);
      }

      $this->output->writeln('<info>Backup completed</info>');

      return $final_artifact_name_for_notify;
    }
    finally {
      $this->tempDirectoryFactory->cleanup($temp_work_dir);
    }
  }

  private function sendNotification(bool $success, $local_path, int $options, float $start_time, ?string $object_name, \Exception $exception = NULL): void {
    $email_config = $this->config['notifications']['email'];
    $subject = $success ? $email_config['on_success']['subject'] : $email_config['on_fail']['subject'];
    $elapsed = round(microtime(TRUE) - $start_time, 2);

    $has_database = (bool) ($options & BackupOptions::DATABASE);
    $has_files = (bool) ($options & BackupOptions::FILES);
    $latest = (bool) ($options & BackupOptions::LATEST);
    $encrypt = (bool) ($options & BackupOptions::ENCRYPT);

    $body = $success ? "Backup succeeded.\n\n" : "Backup failed.\n\n";
    if (!$success && $exception) {
      $body .= "Error: " . $exception->getMessage() . "\n\n";
    }

    $body .= "Details:\n";
    $body .= "- Mode: " . ($local_path ? 'Local' : 'S3') . "\n";

    if ($local_path) {
      $is_encrypted = $encrypt;
    }
    else {
      $is_encrypted = !empty($this->config['encryption']['s3']);
    }
    $body .= "- Encrypted: " . ($is_encrypted ? 'Yes' : 'No') . "\n";

    if ($local_path) {
      $body .= "- Destination: " . $local_path . "\n";
    }
    else {
      $body .= "- Bucket: " . ($this->config['aws_bucket'] ?? 'unknown') . "\n";
      if ($success && $object_name) {
        $s3_link_builder = new S3LinkBuilder();
        $body .= "- S3 URI: " . $s3_link_builder->buildS3Uri($this->config['aws_bucket'], $object_name) . "\n";
        $body .= "- AWS Console: " . $s3_link_builder->buildConsoleUrl($this->config['aws_bucket'], $this->config['aws_region'], $object_name) . "\n";
      }
    }

    $body .= "- Elapsed time: " . $elapsed . " seconds\n";
    $body .= "- Options used:\n";
    $body .= "  - Database: " . ($has_database ? 'Yes' : 'No') . "\n";
    $body .= "  - Files: " . ($has_files ? 'Yes' : 'No') . "\n";
    if ($local_path) {
      $body .= "  - Latest symlink: " . ($latest ? 'Yes' : 'No') . "\n";
    }

    if ($this->emailService->send($email_config['to'], $subject, $body)) {
      $this->output->writeln(sprintf('Email with subject "%s" was sent to: %s', $subject, implode(', ', $email_config['to'])));
    }
  }

  private function validateOptions(int $options, bool $is_local): void {
    if (($options & BackupOptions::ENCRYPT) && !($options & BackupOptions::GZIP)) {
      throw new \InvalidArgumentException('The ENCRYPT option requires GZIP to be set.');
    }
    if (($options & BackupOptions::LATEST) && !$is_local) {
      throw new \InvalidArgumentException('The LATEST option may only be used with a local destination.');
    }
  }
}
