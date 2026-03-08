<?php

namespace AKlump\WebsiteBackup\Service;

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
    if (empty($retention)) {
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
          'YearBucket' => $date->format('Y'),
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

    $keepers = [];

    // Phase 1: Keep all within keep_all_for_days
    $keep_all_days = $retention['keep_all_for_days'] ?? 0;
    $all_window_start = (clone $now)->modify(sprintf('-%d days', $keep_all_days - 1))
      ->format('Y-m-d');
    $today = $now->format('Y-m-d');

    foreach ($candidates as $candidate) {
      $day = $candidate['DayBucket'];
      if ($keep_all_days > 0 && $day >= $all_window_start && $day <= $today) {
        $keepers[$candidate['Key']] = TRUE;
      }
    }

    // Phase 2: Keep latest daily for keep_latest_daily_for_days
    $keep_daily_days = $retention['keep_latest_daily_for_days'] ?? 0;
    if ($keep_daily_days > 0) {
      $daily_window_end = (clone $now)->modify(sprintf('-%d days', $keep_all_days))
        ->format('Y-m-d');
      $daily_window_start = (clone $now)->modify(sprintf('-%d days', $keep_all_days + $keep_daily_days - 1))
        ->format('Y-m-d');

      $daily_buckets_filled = [];
      foreach ($candidates as $candidate) {
        if (isset($keepers[$candidate['Key']])) {
          continue;
        }
        $day = $candidate['DayBucket'];
        if ($day >= $daily_window_start && $day <= $daily_window_end) {
          if (!isset($daily_buckets_filled[$day])) {
            $keepers[$candidate['Key']] = TRUE;
            $daily_buckets_filled[$day] = TRUE;
          }
        }
      }
    }

    // Phase 3: Keep latest monthly for keep_latest_monthly_for_months
    $keep_monthly_months = $retention['keep_latest_monthly_for_months'] ?? 0;
    if ($keep_monthly_months > 0) {
      $daily_window_total_days = $keep_all_days + $keep_daily_days;
      $monthly_window_latest_day = (clone $now)->modify(sprintf('-%d days', $daily_window_total_days))
        ->format('Y-m-d');

      // Calculate start month: subtract N months from current month
      $first_of_this_month = new \DateTime($now->format('Y-m-01'), new \DateTimeZone('UTC'));
      $monthly_window_start_month = (clone $first_of_this_month)->modify(sprintf('-%d months', $keep_monthly_months - 1))
        ->format('Y-m');

      $monthly_buckets_filled = [];
      foreach ($candidates as $candidate) {
        if (isset($keepers[$candidate['Key']])) {
          continue;
        }
        $day = $candidate['DayBucket'];
        $month = $candidate['MonthBucket'];
        if ($day <= $monthly_window_latest_day && $month >= $monthly_window_start_month) {
          if (!isset($monthly_buckets_filled[$month])) {
            $keepers[$candidate['Key']] = TRUE;
            $monthly_buckets_filled[$month] = TRUE;
          }
        }
      }
    }

    // Phase 4: Keep latest yearly for keep_latest_yearly_for_years
    $keep_yearly_years = $retention['keep_latest_yearly_for_years'] ?? 0;
    if ($keep_yearly_years > 0) {
      $daily_window_total_days = $keep_all_days + $keep_daily_days;
      $yearly_window_latest_day = (clone $now)->modify(sprintf('-%d days', $daily_window_total_days))
        ->format('Y-m-d');

      $this_year = (int) $now->format('Y');
      $yearly_window_start_year = $this_year - ($keep_yearly_years - 1);

      $yearly_buckets_filled = [];
      foreach ($candidates as $candidate) {
        if (isset($keepers[$candidate['Key']])) {
          continue;
        }
        $day = $candidate['DayBucket'];
        $year = (int) $candidate['YearBucket'];
        if ($day <= $yearly_window_latest_day && $year >= $yearly_window_start_year) {
          if (!isset($yearly_buckets_filled[$year])) {
            $keepers[$candidate['Key']] = TRUE;
            $yearly_buckets_filled[$year] = TRUE;
          }
        }
      }
    }

    // Step 5: Delete the rest
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
