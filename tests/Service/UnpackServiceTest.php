<?php

namespace AKlump\WebsiteBackup\Tests\Service;

use AKlump\WebsiteBackup\Service\UnpackService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @covers \AKlump\WebsiteBackup\Service\UnpackService
 * @uses \AKlump\WebsiteBackup\Service\ProcessRunner
 * @uses \AKlump\WebsiteBackup\Helper\GetShortPath
 * @uses \AKlump\WebsiteBackup\Service\TempDirectoryFactory
 * @uses \AKlump\WebsiteBackup\Helper\RemoveDirectoryTree
 */
class UnpackServiceTest extends TestCase {

  private $test_dir;

  private $fs;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/website_backup_unpack_test_' . uniqid();
    $this->fs = new Filesystem();
    $this->fs->mkdir($this->test_dir);
  }

  protected function tearDown(): void {
    $this->fs->remove($this->test_dir);
  }

  public function testUnpackTarGz() {
    $source_dir = $this->test_dir . '/my_backup';
    $this->fs->mkdir($source_dir);
    file_put_contents($source_dir . '/file1.txt', 'content1');

    $archive_path = $this->test_dir . '/my_backup.tar.gz';
    // Create real tar.gz
    $cmd = sprintf('cd %s && tar -czf %s %s', escapeshellarg($this->test_dir), escapeshellarg(basename($archive_path)), escapeshellarg(basename($source_dir)));
    exec($cmd);

    $this->fs->remove($source_dir);

    $service = new UnpackService([], new NullOutput());
    $dest_path = $service->unpack($archive_path);

    $this->assertEquals($this->test_dir . '/my_backup', $dest_path);
    $this->assertDirectoryExists($dest_path);
    $this->assertFileExists($dest_path . '/file1.txt');
    $this->assertEquals('content1', file_get_contents($dest_path . '/file1.txt'));
  }

  public function testUnpackTarGzEncrypted() {
    $source_dir = $this->test_dir . '/my_encrypted_backup';
    $this->fs->mkdir($source_dir);
    file_put_contents($source_dir . '/file2.txt', 'content2');

    $password = 'testpassword';
    $archive_path = $this->test_dir . '/my_encrypted_backup.tar.gz';
    $encrypted_path = $archive_path . '.enc';

    // Create real tar.gz and encrypt it
    $cmd = sprintf('cd %s && tar -czf %s %s', escapeshellarg($this->test_dir), escapeshellarg(basename($archive_path)), escapeshellarg(basename($source_dir)));
    exec($cmd);

    $cmd = sprintf(
      'openssl enc -aes-256-cbc -pbkdf2 -salt -in %s -out %s -pass pass:%s',
      escapeshellarg($archive_path),
      escapeshellarg($encrypted_path),
      escapeshellarg($password)
    );
    exec($cmd);

    $this->fs->remove($source_dir);
    $this->fs->remove($archive_path);

    $config = ['encryption' => ['password' => $password]];
    $service = new UnpackService($config, new NullOutput());
    $dest_path = $service->unpack($encrypted_path);

    $this->assertEquals($this->test_dir . '/my_encrypted_backup', $dest_path);
    $this->assertDirectoryExists($dest_path);
    $this->assertFileExists($dest_path . '/file2.txt');
    $this->assertEquals('content2', file_get_contents($dest_path . '/file2.txt'));
  }

  public function testUnpackFailsIfDestExistsWithoutForce() {
    $archive_path = $this->test_dir . '/my_backup.tar.gz';
    touch($archive_path); // Just a dummy
    $dest_path = $this->test_dir . '/my_backup';
    $this->fs->mkdir($dest_path);

    $service = new UnpackService([], new NullOutput());
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Destination directory already exists');
    $service->unpack($archive_path);
  }

  public function testUnpackReplacesDestWithForce() {
    $source_dir = $this->test_dir . '/my_backup';
    $this->fs->mkdir($source_dir);
    file_put_contents($source_dir . '/file1.txt', 'content1');
    $archive_path = $this->test_dir . '/my_backup.tar.gz';
    $cmd = sprintf('cd %s && tar -czf %s %s', escapeshellarg($this->test_dir), escapeshellarg(basename($archive_path)), escapeshellarg(basename($source_dir)));
    exec($cmd);
    $this->fs->remove($source_dir);

    $dest_path = $this->test_dir . '/my_backup';
    $this->fs->mkdir($dest_path);
    touch($dest_path . '/old_file.txt');

    $service = new UnpackService([], new NullOutput());
    $service->unpack($archive_path, TRUE);

    $this->assertDirectoryExists($dest_path);
    $this->assertFileExists($dest_path . '/file1.txt');
    $this->assertFileDoesNotExist($dest_path . '/old_file.txt');
  }

  public function testUnpackDeleteSource() {
    $source_dir = $this->test_dir . '/my_backup';
    $this->fs->mkdir($source_dir);
    touch($source_dir . '/file1.txt');
    $archive_path = $this->test_dir . '/my_backup.tar.gz';
    $cmd = sprintf('cd %s && tar -czf %s %s', escapeshellarg($this->test_dir), escapeshellarg(basename($archive_path)), escapeshellarg(basename($source_dir)));
    exec($cmd);
    $this->fs->remove($source_dir);

    $this->assertFileExists($archive_path);

    $service = new UnpackService([], new NullOutput());
    $service->unpack($archive_path, FALSE, TRUE);

    $this->assertFileDoesNotExist($archive_path);
  }

  public function testUnpackFailsWithUnsupportedExtension() {
    $source_file = $this->test_dir . '/my_backup.zip';
    touch($source_file);

    $service = new UnpackService([], new NullOutput());
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Unsupported file extension');
    $service->unpack($source_file);
  }

  public function testUnpackFailsIfSourceIsDir() {
    $source_dir = $this->test_dir . '/some_dir';
    $this->fs->mkdir($source_dir);

    $service = new UnpackService([], new NullOutput());
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Source path is a directory');
    $service->unpack($source_dir);
  }
}
