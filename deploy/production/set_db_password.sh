#!/usr/bin/env bash
# Writes /etc/cw/db.env on the PRODUCTION box: the database admin login (doadmin of db-mysql-lon1-37047) with the password typed
# here, at the first install and after a reset in DigitalOcean (Databases > db-mysql-lon1-37047 > Users & Databases > doadmin >
# Reset password; docs/ops.md "The production server", "Secrets"). The password is never shown, never on a command line, never
# written anywhere but db.env (0600 root). It is checked against the database first: one the database refuses changes nothing.
# Run as root ON the production box, in a terminal or the DigitalOcean console (never through a chat):
#
#   bash /opt/cw-prod/deploy/production/set_db_password.sh
set -euo pipefail
# No trace: `bash -x` would print the password in the comparisons below.
{ set +x; } 2>/dev/null
umask 077
refuse() { echo "set_db_password: REFUSING: $*" >&2; exit 1; }
[[ $(id -u) -eq 0 ]] || refuse "run as root"
[[ $(hostname) == cw-prod-lon1-01 ]] || refuse "this box is $(hostname), not cw-prod-lon1-01"
here=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ -f $here/vendor/autoload.php ]] || refuse "$here has no vendor/autoload.php: run it from /opt/cw-prod or /opt/cw-prod-release"
host=private-db-mysql-lon1-37047-do-user-39758664-0.m.db.ondigitalocean.com

read -rsp "doadmin password (DigitalOcean > Databases > db-mysql-lon1-37047 > Users & Databases): " P
echo
read -rsp "the same password again: " P2
echo
if [[ -z $P || $P != "$P2" ]]; then
    unset P P2
    echo "the two did not match (or were empty): nothing changed. Run it again." >&2
    exit 1
fi
unset P2

# /etc/cw is 0750 root:www-data once enable_https.sh ran (php-fpm reads app.env): made here only when it is missing, never changed.
[[ -d /etc/cw ]] || install -d -m 0750 -o root -g root /etc/cw
tmp=$(mktemp /etc/cw/.db.env.XXXXXX)
trap 'rm -f "$tmp"' EXIT
{
    echo "username = doadmin"
    printf 'password = %s\n' "$P"
    echo "host = $host"
    echo "port = 25060"
    echo "database = defaultdb"
    echo "sslmode = REQUIRED"
} > "$tmp"
unset P
chmod 0600 "$tmp"
# The check reads the new file alone (no app.env, no environment that could point it elsewhere) and only production's host.
if ! env -i PATH="$PATH" CW_NEW_DB_ENV="$tmp" CW_HERE="$here" CW_WANT_HOST="$host" php -r '
require getenv("CW_HERE") . "/vendor/autoload.php";
$admin = CW\Config::fromArrays(CW\Config::parseEnvFile(getenv("CW_NEW_DB_ENV")))->dbAdmin();
if ($admin->host !== getenv("CW_WANT_HOST") || $admin->port !== 25060) { exit(1); }
CW\Db::connect($admin)->value("SELECT 1");' >/dev/null 2>&1; then
    echo "the database did not accept this password: nothing changed. Copy it again from DigitalOcean and run this again." >&2
    exit 1
fi
mv "$tmp" /etc/cw/db.env
trap - EXIT
echo "saved and checked: /etc/cw/db.env (0600 root)"
