#!/usr/bin/env bash
s="${BASH_SOURCE[0]}";[[ "$s" ]] || s="${(%):-%N}";while [ -h "$s" ];do d="$(cd -P "$(dirname "$s")" && pwd)";s="$(readlink "$s")";[[ $s != /* ]] && s="$d/$s";done;__DIR__=$(cd -P "$(dirname "$s")" && pwd)

# ========= Begin Configutation =========
INSTALL_PATH="../tests/"
VENDOR="../vendor/"
# ========= End Configuration =========

# ========= Validation =========
[[ -z "$INSTALL_PATH" ]] && echo "❌️ \$INSTALL_PATH cannot be empty" && exit 3
INSTALL_PATH="$(cd "$__DIR__/$INSTALL_PATH" && pwd)"
cd "$INSTALL_PATH" || exit 2
[[ -z "$VENDOR" ]] && echo "❌️ \$VENDOR cannot be empty" && exit 4
[[ ! -d  "$VENDOR" ]] && echo "❌️ \"$VENDOR\" does not exist; check the \$VENDOR variable in $0" && exit 5
[[ ! -f $VENDOR/bin/phpunit ]] && echo "❌️ missing dependencies; try \`composer install\`" && echo && exit 6

# ========= Internal config =========
# shellcheck disable=SC2034
coverage_reports="$INSTALL_PATH/reports"

export INSTALL_PATH

# ========= Execute PHPUnit =========
#$VENDOR/bin/phpunit -c phpunit.xml "$@"
#$VENDOR/bin/phpunit -c phpunit.xml --testdox "$@"
export XDEBUG_MODE=$XDEBUG_MODE,coverage;$VENDOR/bin/phpunit -c phpunit.xml --coverage-html="$coverage_reports" "$@"
echo "$coverage_reports/index.html"
