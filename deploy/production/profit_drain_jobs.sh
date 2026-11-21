#!/usr/bin/env bash
# Profit step 1, undo A on the box (README of build/A, "Undo" A): after push_code.sh of the rollback commit, the profit jobs fenced (the
# rollback release's cron file: no profit job) and drained (6 minutes, then TERM and checked), then the rollback release's installer run at
# once (without --migrate). The installer's lock (/run/lock/cw-prod-install.lock) is held for the checks and the drain, so no push can
# change the release copy meanwhile; its REVISION is checked again at the end under the lock, then the lock is let go and the installer
# started at once (the third final review, a-M, b-M6, Fable L1). Stops, naming why, at the first check that fails (the installer then never
# runs). Shipped in deploy/production; copied to /root at go-live step 1b (the rollback release has no copy of it). Run as root on the
# production box, from a plain root shell with no profit tool open: bash /root/profit_drain_jobs.sh <rollback commit id>
# (tests/Unit/ProfitUndoScriptsBehaviourTest.php runs it on stubs).
set -euo pipefail
REL=/opt/cw-prod-release
LIVE=/opt/cw-prod
RELEASES=/opt/cw-prod-releases
LOCK=/run/lock/cw-prod-install.lock
J='bin/(apply_order_snapshots|refresh_item_cost|rebuild_profit_days|import_order_snapshots|import_erp_sales_cost|recost_sales|seed_item_cost)\.php'
stop() { echo "DRAIN STOPPED: $*" >&2; exit 1; }
[[ $(id -u) -eq 0 && $(hostname) == cw-prod-lon1-01 ]] || stop "run as root on cw-prod-lon1-01"
[[ ${1:-} =~ ^[0-9a-f]{40}$ ]] || stop "give the rollback commit's full id"
# The installer's own lock first, held to the end of the drain: no install or push meanwhile.
exec 9>"$LOCK"
flock -n 9 || stop "an install (or a push) holds $LOCK: wait for it, then run this again"
[[ $(tr -cd '0-9a-f' < "$REL/REVISION") == "$1" ]] || stop "$REL is not the rollback commit $1 (push_code.sh first)"
[[ -f $REL/deploy/production/install_cron.sh ]] || stop "$REL has no deploy/production/install_cron.sh: not a whole release copy"
grep -q 'apply_order_snapshots\|refresh_item_cost' "$REL/deploy/production/cw-prod.cron" && stop "$REL's cron file has profit jobs: not the rollback"
for f in /etc/cw/cw-prod.cron.paused /etc/cw/cw-https.paused /etc/cw/cw-fpm.stopped; do
    [[ ! -e $f ]] || stop "$f is there: step 1 stopped half way: recover it first (the PROFIT release's install_cron.sh --migrate)"
done
install -m 0644 -o root -g root "$REL/deploy/production/cw-prod.cron" /etc/cron.d/cw-prod
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
# Still the rollback commit, read again under the lock (a push cannot have changed it since the first check: it waits for the lock).
[[ $(tr -cd '0-9a-f' < "$REL/REVISION") == "$1" ]] || stop "$REL changed during the drain: it is no longer the rollback commit $1; nothing installed (push it again, then run this again)"
echo "DRAINED: no profit job runs; the installer of $1 now (without --migrate)"
# The lock let go, and the installer (which takes it again at its start) run at once: a push waiting for the lock may take it first, then
# the installer refuses (another install or push holds it) and nothing is installed: run this again. What the installer switched to is
# checked after it: a push that took the lock and replaced the release copy before the installer started would have it install that
# commit (the Codex check of the fix round, r13 a): said, never "DRAINED AND INSTALLED".
flock -u 9
exec 9>&-
if bash "$REL/deploy/production/install_cron.sh"; then
    now=$(readlink -f "$LIVE" || true)
    [[ $now == "$RELEASES/$1" ]] || stop "$LIVE runs $now, not the rollback commit $1 (a push took the lock as it was let go): push the rollback again, then run this again"
    echo "DRAINED AND INSTALLED: $1 (the rollback release) runs; check its smoke lines above"
else
    code=$?
    stop "the installer of $1 ended with exit $code (its lines above): nothing more done here; read them, then run this again"
fi
