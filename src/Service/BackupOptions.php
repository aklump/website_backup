<?php

namespace AKlump\WebsiteBackup\Service;

/**
 * Bitwise flags for backup options.
 */
final class BackupOptions {

  public const DATABASE = 1;

  public const FILES = 2;

  public const LATEST = 4;

  public const NOTIFY = 8;

  public const GZIP = 16;

  public const ENCRYPT = 32;
}
