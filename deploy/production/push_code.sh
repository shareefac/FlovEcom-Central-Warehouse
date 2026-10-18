#!/usr/bin/env bash
# Sends CW's main branch to the PRODUCTION server's release copy /opt/cw-prod-release: exactly the files of that commit
# (git archive: no file git does not track, so no ignored local file can travel), without tests/ and tools/, with composer
# --no-dev there. /opt/cw-prod-release/REVISION is removed first and written last, after composer: a push that stopped halfway
# leaves no REVISION, and install_cron.sh refuses a release copy without one. Nothing runs from the release copy:
# install_cron.sh builds a release from it (/opt/cw-prod-releases/<commit>), migrates, and points /opt/cw-prod at it.
# Run ON THE MAIN SERVER (161.35.163.191), from the main checkout:
#
#   bash /root/central-warehouse/deploy/production/push_code.sh
#
# Refuses unless the checkout is /root/central-warehouse on branch main, with a clean tree, and main is exactly GitHub's main
# (production only ever runs what is on GitHub, docs/ops.md "The production server"). On a box without composer yet (before
# provision.sh) it copies the code and says to run provision.sh, then this again.
set -euo pipefail
# CW_DEPLOY_ROOT: tests/Unit/ProductionDeployBehaviourTest.php runs this script against a temporary tree, every path below
# under $R, with its stub commands on PATH. A root is taken only when it holds the marker .cw-deploy-test-root, this is
# not a CW server (the kernel's own hostname, which no stub can change; unreadable or empty: refused), and the stub
# systemctl comes first on PATH. Unset, as it always is on the servers, $R is empty: every path is the real one.
# Every path below is under $R: this server's and the box's (the tests' ssh runs the box's commands here).
R=${CW_DEPLOY_ROOT:-/}
R=${R%/}
if [[ -n $R ]]; then
    kernel_host=
    read -r kernel_host 2>/dev/null < /proc/sys/kernel/hostname || true
    [[ $R =~ ^/[A-Za-z0-9/._-]+$ && -f $R/.cw-deploy-test-root && -n $kernel_host ]] \
        && [[ ! $kernel_host =~ ^(cw-prod-lon1-01|vpg-ded-lon1-01|FlovEcom-Central-Warehouse)$ ]] \
        && grep -qsx '# cw-deploy-stub' "$(type -P systemctl)" \
        || { echo "push_code: CW_DEPLOY_ROOT=$R is not a test tree (its marker, the stub commands first on PATH, never a CW server)" >&2; exit 2; }
fi

HOST=root@129.212.162.243
KEY=$R/root/.ssh/cw_staging
DEST=$R/opt/cw-prod-release
GIT_KEY=$R/root/.ssh/cw_repo_deploy
CHECKOUT=$R/root/central-warehouse
INCOMING=$R/opt/cw-prod-incoming
LOCK=$R/run/lock/cw-prod-install.lock

say() { echo "[push_code] $*"; }
refuse() { echo "push_code: REFUSING: $*" >&2; exit 1; }

[[ $# -eq 0 ]] || { echo "usage: push_code.sh" >&2; exit 2; }
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ $repo == "$CHECKOUT" ]] || refuse "run from the main checkout $CHECKOUT, not $repo"
cd "$repo"
[[ $(git rev-parse --abbrev-ref HEAD) == main ]] || refuse "the checkout is on $(git rev-parse --abbrev-ref HEAD), not main"
[[ -z $(git status --porcelain) ]] || refuse "the checkout has changes that are not committed"
GIT_SSH_COMMAND="ssh -i $GIT_KEY -o IdentitiesOnly=yes -o BatchMode=yes" git fetch -q origin main
rev=$(git rev-parse HEAD)
[[ $rev == "$(git rev-parse origin/main)" ]] || refuse "main ($(git rev-parse --short HEAD)) is not GitHub's main ($(git rev-parse --short origin/main)): push first"

ssh_opts=(-i "$KEY" -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=30 -o LogLevel=ERROR)
rsh="ssh $(printf '%q ' "${ssh_opts[@]}")"
on() { ssh "${ssh_opts[@]}" "$HOST" "$1"; }

[[ $(on hostname) == cw-prod-lon1-01 ]] || refuse "$HOST is not cw-prod-lon1-01"
# The commit's own files, in a fresh local directory (removed when this ends).
tmp=$(mktemp -d "$R/tmp/cw-push.XXXXXX")
trap 'rm -rf "$tmp"' EXIT
chmod 0755 "$tmp"   # rsync -a carries the top directory's mode: mktemp's 0700 would lock the web server out
git archive --format=tar "$rev" | tar -x -C "$tmp"

# Each push sends to its own incoming directory, so two pushes never mix; only the last step, under the lock install_cron.sh
# holds, makes it the release copy: composer there, REVISION written, then the swap (an install never reads a copy that is
# changing, and a push never replaces the copy an install is reading).
incoming=$INCOMING/$rev-$$
on "install -d -o root -g root -m 0755 $INCOMING $incoming"
nice -n 19 rsync -az --exclude=/vendor/ --exclude=/tests/ --exclude=/tools/ --exclude=/REVISION -e "$rsh" "$tmp/" "$HOST:$incoming/"
say "the files of $(git log --oneline -1 | cut -c1-80) sent to $HOST:$incoming"

if ! on "command -v composer >/dev/null"; then
    # Before provision.sh: the code goes where provision.sh is run from, and is never a release (no REVISION).
    on "flock -w 600 $LOCK bash -c 'install -d -m 0755 $DEST && rm -f $DEST/REVISION && rsync -a --delete --exclude=/vendor/ $incoming/ $DEST/ && rm -rf $incoming'"
    say "composer is not installed there yet (no REVISION: the copy is not a release): run 'bash $DEST/deploy/production/provision.sh' on the box, then this again"
    exit 0
fi
on "flock -w 600 $LOCK bash -euo pipefail -c '
    cd $incoming
    [ ! -d $DEST/vendor ] || cp -a $DEST/vendor $incoming/vendor
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 composer install --no-dev --no-progress --prefer-dist --no-plugins --no-scripts --quiet
    printf \"%s\n\" $rev > REVISION
    rm -rf $DEST.old
    [ ! -e $DEST ] || mv $DEST $DEST.old
    mv $incoming $DEST
    rm -rf $DEST.old
'"
say "composer --no-dev done, REVISION $rev written, and $DEST replaced under the install lock: the release copy is whole"
