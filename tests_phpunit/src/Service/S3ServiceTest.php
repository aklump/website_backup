<?php

namespace AKlump\WebsiteBackup\Tests\Service;

use AKlump\WebsiteBackup\Service\S3Service;
use PHPUnit\Framework\TestCase;
use Aws\Result;

/**
 * @covers \AKlump\WebsiteBackup\Service\S3Service
 */
class S3ServiceTest extends TestCase {

  private function getServiceWithMockS3($mockS3) {
    $service = new S3Service('us-west-1', 'test-bucket', 'key', 'secret');
    $reflection = new \ReflectionClass($service);
    $property = $reflection->getProperty('s3');
    $property->setAccessible(TRUE);
    $property->setValue($service, $mockS3);

    return $service;
  }

  public function testPruneByRetentionPhased() {
    $mockS3 = $this->getMockBuilder(\Aws\S3\S3Client::class)
      ->disableOriginalConstructor()
      ->addMethods(['listObjects', 'deleteObjects'])
      ->getMock();

    $bucket = 'test-bucket';
    // Current date: 2026-03-07
    $now = new \DateTime('2026-03-07T12:00:00+0000');

    $objects = [
      // today
      ['Key' => 'backup--20260307T100000+0000.tar.gz'], // Keep (Phase 1: All)
      ['Key' => 'backup--20260307T080000+0000.tar.gz'], // Keep (Phase 1: All)
      // yesterday
      ['Key' => 'backup--20260306T100000+0000.tar.gz'], // Keep (Phase 1: All)
      ['Key' => 'backup--20260306T080000+0000.tar.gz'], // Keep (Phase 1: All)

      // Older than 2 days (Phase 2: Daily starts)
      ['Key' => 'backup--20260305T100000+0000.tar.gz'], // Keep (Phase 2: Daily latest)
      ['Key' => 'backup--20260305T080000+0000.tar.gz'], // Delete (Phase 2: older today)

      // Older than daily window (2 days + 3 days = 5 days total)
      // daily_window_start = 2026-03-07 - (2 + 3 - 1) = 2026-03-03
      // so 2026-03-03 is the last daily.
      // 2026-03-02 starts Phase 3: Monthly.
      ['Key' => 'backup--20260302T100000+0000.tar.gz'], // Keep (Phase 3: Monthly latest March)
      ['Key' => 'backup--20260228T100000+0000.tar.gz'], // Keep (Phase 3: Monthly latest Feb)
      ['Key' => 'backup--20260215T100000+0000.tar.gz'], // Delete (Phase 3: older Feb)

      // Older than monthly window (2 months: March, Feb)
      // 2026-01 starts Phase 4: Yearly.
      ['Key' => 'backup--20260115T100000+0000.tar.gz'], // Keep (Phase 4: Yearly latest 2026)
      ['Key' => 'backup--20251215T100000+0000.tar.gz'], // Keep (Phase 4: Yearly latest 2025)
      ['Key' => 'backup--20250615T100000+0000.tar.gz'], // Delete (Phase 4: older 2025)

      // Older than yearly window (2026, 2025)
      ['Key' => 'backup--20241215T100000+0000.tar.gz'], // Delete (Too old)
    ];

    $mockS3->expects($this->once())
      ->method('listObjects')
      ->with(['Bucket' => $bucket])
      ->willReturn(new Result(['Contents' => $objects]));

    $mockS3->expects($this->once())
      ->method('deleteObjects')
      ->with($this->callback(function ($args) use ($bucket) {
        $keys = array_column($args['Delete']['Objects'], 'Key');
        sort($keys);
        $expected = [
          'backup--20260305T080000+0000.tar.gz',
          'backup--20260115T100000+0000.tar.gz',
          'backup--20250615T100000+0000.tar.gz',
          'backup--20241215T100000+0000.tar.gz',
        ];
        sort($expected);

        return $keys === $expected;
      }));

    $service = $this->getServiceWithMockS3($mockS3);

    $retention = [
      'keep_all_for_days' => 2,
      'keep_latest_daily_for_days' => 3,
      'keep_latest_monthly_for_months' => 2,
      'keep_latest_yearly_for_years' => 2,
    ];

    $service->pruneByRetention($retention, $now);
  }

  public function testPruneByRetentionZeroValues() {
    $mockS3 = $this->getMockBuilder(\Aws\S3\S3Client::class)
      ->disableOriginalConstructor()
      ->addMethods(['listObjects', 'deleteObjects'])
      ->getMock();

    $now = new \DateTime('2026-03-07T12:00:00+0000');
    $objects = [
      ['Key' => 'backup--20260307T100000+0000.tar.gz'],
      ['Key' => 'backup--20260306T100000+0000.tar.gz'],
    ];

    $mockS3->expects($this->once())
      ->method('listObjects')
      ->willReturn(new Result(['Contents' => $objects]));

    // All zero means everything deleted?
    // Wait, Phase 1 keeps nothing. Phase 2 keeps nothing...
    // So everything deleted.
    $mockS3->expects($this->once())
      ->method('deleteObjects')
      ->with($this->callback(function ($args) {
        $keys = array_column($args['Delete']['Objects'], 'Key');
        sort($keys);
        $expected = ['backup--20260306T100000+0000.tar.gz', 'backup--20260307T100000+0000.tar.gz'];
        sort($expected);
        return $keys === $expected;
      }));

    $service = $this->getServiceWithMockS3($mockS3);
    $service->pruneByRetention([
      'keep_all_for_days' => 0,
      'keep_latest_daily_for_days' => 0,
      'keep_latest_monthly_for_months' => 0,
      'keep_latest_yearly_for_years' => 0,
    ], $now);
  }

  public function testPruneByRetentionBoundaryOverlaps() {
    $mockS3 = $this->getMockBuilder(\Aws\S3\S3Client::class)
      ->disableOriginalConstructor()
      ->addMethods(['listObjects', 'deleteObjects'])
      ->getMock();

    $now = new \DateTime('2026-03-07T12:00:00+0000');
    $objects = [
      ['Key' => 'backup--20260307T100000+0000.tar.gz'], // Phase 1
      ['Key' => 'backup--20260306T100000+0000.tar.gz'], // Phase 2 latest
      ['Key' => 'backup--20260306T080000+0000.tar.gz'], // Phase 2 delete
    ];

    $mockS3->expects($this->once())
      ->method('listObjects')
      ->willReturn(new Result(['Contents' => $objects]));

    $mockS3->expects($this->once())
      ->method('deleteObjects')
      ->with($this->callback(function ($args) {
        $keys = array_column($args['Delete']['Objects'], 'Key');
        return $keys === ['backup--20260306T080000+0000.tar.gz'];
      }));

    $service = $this->getServiceWithMockS3($mockS3);
    $service->pruneByRetention([
      'keep_all_for_days' => 1,
      'keep_latest_daily_for_days' => 1,
      'keep_latest_monthly_for_months' => 0,
      'keep_latest_yearly_for_years' => 0,
    ], $now);
  }

  public function testPruneByRetentionSafety() {
    $mockS3 = $this->getMockBuilder(\Aws\S3\S3Client::class)
      ->disableOriginalConstructor()
      ->addMethods(['listObjects', 'deleteObjects'])
      ->getMock();

    $now = new \DateTime('2026-03-07T12:00:00+0000');
    $objects = [
      ['Key' => 'backup--20260307T100000+0000.tar.gz'],
      ['Key' => 'unrelated.txt'],
      ['Key' => 'backup--invalid.tar.gz'],
    ];

    $mockS3->expects($this->once())
      ->method('listObjects')
      ->willReturn(new Result(['Contents' => $objects]));

    // unrelated.txt and backup--invalid.tar.gz should NOT be in delete list
    $mockS3->expects($this->never())
      ->method('deleteObjects');

    $service = $this->getServiceWithMockS3($mockS3);
    $service->pruneByRetention([
      'keep_all_for_days' => 1,
      'keep_latest_daily_for_days' => 0,
      'keep_latest_monthly_for_months' => 0,
      'keep_latest_yearly_for_years' => 0,
    ], $now);
  }
}
