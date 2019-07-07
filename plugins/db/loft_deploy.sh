#!/usr/bin/env bash

#
# @file
# Handles the database export using Loft Deploy.
#
eval $(get_config_as loft_deploy "bin.loft_deploy" "loft_deploy")
"$loft_deploy" export "$database_dumpfile" --dir="$stage_dir" >/dev/null
status=$?
return $status
