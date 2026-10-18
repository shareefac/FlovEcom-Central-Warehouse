#!/usr/bin/env bash
# Prepares the CW PRODUCTION server (cw-prod-lon1-01: Ubuntu 24.04, DigitalOcean LON1; reserved address 129.212.162.243,
# warehouse.floverfy.com) with what the staging box runs (docs/ops.md "The production server"): Apache with the event MPM
# and php-fpm 8.3 (no mod_php), the PHP extensions CW uses, composer, the MySQL client, certbot, and the tools the other
# scripts need (rsync, e2fsprogs for chattr). Unlike staging it also switches the host firewall on (ufw: SSH, 80, 443 only)
# and lets Ubuntu install security updates by itself (unattended-upgrades). Idempotent. Run as root ON the production box:
#
#   bash /opt/cw-prod-release/deploy/production/provision.sh [--check]
#
# --check  lists what is missing and changes nothing.
# It installs no CW code, no vhost, no cron and touches no database: push_code.sh, install_cron.sh, install_file_store.sh and
# enable_https.sh do those, in that order.
set -euo pipefail
# CW_DEPLOY_ROOT: tests/Unit/ProductionDeployBehaviourTest.php runs this script against a temporary tree, every path below
# under $R, with its stub commands on PATH. A root is taken only when it holds the marker .cw-deploy-test-root, this is
# not a CW server (the kernel's own hostname, which no stub can change; unreadable or empty: refused), and the stub
# systemctl comes first on PATH. Unset, as it always is on the servers, $R is empty: every path is the real one.
R=${CW_DEPLOY_ROOT:-/}
R=${R%/}
if [[ -n $R ]]; then
    kernel_host=
    read -r kernel_host 2>/dev/null < /proc/sys/kernel/hostname || true
    [[ $R =~ ^/[A-Za-z0-9/._-]+$ && -f $R/.cw-deploy-test-root && -n $kernel_host ]] \
        && [[ ! $kernel_host =~ ^(cw-prod-lon1-01|vpg-ded-lon1-01|FlovEcom-Central-Warehouse)$ ]] \
        && grep -qsx '# cw-deploy-stub' "$(type -P systemctl)" \
        || { echo "provision: CW_DEPLOY_ROOT=$R is not a test tree (its marker, the stub commands first on PATH, never a CW server)" >&2; exit 2; }
fi

say() { echo "[provision] $*"; }
refuse() { echo "provision: REFUSING: $*" >&2; exit 1; }

check=0
for a in "$@"; do
    case $a in
        --check) check=1 ;;
        *) echo "usage: provision.sh [--check]" >&2; exit 2 ;;
    esac
done
[[ $(id -u) -eq 0 ]] || refuse "run as root"
. "$R/etc/os-release"
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 24.04 ]] || refuse "this is ${PRETTY_NAME:-an unknown system}, not Ubuntu 24.04"
[[ $(hostname) == cw-prod-lon1-01 ]] || refuse "this box is $(hostname), not cw-prod-lon1-01 (the production server)"

PACKAGES=(apache2 php8.3-fpm php8.3-cli php8.3-bcmath php8.3-curl php8.3-intl php8.3-mbstring php8.3-mysql php8.3-opcache
    php8.3-readline php8.3-xml php8.3-zip composer mysql-client certbot git rsync unzip ufw unattended-upgrades logrotate e2fsprogs curl)
PHP_EXTENSIONS=(bcmath curl intl mbstring openssl pdo_mysql sodium xml zip)

missing=()
for p in "${PACKAGES[@]}"; do
    dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'install ok installed' || missing+=("$p")
done
if [[ $check -eq 1 ]]; then
    say "missing packages: ${missing[*]:-none}"
    say "firewall: $(ufw status 2>/dev/null | head -1 || echo 'ufw not installed')"
    exit 0
fi

# 1. Packages (security and other updates first; the configuration files Ubuntu shipped are kept).
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get -y -qq -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade >/dev/null
if [[ ${#missing[@]} -gt 0 ]]; then
    apt-get install -y -qq -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "${missing[@]}" >/dev/null
    say "installed: ${missing[*]}"
else
    say "every package was installed already"
fi

# 2. Security updates by themselves (Ubuntu's security origin, the unattended-upgrades default), daily.
printf '%s\n' 'APT::Periodic::Update-Package-Lists "1";' 'APT::Periodic::Unattended-Upgrade "1";' > "$R/etc/apt/apt.conf.d/20auto-upgrades"
say "unattended security updates: on"

# 3. The clock: UTC (every CW time is UTC; the UK time is worked out where it is shown).
timedatectl set-timezone Etc/UTC
say "time zone: $(timedatectl show -p Timezone --value)"

# 4. Apache: event MPM and php-fpm; the modules the vhosts load (enable_https.sh enables them again, harmlessly).
a2dismod -q php8.3 >/dev/null 2>&1 || true
a2dismod -q mpm_prefork >/dev/null 2>&1 || true
a2enmod -q mpm_event ssl headers rewrite proxy proxy_fcgi setenvif http2 >/dev/null
a2dismod -q remoteip >/dev/null 2>&1 || true
apache2ctl configtest 2>&1 | tail -1
systemctl enable --now php8.3-fpm apache2 >/dev/null 2>&1
systemctl restart apache2
say "apache2 (mpm_event) and php8.3-fpm running"

# 5. Firewall: SSH, HTTP (the certificate's challenge and the redirect) and HTTPS; nothing else comes in. SSH is allowed
#    before the firewall is switched on, so this session is never cut.
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw --force enable >/dev/null
say "firewall on: $(ufw status | grep -cE 'ALLOW') rules (OpenSSH, 80/tcp, 443/tcp, each for IPv4 and IPv6)"

# 6. Checks.
php -v | head -1 | sed 's/^/[provision] /'
mods=$(php -m | tr '[:upper:]' '[:lower:]')
for m in "${PHP_EXTENSIONS[@]}"; do
    grep -qx "$m" <<<"$mods" || refuse "PHP has no $m extension"
done
say "PHP extensions present: ${PHP_EXTENSIONS[*]}"
# Each list read whole first: `cmd | grep -q` under pipefail fails when grep stops before cmd has written everything.
apache_modules=$(apache2ctl -M 2>/dev/null) || refuse "apache2ctl -M failed"
grep -q proxy_fcgi_module <<<"$apache_modules" || refuse "Apache has no proxy_fcgi"
grep -q mpm_event_module <<<"$apache_modules" || refuse "Apache does not run the event MPM"
firewall=$(ufw status) || refuse "ufw status failed"
grep -q 'Status: active' <<<"$firewall" || refuse "the firewall is not active"
command -v chattr >/dev/null || refuse "chattr is missing (e2fsprogs)"
say "done: the next steps are in docs/ops.md, \"The production server\" (push_code.sh again, then the database setup and the installers)"
