#!/usr/bin/env bash
# SWITCHES ON the public HTTPS vhost for warehouse.floverfy.com (UI + API) on the PRODUCTION server (cw-prod-lon1-01),
# and gets its Let's Encrypt certificate: the staging script (deploy/staging/enable_https.sh) with the production names and
# guards. NOT run by anything automatically: a person runs it once the DNS record exists.
#
#   bash /opt/cw-prod-release/deploy/production/enable_https.sh [--email you@example.com] [--check]
#
# --email is optional: Let's Encrypt stopped sending expiry e-mails in 2025 and renewal is automatic,
# so without it the account is registered with no address (no personal address leaves the box).
#
# It refuses (changing nothing) unless ALL of these hold:
#   * the name warehouse.floverfy.com resolves to 129.212.162.243 (the droplet's reserved address) and to nothing else,
#   * DigitalOcean's metadata says that reserved address is this droplet's (so the http-01 challenge lands here; a reserved
#     address is not on the droplet's own network interface),
#   * /opt/cw-prod holds the code with the UI (run install_cron.sh first), app.env names cw_prod and carries no staging
#     marker (environment=staging, read as the application reads it),
# Then it gives php-fpm read access to app.env (0640 root:www-data; db.env stays 0600 root) and generates ui_secret_key once,
# as staging's install_api.sh and install_ui.sh do, before the pool starts.
#   * /etc/cw/initial_staff.txt is gone (it holds one-time passwords and TOTP seeds in clear) and no
#     placeholder account (an e-mail under .invalid, e.g. the mapping_lead the first load ran as) is
#     active: once the site is public, either would be a working second identity (docs/ops.md).
# --check runs those guards and stops (no install, no certificate).
#
# Order matters: the port-80 challenge vhost first, then the certificate, then the HTTPS vhost
# (which cannot load without the certificate files). Renewal is certbot's own systemd timer; a
# deploy hook reloads Apache.
#
# After it: create the first staff login with bin/create_staff.php (provision.sh opened 80 and 443 in ufw; open them in the
# DigitalOcean cloud firewall too if one is ever attached).
set -euo pipefail
# CW_DEPLOY_ROOT: tests/Unit/ProductionDeployBehaviourTest.php runs this script against a temporary tree, every path below
# under $R, with its stub commands on PATH. A root is taken only when it holds the marker .cw-deploy-test-root, this is
# not a CW server (the kernel's own hostname, which no stub can change; unreadable or empty: refused), and the stub
# systemctl comes first on PATH. Unset, as it always is on the servers, $R is empty: every path is the real one.
# What goes INTO a file for the box (the cron line) names the real paths.
R=${CW_DEPLOY_ROOT:-/}
R=${R%/}
if [[ -n $R ]]; then
    kernel_host=
    read -r kernel_host 2>/dev/null < /proc/sys/kernel/hostname || true
    [[ $R =~ ^/[A-Za-z0-9/._-]+$ && -f $R/.cw-deploy-test-root && -n $kernel_host ]] \
        && [[ ! $kernel_host =~ ^(cw-prod-lon1-01|vpg-ded-lon1-01|FlovEcom-Central-Warehouse)$ ]] \
        && grep -qsx '# cw-deploy-stub' "$(type -P systemctl)" \
        || { echo "enable_https: CW_DEPLOY_ROOT=$R is not a test tree (its marker, the stub commands first on PATH, never a CW server)" >&2; exit 2; }
fi

NAME=warehouse.floverfy.com
ADDR=129.212.162.243
WEBROOT=$R/var/lib/cw-acme
src=$R/opt/cw-prod-release
live=$R/opt/cw-prod
etc=$R/etc/cw
app_env=$etc/app.env
db_env=$etc/db.env
email=
check=0
while [[ $# -gt 0 ]]; do
    case $1 in
        --email) email=${2:-}; shift 2 || { echo "enable_https: --email needs a value" >&2; exit 2; } ;;
        --check) check=1; shift ;;
        *) echo "usage: enable_https.sh [--email <address>] [--check]" >&2; exit 2 ;;
    esac
done
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
if [[ $repo != "$src" ]]; then
    echo "enable_https: run from the release copy $src (push_code.sh), not $repo" >&2
    exit 2
fi
[[ $(hostname) == cw-prod-lon1-01 ]] || { echo "enable_https: this box is $(hostname), not cw-prod-lon1-01" >&2; exit 2; }
if [[ -n $email && ! $email =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; then
    echo "enable_https: --email must be an e-mail address" >&2
    exit 2
fi
say() { echo "[enable_https] $*"; }
refuse() { echo "enable_https: REFUSING: $*" >&2; exit 1; }

# 1. Guards: DNS, address, code, UI.
resolved=$(getent ahostsv4 "$NAME" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ' || true)
[[ -n $resolved ]] || refuse "$NAME does not resolve yet: create the DNS record ($NAME A $ADDR, DNS only, not proxied)"
[[ "$resolved" == "$ADDR " ]] || refuse "$NAME resolves to '${resolved% }', expected only $ADDR"
say "DNS: $NAME -> $ADDR"
meta=http://169.254.169.254/metadata/v1/reserved_ip/ipv4
[[ $(curl -s --max-time 5 "$meta/active" || true) == true && $(curl -s --max-time 5 "$meta/ip_address" || true) == "$ADDR" ]] \
    || refuse "DigitalOcean's metadata does not give this droplet the reserved address $ADDR (the challenge would land elsewhere)"
[[ -f $live/public/index.php && -f $live/public/ui/assets/app.css ]] \
    || refuse "$live has no UI yet: run install_cron.sh (it copies the code there)"
# The schema as the web service's CW\Config reads it (app.env only, no CW_* override); the marker as CW\Ui\Kernel reads it.
schema=$(php -r 'require $argv[1] . "/vendor/autoload.php"; echo CW\Config::loadApp(["CW_APP_ENV" => $argv[2]])->appDbName();' -- "$live" "$app_env" 2>/dev/null || true)
[[ $schema == cw_prod ]] || refuse "app.env db_name is '${schema}', expected cw_prod (the public vhost must never serve another schema)"
marker=$(php -r 'require $argv[1] . "/vendor/autoload.php";
    echo strtolower(trim((string) CW\Config::loadApp(["CW_APP_ENV" => $argv[2]])->appFile(CW\Ops\TestRefPurge::ENV_KEY)));' -- "$live" "$app_env" 2>/dev/null) \
    || refuse "cannot read $app_env"
[[ $marker != staging ]] || refuse "$app_env says environment=staging: never on production"
[[ ! -e $etc/initial_staff.txt ]] \
    || refuse "$etc/initial_staff.txt still holds one-time passwords and TOTP seeds in clear: hand them over, then 'shred -u $etc/initial_staff.txt'"
placeholders=$(php -r 'require $argv[1] . "/vendor/autoload.php";
    $db = CW\Db::connect(CW\Config::load(getenv() + ["CW_DB_ENV" => $argv[2], "CW_APP_ENV" => $argv[3]])->dbApp());
    echo $db->value("SELECT COUNT(*) FROM staff_user WHERE is_active = 1 AND email LIKE ?", ["%.invalid"]);' -- "$live" "$db_env" "$app_env" 2>/dev/null || echo unknown)
[[ $placeholders == 0 ]] \
    || refuse "placeholder staff accounts active: ${placeholders} (e-mail under .invalid): 'php bin/reset_staff.php --email=<it> --deactivate' in $live"
say "guards passed (code in $live, schema $schema)"
if [[ $check == 1 ]]; then
    say "--check: nothing installed"
    exit 0
fi

# 2. Packages and modules.
if ! command -v certbot >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq certbot >/dev/null
    say "certbot installed"
fi
a2enmod -q ssl headers rewrite proxy proxy_fcgi setenvif http2 >/dev/null
a2dismod -q remoteip >/dev/null 2>&1 || true

# 2b. Secrets and the UI key (staging: install_api.sh, install_ui.sh). php-fpm (www-data) may read app.env (the cw_app login,
#     the database's host and port, ui_secret_key), never db.env (doadmin). ui_secret_key seals TOTP secrets and signs CSRF
#     tokens: generated once if missing, never printed, never replaced.
chown root:www-data "$etc"
chmod 0750 "$etc"
chown root:www-data "$app_env"
chmod 0640 "$app_env"
chown root:root "$db_env"
chmod 0600 "$db_env"
say "$etc 0750 root:www-data, app.env 0640 root:www-data, db.env 0600 root:root"
php -d display_errors=stderr -r '
require $argv[1] . "/vendor/autoload.php";
$path = CW\Config::load(getenv() + ["CW_APP_ENV" => $argv[2]])->appEnvPath;
$app = CW\Config::parseEnvFile($path);
foreach (["db_user", "db_password", "db_name", "db_host", "db_port"] as $k) {
    if (($app[$k] ?? "") === "") { fwrite(STDERR, "enable_https: app.env has no $k (bin/setup_staging.php writes it)\n"); exit(1); }
}
if (($app["ui_secret_key"] ?? "") === "") {
    CW\Staff\AppEnvFile::set($path, ["ui_secret_key" => CW\Staff\SecretBox::newKeyBase64()]);
    echo "[enable_https] app.env: ui_secret_key generated (not shown)\n";
} else {
    echo "[enable_https] app.env: ui_secret_key present\n";
}
' -- "$live" "$app_env" || { echo "enable_https: app.env is not ready for the web" >&2; exit 1; }
stat -c '%a %U:%G' "$app_env" | grep -qx '640 root:www-data' || { echo "enable_https: app.env lost its mode or group" >&2; exit 1; }

# 3. php-fpm pool (+ hourly size-based log rotation).
install -d -o www-data -g www-data -m 0750 "$R/var/log/cw-web"
install -o root -g root -m 0644 "$repo/deploy/production/logrotate-cw-web.conf" "$etc/logrotate-cw-web.conf"
printf '%s\n' '# CW public web log rotation (deploy/production/enable_https.sh)' \
    '47 * * * * root /usr/sbin/logrotate -s /var/lib/logrotate/cw-web.status /etc/cw/logrotate-cw-web.conf' \
    > "$R/etc/cron.d/cw-web-logrotate"
chmod 0644 "$R/etc/cron.d/cw-web-logrotate"
install -o root -g root -m 0644 "$repo/deploy/production/php-fpm-cw-web.conf" "$R/etc/php/8.3/fpm/pool.d/cw-web.conf"
php-fpm8.3 -t 2>&1 | tail -1
systemctl reload php8.3-fpm
sock=$R/run/php/php8.3-fpm-cw-web.sock
for _ in $(seq 1 50); do [[ -S $sock ]] && break; sleep 0.1; done
[[ -S $sock ]] || { echo "enable_https: pool socket missing" >&2; exit 1; }
say "php-fpm pool cw-web up"

# 4. Port 80: challenge + redirect; then the certificate.
install -d -o root -g root -m 0755 "$WEBROOT/.well-known/acme-challenge"
install -o root -g root -m 0644 "$repo/deploy/production/apache-cw-hardening.conf" "$R/etc/apache2/conf-available/cw-hardening.conf"
a2enconf -q cw-hardening >/dev/null
install -o root -g root -m 0644 "$repo/deploy/production/apache-cw-acme.conf" "$R/etc/apache2/sites-available/cw-acme.conf"
a2ensite -q cw-acme >/dev/null
apache2ctl configtest 2>&1 | tail -1
systemctl reload apache2
account=(--register-unsafely-without-email)
[[ -n $email ]] && account=(--email "$email" --no-eff-email)
certbot certonly --webroot -w "$WEBROOT" -d "$NAME" "${account[@]}" --agree-tos \
    --non-interactive --keep-until-expiring --deploy-hook 'systemctl reload apache2'
[[ -s $R/etc/letsencrypt/live/$NAME/fullchain.pem ]] || { echo "enable_https: no certificate was issued" >&2; exit 1; }
say "certificate for $NAME in place (renewal: certbot's systemd timer)"

# 5. Port 443: the UI + API vhost.
install -o root -g root -m 0644 "$repo/deploy/production/apache-cw-https.conf" "$R/etc/apache2/sites-available/cw-https.conf"
a2ensite -q cw-https >/dev/null
apache2ctl configtest 2>&1 | tail -1
systemctl reload apache2
sleep 1

# 6. Checks through the local listener (the name is pinned to 127.0.0.1, so DNS is not involved).
tls() { curl -s -o /dev/null -w '%{http_code}' --resolve "$NAME:443:127.0.0.1" "https://$NAME$1"; }
code=$(tls /ui/login);  say "GET https://$NAME/ui/login -> HTTP $code";   [[ $code == 200 ]] || { echo "enable_https: expected 200" >&2; exit 1; }
code=$(tls /v1/health); say "GET https://$NAME/v1/health (no key) -> HTTP $code"; [[ $code == 401 ]] || { echo "enable_https: expected 401" >&2; exit 1; }
code=$(curl -s -o /dev/null -w '%{http_code}' --resolve "$NAME:80:127.0.0.1" "http://$NAME/ui/login")
say "GET http://$NAME/ui/login -> HTTP $code (redirect to https)"; [[ $code == 301 ]] || { echo "enable_https: expected 301" >&2; exit 1; }
say "done. Create the first staff login with bin/create_staff.php in $live"
