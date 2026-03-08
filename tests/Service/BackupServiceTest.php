<?php

namespace App\Tests\Service;

use App\Service\BackupOptions;
use App\Service\BackupService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

class BackupServiceTest extends TestCase {

  public function testRunRejectsCombinedDatabaseAndFiles() {
    $service = new BackupService([], new NullOutput());
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The DATABASE and FILES options cannot be combined.');
    $service->run(BackupOptions::DATABASE | BackupOptions::FILES);
  }

  public function testRunRejectsEncryptWithoutGzip() {
    $service = new BackupService([], new NullOutput());
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The ENCRYPT option requires GZIP to be set.');
    $service->run(BackupOptions::ENCRYPT | BackupOptions::DATABASE, '/tmp');
  }

  public function testRunRejectsLatestWithoutLocal() {
    $service = new BackupService([], new NullOutput());
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The LATEST option may only be used with a local destination.');
    $service->run(BackupOptions::LATEST | BackupOptions::DATABASE, '');
  }
}
