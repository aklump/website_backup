<?php

namespace AKlump\WebsiteBackup\Tests\Helper;

use AKlump\WebsiteBackup\Helper\S3LinkBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \AKlump\WebsiteBackup\Helper\S3LinkBuilder
 */
class S3LinkBuilderTest extends TestCase {

  /**
   * @dataProvider providerTestBuildS3Uri
   */
  public function testBuildS3Uri(string $bucket, string $key, string $expected) {
    $builder = new S3LinkBuilder();
    $this->assertEquals($expected, $builder->buildS3Uri($bucket, $key));
  }

  public function providerTestBuildS3Uri(): array {
    return [
      ['example-bucket', 'backup.tar.gz', 's3://example-bucket/backup.tar.gz'],
      ['example-bucket', 'backups/site/backup.tar.gz', 's3://example-bucket/backups/site/backup.tar.gz'],
    ];
  }

  /**
   * @dataProvider providerTestExtractPrefix
   */
  public function testExtractPrefix(string $key, ?string $expected) {
    $builder = new S3LinkBuilder();
    $this->assertEquals($expected, $builder->extractPrefix($key));
  }

  public function providerTestExtractPrefix(): array {
    return [
      ['file.tar.gz', NULL],
      ['backups/file.tar.gz', 'backups/'],
      ['a/b/c/file.tar.gz', 'a/b/c/'],
      ['folder/', 'folder/'],
    ];
  }

  /**
   * @dataProvider providerTestBuildConsoleUrl
   */
  public function testBuildConsoleUrl(string $bucket, string $region, string $key, string $expected) {
    $builder = new S3LinkBuilder();
    $this->assertEquals($expected, $builder->buildConsoleUrl($bucket, $region, $key));
  }

  public function providerTestBuildConsoleUrl(): array {
    return [
      [
        'example-bucket',
        'us-west-1',
        'backup-20260307.tar.gz',
        'https://s3.console.aws.amazon.com/s3/buckets/example-bucket?region=us-west-1',
      ],
      [
        'example-bucket',
        'us-west-1',
        'backups/site/backup-20260307.tar.gz',
        'https://s3.console.aws.amazon.com/s3/buckets/example-bucket?region=us-west-1&prefix=backups%2Fsite%2F',
      ],
      [
        'bucket name with spaces',
        'us-east-1',
        'a/b/file.txt',
        'https://s3.console.aws.amazon.com/s3/buckets/bucket%20name%20with%20spaces?region=us-east-1&prefix=a%2Fb%2F',
      ],
    ];
  }

}
