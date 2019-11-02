# Database Handlers

* Given an id such as `foo`, you must create _plugins/db/foo.sh_.
* It should define the configuration as needed, consider prefixing with plugin name if appropriate.
* The configuration must be shown in the header comment, e.g. 

        #
        # @file
        # Handles the database export using foo
        #
        #   database:
        #    handler: foo
        #    foo: bar
        #

* It must provide a backup of the database using whatever config it wants and place it in `$path_to_stage`.
* The script runs with `$APP_ROOT` as the cwd.
* It must use `$database_dumpfile` as the output filename.
* For configuration errors use `exit_with_failure*`
* For execution errors use `fail_because` and exit non-zero.  You may also use `write_log_error` as appropriate.
* See existing plugins as a starting point.
