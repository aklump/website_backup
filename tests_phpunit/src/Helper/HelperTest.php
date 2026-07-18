<?php

namespace AKlump\WebsiteBackup\Tests\Helper;

use AKlump\WebsiteBackup\Helper\CreateMysqlTempConfig;
use AKlump\WebsiteBackup\Helper\GetInstalledInRoot;
use AKlump\WebsiteBackup\Helper\GetShortPath;
use AKlump\WebsiteBackup\Service\BackupOptions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AKlump\WebsiteBackup\Helper\CreateMysqlTempConfig
 * @covers \AKlump\WebsiteBackup\Helper\GetInstalledInRoot
 * @covers \AKlump\WebsiteBackup\Helper\GetShortPath
 * @covers \AKlump\WebsiteBackup\Service\BackupOptions
 * @uses \AKlump\WebsiteBackup\Service\TemporaryFileFactory
 */
class HelperTest extends TestCase {

  public function testGetShortPath() {
    $base = '/tmp/test';
    $helper = new GetShortPath($base);
    $this->assertEquals('sub/file.php', $helper($base . '/sub/file.php'));
    $this->assertEquals('/other/file.php', $helper('/other/file.php'));
  }

  public function testGetInstalledInRoot() {
    $test_dir = sys_get_temp_dir() . '/wb_root_test_' . bin2hex(random_bytes(8));
    // Ensure we have the real path (resolves /var to /private/var on macOS)
    if (!is_dir($test_dir)) {
      mkdir($test_dir, 0700, TRUE);
    }
    $test_dir = realpath($test_dir);

    mkdir($test_dir . '/bin/config', 0700, TRUE);
    touch($test_dir . '/bin/config/website_backup.yml');
    
    $cwd = getcwd();
    chdir($test_dir . '/bin/config');
    
    $helper = new GetInstalledInRoot();
    $this->assertEquals($test_dir, $helper());
    
    chdir($cwd);
    $this->removeDir($test_dir);
  }

  public function testCreateMysqlTempConfig() {
    $test_dir = sys_get_temp_dir() . '/wb_mysql_test_' . bin2hex(random_bytes(8));
    mkdir($test_dir, 0700);
    
    $helper = new CreateMysqlTempConfig();
    $config = [
      'host' => 'localhost',
      'user' => 'u',
      'password' => 'p',
      'name' => 'n',
      'port' => '3306',
    ];
    $path = $helper($config, $test_dir);
    
    $this->assertFileExists($path);
    $content = file_get_contents($path);
    $this->assertStringContainsString('[client]', $content);
    $this->assertStringContainsString('host=localhost', $content);
    $this->assertStringContainsString('user=u', $content);
    $this->assertStringContainsString('password=p', $content);
    $this->assertStringContainsString('port=3306', $content);
    
    unlink($path);
    rmdir($test_dir);
  }

  public function testBackupOptions() {
    // Just ensure the class exists and constants are accessible
    $this->assertEquals(1, BackupOptions::DATABASE);
    $this->assertEquals(2, BackupOptions::FILES);
  }

  private function removeDir($dir) {
    if (!is_dir($dir)) {
      return;
    }
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
      (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
  }
}
