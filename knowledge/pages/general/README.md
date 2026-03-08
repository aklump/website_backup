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
- optional S3 upload with retention-based pruning

The tool is designed for repeatable CLI use with configuration in YAML and secrets supplied by environment variables.

{{ composer.install|raw }}

## Quick Start

2. Create your config file.

   ```bash
   bin/website_backup install
   ```

3. Edit `bin/config/website_backup.yml` and fill in the variables.

4. Add any required secrets to `.env`.

5. Check your setup.

   ```bash
   bin/website_backup healthcheck
   ```

6. Run a local backup to test.

   ```bash
   bin/website_backup backup:local --dir ./backups/
   ```

7. Run an S3 backup to test

   ```bash
   bin/website_backup backup:s3
   ```
