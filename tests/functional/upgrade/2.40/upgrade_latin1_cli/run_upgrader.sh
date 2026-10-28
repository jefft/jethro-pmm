#!/bin/bash -eu
# Run the CLI upgrader against the test database.
#
# The CLI upgrader resolves JETHRO_ROOT from its own path and expects to run
# from Jethro's root, where conf.php, include/ and upgrades/ live together.
# In a devbox checkout those live in devbox.d/web and the repo root
# respectively, so write a temporary conf.php at the repo root for the run.
# (Depending on a devbox.d/web/upgrades/ symlink would break in a fresh
# checkout that doesn't have one.)
set -o pipefail
set -e

DB=jethro_functest_upgrade_latin1_cli
ROOT="$DEVBOX_PROJECT_ROOT"
CONF="$ROOT/conf.php"
CONF_BAK="$ROOT/conf.php.functest_bak"

# Preserve a real root conf.php if this checkout has one.
if [ -f "$CONF" ]; then
	cp "$CONF" "$CONF_BAK"
fi

# Restore on any exit, so a failed upgrade doesn't leave the temporary conf
# (or a stale backup) behind.
restore_conf() {
	if [ -f "$CONF_BAK" ]; then
		mv "$CONF_BAK" "$CONF"
	else
		rm -f "$CONF"
	fi
}
trap restore_conf EXIT

# Unix-socket auth, matching devbox.d/web/conf.php; MYSQL_UNIX_PORT is exported
# by the devbox mariadb plugin and inherited by the php CLI.
cat > "$CONF" <<-PHP
<?php
define('DB_DSN', 'mysql:unix_socket=' . getenv('MYSQL_UNIX_PORT') . ';dbname=$DB');
define('DB_DATABASE', '$DB');
define('DB_USERNAME', 'jethro');
define('DB_PASSWORD', 'jethro');
PHP

# JETHRO_ROOT resolves to the repo root, which has include/ and upgrades/.
cd "$ROOT"
php upgrades/2026-upgrade-to-2.40-utf8mb4.php
