---
sidebar_position: 9
---

# Jethro PMM on Fly.io

Deployed 2026-09-30, following https://fly.io/agent-ready.md (Fly Machines path).

## Result

- URL: https://jethro-pmm-coastec.fly.dev/
- Account: `sysadmin@coastec.net.au` (org: Coast System Administrator, personal)
- App: `jethro-pmm-coastec`, region `syd`, one Machine (`d8d10e6f4e9218`), shared 1 CPU / 1 GB
- Volume: `jethro_data` (`vol_vlym680mk519gjo4`), 3 GB, encrypted, mounted at `/data`; Fly allows one volume per Machine, so it holds two sibling directories: `/data/jethro` (`JETHRO_HOME`, static files, `docs/` = Documents) and `/data/mariadb` (`MYSQL_DATADIR`), set in `docker/fly.toml` `[env]`. Daily snapshots, 5 retained
- IPs: shared IPv4 `66.241.125.46`, dedicated IPv6 `2a09:8280:1::1a1:8169:0`
- Verified: `fly status` shows 1/1 health checks passing; `GET /` returns HTTP 200 and serves the Jethro installer.

> **Security:** at deploy time the public URL served the install form
> (`install_user_un` / `install_user_pw1`). Whoever completes it first becomes
> admin. Complete installation immediately.

## Approach

- No Dockerfile exists; the image is built with Nix (`docker/default.nix` → `dockerImage`),
  the same image `docker/compose.yml` uses. `fly launch` was therefore not used;
  `docker/fly.toml` was written by hand and the local image pushed with `--local-only`.
- The single container runs MariaDB, PHP-FPM and nginx under process-compose
  (`docker/process-compose.yml`, entered via `docker/entrypoint.sh`). MariaDB's
  datadir is the only persistent state, hence the volume.

## Files

- `docker/fly.toml` (new): app name, `primary_region = "syd"`, env
  `NGINX_WEB_PORT=8081`, `NGINX_WEB_SERVER_NAME=jethro-pmm-coastec.fly.dev`,
  `[mounts]` for `jethro_data`, `http_service` on internal port 8081 with
  `force_https`, autostop/autostart, `min_machines_running = 0`, HTTP check on
  `/status.php` (60 s grace). flyctl only auto-discovers `./fly.toml`, so
  `fly deploy` needs `-c docker/fly.toml`; other commands take `-a jethro-pmm-coastec`.
- `docker/entrypoint.sh` (fixed): exports `NGINX_LOG_DIR`
  (default `$NGINX_RUN/logs` = `/run/jethro/nginx/logs`) and creates it.
- `docker/conf.php` (new): production `conf.php`; `docker/default.nix` installs it over
  the devbox demo config at `/app/devbox.d/web/conf.php`. See Configuration.
- `docker/php-fpm.conf` (new): image-only PHP-FPM config with `clear_env = no`
  and a smaller pool; `docker/process-compose.yml` now uses it.

## Findings

### Bug: Docker image nginx never started

First deploy failed health checks. Logs showed nginx looping on:

```
nginx: [emerg] unknown "nginx_log_dir" variable
```

`devbox.d/nginx/nginx.template` uses `${NGINX_LOG_DIR}` for `error_log` /
`access_log`, but `docker/entrypoint.sh` never set it. `envsubst` in
`docker/process-compose.yml` only substitutes variables present in the
environment, so the literal `${NGINX_LOG_DIR}` reached nginx. Only
process-compose's API on `127.0.0.1:8080` was listening. This affects the
local Docker image too, not only Fly. Fixed in the entrypoint (above); second
deploy passed.

### Other observations

- nginx `listen ${NGINX_WEB_PORT};` binds all interfaces, satisfying Fly's
  `0.0.0.0` requirement.
- With autostop, the Machine stops when idle; a cold request must boot
  MariaDB first (a few seconds). Set `min_machines_running = 1` to avoid.
- The first two deploys ran with the devbox demo `conf.php`
  (`devbox.d/web/conf.php`): the installer and logins were prefilled with
  `demo` / `qfntt7eYuwHs123`, and SMS pointed at the mock server. Replaced by
  `docker/conf.php`.

### Sessions lost when the Machine stops

Fly Machines reset the root filesystem every time they stop (idle autostop with
`min_machines_running = 0`, manual restart, redeploy). PHP file sessions lived
in `/.devbox/virtenv/php/sessions` on that root filesystem — `php.ini` builds
the path from `DEVBOX_PROJECT_ROOT`, which is unset in the image, and the
entrypoint created the resulting root-level directory — so every stop logged
all users out.

Fix: the entrypoint exports `DEVBOX_PROJECT_ROOT`, which `php.ini` builds
`session.save_path` from — `devbox.d/php/php.ini` needs no changes:

- `docker/fly.toml` sets `DEVBOX_PROJECT_ROOT = "/data/php-sessions"`:
  sessions land in `/data/php-sessions/.devbox/virtenv/php/sessions`, on the
  volume next to `jethro/` and `mariadb/`.
- `docker/entrypoint.sh` defaults it to `/var/lib/php-sessions` (a named
  `jethro-sessions` volume in `docker/compose.yml`) and creates the directory
  `1777` — PHP never creates the save dir itself. It also keeps creating the
  root-level `/.devbox/...` directory: `fly ssh console` shells inherit the
  Machine's `fly.toml` env and secrets (verified 2026-10-15) but not the
  entrypoint's own exports such as `PHPRC`, so console PHP CLI reads no
  `php.ini` unless run with `PHPRC=/app/devbox.d/php`; with it, the inherited
  `DEVBOX_PROJECT_ROOT` selects the volume dir. Web sessions never touch the
  fallback.
- Devbox development is unaffected: devbox exports the real project root and
  keeps sessions inside the checkout.

Verified locally: php-fpm (docker config, `PHPRC` pointed at `devbox.d/php`)
served a request through nginx; `ini_get('session.save_path')` returned
`$DEVBOX_PROJECT_ROOT/.devbox/virtenv/php/sessions` and the `sess_*` file was
created in that directory.

## Configuration

`index.php` loads `conf.php` from the web root (`/app/devbox.d/web`). In the
image that is `docker/conf.php`, which hard-codes the in-container DB
connection (socket, user `jethro`) and defines every `JETHRO_CONF_<NAME>`
environment variable as constant `<NAME>`. Values are strings; exactly
`true`/`false` become booleans. Settings go in Fly secrets (restarts the Machine):

```sh
fly secrets set -a jethro-pmm-coastec \
  JETHRO_CONF_SMS_PROVIDER=5centsmsv5 \
  JETHRO_CONF_SMS_5CENTSMS_APIKEY_ID=... JETHRO_CONF_SMS_5CENTSMS_APIKEY=...
```

Constant names: `docs/docs/docs/reference/settings-glossary.mdx`, `conf.php.sample`.
Most Jethro settings live in the database (System Configuration), not `conf.php`.

## Redeploy

```sh
devbox run -- devbox.d/bin/jethro_compile --use 2.40-coastec1   # `devbox run compile` drops arguments
devbox.d/bin/jethro_docker build
rm include/version.txt resources/css/jethro-2.40-coastec1.css resources/js/jethro-2.40-coastec1.js
fly deploy -c docker/fly.toml -a jethro-pmm-coastec --local-only --image jethro-pmm:latest --ha=false
```

`docker/default.nix` refuses to build unless `include/version.txt` names a non-`DEV`
version whose compiled bundles exist; without it Jethro runs as `DEV` (error
details shown to users, uncompiled assets). The files are removed after the
build because the same `version.txt` would take the local devbox instance out
of `DEV` mode, and the bundles are generated output.

Useful: `fly status`, `fly logs -a jethro-pmm-coastec --no-tail`, `fly ssh console`.

## Database access

MariaDB listens only on the unix socket `/run/jethro/mariadb/mysql.sock`
(no TCP port), so `fly proxy` cannot reach it; go through `fly ssh console`.
The image has no `/bin/sh`; use absolute paths (`/sbin/mariadb`, `/sbin/bash`).
Root authenticates without a password.

```sh
DB="/sbin/mariadb -S /run/jethro/mariadb/mysql.sock -u root"
fly ssh console -a jethro-pmm-coastec -C "$DB jethro" < dump.sql   # load
fly ssh console -a jethro-pmm-coastec -C "$DB -e 'show databases'" # query
fly ssh console -a jethro-pmm-coastec -C "$DB jethro" --pty        # interactive
```

For large dumps, upload first and load on the Machine:
`fly sftp put dump.sql /data/dump.sql`, then
`fly ssh console -C "/sbin/bash -c '$DB jethro < /data/dump.sql'"`,
then delete the file.

## Mock-SMS demo app — `easyjethro-sms-demo`

A second app in the `easy-jethro` org demonstrates Jethro's SMS features against
the mock server that ships in the image (no real provider, no cost). It runs the
**same image** as the coastec deployment; only `[env]` differs —
`docker/fly.demo.toml` supplies the `JETHRO_CONF_SMS_*` values described in
DOCKER_PLAN.md ("Mock SMS server in the image").

| | |
|---|---|
| URL | https://easyjethro-sms-demo.fly.dev/ |
| Org / region | `easy-jethro` / `syd` |
| Machine | one, `shared-cpu-1x` 1 GB, volume `jethro_data` (3 GB) at `/data` |
| Idle | scales to zero — `auto_stop_machines="stop"`, `min_machines_running=0` |

Create (once) and deploy/redeploy:

```sh
fly apps create easyjethro-sms-demo -o easy-jethro
fly volumes create jethro_data -a easyjethro-sms-demo -r syd -s 3
devbox.d/bin/jethro_docker build
devbox.d/bin/jethro_fly redeploy   # fly deploy -c docker/fly.toml --local-only -i jethro-pmm:latest --ha=false
```

`--local-only` is required alongside `-i`; without it flyctl looks the image up
in a registry and fails.

Load the demo dataset, or open a SQL terminal against the Machine (otherwise
the fresh volume shows the installer). `jethro_fly` always targets the app
named in `docker/fly.toml`:

```sh
devbox.d/bin/jethro_fly demodata "St Bob's"   # reload demo data into 'jethro'
devbox.d/bin/jethro_fly mysql jethro          # interactive MariaDB terminal
echo 'select * from _person' | devbox.d/bin/jethro_fly mysql jethro   # or pipe
devbox.d/bin/jethro_fly status               # flyctl status (hostname, machines)
```

Log in as `demo` / `qfntt7eYuwHs123`, open a person, **Send SMS** with sender
`Jethro`: the send is recorded by the mock (`mock_sms_message`) and delivery
advances after the mock's 5 s delay.

## Status

As of 2026-10-15 the only Fly app is `jethro-sms-demo` (the coastec
deployment described above has been deleted; `docker/fly.demo.toml` targets a
`mychurch-jethro` app that does not exist). `docker/fly.toml` is the live
deployment config. The session-persistence fix (above) needs a rebuild +
redeploy to take effect; after deploying, verify by logging in, stopping the
Machine (`fly machine stop`), requesting a page, and confirming the session
survives.
