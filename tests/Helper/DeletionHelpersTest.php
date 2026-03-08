<?php

namespace AKlump\WebsiteBackup\Tests\Helper;

use AKlump\WebsiteBackup\Helper\AssertSafeBackupArtifactPath;
use AKlump\WebsiteBackup\Helper\RemoveDirectoryTree;
use AKlump\WebsiteBackup\Helper\RemoveFileOrSymlink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @covers \AKlump\WebsiteBackup\Helper\AssertSafeBackupArtifactPath
 * @covers \AKlump\WebsiteBackup\Helper\RemoveDirectoryTree
 * @covers \AKlump\WebsiteBackup\Helper\RemoveFileOrSymlink
 */
class DeletionHelpersTest extends TestCase {

  private $test_dir;
  private $fs;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/wb_deletion_test_' . bin2hex(random_bytes(8));
    $this->fs = new Filesystem();
    $this->fs->mkdir($this->test_dir, 0700);
  }

  protected function tearDown(): void {
    $this->fs->remove($this->test_dir);
  }

  public function testAssertSafeBackupArtifactPathSuccess() {
    $path = $this->test_dir . '/backup--20260308T120000';
    $this->fs->mkdir($path);
    $helper = new AssertSafeBackupArtifactPath();
    $helper($path, $this->test_dir, 'backup');
    $this->assertTrue(true);
  }

  public function testAssertSafeBackupArtifactPathFailsIfEmpty() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('cannot be empty');
    $helper = new AssertSafeBackupArtifactPath();
    $helper('', $this->test_dir, 'backup');
  }

  public function testAssertSafeBackupArtifactPathFailsIfRoot() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Cannot delete the backup root');
    $helper = new AssertSafeBackupArtifactPath();
    $helper($this->test_dir, $this->test_dir, 'backup');
  }

  public function testAssertSafeBackupArtifactPathFailsIfOutside() {
    $outside = sys_get_temp_dir() . '/wb_outside_' . bin2hex(random_bytes(8));
    $this->fs->mkdir($outside);
    try {
      $this->expectException(\InvalidArgumentException::class);
      $this->expectExceptionMessage('outside the configured local backup directory');
      $helper = new AssertSafeBackupArtifactPath();
      $helper($outside, $this->test_dir, 'backup');
    } finally {
      $this->fs->remove($outside);
    }
  }

  public function testAssertSafeBackupArtifactPathFailsIfSymlink() {
    $path = $this->test_dir . '/backup--latest';
    $target = $this->test_dir . '/target';
    $this->fs->mkdir($target);
    symlink($target, $path);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Recursive deletion refused for symlink');
    $helper = new AssertSafeBackupArtifactPath();
    $helper($path, $this->test_dir, 'backup');
  }

  public function testAssertSafeBackupArtifactPathFailsIfWrongBasename() {
    $path = $this->test_dir . '/something_else';
    $this->fs->mkdir($path);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('does not match the expected backup artifact prefix');
    $helper = new AssertSafeBackupArtifactPath();
    $helper($path, $this->test_dir, 'backup');
  }

  public function testRemoveDirectoryTreeSuccess() {
    $path = $this->test_dir . '/tree';
    $this->fs->mkdir($path . '/sub/dir');
    touch($path . '/sub/dir/file.txt');
    touch($path . '/file2.txt');

    $helper = new RemoveDirectoryTree();
    $helper($path);

    $this->assertDirectoryDoesNotExist($path);
  }

  public function testRemoveDirectoryTreeFailsIfNotDir() {
    $path = $this->test_dir . '/file.txt';
    touch($path);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Path is not a directory');
    $helper = new RemoveDirectoryTree();
    $helper($path);
  }

  public function testRemoveFileOrSymlinkSuccess() {
    $file = $this->test_dir . '/file.txt';
    touch($file);
    $link = $this->test_dir . '/link';
    symlink($file, $link);

    $helper = new RemoveFileOrSymlink();
    $helper($file);
    $this->assertFileDoesNotExist($file);

    $helper($link);
    $this->assertFileDoesNotExist($link);
  }

  public function testRemoveFileOrSymlinkFailsIfDir() {
    $dir = $this->test_dir . '/dir';
    $this->fs->mkdir($dir);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Cannot remove directory using RemoveFileOrSymlink');
    $helper = new RemoveFileOrSymlink();
    $helper($dir);
  }
}
