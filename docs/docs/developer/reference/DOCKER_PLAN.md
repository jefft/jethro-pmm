---
sidebar_position: 5
---

# Docker Image — Jethro PMM (`nixpkgs.dockerTools.streamLayeredImage`)

> **Status: implemented and verified end-to-end** — `nix-build docker -A dockerImage` →
> `./result | docker load` → `docker run` → container healthy, Jethro serves the setup
> wizard against a bind-mounted MariaDB datadir, DB auth via unix socket.

## Goal

MariaDB + PHP-FPM + Nginx in a container built with Nix (no Dockerfile, no
Docker daemon at build time), MariaDB data externally mountable.

---

## Why this approach beats a Dockerfile

| Dimension | Dockerfile | streamLayeredImage |
|---|---|---|
| Nix in the runtime image | Yes — full infrastructure | No — runtime closures only |
| Devbox in the runtime image | Yes | No |
| Docker daemon needed to build | Yes | No (`nix-build` produces the image stream script) |
| Reproducibility | Best-effort | Bit-for-bit (content-addressed store paths) |
| Layer granularity | A few large layers | One layer per Nix store path |
| Build cache reuse | Docker layer cache | Nix binary cache (shared with dev env) |
| Image size (observed) | ~1.5–2 GB | **1.14 GB** |

---

## Architecture

```
Build time (nix-build docker -A dockerImage):
  docker/default.nix  (plain Nix, not a flake — source of truth, read it for details)
    ├── nixpkgs            (fetchTarball; rev + narHash read from devbox.lock)
    ├── php sub-flake      (.devbox/virtenv/php/flake — devbox-generated)
    ├── mariadb sub-flake  (.devbox/virtenv/mariadb/flake — devbox-generated)
    │     └── binaries wrapped so $MYSQL_DATADIR / $MYSQL_CONF / $MYSQL_UNIX_PORT
    │         are read from the runtime environment
    ├── nginx, process-compose, tini, coreutils, bash,
    │   gnused, gettext, gawk, curl      (nixpkgs)
    └── project source   → lib.fileset.toSource (listed paths only; lands at /app)
         → streamLayeredImage → `result` script streaming the image tarball to stdout

Runtime (docker run):
  tini (PID 1)
    └── jethro-entrypoint (thin wrapper from default.nix: Nix PATH + MYSQL_BASEDIR)
          └── docker/entrypoint.sh (store copy)  ← single source of truth
                ├── exports all MYSQL_*/NGINX_*/PHPFPM_* env vars
                ├── creates writable runtime dirs (incl. PHP session dir)
                ├── mariadb-install-db if $MYSQL_DATADIR/mysql absent
                └── exec process-compose up --tui=false
                      ├── mariadbd   --user=root   (socket at /run/jethro/mariadb/)
                      ├── php-fpm    --allow-to-run-as-root  (unix socket /run/jethro/php-fpm.sock)
                      ├── nginx      -g "user root;daemon off;"  (port 8081)
                      └── jethro_database_setup  (socket, root, 'jethro'@'localhost')
```

**Source of truth is the code, not this plan**: `docker/default.nix`,
`docker/entrypoint.sh`, `docker/process-compose.yml` (runs inside the
container), `docker/conf.php` (the production config `default.nix` installs over
the devbox demo config), `docker/compose.yml` (runs the image locally with
`docker compose`) and `devbox.d/bin/jethro_docker build` (builds and loads it).
This document records the *why*.

---

## Why not a flake

The image used to be built from a root `flake.nix`. This repo has no `.git`
(jj keeps history in `.jj/`), so Nix fetched it as a `path:` flake, which
copies the **entire working directory** into `/nix/store` before any Nix code
runs: `.env` secrets, root `conf.php`, `.devbox/data` (the dev database),
`backups/` — 765 MB, world-readable, a fresh copy after every change. Filters
inside `flake.nix` (`builtins.path`, `lib.fileset`) run only on that copy and
cannot prevent it. A git-backed flake would copy only tracked files; plain Nix
copies nothing until referenced. `docker/default.nix` is plain Nix, and
`lib.fileset.toSource` copies only the paths it lists (paths outside the
file's own directory, like `../include`, are allowed in plain Nix).

## How the devbox-generated sub-flakes are used

`./devbox install` generates two plugin sub-flakes:

- **`.devbox/virtenv/php/flake/`** — PHP 8.5 + extensions via `php85.withExtensions`
- **`.devbox/virtenv/mariadb/flake/`** — MariaDB with `wrapProgram`-wrapped binaries

`docker/default.nix` imports each `flake.nix` and calls its `outputs` with the
image's own nixpkgs (their only input; neither uses `self`), the equivalent of
flake `inputs.nixpkgs.follows`: one nixpkgs closure, no version skew.

**Prerequisite**: `.devbox/virtenv/*/flake/` is gitignored. `./devbox install`
must run before `nix-build docker -A dockerImage` (CI and locally); the build
stops with a message if it hasn't.

---

## Problems found while deploying — and their fixes

Each of these surfaced only at runtime; all are baked into the current files.

| Symptom | Root cause | Fix (where) |
|---|---|---|
| `mariadb-install-db` failed: `sed: command not found` then `mkdir ''` | its shell script calls `sed` via PATH; with sed missing, *every* arg parse returned empty | `gnused` added to `runtimePkgs` |
| `mariadbd: ... run mysqld as root!` → abort | MariaDB refuses root by default | `--user=root` (`docker/process-compose.yml`) |
| `nginx: getgrnam("nogroup") failed` | minimal `/etc/group` from `fakeNss` lacks nogroup | `-g "user root;..."` |
| php-fpm `'user' directive has not been specified ... as root` | same root-running issue | `--allow-to-run-as-root` |
| nginx `mime.types`/`fastcgi.conf` not found | nginx resolves relative includes against `-p` prefix | copy both to `$NGINX_PATH_PREFIX/` before start |
| php-fpm `[/app/devbox.d/php/php-fpm.conf] empty value` | `env[JETHRO_VERSION] = ${JETHRO_VERSION}` unset → empty | superseded: the image uses `docker/php-fpm.conf`, which has no `env[]` lines (`clear_env = no`) |
| PHP `session_start(): open(/.devbox/virtenv/php/sessions...) failed` | `session.save_path` in `devbox.d/php/php.ini` uses `DEVBOX_PROJECT_ROOT`, unset in Docker | entrypoint exports `DEVBOX_PROJECT_ROOT` so the path lands on persistent storage (see FLYIO.md "Sessions lost when the Machine stops"); entrypoint still mkdirs `/.devbox/virtenv/php/sessions` for CLI started outside its env |
| `tail: no files remaining` (mariadb_logs) | log file created after tail starts | `touch` in entrypoint + `tail --retry -f` |
| `Access denied for 'jethro'@'localhost' (using password: YES)` for root SQL | `my.cnf` `[client]` password leaked into root connections | **superseded by socket switch** (below) |
| `nix build` fails: `rm: cannot remove '.../devbox.d/functest_jethro_server/public': Permission denied` | `cp` from Nix store preserves source mode 0555 on directories; `rm -rf` cannot unlink entries from a read-only parent | superseded: `lib.fileset.difference` excludes the directory in `docker/default.nix` |

---

## DB auth: unix socket (simplification, completed)

Originally the image used TCP (`DB_HOST=127.0.0.1`, `DB_PORT=3307`) which
required `'jethro'@'%'` grants and `--protocol=tcp --password=` overrides,
because MariaDB treats TCP-from-localhost as `'user'@'127.0.0.1'`, not
`'user'@'localhost'`.

**Now everything connects via the unix socket** (`MYSQL_UNIX_PORT`), so the
plain `'jethro'@'localhost'` grant works everywhere:

- `devbox.d/demo/conf.php` — `DB_DSN` = `mysql:unix_socket=$MYSQL_UNIX_PORT;dbname=jethro`
- `devbox.d/functest_jethro_server/conf.php` — same pattern, building the DSN
  from the scenario-overridable `DB_DATABASE`
- `tests/functional/upgrade/2.40/upgrade_latin1_cli/run_upgrader.sh` — temp conf uses the DSN
- `docker/process-compose.yml` `jethro_database_setup` — `mariadb -u root
  --skip-password` over the socket (wrapper adds `--socket=$MYSQL_UNIX_PORT`)
- `upgrades/.../dbcharsetutils.php` `_openRawConnection()` — now reuses
  `DB_DSN` (charset swapped) instead of requiring `DB_HOST`/`DB_DATABASE`
  constants, which `DB_DSN`-based confs never define

MariaDB itself is socket-only too: `skip-networking` in
`devbox.d/mariadb/my.cnf` — there is no TCP listener at all.

Verified: full functional suite passes (68/68) with the socket confs.

---

## The `mariadb_check` problem

`devbox.d/bin/mariadb_ensure_db` calls `mariadb_check`, which rejects any
datadir not matching `*/.devbox/virtenv/*/data/`. With
`MYSQL_DATADIR=/var/lib/mariadb` the check always fails, so
`docker/process-compose.yml` inlines the CREATE USER/DATABASE SQL instead of
calling the helper. See *Suggested simplifications* below.

---

## Build & Run

```sh
# 1. Materialise sub-flakes (gitignored; no-op if already present)
./devbox install

# 2. Compile CSS/JS bundles and write include/version.txt. docker/default.nix
#    refuses to build without them: the image must not run Jethro as 'DEV'.
devbox run -- devbox.d/bin/jethro_compile --use <version>   # `devbox run compile` drops arguments

# 3. Build — no Docker daemon needed
nix-build docker -A dockerImage

# 4. Load & run — bind-mount both persistent paths OUTSIDE the repo
#    (container writes root-owned files into the mounts)
./result | docker load
mkdir -p /tmp/jethro-home /tmp/jethro-db
docker run -d --name jethro -p 8081:8081 \
  -v /tmp/jethro-home:/var/lib/jethro -v /tmp/jethro-db:/var/lib/mariadb \
  jethro-pmm:latest

# 5. Or run it with Compose, which uses named volumes (no root-owned files on
#    the host) and maps the UI to http://localhost:8082:
docker compose -f docker/compose.yml up -d
docker compose -f docker/compose.yml ps    # wait for "healthy"
curl -o /dev/null -w '%{http_code}\n' http://localhost:8082/status.php   # 200
```

`devbox.d/bin/jethro_docker build` runs steps 3–4 in one go (`./devbox install`,
`nix-build`, `./result | docker load`); it assumes step 2 already produced the
compiled assets.

**`docker/conf.php` must be present in the tree.** `docker/default.nix` copies
it over the devbox demo config (`cp ${./conf.php} $out/app/devbox.d/demo/conf.php`),
so a build that dies evaluating `${./conf.php}` means the file is missing — it is
a normal file to commit (only the *root* `/conf.php` is gitignored; the
`.gitignore` `/conf.php` line is anchored for exactly that reason). If a working
copy predates it, retrieve it from a checkout that has it before building.

Two independent persistent paths (defaults in `docker/entrypoint.sh`):
`JETHRO_HOME` (`/var/lib/jethro`) holds static file data only, with `docs/`
as the Documents root (`DOCUMENTS_ROOT_PATH` in `docker/conf.php`), created on
start; `MYSQL_DATADIR` (`/var/lib/mariadb`) holds MariaDB and is never inside
`JETHRO_HOME`. On Fly.io, where a Machine mounts one volume, both are sibling
directories of it (`docker/fly.toml`).

First start: entrypoint runs `mariadb-install-db` into the empty datadir,
`jethro_database_setup` creates the `jethro` db/user, then nginx/php-fpm/mariadb
come up and http://localhost:8081 shows Jethro's setup wizard (fresh empty DB).
Subsequent starts reuse the mounted data as-is.

Named volumes (as in `docker/compose.yml`) also work and avoid the
root-owned-files-on-host issue entirely.

## Loading demo data into the container

`devbox run demodata` targets the devbox :8081 instance. The container's MariaDB
listens only on its unix socket (`/run/jethro/mariadb/mysql.sock`; `skip-networking`
in `devbox.d/mariadb/my.cnf`), so the host cannot connect to it directly. Use:

```sh
devbox.d/bin/jethro_docker_demodata                    # container auto-discovered (compose label)
devbox.d/bin/jethro_docker_demodata docker-jethro-1 "St Bob's"   # explicit container + SYSTEM_NAME
```

It reuses `devbox.d/bin/jethro_load_demodata` unchanged, shadowing its DB
commands with `docker exec` wrappers (`mariadb` → `-u jethro -pjethro --socket …`;
`mariadb_as_root` → `-u root --skip-password --socket …`) and stubbing
`mariadb_check` (the devbox guard rejects the `/var/lib/mariadb` datadir, see
errata §2). Verified: 62 tables, 194 people, 224 SMSes; `demo` / `qfntt7eYuwHs123`
then logs in.

The manual equivalent, if you want to see the pieces:

```sh
C=docker-jethro-1; S=/run/jethro/mariadb/mysql.sock
zcat tests/functional/testdata/demodata.sql.gz \
  | php devbox.d/bin/mariadb_rename --database jethro \
  | docker exec -i "$C" mariadb --socket=$S -u jethro -pjethro jethro
```

## Mock SMS server in the image

`smsmockserver/` (its own README) ships in the image and is served by the same
nginx/php-fpm at `/smsmockserver/…` — the route is already in
`devbox.d/nginx/nginx.template`. Two image-specific details:

- That location declares `root ../../../smsmockserver/webroot`, relative to
  nginx's `-p` prefix (`/run/jethro/nginx` in the container). The source lands
  at `/app`, so `docker/default.nix` (`fakeRootCommands`) creates
  `/smsmockserver → /app/smsmockserver`.
- The mock's database `jethro_functest_smsmockserver` (hardcoded in
  `smsmockserver/webroot/index.php`) is created by `jethro_database_setup`
  (`docker/process-compose.yml`); `Store::migrate()` creates its tables on the
  first request.

Configuration is **environment-only**, via the `JETHRO_CONF_*` hook, so the same
image serves a real deployment and the mock-SMS demo:

| constant | value |
|---|---|
| `SMS_PROVIDER` | `5centsmsv5` |
| `SMS_5CENTSMS_URL` | `http://127.0.0.1:8081/smsmockserver/5centsms` |
| `SMS_SENDER_OPTIONS` | `Jethro` |
| `SMS_SEND_COOLOFF` | `0` |

`docker/compose.yml` sets these for local Docker, `docker/fly.demo.toml` for the
Fly demo. The absolute `127.0.0.1` URL keeps Jethro's self-call inside the
container; a literal sender avoids `senderNotVerifiedError()`, which blocks
sends as the acting user's own mobile until that number is registered with the
provider (the mock reports none).

Verified locally and on Fly: `GET /` serves Jethro with
`jethro-sms-configured=1`; a send records `sms` + `_smsdelivery` in Jethro and a
`POST /sms` (with the chosen sender) in `mock_sms_message`.

## Startup timing

Measured with the same image, timing from the container's `StartedAt` (warm =
datadir already initialised on a mounted volume; cold = fresh/empty datadir):

| Phase | warm | cold (fresh volume) |
|---|---|---|
| tini + entrypoint preamble | ~0.17 s | ~0.17 s |
| `mariadb-install-db` (cold only) | — | ~0.4 s (fast host) · ~4 s @ 1 vCPU |
| process-compose up → MariaDB answers queries | ~0.9 s (0.55–1.16) | ~2.7 s |
| DB-backed page served at `/` | ~1.0 s (0.59–1.21) | ~2.9 s · ~5.5 s @ 1 vCPU/1 GB |

Constrained to Fly's VM size (`--cpus=1 --memory=1g`, cf. `docker/fly.toml`)
warm startup is still ~1 s, so Fly's reported ~8 s cold start is platform
overhead (Machine boot, volume attach, layer unpack) rather than
process-compose/MariaDB/PHP-FPM. `GET /status.php` is DB-independent and
answers in ~0.3 s — it is *not* an app-readiness signal.

To split Fly's number without deploying: `fly logs -a <app>` timestamps show the
gap from Machine start to `Starting Jethro PMM` (platform) versus
`Starting Jethro PMM` → `Database ready: jethro` (internal).

---

## Suggested further simplifications (not yet done)

1. ~~Move `MYSQL_HOME` (socket/pid) out of the project tree~~ — done in the
   devbox fork (plugin runtime dirs under `/run/user/<uid>/devbox/`), and moot
   for the image since `docker/default.nix` never reads `.devbox/` beyond the
   two sub-flake files.

2. **Relax `mariadb_check`'s datadir pattern** — accept any datadir when an
   env override (e.g. `MARIADB_ALLOW_ANY_DATADIR=1`) is set, or add the Docker
   datadir to the pattern. Then `jethro_database_setup` could call
   `devbox.d/bin/mariadb_ensure_db` directly instead of the inlined SQL,
   keeping one copy of the grant logic.

3. **Non-root container user** — the image currently runs everything as root
   (`--user=root`, `--allow-to-run-as-root`, `user root;`) because the bind
   mount is root-owned. A `jethro` user via `fakeRootCommands` + matching
   ownership of the volume would be more production-appropriate, at the cost
   of setup complexity. Fine to defer — this image is dev/CI-oriented.

4. **Publish a prebuilt image** — GH Actions could `nix-build docker -A dockerImage`
   and push to a registry on tags, so users skip the Nix requirement entirely.

---

## Remaining notes

- **nixpkgs pin** is read from `devbox.lock` (`github:NixOS/nixpkgs/nixpkgs-unstable`
  → `resolved`) by `docker/default.nix`, so `./devbox update` moves the image's
  nixpkgs too. Watch MariaDB major versions across updates: an existing datadir
  upgrades in place, but cannot be downgraded. PHP comes from the same nixpkgs
  (`php85`), so it can differ from devbox's explicitly versioned `php@x.y.z`.
- **Sub-flake wrapper quirk**: the mariadb sub-flake's
  `mariadb-install-db` wrapper passes `--basedir=$out` (empty at runtime);
  harmless because the later `--basedir=$MYSQL_BASEDIR` wins, but worth
  fixing upstream in the devbox fork.
- **Multi-arch**: sub-flakes are hardcoded `x86_64-linux`; ARM needs
  regeneration on ARM plus a `system` update.
