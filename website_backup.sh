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
      eval $(get_config_path path_to_app)

      exit_with_failure_if_empty_config aws_region
      exit_with_failure_if_empty_config aws_bucket
      exit_with_failure_if_empty_config manifest
      exit_with_failure_if_empty_config AWS_ACCESS_KEY_ID
      exit_with_failure_if_empty_config AWS_SECRET_ACCESS_KEY
      exit_with_failure_if_config_is_not_path path_to_app

      # Import our configuration/defaults
      eval $(get_config object_name "${aws_bucket}--")
      object_name=${object_name}--$(date8601 -c)
      exit_with_failure_if_empty_config object_name

      # Create a local directory into which we put the manifest at the root of
      # the app.
      echo_title "Backing Up Your Website"

      path_to_stage="$(tempdir website_backup)/$object_name"
      mkdir -p "$path_to_stage" || fail_because "Could not create the local object directory: $path_to_stage"
      write_log_debug "Staging in: $path_to_stage"
      has_failed && exit_with_failure

      # Allow the database handler add what it wants to the $path_to_stage.
      eval $(get_config_as "database_handler" "database.handler")
      eval $(get_config_as "database_dumpfile" "database.dumpfile" "database-backup")
      if [[ "$database_handler" ]] && (has_option 'database' || ! has_option 'files'); then
        list_clear
        echo_heading "Exporting database (via $database_handler)"
        source "$ROOT/plugins/db/$database_handler.sh" "$path_to_stage"
        [ $? -ne 0 ] && exit_with_failure
        list_add_item "$(echo_elapsed) seconds"
        echo_blue_list
      fi

      # Copy the included files.
      if has_option 'files' || ! has_option 'database'; then
        echo_heading "Cherry-picking files"
        list_clear
        for path in "${manifest[@]}"; do

          # Do not allow absolute paths in the manifest.
          if [[ "${path:0:1}" == '/' ]] || [[ "${path:0:2}" == '!/' ]]; then
            write_log_debug "The manifest item \"$path\" should not begin with a slash, as this indicates an absolute path.  Make sure that you only include relative paths, relative to \"$path_to_app\""
            fail_because "Incorrect manifest item \"$path\". Only paths relative to config var \"path_to_app\" are allowed in the manifest."
          fi

          # For includes, make sure the source files exists.
          if ! has_failed && [[ "${path:0:1}" != '!' ]]; then

            # Expand any globs in the path and calculate absolute path.
            path_to_source="$(path_resolve "$path_to_app" $path)"
            [ -e "$path_to_source" ] || fail_because "Manifest includes \"$path_to_source\", which does not exist."

            # Ensure the destination file structure exists in the object.
            destination_dir="$path"
            if [ -f "$path_to_source" ]; then
              destination_dir="$(dirname $path)/"
            fi
            [[ "$destination_dir" ]] && [[ ! -d "$path_to_stage/$destination_dir" ]] && mkdir -p "$path_to_stage/$destination_dir"

            # Copy files
            list_add_item $path
            if [ -f "$path_to_source" ]; then
              cp "$path_to_source" "$path_to_stage/$path" || fail_because "Could not stage \"$path\"."
            elif [ -d "$path_to_source" ]; then
              rsync -a "$path_to_source/" "$path_to_stage/$path/" || fail_because "Could not stage \"$path\"."
            fi
          fi

          has_failed && exit_with_failure
        done

        # Remove the excluded files.
        for path in "${manifest[@]}"; do
          # Remove the excluded files if they exist.
          if [[ "${path:0:1}" == '!' ]]; then
            remove="$path_to_stage/${path:1}"

            # Expand any globs in the path.
            if [ -e "$remove" ]; then
              rm -r "$remove" || fail_because "Could not exclude \"$remove\" from stage."
            fi
          fi

          has_failed && exit_with_failure
        done
        list_add_item "$(echo_elapsed) seconds"
        echo_blue_list
      fi

      # Save locally only due to --local
      if has_option 'local'; then
        object_basename="$object_name"
        path_to_object="$(dirname $path_to_stage)/$object_basename"
        path_to_local_save=$(get_option 'local')
        [ -d "$path_to_local_save" ] || fail_because "The directory specified by the \"local\" option must already exist; it does not."
        if ! has_failed; then
          list_clear
          echo_heading "Saving locally"
          list_add_item "$path_to_local_save"
          mv "$path_to_object" "$path_to_local_save" || fail_because "Could not move to local: $path_to_local_save"
          echo_blue_list
        fi

      # Push to cloud.
      else
        # Compress the file.
        echo_heading "Compressing object"
        object_basename="$object_name.tar.gz"
        path_to_object="$(dirname $path_to_stage)/$object_basename"
        list_clear

        (cd "$(dirname "$path_to_object")" && tar -czf "$object_basename" "$object_name" ) || fail_because "Could not compress object."
        list_add_item "$(echo_elapsed) seconds"
        echo_blue_list

        # Send to the cloud.
        eval $(get_config backups_to_store 10)
        echo_heading "Sending to bucket \"${aws_bucket}\" on S3"
        export AWS_ACCESS_KEY_ID
        export AWS_SECRET_ACCESS_KEY
        result=$(php "$ROOT/amazon_s3.php" "$aws_region" "$aws_bucket" "$object_basename" "$path_to_object" "$backups_to_store")
        [ $? -ne 0 ] && fail_because "$result"

        # Cleanup the local object file.
        rm "$path_to_object" || fail_because "Could not remove local object directory: $object"
        has_failed && exit_with_failure
        rm -r "$path_to_stage" || fail_because "Could not remove local object directory: $path_to_stage"
      fi

      has_failed && exit_with_failure
      exit_with_success_elapsed "Backup completed"
    ;;

esac

throw "Unhandled command \"$command\"."
