<?php

namespace App\Config;

use App\Helper\GetShortPath;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Dotenv\Dotenv;

class ConfigLoader {

  private $appRoot;

  public function __construct(string $app_root) {
    $this->appRoot = $app_root;
  }

  private function getConfigPath(): string {
    return $this->appRoot . '/bin/config/website_backup.yml';
  }

  public function load(): array {
    $config = ['path_to_app' => $this->appRoot];

    // 1. Load from .env if it exists
    $dotenv_path = $this->appRoot . '/.env';
    if (file_exists($dotenv_path)) {
      (new Dotenv())->load($dotenv_path);
    }

    // 2. Load from YAML config in bin/config/website_backup.yml
    $config_path = $this->getConfigPath();
    if (file_exists($config_path)) {
      $content = file_get_contents($config_path);
      // Replace ${TOKEN} with env var values
      $content = preg_replace_callback('/\${([^}]+)}/', function ($matches) {
        $env_var = $matches[1];

        return $_ENV[$env_var] ?? $_SERVER[$env_var] ?? getenv($env_var) ?: '';
      }, $content);

      $yaml = Yaml::parse($content);
      if (is_array($yaml)) {
        $config = array_replace_recursive($config, $yaml);
      }
    }

    // 3. Apply special overrides (like DATABASE_URL)
    $config = $this->applySpecialOverrides($config);

    return $config;
  }

  private function applySpecialOverrides(array $config): array {
    $db_url = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
    if ($db_url) {
      $parsed = parse_url($db_url);
      if ($parsed) {
        if (empty($config['database']['name'])) {
          $config['database']['name'] = ltrim($parsed['path'] ?? '', '/');
        }
        if (empty($config['database']['user'])) {
          $config['database']['user'] = $parsed['user'] ?? '';
        }
        if (empty($config['database']['password'])) {
          $config['database']['password'] = $parsed['pass'] ?? '';
        }
        if (empty($config['database']['host'])) {
          $config['database']['host'] = $parsed['host'] ?? '';
        }
        if (empty($config['database']['port'])) {
          $config['database']['port'] = (string) ($parsed['port'] ?? '');
        }
      }
    }

    return $config;
  }

  public function validate(array $config, bool $is_local = FALSE, bool $notify = FALSE, bool $encrypt = FALSE): void {
    $config_path = $this->getConfigPath();
    $get_short_path = new GetShortPath();
    $required = ['path_to_app', 'manifest', 'database'];
    if (!$is_local) {
      $required = array_merge($required, [
        'aws_region',
        'aws_bucket',
        'aws_access_key_id',
        'aws_secret_access_key',
        'aws_retention',
      ]);
    }

    foreach ($required as $key) {
      if (empty($config[$key])) {
        throw new \RuntimeException(sprintf('Missing required configuration in %s: %s', $get_short_path($config_path), $key));
      }
    }

    if ($notify) {
      if (empty($config['notifications']['email'])) {
        throw new \RuntimeException(sprintf('Missing required configuration in %s: notifications.email', $get_short_path($config_path)));
      }
      $email = $config['notifications']['email'];
      if (!is_array($email)) {
        throw new \RuntimeException(sprintf('Configuration "notifications.email" must be an array in %s', $get_short_path($config_path)));
      }
      if (empty($email['to']) || !is_array($email['to'])) {
        throw new \RuntimeException(sprintf('Missing required configuration in %s: notifications.email.to', $get_short_path($config_path)));
      }
      foreach ($email['to'] as $to) {
        if (empty($to) || !is_string($to)) {
          throw new \RuntimeException(sprintf('Invalid recipient address in %s: notifications.email.to', $get_short_path($config_path)));
        }
      }
      if (empty($email['on_success']['subject']) || !is_string($email['on_success']['subject'])) {
        throw new \RuntimeException(sprintf('Missing required configuration in %s: notifications.email.on_success.subject', $get_short_path($config_path)));
      }
      if (empty($email['on_fail']['subject']) || !is_string($email['on_fail']['subject'])) {
        throw new \RuntimeException(sprintf('Missing required configuration in %s: notifications.email.on_fail.subject', $get_short_path($config_path)));
      }
    }

    if (!$is_local) {
      $retention = $config['aws_retention'];
      if (!is_array($retention)) {
        throw new \RuntimeException(sprintf('Configuration "aws_retention" must be an array in %s', $get_short_path($config_path)));
      }
      foreach (['keep_daily_for_days', 'keep_monthly_for_months'] as $key) {
        if (!isset($retention[$key])) {
          throw new \RuntimeException(sprintf('Missing required configuration in %s: aws_retention.%s', $get_short_path($config_path), $key));
        }
        if (!is_int($retention[$key]) || $retention[$key] < 0) {
          throw new \RuntimeException(sprintf('Configuration "aws_retention.%s" must be a non-negative integer in %s', $key, $get_short_path($config_path)));
        }
      }
    }

    // Encryption validation
    if ($encrypt) {
      if (empty($config['encryption']) || !is_array($config['encryption'])) {
        throw new \RuntimeException(sprintf('Missing required configuration in %s: encryption', $get_short_path($config_path)));
      }
      if (empty($config['encryption']['password']) || !is_string($config['encryption']['password'])) {
        throw new \RuntimeException(sprintf('Missing required configuration in %s: encryption.password', $get_short_path($config_path)));
      }
    }

    if (!is_dir($config['path_to_app'])) {
      throw new \RuntimeException(sprintf('path_to_app is not a directory: %s', $config['path_to_app']));
    }
  }
}
