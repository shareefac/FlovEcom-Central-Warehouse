# Operating CW: scheduled jobs and the concurrency hammer

Design choices behind this page: `docs/decisions.md` H1–H8. How to develop and run tests:
`docs/dev.md`. Everything here runs on the CW **staging** server `46.101.55.135` against the
staging cluster. Nothing touches a live or proto site or database.

## Scheduled jobs

| Job | Staging schedule (UTC) | What it does | Exit codes |
|---|---|---|---|
| `bin/expire_reservations.php` | every minute | Expires unpaid holds past their TTL (§3, §4 step 3). It selects due ids without locks, then handles each hold in its own transaction through `Reservations::expireDue`: lock, re-check, release the held units, status `expired`. Runs batches of 500 until none is due or 50 s have passed. A hold that fails is logged and skipped (H4). | 0 ok · 1 some holds failed · 3 cannot run |
| `bin/prune_changes.php --days=14` | 03:17 | Deletes change-feed rows (`stock_change`) older than 14 days, except the newest row of each item/listing/channel/global scope, so no listing's version moves (H2). Short autocommit deletes; safe while the service runs. `--dry-run` only counts. | 0 ok · 3 cannot run |
| `bin/invariants.php` | 03:47 | The nightly bucket check (`CW\Invariants`, D44), run on one consistent snapshot (H5): cached buckets = unit states = ledger sums, unit states match their reservations; since C0 also the per-item value sequence (checks 7–9, I3): one seq per on_hand ledger row, seqs 1..N per item matching its clock, seq order = ledger id order within a balance; since 0008 also the document base (D1–D7, `CW\Documents\DocumentInvariants`): gapless numbers per series, whole reversal pairs that net to zero, ledger rows naming posted documents by their number, review tasks on the right documents and never decided by the document's own people, line items that exist, and every posted document against its write-once posting record (`document_posting`: number, hash, poster, time, and its header and lines re-hashed; I33); since 0019 also the configuration history (K1–K4, Y2, Y53: a setting, rule, reason or warehouse changed around its history, or added with none), and every run is recorded in `integrity_run` (Settings → Safety checks; a failed run is Home's first card, Y33). | 0 ok · **1 mismatch** · 3 cannot run |
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
afterwards: `SELECT COUNT(*) FROM app_setting` = 29 (20 once 0013 has moved the nine `company.*` rows), `vat_code` = 6,
`SELECT approval_rule, approval_limit_units, review_rule, reject_action FROM document_type WHERE code = 'PO'` = `over_value,
10000, all, record`, `SELECT COUNT(*) FROM demand_anomaly` = 1, `supplier`/`purchase_order`/`sales_history_day` empty, and
`php bin/invariants.php` says `ok` (it now runs S1–S5 and P1–P6 too). Then the company details (0013 below; "Company details")
and the sales-history load ("Purchasing, Phase I-2" below).

**`0012_key_bulk.sql` (Key spot-check and bulk confirm, M26–M28; not applied yet):** three new append-only tables
(`match_proposal_basis`, `key_sample`, `key_sample_member`; cw_app gets SELECT, INSERT), no seeds, no backfill (the re-band and
sample tools write the proved bases of older proposals). It was written as `0010_key_bulk.sql` on the mapping branch and renamed
when merged after Phase I-2: name order applies it after 0011, in the same `install_cron.sh --migrate` run. Check afterwards: the
three tables exist and are empty, and `php bin/migrate.php --status` lists no PENDING file. Then the runbook "Key spot-check and
bulk confirm" below.

**`0013_company_profile.sql` (the Company details screen, I90–I99; not applied yet):** creates `company_profile` (cw_app:
SELECT, INSERT), copies the nine `company.*` settings into its version 1, tidied as the screen tidies them (audited
`company.change`, actor `system:migrate`), then **deletes those nine rows from `app_setting`**, and adds `company` to
`review_task.subject_type`. Deploy it with the code of the same change in one `install_cron.sh --migrate` run, never apart: older
code reads the `company.*` settings it deletes (every PO PDF and approval would fail with a 500). Re-runnable if it stops half way.
Check afterwards: `SELECT version, kind, legal_name, company_number, vat_number, confirmed, saved_actor FROM company_profile` = one
row, version 1, kind `seed`, saved by `system:migrate`, the values `bin/settings.php --list` showed before (company and VAT numbers
without spaces, extra spaces gone); `SELECT COUNT(*) FROM audit_log WHERE entity_type = 'company_profile'` = 1; `SELECT COUNT(*) FROM
app_setting WHERE setting_key LIKE 'company.%'` = 0 (`app_setting` 20 rows); `SELECT COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_NAME = 'review_task' AND COLUMN_NAME = 'subject_type'` lists `company`; `php bin/migrate.php --status` lists no PENDING
file; `php bin/invariants.php` says `ok` (it now runs C1–C4 too). Then the owner adds and confirms the details on the screen
("Company details" below).

**`0014_key_bulk_hold.sql` (holding screened listings back from every Key bulk confirm, M30; not applied yet):** one new
append-only table, `key_bulk_hold` (cw_app: SELECT, INSERT), with no seeds and no backfill. Deploy it with the code of the same
change in one `install_cron.sh --migrate` run, never the code first. The review screen reads the table for every listing it shows,
and the bulk, sample and re-band tools read it through the eligibility check. Name order applies it after 0012 and 0013; on
`cw_staging` (at 0013) it is the only PENDING file. Check afterwards: the table exists and is empty; cw_app holds SELECT and INSERT
on it, nothing more (`bin/migrate.php` converges the grants); `php bin/migrate.php --status` lists no PENDING file.

**`0015_duplicates.sql` (Vape and Go's duplicate listings, M31-M36 and the review fixes M39-M45; not applied yet):** no new table and
no new grant; `match_decision.action` gains `split`, a CHECK that a split row has its `detail`, and new column comments. Deploy it with
the code of the same change in one `install_cron.sh --migrate` run, never the code first (the Duplicates screen and DecisionService
write `action = 'split'`, which the old ENUM refuses; the CLI tools refuse a schema behind the code, exit 3). On `cw_staging` (at
0014) it is the only PENDING file. The HTTPS vhost serves the screens from `/opt/cw-staging`, which the same run updates. Check afterwards:
`php bin/migrate.php --status` lists no PENDING file; `SHOW CREATE TABLE match_decision` has `'split'` in the action ENUM and
`ck_match_decision_split`; the Duplicates screen (Linking -> Duplicates) lists 145 groups.

**`0016_item_cards.sql` (IM3 item cards and barcodes, I100–I124; not applied yet):** three new tables (`item_card`: cw_app SELECT,
INSERT, UPDATE; `item_card_change`: SELECT, INSERT; `barcode_review`: SELECT, INSERT and UPDATE of its decision columns) and
`import_run.kind` + `item_cards`; no seeds, no backfill. Deploy it with the code of the same change in one `install_cron.sh --migrate`
run, never the code first (the item page, the reorder list and PO approval read `item_card`). On `cw_staging` (at 0015) it is the only
PENDING file. Check afterwards: the three tables exist and are empty; `php bin/migrate.php --status` lists no PENDING file;
`php bin/invariants.php` says `ok` (it now runs IC1–IC3 too). Then the dry run of the barcode sync ("Item cards and barcodes" below).

**`0019_set_it_yourself.sql` (the set-it-yourself pack, `docs/decisions.md` Y1–Y38, with its review fixes Y40–Y55; not applied yet):**
new tables `config_change` and `integrity_run` (cw_app SELECT, INSERT), `warehouse_location` (SELECT, INSERT, UPDATE of
name/is_active/note), `staff_role_request` (SELECT, INSERT, UPDATE of its decision columns and `used_at`); new columns on `warehouse`
(`is_active`, `stock_owner`, `is_system`, `note`, `sort_order`), `document_line.location_id`, `staff_user` (the set-up window, its one-time
code's hash and wrong tries, `totp_state` and the fresh-secret step `totp_next_*`), `supplier` (`approved_alone`, `route_alone`,
`alone_change_id`, `alone_checked_by/_at`), `key_sample.required_size` (backfilled with each sample's own size: owner-1 keeps 20), and
`reason_code.applies_to` gains the order screens' three uses; new CHECKs (`ck_staff_user_one_factor`, `ck_key_sample_size` now 5 or more,
`ck_document_type_po_reject`, …); the index `ix_audit_action` on `audit_log`; eleven new settings (`approvals.*` with `approvals.staff_grant`
and `approvals.staff_reset` off, `approvals.spot_check_size`, `staff.setup_hours`, `staff.setup_max_fails`, `staff.sign_in_address` (empty),
`staff.min_reviewers`; all provisional); a baseline version of every setting, reason, document rule and warehouse, and version 2 (actor
`system:migrate`) of the six reasons the order screens offered; cw_app gains column UPDATE on `app_setting`, `document_type` and
`reason_code.applies_to`, INSERT on `reason_code`, and INSERT on `warehouse` naming only its own columns (never `is_system`). It needs the
code of the same commit (the screens, `Settings::change`, the new Composer package `bacon/bacon-qr-code`, which `install_cron.sh` installs)
and never the code first. On `cw_staging` (at 0018) it is the only PENDING file.

**Pre-flight (read only, before the deploy; every query must return NOTHING).** The ALTER of `warehouse` adds a CHECK that an own
warehouse names no owner, the INSERT of the new settings would hit an existing key, and the PO CHECK needs Not OK = record:

```sql
SELECT id, code, owner_entity FROM warehouse WHERE owner_entity IS NOT NULL;
SELECT setting_key FROM app_setting WHERE setting_key IN ('approvals.supplier_activation', 'approvals.match_multiple', 'approvals.match_counted',
  'approvals.company_own_change', 'approvals.staff_grant', 'approvals.staff_reset', 'approvals.spot_check_size', 'staff.setup_hours',
  'staff.setup_max_fails', 'staff.sign_in_address', 'staff.min_reviewers');
SELECT code, reject_action FROM document_type WHERE code = 'PO' AND reject_action <> 'record';
```

Run them as the app login inside a read-only transaction, printing no secret (from this box; PHP read from stdin, no file left behind):

```bash
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php' <<'PHP'
<?php
require 'vendor/autoload.php';
$db = CW\Db::connect(CW\Config::load()->dbApp()->withDatabase('cw_staging'));
$db->pdo()->exec('SET SESSION MAX_EXECUTION_TIME=30000');
$db->pdo()->exec('START TRANSACTION READ ONLY');
$keys = ['approvals.supplier_activation', 'approvals.match_multiple', 'approvals.match_counted', 'approvals.company_own_change', 'approvals.staff_grant',
    'approvals.staff_reset', 'approvals.spot_check_size', 'staff.setup_hours', 'staff.setup_max_fails', 'staff.sign_in_address', 'staff.min_reviewers'];
echo json_encode($db->all('SELECT id, code, owner_entity FROM warehouse WHERE owner_entity IS NOT NULL')), "\n";
echo json_encode($db->column('SELECT setting_key FROM app_setting WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys)), "\n";
echo json_encode($db->all("SELECT code, reject_action FROM document_type WHERE code = 'PO' AND reject_action <> 'record'")), "\n";
$db->pdo()->exec('ROLLBACK');
PHP
# expected: [] three times
```

**Deploy (the owner's go first):**

```bash
scripts/remote.sh <slot> vendor/bin/phpunit && scripts/remote.sh ui vendor/bin/phpunit --filter 'UiAuthTest|UiSecurityTest|UiReviewFlowTest'
scripts/remote.sh <slot> php tests/concurrency/hammer.php --seed=20261011
scripts/remote.sh <slot> bash deploy/staging/install_cron.sh --migrate
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/migrate.php --status --db=cw_staging'   # no PENDING
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/invariants.php --db=cw_staging'        # ok (K1-K4 included)
# the address the sign-up sheets print (or on the screen: Settings -> Settings and lists -> Sign-in address):
ssh -i /root/.ssh/cw_staging root@46.101.55.135 "cd /opt/cw-staging && php bin/settings.php --admin --set=staff.sign_in_address \
  --value=https://warehouse-staging.floverfy.com --reason='the staging sign-in page'"
```

Check afterwards: `config_change` holds a `baseline` (version 1) of every setting, reason, document rule and warehouse, and a version 2
(`change`, `system:migrate`) of `not_needed`, `duplicate`, `supplier_cannot_supply`, `entered_in_error`, `other` and `po_amended`;
`SELECT name, sample_size, required_size FROM key_sample` shows owner-1 with 20 and 20; `SELECT * FROM integrity_run` has the run just
made; the owner opens Settings → Approval rules and sees every rule as before (both staff rules off) with the "agreed" tick; Staff → Staff
and access shows "Add a staff member"; the owner and Fazil still sign in as before (their accounts are `own`). Nothing changes for the
websites.

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

## Channels: keys, mode and allowlist (`docs/decisions.md` A11, A13–A17)

Run these on the CW server. They connect as the app login (`cw_app`); `--db=<schema>` picks the schema.

| Tool | Does |
|---|---|
| `bin/create_channel.php --code=<c> --name=<n> [--ips=..] [--mode=off] [--movement-types=..]` | a channel and its sellable warehouse; prints the NEW key once on stdout (A11) |
| `bin/rotate_key.php --code=<c>` | a new key at once; the old one stops working |
| `bin/channel_set.php --code=<c> [--mode=off\|shadow\|live] [--ips=<a,b/24>\|none] [--actor=<who>] [--apply]` | the mode and/or the IP allowlist; a dry run unless `--apply`; each change audited (A14) |

Switching a site's mode, one step at a time (plan §12: off → shadow → live):

    php bin/channel_set.php --code=vapeandgo --mode=shadow                                # read the plan and the warnings
    php bin/channel_set.php --code=vapeandgo --mode=shadow --actor="<your name>" --apply

- The output is `mode: <before> -> <after>` and `allowed_ips: [..] -> [..]`, or `(unchanged)`. With
  `--apply` it ends with `applied by <who>; audited: channel.mode, channel.allowlist`.
- `--ips` replaces the whole list. `--ips=none` empties it, which refuses every call of that site
  (403). That is the quickest way to shut a site out without touching its key.
- Warnings, which never stop the run:
  - a mode other than `off` with an empty allowlist;
  - `off -> live`;
  - `live` before the site's final opening_orders batch;
  - a lower mode (the site's own rollback steps apply, plan §12).
- The site sees a new mode on its next call: every answer after authentication carries
  `X-CW-Channel-Mode` (A13). The site's effective mode is the lower of that and its own `CW_MODE`.
- Exit codes: 0 ok (dry run included), 1 refused (unknown channel, bad mode, address or actor),
  2 usage, 3 cannot run.
- The history of a channel:
  `SELECT created_at, action, detail FROM audit_log WHERE entity_type = 'channel' AND entity_id = '<code>' ORDER BY id;`

What the site connector can rely on from the API:
- `X-CW-Channel-Mode` on every authenticated answer, errors and replays included; never on 401/403 (A13).
- `GET /v1/changes` carries `head_seq`. When it is below the seq the site last applied, CW was
  restored: reset the versions and re-snapshot (A15). It only sees a restore while CW's new head is
  still below the site's cursor: if CW writes more rows after the restore than it lost before the site
  polls again, the check passes. So after any restore of CW's database, also run each site's resync by
  hand (`bin/cw_resync.php --reset-versions`, after `cw_replay.php`, plan "Backups & recovery").
- `POST /v1/heartbeat` needs an `Idempotency-Key`. A retry re-sends the same body under the same key;
  the same key with another body gets 422 `idempotency_key_reused` (A16).
- `POST /v1/reservations` on a held order with the same lines answers 200 `extended` with the same
  `lines[]` as a fresh 201 (A17).
- `POST /v1/reservations/{ref}/uncancel {unit_ids}` takes a line cancel back (D46). Each cancel and
  each uncancel of a unit needs its own Idempotency-Key (for example with a per-unit cycle counter in
  it): a cancel re-sent under an earlier cancel's key only replays that answer.

The Vape and Go connector (proto) uses all of these since 2 Oct 2026 (`docs/decisions.md` SC1–SC5). Its
alert `unit_sweep_uncancelled` means CW kept units cancelled that the site ships: count those items.

### Cancels taken back (uncancel, D46)

An uncancel puts cancelled paid units back into `allocated`. Two things can land on a person's desk:
- `oversell_event` kind `uncancel_short`: the unit was back on sale after its cancel, was sold again,
  and the uncancel took it once more. Treat it like any other oversell (the later order is
  back-ordered or refunded).
- `count_review` source `uncancel_after_verify` at the sale warehouse: the unit had been parked in
  VERIFY by a cancel without restock, and somebody may already have dealt with it there
  (`detail.reason`: `recount_closed`, `verify_counted`, `verify_moved` (a write-off or hand transfer of
  the item at VERIFY since the unit arrived) or `verify_short`). CW allocated it again at the sale
  warehouse and left VERIFY alone, so until a person acts availability is lower than the shelf, never
  higher. Look at VERIFY:
  - the unit is still there: move it back to the shelf (`transfer_out` VERIFY, `transfer_in` at the sale
    warehouse) and resolve its `verify_recount`;
  - it was written off at VERIFY: reverse that write-off (the goods are going to the customer);
  - it was already moved back to the shelf by hand: nothing more is needed.

A unit nobody had touched in VERIFY comes back with the paired movement, and its `verify_recount` is
dismissed with resolution `uncancelled`; there is nothing to do.

    SELECT id, ref, detail FROM count_review WHERE source = 'uncancel_after_verify' AND status = 'open' ORDER BY id;

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
| 3 | 20 processes send the same Idempotency-Key at the same instant, for reserve, commit, ship, unship, ship again, return, goods_in (staff), reserve + release, cancel (restockable), cancel (to VERIFY), uncancel (restockable) and uncancel (back out of VERIFY: VERIFY 0, both recounts dismissed). Each has exactly one effect: 1 fresh answer and 19 identical replays; the ledger rows, idempotency row, audit row and balance are as expected. Three more rounds: the same order under 20 keys gives one hold and 19 "extended"; one key with two bodies gives one effect and a 422 for the other body; 20 × ship before commit give 20 × 409 `not_committed`, nothing stored and **no deadlock** (H1). |
| 4 | For 30 s, 22 traders reserve, extend, re-reserve, commit (with and without a hold, with other lines, after expiry, on tombstones, outage orders), release (current and stale attempt), cancel, uncancel, ship, book goods-in and replay earlier calls, while 2 expiry crons run at once. Every answer matches the state machine, and nothing fails. `CW\Invariants` holds on every consistent snapshot taken during the run (about 60). Every negative availability of a strict item is a flagged `oversell_event`. Every interleaving occurs at least once. Once all remaining holds are expired, nothing is held and the invariants hold. |
| 5 | The per-item value sequence (C0, I3) under cross-warehouse races. For 30 s, 20 traders work 6 legacy items (100,000 each at MAIN, with a cost): staff movements of 1–4 items in random line order with a random MAIN/VERIFY warehouse per line (goods-in at £0.50–9.99, write-offs, adjustments ±1..5), MAIN → VERIFY transfers, reserve → commit → ship, commit → cancel to VERIFY (half of them then un-cancelled: back out of VERIFY, or to review when a VERIFY count or write-off came in between), VERIFY counts. An observer polls the seqs every 100 ms in one statement (an item showing a gap fails the run) and checks `CW\Invariants` on a consistent snapshot every ~2 s. At the end every item is numbered 1..N (N = its on_hand rows), and replaying its rows in seq order reproduces every row's `balance_after` per location and the item's total on_hand. No error or deadlock surfaces. Info: calls/s, on_hand rows/s, how often seq order differed from ledger id order. |

Connection budget: the staging cluster allows 76 connections in total, shared by every slot. The
hammer holds at most 30 at once (24 workers + 1 observer by default, plus the parent, which
holds none while children run). Before starting, it checks the cluster's free connections and
keeps 8 spare for other slots. It shrinks the pool if needed, and refuses to start below 8.

With uncancel (2 Oct 2026, slot `cfu2`, D46): `--only=3`: `RESULT: PASS (17 checks passed, 0 failed)`, both uncancel rounds
one effect with 19 identical replays. `--only=4,5 --workers=16 --seconds=20` (a smaller run, the cluster is shared):
`RESULT: PASS (15 checks passed, 0 failed)`, 0 deadlocks surfaced or retried; scenario 4: 24 uncancels among the traders'
calls, 5 `uncancel_short` events, 39 live snapshots without a violation; scenario 5: 41 units back out of VERIFY and 7 sent
to review (a VERIFY count or write-off came in between), 8 live snapshots without a violation, every balance replayed in seq order.
After the D46 review fix (any hand move at VERIFY sends earlier parked units to review; slot `cfu5`, `--only=3,5 --workers=12
--seconds=20`): `RESULT: PASS (24 checks passed, 0 failed)`, 0 deadlocks; scenario 5: 20 units back out of VERIFY and 24 sent
to review, 9 live snapshots without a violation, 1,067 rows replayed in seq order.

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
- The 165 VPG duplicate proposals (merge suggestions, lane `vpg_duplicate`, 145 groups) are decided on the **Duplicates** screen
  (Linking -> Duplicates; the runbook "Duplicates" below; `docs/decisions.md` M31-M36). They sit on mapped listings, which no
  review queue lists. Many are not the same product (in 85 of the 145 groups the titles differ in strength, size or flavour): a
  mapping lead decides each group on the screen, never in bulk. A lead merges two uncounted legacy items alone; a counted item
  needs a second mapping lead; a merge that contradicts a reject is refused (M22).
- Un-minted Vape and Go variants (`Bin`, `Discontinued`) can hold a barcode that a "New item" of Electrofag would duplicate: 60 of
  the 65 New item proposals with a barcode are in that case; the review screen lists them under "This listing's barcode is also on"
  and does not preselect "Mark as a new item" there (U18, U20).

## Key spot-check and bulk confirm (`docs/decisions.md` M26-M28 and M30; the owner's decisions of 2 Oct 2026)

Who: the owner (a mapping lead) on the CW server and on the screens. The sample belongs to the lead who draws it: only that
lead's confirmations count and only that lead runs its bulk confirm. Every tool is a dry run unless given `--apply`, writes
nothing in a dry run, and shares the job frame of the tools above (`--db`, `--admin` for test schemas, one run at a time, exit 3
when the schema is not at the code's migration). Deploy first, with `0012_key_bulk.sql` and `0014_key_bulk_hold.sql`
(`deploy/staging/install_cron.sh --migrate`). Nothing here moves stock, except what every link does: units the listing sold while it was unlinked are adopted
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
# 4. The report to screen (refuses unless all 20 are confirmed by the owner and the sample is fit)
php bin/bulk_confirm_key.php --sample=<sample> --lead=<owner e-mail> --report=/root/key_bulk_<sample>_screen.csv
#    Screen it. Copy it to /root/key_bulk_<sample>_hold.csv, keep only the rows to decide one at a time, and add a column
#    "reason" (why each one is held; it is shown on the listing's page).
# 5. Hold the screened proposals before the bulk dry run. The hold is on each one's LISTING and durable: every later bulk run,
#    of this sample or any later one, leaves the listing out, file or no file, and no later sample draws it
php bin/key_bulk_hold.php --sample=<sample> --file=/root/key_bulk_<sample>_hold.csv --by=<lead e-mail>           # checks each row, writes nothing
php bin/key_bulk_hold.php --sample=<sample> --file=/root/key_bulk_<sample>_hold.csv --by=<lead e-mail> --apply   # a re-run holds nothing twice
# 6. The bulk confirm: the dry run (its held=N = step 5's held_after), a canary, the rest
php bin/bulk_confirm_key.php --sample=<sample> --lead=<owner e-mail> --report=/root/key_bulk_<sample>_dry.csv
php bin/bulk_confirm_key.php --sample=<sample> --lead=<owner e-mail> --apply --limit=10   # a canary: check them on the screens
php bin/bulk_confirm_key.php --sample=<sample> --lead=<owner e-mail> --apply              # the rest; a re-run links only what is left
# 7. Only if needed: release a hold (the next bulk run may link it again; a mapping lead, with a reason per row). The file names
#    the proposal the hold was written for, also when a newer proposal has replaced it (the listing and sample pages show it)
php bin/key_bulk_hold.php --sample=<sample> --file=/root/key_bulk_<sample>_release.csv --by=<lead e-mail> --release
php bin/key_bulk_hold.php --sample=<sample> --file=/root/key_bulk_<sample>_release.csv --by=<lead e-mail> --release --apply
# 8. Only if needed: the undo (back to the Key queue, one at a time; any mapping lead)
php bin/bulk_unlink.php --batch=key_bulk:<sample> --lead=<owner e-mail>
php bin/bulk_unlink.php --batch=key_bulk:<sample> --lead=<owner e-mail> --apply
```

| Tool | Does | Re-run | Exit 1 when |
|---|---|---|---|
| `reband_proposals` | replays every open proposal's evidence: under its own run's band version (must give back its stored band, else `evidence_mismatch`, left alone) and under the current one; applies Key<->Check moves as a new proposal of run `reband-b2.1` that supersedes the old one, attributed to `--by` | moves nothing twice | a move failed |
| `sample_proposals` | writes the proved bases of older proposals (M27), then draws `--size` (20 or more) from every open Key proposal that qualifies (M28), stratified by confidence, lowest sha256("seed:proposal id") first, with a seed the server draws; stores the sample, its seed and the whole population | a name is used once (409) | the name exists, too few proposals qualify, an override is not allowed, or `--verify` differs |
| `key_bulk_hold` | holds (or with `--release` releases) the listings of the proposals a file names, of one sample's population, for one-at-a-time review: no bulk confirm links a held listing, whatever its proposal now, and no later sample draws it; each row is checked, the refused ones are listed and the others still go ahead; `--by` an active mapping lead | holds and releases nothing twice (`already_held`, `already_released`) | a row was refused (it is NOT held / released), or another run is busy |
| `bulk_confirm_key` | refuses unless the sample is complete and fit and `--lead` is its owner; links every proposal of the sample's population that still qualifies and whose listing is not held, one DecisionService decision each (`bulk_batch_id key_bulk:<sample>`, decided by the owner) | links only what is left (`already_in_batch`) | refused or stopped, or a link failed |
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
- **The hold (step 5).** The file needs a header row with `proposal_id`, `listing_id` and `reason`, in any order. Other columns are
  ignored, so the screened copy of the report with a `reason` column added is a valid file. Each row is listed:
  - `to hold` / `held` (the reason);
  - `already held` (with the reason it was held for);
  - `REFUSED <code>`, with the codes below.

  The run ends with `mode=hold ... hold=N already=N refused={...} held_before=N held_after=N` (`held_*`: the sample's held
  listings still waiting, before and after). Exit 1 means some rows were refused. Those are NOT held: fix the file and run again
  (the other rows were held with `--apply`, and a re-run leaves them as they are). Refusals:
  - `bad_proposal_id`, `bad_listing_id`, `no_reason`, `bad_reason` (a control character, or not UTF-8), `reason_too_long` (over
    500 characters);
  - `duplicate_in_file`;
  - `not_in_population`: not a proposal of this sample's population (a Key proposal made after the draw is not in it). When its
    listing is held, the line says under which proposal and sample: a release names that proposal.
  - `sample_member`: one of the 20, which its owner decides on the screen.
  - `listing_mismatch`: the listing is not the proposal's. One of the two ids is mistyped.
  - `listing_mapped`, `listing_ignored`, `listing_quarantined`: the listing no longer waits. If the bulk linked it already, use
    the undo (step 8). A proposal replaced since by a newer one (a new matching run, a re-band) on a listing that still waits is
    held: the hold covers the newer proposal.
  - `not_held`, release only.

  A held listing stays in the Key queue: its page says "Held back from the bulk confirm: <reason>" (under a newer proposal too),
  and anyone decides it there as usual. The sample's page lists the held ones and what became of each: waiting, decided since,
  or flagged as linked by a bulk confirm (that should never happen: check it, undo with step 8). A busy second run exits 1 and
  writes nothing.
- **Bulk dry run.** The run reports:
  - `population` (the sample's population minus the 20), `eligible`, `excluded` by reason;
  - `held` (how many of the population's listings are held and still waiting; it equals step 5's `held_after` unless a held
    listing was decided since, which the sample's page shows) and one `held:` line each (who, when, why);
  - `units_30d` / `units_365d` of the eligible ones.

  The `--report` file lists each proposal: `eligible` (listing, title, item code and name, confidence, units: what will be linked to
  what) or `excluded` with its first reason, and for a held one `hold_reason`, `held_by` and `held_at`. Reasons a proposal is left
  out (each a person decides on the screens):

  | Reason | Meaning |
  |---|---|
  | `proposal_decided`, `proposal_superseded` | already decided (by a person, or an earlier run of this batch), or replaced by a later proposal |
  | `listing_mapped` / `listing_ignored` / `listing_quarantined`, `pending_decision` | the listing is no longer waiting, or waits for a second person |
  | `held_for_review` | a mapping lead held its listing back for one-at-a-time review (step 5; `hold_reason` says why) |
  | `protected_item`, `counted_item`, `item_merged`, `item_quarantined` | the item is protected or counted (two people), merged away, or has a quarantined listing |
  | `units_per_item`, `flag:<flag>` | not one unit per item, or flagged for two people / a relabel |
  | `listing_rejected_before`, `item_rejected_before`, `listing_decided_since`, `bulk_undone_before` | a person said "not this item" somewhere, decided on the listing since, or an earlier bulk link of it was undone |
  | `failed_sample_population`, `in_other_sample` | the listing was in a failed spot-check's population, or the proposal is in another spot-check's |
  | `no_basis`, `changed:listing_profile`, `changed:listing_link`, `changed:item`, `changed:item_origin`, `changed:target_link` | nothing proves the proposal still describes the listing, the item and the key's target listing |
  | `target_unresolved`, `target_relinked` | the Vape and Go listing the key named was not found, or is not linked to the item with units 1 now (relinked, ignored, a multiple) |
  | `evidence_title_differs`, `evidence_target_title_differs` | a title the judge saw (from the run's export) is not the listing's title now |
  | `not_key_now`, `evidence_unreadable`, `not_key_target` | under the current rules the evidence is not Key, or the item is not both the key target and the judge's pick |
- **Apply.** `applied=N skipped={} failed={}`. A `failed` code leaves that listing untouched; run again later. For example:
  - `map_version_conflict`: someone changed the listing a moment earlier;
  - `target_changed`: the key's target listing changed during the link;
  - `held_meanwhile`: a hold of its listing committed during the link, and the next run leaves it out.

  `stopped=sample_changed_during_run` (exit 1): a sample member was decided otherwise during the run; the links made
  before stand (undo if needed). Each decision is an ordinary applied `link` (`mapping.link` in `audit_log` with the batch id),
  so the screens, the item pages and the feed show them like any other.

Checks afterwards (read-only):

```sql
SELECT state, COUNT(*) FROM match_decision WHERE bulk_batch_id = 'key_bulk:<sample>' GROUP BY state;     -- all applied
SELECT action, actor, detail FROM audit_log WHERE action IN ('mapping.reband', 'mapping.key_sample', 'mapping.key_hold', 'mapping.key_hold_release',
  'mapping.key_bulk', 'mapping.key_bulk_undo') ORDER BY id;
SELECT source, COUNT(*) FROM match_proposal_basis GROUP BY source;                                       -- recorded / backfill
SELECT h.proposal_id, h.listing_id, cl.status, h.reason, h.actor, h.created_at FROM key_bulk_hold h JOIN key_sample k ON k.id = h.sample_id
  JOIN channel_listing cl ON cl.id = h.listing_id WHERE k.name = '<sample>' AND h.kind = 'hold'
  AND NOT EXISTS (SELECT 1 FROM key_bulk_hold r WHERE r.released_hold_id = h.id);   -- held now (status suggested/unmapped = waiting)
```

## Duplicates (`docs/decisions.md` M31-M36, M39-M45; the owner's decision of 6 Oct 2026)

Vape and Go sometimes sells one product on two pages (e.g. 23408 "Vaporesso Xros Corex 3.0 Pods (Pack of 4) - 0.4 ohm" and 25772
"Vaporesso Xros Corex Replacement Pods - 0.4ohm Corex 3.0 Pod - 4 Pack"). When they are the same product, both listings are linked
to ONE CW item: sales and deliveries count once, the reorder list sees one item, and CW holds one stock figure. **Nothing changes on
the site** until Vape and Go goes live on CW: each page keeps its own price, reviews and stock field.

Who: a mapping lead (the owner) on the screens. Deploy first, with `0015_duplicates.sql` (`deploy/staging/install_cron.sh --migrate`,
the migration with the code, never the code first).

1. **Linking -> Duplicates** (`/ui/review/duplicates`): the groups to decide, the biggest sellers first (units in 365 days), with
   "What the rules say": "may be different:" and the reasons (VG/PG, barcodes, option, words, strength ...), or "no reason against
   found". The badge in the menu counts the open groups. **Most of run2's suggestions are different products** (the review of 7
   Oct 2026 found 49 of 60 sampled pairs different); expect to keep most of them separate.
2. **Open a group.** First, in colour, "What the rules say": every reason these may be different products, page by page. Then every
   page side by side: titles, brand, options (more than six fold under "All N options"), barcodes, price, sold in 30 and 365 days
   (sales history), the site's stock and mode now, "open the live page" (`https://www.vapeandgo.co.uk/product/...`), its CW item
   (counted or not, what CW holds for it). Below, the table of what the titles and options say (the option text of each page,
   strength, nicotine type, VG/PG, ml, puffs, pack, ohm, colour, form, flavour words, model numbers, range words, barcode): a row in
   colour differs between the pages; a word in bold is not on every page. **Check the live pages** before any merge.
3. **The kept page** (its CW item stays; the others' items fold into it) is suggested: more sold in 365 days, then a barcode, then
   the older page. "Keep this one instead" changes it.
4. **Decide**, one button. When the rules found a reason against, "Different products - keep separate" is the main button and a
   merge needs the box "I checked the live pages" ticked (without it nothing is saved).
   - "Same product - merge into CW-x": the other pages' listings move to CW-x, with the stock CW holds for their items (the
     opening estimate today; the form says how many units); their merge suggestions are settled.
   - "Different products - keep separate": recorded as "this page is not that item"; the pair is never suggested again and can
     never be merged by mistake (a later merge is refused).
   - In a group of three or more, one choice per page ("Same product", "Different product", "Not sure yet") and "Save these
     choices"; "not sure yet" leaves that page for later (the group stays, "partly decided").
   The next group opens with a one-line notice. A merge that touches a **counted** item (or a pack listing of more than one) waits
   for a second mapping lead in Second approval; a protected item cannot be merged here.
5. **A wrong merge**: open the group (Duplicates -> "Decided recently", 25 a page; or the "Duplicates: group N" link on the item or
   listing page), "Undo a wrong merge" -> "Split it off". "Back to CW-x" undoes that merge: the item comes back with every page the
   merge moved and the stock that came with it, less what those pages sold since (the form says which pages and how many units).
   "To a new item" takes that page alone (stock moves only when the merge moved that page alone). A page that came through two
   merges can only go to a new item. It is never suggested with that item again. Once an item has been counted, a split needs a
   second mapping lead.

What to look for:
- **A refusal is shown on the same page, and nothing was saved:** "One of these listings changed since the page was drawn" (someone
  decided on it, or the site renamed it: check the page as it is now and decide again); "cannot be merged: ... marked as a different
  product before"; "waiting for a second person"; "protected"; "counted a moment ago" (decide again: it now needs two leads); "The
  rules found reasons that listing #N is a different product" (tick "I checked the live pages" if it is the same); "has an open
  suggestion in another group" (decide that group first).
- **The keeper changed by itself:** when nothing is left to decide against the suggested keeper but a suggestion is still open, the
  page keeps the item that suggestion proposes (the person can still pick another).
- **Stock.** A merge moves the merged item's *available* stock (`on_hand - allocated - held`) to the kept item: rows `merge_out` /
  `merge_in` under `doc_ref merge:<decision id>`. What the merged item's own orders in flight need stays on it until they ship (none
  on staging: no site sells through CW yet). The merged item's page says it was merged and where; the kept item's page says which
  items were merged into it.
- **T0.** The rebase at Vape and Go's T0 (`bin/import_opening_estimate.php --rebase`) knows merges: the kept item is rebased on both
  pages' T0 figures, the merged item has nothing left to rebase (or is `merged_item`: no action, M42), and items joined by a merge
  are skipped together when one of them was counted or moved otherwise (M35).
- **mint_vpg** re-runs never suggest a pair again that a person kept separate or merged (`answered=N` in its summary line).

Checks afterwards (read-only):

```sql
SELECT action, state, COUNT(*) FROM match_decision WHERE action IN ('merge_skus', 'reject', 'split') GROUP BY action, state;
SELECT status, COUNT(*) FROM match_proposal WHERE lane = 'vpg_duplicate' GROUP BY status;                 -- open = still to decide
SELECT movement_type, COUNT(*), SUM(qty_delta) FROM stock_ledger WHERE movement_type IN ('merge_out', 'merge_in', 'split_out', 'split_in')
  GROUP BY movement_type;                                                                                 -- out and in net to 0
SELECT action, actor, created_at, detail FROM audit_log WHERE action IN ('mapping.merge_skus', 'mapping.duplicates', 'mapping.split')
  ORDER BY id DESC LIMIT 20;
SELECT id, sku_id, warehouse_id, created_at FROM count_review WHERE source = 'merge_recount' AND status = 'open';   -- counted merges to recount
```

### The wider duplicate sweep (`docs/decisions.md` M37-M38, M41, M43, M45)

Finds more duplicate pages than run2's groups (worded differently, a page without a barcode) and adds them to the Duplicates
screen as groups of the run `sweep-<engine>-<sha>`. Rules only, high precision: one word, value or barcode that speaks against a
pair keeps it out. Nothing is merged: the owner decides each group on the screen. Re-run it after run2's groups are decided.

```bash
# 1. Export (read-only: SELECT inside START TRANSACTION READ ONLY, 30 s per statement, app login), on the web server:
umask 077; TS=$(date -u +%Y%m%dT%H%M%SZ)
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'php -- --root=/opt/cw-staging --db=cw_staging' \
    < tools/vpg_duplicates/export.php > /root/cw_work/vpg_dups/export_$TS.jsonl          # stderr: export: {"listings":14856,...}
# 2. The sweep (no database; about 5 min and 1.5 GB for 14,856 listings; deterministic):
nice -n 19 php tools/vpg_duplicates/sweep.php --export=/root/cw_work/vpg_dups/export_$TS.jsonl \
    --out=/root/cw_work/vpg_dups/sweep_$TS --sample=40
# 3. Read sweep_$TS/sample.txt (the pairs side by side; the new groups first) and summary.json (excluded, groups, refusals).
# 4. Import on staging, AFTER the deploy with 0015 (the importer and the Duplicates screen come with it): copy, dry run, apply.
scp -i /root/.ssh/cw_staging /root/cw_work/vpg_dups/sweep_$TS/groups.jsonl root@46.101.55.135:/srv/cw-import/vpg_dup_sweep_$TS.jsonl
#    on the staging box, in /opt/cw-staging (app login):
php bin/import_vpg_duplicates.php --groups=/srv/cw-import/vpg_dup_sweep_$TS.jsonl            # dry run: would_create=N, nothing written
php bin/import_vpg_duplicates.php --groups=/srv/cw-import/vpg_dup_sweep_$TS.jsonl --apply    # created=N; a re-run says exists=N
```

- The summary line counts what was left out and why: `stale` (relinked since the export), `same_item` (merged meanwhile),
  `answered` (kept separate on the screen), `protected`, `quarantined`, `pending` (a second person is awaited), `open_elsewhere` (the
  listing already has an open suggestion: decide that one first). A group keeps only the pages that pass; with the keeper alone
  it is not written. Exit 1 = a line of the file is unreadable or a proposal failed (stderr says which).
- On the screen a sweep group reads "suggested because the duplicate sweep found no difference" and lists, per pair, the fields
  that agree, what neither page states, where the barcodes stand and the price ratio. It is decided like any other group (M34).
- Checks afterwards (read-only):

```sql
SELECT r.run_id, p.status, COUNT(*) FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id
  WHERE r.source = 'vpg_dup_sweep' GROUP BY r.run_id, p.status;
SELECT detail FROM audit_log WHERE action = 'mapping.import_duplicates' ORDER BY id DESC LIMIT 1;
```

The sweep of 6 Oct 2026 (export 22:57 UTC) under the rules `ds1.0`: 24 pairs accepted, 3 new groups (run id
`sweep-ds1.0-ea706d79b79c`, superseded: do not import it). **Under `ds1.1` (M43, 7 Oct 2026), the same export: 33 pairs accepted,
21 already suggested by run2, 1 kept out (in an open group), 11 new groups** (run id `sweep-ds1.1-0bcd02ccd123`), files in
`/root/cw_work/vpg_dups/sweep_ds11_20261006T225658Z/`: this is the file to import (step 4 with `TS=ds11_20261006T225658Z`).

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

## Opening stock (`bin/import_opening_estimate.php`, `docs/decisions.md` D40, D40a, D40b)

The estimate of a site's stock, before counts. Run it on the CW server. Always do a dry run first:

    php bin/import_opening_estimate.php --csv=<file> --as-of=<ISO time with offset> --doc-ref=opening:<channel>:<as-of> \
        --source="<where the figures come from>" --sha256=<hash of the archived file> [--approved-by=<who>] [--channel=<code>] [--dry-run]

The CSV's first two columns are the variant id and the figure, under a header like
`vapeandgo_variant_id,site_qty_at_...` or the connector's `variant_id,prodt_stock,...`. Further columns
are ignored.

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

**At a site's T0 the estimate is rebased** (D40a, D40b): site stock at T0 + the open paid units. Until the
rebase has run, book no counts or adjustments on that site's items. The steps are below.

**On `cw_staging`:** Vape and Go's duty-day stock (00:00 BST, 1 Oct 2026) was booked on 2 Oct 2026.
That is 8,199 items and 296,599 units. The input is
`/srv/cw-import/opening_input_vapeandgo_duty_start_2026-10-01.csv` (sha256 3561293a…), derived from the
archived duty workbook. Checks: invariants hold; all 14,856 linked listings match the file; a re-run
books nothing.

### A site's T0 (the rebase, D40b)

The connector runs on the site's box, today in `App_proto/src/central_warehouse` (`cw_t0.php` drops root
privileges itself). CW runs on the CW server, in `/opt/cw-staging`. Do it in this order:

1. **Pick the time.** Do it after the day's last dispatch run. A ship of an order paid after T0 that reaches CW
   before the rebase makes its item `moved`, and a skipped item needs a person.
2. **Connector, dry run** (the connector still off): `php bin/cw_t0.php --capture --dry-run`. It prints T0, the
   paid-not-shipped units by age, the batches, the orders it would skip, and the stock file's row count and
   sha256.
3. **Connector, capture** (the connector off): `php bin/cw_t0.php --capture --mode=off`. This writes
   `state_dir/t0_site_stock_<YYYYMMDDTHHMMSSZ>.csv` (+ `.sha256`), the T0 record and the opening batches.
   - It is refused while the effective mode is not off, or when connector order rows already exist.
   - In Phase 3 it is refused for any CW but the local mock. That guard is lifted only with the owner's go, once
     CW staging runs this code.
4. **CW, shadow:** `php bin/channel_set.php --code=<channel> --mode=shadow`, then the same with
   `--actor="<you>" --apply`.
5. **Site, shadow:** set the connector's `CW_MODE` to shadow. The worker sends the opening batches first, the
   final one last and only after every earlier one answered 200.
6. **Connector:** run `php bin/cw_t0.php --status` until `final_acked` is true. It then prints the rebase
   commands for this T0, with the file's name and its sha256 as captured.
7. **Copy the file** to the CW server (from the site's box):

       scp -i /root/.ssh/cw_staging <state_dir>/t0_site_stock_<ts>.csv root@46.101.55.135:/srv/cw-import/

8. **CW, dry run.** `--estimate-doc-ref` is the estimate's doc_ref (on `cw_staging`:
   `opening:vapeandgo:2026-10-01T00:00+01:00`), or `none`:

       cd /opt/cw-staging && php bin/import_opening_estimate.php --rebase --channel=vapeandgo \
           --csv=/srv/cw-import/t0_site_stock_<ts>.csv --estimate-doc-ref=opening:vapeandgo:2026-10-01T00:00+01:00 \
           --as-of=<T0 as cw_t0.php printed it> --source="vapeandgo T0 snapshot <T0>" --dry-run

   It prints:
   - T0 and the final batch's time, and the T0 check (`--as-of`, and the T0 in the file name);
   - listings missing from the file, quarantined listings, and unlinked rows;
   - the opening units (linked, unlinked, released, never committed: a unit cancelled while held and left out
     of the opening body);
   - the items skipped by reason, then one line per skipped item with its target, earlier rows and delta;
   - items already rebased, items with no change, and what it would book (+units, −units, net) under its
     doc_ref (default `opening-rebase:<channel>:<T0>`).

   It refuses (exit 1) before the final batch, when `--as-of` or the T0 in the file name is not CW's T0, and
   when the doc_ref is the estimate's. Skip reasons: `counted`, `other_opening`, `moved`, `quarantined_listing`,
   `no_t0_figure`, `no_mapped_listing` (the item was opened by the estimate, or sold in the opening, but has no
   mapped listing on this channel now: relink it before the real run, or settle it in step 11) and
   `units_elsewhere`. Duplicates merged since the estimate (M35): the kept item is rebased on all its pages' T0
   figures (the merged item's estimate moved onto it with the merge), a merged item has nothing left to rebase,
   and items a merge joined are skipped together when one of them is `counted`, `moved` or `other_opening`.
   `merged_item` (M42): a merged item that holds more than its own opening units in flight need. **No action**:
   never relink it (that would undo the merge); its pages are the kept item's, and a count settles the rest.
9. **CW, real run:** the same command with `--sha256=<the hash cw_t0.php printed> --approved-by="<who>"`
   instead of `--dry-run`. `--as-of` is required here (the hash proves the bytes, not the snapshot).
   - It ends `booked N items, net ±M units`.
   - Running it again books nothing (`already rebased`).
   - A different file under the same doc_ref is refused (`rebase_conflict`).
10. **CW:** `php bin/invariants.php --db=cw_staging` must say `ok`.

    Steps 7–10 can also run from the site's box (SC4), after step 6:

        php bin/cw_t0.php --rebase --estimate-doc-ref=<the estimate's doc_ref, or none> \
            --cw-ssh=root@46.101.55.135 --cw-ssh-key=/root/.ssh/cw_staging --cw-db=cw_staging      # copy + CW's dry run
        php bin/cw_t0.php --rebase ... --apply --approved-by="<who>"                                 # + real run + invariants

    It refuses before the final batch is acknowledged (exit 4) and when the T0 file changed (exit 3), stops at
    the first failing step (exit 1), and records the outcome in the connector's `cw_meta` `t0_rebase`
    (`--status` shows it). Without `--cw-ssh` it prints the commands above. It keeps the caller's user for the
    ssh key and creates no local file. In Phase 3 it is refused for any CW but the local mock, like step 3.
11. **Settle the skipped items** from step 8's list: count the item, or book the listed delta as a staff
    adjustment. A `moved` item that only shipped orders paid after T0 takes exactly the listed delta. From now
    on, counts and adjustments on the site's items are fine.

## Test leftovers on staging (`bin/purge_test_refs.php`, `docs/decisions.md` D47)

Removes the reservations a test left on a staging channel, by order_ref prefix. It always prints a dry run
first, and writes only with `--apply`:

    php bin/purge_test_refs.php --db=cw_staging --channel=<code> --prefix=<order_ref prefix> \
        [--heartbeats-until=<ISO time>] [--actor="<you>"] [--apply]

- **Open reservations are neutralised** through the normal paths first, so the ledger records it: held → released,
  a committed order's open units → cancelled, restockable.
- **A reservation and its units are deleted** only when no ledger row and no oversell event names them.
  Unlinked units never have one. Anything else stays and is listed `kept (ledger)`.
- **The idempotency rows of the refs left without a reservation are deleted.** A kept reservation keeps its
  keys (the report counts them), so a late retry under one of them still replays its answer.
- With `--heartbeats-until`, **all** of the channel's heartbeats (`channel_health`) up to that time and their
  idempotency rows are deleted too, whatever the prefix: heartbeats name no order. Check the dry run's time
  range, addresses and connector versions.
- **Never deleted:** ledger rows and audit rows. Every deleted reservation is audited `reservation.purged`, and
  the run `purge.test_refs`.
- **Staging only:** it refuses (exit 1) unless `/etc/cw/app.env` says `environment=staging` and the schema is
  `cw_staging` or `cw_test_*`, and on a channel in mode `live` (`REFUSED: channel_live`). A prefix needs 4–32
  characters with a letter.
- **Go-live checklist:** before this server or schema carries real orders, remove the marker:

      sed -i '/^environment=staging$/d' /etc/cw/app.env

  `bin/setup_staging.php` writes it only with `--mark-staging`, so a later re-run does not put it back.
- **Exit codes:** 0 ok (dry run included); 1 refused, or a reservation could not be neutralised (the rest are done
  and the failures are listed last); 2 usage; 3 cannot run.

### Removing the `proto1-` smoke leftovers on `cw_staging`

The Phase 3 connector smoke (2 Oct 2026) left these on channel `vapeandgo`:
- 3 reservations (`proto1-<ord_id>`: two committed, one released);
- 5 unlinked units (returned, cancelled, released);
- 9 idempotency rows;
- 4 `channel_health` rows, with their 4 heartbeat idempotency rows.

The smoke's 2 `channel.mode` audit rows stay, like every audit row. Nothing moved stock (every unit is unlinked),
so nothing needs neutralising. Run these from this machine.

**0. Before.** `/opt/cw-staging` must hold code that has this tool, on a `cw_staging` migrated to the same
migrations; the tool, like every job, refuses otherwise (H3). That means the next staging deploy:
`scripts/remote.sh hammer bash deploy/staging/install_cron.sh`, with `--migrate` as part of the owner-approved
Phase I-1 deploy (0006–0008). Check it:

    ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && test -f bin/purge_test_refs.php && php bin/migrate.php --db=cw_staging --status'

**1. Mark the server as staging** (once). This adds one line, prints nothing, and keeps the file's owner and mode:

    ssh -i /root/.ssh/cw_staging root@46.101.55.135 "grep -q '^environment=' /etc/cw/app.env || printf '\nenvironment=staging\n' >> /etc/cw/app.env"

**2. Dry run.** Take one time stamp for both runs; it must not be in the future:

    UNTIL=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    ssh -i /root/.ssh/cw_staging root@46.101.55.135 "cd /opt/cw-staging && php bin/purge_test_refs.php --db=cw_staging --channel=vapeandgo --prefix=proto1- --heartbeats-until=$UNTIL"

It must say:

    reservations: 3 (release 0, cancel 0 units); delete 3 with 5 units; keep 0
      proto1-… committed origin=reserved units=2 {"returned":2} linked=0 ledger=0 oversell=0 -> delete     (and the other two, each "-> delete")
    idempotency rows of these refs: 9 (plus the rows of the release/cancel calls above)
    heartbeats until …: 4 channel_health rows (first …, last …; from <the proto box>; connector vpgcw-…) and 4 heartbeat idempotency rows
    dry run: nothing written; run again with --apply

If any number differs, stop: something else used the prefix or the channel. Look before you apply.

**3. Apply,** with the same `UNTIL`:

    ssh -i /root/.ssh/cw_staging root@46.101.55.135 "cd /opt/cw-staging && php bin/purge_test_refs.php --db=cw_staging --channel=vapeandgo --prefix=proto1- --heartbeats-until=$UNTIL --actor='<your name>' --apply"

It ends with:

    done: released 0, cancelled 0 units; deleted 3 reservations (5 units), 9 idempotency rows, 4 channel_health rows, 4 heartbeat idempotency rows; audited purge.test_refs by <your name>

**4. Check.** The invariants must say `ok`. The connector's read-only snapshot must show `reservations_prefix: []`,
`units_prefix: []`, `idempotency_prefix: 0` and `channel_health.n: 0`, with the 2 `channel.mode` rows still in
`audit_channel_mode`:

    ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/invariants.php --db=cw_staging'
    cd /var/www/html/vpg_ecom/App_proto/src/central_warehouse && ssh -i /root/.ssh/cw_staging root@46.101.55.135 'php -- proto1-' < tests/staging/cw_staging_snapshot.php

The audit trail of the purge:

    SELECT created_at, action, entity_id, detail FROM audit_log WHERE actor = 'system:purge_test_refs' ORDER BY id;

## The staff UI (`/ui`, linking backend front end; `docs/decisions.md` U1-U17, U25-U79)

Server-rendered PHP, no JavaScript framework and nothing from a third party: two static files
(`/ui/assets/app.css`, `app.js`) served by the front controller under the CSP
`default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'`.

**In plain words, design "A with B's parts" (owner's decision of 7 Oct 2026; U25-U79).** A sidebar on a laptop; on a phone a
top bar, a bottom tab bar (To do and the person's main jobs, then More) and the whole menu as a sheet; white cards on grey,
one accent colour, every status an icon shape plus a word, lists as cards on a phone. Home (`/ui/`) is the same page for
everyone: "What needs doing" (numbered task cards, each with "What happens:" and one button), then Matching progress for
the matching jobs, What you can use and Coming later (no dates). Every word comes from `src/Ui/Words.php`; the writing rules
are in `docs/dev.md`. Things the owner should know:
- **The person to ask** is a fixed name on every screen, `Words::ASK` = "Fazil" (change it there if that changes).
- **The test strip.** Where `app.env` says `environment=staging` (the staging box: the test slots and `cw_staging` read the
  same `/etc/cw/app.env`, the line `TestRefPurge` needs), every page, sign-in and error pages too, carries "TEST SYSTEM:
  nothing here is real". On `cw_staging` the owner's matching answers are real work that will be carried forward, so the
  wording is the owner's to confirm (U70); a live copy must never carry that `app.env` line.
- **The owner's account with Admin.** While an account holds Admin, its Reviewer and Matching lead jobs are switched off
  (separation of duties, I12): every page shows the yellow strip "Your Reviewer and Matching lead jobs are switched off
  because this account also has Admin. Ask Fazil to take Admin off this account." The fix is to untick Admin on that
  person's page (another admin) or `bin/reset_staff.php --roles=...`. Do **not** answer the owner's spot check from another
  account: spot check owner-1 belongs to this account, and a decision on one of its 20 by anyone else fails it for good.
- **The spot check on the screens.** Only its owner sees the answers of its 20 matches; anyone else sees who owns it and no
  forms, also after a match was confirmed (changing a confirmed member fails the spot check too). "Not a match", and the
  owner's "Change this match" on a confirmed member, open a second step that says "This stops the bulk link for good."
  before anything is saved.
- **Before a deploy, check in a real browser** (Chrome; neither this box nor staging has one): 320, 375, 768, 1,024 and
  1,280 px, light and dark. At least: sign-in (with "NOT saved" and "open too long"), Home for the owner's account, a buyer
  and a reviewer, a list of matches, one website product (normal, a spot check's match as its owner and as another lead, a
  confirmed member, waiting for a second OK), the spot check, Possible duplicates (two pages and three), the orders list,
  the draft order editor (scan, the two buttons under and over the limit, at 1,280 px with 10 lines: no sideways page
  scroll), one order as buyer and reviewer, What to buy with one product's Why, the suppliers pages, a person page; and the
  Deliveries screens (U85-U90): Receive + invoice with "Start a new delivery", a delivery's editor (scan, "Save and book in" with
  the save bar above the phone's tab bar, the lines as cards at 375 px), the read-only delivery as its keyer, as the bench and as a
  reviewer (the "What each answer does" box), the goods-in bench list and the bench check **on a tablet (768 and 1,024 px, portrait
  and landscape)** with 3 lines (find a line by scanning, typing a CW number in "Find a line" with the letter keyboard, the two fill
  buttons, the unstamped choice appearing, a camera photo; at 768 px portrait and at 375 px, Tab and the scanner's Enter into a field near
  the bottom of the page leave it above the save bar, also with "Save and show the next 40 lines"),
  Incidents (closing one), Home for goods in, the desk and a reviewer with a delivery in each state, and a product's "Selling mode
  on the websites" (the cards, the "?", the form; a website whose writer is on while its connection is not live reads "Stock link
  switched on: starts when the website connection is live", never "Stock link on").
- **Timing.** Home runs, for a matching lead, one spot-check status per own waiting spot check (at most 5) and one set-aside
  query per spot check with holds (at most 10); for `reorder.view`, the reorder header. Time `/ui/` and one ordinary page as
  the owner's working account, a lead and a reviewer on staging data before the deploy (plan budget: 100 ms over the old
  dashboard).

Who sees what is decided per **permission** (`src/Auth/Permissions.php`, decisions I11–I16): a person holds one or more
roles, may do what any of their roles may, and a page outside their permissions is refused (403), not only left out of
their menu. Roles are read on every request: a role taken away stops working on the person's next page.

| Screen | Permission | Roles |
|---|---|---|
| `/ui/login`, `/ui/password`, `POST /ui/logout` | public (sign-in), any signed-in (the other two) | everyone (password change is forced at first sign-in) |
| `GET /ui/logout` (an old bookmark: leads Home or to the sign-in page; never signs anyone out, U61) | public | everyone |
| `/ui/`: Home, the same page for everyone ("What needs doing", Matching progress with `linking.view`, What you can use) | any signed-in | everyone |
| `/ui/review?queue=<band>&channel=<code>`, `queue=pending` (second approval), `/ui/review/listing/{id}` | `linking.view` | viewer, mapper, mapping_lead, warehouse, manager, admin, auditor |
| the decision form: link, new item, ignore, reject, withdraw | `mapping.decide` | mapper, mapping_lead |
| approve a waiting decision | `mapping.approve` | mapping_lead, never the person who decided |
| `/ui/review/duplicates`, `/ui/review/duplicates/{id}` (Duplicates: the groups, one group side by side) | `linking.view` | viewer, mapper, mapping_lead, warehouse, manager, admin, auditor |
| merge, keep separate, split (the forms on a group's page) | `mapping.approve` | mapping_lead (a counted item: a second mapping lead approves) |
| `/ui/items/{id}`, `/ui/search` | `catalogue.view` | all 14 roles |
| `/ui/people`, `/ui/people/{id}` (People and roles: list, history) | `staff.view` | admin, auditor |
| change a person's roles, switch an account off/on (never one's own) | `staff.manage` | admin |
| `/ui/documents/reviews` (Review queue: blocking approvals, then posted documents waiting for review) and its approve / reject forms | `documents.review` (an approval also needs `documents.approve`); never the document's creator, submitter or poster, never admin | reviewer |
| `/ui/documents` (list: type, status, review, number or external reference), `/ui/documents/{id}` (header, lines, files, review history, reversal links), `/{id}/pdf`, `/ui/files/{id}` (stored files) | `documents.view` | buyer, purchasing_manager, goods_in, purchasing_desk, stock_controller, reviewer, accountant, auditor, manager, warehouse |
| reverse a posted document (the form on its page) | `doc.<TYPE>.post` of its type, never admin | the type's posting roles (I16) |
| `/ui/reference/reasons` (+ `reasons.csv`), `/ui/reference/series` | `reference.view` | all 14 roles |
| `/ui/people.csv` (the People list for Excel) | `staff.view` | admin, auditor |
| Stock control, Trade, Accounts: not built yet (Receiving is: the Deliveries rows below); Home says "Coming later: …" (no dates, no phase codes) for the jobs that will use them | `doc.<TYPE>.post`, `accounts.view` | proposals pending decisions 3 and 11 (I16) |

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
   channel `--ips` for the API (`bin/create_channel.php`, or `bin/channel_set.php --ips=.. --apply` for an existing
   channel), and create the staff accounts above.

Renewal is certbot's systemd timer (deploy hook reloads Apache). Logs: `/var/log/cw-web/php-error.log` (rotated
hourly at 20 MB) and `/var/log/apache2/cw-https.{access,error}.log`. To switch it off again:
`a2dissite cw-https cw-acme && systemctl reload apache2`.

### Changing the public web server's settings (`install_web.sh`)

Once the public vhost is on, `enable_https.sh` is not run again: a changed `php-fpm-cw-web.conf` or `apache-cw-https.conf` goes out
with `scripts/remote.sh <slot> bash deploy/staging/install_web.sh` (`--check` first: it shows the diff and installs nothing). It
installs both, enables `mod_http2`, puts the old files back if `php-fpm -t` or `apache2ctl configtest` refuses, reloads, and checks
`/ui/login` (200 over HTTP/2), a versioned asset (`Cache-Control: public, max-age=31536000, immutable`), `/favicon.ico` (404 from
Apache) and `/v1/health` (401). Run it after `install_cron.sh` has copied the code it needs. Since 8 Oct 2026 (branch `perf`): the
pool is `pm = dynamic` (2 at start, 1-3 idle, 10 at most; the staff screens keep a persistent database link per worker), HTTP/2,
`KeepAliveTimeout 60` and the favicon rule.

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

### Company details (the screen; `docs/decisions.md` I90–I99)

The company that buys the stock, as every purchase order prints it (legal and trading name, company number, VAT, registered
address, purchasing phone and e-mail, delivery address), lives in `company_profile` (0013), one row per version. It is changed
**on the screen**, by a person holding **reviewer** (the owner on staging); everyone else reads it; admin never changes it.

1. Reference › **Company details** (`/ui/reference/company`; also the card at the top of Reference › Settings, and the link on
   any purchase order whose PDF says "do not send").
2. **Add or change the details** → one form → **Save**. Empty fields may be filled in later. The form refuses, with a sentence at
   the field: a company number that is not 8 characters (8 digits with the leading zeros, or 2 letters + 6 digits; the rarer R +
   7 digits and IP/SP/NP + 5 digits + R pass too); a VAT number that is not GB/XI + 9 or 12 digits (spaces and a missing GB are
   fine); a VAT choice that disagrees with the number ("VAT registered" needs a number, "Not VAT registered" none); an address over
   8 lines or a line over 100 characters; a character the PDF cannot print (letters outside Western European ones, emoji). A save
   makes the details **not confirmed**.
3. Check every detail against Companies House and the VAT certificate, then **These details are correct** (the button appears
   once legal name, company number, registered address, VAT number or "Not VAT registered", purchasing e-mail and delivery
   address are all there; details copied from the old settings that still need tidying say "Save once, then confirm"). New
   drafts' PDFs lose the "COMPANY DETAILS NOT CONFIRMED — DO NOT SEND" banner.
4. **See how a purchase order will look (PDF)**: a SAMPLE order (no number, fictional supplier) with the details in use.

- Orders **approved before** the details were confirmed keep the details they were approved with, and their PDF keeps saying
  "do not send" (the Company details page counts those approved and not sent yet): amend them (cancel + copy) to print the
  confirmed details; sending one anyway needs "Send anyway" ticked (I86).
- A person who **confirms their own change** of the legal name, company number, VAT, purchasing e-mail or delivery address
  (compared with the last confirmed details, however many saves it took) asks **another reviewer** to check it (Document reviews,
  type "Company details"; decided on the Company details page by a reviewer who neither made nor confirmed the change). It stops
  nothing. A change confirmed by someone else needs no check. With one reviewer the check stays open, calmly ("Nobody else holds
  the reviewer role yet"): name a second reviewer (decision 3) to close it.
- **Rejecting** the change unconfirms the details if they still carry it; the people who made or confirmed it cannot confirm it
  again (another reviewer can, if it was right after all). Approved orders that carry the rejected change are listed on the
  Company details page, say so on their own page, print "COMPANY DETAILS REJECTED AT REVIEW — DO NOT SEND" and warn before
  sending: cancel or amend them.
- If someone else saved meanwhile, the save is refused, nothing written: the form shows what changed and keeps what was typed.
- Every version (who, when, what changed, why) and every confirmation is on the page; `audit_log` has `company.change`,
  `company.confirm`, `company.review`. The nightly `bin/invariants.php` checks every version against them (C1–C4): a
  `company details version n ...` line means a row was added outside the screen. Keep the evidence (copy the row and the audit
  rows of the company details), tell the owner, and look at who could write as the app login (`/etc/cw/db.env`). Until a reviewer
  saves and confirms the right details on the screen, the details in use may be the made-up ones. Only a version number at the top
  of its range blocks saving ("The history of the company details is damaged"): then, after copying it, remove that one row with
  the admin login.
- There is **no command-line way** to change them: `bin/settings.php --set=company.<key>` is refused (exit 2) with a pointer to the
  screen. If nobody can sign in with reviewer, give a real person the role (`bin/reset_staff.php --email=<address>
  --roles=reviewer[,...]`, "Staff accounts" above), never admin.

### Settings (`bin/settings.php`)

CW's settings live in `app_setting` (0009): the supplier approval rules (decision 11), the cost write-back switch (decision 12)
and, from the later I-2 tasks, the PO and reorder defaults. Every seeded value is **provisional** until the owner confirms it; the
screen `/ui/reference/settings` (every role) shows each one with its decision number. The app login can only read them. The
company details are not settings any more (above).

```bash
# on the staging box, in /opt/cw-staging, as root (or from a slot: scripts/remote.sh <slot> php bin/settings.php ... --db=cw_test_<slot> --admin)
php bin/settings.php --list                                                   # key, type, value, [provisional], decision
php bin/settings.php --set=suppliers.approval_due_days --value=5 --reason="owner asked for five days, 3 Oct" --admin
php bin/settings.php --set=po.terms --value-file=/root/terms.txt --reason="owner's PO terms" --admin   # several lines
php bin/settings.php --set=po.terms --value-file=/root/terms.txt --reason="owner confirmed the terms" --confirmed --admin
```

- `--set` needs `--admin` (the admin login of `/etc/cw/db.env`): without it the tool stops at once with "settings change
  needs --admin (cw_app has SELECT only)", exit 1.
- The value is parsed for the setting's type: `int` (whole number), `decimal` (`0.50`, kept exactly as typed), `bool`
  (`true`/`false`), `string` (one line, ≤ 255), `text` (≤ 4000, line breaks allowed: give it with `--value-file`), `date`
  (`YYYY-MM-DD`); an empty value clears a string, text, number or date. Key rules (`Settings::RULES`): day counts 0–120,
  `suppliers.approval_due_days` 1–120, `po.default_vat_code` an active VAT code, weights 0–1, the reorder windows against each other.
- `--confirmed` also records that the owner confirmed the value (the "provisional" mark goes).
- A change writes `updated_actor = system:settings` and the audit row `setting.change {key, before, after, reason}`;
  sending the value the setting already has writes nothing ("unchanged").
- Exit codes: 0 done · 1 `--set` without `--admin` · 2 usage, unknown key, a `company.*` key, bad value or reason (3–500
  characters) · 3 cannot run.

| Key | Type | Default | Decision | Meaning |
|---|---|---|---|---|
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
- **After approval** the order's page offers: the PDF (letterhead from the company details it was approved with; "COMPANY
  DETAILS NOT CONFIRMED — DO NOT SEND" when they were not confirmed then: see "Company details" above), **Mark as sent** (e-mail, portal, phone, ...; again allowed; the PDF as
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

## Item cards and barcodes, Phase I-3 (IM3; `docs/decisions.md` I100–I124)

The legal and buying fields of every item (product type, liquid ml, nicotine mg/ml, duty-liable, single-use, ECID / GB-ID,
manufacturer, brand, flavour, discontinued), the TRPR and single-use rules, outer-case barcodes, the barcode sync from the sites
and its review queue. Nothing here books stock. **Not deployed to `cw_staging` yet:** it needs migration 0016 and the code
together ("Deploying IM3 to staging" below), after the owner's go.

### Who does what (provisional, owner to confirm: I109)

| Screen | Permission | Roles |
|---|---|---|
| Items › **Item cards** (`/ui/items/cards`, `.csv`), the Item card and Barcodes sections of `/ui/items/{id}` | `catalogue.view` | all 14 roles |
| the card form (`/ui/items/{id}/card`), "Use this" (a suggestion), "Confirm the card", add / remove a barcode, units per scan, the CSV import (`/ui/items/cards/import`), Items › **Barcode review** (`/ui/items/barcodes`) | `catalogue.edit` | mapping_lead, stock_controller, purchasing_manager (never admin) |

### What the rules do (I102, I103, I113)

- **TRPR** (reg 36): a nicotine refill (e-liquid, shortfill, nic shot) over 10 ml; a tank, prefilled pod, device / kit or
  single-use vape holding over 2 ml; nicotine over 20 mg/ml. **Single-use**: a person answered "single-use: yes" (never assumed
  from "disposable"). A device / kit cannot be confirmed without the capacity of its tank or pod: **0 ml = it comes without one**
  (a mod or a battery; I115).
- **Until a person confirms the card's fields: a warning** (the item page, the item cards list, the reorder list's flag
  "item card warning: <rule>", the PO editor's warnings). Nothing is refused.
- **What a person confirmed blocks, until a person confirms the card again.** Confirming a card that breaks a rule needs the box
  "I checked the packaging: these fields are right, and the item will be blocked". Editing the card afterwards (correcting 5 ml to
  2 ml, emptying a field, retyping the product type, "single-use: not known") makes it "changed since it was confirmed" but **does
  not lift the block**: someone must confirm the corrected card. A rule an edit breaks on a card confirmed compliant is a warning
  until someone confirms the card with it. So a block always rests on a person's acknowledged confirmation (I113).
- **What a block stops today:** it is never suggested on the reorder list, "create draft PO" skips it, and a purchase order with it
  is refused at approval (422 `item_blocked`), and since IM6 a receipt of it is refused at posting. **CW does not stop website
  sales yet** (IM10 will, through `CW\Catalogue\ItemCompliance`): take a blocked item off sale on the website by hand. Items › Item cards › tick **Blocked**
  lists them, most stock first (I121).
- **Discontinued**: never suggested on the reorder list (as "do not reorder"); a buyer can still order it on purpose.
- **Duty** (for receiving, IM6): an item whose card does not answer duty-liable is treated as duty-liable (an unstamped delivery
  of it is refused, decision 8), except a card that names a coil, tank or accessory (no liquid). Items with no card at all are
  treated as duty-liable: give the hardware a card before 1 Jan 2027 (I119).

### The catalogue work

1. Items › **Item cards**, ticked "Holds stock" and "Card: Not confirmed": the items holding stock, most stock first (8,199 on
   staging). Download the CSV (the list's filters apply), fill in `product_type`, `liquid_ml`, `nicotine_mg`, `duty_liable`,
   `single_use` (yes / no), `ecid`, `manufacturer`, `brand`, `flavour`, `discontinued` in Excel, save as CSV, and import it
   (Items › Item cards › **Import a CSV file**): **Check only** first, then **Save the changes**.
   - The screen takes the whole download (up to 2 MiB): unchanged rows cost almost nothing. **At most 2,000 items may change in
     one import**; a file that would change more is refused whole (`too_many_changes`): delete the rows you did not change, or
     import it in parts (filter the list, e.g. by product type or brand, and download each part) (I120).
   - An empty cell changes nothing. A file with any refused row changes nothing at all: the page lists every problem
     ("Row 12 (CW-000123), nicotine_mg: ..."; row 12 is spreadsheet row 13); correct them and import the whole file again.
   - Keep `card_version` from the export: a row whose card someone changed on the screen since is refused, not overwritten.
   - A flavour from a file is "proposed"; it becomes confirmed on the item page ("Confirm this flavour", or when the card is
     confirmed). Saving the card form for another field leaves it proposed (I114). **A file never confirms a card.**
   - Product types in a file: `e-liquid`, `shortfill`, `nicotine shot`, `prefilled pod`, `device / kit`, `single-use vape`,
     `coil`, `tank`, `accessory`. A bare "pod" is refused (it may be an empty refillable pod: say `prefilled pod` or `accessory`).
2. On each item's page: check the fields against the box, use the suggestions that are right ("Use this": from the matcher's
   identity card and the linked listings), then **Confirm the card: these fields are right**.

More than 2,000 changes, or a file over 2 MiB, goes through the CLI (up to 32 MiB / 20,000 rows; by default at most 5,000
changes, `--max-changes` up to 20,000), on the staging box in `/opt/cw-staging` (app login; the slots:
`scripts/remote.sh <slot> php bin/... --db=cw_test_<slot> --admin`). The cards a real run changes stay locked until it ends
(about 14 ms a card), so a person saving one of them waits: run big files at a quiet time, or in parts.

```bash
php bin/import_item_cards.php --file=/srv/cw-import/cards.csv --staff=<e-mail of a catalogue.edit person>        # dry run (no lock, no write)
php bin/import_item_cards.php --file=/srv/cw-import/cards.csv --staff=<e-mail> --apply --report=/tmp/cards-report.csv
```

Exit codes: 0 done · 1 refused rows or a refused file (nothing saved) · 2 usage, or `--staff` cannot change item cards · 3 cannot
run. Every run, dry runs included, is an `import_run` row (kind `item_cards`; `summary.origin` says `screen` or `cli` with the
OS user); each changed card has its history row and `item_card.change` audit row (kind `import`); a real run is audited
`item_card.import` (with the origin). A file the screen refuses as a whole on "Save the changes" is recorded too (I120).

### The barcode sync (`bin/sync_barcodes.php`, I106–I108, I116–I118)

The barcodes the sites hold for linked listings (`listing_profile.barcodes`) into the items' barcodes. A barcode no item has is
added (1 unit a scan); one already on another item, or a new one on a multipack listing (units per item ≠ 1), goes to Items ›
**Barcode review** — never moved. A barcode found on two items is unusable on the item that has it until a person decides.
Stricter than the matcher: a value with letters ("SKU-12345670") and GS1 restricted-circulation numbers (in-store and internal
codes: GTIN-13 020–029, 040–049, 200–299; GTIN-8 2…) are skipped and counted.

```bash
# on the staging box, in /opt/cw-staging (app login), after the deploy with 0016
php bin/sync_barcodes.php                          # DRY RUN: what it would do; writes nothing
php bin/sync_barcodes.php --apply --limit=500      # a canary: the first 500 linked listings
php bin/sync_barcodes.php --apply                  # the rest (idempotent: what is done is "already")
php bin/sync_barcodes.php --apply --channel=electrofag
```

The log line: `listings`, `codes` (usable GTINs), `unusable_codes` (skipped; of which `junk` = letters in the value, `restricted`
= restricted-circulation numbers), `already`, `skipped_decided` (a person decided that barcode for that item: kept elsewhere,
unusable, not added, removed it, or moved it away), `in_review`, `added`, `review_on_another_item` (of which `holder_merged`: the
item that has it was merged into the listing's item), `review_multipack_listing`, `made_unusable`, `raced` (another writer added
it at the same moment: the next run sees it), `open_reviews_now`, `source_unlinked` (barcodes added earlier from a listing that
is no longer linked to their item, as found before the run; the first 20 are listed under the line). The sync never changes
those: a person checks each on the item page and removes what is wrong. Exit 0 also when reviews were opened (they are work for
people). A real run writes one `sku_barcode.sync` audit row. **No cron is installed**: when the owner wants it nightly, after the
listing pushes (`PUT /v1/listings` or `bin/import_listings.php`), add `php bin/sync_barcodes.php --apply` to the staging crontab,
after a decision on who watches the review queue. Use `sync_barcodes`, not `seed_barcodes`, from now on: the seeder marks a clash
unusable without opening a review (it does honour the decisions people made, I118).

**The review queue** (Items › Barcode review; the menu shows the open count to the people who decide):
- *on another item*: "It belongs to the item that has it" (the site's listing carries a wrong barcode: correct it on the
  site), "It belongs to the listing's item: move it there" (with its units per scan; the item it leaves is recorded so its own
  listing does not claim it back, I116), or "Shared by both: keep it unusable" (a box or catch-all code). When the item that has
  it was merged into the listing's item, the queue says so and "move" is chosen for you.
- *multipack listing*: "Add it to the listing's item" (units per scan: the listing's units per item unless typed: 10 for a
  10-pack's own barcode, 1 when the listing carries the single unit's barcode), or "Do not add it".
- A barcode becomes usable again only when its last open review is decided, and never after a person ruled it shared (a later
  "keep" or "move" for another item keeps it unusable and says so; to use it again, remove it from the item and add it by hand,
  I117). A decision that no longer fits (someone moved or removed the barcode meanwhile) is refused and says where it is now.

**On an item's page** (Barcodes): add a barcode (8, 12, 13 or 14 digits with a valid check digit; the page says the right last
digit when only that is wrong) with its **units per scan** (an outer case: "this barcode = 10 units"; receiving (IM6) counts a
scan of it as a pack of 10; a purchase order line found by scanning it is the supplier's pack of that size when there is one), change the
units per scan, or remove a barcode ("Remove…" opens the reason and the button; recorded, so neither the sync nor the seeder adds
it back to that item; adding it by hand again is allowed). A barcode belongs to one item: one already on another item is refused
with a link to that item.

### Deploying IM3 to staging (the owner's go first)

`0016_item_cards.sql` is the only PENDING file on `cw_staging` (at 0015): three new tables (`item_card`, `item_card_change`,
`barcode_review`), and `import_run.kind` gains `item_cards`. No backfill, no seed. Deploy it with the code of the same commit in
one run, never the code first (the item page reads `item_card`):

```bash
scripts/remote.sh <slot> vendor/bin/phpunit                                  # green first
scripts/remote.sh hammer bash deploy/staging/install_cron.sh --migrate       # code to /opt/cw-staging + 0016 + grants + smoke-run
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/migrate.php --db=cw_staging --status'   # no PENDING file
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/invariants.php --db=cw_staging'         # ok (IC1-IC3 included)
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/sync_barcodes.php --db=cw_staging'      # the dry run: read it before --apply
```

Check afterwards: the three tables exist and are empty; cw_app holds SELECT, INSERT, UPDATE on `item_card` (no DELETE), SELECT,
INSERT on `item_card_change`, SELECT, INSERT and UPDATE of the decision columns only on `barcode_review` (`bin/migrate.php`
converges the grants); Items › Item cards opens (every item not merged away, 0 cards) and Items › Barcode review is empty.


## Receiving, Phase I-3: Receive (+ invoice) with duty-stamp checks (IM6; `docs/decisions.md` I125–I147)

One receipt per supplier invoice: the purchasing desk keys it, the goods-in bench checks the delivery on a tablet, the desk (or the
bench) posts it, a second person reviews it within 3 days. Posting books the stock at once (goods_in at the line's provisional cost):
accepted units into MAIN, damaged / wrong-item / over units into VERIFY, quarantined unstamped units into UNSTAMPED; an incident per
exception; the PO's receipts; the items' selling modes for the site stock writer (IM10). **Not deployed to `cw_staging` yet:** it
needs migration 0017 and the code together ("Deploying IM6" below), after the owner's go.

### Who does what (provisional, owner to confirm: I126, I141)

| Screen | Permission | Roles |
|---|---|---|
| Deliveries › **Receive + invoice** (`/ui/receiving`), a delivery's page (`/ui/receiving/{id}`) | `receiving.view` | goods_in, purchasing_desk, purchasing_manager, stock_controller, reviewer, accountant, auditor, manager |
| "Start a new delivery", the editor (its keyer), "Copy the order", the sheet import, files, book in, cancel, take back; Deliveries › **Goods-in bench** (`/ui/receiving/bench`) and the bench check of a delivery | `doc.GRN.post` | goods_in, purchasing_desk, purchasing_manager (never admin) |
| Deliveries › **Incidents** (`/ui/receiving/incidents`) | `incidents.view` | goods_in, purchasing_desk, purchasing_manager, stock_controller, reviewer, auditor, manager |
| closing an incident (resolved / dismissed, with a note) | `incidents.resolve` | purchasing_desk, purchasing_manager, stock_controller |
| the check of a delivery (on its page; Waiting for me, or Home's "Deliveries booked in to check") | `documents.review` | reviewer, never the person who keyed, posted or **checked it at the bench** |

The screens' words (U85-U90): a receipt is a **delivery**, posting it is **booking it in**, units are **items**; the stock places are
**into stock** (MAIN), **set aside to check** (VERIFY) and **the unstamped quarantine** (UNSTAMPED; the "?" beside "Where the items
went" names the codes for older notes). The menu section is **Deliveries**.

### A delivery, step by step (the desk and the bench)

1. **Desk:** Deliveries › Receive + invoice › **Start a new delivery**: the purchase order it is against (optional: the supplier
   follows), or the supplier; the **supplier invoice number** (compulsory before booking in; one delivery per supplier invoice, however
   it is written: "inv 001" = "INV001"); "Copy the order's lines still to come" copies them (in the order's packs, at its prices).
2. **Desk:** change what arrived differently in the one form (packs, price, the order line, the selling mode), or add lines: scan a
   barcode, type the supplier's code, a CW code or words of the name (with a pack price if you have it); or import the supplier's
   invoice or packing list (CSV / XLSX; any header row in the first 30, the supplier's column names; rows without a code are
   skipped and listed; anything else wrong imports nothing). **A barcode counts the units it stands for** (I173): a case barcode
   marked "x5" adds 5 units (5 singles, or one pack of 5 when the supplier sells packs of 5, or a line "case of 5" when the supplier
   only sells boxes of 10: check its price); one bottle's own barcode, when the supplier sells boxes, asks once "a box, or single
   units?". The message after each scan names the line, the item and the units added. **Attach the supplier's invoice** (a PDF, or
   a photo of a paper invoice; one invoice copy is the invoice of one receipt only, I171). Received at: when the goods arrived
   (UK time, "The goods arrived"). The invoice number is needed before booking in, not before scanning.
3. **Bench:** Deliveries › Goods-in bench › **Check this delivery**: "Do the supplier and the paperwork look right?" (No = do not
   book it in: refuse the delivery and cancel it; the desk's Home card "Deliveries refused at the goods-in bench" and the list's
   "Refused at the bench, not cancelled yet" lead to it, U93); if the desk keyed it before the goods came, tick "The goods arrived now" (the
   duty rule goes by the arrival date shown at the top). Per line: tick **Checked** (or change one of its answers: that checks it
   too); for duty-liable liquid the **duty stamp on the outer retail pack and sealing it** (yes / no: "no" means every unit
   that arrived is unstamped), its type (digital / transitional), a scanned stamp code (the scanner's Enter moves on, it does not
   save); what arrived differently, in units: short, over (more than the line itself: tick "the over count is right"), damaged,
   wrong item (say what came instead in the note and photograph it), unstamped and what happens to the unstamped units (the
   choice appears once some are unstamped). "Find a line": scan an item to jump to its card. "Every duty line on this page: stamp on
   the pack, digital" and "Tick every line on this page as checked" only fill the form. Save the check (40 lines a page). Photos of
   damage, a missing stamp, the supplier's certificate (a camera photo is made smaller before it is sent; save the check first:
   attaching leaves the page). **The desk and the bench can work at once** (I175): a save that only crossed the other side's
   (a price, a note; a bench answer) is saved anyway; a real clash (the same line's quantity changed) redraws the page with what
   was typed. A line the desk adds or whose quantity changes after the check is counted again: the receipt cannot post before
   (I168). Anyone who receives goods can set the supplier invoice of a receipt the bench started from the delivery note (its page,
   "The supplier invoice", I172); they do not review it then.
4. **Desk:** Home's card "Checked deliveries to book in" (or the list's "Checked at the bench, not booked in yet") leads to it; the
   delivery's page lists everything that still stops it ("Before it can be booked in") and where the items will go; **Save and book
   in**. A second person checks it (Home's card "Deliveries booked in to check", or the delivery's page). "Not OK" takes the delivery
   back (its stock and what it received against the order): key it again if the goods are here.
5. **Incidents:** Deliveries › Incidents lists the open ones (the menu and Home show how many): close each with what was done
   ("Dealt with": credit asked, goods returned, re-delivered) or "Nothing needed". The items stay set aside (VERIFY) or in the
   unstamped quarantine (UNSTAMPED) until a stock control document moves them (Phase I-4); UNSTAMPED must be empty from 1 Apr 2027.
6. **The selling mode** (I169): a receipt sets the mode only of an item it accepts something of into MAIN. A delivery that was all
   short, refused unstamped at the door or quarantined leaves the item's mode as it was, so it never goes back on sale (nor e-mails
   the waiting customers) without stock.

### Duty stamps (owner decision 8; I134, I135)

- Duty-liable liquid must arrive stamped. Before `receiving.unstamped_refusal_from` (1 Jan 2027, the day the goods **arrived**),
  unstamped units are accepted only with the supplier's evidence that they were made or imported before 1 Oct 2026 (the evidence
  typed on the bench, its certificate attached as "Duty evidence"): they must be sold, returned or destroyed by 31 Mar 2027. From
  that date they are **refused at the door** (not booked) or **quarantined** (UNSTAMPED), with an incident. Anything made or imported
  from 1 Oct 2026 that arrives unstamped is always refused or quarantined.
- **An unstamped delivery's damaged and over units are unstamped too** (I167): with no stamp on the pack, or with unstamped units
  refused or quarantined, the line's damaged and over units are refused or quarantined with them (never put in VERIFY as ordinary
  stock); wrong-item units stay in VERIFY. Units accepted on the pre-October evidence keep going to VERIFY when damaged.
- No stamp check for a card that names a coil, tank or accessory; an item with **no item card** is treated as duty-liable (give
  the hardware a card).
- The expected duty (ml x 22p, rounded down per unit) is shown for information only; it books nothing.

### A delivery keyed later (CW was down: the paper receiving sheet)

Fill in a paper receiving sheet at the bench (supplier, invoice number, each item and pack, what arrived, the stamp check, the time
the goods arrived). When CW is back, key it as a normal receipt with **Received at** = the real time from the sheet, tick "Keyed
from a paper receiving sheet" and say why in "Why it is keyed late" (compulsory for any delivery that arrived before the day the
receipt is keyed). At most `receiving.backdate_max_days` (30) days back. Scan or photograph the paper sheet and attach it as a
delivery note.

### Settings (`bin/settings.php`, I38)

| Setting | Default | |
|---|---|---|
| `receiving.unstamped_refusal_from` | 2027-01-01 | decision 8 (confirmed); from this received date unstamped duty-liable units are refused or quarantined |
| `receiving.duty_pence_per_ml` | 22 | the expected duty's rate (£2.20 per 10 ml), for information |
| `receiving.backdate_max_days` | 30 (provisional) | how far back a receipt may say the goods arrived |
| `receiving.mode_after_out_of_stock` | From-Warehouse (provisional) | an Out-Of-Stock item's mode on receipt when its previous mode is not known (In-Stock or From-Warehouse) |
| `po.over_delivery_tolerance_pct` | 10 (provisional) | how far over a PO line's ordered units a receipt may go |

```bash
php bin/settings.php --list                                                                   # list (app login)
php bin/settings.php --set=receiving.backdate_max_days --value=14 --reason='owner: two weeks' --admin
```

Since 0019 the owner and Fazil change these on the screens (Settings → Settings and lists → the setting; "Changing settings and rules
yourself" below); the CLI stays for the server.

### Deploying IM6 to staging (the owner's go first)

`0017_receiving.sql` is the next file after 0016: five new tables (`goods_receipt`, `grn_line`, `grn_posting`, `incident`,
`item_selling_mode` + `item_selling_mode_log`) and four `receiving.*` settings. No backfill. Deploy it with the code of the same
commit in one run, never the code first (the receiving screens and the GRN handler read the new tables). **0017 and 0018 go
together** (the commit that holds IM6 also holds IM10's 0018: `install_cron.sh --migrate` applies both, in order); the steps of
"Deploying 0018 to staging" below are the same run:

```bash
scripts/remote.sh <slot> vendor/bin/phpunit && scripts/remote.sh ui vendor/bin/phpunit && scripts/remote.sh api vendor/bin/phpunit
scripts/remote.sh hammer php tests/concurrency/hammer.php --seed=<n>                    # stock code booked by documents: green first
scripts/remote.sh hammer bash deploy/staging/install_cron.sh --migrate                 # code to /opt/cw-staging + 0017 + grants + smoke-run
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/migrate.php --db=cw_staging --status'   # no PENDING file
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/invariants.php --db=cw_staging'         # ok (G1-G8 included)
```

Check afterwards: cw_app holds SELECT, INSERT, UPDATE on `goods_receipt` and `item_selling_mode` (no DELETE), FULL on `grn_line`,
SELECT, INSERT on `grn_posting` and `item_selling_mode_log`, SELECT, INSERT and UPDATE of the resolution columns only on `incident`
(`bin/migrate.php` converges the grants); Deliveries › Receive + invoice opens (no receipt), Goods-in bench and Incidents are empty.
The file store (`/srv/cw-docs`) must be there for the invoice copies and photos (it is, since 2 Oct). Staging has 0 suppliers: the
owner's test needs real suppliers first (the ERPNext backup copy, or the 47 typed in; `docs/HANDOFF.md` step 4).

### What to check when something looks wrong

- A receipt that will not post: its page's "Before posting" list says every reason (the invoice number or copy, the supplier not
  active, the PO not receivable, the bench check, a blocked item, a duty stamp, the tolerance, a selling mode conflict).
- "is already on draft receipt #N": someone keyed that invoice already: open it (or cancel it if it was keyed by mistake).
- `relay_route`: the item's goods-in comes from a site's ERPNext relay until I-Day (`bin/channel_set.php` shows the channel's
  movement types); book it in ERPNext.
- `po_closed` when reversing: the order was closed after the delivery; correct the stock with an adjustment (Phase I-4). The same
  stops a review's rejection (a rejection reverses): the review box says so and shows no reject button (open owner question I176).
- `line_not_checked`: a line was added or its quantity changed after the bench check: the bench counts it again.
- `invoice_copy_elsewhere`: the attached invoice is the invoice of another live receipt of the supplier (typed with another number?).
- Nightly: `bin/invariants.php` G1-G8 (`docs/decisions.md` I142) report a receipt changed after posting, stock that does not match
  its lines, a missing incident, a PO line whose receipts do not add up, a review decided by the bench checker.

## The site stock writer, Phase I-3 (IM10; `docs/decisions.md` I148–I166, SC6–SC14)

From I-Day CW keeps each website's own stock figure, selling mode and low-stock threshold right, as ERPNext's pushes do today. CW
never writes a site's database: every listing view of its feed says what the site writes (the `site` block), and the site's connector
worker writes it, with one stock-log line per write and the site's own back-in-stock path when an item comes back.
**Vape and Go proto only for now** (connector 0.4.1, committed locally in App_proto: `src/central_warehouse`, `src/app_modules/central_warehouse`;
the hook lines in the admin files stay uncommitted); Electrofag and Vape Big are Phase I-6 (0.4.1 refuses to run the writer on a site
whose profile declares no writer hooks, SC20).

### The switches (all three are needed; each alone stops it)

| Switch | Where | Default | Turned on by |
|---|---|---|---|
| CW's per-site switch, `channel.site_writer` | CW: `bin/channel_set.php --code=<site> --writer=on --apply` (audited `channel.site_writer`) | off | ops, at I-Day |
| The site's own switch, `site_writer` | the site's `/etc/vpg/central_warehouse*.php`: `bin/cw_write_config.php --keep-key --site-writer=1` | off | ops, at I-Day |
| The effective mode | min(site `CW_MODE`, CW's `channel.mode`) must be **live** | off | the go-live steps |
| The site's scope, `writer_only` (0.4.1) | the site's config: `bin/cw_write_config.php --keep-key --writer-only=<prodt_id,...>` (empty = every writer listing) | every listing | the rehearsal names its test item; I-Day empties it |

```bash
# on the CW server (dry run first; --actor names the person)
php bin/channel_set.php --code=vapeandgo --writer=on --actor="<who>"
php bin/channel_set.php --code=vapeandgo --writer=on --actor="<who>" --apply
# on the site's box, as root (the key is kept; nothing is printed)
php src/central_warehouse/bin/cw_write_config.php --keep-key --site-writer=1
php src/central_warehouse/bin/cw_status.php            # "writer": {"active": true, "listings": ..., "due": ...}
```

Turning either switch off is the rollback: the site keeps the last figures CW wrote and its own stock screens are the writers again
(their refusals stop at once). Nothing is restored by itself; each listing's mode and back-order flag before CW first wrote them are
kept in the site's `cw_listing_state.pre_cw_*`. Paid sales whose local decrement the site skipped and no writer pass will write any
more (the switch went off between the sale and the next pass) are applied to the site's figure by the worker after a minute
(`writer_unwound` in the log, SC15); the site only skips its decrement while the writer's last pass is under 30 s old, so a stopped
worker never leaves sales off the figure (`decrement_skip_stale`).

### What the site gets (I150, SC7)

- **Counted items**: their policy on every site — strict = From-Warehouse, backorder = From-Warehouse + back-orders, stopped =
  Out-Of-Stock (plan §6.5).
- **Legacy items**: the mode CW set for that site (the item page's "Selling mode on the websites", or a receipt on Vape and Go:
  setting `site_writer.receipt_mode_sites`), else the site keeps its own mode; the quantity is CW's in every case.
- **A blocked item** (its item card), a quarantined listing, a stopped policy: Out-Of-Stock on every site whose writer is on. When
  that ends and the site owns the mode again, the site's writer puts back the mode and back-order flag it overwrote (0.4.1, SC16).
- **The figure** = CW's available units for the listing + this site's own unpaid checkouts CW holds − this site's paid orders CW does
  not know yet (CW was down). So the site's figure still drops when an order is paid, not when a checkout starts.

### The selling-mode switch (the item page)

Items › an item › **Selling mode on the websites**: per website, whether its writer is on, the listings, the mode CW writes (with CW's
meaning), the low-stock threshold, who set it. People with `modes.set` (purchasing desk, purchasing manager, stock controller, manager;
provisional) pick a mode, tick the websites (or "All websites"), optionally a threshold, and say why. A counted item has no form
(change its policy). Settings: `site_writer.receipt_mode_sites` (`vapeandgo`, provisional; channel codes separated by commas).

### The owner's rehearsal on Vape and Go proto (I-3 test line)

**EMAIL SAFETY:** the proto database is a copy of live (19 May): its back-in-stock sign-ups are real customers (15 pending on 7 Oct,
9 of them on linked listings). From 0.4.1 the writer calls the site's back-in-stock path on proto only with `--only-test-addresses`
(any real address waiting: nothing is called), and the site's own `email_stock_notify()` is a no-op on the proto tree (someone
else's uncommitted change, 5 Oct; the grouped sweep sends). **CW's `--writer=on` is channel-wide**: without `writer_only` it makes
all ~14,800 linked proto listings writer listings at the first pass.

1. CW staging: deploy 0017 + 0018 (below). Pick a test item: Out-Of-Stock on proto, nobody real waiting (or use the connector test,
   which does all of this on a fake product: `php src/central_warehouse/tests/run.php --filter='I-3 acceptance'`). Add your own
   test sign-up for it (an `@example.com` address) to see the e-mail path.
2. Proto: connector 0.4.1, SQL v4 applied (done 7 Oct 2026), then
   `php src/central_warehouse/bin/cw_write_config.php --keep-key --writer-only=<the test item's prodt_id> --site-writer=1`;
   site mode **shadow** → live only for the rehearsal window; CW: `vapeandgo` live and `bin/channel_set.php --code=vapeandgo
   --writer=on --apply`. **First a dry run**, the site still in shadow: `php src/central_warehouse/bin/cw_sync_worker.php --once
   --steps=feed` stores CW's views, then `php src/central_warehouse/bin/cw_sync_worker.php --once --dry-run --mode=live
   --steps=writer` lists what would be written (`diffs`) and the Out-Of-Stock -> In-Stock flips with their waiting sign-ups
   (`would_notify`): only the test item, only test sign-ups. The worker runs by hand (`bin/cw_sync_worker.php`), never from
   supervisor or cron without the owner's go.
3. Book a receipt of that item in CW (Deliveries › Receive + invoice) with mode In-Stock, counted and posted. Within seconds proto
   shows it In-Stock with the new figure; its stock log has "Central Warehouse CW-...: stock 0 -> N (+N): goods in GRN-..." (the
   Mobile app shows it). A receipt that accepts nothing (all short, refused) leaves it Out-Of-Stock (I169).
4. The back-in-stock email on proto is sent by the grouped sweep, never scheduled there: run it for one test address only, as a dry
   run into an outbox: `php tools/stock_notify_sweep.php --dry-run --only-email=<test address> --outbox=/tmp/<dir> --wait=0`.
5. Change the item's mode on Vape and Go only (the item page) and watch proto follow; try to change its stock in the proto admin
   (refused, with "Open in CW"); block it on its item card (Out-Of-Stock), confirm the card again and watch the old mode come back;
   turn `--writer=off` and check proto keeps the last values. Afterwards: `--writer-only=` stays set on proto.

### Before I-Day on a site (the gates of `lib/sites.php`, 0.4.1)

- The four direct writers of the site's stock that no hook sees (SC19): `api/stock_reconcillation.php`, `api/stock_reconcile.php`,
  `api/erp_stock_pull_sync.php` and `api/erp_mode_sync.php` (the last two also from the command line: web-blocking is not enough;
  their cron lines off, the scripts disabled). Until then the reconcile corrects them, and `cw_status` lists a listing it corrects
  3 times a day (`writer.fought_over_today`).
- The grouped back-in-stock sweep (`tools/stock_notify_sweep.php`) scheduled on the live site: the writer's call runs once per
  flip and is not retried.
- Returns and restocks of orders paid before the site's T0: the admin refuses the restock tick for them (H11, SC19): book the
  units in CW (a stock adjustment, Phase I-4), then process the return without restocking. Tell the staff.
- `writer_only` empty on the live config (every writer listing), `writer_notify_test_only` false.

### Deploying 0018 to staging (the owner's go first)

`0018_site_writer.sql`: `channel.site_writer` (every site off), `item_channel_mode` + `item_channel_mode_log`, the setting. Deploy it
with the code of the same commit, never the code first (`Availability` reads `channel.site_writer`):

```bash
scripts/remote.sh <slot> vendor/bin/phpunit && scripts/remote.sh ui vendor/bin/phpunit && scripts/remote.sh api vendor/bin/phpunit
scripts/remote.sh hammer php tests/concurrency/hammer.php --seed=<n>
scripts/remote.sh hammer bash deploy/staging/install_cron.sh --migrate
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/invariants.php --db=cw_staging'         # ok (W1-W2 included)
```

Check afterwards: every channel shows `site_writer: off (unchanged)` in a `bin/channel_set.php --code=<site> --writer=off` dry run;
cw_app holds SELECT, INSERT, UPDATE on `item_channel_mode` and SELECT, INSERT on `item_channel_mode_log`.

### What to check when something looks wrong

- The site does not follow: `bin/cw_status.php` on the site — `writer.active` (both switches and live), `writer.due` (listings waiting),
  `writer.last_pass`; the connector log (`writer_pass`, `writer_wrote`, `writer_write_failed`). In CW, `/v1/availability?variant_ids=`
  for the listing shows its `site` block and `why`.
- A staff member cannot change a stock figure in the admin: the item is the writer's (refusal names the CW code); change it in CW.
- A value keeps coming back after someone changed it on the site: the writer's reconcile (every 15 min) puts CW's back, with a line
  "corrected: ... changed outside CW". Find the writer outside CW (the Mobile app's mode change, a script).
- A return or restock refused with "the Central Warehouse does not know this order": the order was paid before the site's T0 (or
  CW never confirmed it): book the returned units in CW (a stock adjustment), then restock here without the restock tick (SC19).
- "The Central Warehouse connector could not check this item just now": the connector's own connection failed while the writer is
  on; the admin refuses rather than write what the next reconcile would undo. Try again; `cw_status` shows the connection.
- A sale that is not off the site's figure after the writer was switched off: the worker applies it a minute later (`writer_unwound`);
  `cw_status` `writer.decrement_skips_open` counts the ones still waiting.

## Changing settings and rules yourself (`docs/decisions.md` Y1–Y38; the owner's rule of 8 Oct 2026)

Who: the owner (Reviewer) and Fazil (Admin) — permission `settings.manage`; **switching an approval rule off or making it looser needs a
Reviewer** (the owner; `staff.approve`, Y45), so the admin a rule restrains cannot lift it. Everyone else can look. Every change asks **why** (3 to 500
characters), is kept as a numbered version (who, when, before, after, why) under "Changes" on its page, and is in the audit log. A form
left open while someone else changed the same thing is refused ("Someone else changed this while you had the page open"): reload, check,
save again. Nothing on these pages is ever deleted.

| What | Where (menu) | Notes |
|---|---|---|
| A setting's value, and "the owner has agreed it" | Settings → Settings and lists → click the setting | Allowed values are shown under the field. The approval rules and the company details have their own pages. |
| Which work waits for a second person | Settings → Approval rules | Each kind of record (reviewer check after it is final: every one / over a limit / none, the days, what Not OK does — a purchase order's Not OK only records it —, the OK first and its limit); suppliers (new supplier OK, changed details check, days to decide); matching (1 sale ≠ 1 product, joining counted products, spot check size: a change works for new spot checks only); your own change of the company details; giving someone Admin or Reviewer, and resetting an Admin's or a Reviewer's sign-in (both off by default). Switching an OK first off keeps its limit and never lets through work already waiting. Each rule has the tick "The owner has agreed this rule". Looser: a Reviewer only; stricter: an Admin too. |
| Reasons for stock changes and for cancelling or correcting orders | Settings → Settings and lists → Reasons for stock changes | Add (short code never changes), rename, say where it is offered (stock records, cancellations, and the order screens' three lists: cancelling a confirmed order, cancelling one not confirmed yet, correcting one), switch off / on. A switched-off reason stops new records only: one already waiting for an OK still gets it. CW's own reasons are locked. |
| Warehouses and places inside them | Settings → Warehouses | Add (e.g. the VPG 2 room as "Another account's stock": never sold from), rename, whose stock (only while it is empty, with a tick: another account's stock becomes ours only with a release invoice), websites may sell from it (tick to confirm; Fazil then points a website at it on the server), switch off (only when empty) / on. Places (e.g. OVERFLOW in the main warehouse) are optional. |
| Staff: add a person, a new sign-up sheet, a new sign-in code, a new password, sign out a device | Staff → Staff and access (Admin) | The sheet (QR code and a one-time set-up code; a new password: the set-up code only) is shown once and works once: the person finishes at /ui/enrol (a new sign-in code: at the sign-in, with their own password) and their OWN page then gives them a fresh code nobody else sees, so the admin never holds both keys of anybody. Within `staff.setup_hours` (48 h, at most 168); `staff.setup_max_fails` wrong tries (5) close it (Home card). A new password and a new sign-in code refuse each other while the other is open. Lost both phone and password: `bin/reset_staff.php --new-password --new-totp` on the server. The sheets print `staff.sign_in_address` (set it once). With the staff rules on, Admin/Reviewer jobs and resets of their sign-in wait for a reviewer: Staff access to OK (Home card). Reviewers' Home lists every person added, sign-in reset and rule made looser in the last 7 days. |
| Websites (look) | Settings → Websites | Mode, last contact, queue, how far behind, warehouse, stock writer. Changes stay on the server: the commands are in "Technical details". |
| Safety checks (look) | Settings → Safety checks | The nightly check's results; a problem is Home's first card. Tell Fazil the same day. |
| Audit log (look, CSV) | Settings → Audit log | Search by person, day, record, what was done; "Download as a spreadsheet". |
| Who can do what (look) | Settings → Settings and lists → Who can do what | From the permission map. |

On the server (Fazil): `php bin/settings.php --admin --set=<key> --value=<v> --reason='…'` and `php bin/document_rules.php --admin
--type=PO --approval=off --reason='…'` do the same as the screens and record a version too. `php bin/channel_set.php --code=<site>
--warehouse=<CODE> --actor="<name>"` (dry run; `--apply`) moves a website to another sellable warehouse. A change made by hand in SQL
is reported by the nightly check (K2: "changed outside its history"): record it again through the screen or the CLI so its history
matches. A setting, reason, warehouse or place added by hand with no history at all is reported too (K4: "the row has no history"). The
server's tools are system callers: they may also make a rule looser (root on the server is the break-glass); every such change is in the
audit log and on the reviewers' Home card.

