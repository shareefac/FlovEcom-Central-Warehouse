#!/usr/bin/env bash
# Profit step 1, undo A on the box (README of build/A, "Undo" A, step 1): after push_code.sh of the rollback commit, the profit jobs fenced
# (the rollback release's cron file: no profit job) and drained (6 minutes, then TERM and checked), before install_cron.sh runs.
# Shipped in deploy/production; copied to /root at go-live step 1b (the rollback release has no copy of it). Run as root on the
# production box: bash /root/profit_drain_jobs.sh <rollback commit id> (tests/Unit/ProfitUndoScriptsBehaviourTest.php runs it on stubs).
set -euo pipefail
REL=/opt/cw-prod-release
J='bin/(apply_order_snapshots|refresh_item_cost|rebuild_profit_days|import_order_snapshots|import_erp_sales_cost|recost_sales|seed_item_cost)\.php'
stop() { echo "DRAIN STOPPED: $*" >&2; exit 1; }
[[ $(id -u) -eq 0 && $(hostname) == cw-prod-lon1-01 ]] || stop "run as root on cw-prod-lon1-01"
[[ ${1:-} =~ ^[0-9a-f]{40}$ ]] || stop "give the rollback commit's full id"
[[ $(tr -cd '0-9a-f' < "$REL/REVISION") == "$1" ]] || stop "$REL is not the rollback commit $1 (push_code.sh first)"
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
echo "DRAINED: no profit job runs; now: bash $REL/deploy/production/install_cron.sh (without --migrate)"
