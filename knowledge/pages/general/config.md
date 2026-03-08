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

## Retention Policy

The application uses a flexible retention policy to manage backups on S3. Backups are pruned based on daily and monthly buckets.

In `bin/config/website_backup.yml`:

```yaml
aws_retention:
  keep_daily_for_days: 14
  keep_monthly_for_months: 12
```

### Retention Rules

- **Daily Retention:** Keeps the newest backup for each of the last N calendar days (UTC).
- **Monthly Retention:** For backups older than the daily window, keeps the newest backup for each of the last N calendar months (UTC).
- **Overlap:** Daily retention takes precedence. If a backup is kept by the daily rule, it isn't considered for the monthly rule.
- **Safety:** Only objects matching the app's backup naming pattern are pruned. Unrelated or unparseable objects are ignored.

## Environment Variables and `.env`

The application supports loading environment variables from a `.env` file located at the application root.

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
2.  **`DATABASE_URL` Environment Variable:** If certain database configuration keys (name, user, password, host, port) are missing from the YAML file, they will be automatically filled using the `DATABASE_URL` environment variable if it is present.

### Key Precedence Note

Environment variables **cannot** override values explicitly defined in the YAML file unless the YAML file uses the `${TOKEN}` syntax for that specific setting. This ensures the YAML file remains the single source of truth for the application's configuration structure.

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

To enable notifications, use the `--notify` option with the `backup` command:

```bash
php bin/website_backup backup --notify
```

Notifications are sent for both successful runs and failures (if configured). Email delivery failure is reported as a warning but will not cause the backup command to fail.

## Local Backups and Compression

By default, local backups (using the `--local` option) are saved as directories. You can optionally compress local backups using the `--gzip` flag.

```bash
# Save as a directory (default)
php bin/website_backup backup --local=/path/to/backups

# Save as a .tar.gz archive
php bin/website_backup backup --local=/path/to/backups --gzip
```

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
Encryption for local backups is opt-in via the CLI and requires both `--local` and `--gzip`.

```bash
# Save as an encrypted archive
php bin/website_backup backup --local=/path/to/backups --gzip --encrypt
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

The application specifically supports the `DATABASE_URL` environment variable for database connectivity.

Format: `mysql://user:password@host:port/dbname`

If `DATABASE_URL` is provided, the application will extract the connection details and use them for any database settings that are **not** explicitly defined in the `website_backup.yml` file.
