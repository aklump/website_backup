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
    file_put_contents($config_path, "aws_region: us-east-1\naws_bucket: my-bucket\npath_to_app: " . $this->test_app_root . '/app_path');

    $other_config_path = $this->test_app_root . '/bin/config/website_backup.local.yml';
    file_put_contents($other_config_path, "aws_region: us-west-2");

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $this->assertEquals('us-east-1', $config['aws_region']);
    $this->assertEquals('my-bucket', $config['aws_bucket']);
  }

  public function testEnvOverrides() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "aws_region: \${WEBSITE_BACKUP_AWS_REGION}\naws_bucket: my-bucket\npath_to_app: " . $this->test_app_root . '/app_path');

    putenv('WEBSITE_BACKUP_AWS_REGION=us-west-2');

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $this->assertEquals('us-west-2', $config['aws_region']);

    putenv('WEBSITE_BACKUP_AWS_REGION'); // Clear env
  }

  public function testDatabaseUrlOverride() {
    putenv('DATABASE_URL=mysql://drupal11:drupal11@database:3306/drupal11');

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $this->assertEquals('drupal11', $config['database']['name']);
    $this->assertEquals('drupal11', $config['database']['user']);
    $this->assertEquals('drupal11', $config['database']['password']);
    $this->assertEquals('database', $config['database']['host']);
    $this->assertEquals('3306', $config['database']['port']);

    putenv('DATABASE_URL'); // Clear env
  }

  public function testDatabaseUrlOverrideWithIndividualOverrides() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "database:\n  user: \${WEBSITE_BACKUP_DB_USER}");

    putenv('DATABASE_URL=mysql://user:pass@host:3306/dbname');
    putenv('WEBSITE_BACKUP_DB_USER=override_user');

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    // Individual environment variables should take precedence over DATABASE_URL
    // because they are more specific.
    $this->assertEquals('override_user', $config['database']['user']);
    $this->assertEquals('dbname', $config['database']['name']);

    putenv('DATABASE_URL');
    putenv('WEBSITE_BACKUP_DB_USER');
  }

  public function testEnvOverridesRespectExistingYaml() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    file_put_contents($config_path, "aws_region: us-east-1\ndatabase:\n  user: yaml_user\n  name: \${DATABASE_NAME_VAR}");

    putenv('WEBSITE_BACKUP_AWS_REGION=us-west-2');
    putenv('WEBSITE_BACKUP_DB_USER=env_user');
    putenv('DATABASE_URL=mysql://dburl_user:pass@host:3306/dbname');
    putenv('DATABASE_NAME_VAR=dbname');

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    // YAML should take precedence (no token, fixed value)
    $this->assertEquals('us-east-1', $config['aws_region']);
    $this->assertEquals('yaml_user', $config['database']['user']);

    // This should be loaded from env because it's a token in YAML
    $this->assertEquals('dbname', $config['database']['name']);

    putenv('WEBSITE_BACKUP_AWS_REGION');
    putenv('WEBSITE_BACKUP_DB_USER');
    putenv('DATABASE_URL');
    putenv('DATABASE_NAME_VAR');
  }

  public function testValidateFailsWhenMissingRequired() {
    $loader = new ConfigLoader($this->test_app_root);
    $config = [
      'path_to_app' => $this->test_app_root . '/app_path',
      'manifest' => ['foo'],
      'database' => ['handler' => NULL],
      'aws_region' => 'us-east-1',
      'aws_bucket' => 'bucket',
      'aws_access_key_id' => 'key',
      'aws_secret_access_key' => 'secret',
      'aws_retention' => [
        'keep_daily_for_days' => 1,
        'keep_monthly_for_months' => 1,
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
      'path_to_app' => $this->test_app_root . '/app_path',
      'manifest' => ['foo'],
      'database' => ['handler' => NULL],
      'aws_region' => 'us-east-1',
      'aws_bucket' => 'bucket',
      'aws_access_key_id' => 'key',
      'aws_secret_access_key' => 'secret',
      'aws_retention' => [
        'keep_daily_for_days' => 1,
        'keep_monthly_for_months' => 1,
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
      'path_to_app' => $this->test_app_root . '/app_path',
      'manifest' => ['foo'],
      'database' => ['handler' => NULL],
      'aws_region' => 'us-east-1',
      'aws_bucket' => 'bucket',
      'aws_access_key_id' => 'key',
      'aws_secret_access_key' => 'secret',
      'aws_retention' => [
        'keep_daily_for_days' => 1,
        'keep_monthly_for_months' => 1,
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
      'path_to_app' => $this->test_app_root . '/app_path',
      'manifest' => ['foo'],
      'database' => ['handler' => NULL],
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
    $config['aws_retention'] = ['keep_daily_for_days' => 14];
    try {
      $loader->validate($config);
      $this->fail('Should have thrown exception for missing keep_monthly_for_months');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('aws_retention.keep_monthly_for_months', $e->getMessage());
    }

    // 3. Negative value
    $config = $base_config;
    $config['aws_retention'] = [
      'keep_daily_for_days' => -1,
      'keep_monthly_for_months' => 12,
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
      'keep_daily_for_days' => "14",
      'keep_monthly_for_months' => 12,
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
      'keep_daily_for_days' => 0,
      'keep_monthly_for_months' => 0,
    ];
    $loader->validate($config);
    $this->assertTrue(TRUE);

    // 6. Valid
    $config = $base_config;
    $config['aws_retention'] = [
      'keep_daily_for_days' => 14,
      'keep_monthly_for_months' => 12,
    ];
    $loader->validate($config);
    $this->assertTrue(TRUE);
  }

  public function testUnreplacedTokensAreTreatedAsEmpty() {
    $config_path = $this->test_app_root . '/bin/config/website_backup.yml';
    // aws_access_key_id is required for non-local backup
    file_put_contents($config_path, "aws_access_key_id: \${MISSING_TOKEN}\naws_region: us-east-1\naws_bucket: bucket\naws_secret_access_key: secret\naws_retention:\n  keep_daily_for_days: 1\n  keep_monthly_for_months: 1\nmanifest: [foo]\ndatabase: { handler: null }");

    $loader = new ConfigLoader($this->test_app_root);
    $config = $loader->load();

    $this->assertEmpty($config['aws_access_key_id']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('aws_access_key_id');
    $loader->validate($config, FALSE);
  }
}
