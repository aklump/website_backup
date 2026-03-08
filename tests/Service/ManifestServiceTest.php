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
    $manifest = [$this->source_dir . '/file1.txt'];
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
    $manifest = [$this->source_dir . '/dir1/'];
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
    $manifest = [$this->source_dir . '/dir1/', '!' . $this->source_dir . '/dir1/file2.txt'];
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

  public function testManifestWithAbsolutePathsSucceeds() {
    $manifest = ['/absolute/path'];
    $service = new ManifestService($this->source_dir, $this->dest_dir, $manifest);
    $this->assertEquals($manifest, $service->getManifestItems());
  }

  public function testManifestWithAbsoluteExcludesSucceeds() {
    $manifest = ['!/absolute/path'];
    $service = new ManifestService($this->source_dir, $this->dest_dir, $manifest);
    $this->assertEquals($manifest, $service->getManifestItems());
  }

  public function testGetManifestItems() {
    $manifest = [$this->source_dir . '/file1.txt', $this->source_dir . '/dir1/'];
    $service = new ManifestService($this->source_dir, $this->dest_dir, $manifest);
    $this->assertEquals($manifest, $service->getManifestItems());
  }

  public function testResolve() {
    $service = new ManifestService($this->source_dir, $this->dest_dir, []);
    $this->assertEquals([$this->source_dir . '/file1.txt'], $service->resolve($this->source_dir . '/file1.txt'));
    $this->assertEquals([$this->source_dir . '/dir1'], $service->resolve($this->source_dir . '/dir1'));
    $this->assertEmpty($service->resolve($this->source_dir . '/non_existent.txt'));
  }

  public function testResolveWithGlob() {
    file_put_contents($this->source_dir . '/file2.txt', 'test2');
    $service = new ManifestService($this->source_dir, $this->dest_dir, []);
    $resolved = $service->resolve($this->source_dir . '/file*.txt');
    sort($resolved);
    $expected = [$this->source_dir . '/file1.txt', $this->source_dir . '/file2.txt'];
    sort($expected);
    $this->assertEquals($expected, $resolved);
  }

  public function testResolveExcludePattern() {
    $service = new ManifestService($this->source_dir, $this->dest_dir, []);
    $this->assertEquals([$this->source_dir . '/file1.txt'], $service->resolve('!' . $this->source_dir . '/file1.txt'));
  }

  public function testPrepareCommandsOptimizesMkdir() {
    // This tests the logic in prepareCommands that avoids redundant mkdir -p calls
    $manifest = [$this->source_dir . '/dir1/subdir/file.txt', $this->source_dir . '/dir1/file2.txt'];
    mkdir($this->source_dir . '/dir1/subdir', 0777, TRUE);
    file_put_contents($this->source_dir . '/dir1/subdir/file.txt', 'content');
    file_put_contents($this->source_dir . '/dir1/file2.txt', 'content2');

    $service = new ManifestService($this->source_dir, $this->dest_dir, $manifest);
    $commands = $service->getCommands();

    $mkdir_commands = array_filter($commands, function($cmd) { return $cmd[0] === 'mkdir'; });
    $this->assertCount(1, $mkdir_commands);
    $this->assertEquals(['mkdir', '-p', $this->dest_dir . '/dir1/subdir'], reset($mkdir_commands));
  }
}
