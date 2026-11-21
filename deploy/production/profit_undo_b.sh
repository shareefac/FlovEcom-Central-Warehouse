#!/usr/bin/env bash
# Profit step 1, undo B (README of build/A, "Undo" B; the Codex check of the fix round, new HIGH 3): /opt/cw-prod switched to the rollback
# release (c40c050's code + the final 0074 and 0075, prepared at go-live step 1b), the profit jobs fenced and drained, php-fpm and the public
# vhost on. Every path absolute; every prerequisite checked; stops at the first one that fails, before anything that runs is changed.
# Shipped in deploy/production; copied to /root at go-live step 1b (the rollback release has no copy of it). Run as root on the
# production box: bash /root/profit_undo_b.sh (tests/Unit/ProfitUndoScriptsBehaviourTest.php runs it on stubs).
set -euo pipefail
RB=/opt/cw-prod-releases/rollback-profit
LIVE=/opt/cw-prod
J='bin/(apply_order_snapshots|refresh_item_cost|rebuild_profit_days|import_order_snapshots|import_erp_sales_cost|recost_sales|seed_item_cost)\.php'
stop() { echo "UNDO B STOPPED: $*" >&2; exit 1; }
[[ $(id -u) -eq 0 && $(hostname) == cw-prod-lon1-01 ]] || stop "run as root on cw-prod-lon1-01"
for f in bin/migrate.php bin/expire_reservations.php vendor/autoload.php public/index.php deploy/production/cw-prod.cron \
    migrations/0074_profit.sql migrations/0075_profit_queue.sql; do
    [[ -f $RB/$f ]] || stop "$RB/$f is missing: the rollback release was not prepared (go-live step 1b)"
done
printf '%s  %s\n%s  %s\n' e4dea07d45d4ef271a6b92ebab218d13ef35c5ddbdb78d2f5f04c3fad66468e5 "$RB/migrations/0074_profit.sql" \
    7d90cfa7f6f62dbb2b248c58dfcb64173e64c7120cccd8216357f6935b78dcb8 "$RB/migrations/0075_profit_queue.sql" | sha256sum -c --quiet \
    || stop "the rollback release's 0074 / 0075 are not the files rehearsed"
# The installer's own lock, held to the end: no install or push meanwhile.
exec 9>/run/lock/cw-prod-install.lock
flock -n 9 || stop "an install (or a push) holds /run/lock/cw-prod-install.lock"
status=$(php "$RB/bin/migrate.php" --db=cw_prod --status) || stop "the rollback release could not read the schema (migrate.php --status failed)"
if grep -q PENDING <<<"$status"; then
    stop "a migration is pending: recover step 1 first (the PROFIT release's install_cron.sh --migrate), never undo now"
fi
# The profit jobs: no new start (the rollback's cron file), then the running ones waited for (6 minutes), then stopped and checked.
install -m 0644 -o root -g root "$RB/deploy/production/cw-prod.cron" /etc/cron.d/cw-prod
for _ in $(seq 1 360); do pgrep -f "$J" >/dev/null || break; sleep 1; done
if pgrep -f "$J" >/dev/null; then
    # A job that ended since the line above is no failure (set -e would stop here, silently, half way: the test of 21 Nov).
    pgrep -af "$J" || true
    for p in $(pgrep -f "$J"); do kill -TERM "$p" 2>/dev/null || true; done
    for _ in $(seq 1 30); do pgrep -f "$J" >/dev/null || break; sleep 1; done
    if pgrep -af "$J"; then
        stop "profit jobs still running after TERM (listed above): kill -KILL <pid> each, then run this again"
    fi
fi
ln -sfn "$RB" "$LIVE.next"
mv -T "$LIVE.next" "$LIVE"
[[ $(readlink -f "$LIVE") == "$RB" ]] || stop "$LIVE does not point at $RB"
# The web on, whatever a stopped install left (its markers, or the services themselves).
if [[ -f /etc/cw/cw-fpm.stopped ]] || ! systemctl is-active --quiet php8.3-fpm; then
    systemctl start php8.3-fpm
    rm -f /etc/cw/cw-fpm.stopped
else
    systemctl reload php8.3-fpm
fi
if [[ -f /etc/cw/cw-https.paused ]] || ! a2query -s cw-https >/dev/null 2>&1; then
    a2ensite -q cw-https
    apache2ctl configtest
    systemctl reload apache2
    rm -f /etc/cw/cw-https.paused
fi
if [[ -f /etc/cw/cw-prod.cron.paused ]]; then
    mv /etc/cw/cw-prod.cron.paused "/etc/cw/cw-prod.cron.replaced-$(date -u +%Y%m%dT%H%M%SZ)"
fi
systemctl is-active --quiet php8.3-fpm || stop "php-fpm is not running"
a2query -s cw-https >/dev/null 2>&1 || stop "the public vhost is not on"
cd "$LIVE"
php bin/expire_reservations.php --db=cw_prod || stop "the reservation expiry failed on the rollback release"
echo "UNDO B DONE: $LIVE -> $RB; cron without the profit jobs; php-fpm and the public vhost on. Then undo A (GitHub)."
