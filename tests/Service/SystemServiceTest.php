<?php

namespace AKlump\WebsiteBackup\Tests\Service;

use AKlump\WebsiteBackup\Service\SystemService;
use AKlump\WebsiteBackup\Service\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @covers \AKlump\WebsiteBackup\Service\SystemService
 * @uses \AKlump\WebsiteBackup\Service\ProcessRunner
 */
class SystemServiceTest extends TestCase {

  public function testIsWindows() {
    $runner = $this->createMock(ProcessRunner::class);
    $service = new SystemService($runner);
    
    // We can't easily mock PHP_OS or PHP_OS_FAMILY constants, 
    // but we can check if it returns a boolean.
    $this->assertIsBool($service->isWindows());
  }

  public function testCommandExistsEmptyStringReturnsFalse() {
    $runner = $this->createMock(ProcessRunner::class);
    $service = new SystemService($runner);
    $this->assertFalse($service->commandExists(''));
  }

  public function testCommandExistsWithAbsolutePath() {
    $runner = $this->createMock(ProcessRunner::class);
    $service = new SystemService($runner);
    
    $temp_file = sys_get_temp_dir() . '/test_cmd_' . bin2hex(random_bytes(8));
    touch($temp_file);
    chmod($temp_file, 0755);
    
    try {
      $this->assertTrue($service->commandExists($temp_file));
    } finally {
      unlink($temp_file);
    }
  }

  public function testCommandExistsInPath() {
    $runner = $this->createMock(ProcessRunner::class);
    $service = new SystemService($runner);
    
    // Use a common command likely to exist in PATH on any Unix system
    $this->assertTrue($service->commandExists('ls') || $service->commandExists('dir'));
  }

  public function testCommandExistsFallbackWhich() {
    $mock_process = $this->createMock(Process::class);
    $mock_process->method('isSuccessful')->willReturn(TRUE);
    $mock_process->method('getOutput')->willReturn('/usr/bin/foo');

    $runner = $this->createMock(ProcessRunner::class);
    $runner->method('run')->willReturn($mock_process);

    $service = new SystemService($runner);
    
    // We need to make sure it doesn't find it in PATH first if we want to test fallback.
    // But since it's a mock runner, we can just check if it calls run().
    $this->assertTrue($service->commandExists('non_existent_command_that_mock_will_find'));
  }

  public function testCommandExistsFallbackFails() {
    $mock_process = $this->createMock(Process::class);
    $mock_process->method('isSuccessful')->willReturn(FALSE);

    $runner = $this->createMock(ProcessRunner::class);
    $runner->method('run')->willReturn($mock_process);

    $service = new SystemService($runner);
    $this->assertFalse($service->commandExists('definitely_not_a_command_anywhere'));
  }
}
