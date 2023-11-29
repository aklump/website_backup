---
id: trouble
---

# Troubleshooting

## tmpdir Backups Fail Due to Memory Issues

You may need to [define a temporary directory](https://www.cyberciti.biz/tips/shell-scripting-bash-how-to-create-empty-temporary-file-quickly.html) other than the default if you run out of room during backup. Add something like the following to _.bash_profile_. You should only do this if you are not able to make a backup due to an error resembling, "No space left on device". This seems to happen when a server's default tmp directory has insufficient space.

    export TMPDIR="/home/foo/tmp"

You will also have to [provide the override](@readme:quick) in your crontab.

## Enable Logging to Find Errors

1. Open _bin/website_backup_ and uncomment the logging line, e.g.,

   LOGFILE="website_backup.core.log"

1. Set the path appropriate to your system.
1. Try a backup and then review the log file.

## Wrong Version of PHP Being Used by Cron

"Your Composer dependencies require a PHP version ">= 8.1.0". You are running 7.4.25."

See README for how to set the PHP Versions in the crontab expression.

### Deprecated Solution with `CLOUDY_PHP`

This will be removed in future versions; don't do this.

```cronexp
0 1 * * * export CLOUDY_PHP=/usr/local/php81/bin/php;/var/www/mywebsite.org/app/bin/website_backup bu  2>&1 | mail -s "FOO backup" me@developer.com
```
