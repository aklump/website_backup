<?php

/**
 * @file
 * Script to put a object to an S3 bucket and purge older objects.
 *
 * @link https://docs.aws.amazon.com/AmazonS3/latest/dev/s3-access-control.html
 */

require_once __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;

list(, $aws_region, $aws_bucket, $object, $path_to_object, $backups_to_store) = $argv;

try {

  // Make sure the object exists locally.
  if (!file_exists($path_to_object)) {
    throw new \InvalidArgumentException("Object does not exist: \"$path_to_object\".");
  }

  // Instantiate an Amazon S3 client.
  $s3 = new S3Client(['version' => 'latest', 'region' => $aws_region]);
  $s3->putObject([
    'Bucket' => $aws_bucket,
    'Key' => $object,
    'Body' => fopen($path_to_object, 'r'),
  ]);

  // Purge any extra files.
  $result = $s3->listObjects([
    'Bucket' => $aws_bucket,
  ])->get('Contents');

  // Delete excessive files.
  if (count($result) > $backups_to_store) {

    // Ensure we're sorted most recent first so we delete the oldest.
    uasort($result, function ($a, $b) {
      return $a['LastModified'] > $b['LastModified'] ? -1 : 1;
    });

    // Determine the files to be deleted, if any.
    if ($delete_stack = array_slice($result, $backups_to_store)) {
      $action = [
        'Bucket' => $aws_bucket,
        'Delete' => [
          'Objects' => array_map(function ($object) {
            return [
              'Key' => $object['Key'],
            ];
          }, $delete_stack),
        ],
      ];
      $result = $s3->deleteObjects($action);
    }
  }
}
catch (\Exception $e) {
  echo $e->getMessage();
  exit(1);
}
exit(0);
