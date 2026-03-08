<?php

namespace AKlump\WebsiteBackup\Command;

use AKlump\WebsiteBackup\Config\ConfigLoader;
use AKlump\WebsiteBackup\Helper\GetInstalledInRoot;
use AKlump\WebsiteBackup\Service\UnpackService;
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
      ->addOption('config', NULL, InputOption::VALUE_REQUIRED, 'Path to the configuration file.')
      ->addOption('env-file', NULL, InputOption::VALUE_REQUIRED, 'Path to the environment file.')
      ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite the destination directory if it exists.')
      ->addOption('delete-source', NULL, InputOption::VALUE_NONE, 'Delete the original source file after successful unpacking.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $root = (new GetInstalledInRoot())();
    $config_path = $input->getOption('config');
    $env_path = $input->getOption('env-file');
    $loader = new ConfigLoader($root, $config_path, $env_path);
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
