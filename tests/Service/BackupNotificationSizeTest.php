<?php

namespace AKlump\WebsiteBackup\Tests\Service;

use AKlump\WebsiteBackup\Service\BackupOptions;
use AKlump\WebsiteBackup\Service\BackupService;
use AKlump\WebsiteBackup\Service\EmailService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @covers \AKlump\WebsiteBackup\Service\BackupService
 * @uses \AKlump\WebsiteBackup\Helper\CreateMysqlTempConfig
 * @uses \AKlump\WebsiteBackup\Helper\GetShortPath
 * @uses \AKlump\WebsiteBackup\Helper\S3LinkBuilder
 * @uses \AKlump\WebsiteBackup\Service\DatabaseDumper
 * @uses \AKlump\WebsiteBackup\Service\EmailService
 * @uses \AKlump\WebsiteBackup\Service\ManifestService
 * @uses \AKlump\WebsiteBackup\Service\ProcessRunner
 * @uses \AKlump\WebsiteBackup\Service\TempDirectoryFactory
 * @uses \AKlump\WebsiteBackup\Helper\MoveFileOrDirectory
 * @uses \AKlump\WebsiteBackup\Service\TemporaryFileFactory
 */
class BackupNotificationSizeTest extends TestCase {

  private $test_app_root;
  private $test_local_dir;

  protected function setUp(): void {
    $this->test_app_root = sys_get_temp_dir() . '/wb_size_test_root_' . bin2hex(random_bytes(8));
    $this->test_local_dir = sys_get_temp_dir() . '/wb_size_test_local_' . bin2hex(random_bytes(8));
    mkdir($this->test_app_root, 0700, TRUE);
    mkdir($this->test_local_dir, 0700, TRUE);
    file_put_contents($this->test_app_root . '/file.txt', 'Hello World'); // 11 bytes
  }

  protected function tearDown(): void {
    $this->removeDir($this->test_app_root);
    $this->removeDir($this->test_local_dir);
  }

  private function removeDir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
      (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
    }
    rmdir($dir);
  }

  public function testLocalDirectoryNotificationIncludesSize() {
    $config = [
      'manifest' => [realpath($this->test_app_root) . '/file.txt'],
      'database' => ['handler' => null],
      'object_name' => 'test_backup',
      'notifications' => [
        'email' => [
          'to' => ['ops@example.com'],
          'on_success' => ['subject' => 'Success'],
          'on_fail' => ['subject' => 'Fail'],
        ],
      ],
      '__config_path' => $this->test_app_root . '/config.yml',
    ];
    $output = new BufferedOutput();
    $service = new BackupService($config, $output);

    $reflection = new \ReflectionClass($service);
    $propEmail = $reflection->getProperty('emailService');
    $propEmail->setAccessible(true);
    $mockEmail = $this->createMock(EmailService::class);

    $mockEmail->expects($this->once())
      ->method('send')
      ->with(
        $this->anything(),
        $this->anything(),
        $this->callback(function($body) {
          return strpos($body, 'Artifact size: 11 B') !== false;
        })
      )
      ->willReturn(true);
    $propEmail->setValue($service, $mockEmail);

    $service->run(BackupOptions::FILES | BackupOptions::NOTIFY, $this->test_local_dir);
  }

  public function testLocalGzipNotificationIncludesSize() {
    $config = [
      'manifest' => [realpath($this->test_app_root) . '/file.txt'],
      'database' => ['handler' => null],
      'object_name' => 'test_backup',
      'notifications' => [
        'email' => [
          'to' => ['ops@example.com'],
          'on_success' => ['subject' => 'Success'],
          'on_fail' => ['subject' => 'Fail'],
        ],
      ],
      '__config_path' => $this->test_app_root . '/config.yml',
    ];
    $output = new BufferedOutput();
    $service = new BackupService($config, $output);

    $reflection = new \ReflectionClass($service);
    $propEmail = $reflection->getProperty('emailService');
    $propEmail->setAccessible(true);
    $mockEmail = $this->createMock(EmailService::class);

    $mockEmail->expects($this->once())
      ->method('send')
      ->with(
        $this->anything(),
        $this->anything(),
        $this->callback(function($body) {
          // Gzip size will be more than 11 bytes due to headers but still small
          $matched = preg_match('/Artifact size: \d+(\.\d+)? [KMGT]?B( \(\d+ bytes\))?/', $body) === 1;
          return $matched;
        })
      )
      ->willReturn(true);
    $propEmail->setValue($service, $mockEmail);

    $service->run(BackupOptions::FILES | BackupOptions::NOTIFY | BackupOptions::GZIP, $this->test_local_dir);
  }

  public function testS3NotificationIncludesSize() {
    $config = [
      'manifest' => [realpath($this->test_app_root) . '/file.txt'],
      'database' => ['handler' => null],
      'object_name' => 'test_backup',
      'aws_region' => 'us-east-1',
      'aws_bucket' => 'my-test-bucket',
      'aws_access_key_id' => 'key',
      'aws_secret_access_key' => 'secret',
      'aws_retention' => [
        'keep_all_for_days' => 1,
        'keep_latest_daily_for_days' => 1,
        'keep_latest_monthly_for_months' => 1,
        'keep_latest_yearly_for_years' => 1,
      ],
      'notifications' => [
        'email' => [
          'to' => ['ops@example.com'],
          'on_success' => ['subject' => 'Success'],
          'on_fail' => ['subject' => 'Fail'],
        ],
      ],
      '__config_path' => $this->test_app_root . '/config.yml',
    ];
    $output = new BufferedOutput();
    $service = new BackupService($config, $output);

    $mockS3 = $this->createMock(\AKlump\WebsiteBackup\Service\S3Service::class);
    $mockS3->expects($this->once())->method('upload');
    $service->setS3Factory(function() use ($mockS3) { return $mockS3; });

    $reflection = new \ReflectionClass($service);
    $propEmail = $reflection->getProperty('emailService');
    $propEmail->setAccessible(true);
    $mockEmail = $this->createMock(EmailService::class);

    $mockEmail->expects($this->once())
      ->method('send')
      ->with(
        $this->anything(),
        $this->anything(),
        $this->callback(function($body) {
          $matched = preg_match('/Artifact size: \d+(\.\d+)? [KMGT]?B( \(\d+ bytes\))?/', $body) === 1;
          return $matched;
        })
      )
      ->willReturn(true);
    $propEmail->setValue($service, $mockEmail);

    $service->run(BackupOptions::FILES | BackupOptions::NOTIFY, '');
  }
}
