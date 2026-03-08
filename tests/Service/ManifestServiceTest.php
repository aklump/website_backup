<?php

namespace AKlump\WebsiteBackup\Tests\Service;

use AKlump\WebsiteBackup\Service\ManifestService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AKlump\WebsiteBackup\Service\ManifestService
 */
class ManifestServiceTest extends TestCase {

  private $source_dir;

  private $dest_dir;

  protected function setUp(): void {
    $this->source_dir = sys_get_temp_dir() . '/mb_source';
    $this->dest_dir = sys_get_temp_dir() . '/mb_dest';
    if (!is_dir($this->source_dir)) {
      mkdir($this->source_dir, 0777, TRUE);
    }
    if (!is_dir($this->dest_dir)) {
      mkdir($this->dest_dir, 0777, TRUE);
    }

    file_put_contents($this->source_dir . '/file1.txt', 'test');
    mkdir($this->source_dir . '/dir1');
    file_put_contents($this->source_dir . '/dir1/file2.txt', 'test2');
  }

  protected function tearDown(): void {
    $this->removeDir($this->source_dir);
    $this->removeDir($this->dest_dir);
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

  public function testGetCommandsForFile() {
    $manifest = ['file1.txt'];
    $service = new ManifestService($this->source_dir, $this->dest_dir, $manifest);
    $commands = $service->getCommands();

    $this->assertCount(2, $commands); // mkdir and cp
    $this->assertEquals(['mkdir', '-p', $this->dest_dir], $commands[0]);
    $this->assertEquals([
      'cp',
      $this->source_dir . '/file1.txt',
      $this->dest_dir . '/file1.txt',
    ], $commands[1]);
  }

  public function testGetCommandsForDir() {
    $manifest = ['dir1/'];
    $service = new ManifestService($this->source_dir, $this->dest_dir, $manifest);
    $commands = $service->getCommands();

    $this->assertCount(2, $commands); // mkdir and rsync
    $this->assertEquals([
      'mkdir',
      '-p',
      $this->dest_dir . '/dir1',
    ], $commands[0]);
    $this->assertEquals([
      'rsync',
      '-a',
      $this->source_dir . '/dir1/',
      $this->dest_dir . '/dir1/',
    ], $commands[1]);
  }

  public function testExcludes() {
    $manifest = ['dir1/', '!dir1/file2.txt'];
    $service = new ManifestService($this->source_dir, $this->dest_dir, $manifest);
    $commands = $service->getCommands();

    $this->assertEquals([
      'rsync',
      '-a',
      $this->source_dir . '/dir1/',
      $this->dest_dir . '/dir1/',
      '--exclude=/file2.txt',
    ], $commands[1]);
  }
}
