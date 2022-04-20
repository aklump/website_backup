<?php

use Cloudy\AKlump\WebsiteBackup\DefaultHandler;
use PHPUnit\Framework\TestCase;

/**
 * @group default
 * @covers \Cloudy\AKlump\WebsiteBackup\DefaultHandler
 */
final class DefaultHandlerTest extends TestCase {

  private $source = '';

  public function testReduceDuplicateParentMkdir() {
    $handler = new DefaultHandler();
    $commands = $handler
      ->setSource($this->source)
      ->setDestination('/foo')
      ->setManifest([
        'web/sites/*/files/',
        'web/sites/*/README.txt',
      ])->getCommands();
    $this->assertCount(3, $commands);
    $this->assertSame('mkdir -p /foo/web/sites/default/files', $commands[0]);
    $this->assertStringStartsWith('rsync', $commands[1]);
    $this->assertStringStartsWith('cp', $commands[2]);
  }

  public function testDedupeCommands() {
    $handler = new DefaultHandler();
    $commands = $handler
      ->setSource($this->source)
      ->setDestination('/foo')
      ->setManifest([
        'web/sites/*/files/',
        'web/sites/default/files/',
        'web/sites/*/files/alpha.txt',
        'web/sites/*/files/',
        'web/sites/default/files/',
        'web/sites/*/files/alpha.txt',
      ])->getCommands();
    $this->assertCount(3, $commands);
    $this->assertStringStartsWith('mkdir', $commands[0]);
    $this->assertStringStartsWith('rsync', $commands[1]);
    $this->assertStringStartsWith('cp', $commands[2]);
  }

  public function testExclusionGlobBug() {
    $handler = new DefaultHandler();
    $commands = $handler
      ->setSource($this->source)
      ->setDestination('/foo')
      ->setManifest([
        'web/sites/*/files/',
        '!web/sites/*/files/styles/',
      ])->getCommands();
    $this->assertStringContainsString('/foo/web/sites/default/files/', $commands[1]);
    $this->assertStringContainsString(' --exclude=/styles', $commands[1]);
  }

  public function testGlobExpansion() {
    $handler = new DefaultHandler();
    $commands = $handler
      ->setSource($this->source)
      ->setDestination('/foo')
      ->setManifest([
        'web/sites/*/README.*',
      ])->getCommands();

    $this->assertSame('mkdir -p /foo/web/sites/default', $commands[0]);
    $this->assertStringContainsString('cp', $commands[1]);
    $this->assertStringContainsString('/website_backup/web/sites/default/README.md ', $commands[1]);
    $this->assertStringContainsString(' /foo/web/sites/default/README.md', $commands[1]);

    $this->assertStringContainsString('cp', $commands[2]);
    $this->assertStringContainsString('/website_backup/web/sites/default/README.txt ', $commands[2]);
    $this->assertStringContainsString(' /foo/web/sites/default/README.txt', $commands[2]);
  }

  public function testGetCommandsThrowsWithoutSetDestination() {
    $this->expectException(\RuntimeException::class);
    $handler = new DefaultHandler();
    $handler->setSource($this->source)->getCommands();
  }

  public function testGetCommand() {
    $handler = new DefaultHandler();
    $handler
      ->setSource($this->source)
      ->setDestination($this->destination);
    $manifest = [
      'web/sites/default/files',
      'web/sites/default/files/alpha.txt',
      '!web/sites/default/files/bravo.txt',
      '!web/sites/default/files/styles',
    ];

    $commands = $handler->setManifest($manifest)->getCommands();

    $this->assertSame('mkdir -p /foo/web/sites/default/files', $commands[0]);

    $this->assertStringContainsString('rsync ', $commands[1]);
    $this->assertStringContainsString('/website_backup/web/sites/default/files/ ', $commands[1]);
    $this->assertStringContainsString(' /foo/web/sites/default/files/ ', $commands[1]);
    $this->assertStringContainsString(' --exclude=/bravo.txt ', $commands[1]);
    $this->assertStringContainsString(' --exclude=/styles', $commands[1]);

    $this->assertStringContainsString('cp ', $commands[2]);
    $this->assertStringContainsString('/website_backup/web/sites/default/files/alpha.txt ', $commands[2]);
    $this->assertStringContainsString(' /foo/web/sites/default/files/alpha.txt', $commands[2]);
  }

  public function testSetDestinationThrowsIfNotAbsolute() {
    $this->expectException(\InvalidArgumentException::class);
    $handler = new DefaultHandler();
    $handler->setDestination('foo');
  }

  public function testSetDestination() {
    $handler = new DefaultHandler();
    $this->assertSame($handler, $handler->setDestination('/foo'));
  }

  public function testSetSource() {
    $handler = new DefaultHandler();
    $this->assertSame($handler, $handler->setSource(''));
  }

  public function testAbsolutePathInManifestThrows() {
    $this->expectException(InvalidArgumentException::class);
    $handler = new DefaultHandler();
    $manifest = [
      '/web/sites/default/files',
    ];
    $handler->setManifest($manifest);
  }

  public function testSetManifestReturnsSelf() {
    $handler = new DefaultHandler();
    $manifest = [
      'web/sites/default/files',
      '!web/sites/default/files/styles',
    ];
    $this->assertSame($handler, $handler->setManifest($manifest));
  }

  public function setUp(): void {
    $this->source = realpath(__DIR__ . '/../../../../');
    $this->destination = '/foo/';
  }

}
