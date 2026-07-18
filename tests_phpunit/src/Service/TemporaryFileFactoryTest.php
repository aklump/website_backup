<?php

namespace AKlump\WebsiteBackup\Tests\Service;

use AKlump\WebsiteBackup\Service\TemporaryFileFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @covers \AKlump\WebsiteBackup\Service\TemporaryFileFactory
 */
class TemporaryFileFactoryTest extends TestCase {

  private $test_dir;
  private $fs;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/wb_temp_file_test_' . bin2hex(random_bytes(8));
    $this->fs = new Filesystem();
    $this->fs->mkdir($this->test_dir, 0700);
  }

  protected function tearDown(): void {
    $this->fs->remove($this->test_dir);
  }

  public function testCreate() {
    $factory = new TemporaryFileFactory();
    $content = "test content";
    $path = $factory->create($this->test_dir, $content);

    $this->assertFileExists($path);
    $this->assertEquals($content, file_get_contents($path));

    // Check permissions (0600)
    $perms = fileperms($path) & 0777;
    $this->assertEquals(0600, $perms);

    $factory->cleanup($path);
    $this->assertFileDoesNotExist($path);
  }

  public function testCreateInNonExistentDirectory() {
    $dir = $this->test_dir . '/nested';
    $factory = new TemporaryFileFactory();
    $path = $factory->create($dir, "content");

    $this->assertFileExists($path);
    $this->assertDirectoryExists($dir);

    // Check directory permissions (0700)
    $dir_perms = fileperms($dir) & 0777;
    $this->assertEquals(0700, $dir_perms);
  }

}
