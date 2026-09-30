#!/usr/bin/env bash
# Installs (idempotently) the CW /ui staff screens on the STAGING server for slot "ui":
#   php-fpm pool cw-ui (www-data) + a name-based Apache vhost (Host: cw-ui.staging.invalid) on the
#   loopback listener 127.0.0.1:8080 that install_api.sh opened, docroot /opt/cw-ui/public.
# The vhost only serves the UI tests and curl on the box itself. The public HTTPS vhost for
# warehouse-staging.floverfy.com is NOT touched here (see enable_https.sh; it stays off until the
# DNS record exists).
# Run from this machine (syncs the repo to /opt/cw-ui first):
#   scripts/remote.sh ui bash deploy/staging/install_ui.sh
set -euo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
if [[ $repo != /opt/cw-ui ]]; then
    echo "install_ui: run from /opt/cw-ui (scripts/remote.sh ui ...), not $repo" >&2
    exit 2
fi
say() { echo "[install_ui] $*"; }

# 0. The loopback listener (Listen 127.0.0.1:8080) belongs to the api vhost; this vhost only adds a
#    name. Without it there is nothing to attach to.
if ! a2query -s cw-api >/dev/null 2>&1; then
    echo "install_ui: the cw-api vhost is not enabled; run install_api.sh (slot api) first" >&2
    exit 1
fi

# 1. Secrets: same modes as install_api.sh (php-fpm reads app.env, never db.env).
chown root:www-data /etc/cw
chmod 0750 /etc/cw
chown root:www-data /etc/cw/app.env
chmod 0640 /etc/cw/app.env
chown root:root /etc/cw/db.env
chmod 0600 /etc/cw/db.env
say "/etc/cw 0750 root:www-data, app.env 0640 root:www-data, db.env 0600 root:root"

# 2. app.env needs db_host/db_port (install_api.sh adds them) and ui_secret_key: the key that seals
#    TOTP secrets and signs CSRF tokens. Generated once if missing; never printed.
php -d display_errors=stderr -r '
require "/opt/cw-ui/vendor/autoload.php";
$c = CW\Config::load();
$app = CW\Config::parseEnvFile($c->appEnvPath);
foreach (["db_host", "db_port"] as $k) {
    if (($app[$k] ?? "") === "") { fwrite(STDERR, "install_ui: app.env has no $k; run install_api.sh first\n"); exit(1); }
}
if (($app["ui_secret_key"] ?? "") === "") {
    CW\Staff\AppEnvFile::set($c->appEnvPath, ["ui_secret_key" => CW\Staff\SecretBox::newKeyBase64()]);
    echo "[install_ui] app.env: ui_secret_key generated (not shown)\n";
} else {
    echo "[install_ui] app.env: ui_secret_key present\n";
}
'

# 2b. The pool serves the schema named in its CW_DB_NAME (the ui slot's test schema): converge
#     cw_app's table grants there if it exists (tests/Support/UiTestCase does the same each run).
served=$(sed -n 's/^env\[CW_DB_NAME\] = //p' "$repo/deploy/staging/php-fpm-cw-ui.conf")
schema_exists=0
CW_SERVED="$served" php -d display_errors=stderr -r '
require "/opt/cw-ui/vendor/autoload.php";
$c = CW\Config::load();
$schema = getenv("CW_SERVED");
$admin = CW\Db::connect($c->dbAdmin()->withDatabase(null));
if ($admin->value("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?", [$schema]) === null) {
    echo "[install_ui] schema $schema does not exist yet (the first test run creates it)\n";
    exit(3);
}
$db = CW\Db::connect($c->dbAdmin()->withDatabase($schema));
$changes = CW\Schema\Grants::apply($db, $schema, (string) $c->appDbUser());
echo "[install_ui] cw_app grants on $schema: " . ($changes === [] ? "unchanged" : count($changes) . " change(s)") . "\n";
' && schema_exists=1 || { rc=$?; [[ $rc == 3 ]] || exit "$rc"; }

# 3. php-fpm pool (+ size-based rotation of its log directory, run hourly)
install -d -o www-data -g www-data -m 0750 /var/log/cw-ui
install -o root -g root -m 0644 "$repo/deploy/staging/logrotate-cw-ui.conf" /etc/cw/logrotate-cw-ui.conf
printf '%s\n' '# CW UI log rotation (deploy/staging/install_ui.sh)' \
    '41 * * * * root /usr/sbin/logrotate -s /var/lib/logrotate/cw-ui.status /etc/cw/logrotate-cw-ui.conf' \
    > /etc/cron.d/cw-ui-logrotate
chmod 0644 /etc/cron.d/cw-ui-logrotate
/usr/sbin/logrotate -d -s /var/lib/logrotate/cw-ui.status /etc/cw/logrotate-cw-ui.conf >/dev/null 2>&1 \
    || { echo "install_ui: logrotate rejects /etc/cw/logrotate-cw-ui.conf" >&2; exit 1; }
say "log rotation: /etc/cw/logrotate-cw-ui.conf, hourly (/etc/cron.d/cw-ui-logrotate)"
install -o root -g root -m 0644 "$repo/deploy/staging/php-fpm-cw-ui.conf" /etc/php/8.3/fpm/pool.d/cw-ui.conf
php-fpm8.3 -t 2>&1 | tail -1
systemctl reload php8.3-fpm
for _ in $(seq 1 50); do [[ -S /run/php/php8.3-fpm-cw-ui.sock ]] && break; sleep 0.1; done
[[ -S /run/php/php8.3-fpm-cw-ui.sock ]] || { echo "install_ui: pool socket missing" >&2; exit 1; }
say "php-fpm pool cw-ui up"

# 4. Apache: modules + the name-based vhost (no Listen line: the api vhost owns the listener)
a2enmod -q proxy proxy_fcgi rewrite setenvif >/dev/null
a2dismod -q remoteip >/dev/null 2>&1 || true
install -o root -g root -m 0644 "$repo/deploy/staging/apache-cw-ui.conf" /etc/apache2/sites-available/cw-ui.conf
a2ensite -q cw-ui >/dev/null
apache2ctl configtest 2>&1 | tail -1
systemctl reload apache2
sleep 1
say "apache vhost cw-ui enabled"

# 5. Checks: loopback only; the api vhost is still the default for :8080; the UI answers by name.
listeners=$(ss -Hltn 'sport = :8080' | awk '{print $4}' | sort -u | tr '\n' ' ')
say "listening on :8080 -> ${listeners}"
if [[ "$listeners" != "127.0.0.1:8080 " ]]; then
    echo "install_ui: port 8080 must be bound to 127.0.0.1 only" >&2
    exit 1
fi
default=$(apache2ctl -S 2>&1 | awk '/^[^ ]/ {cur = $1} cur == "127.0.0.1:8080" && $1 == "default" && $2 == "server" {print $3; exit}')
if [[ $default != cw-api.staging.invalid ]]; then
    echo "install_ui: the default vhost on 127.0.0.1:8080 is '$default', expected cw-api.staging.invalid" >&2
    exit 1
fi
say "default vhost on :8080 is still cw-api.staging.invalid"
ui() { curl -s -o "$2" -D "$3" -w '%{http_code}' -H 'Host: cw-ui.staging.invalid' "http://127.0.0.1:8080$1"; }
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
code=$(ui /ui/assets/app.css "$tmp/css" "$tmp/css.h")
say "GET /ui/assets/app.css -> HTTP $code $(tr -d '\r' < "$tmp/css.h" | sed -n 's/^[Cc]ontent-[Tt]ype: //p' | head -1)"
[[ $code == 200 ]] || { echo "install_ui: expected 200 for app.css" >&2; exit 1; }
if [[ $schema_exists == 1 ]]; then
    code=$(ui /ui/login "$tmp/login" "$tmp/login.h")
    say "GET /ui/login -> HTTP $code"
    [[ $code == 200 ]] || { echo "install_ui: expected 200 for /ui/login" >&2; exit 1; }
    grep -qi "^content-security-policy: default-src 'self'" "$tmp/login.h" \
        || { echo "install_ui: /ui/login has no CSP" >&2; exit 1; }
    code=$(ui /ui/ "$tmp/root" "$tmp/root.h")
    say "GET /ui/ without a session -> HTTP $code"
    [[ $code == 303 ]] || { echo "install_ui: expected 303 to /ui/login" >&2; exit 1; }
else
    say "schema $served is missing: skipped the page checks (run the tests once, then re-run this)"
fi
say done
