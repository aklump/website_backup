<?php

namespace AKlump\WebsiteBackup\Tests\Command;

use AKlump\WebsiteBackup\Command\GenerateCrontabCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \AKlump\WebsiteBackup\Command\GenerateCrontabCommand
 * @uses \AKlump\WebsiteBackup\Helper\GetInstalledInRoot
 * @uses \AKlump\WebsiteBackup\Config\ConfigLoader
 */
class GenerateCrontabCommandTest extends TestCase {

  private $test_dir;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/wb_crontab_test_' . bin2hex(random_bytes(8));
    mkdir($this->test_dir, 0700, TRUE);
    mkdir($this->test_dir . '/bin/config', 0700, TRUE);
    touch($this->test_dir . '/bin/config/website_backup.yml');
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

  public function testGenerateFull() {
    $application = new Application();
    $application->add(new GenerateCrontabCommand());

    $command = $application->find('generate:crontab');
    $command_tester = new CommandTester($command);

    // Inputs: php_path, tmpdir
    $php_dir = dirname(PHP_BINARY);
    $tmp_dir = sys_get_temp_dir();
    $command_tester->setInputs([$tmp_dir, $php_dir]);
    $command_tester->execute([]);

    $output = $command_tester->getDisplay();
    // Resolve /var vs /private/var on macOS
    $expected_project_root = realpath($this->test_dir);
    $expected_project_root = rtrim($expected_project_root, '/') . '/';

    $this->assertStringContainsString('TMPDIR="' . $tmp_dir . '"', $output);
    $this->assertStringContainsString('PATH="' . $php_dir . ':$PATH"', $output);
    $this->assertStringContainsString('"' . $expected_project_root . 'vendor/bin/website-backup"', $output);
    $this->assertStringContainsString('--config "' . $expected_project_root . 'bin/config/website_backup.yml"', $output);
    $this->assertStringContainsString('--env-file "' . $expected_project_root . '.env"', $output);
    $this->assertStringContainsString('backup:s3 -f --notify', $output);
  }

  public function testGenerateDefaults() {
    $application = new Application();
    $application->add(new GenerateCrontabCommand());

    $command = $application->find('generate:crontab');
    $command_tester = new CommandTester($command);

    // Inputs: empty strings to accept defaults
    $command_tester->setInputs(['', '']);
    $command_tester->execute([]);

    $output = $command_tester->getDisplay();

    $php_dir = dirname(PHP_BINARY);
    $tmp_dir = sys_get_temp_dir();

    $this->assertStringContainsString('TMPDIR="' . $tmp_dir . '"', $output);
    $this->assertStringNotContainsString('PATH="' . $php_dir . ':$PATH"', $output);
    $this->assertStringContainsString('vendor/bin/website-backup', $output);
  }

  public function testFailsWithoutConfig() {
    // Remove the config file to simulate missing installation
    unlink($this->test_dir . '/bin/config/website_backup.yml');

    $application = new Application();
    $application->add(new GenerateCrontabCommand());

    $command = $application->find('generate:crontab');
    $command_tester = new CommandTester($command);

    $exit_code = $command_tester->execute([]);
    $this->assertEquals(1, $exit_code);
    $this->assertStringContainsString('Could not find the application root', $command_tester->getDisplay());
  }
}
