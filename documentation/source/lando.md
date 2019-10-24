# Lando Integration

1. Use the `mysqldump` plugin.
1. Create `tooling` in _lando.yml_
1. Use `lando website-backup` to execute.

## In Detail

To integration with Lando you need to create some tooling scripts in _lando.yml_.  Here is an example.

        tooling:
          website-backup:
            service: appserver
            description: Send a backup of the db and user files to S3.
            cmd: "cd /app && ./bin/website_backup backup"

In this second example we are going to store backups locally in the _purgeable_ folder and ignore the files.  This should be connected to Loft Dev for safety backups.

        tooling:
          website-backup-local-db:
            service: appserver
            description: Save a local db-only snapshot.
            cmd: "cd /app && ./bin/website_backup backup -d --local=/app/private/default/db/purgeable"

Then to run the backups you need only do:

        lando website-backup
        lando website-backup-local-db
