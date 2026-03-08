<?php

namespace AKlump\WebsiteBackup\Helper;

class GetShortPath {

  private string $basepath;

  public function __construct(string $basepath = NULL) {
    $this->basepath = $basepath ?? getcwd();
  }

  public function __invoke(string $path): string {
    if (!str_starts_with($path, $this->basepath)) {
      return $path;
    }

    $relative = substr($path, strlen($this->basepath));
    if ($relative === '') {
      return '.';
    }
    $relative = ltrim($relative, '/');

    if ($this->basepath === getcwd()) {
      return "./$relative";
    }

    return $relative;
  }
}
