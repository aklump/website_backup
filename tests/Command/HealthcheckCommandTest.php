<?php

namespace App\Tests\Command;

use App\Command\HealthcheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class HealthcheckCommandTest extends TestCase {

  private $test_dir;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/website_backup_healthcheck_test_' . uniqid();
    mkdir($this->test_dir, 0777, TRUE);
    mkdir($this->test_dir . '/bin/config', 0777, TRUE);
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

  public function testExecuteFailsWithoutConfig() {
    // No config file created in setUp
    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $exit_code = $command_tester->execute([]);

    $this->assertEquals(1, $exit_code);
    $this->assertStringContainsString('Could not find the application root', $command_tester->getDisplay());
  }

  public function testExecuteFailsWithInvalidConfig() {
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', "manifest: []"); // Missing required fields

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $exit_code = $command_tester->execute([]);

    $this->assertEquals(1, $exit_code);
    $this->assertStringContainsString('Configuration check failed', $command_tester->getDisplay());
  }

  public function testExecuteFailsWithInvalidDatabase() {
    $config = <<<YAML
manifest: [foo]
aws_region: us-west-1
aws_bucket: example
aws_access_key_id: key
aws_secret_access_key: secret
aws_retention:
  keep_daily_for_days: 1
  keep_monthly_for_months: 1
database:
  host: localhost
  user: invalid_user
  password: invalid_password
  name: invalid_db
  handler: mysqldump
YAML;
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $exit_code = $command_tester->execute([]);

    $this->assertEquals(1, $exit_code);
    $this->assertStringContainsString('Database connectivity failed', $command_tester->getDisplay());
  }

  public function testExecuteFailsWithInvalidS3() {
    $config = <<<YAML
manifest: [foo]
aws_region: us-west-1
aws_bucket: invalid-bucket
aws_access_key_id: key
aws_secret_access_key: secret
aws_retention:
  keep_daily_for_days: 1
  keep_monthly_for_months: 1
database:
  host: localhost
  user: root
  password: ""
  name: mysql
  handler: mysqldump
YAML;
    // Note: For database check to pass, we might need a real MySQL, but here it fails on S3
    // Actually, we want to test S3 failure. Let's make DB pass if possible or mock it.
    // For now, let's just assert that it fails and contains S3 related message.

    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $exit_code = $command_tester->execute([]);

    $this->assertEquals(1, $exit_code);
    $this->assertStringContainsString('S3 connectivity failed', $command_tester->getDisplay());
  }

  public function testExecuteWarnsOnZeroRetention() {
    $config = <<<YAML
manifest: [foo]
aws_region: us-west-1
aws_bucket: example
aws_access_key_id: key
aws_secret_access_key: secret
aws_retention:
  keep_daily_for_days: 0
  keep_monthly_for_months: 0
database:
  handler: null
YAML;
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $command_tester->execute([]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Retention is set to 0 for both daily and monthly', $output);
    $this->assertStringContainsString('This will cause the', $output);
    $this->assertStringContainsString('S3 bucket to continue to grow', $output);
  }

  public function testExecuteAlwaysChecksSystemTools() {
    $config = <<<YAML
manifest: [foo]
database: { handler: null }
YAML;
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $command_tester->execute([]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Checking System Tools', $output);
    $this->assertStringContainsString('Tar is installed', $output);
    $this->assertStringContainsString('Openssl is installed', $output);
  }

  public function testExecuteFailsIfS3EncryptionEnabledAndPasswordMissing() {
    $config = <<<YAML
manifest: [foo]
aws_region: us-west-1
aws_bucket: example
aws_access_key_id: key
aws_secret_access_key: secret
aws_retention:
  keep_daily_for_days: 1
  keep_monthly_for_months: 1
database: { handler: null }
encryption:
  s3: true
YAML;
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $command_tester->execute([]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Encryption password is not configured', $output);
  }
}
