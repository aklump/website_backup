<?php

namespace AKlump\WebsiteBackup\Helper;

/**
 * Remove a directory tree safely using PHP.
 */
class RemoveDirectoryTree {

  /**
   * @param string $path The absolute path to the directory to remove.
   *
   * @throws \RuntimeException If deletion fails.
   * @throws \InvalidArgumentException If path is not a directory or is a symlink.
   */
  public function __invoke(string $path): void {
    if (empty(trim($path)) || $path === DIRECTORY_SEPARATOR) {
      throw new \InvalidArgumentException(sprintf('Invalid path for directory removal: "%s"', $path));
    }
    if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
      throw new \InvalidArgumentException(sprintf('Path must be absolute for directory removal: %s', $path));
    }
    if (!is_dir($path)) {
      throw new \InvalidArgumentException(sprintf('Path is not a directory: %s', $path));
    }
    if (is_link($path)) {
      throw new \InvalidArgumentException(sprintf('Refusing to recursively delete a symlink: %s', $path));
    }

    $this->removeRecursive($path);
  }

  /**
   * @param string $dir
   */
  private function removeRecursive(string $dir): void {
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
      $path = $dir . DIRECTORY_SEPARATOR . $file;
      if (is_dir($path) && !is_link($path)) {
        $this->removeRecursive($path);
      } else {
        if (!unlink($path)) {
          throw new \RuntimeException(sprintf('Failed to delete file: %s', $path));
        }
      }
    }

    if (!rmdir($dir)) {
      throw new \RuntimeException(sprintf('Failed to delete directory: %s', $dir));
    }
  }

}
