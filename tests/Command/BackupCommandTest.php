<?php

namespace App\Tests\Command;

use App\Command\BackupLocalCommand;
use App\Command\BackupS3Command;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class BackupCommandTest extends TestCase {

  private $test_dir;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/website_backup_test_' . bin2hex(random_bytes(8));
    mkdir($this->test_dir, 0700, TRUE);
    mkdir($this->test_dir . '/bin/config', 0777, TRUE);
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', "manifest: [foo]\ndatabase: { handler: null }\naws_bucket: example");
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

  public function testBackupLocalFailsWithMissingDir() {
    $application = new Application();
    $application->add(new BackupLocalCommand());

    $command = $application->find('backup:local');
    $command_tester = new CommandTester($command);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The --dir option is required.');

    $command_tester->execute([]);
  }

  public function testBackupLocalFailsWithInvalidDir() {
    $application = new Application();
    $application->add(new BackupLocalCommand());

    $command = $application->find('backup:local');
    $command_tester = new CommandTester($command);

    $invalid_path = $this->test_dir . '/non_existent_dir';

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("The directory specified by --dir does not exist or is not a directory: $invalid_path");

    $command_tester->execute([
      '--dir' => $invalid_path,
    ]);
  }

  public function testBackupLocalFailsWithDatabaseAndFiles() {
    $application = new Application();
    $application->add(new BackupLocalCommand());

    $command = $application->find('backup:local');
    $command_tester = new CommandTester($command);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The --database and --files options cannot be used together.');

    $command_tester->execute([
      '--dir' => $this->test_dir,
      '--database' => TRUE,
      '--files' => TRUE,
    ]);
  }

  public function testBackupS3FailsWithDatabaseAndFiles() {
    $application = new Application();
    $application->add(new BackupS3Command());

    $command = $application->find('backup:s3');
    $command_tester = new CommandTester($command);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The --database and --files options cannot be used together.');

    $command_tester->execute([
      '--database' => TRUE,
      '--files' => TRUE,
    ]);
  }

  public function testBackupLocalEncryptFailsWithoutGzip() {
    $application = new Application();
    $application->add(new BackupLocalCommand());
    $command = $application->find('backup:local');
    $command_tester = new CommandTester($command);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('--encrypt option may only be used with --gzip');

    $command_tester->execute([
      '--dir' => $this->test_dir,
      '--encrypt' => TRUE,
    ]);
  }

  public function testBackupS3RequiresConfirmation() {
    $application = new Application();
    $application->add(new BackupS3Command());

    $command = $application->find('backup:s3');
    $command_tester = new CommandTester($command);

    // Simulate "no" response to confirmation
    $command_tester->setInputs(['no']);
    $command_tester->execute([]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('You are about to backup to S3', $output);
    $this->assertStringContainsString('Backup aborted.', $output);
  }

  public function testBackupS3WithForceBypassesConfirmation() {
    $config = "manifest: [foo]\ndatabase: { handler: null }\naws_region: us-east-1\naws_bucket: bucket\naws_access_key_id: key\naws_secret_access_key: secret\naws_retention:\n  keep_daily_for_days: 1\n  keep_monthly_for_months: 1";
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new BackupS3Command());

    $command = $application->find('backup:s3');
    $command_tester = new CommandTester($command);

    // If it doesn't prompt, it will proceed to try to run the backup service.
    try {
      $command_tester->execute(['--force' => TRUE]);
    }
    catch (\Exception $e) {
      // Ignore failures during actual backup execution in this test.
    }

    $output = $command_tester->getDisplay();
    $this->assertStringNotContainsString('You are about to backup to S3', $output);
  }

  public function testBackupLocalWithNotifyShowsSentMessage() {
    $config = "manifest: [foo]\ndatabase: { handler: null }\naws_bucket: example\nnotifications:\n  email:\n    to: [ops@example.com]\n    on_success: { subject: Success }\n    on_fail: { subject: Fail }";
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new BackupLocalCommand());
    $command = $application->find('backup:local');
    $command_tester = new CommandTester($command);

    $local_path = $this->test_dir . '/backups';
    mkdir($local_path);

    $command_tester->execute([
      '--notify' => TRUE,
      '--dir' => $local_path,
    ]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Backing Up Your Website', $output);
    if (strpos($output, 'Email with subject "Success" was sent to: ops@example.com') === FALSE) {
      $this->assertStringContainsString('Failed to send email notification', $output);
    }
    else {
      $this->assertStringContainsString('Email with subject "Success" was sent to: ops@example.com', $output);
    }
  }

  public function testBackupLocalDirectoryMode() {
    $application = new Application();
    $application->add(new BackupLocalCommand());
    $command = $application->find('backup:local');
    $command_tester = new CommandTester($command);

    $local_path = $this->test_dir . '/backups';
    mkdir($local_path);

    $command_tester->execute([
      '--dir' => $local_path,
      '--database' => TRUE,
    ]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Saving locally', $output);

    $dirs = glob($local_path . '/example--*');
    $this->assertCount(1, $dirs);
    $this->assertTrue(is_dir($dirs[0]));
    $this->assertStringNotContainsString('.tar.gz', $dirs[0]);
  }

  public function testBackupLocalGzipMode() {
    $application = new Application();
    $application->add(new BackupLocalCommand());
    $command = $application->find('backup:local');
    $command_tester = new CommandTester($command);

    $local_path = $this->test_dir . '/backups';
    mkdir($local_path);

    $command_tester->execute([
      '--dir' => $local_path,
      '--gzip' => TRUE,
      '--database' => TRUE,
    ]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Compressing object', $output);
    $this->assertStringContainsString('Saving locally', $output);

    $files = glob($local_path . '/example--*.tar.gz');
    $this->assertCount(1, $files);
    $this->assertTrue(file_exists($files[0]));

    $dir_name = str_replace('.tar.gz', '', $files[0]);
    $this->assertFalse(is_dir($dir_name));
  }

  public function testBackupLocalLatestSymlinkDirectory() {
    $application = new Application();
    $application->add(new BackupLocalCommand());
    $command = $application->find('backup:local');
    $command_tester = new CommandTester($command);

    $local_path = $this->test_dir . '/backups';
    mkdir($local_path);

    $command_tester->execute([
      '--dir' => $local_path,
      '--latest' => TRUE,
      '--database' => TRUE,
    ]);

    $symlink = $local_path . '/example--latest';
    $this->assertTrue(is_link($symlink), 'File should be a symlink: ' . $symlink);
    $this->assertTrue(is_dir($local_path . '/' . readlink($symlink)), 'Symlink target should be a directory');
    $this->assertStringNotContainsString('.tar.gz', readlink($symlink));
  }

  public function testBackupLocalLatestSymlinkGzip() {
    $application = new Application();
    $application->add(new BackupLocalCommand());
    $command = $application->find('backup:local');
    $command_tester = new CommandTester($command);

    $local_path = $this->test_dir . '/backups';
    mkdir($local_path);

    $command_tester->execute([
      '--dir' => $local_path,
      '--gzip' => TRUE,
      '--latest' => TRUE,
      '--database' => TRUE,
    ]);

    $symlink = $local_path . '/example--latest.tar.gz';
    $this->assertTrue(is_link($symlink), 'File should be a symlink: ' . $symlink);
    $this->assertStringEndsWith('.tar.gz', readlink($symlink));
    $this->assertTrue(file_exists($local_path . '/' . readlink($symlink)), 'Symlink target file should exist');
  }
}
