#!/usr/bin/env bash
# Run a command against this repo on the CW staging server, in a per-slot copy.
#
#   scripts/remote.sh <slot> <command...>
#
#   scripts/remote.sh base vendor/bin/phpunit
#   scripts/remote.sh base vendor/bin/phpunit --testsuite unit
#   scripts/remote.sh base php bin/migrate.php --db=cw_staging
#   scripts/remote.sh base 'vendor/bin/phpunit 2>&1 | tail -5'    # ONE argument = a shell string
#
# 1. rsync the repo (minus vendor/, .git/, PHPUnit caches) to root@46.101.55.135:/opt/cw-<slot>/
#    (--delete, so the remote copy mirrors the local tree; vendor/ there is kept).
# 2. composer install there when vendor/ is missing or composer.json/composer.lock changed;
#    if the local repo has no composer.lock yet, the one composer resolved is copied back.
# 3. run the command in /opt/cw-<slot> with CW_SLOT=<slot> (tests use schema cw_test_<slot>).
# Runs for one slot are serialised by a local lock. Exit status = the command's.
# Overrides: CW_REMOTE_HOST (user@host), CW_REMOTE_KEY (ssh key path).
set -euo pipefail

usage() {
    echo "usage: scripts/remote.sh <slot> <command...>   (slot: [a-z0-9_]{1,24})" >&2
    exit 2
}

[[ $# -ge 2 ]] || usage
slot=$1
shift
[[ $slot =~ ^[a-z0-9_]{1,24}$ ]] || usage

host=${CW_REMOTE_HOST:-root@46.101.55.135}
key=${CW_REMOTE_KEY:-/root/.ssh/cw_staging}
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
dest=/opt/cw-$slot

ssh_opts=(-i "$key" -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=30
          -o ServerAliveCountMax=4 -o LogLevel=ERROR)
rsh="ssh $(printf '%q ' "${ssh_opts[@]}")"

# One run per slot at a time (the slot shares one remote directory and one test schema).
lock_dir=${XDG_RUNTIME_DIR:-/tmp}
exec 9>"$lock_dir/cw-remote-$slot.lock"
if ! flock -w 1800 9; then
    echo "remote.sh: slot $slot is busy (lock held > 30 min)" >&2
    exit 75
fi

# 1. sync (low priority locally: this box is the live web server)
if ! nice -n 19 rsync -az --delete --rsync-path="mkdir -p $dest && rsync" \
        --exclude=/vendor/ --exclude=/.git/ --exclude=/.phpunit.cache/ --exclude=/.phpunit.result.cache \
        -e "$rsh" "$repo/" "$host:$dest/"; then
    echo "remote.sh: rsync to $host:$dest failed" >&2
    exit 1
fi

# 2. dependencies
had_lock=0
[[ -f $repo/composer.lock ]] && had_lock=1
# shellcheck disable=SC2029
if ! ssh "${ssh_opts[@]}" "$host" "bash -s -- $(printf '%q' "$dest")" <<'REMOTE'
set -euo pipefail
cd "$1"
fingerprint() { cat composer.json composer.lock 2>/dev/null | sha256sum | cut -d' ' -f1; }
if [[ ! -f vendor/autoload.php || "$(fingerprint)" != "$(cat vendor/.cw-composer-hash 2>/dev/null || true)" ]]; then
    export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
    if ! out=$(composer install --no-progress --prefer-dist --no-plugins --no-scripts 2>&1); then
        echo "$out" >&2
        echo "remote.sh: composer install failed" >&2
        exit 1
    fi
    fingerprint > vendor/.cw-composer-hash
fi
REMOTE
then
    exit 1
fi
if [[ $had_lock -eq 0 ]]; then
    if nice -n 19 rsync -q -e "$rsh" "$host:$dest/composer.lock" "$repo/composer.lock" 2>/dev/null; then
        echo "remote.sh: composer.lock created on staging and copied into the repo (commit it)" >&2
    fi
fi

# 3. the command
if [[ $# -eq 1 ]]; then
    cmd="bash -c $(printf '%q' "$1")"
else
    cmd=$(printf '%q ' "$@")
fi
set +e
# shellcheck disable=SC2029
ssh "${ssh_opts[@]}" "$host" "cd $(printf '%q' "$dest") && export CW_SLOT=$(printf '%q' "$slot") && $cmd"
status=$?
set -e
exit $status
