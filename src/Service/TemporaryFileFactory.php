<?php

namespace App\Service;

use Symfony\Component\Filesystem\Filesystem;

class TemporaryFileFactory {

  private Filesystem $filesystem;

  public function __construct() {
    $this->filesystem = new Filesystem();
  }

  /**
   * Create a temporary file with restrictive permissions.
   *
   * @param string $directory The directory where to create the file.
   * @param string $content The content to write to the file.
   * @param string $prefix
   * @param int $mode
   *
   * @return string The absolute path to the created file.
   */
  public function create(string $directory, string $content, string $prefix = 'wb_tmp_', int $mode = 0600): string {
    if (!$this->filesystem->exists($directory)) {
      $this->filesystem->mkdir($directory, 0700);
    }

    $path = $directory . '/' . $prefix . bin2hex(random_bytes(16));
    
    if ($this->filesystem->exists($path)) {
      return $this->create($directory, $content, $prefix, $mode);
    }

    $this->filesystem->dumpFile($path, $content);
    $this->filesystem->chmod($path, $mode);

    return $path;
  }

  /**
   * Remove a file.
   *
   * @param string $path
   */
  public function cleanup(string $path): void {
    if ($this->filesystem->exists($path)) {
      $this->filesystem->remove($path);
    }
  }

}
