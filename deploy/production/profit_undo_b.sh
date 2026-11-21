#!/usr/bin/env bash
# Profit step 1, undo B (README of build/A, "Undo" B; the Codex check of the fix round, new HIGH 3): /opt/cw-prod switched to the rollback
# release (c40c050's code + the final 0074 and 0075, prepared at go-live step 1b), the profit jobs fenced and drained, php-fpm, Apache and the
# public vhost on, then the installer's own smoke run. Every path absolute; every prerequisite checked; stops at the first one that fails,
# before anything that runs is changed. Once /opt/cw-prod is switched, any failure says "UNDO B STOPPED: ... (the link is already switched;
# run this again)" (the third final review, a-HIGH4, c-M8, Fable L3): it is safe to run again. No "UNDO B DONE" line = not finished.
# Shipped in deploy/production; copied to /root at go-live step 1b (the rollback release has no copy of it). Run as root on the
# production box, from a plain root shell with no profit tool open: bash /root/profit_undo_b.sh (tests/Unit/ProfitUndoScriptsBehaviourTest.php
# runs it on stubs).
set -euo pipefail
set -o errtrace  # the ERR trap below in functions and subshells too
RB=/opt/cw-prod-releases/rollback-profit
LIVE=/opt/cw-prod
J='bin/(apply_order_snapshots|refresh_item_cost|rebuild_profit_days|import_order_snapshots|import_erp_sales_cost|recost_sales|seed_item_cost)\.php'
switched=0
stop() {
    if [[ $switched -eq 1 ]]; then
        echo "UNDO B STOPPED: $* (the link is already switched; run this again)" >&2
    else
        echo "UNDO B STOPPED: $*" >&2
    fi
    exit 1
}
# A command that fails after the switch is never a bare tool error: it names itself and says the link is switched.
trap 'rc=$?; if [[ $switched -eq 1 ]]; then echo "UNDO B STOPPED: \"$BASH_COMMAND\" failed (exit $rc) (the link is already switched; run this again)" >&2; fi; exit 1' ERR
[[ $(id -u) -eq 0 && $(hostname) == cw-prod-lon1-01 ]] || stop "run as root on cw-prod-lon1-01"
for f in bin/migrate.php bin/expire_reservations.php vendor/autoload.php public/index.php deploy/production/cw-prod.cron \
    migrations/0074_profit.sql migrations/0075_profit_queue.sql; do
    [[ -f $RB/$f ]] || stop "$RB/$f is missing: the rollback release was not prepared (go-live step 1b)"
done
printf '%s  %s\n%s  %s\n' e4dea07d45d4ef271a6b92ebab218d13ef35c5ddbdb78d2f5f04c3fad66468e5 "$RB/migrations/0074_profit.sql" \
    7d90cfa7f6f62dbb2b248c58dfcb64173e64c7120cccd8216357f6935b78dcb8 "$RB/migrations/0075_profit_queue.sql" | sha256sum -c --quiet \
    || stop "the rollback release's 0074 / 0075 are not the files rehearsed"
# The web server must be able to run it (as install_cron.sh checks a release before its switch).
runuser -u www-data -- test -r "$RB/public/index.php" -a -r "$RB/vendor/autoload.php" \
    || stop "www-data cannot read $RB: the web server could not run it (prepare it again, go-live step 1b)"
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
ln -sfn "$RB" "$LIVE.next" || stop "could not make $LIVE.next: $LIVE not switched (the rollback cron file is in); run this again"
mv -T "$LIVE.next" "$LIVE" || stop "$LIVE was not switched (mv failed; the rollback cron file is in): run this again"
switched=1
[[ $(readlink -f "$LIVE") == "$RB" ]] || stop "$LIVE does not point at $RB"
# The web on, whatever a stopped install left (its markers, or the services themselves): php-fpm, the vhost, Apache.
if [[ -f /etc/cw/cw-fpm.stopped ]] || ! systemctl is-active --quiet php8.3-fpm; then
    systemctl start php8.3-fpm
    rm -f /etc/cw/cw-fpm.stopped
else
    systemctl reload php8.3-fpm
fi
site=0
if [[ -f /etc/cw/cw-https.paused ]] || ! a2query -s cw-https >/dev/null 2>&1; then
    a2ensite -q cw-https
    site=1
fi
if ! systemctl is-active --quiet apache2; then
    # Apache stopped (the site enabled on disk or not): its configuration checked, then started.
    apache2ctl configtest || stop "Apache's configuration does not load (apache2ctl configtest): fix it, then run this again"
    systemctl start apache2 || stop "Apache does not start (systemctl start apache2)"
elif [[ $site -eq 1 ]]; then
    apache2ctl configtest || stop "Apache's configuration does not load (apache2ctl configtest): fix it, then run this again"
    systemctl reload apache2
fi
rm -f /etc/cw/cw-https.paused
if [[ -f /etc/cw/cw-prod.cron.paused ]]; then
    mv /etc/cw/cw-prod.cron.paused "/etc/cw/cw-prod.cron.replaced-$(date -u +%Y%m%dT%H%M%SZ)"
fi
systemctl is-active --quiet php8.3-fpm || stop "php-fpm is not running"
systemctl is-active --quiet apache2 || stop "Apache is not running"
a2query -s cw-https >/dev/null 2>&1 || stop "the public vhost is not on"
cd "$LIVE"
# The installer's own smoke run (install_cron.sh, its smoke: step): each job once, as cron runs it, in the forms that write nothing.
fail=0
for job in "expire_reservations.php" "prune_changes.php --dry-run" "invariants.php" "check_arrivals.php" "apply_store_choices.php" "refresh_reports.php --status" "health_alert.php --no-send" "housekeeping.php --dry-run"; do
    # The job's exit read in the if (never the ERR trap's: a smoke job may say exit 1).
    if out=$(php bin/$job --db=cw_prod 2>&1); then code=0; else code=$?; fi
    sed 's/^/[undo_b]   /' <<<"$out"
    echo "[undo_b] smoke: bin/$job -> exit $code"
    # health_alert exits 1 when a shadow/live channel is not reporting: expected until the stores report (as install_cron.sh reads it).
    if [[ $code -ne 0 && ! ( $job == health_alert.php* && $code -eq 1 ) ]]; then
        fail=1
    fi
done
[[ $fail -eq 0 ]] || stop "a smoke job failed on the rollback release (its lines above)"
echo "UNDO B DONE: $LIVE -> $RB; cron without the profit jobs; php-fpm, Apache and the public vhost on; smoke run ok. Then undo A (GitHub)."
