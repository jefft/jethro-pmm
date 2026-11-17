<?php
/**
 * conf.php for the Docker/Fly.io image. docker/default.nix installs it over the devbox
 * demo config at /app/devbox.d/web/conf.php, so no demo prefills or mock SMS
 * settings reach a deployed instance.
 *
 * Any conf.php constant can be set from the environment as JETHRO_CONF_<NAME>,
 * e.g. `fly secrets set JETHRO_CONF_SMS_PROVIDER=5centsmsv5`. Values are
 * strings, except exactly "true"/"false", which become booleans.
 * (Numeric strings stay strings: SMS_SEND_COOLOFF=0 must not become false.)
 *
 * Environment reaches PHP because docker/php-fpm.conf sets clear_env = no.
 *
 * @see docs/docs/developer/reference/FLYIO.md
 */

// MariaDB runs in the same container and listens only on its unix socket
// (MYSQL_UNIX_PORT, exported by docker/entrypoint.sh). The jethro user is
// created by jethro_database_setup in docker/process-compose.yml.
define('DB_DSN', 'mysql:unix_socket=' . getenv('MYSQL_UNIX_PORT') . ';dbname=jethro');
define('DB_DATABASE', 'jethro');
define('DB_USERNAME', 'jethro');
define('DB_PASSWORD', 'jethro');

/** @var array<string, string> $jethroEnv */
$jethroEnv = getenv();
foreach ($jethroEnv as $jethroEnvName => $jethroEnvValue) {
	if (!str_starts_with($jethroEnvName, 'JETHRO_CONF_')) continue;
	$jethroConstName = substr($jethroEnvName, strlen('JETHRO_CONF_'));
	if ($jethroConstName === '' || defined($jethroConstName)) continue;
	define($jethroConstName, match ($jethroEnvValue) {
		'true' => true,
		'false' => false,
		default => $jethroEnvValue,
	});
}
unset($jethroEnv, $jethroEnvName, $jethroEnvValue, $jethroConstName);

// ── SMS ─────────────────────────────────────────────────────────────────────
// Point Jethro at an SMS provider by setting JETHRO_CONF_SMS_* in the
// environment (see the loop above); the mock-SMS demo image does exactly that,
// via docker/compose.yml and docker/fly.demo.toml:
//
//   SMS_PROVIDER=5centsmsv5, SMS_5CENTSMS_URL=http://127.0.0.1:8081/smsmockserver/5centsms
//
// That URL is served by the in-image mock server (smsmockserver/, routed by
// devbox.d/nginx/nginx.template). An absolute http://127.0.0.1 URL keeps the
// self-call inside the container instead of going out through the Fly proxy and
// back in — FiveCentSmsV5Provider::fromConstants() resolves a bare '/'-prefixed
// URL against the instance's public BASE_URL, which works but is slower.
// A real deployment sets the real SMS_* constants via Fly secrets; with neither
// set, SMS_PROVIDER falls back to the DB-backed System Configuration setting.

// Jethro shows error details to users when JETHRO_VERSION is 'DEV'.
// Production images default them off; JETHRO_CONF_SHOW_ERROR_DETAILS=true overrides.
if (!defined('SHOW_ERROR_DETAILS')) define('SHOW_ERROR_DETAILS', false);

// Uploaded documents live on the persistent volume; docker/entrypoint.sh
// exports JETHRO_HOME and creates $JETHRO_HOME/docs.
if (!defined('DOCUMENTS_ROOT_PATH')) define('DOCUMENTS_ROOT_PATH', getenv('JETHRO_HOME') . '/docs');
