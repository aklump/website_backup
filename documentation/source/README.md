# Website Backup

![website_backup](images/website-backup.jpg)

## Summary

A script to perform routine backups of critical website files and database.  Highlights include:

* Cloud backup to AWS S3.
* Automatically deletes older backups.
* YAML based configuration.
* Easily cherry-pick files or folders to include in backup.

**Visit <https://aklump.github.io/website_backup> for full documentation.**

## Quick Start

- Install in your repository root using `cloudy pm-install aklump/website_backup`
- Open _bin/config/website_backup.yml_ and modify as needed.
- Open _bin/config/website_backup.local.yml_ and ...; be sure to ignore this file in SCM as it contains your AWS credentials.
- Create a cron job that executes _bin/website_backup_ at the desired interval, e.g.

    0 1 * * * /var/www/mywebsite.org/app/bin/website_backup bu  2>&1 | mail -s "backup" me@developer.com

## Requirements

You must have [Cloudy](https://github.com/aklump/cloudy) installed on your system to install this package.

## Installation

The installation script above will generate the following structure where `.` is your repository root.

    .
    ├── bin
    │   ├── website_backup -> ../opt/website_backup/website_backup.sh
    │   └── config
    │       ├── website_backup.yml
    │       └── website_backup.local.yml
    ├── opt
    │   ├── cloudy
    │   └── aklump
    │       └── website_backup
    └── {public web root}

    
### To Update

- Update to the latest version from your repo root: `cloudy pm-update aklump/website_backup`

## Configuration Files

| Filename | Description | VCS |
|----------|----------|---|
| _website_backup.yml_ | Configuration shared across all server environments: prod, staging, dev  | yes |
| _website_backup.local.yml_ | Configuration overrides for a single environment; not version controlled. | no |

### Custom Configuration

* lorem
* ipsum

## Usage

* To see all commands use `./bin/website_backup`

## Contributing

If you find this project useful... please consider [making a donation](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=4E5KZHDQCEUV8&item_name=Gratitude%20for%20).
