<?php

/**
 * Generate the bash code to evaluate that will copy files and directories.
 */

use Cloudy\AKlump\WebsiteBackup\DefaultHandler;

require_once __DIR__ . '/../../vendor/autoload.php';

$manifest = $argv;
array_shift($manifest);
$source_dir = array_shift($manifest);
$destination_dir = array_shift($manifest);

try {
  $handler = new DefaultHandler();
  $commands = $handler
    ->setSource($source_dir)
    ->setDestination($destination_dir)
    ->setManifest($manifest)
    ->getCommands();

  echo sprintf('declare -a commands=("%s")',
    implode('" "', $commands)
  );
  exit(0);
}
catch (\Exception $exception) {
  echo $exception->getMessage();
  exit(1);
}
