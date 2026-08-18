---
sidebar_position: 8
---

# Docker Plan — Errata: suggested project simplifications

Companion to `DOCKER_PLAN.md` ("Suggested further simplifications"). Each item
below explains the change in full, and — critically — **where it belongs**:
inside this project (`jethro-pmm`) or upstream in the devbox fork at
`/home/jturner/src/github.com/jefft/devbox`.

---

## 1. Relocate `MYSQL_HOME` (socket + pid) out of the project tree

> **Resolved.** The devbox fork now puts plugin sockets and pid files in a
> per-user runtime dir (`/run/user/<uid>/devbox/<hash>/`), and the image is
> built from `docker/default.nix` (plain Nix), which reads only the paths it
> lists — the whole-root copy described below no longer happens. Kept for
> history.

**Why.** `nix build .#dockerImage` hashes the entire project root. Nix stats
every directory entry *before* any filter runs, and a Unix socket file is an
"unsupported type" that aborts the hash unconditionally. The devbox mariadb
plugin puts its socket at `{{ .Virtenv }}/run/mysql.sock`, i.e.
`.devbox/virtenv/mariadb/run/mysql.sock` — inside the repo. So `nix build`
fails whenever the dev MariaDB is running. This is the single biggest
developer-experience wart in the Docker workflow: you cannot build the image
while your dev environment is up.

**The fix.** Override the socket/pid location via `devbox.json`'s `env` block.
This works because of how devbox composes the environment — verified in the
fork source at `internal/devconfig/config.go:352-361`:

```go
func (c *Config) Env() map[string]string {
	env := map[string]string{}
	for _, i := range c.included {                 // plugins first
		expandedEnvFromPlugin := OSExpandIfPossible(i.Env(), env)
		maps.Copy(env, expandedEnvFromPlugin)
	}
	rootConfigEnv := OSExpandIfPossible(c.Root.Env, env)   // devbox.json last
	maps.Copy(env, rootConfigEnv)                          // root wins
	return env
}
```

`devbox.json` env **overrides** plugin env. So:

```jsonc
// devbox.json
"env": {
  "MYSQL_HOME":      "/tmp/jethro-mariadb",
  "MYSQL_UNIX_PORT": "/tmp/jethro-mariadb/mysql.sock",
  "MYSQL_PID_FILE":  "/tmp/jethro-mariadb/mysql.pid"
}
```

Three vars, not one, because the plugin sets `MYSQL_UNIX_PORT` and
`MYSQL_PID_FILE` independently; overriding `MYSQL_HOME` alone would leave the
socket pointing back at `.devbox/`. `MYSQL_DATADIR` is deliberately left as-is:
the datadir contains only regular files (Nix hashes them fine) and
`mariadb_check` still expects `*/.devbox/virtenv/*/data/`.

**Where it belongs:** `jethro-pmm` only. No fork change required.

**Gotcha — env expansion.** `devbox.json` env values are expanded by
`OSExpandEnvMap` (`internal/conf/env.go`) against the *devbox/nix* environment,
with only `$PWD` special-cased to the project dir. It does **not** do shell
`${VAR:-default}` fallback, and it does **not** consult arbitrary OS variables
like `$XDG_RUNTIME_DIR` or `$HOME`. So the target must be a literal absolute
path (`/tmp/jethro-mariadb` above) — not `${XDG_RUNTIME_DIR}/...`. `/tmp` is
fine for a dev convenience; a per-user or per-checkout location (to avoid
collisions when two checkouts run simultaneously) would require the expansion
improvement in §5.1 first.

**Effort:** tiny (one `env` block) once the expansion limitation is understood.
Completely removes the "stop devbox before `nix build`" footgun.

---

## 2. Relax `mariadb_check`'s datadir pattern

**Why.** `devbox.d/bin/mariadb_check` (project code) refuses any MariaDB whose
datadir doesn't match `*/.devbox/virtenv/*/data/`:

```bash
[[ $(mariadb_as_root -sNBe "select @@datadir") == */.devbox/virtenv/*/data/ ]] \
	|| { echo >&2 "Connected to a non-devbox database - refusing to continue"; exit 1; }
```

Inside the Docker image the datadir is `/var/lib/mariadb`, so
`mariadb_ensure_db` can't be used there and `docker/process-compose.yml` has to
inline the `CREATE USER`/`CREATE DATABASE`/`GRANT` SQL instead — a second copy
of the grant logic that can drift.

**The fix.** Add an escape hatch, e.g. honour `MARIADB_ALLOW_ANY_DATADIR=1`:

```bash
[[ -n "${MARIADB_ALLOW_ANY_DATADIR:-}" ]] \
  || [[ $(mariadb_as_root -sNBe "select @@datadir") == */.devbox/virtenv/*/data/ ]] \
  || { echo >&2 "Connected to a non-devbox database - refusing to continue"; exit 1; }
```

Then `docker/process-compose.yml`'s `jethro_database_setup` can call
`devbox.d/bin/mariadb_ensure_db jethro` again, with `MARIADB_ALLOW_ANY_DATADIR=1`
in the container env — one copy of the grant logic, matching local dev.

**Where it belongs:** `jethro-pmm` only. `mariadb_check` is project code, not
part of the devbox fork.

**Effort:** small. Pure hygiene — the current inline SQL already works; this
removes the duplication and makes Docker match dev more closely.

---

## 3. Run the container as a non-root user

**Why.** The image currently runs everything as root and must defeat three
root-refusal guards: `mariadbd --user=root`, `php-fpm --allow-to-run-as-root`,
`nginx -g "user root;…"`. It also means the bind-mounted datadir ends up owned
by root on the host, which broke `nix build` when the mount was inside the repo
and generally makes host-side cleanup awkward.

**The fix.** Create a `jethro` user in the image via `fakeRootCommands`, run
`mariadbd`/`php-fpm`/`nginx` as that user, and document that the bind-mount must
be chowned to that uid. More moving parts (uid choice, volume ownership, the
fact that `streamLayeredImage` images have no `/etc/passwd` beyond `fakeNss`),
and this image is dev/CI-oriented — so it's deliberately deferred.

**Where it belongs:** `jethro-pmm` only (`docker/default.nix`). No fork change.

**Effort:** medium. Not required for the current goals.

---

## 4. Publish a prebuilt image from CI

**Why.** Today a user must install Nix, run `./devbox install`, then
`nix build` before they can `docker run` anything. For teams that just want to
run Jethro, that's a high bar.

**The fix.** A GitHub Actions job that runs `./devbox install`, `nix-build
docker -A dockerImage`, `./result | docker load`, and pushes to GHCR (or Docker Hub) on tags.

**Where it belongs:** `jethro-pmm` only (`.github/workflows/`). No fork change.

**Effort:** small; it removes the Nix requirement for end users who just want
to run Jethro.

---

## 5. Upstream devbox fork issues (separate from the simplifications)

These are real bugs/improvements for `/home/jturner/src/github.com/jefft/devbox`,
found while deploying. They are not prerequisites for the Docker image, but
each would remove a landmine.

### 5.1 `devbox.json` env expansion doesn't support `${VAR:-default}` or OS vars

`internal/conf/env.go` `OSExpandEnvMap` resolves only `$PWD` (→ project dir) and
variables already in the devbox/nix env; `${VAR:-default}` is misparsed as a
variable literally named `VAR:-default` and resolves empty. Supporting
`${VAR:-default}` and falling back to `os.Getenv` would let §1 use
`${XDG_RUNTIME_DIR:-/tmp}` and make the socket relocation portable.

**File:** `internal/conf/env.go`.


### 5.2 mariadb plugin `flake.nix` passes `--basedir=$out` (empty at runtime)

`plugins/mariadb/flake.nix` wraps the binaries with:

```nix
wrapProgram $out/bin/mariadbd \
  --add-flags '--defaults-file=''$MYSQL_CONF --basedir=$out --datadir=''$MYSQL_DATADIR ...'
```

Inside a Nix `''...''` string, `$out` is literal text (no `${`), so the wrapper
script carries `--basedir=$out`, which bash expands at runtime to an **empty**
string (`$out` is not a runtime env var). The `mariadb-install-db` wrapper is
masked because it also passes `--basedir=''$MYSQL_BASEDIR` afterwards, but
`mariadbd`/`mariadbd-safe` have only the broken `--basedir=$out`. It works today
only because mariadbd tolerates a missing basedir, but it's a latent bug — the
intent was the build-time store path, i.e. `--basedir=${out}`.

**File:** `plugins/mariadb/flake.nix`.

### 5.3 `devbox services stop` can leave a respawned `mariadbd` orphaned

Observed once, not yet deterministically reproduced: after stopping services, a
`mariadbd` (crash-recovering from an earlier unclean kill) was found running
with `restart: always` metadata and a stale socket left in `.devbox/`, which
then blocked `nix build`. Likely a shutdown-ordering race between process-compose
teardown and the `restart: always` respawn. Worth a look in the fork's services
lifecycle (and possibly `/home/jturner/src/github.com/jefft/process-compose`),
but needs a minimal repro before fixing.

---

## Summary table

| # | Change | Where | Effort | Unblocks |
|---|---|---|---|---|
| 1 | ~~Relocate `MYSQL_HOME` socket out of repo~~ (resolved) | devbox fork | — | `nix build` while dev services run |
| 2 | `mariadb_check` env escape hatch | jethro-pmm | small | drop inlined SQL in Docker |
| 3 | Non-root container user | jethro-pmm (`docker/default.nix`) | medium | security/ownership hygiene (deferred) |
| 4 | CI-published image | jethro-pmm (`workflows`) | small | no-Nix `docker run` for users |
| 5.1 | env `${VAR:-default}` + OS fallback | devbox fork | small | portable §1 |
| 5.2 | mariadb `--basedir=${out}` | devbox fork | tiny | correct wrapper |
| 5.3 | services-stop orphan race | devbox/process-compose fork | medium | no stale socket landmine |
