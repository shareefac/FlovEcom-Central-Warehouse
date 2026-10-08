#!/usr/bin/env bash
# Re-installs the PUBLIC web server settings of CW staging (warehouse-staging.floverfy.com) from the repo, on a box where
# enable_https.sh has already switched the public vhost on: the php-fpm pool cw-web (php-fpm-cw-web.conf), the HTTPS vhost
# (apache-cw-https.conf) and mod_http2. Idempotent. It changes nothing else (no certificate, no code, no schema: the code
# goes out with install_cron.sh) and puts the previous files back if php-fpm or Apache refuses the new ones.
#
#   scripts/remote.sh <slot> bash deploy/staging/install_web.sh [--check]
#
# --check  shows what would change (diff against the installed files) and installs nothing.
# After the reloads it checks, through the box's own listener: /ui/login 200 over HTTP/2, an asset's versioned URL cached
# for a year, /favicon.ico answered by Apache (404), /v1/health without a key 401, and the pool's idle workers.
set -euo pipefail

NAME=warehouse-staging.floverfy.com
POOL=/etc/php/8.3/fpm/pool.d/cw-web.conf
SITE=/etc/apache2/sites-available/cw-https.conf
check=0
for a in "$@"; do
    case $a in
        --check) check=1 ;;
        *) echo "usage: install_web.sh [--check]" >&2; exit 2 ;;
    esac
done
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
if [[ $repo != /opt/cw-* || $repo == /opt/cw-staging ]]; then
    echo "install_web: run from a slot copy /opt/cw-<slot> (scripts/remote.sh <slot> ...), not $repo" >&2
    exit 2
fi
say() { echo "[install_web] $*"; }
a2query -s cw-https >/dev/null 2>&1 && [[ -f $POOL ]] \
    || { echo "install_web: the public vhost is not on yet: run enable_https.sh (it installs both the first time)" >&2; exit 1; }

say "changes (installed -> repo):"
diff -u "$POOL" "$repo/deploy/staging/php-fpm-cw-web.conf" | sed 's/^/[install_web]   /' || true
diff -u "$SITE" "$repo/deploy/staging/apache-cw-https.conf" | sed 's/^/[install_web]   /' || true
if [[ $check == 1 ]]; then
    say "--check: nothing installed"
    exit 0
fi

backup=$(mktemp -d /root/.cw-web-backup.XXXXXX)
cp -p "$POOL" "$backup/cw-web.conf"
cp -p "$SITE" "$backup/cw-https.conf"
restore() {
    cp -p "$backup/cw-web.conf" "$POOL"
    cp -p "$backup/cw-https.conf" "$SITE"
    echo "install_web: REFUSED by php-fpm or Apache: the previous files are back ($backup), nothing was reloaded" >&2
    exit 1
}

a2enmod -q http2 >/dev/null
install -o root -g root -m 0644 "$repo/deploy/staging/php-fpm-cw-web.conf" "$POOL"
install -o root -g root -m 0644 "$repo/deploy/staging/apache-cw-https.conf" "$SITE"
php-fpm8.3 -t >/dev/null 2>&1 || restore
apache2ctl configtest >/dev/null 2>&1 || { a2dismod -q http2 >/dev/null 2>&1 || true; restore; }
systemctl reload php8.3-fpm
systemctl reload apache2
say "installed $POOL and $SITE, http2 enabled, php-fpm and Apache reloaded (previous files in $backup)"
sleep 2

# Checks through the local listener (the name is pinned to 127.0.0.1: DNS is not involved).
fail=0
tls() { curl -s -o /dev/null --resolve "$NAME:443:127.0.0.1" "$@"; }
v=$(tls -w '%{http_code} %{http_version}' --http2 "https://$NAME/ui/login")
say "GET /ui/login -> $v (want 200 2)"; [[ $v == "200 2" ]] || fail=1
asset=$(curl -s --resolve "$NAME:443:127.0.0.1" "https://$NAME/ui/login" | grep -o '/ui/assets/app\.css?v=[0-9a-f]*' | head -1 || true)
cache=$(curl -s -D - -o /dev/null --resolve "$NAME:443:127.0.0.1" "https://$NAME${asset:-/ui/assets/app.css}" | tr -d '\r' | sed -n 's/^[Cc]ache-[Cc]ontrol: //p')
say "GET ${asset:-(no versioned asset link)} -> Cache-Control: $cache (want public, max-age=31536000, immutable)"
[[ -n $asset && $cache == 'public, max-age=31536000, immutable' ]] || fail=1
v=$(tls -w '%{http_code}' "https://$NAME/favicon.ico"); say "GET /favicon.ico -> $v (want 404)"; [[ $v == 404 ]] || fail=1
v=$(tls -w '%{http_code}' "https://$NAME/v1/health"); say "GET /v1/health (no key) -> $v (want 401)"; [[ $v == 401 ]] || fail=1
say "cw-web workers now: $(pgrep -fc 'php-fpm: pool cw-web' || true) (want 2 at start, at least 1 idle later)"
exit $fail
