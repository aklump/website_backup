<!--
id: readme
tags: ''
-->

# Website Backup

![hero](../../images/hero.jpg)

## Summary

Website Backup creates portable snapshots of a website for storage or transfer.

A backup can include:

- selected files and directories from your application
- a database dump
- optional local archive output as `.tar.gz`
- optional archive encryption
- optional S3 upload with retention-based pruning, for example: keep daily backups for 14 days, then keep monthly backups for 12 months

The tool is designed for repeatable CLI use with configuration in YAML and secrets supplied by environment variables.

{{ composer.install|raw }}

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

## S3 Bucket Requirements

The backup process requires a **dedicated S3 bucket**.

**DO NOT SHARE THE BUCKET** with other applications or unrelated data.

The retention and pruning logic assumes full ownership of the bucket's content matching the backup pattern. Sharing the bucket can lead to unintended pruning behavior, performance issues, or operator confusion.

## Setting Up S3

1. [Create a private, dedicated S3 bucket](https://docs.aws.amazon.com/AmazonS3/latest/userguide/GetStartedWithS3.html) for backups.
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

## Setting Up Cron

Once installed and deployed and you've manually tested you should configure the crontab for automated backups.

1. Log in to the server where backups should run.
2. From the project root, generate the crontab entry using:

```shell script
vendor/bin/website-backup generate:crontab
```

3. Add the generated command to your crontab:

```shell script
crontab -e
```

Example:

```
0 1 * * * TMPDIR="/path/to/tmp" PATH="/path/to/php/bin:$PATH" /path/to/project/vendor/bin/website-backup --config /path/to/project/bin/config/website_backup.yml --env-file /path/to/project/.env backup:s3 -f --notify --quiet
```

{{ funding|raw }}
