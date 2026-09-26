# Operating CW: scheduled jobs and the concurrency hammer

Design choices behind this page: `docs/decisions.md` H1–H8. How to develop and run tests:
`docs/dev.md`. Everything here runs on the CW **staging** server `46.101.55.135` against the
staging cluster. Nothing touches a live or proto site or database.

## Scheduled jobs

| Job | Staging schedule (UTC) | What it does | Exit codes |
|---|---|---|---|
| `bin/expire_reservations.php` | every minute | Expires unpaid holds past their TTL (§3, §4 step 3). It selects due ids without locks, then handles each hold in its own transaction through `Reservations::expireDue`: lock, re-check, release the held units, status `expired`. Runs batches of 500 until none is due or 50 s have passed. A hold that fails is logged and skipped (H4). | 0 ok · 1 some holds failed · 3 cannot run |
| `bin/prune_changes.php --days=14` | 03:17 | Deletes change-feed rows (`stock_change`) older than 14 days, except the newest row of each item/listing/channel/global scope, so no listing's version moves (H2). Short autocommit deletes; safe while the service runs. `--dry-run` only counts. | 0 ok · 3 cannot run |
| `bin/invariants.php` | 03:47 | The nightly bucket check (`CW\Invariants`, D44), run on one consistent snapshot (H5): cached buckets = unit states = ledger sums, unit states match their reservations. | 0 ok · **1 mismatch** · 3 cannot run |
| `bin/health_alert.php` | not scheduled (stub) | Lists shadow/live channels that stopped heartbeating (no heartbeat, or none for 180 s), report dead letters, or run another mode than CW's (H8). Prints only. | 0 ok · 1 problems printed · 3 cannot run |

Common to all jobs (`CW\Ops\Cli`, H3):
- `--db=<schema>` (default: `db_name` in app.env).
- The **app login** `cw_app` is used; `--admin` switches to the admin login (test schemas, break-glass).
- A job refuses to run (exit 3) when the schema's applied migrations differ from `migrations/`.
- One run per job and schema: an overlapping run logs `skipped` and exits 0.
- Usage errors exit 2.
- Each run logs one line: `<UTC time> <job> [<schema>] ...`. Errors go to stderr.

Examples of a log line:

```
2026-09-26T18:02:50Z expire_reservations [cw_staging] expired=0 failed=0 batches=1 ms=16
2026-09-26T18:02:50Z prune_changes [cw_staging] cutoff=2026-09-12T18:02:50Z (14 days) deleted=0 kept_newest_of_scope=0 windows=0 up_to_seq=0 ms=2
2026-09-26T18:02:50Z invariants [cw_staging] ok: invariants hold (balances=0 units=0 ms=29 peak_mb=2)
```

### Staging installation

| What | Where |
|---|---|
| Code the jobs run | `/opt/cw-staging`: a copy of the repo without `tests/` and `tools/`, with `composer --no-dev`. Never a slot directory, because `/opt/cw-<slot>` is re-synced with `--delete`. |
| Schedule | `/etc/cron.d/cw-staging` (source: `deploy/staging/cw-staging.cron`). Runs as root, because `/etc/cw/app.env` is not world-readable. |
| Logs | `/var/log/cw/<job>.log`, rotated weekly with 8 kept (`/etc/logrotate.d/cw-staging`, source `deploy/staging/logrotate-cw.conf`). An invariant failure also goes to syslog: `journalctl -t cw-invariants`. |
| Schema | `cw_staging`, migrated to `0002_stock_core.sql` on 26 Sep 2026 18:02 UTC. |

Install or update (idempotent; run from this machine):

```bash
scripts/remote.sh hammer bash deploy/staging/install_cron.sh             # copy code, install cron + logrotate, smoke-run every job
scripts/remote.sh hammer bash deploy/staging/install_cron.sh --migrate   # also apply pending migrations to cw_staging first
```

The installer stops, with exit 3, when `cw_staging` has pending migrations and `--migrate` was
not given: the jobs would refuse to run anyway. After the copy it runs each job once, exactly as
cron does, and prints what the jobs said. **Re-run it after every change** to `bin/`, `src/` or
`migrations/` that the jobs should pick up. The cron keeps running the old copy until then.

Run a job by hand on staging:

```bash
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/invariants.php --db=cw_staging'
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'tail -n 20 /var/log/cw/expire_reservations.log'
```

To stop the jobs: `rm /etc/cron.d/cw-staging` (cron notices within a minute).

**Pending:** `migrations/0003_review_fixes.sql` (review fixes R1–R18, `docs/decisions.md`) is not yet
applied to `cw_staging`, and `/opt/cw-staging` still holds the 0002 code. Apply both together
(`install_cron.sh --migrate`) when the change is accepted; the jobs refuse to run while code and
schema disagree.

### API log rotation (staging)

`install_api.sh` installs `/etc/cw/logrotate-cw-api.conf` (from `deploy/staging/logrotate-cw-api.conf`)
and `/etc/cron.d/cw-api-logrotate`, which runs it hourly with its own state file
(`/var/lib/logrotate/cw-api.status`): `/var/log/cw-api/*.log` is rotated at 20 MB, 10 kept,
compressed, copytruncate (R18). Check it with
`/usr/sbin/logrotate -d -s /var/lib/logrotate/cw-api.status /etc/cw/logrotate-cw-api.conf`.

### When a job complains

- **`invariants` exits 1 (MISMATCH).** Each violation is on stderr in the log, at most 50 per
  check. Nothing is repaired automatically.
  - A balance that differs from its units or ledger means a write bypassed `CW\Stock`, which is
    the only writer of the buckets.
  - A unit whose state disagrees with its reservation points to a state-machine bug.
  - Keep the snapshot (`mysqldump` of the schema) before touching anything, then find the
    ledger rows of the item.
- **`expire_reservations` exits 1.** A hold could not be expired. Its id and the error are in the
  log; the other holds still expire. Common causes:
  - A lock wait: it clears itself on the next run.
  - A balance whose `held` is lower than its held units: `invariants` shows the same item.
- **`prune_changes` says "time budget used up".** The next night continues where it stopped.
- **Any job exits 3.** The config or connection is broken, or the schema does not match the code
  (deploy or migrate). The message says which.

## The concurrency hammer (`tests/concurrency/hammer.php`)

It proves the §14 concurrency guarantees with real processes, each with its own connection,
calling `CW\Reservations` directly. Run it on staging in its own slot. It drops and re-creates
`cw_test_<slot>`, never any other schema:

```bash
scripts/remote.sh hammer php tests/concurrency/hammer.php                 # all four scenarios, ~50 s
scripts/remote.sh hammer php tests/concurrency/hammer.php --only=1,3      # some of them
scripts/remote.sh hammer php tests/concurrency/hammer.php --seed=1603129820 --seconds=60 --workers=20
```

Output: a progress line per check, then one PASS/FAIL table. It ends with `RESULT: PASS` (exit 0)
only if every check passed; otherwise it exits 1.

| Scenario | Proves |
|---|---|
| 1 | 200 reserve attempts from 3 live channels on a strict item with `on_hand = 10`. Result: exactly 10 held and 190 refused (409 short). Each hold saw its own state (available after = 9..0). Availability is never negative in 1,000+ observer samples, in any response, or in a row-by-row replay of the item's ledger. Refused orders leave no rows, every site then sees 0, and the invariants hold. |
| 2 | 100 orders placed at once, each with 2–5 lines in random order across 5 strict items, including a duplicate listing and a "10 x" listing. No deadlock or other error surfaces. Each order is all-or-nothing. `held` equals exactly the sum of the accepted orders (qty × u), and availability is never negative. Then half the orders are paid and half abandoned at the same moment: all answer 200, `held` returns to 0, `allocated` equals what was paid, and nothing oversells. |
| 3 | 20 processes send the same Idempotency-Key at the same instant, for reserve, commit, ship, unship, ship again, return, goods_in (staff), reserve + release, cancel (restockable) and cancel (to VERIFY). Each has exactly one effect: 1 fresh answer and 19 identical replays; the ledger rows, idempotency row, audit row and balance are as expected. Three more rounds: the same order under 20 keys gives one hold and 19 "extended"; one key with two bodies gives one effect and a 422 for the other body; 20 × ship before commit give 20 × 409 `not_committed`, nothing stored and **no deadlock** (H1). |
| 4 | For 30 s, 22 traders reserve, extend, re-reserve, commit (with and without a hold, with other lines, after expiry, on tombstones, outage orders), release (current and stale attempt), cancel, ship, book goods-in and replay earlier calls, while 2 expiry crons run at once. Every answer matches the state machine, and nothing fails. `CW\Invariants` holds on every consistent snapshot taken during the run (about 60). Every negative availability of a strict item is a flagged `oversell_event`. Every interleaving occurs at least once. Once all remaining holds are expired, nothing is held and the invariants hold. |

Connection budget: the staging cluster allows 76 connections in total, shared by every slot. The
hammer holds at most 30 at once (24 workers + 1 observer by default, plus the parent, which
holds none while children run). Before starting, it checks the cluster's free connections and
keeps 8 spare for other slots. It shrinks the pool if needed, and refuses to start below 8.

Last results (26 Sep 2026, staging, 24 workers):
- The first full run found the H1 deadlock in scenario 3. It was fixed, with the regression test
  `IdempotencyRaceTest`.
- Since the fix: `RESULT: PASS (50 checks passed, 0 failed)` in ~50 s.
  - 0 deadlocks surfaced, and 0 retried inside CW, in every scenario.
  - Scenario 4 ran about 2,000–3,000 operations in 30 s (70–100/s, limited by the feed clock,
    D39) and checked about 60 live snapshots.
