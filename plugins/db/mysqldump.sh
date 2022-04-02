#!/usr/bin/env bash

#
# @file
# Handles the database export using mysqldump
#
#   database:
#      handler: mysqldump
#      host: localhost
#      port: PORT
#      name: NAME
#      user: USER
#      password: PASSWORD
#

eval $(get_config_as mysqldump "bin.mysqldump" "mysqldump")
eval $(get_config_as mysql "bin.mysql" "mysql")

eval $(get_config_as "host" "database.host" "localhost")
eval $(get_config_as "user" "database.user")
eval $(get_config_as "password" "database.password")
eval $(get_config_as "name" "database.name")
eval $(get_config_as "port" "database.port")
eval $(get_config_as -a "cache_tables" "database.cache_tables")

# Convert to CSV and make wildcards per MySQL.
exit_with_failure_if_empty_config "database.host" --as=host
exit_with_failure_if_empty_config "database.user" --as=user
exit_with_failure_if_empty_config "database.password" --as=password
exit_with_failure_if_empty_config "database.name" --as=name

path_to_output="$path_to_stage/$database_dumpfile.$name.sql"

eval $(get_config_as -a "plugin_options" "plugins.mysqldump.options")
shared_options=''
for option in "${plugin_options[@]}"; do
   shared_options="$shared_options --${option#--}"
done

# Create the .cnf file with connection information.
local_db_cnf="$(tempdir website_backup)/connection.cnf"
connection_options="--defaults-file=$local_db_cnf"
[ -e "$local_db_cnf" ] && rm "$local_db_cnf"
echo "[client]" >"$local_db_cnf"
echo "host=\"$host\"" >>"$local_db_cnf"
[[ "$port" ]] && echo "port=\"$port\"" >>"$local_db_cnf"
echo "user=\"$user\"" >>"$local_db_cnf"
echo "password=\"$password\"" >>"$local_db_cnf"

# Make a note of total tables.
total_tables=($($mysql $connection_options "$name" -s -N -e "SELECT COUNT(table_name) FROM information_schema.tables WHERE table_schema = '$name'"))
list_add_item "structure for $total_tables table(s)."

# If we have cache tables then handle them.
if [ ${#cache_tables[@]} -gt 0 ]; then
  table_where_clause=$(printf " AND table_name NOT LIKE '%s'" "${cache_tables[@]}")
  table_where_clause="${table_where_clause//\*/%}"
  tables=($($mysql $connection_options "$name" -s -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$name'$table_where_clause"))
  list_add_item "data for ${#tables[@]} table(s)."

  # Create a file, and export the CREATE statements for all tables.
  $mysqldump $connection_options $shared_options --no-data "$name" --result-file="$path_to_output" || fail_because "mysqldump structure export failed."

  # Now append the data from all but our cache_tables to the same dumpfile.
  $mysqldump $connection_options $shared_options --no-create-info "$name" ${tables[@]} >>"$path_to_output" || fail_because "mysqldump data export failed."

# Otherwise one dump is sufficient.
else
  $mysqldump $connection_options $shared_options "$name" --result-file="$path_to_output" || fail_because "mysqldump failed."
fi

rm "$local_db_cnf" || write_log_error "Could not remove $local_db_cnf credentials file"
