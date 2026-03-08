<?php

namespace App\Tests\Service;

use App\Service\DatabaseDumper;
use App\Service\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class DatabaseDumperTest extends TestCase {

  private $test_dir;
  private $fs;

  protected function setUp(): void {
    $this->test_dir = sys_get_temp_dir() . '/wb_db_dumper_test_' . bin2hex(random_bytes(8));
    $this->fs = new Filesystem();
    $this->fs->mkdir($this->test_dir, 0700);
  }

  protected function tearDown(): void {
    $this->fs->remove($this->test_dir);
  }

  public function testDumpUsesDefaultsExtraFile() {
    $mock_runner = $this->createMock(ProcessRunner::class);
    $mock_process = $this->createMock(Process::class);
    $mock_process->method('isSuccessful')->willReturn(TRUE);

    $captured_args = [];
    $mock_runner->method('run')->willReturnCallback(function ($args) use (&$captured_args, $mock_process) {
      $captured_args[] = $args;
      return $mock_process;
    });

    $dumper = new DatabaseDumper($mock_runner);
    $dumper->temp_dir = $this->test_dir;

    $db_config = [
      'host' => 'localhost',
      'user' => 'user',
      'password' => 'pass',
      'name' => 'db',
    ];
    $output_path = $this->test_dir . '/dump.sql';

    $dumper->dump($db_config, $output_path);

    // Verify that --defaults-extra-file was used and NOT --password
    $this->assertCount(1, $captured_args);
    $args = $captured_args[0];
    $this->assertContains('mysqldump', $args);

    $defaults_extra_file_arg = null;
    foreach ($args as $arg) {
      if (strpos($arg, '--defaults-extra-file=') === 0) {
        $defaults_extra_file_arg = $arg;
      }
      $this->assertStringNotContainsString('--password', $arg);
      $this->assertStringNotContainsString('-p', $arg); // Careful with -p vs --port, but we check for password specifically.
    }
    $this->assertNotNull($defaults_extra_file_arg);

    // Verify file was cleaned up
    $config_path = substr($defaults_extra_file_arg, strlen('--defaults-extra-file='));
    $this->assertFileDoesNotExist($config_path);
  }

  public function testDumpWithCacheTables() {
    $mock_runner = $this->createMock(ProcessRunner::class);
    $mock_process_success = $this->createMock(Process::class);
    $mock_process_success->method('isSuccessful')->willReturn(TRUE);
    
    // For listing tables
    $mock_process_tables = $this->createMock(Process::class);
    $mock_process_tables->method('isSuccessful')->willReturn(TRUE);
    $mock_process_tables->method('getOutput')->willReturn("table1\ntable2\n");

    $captured_args = [];
    $mock_runner->method('run')->willReturnCallback(function ($args) use ($mock_process_success, $mock_process_tables, &$captured_args) {
        $captured_args[] = $args;
        if (in_array('-e', $args)) {
            return $mock_process_tables;
        }
        return $mock_process_success;
    });

    $dumper = new DatabaseDumper($mock_runner);
    $dumper->temp_dir = $this->test_dir;
    
    $db_config = [
      'host' => 'localhost',
      'user' => 'user',
      'password' => 'pass',
      'name' => 'db',
    ];
    $output_path = $this->test_dir . '/dump.sql';
    
    $dumper->dump($db_config, $output_path, ['cache_*']);

    // Check that mysql and mysqldump were called without --password
    // 1. structure dump, 2. list tables, 3. data dump
    $this->assertGreaterThanOrEqual(3, count($captured_args));
    
    foreach ($captured_args as $args) {
      foreach ($args as $arg) {
        $this->assertStringNotContainsString('--password', $arg);
        $this->assertStringNotContainsString('-p', $arg);
      }
      $this->assertTrue(
        in_array('mysql', $args) || in_array('mysqldump', $args)
      );
      // Check for --defaults-extra-file
      $has_defaults = false;
      foreach ($args as $arg) {
        if (strpos($arg, '--defaults-extra-file=') === 0) {
          $has_defaults = true;
          break;
        }
      }
      $this->assertTrue($has_defaults, 'Command missing --defaults-extra-file: ' . implode(' ', $args));
    }
  }
}
