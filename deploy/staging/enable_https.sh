#!/usr/bin/env bash
# SWITCHES ON the public HTTPS vhost for warehouse-staging.floverfy.com (UI + API) on the STAGING
# server, and gets its Let's Encrypt certificate. NOT run by anything automatically: a person runs
# it once the DNS record exists.
#
#   scripts/remote.sh <slot> bash deploy/staging/enable_https.sh --email you@example.com [--check]
#
# It refuses (changing nothing) unless ALL of these hold:
#   * the name warehouse-staging.floverfy.com resolves to 46.101.55.135 and to nothing else,
#   * this box owns that address (so the http-01 challenge lands here),
#   * /opt/cw-staging holds the code with the UI (run install_cron.sh first),
#   * the UI's loopback vhost is installed (install_ui.sh) and cw_staging answers the UI,
#   * /etc/cw/initial_staff.txt is gone (it holds one-time passwords and TOTP seeds in clear) and no
#     placeholder account (an e-mail under .invalid, e.g. the mapping_lead the first load ran as) is
#     active: once the site is public, either would be a working second identity (docs/ops.md).
# --check runs those guards and stops (no install, no certificate).
#
# Order matters: the port-80 challenge vhost first, then the certificate, then the HTTPS vhost
# (which cannot load without the certificate files). Renewal is certbot's own systemd timer; a
# deploy hook reloads Apache.
#
# After it: open TCP 80 and 443 in the DigitalOcean cloud firewall if one is attached (ufw is
# inactive on this box), and create the first staff login with bin/create_staff.php.
set -euo pipefail

NAME=warehouse-staging.floverfy.com
ADDR=46.101.55.135
WEBROOT=/var/lib/cw-acme
email=
check=0
while [[ $# -gt 0 ]]; do
    case $1 in
        --email) email=${2:-}; shift 2 || { echo "enable_https: --email needs a value" >&2; exit 2; } ;;
        --check) check=1; shift ;;
        *) echo "usage: enable_https.sh --email <address> [--check]" >&2; exit 2 ;;
    esac
done
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
if [[ $repo != /opt/cw-* || $repo == /opt/cw-staging ]]; then
    echo "enable_https: run from a slot copy /opt/cw-<slot> (scripts/remote.sh <slot> ...), not $repo" >&2
    exit 2
fi
if [[ $check == 0 && ! $email =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; then
    echo "enable_https: --email <address> is required (Let's Encrypt expiry notices)" >&2
    exit 2
fi
say() { echo "[enable_https] $*"; }
refuse() { echo "enable_https: REFUSING: $*" >&2; exit 1; }

# 1. Guards: DNS, address, code, UI.
resolved=$(getent ahostsv4 "$NAME" 2>/dev/null | awk '{print $1}' | sort -u | tr '\n' ' ' || true)
[[ -n $resolved ]] || refuse "$NAME does not resolve yet: create the DNS record ($NAME A $ADDR, DNS only, not proxied)"
[[ "$resolved" == "$ADDR " ]] || refuse "$NAME resolves to '${resolved% }', expected only $ADDR"
say "DNS: $NAME -> $ADDR"
ip -4 -o addr show | awk '{print $4}' | cut -d/ -f1 | grep -qx "$ADDR" \
    || refuse "this box does not own $ADDR (the challenge would land elsewhere)"
[[ -f /opt/cw-staging/public/index.php && -f /opt/cw-staging/public/ui/assets/app.css ]] \
    || refuse "/opt/cw-staging has no UI yet: run install_cron.sh (it copies the code there)"
a2query -s cw-ui >/dev/null 2>&1 || refuse "the loopback UI vhost is not installed: run install_ui.sh first"
schema=$(php -r 'require "/opt/cw-staging/vendor/autoload.php"; echo CW\Config::loadApp(["CW_APP_ENV" => "/etc/cw/app.env"])->appDbName();' 2>/dev/null || true)
[[ $schema == cw_staging ]] || refuse "app.env db_name is '${schema}', expected cw_staging (the public vhost must never serve a test schema)"
[[ ! -e /etc/cw/initial_staff.txt ]] \
    || refuse "/etc/cw/initial_staff.txt still holds one-time passwords and TOTP seeds in clear: hand them over, then 'shred -u /etc/cw/initial_staff.txt'"
placeholders=$(php -r 'require "/opt/cw-staging/vendor/autoload.php"; $db = CW\Db::connect(CW\Config::load()->dbApp());
    echo $db->value("SELECT COUNT(*) FROM staff_user WHERE is_active = 1 AND email LIKE ?", ["%.invalid"]);' 2>/dev/null || echo unknown)
[[ $placeholders == 0 ]] \
    || refuse "placeholder staff accounts active: ${placeholders} (e-mail under .invalid): 'php bin/reset_staff.php --email=<it> --deactivate' in /opt/cw-staging"
say "guards passed (code in /opt/cw-staging, schema $schema)"
if [[ $check == 1 ]]; then
    say "--check: nothing installed"
    exit 0
fi

# 2. Packages and modules.
if ! command -v certbot >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq certbot >/dev/null
    say "certbot installed"
fi
a2enmod -q ssl headers rewrite proxy proxy_fcgi setenvif >/dev/null
a2dismod -q remoteip >/dev/null 2>&1 || true

# 3. php-fpm pool (+ hourly size-based log rotation).
install -d -o www-data -g www-data -m 0750 /var/log/cw-web
install -o root -g root -m 0644 "$repo/deploy/staging/logrotate-cw-web.conf" /etc/cw/logrotate-cw-web.conf
printf '%s\n' '# CW public web log rotation (deploy/staging/enable_https.sh)' \
    '47 * * * * root /usr/sbin/logrotate -s /var/lib/logrotate/cw-web.status /etc/cw/logrotate-cw-web.conf' \
    > /etc/cron.d/cw-web-logrotate
chmod 0644 /etc/cron.d/cw-web-logrotate
install -o root -g root -m 0644 "$repo/deploy/staging/php-fpm-cw-web.conf" /etc/php/8.3/fpm/pool.d/cw-web.conf
php-fpm8.3 -t 2>&1 | tail -1
systemctl reload php8.3-fpm
for _ in $(seq 1 50); do [[ -S /run/php/php8.3-fpm-cw-web.sock ]] && break; sleep 0.1; done
[[ -S /run/php/php8.3-fpm-cw-web.sock ]] || { echo "enable_https: pool socket missing" >&2; exit 1; }
say "php-fpm pool cw-web up"

# 4. Port 80: challenge + redirect; then the certificate.
install -d -o root -g root -m 0755 "$WEBROOT/.well-known/acme-challenge"
install -o root -g root -m 0644 "$repo/deploy/staging/apache-cw-hardening.conf" /etc/apache2/conf-available/cw-hardening.conf
a2enconf -q cw-hardening >/dev/null
install -o root -g root -m 0644 "$repo/deploy/staging/apache-cw-acme.conf" /etc/apache2/sites-available/cw-acme.conf
a2ensite -q cw-acme >/dev/null
apache2ctl configtest 2>&1 | tail -1
systemctl reload apache2
certbot certonly --webroot -w "$WEBROOT" -d "$NAME" --email "$email" --agree-tos --no-eff-email \
    --non-interactive --keep-until-expiring --deploy-hook 'systemctl reload apache2'
[[ -s /etc/letsencrypt/live/$NAME/fullchain.pem ]] || { echo "enable_https: no certificate was issued" >&2; exit 1; }
say "certificate for $NAME in place (renewal: certbot's systemd timer)"

# 5. Port 443: the UI + API vhost.
install -o root -g root -m 0644 "$repo/deploy/staging/apache-cw-https.conf" /etc/apache2/sites-available/cw-https.conf
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
say "done. Open TCP 80/443 in the cloud firewall if one is attached; create staff with bin/create_staff.php"
