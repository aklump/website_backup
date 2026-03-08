<?php

namespace AKlump\WebsiteBackup\Tests\Config;

use AKlump\WebsiteBackup\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AKlump\WebsiteBackup\Config\ConfigLoader
 * @uses \AKlump\WebsiteBackup\Helper\GetShortPath
 */
class ConfigLoaderTest extends TestCase {

  private $test_app_root;

  protected function setUp(): void {
    $this->test_app_root = sys_get_temp_dir() . '/website_backup_loader_test_' . bin2hex(random_bytes(8));
    if (!is_dir($this->test_app_root)) {
      mkdir($this->test_app_root, 0700, TRUE);
    }
    mkdir($this->test_app_root . '/bin/config', 0700, TRUE);
    mkdir($this->test_app_root . '/app_path', 0700, TRUE);
  }

  protected function tearDown(): void {
    $this->removeDir($this->test_app_root);
  }

  private function removeDir($dir) {
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
      (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
    }

    return rmdir($dir);
  }

  public function testLoadFromYaml() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "aws_region: us-east-1\naws_bucket: my-bucket");

    $other_config_path = $this->test_app_root . '/bin/config/website_backup.local.yml';
    file_put_contents($other_config_path, "aws_region: us-west-2");

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $this->assertEquals('us-east-1', $config['aws_region']);
    $this->assertEquals('my-bucket', $config['aws_bucket']);
  }

  public function testEnvOverrides() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "aws_region: \${WEBSITE_BACKUP_AWS_REGION}\naws_bucket: my-bucket");

    putenv('WEBSITE_BACKUP_AWS_REGION=us-west-2');

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $this->assertEquals('us-west-2', $config['aws_region']);

    putenv('WEBSITE_BACKUP_AWS_REGION'); // Clear env
  }

  public function testUnsubstitutedDatabaseUrlThrowsException() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    // Provide other required fields to ensure it reaches database.url validation
    file_put_contents($config_path, "manifest: [foo]\naws_region: us-east-1\naws_bucket: bucket\naws_access_key_id: key\naws_secret_access_key: secret\naws_retention:\n  keep_all_for_days: 1\n  keep_latest_daily_for_days: 1\n  keep_latest_monthly_for_months: 1\n  keep_latest_yearly_for_years: 1\ndatabase:\n  url: \${DATABASE_URL}");

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('database.url');
    $loader->validate($config);
  }

  public function testDatabaseUrlInYaml() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "database:\n  url: \${DATABASE_URL}");

    putenv('DATABASE_URL=mysql://user:pass@host:3306/dbname');

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $this->assertEquals('dbname', $config['database']['name']);
    $this->assertEquals('user', $config['database']['user']);
    $this->assertEquals('pass', $config['database']['password']);
    $this->assertEquals('host', $config['database']['host']);
    $this->assertEquals('3306', $config['database']['port']);

    putenv('DATABASE_URL');
  }

  public function testMissingDatabaseUrlThrowsException() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "manifest: [foo]\naws_region: us-east-1\naws_bucket: bucket\naws_access_key_id: key\naws_secret_access_key: secret\naws_retention:\n  keep_all_for_days: 1\n  keep_latest_daily_for_days: 1\n  keep_latest_monthly_for_months: 1\n  keep_latest_yearly_for_years: 1\ndatabase: { handler: null }");

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('database.url');
    $loader->validate($config);
  }

  public function testDatabaseUrlMissingPartsThrowsException() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "database:\n  url: \${DATABASE_URL}");

    putenv('DATABASE_URL=mysql://user:pass@host');
    $loader = new ConfigLoader($this->test_app_root);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Database name is missing in URL');
    try {
      $loader->load();
    } finally {
      putenv('DATABASE_URL');
    }
  }

  public function testInvalidDatabaseNameThrowsException() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "database:\n  url: \${DATABASE_URL}");

    putenv('DATABASE_URL=mysql://user:pass@host/db;drop');
    $loader = new ConfigLoader($this->test_app_root);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Invalid database name');
    try {
      $loader->load();
    } finally {
      putenv('DATABASE_URL');
    }
  }

  public function testValidateFailsWhenMissingRequired() {
    $loader = new ConfigLoader($this->test_app_root);
    $config = [
      'manifest' => ['foo'],
      'database' => [
        'url' => 'mysql://user:pass@host/db',
        'handler' => NULL,
      ],
      'aws_region' => 'us-east-1',
      'aws_bucket' => 'bucket',
      'aws_access_key_id' => 'key',
      'aws_secret_access_key' => 'secret',
      'aws_retention' => [
        'keep_all_for_days' => 1,
        'keep_latest_daily_for_days' => 1,
        'keep_latest_monthly_for_months' => 1,
        'keep_latest_yearly_for_years' => 1,
      ],
    ];

    // Valid base config
    $loader->validate($config);

    // Missing manifest
    $invalid_config = $config;
    unset($invalid_config['manifest']);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('manifest');
    $loader->validate($invalid_config);
  }

  public function testValidateNotify() {
    $loader = new ConfigLoader($this->test_app_root);
    $base_config = [
      'manifest' => ['foo'],
      'database' => [
        'url' => 'mysql://user:pass@host/db',
        'handler' => NULL,
      ],
      'aws_region' => 'us-east-1',
      'aws_bucket' => 'bucket',
      'aws_access_key_id' => 'key',
      'aws_secret_access_key' => 'secret',
      'aws_retention' => [
        'keep_all_for_days' => 1,
        'keep_latest_daily_for_days' => 1,
        'keep_latest_monthly_for_months' => 1,
        'keep_latest_yearly_for_years' => 1,
      ],
    ];

    // 1. Notify enabled but config missing
    try {
      $loader->validate($base_config, FALSE, TRUE);
      $this->fail('Should have failed validation for missing notifications.email');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('notifications.email', $e->getMessage());
    }

    // 2. Notify enabled but "to" missing
    $config = $base_config;
    $config['notifications']['email'] = [
      'on_success' => ['subject' => 'Success'],
      'on_fail' => ['subject' => 'Fail'],
    ];
    try {
      $loader->validate($config, FALSE, TRUE);
      $this->fail('Should have failed validation for missing to');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('notifications.email.to', $e->getMessage());
    }

    // 3. Notify enabled but "subject" missing
    $config = $base_config;
    $config['notifications']['email'] = [
      'to' => ['ops@example.com'],
      'on_success' => ['subject' => 'Success'],
    ];
    try {
      $loader->validate($config, FALSE, TRUE);
      $this->fail('Should have failed validation for missing on_fail subject');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('notifications.email.on_fail.subject', $e->getMessage());
    }

    // 4. Valid notify config
    $config = $base_config;
    $config['notifications']['email'] = [
      'to' => ['ops@example.com'],
      'on_success' => ['subject' => 'Success'],
      'on_fail' => ['subject' => 'Fail'],
    ];
    $loader->validate($config, FALSE, TRUE);
    $this->assertTrue(TRUE);
  }

  public function testValidateEncryption() {
    $loader = new ConfigLoader($this->test_app_root);
    $base_config = [
      'manifest' => ['foo'],
      'database' => [
        'url' => 'mysql://user:pass@host/db',
        'handler' => NULL,
      ],
      'aws_region' => 'us-east-1',
      'aws_bucket' => 'bucket',
      'aws_access_key_id' => 'key',
      'aws_secret_access_key' => 'secret',
      'aws_retention' => [
        'keep_all_for_days' => 1,
        'keep_latest_daily_for_days' => 1,
        'keep_latest_monthly_for_months' => 1,
        'keep_latest_yearly_for_years' => 1,
      ],
    ];

    // 1. Local encrypt but password missing
    try {
      $loader->validate($base_config, TRUE, FALSE, TRUE);
      $this->fail('Should have failed validation for missing encryption.password');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('encryption', $e->getMessage());
    }

    // 2. S3 encryption enabled but password missing
    $s3_config = $base_config;
    $s3_config['encryption'] = ['s3' => TRUE];
    try {
      $loader->validate($s3_config, FALSE, FALSE, FALSE);
      $this->fail('Should have failed validation for missing encryption.password');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('encryption', $e->getMessage());
    }

    // 3. Valid encryption config
    $config = $base_config;
    $config['encryption'] = ['password' => 'secret', 's3' => TRUE];
    $loader->validate($config, FALSE, FALSE, FALSE);
    $this->assertTrue(TRUE);
  }

  public function testValidateRetention() {
    $loader = new ConfigLoader($this->test_app_root);
    $base_config = [
      'manifest' => ['foo'],
      'database' => [
        'url' => 'mysql://user:pass@host/db',
        'handler' => NULL,
      ],
      'aws_region' => 'us-east-1',
      'aws_bucket' => 'bucket',
      'aws_access_key_id' => 'key',
      'aws_secret_access_key' => 'secret',
    ];

    // 1. Invalid type
    $config = $base_config;
    $config['aws_retention'] = 'not an array';
    try {
      $loader->validate($config);
      $this->fail('Should have thrown exception for non-array retention');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('aws_retention" must be an array', $e->getMessage());
    }

    // 2. Missing key
    $config = $base_config;
    $config['aws_retention'] = ['keep_latest_daily_for_days' => 14];
    try {
      $loader->validate($config);
      $this->fail('Should have thrown exception for missing keep_all_for_days');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('aws_retention.keep_all_for_days', $e->getMessage());
    }

    // 3. Negative value
    $config = $base_config;
    $config['aws_retention'] = [
      'keep_all_for_days' => -1,
      'keep_latest_daily_for_days' => 14,
      'keep_latest_monthly_for_months' => 12,
      'keep_latest_yearly_for_years' => 3,
    ];
    try {
      $loader->validate($config);
      $this->fail('Should have thrown exception for negative value');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('must be a non-negative integer', $e->getMessage());
    }

    // 4. Non-integer value
    $config = $base_config;
    $config['aws_retention'] = [
      'keep_all_for_days' => "14",
      'keep_latest_daily_for_days' => 14,
      'keep_latest_monthly_for_months' => 12,
      'keep_latest_yearly_for_years' => 3,
    ];
    try {
      $loader->validate($config);
      $this->fail('Should have thrown exception for non-integer value');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('must be a non-negative integer', $e->getMessage());
    }

    // 5. Both zero is now permitted, but should be warned elsewhere.
    $config = $base_config;
    $config['aws_retention'] = [
      'keep_all_for_days' => 0,
      'keep_latest_daily_for_days' => 0,
      'keep_latest_monthly_for_months' => 0,
      'keep_latest_yearly_for_years' => 0,
    ];
    $loader->validate($config);
    $this->assertTrue(TRUE);

    // 6. Valid
    $config = $base_config;
    $config['aws_retention'] = [
      'keep_all_for_days' => 2,
      'keep_latest_daily_for_days' => 14,
      'keep_latest_monthly_for_months' => 12,
      'keep_latest_yearly_for_years' => 3,
    ];
    $loader->validate($config);
    $this->assertTrue(TRUE);
  }

  public function testUnreplacedTokensAreTreatedAsEmpty() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    // aws_access_key_id is required for non-local backup
    file_put_contents($config_path, "aws_access_key_id: \${MISSING_TOKEN}\naws_region: us-east-1\naws_bucket: bucket\naws_secret_access_key: secret\naws_retention:\n  keep_daily_for_days: 1\n  keep_monthly_for_months: 1\nmanifest: [foo]\ndatabase: { url: 'mysql://user:pass@host/db', handler: null }");

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $this->assertEmpty($config['aws_access_key_id']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('aws_access_key_id');
    $loader->validate($config, FALSE);
  }

  public function testCustomConfigPath() {
    $custom_config = $this->test_app_root . '/custom_config.yml';
    file_put_contents($custom_config, "aws_region: us-north-1\naws_bucket: custom-bucket\ndatabase: { url: 'mysql://user:pass@host/db' }");

    $loader = new ConfigLoader($this->test_app_root, $custom_config);
    $config = $loader->load();

    $this->assertEquals('us-north-1', $config['aws_region']);
    $this->assertEquals('custom-bucket', $config['aws_bucket']);
  }

  public function testCustomEnvPath() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "aws_region: \${CUSTOM_REGION}\naws_bucket: bucket\ndatabase: { url: 'mysql://user:pass@host/db' }");

    $custom_env = $this->test_app_root . '/custom.env';
    file_put_contents($custom_env, "CUSTOM_REGION=us-south-1");

    $loader = new ConfigLoader($this->test_app_root, NULL, $custom_env);
    $config = $loader->load();

    $this->assertEquals('us-south-1', $config['aws_region']);
  }

  public function testMissingCustomConfigThrowsException() {
    $loader = new ConfigLoader($this->test_app_root, $this->test_app_root . '/non_existent.yml');
    // Default fallback shouldn't happen if custom path is provided
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Configuration file not found');
    $loader->load();
  }

  public function testMissingCustomEnvThrowsException() {
    $loader = new ConfigLoader($this->test_app_root, NULL, $this->test_app_root . '/non_existent.env');
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Environment file not found');
    $loader->load();
  }

  public function testUnreadableCustomConfigThrowsException() {
    $custom_config = $this->test_app_root . '/unreadable.yml';
    file_put_contents($custom_config, "foo: bar");
    chmod($custom_config, 0000);

    $loader = new ConfigLoader($this->test_app_root, $custom_config);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Configuration file is not readable');
    try {
      $loader->load();
    }
    finally {
      chmod($custom_config, 0600);
    }
  }

  /**
   * @dataProvider providerTestFlexibleConfigPath
   */
  public function testFlexibleConfigPath(string $filename, string $actual_file) {
    $config_path = $this->test_app_root . '/' . $actual_file;
    file_put_contents($config_path, "aws_region: flexible-region\naws_bucket: bucket\ndatabase: { url: 'mysql://user:pass@host/db' }");

    $loader = new ConfigLoader($this->test_app_root, $this->test_app_root . '/' . $filename);
    $config = $loader->load();

    $this->assertEquals('flexible-region', $config['aws_region']);
  }

  public function testProjectRootTokenExpansion() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "manifest:\n  - \${PROJECT_ROOT}/web/sites/*/files/");

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $this->assertEquals([$this->test_app_root . '/web/sites/*/files/'], $config['manifest']);
  }

  public function testConfigRelativePathNormalization() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "directories:\n  local: ../../backups\nmanifest:\n  - ../web/files\n  - \"!../web/private\"");

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $config_dir = dirname($config_path);
    $this->assertEquals($config_dir . '/../../backups', $config['directories']['local']);
    $this->assertEquals([
      $config_dir . '/../web/files',
      '!' . $config_dir . '/../web/private',
    ], $config['manifest']);
  }

  public function providerTestFlexibleConfigPath(): array {
    return [
      ['website_backup', 'website_backup.yml'],
      ['website_backup.yml', 'website_backup.yml'],
      ['website_backup.yaml', 'website_backup.yaml'],
      ['website_backup.local', 'website_backup.local.yml'],
      ['website_backup.local.yml', 'website_backup.local.yml'],
    ];
  }
}
