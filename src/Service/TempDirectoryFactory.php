<?php

namespace AKlump\WebsiteBackup\Service;

use Symfony\Component\Filesystem\Filesystem;

class TempDirectoryFactory {

  private string $temp_base;

  private Filesystem $filesystem;

  public function __construct(string $temp_base = NULL) {
    $this->temp_base = $temp_base ?? sys_get_temp_dir();
    $this->filesystem = new Filesystem();
  }

  /**
   * Create a unique temp directory with restrictive permissions.
   *
   * @param string $prefix
   * @param int $mode
   *
   * @return string The absolute path to the created directory.
   */
  public function create(string $prefix = 'website_backup_', int $mode = 0700): string {
    $path = $this->temp_base . '/' . $prefix . bin2hex(random_bytes(16));
    
    if ($this->filesystem->exists($path)) {
      // Very unlikely with random_bytes(16), but let's be safe.
      return $this->create($prefix, $mode);
    }

    $this->filesystem->mkdir($path, $mode);
    // Ensure permissions are exactly $mode even if umask interferes.
    $this->filesystem->chmod($path, $mode);

    return $path;
  }

  /**
   * Remove a directory and all its contents.
   *
   * @param string $path
   */
  public function cleanup(string $path): void {
    if ($this->filesystem->exists($path)) {
      $this->filesystem->remove($path);
    }
  }

}
