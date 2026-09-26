#!/usr/bin/env bash
# Installs (idempotently) the CW /v1 API on the STAGING server for slot "api":
#   php-fpm pool cw-api (www-data) + Apache vhost on 127.0.0.1:8080 ONLY, docroot /opt/cw-api/public.
# Run from this machine (syncs the repo to /opt/cw-api first):
#   scripts/remote.sh api bash deploy/staging/install_api.sh
set -euo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
if [[ $repo != /opt/cw-api ]]; then
    echo "install_api: run from /opt/cw-api (scripts/remote.sh api ...), not $repo" >&2
    exit 2
fi
say() { echo "[install_api] $*"; }

# 1. Secrets: php-fpm (www-data) may read app.env (cw_app login), never db.env (doadmin).
chown root:www-data /etc/cw
chmod 0750 /etc/cw
chown root:www-data /etc/cw/app.env
chmod 0640 /etc/cw/app.env
chown root:root /etc/cw/db.env
chmod 0600 /etc/cw/db.env
say "/etc/cw 0750 root:www-data, app.env 0640 root:www-data, db.env 0600 root:root"

# 2. app.env needs the database host/port (the web process never opens db.env). Values are
#    copied from db.env and never printed.
php -d display_errors=stderr -r '
require "/opt/cw-api/vendor/autoload.php";
$c = CW\Config::load();
$s = $c->dbAdmin();
$app = CW\Config::parseEnvFile($c->appEnvPath);
$add = "";
foreach (["db_host" => $s->host, "db_port" => (string) $s->port] as $k => $v) {
    if (($app[$k] ?? null) === null) { $add .= $k . "=" . $v . "\n"; }
    elseif ($app[$k] !== $v) { fwrite(STDERR, "app.env $k differs from db.env; leaving it\n"); }
}
if ($add !== "") {
    $text = file_get_contents($c->appEnvPath);
    $sep = ($text !== "" && substr($text, -1) !== "\n") ? "\n" : "";
    file_put_contents($c->appEnvPath, $sep . $add, FILE_APPEND | LOCK_EX);
    echo "[install_api] app.env: db_host/db_port added\n";
} else {
    echo "[install_api] app.env: db_host/db_port present\n";
}
'

# 2b. The pool serves the schema named in its CW_DB_NAME (the api slot's test schema): converge
#     cw_app's table grants there if it exists (tests/Support/ApiTestCase does the same each run).
served=$(sed -n 's/^env\[CW_DB_NAME\] = //p' "$repo/deploy/staging/php-fpm-cw-api.conf")
CW_SERVED="$served" php -d display_errors=stderr -r '
require "/opt/cw-api/vendor/autoload.php";
$c = CW\Config::load();
$schema = getenv("CW_SERVED");
$admin = CW\Db::connect($c->dbAdmin()->withDatabase(null));
if ($admin->value("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?", [$schema]) === null) {
    echo "[install_api] schema $schema does not exist yet (the first test run creates it)\n";
    exit(0);
}
$db = CW\Db::connect($c->dbAdmin()->withDatabase($schema));
$changes = CW\Schema\Grants::apply($db, $schema, (string) $c->appDbUser());
echo "[install_api] cw_app grants on $schema: " . ($changes === [] ? "unchanged" : count($changes) . " change(s)") . "\n";
'

# 3. php-fpm pool (+ size-based rotation of its log directory, run hourly: R18)
install -d -o www-data -g www-data -m 0750 /var/log/cw-api
install -o root -g root -m 0644 "$repo/deploy/staging/logrotate-cw-api.conf" /etc/cw/logrotate-cw-api.conf
printf '%s\n' '# CW API log rotation (deploy/staging/install_api.sh, R18)' \
    '23 * * * * root /usr/sbin/logrotate -s /var/lib/logrotate/cw-api.status /etc/cw/logrotate-cw-api.conf' \
    > /etc/cron.d/cw-api-logrotate
chmod 0644 /etc/cron.d/cw-api-logrotate
/usr/sbin/logrotate -d -s /var/lib/logrotate/cw-api.status /etc/cw/logrotate-cw-api.conf >/dev/null 2>&1 \
    || { echo "install_api: logrotate rejects /etc/cw/logrotate-cw-api.conf" >&2; exit 1; }
say "log rotation: /etc/cw/logrotate-cw-api.conf, hourly (/etc/cron.d/cw-api-logrotate)"
install -o root -g root -m 0644 "$repo/deploy/staging/php-fpm-cw-api.conf" /etc/php/8.3/fpm/pool.d/cw-api.conf
php-fpm8.3 -t 2>&1 | tail -1
systemctl reload php8.3-fpm
for _ in $(seq 1 50); do [[ -S /run/php/php8.3-fpm-cw-api.sock ]] && break; sleep 0.1; done
[[ -S /run/php/php8.3-fpm-cw-api.sock ]] || { echo "install_api: pool socket missing" >&2; exit 1; }
say "php-fpm pool cw-api up"

# 4. Apache: modules + loopback-only vhost
a2enmod -q proxy proxy_fcgi rewrite setenvif >/dev/null
a2dismod -q remoteip >/dev/null 2>&1 || true
install -o root -g root -m 0644 "$repo/deploy/staging/apache-cw-api.conf" /etc/apache2/sites-available/cw-api.conf
a2ensite -q cw-api >/dev/null
apache2ctl configtest 2>&1 | tail -1
systemctl reload apache2
sleep 1
say "apache vhost cw-api enabled"

# 5. Checks: bound to loopback only; a call without a key is refused with the JSON envelope.
listeners=$(ss -Hltn 'sport = :8080' | awk '{print $4}' | sort -u | tr '\n' ' ')
say "listening on :8080 -> ${listeners}"
if [[ "$listeners" != "127.0.0.1:8080 " ]]; then
    echo "install_api: port 8080 must be bound to 127.0.0.1 only" >&2
    exit 1
fi
code=$(curl -s -o /tmp/cw-api-check.json -w '%{http_code}' http://127.0.0.1:8080/v1/health)
say "GET /v1/health without a key -> HTTP $code $(cat /tmp/cw-api-check.json)"
rm -f /tmp/cw-api-check.json
[[ $code == 401 ]] || { echo "install_api: expected 401" >&2; exit 1; }
say done
