<?php

namespace AKlump\WebsiteBackup\Service;

use AKlump\WebsiteBackup\Helper\GetShortPath;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class UnpackService {

  private $config;
  private $output;
  private $processRunner;
  private $getShortPath;
  private $fs;
  private $tempDirectoryFactory;

  public function __construct(array $config, OutputInterface $output) {
    $this->config = $config;
    $this->output = $output;
    $this->processRunner = new ProcessRunner();
    $this->getShortPath = new GetShortPath();
    $this->fs = new Filesystem();
    $this->tempDirectoryFactory = new TempDirectoryFactory();
  }

  /**
   * Unpacks a backup archive.
   *
   * @param string $source_path The absolute path to the backup file.
   * @param bool $force Overwrite existing destination.
   * @param bool $delete_source Delete source file after success.
   *
   * @return string The path to the unpacked directory.
   */
  public function unpack(string $source_path, bool $force = false, bool $delete_source = false): string {
    if (!$this->fs->exists($source_path)) {
      throw new \RuntimeException(sprintf('Source file does not exist: %s', $source_path));
    }
    if (is_dir($source_path)) {
      throw new \RuntimeException(sprintf('Source path is a directory: %s', $source_path));
    }

    $filename = basename($source_path);
    $is_encrypted = str_ends_with($filename, '.tar.gz.enc');
    $is_compressed = str_ends_with($filename, '.tar.gz') || $is_encrypted;

    if (!$is_compressed) {
      throw new \RuntimeException(sprintf('Unsupported file extension for: %s', $filename));
    }

    $dest_name = $filename;
    if ($is_encrypted) {
      $dest_name = substr($dest_name, 0, -strlen('.tar.gz.enc'));
    } else {
      $dest_name = substr($dest_name, 0, -strlen('.tar.gz'));
    }
    $dest_path = dirname($source_path) . '/' . $dest_name;

    if ($this->fs->exists($dest_path)) {
      if (!$force) {
        throw new \RuntimeException(sprintf('Destination directory already exists: %s', ($this->getShortPath)($dest_path)));
      }
      $this->fs->remove($dest_path);
    }

    $this->validateDependencies($is_encrypted);

    $temp_base = $this->tempDirectoryFactory->create('website_backup_unpack_');
    $extract_to = $temp_base . '/extracted';
    $this->fs->mkdir($extract_to);

    try {
      $working_file = $source_path;

      if ($is_encrypted) {
        $this->output->writeln('<comment>Decrypting archive</comment>');
        if (empty($this->config['encryption']['password'])) {
          throw new \RuntimeException('Encryption password is not configured.');
        }
        $temp_decrypted = $temp_base . '/decrypted.tar.gz';
        $process = $this->processRunner->run(
          ['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-salt', '-in', $source_path, '-out', $temp_decrypted, '-pass', 'env:WEBSITE_BACKUP_ENCRYPTION_PASSWORD'],
          null,
          ['WEBSITE_BACKUP_ENCRYPTION_PASSWORD' => $this->config['encryption']['password']]
        );
        if (!$process->isSuccessful()) {
          throw new \RuntimeException('Decryption failed: ' . $this->processRunner->redact($process->getErrorOutput()));
        }
        $working_file = $temp_decrypted;
      }

      $this->output->writeln('<comment>Extracting archive</comment>');
      $process = $this->processRunner->run(['tar', '-xzf', $working_file, '-C', $extract_to]);
      if (!$process->isSuccessful()) {
        throw new \RuntimeException('Extraction failed: ' . $this->processRunner->redact($process->getErrorOutput()));
      }

      // Check for a single top-level directory in extract_to that matches the archive name (common with our backups)
      $extracted_items = array_diff(scandir($extract_to), ['.', '..']);
      if (count($extracted_items) === 1) {
        $single_item = reset($extracted_items);
        $single_path = $extract_to . '/' . $single_item;
        if (is_dir($single_path)) {
          $this->fs->rename($single_path, $dest_path);
        } else {
          $this->fs->rename($extract_to, $dest_path);
        }
      } else {
        $this->fs->rename($extract_to, $dest_path);
      }

      $this->output->writeln(sprintf('<info>Unpacked to: %s</info>', ($this->getShortPath)($dest_path)));

      if ($delete_source) {
        $this->fs->remove($source_path);
      }

    } finally {
      $this->tempDirectoryFactory->cleanup($temp_base);
    }

    return $dest_path;
  }

  private function validateDependencies(bool $require_openssl): void {
    if (!$this->commandExists('tar')) {
      throw new \RuntimeException('The "tar" tool is required but not found.');
    }
    if ($require_openssl && !$this->commandExists('openssl')) {
      throw new \RuntimeException('The "openssl" tool is required for encrypted backups but not found.');
    }
  }

  private function commandExists(string $cmd): bool {
    $process = $this->processRunner->run(['command', '-v', $cmd]);
    return $process->isSuccessful();
  }
}
