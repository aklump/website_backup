<?php

namespace App\Service;

use Symfony\Component\Process\Process;

class ProcessRunner {

  public function run(array $command, ?string $cwd = NULL, ?array $env = NULL): Process {
    $process = new Process($command, $cwd, $env);
    $process->setTimeout(3600);
    $process->run();

    return $process;
  }
}
