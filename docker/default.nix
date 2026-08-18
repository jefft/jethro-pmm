# Jethro PMM Docker image via nixpkgs.dockerTools.streamLayeredImage.
#
# Build from the repo root, then load:
#   nix-build docker -A dockerImage && ./result | docker load
#
# Deliberately NOT a flake: this repo has no .git (jj only), so a flake is
# fetched as a `path:` flake and Nix copies the ENTIRE working directory
# (.env secrets, .devbox/data, backups/, …) into the world-readable /nix/store
# before any Nix code runs. Plain Nix reads files in place, and
# lib.fileset.toSource below copies only the listed paths.
# See docs/docs/developer/reference/DOCKER_PLAN.md.
let
  system = "x86_64-linux";

  # nixpkgs: the same commit devbox uses, read from devbox.lock (tracked), so
  # './devbox update' moves the image too. devbox records it as a flake ref:
  #   github:NixOS/nixpkgs/<rev>?lastModified=…&narHash=sha256-…  (URL-encoded)
  # narHash is the hash of the unpacked tree, which is what fetchTarball's
  # sha256 checks. A MariaDB major-version jump here changes the image's
  # MariaDB too: upgrades of an existing datadir work, downgrades do not.
  nixpkgsPin =
    let
      lockKey = "github:NixOS/nixpkgs/nixpkgs-unstable";
      locked = (builtins.fromJSON (builtins.readFile ../devbox.lock)).packages.${lockKey}.resolved or
        (throw "devbox.lock has no resolved '${lockKey}' entry: run './devbox install'");
      parsed = builtins.match "github:NixOS/nixpkgs/([0-9a-f]{40})\\?(.*&)?narHash=([^&]+).*" locked;
      urlDecode = builtins.replaceStrings [ "%2B" "%2F" "%3D" ] [ "+" "/" "=" ];
    in
      if parsed == null then throw "unexpected nixpkgs ref in devbox.lock: ${locked}"
      else {
        rev = builtins.elemAt parsed 0;
        narHash = urlDecode (builtins.elemAt parsed 2);
      };
  pkgs = import (builtins.fetchTarball {
    url    = "https://github.com/NixOS/nixpkgs/archive/${nixpkgsPin.rev}.tar.gz";
    sha256 = nixpkgsPin.narHash;
  }) { inherit system; config = { }; overlays = [ ]; };
  inherit (pkgs) lib;

  # The same PHP and MariaDB derivations devbox puts on PATH, from the
  # sub-flakes './devbox install' generates under .devbox/virtenv/ (gitignored).
  # Their outputs are called with our nixpkgs instead of their own pins (the
  # equivalent of flake `follows`), so the image shares one nixpkgs closure.
  # The mariadb derivation wraps all binaries (mariadbd, mariadb,
  # mariadb-install-db, mariadb-admin) via wrapProgram to read $MYSQL_DATADIR,
  # $MYSQL_CONF, $MYSQL_UNIX_PORT etc. at runtime.
  devboxFlake = name:
    let flakeFile = ../.devbox/virtenv + "/${name}/flake/flake.nix"; in
    if !builtins.pathExists flakeFile then
      throw "${toString flakeFile} missing: run './devbox install' first"
    else
      ((import flakeFile).outputs {
        self = { };
        nixpkgs = { legacyPackages.${system} = pkgs; };
      }).packages.${system}.default;
  php     = devboxFlake "php";
  mariadb = devboxFlake "mariadb";

  runtimePkgs = with pkgs; [
    php
    mariadb
    nginx
    process-compose
    tini
    coreutils
    bash
    gnused    # mariadb-install-db shell script calls 'sed' via PATH
    gettext   # provides envsubst, used in nginx service command
    gawk      # used in the envsubst pipeline in docker/process-compose.yml
    curl      # health checks and devbox.d/bin/ scripts
    cacert    # /etc/ssl/certs CA bundle: PHP curl/openssl verify outbound HTTPS (URL shortener etc.)
  ];

  # The image runs Jethro as a release, not 'DEV' (include/init.php): no
  # error details for users, bundled assets (templates/head.template.php).
  # That needs include/version.txt plus the compiled bundles it names,
  # produced beforehand by `devbox run -- devbox.d/bin/jethro_compile --use <version>`;
  # the Nix sandbox has no network for the less@1.7.5 install that compile does.
  jethroVersion =
    let
      versionFile = ../include/version.txt;
      version = lib.trim (builtins.readFile versionFile);
      missing = builtins.filter (f: !builtins.pathExists (../. + "/${f}")) [
        "resources/css/jethro-${version}.css"
        "resources/js/jethro-${version}.js"
      ];
    in
      if !builtins.pathExists versionFile then
        throw "include/version.txt missing: run `devbox run -- devbox.d/bin/jethro_compile --use <version>` before building the image"
      else if version == "" || version == "DEV" then
        throw "include/version.txt must name a compiled release version, not '${version}'"
      else if missing != [ ] then
        throw "compiled assets missing for version ${version}: ${toString missing}; run `devbox run -- devbox.d/bin/jethro_compile --use ${version}`"
      else version;

  # The only repo files that reach /nix/store; paths keep their layout
  # relative to the repo root and land at /app.
  # Excluded: files/ (runtime uploads), the root process-compose.yml
  # (devbox/functest services), devbox.d/functest_jethro_server, and
  # everything else not listed.
  # devbox.d/web/ contains relative symlinks (../../calls/, ../../include/, …)
  # that resolve against the reassembled tree: /app/devbox.d/web/calls -> /app/calls/.
  src = lib.fileset.toSource {
    root = ../.;
    fileset = lib.fileset.unions [
      ../include ../views ../calls ../resources ../members ../public ../scripts
      ../templates ../upgrades ../db_objects ../hooks ../locale ../vendor
      ../smsmockserver
      ../index.php ../status.php ../favicon.ico
      (lib.fileset.difference ../devbox.d ../devbox.d/functest_jethro_server)
      ./php-fpm.conf ./process-compose.yml
    ];
  };

  # The name embeds jethroVersion, which also forces its asset checks.
  srcAtApp = pkgs.runCommand "jethro-src-${jethroVersion}" { } ''
    mkdir -p $out
    cp -rP ${src} $out/app
    chmod -R u+w $out/app/devbox.d/web
    # Production config replaces the devbox demo conf (prefills, mock SMS).
    cp ${./conf.php} $out/app/devbox.d/web/conf.php
  '';

  # Thin wrapper: sets up the Nix-store PATH and the store-path-dependent
  # env vars, then runs docker/entrypoint.sh (copied to the store) — the
  # single source of truth for the entrypoint logic, also usable outside
  # a Nix build for local testing. Invoked via the store bash because the
  # minimal image has no /usr/bin/env for the script's shebang.
  entrypoint = pkgs.writeShellScript "jethro-entrypoint" ''
    set -euo pipefail
    # Nix store paths first, then /app/devbox.d/bin (helper scripts),
    # then whatever the image/env already provides.
    export PATH="${lib.makeBinPath runtimePkgs}:/app/devbox.d/bin:$PATH"
    export MYSQL_BASEDIR="${mariadb}"
    exec "${pkgs.bash}/bin/bash" "${./entrypoint.sh}"
  '';
in
{
  # streamLayeredImage: `result` is a script that writes the image tarball
  # to stdout, so `./result | docker load` never stores the tarball in /nix/store.
  dockerImage = pkgs.dockerTools.streamLayeredImage {
    name = "jethro-pmm";
    tag  = "latest";

    contents = runtimePkgs ++ [
      srcAtApp
      # Minimal /etc/passwd and /etc/group so nginx/php-fpm don't fail
      # on getpwuid() calls.
      pkgs.dockerTools.fakeNss
    ];

    # /tmp must exist with sticky world-write; /run/jethro and the persistent
    # paths are created at runtime by docker/entrypoint.sh.
    # enableFakechroot is required for fakeRootCommands to work.
    enableFakechroot = true;
    fakeRootCommands = ''
      mkdir -p /tmp
      chmod 1777 /tmp
      # devbox.d/nginx/nginx.template's mock-SMS location declares
      #   root ../../../smsmockserver/webroot;
      # a relative path, resolved against nginx's -p prefix (/run/jethro/nginx
      # in the container), i.e. /smsmockserver/webroot. That name doesn't exist
      # in the image (the source lands at /app), so link it to the real tree.
      ln -s /app/smsmockserver /smsmockserver
    '';

    # Runtime env defaults (MYSQL_DATADIR, NGINX_WEB_PORT, …) live only in
    # docker/entrypoint.sh.
    config = {
      Entrypoint = [ "${pkgs.tini}/bin/tini" "--" "${entrypoint}" ];
      Env = [ "SSL_CERT_FILE=${pkgs.cacert}/etc/ssl/certs/ca-bundle.crt" ];
      ExposedPorts = { "8081/tcp" = { }; };
      # JETHRO_HOME (static files) and MYSQL_DATADIR, see docker/entrypoint.sh
      Volumes = { "/var/lib/jethro" = { }; "/var/lib/mariadb" = { }; };
      WorkingDir = "/app";
      Healthcheck = {
        Test        = [ "CMD" "curl" "-sf" "http://localhost:8081/status.php" ];
        Interval    = 10 * 1000000000;   # 10s in nanoseconds
        Timeout     = 3  * 1000000000;
        StartPeriod = 60 * 1000000000;
        Retries     = 3;
      };
    };
  };
}
