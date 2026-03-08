<?php

namespace App\Service;

use Aws\S3\S3Client;

class S3Service {

  private $s3;

  private $region;

  private $bucket;

  public function __construct(string $region, string $bucket, string $key, string $secret) {
    $this->region = $region;
    $this->bucket = $bucket;
    $this->s3 = new S3Client([
      'version' => 'latest',
      'region' => $region,
      'credentials' => [
        'key' => $key,
        'secret' => $secret,
      ],
    ]);
  }

  public function upload(string $object_key, string $file_path): void {
    if (!file_exists($file_path)) {
      throw new \InvalidArgumentException("File does not exist: \"$file_path\".");
    }

    $this->s3->putObject([
      'Bucket' => $this->bucket,
      'Key' => $object_key,
      'SourceFile' => $file_path,
    ]);
  }

  public function pruneByRetention(array $retention, \DateTime $now = NULL): void {
    $keep_daily = $retention['keep_daily_for_days'] ?? 0;
    $keep_monthly = $retention['keep_monthly_for_months'] ?? 0;

    if ($keep_daily === 0 && $keep_monthly === 0) {
      return;
    }

    $objects = $this->s3->listObjects([
      'Bucket' => $this->bucket,
    ])->get('Contents');

    if (!$objects) {
      return;
    }

    $candidates = [];
    foreach ($objects as $object) {
      $key = $object['Key'];

      // Expected pattern: prefix--YYYYMMDDTHHIS+offset.tar.gz[.enc]
      // We'll look for the timestamp part.
      if (!preg_match('/--(\d{8}T\d{6}[+-]\d{4})\.tar\.gz(\.enc)?$/', $key, $matches)) {
        continue;
      }

      $timestamp_str = $matches[1];
      try {
        $date = new \DateTime($timestamp_str);
        $date->setTimezone(new \DateTimeZone('UTC'));

        $candidates[] = [
          'Key' => $key,
          'Timestamp' => $date->getTimestamp(),
          'DayBucket' => $date->format('Y-m-d'),
          'MonthBucket' => $date->format('Y-m'),
        ];
      }
      catch (\Exception $e) {
        // Skip if timestamp is unparseable
        continue;
      }
    }

    if (empty($candidates)) {
      return;
    }

    // Sort candidates newest first
    usort($candidates, function ($a, $b) {
      return $b['Timestamp'] <=> $a['Timestamp'];
    });

    if (!$now) {
      $now = new \DateTime('now', new \DateTimeZone('UTC'));
    }
    else {
      $now = clone $now;
      $now->setTimezone(new \DateTimeZone('UTC'));
    }
    $today = $now->format('Y-m-d');

    $keepers = [];
    $daily_window_start = (clone $now)->modify(sprintf('-%d days', $keep_daily - 1))
      ->format('Y-m-d');

    // Step 1: Daily Keepers
    $daily_buckets_filled = [];
    foreach ($candidates as $candidate) {
      $day = $candidate['DayBucket'];
      if ($day >= $daily_window_start && $day <= $today) {
        if (!isset($daily_buckets_filled[$day])) {
          $keepers[$candidate['Key']] = TRUE;
          $daily_buckets_filled[$day] = TRUE;
        }
      }
    }

    // Step 2: Monthly Keepers
    $monthly_buckets_filled = [];
    // Monthly window starts before the daily window
    // Actually, the requirement says "For backups older than the daily window"
    foreach ($candidates as $candidate) {
      if (isset($keepers[$candidate['Key']])) {
        continue;
      }

      $day = $candidate['DayBucket'];
      if ($day < $daily_window_start) {
        $month = $candidate['MonthBucket'];

        // Monthly window: keep N calendar months prior to TODAY.
        // If today is 2026-03-07, and keep_monthly is 2, we keep 2026-02 and 2026-01.
        // We calculate this by getting the first day of the current month and walking back.
        $first_of_this_month = new \DateTime($now->format('Y-m-01'), new \DateTimeZone('UTC'));
        $monthly_window_start = (clone $first_of_this_month)->modify(sprintf('-%d months', $keep_monthly))
          ->format('Y-m');

        if ($month >= $monthly_window_start && $month < $now->format('Y-m')) {
          if (!isset($monthly_buckets_filled[$month])) {
            $keepers[$candidate['Key']] = TRUE;
            $monthly_buckets_filled[$month] = TRUE;
          }
        }
      }
    }

    // Step 3: Delete the rest
    $to_delete = [];
    foreach ($candidates as $candidate) {
      if (!isset($keepers[$candidate['Key']])) {
        $to_delete[] = ['Key' => $candidate['Key']];
      }
    }

    if (!empty($to_delete)) {
      $this->s3->deleteObjects([
        'Bucket' => $this->bucket,
        'Delete' => [
          'Objects' => $to_delete,
        ],
      ]);
    }
  }


  public function checkConnection(): void {
    $this->s3->listObjects([
      'Bucket' => $this->bucket,
      'MaxKeys' => 1,
    ]);
  }
}
