#!/usr/bin/env bash
# Installs (idempotently) the CW code and scheduled jobs on the PRODUCTION server, against cw_prod (the same jobs as staging,
# deploy/staging/install_cron.sh; docs/ops.md "The production server"):
#   /opt/cw-prod-releases/<commit>   each release, built whole in a fresh directory from the release copy /opt/cw-prod-release
#                                    (push_code.sh) and only then put in place; earlier releases are kept
#   /opt/cw-prod                     a symlink to the release the crons and the web server run, switched in one rename
#   /etc/cron.d/cw-prod              the jobs (deploy/production/cw-prod.cron)
#   /etc/logrotate.d/cw-prod, /var/log/cw/
# Then runs every job once, as cron will (app login), and prints what they said.
# Run as root ON the production box, after push_code.sh and the database setup (bin/setup_staging.php --db=cw_prod):
#
#   bash /opt/cw-prod-release/deploy/production/install_cron.sh [--migrate]
#
# --migrate  run the new release's migrations (bin/migrate.php, which also converges cw_app's grants, every time). When a
#            migration is pending the writers are paused first: the cron file moved aside and the running jobs waited for,
#            the public vhost switched off and php-fpm stopped gracefully (no request of the old code still running); then the
#            schema changes with the NEW code, /opt/cw-prod switches, and the writers come back. A failure leaves them paused
#            and says so; the next run must have --migrate (it converges again, then resumes). Without --migrate a pending
#            migration stops the run before anything that runs has changed.
# Refuses, before anything is built: a second install at the same time; a release copy that is not whole; an app.env that
# does not name cw_prod or that says environment=staging (read as the application reads it: CW\Ui\Kernel's own expression,
# CW\Config's appFile, trimmed and lower-cased). Production never serves another schema, never runs the staging-only tools,
# and no screen says TEST SYSTEM.
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
        || { echo "install_cron: CW_DEPLOY_ROOT=$R is not a test tree (its marker, the stub commands first on PATH, never a CW server)" >&2; exit 2; }
fi

src=$R/opt/cw-prod-release
releases=$R/opt/cw-prod-releases
live=$R/opt/cw-prod
schema=cw_prod
app_env=$R/etc/cw/app.env
cron_file=$R/etc/cron.d/cw-prod
lock=$R/run/lock/cw-prod-install.lock
paused_cron=$R/etc/cw/cw-prod.cron.paused
# Set while this installer holds the web off, so a later run brings it back even after a failed one.
paused_web=$R/etc/cw/cw-https.paused
paused_fpm=$R/etc/cw/cw-fpm.stopped
migrate=0
for a in "$@"; do
    case $a in
        --migrate) migrate=1 ;;
        *) echo "usage: install_cron.sh [--migrate]" >&2; exit 2 ;;
    esac
done
say() { echo "[install_cron] $*"; }
refuse() { echo "install_cron: REFUSING: $*" >&2; exit 1; }

# 0. Guards: nothing is built or changed before these hold.
[[ $(id -u) -eq 0 ]] || refuse "run as root"
[[ $(hostname) == cw-prod-lon1-01 ]] || refuse "this box is $(hostname), not cw-prod-lon1-01"
exec 9>"$lock"
flock -n 9 || refuse "another install (or a push) holds $lock"
here=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ $here == "$src" ]] || refuse "run from the release copy $src (push_code.sh puts it there), not $here"
[[ -s $src/REVISION && -f $src/vendor/autoload.php ]] \
    || refuse "$src is not a whole release (push_code.sh writes REVISION last, after composer): run push_code.sh"
rev=$(tr -cd '0-9a-f' < "$src/REVISION")
[[ ${#rev} -eq 40 ]] || refuse "$src/REVISION is not a commit id"
[[ -f $app_env ]] || refuse "$app_env is missing: run 'php bin/setup_staging.php --db=$schema' in $src first"
if [[ -e $live && ! -L $live ]]; then
    refuse "$live is a directory, not the symlink this installer keeps: move it aside by hand first"
fi
# The marker as CW\Ui\Kernel reads it, the schema as the web service's CW\Config does (app.env only, no CW_* override).
appcheck=$(php -d display_errors=stderr -r '
require $argv[1] . "/vendor/autoload.php";
$app = CW\Config::loadApp(["CW_APP_ENV" => $argv[2]]);
echo strtolower(trim((string) $app->appFile(CW\Ops\TestRefPurge::ENV_KEY))), "|", $app->appDbName() ?? "";
' -- "$src" "$app_env") || refuse "cannot read $app_env"
[[ ${appcheck%%|*} != staging ]] || refuse "$app_env says environment=staging: never on production"
[[ ${appcheck#*|} == "$schema" ]] || refuse "$app_env names the schema '${appcheck#*|}', not $schema"
recovering=0
[[ -e $paused_cron || -e $paused_web || -e $paused_fpm ]] && recovering=1
if [[ $recovering -eq 1 && $migrate -ne 1 ]]; then
    refuse "the writers are paused from an earlier run that stopped: re-run with --migrate (it converges the schema and the grants, then resumes)"
fi

# 1. The new release: built in a fresh directory beside the running one, checked, then put in place (never into the running one).
install -d -o root -g root -m 0755 "$releases"
build=$(mktemp -d "$releases/.build-$rev.XXXXXX")
rsync -a --exclude=/tests/ --exclude=/tools/ --exclude=/.phpunit.cache/ --exclude=/.phpunit.result.cache --exclude=/.git/ "$src/" "$build/"
chmod 0755 "$build"
chmod -R go+rX,go-w "$build"
[[ $(tr -cd '0-9a-f' < "$build/REVISION" 2>/dev/null || true) == "$rev" && $(tr -cd '0-9a-f' < "$src/REVISION" 2>/dev/null || true) == "$rev" ]] \
    || { rm -rf "$build"; refuse "$src changed while it was copied (a push ran): run this again"; }
[[ -f $build/vendor/autoload.php && -f $build/public/index.php ]] || { rm -rf "$build"; refuse "the release built from $src is not whole"; }
new=$releases/$rev
active=$(readlink -f "$live" 2>/dev/null || true)
if [[ -e $new && $active == "$new" ]]; then
    rm -rf "$build"
    say "release $new is the one running: kept as it is"
else
    [[ ! -e $new ]] || mv "$new" "$new.replaced-$(date -u +%Y%m%dT%H%M%SZ)"
    mv "$build" "$new"
    say "release $new built from $src ($(cut -c1-12 "$new/REVISION"))"
fi
runuser -u www-data -- test -r "$new/public/index.php" -a -r "$new/vendor/autoload.php" \
    || refuse "www-data cannot read $new: the web server could not run it"

# 2. Schema, with the NEW code. Pending and no --migrate: stop here, nothing that runs has changed.
status=$(php "$new/bin/migrate.php" --db="$schema" --status)
sed 's/^/[install_cron]   /' <<<"$status"
pending=0
grep -q PENDING <<<"$status" && pending=1
if [[ $pending -eq 1 && $migrate -ne 1 ]]; then
    echo "install_cron: $schema has pending migrations for the new code; re-run with --migrate (nothing running was changed)" >&2
    exit 3
fi
if [[ $migrate -eq 1 ]]; then
    # Pause every writer, for every --migrate run (grants change too, the third review): the jobs (cron file aside, then the running ones waited for), and the web (vhost off, php-fpm stopped
    # gracefully: the requests it is answering finish first).
    if [[ -f $cron_file ]]; then
        mv "$cron_file" "$paused_cron"
        say "cron paused ($cron_file -> $paused_cron)"
    fi
    if a2query -s cw-https >/dev/null 2>&1; then
        touch "$paused_web"
        a2dissite -q cw-https >/dev/null
        systemctl reload apache2
        say "public vhost off while the schema changes"
    fi
    if systemctl is-active --quiet php8.3-fpm; then
        touch "$paused_fpm"
        systemctl kill -s QUIT php8.3-fpm
        for _ in $(seq 1 150); do systemctl is-active --quiet php8.3-fpm || break; sleep 1; done
        systemctl stop php8.3-fpm
        say "php-fpm stopped (its requests finished first)"
    fi
    for _ in $(seq 1 120); do
        pgrep -f "bin/[a-z_]+\.php .*--db=$schema" >/dev/null || break
        sleep 1
    done
    if pgrep -f "bin/[a-z_]+\.php .*--db=$schema" >/dev/null; then
        echo "install_cron: jobs still running after 2 minutes; the writers stay paused, run this again with --migrate" >&2
        exit 1
    fi
fi
if [[ $migrate -eq 1 ]]; then
    if ! php "$new/bin/migrate.php" --db="$schema" | sed 's/^/[install_cron]   /'; then
        echo "install_cron: the migration (or the grants) FAILED; the writers stay paused: fix, then run this again with --migrate (docs/ops.md)" >&2
        exit 1
    fi
fi

# 3. Switch: /opt/cw-prod points at the new release in one rename.
ln -sfn "$new" "$live.next"
mv -T "$live.next" "$live"
say "$live -> $new"

# 4. Logs, cron, logrotate (from the new release); the web back on if a run paused it.
cd "$live"
install -d -m 0750 -o root -g root "$R/var/log/cw"
install -m 0644 -o root -g root deploy/production/logrotate-cw.conf "$R/etc/logrotate.d/cw-prod"
if ! logrotate --debug "$R/etc/logrotate.d/cw-prod" >/dev/null 2>&1; then
    echo "install_cron: logrotate rejects $R/etc/logrotate.d/cw-prod" >&2
    exit 1
fi
install -m 0644 -o root -g root deploy/production/cw-prod.cron "$cron_file"
[[ ! -f $paused_cron ]] || mv "$paused_cron" "$R/etc/cw/cw-prod.cron.replaced-$(date -u +%Y%m%dT%H%M%SZ)"
if [[ -f $paused_fpm ]]; then
    systemctl start php8.3-fpm
    rm -f "$paused_fpm"
    say "php-fpm started"
elif systemctl is-active --quiet php8.3-fpm; then
    systemctl reload php8.3-fpm
fi
if [[ -f $paused_web ]]; then
    a2ensite -q cw-https >/dev/null
    apache2ctl configtest 2>&1 | tail -1
    systemctl reload apache2
    rm -f "$paused_web"
    say "public vhost back on"
fi
say "installed $cron_file, $R/etc/logrotate.d/cw-prod, $R/var/log/cw"

# 5. Smoke: each job once, exactly as cron runs it (the forms that write nothing, as on staging).
fail=0
for job in "expire_reservations.php" "prune_changes.php --dry-run" "invariants.php" "check_arrivals.php" "refresh_reports.php --status" "health_alert.php --no-send" "housekeeping.php --dry-run"; do
    set +e
    out=$(php bin/$job --db="$schema" 2>&1)
    code=$?
    set -e
    sed 's/^/[install_cron]   /' <<<"$out"
    say "smoke: bin/$job -> exit $code"
    # health_alert exits 1 when a shadow/live channel is not reporting: expected until the stores report.
    if [[ $code -ne 0 && ! ( $job == health_alert.php* && $code -eq 1 ) ]]; then
        fail=1
    fi
done
exit $fail
