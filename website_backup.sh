#!/usr/bin/env bash

#
# @file
# Lorem ipsum dolar sit amet consectador.
#

# Define the configuration file relative to this script.
CONFIG="website_backup.core.yml";

# Uncomment this line to enable file logging.
#LOGFILE="website_backup.core.log"

# TODO: Event handlers and other functions go here or register one or more includes in "additional_bootstrap".
function on_pre_config() {
  [[ "$(get_command)" == "init" ]] && exit_with_init
}

# Begin Cloudy Bootstrap
s="${BASH_SOURCE[0]}";while [ -h "$s" ];do dir="$(cd -P "$(dirname "$s")" && pwd)";s="$(readlink "$s")";[[ $s != /* ]] && s="$dir/$s";done;r="$(cd -P "$(dirname "$s")" && pwd)";source "$r/../../cloudy/cloudy/cloudy.sh";[[ "$ROOT" != "$r" ]] && echo "$(tput setaf 7)$(tput setab 1)Bootstrap failure, cannot load cloudy.sh$(tput sgr0)" && exit 1
# End Cloudy Bootstrap

# Input validation.
validate_input || exit_with_failure "Input validation failed."

implement_cloudy_basic

# Handle other commands.
command=$(get_command)
case $command in

    "backup")

      # Validate our configuration.
      eval $(get_config aws_region)
      eval $(get_config aws_bucket)
      eval $(get_config -a manifest)
      eval $(get_config_as AWS_ACCESS_KEY_ID aws_access_key_id)
      eval $(get_config_as AWS_SECRET_ACCESS_KEY aws_secret_access_key)

      exit_with_failure_if_empty_config aws_region
      exit_with_failure_if_empty_config aws_bucket
      exit_with_failure_if_empty_config manifest
      exit_with_failure_if_empty_config AWS_ACCESS_KEY_ID
      exit_with_failure_if_empty_config AWS_SECRET_ACCESS_KEY

      # Import our configuration/defaults
      eval $(get_config object_name "${aws_bucket}--")
      object_name=${object_name}--$(date8601 -c)
      stage_dir="$object_name"

      echo_title "Backing Up Your Website"

      # Create a local directory into which we put the manifest.
      mkdir "$stage_dir" || fail_because "Could not create the local object directory: $PWD/$stage_dir"

      # Allow the database handler add what it wants to the $stage_dir.
      eval $(get_config_as "database_handler" "database.handler")
      eval $(get_config_as "database_dumpfile" "database.dumpfile" "database-backup")
      if [[ "$database_handler" ]]; then
        list_clear
        echo_heading "Exporting database (via $database_handler)"
        source "$ROOT/plugins/db/$database_handler.sh" "$stage_dir"
        [ $? -ne 0 ] && exit_with_failure
        echo_green_list
      fi

      # Copy the included files.
      echo_heading "Cherry-picking files"
      list_clear
      for path in "${manifest[@]}"; do

        # Expand any globs in the path.
        path="$(echo $path)"

        # Make sure the source files exists.
        if [[ "${path:0:1}" != '!' ]]; then
          [ -e "$path" ] || fail_because "Manifest includes \"$path\", which does not exist."
        fi

        if [[ "${path:0:1}" != '!' ]]; then
          # Ensure the destination file structure exists in the object.
          destination_dir="$path"
          if [ -f "$path" ]; then
            destination_dir="$(dirname $path)/"
          fi
          [[ "$destination_dir" ]] && [[ ! -d "$stage_dir/$destination_dir" ]] && mkdir -p "$stage_dir/$destination_dir"

          # Copy files
          list_add_item $path
          if [ -f "$path" ]; then
            cp "$path" "$stage_dir/$path" || fail_because "Could not stage \"$path\"."
          elif [ -d "$path" ]; then
            rsync -a "$path/" "$stage_dir/$path/" || fail_because "Could not stage \"$path\"."
          fi
        fi

        has_failed && exit_with_failure
      done

      # Remove the excluded files.
      for path in "${manifest[@]}"; do

        # Expand any globs in the path.
        path="$(echo $path)"

        # Remove the excluded files if they exist.
        if [[ "${path:0:1}" == '!' ]]; then
          remove="$stage_dir/${path:1}"
          if [ -e "$remove" ]; then
            rm -r "$remove" || fail_because "Could not exclude \"$remove\" from stage."
          fi
        fi

        has_failed && exit_with_failure
      done
      echo_green_list

      # Compress the file.
      object="$stage_dir.tar.gz"
      tar -czf "$object" "$stage_dir" || fail_because "Could not compress object."

      # Send to the cloud.
      eval $(get_config backups_to_store 10)
      echo_heading "Sending to bucket \"${aws_bucket}\" at S3"
      export AWS_ACCESS_KEY_ID
      export AWS_SECRET_ACCESS_KEY
      result=$(php "$ROOT/amazon_s3.php" "$aws_region" "$aws_bucket" "$object" "$PWD/$object" "$backups_to_store")
      [ $? -ne 0 ] && fail_because "$result"

      # Cleanup the local object stage and file.
      rm -r $stage_dir || fail_because "Could not remove local object directory: $PWD/$stage_dir"
      rm $object || fail_because "Could not remove local object directory: $PWD/$object"

      has_failed && exit_with_failure
      exit_with_success_elapsed "Backup completed"
    ;;

esac

throw "Unhandled command \"$command\"."
