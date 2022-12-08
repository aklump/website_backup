# Cache Tables by Framework

Use these as a starting point for configuration. You may want to check if all the tables listed are actually in your database before listing them in your config, e.g. `accesslog` is Drupal 7 only, I believe.

## Drupal

```yaml
database:
  cache_tables:
    - accesslog
    - batch
    - cache
    - cache_*
    - captcha_sessions
    - field_deleted_data_*
    - flood
    - honeypot_user
    - node_access
    - old_*
    - queue
    - search_index
    - sessions
    - watchdog
```

* [https://www.drupal.org/docs/7/modules/backup-and-migrate/recommendations-for-making-backups-more-reliable](Recommendations for making backups more reliable)
* https://drupal.stackexchange.com/a/171884/26195

## Wordpress

@todo
