<?php

namespace App\Command;

use App\Config\ConfigLoader;
use App\Helper\GetInstalledInRoot;
use App\Service\UnpackService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UnpackCommand extends Command {

  protected static $defaultName = 'backup:unpack';

  protected function configure(): void {
    $this
      ->setDescription('Decrypts and extracts a backup archive next to the source file.')
      ->addArgument('source', InputArgument::REQUIRED, 'The path to the backup artifact.')
      ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite the destination directory if it exists.')
      ->addOption('delete-source', NULL, InputOption::VALUE_NONE, 'Delete the original source file after successful unpacking.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $root = (new GetInstalledInRoot())();
    $loader = new ConfigLoader($root);
    $config = $loader->load();

    $source = $input->getArgument('source');
    // If source is relative, make it absolute based on current directory
    if (!str_starts_with($source, '/')) {
      $source = getcwd() . '/' . $source;
    }

    $service = new UnpackService($config, $output);
    $service->unpack(
      $source,
      $input->getOption('force'),
      $input->getOption('delete-source')
    );

    return Command::SUCCESS;
  }
}
