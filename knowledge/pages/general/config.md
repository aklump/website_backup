<!--
id: config
tags: ''
-->

# Configuration

The configuration system manages settings for the backup process, combining a YAML configuration file with environment variables.

## Configuration File

The primary configuration file is located at:

```text
bin/config/website_backup.yml
```

This file is required and contains the core settings for the backup, such as the `manifest` (files to include/exclude), `database` connection details, and AWS settings.

You can override the default configuration file path using the `--config` global option. If the extension is omitted, the application will automatically try `.yml` and `.yaml`.

```bash
# These are equivalent if custom_config.yml exists
php bin/website_backup backup:s3 --config=/path/to/custom_config.yml
php bin/website_backup backup:s3 --config=/path/to/custom_config
```

Additionally, dot-separated names like `website_backup.local` are supported and will resolve to `website_backup.local.yml` if the file exists.

```bash
php bin/website_backup backup:s3 --config=website_backup.local
```

## Retention Policy

The application uses a flexible retention policy to manage backups on S3. Backups are pruned based on daily and monthly buckets.

In `bin/config/website_backup.yml`:

```yaml
aws_retention:
  keep_all_for_days: 2
  keep_latest_daily_for_days: 14
  keep_latest_monthly_for_months: 12
  keep_latest_yearly_for_years: 3
```

### Retention Rules

- **Keep All:** (`keep_all_for_days`) Keeps every backup for the most recent N days. This is useful for preserving multiple backups taken on the same day during active development or maintenance.
- **Daily Retention:** (`keep_latest_daily_for_days`) After the "Keep All" window, keeps only the newest backup for each day for N additional days.
- **Monthly Retention:** (`keep_latest_monthly_for_months`) After the daily window, keeps only the newest backup for each month for N additional months.
- **Yearly Retention:** (`keep_latest_yearly_for_years`) After the monthly window, keeps only the newest backup for each year for N additional years.
- **Phased Model:** Each rule applies only to backups not already retained by an earlier rule. Backups "age" through these phases.
- **Time Basis:** All calculations use UTC and calendar-based buckets (days, months, years).
- **Safety:** Only objects matching the app's backup naming pattern are pruned. Unrelated or unparseable objects are ignored.

## Environment Variables and `.env`

The application supports loading environment variables from a `.env` file located at the application root.

You can override the default environment file path using the `--env-file` global option:

```bash
php bin/website_backup backup:s3 --env-file=/path/to/custom.env
```

You can use the `install` command to automatically generate a `.env` file with the necessary keys based on your configuration:

```bash
php bin/website_backup install
```

## Token Replacement

The configuration file supports dynamic values using the `${TOKEN}` syntax. When the configuration is loaded, any occurrences of `${TOKEN}` in the YAML file are replaced with the corresponding environment variable value.

### Example

In `bin/config/website_backup.yml`:

```yaml
aws_access_key_id: ${WEBSITE_BACKUP_AWS_ACCESS_KEY_ID}
```

In `.env`:

```text
WEBSITE_BACKUP_AWS_ACCESS_KEY_ID=your_access_key
```

## Precedence Rules

The configuration is resolved using the following order of precedence (highest to lowest):

1.  **YAML File (with Token Resolution):** Values defined directly in `website_backup.yml` take absolute precedence. If a `${TOKEN}` is used, it is resolved from the environment, but the resulting value is still considered part of the YAML configuration.
2.  **`DATABASE_URL` Environment Variable:** Environment variables are only used when referenced explicitly in YAML. To use your application's existing `DATABASE_URL`, reference it explicitly in YAML as `database.url: ${DATABASE_URL}`.

### Key Precedence Note

Environment variables **cannot** be used by the application unless the YAML file explicitly references them using the `${TOKEN}` syntax. This ensures the YAML file remains the single source of truth for the application's configuration structure.

## Notifications

The application supports optional email notifications for backup runs.

In `bin/config/website_backup.yml`:

```yaml
notifications:
  email:
    to:
      - ops@example.com
      - dev@example.com
    on_success:
      subject: Backup succeeded
    on_fail:
      subject: Backup failed
```

To enable notifications, use the `--notify` option with the backup commands:

```bash
php bin/website_backup backup:s3 --notify
# or
php bin/website_backup backup:local --dir=/path/to/backups --notify
```

Notifications are sent for both successful runs and failures (if configured). Email delivery failure is reported as a warning but will not cause the backup command to fail.

## Local Backups and Compression

By default, local backups are saved as directories. You can optionally compress local backups using the `--gzip` flag.

```bash
# Save as a directory (default)
php bin/website_backup backup:local --dir=/path/to/backups

# Save as a .tar.gz archive
php bin/website_backup backup:local --dir=/path/to/backups --gzip
```

### Fallback Local Directory

You can configure a default local backup directory in `bin/config/website_backup.yml`. If `directories.local` is set, the `--dir` option becomes optional for `backup:local`.

```yaml
directories:
  local: /private/backups
```

The `--dir` CLI option will always override the configuration value.

S3 backups are always compressed and do not support directory output.

## Archive Encryption

The application supports symmetric archive encryption using OpenSSL (`aes-256-cbc` with PBKDF2).

### Configuration

In `bin/config/website_backup.yml`:

```yaml
encryption:
  password: ${WEBSITE_BACKUP_ENCRYPTION_PASSWORD}
  s3: true
```

- `password`: The shared password used for encryption and decryption.
- `s3`: If `true`, all backups uploaded to S3 will be automatically encrypted.

### Usage

**S3 Backups:**
Encryption is controlled by the `encryption.s3` configuration setting. If enabled, the uploaded file will have a `.tar.gz.enc` suffix.

**Local Backups:**
Encryption for local backups is opt-in via the CLI and requires `--gzip`.

```bash
# Save as an encrypted archive
php bin/website_backup backup:local --dir=/path/to/backups --gzip --encrypt
```

The resulting file will be named `[object_name]--[timestamp].tar.gz.enc`.

### Decryption and Extraction

To restore from an encrypted backup, you must first decrypt the file using OpenSSL and then extract the resulting archive.

**1. Decrypt the file:**

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -salt -in backup.tar.gz.enc -out backup.tar.gz
```

*You will be prompted for the encryption password.*

**2. Extract the archive:**

```bash
tar -xzf backup.tar.gz
```

## Unpacking Backups

The `backup:unpack` command allows you to decrypt and extract a backup artifact locally without restoring it to the live application.

```bash
php bin/website_backup backup:unpack /path/to/backup.tar.gz.enc
```

### Options

- `--force`, `-f`: Overwrite the destination directory if it already exists.
- `--delete-source`: Delete the original source artifact after successful unpacking.

The command will create a directory next to the source file with the unpacked contents.

## Database URL Support

The application uses a single connection URL for database connectivity.

**Configuration in `bin/config/website_backup.yml`:**

```yaml
database:
  url: ${DATABASE_URL}
```

**Format:** `mysql://user:password@host:port/dbname`

To use your application's existing `DATABASE_URL` environment variable, you must reference it explicitly in the YAML file as shown above. Environment variables are not automatically used unless they are referenced with the `${TOKEN}` syntax.

Special characters in the password (e.g., `@`, `:`, `/`) must be URL-encoded. For example, a password of `p@ssword` should be written as `p%40ssword`.

The URL must include the database name (as the path), user, and host. The port is optional and defaults to the standard MySQL port (3306) if omitted by the underlying tools. Password can be omitted if not required.
