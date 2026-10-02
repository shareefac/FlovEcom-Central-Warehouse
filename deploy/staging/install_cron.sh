#!/usr/bin/env bash
# Installs (idempotently) the CW scheduled jobs on the STAGING server, against cw_staging:
#   /opt/cw-staging            a copy of the code (no tests/tools, no dev dependencies): the crons
#                              never run from a slot directory, which remote.sh re-syncs with --delete
#   /etc/cron.d/cw-staging     expire_reservations (every minute), prune_changes, invariants (nightly), the document
#                              store's seal sweep (every minute) and verify_files (nightly; both idle until
#                              install_file_store.sh has made /srv/cw-docs)
#   /etc/logrotate.d/cw-staging, /var/log/cw/
# Then runs every job once, as cron will (app login), and prints what they said.
# Run from this machine (remote.sh syncs the slot first, then this copies the slot):
#   scripts/remote.sh hammer bash deploy/staging/install_cron.sh [--migrate]
# --migrate  apply pending migrations to cw_staging first (bin/migrate.php also converges cw_app's
#            grants); without it the install stops when cw_staging is behind the code.
set -euo pipefail

src=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
dest=/opt/cw-staging
schema=cw_staging
migrate=0
for a in "$@"; do
    case $a in
        --migrate) migrate=1 ;;
        *) echo "usage: install_cron.sh [--migrate]" >&2; exit 2 ;;
    esac
done
if [[ $src != /opt/cw-* || $src == "$dest" ]]; then
    echo "install_cron: run from a slot copy /opt/cw-<slot> (scripts/remote.sh <slot> ...), not $src" >&2
    exit 2
fi
say() { echo "[install_cron] $*"; }

# 1. Code.
mkdir -p "$dest"
rsync -a --delete --exclude=/vendor/ --exclude=/tests/ --exclude=/tools/ --exclude=/.phpunit.cache/ \
      --exclude=/.phpunit.result.cache --exclude=/.git/ "$src/" "$dest/"
cd "$dest"
fingerprint() { { cat composer.json composer.lock; echo no-dev; } | sha256sum | cut -d' ' -f1; }
if [[ ! -f vendor/autoload.php || "$(fingerprint)" != "$(cat vendor/.cw-composer-hash 2>/dev/null || true)" ]]; then
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 \
        composer install --no-dev --no-progress --prefer-dist --no-plugins --no-scripts --quiet
    fingerprint > vendor/.cw-composer-hash
fi
say "code copied from $src to $dest (composer --no-dev)"

# 2. Schema: the jobs refuse to run against a schema that is behind (or ahead of) the code.
status=$(php bin/migrate.php --db="$schema" --status)
sed 's/^/[install_cron]   /' <<<"$status"
if grep -q PENDING <<<"$status"; then
    if [[ $migrate -ne 1 ]]; then
        echo "install_cron: $schema has pending migrations; re-run with --migrate" >&2
        exit 3
    fi
    php bin/migrate.php --db="$schema" | sed 's/^/[install_cron]   /'
fi

# 3. Logs, cron, logrotate.
install -d -m 0750 -o root -g root /var/log/cw
install -m 0644 -o root -g root deploy/staging/cw-staging.cron /etc/cron.d/cw-staging
install -m 0644 -o root -g root deploy/staging/logrotate-cw.conf /etc/logrotate.d/cw-staging
if ! logrotate --debug /etc/logrotate.d/cw-staging >/dev/null 2>&1; then
    echo "install_cron: logrotate rejects /etc/logrotate.d/cw-staging" >&2
    exit 1
fi
say "installed /etc/cron.d/cw-staging, /etc/logrotate.d/cw-staging, /var/log/cw"

# 4. Smoke: each job once, exactly as cron runs it.
fail=0
for job in "expire_reservations.php" "prune_changes.php --dry-run" "invariants.php" "health_alert.php"; do
    set +e
    out=$(php bin/$job --db="$schema" 2>&1)
    code=$?
    set -e
    sed 's/^/[install_cron]   /' <<<"$out"
    say "smoke: bin/$job -> exit $code"
    # health_alert exits 1 when a shadow/live channel is not reporting: expected on staging.
    if [[ $code -ne 0 && ! ( $job == health_alert.php && $code -eq 1 ) ]]; then
        fail=1
    fi
done
exit $fail
