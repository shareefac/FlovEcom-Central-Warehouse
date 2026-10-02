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
under its sha256, read-only, for at least 7 years (`stored_file.retain_until`), and re-hashed whenever it is read. In I-1
files arrive only through the CLI below; browser uploads come with the I-2/I-3 screens. **Before live** the local
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
| `bin/store_file.php --file=<path> --kind=<kind> [--note=..]` | kinds: supplier_invoice, delivery_note, packing_list, photo, duty_evidence, generated_pdf, other. Type sniffed from the content (PDF, JPEG, PNG, CSV, text, XLSX only), ≤ 25 MiB, audited `file.store` as `system:store_file`. | 0 stored · 1 refused (type, size, kind) · 2 usage · 3 cannot run (no `file_store_dir`, database) |
| `bin/verify_files.php [--limit=N]` | Re-hashes every stored file through the store. `missing id= sha256=` / `mismatch id= sha256=` on stderr; orphans (bytes no row names, left by a failed commit) are counted and kept. Scheduled nightly at 04:27 (`cw-staging.cron`, I36). | 0 intact · **1 missing or changed** · 2 usage · 3 cannot run |
| `deploy/staging/seal_file_store.sh [root]` | Seals stored files not yet immutable (root:www-data 0440, `chattr +i`); every minute from cron, once by the installer. | 0 ok · 1 a file could not be sealed · 3 cannot run |

A changed or missing file is never served: the screen answers 500 with a request id (`grep <id>
/var/log/cw-ui/php-error.log` shows which file). Keep the evidence, compare with the backup, never "fix" the row.

