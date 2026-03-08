<?php

namespace App\Helper;

use App\Service\TemporaryFileFactory;

/**
 * Creates a temporary MySQL configuration file for use with --defaults-extra-file.
 */
class CreateMysqlTempConfig {

  private $tempFileFactory;

  public function __construct() {
    $this->tempFileFactory = new TemporaryFileFactory();
  }

  /**
   * @param array $db_config Keys: host, user, password, port
   * @param string|null $temp_dir The directory where to create the file.
   *
   * @return string The path to the created temporary file.
   */
  public function __invoke(array $db_config, ?string $temp_dir = NULL): string {
    $host = $db_config['host'] ?? '';
    $user = $db_config['user'] ?? '';
    $password = $db_config['password'] ?? '';
    $port = $db_config['port'] ?? '';

    $content = "[client]\n";
    if ($host) {
      $content .= "host=" . $host . "\n";
    }
    if ($user) {
      $content .= "user=" . $user . "\n";
    }
    if ($password) {
      $content .= "password=" . $password . "\n";
    }
    if ($port) {
      $content .= "port=" . $port . "\n";
    }

    $temp_dir = $temp_dir ?? sys_get_temp_dir();

    return $this->tempFileFactory->create($temp_dir, $content, 'mysql_cfg_');
  }

}
