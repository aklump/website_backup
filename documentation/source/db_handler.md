# Database Handlers

* Given an id such as `my_handler`, you must create _plugins/db/my_handler.sh_.
* It must provide a backup of the database using whatever config it wants and place it in `$stage_dir`.
* It must use `$database_dumpfile` in the output filename.
