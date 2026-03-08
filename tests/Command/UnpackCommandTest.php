<?php

namespace AKlump\WebsiteBackup\Tests\Command;

use AKlump\WebsiteBackup\Command\UnpackCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @covers \AKlump\WebsiteBackup\Command\UnpackCommand
 * @uses \AKlump\WebsiteBackup\Config\ConfigLoader
 * @uses \AKlump\WebsiteBackup\Service\UnpackService
 * @uses \AKlump\WebsiteBackup\Service\ProcessRunner
 * @uses \AKlump\WebsiteBackup\Helper\GetShortPath
 * @uses \AKlump\WebsiteBackup\Helper\GetInstalledInRoot
 * @uses \AKlump\WebsiteBackup\Service\TempDirectoryFactory
 * @uses \AKlump\WebsiteBackup\Service\SystemService
 */
class UnpackCommandTest extends TestCase {

  private $test_dir;

  private $fs;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/website_backup_unpack_command_test_' . bin2hex(random_bytes(8));
    $this->fs = new Filesystem();
    $this->fs->mkdir($this->test_dir, 0700);
    $this->fs->mkdir($this->test_dir . '/bin/config');
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', "manifest: [foo]\ndatabase: { handler: null }");
    chdir($this->test_dir);
  }

  protected function tearDown(): void {
    $this->fs->remove($this->test_dir);
  }

  public function testExecute() {
    $source_dir = $this->test_dir . '/my_backup';
    $this->fs->mkdir($source_dir);
    touch($source_dir . '/file1.txt');
    $archive_path = $this->test_dir . '/my_backup.tar.gz';
    exec(sprintf('cd %s && tar -czf %s %s', escapeshellarg($this->test_dir), escapeshellarg(basename($archive_path)), escapeshellarg(basename($source_dir))));
    $this->fs->remove($source_dir);

    $application = new Application();
    $application->add(new UnpackCommand());

    $command = $application->find('backup:unpack');
    $command_tester = new CommandTester($command);
    $command_tester->execute([
      'source' => $archive_path,
    ]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Extracting archive', $output);
    $this->assertStringContainsString('Unpacked to:', $output);
    $this->assertDirectoryExists($this->test_dir . '/my_backup');
  }

  public function testExecuteEncrypted() {
    $source_dir = $this->test_dir . '/my_encrypted_backup';
    $this->fs->mkdir($source_dir);
    touch($source_dir . '/file2.txt');
    $password = 'testpassword';
    $archive_path = $this->test_dir . '/my_encrypted_backup.tar.gz';
    $encrypted_path = $archive_path . '.enc';
    exec(sprintf('cd %s && tar -czf %s %s', escapeshellarg($this->test_dir), escapeshellarg(basename($archive_path)), escapeshellarg(basename($source_dir))));
    exec(sprintf('openssl enc -aes-256-cbc -pbkdf2 -salt -in %s -out %s -pass pass:%s', escapeshellarg($archive_path), escapeshellarg($encrypted_path), escapeshellarg($password)));
    $this->fs->remove($source_dir);
    $this->fs->remove($archive_path);

    // Update config with password
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', "encryption:\n  password: $password\nmanifest: [foo]\ndatabase: { handler: null }");

    $application = new Application();
    $application->add(new UnpackCommand());

    $command = $application->find('backup:unpack');
    $command_tester = new CommandTester($command);
    $command_tester->execute([
      'source' => $encrypted_path,
    ]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Decrypting archive', $output);
    $this->assertStringContainsString('Extracting archive', $output);
    $this->assertDirectoryExists($this->test_dir . '/my_encrypted_backup');
  }
}
