# Operating CW: scheduled jobs and the concurrency hammer

Design choices behind this page: `docs/decisions.md` H1–H8. How to develop and run tests:
`docs/dev.md`. Everything here runs on the CW **staging** server `46.101.55.135` against the
staging cluster. Nothing touches a live or proto site or database.

## Scheduled jobs

| Job | Staging schedule (UTC) | What it does | Exit codes |
|---|---|---|---|
| `bin/expire_reservations.php` | every minute | Expires unpaid holds past their TTL (§3, §4 step 3). It selects due ids without locks, then handles each hold in its own transaction through `Reservations::expireDue`: lock, re-check, release the held units, status `expired`. Runs batches of 500 until none is due or 50 s have passed. A hold that fails is logged and skipped (H4). | 0 ok · 1 some holds failed · 3 cannot run |
| `bin/prune_changes.php --days=14` | 03:17 | Deletes change-feed rows (`stock_change`) older than 14 days, except the newest row of each item/listing/channel/global scope, so no listing's version moves (H2). Short autocommit deletes; safe while the service runs. `--dry-run` only counts. | 0 ok · 3 cannot run |
| `bin/invariants.php` | 03:47 | The nightly bucket check (`CW\Invariants`, D44), run on one consistent snapshot (H5): cached buckets = unit states = ledger sums, unit states match their reservations; since C0 also the per-item value sequence (checks 7–9, I3): one seq per on_hand ledger row, seqs 1..N per item matching its clock, seq order = ledger id order within a balance; since 0008 also the document base (D1–D7, `CW\Documents\DocumentInvariants`): gapless numbers per series, whole reversal pairs that net to zero, ledger rows naming posted documents by their number, review tasks on the right documents and never decided by the document's own people, line items that exist, and every posted document against its write-once posting record (`document_posting`: number, hash, poster, time, and its header and lines re-hashed; I33). | 0 ok · **1 mismatch** · 3 cannot run |
| `deploy/staging/seal_file_store.sh /srv/cw-docs` | every minute | The document store's seal sweep (I36): every stored file not yet immutable becomes root:www-data 0440 and `chattr +i`, so it cannot be rewritten in place, removed or renamed. Silent when nothing is new; does nothing before `install_file_store.sh`. Log: `/var/log/cw/seal_file_store.log`. | 0 ok · **1 a file could not be sealed** · 3 cannot run |
| `bin/verify_files.php` | 04:27 (only once `/srv/cw-docs` exists) | Re-hashes every stored file (I23, I36); a missing or changed file goes to syslog too (`journalctl -t cw-files`). | 0 ok · **1 missing or changed** · 3 cannot run |
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
| Schema | `cw_staging`, migrated to `0005_listing_barcodes_index.sql` on 30 Sep 2026 (`install_cron.sh --migrate`). |

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

**Pending:** nothing. `0003`, `0004` and `0005` are applied to `cw_staging`, and `/opt/cw-staging` holds the code
that matches them (installed 30 Sep 2026 from slot `fix2`). Re-run `install_cron.sh` after every change to `bin/`, `src/` or `migrations/`.

**`0006_value_core.sql` (C0, Phase I-1; not applied yet):** it backfills one `stock_value_seq` row for every on_hand
ledger row already booked (8,199 opening adjustments on `cw_staging`, plus anything since) and a `stock_value_clock` row
for every item (taken from the seq rows, I28). **Migrate it with the code, in one run, with the writers stopped**
(`install_cron.sh --migrate`, after the owner approves the I-1 deploy): code without 0006 fails on the missing tables, and
code from before C0 running on a migrated schema books on_hand rows without seqs, which `bin/invariants.php` reports
(check 7). On `cw_staging` nothing books on_hand during a deploy today (no channel is live, the only cron touches holds); on
live, stop the API and the crons first. **Reopen only after the check:** `SELECT COUNT(*) FROM stock_value_seq` must equal
`SELECT COUNT(*) FROM stock_ledger WHERE bucket = 'on_hand'`, and `bin/invariants.php` must say `ok`.

**`0007_staff_roles.sql` (roles, Phase I-1; not applied yet):** gives every existing person the role they have as a
`staff_role` grant (one `staff.roles` audit row each, actor `system:migrate`) and **drops `staff_user.role`**. Same rule:
migrate it with the code in one run (decision I10); code from before it cannot sign anyone in on the new schema (unknown
column), and the new code cannot on the old one. Check afterwards: `SELECT COUNT(*) FROM staff_role` = `SELECT COUNT(*)
FROM staff_user` (one grant each), then convert or create the real people with `--roles` ("Staff accounts" below).

**`0008_documents.sql` (documents, Phase I-1; not applied yet):** creates the document base and seeds 22 reason codes, 8
document types and 8 number series at 0 (no backfill). Migrate it in the same run as 0006 and 0007 (`install_cron.sh
--migrate`): the I-1 code's invariant check reads the new tables. Then install the file store (below, "The document
store"). Check afterwards: `SELECT COUNT(*) FROM reason_code` = 22, `document_type` = 8, `SELECT SUM(last_no) FROM
number_series` = 0, `document` and `document_posting` empty, and `php bin/invariants.php` says `ok`. The new cron lines
(seal sweep, nightly `verify_files`) come with the same `install_cron.sh` run and stay idle until the store is installed.

**`0009_suppliers.sql`, `0010_purchase_orders.sql`, `0011_reorder.sql` (Phase I-2; not applied yet):** new tables only, plus
the PO `document_type` row (review all, due 7, approval over £10,000 net, reject records), 3 PO reversal reasons, 29
`app_setting` rows (12 + 3 + 14, provisional), 6 VAT codes and the 14–22 Sep 2026 anomaly window. No backfill; nothing
books stock. Migrate with the I-2 code in one `install_cron.sh --migrate` run (after 0006–0008, or in the same run). Check
afterwards: `SELECT COUNT(*) FROM app_setting` = 29, `vat_code` = 6, `SELECT approval_rule, approval_limit_units,
review_rule, reject_action FROM document_type WHERE code = 'PO'` = `over_value, 10000, all, record`, `SELECT COUNT(*) FROM
demand_anomaly` = 1, `supplier`/`purchase_order`/`sales_history_day` empty, and `php bin/invariants.php` says `ok` (it now
runs S1–S5 and P1–P6 too). Then the settings (company details) and the sales-history load ("Purchasing, Phase I-2" below).

**`0012_key_bulk.sql` (Key spot-check and bulk confirm, M26–M28; not applied yet):** three new append-only tables
(`match_proposal_basis`, `key_sample`, `key_sample_member`; cw_app gets SELECT, INSERT), no seeds, no backfill (the re-band and
sample tools write the proved bases of older proposals). It was written as `0010_key_bulk.sql` on the mapping branch and renamed
when merged after Phase I-2: name order applies it after 0011, in the same `install_cron.sh --migrate` run. Check afterwards: the
three tables exist and are empty, and `php bin/migrate.php --status` lists no PENDING file. Then the runbook "Key spot-check and
bulk confirm" below.

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
- **`seal_file_store` logs `FAILED to seal` (exit 1).** A stored file is still rewritable in place by its owner (php-fpm or
  root): check `lsattr` on it and the file system (the immutable flag needs ext4/xfs); its content stays protected by
  detection (re-hash on read, nightly `verify_files`). The next minute retries.
- **`verify_files` exits 1 (syslog `cw-files`).** A stored file is missing or no longer matches its sha256: it is never served
  (500 with a request id). Keep the evidence, compare with the backup, never "fix" the row (I23, I36).
- **Any job exits 3.** The config or connection is broken, or the schema does not match the code
  (deploy or migrate). The message says which.

## The concurrency hammer (`tests/concurrency/hammer.php`)

It proves the §14 concurrency guarantees with real processes, each with its own connection,
calling `CW\Reservations` directly. Run it on staging in its own slot. It drops and re-creates
`cw_test_<slot>`, never any other schema:

```bash
scripts/remote.sh hammer php tests/concurrency/hammer.php                 # all five scenarios, ~90 s
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
| 5 | The per-item value sequence (C0, I3) under cross-warehouse races. For 30 s, 20 traders work 6 legacy items (100,000 each at MAIN, with a cost): staff movements of 1–4 items in random line order with a random MAIN/VERIFY warehouse per line (goods-in at £0.50–9.99, write-offs, adjustments ±1..5), MAIN → VERIFY transfers, reserve → commit → ship, commit → cancel to VERIFY, VERIFY counts. An observer polls the seqs every 100 ms in one statement (an item showing a gap fails the run) and checks `CW\Invariants` on a consistent snapshot every ~2 s. At the end every item is numbered 1..N (N = its on_hand rows), and replaying its rows in seq order reproduces every row's `balance_after` per location and the item's total on_hand. No error or deadlock surfaces. Info: calls/s, on_hand rows/s, how often seq order differed from ledger id order. |

Connection budget: the staging cluster allows 76 connections in total, shared by every slot. The
hammer holds at most 30 at once (24 workers + 1 observer by default, plus the parent, which
holds none while children run). Before starting, it checks the cluster's free connections and
keeps 8 spare for other slots. It shrinks the pool if needed, and refuses to start below 8.

Last results (2 Oct 2026, staging, slot `i1c0`, 24 workers, seed 20261002, with C0: `docs/decisions.md` I9):
- `RESULT: PASS (57 checks passed, 0 failed)` in 87 s; 0 deadlocks surfaced and 0 retried inside CW in every scenario.
- Scenario 4 before/after C0 (`--only=4`, three runs each): 54, 62, 61 operations/s before (median 61) and 51, 60, 63
  after (median 60): unchanged (−1.6 %; the stop-and-report threshold was 25 %, the target 15 %). In the full run: 67/s.
- Scenario 5: 1,072 calls (36/s) and 1,634 on_hand rows (54/s) in 30 s; 231 seq polls without a gap; 13 live snapshots
  without a violation; seq order differed from ledger id order 26 times (commit order across warehouses, as designed).
- `LockOrderTest` (2,000-line goods-in): feed rows written within 66/69/67 ms before and 190/70/47 ms after (median of
  14 runs each: 65 vs 78 ms; one after-run hit 313 ms while the cluster was slow, see I9). Re-measure on a quiet cluster.

Phase I-2 after its review fixes (2 Oct 2026, slot `i2fx`, seed 20261002, 24 workers; the invariants now include S1–S5 and
P1–P6; decisions I72–I89): `RESULT: PASS (57 checks passed, 0 failed)` in 91 s; 0 deadlocks surfaced or retried; scenario 5:
1,349 calls (45/s), 1,984 on_hand rows (66/s), 206 seq polls without a gap, 11 live snapshots (slowest check 2.9 s) without a
violation; scenario 4 alone (`--only=4`, twice): 1,666 and 1,737 operations (**56/s and 58/s**, within 15 % of I-1's 62/s; the
pos task's 39/s was the cluster that hour, I59).

After the I-1 review fixes (2 Oct 2026, slot `i1fx`, seed 20261002, 24 workers; decisions I28–I37): `RESULT: PASS (57 checks
passed, 0 failed)` in 86 s; 0 deadlocks surfaced and 0 retried in every scenario (the new I29 guard never fired); scenario 4:
1,862 operations (62/s), 59 live snapshots without a violation; scenario 5: 1,397 calls (47/s), 2,073 on_hand rows (69/s),
226 seq polls without a gap, 13 live snapshots without a violation, 2,079 rows replayed in seq order = every balance; seq
order differed from ledger id order 33 times. What C0 costs per on_hand operation (about 4 ms, taken under the balance
locks; median −7 % on an on_hand-heavy mix) is in I30: size the I-3 goods-in bench with it.

Phase I-1 regression (2 Oct 2026, slot `i1do`: C0 + roles + documents, seed 20261002, 24 workers): `RESULT: PASS (57
checks passed, 0 failed)` in 90 s; 0 deadlocks surfaced and 0 retried in every scenario; scenario 4: 1,844 operations
(61/s), 60 live snapshots without a violation (the invariant check now includes the document checks D1–D7); scenario 5:
1,728 calls (58/s), 2,537 on_hand rows, 237 seq polls without a gap. The document races run in PHPUnit
(`tests/Integration/Documents/{NumberSeriesRace,PostingRace}Test.php`, ≤ 12 processes): 1,200 number allocations by 12
workers (120 rolled back) gave exactly 1..1,080; 64 postings by 8 workers mixed with 60 staff moves by 4 on the same 6
items: 0 deadlocks retried or surfaced, numbers 1..64.

Earlier results (26 Sep 2026, staging, 24 workers):
- The first full run found the H1 deadlock in scenario 3. It was fixed, with the regression test
  `IdempotencyRaceTest`.
- Since the fix: `RESULT: PASS (50 checks passed, 0 failed)` in ~50 s.
  - 0 deadlocks surfaced, and 0 retried inside CW, in every scenario.
  - Scenario 4 ran about 2,000–3,000 operations in 30 s (70–100/s, limited by the feed clock,
    D39) and checked about 60 live snapshots.

## Loading the first match (linking backend, `docs/decisions.md` M11–M15)

All four tools share the job frame (`CW\Ops\Cli`): `--db=<schema>` (default app.env `db_name`),
`--admin` for test schemas, exit 3 when the schema is not at the code's migration, one run at a time
per tool and schema. They print one summary line; problems go to stderr (at most 20 per kind). Run
them on staging from a copy that holds the input files (the export and run files are on the web
server: copy `/root/cw_work/first_match/{*.jsonl.gz,run2,run3,private/run3}` over first).

```bash
php bin/create_channel.php --code=vapeandgo --name="Vape and Go" ...   # once per site (electrofag, vapebig)
php bin/create_staff.php --email=lead@example --roles=mapping_lead     # prints password= and otpauth= ONCE
php bin/import_listings.php --channel=vapeandgo --file=vapeandgo_listings_<ts>.jsonl.gz
php bin/import_listings.php --channel=electrofag --file=electrofag_listings_<ts>.jsonl.gz
php bin/mint_vpg.php --features=run2/listings_features.jsonl --staff=lead@example --channel=vapeandgo --dry-run   # counts + duplicate report
php bin/mint_vpg.php --features=run2/listings_features.jsonl --staff=lead@example --channel=vapeandgo
php bin/import_proposals.php --run-dir=run3 --private=private/run3 --channel=electrofag --vpg-channel=vapeandgo [--dry-run]
php bin/seed_barcodes.php [--dry-run]                                  # sku_barcode from the minted listings (mint_vpg does it for what it mints)
```

`mint_vpg` and its bulk decisions need a `mapping_lead` (M7): an `admin` gets 403 for mapping decisions.
The `--channel` defaults of `mint_vpg` and `import_proposals` are `vpg` and `alt`; pass the real codes.

| Tool | Does | Re-run | Exit 1 when |
|---|---|---|---|
| `import_listings` | export lines → `listing_profile` (+ `unmapped` listing rows), batches of `--batch` (500) per transaction; a barcode the checks refuse is dropped and counted, the listing is kept (M17) | every profile `unchanged` | a line fails the `PUT /v1/listings` checks (skipped) |
| `mint_vpg` | features → `listing_profile.features`; one item + applied `link` per seed listing (`bulk_batch_id vpg_mint:<run>`); duplicate groups → merge-suggestion proposals | linked listings skipped, proposals `exists` | a seed listing has no row (import first) or a mint failed |
| `import_proposals` | one `match_run`, one proposal per Electrofag listing (CWP ids → minted items, candidates from the private ref maps), unmapped → suggested | proposals `exists` | a proposal's listing was not imported |
| `seed_barcodes` | usable GTINs of each item's origin listing -> `sku_barcode` (GTIN key); a key already on another item is marked unusable, not moved (M25) | rows already there are `already` | never (clashes are counted) |
| `create_staff` | a staff user; adds `ui_secret_key` to app.env if missing (never printed) | refuses an existing e-mail | refused |
| `reset_staff` | an existing account: `--new-password`, `--new-totp`, `--deactivate`, `--activate`; ends its sessions (U23) | each run issues new secrets | unknown account |

Measured on `cw_staging` (30 Sep 2026): `mint_vpg` 14,856 mints (one transaction each) in 7 min 9 s (about
2,300 per minute, one connection); `import_proposals` run3, 2,623 proposals, in 59 s; the idempotent re-run
of `import_listings` (every profile `unchanged`) takes 40 s for 29,105 lines and 12 s for 9,014.
`mint_vpg --limit=N` mints only N (a trial). Nothing here moves stock: adoption
of units sold while unlinked happens only for listings that already sold through CW.

### First-match data on `cw_staging` (loaded 30 Sep 2026)

Catalogue text only, no customer data. Loaded by the four tools above; the input files sit in
`/srv/cw-import/` (0700), the one-time channel keys in `/etc/cw/channel_keys.env` and the staff
credentials in `/etc/cw/initial_staff.txt` (both 0600; shred the staff file once the credentials are handed over, see
"Staff accounts").

| What | Figure |
|---|---|
| Channels | `vapeandgo`, `electrofag`, `vapebig`: mode `off`, warehouse MAIN, key set, no allowed IPs (every API call is refused until `--ips` is set) |
| Vape and Go listings | 29,105: 14,856 `mapped` (the seed, one minted item each, `u = 1`), 14,249 `unmapped` (not seed) |
| Electrofag listings | 9,014: 2,623 `suggested` (run3 proposals), 6,391 `unmapped` (6,373 without a sale in 365 days, 18 ignored-but-sold by run3) |
| Items | 14,856, all `sell_policy = legacy`, code = `CW-` + zero-padded id |
| Decisions | 14,856 applied `link` (`bulk_batch_id vpg_mint:run2`, decided by the mapping_lead placeholder) + 2,623 applied `suggest` (system) |
| Proposals | run3 2,623 (Key 936, Check 1,028, New item 119, Can't tell 420, Conflict 73, Manual 47) + 165 VPG duplicate proposals (145 groups, 165 non-keeper listings, all open, band Manual) |
| Stock | `stock_balance` and `stock_ledger` empty; `stock_change` holds 14,856 `link` + 2,623 `status` feed rows |
| Barcodes (`sku_barcode`, seeded 30 Sep 2026, M25) | 13,082 rows on 11,297 items (13,079 usable; 3 GTINs sit on two items and are marked unusable); 472 unusable codes (short, bad check digit, text) not seeded; 11,299 of the 14,856 origin listings carry a usable GTIN |
| Coverage (units on linked listings) | Vape and Go 99.53% of 30-day and 95.36% of 365-day units; Electrofag 0% (proposals only); protected share 0% (all items `legacy`) |

The Vape and Go units on unlinked listings are almost all variants the site has binned (970 of the 1,059
unlinked listings with a sale, 244,836 of the 250,798 unlinked 365-day units).

Reconciling with the files (re-verified after `0005`, 30 Sep 2026: every figure above unchanged; only `sku_barcode` and the
DELETE grants changed):
- The Electrofag export manifest says `variants_with_sales_365d: 2673`, but the file and `cw_staging` hold 2,641 sold listings:
  `tools/first_match/export.php` counts every variant id with sales in the order data, including 32 ids that are no longer in
  the variants table (deleted variants, so not exported).
- The Vape and Go barcode count is 1 lower in `cw_staging` than in the export: the junk code "Black Grey" is dropped on import (M17).
- `proposals.csv` names items `CWP-<vpg variant id>`; the screens show that id next to the CW code (`CW-<zero-padded sku id>`) of
  every item minted from Vape and Go (review screen, search, item page), so a CSV row and a screen can be matched.
- The 165 VPG duplicate proposals (merge suggestions, lane `vpg_duplicate`, 145 groups) are **not reviewable on the screens**:
  they sit on mapped listings, which no queue lists, and there is no merge screen (U12, U19). Many are not the same product (in 85
  of the 145 groups the titles differ in strength, size or flavour). Do not merge them in bulk: each needs two people through
  `DecisionService::decide(merge_skus)` and approve, and a merge that contradicts a reject is refused (M22).
- Un-minted Vape and Go variants (`Bin`, `Discontinued`) can hold a barcode that a "New item" of Electrofag would duplicate: 60 of
  the 65 New item proposals with a barcode are in that case; the review screen lists them under "This listing's barcode is also on"
  and does not preselect "Mark as a new item" there (U18, U20).

## Key spot-check and bulk confirm (`docs/decisions.md` M26-M28; the owner's decisions of 2 Oct 2026)

Who: the owner (a mapping lead) on the CW server and on the screens. The sample belongs to the lead who draws it: only that
lead's confirmations count and only that lead runs its bulk confirm. Every tool is a dry run unless given `--apply`, writes
nothing in a dry run, and shares the job frame of the tools above (`--db`, `--admin` for test schemas, one run at a time, exit 3
when the schema is not at the code's migration). Deploy first, with `0012_key_bulk.sql` (`deploy/staging/install_cron.sh
--migrate`). Nothing here moves stock, except what every link does: units the listing sold while it was unlinked are adopted
(none on staging: no site has sold through CW yet).

```bash
# 1. Re-band the open proposals with the current band rules (b2.1: Key from confidence 85, M26)
php bin/reband_proposals.php --by=<owner e-mail>                  # moves by old>new, what blocks them, what is skipped
php bin/reband_proposals.php --by=<owner e-mail> --apply          # applies the Check>Key moves; a re-run moves nothing
# 2. The spot-check sample (20, stratified 90-100 / 85-89)
php bin/sample_proposals.php --name=<sample> --by=<owner e-mail>            # population, strata, allocation; draws nothing
php bin/sample_proposals.php --name=<sample> --by=<owner e-mail> --apply    # the server draws the seed, stores the 20 and the population
php bin/sample_proposals.php --name=<sample> --verify                       # optional: re-draws it from the stored seed (read-only)
# 3. The owner decides the 20 on the screens: /ui/review/samples -> the sample -> each listing (confirm, reject, or decide)
# 4. The bulk confirm (refuses unless all 20 are confirmed by the owner and the sample is fit)
php bin/bulk_confirm_key.php --sample=<sample> --lead=<owner e-mail> --report=/root/key_bulk_<sample>_dry.csv
php bin/bulk_confirm_key.php --sample=<sample> --lead=<owner e-mail> --apply --limit=10   # a canary: check them on the screens
php bin/bulk_confirm_key.php --sample=<sample> --lead=<owner e-mail> --apply              # the rest; a re-run links only what is left
# 5. Only if needed: the undo (back to the Key queue, one at a time; any mapping lead)
php bin/bulk_unlink.php --batch=key_bulk:<sample> --lead=<owner e-mail>
php bin/bulk_unlink.php --batch=key_bulk:<sample> --lead=<owner e-mail> --apply
```

| Tool | Does | Re-run | Exit 1 when |
|---|---|---|---|
| `reband_proposals` | replays every open proposal's evidence: under its own run's band version (must give back its stored band, else `evidence_mismatch`, left alone) and under the current one; applies Key<->Check moves as a new proposal of run `reband-b2.1` that supersedes the old one, attributed to `--by` | moves nothing twice | a move failed |
| `sample_proposals` | writes the proved bases of older proposals (M27), then draws `--size` (20 or more) from every open Key proposal that qualifies (M28), stratified by confidence, lowest sha256("seed:proposal id") first, with a seed the server draws; stores the sample, its seed and the whole population | a name is used once (409) | the name exists, too few proposals qualify, an override is not allowed, or `--verify` differs |
| `bulk_confirm_key` | refuses unless the sample is complete and fit and `--lead` is its owner; links every proposal of the sample's population that still qualifies, one DecisionService decision each (`bulk_batch_id key_bulk:<sample>`, decided by the owner) | links only what is left (`already_in_batch`) | refused or stopped, or a link failed |
| `bulk_unlink` | unlinks every listing still linked by the batch (`bulk_batch_id undo:key_bulk:<sample>`) and re-opens its proposal (run `undo:key_bulk:<sample>`) | `undone` ones are left; a waiting unlink is re-opened after its approval | an unlink failed |

What to look for:
- **Re-band.** `moves={"Check>Key":N}`: run3's files give N = 435 (confidence 85-89 only); the staging figure is lower by the
  ones decided since. `skipped` should be `manual` only; any `evidence_mismatch` means a stored band its own evidence does not
  give back (stop and look). `blocked` lists moves not applied: `pending_decision`, `listing_rejected_before`,
  `listing_decided_since`, `changed:<part>` (the listing, its item or the key's target listing changed since the proposal),
  `no_basis` (an older proposal that cannot be proved unchanged), `target_relinked` / `target_unresolved` (the Vape and Go
  listing the key named is not linked to the item with units 1 now), `evidence_title_differs` /
  `evidence_target_title_differs` (a title the judge saw is not the listing's title now). Those stay Check: a person decides them.
- **Sample.** The dry run prints the strata (`stratum conf_90_100 (confidence 90-100): 14 of ...`, `conf_85_89: 6 of ...`), the
  population and why the other Key proposals are left out (`excluded={...}`, the reasons below), and draws nothing. `--apply`
  prints the seed the server drew and the 20 (`#position proposal listing confidence stratum`). `--seed` is refused. Run step 1
  first: a sample drawn before the re-band has no 85-89 stratum (its population has none), and its bulk confirm covers the
  90-100 proposals only.
- **The 20.** `/ui/review/samples` (menu Linking -> Key spot-check) shows "n of 20 decided" and each one's state; each row opens
  the normal review screen, which leads back and says "#n of 20 in your Key spot-check". Confirm only what is right, with
  units 1. One rejection, any other decision (new item, ignore, another item, units other than 1), a decision that needs a
  second person, or a decision by anyone but the owner makes the sample FAIL, and the bulk confirm then refuses it for good.
  Anyone else opening one of the 20 is told to leave it to the owner. A failed sample's listings are never drawn into another
  sample: find out why the Key band was wrong first; after a new matching run (or a new band version) a new sample may take
  the NEW proposals of those listings with `--after-failed=<failed sample>` (audited).
- **Bulk dry run.** `population` (the sample's population minus the 20), `eligible`, `excluded` by reason, `units_30d` /
  `units_365d` of the eligible ones; the `--report` file lists each proposal: `eligible` (listing, title, item code and name,
  confidence, units: what will be linked to what) or `excluded` with its first reason. Reasons a proposal is left out (each a
  person decides on the screens):

  | Reason | Meaning |
  |---|---|
  | `proposal_decided`, `proposal_superseded` | already decided (by a person, or an earlier run of this batch), or replaced by a later proposal |
  | `listing_mapped` / `listing_ignored` / `listing_quarantined`, `pending_decision` | the listing is no longer waiting, or waits for a second person |
  | `protected_item`, `counted_item`, `item_merged`, `item_quarantined` | the item is protected or counted (two people), merged away, or has a quarantined listing |
  | `units_per_item`, `flag:<flag>` | not one unit per item, or flagged for two people / a relabel |
  | `listing_rejected_before`, `item_rejected_before`, `listing_decided_since`, `bulk_undone_before` | a person said "not this item" somewhere, decided on the listing since, or an earlier bulk link of it was undone |
  | `failed_sample_population`, `in_other_sample` | the listing was in a failed spot-check's population, or the proposal is in another spot-check's |
  | `no_basis`, `changed:listing_profile`, `changed:listing_link`, `changed:item`, `changed:item_origin`, `changed:target_link` | nothing proves the proposal still describes the listing, the item and the key's target listing |
  | `target_unresolved`, `target_relinked` | the Vape and Go listing the key named was not found, or is not linked to the item with units 1 now (relinked, ignored, a multiple) |
  | `evidence_title_differs`, `evidence_target_title_differs` | a title the judge saw (from the run's export) is not the listing's title now |
  | `not_key_now`, `evidence_unreadable`, `not_key_target` | under the current rules the evidence is not Key, or the item is not both the key target and the judge's pick |
- **Apply.** `applied=N skipped={} failed={}`. A `failed` code (e.g. `map_version_conflict`: someone changed the listing a
  moment earlier; `target_changed`: the key's target listing changed during the link) leaves that listing untouched; run again
  later. `stopped=sample_changed_during_run` (exit 1): a sample member was decided otherwise during the run; the links made
  before stand (undo if needed). Each decision is an ordinary applied `link` (`mapping.link` in `audit_log` with the batch id),
  so the screens, the item pages and the feed show them like any other.

Checks afterwards (read-only):

```sql
SELECT state, COUNT(*) FROM match_decision WHERE bulk_batch_id = 'key_bulk:<sample>' GROUP BY state;     -- all applied
SELECT action, actor, detail FROM audit_log WHERE action IN ('mapping.reband', 'mapping.key_sample', 'mapping.key_bulk', 'mapping.key_bulk_undo') ORDER BY id;
SELECT source, COUNT(*) FROM match_proposal_basis GROUP BY source;                                       -- recorded / backfill
```

## Building the next first-match run (`docs/decisions.md` M29)

On the web server, read-only on the exports. Engine `n2.1/c1.0/v2.1/b2.1/f2.1+fv1-aeaa0af8/tp1.1` (run3 was built with
`n2.0/c1.0/v2.0/b2.0/f2.0+fv1-aeaa0af8/tp1.0`).

```bash
nice -n 19 php -d memory_limit=2G tools/first_match/run.php --out=/root/cw_work/first_match/run4 --judge=sold \
    [--scratch=/root/cw_work/first_match/judge_scratch/run4]      # the default
```

- The build makes one empty scratch directory per chunk (mode 0700) and stops if one already holds files: remove them or pass
  another `--scratch`. Each chunk file names its own directory (`scratch_dir`), and `private/run4/run4_chunks.json` lists them.
- Judges run in parallel. Give each judge its chunk, the prompt and **its own** `scratch_dir`. Never give them one shared
  directory: run3's judges all used one, under the same file names.
- Assemble as before (`tools/first_match/assemble.php`). `proposals_summary.listing_extract_form` shows how many of the judges'
  form answers were outside the enum (information only).
- Golden tests: `php tests/matching/run.php`. It also runs in the suite as `tests/Unit/MatchingGoldenTest.php`.

## Opening stock (`bin/import_opening_estimate.php`, `docs/decisions.md` D40, D40a)

The estimate of a site's stock, before counts. Run it on the CW server. Always do a dry run first:

    php bin/import_opening_estimate.php --csv=<file> --as-of=<ISO time with offset> --doc-ref=opening:<channel>:<as-of> \
        --source="<where the figures come from>" --sha256=<hash of the archived file> [--approved-by=<who>] [--channel=<code>] [--dry-run]

The CSV has 2 columns, the variant id and the figure, under a header like
`vapeandgo_variant_id,site_qty_at_...`.

The dry run reports:
- rows at or below zero, which are skipped;
- unknown variants;
- unlinked listings, by status;
- items left alone (`counted` / `earlier_opening` / `moved_before_as_of`);
- items already booked by this opening;
- what it would book.

A `quarantined` listing with stock stops the run: decide its link first. If a run stops halfway, run
the same command again; it books only the missing items. A different file under the same doc_ref is
refused (`opening_conflict`).

**At a site's T0 the estimate must be rebased** (D40a: site stock at T0 + open paid units). The tool
has no rebase mode yet, so this is a Phase 3 prerequisite. Until T0, book no counts or adjustments on
that site's items.

**On `cw_staging`:** Vape and Go's duty-day stock (00:00 BST, 1 Oct 2026) was booked on 2 Oct 2026.
That is 8,199 items and 296,599 units. The input is
`/srv/cw-import/opening_input_vapeandgo_duty_start_2026-10-01.csv` (sha256 3561293a…), derived from the
archived duty workbook. Checks: invariants hold; all 14,856 linked listings match the file; a re-run
books nothing.

## The staff UI (`/ui`, linking backend front end; `docs/decisions.md` U1-U17)

Server-rendered PHP, no JavaScript framework and nothing from a third party: two static files
(`/ui/assets/app.css`, `app.js`) served by the front controller under the CSP
`default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'`.

Who sees what is decided per **permission** (`src/Auth/Permissions.php`, decisions I11–I16): a person holds one or more
roles, may do what any of their roles may, and a page outside their permissions is refused (403), not only left out of
their menu. Roles are read on every request: a role taken away stops working on the person's next page.

| Screen | Permission | Roles |
|---|---|---|
| `/ui/login`, `/ui/password`, `POST /ui/logout` | public (sign-in), any signed-in (the other two) | everyone (password change is forced at first sign-in) |
| `/ui/`: the linking dashboard (queue counts, coverage) with `linking.view`, else a home page with the person's menu | any signed-in | everyone |
| `/ui/review?queue=<band>&channel=<code>`, `queue=pending` (second approval), `/ui/review/listing/{id}` | `linking.view` | viewer, mapper, mapping_lead, warehouse, manager, admin, auditor |
| the decision form: link, new item, ignore, reject, withdraw | `mapping.decide` | mapper, mapping_lead |
| approve a waiting decision | `mapping.approve` | mapping_lead, never the person who decided |
| `/ui/items/{id}`, `/ui/search` | `catalogue.view` | all 14 roles |
| `/ui/people`, `/ui/people/{id}` (People and roles: list, history) | `staff.view` | admin, auditor |
| change a person's roles, switch an account off/on (never one's own) | `staff.manage` | admin |
| `/ui/documents/reviews` (Review queue: blocking approvals, then posted documents waiting for review) and its approve / reject forms | `documents.review` (an approval also needs `documents.approve`); never the document's creator, submitter or poster, never admin | reviewer |
| `/ui/documents` (list: type, status, review, number or external reference), `/ui/documents/{id}` (header, lines, files, review history, reversal links), `/{id}/pdf`, `/ui/files/{id}` (stored files) | `documents.view` | buyer, purchasing_manager, goods_in, purchasing_desk, stock_controller, reviewer, accountant, auditor, manager, warehouse |
| reverse a posted document (the form on its page) | `doc.<TYPE>.post` of its type, never admin | the type's posting roles (I16) |
| `/ui/reference/reasons` (+ `reasons.csv`), `/ui/reference/series` | `reference.view` | all 14 roles |
| `/ui/people.csv` (the People list for Excel) | `staff.view` | admin, auditor |
| Purchasing (I-2), Receiving (I-3/I-4), Stock control (I-4), Trade (I-6), Accounts (I-5): shown as "coming in Phase I-n" | `doc.<TYPE>.post`, `accounts.view` | proposals pending decisions 3 and 11 (I16) |

**Separation of duties (I12):** `admin` manages people and roles and nothing else: it can be combined only with viewer,
accountant and auditor, so the person who gives roles never posts, reviews or decides (the screen and the tools refuse
anything else with the reason). The owner is the backup **reviewer** (decision 3), so **the owner is not the admin**: name
another person as admin. **At least two active reviewers** (decision 3, the owner one of them): the People list warns while
there are fewer, and while nobody is admin.

### Where it runs today

- **Test copy (slot `ui`):** `http://127.0.0.1:8080/ui/` with `Host: cw-ui.staging.invalid`, schema `cw_test_ui`
  (`scripts/remote.sh ui bash deploy/staging/install_ui.sh`; `docs/dev.md`). Loopback only.
- **Real staging data (`cw_staging`): not served by anything yet.** The public vhost below is what serves it.

### Staff accounts

On the staging box, in `/opt/cw-staging`, as root (prints the one-time password and the `otpauth://` URI ONCE
on stdout, messages on stderr; add `ui_secret_key` to `app.env` if it is missing, never printing it):

```bash
cd /opt/cw-staging && php bin/create_staff.php --email=<address> --roles=<role>[,<role>...] --name="Display Name"
# e.g. --roles=buyer · --roles=purchasing_desk · --roles=reviewer (the owner: reviewer, never admin) · --roles=admin,auditor
```

Roles: viewer, mapper, mapping_lead, warehouse, manager, admin, buyer, purchasing_manager, goods_in, purchasing_desk,
stock_controller, reviewer, accountant, auditor (what each may do: `/ui/people/<id>` lists them with a description).
`--role=<one>` still works as an alias of `--roles` (never both). After that an admin gives and takes roles and switches
accounts off on **People and roles** (`/ui/people`); the role history and `audit_log` (`staff.roles`, `staff.deactivate`,
`staff.activate`) keep who changed what.

Give the person the password and the URI over different channels; they scan the URI into an authenticator app
(SHA-1, 6 digits, 30 s) and must choose a new password (12-200 characters) at the first sign-in. The load ran
under a placeholder `mapping_lead` (docs/decisions.md, "Open after the linking backend"): create real people
first, then deactivate the placeholder. Two people are needed for links that touch a protected item, units per
item other than 1 and merges, so create at least two `mapping_lead`/`mapper` accounts.

Recovery (never re-create or delete an account: decisions name it), in `/opt/cw-staging` as root:

```bash
php bin/reset_staff.php --email=<address> --new-totp        # lost phone / leaked seed: prints a new otpauth:// URI once
php bin/reset_staff.php --email=<address> --new-password    # forgotten password: prints a one-time password once
php bin/reset_staff.php --email=<address> --deactivate      # someone leaves (or the placeholder); --activate undoes it
php bin/reset_staff.php --email=<address> --roles=admin     # break-glass: replace the roles (stderr: roles: a,b -> admin)
```

`--roles` follows the screen's rules (admin only with viewer/accountant/auditor) and is audited `staff.roles` as
`system:reset_staff`; on its own it keeps the person's sessions (the new roles apply on their next page).

Placeholder accounts (an e-mail under `.invalid`, such as the mapping_lead the first load ran as) are never switched on or
given a role on the screen (409, I35); the People list warns while one is active. If one must really be switched on (it
never should on the public vhost: `enable_https.sh` refuses while one is active), `bin/reset_staff.php --activate` on the
server is the break-glass.

Every reset signs the person out everywhere and is audited (`staff.reset`, `staff.deactivate`, `staff.activate`).
A person changes their own password on `/ui/password`; that also ends every other session and gives the browser a new one.

**Before the public vhost is switched on** (`enable_https.sh` refuses until both are done):
1. Hand the initial credentials over, then destroy the file that holds them in clear: `shred -u /etc/cw/initial_staff.txt`
   (it holds the one-time passwords and TOTP seeds of staff 1 and 2).
2. Deactivate the placeholder mapping_lead (staff 2): `php bin/reset_staff.php --email=mapping-lead-placeholder@cw-staging.invalid --deactivate`
   (or reset it for a real person). The 14,856 seed decisions keep naming it.

How staff sign in: `https://warehouse-staging.floverfy.com/ui/login` (once the vhost is on): e-mail, password and the current
6-digit code in one form; at the first sign-in the one-time password must be changed.

Sign-in limits (`LoginLimiter`): 10 failures for one e-mail or 30 for one address in 15 minutes refuse further
attempts (the answer never says which), also for attempts made at the same moment (they queue on a named lock, U21). Wait 15 minutes; `login_attempt` and `audit_log` (`login.ok`, `login.fail`,
`logout`) show who tried. Sessions: idle 30 min, absolute 12 h, a new one (and a new id) at every sign-in.

### Documents on the screens (Phase I-1)

No document type is live in I-1 (decision I27): the Review queue says "Nothing is waiting for review", the documents list
says "No document type is live yet" and the reference pages show the 22 reason codes and the 8 number series (next
`PO-000001`, ...). The types arrive with their phases (PO in I-2, GRN in I-3, SINV/DN/CNT/ADJ/WO in I-4, TRD in I-6).
Rules the screens enforce (I17–I19): a posted document is never edited, only reversed (a new document in the same series
with every line negated); documents are posted first and reviewed by a second person, who is never the creator, the
person who asked for an approval or the poster; only a positive adjustment without a supplier document above the limit
(and, from I-2, a new supplier) waits for an approval before it is booked; rejecting a posted document posts its reversal,
rejecting a request cancels it. A reversal that puts more units back on hand than that limit (the reversal of a write-down,
any type) is a request too: "Reversal requested", no number and no stock until a reviewer approves it (I32); while it waits
the document cannot be reversed again. Rejecting the review of a reversal records the rejection and books nothing (a
reversal is never reversed, I31): if the original was right, its poster posts it again as a new document. Downloads (PDF,
CSV, stored files) are attachments, never shown inline; a stored file is saved with the extension of the type CW sniffed
(a text file named `duty.hta` downloads as `duty.hta.txt`, I36).

### Switching on the public HTTPS vhost (NOT done; needs the DNS record)

`warehouse-staging.floverfy.com` (UI + API, same front controller, `/opt/cw-staging`, schema `cw_staging`) is
prepared and **off**. Nothing in `deploy/staging` installs or enables it except `enable_https.sh`, which a person runs.

1. Create the DNS record `warehouse-staging.floverfy.com A 46.101.55.135`, **DNS only** (not proxied: the app
   trusts `REMOTE_ADDR`, and `mod_remoteip` stays disabled).
2. Copy the current code to the live staging copy: `scripts/remote.sh <slot> bash deploy/staging/install_cron.sh`
   (the UI files must be in `/opt/cw-staging`), and run `install_ui.sh` once so the loopback vhost exists.
3. Dry run: `scripts/remote.sh <slot> bash deploy/staging/enable_https.sh --check`. It refuses (changing nothing)
   unless the name resolves to that address and nothing else, the box owns the address, `/opt/cw-staging` holds the
   UI, `cw-ui` is installed, `app.env` `db_name` is `cw_staging`, `/etc/cw/initial_staff.txt` is gone and no placeholder
   account (e-mail under `.invalid`) is active ("Staff accounts" above). It must be run from a slot copy, never from `/opt/cw-staging`.
4. `scripts/remote.sh <slot> bash deploy/staging/enable_https.sh [--email <address>]` (the address is optional:
   Let's Encrypt no longer sends expiry e-mails and renewal is automatic): installs certbot, the pool
   `cw-web` (+ hourly log rotation), the port-80 challenge/redirect vhost, gets the certificate, then installs the
   HTTPS vhost and checks `/ui/login` (200), `/v1/health` without a key (401) and the 301 from port 80.
5. Open TCP 80 and 443 in the DigitalOcean cloud firewall if one is attached (ufw is inactive on this box), set
   channel `--ips` for the API (`bin/create_channel.php`), and create the staff accounts above.

Renewal is certbot's systemd timer (deploy hook reloads Apache). Logs: `/var/log/cw-web/php-error.log` (rotated
hourly at 20 MB) and `/var/log/apache2/cw-https.{access,error}.log`. To switch it off again:
`a2dissite cw-https cw-acme && systemctl reload apache2`.

### UI log rotation and failures

`install_ui.sh` installs `/etc/cw/logrotate-cw-ui.conf` and `/etc/cron.d/cw-ui-logrotate` (hourly, own state file
`/var/lib/logrotate/cw-ui.status`): `/var/log/cw-ui/*.log` at 20 MB, 10 kept, compressed, copytruncate. A browser error
page shows a request id; `grep <id> /var/log/cw-ui/php-error.log` (or `cw-web`) finds the cause. 503 means the database
refused or was busy (`Retry-After` is sent) or `ui_secret_key` is missing from `app.env`.

## The document store (`/srv/cw-docs`; `docs/decisions.md` I23)

Every file CW keeps (supplier invoices, delivery notes, duty-stamp photos, generated PDFs) is stored once per content
under its sha256, read-only, for at least 7 years (`stored_file.retain_until`), and re-hashed whenever it is read. Files
arrive through the CLI below and, since the I-2 suppliers task, the supplier card's evidence upload (kind `supplier_check`,
≤ 2 MiB, which answers 503 until this store is installed); the receiving screens follow in I-3. **Before live** the local
directory is replaced by an S3 (London) or B2 bucket with Object Lock in COMPLIANCE mode (same interface, `CW\Files\FileStorage`).

Install on staging (**not done yet**: after the I-1 deploy, `install_cron.sh --migrate`, with the owner's go), from this
machine:

```bash
scripts/remote.sh <slot> bash deploy/staging/install_file_store.sh     # idempotent; never from /opt/cw-staging
```

It creates `/srv/cw-docs`, `tmp/` and the shard directories `00`..`ff` (root:www-data 02770), makes the shards
append-only (`chattr +a`: a file can be added, never removed or renamed, by anyone including root; it warns if the file
system cannot), adds `file_store_dir = /srv/cw-docs` to `/etc/cw/app.env` (mode and group kept), and checks that `rm` of a
probe file in a shard fails (the probe `/srv/cw-docs/00/.append-only-probe` stays; it is not a sha256 name, so the verifier
ignores it). It then seals every stored file (`seal_file_store.sh`: root:www-data 0440 and `chattr +i`) and checks that a
sealed probe (`/srv/cw-docs/01/.immutable-probe`, kept) cannot be rewritten in place by www-data or root. From then on the
cron sweep seals new files every minute.

**What protects a stored file on staging (I36):** append-only shards stop rm and mv; the seal (immutable flag) stops an
in-place rewrite, also by root, once the sweep has run (at most a minute after the store); before that, and against
someone who removes the flag, the protection is detection: every read re-hashes (a changed file is never served) and
`verify_files.php` runs nightly. The Object Lock bucket before live prevents all of it.

To remove a file anyway (a court order, a file stored by mistake): `chattr -i` the file and `chattr -a` its shard, remove it,
`chattr +a` the shard again, and record why; its `stored_file` row stays and `verify_files.php` reports it missing from then
on. Retention: a file is kept 7 years from its first store (`stored_file.retain_until`) and 7 years from every attachment to
a document (`document_file.retain_until`), whichever is later.

Store and verify, on the staging box in `/opt/cw-staging` as root:

```bash
php bin/store_file.php --file=/root/sample-duty-evidence.pdf --kind=duty_evidence --note="sample"
#   id=1 sha256=<64 hex> size=<bytes> mime=application/pdf deduped=0      (the same content again: deduped=1)
sha256sum /root/sample-duty-evidence.pdf                                   # = the sha256 printed
php bin/verify_files.php                                                   # ok: checked=1 ok=1 missing=0 mismatch=0 orphans=0 ms=..
rm /srv/cw-docs/<first 2 hex>/<sha256>                                     # must fail: Operation not permitted
bash deploy/staging/seal_file_store.sh                                     # (cron does it within a minute) sealed=1 failed=0
lsattr /srv/cw-docs/<first 2 hex>/<sha256>                                 # ----i---------e------- : immutable
printf x >> /srv/cw-docs/<first 2 hex>/<sha256>                            # must fail: Operation not permitted (even as root)
```

| Tool | What it does | Exit codes |
|---|---|---|
| `bin/store_file.php --file=<path> --kind=<kind> [--note=..]` | kinds: supplier_invoice, delivery_note, packing_list, photo, duty_evidence, generated_pdf, supplier_check, other. Type sniffed from the content (PDF, JPEG, PNG, CSV, text, XLSX only), ≤ 25 MiB, audited `file.store` as `system:store_file`. | 0 stored · 1 refused (type, size, kind) · 2 usage · 3 cannot run (no `file_store_dir`, database) |
| `bin/verify_files.php [--limit=N]` | Re-hashes every stored file through the store. `missing id= sha256=` / `mismatch id= sha256=` on stderr; orphans (bytes no row names, left by a failed commit) are counted and kept. Scheduled nightly at 04:27 (`cw-staging.cron`, I36). | 0 intact · **1 missing or changed** · 2 usage · 3 cannot run |
| `deploy/staging/seal_file_store.sh [root]` | Seals stored files not yet immutable (root:www-data 0440, `chattr +i`); every minute from cron, once by the installer. | 0 ok · 1 a file could not be sealed · 3 cannot run |

A changed or missing file is never served: the screen answers 500 with a request id (`grep <id>
/var/log/cw-ui/php-error.log` shows which file). Keep the evidence, compare with the backup, never "fix" the row.


## Purchasing, Phase I-2: settings and the ERPNext supplier seed (`docs/decisions.md` I38–I47)

### Settings (`bin/settings.php`)

CW's settings live in `app_setting` (0009): the company that buys and owns the warehouse stock (decision 9: the PO
letterhead), the supplier approval rules (decision 11), the cost write-back switch (decision 12) and, from the later I-2
tasks, the PO and reorder defaults. Every seeded value is **provisional** until the owner confirms it; the screen
`/ui/reference/settings` (every role) shows each one with its decision number. The app login can only read them.

```bash
# on the staging box, in /opt/cw-staging, as root (or from a slot: scripts/remote.sh <slot> php bin/settings.php ... --db=cw_test_<slot> --admin)
php bin/settings.php --list                                                   # key, type, value, [provisional], decision
php bin/settings.php --set=company.legal_name --value="Example Vapes Ltd" --reason="owner's company details, 3 Oct" --admin
php bin/settings.php --set=company.address --value-file=/root/address.txt --reason="registered office" --admin   # several lines
php bin/settings.php --set=company.confirmed --value=true --reason="owner confirmed the details" --confirmed --admin
```

- `--set` needs `--admin` (the admin login of `/etc/cw/db.env`): without it the tool stops at once with "settings change
  needs --admin (cw_app has SELECT only)", exit 1.
- The value is parsed for the setting's type: `int` (whole number), `decimal` (`0.50`, kept exactly as typed), `bool`
  (`true`/`false`), `string` (one line, ≤ 255), `text` (≤ 4000, line breaks allowed: give it with `--value-file`), `date`
  (`YYYY-MM-DD`); an empty value clears a string, text, number or date. Key rules (`Settings::RULES`): day counts 0–120,
  `suppliers.approval_due_days` 1–120, `company.email` an address, `po.default_vat_code` an active VAT code, weights 0–1.
- `--confirmed` also records that the owner confirmed the value (the "provisional" mark goes).
- A change writes `updated_actor = system:settings` and the audit row `setting.change {key, before, after, reason}`;
  sending the value the setting already has writes nothing ("unchanged").
- Exit codes: 0 done · 1 `--set` without `--admin` · 2 usage, unknown key, bad value or reason (3–500 characters) · 3 cannot run.

| Key | Type | Default | Decision | Meaning |
|---|---|---|---|---|
| `company.legal_name`, `trading_name`, `company_number`, `vat_number`, `phone`, `email` | string | empty | 9 | the PO letterhead; an empty value prints `[to be confirmed]` |
| `company.address`, `company.delivery_address` | text | empty | 9 | one line per line |
| `company.confirmed` | bool | false | 9 | false: every PO PDF says "COMPANY DETAILS NOT CONFIRMED — DO NOT SEND" |
| `costs.site_writeback` | bool | false | 12 | CW's average cost into the sites' cost field: **not built in I-2**, nothing reads it |
| `suppliers.approval_due_days` | int | 3 | 11 | an activation / import-route approval is due this many days after it is asked for |
| `suppliers.change_review` | bool | true | 11 | identity changes of an active supplier open a (non-blocking) review |

### Suppliers on the screens

Purchasing › Suppliers (`/ui/purchasing/suppliers`). A buyer creates a supplier (a **draft**), completes it — address,
postcode, country, e-mail or phone, payment terms, the due-diligence check (date, who, next review) and, for an overseas
supplier, its import route (how and where UK duty stamps are applied) — and presses "Ask a second person to activate it".
The supplier then waits (`pending_approval`, nothing can be changed; the requester may withdraw). A **reviewer** who did not
create it, ask for it or last change it finds it in Document reviews › "Waiting for approval (blocking)" and approves or
rejects it on the supplier's page; admin never decides. Only an active supplier will get purchase orders (pos task). An
identity change of an active supplier opens a review that does not block (rejecting it deactivates the supplier); a change
of an overseas supplier's import route, **or of whether it is overseas at all** (ticking or unticking "overseas"), blocks its
POs until a second person approves it (rejecting "no longer overseas" deactivates the supplier; I72). A supplier whose
country is not GB must be marked overseas (provisional, I72). Deactivating needs a reason;
reactivating needs a second person's approval again. Evidence files (due diligence, import route) are uploaded on the card
(≤ 2 MiB, kept in the document store as kind `supplier_check`); **until `install_file_store.sh` has run, the upload answers
503** "the file store is not set up on this server" and the evidence is typed into the text fields instead. (Observed by the
pos task, 2 Oct 2026 14:40 UTC: `/srv/cw-docs` exists on staging since 12:03 UTC with the installer's probes and app.env
names it, so staging has a file store now.) No bank
details are kept in CW (decision 25).

### ERPNext supplier seed (`bin/import_erp_suppliers.php`)

Seeds suppliers and supplier items from CSV files exported from the **restored backup copy** of ERPNext (owner decision 4).
CW never connects to ERPNext, and the indicative SQL below is **never run on live ERPNext**.

```bash
# on the staging box, in /opt/cw-staging (app login), files copied to /srv/cw-import/ (or any path):
php bin/import_erp_suppliers.php --suppliers=/srv/cw-import/suppliers.csv --staff=buyer@example.co.uk --dry-run --report=/root/suppliers-dry.csv
php bin/import_erp_suppliers.php --suppliers=/srv/cw-import/suppliers.csv --staff=buyer@example.co.uk --report=/root/suppliers.csv
php bin/import_erp_suppliers.php --items=/srv/cw-import/supplier_items.csv --staff=buyer@example.co.uk \
    --vpg-codes=/root/cw_work/first_match/vapeandgo_listings_<date>.jsonl.gz --report=/root/items.csv
```

- `--staff` is the buyer the rows are created by (active, `suppliers.manage`): that person can then never approve them.
- New suppliers are **drafts**; an existing ERPNext name is skipped, or with `--update-blank` its empty fields are filled
  while it is draft or inactive. An active (or pending) supplier is never changed by an import: the report lists the
  fields that differ. `--request-activation` asks a second person to activate every supplier of the file that is draft or
  inactive and complete — the due-diligence check is never imported (it is captured fresh in CW), so in practice the
  buyer completes the check on the screen first and a second import with `--update-blank --request-activation`, or the
  card's button, asks for the approval.
- Each file is one `import_run` row (its sha256): the same file again prints "already imported (run N)" and exits 0. A
  dry run checks every row and keeps nothing but its `import_run` row (and does not count as imported).
- A row that cannot be used fails on its own (unknown supplier, unresolved or unlinked or merged item, a bad value, two
  rows making one item preferred — both fail) and is listed in `--report` (`row, key, status, reason`; status created /
  updated / skipped / failed) and on stdout; the other rows are kept. Exit 1 when a row failed or a file was refused
  (no `erp_name` column, ...), 2 usage (also `--staff` who may not manage suppliers), 3 cannot run.
- Columns are matched by header name (case-insensitive, spaces = `_`); unknown columns are ignored and listed in the run's
  summary (a bank column included: decision 25). Files: UTF-8 or Windows-1252 (Excel UK), comma, semicolon or TAB, may be
  gzipped, at most 32 MiB.

**`suppliers.csv`** (* = required): `erp_name*` (ERPNext `Supplier.name`), `name` (default `erp_name`), `code` (default:
the capitals and digits of the name, cut to 12, then `-2`, `-3`… on a clash), `legal_name`, `company_number`,
`vat_number`, `address_line1`, `address_line2`, `city`, `postcode`, `country` (ISO alpha-2, default GB; `UK` reads as GB),
`contact_name`, `email`, `phone`, `payment_terms`, `payment_terms_days`, `default_lead_days`, `review_days`, `is_overseas`
(0/1), `default_vat_code` (S, R, Z, E, RC, OS; default S), `notes`.

**`supplier_items.csv`**: `supplier*` (the ERPNext name or the CW code), `item_ref_type*`, `item_ref*`, `supplier_code`,
`supplier_description`, `purchase_unit` (default `each`), `units_per_pack` (default 1, **in the referenced item's units**),
`moq_packs`, `order_multiple_packs`, `lead_days`, `is_preferred` (0/1), `last_pack_price` (GBP excl. VAT per purchase unit,
≤ 4 decimals), `last_price_date` (`YYYY-MM-DD`, default the import date; not in the future), `last_price_ref`.

| `item_ref_type` | `item_ref` | Resolved through | Central units per pack |
|---|---|---|---|
| `cw_code` | `CW-000123` (or `123`) | the CW item | `units_per_pack` |
| `vpg_variant` | Vape and Go `prodt_id` (= ERPNext item `product_id`) | the `vapeandgo` listing, which must be linked (`mapped`) | × the listing's `units_per_item` |
| `vpg_code` | Vape and Go `prodt_code` | `--vpg-codes` (the first-match listings export: `code` → `variant_id`), then as `vpg_variant` | × `units_per_item` |
| `barcode` | a GTIN (leading zeros ignored) | a usable `sku_barcode` | × `units_per_scan` |
| `erp_item` | ERPNext `Item.name` | `sku_erp_item` | × its `units_per_item` |

Rows are upserted by (supplier, item, central pack): the same pack again updates the given fields; a price becomes a
price-history row (source `import`, `source_ref` = `last_price_ref` or `import_run:<id>`) and the supplier item's last
price when it is the newest. A merged item or an unlinked listing is never guessed at: the row fails.

Worked example (two files and what the import makes):

```csv
erp_name,name,address_line1,postcode,email,payment_terms,is_overseas
Example Wholesale,Example Wholesale Ltd,Unit 2 Park Road,LS1 1AA,orders@example-wholesale.co.uk,30 days EOM,0
Shenzhen Vape Co,,,,,,1
```

```csv
supplier,item_ref_type,item_ref,supplier_code,purchase_unit,units_per_pack,moq_packs,is_preferred,last_pack_price,last_price_date,last_price_ref
Example Wholesale,vpg_variant,48213,EW-ELX-BR,box,10,2,1,16.5000,2026-09-18,PINV-2026-00412
EXAMPLEWHOLE,barcode,5060000000001,EW-CASE,case,6,,,72.00,,
```

→ two draft suppliers, `EXAMPLEWHOLE` and `SHENZHENVAPE` (the second overseas: it needs its import route before it can be
activated); a supplier item "box of 10 listing units" of variant 48213 — with `units_per_item` 1 that is 10 central units
(a 2-pack listing would make it 20) — preferred, MOQ 2 boxes, last price £16.50 a box (£1.65 a unit) from invoice
PINV-2026-00412; and a case of 6 × the barcode's `units_per_scan`.

**Indicative SQL on the restored backup copy** (MariaDB; field names as on ERPNext v15, to be confirmed on the I-0 copy;
`product_id` is the custom Item field that holds the Vape and Go `prodt_id`):

```sql
-- suppliers.csv: every enabled supplier with its primary address
SELECT s.name AS erp_name, s.supplier_name AS name, s.tax_id AS vat_number,
       a.address_line1, a.address_line2, a.city, a.pincode AS postcode,
       CASE WHEN a.country IN ('United Kingdom', 'UK') OR a.country IS NULL THEN 'GB' ELSE '' END AS country,
       s.email_id AS email, s.mobile_no AS phone, s.payment_terms,
       CASE WHEN a.country IS NOT NULL AND a.country NOT IN ('United Kingdom', 'UK') THEN 1 ELSE 0 END AS is_overseas
FROM `tabSupplier` s LEFT JOIN `tabAddress` a ON a.name = s.supplier_primary_address
WHERE s.disabled = 0 ORDER BY s.name;

-- supplier_items.csv: the latest SUBMITTED purchase-invoice line per supplier and item (rate per purchase UOM,
-- conversion_factor = stock units per purchase UOM; check that it is whole: the importer takes whole packs only)
SELECT x.supplier, IF(x.product_id IS NULL, 'erp_item', 'vpg_variant') AS item_ref_type, COALESCE(x.product_id, x.item_code) AS item_ref,
       x.supplier_part_no AS supplier_code, x.item_name AS supplier_description, LOWER(x.uom) AS purchase_unit,
       CAST(x.conversion_factor AS UNSIGNED) AS units_per_pack, ROUND(x.rate, 4) AS last_pack_price,
       x.posting_date AS last_price_date, x.invoice AS last_price_ref
FROM (SELECT pi.supplier, pii.item_code, it.product_id, its.supplier_part_no, pii.item_name, pii.uom, pii.conversion_factor, pii.rate,
             pi.posting_date, pi.name AS invoice,
             ROW_NUMBER() OVER (PARTITION BY pi.supplier, pii.item_code ORDER BY pi.posting_date DESC, pi.name DESC) AS rn
      FROM `tabPurchase Invoice` pi
      JOIN `tabPurchase Invoice Item` pii ON pii.parent = pi.name
      JOIN `tabItem` it ON it.name = pii.item_code
      LEFT JOIN `tabItem Supplier` its ON its.parent = it.name AND its.supplier = pi.supplier
      WHERE pi.docstatus = 1 AND pi.is_return = 0) x
WHERE x.rn = 1 ORDER BY x.supplier, x.item_code;
```

Export with the MariaDB client's `--batch` output converted to CSV (or `SELECT ... INTO OUTFILE` on the backup box), copy the
files to staging, and run a `--dry-run` first: its report shows every row that would fail.

## Purchasing, Phase I-2: purchase orders (`docs/decisions.md` I48–I59)

### Purchase orders on the screens

Purchasing › Purchase orders (`/ui/purchasing/orders`; everyone with `purchasing.view` reads, buyers and purchasing managers
act). The list filters by state, supplier, number / supplier's reference and "rejected at review" (CSV: `orders.csv`); a
buyer starts a draft there ("New purchase order": pick the supplier — a draft or pending supplier is allowed, the approval
then waits for the supplier's own approval).

- **The editor** (the draft's creator only; others see it read-only) is one form: order date, expected delivery, the
  supplier's quote reference, notes to the supplier (printed), then the **scan box**: a barcode (a case barcode takes the
  supplier item of that pack), this supplier's code, a CW code or words of the name. Enter adds the line **and saves every
  change made in the table** (packs, pack price, VAT code, note; packs 0 removes a line); scanning the same item again adds
  a pack. Several matches give a "Choose" list on the same page. A line without a supplier item takes the units per pack
  typed, and "save as this supplier's item" remembers the pack and code. A charge (delivery, ...) is added below the lines.
  The page shows stock now, on order elsewhere, the last price and last PO price, and warnings (supplier not active yet,
  due diligence overdue, below the supplier's minimum order, a £0 price, a merged item). The table is editable while the
  form stays under PHP's `max_input_vars` (1,000): about 240 lines with supplier items, fewer without (I73); a larger order
  shows its lines read-only and is edited with the lines file (the header, a scan and a charge still work). Any form that
  arrives with 1,000 fields is refused, nothing saved (400 `form_truncated`).
- **New supplier items** (the supplier card, the import, "save as this supplier's item") become the item's **preferred
  supply** when the item has none yet (I75): the reorder list and its draft orders use the preferred supply. Changing a
  supplier item's pack size drops the old pack's last price (record the new pack's price; I74).
- **The lines file**: "Download the lines" (XLSX or CSV: `line, cw_code, supplier_code, barcode, item_name, purchase_unit,
  units_per_pack, packs, units, pack_price, vat_code, line_total, note`) and "Import" (CSV or XLSX, ≤ 2 MiB, ≤ 2,000 rows;
  `append` adds packs to a line of the same supplier item, `replace` replaces every line). **All or nothing**: a file with
  any problem changes nothing and the page lists the problems as `row N, column: message` (row 1 = the first line under
  the header). `units`, when given, must be packs × units per pack (boxes typed as units are caught); a supplier item's pack
  and unit must match. A charge row: `purchase_unit` = `charge`, no item code, the amount in `pack_price`, what it is in `note`.
- **Approve** ("Save and approve the order", a button of the editor form: what is typed is saved and approved together, I87):
  at most £10,000 net (provisional, decision 11) it is numbered at once (`PO-000123`) and fixed; a second
  person (reviewer) reviews it within 7 days in Document reviews (filter "Purchase order"). Above the limit a reviewer
  approves it first on the order's page (the requester may withdraw the request). Approval is refused for a supplier that
  is not active, an overseas supplier whose import route is not approved, or a supplier whose change of route / overseas
  status waits for a second person. The order date is at most 31 days ahead and 731 days back (I81).
- **After approval** the order's page offers: the PDF (letterhead from the company settings; "COMPANY DETAILS NOT CONFIRMED
  — DO NOT SEND" until `company.confirmed` is true), **Mark as sent** (e-mail, portal, phone, ...; again allowed; the PDF as
  sent is kept in the document store `/srv/cw-docs` when the server has one; while the company details are not confirmed
  or the order's review was rejected the form says so and needs "Send anyway" ticked, which the audit row keeps, I86), **Cancel** (posts a cancellation in the PO
  series, reviewed like an order; refused once goods were received: close it instead), **Amend** (cancels it and copies it
  into a new draft that says "Amends PO-x"), **Copy** into a new draft, and **Close** (part-received only: the rest is not
  expected). A rejected review is recorded and shown on the page ("Rejected at review by …"); the order stands until the
  buyer cancels or amends it.
- The supplier's card lists its last 10 orders; the document page (`/ui/documents/{id}`) links "Open in Purchasing".
  Purchase orders (and the PDFs attached to them) are shown only to people with `purchasing.view`: warehouse and stock
  control see every other document (I85).

### Document rules (`bin/document_rules.php`)

How owner decision 11 changes the PO approval limit (provisional £10,000 net) or a type's review rule. `document_type` is
read-only for the app login, so a change needs the admin login.

```bash
# on the staging box, in /opt/cw-staging, as root (from a slot: scripts/remote.sh <slot> php bin/document_rules.php ... --db=cw_test_<slot> --admin)
php bin/document_rules.php --type=PO --approval-limit=25000 --reason="owner decision 11: approval above GBP 25k" --admin
php bin/document_rules.php --type=PO --review-due-days=14 --reason="fortnightly PO review" --admin
php bin/document_rules.php --type=WO --review-rule=over_limit --review-limit=25 --reason="write-offs above 25 units" --admin
```

- `--approval-limit` only for a type with an approval rule (PO: whole GBP of the net total; ADJ: units); `--review-limit`
  only with the `over_limit` review rule (which needs one); limits 0–2,000,000,000; `--review-due-days` 1–120. The same
  values again: "unchanged".
- Audited `document_type.change {type, before, after, reason}` (actor `system:document_rules`); the next posting uses the
  new rule (open tasks keep the due date they were opened with).
- Exit codes: 0 done (also unchanged) · 1 without `--admin` (refused before connecting) · 2 usage, unknown type, a value
  the rules refuse, a reason not 3–500 characters · 3 cannot run.

### ERPNext open POs (`bin/import_erp_open_pos.php`)

Brings ERPNext's open purchase orders into CW once, at I-Day, from a CSV exported from the **restored backup copy** (owner
decision 4; never live ERPNext). Each ERPNext PO becomes one CW PO holding only its **outstanding** packs, created as the
`--staff` buyer, approved through the normal rules (above the value limit it waits for a reviewer: reported) and marked sent
(`imported`, to `ERPNext`). Run the supplier and supplier-item seed first: the suppliers must be **active**.

```bash
# on the staging box, in /opt/cw-staging (app login), the file copied to /srv/cw-import/:
php bin/import_erp_open_pos.php --file=/srv/cw-import/open_pos.csv --staff=buyer@example.co.uk --dry-run --report=/root/open-pos-dry.csv
php bin/import_erp_open_pos.php --file=/srv/cw-import/open_pos.csv --staff=buyer@example.co.uk --report=/root/open-pos.csv \
    [--vpg-codes=/root/cw_work/first_match/vapeandgo_listings_<date>.jsonl.gz]
```

**`open_pos.csv`**, one row per PO line (* = required; headers case-insensitive, any order): `erp_po*` (ERPNext
`Purchase Order.name`), `supplier*` (ERPNext name or CW code), `order_date*` (`YYYY-MM-DD`), `expected_date`, `line_no*`,
`item_ref_type*` + `item_ref*` (`cw_code`, `vpg_variant`, `vpg_code`, `barcode`, `erp_item`: as `supplier_items.csv`),
`supplier_code`, `purchase_unit`, `units_per_pack` (in the referenced item's units, default 1), `packs_ordered*`,
`packs_received` (default 0), `pack_price*` (GBP excl. VAT per purchase unit, ≤ 4 decimals), `vat_code` (default the
supplier's).

- Report statuses (`--report`: `row, key, status, reason`): **created** ("PO-000123 approved and marked sent", or "waits
  for a reviewer's approval"), **skipped** ("already in CW as PO-x": a live CW PO has `external_ref = ERPNext <erp_po>`, so a
  re-run is safe; "nothing outstanding"), **failed** ("whole PO skipped: …": a supplier that is not active, an unresolved,
  unlinked or merged item, a bad value — never a partial PO). One transaction per PO; one `import_run` per file (the same
  file again: "already imported (run N)"); `--dry-run` writes only its `import_run` row.
- Exit codes: 0 done · 1 a PO failed or the file was refused · 2 usage (also `--staff` who may not post POs) · 3 cannot run.

Worked example:

```csv
erp_po,supplier,order_date,expected_date,line_no,item_ref_type,item_ref,supplier_code,purchase_unit,units_per_pack,packs_ordered,packs_received,pack_price,vat_code
PUR-ORD-2026-00412,Example Wholesale,2026-09-20,2026-09-27,1,vpg_variant,48213,EW-ELX-BR,box,10,10,4,16.50,S
PUR-ORD-2026-00412,Example Wholesale,2026-09-20,2026-09-27,2,vpg_variant,48214,EW-ELX-WM,box,10,5,5,16.50,S
```

→ one CW PO, `external_ref` "ERPNext PUR-ORD-2026-00412", order date 20 Sep, one line: 6 boxes of 10 (60 central units with
`units_per_item` 1) at £16.50, linked to the supplier item of that pack when there is one; line 2 is fully received and left
out.

**Indicative SQL on the restored backup copy** (field names as on ERPNext v15, to be confirmed on the I-0 copy):

```sql
SELECT po.name AS erp_po, po.supplier, po.transaction_date AS order_date, po.schedule_date AS expected_date, poi.idx AS line_no,
       IF(it.product_id IS NULL, 'erp_item', 'vpg_variant') AS item_ref_type, COALESCE(it.product_id, poi.item_code) AS item_ref,
       its.supplier_part_no AS supplier_code, LOWER(poi.uom) AS purchase_unit, CAST(poi.conversion_factor AS UNSIGNED) AS units_per_pack,
       CAST(poi.qty AS UNSIGNED) AS packs_ordered, CAST(FLOOR(poi.received_qty) AS UNSIGNED) AS packs_received, ROUND(poi.rate, 4) AS pack_price
FROM `tabPurchase Order` po
JOIN `tabPurchase Order Item` poi ON poi.parent = po.name
JOIN `tabItem` it ON it.name = poi.item_code
LEFT JOIN `tabItem Supplier` its ON its.parent = it.name AND its.supplier = po.supplier
WHERE po.docstatus = 1 AND po.status IN ('To Receive and Bill', 'To Receive') AND poi.received_qty < poi.qty
ORDER BY po.name, poi.idx;
```

`qty` and `received_qty` are in the line's purchase UOM; check that `conversion_factor` is whole. Run `--dry-run` first.

## Purchasing, Phase I-2: sales history and the reorder list (`docs/decisions.md` I60–I71)

The reorder list needs the sites' sales. Until the sites are connected (plan Phases 3–4) the history comes from a
**read-only export run on this box** (the Vape and Go web server: the Vape and Go database is VPC-private), copied to
staging and loaded there. Nothing in this section writes to a site or to ERPNext.

### 1. The export (`tools/sales_history/export.php`, on this box)

```bash
cd /root/central-warehouse
nice -n 10 php tools/sales_history/export.php --site=vapeandgo --source=cps \
    --global-config=/var/www/html/vpg_ecom/App/global_config.php                  # 12 months to yesterday (UK)
nice -n 10 php tools/sales_history/export.php --site=electrofag --source=orders \
    --dbtransfer-dest=/var/www/html/vpg_ecom/App/db-transfer/config.php
# later, only the new days (the import refuses a gap): --from=<the last export's --to + 1> [--to=YYYY-MM-DD]
```

- **When:** after 05:00 UK (Vape and Go's `consolidate_product_sale` is rebuilt at 03:40; before 04:00 the tool warns that
  yesterday may not be settled) and outside the night jobs (03:30–05:00). It takes about half a minute a site.
- **What it reads** (only these SELECTs, each EXPLAINed first; the exact SQL is in the tool and in the manifest):
  Vape and Go online sales from `consolidate_product_sale` (`--source=cps`), Electrofag's from its order lines
  (`--source=orders`: Completed Online orders, a cancelled line counted only when it was returned); office orders of both
  (Completed, not a re-ship child, line not cancelled); the stock snapshot (`product_stock_snapshot`): the days it covered,
  the unsellable days of the variants sold in the **365 days to `--to`**, and the last day's stock. A window shorter than a
  year (the nightly `--from=<last --to + 1>`) first reads the earlier sales of that year with the same statements and gate,
  keeping only the variant ids (tool 1.1, I76): an out-of-stock day counts only for a variant that did not sell that day, so
  without it a one-day export kept no out-of-stock day at all. That pass makes a one-day export take about as long as a
  12-month one (some 30 s for Vape and Go). Load short exports made by tool 1.0 (no `stock_filter` in their manifest)
  only together with a 12-month re-export.
- **Safety, in the code:** the session is `MAX_EXECUTION_TIME = 30000`, READ COMMITTED, READ ONLY; one `START TRANSACTION
  READ ONLY` per 7-day slice, 200 ms apart; before each slice `Threads_running` must be ≤ 30 (`--max-threads-running`),
  else it waits 5 s, at most 12 times, then gives up (exit 3); **the EXPLAIN gate** refuses (exit 3, nothing further run,
  no file kept) a full scan, an index outside the statement's allow-list, a missing forced index, or a driving estimate
  above 500,000 rows. On exit 3 do not loosen anything: the site's indexes changed — compare the plan printed with the
  manifest of the last good run and talk to whoever changed them.
- **Output:** `/root/cw_work/sales_history/<site>/` only (`--out` must be inside `CW_SALES_EXPORT_ROOT`, default
  `/root/cw_work/sales_history`; else exit 2), files 0640: `<site>_sales_<from>_<to>_<ts>.csv.gz`, `..._stockdays_...`,
  `..._stocklatest_<date>_...` (the stock files only when the site has a snapshot) and the `.manifest.json` (window, rule,
  per-month units, snapshot days, every file's sha256 and rows, the SQL's sha256, the first slice's plans, Threads_running
  before / max / after). It prints one summary line; credentials and the database host are never printed.
- Exit codes: 0 done · 1 failed (connection, query, file; no file kept) · 2 usage · 3 refused (EXPLAIN gate, busy server).

First run (2 Oct 2026, 16:57 BST; docs/decisions.md I70): vapeandgo 2025-10-02..2026-10-01 in 32.8 s, 602,739 sales rows,
36,841 stock-day rows, 29,127 latest rows (snapshot 1 Oct), Threads_running 3 / 7 / 2; electrofag in 22.1 s, 11,481 /
273 / 9,014 rows (one snapshot day, 29 Sep), Threads_running 2 / 3 / 2.

### 2. Copy and import (staging)

```bash
# from this box (the staging key): the site's directory next to its manifest
rsync -a -e 'ssh -i /root/.ssh/cw_staging' /root/cw_work/sales_history/vapeandgo/ root@46.101.55.135:/srv/cw-import/sales/vapeandgo/
rsync -a -e 'ssh -i /root/.ssh/cw_staging' /root/cw_work/sales_history/electrofag/ root@46.101.55.135:/srv/cw-import/sales/electrofag/
# on the staging box, in /opt/cw-staging (app login; the slots: scripts/remote.sh <slot> php bin/... --db=cw_test_<slot> --admin)
php bin/import_sales_history.php --channel=vapeandgo --manifest=/srv/cw-import/sales/vapeandgo/<file>.manifest.json --dry-run
php bin/import_sales_history.php --channel=vapeandgo --manifest=/srv/cw-import/sales/vapeandgo/<file>.manifest.json --no-build
php bin/import_sales_history.php --channel=electrofag --manifest=/srv/cw-import/sales/electrofag/<file>.manifest.json
php bin/reorder_demand.php                                       # also run by each import unless --no-build
```

- **Checks:** the manifest's site must be `--channel` (exit 2); every file's sha256 must be the manifest's (exit 1,
  `sha_mismatch`: copy again); the same sales file loaded before: "already loaded as batch N" (exit 0).
- **Coverage:** a site's history runs from its first loaded day to its last. An export that would leave days without
  history before or after it is refused (exit 1, `history_gap`) unless `--allow-gap` — those days would then read as days
  without sales. An export that overlaps loaded days **replaces** them (sales, stock days, snapshot days); the site's latest
  stock is replaced only by a snapshot at least as new as the stored one (an older re-export says "the stored snapshot of
  <date> is newer than this export's: kept", I77).
- **The report:** `rows / units`, `unknown` (variants CW has no listing for: import the site's listings), `unlinked`
  (listings not linked to an item, or not `mapped` — a quarantined listing keeps its item but counts for nothing, I77: link
  or release them on the review screens — their history counts from then on, nothing is lost), stock days, latest rows, snapshot days, a units-a-day table per month, and per active anomaly window its units a
  day against July–August of its year ("+42.9 %" for 14–22 Sep 2026 on Vape and Go). Then the demand build.
- A load that fails part-way marks its batch `failed` (Sales history shows the error); the days loaded before stay; run the
  same command again (the slices are idempotent).
- 12 months of Vape and Go take about five minutes to load on staging (602,739 rows); a day's export takes seconds.
- Exit codes: 0 loaded / already / dry run · 1 refused or the build failed · 2 usage · 3 cannot run.
- **No cron is installed.** When the owner wants it nightly: the export at 05:15 UK here with `--from` = the day after the
  last export, the copy, the import, all three in one script, after a decision on who watches its exit codes.

### 3. The reorder list on the screens

Purchasing › **Reorder list** (`/ui/purchasing/reorder`; buyers, purchasing managers, reviewers, auditors, managers read;
buyers and purchasing managers change settings and make drafts):

- Filters: brand, preferred supplier, item, "lines to order" / "every item", urgent only, **CW stock** or **Vape and Go's own
  stock** (the site's last snapshot: use it to compare with ERPNext while CW stock on staging is the 1 Oct estimate; an
  unsellable listing counts 0 and a figure below 0 counts 0; a line whose listing is in the site's In-Stock mode — sold
  whatever its figure, best sellers far below 0 — is flagged "site stock not reliable", I78). 200
  lines a page; "Download (CSV, every line)" has every column and the Why; `rate_raw_30` is the plain 30-day average
  (ERPNext "Fetch Item"-like, no exclusions) to compare with.
- Each line: demand a day, cover days (lead + review + safety), target, available, on order (approved / sent /
  part-received POs), in drafts (shown, not counted), need, **packs** (prefilled, editable), units, last pack price, value,
  cover now, flags, and **Why** (the demand's windows and exclusions, the cover, the stock, the packs).
- Lines are pre-ticked when they suggest packs, **except** a line already in a draft order (flag "already in a draft
  order"; its suggestion does not count drafts): tick it only to order more (I79). A maximum stock below the cover caps the
  reorder point too, so such a line is never "urgent" with nothing to order (I80).
- **Create draft orders from the ticked lines:** one draft PO per preferred supplier (lines in the supplier's pack, the
  last price, the supplier's VAT code; the suggestion is kept on each line). One supplier → straight into the PO editor;
  several, or items skipped (no preferred supplier, an inactive supplier, a merged item) → back to the list with the new
  drafts and what was skipped. The same form sent twice makes the drafts once.
- **Recalculate the demand** after an import or a change of the windows — from the page while at most 3,000 items are
  linked (a UI request stops at 50 s); above that the page says so and the demand is rebuilt with `bin/reorder_demand.php`
  on the server (I84). The header says when the demand was computed and
  warns when a site's history ends more than `reorder.stale_history_days` (3) ago or an import is newer than the demand.
- An item's page (`/ui/purchasing/reorder/items/{id}`): the line, the stored demand and its 12 months, every day of the
  long window per listing with why it was or was not counted, and the item's settings (demand factor, safety days, lead
  days, minimum / maximum stock, pack rounding, do not reorder).
- **Brand factors and safety days** (`/ui/purchasing/reorder/brands`): e.g. a factor 0.85 for the expected post-duty drop.
- **Anomaly windows** (`/ui/purchasing/reorder/anomalies`): days not trusted as demand (at most 93 days; one site or all;
  one brand or all). 14–22 Sep 2026 (pre-duty stockpiling) is seeded. A change counts at the next Recalculate.
- Purchasing › **Sales history** (`/ui/purchasing/sales-history`): per site the coverage, the snapshot days, the latest
  site stock, the top 50 unknown / unlinked variants of the last 91 days (with the site's titles; a link to the listing for
  those who may link) and the full list as CSV; the import batches with their counts.
- The `reorder.*` settings (windows, weight, minimum valid days, the spike cap, the promotion test, default lead / review /
  safety days) are provisional (owner to confirm) and change with `bin/settings.php --set=reorder.<key> ... --admin`; the
  windows and their fewest valid days are checked against each other (short ≤ long, fewest valid days 1..their window, I82).
- **The export's database logins** (I88, owner's choice): the Electrofag export connects with db-transfer's DEST login and
  the Vape and Go export with the site's own application login; both can write, and the read-only session settings, the
  per-slice READ ONLY transactions and the constant SQL are what keep the export read-only. Before a nightly job exists, a
  SELECT-only login per site (on the five tables the export reads) is the safer choice: point the tool at it with
  `CW_EXPORT_DB_HOST / _PORT / _USER / _PASSWORD / _NAME / _SSL`.

### 4. The real-data rehearsal (opt-in test, staging only)

```bash
mkdir -p data/rehearsal/sales data/rehearsal/first_match                      # data/ is git-ignored
cp -r /root/cw_work/sales_history/vapeandgo /root/cw_work/sales_history/electrofag data/rehearsal/sales/
cp /root/cw_work/first_match/*_listings_*.jsonl.gz data/rehearsal/first_match/  # one file per site
scripts/remote.sh <slot> env CW_REHEARSAL_DIR=data/rehearsal vendor/bin/phpunit --filter RealDataRehearsalTest
rm -rf data/rehearsal                                                         # the next remote.sh run removes the remote copy
```

It builds `cw_test_<slot>_rh`, imports both listing exports, links only the Vape and Go listings of Elux, Lost Mary and
Bar Juice 5000, loads both sales exports, builds the demand, checks the stockpiling window, Elux's promotion and its demand
against the plain average, prints the numbers on stderr and drops the schema (about 8 minutes).
