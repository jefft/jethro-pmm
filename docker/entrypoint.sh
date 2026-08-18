#!/usr/bin/env bash
# Docker entrypoint for Jethro PMM — the single source of truth for entrypoint
# logic. docker/default.nix wraps this script in a thin `jethro-entrypoint` that first
# sets the Nix-store PATH (via lib.makeBinPath) and MYSQL_BASEDIR, then execs
# this file.
#
# Expects on PATH: mariadbd, mariadb-install-db, php-fpm, nginx,
# process-compose, envsubst, awk, coreutils. /app holds the project source.
set -euo pipefail

# ── Runtime-writable state (not bind-mounted) ─────────────────────────────────
MYSQL_HOME="${MYSQL_HOME:-/run/jethro/mariadb}"
NGINX_RUN="${NGINX_RUN:-/run/jethro/nginx}"

export MYSQL_HOME
export MYSQL_UNIX_PORT="$MYSQL_HOME/mysql.sock"
export MYSQL_PID_FILE="$MYSQL_HOME/mysql.pid"
export PHPFPM_PID_FILE=/run/jethro/php-fpm.pid
export PHPFPM_ERROR_LOG_FILE=/run/jethro/php-fpm.log
export PHPFPM_UNIX_SOCKET=/run/jethro/php-fpm.sock
export NGINX_PATH_PREFIX="$NGINX_RUN"
export NGINX_LOG_DIR="${NGINX_LOG_DIR:-$NGINX_RUN/logs}"

# ── Persistent data — two independent mounts ─────────────────────────────────
#   JETHRO_HOME    static file data only; $JETHRO_HOME/docs is the Documents
#                  root (DOCUMENTS_ROOT_PATH in docker/conf.php)
#   MYSQL_DATADIR  MariaDB datadir; never inside JETHRO_HOME
# Fly.io allows one volume per Machine, so docker/fly.toml points both at
# sibling directories of that volume.
export JETHRO_HOME="${JETHRO_HOME:-/var/lib/jethro}"
export MYSQL_DATADIR="${MYSQL_DATADIR:-/var/lib/mariadb}"
#   PHP sessions   also live on persistent storage: the root filesystem is
#                  reset whenever a Fly Machine stops (idle autostop, restart,
#                  redeploy), which would log every user out.
#                  devbox.d/php/php.ini builds session.save_path from
#                  ${DEVBOX_PROJECT_ROOT}, so pointing it at a persistent dir
#                  relocates sessions with no image-specific php.ini. Sessions
#                  end up in $DEVBOX_PROJECT_ROOT/.devbox/virtenv/php/sessions.
export DEVBOX_PROJECT_ROOT="${DEVBOX_PROJECT_ROOT:-/var/lib/php-sessions}"

# ── Paths baked into the image ───────────────────────────────────────────────
export MYSQL_CONF=/app/devbox.d/mariadb/my.cnf
# MYSQL_BASEDIR (Nix store path of the wrapped mariadb) is set by the flake
# wrapper when present; devbox sets it via shellenv.
export MYSQL_BASEDIR="${MYSQL_BASEDIR:-}"
export NGINX_CONFDIR=/app/devbox.d/nginx
export NGINX_WEB_ROOT=/app/devbox.d/web
export NGINX_WEB_SERVER_NAME="${NGINX_WEB_SERVER_NAME:-localhost}"
export NGINX_WEB_PORT="${NGINX_WEB_PORT:-8081}"
export PHPRC=/app/devbox.d/php

# ── Create writable runtime directories ──────────────────────────────────────
mkdir -p "$MYSQL_HOME" "$NGINX_RUN/temp" "$NGINX_LOG_DIR" /run/jethro \
       "$DEVBOX_PROJECT_ROOT/.devbox/virtenv/php/sessions" \
       "$JETHRO_HOME/docs"
chmod 1777 "$DEVBOX_PROJECT_ROOT/.devbox/virtenv/php/sessions"
# Fallback for PHP CLI started outside the entrypoint's environment (e.g. a
# `fly ssh console` shell, which inherits no exported vars): php.ini then
# expands ${DEVBOX_PROJECT_ROOT} to empty and uses this root-level directory.
# Web sessions never touch it.
mkdir -p /.devbox/virtenv/php/sessions
chmod 1777 /.devbox/virtenv/php/sessions
# Pre-create log file so mariadb_logs' `tail --retry -f` doesn't miss early output
touch "$MYSQL_HOME/mysql.log"

# ── Initialise MariaDB data directory if empty ───────────────────────────────
# mariadb-install-db (the devbox sub-flake wrapper) already carries
# --basedir/--datadir flags that read $MYSQL_BASEDIR/$MYSQL_DATADIR at runtime.
if [ ! -d "$MYSQL_DATADIR/mysql" ]; then
    echo "Initialising MariaDB data directory at $MYSQL_DATADIR..."
    mkdir -p "$MYSQL_DATADIR"
    mariadb-install-db --auth-root-authentication-method=normal
fi

echo "Starting Jethro PMM — Nginx on port $NGINX_WEB_PORT"
exec process-compose up \
    --tui=false \
    --config /app/docker/process-compose.yml
