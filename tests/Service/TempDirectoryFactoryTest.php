<?php

namespace App\Tests\Service;

use App\Service\TempDirectoryFactory;
use PHPUnit\Framework\TestCase;

class TempDirectoryFactoryTest extends TestCase {

  private string $test_base;

  protected function setUp(): void {
    $this->test_base = sys_get_temp_dir() . '/website_backup_factory_test_' . bin2hex(random_bytes(8));
    if (!is_dir($this->test_base)) {
      mkdir($this->test_base, 0700, TRUE);
    }
  }

  protected function tearDown(): void {
    if (is_dir($this->test_base)) {
      $this->removeDir($this->test_base);
    }
  }

  private function removeDir($dir) {
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
      (is_dir("$dir/$file")) ? $this->removeDir("$dir/$file") : unlink("$dir/$file");
    }

    return rmdir($dir);
  }

  public function testCreateUniqueDirectory() {
    $factory = new TempDirectoryFactory($this->test_base);
    $path1 = $factory->create();
    $path2 = $factory->create();

    $this->assertDirectoryExists($path1);
    $this->assertDirectoryExists($path2);
    $this->assertNotEquals($path1, $path2);
    $this->assertStringContainsString($this->test_base, $path1);
    
    // Check permissions (0700)
    // octdec(substr(sprintf('%o', fileperms($path1)), -4)) on some systems might return 0700
    $this->assertEquals('0700', substr(sprintf('%o', fileperms($path1)), -4));
  }

  public function testCleanup() {
    $factory = new TempDirectoryFactory($this->test_base);
    $path = $factory->create();
    $this->assertDirectoryExists($path);
    
    file_put_contents($path . '/test.txt', 'hello');
    
    $factory->cleanup($path);
    $this->assertDirectoryDoesNotExist($path);
  }
}
