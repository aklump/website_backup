<?php

namespace AKlump\WebsiteBackup\Helper;

/**
 * Remove an existing file or symlink safely.
 */
class RemoveFileOrSymlink {

  /**
   * @param string $path
   *
   * @throws \InvalidArgumentException If path is a directory.
   * @throws \RuntimeException If deletion fails.
   */
  public function __invoke(string $path): void {
    if (is_dir($path) && !is_link($path)) {
      throw new \InvalidArgumentException(sprintf('Cannot remove directory using RemoveFileOrSymlink: %s', $path));
    }

    if (file_exists($path) || is_link($path)) {
      if (!unlink($path)) {
        throw new \RuntimeException(sprintf('Failed to remove file or symlink: %s', $path));
      }
    }
  }

}
