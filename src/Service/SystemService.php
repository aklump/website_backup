<?php

namespace AKlump\WebsiteBackup\Service;

class SystemService {

  /**
   * @var \AKlump\WebsiteBackup\Service\ProcessRunner
   */
  protected ProcessRunner $processRunner;

  public function __construct(ProcessRunner $processRunner) {
    $this->processRunner = $processRunner;
  }

  public function isWindows(): bool {
    return (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows')
      || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
      || DIRECTORY_SEPARATOR === '\\';
  }

  public function commandExists(string $cmd): bool {
    if ($cmd === '') {
      return FALSE;
    }

    if (strpbrk($cmd, '/\\') !== FALSE) {
      return $this->isExecutableCommand($cmd);
    }

    $path = getenv('PATH') ?: '';
    $paths = array_filter(explode(PATH_SEPARATOR, $path));
    $is_windows = $this->isWindows();

    $extensions = [''];
    if ($is_windows) {
      $pathext = getenv('PATHEXT') ?: '.EXE;.BAT;.CMD;.COM';
      $extensions = array_filter(array_map('trim', explode(';', $pathext)));
      array_unshift($extensions, '');

      $cmd_ext = pathinfo($cmd, PATHINFO_EXTENSION);
      if ($cmd_ext !== '') {
        $extensions = [''];
      }
    }

    foreach ($paths as $dir) {
      $dir = rtrim($dir, '/\\');
      if ($dir === '') {
        continue;
      }

      foreach ($extensions as $ext) {
        $candidate = $dir . DIRECTORY_SEPARATOR . $cmd;
        if ($ext !== '') {
          $candidate .= $ext;
        }
        if ($this->isExecutableCommand($candidate)) {
          return TRUE;
        }
      }
    }

    $fallbacks = $is_windows
      ? [
        ['where', $cmd],
      ]
      : [
        ['which', $cmd],
      ];

    foreach ($fallbacks as $fallback) {
      try {
        $process = $this->processRunner->run($fallback);
        if ($process->isSuccessful() && trim($process->getOutput()) !== '') {
          return TRUE;
        }
      }
      catch (\Throwable $e) {
      }
    }

    return FALSE;
  }

  private function isExecutableCommand(string $path): bool {
    if (!file_exists($path) || is_dir($path)) {
      return FALSE;
    }

    if ($this->isWindows()) {
      return TRUE;
    }

    return is_executable($path);
  }
}
