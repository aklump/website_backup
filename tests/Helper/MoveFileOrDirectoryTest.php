<?php

namespace AKlump\WebsiteBackup\Tests\Helper;

use AKlump\WebsiteBackup\Helper\MoveFileOrDirectory;
use AKlump\WebsiteBackup\Helper\RemoveDirectoryTree;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AKlump\WebsiteBackup\Helper\MoveFileOrDirectory
 * @uses \AKlump\WebsiteBackup\Helper\GetShortPath
 * @uses \AKlump\WebsiteBackup\Helper\RemoveDirectoryTree
 */
class MoveFileOrDirectoryTest extends TestCase {

  private string $testDir;

  protected function setUp(): void {
    $this->testDir = sys_get_temp_dir() . '/website_backup_move_test_' . uniqid();
    mkdir($this->testDir);
  }

  protected function tearDown(): void {
    if (is_dir($this->testDir)) {
      (new RemoveDirectoryTree())($this->testDir);
    }
  }

  public function testMoveFileUsesRenameSucceeds() {
    $source = $this->testDir . '/source.txt';
    $destination = $this->testDir . '/dest.txt';
    file_put_contents($source, 'hello');

    $mover = new MoveFileOrDirectory();
    $mover($source, $destination);

    $this->assertFileExists($destination);
    $this->assertFileDoesNotExist($source);
    $this->assertEquals('hello', file_get_contents($destination));
  }

  public function testMoveDirectoryUsesRenameSucceeds() {
    $source = $this->testDir . '/source_dir';
    $destination = $this->testDir . '/dest_dir';
    mkdir($source);
    file_put_contents($source . '/file.txt', 'hello');

    $mover = new MoveFileOrDirectory();
    $mover($source, $destination);

    $this->assertDirectoryExists($destination);
    $this->assertFileExists($destination . '/file.txt');
    $this->assertDirectoryDoesNotExist($source);
  }

  public function testMoveFileFallbackSucceeds() {
    $source = $this->testDir . '/source.txt';
    $destination = $this->testDir . '/dest.txt';
    file_put_contents($source, 'hello');

    // We can't easily make rename fail across filesystems in a simple test,
    // but we can mock the behavior if we use a subclass or a different approach.
    // For now, let's test the private methods directly or via a mock if needed.

    $mover = new class extends MoveFileOrDirectory {

      public bool $renameCalled = FALSE;

      public function __invoke(string $source, string $destination): void {
        $this->renameCalled = TRUE;
        // Force fallback by not calling rename()
        if (is_dir($source)) {
          $method = new \ReflectionMethod(MoveFileOrDirectory::class, 'moveDirectory');
          $method->setAccessible(TRUE);
          $method->invoke($this, $source, $destination);
        }
        else {
          $method = new \ReflectionMethod(MoveFileOrDirectory::class, 'moveFile');
          $method->setAccessible(TRUE);
          $method->invoke($this, $source, $destination);
        }
      }
    };

    $mover($source, $destination);
    $this->assertTrue($mover->renameCalled);
    $this->assertFileExists($destination);
    $this->assertFileDoesNotExist($source);
    $this->assertEquals('hello', file_get_contents($destination));
  }

  public function testMoveDirectoryFallbackSucceeds() {
    $source = $this->testDir . '/source_dir';
    $destination = $this->testDir . '/dest_dir';
    mkdir($source);
    mkdir($source . '/sub');
    file_put_contents($source . '/sub/file.txt', 'hello');

    $mover = new class extends MoveFileOrDirectory {

      public function __invoke(string $source, string $destination): void {
        $method = new \ReflectionMethod(MoveFileOrDirectory::class, 'moveDirectory');
        $method->setAccessible(TRUE);
        $method->invoke($this, $source, $destination);
      }
    };

    $mover($source, $destination);
    $this->assertDirectoryExists($destination);
    $this->assertDirectoryExists($destination . '/sub');
    $this->assertFileExists($destination . '/sub/file.txt');
    $this->assertDirectoryDoesNotExist($source);
  }
}
