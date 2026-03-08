<!--
id: changelog
tags: ''
-->

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-03-07

### Added

- Complete rewrite in PHP using Symfony Console, replacing the legacy Bash/Cloudy implementation.
- New `backup:s3` command for S3-hosted backups.
- New `backup:local` command for local destination backups with `--dir` option.
- New `backup:unpack` command for local decryption and extraction of backup archives.
- New `healthcheck` command for verifying configuration, system tools, and connectivity.
- New `install` command for scaffolding the initial configuration and `.env` file.
- Support for `.env` files and dynamic `${TOKEN}` replacement in YAML configuration.
- Optional symmetric archive encryption using OpenSSL (`aes-256-cbc`).
- Opt-in email notifications for backup status (success/failure) via `--notify`.
- Support for compressed local backups via the `--gzip` flag on `backup:local`.
- Secure temporary directory and credential handling to prevent password exposure.

### Changed

- Configuration format modernized to a single `bin/config/website_backup.yml` file.
- S3 retention system changed from a simple count to a policy-based "daily/monthly" bucket system (`aws_retention`).
- Improved database dump handling with support for `DATABASE_URL` and "cache tables" structure-only exports.
- Local destination backups are now directories by default (use `--gzip` for archives).

### Removed

- Legacy Cloudy/Bash runtime and configuration dependencies.
- The generic `backup` command (replaced by `backup:s3` and `backup:local`).
- Simple count-based pruning (`backups_to_store`).
- Legacy `email_on_failure` configuration (replaced by `notifications`).
