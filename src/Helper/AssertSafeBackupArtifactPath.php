<?php

namespace AKlump\WebsiteBackup\Helper;

/**
 * Validate a backup artifact destination before deletion.
 */
class AssertSafeBackupArtifactPath {

  /**
   * @param string $path The path to validate.
   * @param string $local_backup_root The configured local backup directory.
   * @param string $expected_basename_prefix The expected prefix of the artifact basename.
   *
   * @throws \InvalidArgumentException If the path is unsafe for deletion.
   */
  public function __invoke(string $path, string $local_backup_root, string $expected_basename_prefix): void {
    if (empty(trim($path))) {
      throw new \InvalidArgumentException('Backup artifact path cannot be empty.');
    }

    $real_path = realpath($path) ?: $path;
    $real_root = realpath($local_backup_root) ?: $local_backup_root;

    // Ensure root ends with a slash for containment check to avoid sibling-prefix tricks
    $check_root = rtrim($real_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $check_path = rtrim($real_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if ($real_path === $real_root) {
      throw new \InvalidArgumentException(sprintf('Cannot delete the backup root directory: %s', $path));
    }

    if (!str_starts_with($check_path, $check_root)) {
      throw new \InvalidArgumentException(sprintf('Path is outside the configured local backup directory: %s', $path));
    }

    if (is_link($path)) {
      throw new \InvalidArgumentException(sprintf('Recursive deletion refused for symlink: %s', $path));
    }

    $basename = basename($path);
    if (!str_starts_with($basename, $expected_basename_prefix)) {
      throw new \InvalidArgumentException(sprintf('Path basename "%s" does not match the expected backup artifact prefix: %s', $basename, $expected_basename_prefix));
    }
  }

}
