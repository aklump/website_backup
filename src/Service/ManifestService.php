<?php

namespace AKlump\WebsiteBackup\Service;

class ManifestService {

  private $source;

  private $destination;

  private $manifest;

  private $excludes = [];

  public function __construct(string $source, string $destination, array $manifest) {
    $this->source = rtrim($source, '/');
    $this->destination = rtrim($destination, '/');
    $this->setManifest($manifest);
  }

  private function setManifest(array $manifest_items): void {
    foreach ($manifest_items as $item) {
      if (substr($item, 0, 1) === '/' || substr($item, 0, 2) === '!/') {
        throw new \InvalidArgumentException(sprintf('Incorrect manifest item "%s". Only paths relative to config var "path_to_app" are allowed in the manifest.', $item));
      }
    }
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
      $globbed = glob($this->source . '/' . $pattern);
      if ($globbed) {
        $paths = $globbed;
      }
    } elseif (file_exists($this->source . '/' . $pattern)) {
      $paths[] = $this->source . '/' . $pattern;
    }

    return $paths;
  }

  public function getCommands(): array {
    $includes = array_map(function ($item) {
      return rtrim($item, '/');
    }, array_filter($this->manifest, function ($item) {
      return substr($item, 0, 1) !== '!';
    }));
    $includes = $this->handleGlobs($includes);

    $this->excludes = array_map(function ($item) {
      return rtrim(ltrim($item, '!'), '/');
    }, array_filter($this->manifest, function ($item) {
      return substr($item, 0, 1) === '!';
    }));
    $this->excludes = $this->handleGlobs($this->excludes);

    $commands = [];
    foreach ($includes as $item) {
      $source = $this->source . '/' . $item;
      if (is_file($source)) {
        $dir = dirname($this->destination . '/' . $item);
        $commands['mkdir'][$dir] = [$dir];
        $commands['cp'][] = [$source, $this->destination . '/' . $item];
      }
      elseif (is_dir($source)) {
        $dest_dir = $this->destination . '/' . $item;
        $commands['mkdir'][$dest_dir] = [$dest_dir];

        $rsync_args = [
          $this->source . '/' . trim($item, '/') . '/',
          $this->destination . '/' . trim($item, '/') . '/',
        ];
        foreach ($this->pluckExcludes($item) as $exclusion) {
          $rsync_args[] = '--exclude=' . $exclusion;
        }
        $commands['rsync'][] = $rsync_args;
      }
    }

    return $this->prepareCommands($commands);
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
        $globbed = glob($this->source . '/' . $item);
        foreach ($globbed as $g) {
          $revised[] = substr($g, strlen($this->source) + 1);
        }
      }
      else {
        $revised[] = $item;
      }
    }

    return $revised;
  }
}
