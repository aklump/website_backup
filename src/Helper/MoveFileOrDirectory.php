<?php

namespace AKlump\WebsiteBackup\Helper;

/**
 * Safely move a file or directory, including across filesystems.
 */
class MoveFileOrDirectory {

  /**
   * @param string $source The absolute path to the source file or directory.
   * @param string $destination The absolute path to the destination.
   *
   * @throws \RuntimeException If move fails.
   */
  public function __invoke(string $source, string $destination): void {
    if (!file_exists($source)) {
      throw new \RuntimeException(sprintf('Source path does not exist: %s', $source));
    }

    // 1. Try rename() first
    if (@rename($source, $destination)) {
      return;
    }

    // 2. Fallback if rename() failed (likely cross-filesystem)
    if (is_dir($source)) {
      $this->moveDirectory($source, $destination);
    }
    else {
      $this->moveFile($source, $destination);
    }
  }

  /**
   * @param string $source
   * @param string $destination
   */
  private function moveFile(string $source, string $destination): void {
    if (!copy($source, $destination)) {
      throw new \RuntimeException(sprintf('Failed to copy file from %s to %s', $source, $destination));
    }
    if (!file_exists($destination)) {
      throw new \RuntimeException(sprintf('Destination file was not created: %s', $destination));
    }
    if (!unlink($source)) {
      throw new \RuntimeException(sprintf('Failed to remove source file after copy: %s', $source));
    }
  }

  /**
   * @param string $source
   * @param string $destination
   */
  private function moveDirectory(string $source, string $destination): void {
    if (!is_dir($destination) && !mkdir($destination, 0755, TRUE)) {
      throw new \RuntimeException(sprintf('Failed to create destination directory: %s', $destination));
    }

    $this->copyRecursive($source, $destination);

    if (!file_exists($destination)) {
      throw new \RuntimeException(sprintf('Destination directory was not created: %s', $destination));
    }

    (new RemoveDirectoryTree())($source);
  }

  /**
   * @param string $source
   * @param string $destination
   */
  private function copyRecursive(string $source, string $destination): void {
    $dir = opendir($source);
    while (($file = readdir($dir)) !== FALSE) {
      if ($file === '.' || $file === '..') {
        continue;
      }
      $srcPath = $source . DIRECTORY_SEPARATOR . $file;
      $destPath = $destination . DIRECTORY_SEPARATOR . $file;

      if (is_dir($srcPath)) {
        if (!is_dir($destPath) && !mkdir($destPath, 0755)) {
          throw new \RuntimeException(sprintf('Failed to create subdirectory: %s', $destPath));
        }
        $this->copyRecursive($srcPath, $destPath);
      }
      else {
        if (!copy($srcPath, $destPath)) {
          throw new \RuntimeException(sprintf('Failed to copy file: %s', $srcPath));
        }
      }
    }
    closedir($dir);
  }
}
