<?php

namespace Cloudy\AKlump\WebsiteBackup;

final class DefaultHandler {

  /**
   * @var array
   */
  private $manifest;

  private $source = '';

  private $destination = '';

  private $excludes = [];

  public function setDestination(string $destination_dir): self {
    if (substr($destination_dir, 0, 1) !== '/') {
      throw new \InvalidArgumentException(sprintf('The destination directory, must be absolute, it must begin with a forward slash: "%s" does not', $destination_dir));
    }
    $this->destination = rtrim($destination_dir, '/');

    return $this;
  }

  public function setSource(string $source_dir): self {
    $this->source = rtrim($source_dir, '/');

    return $this;
  }

  public function setManifest(array $manifest_items): DefaultHandler {

    // Do not allow absolute paths in the manifest.
    $absolute_paths = array_filter($manifest_items, function ($item) {
      return substr($item, 0, 1) === '/' || substr($item, 0, 2) === '!/';
    });
    if (count($absolute_paths)) {
      throw new \InvalidArgumentException(sprintf('Incorrect manifest item "%s". Only paths relative to config var "path_to_app" are allowed in the manifest.', array_values($absolute_paths)[0]));
    }

    $manifest_items = array_unique($manifest_items);
    $this->manifest = $manifest_items;

    return $this;
  }

  /**
   * @return array
   *   An array of bash commands that will migrate the files based on manifest.
   */
  public function getCommands(): array {
    if (empty($this->destination)) {
      throw new \RuntimeException('You must call setDestination() first.');
    }

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
        $command = dirname($this->destination . '/' . $item);
        $commands['mkdir'][$command] = [$command];

        $command = [$source, $this->destination . '/' . $item];
        $cid = md5(json_encode($command));
        $commands['cp'][$cid] = $command;
      }
      elseif (is_dir($source)) {
        $command = $this->destination . '/' . $item;
        $commands['mkdir'][$command] = [$command];

        $command = array_merge([
          $this->source . '/' . trim($item, '/') . '/',
          $this->destination . '/' . trim($item, '/') . '/',
        ], array_map(function ($exclusion) {
          return '--exclude=' . $exclusion;
        }, $this->pluckExcludes($item)));
        $cid = md5(json_encode($command));
        $commands['rsync'][$cid] = $command;
      }
      else {
        // TODO Decide how to handle missing source?
        continue;
      }
    }

    return $this->prepareCommands($commands);
  }

  private function prepareCommands(array $commands): array {
    $prepared = [];

    // This step will remove any parent directories to remove unnecessary mkdir
    // commands, since mkdir uses the "-p" parameter.
    if (count($commands['mkdir']) > 1) {
      ksort($commands['mkdir']);
      $haystack = serialize(array_keys($commands['mkdir']));
      foreach (array_keys($commands['mkdir']) as $key) {
        if (strstr($haystack, '"' . $key . '/') !== FALSE) {
          unset($commands['mkdir'][$key]);
        }
      }
    }
    $prepared = array_merge($prepared, array_map(function ($args) {
      return implode(' ', array_merge(['mkdir', '-p'], $args));
    }, $commands['mkdir'] ?? []));

    $prepared = array_merge($prepared, array_map(function ($args) {
      return implode(' ', array_merge(['rsync', '-a'], $args));
    }, $commands['rsync'] ?? []));

    $prepared = array_merge($prepared, array_map(function ($args) {
      return implode(' ', array_merge(['cp'], $args));
    }, $commands['cp'] ?? []));

    return array_values($prepared);
  }

  private function pluckExcludes(string $include): array {
    $excludes = array_filter($this->excludes, function ($item) use ($include) {
      return strpos($item, $include) === 0;
    });
    // Remove those matches from subsequent searches.
    $this->excludes = array_diff($this->excludes, $excludes);

    $excludes = array_map(function ($item) use ($include) {
      return '/' . substr($item, strlen($include) + 1);
    }, $excludes);

    return $excludes;
  }

  private function handleGlobs(array $includes) {
    $revised = [];
    foreach ($includes as $item) {
      if (strstr($item, '*') !== FALSE) {
        $items = glob($this->source . '/' . $item);
        $revised = array_merge($revised, array_map(function ($item) {
          return substr($item, strlen($this->source) + 1);
        }, $items));
      }
      else {
        $revised[] = $item;
      }
    }

    return $revised;
  }

}
