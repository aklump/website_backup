<?php

namespace AKlump\WebsiteBackup\Helper;

/**
 * Locate the root path where this is installed
 */
class GetInstalledInRoot {

  public function __invoke(): string {
    $basename = 'bin/config/website_backup.yml';
    $result = $this->upfind($basename);
    if (!$result) {
      return '';
    }

    return $result;
  }

  private function upfind($basename, $start_dir = NULL) {
    $path = $start_dir;
    if (!isset($path)) {
      $path = getcwd();
    }
    while ($path
      && $path !== DIRECTORY_SEPARATOR
      && ($expected = $path . DIRECTORY_SEPARATOR . $basename)
      && !file_exists($expected)) {
      $path = dirname($path);
      unset($expected);
    }
    if (empty($expected)) {
      return '';
    }

    return $path;
  }

}
