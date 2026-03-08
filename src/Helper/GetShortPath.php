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

    $short_path = substr($path, strlen($this->basepath) + 1);
    if ($this->basepath === getcwd()) {
      $short_path = "./$short_path";
    }

    return $short_path;
  }
}
