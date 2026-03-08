<?php

namespace AKlump\WebsiteBackup\Tests\Command;

use AKlump\WebsiteBackup\Command\InstallCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \AKlump\WebsiteBackup\Command\InstallCommand
 * @uses \AKlump\WebsiteBackup\Config\ConfigLoader
 * @uses \AKlump\WebsiteBackup\Helper\GetShortPath
 */
class InstallCommandTest extends TestCase {

  private $test_dir;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/website_backup_install_test_' . bin2hex(random_bytes(8));
    mkdir($this->test_dir, 0700, TRUE);
    chdir($this->test_dir);
  }

  protected function tearDown(): void {
    $this->removeDirectory($this->test_dir);
  }

  private function removeDirectory($dir) {
    if (!is_dir($dir)) {
      return;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
      (is_dir("$dir/$file")) ? $this->removeDirectory("$dir/$file") : unlink("$dir/$file");
    }

    return rmdir($dir);
  }

  public function testExecute() {
    $application = new Application();
    $application->add(new InstallCommand());

    $command = $application->find('install');
    $command_tester = new CommandTester($command);
    $command_tester->setInputs(['yes']);
    $command_tester->execute([]);

    $this->assertFileExists($this->test_dir . '/bin/config/website_backup.yml');
    $this->assertFileExists($this->test_dir . '/.env');

    $env_content = file_get_contents($this->test_dir . '/.env');
    $this->assertStringContainsString('WEBSITE_BACKUP_AWS_ACCESS_KEY_ID=', $env_content);
    $this->assertStringContainsString('WEBSITE_BACKUP_AWS_SECRET_ACCESS_KEY=', $env_content);
    $this->assertStringContainsString('WEBSITE_BACKUP_DATABASE_PASSWORD=', $env_content);
  }

  public function testExecuteWithForce() {
    $application = new Application();
    $application->add(new InstallCommand());

    $command = $application->find('install');
    $command_tester = new CommandTester($command);
    $command_tester->execute(['--force' => TRUE]);

    $this->assertFileExists($this->test_dir . '/bin/config/website_backup.yml');
    $this->assertFileExists($this->test_dir . '/.env');
  }

  public function testExecuteAborted() {
    $application = new Application();
    $application->add(new InstallCommand());

    $command = $application->find('install');
    $command_tester = new CommandTester($command);
    $command_tester->setInputs(['no']);
    $command_tester->execute([]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Installation aborted.', $output);
    $this->assertFileDoesNotExist($this->test_dir . '/bin/config/website_backup.yml');
  }

  public function testExecuteWithExistingEnv() {
    file_put_contents($this->test_dir . '/.env', "EXISTING_VAR=foo");

    $application = new Application();
    $application->add(new InstallCommand());

    $command = $application->find('install');
    $command_tester = new CommandTester($command);
    $command_tester->setInputs(['yes']);
    $command_tester->execute([]);

    $env_content = file_get_contents($this->test_dir . '/.env');
    $this->assertStringContainsString("EXISTING_VAR=foo\nWEBSITE_BACKUP_AWS_ACCESS_KEY_ID=", $env_content);
  }
}
