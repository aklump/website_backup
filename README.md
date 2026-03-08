# Website Backup

![hero](images/hero.jpg)

## Summary

Website Backup creates portable snapshots of a website for storage or transfer.

A backup can include:

- selected files and directories from your application
- a database dump
- optional local archive output as `.tar.gz`
- optional archive encryption
- optional S3 upload with retention-based pruning, for example: keep daily backups for 14 days, then keep monthly backups for 12 months

The tool is designed for repeatable CLI use with configuration in YAML and secrets supplied by environment variables.

## Install with Composer

1. Because this is an unpublished package, you must define it's repository in
   your project's _composer.json_ file. Add the following to _composer.json_ in
   the `repositories` array:
   
    ```json
    {
     "type": "github",
     "url": "https://github.com/aklump/website_backup"
    }
    ```
1. Require this package:
   
    ```
    composer require aklump/website-backup:^0.0
    ```

## Quick Start

2. Create your config file.

   ```bash
   vendor/bin/website_backup install
   ```

3. Edit `bin/config/website_backup.yml` and fill in the variables.

4. Add any required secrets to `.env`.

5. Check your setup.

   ```bash
   vendor/bin/website_backup healthcheck
   ```

6. Run a local backup to test.

   ```bash
   vendor/bin/website_backup backup:local --dir ./backups/
   ```

7. Run an S3 backup to test

   ```bash
   vendor/bin/website_backup backup:s3
   ```

## Setting Up S3

1. [Create a private S3 bucket](https://docs.aws.amazon.com/AmazonS3/latest/userguide/GetStartedWithS3.html) for backups.
2. Create a dedicated IAM user with access to that bucket only.
3. Add your bucket settings to `bin/config/website_backup.yml`.
4. Add your AWS credentials to `.env`.
5. Verify the setup:

   ```bash
   vendor/bin/website_backup healthcheck
   ```

A minimal IAM policy should allow listing the bucket and reading, writing, and deleting objects within it:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:PutObject",
        "s3:DeleteObject"
      ],
      "Resource": "arn:aws:s3:::BUCKET/*"
    },
    {
      "Effect": "Allow",
      "Action": [
        "s3:ListBucket"
      ],
      "Resource": "arn:aws:s3:::BUCKET"
    }
  ]
}
```

Add the credentials to `.env`:

```dotenv
WEBSITE_BACKUP_AWS_ACCESS_KEY_ID=your-access-key
WEBSITE_BACKUP_AWS_SECRET_ACCESS_KEY=your-secret-key
```

## Support My Open Source Work

If you’ve found this project useful, please consider supporting its ongoing maintenance. Even a small contribution helps fund updates, fixes, and new ideas.


  * [Sponsor on GitHub](https://github.com/sponsors/aklump)

  * [Buy Me a Coffee](https://buymeacoffee.com/aklump)

  * [Donate via PayPal](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=4E5KZHDQCEUV8&item_name=Open%20Source%20Sponsorship)
