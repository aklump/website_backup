<?php

namespace AKlump\WebsiteBackup\Helper;

class S3LinkBuilder {

  /**
   * Build an s3:// URI.
   */
  public function buildS3Uri(string $bucket, string $key): string {
    return sprintf('s3://%s/%s', $bucket, $key);
  }

  /**
   * Build a URL to the S3 bucket/prefix in the AWS Console.
   */
  public function buildConsoleUrl(string $bucket, string $region, string $key): string {
    $prefix = $this->extractPrefix($key);
    $url = sprintf(
      'https://s3.console.aws.amazon.com/s3/buckets/%s?region=%s',
      rawurlencode($bucket),
      rawurlencode($region)
    );

    if ($prefix !== NULL) {
      $url .= '&prefix=' . rawurlencode($prefix);
    }

    return $url;
  }

  /**
   * Extract the directory-like prefix from an object key.
   */
  public function extractPrefix(string $key): ?string {
    $last_slash_pos = strrpos($key, '/');
    if ($last_slash_pos === FALSE) {
      return NULL;
    }

    return substr($key, 0, $last_slash_pos + 1);
  }

}
