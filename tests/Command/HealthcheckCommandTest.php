<?php

namespace AKlump\WebsiteBackup\Tests\Command;

use AKlump\WebsiteBackup\Command\HealthcheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \AKlump\WebsiteBackup\Command\HealthcheckCommand
 * @uses \AKlump\WebsiteBackup\Config\ConfigLoader
 * @uses \AKlump\WebsiteBackup\Helper\GetInstalledInRoot
 * @uses \AKlump\WebsiteBackup\Service\ManifestService
 * @uses \AKlump\WebsiteBackup\Service\ProcessRunner
 * @uses \AKlump\WebsiteBackup\Service\S3Service
 * @uses \AKlump\WebsiteBackup\Service\TemporaryFileFactory
 * @uses \AKlump\WebsiteBackup\Helper\CreateMysqlTempConfig
 * @uses \AKlump\WebsiteBackup\Helper\GetShortPath
 */
class HealthcheckCommandTest extends TestCase {

  private $test_dir;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/website_backup_healthcheck_test_' . bin2hex(random_bytes(8));
    mkdir($this->test_dir, 0700, TRUE);
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
  url: mysql://invalid_user:invalid_password@localhost/invalid_db
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
  url: mysql://root:@localhost/mysql
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
  keep_all_for_days: 0
  keep_latest_daily_for_days: 0
  keep_latest_monthly_for_months: 0
  keep_latest_yearly_for_years: 0
database:
  url: mysql://user:pass@host/db
  handler: null
YAML;
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $command_tester->execute([]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('All retention settings are set to 0', $output);
    $this->assertStringContainsString('This will cause the', $output);
    $this->assertStringContainsString('S3 bucket to continue to grow', $output);
  }

  public function testExecuteAlwaysChecksSystemTools() {
    $config = <<<YAML
manifest: [foo]
database:
  url: mysql://user:pass@host/db
  handler: null
YAML;
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $command_tester->execute([]);

    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Checking System Tools', $output);
    $this->assertStringContainsString('✓ tar', $output);
    $this->assertStringContainsString('✓ openssl', $output);
  }

  public function testExecuteFailsIfS3EncryptionEnabledAndPasswordMissing() {
    $config = <<<YAML
manifest: [foo]
aws_region: us-west-1
aws_bucket: example
aws_access_key_id: key
aws_secret_access_key: secret
aws_retention:
  keep_all_for_days: 1
  keep_latest_daily_for_days: 1
  keep_latest_monthly_for_months: 1
  keep_latest_yearly_for_years: 1
database:
  url: mysql://user:pass@host/db
  handler: null
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

  public function testExecuteS3CheckHandlesNullCredentials() {
    $config = <<<YAML
manifest: [foo]
aws_region: us-west-1
aws_bucket: example
aws_retention:
  keep_all_for_days: 1
  keep_latest_daily_for_days: 1
  keep_latest_monthly_for_months: 1
  keep_latest_yearly_for_years: 1
database: { handler: null }
YAML;
    // Note: aws_access_key_id and aws_secret_access_key are missing.
    // ConfigLoader::validate() will fail if we call it for non-local backup.
    // However, HealthcheckCommand calls validate($config) which defaults to $is_local = false.
    // So it should fail in Configuration Check section.

    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $exit_code = $command_tester->execute([]);

    $this->assertEquals(1, $exit_code);
    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Configuration check failed', $output);
    $this->assertStringContainsString('Missing required configuration', $output);
    $this->assertStringContainsString('aws_access_key_id', $output);
  }

  public function testExecuteFailsWithInvalidManifest() {
    $config = <<<YAML
manifest: [\${PROJECT_ROOT}/non_existent_file.txt]
database: { handler: null }
YAML;
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $exit_code = $command_tester->execute([]);

    $this->assertEquals(1, $exit_code);
    $output = $command_tester->getDisplay();
    $this->assertStringContainsString('Checking Manifest', $output);
    // Realpath on macOS might return /private/var...
    $expected_path = realpath($this->test_dir) . '/non_existent_file.txt';
    $this->assertStringContainsString('? ' . $expected_path . ' — No files found matching this pattern.', $output);
  }

  public function testExecuteSucceedsWithValidManifest() {
    touch($this->test_dir . '/valid_file.txt');
    $config = <<<YAML
manifest: [\${PROJECT_ROOT}/valid_file.txt]
aws_region: us-west-1
aws_bucket: example
aws_access_key_id: key
aws_secret_access_key: secret
aws_retention:
  keep_all_for_days: 1
  keep_latest_daily_for_days: 1
  keep_latest_monthly_for_months: 1
  keep_latest_yearly_for_years: 1
database: 
  url: mysql://user:pass@host/db
  handler: null
YAML;
    file_put_contents($this->test_dir . '/bin/config/website_backup.yml', $config);

    $application = new Application();
    $application->add(new HealthcheckCommand());

    $command = $application->find('healthcheck');
    $command_tester = new CommandTester($command);
    $exit_code = $command_tester->execute([]);

    $output = $command_tester->getDisplay();
    // Manifest should pass even if database or S3 fail (though we expect them to fail in test)
    // We just want to check that it reports a pass for the manifest section.
    $this->assertStringContainsString('Checking Manifest', $output);
    $expected_path = realpath($this->test_dir) . '/valid_file.txt';
    $this->assertStringContainsString('✓ ' . $expected_path, $output);
  }
}
