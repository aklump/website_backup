<?php

namespace App\Service;

use App\Helper\GetShortPath;
use Symfony\Component\Console\Output\OutputInterface;

class BackupService {

  private $config;

  private $output;

  private $processRunner;

  private $databaseDumper;

  private $getShortPath;

  private $emailService;

  private $tempDirectoryFactory;

  public function __construct(array $config, OutputInterface $output) {
    $this->config = $config;
    $this->output = $output;
    $this->processRunner = new ProcessRunner();
    $this->databaseDumper = new DatabaseDumper($this->processRunner);
    $this->getShortPath = new GetShortPath();
    $this->emailService = new EmailService($output);
    $this->tempDirectoryFactory = new TempDirectoryFactory();
  }

  public function run(int $options = 0, $local_path = ''): void {
    $this->validateOptions($options, (bool) $local_path);
    $start_time = microtime(TRUE);
    try {
      $this->doRun($options, $local_path);
      if ($options & BackupOptions::NOTIFY) {
        $this->sendNotification(TRUE, $local_path, $options, $start_time, NULL);
      }
    }
    catch (\Exception $e) {
      if ($options & BackupOptions::NOTIFY) {
        $this->sendNotification(FALSE, $local_path, $options, $start_time, $e);
      }
      throw $e;
    }
  }

  private function doRun(int $options = 0, $local_path = ''): void {
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
      if (!empty($this->config['database']['handler']) && ($has_database || !$has_files)) {
        $this->output->writeln(sprintf('<comment>Exporting database (via %s)</comment>', $this->config['database']['handler']));
        $dumpfile = ($this->config['database']['dumpfile'] ?? 'database-backup') . '.' . ($this->config['database']['name'] ?? 'db') . '.sql';
        $output_path = $staging_dir . '/' . $dumpfile;
        $start = microtime(TRUE);
        $this->databaseDumper->dump($this->config['database'], $output_path, $this->config['database']['cache_tables'] ?? []);
        $elapsed = round(microtime(TRUE) - $start, 2);
        $this->output->writeln(sprintf(' <info>*</info> %s seconds', $elapsed));
      }

      // 2. File copying
      if ($has_files || !$has_database) {
        $this->output->writeln('<comment>Cherry-picking files</comment>');
        $start = microtime(TRUE);
        $manifest_service = new ManifestService($this->config['path_to_app'], $staging_dir, $this->config['manifest']);
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
              throw new \RuntimeException('Could not encrypt object: ' . $this->processRunner->redact($process->getErrorOutput()));
            }
            $elapsed = round(microtime(TRUE) - $start, 2);
            $this->output->writeln(sprintf(' <info>*</info> %s seconds', $elapsed));

            // Remove plaintext archive
            unlink($archive_path);

            $final_artifact_path = $encrypted_path;
            $final_artifact_name = $encrypted_name;
          }

          $destination = rtrim($local_path, '/') . '/' . $final_artifact_name;
          if (file_exists($destination)) {
            unlink($destination);
          }
          rename($final_artifact_path, $destination);
          $this->output->writeln(sprintf(' <info>*</info> %s', ($this->getShortPath)($destination)));

          if ($latest) {
            $this->output->writeln(' <info>*</info> latest symlink created.');
            $symlink_path = rtrim($local_path, '/') . '/' . $latest_symlink_name . ($encrypt ? '.tar.gz.enc' : '.tar.gz');
            if (file_exists($symlink_path) || is_link($symlink_path)) {
              $this->processRunner->run(['rm', '-rf', $symlink_path]);
            }
            $cwd = getcwd();
            chdir($local_path);
            symlink($final_artifact_name, basename($symlink_path));
            chdir($cwd);
          }
        }
        else {
          $destination = rtrim($local_path, '/') . '/' . $full_object_name;
          if (is_dir($destination)) {
            $this->processRunner->run(['rm', '-rf', $destination]);
          }
          rename($staging_dir, $destination);
          $this->output->writeln(sprintf(' <info>*</info> %s', ($this->getShortPath)($destination)));

          if ($latest) {
            $this->output->writeln(' <info>*</info> latest symlink created.');
            $symlink_path = rtrim($local_path, '/') . '/' . $latest_symlink_name;
            if (file_exists($symlink_path) || is_link($symlink_path)) {
              $this->processRunner->run(['rm', '-rf', $symlink_path]);
            }
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
            throw new \RuntimeException('Could not encrypt object: ' . $this->processRunner->redact($process->getErrorOutput()));
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

        $s3 = new S3Service(
          $this->config['aws_region'],
          $this->config['aws_bucket'],
          $this->config['aws_access_key_id'],
          $this->config['aws_secret_access_key']
        );
        $s3->upload($final_artifact_name, $final_artifact_path);

        // Prune
        $s3->pruneByRetention($this->config['aws_retention']);
      }

      $this->output->writeln('<info>Backup completed</info>');
    }
    finally {
      $this->tempDirectoryFactory->cleanup($temp_work_dir);
    }
  }

  private function sendNotification(bool $success, $local_path, int $options, float $start_time, \Exception $exception = NULL): void {
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
    $has_db = (bool) ($options & BackupOptions::DATABASE);
    $has_files = (bool) ($options & BackupOptions::FILES);
    if ($has_db && $has_files) {
      throw new \InvalidArgumentException('The DATABASE and FILES options cannot be combined.');
    }
    if (($options & BackupOptions::ENCRYPT) && !($options & BackupOptions::GZIP)) {
      throw new \InvalidArgumentException('The ENCRYPT option requires GZIP to be set.');
    }
    if (($options & BackupOptions::LATEST) && !$is_local) {
      throw new \InvalidArgumentException('The LATEST option may only be used with a local destination.');
    }
  }
}
