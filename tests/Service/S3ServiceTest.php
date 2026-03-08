<?php

namespace AKlump\WebsiteBackup\Tests\Service;

use AKlump\WebsiteBackup\Service\S3Service;
use PHPUnit\Framework\TestCase;
use Aws\Result;

/**
 * @covers \AKlump\WebsiteBackup\Service\S3Service
 */
class S3ServiceTest extends TestCase {

  public function testPruneByRetention() {
    $mockS3 = $this->getMockBuilder(\Aws\S3\S3Client::class)
      ->disableOriginalConstructor()
      ->addMethods(['listObjects', 'deleteObjects'])
      ->getMock();

    $bucket = 'test-bucket';
    $region = 'us-west-1';
    $key = 'key';
    $secret = 'secret';

    // Current date: 2026-03-07
    $now = new \DateTime('2026-03-07T12:00:00+0000');

    $objects = [
      // Daily window (7 days)
      ['Key' => 'backup--20260307T100000+0000.tar.gz'],
      // Keep (Today, Newest)
      ['Key' => 'backup--20260307T080000+0000.tar.gz'],
      // Delete (Today, Older)
      ['Key' => 'backup--20260306T100000+0000.tar.gz'],
      // Keep (Yesterday)
      ['Key' => 'backup--20260301T100000+0000.tar.gz'],
      // Keep (Cutoff day 7)

      // Monthly window (2 months: 2026-02, 2026-01)
      ['Key' => 'backup--20260228T100000+0000.tar.gz'],
      // Keep (Feb, Newest)
      ['Key' => 'backup--20260215T100000+0000.tar.gz'],
      // Delete (Feb, Older)
      ['Key' => 'backup--20260115T100000+0000.tar.gz'],
      // Keep (Jan)

      // Older than monthly window
      ['Key' => 'backup--20251215T100000+0000.tar.gz'],
      // Delete (Older than 2 months)

      // Safety: malformed/unrelated
      ['Key' => 'unrelated.txt'],
      // Skip
      ['Key' => 'backup--invalid.tar.gz'],
      // Skip
    ];

    $mockS3->expects($this->once())
      ->method('listObjects')
      ->with(['Bucket' => $bucket])
      ->willReturn(new Result(['Contents' => $objects]));

    $mockS3->expects($this->once())
      ->method('deleteObjects')
      ->with($this->callback(function ($args) use ($bucket) {
        if ($args['Bucket'] !== $bucket) {
          return FALSE;
        }
        $keys = array_column($args['Delete']['Objects'], 'Key');
        sort($keys);
        $expected = [
          'backup--20251215T100000+0000.tar.gz',
          'backup--20260215T100000+0000.tar.gz',
          'backup--20260307T080000+0000.tar.gz',
        ];
        sort($expected);

        return $keys === $expected;
      }));

    // We need to inject the mock S3 client.
    // Since S3Service creates its own client in constructor, we might need to use reflection or change S3Service to accept a client.
    // For testing purposes, let's use reflection to replace the private $s3 property.

    $service = new S3Service($region, $bucket, $key, $secret);
    $reflection = new \ReflectionClass($service);
    $property = $reflection->getProperty('s3');
    $property->setAccessible(TRUE);
    $property->setValue($service, $mockS3);

    $retention = [
      'keep_daily_for_days' => 7,
      'keep_monthly_for_months' => 2,
    ];

    // We also need to control 'now' inside pruneByRetention.
    // I'll update pruneByRetention to optionally accept a reference time, but the requirement didn't ask for it.
    // Alternatively, I can use a library or just hope the system clock is close enough if I use relative offsets.
    // But for a pure test, I should probably have made 'now' injectable.
    // Wait, the requirement said "Use UTC for all retention calculations."

    // Let's modify S3Service to allow passing a reference date for testing, or just use reflection to mock the time if possible.
    // Since I can't easily mock `new DateTime()`, I'll add an optional parameter to pruneByRetention.

    $service->pruneByRetention($retention, $now);
  }

  public function testPruneByRetentionBoundaryCases() {
    $mockS3 = $this->getMockBuilder(\Aws\S3\S3Client::class)
      ->disableOriginalConstructor()
      ->addMethods(['listObjects', 'deleteObjects'])
      ->getMock();

    $bucket = 'test-bucket';
    $service = new S3Service('us-west-1', $bucket, 'key', 'secret');
    $reflection = new \ReflectionClass($service);
    $property = $reflection->getProperty('s3');
    $property->setAccessible(TRUE);
    $property->setValue($service, $mockS3);

    // Case 1: 1 day retention, today and yesterday
    $now = new \DateTime('2026-03-07T12:00:00+0000');
    $objects = [
      ['Key' => 'backup--20260307T100000+0000.tar.gz'],
      // Keep
      ['Key' => 'backup--20260306T100000+0000.tar.gz'],
      // Delete (Daily 1 day means only today)
    ];

    $mockS3->expects($this->exactly(1))
      ->method('listObjects')
      ->willReturn(new Result(['Contents' => $objects]));

    $mockS3->expects($this->once())
      ->method('deleteObjects')
      ->with($this->callback(function ($args) {
        $keys = array_column($args['Delete']['Objects'], 'Key');

        return $keys === ['backup--20260306T100000+0000.tar.gz'];
      }));

    $service->pruneByRetention([
      'keep_daily_for_days' => 1,
      'keep_monthly_for_months' => 0,
    ], $now);

    // Case 2: Month rollover (March 1st)
    $now = new \DateTime('2026-03-01T12:00:00+0000');
    $objects = [
      ['Key' => 'backup--20260301T100000+0000.tar.gz'],
      // Keep (Daily)
      ['Key' => 'backup--20260228T100000+0000.tar.gz'],
      // Keep (Monthly 1 month)
      ['Key' => 'backup--20260131T100000+0000.tar.gz'],
      // Delete (Only 1 month)
    ];

    $mockS3 = $this->getMockBuilder(\Aws\S3\S3Client::class)
      ->disableOriginalConstructor()
      ->addMethods(['listObjects', 'deleteObjects'])
      ->getMock();
    $property->setValue($service, $mockS3);

    $mockS3->expects($this->once())
      ->method('listObjects')
      ->willReturn(new Result(['Contents' => $objects]));

    $mockS3->expects($this->once())
      ->method('deleteObjects')
      ->with($this->callback(function ($args) {
        $keys = array_column($args['Delete']['Objects'], 'Key');

        return $keys === ['backup--20260131T100000+0000.tar.gz'];
      }));

    $service->pruneByRetention([
      'keep_daily_for_days' => 1,
      'keep_monthly_for_months' => 1,
    ], $now);

    // Case 3: Encrypted files recognition
    $now = new \DateTime('2026-03-07T12:00:00+0000');
    $objects = [
      ['Key' => 'backup--20260307T100000+0000.tar.gz.enc'],
      // Keep (Today)
      ['Key' => 'backup--20260306T100000+0000.tar.gz.enc'],
      // Delete (1 day retention)
    ];

    $mockS3 = $this->getMockBuilder(\Aws\S3\S3Client::class)
      ->disableOriginalConstructor()
      ->addMethods(['listObjects', 'deleteObjects'])
      ->getMock();
    $property->setValue($service, $mockS3);

    $mockS3->expects($this->once())
      ->method('listObjects')
      ->willReturn(new Result(['Contents' => $objects]));

    $mockS3->expects($this->once())
      ->method('deleteObjects')
      ->with($this->callback(function ($args) {
        $keys = array_column($args['Delete']['Objects'], 'Key');

        return $keys === ['backup--20260306T100000+0000.tar.gz.enc'];
      }));

    $service->pruneByRetention([
      'keep_daily_for_days' => 1,
      'keep_monthly_for_months' => 0,
    ], $now);
  }
}
