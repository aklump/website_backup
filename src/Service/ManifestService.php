<?php

namespace AKlump\WebsiteBackup\Service;

class ManifestService {

  private $configDir;

  private $destination;

  private $manifest;

  private $projectRoot;

  private $excludes = [];

  public function __construct(string $config_dir, string $destination, array $manifest, string $project_root = '') {
    $this->configDir = rtrim($config_dir, '/');
    $this->destination = rtrim($destination, '/');
    $this->projectRoot = rtrim($project_root, '/');
    $this->setManifest($manifest);
  }

  private function setManifest(array $manifest_items): void {
    $this->manifest = array_unique($manifest_items);
  }

  public function getManifestItems(): array {
    return $this->manifest;
  }

  /**
   * Resolve a manifest pattern to absolute paths on disk.
   *
   * @param string $pattern
   *
   * @return array
   */
  public function resolve(string $pattern): array {
    $pattern = ltrim($pattern, '!');
    $paths = [];
    if (strpos($pattern, '*') !== false) {
      $globbed = glob($pattern);
      if ($globbed) {
        $paths = $globbed;
      }
    } elseif (file_exists($pattern)) {
      $paths[] = $pattern;
    }

    if ($this->projectRoot) {
      $check_root = rtrim(realpath($this->projectRoot) ?: $this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
      foreach ($paths as $path) {
        $real_path = realpath($path) ?: $path;
        $check_path = rtrim($real_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($check_path, $check_root)) {
          throw new \RuntimeException(sprintf('Manifest item "%s" resolves to a path outside of the project root: %s', $pattern, $path));
        }
      }
    }

    return $paths;
  }

  public function getCommands(): array {
    $includes = array_map(function ($item) {
      $item = rtrim($item, '/');
      if ($this->projectRoot) {
        $check_root = rtrim(realpath($this->projectRoot) ?: $this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $real_path = realpath($item) ?: $item;
        $check_path = rtrim($real_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($check_path, $check_root) && !str_starts_with($item, '!')) {
          // This should have been caught by resolve() if used, but for safety in getCommands:
          throw new \RuntimeException(sprintf('Manifest item "%s" is outside of the project root.', $item));
        }
      }

      return $item;
    }, array_filter($this->manifest, function ($item) {
      return substr($item, 0, 1) !== '!';
    }));
    $includes = $this->handleGlobs($includes);

    $this->excludes = array_map(function ($item) {
      $item = rtrim(ltrim($item, '!'), '/');
      if ($this->projectRoot) {
        $check_root = rtrim(realpath($this->projectRoot) ?: $this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $real_path = realpath($item) ?: $item;
        $check_path = rtrim($real_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($check_path, $check_root)) {
          throw new \RuntimeException(sprintf('Manifest exclusion "%s" is outside of the project root.', $item));
        }
      }

      return $item;
    }, array_filter($this->manifest, function ($item) {
      return substr($item, 0, 1) === '!';
    }));
    $this->excludes = $this->handleGlobs($this->excludes);

    $commands = [];
    foreach ($includes as $item) {
      $source = $item;
      $relative_path = $this->getRelativePath($source);

      if (is_file($source)) {
        $dir = dirname($this->destination . '/' . $relative_path);
        $commands['mkdir'][$dir] = [$dir];
        $commands['cp'][] = [$source, $this->destination . '/' . $relative_path];
      }
      elseif (is_dir($source)) {
        $dest_dir = $this->destination . '/' . $relative_path;
        $commands['mkdir'][$dest_dir] = [$dest_dir];

        $rsync_args = [
          rtrim($source, '/') . '/',
          rtrim($dest_dir, '/') . '/',
        ];
        foreach ($this->pluckExcludes($item) as $exclusion) {
          $rsync_args[] = '--exclude=' . $exclusion;
        }
        $commands['rsync'][] = $rsync_args;
      }
      else {
        // Log or handle missing source file/directory if needed
        // For now, we follow previous behavior of skipping if resolve didn't find it
        // but resolve is called before getCommands in some flows.
      }
    }

    return $this->prepareCommands($commands);
  }

  private function getRelativePath(string $path): string {
    $real_path = realpath($path) ?: $path;
    $real_config_dir = realpath($this->configDir) ?: $this->configDir;

    $norm_path = preg_replace('|^/private/var/|', '/var/', $real_path);
    $norm_config_dir = preg_replace('|^/private/var/|', '/var/', $real_config_dir);

    if (str_starts_with($norm_path, $norm_config_dir)) {
      $relative = substr($norm_path, strlen($norm_config_dir));

      return ltrim($relative, '/');
    }

    return basename($path);
  }

  private function prepareCommands(array $commands): array {
    $prepared = [];
    if (isset($commands['mkdir']) && count($commands['mkdir']) > 1) {
      ksort($commands['mkdir']);
      $keys = array_keys($commands['mkdir']);
      $haystack = serialize($keys);
      foreach ($keys as $key) {
        if (strstr($haystack, '"' . $key . '/') !== FALSE) {
          unset($commands['mkdir'][$key]);
        }
      }
    }

    foreach ($commands['mkdir'] ?? [] as $args) {
      $prepared[] = array_merge(['mkdir', '-p'], $args);
    }
    foreach ($commands['rsync'] ?? [] as $args) {
      $prepared[] = array_merge(['rsync', '-a'], $args);
    }
    foreach ($commands['cp'] ?? [] as $args) {
      $prepared[] = array_merge(['cp'], $args);
    }

    return $prepared;
  }

  private function pluckExcludes(string $include): array {
    $found = array_filter($this->excludes, function ($item) use ($include) {
      return strpos($item, $include) === 0;
    });
    $this->excludes = array_diff($this->excludes, $found);

    return array_map(function ($item) use ($include) {
      return '/' . substr($item, strlen($include) + 1);
    }, $found);
  }

  private function handleGlobs(array $items): array {
    $revised = [];
    foreach ($items as $item) {
      if (strstr($item, '*') !== FALSE) {
        $globbed = glob($item);
        foreach ($globbed as $g) {
          $revised[] = $g;
        }
      }
      else {
        $revised[] = $item;
      }
    }

    return $revised;
  }
}
