<?php

namespace AKlump\WebsiteBackup\Config;

use AKlump\WebsiteBackup\Helper\GetShortPath;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Dotenv\Dotenv;

class ConfigLoader {

  public const ENV_TOKEN_PATTERN = '/\${([^}]+)}/';

  private $appRoot;

  private ?string $configPath = NULL;

  private ?string $envPath = NULL;

  public function __construct(string $app_root, string $config_path = NULL, string $env_path = NULL) {
    $this->appRoot = $app_root;
    $this->configPath = $config_path;
    $this->envPath = $env_path;
  }

  private function getConfigPath(): string {
    $path = $this->configPath ?? ($this->appRoot . '/bin/config/website_backup.yml');
    if (file_exists($path)) {
      return $path;
    }

    foreach (['.yml', '.yaml'] as $ext) {
      if (file_exists($path . $ext)) {
        return $path . $ext;
      }
    }

    return $path;
  }

  private function getEnvPath(): string {
    return $this->envPath ?? ($this->appRoot . '/.env');
  }

  public function load(): array {
    $config = [];

    // 1. Load from .env if it exists
    $env_path = $this->getEnvPath();
    if (file_exists($env_path)) {
      if (!is_readable($env_path)) {
        throw new \RuntimeException(sprintf('Environment file is not readable: %s', $env_path));
      }
      (new Dotenv())->load($env_path);
    }
    elseif ($this->envPath !== NULL) {
      throw new \RuntimeException(sprintf('Environment file not found: %s', $this->envPath));
    }

    // 2. Load from YAML config
    $config_path = $this->getConfigPath();
    $config_dir = dirname($config_path);
    $config = ['__config_path' => $config_path];

    if (file_exists($config_path)) {
      if (!is_readable($config_path)) {
        throw new \RuntimeException(sprintf('Configuration file is not readable: %s', $config_path));
      }
      $content = file_get_contents($config_path);
      // Replace ${TOKEN} with env var values
      $content = preg_replace_callback(static::ENV_TOKEN_PATTERN, function ($matches) {
        $env_var = $matches[1];
        if ($env_var === 'PROJECT_ROOT') {
          return $this->appRoot;
        }

        return $_ENV[$env_var] ?? $_SERVER[$env_var] ?? getenv($env_var) ?: '';
      }, $content);

      $yaml = Yaml::parse($content);
      if (is_array($yaml)) {
        $config = array_replace_recursive($config, $yaml);
      }
    }
    elseif ($this->configPath !== NULL) {
      throw new \RuntimeException(sprintf('Configuration file not found: %s', $this->configPath));
    }

    // 3. Normalize paths relative to the configuration file directory
    $config = $this->normalizePaths($config, $config_dir);

    // 4. Apply special overrides (like DATABASE_URL)
    $config = $this->applySpecialOverrides($config);

    return $config;
  }

  private function normalizePaths(array $config, string $config_dir): array {
    // Normalize directories.local
    if (!empty($config['directories']['local'])) {
      $config['directories']['local'] = $this->resolvePath($config['directories']['local'], $config_dir);
    }

    // Manifest paths are normalized by ManifestService or here?
    // The requirement says: "after YAML parsing, normalize path-based config values relative to the config directory"
    // "ensure this normalization is centralized so commands do not each implement their own path logic"
    if (!empty($config['manifest']) && is_array($config['manifest'])) {
      foreach ($config['manifest'] as &$item) {
        $is_exclude = str_starts_with($item, '!');
        $path = $is_exclude ? substr($item, 1) : $item;
        $resolved = $this->resolvePath($path, $config_dir);
        $item = ($is_exclude ? '!' : '') . $resolved;
      }
    }

    return $config;
  }

  private function resolvePath(string $path, string $base_dir): string {
    if (str_starts_with($path, '/')) {
      return $path;
    }

    return realpath($base_dir . '/' . $path) ?: ($base_dir . '/' . $path);
  }

  private function applySpecialOverrides(array $config): array {
    $db_url = $config['database']['url'] ?? NULL;
    if (!$db_url) {
      return $config;
    }

    $parsed = parse_url($db_url);
    if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
      throw new \RuntimeException(sprintf('Invalid database URL: %s', $db_url));
    }

    $config['database']['name'] = ltrim($parsed['path'] ?? '', '/');
    $config['database']['user'] = $parsed['user'] ?? '';
    $config['database']['password'] = $parsed['pass'] ?? '';
    $config['database']['host'] = $parsed['host'] ?? '';
    $config['database']['port'] = (string) ($parsed['port'] ?? '');

    if (empty($config['database']['name'])) {
      throw new \RuntimeException(sprintf('Database name is missing in URL: %s', $db_url));
    }
    if (empty($config['database']['user'])) {
      throw new \RuntimeException(sprintf('Database user is missing in URL: %s', $db_url));
    }

    return $config;
  }

  public function validate(array $config, bool $is_local = FALSE, bool $notify = FALSE, bool $encrypt = FALSE): void {
    $config_path = $this->getConfigPath();
    $get_short_path = new GetShortPath();
    $required = ['manifest'];
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

    if (empty($config['database']['url'])) {
      throw new \RuntimeException(sprintf('Missing required configuration in %s: database.url', $get_short_path($config_path)));
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
      $retention_keys = [
        'keep_all_for_days',
        'keep_latest_daily_for_days',
        'keep_latest_monthly_for_months',
        'keep_latest_yearly_for_years',
      ];
      foreach ($retention_keys as $key) {
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
  }
}
