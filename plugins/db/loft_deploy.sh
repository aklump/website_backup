#!/usr/bin/env bash

#
# @file
# Handles the database export using Loft Deploy.
#
# database:
#   handler: loft_deploy
#
eval $(get_config_as loft_deploy "bin.loft_deploy" "loft_deploy")
"$loft_deploy" export "$database_dumpfile" --dir="$path_to_stage" >/dev/null
status=$?
return $status
