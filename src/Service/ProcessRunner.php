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

  public function redact(string $text): string {
    // Redact password from --defaults-extra-file
    $text = preg_replace('/--defaults-extra-file=[^ ]+/', '--defaults-extra-file=[REDACTED]', $text);
    // Redact password from command arguments if any remain
    $text = preg_replace('/--password=[^ ]+/', '--password=[REDACTED]', $text);
    $text = preg_replace('/-p[^ ]+/', '-p[REDACTED]', $text);

    return $text;
  }
}
