# Decisions (where docs/plan.md is silent)

Each entry: the choice, then why. Plan references are to `docs/plan.md`. Foundation slot, 26 Sep 2026.

## Stock quantities

**D1. Sign rules.** `stock_balance.allocated >= 0` and `held >= 0` are CHECK constraints (and
`stock_ledger.balance_after >= 0` for those two buckets). `on_hand` has **no** lower bound, for every
sell policy. `available = on_hand - allocated - held` may be negative (backorder items; flagged
oversells).
*Why:* allocated/held are sums of `reservation_unit` states and can never legitimately go below zero
(§14 "allocated returns to 0, never negative"), so a negative value is always a bug and the database
should stop it. `on_hand` records physical facts that have already happened (a ship, an ERP sale,
a write-off). Until an item is counted, its opening on_hand is an estimate (§8.1). Refusing such a
movement would leave CW out of step with the building, and the site would retry until it
dead-lettered. Part 2 valuation also expects negative stock ("true-up at the next receipt", §15.3).
Restricting negatives to `backorder` items cannot be written as a CHECK, because the policy lives on
`sku`. **Rule for Stock.php:** any movement that leaves `on_hand < 0` also opens a `count_review`
row (`source = negative_on_hand`, deduplicated per warehouse+sku while open).

**D2. As-of counting per location.** `stock_balance.counted_at` (per warehouse+sku) stores the as-of
time of the last count at that location. `stock_ledger.effective_at` carries `dispatched_at` for
ships and `counted_at` for counts. `sku.counted_at` stays as the plan's identity/protection marker.
*Why:* the §8.2 rule compares each ship's dispatch time with the count of *that* location. A recount
in `VERIFY` must not move MAIN's cut-off. With `effective_at`, "ships already applied that were
dispatched after counted_at" is a single ledger query.

**D3. All quantities are INT in central units.** `units_per_item`, `units_per_scan` and
`pack_units` are ≥ 1 (CHECK).

## Catalogue

**D4. `sku.code` is nullable and set in the creating transaction.** The code is `sprintf('CW-%06d', id)`,
written by an `UPDATE` right after the `INSERT`. The column is UNIQUE, with a format CHECK
`^CW-[0-9]{6,}$` (7+ digits are allowed once the id passes 999,999).
*Why:* MySQL does not let a generated column or a CHECK refer to an AUTO_INCREMENT column. Codes are
never reused because ids are never reused: InnoDB 8.x persists the counter, and SKUs are never
deleted (merges keep the row).

**D5. `channel_listing` link consistency.** A CHECK requires `sku_id IS NOT NULL` **exactly when**
`status IN ('mapped','quarantined')`. Suggestions (`suggested`) carry no `sku_id`: the proposed item
lives in the matching tables.
*Why:* elsewhere, a NULL sku means "not linked" (§2.3 line kinds), so a half-set link must be
impossible.

**D6. `listing_profile` is keyed by `listing_id`.** `PUT /v1/listings` asks the DecisionService to
create the `unmapped` listing row first (§3), then writes the profile. It holds two hashes:
`profile_hash` (the 15-min delta) and `identity_hash` (matching-relevant fields, for the
"edited listing" flag of §7.3).

**D7. `sku_erp_item.units_per_item`** (default 1) was added. An ERP override may need the same
conversion that a listing link has.

**D8. Site identifiers are `VARCHAR(64)`** (`order_ref`, `unit_id`, `external_variant_id`).
*Why:* a future non-PHP storefront may use non-numeric ids (§10). The T0 watermarks
(`t0_last_order_id`, `t0_last_stock_log_id`) are `BIGINT` because they are compared numerically.

## Channels and warehouses

**D9. One sellable warehouse per channel, enforced by the database.** `channel_warehouse.is_sellable`
copies `warehouse.is_sellable`. It is pinned by a composite FK `(warehouse_id, is_sellable)` →
`warehouse(id, is_sellable)`. A stored generated column
`sellable_channel_id = IF(is_sellable = 1, channel_id, NULL)` has a UNIQUE key. Effects:
- A channel can have at most one sellable assignment, plus any number of non-sellable ones.
- A non-sellable warehouse cannot be claimed as sellable.
- A warehouse's sellability cannot change while any channel is assigned to it; remove the
  assignments first.

"At least one" cannot be a constraint, so the app must refuse `shadow`/`live` mode for a channel
without a sellable assignment.

**D10. Channel defaults fail closed.** The defaults are `mode = 'off'`, `api_key_hash = NULL` (every
call refused) and `allowed_ips = []` (every call refused). The key hash is sha256 hex (CHECK), and
`allowed_ips` must be a JSON array (CHECK). `reserve_ttl_sec` defaults to 2400 (40 min, §4) and is
limited to 60..86400. The opening-orders marker and the T0 watermarks live in `channel_opening`,
not on the channel row (R1, R12).

**D11. Warehouse ids are fixed on seed:** 1 `MAIN` (sellable), 2 `VERIFY`, 3 `UNSTAMPED`. Code must
look warehouses up by `code`.

## Reservations

**D12. `reservation`.** `is_tombstone = 1` marks the row a release creates for an unknown ref (§3),
so a late reserve can tell a tombstone from an ordinary released attempt. `origin` defaults to
`reserved`. A CHECK makes `held` imply `expires_at IS NOT NULL`. `(status, expires_at)` is indexed
for the expiry cron.

**D13. `reservation_unit`.** `listing_id` is NOT NULL, so a reserve for an unknown variant first
has the DecisionService create its `unmapped` listing row. `warehouse_id` is NOT NULL (the channel's
sellable warehouse at sale time, even for unlinked units). A CHECK makes `shipped` imply
`dispatched_at IS NOT NULL`. The only FK is to `reservation`. See D15.

## Keys, locks and integrity

**D14. Idempotency.** The PK is `(channel_id, idem_key)`, plus `request_hash`: the same key with a
different body gets 422. The stored response is written **in the same transaction as the effect**,
so a concurrent duplicate blocks on the PK and then reads the stored response: one effect (§14).
`idem_key` columns use `utf8mb4_0900_bin`, the only exception to the one-collation rule, because
keys are opaque tokens and `_ai_ci` would treat `abc` and `ABC` as the same key. Keys are at most
191 characters, so the API should reject longer ones with 400. Prune by `created_at` after
≥ 30 days: the site outbox keeps 14 days, plus replay after a restore.

**D15. FK policy: "cheap" means no extra lock outside the §3 lock order.**
- FKs are declared on the configuration and catalogue tables, the queues, `idempotency` and
  `channel_health`.
- `stock_ledger` and `oversell_event` reference `stock_balance(warehouse_id, sku_id)`. The writer
  already holds that row's X lock, so the FK adds no lock.
- There are **no** FKs on `stock_change`, `audit_log`, or `reservation_unit` → listing/sku/warehouse.
  An FK takes a shared lock on the parent row, and on these hot paths that could take a
  listing/sku lock before the `stock_balance` locks, which would violate
  reservation → stock_balance → sku.

`audit_log` must also never fail a write. The integrity of these tables is covered by the nightly
invariant check (§14). Every FK is `RESTRICT`: rows are never deleted, and state changes go
through status columns.

**D16. Change-feed `seq`.** It is the AUTO_INCREMENT of `stock_change`, and the server runs
`innodb_autoinc_lock_mode = 2`. A value is allocated at insert, not at commit, so a lower seq can
become visible after a higher one, and rolled-back transactions leave gaps. `/v1/changes` must
re-read an overlap window (§4 already requires this). A NULL `sku_id`/`listing_id`/`channel_id`
widens the scope; an all-NULL row means "everything".

**D17. Queue tables** (`oversell_event`, `goods_in_suspense`, `count_review`, `policy_review`) share
one lifecycle: `status` open/resolved/dismissed, plus a free-text `resolution` and
`resolved_by`/`resolved_at`. A CHECK makes "closed" imply `resolved_at`. Each table has
`dedupe_key` UNIQUE (NULL allowed; NOT NULL in `goods_in_suspense`, where it is document name +
line index, §9), so a replayed event never duplicates a queue row.

**D18. `channel_health` keeps one row per heartbeat** instead of a latest-only row, to give history
for the shadow acceptance metrics (§14). The latest row is found via `(channel_id, id)`. Prune after
30 days.

**D19. `staff_user`.** `totp_secret` is stored encrypted (VARBINARY), and a NULL secret means login
is refused. `totp_last_step` guards against replay. `roles` is a JSON array (CHECK); role names are
settled with the UI work. *Superseded by M2 (0004): one `role` ENUM, `totp_secret_enc`.*

## Database access

**D20. Session settings are pinned by `CW\Db`**, whatever the server defaults are: `time_zone '+00:00'`,
`READ-COMMITTED`, `collation_connection utf8mb4_0900_ai_ci`, and
`sql_mode = ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`.
Connections use native prepares (ints come back as ints), `ERRMODE_EXCEPTION` and a 5 s connect
timeout.
*Why:* DO managed MySQL defaults to `ANSI` (ANSI_QUOTES, PIPES_AS_CONCAT), which would change what
`"..."` and `||` mean. Timestamps are `DATETIME(6)` with `CURRENT_TIMESTAMP(6)` defaults, and they
are UTC because every CW session is UTC (the server's system zone is UTC too).

**D21. TLS is mandatory.** `Db::connect` fails unless `Ssl_cipher` is non-empty, and `sslmode=DISABLED`
is rejected. `REQUIRED` (the staging setting) encrypts but does not verify the server certificate,
because DigitalOcean's CA certificate is not on the box. `VERIFY_CA`/`VERIFY_IDENTITY` together with
`ssl_ca=<file>` are supported (open item: install the DO CA and switch).

**D22. `Db::transaction()`** retries only deadlocks (1213): up to **3 retries** (4 attempts), with a
5–25 ms × attempt random back-off. Lock-wait timeouts (1205, 120 s on this server) are not retried.
Any other error rolls back and is rethrown. A call made inside an open transaction joins it, and a
deadlock propagates to the outermost call, which retries the whole unit. The callable must have no
side effects outside the database.

**D23. Grants.** `cw_app`@`%` has `REQUIRE SSL`; the network is limited by DO trusted sources. It has
table-level grants only and no `cw_staging.*` grant:
- SELECT/INSERT/UPDATE/DELETE on every table;
- SELECT/INSERT only on `stock_ledger` and `audit_log` (append-only);
- SELECT only on `schema_migrations` (migrations run as doadmin).

`CW\Schema\Grants::apply()` diffs against `mysql.tables_priv` and grants or revokes only the
difference, so the app never loses a right mid-deploy. It also removes a database-level grant and
stale grants on dropped tables. `bin/migrate.php` calls it automatically when the target is the
schema named in `app.env`. A table added without a re-run would otherwise have no rights at all,
which fails safe.

**D24. `/etc/cw/app.env`** is 0600 root, as instructed, and holds `db_user`, `db_password` and
`db_name`. The password is 32 characters from `[A-Za-z0-9-_.]` with every class present. It is
generated once and reused on re-runs (`setup_staging.php` re-aligns the login only if it cannot log
in). Other keys in the file are preserved.

**D25. Migrations** are plain `NNNN_lower_snake.sql` files applied in name order. Each file's sha256
(CRLF-normalised) is recorded in `schema_migrations`; a changed or missing applied file is an error.
A file is recorded only after all of its statements succeed. MySQL DDL is not transactional, so a
failure leaves a partly applied file: fix it by hand on staging, or re-create the schema. There is
no `DELIMITER`, so there are no procedures or triggers. `GET_LOCK('cw_migrate:<schema>')`
serialises concurrent runs.

**D26. Tests run as doadmin** on `cw_test_<slot>`, which is re-created on each run: creating the
schema needs admin rights. The cw_app grant model is tested with a throwaway login `cw_t_<slot>`,
created and dropped by `GrantsTest`. Only schemas matching `cw_test_*` are ever dropped.

## Stock core (slot `core`, 26 Sep 2026)

Code: `src/Stock.php`, `Reservations.php`, `Movements.php`, `Availability.php`, `Invariants.php`,
`Idempotency.php`, `Caller.php`, `OpResult.php`, `CwException.php`; schema additions in
`migrations/0002_stock_core.sql`.

**D27. Two kinds of outcome.** A domain answer (201 held, 200 committed, 409 refused/use_cancel,
422 lines_changed/unit_conflict, ...) is an `OpResult`. It is stored under the Idempotency-Key in
the same transaction as the effect, so a replay gets the same answer. A request CW cannot act on
*yet*, or at all (bad input 400, unknown order 404, `not_committed` 409, no sellable warehouse 409),
is a `CwException`. Then the whole transaction rolls back and nothing is stored, so the site may
retry the same key later.
*Why:* a ship that arrives before its commit must succeed later under the same outbox key. If the
refusal were stored, it would be replayed for ever.

**D28. Idempotency mechanics.** The key is claimed by inserting its row first, inside the effect's
transaction. A concurrent duplicate blocks on the key and then reads the stored answer
(`replayed = true`). The request hash is sha256 of action + path + canonical JSON of the
*normalised* request (object keys sorted, lines and unit ids sorted), so a retry that sends the
lines in another order counts as the same request. The same key with another request gets 422
`idempotency_key_reused`, which is not stored. Two first calls that race to create the same
reservation or unit row: the loser gets a duplicate key, throws `RetryOperation` and re-runs the
whole operation (at most 3 times), and on the re-run it follows the state machine. Every
operation writes one `audit_log` row.

**D29. Idempotency scope = (channel OR source).** A site calls as its channel. Staff screens share
the scope `staff`, and system jobs use `system:<job>`. `0002` makes `idempotency.channel_id`
nullable and moves the PK to `(IFNULL(channel_id,0), source, idem_key)`. The same key string from
a site, from staff and from a job means three different requests.

**D30. Order lines.** `{variant_id, qty, unit_ids[]}` with `qty = count(unit_ids)`: one unit id
(`ordi_id`) per listing unit sold, because `orders_items` has no qty column (§2.2). Within one
request, unit ids must be unique. Within a channel, a unit belongs to one order only: a unit id
already used by another order gets 422 `unit_conflict`. Limits are 500 lines and 20,000 units per
call. The stored `lines_hash` is taken over the canonical (sorted) lines.

**D31. CW refuses only for a `live` channel.** For `strict` items it refuses when the order's
summed need (qty × u across all lines of the item) is more than `available`. It always refuses a
`stopped` item or a `quarantined` listing. `shadow` (and `off`) channels are never refused, but
their holds are still booked, so CW's books follow what the site really sold. A refusal is
all-or-nothing: nothing is held, and a refused *new* order leaves no reservation row, only the
stored answer. The 409 body lists every line with its result (`ok`/`short`/`stopped`/
`quarantined`/`unlinked`) and `available` = floor(available / u). Whether an `off` channel may call
at all is for the API layer.

**D32. Line kinds.** A line is *linked* when its listing is `mapped` or `quarantined` (sku set,
D5). Anything else (unknown, `unmapped`, `suggested`, `ignored`) is *unlinked*: its
`reservation_unit` row has `sku_id` NULL and no bucket moves (§2.3). Until the DecisionService
exists, a sale of an unknown variant creates its `unmapped` listing row itself (`INSERT IGNORE`,
D13). Linked `legacy` lines move buckets and are never refused.

**D33. Commit.** The body lines win:
- A held unit that is in the body under the same listing is converted held → allocated and keeps
  its sale-time snapshot.
- A body unit that was not held, or was held under another listing, is allocated with today's
  link. Its old hold is released first.
- A held unit missing from the body is released.
- Any difference is audited as `reservation.commit_lines_differ`.
- A commit on a committed order answers 200 `already_committed` and changes nothing.
An `oversell_event` is raised only for units allocated **without a hold**, only on `strict`/
`stopped` items, and only when `available` is below 0 afterwards. The shortfall is
min(unheld units, −available). The kind is `outage_order` (origin `unreserved`), `opening_short`
(origin `opening`), `commit_after_expiry` (the reservation was released or expired) or
`commit_short`. It is deduplicated per (channel, order, warehouse, item). R7 adds: on a `live`
channel an unheld unit of a `stopped` item or of a quarantined listing is flagged whatever
`available` is (`stopped_sale` / `quarantined_sale`); R6 flags the non-sale paths.

**D34. Release and tombstones.** An `attempt` lower than the current one is a stale release: 200
`stale_attempt`, ignored. A NULL attempt means attempt 1 (R9; it meant "the current one" before). A release of an already
released/expired order answers `already_released`, and of a committed order 409 `use_cancel`. An
unknown ref gets a tombstone: a `released` row with `is_tombstone = 1`. A reserve on a tombstone
answers 409 `released` and holds nothing. A **commit** on a tombstone still commits, because a
payment is never refused.

**D35. Dispatch times and the count rule, both ways.**
- `ship` `dispatched_at` becomes the ledger rows' `effective_at`.
- `unship` takes `at`, the moment of the dispatch reset (default in the core: receive time; the API
  requires it, R10). An explicit `at` must be later than each unit's stored dispatch time (R10).
- Mirroring §8.2: a ship dispatched at or before the location's `counted_at` moves only
  `allocated`, and a reset at or before `counted_at` moves only `allocated` too, because the count
  already includes the unit. Both are marked `pre_count`.
- A ship or reset within ±10 min of `counted_at` also opens a `count_review` (`ship_near_count` /
  `unship_near_count`).
"Count review instead" is read this way: the timestamp rule is applied provisionally and a person
confirms it. `allocated` must fall either way, because the unit did leave.

**D36. Per-unit operations.** `ship`/`unship`/`return` need a **committed** order. Otherwise they
throw 409 `not_committed`, which is not stored (D27): the site's outbox sends the commit first,
and a retry succeeds. An unknown order is 404, also not stored. Units in the wrong state get a
stored per-unit result (`already_shipped`, `not_shipped`, `already_returned`, `cancelled`,
`unknown_unit`, ...) and change nothing. `cancel` also accepts *held* units (held −u; never VERIFY,
because the goods never left). `return` is once per unit whatever the key, so a refund-with-restock
and a return receipt never double-count. `uncancel` (D46) follows the same rules.

**D37. Cancel with `restockable = false`.** The unit leaves `allocated` at its sale warehouse, then
a `transfer_out` (on_hand −u there) and a `transfer_in` (on_hand +u at `VERIFY`), noted
`cancel_not_restockable`, and a `count_review` at VERIFY (`verify_recount`, dedupe
`verify:<channel>:<unit>`). Nothing is written off. A person later books `write_off` at VERIFY or
moves the unit back with `transfer_out`/`transfer_in`. An uncancel (D46) moves a unit nobody has
touched back the same way and dismisses its recount.

**D38. Feed rows and versions.** `Stock::flush()` writes one `stock_change` row (`sku_id`,
reason `stock`) per item whose availability at a **sellable** warehouse changed by a non-zero net
amount. So commit (held → allocated) and a normal ship (allocated and on_hand together) write
none, and a VERIFY movement writes none. Link/quarantine changes write a `listing_id` row, policy
changes a `sku_id` row, and warehouse assignment a `channel_id` row. A listing's version is
MAX(seq) over its listing rows, its current item's sku rows, its channel's rows and global rows.
A channel-wide or global row makes `changes()` return `resync = true`, and the site then pages
`snapshot()`. Versions are read before values, so a value is never older than its version.
`changes()` re-reads rows of the last 10 s (the overlap); only a *new* channel-wide or global row
(seq > after) sets `resync`, an overlap re-read of one does not (R3).

**D39. Feed clock: `seq` is allocated in commit order.** Every transaction that inserts
`stock_change` rows first takes the X lock of the single `feed_clock` row
(`INSERT ... ON DUPLICATE KEY UPDATE`, so it works even on an emptied table) and holds it until
commit. This is the **last** lock in the order. The full order (R1, R4): idempotency claim (its FK
check takes an S lock on the calling site's `channel` row) → `channel_opening` (opening orders only)
→ reservation rows → `channel_listing` rows FOR SHARE (sales only) → stock_balance → sku → feed
clock. Nothing X-locks a `channel` row except `assignSellableWarehouse` and key rotation, and they
take it first.
*Why:* AUTO_INCREMENT allocates at insert. Without the clock, a change to an unrelated item, to
the same item in another warehouse, or to a link or warehouse assignment could take seq 11 and
commit before the change holding seq 10. A reader then serves version 11 with a value that lacks
seq 10's effect. When 10 commits, the listing's version is still 11, so the site's version guard
never applies the newer value, and the snapshot would not fix it either. With the clock, a
listing's version only ever grows with its value. Cost: feed-writing transactions serialise from
their flush to commit: the feed rows (multi-row INSERTs of 1,000, R2), the idempotency update, the
audit insert and the commit. Measured on staging: 2,000 feed rows in ~60 ms (it was ~1.6 s with one
INSERT per item). The
overlap window is kept as a second line of defence. Anything that writes feed rows must do so as
its last step.

**D40. Opening orders (§8.1).** They are accepted until a call with `final = true` sets
`channel_opening.opening_orders_at` (R1). Large sites send batches with `final = false` (≤ 20,000 units per
call, ≤ 1,000 per order (R15); ≈ 2,000 recommended, because one call is one transaction holding every
touched balance). The connector sends the final batch only after every earlier batch answered
200; the final batch needs the T0 watermarks (R12).
Orders already committed through the normal path are skipped. Each order becomes a committed
reservation with `origin = opening`. The opening **on_hand estimate** (site stock × u + open paid
units) is a separate `adjustment` movement, noted as the estimate.

**D40a. The opening estimate as booked, and its rebase at T0 (2 Oct 2026; amends D40).**
`bin/import_opening_estimate.php` (CW\Ops\OpeningEstimate) books the *site-stock term* of D40:
per item, the sum over its `mapped` listings of max(site figure as of `--as-of`, 0) x u. That is one
`adjustment` per item, actor `system:opening_estimate`, under a `doc_ref` that names the opening, with
Idempotency-Key `<doc_ref>:<sku_id>`.
- It never touches `counted_at`. A count replaces the figure.
- It leaves an item alone when the item was counted, carries an opening under another doc_ref (any
  spelling, compared byte for byte), or has on_hand movements recorded before the as-of moment.
- A `quarantined` listing with stock stops the run.
- Re-runs and resumes book only what is missing. A different file under the same doc_ref is refused
  (`opening_conflict`).

*Not in it:* the open paid-not-shipped units. `/v1/opening_orders` adds them to `allocated` only, never
to `on_hand` (Reservations::applyCommit, "fresh unit" branch). So after the opening orders, available
would sit below the site's figure by those units, and an item whose site figure is <= 0 would go
negative when its open units ship.

**Rule at a site's T0:** rebase every item whose on_hand history is opening estimates only.
- Target: max(site stock read in the SAME read-only snapshot as the T0 watermarks, 0) x u, plus the
  units of that channel's `origin = 'opening'` reservation_units, in every state, x u.
- Delta: target minus the sum of the item's earlier opening rows. Book it as a signed `adjustment`
  under a new doc_ref after the final opening_orders batch, noted as the rebase.
- Counted items are not rebased.
- ~~The tool has no rebase mode yet.~~ Built and tested: `--rebase` (D40b), with the proof asked for here
  (estimate → opening_orders → some ships → rebase ⇒ available equals the site figure at T0, and on_hand
  equals max(S_T0, 0) once all opening units have shipped).
- Nobody books CW counts or adjustments on a site's items between its estimate and its rebase.

*Booked so far:* only `cw_staging`, on 2 Oct 2026. The owner chose the "duty-day stock": Vape and Go's
figure at 00:00 BST on 1 Oct 2026, from the archived VPD workbook (xlsx sha256 aecf0178…, input CSV
sha256 3561293a…). 8,199 items, 296,599 units, doc_ref `opening:vapeandgo:2026-10-01T00:00+01:00`.
- Excluded: 20,031 rows at or below zero, 897 rows of unlinked (mostly deleted or draft) listings
  holding 26,191 units, the open paid units, and every movement between 1 Oct and T0.
- It is provisional: the T0 rebase above replaces it in effect.
*Why the actor is a system job:* the booking is mechanical, from an archived file. The approver is
named in the note (`--approved-by`) and here.

**D40b. The rebase at T0, as built (F4, 2 Oct 2026; amends D40a).** `bin/import_opening_estimate.php --rebase`
(`CW\Ops\OpeningRebase` plans, `OpeningEstimate::apply` books). Slot `cfu3`.
- **When.** After the channel's final opening_orders batch was accepted (`channel_opening.opening_orders_at`).
  Before it: 409 `opening_not_final`, exit 1. T0 is the one CW recorded with the batches. `--as-of` and a file
  named `t0_site_stock_<YYYYMMDDTHHMMSSZ>.csv` must match it. A real run needs both `--sha256` (as for the
  estimate) and `--as-of`: the hash proves the file's bytes, not which snapshot they are, so a renamed file
  from another capture must not pass on its name (review fix). The dry run prints the T0 check either way.
- **Input.** The connector's T0 file as `cw_opening_stock_csv()` writes it: `variant_id,prodt_stock,...`, the
  variant and the figure first, further columns ignored. The estimate mode accepts extra columns now too.
- **Per item**, at the channel's sellable warehouse (`--warehouse` overrides):
  - target = Σ over the item's `mapped` listings on this channel of max(S_T0, 0) × u, plus Σ u of the units the
    opening committed: this channel's units of `origin = 'opening'` reservations in the states allocated,
    shipped, cancelled and returned (every state such a unit can reach) that have a `commit` row (or an `adopt`
    row) on their allocated bucket.
    - A `released` unit was held before T0 and left out of the opening body: never paid, not counted.
    - A unit cancelled while held and left out of the opening body stays `cancelled` under the (now opening)
      reservation, but it was never paid either and has no commit row: not counted (`never_committed` in the
      report; review fix). An uncancel of it after T0 is a sale after T0, which lowers availability as it
      should.
    - An unlinked unit counts for no item.
  - delta = target − Σ the item's on_hand rows under `--estimate-doc-ref` at that warehouse (byte for byte;
    `none` when no estimate was booked).
  - Each non-zero delta is one signed `adjustment` under `--doc-ref` (default
    `opening-rebase:<channel>:<T0 as YYYYMMDDTHHMMSSZ>`): actor `system:opening_estimate`, doc_type
    `opening_rebase`, Idempotency-Key `<doc_ref>:<sku_id>`. The note names T0, the file hash, `--source` and
    `--approved-by`.
- **Skipped and listed**, each with its target, the earlier rows and the delta it would have booked, so a person
  can book it or count the item:
  - `counted`: sku or balance `counted_at` is set, or the item has a `count` row;
  - `other_opening`: an opening row under any other doc_ref, or at another warehouse;
  - `moved`: any on_hand row besides the estimate's and those of this channel's opening units, with its
    movement types (the opening units' ship, unship and return, and the VERIFY moves of their cancels and
    uncancels, are the opening's own history);
  - `quarantined_listing`: a quarantined listing of the item has T0 stock > 0, or no figure;
  - `no_t0_figure`: a mapped listing of the item is not in the file;
  - `no_mapped_listing`: the item has rows under the estimate's doc_ref, or opening units, but no `mapped` or
    `quarantined` listing on this channel now (it was unmapped or relinked after the estimate; the parallel
    mapping work changes links). Its site figure at T0 is unknown, and without this skip the rebase would
    write the item down to its units term without saying so (review fix). A person relinks it, counts it,
    or books the listed delta;
  - `units_elsewhere`: an opening unit of the item sits at another warehouse.
- **"On_hand history is opening estimates only" is read strictly.** A ship of an order paid after T0 also makes
  an item `moved`, although the rebase arithmetic would still hold for it. So the rebase runs straight after
  the final batch is acknowledged, at a quiet time (after the day's dispatch run). A `moved` item is settled by a
  person: book the listed delta as a staff adjustment, or count the item. Admitting post-T0 rows of the
  channel's own units is the owner's choice; it is reversible.
- **Re-runs and resumes** book only what is missing. An item already rebased under the doc_ref is judged on its
  figure only: a later ship or count does not turn it into a skip. A different figure gives 409
  `rebase_conflict` for the whole run, and nothing is booked. That means another file, or links changed since.
  A deliberate second rebase uses another doc_ref; the first rebase's rows then count as `other_opening`.
- The rebase rows carry the estimate's actor, so a later estimate run sees them as an earlier opening
  (`earlier_opening`) and books nothing on those items.
- **No lock across the run.** The plan is read without locks, then each item is booked in its own transaction,
  like the estimate. D40a's rule stands: nobody books stock on a site's items between its estimate and its
  rebase.
- **Sister sites.** An item an earlier site's opening touched is `other_opening` or `moved`, so it is skipped by
  design; only items no other opening touched are rebased. That fits the owner's decision of 2 Oct
  (`docs/inventory-modules-plan.md`, "now" decision 9): sister-only items open from the sister's own figure
  (0 or more). At that site's T0: `--estimate-doc-ref=none` with the sister's T0 file.
- **Order at a site's T0:** `docs/ops.md` "A site's T0". The connector's `bin/cw_t0.php` prints the exact
  commands once the final batch is acknowledged, and `--rebase` runs steps 7–10 itself (SC4).
- **Tests:** `tests/Integration/Stock/OpeningRebaseTest.php`.
  - The proof: estimate → opening_orders → some ships → rebase ⇒ available = S_T0 × u. It covers u = 10, an
    item with no estimate because its figure was negative, and an item with two listings. Once every opening
    unit has shipped, on_hand = max(S_T0, 0) × u and allocated = 0.
  - Cancels (to VERIFY and back), a return, and a hold released by the opening body.
  - Every skip reason, a resume, a re-run and a conflict.
  - The connector's 4-column T0 file through the CLI, with its guards.

*Why:* after the rebase, available equals the site's T0 figure, plus what came back on sale since (restockable
cancels, returns). Once the opening units have shipped, on_hand equals max(S_T0, 0) × u. One row per item corrects
both the estimate's drift between its as-of moment and T0 and the open paid units the estimate left out.

**D41. Movements.**
- The sign comes from the type, whatever sign the sender used: `goods_in`/`transfer_in` +|q|;
  `supplier_return`/`erp_sale`/`write_off`/`transfer_out` −|q|; `adjustment` takes a signed,
  non-zero q.
- A line names its item by exactly one of `sku_id`, `sku_code`, `erp_item_code` (the override,
  with an optional `variant_id` fallback) or `variant_id` (channel callers only; × the link's u).
  Lines are never merged, so the same variant twice books the sum.
- Unresolved lines of `goods_in`/`supplier_return`/`erp_sale` go to `goods_in_suspense`
  (dedupe `<type>:<doc_ref>#<line_index>`), and the rest of the call is booked. For any other type,
  one unresolved line refuses the whole call (422).
- The warehouse comes from the request's or the line's `warehouse` code. The default is the
  channel's sellable warehouse, or `MAIN` for staff and jobs.
- A site may send only the ERP-relay types its channel is granted (R17).
- `transfer_out` and `transfer_in` are two movements (in transit between the calls).
- `doc_ref` is required for the three relay types.

**D42. Count.**
- `counted_at` is required. Lines of one item at one location are summed.
- on_hand = counted + the net on_hand of `ship`/`unship` ledger rows whose `effective_at` is after
  `counted_at` (§8.2).
- A count older than the location's last count answers `stale_count` and books nothing. A count
  with no difference is still journalled.
- A ship dispatched within ±10 min of `counted_at` that was already applied opens a
  `count_review` (`count_near_ship`).
- A count does not change `sku.counted_at` or the policy: the count gate (§7.4) owns them.
- Other on_hand movements booked after `counted_at` (goods-in, returns, ERP sales, write-offs,
  adjustments, transfers incl. a cancel moved to VERIFY) are *not* adjusted for: the count
  overwrites them, and a `count_review` (`count_after_movements`) lists them (R13).
- `counted_at` must lie within [CW's clock − 24 h, CW's clock + 5 min]; staff may send
  `backdated: true` for up to 90 days (R5).

**D43. Expiry.** `expireDue()` selects due ids without locks, then handles each in its own
transaction: lock the reservation, re-check it is still held and due, release its held units
(movement `expire`) and set the status to `expired`. It is audited as `system:expiry` and has no
idempotency key, because the state makes it idempotent. Extending a hold sets
`expires_at = now + channel.reserve_ttl_sec`.

**D44. Nightly invariant check (`CW\Invariants::check`, asserted after every stock test).**
1. `held`/`allocated` of every balance equal the summed u of its `reservation_unit` rows in that
   state.
2. All three buckets equal the sum of their ledger rows.
3. Each bucket's newest ledger row has `balance_after` equal to the bucket.
4. Per unit, the net held/allocated ledger deltas match its state under its snapshot
   warehouse+sku.
5. Held units sit only under held reservations, and allocated/shipped/returned units only under
   committed ones of the same channel.
6. Every linked unit has a balance row, and no held reservation lacks `expires_at`.
Later additions: 7–9, the value sequence (I3); 10–11, the units' moves through VERIFY (D46).
`src/Invariants.php` lists them all.
It is read-only and returns readable violations. It loads units into memory, which is fine for
tests; production needs chunking (open item).

**D45. Admin operations in `Stock`.** `setPolicy` locks the item's balances, then its sku row
(lock order), and writes a sku feed row. `assignSellableWarehouse` replaces the channel's one
sellable assignment (D9) and writes a channel-wide feed row. Both are idempotent (staff scope).
The count-gate conditions (§7.4) are the caller's job. The ledger's `effective_at` is: ship/unship
→ dispatch/reset time; count → `counted_at`; return → CW's clock; other movements → booking time.

## HTTP API (slot `api`, 26 Sep 2026)

Numbered A1–A12 so they cannot collide with D-numbers other workstreams add in parallel.

Code: `public/index.php`, `src/Api/` (Kernel, Router, Auth, IpAllowlist, ApiKey, Request, Response,
Context, Input, one controller per resource), `src/ListingProfiles.php`, `src/Heartbeat.php`,
`src/Purchasing.php`, `src/ChannelAdmin.php`, `bin/create_channel.php`, `bin/rotate_key.php`,
`deploy/staging/`.

**A1. Envelope.** Every answer is `{"ok": bool, "data": object|null, "error": null|{"code", "message"}}`,
with all three keys present. The HTTP status is the domain status (OpResult / CwException). For a
refusal, `error.code` is the body's `error` (or the exception code) and `data` holds the rest of
the body (e.g. the per-line `available` of a 409). `data` is canonical (object keys sorted, lists
kept in order). MySQL's JSON type re-orders keys, so without this a replay from the idempotency
table would not be byte-identical to the first answer.
Unexpected failures answer 500 `internal` with the message "internal error (request <id>)". The
same id is in `X-Request-Id` and in the error log line, which holds the details. A database that
is unreachable or refuses the login gives 503 `unavailable`. A deadlock after the retries, or a
lock-wait timeout, gives 503 `busy` with `Retry-After`. None of these is stored, so the site
retries the same key. Headers: `Content-Type: application/json; charset=utf-8`,
`Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, no `X-Powered-By`, no CORS headers.

**A2. Authentication (§11).** The steps, in order:
1. A well-formed `Authorization: Bearer <token>` (scheme case-insensitive, token 16–256 printable
   characters), checked before any database work.
2. Connect as `cw_app`.
3. sha256 of the token, compared with `hash_equals` against every configured channel's
   `api_key_hash`, with no early exit. There are a handful of rows, and no lookup is keyed on the
   secret.
4. The channel's `allowed_ips` must contain `REMOTE_ADDR`.

Allowlist entries are IPv4/IPv6 addresses or CIDR blocks. An IPv4-mapped IPv6 client address is
compared as IPv4. An empty list, a malformed entry and a `/0` block match nothing (fail closed).
`CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`, `Forwarded` and the like are never read.
A missing or unknown key gets 401 `unauthorized` (+ `WWW-Authenticate: Bearer`). A known key
from an address that is not allowed gets 403 `forbidden`. Authentication runs before routing,
so a caller without a key cannot discover which paths exist. Keys are `cwk_` + 64 hex (256
random bits). Only `bin/create_channel.php` and `bin/rotate_key.php` ever see one, and they
print it once.

**A3. Channel mode `off` (core open item 5).** While a channel is `off`, the stock-writing routes
(`/v1/reservations*`, `/v1/opening_orders`, `/v1/movements`) answer 409 `channel_off`, and the
answer is not stored. Health, heartbeat, the GET routes and `PUT /v1/listings` are allowed.
*Why:* §8.1 says only events after T0 (the moment a site enters shadow) reach CW, and opening
orders are sent at shadow start. Health must work so the site can learn its mode. Listing ingest
(Phase 2a) may start before shadow and does not touch stock. The mode check runs before the
idempotency lookup, so a key stored while `shadow` answers `channel_off` after a switch back to
`off`. That is harmless: an `off` site sends nothing.

**A4. Idempotency over HTTP.**
- Every POST needs `Idempotency-Key` (1–191 printable ASCII). Without one the answer is 400
  `idempotency_key_required`; a malformed key gets `bad_idempotency_key`. The key is checked
  before the body is read.
- The scope and request hash are the core's (D28/D29). A retry that sends the lines in another
  order is therefore a replay, and the same key on another endpoint or with another body gets
  422 `idempotency_key_reused`.
- The heartbeat goes through `Idempotency::run` too.
- `PUT /v1/listings` takes no key: it writes values, so repeating it is harmless.
- A replay carries `Idempotent-Replayed: true`.
- Nothing that fails before the core is stored (auth, `off`, route, body shape), and neither is
  a `CwException` (D27). That includes `idempotency_key_busy` (409), which a parallel change to
  `Idempotency` (same-key calls queue on a named lock) returns after 30 s of waiting.

**A5. Request shapes.**
- Bodies are JSON objects: `Content-Type: application/json` (else 415), at most 8 MB (else
  413), valid JSON (else 400 `bad_json`). A list or scalar body is also 400.
- `release` also accepts an empty body (attempt 1, R9). `attempt` is at most 4294967295.
- `order_ref` may be a JSON integer.
- `commit.origin` defaults to `reserved`.
- `cancel.restockable` and `opening_orders.final` must be explicit booleans: the site sends its
  tick as it is, and a default either way would hide a bug.
- `unship` requires `at`, the reset time (D35, R10); a body with `dispatched_at` is refused (400).
- The `{ref}` path segment is percent-decoded. Apache refuses `%2F` by default, so a ref
  containing `/` cannot be used in a path; site refs are numeric.
- Unknown fields are ignored.

**A6. Read endpoints.**
- `GET /v1/changes?after=&limit=`: after ≥ 0 (default 0), limit 1–5000. It is `Availability::changes`.
  It also carries `head_seq`, the feed head (A15).
- `GET /v1/availability?variant_ids=`: 1–1000 printable ids, as a comma list or repeated
  `variant_ids[]`.
- `GET /v1/snapshot?after_listing=&limit=`: limit 1–5000.
- `GET /v1/health`: `{channel, mode, time}`.
- `GET /v1/purchasing?after_sku=&limit=`: limit 1–2000, paged by sku id. Per item:
  - `on_hand`, `allocated`, `held`, `available`, summed over sellable warehouses;
  - `non_sellable_on_hand` (VERIFY, UNSTAMPED);
  - `units_90d`, as `{channel code: central units}` of units in reservations committed in the
    last 90 days and still allocated, shipped or returned (cancelled and released units are
    left out; opening orders count).

  Any authenticated site may read it: one business owns all three.
- Bad query values get 400 `bad_query`.

**A7. `POST /v1/heartbeat`.**
- One `channel_health` row per call (D18). It keeps `remote_ip` and the whole body as `payload`
  (at most 16 KB).
- Checked fields: `site_mode` off/shadow/live, the counters are integers ≥ 0, and
  `connector_version` is at most 32 characters.
- The answer is `{result, mode, feed_seq, received_at}`. `mode` is CW's `channel.mode`; the site
  takes the lower of that and its own (§12). Every authenticated answer also carries it in
  `X-CW-Channel-Mode` (A13).
- It needs an `Idempotency-Key` like every POST; the same key with another body is 422 (A16).

**A8. `PUT /v1/listings`** writes `listing_profile` only (§3, D6).
- A variant CW has never seen gets its `unmapped` `channel_listing` row first. The profile's FK
  needs it; a sale does the same (D32).
- It never changes a link (sku, status, u) and writes no feed row.
- Text is cut to the column length rather than refused, so one long title does not fail a
  nightly batch.
- Barcodes are de-duplicated and sorted, price is kept to 2 dp, and attributes must be a JSON
  object.
- `profile_hash` covers every field. `identity_hash` covers titles, brand, attributes and
  barcodes, and drives the `identity_changed` flag for the matcher (§7.3).
- An unchanged profile only moves `pushed_at`.
- At most 1000 listings per call, in one transaction, audited as `listing.profiles`.
- Variant ids resolve through the database's own comparison (see A12). Two ids in one push that
  are the same listing get 400.

**A9. The web process never reads `db.env`.**
- `Config::loadApp()` reads `app.env` only, and a missing file is an error. `app.env` now also
  carries `db_host`/`db_port`, copied from `db.env`, plus optional `db_sslmode`/`db_ssl_ca`,
  which win over `db.env`'s for both profiles.
- On staging: `/etc/cw` is 0750 root:www-data, `app.env` is 0640 root:www-data, and `db.env` is
  0600 root. php-fpm (www-data) therefore has the `cw_app` login and never `doadmin`.
- `bin/setup_staging.php` now keeps an existing `app.env`'s group and mode (capped at 0640) and
  writes `db_host`/`db_port`. It has not been re-run.

**A10. Staging deployment (`deploy/staging/`, installed by `install_api.sh`).**
- Apache listens on `127.0.0.1:8080` only.
- Every path is rewritten to `public/index.php`, which runs in the php-fpm pool `cw-api`.
- `CGIPassAuth On` passes the Bearer header on.
- `mod_remoteip` is kept off, and `TraceEnable`/`ServerSignature` are off.
- The pool runs as www-data with `pm = ondemand` and 8 children, so at most 8 database
  connections; the cluster allows 76 in total.
- `/var/log/cw-api/*.log` is rotated by size, hourly (R18).
- The pool pins `CW_DB_NAME=cw_test_api`, the api slot's test schema, which each PHPUnit run
  re-creates. `cw_app` has table grants there too: `install_api.sh` and `ApiTestCase` converge
  them with `Grants::apply`, and MySQL keeps table grants across DROP/CREATE.
- `tests/Integration/Api*Test.php` are skipped in every other slot, because there the served
  schema is not the schema under test (`CW_API_SCHEMA` overrides the name).

**A11. Channel tools and audit.**
- `bin/create_channel.php` and `bin/rotate_key.php` connect as `cw_app` (least privilege). They
  print the key alone on stdout and messages on stderr.
- `create_channel.php` defaults to mode `off` and an empty allowlist, which refuses every call
  (with a warning); `--ips` adds addresses. It assigns exactly one sellable warehouse (D9).
- Rotation replaces the key at once. A grace window would need a second hash column.
- Both tools write an audit row, without the key.
- `Caller::channel()` now carries the client `REMOTE_ADDR`, and `Audit::write` stores it in
  `audit_log.ip`, so every API write is attributed to a site and an address (§11 "write audit").

**A12. Core fixes found through the API** (fixed minimally, each with a test):
- `Audit::write` (entity_id, 64) and the goods-in suspense dedupe key (191) cut strings with
  `substr`. That can split a UTF-8 character, and strict mode then refuses the row (1366), so the
  site gets a 500 and can never book, for example, a `doc_ref` longer than 64 bytes with a
  non-ASCII character. Both now use `mb_strcut`.
  Test: `ApiResourcesTest::testLongNonAsciiDocumentRefsAreBooked`.
- `Reservations::listings()` failed with "listing rows could not be created" (a 500) when a sale
  named a variant in another case than the stored one. `external_variant_id` is `_ai_ci`, so
  `INSERT IGNORE` saw it as a duplicate while the PHP map missed it. A variant without an exact
  match is now looked up with `=`, which applies the column's own collation.
  Test: `ApiReservationsTest::testVariantIdsFollowTheDatabaseCollation`.

## Connector follow-ups, API side (slot `cfu1`, 2 Oct 2026)

A13–A17 continue the A-series (the HTTP API). They close follow-ups F1, F2, F5, F6 and F8 found
while building the site connector. Code: `src/Api/Kernel.php`, `src/Availability.php`,
`src/Reservations.php`, `src/ChannelAdmin.php`, `bin/channel_set.php`. Tests:
`tests/Integration/ApiKernel/` drives the real kernel in-process (base
`tests/Support/ApiKernelTestCase`), and `tests/Integration/Ops/ChannelSetToolTest.php` runs the tool
as the app login, so both run in every slot. The HTTP tests of slot `api` check the same over Apache.

**A13. `X-CW-Channel-Mode` on every answer after authentication (F1; amends A1, A7; plan §6.2).**
- Every answer to a caller that passed authentication (key AND allowlist, A2) carries
  `X-CW-Channel-Mode: off|shadow|live`. That includes successes, 404/405 from routing,
  409 `channel_off`, 400 for a missing or malformed Idempotency-Key, 413/415, shape errors, domain
  refusals, 500 `internal` and 503 after authentication, and idempotent replays.
- The value is `channel.mode` as this request's authentication read it: the mode the request was
  judged under (the `off` gate, A3, uses the same read). A replay carries the mode of the moment,
  while its stored body stays as stored (a replayed heartbeat's `data.mode` is the old one).
- It is never sent before authentication: not on a 401, not on a 403 (a known key from an address
  off the allowlist learns nothing), not on a 503 before the database answered.
- *Why:* §6.2 takes the site's effective mode as the lower of its `CW_MODE` and CW's mode "read from
  every response". Before this only the health and heartbeat bodies carried it, so a site learnt of a
  switch on its next heartbeat (60 s) or health probe. A header keeps stored answers byte-identical
  on replay (A1) and changes no body.

**A14. `bin/channel_set.php` sets a channel's mode and allowlist (F2; amends A11; closes the open item
"a tool to change a channel's allowlist or mode").**
- `php bin/channel_set.php --code=<c> [--mode=off|shadow|live] [--ips=<a,b/24>|none] [--actor=<who>]
  [--apply] [--db=<schema>] [--admin]`, through `ChannelAdmin::configure`. It is a dry run unless
  `--apply`. Both print `mode: <before> -> <after>` and `allowed_ips: [..] -> [..]` (or
  `(unchanged)`) and any warning.
- `--ips` replaces the list, with the same validation as `create_channel` (addresses or CIDR blocks,
  `/0` refused, duplicates dropped). `--ips=none` empties it. `--ips=` with no value, an option
  without `=value` and a repeated option are usage errors: `getopt()` would silently drop the first
  and take the next option as the value of the second.
- With `--apply`, one transaction X-locks the channel row first (like key rotation, D39), reads the
  before-values again under that lock, and writes and audits each setting that really changes:
  `channel.mode` {from, to, by} and `channel.allowlist` {before, after, by}, actor
  `system:channel_admin`. `by` is `--actor`, else the login running the tool (`SUDO_USER` first).
  A list that differs only in order is unchanged. When nothing changes, nothing is written or audited.
- Warnings, never refusals (the dry run is the review step): a mode other than `off` with an empty
  allowlist (every call gets 403); `off -> live` (skips shadow, §12); `live` before the channel's
  final opening_orders batch (D40); a lower mode (the site's rollback steps apply, §12).
- It connects as the app login like the other channel tools (A11) and uses the job frame (H3):
  exit 0 ok or dry run, 1 refused (unknown channel; bad mode, address or actor), 2 usage, 3 cannot
  run. It takes no job lock: the row lock serialises two runs.
- No feed row is written: a listing's view does not depend on the mode, and the site learns the mode
  from A13.

**A15. `GET /v1/changes` carries `head_seq` (F5; amends A6; answers the open item "after a restore
from backup").**
- `head_seq` is MAX(seq) of `stock_change`, 0 on an empty feed, read after the page. So
  `head_seq >= next_after`, unless the caller's own `after` is beyond the head. It can exceed
  `next_after` (more pages, or rows committed meanwhile).
- Pruning keeps the newest row of every scope (H2), and seq is allocated in commit order (D39), so
  the head never moves back in operation. A `head_seq` below the seq the site last applied
  therefore means CW was restored to an earlier point. The site must then reset its versions and
  re-snapshot (`/v1/snapshot`, whose `seq` is the same head). It sees this on every poll (every 2 s),
  not only through the heartbeat's `feed_seq`, which is the same head.
- *Limit:* it sees a restore only while the restored head is still below the site's cursor. When CW
  writes more rows after a restore than it lost (other channels, purchasing) before the site polls
  again, the head passes the cursor and the reused seqs in between go unnoticed. After any restore
  the operator resyncs every site by hand (open items).

**A16. The heartbeat's Idempotency-Key, spelled out (F6; amends A4, A7).** `POST /v1/heartbeat` is a
POST like the others.
- Without `Idempotency-Key` the answer is 400 `idempotency_key_required` (`bad_idempotency_key` for a
  malformed one), and nothing is recorded.
- A retry with the same key and the same body (fields in any order) replays the stored answer
  (`Idempotent-Replayed: true`, no second `channel_health` row).
- The same key with another body is 422 `idempotency_key_reused`, and nothing is recorded.
- So the connector makes one key per heartbeat and re-sends exactly that body on a retry. The next
  heartbeat, with new counters, gets a new key.

**A17. An extended hold answers with its lines (F8; amends plan §3's reserve row).**
- A reserve on a held order with the same lines answers 200 `extended`. It has the same top-level
  keys and the same `lines[]` as a fresh 201, in the same (normalised) order.
- Per line: `variant_id` (as sent), `qty`, `units_per_item`, `kind` (the item's policy, or
  `unlinked`), `result` (`held`, or `unlinked`), `sku_code`, `quarantined` (linked lines) and
  `available` (listing units, after the hold).
- Item, u and warehouse are the units' snapshot: what the hold sits on, even if the listing was
  relinked or its u changed since. Policy, code, quarantine and `available` are read as they are now.
- The extra reads take no locks. Under READ COMMITTED they see the latest committed rows, and the
  extension moves no bucket, so no balance lock is taken and the lock order is unchanged. The answer
  is stored under the key like every other.
- *Why:* the connector handles 201 `held` and 200 `extended` in one branch. Without `lines[]` a
  payment retry lost the per-line kinds and availability that a fresh hold gives it.

## Connector follow-ups, stock side (slot `cfu2`, 2 Oct 2026)

D46 closes follow-up F7 found while building the site connector. Code: `src/Reservations.php`
(`uncancel`), `src/Stock.php` (`UNFLAGGED`), `src/Invariants.php` (10–11), the route in
`src/Api/Kernel.php` and `ReservationsController`. Tests: `tests/Integration/Stock/UncancelTest.php`,
`UncancelInvariantsTest.php`, `tests/Integration/ApiKernel/UncancelRouteTest.php`, and hammer scenarios 3–5.

**D46. Uncancel: a cancel taken back (F7; amends D36, D37, D44).**
`POST /v1/reservations/{ref}/uncancel {unit_ids}` (`Reservations::uncancel`). The site sends it when a
line cancel is taken back (`ordi_iscancelled` 1 → 0 on a paid order).
- Like ship/unship/return (D36): an unknown order is 404 `unknown_order`, an order that is not committed
  (held, released, expired, a tombstone) is 409 `not_committed`. Neither is stored (D27), so the same
  key works once the commit has arrived. It is a stock-writing route: 409 `channel_off` while the
  channel is `off` (A3).
- Per unit, under its sale-time snapshot (warehouse, item, u), the result is one of:
  - `uncancelled`: the unit's last cancel was restockable, or it was cancelled while held.
    allocated +u (movement `uncancel`).
  - `uncancelled_from_verify`: the last cancel parked it in VERIFY (D37) and it is untouched there.
    Untouched means all of:
    - its `verify_recount` is still open;
    - no count of the item at VERIFY was booked after the unit arrived (whatever that count's
      `counted_at`: a later count replaced the figure, R13);
    - no other on_hand row of the item at VERIFY was booked after the unit arrived, except other parked
      units arriving (`transfer_in` noted `cancel_not_restockable`) and leaving (`transfer_out` noted
      `uncancel_from_verify`). D37 has a person settle VERIFY with `write_off` or
      `transfer_out`/`transfer_in`, and no screen closes the recounts, so such a row may concern any unit
      parked before it: CW cannot tell whose unit moved and moves none of them back (review fix, 2 Oct);
    - VERIFY still holds u.

    CW then books the paired movement back (`transfer_out` VERIFY −u and `transfer_in`
    +u at the unit's warehouse, noted `uncancel_from_verify`, at booking time), then allocated +u.
    Availability does not move, so this can never oversell. The recount is dismissed
    (`resolution = 'uncancelled'`, the call's key in its note).
  - `uncancelled_after_verify`: parked in VERIFY, but somebody may already have dealt with it. The
    recount was resolved or dismissed by a person, VERIFY was counted since, VERIFY was moved by hand
    since (a write-off or transfer), or VERIFY holds less than u. CW cannot tell where that unit is now,
    so VERIFY is left alone. The unit is allocated +u like a restockable one, and a `count_review`
    `uncancel_after_verify` at the unit's warehouse asks a person to reconcile (for example, to move
    the unit back from VERIFY, or to reverse a write-off). Its detail carries `reason`
    (`recount_closed`, `verify_counted`, `verify_moved`, `verify_short`), the ledger id of the count or
    move that decided it, and the recount's id and status. Availability is then lower than the shelf
    until the person acts, never higher.
    - *Example (the review's probe):* 10 at MAIN; units a and b cancelled to VERIFY (MAIN 8, VERIFY 2);
      staff move one back (MAIN 9, VERIFY 1). Uncancelling a gives MAIN 9 with 1 allocated, available 8,
      VERIFY 1 and a review. Before this rule a came back from VERIFY, taking b's still-unverified unit
      with it: available 9, one too high, with nothing raised.
  - A unit cancelled while its listing was unlinked, and linked since, is adopted with today's link,
    as a commit without a hold would do. The unit's sku and u are set, and the ledger note is
    `adopted: listing <id>`. A unit that is still unlinked only changes state.
  - Anything else gets a stored per-unit result and changes nothing: `not_cancelled` (allocated),
    `shipped`, `returned`, `released`, `unknown_unit`.
- Which cancel parked a unit is read from the ledger: the unit's newest `cancel` row, and a
  `transfer_in` to VERIFY noted `cancel_not_restockable` after it. No schema change.
- In both VERIFY cases the unit's recount key (`verify:<channel>:<unit>`) is retired, by suffixing
  `#<review id>`. A later cancel to VERIFY then opens a fresh recount instead of meeting the old row's
  dedupe key (D17). A person's decision on a resolved recount is left as it is.
- **Oversell.** Some uncancels lower availability: the restockable, after-VERIFY and adopted units.
  Where they leave a `strict` or `stopped` item below zero, CW raises one `oversell_event`
  `uncancel_short` per balance and call, with the reservation. The shortfall is min(those units,
  −available). The event is also listed under `oversell` in the answer, like commit's.
  `Stock::flush` leaves `uncancel` rows to this check (`UNFLAGGED`), so nothing is flagged twice.
  R7's flags that ignore the stock level (`stopped_sale`, `quarantined_sale`) are not raised: an
  uncancel re-instates a sale CW already accepted.
- Lock order is unchanged: reservation → listing rows FOR SHARE (adoption only, R4) → balances (the
  sale warehouse and VERIFY, in one sorted `lock()`) → sku FOR SHARE → the recount rows → value clocks →
  feed clock.
- **Keys.** A cancel and an uncancel of the same units are separate events, each with its own key
  (e.g. a per-unit cycle counter in the connector's key). A cancel re-sent under an earlier cancel's key
  replays that answer and changes nothing; so does an old uncancel key.
- Invariants 10–11 (D44):
  - each cancel or uncancel move of a unit through VERIFY is one balanced `transfer_out`/`transfer_in`
    pair of one item, with VERIFY on the right side;
  - per unit and item, those VERIFY rows never net below zero;
  - a unit whose newest such row is a move back has no open recount under its key.

*Why:* until now CW could not take a cancel back. The site would ship a unit that CW held as
cancelled, so on_hand never fell for it and availability stayed overstated by u. The VERIFY rule keeps
D37's "nothing is written off without a person". An untouched unit is simply its cancel undone. A unit
that a person or a count may have acted on (any count, write-off or hand transfer of the item at VERIFY
since it arrived) is not guessed at, and is put in front of a person instead.

## Connector follow-ups, opening and staging tools (slot `cfu3`, 2 Oct 2026)

F4 is D40b, recorded next to D40a above. D47 closes F9. Code: `src/Ops/TestRefPurge.php`,
`bin/purge_test_refs.php`, `Caller::channelJob`, the staging marker in `bin/setup_staging.php`. Tests:
`tests/Integration/Ops/PurgeTestRefsTest.php`.

**D47. Test leftovers on a staging channel: `bin/purge_test_refs.php` (F9).**
`php bin/purge_test_refs.php --channel=<code> --prefix=<order_ref prefix> [--heartbeats-until=<ISO time>]
[--actor=<who>] [--apply] [--db=<schema>] [--admin]`. It is a dry run unless `--apply`.
- **Staging only.** app.env must say `environment=staging`, and the schema must be `cw_staging` or `cw_test_*`.
  Otherwise the tool exits 1 with `REFUSED`.
  - The value is read from the app.env file (`Config::appFile`). A `CW_ENVIRONMENT` variable does not count.
    `CW_APP_ENV` can still point the tool at another app.env file (the tests use that), so the marker guards
    against mistakes, not against a determined operator.
  - `bin/setup_staging.php --mark-staging` writes the line; without the flag the script leaves the marker
    alone (review fix: a re-run on a server promoted to real use must not put it back). On today's staging
    box it is added once by hand (ops.md). Production's app.env never carries it.
  - **A channel in mode `live` is refused** (409 `channel_live`, `REFUSED` on the CLI), whatever the marker
    says (review fix). The marker and the schema name cannot tell a staging schema that carries live data
    apart: `cw_staging` already holds the owner's real duty-day estimate on the live-shaped `vapeandgo`
    channel. A test channel is `off` or `shadow`; lower a live one with `bin/channel_set.php` first if it
    really is a test.
  - **Go-live checklist:** before a CW server or schema carries real orders, remove `environment=staging`
    from its app.env (ops.md "Test leftovers on staging").
- **The prefix** is 4–32 characters of A–Z a–z 0–9 . : - with at least one letter. A bare number would match
  real ord_ids, and LIKE wildcards are refused.
- **Steps.** The dry run lists each reservation with what would happen to it.
  1. *Neutralise* through the normal Reservations paths, so the ledger records it. A held reservation is
     released (its current attempt). The held and allocated units of a committed one are cancelled, restockable.
     Shipped and returned units stay as they are.
     - The calls run as `Caller::channelJob(<channel>, 'purge_test_refs')`: the channel's idempotency scope,
       actor `system:purge_test_refs` in the ledger and the audit log.
     - Keys: `purge:release:<ref>:<attempt>` and `purge:cancel:<ref>:<16 hex of sha1(unit ids)>`, so a re-run
       replays them.
  2. *Delete* a reservation and its units only when no `stock_ledger` row names the order or one of its units,
     and no `oversell_event` names the reservation. It is re-checked under the reservation's lock (lock order),
     in one transaction per reservation, and audited `reservation.purged` (the units and their last state in the
     detail). A linked unit always has ledger rows, so its reservation stays and is listed `kept (ledger)`.
  3. *Delete the idempotency rows of the refs left without a reservation*: the channel-scope keys that
     `audit_log` names for a `reservation` entity with the prefix, for refs whose reservation this run (or an
     earlier one) deleted, or that never had one. Every reservation call is audited with its key and ref,
     including a refused reserve that left no reservation, and the purge's own calls.
     - The keys of a **kept** reservation stay, and the report counts them (review fix). After the purge
       released or cancelled it, a late retry under an old key must replay its answer: deleted, a commit
       retry would run again as `commit_after_expiry` and allocate again, and an old uncancel key would
       re-allocate the units the purge cancelled.
  4. With `--heartbeats-until`: **all** of the channel's `channel_health` rows received at or before that time,
     and **all** its `/v1/heartbeat` idempotency rows created at or before it. The prefix does not apply:
     heartbeats name no order. The dry run shows their count, time range, addresses and connector versions,
     to check they are the test's.
  5. One `purge.test_refs` audit row: actor `system:purge_test_refs`, the channel, `by` (`--actor`, else the login
     running it, SUDO_USER first), the counts, and the kept and failed refs.
- **Never deleted:** `stock_ledger`, `stock_value_ledger` and `audit_log` rows (append-only; the app login cannot)
  and listings (NO_DELETE). The tool connects as `cw_app` like the other jobs (H3), which has full rights on
  `reservation`, `reservation_unit`, `idempotency` and `channel_health`, and takes the job lock.
- **Failures.** A reservation whose neutralising call fails is skipped and listed `FAILED` at the end, the rest go
  on, and the exit code is 1 (the owner's rule for bulk actions: skip what is doubtful, finish the rest, list the
  skipped).
- The invariants stay green: only units with no ledger rows (unlinked ones) are deleted, with their reservation.

*Why:* the Phase 3 connector smoke left `proto1-` reservations, unlinked units, idempotency rows and heartbeats
on `cw_staging`'s live-shaped `vapeandgo` channel. Removing them by hand means admin DELETEs that nobody audits,
and a hand-made mistake there could delete rows the ledger still names. This tool goes through the same paths a
site does, and every row it removes is audited.

## Connector follow-ups, site side (the Vape and Go connector, proto, 2 Oct 2026)

SC1–SC5 record how the site connector (`App_proto/src/central_warehouse`, proto only) uses A13, A15, A17, D46
and D40b. Its tests: `php tests/run.php` in that directory, against the mock CW `tests/mock_cw/router.php`,
whose `legacy` switch stands for a CW before these follow-ups.

**SC1. CW's mode from every answer (A13).** `cw_http` reads `X-CW-Channel-Mode` on every answer and updates
the mode cache (`state_dir/mode.json`, source `header`); never from a 401/403 or an answer without it. The
effective mode follows a switch at CW on the next call of any kind (the feed poll every 2 s, any hook call).
- The health probe is now a fallback: it runs when no header was seen for 15 s (an older CW), and as the
  breaker's recovery probe.
- The heartbeat takes the mode from the header. Its body's `mode` counts only without a header and when the
  answer is not a replay, because a replayed body carries the first answer's mode.
- *Review fixes:*
  - A header with a lower mode caps the effective mode the process has already cached, at once and before
    any early return. Otherwise, when mode.json already held the new mode (the worker's poll wrote it), a
    checkout that cached `live` kept skipping the site's own stock check after a reserve CW had judged in
    shadow, where CW refuses nothing.
  - A header may raise mode.json only if its request was sent after mode.json was last written (mode.json
    carries `at_us`). An answer judged before a lowering that another process has already recorded is
    stale; written back, it would hold mode.json up for up to 10 s. Lowering is always taken.

**SC2. A restore is detected on every poll (A15).** A `/v1/changes` answer whose `head_seq` is below the seq
the site asked after is not applied: `feed.reset_required` is set and `feed_restore_detected` alerts (source
`changes`). A resync snapshot whose `seq` is below the cursor counts too. The feed then stops until
`bin/cw_resync.php --reset-versions`, as before. An older CW without `head_seq` keeps the heartbeat's
`feed_seq` check.
- *Review fix:* the heartbeat's check runs only while the feed poll has seen no `head_seq` (an older CW; the
  poll forgets the head when CW stops sending it), and never on a replayed answer. A replay carries the
  `feed_seq` of its first answer. A worker that restarts within the minute polls first, moving the cursor
  on, then resends the pending heartbeat; the old `feed_seq` would then signal a false restore and stop the
  feed until someone resynced by hand.
- *Limit (A15):* a restore is seen only while CW's head is still below the cursor. After any restore the
  operator resyncs the sites by hand (CW ops.md).
*Why not reset by itself:* a restored CW lost events the site had already sent (holds, commits, ships). A person
reconciles CW first; otherwise the site would take CW's restored figures, which are too high.

**SC3. Uncancel (D46).** The unit sweep sends `POST …/uncancel {unit_ids}` when a unit whose cancel was queued
is live again (`ordi_iscancelled` 1 → 0, or a Cancelled order completed again) and not shipped. The unit's
`cancel_state` goes back to none and its `cancel_cycle` up by one.
- Keys: `vpg:cnl:<ref>:<cycle>:<h>` and `vpg:ucn:<ref>:<cycle>:<h>`, cycle = cancel_cycle + 1, so every cancel
  and every uncancel of a unit has its own key (D46 "Keys").
- Expected answers: `uncancelled`, `uncancelled_from_verify`, `uncancelled_after_verify` (logged as a warning:
  CW opened a count review) and `not_cancelled`. A unit CW cannot put back (`shipped`, `returned`,
  `released`, `unknown_unit`), or an uncancel row that goes dead, raises the alert `unit_sweep_uncancelled`.
  An `oversell` in the answer is logged.
- It needs the connector's SQL v2 (`cw_outbox.kind` 'uncancel', `cw_unit_state.cancel_cycle`), applied by
  `bin/cw_install_tables.php` before the code; the worker refuses to start without it. Applied on the proto
  database on 2 Oct 2026.
- **The order-level cancel grace is 15 minutes (it was 48 h).** *Why:* the 48 h existed only because CW could
  not take a cancel back. Now a cancel taken back is an uncancel, so the grace only has to absorb quick toggles
  (a mis-click, a payment status flipping back). Without any grace each toggle would put the stock back on sale
  for its length, where another order could take it, and the uncancel would then be an `uncancel_short`
  oversell. With 48 h, a really cancelled order kept its units allocated for two days; now it is 15 minutes.
  Units cancelled one by one are not delayed, as before.
- An order holding a cancelled unit closes after 48 h without a change (6 h otherwise), so an order completed
  again within two days (the proto fork's longest gap was 29 h) is uncancelled at once. A later one is found
  by the nightly 30-day reopen, within a day.
- *Review fixes:*
  - Older than 30 days: the nightly pass also reopens any closed order holding a unit whose cancel was sent
    and that is live again on the site (one scan of `cw_unit_state` for queued cancels). Before, nothing
    reopened it, and CW kept the units cancelled while the site shipped them.
  - A ship that CW answers `cancelled` (the site shipped a unit CW still holds as cancelled) raises the
    alert `unit_sweep_uncancelled` (`why` = `shipped_while_cancelled_in_cw`), like a failed uncancel.
  - An `oversell` in an uncancel answer is now the alert `uncancel_oversell`, not a warning: two paid orders
    want one unit, and a person has to sort that out.
  - Return rule: every site restock path sets `ordi_restock` and `ordi_iscancelled` together. A unit whose
    cancel was taken back (`cancel_cycle` > 0) can keep that cancel's `ordi_restock = 1` with
    `ordi_iscancelled` back at 0; that leftover no longer counts as a return once the unit ships. A real
    restock after the dispatch sets both flags again, or leaves an `order_return_items` row.
  - *Owner's choice, open:* the 15-minute order-level grace. Any order completed again more than 15 minutes
    after its Cancel has its units on sale at CW in the meantime. If they sell, the uncancel raises
    `uncancel_short`. All 9 re-completions seen on proto were 23 s to 29 h after the Cancel. To choose, compare
    paid orders Cancelled per week (stock held back for the grace) with re-completions per week later than
    the grace. A middle value (2 to 6 h) covers most of the observed gaps.

**SC4. The rebase step from the site's box (D40b).** `bin/cw_t0.php --rebase --estimate-doc-ref=<doc_ref>|none`
first checks T0, that CW accepted the final batch (else exit 4), and that the T0 file and its `.sha256` are the
ones captured (else exit 3).
- Without a transport it prints CW's commands for this T0.
- With `--cw-ssh=<user@host> --cw-ssh-key=<file>` it runs `docs/ops.md` "A site's T0" steps 7–10 in order:
  copy, CW's dry run (printed; a run without `--apply` stops there), then with `--apply --approved-by=<who>`
  the real run and `bin/invariants.php`. A failing step stops the run (exit 1, alert).
- The outcome goes to the connector's `cw_meta` `t0_rebase`. `--status` shows it and drops the TODO once
  booked, and a booked rebase is not run again.
- *Review fix:* `--apply` books only the plan the operator reviewed. The recorded dry run must be the last
  step, through the same transport and for the same estimate, and CW's fresh dry run must print the same
  doc_ref and figures (items, up, down, net, skipped). Otherwise it exits 4 (`REFUSED`), nothing is booked
  and the record stays as it was. CW itself now needs `--as-of` on a real rebase run, and the connector
  always sends it.
- Phase 3: refused (exit 4) for any CW but the local mock, the same guard as the capture. The capture's own
  guards (effective mode off, no connector order rows) are unchanged.
- *Review fix:* that guard checks the config, not where ssh connects. `--cw-ssh` is therefore also refused
  while the config points at the local mock (outside a CLI test config): the batches and T0 went to the
  mock, so a rebase over ssh would run on a CW that never received them.

**SC5. Extended holds (A17).** The connector already handled 201 `held` and 200 `extended` in one branch. With
`lines[]` on `extended`, the per-line kinds now survive a payment retry. An older CW's bare `extended` leaves
the kinds empty, so H2 keeps the site's own stock check.

## Concurrency hammer and scheduled jobs (slot `hammer`, 26 Sep 2026)

Numbered H1–H8 so they cannot collide with D- and A-numbers added in parallel. Code:
`tests/concurrency/hammer.php`, `src/Ops/` (Cli, Snapshot, ChangePruner, ChannelHealth),
`bin/expire_reservations.php`, `bin/prune_changes.php`, `bin/invariants.php`,
`bin/health_alert.php`, `deploy/staging/{install_cron.sh,cw-staging.cron,logrotate-cw.conf}`;
runbook in `docs/ops.md`.

**H1. Calls with the same Idempotency-Key queue on a named lock (bug found by the hammer).**
`Idempotency::run` takes `GET_LOCK('cw_idem:' || sha1(schema, scope, key), 30 s)` before its
transaction and releases it after. The fast path is unchanged: a key that is already stored is
replayed before any lock.
*Why:* a call that stores nothing (a `CwException`, e.g. ship before commit, D36) rolls its key
claim back. Every copy of the call that was waiting on that claim held a shared lock from its
duplicate-key check, and all of them then tried to insert the freed key: the InnoDB
S-lock/insert-intention deadlock. With 20 copies there were 35 deadlocks and 2 surfaced as errors;
with 12, 18 deadlocks (`IdempotencyRaceTest`). A waiter on the named lock holds no row lock, so it
cannot deadlock, and the next copy claims the key alone. Cost: two round trips per stored call. A
call that waits more than 30 s answers 409 `idempotency_key_busy` (not stored; retry).

**H2. Pruning the change feed keeps the newest row of every scope.** `bin/prune_changes.php`
(nightly, 14 days) deletes `stock_change` rows older than the cutoff, except the newest row of
each (channel_id, sku_id, listing_id) scope, however old.
*Why:* a listing's version is MAX(seq) over its scopes (D38). A plain age cut would move the
version of a listing that has not changed for 14 days back to 0, and a site starting empty would
then never apply its value. Keeping one row per scope leaves every version and the feed head
exactly as they were (`ChangePrunerTest`). The work runs in windows of 5,000 rows by seq: read the
window, look up the newest seq of each distinct scope once (backward lookup on
`ix_stock_change_scope`), then delete in autocommit chunks of 1,000. A per-row `EXISTS (newer
row)` probe took 27 s per window for one busy item; this takes 47 ms. A site away for longer than
14 days catches up through its 15-minute snapshot (§3), not the feed.

**H3. Scheduled jobs share one frame (`CW\Ops\Cli`).**
- They connect with the **app login** (`cw_app`). `--admin` switches to db.env's admin login, for
  test schemas and break-glass use.
- `--db` names the schema (default: app.env `db_name`).
- A job refuses to run when `schema_migrations` differs from `migrations/` in either direction.
  It reads the table directly, because `cw_app` has only SELECT on it.
- One run per job and schema: `GET_LOCK('cw_job:<job>:<schema>', 0)`. An overlapping run logs
  "skipped" and exits 0. The lock works across hosts and is freed if the process dies.
  `invariants` and `health_alert` are read-only and skip the lock.
- Exit codes: 0 ok, 1 problem found, 2 usage, 3 cannot run.
- One log line per run on stdout, with the UTC time, job and schema. Errors go to stderr, and
  secrets are never printed.

**H4. Expiry cron isolates a failing reservation.** `Reservations::expireDue()` gained an
optional `$onError(id, error)`. Without it, behaviour is unchanged (the error propagates). The
cron passes it, logs each failure once and keeps expiring the rest. It runs batches of 500 until
none is due or 50 s have passed, and exits 1 if anything failed.
*Why:* the select is `ORDER BY expires_at`, so a reservation whose expiry always throws (a corrupt
balance, a long lock wait) would sit first in every run and stop all expiry. Held stock would
then never come back, and strict items would read sold out on every site
(`OpsScriptsTest::testExpireDueIsolatesAFailingReservation`).

**H5. The invariant check reads one consistent snapshot.** `bin/invariants.php` runs
`Invariants::check` inside `CW\Ops\Snapshot::read()`: REPEATABLE READ, `START TRANSACTION WITH
CONSISTENT SNAPSHOT, READ ONLY`, no locks.
*Why:* the check runs several queries. Under the service's READ COMMITTED (D20) it could see half
of a concurrent transaction and raise a false alarm at night. On a snapshot, any instant must
satisfy it. The hammer relies on this: it checks invariants on about 60 live snapshots while 22
processes trade.

**H6. Staging layout for the jobs.**
- The cron runs from `/opt/cw-staging`, never from a slot directory. That directory is a copy
  made by `deploy/staging/install_cron.sh` (no tests or tools, `composer --no-dev`). Slot
  directories are re-synced with `--delete` by whoever uses them.
- `/etc/cron.d/cw-staging` runs as root, because `/etc/cw/app.env` is root-readable.
- Logs go to `/var/log/cw/<job>.log`, rotated weekly with 8 kept. An invariant failure is also
  sent to syslog, tag `cw-invariants`.
- Schedule (UTC): expire every minute, prune at 03:17, invariants at 03:47.
- `health_alert` is not scheduled: it only prints, and there is no webhook yet.
- **`cw_staging` was migrated to `0002_stock_core.sql` on 26 Sep 18:02 UTC.** That file is now
  frozen: change the schema only through new files (`0003_*`).

**H7. How the hammer is built (`tests/concurrency/hammer.php`).**
- Processes: `pcntl_fork`. Each child opens its own connection after the fork. The parent holds
  none while children live, because a TLS socket shared across fork is corrupted when a child
  exits; a `WeakReference` check enforces this.
- Start: children connect, report ready over a socket pair, and start together when the parent
  says go.
- Connection budget: at most 30 at once (workers + observer + parent). This is checked against
  the cluster's free connections, minus a spare of 8 for other slots, before the run. Scenario 3
  uses exactly 20 processes.
- "Availability never negative at any observed point" is checked three ways: a polling observer,
  the value in every response, and a replay of each balance's ledger row by row in id order,
  which is the balance's own lock order.
- Scenario 4 needs short holds, but channel TTLs cannot go below 60 s (CHECK). Its traders
  therefore run on a clock 57 s behind the expiry crons' real clock, which gives holds a 3 s
  life. Two expirers run at once, because overlapping cron runs must be harmless.
- Every run prints its seed; `--seed` replays the random choices, though not the timing.

**H8. `health_alert` (stub).** It checks channels in `shadow`/`live` only; `off` channels are
skipped. It reports:
- `stale`: no heartbeat at all, or the newest is older than 180 s (three missed 60 s beats).
- `dead_letters`: the newest heartbeat reports dead letters.
- `mode_mismatch`: the site's `site_mode` differs from `channel.mode` (§6.2).
It prints one line per problem and exits 1 if there is any. Sending alerts to a webhook comes
with the alerting work.

## Open items (for the owners of the next slices)
- Install DigitalOcean's CA certificate as `/etc/cw/ca-certificate.crt` and set `sslmode = VERIFY_CA`
  (D21).
- ~~When php-fpm serves CW, `app.env` needs group read for the pool user.~~ Done on staging:
  0640 root:www-data (A9).
- The staging cluster allows **76 connections** in total. The §14 hammer (200 parallel reserves)
  must use a bounded connection pool, or the cluster must be resized.
- The matching tables (`match_proposal`, `match_decision`, `listing_map_history`, `ai_request`,
  `ai_call_log`, `alias`) are left to the matching workstream (a later migration). `tests/matching/`
  is not in any phpunit.xml suite yet; add a suite when that workstream is ready.
- Stock core: `0002_stock_core.sql` is now applied to `cw_staging` too (26 Sep 18:02 UTC, H6), so
  it is frozen; any other workstream's migration must be `0003_*` or later.
- Stock core: the count rule adjusts for ships/unships only (D42). Every other on_hand movement
  booked between `counted_at` and the count's submission is overwritten; since R13 each such count
  opens a `count_after_movements` review listing them. Decide before counting starts (Phase 7)
  whether the count screen should warn up front, or the rule add their net like ships.
- Stock core: `Invariants::check` loads every linked unit into memory (D44); chunk it before
  running it nightly on production volumes.
- API: external ids are compared case-insensitively. `channel_listing.external_variant_id`,
  `reservation.order_ref` and `reservation_unit.unit_id` are `_ai_ci`, so `abc` and `ABC` are one
  id. That is fine for today's numeric ids. A storefront with case-sensitive ids (§10) needs
  `utf8mb4_0900_bin` on these columns (one migration, the same reasoning as D14). Until then,
  `GET /v1/availability` lists a variant sent in another spelling twice: the stored one, plus an
  `unknown` entry.
- API, production: a TLS vhost for `warehouse.floverfy.com` (DNS-only), a pool without the
  `CW_DB_NAME` pin, and `ServerTokens Prod`. On staging, Apache's default site on :80 is still
  public (the Ubuntu default page; not the API).
- API: a long-lived staging instance on `cw_staging` needs `0002` applied there first. It would
  then run as a second pool and vhost, e.g. `127.0.0.1:8081`.
- API: still to build: pruning of `idempotency` (≥ 30 days), `channel_health` and `audit_log`
  (every heartbeat adds one row to each of the three). ~~A tool to change a channel's allowlist
  or mode.~~ Done: `bin/channel_set.php` (A14). The hold-expiry cron now exists (`bin/expire_reservations.php`, H4).
- API: each request opens its own TLS database connection. On staging a health call takes
  ~40 ms and a reserve ~100 ms, well inside the site's 3 s budget.
- API: after a restore from backup, CW's `seq` can be lower than versions a site already holds.
  The site then needs a full resync with its versions reset (a connector and runbook step, §2
  "Backups & recovery"). Detection: `/v1/changes` now carries `head_seq` on every poll (A15).
- API: a body over 12 MB is refused by Apache with its own HTML 413 page, not the envelope.
- Hammer/ops: the scheduled jobs run as root on staging because `/etc/cw/app.env` is root-readable
  (H6). Production should run them as a dedicated user that can read `app.env` only.
- Hammer/ops: `bin/health_alert.php` only prints (H8). Wire it to the alert webhook (§6.3) and
  schedule it once there is one.
- Hammer/ops: the feed clock (D39) serialises every stock-changing transaction from its flush to
  its commit. The hammer measured about 70–100 mixed operations/s with 22 concurrent callers
  against the staging cluster: far above today's peak, but it is the ceiling to watch.
- Opening (D40b): the rebase skips an item as `moved` even when its only other history is ships of orders
  paid after T0, which the arithmetic would allow. Owner's choice: keep it strict (run the rebase straight
  after the final batch, settle any `moved` item by hand), or admit post-T0 rows of the channel's own units.
- Opening (D40b): the connector's `bin/cw_t0.php` still refuses a real capture, and a `--rebase` run through a
  transport, against CW staging (Phase 3 guard, one function: `cw_opening_target_allowed`). Lift it, with the
  owner's go for a staging T0 rehearsal, once CW staging runs the rebase mode (SC4).
- Staging (D47): add `environment=staging` to staging's app.env once (`docs/ops.md`), or run
  `bin/setup_staging.php --mark-staging`. Go-live: remove it from any server or schema that will carry real
  orders (ops.md); the purge also refuses a `live` channel.
- Restore detection (A15, SC2): `head_seq` < the site's cursor catches a restore only while CW's new head is
  still below the cursor. If CW writes more rows after a restore than it lost before the site's first poll
  (other channels, purchasing), the head passes the cursor again and the reused seqs in between are skipped
  unnoticed. A restore therefore stays an operator event: after any restore of CW's database, run
  `bin/cw_resync.php --reset-versions` on every site (ops.md). A fingerprint of the row at `after`, or a
  restore epoch kept outside the database, would close it; not built.

## Review fixes (slot `fix`, 26 Sep 2026)

Numbered R1–R18, one per review finding (concurrency lens: review1; semantics: review2; security:
review3). Every fix has a regression test; the review's own probes moved from `tests/Review/` into
the suites (`tests/Integration/Stock/{LockOrderTest,LinkAdoptionTest,EventTimeTest,
OversellFlagTest,InputRangeTest}.php`, new cases in `CountingTest`, `FeedTest`, `MovementsTest`,
`OpeningOrdersTest`, `ReservationTest`, `Api*Test`, `tests/Unit/{ClockTest,DeployTest}.php`).
Schema: `migrations/0003_review_fixes.sql`.

**R1. Opening orders never lock the channel row (deadlock fix).** Every site transaction holds an S
lock on its own `channel` row from its first statement: the idempotency claim's FK check (and
reservation / listing inserts). The final opening batch took an X lock on that row *after* the feed
clock (`UPDATE channel SET opening_orders_at`), so it deadlocked with any feed-writing call of the
same site, lost a concurrent non-final batch (whose retry then stored 409 `opening_orders_done` for
ever), and made every other site's stock write wait behind the feed clock it held.
Now `channel_opening(channel_id PK, t0_*, opening_orders_at)` holds the marker. `openingOrders`
X-locks that row first after the claim (`INSERT … ON DUPLICATE KEY UPDATE`, then `SELECT … FOR
UPDATE`), reads the marker under it, and writes the marker (and T0) before `flush()`, so the feed
clock stays the last lock. Opening calls of one site therefore run one at a time. Contract: the
connector sends `final = true` only after every earlier batch answered 200 (a batch that arrives
after the final one gets the stored 409 `opening_orders_done`). `channel.opening_orders_at` and
`channel.t0_*` were moved there (0003 copies any values) and dropped. D39 now states the whole lock
order, starting with the claim.
Tests: `LockOrderTest` (forced interleavings on `performance_schema.data_locks`: no deadlock,
non-final batch not lost, another site's reserve not stalled; 3/3 runs).

**R2. `flush()` writes feed rows in multi-row INSERTs** (chunks of `Stock::FEED_CHUNK` = 1,000), so
the global feed clock is held for a couple of round trips, not one per item. A 2,000-line goods-in
now writes its 2,000 feed rows within ~60 ms (was 1.5–1.8 s). `Movements::MAX_LINES` stays 2,000.
Test: `LockOrderTest::testALargeMovementDoesNotHoldTheFeedClockForOneRoundTripPerItem`: under
100 ms in all eight runs (46–90 ms where printed; the review's target was < 100 ms). The assertion
bound is 250 ms: headroom for a busy shared cluster that still catches one INSERT per item
(1.5–1.8 s).

**R3. `resync` only for new channel-wide rows.** Overlap rows (seq ≤ after, last 10 s) add listing
and item ids only; one warehouse re-assignment means one re-snapshot, not ~5 (one per 2 s poll).
The overlap is kept as a second line of defence (D39 already gives commit-order seq).
Test: `FeedTest::testAChannelWideChangeTriggersOneResyncNotOnePerPollOfTheOverlapWindow`.

**R4. Units sold while their listing was unlinked are adopted at link time.**
`Reservations::adoptUnlinkedUnits(caller, listingId)` runs inside the link transaction (the
DecisionService's; `StockTestCase::relink` does the same), after `channel_listing` changed. It locks
the units' reservations (by order_ref, as opening orders do), then the balances; for every `held`
or `allocated` unit of the listing with sku NULL it sets sku and u to the link and books held /
allocated +u (movement `adopt`, per unit: invariant 4 holds), flags a protected item pushed below
zero (`adopt_short`, R6), writes the feed and audits `listing.adopt_units`. The unit keeps the
warehouse recorded at sale time (D13 records it for exactly this; v1 has one sellable warehouse per
channel anyway). Shipped / returned / cancelled / released units are left alone: a shipped one left
before the item's next count, which settles it. Idempotent (a second run finds nothing).
Race: `listings()` now reads the listing rows `FOR SHARE` (after the reservation lock, before the
balances), so a sale and a link change of the same listing serialise: the sale either commits first
(sku NULL) and is adopted, or waits and snapshots the new link. A deadlock between the two (a sale
re-attempting an order that already holds unlinked units of that listing) is retried by
`Db::transaction`. Tests: `LinkAdoptionTest` (6 cases incl. the forced race).

**R5. Caller-reported event times must be plausible** (`Clock::checkWindow`, 400 `bad_time`,
`detail.reason` = `in_future` / `too_old` / `not_after_dispatch`, not stored):
- all: at most `Clock::MAX_AHEAD_SEC` = 300 s after CW's clock (skew between boxes);
- ship `dispatched_at`, unship `at`, opening `t0.at`: at most 90 days old (the connector's nightly
  backstop looks back 30 days; the outbox keeps 14);
- count `counted_at`: at most 24 h old; staff may send `backdated: true` (count only, not sites)
  for up to 90 days (a count typed in later from paper).
The window is checked inside the operation, after the idempotency lookup, so a stored answer is
still replayed later. CW's clock is injectable in `Reservations` and now `Movements` (tests pin it to
`StockTestCase::NOW` = 26 Sep 18:00 UTC; `item()` books its opening stock at 00:00). HTTP tests use
times relative to the real clock (`ApiTestCase::ago()`).
Tests: `EventTimeTest`, `tests/Unit/ClockTest.php`, `ApiReservationsTest`.

**R6. Oversells of the non-sale paths are flagged.** At the end of every stock operation
(`Stock::flush`, before the feed clock): for each balance at a sellable warehouse whose availability
*fell* in this operation and ended below 0, on a `strict` or `stopped` item, one `oversell_event`:
`count_short` (count), `reset_short` (a pre-count unship), `adopt_short` (R4) or `movement_short`
(erp_sale, write_off, supplier_return, adjustment, transfer_out). shortfall = min(fall,
−available); dedupe `<kind>:<actor>:<idem key>:<wh>:<sku>`. Excluded: holds (`reserve`: a live
site's reserve is refused when short; a shadow site's hold is not a sale) and the commit path, which
flags its own (D33, R7). The policy is read without a lock (setPolicy locks the balances first).
Tests: `OversellFlagTest`.

**R7. An unheld sale CW would have refused is always flagged.** In `applyCommit`, on a `live`
channel, units allocated without a hold on a `stopped` item give `stopped_sale` (shortfall = those
units) and on a quarantined listing of a non-legacy item `quarantined_sale`, whatever `available`
is. Otherwise D33 applies unchanged. One event per (order, balance).

**R8. A legacy line is never refused, even on a quarantined listing** (§2.3). The reserve refuses a
quarantined listing only when the item's policy is not `legacy`, and the view reports `legacy` for
it (CW writes nothing to the site for legacy lines, §6.5). As soon as the item is protected again
the listing is refused and reads `stopped`. Test: `ReservationTest`.

**R9. A release without `attempt` means attempt 1**, the only attempt a site can fail to know: a
delayed re-send of the first release can no longer drop a later attempt's hold (it is
`stale_attempt`). A site that lost a later reserve's answer and releases without the field leaves
the hold to the TTL (the backstop). Connector contract: always send the attempt from the reserve
answer. Tests: `ReservationTest`, `ApiReservationsTest`.

**R10. unship: `at` is required over HTTP and must be after the dispatch.** The plan's shared
payload `{unit_ids, dispatched_at}` (§3) made `dispatched_at` on unship ambiguous (dispatch time vs
reset time); a connector reversing a ship with the ship's payload turned a reset after a count into
a pre-count one (unit back on the shelf, on_hand never rose). The alias is dropped: `unship` takes
`at` (400 `at_required` without it, 400 `bad_request` with `dispatched_at`), and an `at` not later
than a unit's stored `dispatched_at` is 400 `bad_time` (`not_after_dispatch`). Connector contract:
`ship {unit_ids, dispatched_at}`, `unship {unit_ids, at}` (read §3 this way).
Tests: `EventTimeTest`, `ApiReservationsTest`; the hammer's scenario 3 now resets 30 s after its ship.

**R11. Purchasing `counted_at`** = the latest `stock_balance.counted_at` over sellable warehouses
(the count writes that one; `sku.counted_at` stays the count gate's marker, D2, and nothing writes it
yet). Tests: `CountingTest::testPurchasingShowsWhenAnItemWasCounted`, `ApiResourcesTest`.

**R12. T0 watermarks (§8.1) are recorded by `POST /v1/opening_orders`.** Optional
`t0: {at, last_order_id, last_stock_log_id|null}` (null: a site without the ERP stock feed) on any
batch; stored once in `channel_opening`; other values later → stored 409 `t0_mismatch`; the final
batch is refused with 409 `t0_required` (not stored) while none is recorded. Test:
`OpeningOrdersTest::testT0WatermarksAreRecordedOnceAndTheFinalBatchNeedsThem`.

**R13. The count's known gap is flagged, not silently widened.** The count rule stays §8.2's (ships
only). Any other on_hand ledger row with `effective_at` after `counted_at` opens a `count_review`
(`count_after_movements`, dedupe per location+item+counted_at) listing up to 50 of them and their
net, for a person to settle. The cancel-to-VERIFY transfer rows now carry `effective_at` (booking
time, D45) so they are seen too. Why not add their net like ships: whether the counter saw a
goods-in booked mid-count is unknowable, so a person decides (open item kept for Phase 7).
Test: `CountingTest::testACountOverwritingMovementsBookedAfterCountedAtOpensAReview`.

**R14. Times must be real calendar times.** `Clock::parse` refuses 2026-02-31 (PHP rolled it over to
3 March), hours > 23, minutes/seconds > 59, offsets beyond ±14:00, and anything outside years
1–9999 in UTC (`9999-12-31T23:59:59-01:00` gave a strict-mode 500 on `effective_at`).

**R15. At most 1,000 units per order** (`Reservations::MAX_ORDER_UNITS`): reserve, commit, each
opening order and each per-unit call (unit_ids). Refused with 413 `too_many_units` before any lock,
nothing stored. Each unit is a row, an UPDATE and a ledger row under the item's balance lock; a
45,000-unit commit held one SKU for 154 s (every other site's call on it hit the 120 s lock wait,
and php-fpm's 150 s limit would have rolled it back for ever). A smaller body limit for the
reservation routes was considered and not added: the unit cap bounds the work, and the 8 MB body
check still bounds parsing. The opening batch cap stays 20,000 units per call (D40).

**R16. Values beyond their column are 4xx, not 500.** `Stock::apply` refuses a delta or a resulting
bucket outside INT with 422 `out_of_range` (covers qty × u, summed counts, cumulative on_hand);
`line_index` and `attempt` are limited to INT UNSIGNED (400); a listing price is rounded to 2 dp
before the ≤ 99999999.99 check (400 `bad_listings`: it applies per push, as before); times via R14.
Tests: `InputRangeTest`, `ApiReservationsTest`.

**R17. Least privilege for `POST /v1/movements`.** New `channel.movement_types` (JSON array, default
`[]`: fail closed). A site may send a movement only if its type is one of the ERP-relay types
(`Movements::CHANNEL_TYPES` = goods_in, supplier_return, erp_sale) AND listed for its channel;
otherwise 403 `movement_not_allowed` (not stored). Counts, adjustments, write-offs and transfers are
staff-only (/ui, session + TOTP), whatever a channel row lists; ERPNext's reconciliations go to the
count-review queue (§9), never straight to a count. Grant with `bin/create_channel.php
--movement-types=goods_in,supplier_return,erp_sale` (validated by `ChannelAdmin::movementTypes`);
changing it later is SQL for now, like the allowlist and mode. Line identifiers are not restricted:
the relaying site names items by its listings or by ERP item code, and sku ids gain it nothing more.
Tests: `MovementsTest`, `ApiResourcesTest`, `ApiChannelToolsTest`.

**R18. The API's log directory is rotated.** `deploy/staging/logrotate-cw-api.conf` (size 20 MB,
10 kept, copytruncate, as www-data) is installed by `install_api.sh` as
`/etc/cw/logrotate-cw-api.conf` and run hourly from `/etc/cron.d/cw-api-logrotate` with its own
state file, because an unauthenticated caller can add one log line per request and the daily
logrotate run is too far apart. Installed on staging 26 Sep 19:15 UTC. Not done: sampling the
unauthenticated auth log lines (optional in the review). Carry the rule into the production vhost.
Test: `tests/Unit/DeployTest.php` (the file and the installer line).

Open after the review fixes:
- `0003_review_fixes.sql` is not applied to `cw_staging` (the cron's schema): the cron copy
  (`/opt/cw-staging`) still runs the 0002 code. Apply both together with
  `scripts/remote.sh hammer bash deploy/staging/install_cron.sh --migrate` once this change is
  accepted. Another workstream adding a migration must use `0004_*` or later.
- ~~The DecisionService (not built) must call `adoptUnlinkedUnits` inside every link transaction
  (R4).~~ Done: `src/Mapping/DecisionService.php` calls it in every link transaction (M3, M4). A
  periodic sweep calling it for linked listings with unlinked held/allocated units would still be
  a cheap safety net.
- The connector contract changes: final opening batch after all others answered 200 and with `t0`
  (R1, R12); release always with its attempt (R9); unship with `at` (R10); the ERP-relaying site
  gets `--movement-types` (R17).

## Linking backend (slot `dec`, 30 Sep 2026)

Code: `src/Mapping/{DecisionService,ListingIngestService,Proposals}.php`, `src/Staff/*`,
`bin/{import_listings,mint_vpg,import_proposals,create_staff}.php`; schema
`migrations/0004_matching.sql`; tests `tests/Integration/Mapping/`, `tests/Unit/StaffSecretsTest.php`,
new cases in `GrantsTest`. Sources: plan §7.1 (which wins), §2.2, §11; the matching design record
(workflow `wf_761ea40a-40e`, finalize+critic: A.1, A.4, A.9), adapted to v1 as below.

**M1. Tables, and append-only without triggers.** `match_run`, `match_proposal`, `match_decision`,
`listing_map_history`, `match_reject`, `alias`, `staff_session`, `login_attempt`, plus columns on
`staff_user`, `sku` (`origin`, `origin_listing_id`, `merged_into_sku_id`) and `listing_profile`
(`features`, `features_version`). The design's triggers (A.4) are not used (D25: no DELIMITER, and the
plan says no triggers); the guards are the grant model (M16), the code (only DecisionService writes a
link: `grep` finds no other writer of `channel_listing` in `src/` or `bin/`) and constraints:
- stored generated columns with UNIQUE keys: at most one `open` proposal per listing
  (`match_proposal.open_listing_id`), one `pending_second` decision per listing
  (`match_decision.pending_listing_id`) and one open link period per listing
  (`listing_map_history.open_listing_id`);
- CHECKs: a decision other than `suggest` names its decider; `applied` ⇔ `applied_at`; an applied
  decision's `second_by` differs from `decided_by`; a `link` names item and u; a merge names two
  different items; a closed period names the decision that closed it.

**M2. Staff (design A.9, one role each).** `staff_user.roles` (JSON) became `role` ENUM
`viewer | mapper | mapping_lead | warehouse | manager | admin` (nothing used `roles`).
`totp_secret` became `totp_secret_enc`: the base32 secret sealed with libsodium secretbox
(XSalsa20-Poly1305, nonce ‖ box) under `ui_secret_key` (32 random bytes, base64) in
`/etc/cw/app.env` — outside the web root and git (§11), readable by php-fpm (it must check codes), never
printed. Passwords: `password_hash(PASSWORD_ARGON2ID)`, `password_must_change = 1` for the one-time
password. `staff_session` keeps sha256 of the session token (the cookie holds the token), `mfa_at`
(TOTP passed), ip, sha256 of the User-Agent and `revoked`. `login_attempt` (no FKs, like `audit_log`)
is for per-ip and per-login rate limiting. The login screens themselves are not built yet (`CW\Staff\Totp`
already verifies codes with a ±1 step window and a used-step guard).

**M3. Decision actions.** One transaction each (`DecisionService::decide`), every one audited
(`mapping.<action>`, plus `mapping.approve` / `mapping.withdraw` / `mapping.propose`):

| Action | Listing afterwards | Also |
|---|---|---|
| `link` (sku, u) | `mapped`, sku, u, map_version + 1 | closes the open history period and opens one; settles the open proposal (`decided`); `adoptUnlinkedUnits` (R4); feed row `link` |
| `new_item` (u, card) | as link, to an item minted from the listing (M9) | the same |
| `unlink` | sku NULL, u 1, `suggested` if an open proposal exists, else `unmapped` | closes the period; feed row `link` |
| `ignore` | `ignored`, sku NULL | closes the period if linked; settles the proposal; feed row |
| `reject` (sku) | unchanged | `match_reject` (M8); the proposal stays open |
| `suggest` | `unmapped` → `suggested` (needs an open proposal) | feed row `status` (the view's `link` field changes) |
| `merge_skus` | M10 | |

`mintAndLink` (the Vape and Go seed, M14) mints an item and records an applied `link` decision in the
same transaction. A no-op (`link` to the current item and u, `ignore` of an ignored listing, `suggest`
of a non-unmapped one) is 409 `no_change` and records nothing. Results carry the decision id, state,
the listing's new status/sku/u/map_version, `needs_second` and the units adopted.

**M4. Lock order of a decision:** reservation rows (link outcomes only: the reservations of the
listing's unlinked held/allocated units, sorted by order_ref, read before the listing lock) →
`channel_listing` X → `sku` rows S in id order (X for the item a merge folds away) → `stock_balance`
(adoption) → feed clock. The sale path takes its reservation, then the listing FOR SHARE (R4), so a
link transaction must not hold the listing while waiting for a reservation: pre-locking removes the
R4 deadlock between a link and a sale re-attempting an order with unlinked units of that listing. The
item rows are read FOR SHARE so a concurrent `setPolicy` (balances → sku X) cannot slip a legacy →
strict change under a one-person link; that pair can deadlock (sku S before balances here) and is
retried by `Db::transaction` (rare: policy changes are staff actions). A merge locks every listing of
the folded item (id order) before the item rows. Every decision, history, proposal and audit row is
written before the feed clock (adoption's `flush`, then `Stock::listingChanged`), so nothing waits on a
`staff_user` FK while holding the clock.

**M5. Optimistic concurrency (design I7).** Every decision carries `expected_map_version`; a
mismatch is 409 `map_version_conflict` (detail: current version, status, sku) and nothing is written.
Two people confirming the same listing: one commits, the other waits on the row lock and gets 409
(`DecisionServiceTest`, forced and truly simultaneous). While a decision is `pending_second` no other
decision on that listing is taken (409 `pending_second_exists`); approving it re-checks that the
listing is still at the version the decider saw (else 409: withdraw and decide again). Round 2 adds the proposal (M19) and the
identity (M20) the person saw.

**M6. Two-person rule (plan §7.1; design A.9 rule 4 reduced to the plan's list).** Stored
`pending_second` with the reasons in `needs_second`: `protected_sku` (a link to, or a link/unlink/
ignore/new_item away from, an item whose `sell_policy` <> legacy), `units_per_item` (u <> 1),
`merge` (every merge_skus; since M31 only a mapper's, with `counted_item` for a counted item, M39 for the merge family) and `previously_rejected` (M8). A **different** `mapping_lead` approves
(`approve`: applies it, `second_by`, `applied_at`) or withdraws it; the decider may withdraw their own.
`second_by` records whoever settled it; the withdrawal time is in `audit_log` (the only mutable columns
are `state`, `applied_at`, `second_by`). A pending `new_item` mints its item only on approval, so its
decision row keeps `sku_id` NULL; the item is in `listing_map_history` and the audit row. Not built:
veto overrides and quarantine decisions (the vetoes and quarantine flows are not part of this slice). Round 2 adds changes of a
listing linked with u <> 1 (M21) and rejects across merges (M22).

**M7. Roles.** `mapper` and `mapping_lead` decide; `viewer`, `warehouse`, `manager` and `admin` get
403 `role_not_allowed`. A listing whose open proposal is in the Conflict band — named in the request
or not — is decided (link, new item, ignore, reject, unlink, merge) by a `mapping_lead` only. Bulk
decisions (`bulk_batch_id`, `mintAndLink`) are `mapping_lead` only (the design gives bulk rights to
managers; v1 keeps mapping in mapping roles). A system caller (`Caller::system`) may only `suggest`;
a site caller never decides. Inactive or unknown staff: 403 `staff_not_allowed`.

**M8. Reject.** `match_reject(listing, sku)` is append-only and unique; a second reject of the pair
adds a decision row, not a second reject row. The proposal stays open for another choice. Rejecting
the item the listing is linked to is 409 `reject_current_link` (unlink instead). There is no
"un-reject" (append-only): a later link to a rejected item is allowed but needs a second person
(`previously_rejected`).

**M9. The new item's identity card** (`DecisionService::card`, `cardFrom`): name = the listing's
variant title, else product title, else the features' title; brand = profile brand, else features'
`brand_raw`; strength, nic type, form, ml, puffs, pack from `listing_profile.features` (the rules-only
Normalizer output); `line` = the distinct line tokens, numbers and modifiers; `flavour` = the flavour
tokens. The reviewer's typed values (`card`) override any field (null clears it); values outside the
column ranges are 400 `bad_card`; no name is 422. `sku.origin` = `new_item` (or `vpg_mint`),
`origin_listing_id` = the listing. The count gate confirms the card later (plan §2.1).

**M10. merge_skus (v1).** The request names a listing linked to the item being folded away (the
anchor; the VPG duplicate proposal's listing), the item kept and the item merged. Both items must be
`legacy` — a protected item's counted stock would have to move with it, which needs the recount
flow (409 `protected_merge` until then). On approval every listing linked to the merged item moves to
the kept one (same u and status; one history period and one feed row each), the proposal is settled
and `sku.merged_into_sku_id` is set; a merged item can never be linked again (409 `sku_merged`).
Units sold before keep their sale-time item, like any relink; the merged item's buckets stay where
they are (legacy estimates, settled by the kept item's count). Items are never deleted.
**Amended by M31-M33 (the owner, 6 Oct 2026):** a mapping lead merges two uncounted legacy items alone; the merged item's available
stock moves to the kept item (what its units in flight need stays); a wrong merge is undone for one listing by a `split`.

**M11. `ListingIngestService`** replaces `CW\ListingProfiles` (same code path for `PUT /v1/listings`
and `bin/import_listings.php`; the API answers are unchanged — its HTTP tests' listing cases also run
in process in `ListingIngestTest`). New listing rows come only from
`DecisionService::createUnmappedListings` (INSERT IGNORE of the missing variants), which
`Reservations` now also uses for sales of unknown variants (same statements as before). A push that
changes a listing's identity clears its stored `features` (they described the old titles). An export's
attribute list `[{attr_id, name, value, is_variable}]` is stored as `{"items": [...]}`: exactly what
the Normalizer reads (is_variable decides between values); the connector should send the same shape.

**M12. Features.** `listing_profile.features` holds the rules-only Normalizer output of a listing
(`features_version` = normalizer version). The bootstrap tools copy it from the run files
(`listings_features.jsonl`, minus the run bookkeeping `cw_id` / `in_seed` / `in_scope`): mint_vpg for
every Vape and Go line, import_proposals for the listings it proposes for. After go-live the matcher
writes them.

**M13. Proposals** (`CW\Mapping\Proposals`). `match_run` is unique on (run_id, source) and
append-only. One proposal per (run, listing); recording it for a listing with an open proposal of
another run supersedes that one; recording it again is `exists` (the tools are re-runnable). All of it
runs under the listing's row lock, then `suggest` moves an unmapped listing without a pending decision
to `suggested`. The run's band `Manual (relabel)` is stored as `Manual` (the ENUM of the task) with the
original in `evidence.band`. `CWP-<vpg id>` becomes the item minted for that Vape and Go listing; the
private ref maps add every candidate the judge saw (item, role, prescore, rank, vetoes, soft flags) to
`evidence.candidates`, so the review screen can offer alternatives without the run files. `flags` is
the sorted list of lane flags, target soft flags and vetoes, key blockers, `relabel_pending`,
`two_person_confirm` and `target_not_minted`.

**M14. The Vape and Go seed (`bin/mint_vpg.php`).** Seed = features lines of `vapeandgo` with
`in_seed = true`, `variant_status = Published` and not a placeholder (14,856 in run2 = the export's
published non-landing count). One transaction per listing (`mintAndLink`): the feed clock is held
per listing, not per batch. `bulk_batch_id` defaults to `vpg_mint:<run dir>`; `--staff` must be an
active mapping_lead; `--dry-run` writes nothing (no features, no run row). Linked listings are skipped,
so a re-run mints nothing twice. Duplicate groups (`vpg_duplicates.jsonl`): the member with the most
30-day units (then the lowest id) is the keeper; every other member's listing gets an open proposal
(run `<run dir>-vpg-duplicates`, source `vpg_duplicates`, band Manual, lane `vpg_duplicate`, proposed
item = the keeper's) — a merge suggestion for two people (M10); nothing is merged. Run2's first group
(Nic Nic 100% VG vs 70VG/30PG) shows why a person must look.

**M15. `bin/create_staff.php`** prints `password=` and `otpauth=` lines on stdout once (messages on
stderr), stores username = e-mail (lower-cased), the argon2id hash with `password_must_change = 1` and
the sealed TOTP secret, and audits `staff.create` with e-mail and role only. When app.env has no
`ui_secret_key` it generates one and adds it in place (other lines kept, file mode/group kept, atomic
rename; `CW\Staff\AppEnvFile`). An existing e-mail is refused; an existing account is recovered with `bin/reset_staff.php` (U23).

**M16. Column-level grants.** `CW\Schema\Grants` now also converges `mysql.columns_priv`: the app
login gets SELECT, INSERT on `match_run`, `match_reject` (APPEND_ONLY) and on `match_proposal`,
`match_decision`, `listing_map_history` plus UPDATE of exactly `status`; `state`, `applied_at`,
`second_by`; `valid_to`, `closed_by_decision_id` (UPDATE_COLUMNS). A table-level `REVOKE UPDATE` also
drops that table's column grants, so column grants are read after the table-level pass. Checked with a
throwaway login in `GrantsTest`, which also runs the whole linking flow (intake, proposal, suggest,
reject, adoption, pending + approval, withdrawal, new item, mint, merge, unlink, ignore) with exactly
those rights.

**M17. `import_listings` drops an unusable barcode, not the listing.** The real Vape and Go export
(29,105 variants) has 5 listings whose barcode field holds text, not a code (`Black Grey`, `85104 - 1`,
`85180 - 1`, `85186 - 1`, `85190 - 1`). `PUT /v1/listings` refuses any barcode that is not a printable
code without spaces of at most 64 characters, so the whole listing was skipped and the import exited 1.
The importer now removes exactly the barcodes those checks would refuse, ingests the listing with the rest,
counts them (`barcodes_dropped`, `listings_with_dropped` in the summary line) and lists the first 20 on
stderr. The API is unchanged: it still answers 422 for such a barcode. The first-match features file has
no barcodes for these 5 variants either, so the matching run never used them. A later connector that feeds
`PUT /v1/listings` from the same source must clean barcodes the same way (or accept the 422 per listing).
Covered by `ImportToolsTest::testImportListingsDropsUnusableBarcodesButKeepsTheListing`.

Open after the linking backend:
- `0004_matching.sql` is applied to `cw_staging` and `/opt/cw-staging` runs the matching code (30 Sep 2026);
  `ui_secret_key` is in staging's `/etc/cw/app.env`. The first-match data is loaded (docs/ops.md, "First-match
  data on `cw_staging`").
- The load ran under a placeholder `mapping_lead` (`mapping-lead-placeholder@cw-staging.invalid`, staff id 2),
  because `mint_vpg` needs a mapping_lead (M7) and the admin gets 403 for mapping decisions. Replace or
  deactivate it before real people use the review UI; the 14,856 link decisions carry its id.
- Channels have no allowed IPs, so every API call is refused until `bin/create_channel.php --ips` is run.
- Not built here (the review/login UI and its HTTP routes were built afterwards: next section): quarantine and veto-override decisions, the count gate, `sku_barcode` seeding from the
  minted listings' barcodes (design S3; `listing_profile.barcodes` has them), `sku_erp_item` seeding,
  the StrictReadiness check before a policy change. Both tables are still empty on staging.

## Staff UI (slot `ui`, 30 Sep 2026)

Code: `src/Auth/`, `src/Ui/` (controllers, templates in `src/Ui/views/`), `public/index.php`,
`public/ui/assets/{app.css,app.js}`, `deploy/staging/{install_ui.sh,*-ui.conf,*-https.conf,*-acme.conf,
*-hardening.conf,php-fpm-cw-web.conf,logrotate-cw-{ui,web}.conf,enable_https.sh}`; tests
`tests/Integration/Ui{Auth,Security,ReviewFlow}Test.php` (slot `ui`, over HTTP), `tests/Unit/Ui{Unit,Templates}Test.php`,
`tests/Unit/{StaffSecrets,Deploy}Test.php`. Sources: plan §7.1 (wins: one person at a time, preselection; two people only
for a protected item, units per item other than 1, merges), §2.2, §11; the matching design record (A.1, A.4, A.9).
`DecisionService` semantics are unchanged: every rule below is about what the screens do with its answers.

**U1. Sign-in and the limiter.** One form: e-mail, password, 6-digit code (`CW\Auth\Login`). An unknown account, a wrong
password and a wrong code give the same answer and cost the same (a dummy argon2id verify). `LoginLimiter` is a rolling
window over `login_attempt`: 10 failures for one e-mail or 30 for one address in 15 minutes refuse sign-in (plan §11: 10
failures, 15 minutes; the address limit is higher because an office shares one). Refused attempts are not recorded, so
hammering cannot extend a lock; a successful sign-in resets the account's count. Tradeoff: anyone who knows a staff e-mail can
lock that account for 15 minutes (accepted for v1; the address limit and `audit_log` show it). `staff_session.ip` and
`user_agent_hash` are stored but not enforced (a phone moving between networks would be signed out). The count and the charge are
one step under named locks since round 2 (U21).

**U2. Sessions.** `staff_session.id` is the sha256 of a random 256-bit token; the cookie holds the token only
(`HttpOnly; SameSite=Strict; Path=/ui`, `Secure` when the request is HTTPS). Live while idle < 30 min and age < 12 h, not
revoked, MFA done. Every sign-in creates a new session and revokes the one the browser presented. Logout is POST-only and
revokes. A code is used once (`staff_user.totp_last_step`). `audit_log`: `login.ok`, `login.fail`, `logout`. The password
change is forced while `password_must_change` is set (the only reachable routes are `/ui/password` and `/ui/logout`); a change
rotates the session (U22).

**U3. CSRF.** Every POST needs a token (HMAC-SHA256 under a key derived from `ui_secret_key`, never the key itself). A
signed-in form's token is bound to the session id (stable within a session, worthless in any other, dead after logout or
rotation). Public forms (the sign-in) always use the pre-login token bound to the `cw_pre` cookie, even when the browser already
holds a session (a second tab): that POST replaces the session. Before the token, a POST that says it came from another site
(`Sec-Fetch-Site`, `Origin`) is refused; absent headers fall back to the token and SameSite=Strict. A refused form answers 403
`csrf` with a plain "reload the page" message.

**U4. TOTP.** The task named `src/Auth/Totp.php`; `src/Staff/Totp.php` (M2: RFC 6238, SHA-1, 6 digits, ±1 step, used-step
guard, constant-time compare) already existed and is used instead. Vectors (RFC 6238 appendix B incl. T=20000000000) and the
window edges are in `StaffSecretsTest`.

**U5. Headers on every answer, assets included.** CSP `default-src 'self'; base-uri 'none'; form-action 'self';
frame-ancestors 'none'` (the last two additions close base-tag and form-target tricks that `default-src` does not cover),
`nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin` (was `no-referrer`, U24), a restrictive `Permissions-Policy`, COOP/CORP `same-origin`,
`Cache-Control: no-store` (assets: `no-cache` + ETag, so a deploy shows at once), HSTS only over HTTPS. `Assets::serve` wraps the
asset answer in `Kernel::secure`, so a 404 or 304 for an asset carries them too. Error pages (403, 404, 405, 500, 503) are
hardened the same way and never show an exception message, a host name or a path (the request id finds the log line).

**U6. What the queue lists.** Open proposals whose listing is `unmapped` or `suggested` and has no `pending_second` decision
(those are in the second-approval queue). Order: units in 30 days, then 365 days, then id (best-sellers first), 50 per page
(changed by U19: 365 days first).
Filters: channel, lane, minimum 30-day units, free text (title, variant title, brand, variant id, barcode). The filters travel
in the URL and in hidden fields, so the page after a decision is the next item of the same filtered queue. Listings with no
proposal are counted on the dashboard ("No proposal yet") but not queued: there is nothing to confirm.

**U7. Dashboard coverage.** Per site: listings, linked, units in 30 and 365 days and the linked share. The denominator is ALL
units sold (ignored listings included) so the percentage answers "how much of what we sell is linked"; ignored units are
shown in their own column. A site with no sales shows `-`, not 0%.

**U8. One decision form.** Radios: Confirm link, Mark as a new item, Ignore (a reason is required), Reject this proposal; one
"Save decision". Band Key preselects Confirm link; every other band preselects nothing and needs a choice. "Choose other
item" is search plus the candidate links, which set `?pick=<sku>`: the page re-renders with that item as the target. A pick
that does not exist, is merged away or is not usable falls back to the proposal and says so; the heading then reads "Proposed
item", never "Item you picked" (`ReviewController`, `target_is_pick`). Units per item other than 1 shows the two-person note
(the note is in the page, hidden while the value is 1; `app.js` only toggles it as the value is typed).

**U9. Reject.** Applies to the item shown as target, writes `match_reject` and leaves the proposal open (DecisionService).
After a reject the page stays on the same listing when it is the only item left in the queue (otherwise it would redirect to
itself); otherwise it goes to the next. Any later link of a rejected pair needs two people (`previously_rejected`).

**U10. After an action.** Go to the next listing of the queue (after the current one in order, else the first other one), with
a one-line notice naming what happened. Last in the queue: "queue done", except that a decision that became
`pending_second` shows the pending notice instead. (A last-in-queue ignore or new item shows "queue done", not its own notice.)

**U11. Two people.** Links touching a protected (non-legacy) item, units per item other than 1 and merges wait for a second
person. The waiting list (`queue=pending`) shows who decided and why it waits. Approve: `mapping_lead` only and never the
decider (the decider sees "Waiting for another mapping lead."; a mapper sees "Waiting for a mapping lead."); the approval
takes an optional note. Withdraw: the decider or a lead. Roles without `canDecide` (viewer, warehouse, manager, admin) see
listings but get no form ("Your role (...) can look at listings but not decide them."); a Conflict proposal shows a mapper no
form either ("only a mapping lead"): that rule is `DecisionService`'s (403 `lead_required`), the screen only hides the form,
and the POST route itself needs `mapper` or `mapping_lead` (`Route::DECIDE`).

**U12. Unlink is not in the UI.** `DecisionService` supports it, but the screens in the brief have no unlink action, so it is
not offered (it stays a backend action; the tests call it directly). Merges and their undo (split) have a screen since M34
(Duplicates).

**U13. The pending badge** in the navigation shows the waiting count to every signed-in user (everyone sees that work is
waiting; only the roles that may act get buttons, U11).

**U14. Stale forms.** The form carries `expected_map_version`. If the listing changed meanwhile (someone else linked it), the
decision is refused, the page is re-rendered with the new state and the person's choices kept, with the `CwException`'s HTTP
status, never a silent overwrite.

**U15. Search.** `/ui/search` and the listing page's box: one character runs no query; a CW code (`CW-000123`, `123`), a
barcode (6-64 digits) or up to six words (title, brand, flavour) find items; listings are found by variant id, title or brand.
Result lists are capped (`Queries` limits).

**U16. Templates never print raw.** Templates get only `$e $n $dec $dt $u $pct $partial` (and `$body` in the layout) and
`UiTemplatesTest` fails the build otherwise, for inline script, style, event handlers, third-party references and POST forms
without the CSRF field. Found while testing: `preg_match('/^...$/')` accepts a trailing newline (`UiRequest::id("1\n")` was
1); every anchored pattern in `src/Ui` and `src/Auth` now has the `D` modifier.

**U17. Deployment.** The UI test copy is a second name-based vhost on the API's loopback listener (`Host:
cw-ui.staging.invalid`, pool `cw-ui`, schema `cw_test_ui`), so the API tests in slot `api` are unaffected and no second `Listen`
is added. The public HTTPS vhost for `warehouse-staging.floverfy.com` (UI + API, pool `cw-web`, `/opt/cw-staging`, real schema)
and its port-80 ACME vhost are in the repo and OFF: only `enable_https.sh`, run by a person, installs them, and it refuses
until DNS resolves to this box only, the code and `cw-ui` exist and `app.env` names `cw_staging`. The record must be DNS-only
(not proxied): `REMOTE_ADDR` is the only client address the app trusts (`mod_remoteip` stays off).

Open after the UI:
- Nothing serves `cw_staging` over HTTP until `enable_https.sh` is run (needs the DNS record and TCP 80/443 open); the UI
  has only been exercised against `cw_test_ui`. `app.js` was reviewed by eye only (no JavaScript runtime on either machine);
  the forms work without it.
- Staff accounts for real people do not exist yet (`docs/ops.md`, "Staff accounts"); the placeholder `mapping_lead`
  (staff id 2) still carries the 14,856 seed decisions.
- Not built: unlink and merge screens (U12), quarantine and veto-override decisions, a screen to manage staff (use
  `bin/create_staff.php` and `bin/reset_staff.php`, U23), per-session device list. (The recovery "an admin re-creates the
  account" never worked for anyone who had decided something; `bin/reset_staff.php` replaced it, U23.)

## Review fixes, round 2 (slot `fix2`, 30 Sep 2026)

A review of the linking backend and the staff UI (lenses: security, linking rules, data) found the defects below. Each fix
has a regression test; the review's probes (`tests/Review/`) were moved into the suites and the directory deleted:
`DecisionServiceTest` (linking), `GrantsTest`, `tests/Integration/UiKernel/{ApprovalScreens,PasswordChange,ReviewEvidence}Test`,
`tests/Integration/Auth/LoginLimiterRaceTest`, `tests/Integration/Staff/StaffResetTest`, `tests/Integration/Mapping/BarcodeSeederTest`,
`UiUnitTest::testBrandsSpeltDifferentlyAreNotConflicts`. The `UiKernel`, `Auth` and `Staff` tests drive the real `/ui` kernel
in-process as `cw_app` (`tests/Support/{KernelBrowser,KernelUiTestCase}.php`), so they run in every slot, not only in slot `ui`.
Schema: `migrations/0005_listing_barcodes_index.sql` (applied to `cw_staging`, M25).

**M18. What the second person approves is what the screens show (amends U11).** While a decision waits, the listing page's item
card, side-by-side comparison, barcodes and heading ("Item this decision links to") are those of the item the DECISION links to
(`?pick` is ignored), never the proposal's. A waiting `new_item` shows the identity card it will mint (`match_decision.detail.card`,
field by field) on the listing page ("New item this decision creates") and in the second-approval list (partial `pending_decision`);
a waiting merge names both items. Both screens flag a stale decision (M19, M20) and approving it is refused.

**M19. The proposal a person saw (design I7; amends M5).** A decision on a listing that has an open proposal must name it: 409
`proposal_changed` (detail `open_proposal_id`) otherwise, checked after the Conflict gate. The system `suggest` names it anyway
(`Proposals::add`). `approve()` refuses (409 `proposal_changed`) when the listing's open proposal is no longer the one the decision
named (a later run superseded it, or one opened on a listing that had none; `Proposals::add` does not change `map_version`), so a
superseded decision is never applied and the new proposal (e.g. a Conflict) is never settled unseen. A decision settles only the
proposal it named (`settleProposal(id)`), never "whatever is open".

**M20. The identity a person saw (design I7): option A.** `ListingIngestService` moves `channel_listing.map_version` on (through
`DecisionService::identityChanged`, keeping the single writer of `channel_listing`) for every listing whose `identity_hash`
changed (titles, brand, attributes, barcodes), or which gets its first profile, and which existed before the push. The existing
`map_version` checks then refuse a form drawn before the change and the approval of a `pending_second` decision taken on the old
titles (a u = 10 link approved after the site renamed the variant to a 5-pack). A price or sales change is not an identity change.
Rows created by the same push start at 0 (nobody has seen them). Option B (store `expected_identity_hash` on `match_decision` and
compare it) was not taken: it needs a new column and a new field in every caller, and gives no more than A, whose version is not
exposed to the sites (the feed carries its own versions, D38). The ingest audit row counts them (`identity_changed`).

**M21. Two people for multiples, both ways (amends M6).** Besides a target u <> 1, any decision on a listing linked with u <> 1
(verified by two people) needs `units_per_item` in `needs_second`: a relink (to u = 1 or to another item), `new_item`, `unlink`,
`ignore`. A merge moves listings with their u and already needs two people.

**M22. Rejects survive merges (amends M8, M10).** `match_reject` counts for an item's family (the item and every item merged into
it, recursively): a link of a listing to an item it rejected, or to an item such an item was merged into, needs
`previously_rejected`. A merge that would contradict a reject is refused, 409 `rejected_pair` (detail `listing_ids`): a listing
of the merged item that rejected the kept item's family, or a listing of the kept item that rejected the merged item's family.
Checked at the decision and again at the approval; a person relinks or unlinks those listings first. The screen's "rejected
before" note uses the same family (`Queries::rejectedOf`).

**M23. The app login deletes no listing, item, profile or staff account (design A.1 I1; amends D23, M16).** `Grants::NO_DELETE`
(`channel_listing`, `listing_profile`, `sku`, `staff_user`): SELECT, INSERT, UPDATE. Nothing in `src/` or `bin/` deleted them;
`reservation_unit.listing_id` has no FK, so a listing that only ever sold while unlinked could have been deleted from under its
holding-ledger units. `bin/setup_staging.php` probes the DELETE refusal; converged on `cw_staging` by the 0005 migration run.

**M24. A relink with units in flight queues a recount (design A.9 rule 5, A.12 "Reversal").** When a link changes (another
item, another u, unlink, ignore, new item) and units of the listing are held or allocated on the old item, or were shipped
from it under the closing link (sold since the period's `valid_from`, or dispatched since), and either item is counted
(`sell_policy` <> legacy), `count_review` rows are opened on both items (source `remap_correction`, per warehouse of the units,
dedupe key `remap:<decision>:<sku>:<warehouse>`, detail: decision, history period, unit ids and states, central units) through
`Stock::openCountReview`, before the feed clock (M4). The units keep their sale-time item (I14). Legacy -> legacy queues nothing
(uncounted estimates). Merges are legacy-only (M10), so they never queue one. Not built: the paired `remap_correction`
movement (-q on A, +q on B) after the recount: that is the count gate's (plan: later).

**M25. `sku_barcode` is seeded from the listing each item was minted from (design S3).** `CW\Mapping\BarcodeSeeder`
(`bin/seed_barcodes.php`; `bin/mint_vpg.php` runs it for what it mints): the usable GTINs of the origin listing's profile
(`CW\Matching\Gtin::classify`: 8-14 digits, valid check digit; so "Black Grey", short shop codes and URLs never enter), stored as
the GTIN key (no leading zeros; search accepts a scanned code with them). Idempotent; a key already on another item is not
added and the existing row is marked unusable (`is_usable = 0`, note "also on CW-..."), never moved. Until an item is seeded
the screens fall back to its origin listing's usable GTINs (`Queries::barcodesOfMany`; item page source "listing it was minted
from"). On `cw_staging` (30 Sep 2026): 14,856 items, 11,299 with a usable GTIN, 13,082 rows added (3 keys on two items, marked
unusable), 472 unusable codes skipped; afterwards 873 of the 936 Key proposals show "Barcodes: same". `0005` adds a
multi-valued index on `listing_profile.barcodes` (`CAST(barcodes AS CHAR(64) ARRAY)`), which `Queries::barcodeElsewhere` uses
(`JSON_OVERLAPS`, ~3 ms instead of a 2.3 s scan).

**U18. The evidence on the review screen (review findings, data lens).**
- The candidates table lists EVERY candidate the judge saw (up to 15, not 10), in judge order, with its ref (C1..C15, from
  `evidence.candidates[].ref`), which the AI's reason and the band reasons cite; vetoes are red "veto: ..." tags, soft flags amber.
- The AI's pick (`evidence.ai.chosen`) is always shown: an "AI picked" line (with "use this item") and a marked table row, added
  as its own row when the candidate list does not carry it (Conflict, Can't tell and Manual proposals name no item).
- "Fields that do not agree" shows `field: state` pairs (the run stores a dict), conflicts first and in red; an old list still reads.
- Flags are grouped by weight: "Blocks a link" (the target's vetoes and the AI pick's, `veto:`/`chosen_veto:` in proposals.csv),
  "Check" (soft flags, `soft:`/`chosen_soft:`), "Other flags" (lane flags, key blockers, relabel_pending, ...); AI warnings apart.
- The Manual band is named "Relabel (alias)" everywhere (`Queries::BAND_LABELS`; the URL keeps `queue=Manual`), with the run's
  own band text ("Manual (relabel)"), the rename (`relabel_pending`) and the paired items (`relabel_partners`, each with "use this
  item"). The queue tab says that aliases are not recorded on these screens (a mapping lead confirms them separately) and what to
  do with each listing. No alias decision is built (the `alias` table stays empty).
- The queue shows the AI's confidence only next to a proposal (a "85%" beside "Proposal: none" read as a match).
- Brands are compared on their distinctive words (`Compare::brandWords`: case, punctuation, shop words such as vapes, e-liquids,
  nic salts, brand, co, puff counts like 10K dropped; confirmed brand aliases applied): equal -> `same`; one within the other, a
  prefix, or the same first word -> `alike`, shown "spelt differently", not highlighted; else `differs`. On staging's Key
  proposals the brand-only "differs" fell from 305 to 40 (Check: 185 to 15). Barcodes compare on GTIN keys.
- Items minted from Vape and Go show their first-match id (`CWP-<vpg variant id>`, the id proposals.csv uses) next to the CW code,
  on the review screen, in searches and on the item page.
- "This listing's barcode is also on": other listings (any site, linked or not, with the site's variant status from the
  features, e.g. "Bin") and items holding one of the listing's barcodes (60 of the 65 New item proposals with a barcode on staging).

**U19. Queue order and lanes (amends U6).** Units in 365 days, then 30 days, then id (September's 30-day figure is inflated
by stockpiling and a promotion); the 365-day column comes first. The `vpg_duplicate` lane is no longer offered as a filter: those
165 merge suggestions sit on mapped listings, which no queue lists, and merges have no screen (U12). The dashboard counts them
("not in these queues ... nothing merges them on its own"); `docs/ops.md` says the same. **Since M34** they have their own screen
(Linking -> Duplicates), which the dashboard's note links to.

**U20. Preselection and the quick confirm (amends U8).** Key preselects "Confirm link" as before, and a "Confirm link to CW-... and
open the next listing" button (a second POST form, same fields and checks) sits above the evidence, so a Key item that checks out
needs one click. "New item" preselects "Mark as a new item" unless its barcode is on another listing or item. Rejected: a bulk
confirm of Key items (plan §7.1: one-at-a-time confirmation with preselection; bulk decisions stay mapping_lead tools). Not done:
revising the plan's "a few seconds each" (plan.md is the spec; no measured figure exists yet). **Amended by M28 (the owner,
2 Oct 2026):** a bulk confirm of Key proposals exists as a mapping-lead CLI step after a spot-check, never as a button.

**U21. The limiter's check and charge are one step (amends U1).** `Login::attempt` (and the password change's check of the current
password) runs count -> verify -> record under named locks of the account and of the address (`LoginLimiter::exclusive`,
`GET_LOCK`, schema-qualified names, account before address, 10 s wait; an attempt that cannot get them is refused like a locked
one and not recorded). Ten simultaneous wrong passwords against an account with 9 failures: one is checked, nine refused (was:
all ten checked, 19 failures). Cost: attempts for one account or from one address queue behind each other's argon2id (~330 ms).

**U22. A password change rotates the session (amends U2).** Every session of the person ends, the current one too; the browser
that changed it gets a new session cookie on the 303 (CSRF follows the new session id). A copied cookie dies with the change,
and the session opened with a one-time password never becomes the long-lived one. Audit `password.change` with `rotated: true`
and `sessions_ended`.

**U23. Staff recovery: `bin/reset_staff.php` (amends M15).** `--new-password` (a new one-time password, `password_must_change = 1`),
`--new-totp` (a new sealed seed, `totp_last_step` NULL: the old codes stop), `--deactivate` / `--activate`; every reset revokes all
of the person's sessions and is audited (`staff.reset`, `staff.deactivate`, `staff.activate`; never a secret). Secrets are printed
once on stdout, as `create_staff` does. `enable_https.sh` now refuses while `/etc/cw/initial_staff.txt` exists (one-time passwords
and TOTP seeds of staff 1 and 2 in clear) or any account with an e-mail under `.invalid` (the placeholder mapping_lead, staff 2) is
active: public, either would be a working second identity that defeats the two-person rule.

**U24. `Referrer-Policy: same-origin`, not `no-referrer` (amends U5; found 1 Oct 2026 at the first real sign-in).** Under
`no-referrer` the Fetch spec makes a browser serialise the request origin as `null` on a non-GET, non-CORS request, so Chrome
posted the sign-in form with `Origin: null` and `checkOrigin()` refused it ("cross-site form posts are refused") for every real
browser. The tests and the curl checks never saw it: neither applies a referrer policy. `same-origin` still sends no referrer
to any other site, and lets the browser send the real `Origin` to this one. `checkOrigin()` is unchanged (a sandboxed frame
still posts `Origin: null` with `Sec-Fetch-Site: cross-site` and is still refused).

Open after round 2:
- Done 1 Oct 2026: `/etc/cw/initial_staff.txt` shredded, staff 2 deactivated (and its secrets reset), the public HTTPS vhost on.
- Not built: the alias decision (U18), merge and unlink screens (U12), the remap correction movement (M24), bulk confirm (U20, rejected).

## Inventory Phase I-1 (slot `i1c0`, C0 core change)

The one reviewed change to the frozen stock core that the inventory plan calls C0 (`docs/inventory-modules-plan.md` §3):
a cost and a document link on stock movements, the per-item value sequence, and the (empty) value ledger. Numbered I1–I9
(I10–I16: roles, I17–I27: documents). Code: `migrations/0006_value_core.sql`, `src/Stock.php` (`apply`, `lock`, `flush`,
`assignValueSeq`), `src/Movements.php` (`normaliseCost`, `prepare` + `book`, `bookForDocument`, `reverseDocument`),
`src/Db.php` (`transactionSerial`), `src/Invariants.php` (7–9), `src/Schema/Grants.php`, `bin/setup_staging.php` (probe);
tests `tests/Unit/MovementCostUnitTest.php`, `tests/Integration/Stock/{MovementCost,MovementHashCompat,ValueSequence,
ValueSequenceRace,DocumentBooking}Test.php`, `tests/Integration/{ValueInvariants,Migration0006}Test.php`, new cases in
`GrantsTest`, `SchemaConstraintsTest`, `LockOrderTest`; hammer scenario 5; `tests/Support/MigrationFixture.php`.

**I1. A cost on the ledger row.** `stock_ledger` gets `unit_cost DECIMAL(14,6)` (GBP per **central** unit, 6 decimals),
`cost_currency CHAR(3)` (always `GBP` when a cost is set) and `cost_source ENUM('document','manual','estimate')`.
- Allowed only on an `on_hand` row of `goods_in`, `supplier_return`, `adjustment` (either sign), `count` and `write_off`
  (`Stock::COST_TYPES`), and refused on every other row three times over: the CHECK `ck_stock_ledger_cost`, a
  `LogicException` in `Stock::apply` (which also wants the canonical form and a known source), and 400 `cost_not_allowed`
  from `Movements` (detail `field`). Sales, ships, transfers, `erp_sale` and `trade_sale` are issues or moves: IM8 values
  them at the moving average, so a cost typed on them would only be a second, wrong opinion.
- GBP only: a foreign-currency invoice is converted on its document (I-4), and the ledger never mixes currencies.
- **Never required in C0.** The ERP relay (the sites' goods-in today) sends none, and existing callers are unchanged. The
  GRN handler (I-3) makes it compulsory on its own lines.
- **Sites cannot send a cost** (400 `cost_not_allowed`, nothing stored, the same key may be retried without it): costs come
  from CW documents or from staff, never from a site's cost field (Vape and Go's holds £1 placeholders).
- `record()` books a staff line's cost with `cost_source = 'manual'`; `bookForDocument` with `'document'`. `'estimate'` is
  reserved for the opening cost load (IM8/IM15, decision 15); no code writes it yet and `OpeningEstimate` books quantities
  only, as before (D40a).
- One canonical form (`Movements::normaliseCost`): an int 0..99,999,999, a plain decimal string (no sign, exponent, padding,
  grouping or spaces; at most 8 + 6 digits) or a finite float ≥ 0 with at most 6 decimals, stored and hashed as a 6-decimal
  string (`"1.250000"`); anything else is 400 `bad_cost`. `"unit_cost": null` counts as not sent.
- Count lines of one (warehouse, item) are summed into one row, so they must carry the same cost: 400 `cost_conflict`
  otherwise, and a line without a cost next to one with a cost is a conflict too (which one would the summed row carry?).
- How a cost is used is IM8's decision, not the ledger's: receipts at the supplied cost, issues at average; a cost on a
  write-off or supplier return is evidence for IM8, not an instruction.

**I2. A document link on the ledger row.** `document_id BIGINT UNSIGNED` and `document_line INT UNSIGNED`, indexed
(`ix_stock_ledger_document`), **no FK** (D15): the `document` table only arrives with 0008, and an FK from the hot ledger
insert would take a lock on the document row outside the §3 lock order. CHECK `ck_stock_ledger_document`: a line needs its
document, and `cost_source = 'document'` needs a document. Only `Movements::bookForDocument` and `reverseDocument` write
them; on their rows `doc_ref` is the document number (`ADJ-000001`), so the ledger reads without a join. `record()` cannot
set them: unknown request fields are ignored (A5) and never enter the canonical request. A count row summed from several
document lines has `document_line` NULL and lists the lines in its note (`counted=7 ships_after=0 lines 5,6`).

**I3. The per-item value sequence (extends D39).** Every `on_hand` ledger row, a journalled zero count included, gets one
`stock_value_seq` row: per item 1, 2, 3, ... in **commit order**, with no gap. IM8 values each item's movements in that
order. Consuming `stock_ledger` ids in id order is wrong (the finance review): ids are AUTO_INCREMENT values given at insert,
so a row of another warehouse can take a lower id and commit later, and a consumer that already passed that id skips it.
- *The brief's premise was checked and is false.* It said "assigned under the sku row lock, which is last in the lock
  order". `Stock.php` takes no `sku` lock on an on_hand change; `setPolicy` locks balances → `sku` X, `Reservations::lockSkus`
  takes `sku` FOR SHARE after the balances, and a link decision takes `sku` FOR SHARE **before** the balances (M4). X-locking
  `sku` on every on_hand change, after the balances, would put dispatch and goods-in into a cycle with every link that adopts
  units of the same item (decision: sku S → waits for balance X; ship: balance X → waits for sku X), and every `sku` FOR SHARE
  reader (reserve, commit, the policy screen) would queue behind dispatch traffic.
- *Chosen:* a dedicated clock row per item, `stock_value_clock(sku_id PK, last_seq)`, locked where the brief wanted the sku
  lock. The full order is now: idempotency claim → channel_opening → reservation rows → channel_listing (FOR SHARE) →
  stock_balance → sku rows → **item value clocks (sku_id order, in `flush()`)** → feed clock. Nothing else ever locks a clock
  row, so a holder of one waits only for a clock of a higher sku_id or for the feed clock, and neither waits back: no cycle.
- `Stock::apply` queues `[sku_id, ledger id]` of every on_hand row; the **first** statement of `flush()` is
  `assignValueSeq()`, before `flagShortfalls()` and before the early return, so ships, VERIFY moves and counts without a
  difference (which write no feed row) are numbered too. Per `VALUE_CHUNK` (1,000) items, in ascending sku_id: one
  `INSERT INTO stock_value_clock ... AS new ON DUPLICATE KEY UPDATE last_seq = last_seq + new.last_seq` (X-locks the rows in a
  global order, creates a missing one), one `SELECT` of the new `last_seq` (own writes are visible under READ COMMITTED), and
  the seq rows in multi-row INSERTs: three round trips per 1,000 items, all before the feed clock.
- *Why gap-free and in commit order:* the clock row's X lock is held to commit; a rollback restores `last_seq` together with
  the seq rows; so an item's seq n + 1 cannot be assigned until the transaction holding n has ended, and any reader that sees
  n + 1 committed sees n. Within one balance, seq order is also ledger id order (the balance lock serialises whole
  transactions, invariant 9); across warehouses it is commit order and may differ from id order (`ValueSequenceRaceTest`, and
  the hammer's scenario 5 counts it: 26 times in a 30 s run).
- **Consumer rule (IM8):** value an item's rows strictly by seq, starting after the item's last valued seq. A seq that is
  missing below a visible one is corruption: stop that item and alert; it is never "not yet committed". The seq orders an
  item, not the whole stock; IM8 needs no global order (one moving average per pool and item, I5). A clock moved by hand
  (the app login may update `last_seq`) is corruption too (I28).

**I4. Backfill in 0006.** The migration numbers every on_hand row already booked in ledger id order per item (`ROW_NUMBER()
OVER (PARTITION BY sku_id ORDER BY id)`; deterministic: 8,199 opening adjustments expected on `cw_staging`) and gives
**every** item a clock row (0 when it has no on_hand row; taken from the seq rows just written, never from a second count of
the ledger: I28), so the hot path rarely inserts one. For historical rows seq order is id order. 0006 must be applied
**together with the code**, with the writers stopped (`install_cron.sh --migrate`; I28): old code booking on_hand after the
migration would leave rows without seqs, which invariant 7 reports, and new code before the migration fails on the missing
tables.

**I5. `stock_value_ledger`, created empty.** The value journal that IM8's `Valuation.php` will be the only writer of: its own
BIGINT PK, `stock_ledger_id` NULLable (value-only entries), `kind` in `movement`, `cost_adjust`, `landed`, `price_credit`,
`trueup`, `nrv_reclass`, `opening`, `count_reclass`, `qty_delta` (0 for value-only kinds: CHECK), `unit_cost`, `value_delta`,
`qty_after`, `value_after`, `cost_source`, the document link, `effective_at` (valuation date) and `actor`. `valuation_pool`
(default `default`) is where decision 9 (which company owns the stock) lands: one moving average per (pool, item). A stored
generated column with a UNIQUE key allows one `movement` entry per ledger row. Append-only for the app login. IM8 may still
change its shape while it is empty; C0 only creates it so the core never changes twice.

**I6. Idempotency keys stored before C0 still replay** (requests without `unit_cost`; one that carried an ignored cost
does not: I28). `unit_cost` enters the canonical request of a line only when it is
sent, as its 6-decimal string, so every request without a cost hashes exactly as before (`ksort` of the line keys is
unchanged); `"1.5"`, `1.5` and `"1.500000"` are one request, `"1.51"` another (422 `idempotency_key_reused`). Pinned by
`MovementHashCompatTest`: three golden request hashes printed by the code before C0 (b390a40) — a goods-in by sku_id with
line_index, warehouse and note; a backdated count; a two-line adjustment — and a stored-before-C0 key that replays.
Response bodies are unchanged (no cost field).

**I7. One `lock()` and one `flush()` per document posting.** `Movements::record()` is now `prepare()` (validation, canonical
request) + `Idempotency::run(book())`, and `book()` resolves every line of every movement, takes ONE `Stock::lock()` of all
their balances, applies the movements in order and calls ONE `flush()`. Document postings run inside their own transaction,
where `Idempotency::run` refuses to run, so C0 adds two in-transaction entry points on the same `book()`:
- `bookForDocument(Caller, {document_id, doc_ref}, opKey, movements)`: staff only (403 `staff_only`), 1–20 movements of
  `DOCUMENT_TYPES` (400 `bad_type` / `bad_movements`), at most `MAX_LINES` lines in all, each line with its `document_line`
  (1..4294967295, unique in its movement, used as the line index) naming the item by `sku_id` or `sku_code` (400
  `bad_lines` otherwise: a document never names a site variant); counts pass `Clock::checkWindow` (backdated allowed). An
  unresolved line refuses the whole posting with `CwException` 422 `unresolved_line` (detail: movement, type,
  document_line, reason) before anything is locked; documents never park lines in `goods_in_suspense`. No idempotency row
  and no audit row: the posting's own transaction, document lock and audit row do that. Also refused (`LogicException`, not in
  the brief): a second call for a document that has already booked stock (a handler bug would book it twice).
- `reverseDocument(Caller, originalId, {document_id, doc_ref}, opKey)`: the exact negation of every ledger row of the
  original (same bucket and type, cost, cost source and document line; `effective_at` now; note `reversal of <number>`;
  zero rows mirrored), in one `lock()`/`flush()`; 0 and no lock when the original moved no stock; `LogicException` when
  the reversal document has already booked stock (so it can never negate twice). It never touches `counted_at`: a reversed
  count leaves the location's count time, and a recount is IM2's business. Staff only, like `bookForDocument`.
- **The `lock()` guard:** `Stock::lock()` throws a `LogicException` while on_hand rows applied in the same transaction still
  wait for their seq ("call flush() before lock() again"). "The same transaction" is `Db::transactionSerial()`, a counter that
  moves at every real `beginTransaction()` (deadlock retries included) and never when a call joins an open transaction, so the
  stale entries a rolled-back operation leaves in a long-lived `Stock` are discarded, not numbered (also by a `flush()`
  without `lock()`). Since I29 `lock()` also refuses once the transaction took value clocks or the feed clock through ANY
  `Stock` on the connection.

**I8. `trade_sale`, document-only.** A new movement type (on_hand − |qty|, no cost: issued at average) for IM11's trade and
inter-site issues, added now so IM11 does not have to touch the core again. It is in `DOCUMENT_TYPES` and not in `TYPES`:
`record()` (POST /v1/movements, the ERP relay, the staff screens) answers 400 `bad_type` for it.

**I9. Measurements (2 Oct 2026, slot `i1c0`, staging cluster shared with other slots).** Before = HEAD b390a40 (a clean
copy run in the same slot), after = C0. Commands: `scripts/remote.sh i1c0 php tests/concurrency/hammer.php --seed=20261002
[--only=4]` and `scripts/remote.sh i1c0 vendor/bin/phpunit --filter testALargeMovementDoesNotHoldTheFeedClockForOneRoundTripPerItem`
(the before copy carried only the extra "whole movement" print).

| Measure | Before | After | Change |
|---|---|---|---|
| Scenario 4 operations/s (3 runs, 30 s, 22 traders + 2 crons) | 54, 62, 61 (median **61**) | 51, 60, 63 (median **60**) | −1.6 % (limit: 15 %) |
| Scenario 4 deadlocks retried / surfaced | 0 / 0 | 0 / 0 | — |
| 2,000-line goods-in: feed rows written within (ms), the 3 specified runs | 66, 69, 67 (median 67) | 190, 70, 47 (median 70) | bound < 250 ms held |
| same, all 14 runs each (incl. 5 interleaved before/after pairs) | median 65, max 122 | median 78, max **313** | 13 of 14 after runs < 250 ms |
| same, the 5 interleaved pairs only | 122, 69, 96, 52, 63 (median 69) | 67, 86, 84, 61, 88 (median 84) | |
| whole 2,000-line movement (ms), the 5 interleaved pairs | 16031, 12641, 15097, 18267, 12366 (median 15097) | 14619, 15378, 12971, 14996, 20078 (median 14996) | none measurable |
| Full hammer (scenarios 1–5) | 1–4: PASS (50 checks, 26 Sep) | **RESULT: PASS (57 checks passed, 0 failed)**, 87 s, 0 deadlocks retried in every scenario | |
| Scenario 5 (new; 20 traders, 30 s) | — | 1,072 calls (36/s), 1,634 on_hand rows (54/s); 231 polls, 0 gaps; 13 live snapshots, 0 violations; seq order ≠ ledger id order 26 times | |

Reading: the C0 work sits before the feed clock (three round trips per 1,000 items, measured in the whole movement, where it
is lost in the noise of ~2,000 per-line round trips), so the serialised feed section is the same code as before; scenario 4's
throughput is unchanged. One after-run measured a 313 ms feed span (the test's bound is 250 ms): in that run the whole
movement took 30 s instead of the usual 12–16 s and the other site's reserve 969 ms, i.e. the shared cluster was slow, and
before-runs in the same hour reached 122 ms. The medians differ by 13 ms in the after's disfavour; that is within the
run-to-run spread, but it is recorded as an open item: re-measure on a quiet cluster before live. The new per-item clock does
add one serialisation that did not exist: two transactions moving the SAME item at different warehouses (MAIN and VERIFY)
now queue from `flush()` to commit (a few round trips); different items never wait for each other's clocks. What C0 costs
on traffic heavy on on_hand changes (about 4 ms per one-item operation, median −7 % in an A/B): I30.

## Inventory Phase I-1 (slot `i1ro`, roles)

IM1's roles (`docs/inventory-modules-plan.md` §3 IM1): several roles per person, one permission map, role-aware menus and
the People and roles screen. Numbered I10–I16. Code: `migrations/0007_staff_roles.sql`, `src/Auth/Permissions.php` (new),
`src/Auth/{StaffIdentity,Sessions}.php`, `src/Staff/StaffRoles.php` (new), `src/Staff/StaffAdmin.php` (`create` with a role
list, `setRoles`, `setActive`), `src/Mapping/DecisionService.php` (`staff()` and its role checks), `src/Ui/{Route,Router,
Kernel,Context}.php`, `src/Ui/Controller/{Dashboard,People,Review}Controller.php`, `src/Ui/views/{layout,home,people,person}.php`,
`public/ui/assets/app.css`, `bin/{create_staff,reset_staff,mint_vpg}.php`, `src/Schema/Grants.php`; tests
`tests/Unit/PermissionsTest.php`, `tests/Integration/Staff/StaffRolesTest.php`, `tests/Integration/UiKernel/{Menus,
PeopleScreen}Test.php`, `tests/Integration/Migration0007Test.php`, new cases in `GrantsTest`, `UiUnitTest`, `UiTemplatesTest`.

**I10. `staff_role`, with history; `staff_user.role` dropped (amends M2).** One row per grant: `staff_user_id`, `role` (the
14 roles of I11), `granted_by`/`granted_at`, `revoked_by`/`revoked_at`. A grant is revoked, never deleted: the app login has
SELECT, INSERT and UPDATE of `revoked_at`, `revoked_by` only (`Grants::UPDATE_COLUMNS`), so the history stays readable; the
authoritative record of who changed which role is the insert-only `audit_log` (`staff.roles`), because the app login could
still clear a `revoked_at` (I35; `ck_staff_role_order`: never revoked before it was given). A stored generated column
`active_staff_user_id` (the person while the grant is live, NULL once revoked) with `UNIQUE (active_staff_user_id, role)`
allows one live grant per person and role and any number of revoked ones; CHECKs: nobody grants or revokes their own role,
and a revoker needs a revocation time. `granted_by` NULL means a CLI tool or the backfill (`audit_log` names which). 0007
gives every person the role they had (`CAST(role AS CHAR)`, granted at their `created_at`, one `staff.roles` audit row each,
actor `system:migrate`) and then **drops** `staff_user.role`: a reader that was missed fails loudly (unknown column, 1054)
instead of trusting a stale column. Forward-only (D25): deploy 0007 together with the code (`install_cron.sh --migrate`);
old code on the new schema and new code on the old schema both fail at once, on the sign-in, rather than silently.

**I11. One permission map; routes are guarded by permission; roles are read per request.** `CW\Auth\Permissions` (pure, no
database) is the single place that says what a role may do: `ROLES` (14), `DESCRIPTIONS`, `MAP` (permission → roles),
`MENU`, `can()`, `permissionsOf()`, `checkRoleSet()`, `menu()`. What a person may do is the union of their roles'
permissions. A UI route's access is `public`, `any` or a permission (`Router::add` throws `InvalidArgumentException` on
anything else, so a typo cannot leave a page open); `Kernel::guarded` refuses 403 when `$who->can($access)` is false, so a
page left out of a menu is also refused, not only hidden. `can()` with an unknown permission throws, never answers "no".
`Sessions::resolve` reads the live roles (`GROUP_CONCAT` of `staff_role` where `revoked_at IS NULL`) on every request, so a
role taken away stops working on the person's next page, without ending their session. Services that write re-read the
caller's roles inside their own transaction (`StaffRoles::active`: 403 `staff_not_allowed` for an unknown or inactive
person): `DecisionService::staff()` now returns `{id, roles}` and checks `mapping.decide` (= mapper, mapping_lead, the old
`DECIDERS`) and `mapping.approve` (= mapping_lead) with unchanged semantics; `DecisionService::ROLES` stays as an alias of
`Permissions::ROLES`. 403 texts name the roles: "your role (viewer) cannot make mapping decisions" (unchanged for one role),
"your roles (a, b) ..." for several, and "your role (buyer) does not open this page" / "your roles (a, b) do not open this
page" for any other permission (the spec gave only the plural form; one role reads in the singular).

**I12. Separation of duties: admin never posts, reviews or decides.** `admin` (people and roles) may be combined only with
`ADMIN_COMPATIBLE` = viewer, accountant, auditor (read-only roles). `checkRoleSet` refuses anything else with 422
`role_conflict` ("admin cannot be combined with <list>: the person who manages people and roles never posts, reviews or
decides"), an empty set with 422 `no_roles` (to take every role away, deactivate the person) and an unknown role with 400
`bad_role`; every writer (`StaffAdmin::create`, `setRoles`, both CLI tools, the screen) goes through it. The map gives admin
no `doc.*`, `documents.review`, `documents.approve` or `mapping.*` (`PermissionsTest`).
- *Defence in depth (not in the spec):* a set that breaks the rule anyway (only admin SQL can write one) is read fail-closed:
  while admin is held, the conflicting roles grant nothing (`Permissions::effective`), so admin + mapper cannot decide.
- **Consequence for the owner (decision 3):** the owner holds `reviewer` as the backup reviewer, and reviewer cannot be
  combined with admin. **The owner therefore cannot also be admin: the owner must name another person as admin.** If nobody
  is admin, `bin/reset_staff.php --email=<address> --roles=admin` on the server is the break-glass (CLI callers are system
  callers and pass the admin check), and the People list warns "No active person holds the admin role".
- If the owner decides otherwise (an owner-admin who also reviews), `ADMIN_COMPATIBLE` is the one constant to change
  (`PermissionsTest::testAdminNeverPostsReviewsOrDecides` and this entry change with it).

**I13. The People and roles screen (`/ui/people`).** Admin and auditor see the list (`staff.view`: name, e-mail, roles,
active, last sign-in, created; warnings when fewer than 2 active people hold `reviewer`, decision 3, or none holds `admin`)
and each person's page (details and the full role history: role, given at/by, taken away at/by). Only admin changes
(`staff.manage`): a checkbox per role (`role_<name>=1`, grouped Linking / Purchasing and receiving / Stock / Review and
finance / Admin with the descriptions; `UiRequest::field` ignores arrays, hence one field per role), and switching the
account off or on. Rules, all enforced again by `StaffAdmin` inside its transaction:
- **No self-edit:** an admin's own row has no forms (the page says why) and a POST is 403 `own_account`; an auditor sees no
  forms ("Your role (auditor) can look at people and roles but not change them.") and a POST is 403 at the route.
- **Optimistic check:** the form carries `roles_seen` (the live roles it was drawn with). When they changed meanwhile the save
  is 409 `roles_changed`; the page is re-drawn with the current roles named, the admin's choices kept and `roles_seen`
  updated, so saving again is a deliberate overwrite. A form without `roles_seen` is 400. Every refusal re-renders the page
  under its status with the error and the choices kept (422 `role_conflict`/`no_roles`, 409, 403).
- **Effects:** removed roles are revoked (`revoked_at = NOW(6)`, `revoked_by` = the admin), added ones granted
  (`granted_by` = the admin), audit `staff.roles` `{email, before, after, added, removed}` with the admin as actor; nothing
  changed: no write, no audit, notice "Nothing changed" (`roles_unchanged`, a notice the spec did not list). A role removed
  takes effect on the person's next request (I11). Switching off sets `is_active = 0` and ends every session of the person at
  once (`Sessions::revokeAll`), audit `staff.deactivate`/`staff.activate`; switching to the state the account already has
  writes nothing.
- **Concurrency (not in the spec):** `setRoles`/`setActive` lock the caller's and the person's `staff_user` rows in id order
  before re-reading the caller's roles, so two admins taking admin away from each other at the same moment queue, and the
  second finds it is no longer an admin (403) instead of both succeeding and leaving no admin.
- **Creation and secrets stay on the server:** new people are made with `bin/create_staff.php`; a one-time password or TOTP
  seed is never shown in a browser (the list says so). `StaffAdmin::create` called by a staff caller needs admin too.

**I14. Menus and the home page (amends U13).** The navigation is `Permissions::menu($roles)`: grouped sections (Linking,
Items, Purchasing, Receiving, Stock control, Trade, Document reviews, Documents, Accounts, Reference, Admin), only the items
the roles permit, empty sections left out. An item is either a live link (a real GET route; `aria-current` on the current
page's key) or a placeholder "<label> · coming in Phase I-n", which is text, never a link. In I-1 Document reviews, Documents
and Reference are placeholders (phase I-1); the documents task (0008) makes them live. Badges are computed only for what the
person may see: the second-approval count needs `linking.view` (U13 showed it to every signed-in user; the new roles have no
linking screens). The quick search box needs `catalogue.view` (every role). `/ui/` stays open to every signed-in person:
with `linking.view` it is today's dashboard; otherwise a home page ("Signed in as <name> (<roles>)", one card per menu
section with its links and placeholders, "You have no roles yet: ask an admin" when the person has none). **The linking
screens need `linking.view`** (viewer, mapper, mapping_lead, warehouse, manager, admin, auditor: everyone who had them
before); the new inventory roles never had them. Search and item pages need `catalogue.view` (all 14 roles).
- *Spec conflict, resolved in favour of the map:* the spec's MenusTest lists a buyer's sections as Items, Purchasing,
  Reference, but its own permission map gives `buyer` `documents.view` (a buyer will post and read purchase orders). The menu
  is derived from the map, so a buyer sees Items, Purchasing, **Documents**, Reference; the tests assert that. The three
  acceptance menus stay different: buyer {Items, Purchasing, Documents, Reference}, purchasing desk {Items, Receiving, Trade,
  Documents, Reference}, reviewer {Items, Document reviews, Documents, Reference}; none shows Linking or Admin. If the owner
  wants buyers without the documents list, drop `buyer` from `documents.view` (one line).

**I15. CLI: `--roles`, with `--role` as an alias (amends M15, U23).** `bin/create_staff.php --roles=buyer,reviewer` (comma
list; `--role=<one>` kept for the existing runbooks); exactly one of the two, else exit 2 (an empty `--roles=` is exit 2 too:
getopt gives it no value); the set passes `checkRoleSet` (exit 1 otherwise); stderr prints the roles. `bin/reset_staff.php
--roles=<list>` replaces the set through `StaffAdmin::setRoles` as `system:reset_staff` (audited `staff.roles`), prints
`roles: a,b -> c,d` on stderr, and combines with the other flags (the roles first, in their own transaction; then the
reset). A role change alone does not end the person's sessions (it takes effect on their next request); every other reset
still does. `bin/mint_vpg.php --staff` must be active and hold `mapping_lead` (`StaffRoles::of`).

**I16. Proposed posting permissions (pending decisions 3 and 11).** `doc.PO.post`: buyer, purchasing_manager;
`doc.GRN.post`: goods_in, purchasing_desk, purchasing_manager; `doc.SINV.post` and `doc.DN.post`: purchasing_desk,
purchasing_manager; `doc.CNT.post`: stock_controller, warehouse; `doc.ADJ.post` and `doc.WO.post`: stock_controller;
`doc.TRD.post`: purchasing_desk, purchasing_manager. `documents.review` and `documents.approve`: reviewer only (the poster
never reviews their own document: enforced per document by the documents task). `documents.view`: every role that posts,
reviews or audits, plus manager and warehouse; `accounts.view`: accountant, auditor. Nothing posts in I-1 (no document type
is live), so these are defaults to confirm with the owner when decisions 3 (people per role) and 11 (approval rules and
limits) are taken; changing one is a line in `Permissions::MAP` plus its test.

## Inventory Phase I-1 (slot `i1do`, documents)

IM1's document base (`docs/inventory-modules-plan.md` §3 IM1): one generic document header, lines, statuses, the post-first
review and the blocking approvals' infrastructure, reversals, number series, reason codes, the document store, PDF and CSV
output, and the screens around them. Numbered I17–I27. Code: `migrations/0008_documents.sql`, `src/Documents/` (`Document`,
`DocumentHandler`, `DocumentHandlers`, `Documents`, `NumberSeries`, `DocumentInvariants`), `src/Files/` (`FileStorage`,
`LocalFileStorage`, `FileStore`, `FileStoreException`), `src/Output/` (`Fpdf`, `PdfWriter`, `CsvWriter`),
`src/Ui/Controller/{Documents,Reviews,Reference,Files}Controller.php`, `src/Ui/views/{documents,document,reviews,reasons,
series}.php`, `src/Ui/{Kernel,Context}.php`, `PeopleController::csv`, `src/Auth/Permissions.php` (the three menu sections go
live), `src/Invariants.php` (D1–D7), `src/Schema/Grants.php`, `bin/{store_file,verify_files}.php`,
`deploy/staging/install_file_store.sh` (written, not run), `composer.json`/`composer.lock` (setasign/fpdf); tests
`tests/Unit/{CsvWriter,PdfWriter}Test.php`, `tests/Integration/Documents/*`, `tests/Integration/Files/*`,
`tests/Integration/UiKernel/{ReviewScreens,ReferenceScreens,Downloads}Test.php`, `tests/Integration/Migration0008Test.php`,
new cases in `GrantsTest`; support `tests/Support/Documents/{FixtureAdjustmentHandler,FixtureDocuments,DocWorkerPool}.php`,
`tests/Support/doc_worker.php`.

**I17. The document base: one header, generic lines, module extension tables; immutable once posted.** Every document
type (PO, GRN, SINV, DN, CNT, ADJ, WO, TRD) is a `document` row (type, number, status, version, external_ref, doc_date,
warehouse, reason, note, who created / submitted / posted / cancelled it and when, `posted_hash`, `reverses_id`,
`review_state`) with `document_line` rows (line_no, item, warehouse, signed qty in central units, unit cost, amount,
reason, description); a module keeps its own columns in extension tables keyed `(document_id, line_no)`, never in new
columns of the base. What a type does is its `DocumentHandler` (validate, approvalUnits, post, reverse); the generic
`CW\Documents\Documents` owns everything else.
- **Status machine:** draft → posted | awaiting_approval | cancelled; awaiting_approval → posted (approved) | draft
  (withdrawn by the requester) | cancelled (rejected); posted → reversed (by its reversal). CHECKs hold the pairs
  together (a number iff posted or reversed; posted needs posted_at, actor, hash, review_state; cancelled iff
  cancelled_at; awaiting approval needs submitted_at).
- **Immutable after posting, three times over:** the code (every write locks the row `FOR UPDATE`, requires `status =
  'draft'` (409 `not_draft`) and the version the form was drawn with (409 `version_conflict`), and moves `version`); the
  column grants (the app login cannot UPDATE `id`, `doc_type`, `created_by`, `created_actor`, `created_at`, `reverses_id`,
  and deletes nothing: `Grants::UPDATE_COLUMNS['document']`); and `posted_hash`, the sha256 of the canonical JSON
  (`Idempotency::canonicalJson`) of the header fields (id, doc_type, number, external_ref, doc_date, warehouse_id,
  reason_code, note, reverses_id) and the lines, decimals as the database returns them, checked nightly for EVERY posted
  document against its write-once posting record, `document_posting` (D7, I33: the document's own columns, posted_hash and
  posted_at included, can be rewritten by the app login; the record cannot). `document_line` keeps FULL grants (draft lines
  are replaced); a line changed after posting is what D7 finds.
- **Who may write a draft (not in the spec):** drafting, posting, cancelling and reversing need `doc.<TYPE>.post`; a
  caller holding admin is refused first (403 `admin_cannot_post`, I12; also for an admin set that only admin SQL can
  write); system and channel callers get 403 `staff_required`; roles are re-read inside the transaction (an inactive
  person: 403 `staff_not_allowed`). **A draft's header and lines are changed only by its creator** (403 `not_creator`);
  anyone allowed to post the type may post or cancel it. The review rule excludes the creator, the submitter and the
  poster, so a third person who could edit the lines could otherwise write a document's content and then review it.
- **Generic checks at posting** (again, a draft may be days old): at least one line (422 `no_lines`); header and line
  reasons known, applicable to the type, active and not CW's own (422 `unknown_reason`, `reason_not_applicable`,
  `reason_inactive`, `reason_system_only`); a reason that needs a note has one (the header note, or a line's description:
  422 `note_required`); items exist and are not merged (422 `unknown_sku`, `merged_item`). Then the handler's own
  `validate()`. Lines are checked when set too (400 `bad_lines`, `bad_cost` via `Movements::normaliseCost`, `bad_amount`,
  422 `unknown_warehouse`); a header takes only external_ref, doc_date, warehouse, reason_code, note (400 `bad_field`).
- **Spec DDL corrected (safer):** the spec's format CHECKs used `REGEXP`, which follows the column collation
  (`utf8mb4_0900_ai_ci`, case-insensitive): an upper-case `posted_hash` or sha256, a lower-case type code and an
  upper-case reason code passed. 0008 uses `REGEXP_LIKE(..., 'c')` for `ck_reason_code_code`, `ck_document_type_code`,
  `ck_document_hash` and `ck_stored_file_sha` (`Migration0008Test`). 0008 was never applied anywhere before this change.

**I18. Corrections are reversals.** A reversal is a new document of the same type and number series with `reverses_id` = the
original (one LIVE reversal per document: `UNIQUE (live_reverses_id)`, I32), the original's external_ref and warehouse,
today's date, the reason, and the original's lines copied with `qty` and `amount` negated (unit costs and line reasons
kept). In one transaction: original row lock → reversal row + lines → number → reversal posted (its own `posted_hash`) and
original `reversed` → the original's open review task `withdrawn` (decided_by NULL, note `reversed by <number>`) → audit
`document.reverse` (idem_key `doc:<reversal id>:reverse`) → `handler->reverse()` (module rows only) →
`Movements::reverseDocument` (the exact negation of every ledger row of the original, I7) → the reversal's review task if
the type's rule asks for one.
- A reversal is never reversed, and a document is reversed once (409 `not_reversible`); a draft is cancelled, not reversed.
  Rejecting the review of a reversal records the rejection and books nothing (I31).
- A **voluntary** reversal (`doc.<TYPE>.post`; reason applying to `reversal`, not CW's own: entered_in_error, duplicate,
  other with a note) is reviewed under the type's rule; its review units (spec silent) are what it moved:
  Σ|qty_delta| of its on_hand ledger rows, so an `over_limit` type reviews a large undo like a large posting. A voluntary
  reversal that puts more units back on hand than the "positive without a supplier document" limit waits for that blocking
  approval first, for every type (I32).
- The **rejection** reversal (I19) has reason `review_rejected` (CW's own), is created and posted by the reviewer, needs no
  `doc.<TYPE>.post` (a mechanical undo), and is not reviewed again (review_state `not_required`).
- The original keeps its review_state as it was when reversed; D4 only asks posted documents for their open task.

**I19. Review model: post first, a second person reviews; two blocking approvals.** A posting books at once; the type's
`review_rule` (`all`, `over_limit` with `review_limit_units`, `none`) then opens a `review_task` (kind `review`, due
`review_due_days` later) and sets `review_state = 'pending'`. Blocking approvals come only from
`document_type.approval_rule` (`positive_without_supplier_doc`: ADJ, when the handler's approval units exceed
`approval_limit_units`), a reversal that puts stock back above that limit (I32) and, from I-2, supplier activation
(`subject_type = 'supplier'`; until then a supplier task is 409 `subject_not_built`): the document waits in
`awaiting_approval`, unnumbered and unbooked, with an open task of kind `approval`.
- **Who decides:** a review needs `documents.review`, an approval `documents.approve` (both reviewer today, I16); never
  admin (403 `admin_cannot_review`); never the document's creator, submitter or poster (403 `own_document`, with the
  reason the screen shows: "You posted this document: another reviewer must review it."). `ck_review_task_not_own`
  refuses the opener as decider in SQL as well, and D5 checks the creator and submitter too.
- **Outcomes:** approving a review: `review_state = 'approved'`. Approving an approval: the document is posted now as the
  **requester's posting** (posted_by = submitted_by, actor `staff:<requester>` on the ledger), review_state `approved` (the
  approval was the review; no review task; audited under the reviewer, I34). Rejecting a review posts the reversal (I18) and
  sets review_state `rejected` (the review of a reversal: recorded, nothing booked, I31). Rejecting an approval cancels the
  request (cancel_reason = the note). A rejection needs a note of 3–500 characters (400 `note_required`). The requester may
  withdraw an open approval (the document is a draft again, submitted_by/at cleared; a reversal request is cancelled
  instead, I32; nobody else may: 403 `not_requester`).
- **Not in the spec:** an approval posts as the requester only while the requester is still active and may still post the
  type; otherwise 409 `requester_cannot_post` and the reviewer rejects instead (posting under the name of someone who has
  left would be wrong).
- **Locking:** a decision reads the task, locks its document `FOR UPDATE`, then the task `FOR UPDATE` and re-checks that it
  is open (409 `task_closed`); the document is locked first by every writer, so a reversal and a decision on the same
  document queue instead of deadlocking.
- **Badge and queue:** the menu's Review queue shows `reviews_open`, the open tasks this person may decide (their kinds,
  not opened by them, not on a document they created, submitted or posted); the queue lists every open task, oldest
  first, with the due date and an overdue flag, and says why for the ones the person may not decide.
- **Limits** (10 units for CNT/WO reviews and ADJ approvals; 3 review days, 7 for PO) are placeholders until decision 11.

**I20. Number series: continuous per prefix, gapless.** `number_series(prefix, last_no, pad)`, one per type, no yearly
reset: the year end is undecided (decision 13), a calendar reset would split a financial year anyway, there is no New Year
edge, and it is simpler. A number is taken at posting, inside the posting transaction, after the document row and before
any stock lock, so the ledger's `doc_ref` is the number: `UPDATE number_series SET last_no = LAST_INSERT_ID(last_no + 1)`
(X-locks the row to commit; the value comes back through the connection's `LAST_INSERT_ID()`), then `SELECT LAST_INSERT_ID(),
pad`. A rollback restores `last_no`, so 1..last_no are always all on posted documents (D1). Format `PREFIX-000001`; a series
that outgrows its pad gets wider (`ADJ-1000000`), never wraps. Cost: postings of one type serialise from numbering to
commit, acceptable at ~15 a day. Measured: 12 processes × 100 allocations, every 10th rolled back: 1,080 unique numbers,
exactly 1..1,080, 0 deadlocks (`NumberSeriesRaceTest`). The app login may UPDATE `last_no` only.

**I21. The full lock order of a posting (extends D39, I3).** idempotency claim (none for documents) → the document row(s),
by id (the original first, a new reversal row after it) → the review task row (decisions) → the number_series row →
(module rows, I-2 onwards) → reservation → channel_listing → stock_balance → sku → item value clocks → feed clock. Nothing
with a foreign key is written after the stock locks: the review_state UPDATE touches the already-locked document row and
no FK column, `review_task` has no foreign keys (its staff ids are checked by D5), audit_log has none, and
`document_line.sku_id` has no FK (D15; D6 checks it). FK checks of the header and lines (type, warehouse, reason, staff)
take S locks before the number and the stock. Locking reads (`FOR UPDATE` / `FOR SHARE`) of the column-granted `document`
and `review_task` work for the app login (`GrantsTest`). Measured: 8 processes posting 64 documents over 6 items at MAIN
and VERIFY in random line order, while 4 processes made 60 staff movements on the same items: no error, numbers 1..64,
every document's ledger rows carry its id and number, all invariants (`PostingRaceTest`).

**I22. Reason codes.** The 22 seeded codes of 0008 (damaged ... other), each with `applies_to` (adjustment, write_off,
count, return, supplier_return, reversal), a direction, `needs_note`, `is_gift` (a free gift is reported apart, IM13;
vaping/nicotine gifts to the public are an offence from 29 Oct 2026) and `system_only` (`opening_rebase` for the T0 rebase,
IM2; `review_rejected` for I19's reversals: never offered on a form, refused when staff send them). Provisional until the
I-0 analysis of the ERPNext reconciliation history (+644k / −128k units). The app login has SELECT only (`READ_ONLY`): the
list changes by migration. Which `applies_to` a document uses is `Documents::REASON_USE` (ADJ adjustment, WO write_off,
CNT count, DN supplier_return; reversals `reversal`; the other types carry no reason until their phase decides). The
direction (increase/decrease) is checked by the type's handler, because what a sign means is the type's business (a WO
line's positive qty is a decrease).

**I23. The document store: content-addressed, write-once, verified, kept 7 years.**
- `stored_file`: one row per distinct content (sha256 UNIQUE), its size, the MIME type sniffed from the bytes with finfo
  (never the client's name or claim; allow-list PDF, JPEG, PNG, CSV, text, XLSX: 415 `type_not_allowed` otherwise, HTML,
  SVG and executables included), the original name (base name, no control characters or path separators, ≤ 255 bytes),
  kind, backend, storage key, `retain_until` = `CURRENT_DATE + 7 years` (CHECK ≥ created + 7 years), who stored it.
  `document_file` attaches a file to a document under a role (any status but cancelled: 409 `document_cancelled`).
  Both append-only for the app login. 25 MiB cap (413 `too_large`), 400 `empty_file`.
- `FileStorage` has put / open / exists / keys / name and deliberately **no delete, rename or overwrite**.
  `LocalFileStorage` (staging) keeps `<root>/<2 hex>/<sha256>`: put copies into `tmp/` (fwrite, fflush, fsync), checks
  **the copy** hashes to the key (stronger than the spec's check of the source: the copy is what is kept), chmods it 0440
  and `link()`s it into place (fails if the name exists, so nothing is ever replaced; an existing file must hold the same
  content, else `collision`). The root must be absolute and must not be a `public` path, under /var/www or inside the code
  directory (a deploy rsyncs it with --delete).
- `FileStore::store` writes the row and the bytes in one transaction (the bytes before the commit: a row never names
  missing content; a failed commit leaves an orphan, which `verify` counts); the same content again returns the first row
  (`deduped`), putting the bytes back if the storage lost them; audit `file.store` either way. `read` re-hashes the bytes:
  a mismatch is 500 `file_corrupt` (logged, with the request id on the screens), never served; a missing file 500
  `file_missing`.
- Staging: `deploy/staging/install_file_store.sh` (written, **not run**; after the I-1 deploy) creates `/srv/cw-docs`,
  `tmp/` and the shards `00`..`ff` as root:www-data 02770, makes the shards append-only (`chattr +a`: entries can be added,
  never removed or renamed, root included; a warning where the file system cannot), adds `file_store_dir` to app.env through
  `AppEnvFile`, and checks that `rm` of a probe file in a shard fails. Since I36 a root sweep also makes every stored file
  immutable (`seal_file_store.sh`, every minute): until then a file's content is protected by detection only. **Before
  live:** an S3 (London) or B2 bucket with Object Lock in COMPLIANCE mode behind the same interface, enforcing
  `retain_until` itself.
- Tools: `bin/store_file.php --file --kind [--note]` (prints `id= sha256= size= mime= deduped=`), `bin/verify_files.php
  [--limit]` (missing / mismatch lines and exit 1; orphans counted, exit 0; scheduled nightly since I36). Browser uploads wait
  for the I-2/I-3 screens and a change of the UI pool's `post_max_size` (2M).
- Downloads (`/ui/files/{id}`, the PDFs, the CSVs) go through `FilesController::download`: `Content-Disposition:
  attachment` with an ASCII fallback and the UTF-8 `filename*`, a second CSP `sandbox` enforced together with the
  kernel's, nosniff and no-store from `Kernel::secure`.

**I24. PDF: FPDF, pinned to 1.8.2 for now.** FPDF (setasign/fpdf, MIT on Packagist) is one small class (about 1,900
lines plus the core font metrics), writes text, lines and tables with the PDF core fonts, embeds nothing and fetches
nothing: no HTML or CSS parser and no remote resources or font cache (dompdf's history of a font-cache RCE is avoided by
not having those parts). The LGPL/GPL alternatives (tFPDF, dompdf, TCPDF, mPDF) were rejected as not permissive.
- **The spec's premise was checked and is wrong:** it said 1.8.6 has no requirements and only 1.9 needs ext-gd and
  ext-zlib. Packagist's metadata (checked from staging, 2 Oct 2026) lists `ext-gd` and `ext-zlib` for **every release
  from 1.8.3 to 1.9.0**; 1.8.2 (FPDF 1.82, 2019-12-07) is the newest without them. Staging has zlib but **not gd**
  (`php -m`; package `php8.3-gd` 8.3.6 is available but not installed), so `composer require setasign/fpdf:1.8.6` fails.
- Options: install php8.3-gd (a server change outside this task: needs the owner's go); declare a root
  `"provide": {"ext-gd": "*"}` (tells Composer a lie for every future package); vendor a copy of 1.9.0 (no Composer
  updates); or **pin 1.8.2 (chosen)**: the same API, run on staging under `error_reporting(E_ALL)` with every call
  PdfWriter makes and no notice or deprecation (PdfWriter passes `isUTF8 = true` to the metadata setters, so 1.82's
  `utf8_encode` is never called); the same probe (a title, a 120-row table over four pages, the `{nb}` footer) gave a valid
  four-page PDF with 1.8.2 and with 1.9.0. gd is only used by FPDF for GIF and WebP images, which PdfWriter never draws.
  `composer audit`: no advisories.
- **Open item:** install `php8.3-gd` on staging (and live), then `composer require setasign/fpdf:^1.9` (one line and the
  lock); `PdfWriterTest` stays the check.
- Windows-1252 only (core fonts): `PdfWriter::text()` converts UTF-8 with `iconv //TRANSLIT//IGNORE` (fallback mbstring
  with `?`) after removing control characters; £, é, ™, €, curly quotes survive, other scripts become `?` or their ASCII
  look-alike, never raw UTF-8. A PDF that must show another script needs a TTF font (tFPDF is LGPL; decide then).

**I25. CSV for Excel, safe against formula injection.** `CsvWriter`: UTF-8 with one BOM, RFC 4180 (every field quoted,
quotes doubled, CRLF line ends; a newline inside a field stays inside its quotes). Every column is declared `text` or
`number`. A text cell loses NUL bytes, has invalid UTF-8 repaired, and is prefixed with `'` when its first non-blank
character (any Unicode white space or U+3000) is `=`, `+`, `-`, `@` or their full-width forms ＝ ＋ － ＠, or when it starts
with TAB or CR, so `=HYPERLINK(...)`, `-1+2`, `\t=1` and `＝1` read as text. A number cell is an int, a finite float or a
plain decimal string written bare; null is an empty cell (not in the spec: an empty cell cannot inject); anything else
throws (`\InvalidArgumentException`: a programming error, never silently text). Header cells follow the text rule. Served
as `text/csv; charset=utf-8` attachments (I23). Two lists in I-1: reason codes (`/ui/reference/reasons.csv`, every role)
and people (`/ui/people.csv`, staff.view: id, name, e-mail, roles, active, last sign-in, created; no secrets).

**I26. DN means our supplier return / debit note.** The type `DN` ("Supplier return / debit note") is the document CW
raises when goods go back to a supplier and the supplier owes us; its reasons are the `supplier_return` ones. This is an
assumption to confirm in I-4 (IM7), where the supplier's own credit note may become a separate type.

**I27. No document type is live in Phase I-1.** `DocumentHandlers::all()` returns `[]`: the document base, the review queue,
the number series and the reference screens are real and reachable from the menu (Document reviews, Documents, Reference
are live links since this task), but nothing can be drafted until a phase registers its type (I-2 PO and supplier
activation, I-3 GRN, I-4 SINV, DN, CNT, ADJ, WO, I-6 TRD). The documents list says "No document type is live yet" and a
document of a type without a handler says which phase brings its screens. Tests register
`tests/Support/Documents/FixtureAdjustmentHandler` as ADJ (one signed `adjustment` per line, review units Σ|qty|, approval
units = positive units without an external_ref) through `Ui\Kernel`'s `$handlers` and `new Documents(...)`; it never ships.
c0's `DocumentBookingTest` and `GrantsTest` book with fixed document ids, so `FixtureDocuments::posted()` gives those ids
real posted rows (numbers 1, 2, ...; a reversal pair whole) and D1–D7 hold for them.

Measurements (2 Oct 2026, slot `i1do`, the working tree with C0, roles and documents): full suite `scripts/remote.sh i1do
vendor/bin/phpunit` green (the 73 skips are the HTTP Api*/Ui* tests of slots api and ui); the whole hammer
`scripts/remote.sh i1do php tests/concurrency/hammer.php --seed=20261002`: RESULT: PASS (57 checks), 90 s, 0 deadlocks
surfaced or retried, scenario 4 at 61 operations/s (I9 measured 60 after C0), scenario 5 at 58 calls/s;
`NumberSeriesRaceTest` 1,200 allocations by 12 workers in 4.3 s (including a 1.5 s start delay), 0 deadlocks;
`PostingRaceTest` 64 postings and 60 staff moves in 5.0 s, 0 deadlocks retried or surfaced.


## Inventory Phase I-1 review fixes (slot `i1fx`, 2 Oct 2026)

Three reviews of the I-1 tree (C0 core safety, security and permissions, data integrity; probes in slots i1rv1–i1rv3)
found one blocker, five important and several minor points. Each fix below has a regression test. 0006–0008 had not been
applied anywhere, so their DDL was corrected in place (D25 forbids editing an APPLIED migration only). Numbered I28–I37.
Code: `migrations/0006_value_core.sql` (clock backfill), `0007_staff_roles.sql` (`ck_staff_role_order`),
`0008_documents.sql` (`live_reverses_id`, `document_posting`, `stored_file.storage_key` index, `document_file.retain_until`),
`src/Stock.php` (`seal()`), `src/Documents/{Documents,DocumentInvariants,Document,DocumentHandler}.php`,
`src/Staff/StaffAdmin.php`, `src/Files/{FileStorage,LocalFileStorage,FileStore}.php`, `src/Ui/Controller/{Documents,Reviews,
People,Files}Controller.php`, `src/Ui/views/{document,person}.php`, `src/Schema/Grants.php`, `bin/mint_vpg.php`,
`deploy/staging/{seal_file_store.sh,install_file_store.sh,cw-staging.cron,install_cron.sh}` (written, not run); tests in
`ValueSequenceTest`, `Migration000{6,8}Test`, `Documents/{DocumentLifecycle,ReviewRules,DocumentInvariants}Test`,
`Files/{FileStore,LocalFileStorage,SealFileStore}Test`, `UiKernel/{ReviewScreens,PeopleScreen,Downloads}Test`,
`Staff/StaffRolesTest`, `GrantsTest`, `ImportToolsTest`, `DeployTest`.

**I28. The value core's data edges (amends I3, I4, I5, I6).**
- **0006 takes each item's clock from the seq rows it has just written** (`COALESCE(MAX(seq), 0)`), no longer from a second
  count of `stock_ledger`. The migrator runs each statement on its own (autocommit), so an on_hand row booked by old code
  between the two statements used to leave the clock one ahead of the seqs: the next booking took n + 2 and left a gap that
  no later booking can fill and that I3's consumer rule must treat as corruption (review probe: seqs [1, 3], clock 3). Now
  such a row is a row WITHOUT a seq (invariant 7), which an admin repair can append, and the next booking continues at n + 1
  (`Migration0006Test::testARowBookedBetweenTheBackfillStatementsLeavesNoGap`, run statement by statement).
- **Stop the writers while `--migrate` runs, and reopen only after the check** (ops.md, "0006"): `SELECT COUNT(*) FROM
  stock_value_seq` = the on_hand ledger rows and `bin/invariants.php` says `ok`. On `cw_staging` nothing books on_hand during
  a deploy (the cron only touches holds) and live starts empty, so the window was theoretical; the rule makes it impossible.
- **A clock moved by hand is corruption** (I3, I5): the app login may UPDATE `stock_value_clock.last_seq` (the ODKU needs
  it), so a hand-made `last_seq = last_seq + 5` is possible with the app's own rights and leaves a gap that only invariant 8
  reports. IM8 must stop the item and alert on such a gap, never skip it.
- **I6's promise covers requests without `unit_cost`.** A request that carried an (ignored) `unit_cost` before C0 now hashes
  differently (a stored key answers 422 `idempotency_key_reused`), and a site or a staff `erp_sale` sending one gets 400
  `cost_not_allowed` before the idempotency lookup. No known caller sends it (the relay is not live, there are no staff
  movement screens); the relay connector must never forward a cost field.

**I29. No balance is locked after the clocks, whatever the Stock instance (extends I7, D39).** I7's `lock()` guard lived in
one `Stock` object, so a second `Movements`/`Stock` on the same connection inside the same transaction could take balance
locks after the first one's value clocks or feed clock (e.g. a future handler whose `reverse()` books stock, which
`Documents::reverse` calls before `Movements::reverseDocument`). `Stock` now marks, per connection (a static `WeakMap` keyed
by the `Db`), the `transactionSerial()` in which it took value clocks or the feed clock, and `lock()` refuses in that same
transaction ("no balance is locked after them"). A transaction whose `flush()` took no clock may still lock again; a new
transaction (a deadlock retry included) starts clean. No current path locks after its clocks: the full suite and the hammer
pass unchanged (`ValueSequenceTest::testNoBalanceIsLockedAfterTheClocksThroughAnyStockOfTheConnection`,
`DocumentLifecycleTest::testAHandlerThatBooksStockInReverseIsRefused`). Also new:
`testARollbackAfterFlushRestoresTheClockAndTheSeqRows` (the R16 case failed before `flush()`, so it never proved that a
rollback restores a clock that was bumped).

**I30. What C0 costs on the on_hand paths (amends I9).** I9's −1.6 % was scenario 4, where only about 9 % of the operations
touch on_hand. The review measured the value-seq statements directly (`SeqCostProbeTest`, slot i1rv1): about **4 ms per
one-item on_hand operation** (median 3.98, p90 4.91 against a 0.74 ms `SELECT 1`), taken while the operation's balance locks
are held, i.e. +20–25 % on a 1-line goods-in (~15 ms) or a 1-unit ship (~19 ms); and an A/B on a mix heavy on on_hand
(12 workers, 30 s, 4 hot items at MAIN and VERIFY, 4 alternating rounds): on_hand rows/s −0.7, −4.9, −9.2, −14.8 %
(median about −7 %). Inside the 15 % target, and recorded so the I-3 goods-in bench is sized with it. *Not done:* saving the
SELECT round trip in the single-item case (`LAST_INSERT_ID(expr)` in the ODKU, or folding the seq INSERT into an INSERT ...
SELECT): it would change the frozen core again for ~1 ms of ~4, and the target holds; revisit with IM8's measurements.

**I31. Rejecting the review of a reversal never reverses it (blocker; amends I18, I19).** `reject()` posted the reversal of
whatever document it was given, so a reviewer rejecting the review of a VOLUNTARY reversal (an ordinary action: the reversal's
page showed the form) created a reversal of the reversal: the original's stock was booked again while it still said
`reversed`, and `Invariants::check` reported two D2 violations (both reviews reproduced it). Chosen: the rejection of a
reversal's review is **recorded and books nothing** — task `rejected`, the reversal's `review_state = 'rejected'`, audit
`document.reject` with `booked: false`; the original stays reversed; if it was right, its poster posts it again as a new
document (the page and the notice say so; the list's review filter "rejected" finds them). Refusing the rejection instead
(409) was rejected: the task would stay open forever or force a reviewer to approve what they think is wrong. A reversal is
never reversed, now three times over: `reverse()` (409 `not_reversible`), `reject()` (this rule) and a `LogicException` in
`postReversal()` (I32's split of it) for any other caller.

**I32. A reversal that puts stock back waits for the blocking approval (important; amends I18, I19).** The spec reviewed a
voluntary reversal "under the type's rule", so reversing a correct write-down of 50 put 50 units back on hand at once, with a
review only afterwards: exactly the owner's "positive adjustment without a supplier document above a small limit", one of
the only two blocking approvals, bypassed (and for WO, CNT and DN, whose types have no approval rule, always). The safer
option, chosen: a voluntary reversal whose units back on hand — per item, `max(0, −Σ qty_delta)` of the original's on_hand
rows, so a move between warehouses counts 0 and a write-down's reversal counts in full — exceed the limit of the
`positive_without_supplier_doc` rule (`MIN(approval_limit_units)` of the types that have it: ADJ, 10, until decision 11), for
ANY type, is a **request**: the reversal document is created `awaiting_approval` (no number, nothing booked; lines the
original's negated; created and submitted by the requester) with an open approval task (`reason positive_without_supplier_doc`,
units). A reviewer approving it posts it as the requester's reversal (number, original `reversed`, the original's open review
withdrawn, stock negated; review_state `approved`); rejecting cancels it; the requester may withdraw it, which **cancels** it
(a reversal is never a draft: its lines are the original's). A reversal never carries a supplier document of its own, so
the original's external_ref does not exempt it.
- **One live reversal per document:** `UNIQUE (live_reverses_id)`, a stored generated column that is `reverses_id` unless
  the reversal is cancelled, so a cancelled request frees the slot and the document can be reversed again; while a request
  waits, a second `reverse()` is 409 `reversal_pending` and the page offers no reversal form. Rejecting the ORIGINAL's review
  while a request waits cancels the request first (its task withdrawn, "superseded"), then posts the rejection's reversal.
- **Locks:** `lockTask` locks a reversal's original before the reversal (document rows in id order, I21; `reverses_id` is
  frozen by the column grant, so the unlocked read of it is stable).
- **D2** now allows a reversal that is `awaiting_approval` (its original `posted`) or `cancelled` (history), refuses a
  reversal that is a draft or reversed, nets only POSTED pairs and asks every `reversed` document for its POSTED reversal.
- Smaller reversals, and the rejection's reversal (decided by a reviewer, the second person already), post at once as before.

**I33. The posting record: posted documents are checked against a write-once anchor (important; amends I17 D7).** The app
login may UPDATE a posted document's state columns (posted_hash and posted_at among them, needed by the posting itself) and
has full rights on `document_line` (draft lines are replaced), so it could rewrite a posted line AND recompute the unkeyed
`posted_hash`, or move `posted_at` back past D7's 400-day window: D7 then reported nothing (review probe). Now every posting
also inserts a `document_posting` row (document_id PK, number, posted_hash, posted_by, posted_actor, posted_at and
`content`, the canonical JSON the hash covers), append-only for the app login (`Grants::APPEND_ONLY`), and the
`document.post`/`document.reverse` audit rows carry the hash too. D7 now checks (1) every posted or reversed document has
its record and its number, posted_hash, posted_by, posted_actor and posted_at still equal it, and no unposted document has
one; (2) each record's content hashes to its posted_hash; (3) EVERY posted document's header and lines, recomputed by id in
batches of 500 (no time window), hash to its RECORD's posted_hash. The posted content can be read back from the record
after a tampering. Cost: one extra row per posting (~15 a day) and a full re-hash nightly (estimated well under a minute at
7 years' volume); if it ever grows, rotate by id. `document_line` keeps FULL rights: detection, not prevention, is the
control for posted lines (as for every column-granted table).

**I34. An approval's posting is audited under the reviewer who performed it (amends I19).** The posting stays the
requester's (`posted_by`, `posted_actor` and the ledger's actor, as the spec says), but the `document.post` (or
`document.reverse`, I32) audit row now has the reviewer as actor, with their IP, and `on_behalf_of` (the requester) and
`approved_by` in its detail: a forensic query by actor no longer shows a posting by someone who was not there.

**I35. Staff administration hardening (amends I10, I12, I13, I15, U23).**
- **`StaffAdmin::reset()` follows `setRoles()`'s caller rules** (`authorise()`: admin only, never one's own account, staff
  only): it checked nothing, so a future screen calling it would have let a buyer re-issue their own one-time password or
  switch anyone off (review probe). Only `bin/reset_staff.php` calls it today, as a system caller, which still passes.
- **Placeholder accounts are never switched on or given a role by a staff caller** (409 `placeholder_account`):
  `create()`, `setRoles()` when it adds a role, `setActive(true)` and `reset(--activate)`. A placeholder is an account whose
  e-mail is under `.invalid` (U23's definition, `enable_https.sh`'s check). An admin could switch the `.invalid` mapping_lead
  back on in the browser and give it reviewer, a working second identity that defeats the two-person rule, which
  `enable_https.sh` checks only once. Taking a role away stays allowed; the CLI (a system caller, root on the server) stays the
  break-glass. The People list warns while a placeholder is active, and an inactive placeholder's page has no switch-on form.
  The test fixtures now give staff `@test.example` addresses (a real person's), keeping `.invalid` for placeholders.
- `ck_staff_role_order`: a grant is never revoked before it was given. **I10 reworded:** the authoritative record of who
  changed which role is the insert-only `audit_log` (`staff.roles`); the app login can still clear a `revoked_at` (it must
  write it), which the history alone would not show.
- `bin/mint_vpg.php --staff` checks `Permissions::can(..., 'mapping.approve')`, so a set that breaks I12 (admin +
  mapping_lead, only admin SQL can write one) is refused up front instead of failing part-way in DecisionService.

**I36. The file store, hardened (amends I23).**
- **Staging is detect-only for a file's content until it is sealed.** `chattr +a` on a shard stops rm and mv only: a file
  linked in is owned by whoever stored it (php-fpm's www-data for browser uploads, root for the CLI), mode 0440, and its
  owner can chmod it back and rewrite it in place (review probe). New `deploy/staging/seal_file_store.sh` (root, every minute
  from `cw-staging.cron`) makes every stored file root:www-data 0440 and `chattr +i` (immutable: nobody, root included,
  changes, removes or renames it until the flag is taken off); `install_file_store.sh` seals what is there and now also
  checks that a sealed probe cannot be rewritten in place by www-data or root. Until the sweep (≤ 1 minute), and against
  someone who removes the flag, the controls are detection: re-hash on every read and **`bin/verify_files.php`, now
  scheduled nightly** (04:27, syslog tag `cw-files`). The Object Lock bucket before live is still the real control.
- **Downloads carry the SNIFFED type's extension** (`FileStore::downloadName`): a name whose extension is not one of the
  sniffed type's gets that type's first extension appended, so 4 KB of text followed by `<html><script>` (sniffed text/plain,
  allowed) named `duty.hta` is saved as `duty.hta.txt`, never run by mshta. `cleanName` also removes Unicode format
  characters (`\p{Cf}`: the bidi overrides and isolates that make `invoice<RLO>fdp.bat` read as a PDF, zero-width
  characters). text/plain stays allowed: libmagic reports many CSV exports as text/plain.
- **`store()` checks and keeps a private copy** (mode 0600 in the temp directory, read at most 25 MiB + 1): size, type and
  hash are taken from bytes nobody else can change, so a source swapped between the checks cannot slip past them.
- **Retention per attachment:** `document_file.retain_until` (CHECK ≥ attached + 7 years) is set at every attachment, and
  `FileStorage::extendRetention()` (new; the local backend records retention on the rows only and checks the key exists) is
  called with it, so the same certificate attached to a document five years later is kept for that document too. A bare
  re-store of the same content does not extend anything (no record depends on an unattached file). Notes for the Object
  Lock backend in the interface's docblock: a conditional put (`If-None-Match: *`), read the OLDEST version and treat a
  delete marker as missing, no DeleteObject/DeleteObjectVersion/governance bypass for the app role, retention set at put and
  only ever extended.
- `stored_file.storage_key` is indexed (the verifier's orphan lookup scanned the table per batch of 500 keys).

**I37. Review points declined or deferred, with why.**
- *Saving a round trip in `assignValueSeq`* (optional, review 1): declined for now (I30).
- *Forcing a password change when an admin switches an account back on* (optional, review 2): declined. The admin's action is
  audited, and a new one-time password can only be issued on the server (secrets never reach a browser, I13); a person whose
  credentials may have leaked is reset with `bin/reset_staff.php --new-password --new-totp`.
- *An invariant comparing each person's live roles with their last `staff.roles` audit row* (optional, review 2): deferred;
  the audit rows have three shapes (backfill, create, change) and the record itself is authoritative (I35).
- *Dropping text/plain from the allowed types* (review 3): declined (I36: CSV detection); the download extension removes the risk.
- *Splitting documents under the review/approval limits, and "a supplier document" being any external_ref* (review 3,
  minor): real, but it belongs to decision 11 and the I-4 ADJ handler, since no type is live in I-1. Written into
  `DocumentHandler::approvalUnits`'s contract: evidence must be verifiable (an attached `supplier_invoice`/`delivery_note`
  file or a posted SINV/GRN), and a person's positive units of the day should count together.
- *Scoping `/ui/files/{id}` to files attached to a document the person may see* (review 3, nit): deferred to the I-2/I-3
  upload screens; in I-1 `documents.view` sees every document, and files arrive only through the CLI.
- *A test that a deadlock-victim retry renumbers cleanly*: the review's `DeadlockRetryProbeTest` showed it does (a new
  `transactionSerial()` drops the stale pending rows); covered by the existing serial logic and `ValueSequenceRaceTest`, no
  new test.

Measurements (2 Oct 2026, slot `i1fx`, the whole I-1 tree with these fixes): full suite `scripts/remote.sh i1fx
vendor/bin/phpunit` green (the skips are the HTTP Api*/Ui* tests of slots api and ui); the hammer `scripts/remote.sh i1fx php
tests/concurrency/hammer.php --seed=20261002`: RESULT: PASS (57 checks), 86 s, 0 deadlocks surfaced or retried in every
scenario; scenario 4 at 62 operations/s (I9: 60 after C0; i1do: 61), scenario 5 at 47 calls/s and 69 on_hand rows/s with 226
polls and no gap; `LockOrderTest`'s 2,000-line goods-in wrote its feed rows within 54 ms (bound 250 ms), the whole movement
12.8 s; `NumberSeriesRaceTest` 1,200 allocations by 12 workers in 4.8 s, 0 deadlocks; `PostingRaceTest` 64 postings and 60
staff moves in 5.3 s, 0 deadlocks retried or surfaced.


## Inventory Phase I-2 (slot `i2su`, suppliers)

Phase I-2 task 1 of 3 (`docs/inventory-modules-plan.md` IM4; the I-2 build spec of 2 Oct 2026): the settings, the VAT
codes, suppliers and supplier items with the blocking activation approval, the ERPNext supplier seed importer, every
Phase I-2 permission and menu item, and the shared groundwork of the next two tasks (one-effect forms, uploads, a CSV
reader). Numbered I38–I47. Nothing books stock. Code: `migrations/0009_suppliers.sql`, `src/Settings.php`,
`src/Suppliers/{Suppliers,SupplierItems,SupplierInvariants,ErpSeedImport}.php`, `src/Output/CsvReader.php`,
`src/Ui/FormOnce.php`, `src/Ui/Controller/{Suppliers,SupplierItems}Controller.php`, `src/Ui/views/{suppliers,supplier,
supplier_form,supplier_items,supplier_item,supplier_item_form,settings}.php`, `bin/{settings,import_erp_suppliers}.php`;
changed: `src/Auth/Permissions.php`, `src/Ui/{Kernel,Context,UiRequest}.php`, `src/Ui/Controller/{Reviews,Reference,
Item}Controller.php`, `src/Ui/views/{reviews,item}.php`, `src/Documents/Documents.php` (`lockTask`), `src/Files/FileStore.php`
(kind `supplier_check`), `src/Schema/Grants.php`, `src/Invariants.php`, `public/ui/assets/app.css`; tests
`tests/Unit/{CsvReader,SettingsParse}Test.php`, `tests/Integration/{Migration0009,Settings}Test.php`,
`tests/Integration/Suppliers/*` (with `SupplierTestCase` and `supplier_worker.php`), `tests/Integration/UiKernel/
SupplierScreensTest.php`, and updates of `PermissionsTest`, `MenusTest`, `GrantsTest`, `ReviewRulesTest`,
`tests/Support/{KernelBrowser,TestDb}.php`, `UiTemplatesTest` and `ReferenceScreensTest` (the last two outside the task's
file list: see I47).

**I38. Settings; decision 9 — one company owns all warehouse stock (provisional, owner to confirm).** `app_setting` (0009)
holds typed settings (string, text, int, decimal, bool, date) as JSON, with `provisional` (1 = a default the owner has
not confirmed) and the owner `decision` it implements; `CW\Settings` reads them once per instance (`get`, `company`,
`all`); an unknown key is a `\LogicException`. The table is READ-ONLY for the app login (`Grants::READ_ONLY`): only
`bin/settings.php --set ... --admin` changes a value (audit `setting.change {key, before, after, reason}`, `updated_actor =
system:settings`). Decision 9 is built as: **one company buys and owns all warehouse stock**, its legal and trading name,
address, company number, VAT number, purchasing phone and e-mail and the delivery address come from `company.*` settings,
**empty placeholders** until the owner provides them, and `company.confirmed = false` makes every PO PDF carry "COMPANY
DETAILS NOT CONFIRMED — DO NOT SEND" (the pos task prints it); one valuation pool (`valuation_pool = 'default'`, I5).
*(Since 0013 the company details are versions in `company_profile`, added and confirmed by a reviewer on the Company details
screen; the `company.*` settings are gone: I90–I99.)*
Choices the spec left open:
- `--set` without `--admin` is refused before connecting (exit 1): connecting as the app login to a test schema that has no
  grants would otherwise fail as "cannot run" (exit 3) and hide the real reason.
- A decimal is stored as a JSON **string** (its digits exactly as typed); a seeded JSON number (the reorder task's `0.50`)
  is read through its text, so `get()` never returns a float. Empty (`""`) is "not set": '' for string/text, null for
  int/decimal/date; a bool is always true or false.
- `--value` is accepted for a text setting too (one line); several lines come with `--value-file` (CRLF read as LF, the
  file's final line break dropped). Sending the current value writes nothing ("unchanged").
- `--confirmed` (not in the spec): records that the owner confirmed a value (provisional = 0, audited `confirmed: true`),
  so the screen's "provisional" tags can disappear one decision at a time. Changing a value does not confirm it.
- `Settings::RULES`: every `*_days` key 0–120 (`suppliers.approval_due_days` 1–120), `company.email` an address,
  `po.default_vat_code` an active VAT code, `reorder.short_weight` / `promo_price_drop` 0–1, and the reorder task's keys
  bounded in advance (the rules of later tasks' keys live here so that the CLI checks them the day the keys arrive).
- The screen `/ui/reference/settings` (every role) lists every setting (value, provisional, decision, description,
  updated), the document types' rules (review, due days, approval, what a rejection does: `reject_action`, read as
  "reversed" until the pos task adds the column) and the VAT codes. `vat_code` (S 20 %, R 5 %, Z, E, RC, OS) is read-only
  and changed by migration.

**I39. Decision 25 — no supplier bank details (provisional, owner to confirm).** No bank, IBAN, SWIFT/BIC, sort-code or
account-number column exists on `supplier`, `supplier_item` or `supplier_item_price` (`Migration0009Test` asserts the
column names); the forms say so; an ERPNext export with a bank column is imported without it (the run's summary lists it
under `ignored_columns`); a supplier field named `bank_account` is refused (400 `bad_field`). Supplier payments stay outside
CW until a payments part is built.

**I40. Decision 11 for suppliers — blocking approvals by a second person (provisional, owner to confirm); the I-2
permissions and menus.** Activating a new supplier, re-activating an inactive one, and an overseas supplier's import route
(how and where UK duty stamps are applied before arrival) each need a **blocking** approval: a `review_task` with
`subject_type = 'supplier'`, kind `approval`, reason `new_supplier` / `reactivation` / `import_route`, due
`suppliers.approval_due_days` (3) later. Identity changes of an active supplier open a **non-blocking** review (kind
`review`, reason `supplier_changed`, due 7 days; `suppliers.change_review = true`): approving it acknowledges the change,
rejecting it deactivates the supplier.
- **Who decides** (`Suppliers::refusal`, shown on the card and the queue, enforced with 403): never admin
  (`admin_cannot_review`), only `suppliers.approve` (reviewer; `role_not_allowed`), never the task's opener, the supplier's
  creator or the person who last changed an approval-relevant field (`own_supplier`: "You asked for this approval / You
  created this supplier / You last changed this supplier: another reviewer must decide."); `ck_review_task_not_own` holds
  the opener in SQL. A supplier created by the ERPNext import is "created" by the `--staff` buyer, who can never approve it.
- `Permissions::MAP` gains `suppliers.view` (buyer, purchasing_manager, goods_in, purchasing_desk, stock_controller,
  reviewer, accountant, auditor, manager), `suppliers.manage` (buyer, purchasing_manager), `suppliers.approve` (reviewer),
  `purchasing.view` (buyer, purchasing_manager, goods_in, purchasing_desk, reviewer, accountant, auditor, manager),
  `reorder.view` (buyer, purchasing_manager, reviewer, auditor, manager), `reorder.manage` (buyer, purchasing_manager);
  `doc.PO.post` is unchanged. Admin holds none of the manage / approve / post permissions (`PermissionsTest`).
- The Purchasing section is Suppliers (live, `/ui/purchasing/suppliers`), Purchase orders (`purchasing.view`), Reorder
  list and Sales history (`reorder.view`), the last three placeholders "coming in Phase I-2" until the pos and reorder
  tasks; Reference gains Settings. The acceptance menus therefore change (amends I14): buyer {Items, Purchasing, Documents,
  Reference}; purchasing desk {Items, **Purchasing**, Receiving, Trade, Documents, Reference}; reviewer {Items,
  **Purchasing**, Document reviews, Documents, Reference}; admin {Linking, Items, Reference, Admin}; auditor {Linking,
  Items, **Purchasing**, Documents, Accounts, Reference, Admin}. stock_controller also sees Purchasing (Suppliers only).
- The review queue (`/ui/documents/reviews`) lists supplier tasks (type "Supplier", linking to the card): activations and
  import routes under "Waiting for approval (blocking)", change reviews under "Posted, waiting for review (and changed
  suppliers)", oldest first with the document tasks. The badge `reviews_open` = `Documents::decidableCount` +
  `Suppliers::decidableCount` (only for holders of `suppliers.approve`). Supplier tasks are decided on the supplier's
  card: `Documents::approve/reject/withdraw` refuse them with 409 `supplier_task` ("supplier approvals are decided on the
  supplier's page"; was `subject_not_built`, I19).

**I41. Decision 12 — CW does not write its cost into the sites (provisional, owner to confirm).** Not built in Phase I-2:
the setting `costs.site_writeback = false` records the default and nothing reads it yet.

**I42. The supplier state machine, as built (spec §5.2–§5.5).**
- draft → (request activation, complete: name; address line 1, postcode, country; e-mail or phone; payment terms; the
  due-diligence check on / by / next review; an overseas supplier's import route — else 422 `supplier_incomplete` with
  `detail.missing`) → pending_approval → (a second person approves) active → (deactivate, reason 3–500) inactive →
  (request: reason `reactivation`) pending_approval → active. Withdraw (the requester only, 403 `not_requester`) or reject
  (note 3–500) sends a new supplier back to draft and a reactivation back to inactive. A pending supplier cannot be
  changed (409 `supplier_pending`). Every write carries the version (409 `version_conflict`) or a state check; nothing
  changed writes nothing.
- **Reactivation and `ck_supplier_inactive`** (spec silent): the CHECK ties `deactivated_at` to status inactive, so asking
  for a reactivation clears `deactivated_at` (keeping `deactivated_by` and the reason for the card); withdrawing or
  rejecting it makes the supplier inactive again **from that moment**: `deactivated_at` = now, `deactivated_by` = the person
  who withdrew or rejected, the reason "reactivation withdrawn by the requester; earlier: …" / "reactivation rejected:
  <note>". The approval clears every `deactivated_*` field and sets `approved_by/at` to the new approver.
- **Approval-relevant fields**: name, legal name, company number, VAT number, the address lines, city, postcode, country,
  e-mail, overseas, import route, **the import-route evidence file** and the due-diligence fields (the DD evidence file
  included): a change sets `details_changed_by/at`. Terms, lead days, VAT code, contacts and notes are not.
- **The import route** (spec: "is_overseas or import_route changed on an overseas supplier"): on an active supplier that
  was or becomes overseas, a change of `is_overseas`, `import_route` or the route's evidence file clears
  `import_route_approved_*` and, while it stays overseas, opens the blocking `import_route` approval unless one is open
  (the open one covers further changes; its decider sees the current route). Made UK: the approval is cleared (the CHECK
  needs overseas) and an open route approval is withdrawn ("the supplier is no longer overseas"). An active supplier made
  overseas without a route: 422 `supplier_incomplete`. Rejecting a route approval records the note; the route stays
  unapproved (POs refused) until it is changed again. A route change is the route approval, not also a change review:
  `supplier_changed` opens only for the other identity fields.
- **Deactivation withdraws the supplier's open tasks** (a change review, a route approval; note "the supplier was
  deactivated"): a reactivation is approved afresh and re-approves the route with it, and the one-open-task-per-kind rule
  (`open_key`) cannot block the reactivation's approval. Rejecting a change review does the same.
- An inactive supplier's identity changes open no review: its reactivation approval is that review.
- Approving or rejecting moves the version and records `last_decision_note` (forms drawn before are redrawn, 409).
- **Locks**: the supplier row `FOR UPDATE`, then its `review_task` rows `FOR UPDATE` (a decision reads the task's subject
  unlocked, then locks the supplier, then the task, and re-checks it is open: 409 `task_closed`). No supplier code locks a
  document row or books stock (spec §6.9).
- **Invariants S1–S5** (`SupplierInvariants`, appended to `Invariants::check`, ≤ 50 per check): S1 also checks that an open
  route approval is on an active overseas supplier and an open change review on an active supplier, and that open tasks
  carry known reasons; S2 also checks that `approved_by` is the latest activation's decider. A merged item is not a
  violation (S5; the screens tag it "merged into CW-x").

**I43. Supplier items.** Created in any supplier status (a buyer prepares the items while the activation waits); the item
must exist and not be merged (422); one row per (supplier, item, central pack) (409 `duplicate_pack`), a supplier code once
per supplier, compared without case (409 `duplicate_supplier_code`); defaults each / 1 / MOQ 1 / multiple 1. A first price
may come with the creation (the screen's optional field; recorded as a manual price in the same transaction).
- **Preferred** (spec: unset the others, then set; retry a 1062 once, then 409): `setPreferred` and `update(is_preferred)`
  unset the item's other preferred rows and **move their version** (a form drawn before cannot set one back unseen), then
  set this one; the UNIQUE `preferred_sku_id` decides a race and the loser retries once (then wins: it unsets the winner;
  409 `preferred_busy` if it collides again). An inactive row is never preferred: switching a row off drops its flag, and
  preferring an inactive row is 422 `supplier_item_inactive`.
- **`pack_in_use`** (a changed pack once a posted PO line uses the item) is left to the pos task, which owns `po_line`:
  `SupplierItems::checkPackChange()` is the hook, empty today.

**I44. Prices: integers only, the last-price rule.** A pack price is GBP excluding VAT per purchase unit, ≥ 0, at most 10
digits and 4 decimals (`£`, spaces and thousands commas are ignored; 422 `bad_price` otherwise); the unit price is pack
price / units per pack rounded **half-up to 6 decimals with integer arithmetic** (`(e4 × 200 + upp) div (2 × upp)`; no
bcmath, no floats). A manual price's `effective_on` is on or before today (UK date; default today). The last price
(`last_pack_price/on/source`) moves when the new price's date is on or after the current one's (the same date: the newer
row wins), so it always mirrors the newest (effective_on, id) row of source import / manual / invoice (S4); a PO price
(pos task) goes to the history as `po` and sets only `last_po_*`.

**I45. Reading people's CSV; the ERPNext seed import.** `CsvReader` (spec §5.6) as specified, plus: rows are numbered by
CSV record after the header (blank records keep their numbers, so row N is spreadsheet row N + 1); rows of empty cells are
skipped like blank lines; **trailing empty cells beyond the header are tolerated** (a spreadsheet adds them) while a
non-empty extra cell is 400 `bad_file`; a short row is padded; the byte cap applies after gunzip (a zip bomb is 413
`too_large`), the row cap is 413 `too_many_rows`. `ErpSeedImport` (spec §5.7):
- One `import_run` per file (sha256); "already imported" counts only a **done, non-dry** run of the same kind, so a dry run
  never blocks the real one and a failed run can be repeated. A real run is ONE transaction with a **savepoint per row**:
  a refused row rolls back to its savepoint and is reported, an unexpected error rolls the file back (run `failed`). A dry
  run processes everything in a transaction that is rolled back (it writes only its `import_run` row) and reports what the
  real run would do.
- Suppliers: a new supplier is named after its ERPNext name unless the file names it; an existing ERPNext name is compared
  only on the fields the file gives (the report lists the fields that differ). `--request-activation` asks for every
  supplier of the file that ends draft or inactive and complete, as `--staff`. The due-diligence check is **not** an
  import column (it is captured fresh in CW): a buyer completes it on the card; `--request-activation` is for a re-run with
  `--update-blank` or a supplier completed by hand before the import.
- Items: pass 1 resolves every row (no writes), pass 2 fails both rows of an item made preferred twice, pass 3 upserts by
  (supplier, item, central pack) — the same pack again updates the fields given — and records the price (source `import`,
  `source_ref` = the file's reference or `import_run:<id>`). A Vape and Go listing must be `mapped` (an unmapped or
  quarantined one is "not linked"); a `vpg_code` that names several variants in the listings export fails as ambiguous.
- Exit codes: 0 done (also "already imported"), 1 a row failed or a file was refused (missing required column), 2 usage
  (also a `--staff` who may not manage suppliers, checked up front), 3 cannot run. Files up to 32 MiB.

**I46. One effect per form, uploads (the shared I-2 groundwork, spec §8.2).**
- `Ui\FormOnce`: a creating form carries `form_key` (32 hex, `random_bytes(16)`), run through `Idempotency::run` under
  `ui:<staff id>:<form_key>` (staff share the idempotency scope `source = 'staff'`); the request hash covers the posted
  fields (without csrf and form_key). A bad key is 400 `bad_form_key`; a replay returns the stored 303 (one supplier however
  often the form is sent); the same key with other values is 422 `idempotency_key_reused` ("this form was already sent
  with other values: reload"); a refusal stores nothing, so the corrected form is sent again with the same key. Used for a
  new supplier, a new supplier item and a manual price; every other supplier POST carries the version (409 redraws the
  page with the current data: "changed since you opened it") or relies on a state check.
- `UiRequest` gains `files` (`{path, name, size, error}` per file field; `fromGlobals()` keeps only `is_uploaded_file()`
  entries and the ones PHP refused for their size, so a screen can answer 413) and `file()`. Over 2 MiB the UI pool either
  refuses the file (`UPLOAD_ERR_INI_SIZE`: the controller answers 413) or, above `post_max_size`, drops the whole body: the
  kernel answers **413 before the CSRF check** when a POST arrives with no fields, no files and a Content-Length over 2 MiB
  (otherwise it would read as "this form has expired"). `KernelBrowser::postMultipart` builds such requests for tests.
- The supplier evidence upload (multipart `file` + `kind` dd / import_route + version) stores through `FileStore::store`
  (new kind `supplier_check`; type sniffed, deduplicated) and records the file id with `Suppliers::setEvidence` (an
  approval-relevant change); when the file store is not set up (cw_staging today) the card answers **503** "the file store
  is not set up on this server". `UiTemplatesTest` now also checks that a form with a file field is a multipart POST.

**I47. Deviations from the spec, measurements, open items for the pos task.**
- *Files outside the task's list:* `tests/Unit/UiTemplatesTest.php` (its menu test asserted "Suppliers · coming in Phase
  I-2"; it now asserts the live link, and gained the multipart check) and `tests/Integration/UiKernel/ReferenceScreensTest.php`
  (the Reference menu now ends with Settings) had to follow the §3 menu change; a support base
  `tests/Integration/Suppliers/SupplierTestCase.php` and the race worker `tests/Integration/Suppliers/supplier_worker.php`
  were added.
- *Supplier items in any supplier status* (the spec's §5.4 settles its own question that way): built so.
- *0009 is exactly §4.1* (a header comment added).
- *Additions the spec did not ask for:* `bin/settings.php --confirmed` (I38); trailing empty CSV cells tolerated (I45);
  the version of a supplier item whose preferred flag another write unset moves (I43); S1/S2 check a little more than
  listed (I42); the multipart check in `UiTemplatesTest` (I46).
- *Measurements* (2 Oct 2026, slot `i2su`): the full suite `scripts/remote.sh i2su vendor/bin/phpunit`: 537 tests (482
  before this task), 11,202 assertions, 73 skipped — the HTTP Api*/Ui* tests of slots api and ui — green in 5 min 30 s (the
  third full run). The two earlier full runs each had one failure of a timing-bound test in code this task does not touch,
  each passing alone right after: `DbTest::testRealDeadlockIsDetectedAndRetried` ("second session never started waiting
  for the lock" within 10 s; then 3 × OK) and `LockOrderTest`'s 2,000-line goods-in (feed rows within 903 ms against a
  250 ms bound; alone 62–65 ms, 154 ms in the green run): the shared staging cluster was slow for a moment. The supplier
  tests alone (36 + 14 screen and grants tests) take about 35 s. `SupplierRaceTest`: two reviewers
  approving one activation at once → one approved, one 409 `task_closed`, 0 deadlocks; with the first holding its
  transaction 800 ms the second waited for the supplier row (≥ 400 ms) and then got 409; two buyers preferring two supplies
  of one item → exactly one preferred (with a hold: both calls 200, the later one wins after its one retry). S1–S5 on 50
  suppliers, 15,000 supplier items and 45,000 price rows (a probe on the slot's schema, not kept): **830 ms** (best of 3),
  almost all of it S4's newest-price lookup per item; nightly that is fine; if supplier items grow past ~100k, rewrite S4
  as one ROW_NUMBER() pass.
- *Open for the pos task:* `SupplierItems::checkPackChange()` (409 `pack_in_use`); the PO approval refusing a supplier
  that is not active or an overseas supplier without `import_route_approved_at`; the PO PDF banner from
  `Settings::company()['confirmed']`; the supplier card's "Recent purchase orders"; `po.*` settings rows (RULES already
  bound `po.default_vat_code` and `po.over_delivery_tolerance_pct`); `ReferenceController::settings` shows
  `reject_action` once 0010 adds it; the queue's `?type=` filter must keep supplier rows (type "Supplier") apart.


## Inventory Phase I-2 (slot `i2po`, purchase orders)

Phase I-2 task 2 of 3 (`docs/inventory-modules-plan.md` IM5; the I-2 build spec of 2 Oct 2026, §2.3, §4.2, §6, §8.1–8.3,
§9.2): purchase orders as the first real document type, their approval and review rules, the PDF, the lines file (CSV and
XLSX), the receipt API Phase I-3 will call, the ERPNext open-PO importer and the screens. Numbered I48–I59. Nothing books
stock. Code: `migrations/0010_purchase_orders.sql`, `src/PurchaseOrders/{PurchaseOrders,PurchaseOrderHandler,PurchaseInvariants,
PurchaseOrderPdf,PoLinesFile,PoMath,ErpOpenPoImport}.php`, `src/Output/{XlsxWriter,XlsxReader}.php`,
`src/Ui/Controller/PurchaseOrdersController.php`, `src/Ui/views/{purchase_orders,purchase_order,purchase_order_edit}.php`,
`bin/{document_rules,import_erp_open_pos}.php`, `composer.json`/`composer.lock` (openspout); changed:
`src/Documents/{DocumentHandlers,Documents}.php`, `src/Output/Fpdf.php`, `src/Suppliers/SupplierItems.php`,
`src/Auth/Permissions.php`, `src/Ui/{Kernel,UiRequest}.php`, `src/Ui/Controller/{Reviews,Documents,Suppliers,Reference}Controller.php`,
`src/Ui/views/{reviews,document,documents,supplier}.php`, `src/Schema/Grants.php`, `src/Invariants.php`,
`public/ui/assets/app.css`; tests `tests/Unit/{PoMath,PoLinesFile,XlsxRoundTrip,PurchaseOrderPdf}Test.php`,
`tests/Integration/{Migration0010,DocumentRulesCli}Test.php`, `tests/Integration/PurchaseOrders/*` (with
`PurchaseOrderTestCase` and `po_worker.php`), `tests/Integration/UiKernel/PurchaseOrderScreensTest.php`, and updates of
`KernelUiTestCase`, `PermissionsTest`, `MenusTest`, `ReviewScreensTest`, `DocumentLifecycleTest`, `ReviewRulesTest`,
`GrantsTest` and (outside the task's list: I59) `Migration0008Test`, `Migration0009Test`, `SettingsTest`,
`SupplierScreensTest`, `UiTemplatesTest`.

**I48. Decision 11 for purchase orders (provisional, owner to confirm).** The PO `document_type` row (0010) is: **post first,
a second person reviews every PO within 7 days** (`review_rule 'all'`, `review_due_days 7`: the reviewer's weekly routine is
Document reviews filtered to "Purchase order"); **a PO whose net total (excl. VAT) is above £10,000 waits for a blocking
approval** before it is numbered (`approval_rule 'over_value'`, `approval_limit_units 10000` in WHOLE GBP; the handler's
approval units are the net rounded UP to whole pounds, so £10,000.00 posts at once and £10,000.01 = 10,001 waits); and
**rejecting a PO's review records the rejection and cancels nothing** (`reject_action 'record'`: the order may already be
with the supplier; the buyer then cancels or amends it, I49). The limit and the review days change with
`bin/document_rules.php --admin` (I57), never by editing code. The cancellation of a PO is reviewed like a PO (rule `all`;
its review units are 0: it books nothing). Over-delivery tolerance at goods-in: `po.over_delivery_tolerance_pct = 10`
(provisional, decision 11; read from Phase I-3). `po.default_vat_code = 'S'` (a line's VAT code when the supplier names
none) and `po.terms` (printed under every PO, including the UK duty-stamp sentence) are provisional settings too. The
creator, the requester and the poster never decide (I19); admin never posts or decides (I12).

**I49. `reject_action`: a rejected review that only records (amends I19).** `document_type.reject_action` ENUM('reverse',
'record') (0010; every type but PO keeps `reverse`). `Documents::reject` on a review: when the document is a reversal (I31)
**or** its type's `reject_action` is `record`, the task is rejected, the document's `review_state = 'rejected'` and its
version moves, nothing is reversed or booked; audit `document.reject` with `booked: false` and, for a record type,
`recorded: true`. The generic document page says so ("Rejecting records the rejection and changes nothing else"; the
button reads "Reject", not "Reject and reverse") and a new notice `rejected_recorded` exists on both the document and the PO
pages. The PO page shows "Rejected at review by X: note" and the list filters "rejected at review". Why not reverse a PO:
reversing it would tell the system it is cancelled while the supplier is already picking it; the person who can phone the
supplier (the buyer) decides between cancel and amend.

**I50. The PO as a document type; immutability starts at approval.** A PO is a `document` (type PO, warehouse MAIN, doc_date
= the order date) with a `purchase_order` header extension (supplier, `state`, totals, the company and supplier snapshots,
sent / closed) and one `po_line` per `document_line` (pack, packs, pack price, VAT code and rate, the reorder suggestion,
received units), removed with its draft line (`ON DELETE CASCADE`: `Documents::setLines` replaces draft lines). Mapping:
draft (its creator edits it freely) → **approve** = `Documents::post` (number `PO-000123`, `posted_hash` +
`document_posting`, the handler's `po_posting` anchor, the review task) or `awaiting_approval` above the limit → state
`approved` → `sent` (`markSent`, re-sending allowed) → `part_received` / `received` (`applyReceipt`, I-3) → `closed`
(part-received only). Cancelling a draft is `cancelDraft`; cancelling a posted PO is `Documents::reverse` — a "cancellation"
document numbered in the same series (it takes a number, like every reversal, I18) — refused with 409 `po_has_receipts` once
a line has receipts (close it instead) and 409 `po_completed` when received or closed. A reversal PO document has no
`purchase_order` (P1).
- **Why immutability starts at approval, not at "sent":** the number, the review and the supplier's copy all refer to the
  approved content; the review is of what was approved; an order is often phoned or e-mailed minutes after approval, and
  "sent" is a fact recorded afterwards, so a sent flag cannot be what fixes the content. Every change after approval is a
  reversal plus a new PO (I17, I18): amend = **reverse (reason `po_amended`) + a new draft copied from it** (source `amend`,
  `amends_document_id`, the original's lines, packs, prices, supplier quote reference and notes) **in ONE transaction**, so
  there is never an order cancelled without its replacement or two live versions; the new PDF says "Amends PO-x".
- **The write-once anchor (P2, like I33):** at approval the handler writes `po_posting` (the canonical JSON of the immutable
  `purchase_order` fields — supplier, source, expected date, amends, currency, totals, both snapshots — and every `po_line`
  field but `received_units`, with its sha256), append-only for the app login. `document_posting` already anchors the
  generic lines; `po_posting` anchors what only the PO tables hold (packs, pack price, VAT, the snapshots).
- **Snapshots (decision 9):** `company_snapshot` = `Settings::company()` (confirmed flag included) and `supplier_snapshot`
  (code, name, legal name, address, VAT number, contact, e-mail, phone) at approval: a posted PO's PDF prints them, not
  today's settings or supplier record; a draft prints the current ones.
- **Prices at approval:** each item line with a supplier item adds a price-history row `source 'po'` (`source_ref` = the
  number, `document_id`, `effective_on` = the order date) and sets `supplier_item.last_po_*` (only when the order date is on
  or after the current `last_po_on`), never the last price (I44, S4): the next PO is pre-filled from the last invoice or
  manual price, not from the previous PO.
- **No stock:** the handler books nothing; `Movements::reverseDocument` on its cancellation finds no ledger rows.
- Grants (§4.4): `purchase_order` NO_DELETE, `po_posting` APPEND_ONLY, `po_line` FULL.

**I51. PO money with integers only.** `PoMath`: a pack price is held as e4 (1/10,000 GBP), the line amount = half-up(packs ×
pack price, 2), the unit cost = half-up(pack price / units per pack, 6) (`(e4 × 200 + upp) div (2 × upp)`, as I44), the line
VAT = half-up(amount × rate / 100, 2) **per line**, net = Σ amounts, VAT = Σ line VAT (so the PDF's per-code VAT adds up to
the total exactly), gross = net + VAT, the approval units = ceil(net) in whole GBP. Limits (spec silent): a pack price ≤
£9,999,999.9999, a line ≤ £99,999,999.99, packs 1..1,000,000, units per pack 1..100,000, a line ≤ 10,000,000 units
(`Documents::MAX_QTY`): every product stays far inside a 64-bit integer, so bcmath is not needed. A charge line (delivery,
...) is one "pack" of 1 whose pack price is its amount, in whole pence and > 0. Invariant P3 recomputes the formulas **in
SQL with integer operands** (`CAST(pack_price * 10000 AS UNSIGNED)` then `DIV`): a DECIMAL division rounds its quotient at
`div_precision_increment` (4) decimals, so a quotient of n + 0.99996 (units per pack ≥ 10,000) would truncate to n + 1, and
rounding to 8 decimals first turns 0.0499 / 99,999 = 0.000000499 into 0.000001 (PoMathTest has that case).

**I52. The PurchaseOrders service as built (spec §6.3).**
- Every write: a staff caller holding `doc.PO.post` (never admin), roles re-read inside the transaction, ONE
  `Db::transaction`, the document row locked first and its version checked and moved; a draft changed only by its creator
  (403 `not_creator`); anyone allowed to post POs may approve or cancel another's draft (the document base's rule).
- `saveDraft` moves the version by 2 (`updateDraft`, then `setLines`, as the spec says). The supplier changes only while the
  draft has no lines (409 `supplier_has_lines`). A line without a supplier item is linked automatically to the supplier's
  active supplier item of that item and pack when one exists; `save_item` creates it (needs `suppliers.manage`: buyers and
  purchasing managers have both). A VAT code must exist and be in use; a draft line keeps the rate of the moment it was
  saved, and approval refuses a line whose code's rate changed since (422 `vat_rate_changed`: save again).
- `addLine` resolution (spec): digits → a usable barcode (a case barcode with `units_per_scan` k > 1 takes the supplier item
  of pack k; else the preferred or only supplier item; **several and none preferred → `choices`** (spec silent); none → a
  line in packs of k, purchase unit `case`); then this supplier's code (any case); then a CW code (`CW-123`; the `CW` prefix is
  required here so that digits stay barcodes); then a search (`choices`, items this supplier sells first, ≤ 20). The same
  supplier item already on the draft gets the packs added (a repeated scan adds a pack). `addSupplierItem` (not in the spec)
  serves the editor's "Choose" buttons for a supplier item.
- `markSent`: state approved or sent; with a file store the PDF as sent is stored (kind `generated_pdf`) and attached (role
  `generated_pdf`) in the same transaction and `sent_file_id` set; without one it is skipped (the screen says so) and an
  earlier archived PDF stays referenced. The document's version moves, so the same send form twice is 409.
- `cancel`: a draft → `cancelDraft` (the reason's label and the note); a PO waiting for approval → withdrawn and cancelled
  by its requester only (others: 409 `awaiting_approval`); a posted PO → `Documents::reverse` with a PO reason
  (`not_needed`, `supplier_cannot_supply`, `entered_in_error`, `duplicate`, `other` with a note). `withdraw` (not in the spec)
  turns a waiting request back into a draft (the requester), with its own route.
- `copy` from any PO (a cancellation copies its original); `copy` and `amend` drop a supplier item that is no longer active
  or no longer this supplier's (the line keeps its item, pack and code) and a VAT code no longer in use (the default
  applies). An item merged since stops the save with 422 `merged_item` (the document base refuses merged items) — the
  editor shows the warning first.
- The receipt API for I-3: `openLines(poId, outstandingOnly = true)` (an order that expects nothing — not posted, received,
  closed, cancelled — has none), `applyReceipt` / `reverseReceipt(poId, [line_no => central units], grnLabel, ?Caller)`:
  inside the caller's transaction only (`LogicException`), the PO document row then `purchase_order` FOR UPDATE, item lines
  only (422 `bad_receipt`), never below 0 (409 `receipt_below_zero`); the state follows (all item lines received ≥ ordered →
  received; some → part_received; none → sent or approved by `sent_at`); a receipt is applied only to an approved, sent or
  part-received order (409 `po_not_receivable`), taken back also from received; audit `po.receipt`. The optional Caller
  (default `system:po_receipt`) is an addition for the audit row.
- Warnings, never refusals: the supplier not active yet, an overseas route not approved, due diligence overdue, the net
  below the supplier's minimum order, a £0 price, a merged item, a supplier item switched off.

**I53. The screens (spec §8.1, §8.3).** Purchase orders is a live menu item (`purchasing.view`). The list (filters state,
supplier, number / supplier's reference, rejected at review; cancellation documents only with `show=all`; CSV) carries the
"New purchase order" form (FormOnce). The **editor** (the draft's creator only; anyone else gets the read-only view) is one
form whose first fields are `version`, `line_count` and `lines_editable`; the first submit button is **Add**, so Enter in the
scan box adds the line and saves every edit in the table in one transaction; packs 0 removes a line; a charge is added below
the lines; fewer `line_<n>_packs` fields than `line_count` is 400 `form_truncated` (PHP drops fields past `max_input_vars`);
above 300 lines the table is read-only and the file import edits the order. A refused save redraws the editor with what was
typed. `UiRequest::fieldsMatching()` reads the rows. The **view** shows state, review and history, the decide box (its forms
post to `/ui/documents/reviews/{task}/approve|reject`, which come back to the order's page for a PO or its cancellation),
the actions the person may use (send, cancel, amend and copy with FormOnce, close, withdraw), the files, the downloads (PDF,
lines CSV / XLSX). The review queue gains `?type=` (a document type or Supplier) and shows the units of an `over_value`
approval as £ (whole GBP). The generic document page links "Open in Purchasing" and hides its generic reversal form for a
PO (a PO is cancelled or amended in Purchasing); the PO reversal reasons are offered only on POs. The supplier card lists
its last 10 orders with links. Desk, reviewer, accountant and auditor read; POSTs need `doc.PO.post` (403).

**I54. Locks and races (spec §6.9, extends I21).** idempotency claim (FormOnce) → document rows by id (an original before its
reversal; I-3: the GRN, then the PO document) → review task → **supplier rows** (FOR SHARE in the PO's validate and in
save / create / amend, FOR UPDATE in supplier writes) → number_series → **purchase_order → po_line → supplier_item (id
order) → supplier_item_price → po_posting** → (stock: never, for a PO). Supplier code never locks a document row; PO code
never locks stock. Tested with two processes (`PurchaseOrderRaceTest`): the same draft approved twice → one posting, the
other 409; the same new-order form from two processes → one draft (the second replays the first); approve against the
supplier's deactivation → either posted first (the deactivation waited ≥ 400 ms for the share lock) or refused 422
`supplier_not_active` after waiting for the deactivation — never posted after the deactivation committed; 0 deadlocks.

**I55. `pack_in_use` (amends I43; stricter than the spec).** `SupplierItems::update` refuses a changed `units_per_pack` with
409 `pack_in_use` once **any PO line that is not on a cancelled draft** uses the supplier item — the spec said a posted line.
A draft or a waiting request keeps the pack it was saved with, and its next save (or an approval after the change) would
refuse the line as "pack differs"; refusing the change up front is clearer. Add a new supplier item for a new pack size.

**I56. The ERPNext open-PO import as built (spec §6.8).** One CW PO per `erp_po` with only the outstanding packs
(ordered − received > 0, lines in `line_no` order); `external_ref = "ERPNext <erp_po>"`; a live CW PO (draft, waiting or
posted) with that reference is **skipped** (re-run safe), as is a PO with nothing outstanding; a supplier that is not active
(or overseas without an approved route), an unresolved / unlinked / merged item or a bad value refuses the **whole PO**,
reported with status **failed** and "whole PO skipped: …" (the spec says "skipped"; "failed" keeps the report's statuses
those of the supplier seed and makes the CLI exit 1, so a refused order is not missed). Created as `--staff` (active,
`doc.PO.post`), source `erp_seed`, doc_date = the order date, approved through the base (above £10,000 it waits: reported
"waits for a reviewer's approval"), then `markSent(imported, ERPNext)` without archiving a PDF. One transaction per PO; one
`import_run` (kind `erp_open_pos`) per file; the same file again → "already imported (run N)"; `--dry-run` runs every PO in
a rolled-back transaction and keeps only its run row. Item references resolve exactly as the supplier-items seed (§5.7);
the resolution is a copy of `ErpSeedImport::resolveItem` (private there, outside this task's files): open item to share it.
Tested only with synthetic temporary files; nothing connects to ERPNext.

**I57. `bin/document_rules.php`.** `--type=<CODE> [--approval-limit=N] [--review-rule=all|over_limit|none] [--review-limit=N]
[--review-due-days=N] --reason=... --admin`: refused before connecting without `--admin` (exit 1, `document_type` is
read-only for the app login); the CHECKs as usage errors (exit 2): `--approval-limit` only on a type with an approval rule,
`--review-limit` only with `over_limit`, which needs a limit; limits 0..2,000,000,000; due days 1..120; reason 3–500
characters. One transaction (the row FOR UPDATE), audit `document_type.change {type, before, after, reason}` (actor
`system:document_rules`); the same values again: "unchanged", nothing written. It does not change `approval_rule` or
`reject_action` (a migration does).

**I58. The lines file and XLSX; OpenSpout; spec DDL corrections.**
- **openspout/openspout v4.32.0** (MIT), installed in slot i2po with `composer require openspout/openspout:^4.32`; it requires
  php ~8.3, ext-dom, fileinfo, filter, libxml, xmlreader, zip — all on staging — and **no gd**. `composer audit`: "No security
  vulnerability advisories found" (2 Oct 2026). `composer.json`/`composer.lock` copied back from the slot.
- `XlsxWriter` writes every text cell as `new Cell\StringCell` (an inline string) and numbers as `NumericCell`, never
  `Cell::fromValue()`: a supplier description `=HYPERLINK(...)` stays text (the test reads `sheet1.xml`: no `<f>`).
  Characters XML 1.0 cannot hold are removed.
- `XlsxReader`: before any XML is parsed, the zip-bomb guard checks the archive's entries (≤ 1,000), the declared
  uncompressed size of the first worksheet and of `sharedStrings.xml` (≤ 50 MiB each) and of all entries (≤ 100 MiB) — a
  forged size in the central directory is refused unread (tested); ≤ 2,001 non-empty rows, row numbers ≤ 10,000 (empty rows
  are kept so a row number is the spreadsheet's), ≤ 50 columns. **openspout reads a text cell that starts with "=" as a
  FormulaCell without a computed value**: the reader returns that text; a real formula returns its cached value, never the
  formula. Values become strings (a number in its shortest form: 24, not 24.0; a long barcode stays all digits).
- `PoLinesFile` (§6.6) as specified, plus: a **charge row** (`purchase_unit = charge`, no item code, the amount in
  `pack_price`, the description in `note`) so an exported order with a delivery charge imports again; header names are
  normalised like `CsvReader`'s; errors read `row N, column: message` (N = the data row, spreadsheet row N + 1), at most 50
  plus "... and N more"; an empty file is "the file has no lines".
- **0010's DDL corrected (safer):** `ck_purchase_order_sent` and `ck_purchase_order_closed` compare the state NULL-safely
  (`state <=> 'closed'`, `NOT (state <=> 'sent')`): with the spec's `state = 'closed'` an unposted row (state NULL) carrying
  `closed_at` made the CHECK NULL, which MySQL accepts. Otherwise 0010 is §4.2 with a header comment.
- `Fpdf` takes an optional footer text (null keeps CW's footer); the PO footer is "<legal name> · Company no. · VAT no. ·
  <number> · page n/{nb}". The PO PDF's table header is 7.5 pt bold so "Pack price" fits its 16 mm column; the totals print
  "Lines / units", Net, "VAT S 20%" per code and Total.

**I59. Deviations, measurements, open items (pos task).**
- *Files outside the task's list* (each had to follow the PO type going live or 0010's seeds): `tests/Integration/Migration0008Test.php`
  (22 reason codes are 0008's: the 3 PO reasons excluded; the PO row is now `all`), `Migration0009Test` (its 12 seeds selected by
  prefix), `SettingsTest` (counts read from the table; `po.*` now exist), `UiKernel/SupplierScreensTest` (the settings page's PO
  rule and counts), `UiKernel/ReferenceScreensTest` (25 reason codes, PO live in the series list), `tests/Unit/UiTemplatesTest.php`
  (Purchase orders is a live link; the new templates listed); `src/Ui/Controller/SuppliersController.php` (the card's
  "Recent purchase orders" data) and `ReferenceController.php` (the series page printed an `over_value` rule as "positive units
  …"; stale comments); support files `tests/Integration/PurchaseOrders/{PurchaseOrderTestCase,po_worker}.php`. `src/Ui/Context.php`
  was left alone: the controller builds the service.
- *Not as the spec says:* `pack_in_use` counts drafts too (I55); a refused open PO is status `failed` (I56); the ERP item
  resolution is copied from `ErpSeedImport` (I56); 0010's two state CHECKs are NULL-safe (I58); `addLine` answers `choices` when a
  supplier sells an item in several packs and none is preferred, and needs the `CW` prefix for a CW code (I52); a merged item on
  a draft stops its save (the document base refuses merged items: the editor warns first) instead of being only a warning.
- *Additions:* `addSupplierItem`, `withdraw` (service and route), the optional Caller of the receipt API, `openLines`'
  `$outstandingOnly`, charge rows in the lines file, `Fpdf`'s footer, the PO reversal reasons hidden from other types'
  reversal form, the generic reversal form hidden for POs, the supplier card's "New purchase order" link.
- *Test runs* (slot i2po): the full suite `scripts/remote.sh i2po vendor/bin/phpunit` **green: 581 tests (537 before this task),
  11,619 assertions, 73 skipped** (the HTTP Api*/Ui* tests of slots api and ui), 6 min 49 s. The run before it had one failure,
  the timing-bound `DbTest::testRealDeadlockIsDetectedAndRetried` ("second session never started waiting for the lock", as in the
  suppliers task: I47), which passed alone straight after; the cluster was slow that hour (`PostingRaceTest` 14.9 s against 7.4 s
  in the green run).
- *Measurements* (2 Oct 2026, slot i2po, staging): saving a **300-line** draft 134–186 ms (3 runs; 414–474 ms before the supplier
  items were read in two queries instead of one per line); adding a line to it 149 ms; **approving** it 171 ms (825 ms before the
  `last_po_*` update became one statement and the PO price rows one multi-row insert); `pdfData` 19 ms; the **PDF of 120 lines**
  16–30 ms (14.8 KB compressed; 3+ pages), of 300 lines 43 ms; P1–P6 on that schema 34 ms. Races: 0 deadlocks.
- *The hammer* (`scripts/remote.sh i2po php tests/concurrency/hammer.php --seed=20261002`): **RESULT: PASS (57 checks passed, 0
  failed)** in 89 s, 0 deadlocks surfaced or retried; scenario 4 at 1,169 operations (39/s), scenario 5 at 35 calls/s. **Scenario
  4 is not within 15 % of 62/s, and the cause is the cluster, not this change:** in the same hour the committed I-1 tree (HEAD
  0e53d35, `git archive` into the same slot) measured 45, 46, 51, 32/s (`--only=4`); this tree 42, 38, 46, 34, 40/s (and 44/s); this
  tree with the S1–S5 and P1–P6 hooks removed from `Invariants::check` 37, 43/s — the spread of each set covers the others.
  Re-measure in the FIX step when the staging cluster is quiet (the I-1 figure of 62/s was taken in the morning).
- *Staging file store, found during the run:* `/srv/cw-docs` exists on the staging box since 2 Oct 2026 12:03 UTC (the
  installer's probes are in shards 00 and 01) and app.env names it, contrary to the suppliers report. The first run of
  `PurchaseOrderScreensTest` (before the fix) therefore stored ONE test PDF there: `/srv/cw-docs/a8/a8c2e9ef06bd4e67bfee48a66ffac29d342655491a09861e0f6457f508203eca`
  (2,899 bytes, root, 14:37 UTC; a draft-company PO of a test supplier, no personal data). Its `stored_file` row was in the test
  schema `cw_test_i2po`, so it is an **orphan** (`bin/verify_files.php` counts it, exit 0). The shard is append-only (`chattr
  +a`), so nothing was removed: deleting it needs root and `chattr -a` on shard `a8` — the owner's call. The screen test now
  points `CW_FILE_STORE_DIR` at a temporary directory (`docs/dev.md`), as every other storing test already did.
- *Open for the reorder task (I-2 task 3):* `PurchaseOrders::onOrder(list<int> $skuIds): array<int, int>` — central units still
  expected per item (Σ max(0, qty − received_units) over posted POs in approved / sent / part_received; every id asked for is a
  key, 0 when nothing) and `PurchaseOrders::inDrafts(list<int> $skuIds): array<int, int>` — central units on PO drafts and on
  POs waiting for approval (shown, not counted); both chunk the ids by 1,000 and read `document_line` by `ix_document_line_sku`.
  "Create draft PO" builds drafts with `createDraft(Caller, supplierId, [], 'reorder')` then `saveDraft(..., [], lines)` where a
  line is `['supplier_item_id' => id, 'packs' => k, 'suggested_units' => units]` (price = the last price, VAT = the supplier's),
  inside its FormOnce transaction (both join it). `PurchaseOrders::resolve()` is public if the reorder screens need the scan rules.
- *Open for I-3:* `openLines` / `applyReceipt` / `reverseReceipt` as specified (I52); the over-delivery tolerance
  (`po.over_delivery_tolerance_pct`) is enforced there; a closed PO's receipts cannot be taken back (409) — decide if a GRN
  reversal on a closed PO should reopen it.
- *Other open items:* share the item-reference resolution between `ErpSeedImport` and `ErpOpenPoImport`; the PO PDF keeps a
  fixed layout for one company (decision 9 may add more); the owner's provisional decision 11 (the £10,000 limit, the 7-day
  review, record-not-reverse) and the `po.*` settings stay "provisional, owner to confirm".


## Inventory Phase I-2 (slot `i2re`, reorder)

Phase I-2 task 3 of 3 (`docs/inventory-modules-plan.md` IM9 basic and the sales-history import; the I-2 build spec of 2 Oct
2026, §4.3, §7, §8.1, §9.3, §11.R): the read-only sales-history export from the live sites and its import, the demand
builder, the IM9 basic reorder list with "create draft PO", and their screens. Numbered I60–I71. Nothing books stock. Code:
`migrations/0011_reorder.sql`, `tools/sales_history/export.php`, `src/Reorder/{SalesHistoryImport,DemandBuilder,DemandMath,
PromoDetector,ReorderList,ReorderMath,Explain,ReorderSettings,DraftPos}.php`, `src/Ui/Controller/{Reorder,SalesHistory}Controller.php`,
`src/Ui/views/{reorder,reorder_item,reorder_brands,reorder_anomalies,sales_history}.php`, `bin/{import_sales_history,reorder_demand}.php`;
changed: `src/Purchasing.php` (`stockOf`), `src/Auth/Permissions.php` (Reorder list and Sales history live), `src/Ui/Kernel.php`,
`src/Schema/Grants.php`, `public/ui/assets/app.css`; tests `tests/Unit/{DemandMath,PromoDetector,ReorderMath,Explain}Test.php`,
`tests/Integration/Migration0011Test.php`, `tests/Integration/Reorder/*` (with the support files `ReorderFixtures.php` and
`ReorderTestCase.php`), `tests/Integration/UiKernel/{ReorderScreens,SalesHistoryScreen}Test.php`, and updates of
`PermissionsTest`, `MenusTest`, `GrantsTest` and (outside the task's list, I71) `UiTemplatesTest`.

**I60. The read-only export from the live sites (`tools/sales_history/export.php`, spec §7.1).** A standalone `mysqli` tool
run on the Vape and Go box (that database is VPC-private), with the spec's SQL V1 / O1 / O2 / S1–S3 verbatim, the session
`MAX_EXECUTION_TIME = 30000`, READ COMMITTED, READ ONLY (checked through `@@transaction_read_only` before the first query), one
`START TRANSACTION READ ONLY … COMMIT` per 7-day slice, 200 ms apart, the Threads_running guard (≤ 30, 12 waits of 5 s, then
exit 3), and output only under `CW_SALES_EXPORT_ROOT` (default `/root/cw_work/sales_history`; `--out` checked lexically and
again after `realpath`, exit 2), files 0640. Choices the spec left open:
- **Parameters are inlined** as quoted literals of dates the tool computed and checked (`YYYY-MM-DD[ 00:00:00]`), so the
  EXPLAIN and the query are the same text; nothing from outside the tool reaches the SQL.
- **The gate**: every statement of a slice is EXPLAINed before any of them runs; a row of type `ALL`, a table or alias whose
  key is not in its allow-list (or NULL), a driving estimate above 500,000 rows, or an EXPLAIN that fails (a forced index
  that is gone: MySQL 1176) is exit 3, "nothing further was run (data queries run before: N); no files kept". A plan row
  without a table is accepted only for "Select tables optimized away" / "No matching min/max row" (the MAX of S3).
- **Two passes**: the sales (V1 or O1, then O2, merged per variant and day) slice by slice; then the snapshot. S2 keeps only
  the variants that sold anywhere in the export (spec), so it needs the whole first pass. S3's `MAX(pss_date)` runs first
  in pass 2: when it finds no day on or before `--to` (Electrofag's table was empty when the spec was written) or the table
  does not exist, the stock files are not written (a manifest warning says why), and S1/S2 only read slices up to that day.
- Files are written as `.part` and renamed when everything succeeded; the manifest adds `kind` per file, the `queries`
  themselves (their sha256 is `query_sha256`) and `warnings`. Error messages are scrubbed of the host, user and password; a
  connection failure prints only its MySQL code.
- Tested on a fake site schema with the live tables' columns and index names (`SalesExportToolTest`): both sources, the
  office orders (a re-ship child left out, `ord_parent_Id = 0` counted), a cancelled line counted only when returned, the
  window [from, to + 1), the stock files, the sha256s, the gate (index dropped; a primary key without the date first), the
  root check, a wrong password, a missing and an empty snapshot table.

**I61. The import (`SalesHistoryImport`, `bin/import_sales_history.php`, spec §7.2).** As specified (manifest site = channel
else exit 2; sha256 per file else exit 1 `sha_mismatch`; the same sales file loaded → "already loaded as batch N", exit 0;
one transaction per 7-day slice deleting the channel's sales, stock and snapshot days of the slice before inserting 500-row
chunks; `listing_stock_latest` replaced; `loaded` with its counts, or `failed` with the error; unknown / unlinked counted per
batch and every row kept; the month table and the anomaly uplift; then the demand unless `--no-build` or a dry run). Choices:
- **Gaps both ways**: the spec refuses a batch starting after coverage end + 1; a batch ending before coverage start − 1 is
  refused too (the coverage is [min from, max to], so a hole in the middle would otherwise read as days without sales);
  `--allow-gap` overrides both and the hole then does read as zero sales (`docs/ops.md`).
- **A dry run writes nothing at all**, not even a batch row (the UNIQUE (channel, sales_sha256) would then block the real
  load). A `failed` batch row is reused when the same file is run again (its slices are idempotent).
- Rows are checked strictly (the exact header, the site, a printable variant id ≤ 64, dates inside the export and never going
  back, whole non-negative units, a row selling at least one unit, money rounded half-up to pence). The rows of a slice are
  inserted in primary-key order (variant, then day): the 12-month Vape and Go load fell from 290 s to 169 s on staging.
- The uplift baseline is 1 July – 31 August of the window's year, both clipped to the export; a brand window is measured on
  the rows of that brand's linked items.

**I62. Demand (`DemandMath`, `DemandBuilder`, spec §7.3) as built.** The formula exactly as specified, with rates as e4
integers (half-up) and the weight as e6; the exclusions in order nodata → before_first → anomaly → promo → oos; V_S ⊂ V_L.
- **The spike-cap example in spec §9.3** ("one day of 200 in a 2/day series → capped at 8") assumes μ = 2, i.e. the mean
  *without* the spike; §7.3 defines μ as the mean of the valid days, spike included: 380 / 91, cap ⌈4 × 4.18⌉ = 17. The
  formula was followed and `DemandMathTest` asserts 17 (and 197 / 91 for r_L); the floor (5) and the multiple are tested too.
- first(ℓ), units_365 and the plain 30-day average come from the 365 days to E_c; the read is 366 days so that the 12
  calendar months (`monthly`, ending with the month of the newest history end over all sites) are whole. "Sold in the loaded
  history" (spec) = a sale in those 365 days; an item with a minimum stock gets a row (of zeros) without history.
- **Item columns**: rate = Σ u·rate(ℓ); `rate_short` / `rate_long` = Σ u·r over the listings that have one (NULL when none);
  `valid_days_*`, `excluded_*` and `capped_days` are those of the **main listing** (the largest u·rate, then u·units_365, then
  the lowest id); `detail` = `{"listings": [...]}` per listing, main first, with the spec's fields plus `excluded_short`,
  `anomalies` (each window's id, label, dates and days in the short and long windows), `method`, first / last sale and
  units_365. MySQL keeps JSON objects in its own key order (tests compare them with `assertEquals`).
- Listings: status `mapped`, the item not merged, on a channel with loaded history; a brand is compared lower-case and
  trimmed (the column's collation ignores case). An anomaly of one site or one brand applies only there.
- The build: `GET_LOCK('cw_reorder_build:<schema>', 0)` (409 `build_running`; the CLI and the import say "already running",
  exit 0), released in `finally`; the reorder.* settings checked (`ReorderSettings::params`: a value out of range is 500
  `bad_setting`, nothing written); chunks of 1,000 items with per-channel `IN` reads on the primary keys; ONE transaction
  `DELETE FROM reorder_demand` + 500-row INSERTs. `DemandBuilder::item()` computes one item day by day for its page.

**I63. Promotions (`PromoDetector`, spec §7.3).** As specified: per channel and brand, days from E_c − W_L − 28 + 1, the
28-day reference without days that sold nothing, anomaly days of (c, b) and days already flagged; |R| ≥ 7; the three tests.
Money in pence, the decimal settings in millionths, the price test cross-multiplied with **bcmath** (a whole-brand day passes
64 bits). U(t) counts **listing units** as the spec says; a brand mixing single and multi-pack listings would read better in
central units (u × units) — left for the full engine (I-6). Merged items are left out of the brand totals.

**I64. The reorder line and the list (`ReorderMath`, `ReorderList`, spec §7.4) as built.** L / R / S / φ precedence, target
(min then max), ROP, P = A + O (drafts shown, never counted), need, packs (up, or nearest = ⌊2N + upp⌋ / 2upp; MOQ, then the
multiple), value = packs × last pack price, urgent ⇔ P < ROP, cover now in tenths of days (∞ when d = 0), the order urgent →
cover now → value → item. Choices:
- **The list's items** are the demand rows plus the items with a minimum stock (a minimum set since the last build shows at
  once). A preferred supplier item comes from the generated `preferred_sku_id`; a supplier not active is a flag
  (`supplier_draft`, `supplier_pending_approval`, `supplier_inactive`), not a refusal; `no_price`, `no_history`, `merged`,
  `do_not_reorder`, `no_supplier` are flags too. Never suggested: do-not-reorder and merged (k = 0, shown with "every item").
- **Stock**: `cw` = `Purchasing::stockOf()` (Σ sellable on_hand − allocated − held, may be negative); `site` = Σ over the item's
  **vapeandgo** mapped listings of u × `listing_stock_latest.stock`, with its date (0 and "no snapshot" without one).
- Filters: brand (any case), preferred supplier, words of name / brand / code or a CW code, urgent, `show=need` (k > 0) or
  `all`, stock source; unknown values fall back to the defaults. 200 lines a page; the CSV has every line.

**I65. The "Why" (`Explain`).** Built from the stored detail and the line, in the spec's shape: "Demand 12.4/day = 0.5×13.1
(28 days: 19 valid; 9 excluded: Pre-duty stockpiling 14–22 Sep) + 0.5×11.7 (91 days: …; 1 day capped at 40) [vapeandgo 11.9 +
electrofag 0.50]; factor 1.00. Cover 2 lead + 7 review + 5 safety = 14 days → target 174. Available 60 + on order 48 = 108 (in
drafts 0). Need 66 → 3 × box of 24 = 72 (MOQ 2). Plain 30-day average 15.2/day." Choices: the windows' facts are the main
listing's; listings with different methods are explained one by one; an anomaly is named by its label before " (", or its
first two words when that is longer than 40 characters (the seeded window reads "Pre-duty stockpiling 14–22 Sep"); a rate
prints one decimal from 1 a day and two below; the factor names its source (item / brand / default) and the factored rate;
site stock, urgency, a minimum or maximum that set the target, MOQ / multiple / nearest rounding and "no preferred
supplier" are said when they apply.

**I66. Reorder settings (`ReorderSettings`, spec §7.5).** Item and brand rows carry a version, 0 meaning "no row yet" (an
insert; a row created meanwhile is 409 `version_conflict`); an unchanged save writes nothing; audit `reorder.item_settings` /
`reorder.brand_settings` with before and after. A brand must be some item's brand (422 `unknown_brand`; stored in the item's
spelling). Factors 0.00–5.00 with two decimals, safety 0–90, lead 0–120, min ≤ max. Anomaly windows: 3–200-character label,
first ≤ last day, at most 92 days apart (93 days, the CHECK), optional site and brand; ended, never deleted (`NO_DELETE`);
ending an ended one is 409 `anomaly_ended`. Every write: `reorder.manage` read inside the transaction, never admin. Recalculate
audits `reorder.recalculate {items, listings, ms, channels}`.

**I67. "Create draft PO" (`DraftPos`, spec §7.4).** The ticked items are grouped by their preferred supplier (by supplier id,
lines by item code), one `PurchaseOrders::createDraft(…, 'reorder')` + `saveDraft` per supplier with `{supplier_item_id,
packs, suggested_units}` (price = last price, VAT = the supplier's), all in the caller's transaction (FormOnce on the screen).
Skipped and listed: no preferred supplier, an inactive preferred supplier, a merged item, an item not on the list. Packs 0 are
left out; nothing left is 422 `nothing_picked`; an item twice or negative packs is 400 `bad_pick`. **The signature gained the
stock source** (`create(Caller, picks, 'cw'|'site', ?formKey)`): `suggested_units` is the suggestion the buyer saw (with the
site's stock it differs). A draft below the supplier's minimum order is warned about. Audit `reorder.draft_pos`.

**I68. The screens (spec §8.1).** Reorder list and Sales history are live menu items (`reorder.view`). The list carries the
draft form only for `doc.PO.post` with `row_count` first and the filters as hidden fields; fewer `packs_<sku>` fields than
`row_count` is 400 `form_truncated`. **After "create draft PO"**: one draft and nothing skipped → 303 to the PO editor
(`?notice=created`); otherwise → 303 to the reorder list with a "New draft orders" panel (draft ids and up to 50 skipped
item ids in the URL, re-read and re-checked on display) — the PO list has no "these drafts" filter and its controller is
not this task's. A refused draft form redraws the list with the error (FormOnce stores nothing). Item, brand and anomaly
forms: 409 redraws the current values ("changed since you opened them"), 422 keeps what was typed. The item page shows every
day of the long window per listing (computed now) with its reason. Sales history: per site the coverage, snapshot days, the
latest site stock, the top 50 unknown / unlinked variants by units in the last 91 days of the history with the listing
profile's titles (a link to `/ui/review/listing/{id}` only for `linking.view`), its full CSV, and the last 100 batches. CSS in
`app.css`; no JavaScript.

**I69. IM9 defaults — provisional, owner to confirm.** All in `app_setting` (0011, `provisional = 1`), changed with
`bin/settings.php --set=reorder.<key> … --admin`, read at every build and list:

| Setting | Default | |
|---|---|---|
| `reorder.default_lead_days` / `default_review_days` / `default_safety_days` | 2 / 7 / 5 | provisional, owner to confirm (safety 5 = today's ERPNext practice) |
| `reorder.short_window_days` / `long_window_days` / `short_weight` | 28 / 91 / 0.50 | provisional, owner to confirm |
| `reorder.min_valid_days_short` / `min_valid_days_long` | 7 / 21 | provisional, owner to confirm |
| `reorder.spike_cap_multiple` / `spike_cap_floor` | 4 / 5 | provisional, owner to confirm |
| `reorder.promo_price_drop` / `promo_units_uplift` / `promo_min_units` | 0.10 / 1.50 / 20 | provisional, owner to confirm |
| `reorder.stale_history_days` | 3 | not provisional (a screen warning) |
| `demand_anomaly` 14–22 Sep 2026, every site and brand | seeded | provisional, owner to confirm (see I71 on 23–30 Sep) |

Also provisional, owner to confirm: the list's default stock source is CW's (site stock on request); "site stock" means
Vape and Go's; packs round up unless an item says nearest; drafts take the last (invoice / manual / import) price; the
earlier provisional decisions stand (9: one company, I38; 11: approvals, I40 / I48; 12: no cost write-back, I41; 25: no bank
details, I39).

**I70. Measurements (2 Oct 2026).**
- **The live export, run once per site** (this box, `nice -n 10`, 16:57 BST, after the 03:40 rebuild and outside 03:30–05:00):
  - vapeandgo (`appad_vapeandago_ecommerce`, `--source=cps`) 2025-10-02 to 2026-10-01: **32.8 s**, 53 slices, **602,739**
    sales rows, 36,841 stock-day rows, 29,127 latest rows (snapshot 1 Oct; 8 snapshot days from 24 Sep, ~16k unsellable a
    day), **Threads_running before 3, max 7, after 2**. Plans: V1 range `cps_date` (19,420 rows), O2 range
    `idx_ord_type_status_date` (45) + ref `idx_ordi_ord_cancelled_prodt` (11), S1/S2 range PRIMARY, S3 optimized away + ref
    PRIMARY. Units/day online: Jul 14,856, Aug 14,823, Sep 22,523.
  - electrofag (`alectrofag_live`, `--source=orders`): **22.1 s**, 53 slices, **11,481** sales rows (history from May 2026),
    273 stock-day rows, 9,014 latest rows (one snapshot day, 29 Sep), **Threads_running before 2, max 3, after 2**. Plans: O1
    range `idx_orders_report` + ref `idx_ordi_ord_cancelled_prodt` + DEPENDENT SUBQUERY ref `idx_orti_ordi`; O2 as above.
  - Files: `/root/cw_work/sales_history/{vapeandgo,electrofag}/`. Nothing else touched the live databases.
- **The rehearsal** (`RealDataRehearsalTest`, slot i2re, scratch schema `cw_test_i2re_rh`; 1,858 Vape and Go listings of Elux,
  Lost Mary and Bar Juice 5000 minted and linked, 0 refused): import vapeandgo **169.1 s** (602,739 rows, 5,534,577 units;
  unknown 0, **unlinked 2,632,447 units = 47.6 %** — only three brands linked), electrofag **3.0 s** (34,628 units; unknown 77,
  unlinked 34,551 = 100 %: no Electrofag listing linked); the 14–22 Sep window **+42.9 %** on Vape and Go (21,262.8 units/day
  against 14,884.5 in Jul–Aug; the memory note's +43 %) and +7.9 % on Electrofag; **build 10.7 s** for 1,010 items; all 262
  Elux items sold before 14 Sep have the 9 days excluded. **Promotion days flagged on vapeandgo**: Elux Nic Salt (Legend
  Salts) **2026-09-23** (29,745 units at £1.651 against ~7,000 at £1.95), and 17 Jun, 22 Jul, 21 Aug; Lost Mary 10 Jul, 31 Aug;
  Bar Juice 5000 5 Jun, 17 Jul; Elux Vape 10 Jun, 26 Jun. 24 Sep (13,978 at £1.77, a 9.2 % drop) is not a promotion day under
  the 10 % rule.
- **rate against rate_raw_30** (Σ over the brand's items, central units a day): Elux Nic Salt 8,421.5 vs 10,350.3 (**81.4 %**),
  Lost Mary 476.4 vs 558.9 (85.2 %), Bar Juice 5000 1,686.8 vs 2,007.7 (84.0 %), Elux Vape 4.8 vs 3.3 (145 %: a few units).
  Expect suggestions about 15–20 % below an ERPNext 30-day average for brands inflated in September.
- **The build at scale** (`DemandBuilderTest::testTheBuildAtScale`, `CW_REORDER_PERF=1`): 15,000 linked items of 100 brands,
  182,000 rows in the 91-day window (730,000 in the year), 8 snapshot days: **49.0 s** (target ≤ 60 s; the staging cluster was
  slow that hour: generating the rows server-side took 450 s), 196 MB.
- **The full suite** `scripts/remote.sh i2re vendor/bin/phpunit`: **green, 641 tests (581 before this task), 12,042
  assertions, 75 skipped** (the 73 HTTP Api*/Ui* tests of slots api and ui, and the two opt-in tests above), 6 min 50 s. The
  run before it had one failure, the pos task's timing-bound `PurchaseOrderRaceTest::testApproveAgainstTheSupplierDeactivation`
  (case a: the approving worker started after the deactivation's 0.2 s head start, so the approval found the supplier
  inactive — correct behaviour, wrong interleaving for the test); alone it passed 3 times out of 3. This task touches no PO or
  supplier code. The reorder tests alone (24 unit, 34 integration and screen tests, plus the 2 opt-in) take about 70 s.

**I71. Deviations, open items.**
- *Not as the spec says:* the spike-cap example (I62); dry run writes no batch row, a backward gap is refused too, a failed
  batch is reused (I61); the export inlines its checked dates, runs S3's MAX first and skips the stock files without a
  snapshot (I60); `DraftPos::create` takes the stock source (I67); after "create draft PO" with several drafts or skipped
  items the screen returns to the reorder list's "New draft orders" panel instead of a PO list filter (I68).
- *Files outside the task's list:* `tests/Unit/UiTemplatesTest.php` (it asserted the two "coming in Phase I-2" placeholders;
  the new templates are listed); the test support files `tests/Integration/Reorder/{ReorderFixtures,ReorderTestCase}.php`.
- *Settings::RULES* (the suppliers task's file) does not bound `reorder.min_valid_days_short/long` (their keys do not end in
  `_days`); `ReorderSettings::params` checks every reorder.* range when the demand is built or listed (500 `bad_setting`).
  Add `min ≤ window` rules there when that file is next open.
- **For the owner — September after the window:** the export shows pre-duty buying after 22 Sep too: Elux Nic Salt sold
  13,436 / 16,372 / **35,453** units on 28 / 29 / **30 Sep** (the day before the duty) at the normal price (~£1.95), against
  ~7,000 a normal day; the spike cap holds each listing at 4× its mean, but these days still lift the short window. A buyer can
  add a window "23–30 Sep" on Reorder › Anomaly windows (or the owner extends the seeded one): **provisional, owner to decide**.
  After the duty, a brand factor (e.g. 0.85) is the planned lever for the expected drop (memory "demand context").
- *Not done here (each needs the owner's go, spec §11):* loading the exports into `cw_staging` (migration 0011 first, after
  the FIX step); a nightly export → import → build (no cron installed); Vape Big's history (access pending, decision 5).
- The import on staging writes ~3,600 rows a second; a nightly one-day export is ~2,000 rows (seconds).


## Inventory Phase I-2 review fixes (slot `i2fx`, 2 Oct 2026)

Two reviews of the uncommitted I-2 tree (correctness and data integrity, probes in slot i2rv1; security and usability,
probes in slot i2rv2) found one blocker, five important points, nine minor ones and four nits. Every point was applied
except one (I88), each with a regression test. 0009–0011 have not been applied to `cw_staging`, but no DDL had to change.
Numbered I72–I89. Code: `src/Suppliers/{Suppliers,SupplierItems,SupplierInvariants,ErpSeedImport}.php`,
`src/PurchaseOrders/{PurchaseOrders,PurchaseOrderHandler,ErpOpenPoImport}.php`, `src/Output/XlsxReader.php`,
`src/Reorder/{ReorderList,ReorderMath,Explain,SalesHistoryImport}.php`, `tools/sales_history/export.php` (version 1.1),
`bin/import_sales_history.php`, `src/Settings.php`, `src/Ui/{Kernel,UiRequest}.php`,
`src/Ui/Controller/{PurchaseOrders,SupplierItems,Reorder,SalesHistory,Documents,Files}Controller.php`,
`src/Ui/views/{supplier,supplier_form,supplier_item_form,purchase_order,purchase_order_edit,reorder}.php`; tests in
`Suppliers/{SupplierLifecycle,SupplierItems,ErpSupplierImport}Test`, `PurchaseOrders/PurchaseOrderLifecycleTest` (and the
other PO tests' `markSent` calls), `Reorder/{SalesExportTool,SalesHistoryImport,ReorderList,DemandBuilder}Test`,
`UiKernel/{PurchaseOrderScreens,ReorderScreens}Test`, `SettingsTest`, `Unit/{ReorderMath,XlsxRoundTrip}Test`.

**I72. An overseas supplier's route, and whether it is overseas at all, need a second person (blocker; amends I40, I42).**
One buyer could switch the blocking import-route approval off by unticking "overseas": the open approval was withdrawn, the
change was left out of the change review, and `PurchaseOrderHandler::validate` checked the route only for `is_overseas = 1`
(the review's probe posted PO-000001 straight after). Now:
- On an **active** supplier a change of any route field (`is_overseas` either way, `import_route`, its evidence file)
  clears `import_route_approved_*` and keeps or opens ONE blocking approval task (reason `import_route`), whatever the
  direction. **POs are refused while it is open** (`validate` reads `review_task.open_key = 'supplier:<id>:approval'` after
  the supplier's FOR SHARE; READ COMMITTED and the supplier row written by every task-opening transaction make that read
  current), with the warning in the editor. Approving it for a supplier that is overseas approves the route (as before); for
  one made UK it confirms that no route is needed. **Rejecting "no longer overseas" deactivates the supplier** ("rejected at
  review: …", its other tasks withdrawn), like a rejected change review; rejecting a route change of an overseas supplier
  still only records it (POs stay refused). S1 now allows an open `import_route` task on any active supplier.
- **A supplier whose country is not GB must be overseas** (provisional, owner to confirm): `create`/`update` refuse 422
  `bad_field` (is_overseas) otherwise; the ERPNext supplier import derives `is_overseas = 1` from a non-GB country when the
  column is empty (an explicit 0 fails the row). The country itself is approval-relevant, so changing it on an active supplier
  also opens the (non-blocking) change review.
- **Minor, same place:** the route approval is cleared on a route change **whatever the status** (it covered the route as it
  was; a reactivation approves it again). Before, editing an inactive overseas supplier's route, or making it UK, broke
  `ck_supplier_route` (an unmapped 3819, a 500 page).

**I73. The PO editor stays below `max_input_vars` (important).** Each item line sends 4 fields (6 without a supplier item),
plus ~17 others, against PHP's default 1,000 (the UI pool does not raise it): from 249 lines every save was refused and at
248 a typed charge was dropped silently. Now (a) `Kernel` refuses **any** POST that arrives with `max_input_vars` fields
(400 `form_truncated`, nothing done: PHP keeps at most that many and drops the rest without a word), and (b) the editor is
editable only while `PurchaseOrdersController::editorFields()` (24 fixed + 4/6/3 per item / item without supplier item /
charge line) stays below `max_input_vars` (about 240 lines with supplier items), else the lines are read-only and changed by
the file import; `MAX_EDITOR_LINES` (300) stays as an upper bound. The test checks that every named field the rendered form
can send is counted by `editorFields`.

**I74. The last price is the price of a pack (important; amends I44, S4).** A supplier item moved from packs of 1 at £1.00 to
packs of 24 kept £1.00, so a draft PO priced 48 units at £2 (the reorder value, the editor's pre-fill and the PDF too).
`last_pack_price/on/source` now mirror the newest non-`po` history row **whose `units_per_pack` is the item's current one**
(NULL when none): `SupplierItems::update` recomputes them when the pack changes (audited as a change of `last_pack_price`;
the page says "record the new pack's price"), and changing back finds the old pack's price again. S4 checks the same rule.
`last_po_*` needs nothing: a pack change is refused once any live PO uses the item (I55).

**I75. A new supplier item becomes the preferred supply when its item has none (nit, usability).** The reorder list and its
drafts use only the preferred supply, and the form's preferred box was unticked by default, so a buyer adding 40 items for a
brand would see "no preferred supplier" on every line and "create draft PO" would skip them all (owner acceptance steps 3–5).
`SupplierItems::create` takes `is_preferred = 'auto'`: preferred when the item has no active preferred supply (the UNIQUE
`preferred_sku_id` decides a race: a duplicate key leaves it an alternative). The screen's new select defaults to "Yes,
unless the item already has a preferred supply" (also "Yes, instead of its current one" and "No"); the ERPNext items import
uses `auto` for an empty `is_preferred`; the PO editor's "save as this supplier's item" uses `auto`. Explicit 1 and 0 behave
as before; service callers that pass nothing get an alternative, as before.

**I76. Out-of-stock days survive short exports (important; amends I60).** An out-of-stock day counts only when the variant
sold nothing that day, but the export kept unsellable days only for variants that sold **inside its own window**, so the
nightly one-day export the runbook prescribes kept none, and its overlapping import deleted the ones loaded before (probe:
rate 10.0 → 8.37, `excluded_oos` 7 → 0). Export tool 1.1 keeps the unsellable days of every variant sold (online or office)
in the **365 days to `--to`**: a window shorter than that first reads `[--to − 364, --from)` with the same statements, the same
EXPLAIN gate, Threads_running guard and per-slice READ ONLY transactions, keeping only variant ids (a slice that reads nothing
skips the 200 ms pause). The manifest records `stock_filter` and `lookback {from, to, slices, variants_in_window, variants}`.
With that, replacing a slice's stock days on import is right again, so the import is unchanged; a short export of tool 1.0
(no `stock_filter`) must not be loaded alone (ops.md). The 12-month exports already in `/root/cw_work/sales_history` are
complete (their window is the year) and were not re-run: no new live read was needed for this fix.

**I77. The sales import: quarantined listings and older snapshots (minor).**
- A quarantined listing keeps its `sku_id` (`ck_channel_listing_link`) but the demand reads `mapped` listings only, so its
  sales vanished unreported (probe: 460 units, unlinked 0). The batch counts and the Sales history list now count a listing
  that is not `mapped` as unlinked ("unlinked (quarantined)").
- `listing_stock_latest` is replaced only by a snapshot **at least as new** as the stored one; an older re-export is
  reported ("the stored snapshot of … is newer than this export's: kept", count `latest_kept_older`).

**I78. `stock=site` counts usable site stock (important; amends I64).** The real export has 350 In-Stock-mode sellable rows
summing to −114,704 (variant 48: −35,794 against 41,709 units sold in 91 days) and 171 unsellable rows with stock > 0, and the
list added them as they were. Now a listing counts `max(0, stock)` when sellable and **0 when unsellable**; a line whose
listing is in the site's **In-Stock** mode (sold whatever its figure; availability keys off the stock mode, not the
quantity) or below 0 is flagged `site_stock_unreliable` ("site stock not reliable"), and the Why says "[not reliable: …]".
The ERPNext comparison of acceptance step 4 should prefer lines without that flag.

**I79. The reorder list does not pre-tick a line already in a draft (minor; amends I67, I68).** In-drafts units are shown and
never counted (spec §7.4), so after "create draft orders" (several suppliers, or skipped items) the list came back with every
line ticked again and a second click drafted them twice. A line with units in drafts is now unticked with the flag "already
in a draft order"; the buyer ticks it to order more.

**I80. The reorder point is capped by the maximum stock (minor; amends I64).** `ROP = min(ROP, T)` when `max_stock` is set:
a maximum below the cover no longer leaves a line "urgent" with nothing to order (probe: target 20, ROP 70, need 0).

**I81. The order date and the last PO price (nits).** A PO's order date is at most 31 days ahead and 731 days back (422
`bad_field`): a typo such as 2027 set `last_po_on` in the future and froze every later PO price of its items. Cancelling a
posted PO (`PurchaseOrderHandler::reverse`) sets `last_po_*` of the supplier items it had set back to their newest PO that
still stands (or NULL), so a cancelled order no longer pre-fills the editor's "last PO" price.

**I82. The reorder windows are bounded in the settings tool (nit; amends I69).** `Settings::RULES` gains
`reorder.short_window_days` 1..120 and ≤ the long window and ≥ its fewest valid days, `reorder.long_window_days` 1..120 and ≥
both the short window and its fewest valid days, `reorder.min_valid_days_short/long` 1..120 and ≤ their window (a new
`at_least`/`at_most` rule against other settings). A value the tool accepts can no longer make every reorder page and build
fail with 500 `bad_setting`.

**I83. XLSX: the wide-row bomb (minor; amends I58).** An 88 KB file whose row 1 held 3,000,000 cells (45 MB of XML, under the
50 MiB guard) exhausted 256 MB inside openspout (a fatal error, a crash page). `XlsxReader` now pre-scans every worksheet
with `XMLReader` (streaming, `LIBXML_NONET`, constant memory) and refuses a row of more than **1,000 cells** (empty styled
cells included; the 50-column rule still applies to what is read) with 400 `bad_file` before openspout builds anything, and
the per-part limit drops from 50 MiB to **16 MiB** (2,001 rows × 50 columns of PO lines are a few MiB).

**I84. "Recalculate" inside a UI request (minor).** The full rebuild measured 49 s for 15,000 items in phpunit, against the UI
pool's `max_execution_time` 50 s / `request_terminate_timeout` 60 s (a killed worker rebuilds nothing). The button now
rebuilds only while at most **3,000** items are linked (`ReorderController::UI_REBUILD_MAX_ITEMS`; the rehearsal: 1,010
items in 10.7 s), else 409 `rebuild_on_server` pointing to `bin/reorder_demand.php`. The list at that scale is measured in
the opt-in perf test (I89).

**I85. Purchase orders are for people with Purchasing (minor).** `documents.view` includes warehouse and stock_controller,
`purchasing.view` does not, yet they could list POs and open their lines, prices and PDF under `/ui/documents`, and were
offered a dead "Open in Purchasing" link. Now `/ui/documents` leaves PO documents out for them, `/ui/documents/{id}` and its
PDF answer 403, the link is drawn only for `purchasing.view`, and `/ui/files/{id}` refuses a file attached only to POs (the
PDF as sent) without `purchasing.view` and a supplier's evidence (`supplier_check`) without `suppliers.view` (a step on I37's
deferred file scoping).

**I86. Sending a PO that should not go yet (minor; provisional, owner to confirm).** `PurchaseOrders::sendWarnings()` lists
a rejected review ("cancel or amend it rather than send it") and company details not confirmed in the order's snapshot (its
PDF says DO NOT SEND). `markSent` refuses 409 `send_warnings` while there are any unless the person acknowledges them
(`$acknowledged`; the form shows them with a required "Send anyway" box); the `po.send` audit row keeps what was acknowledged.
The ERPNext open-PO import passes true (ERPNext sent those orders). With `company.confirmed` false (today's seed) every send
needs the box ticked, which is the point until the owner confirms the company.

**I87. Approve is part of the editor form (important; amends I53).** "Approve the order" was a separate form carrying only the
version, so packs or prices typed and not saved were silently left out of the approved — immutable — order (owner acceptance
step 5: "adjusts packs in the editor … then Approve PO"). The editor's form now ends with "Save" and **"Save and approve the
order"**: `lines()` saves what is typed and approves exactly that in ONE transaction (a refused approval saves nothing and the
page comes back with what was typed); text left in the scan box is refused first ("press Add, or clear it"). The separate
form is gone from the editor (the read-only view keeps its own for a draft seen by another buyer).

**I88. Points declined or left to the owner.**
- *The import keeping the stock days of variants absent from a new file* (review 1, finding 1, second option): not done. With
  I76 every export carries the stock days of all variants sold in the year to its end, so a slice's rows are complete and
  replacing them is correct; keeping old rows would keep stale out-of-stock days when a re-export corrects them.
- *A SELECT-only database login per site for the export* (review 2, nit): the owner's choice, written into ops.md. The
  Electrofag run used db-transfer's DEST login and the Vape and Go run the site's application login; both can write, and the
  read-only session, the per-slice READ ONLY transactions and the constant SQL are the guards (the review verified all three).

**I89. Measurements and test runs (2 Oct 2026).**
- **Full suite** `scripts/remote.sh i2fx vendor/bin/phpunit`: green, **649 tests, 12,540 assertions, 75 skipped** (the HTTP
  Api*/Ui* tests of slots api and ui, and the two opt-in tests), 6 min 41 s. Baseline of the unfixed tree in the same slot:
  641 tests, 12,338 assertions, green. No timing flake this time (`DbTest`, `LockOrderTest`: 2,000 feed rows within 62 ms).
- **HTTP tests:** `scripts/remote.sh ui vendor/bin/phpunit --filter Ui`: 128 tests, 19,460 assertions, OK (the 1 skip is the
  opt-in `testTheBuildAtScale`, whose name matches); `scripts/remote.sh api vendor/bin/phpunit --filter Api`: 49 tests, 2,687
  assertions, OK (the 1 skip is `UiReviewFlowTest`, which runs in slot ui).
- **Hammer** `scripts/remote.sh i2fx php tests/concurrency/hammer.php --seed=20261002` (24 workers, ≤ 30 connections):
  `RESULT: PASS (57 checks passed, 0 failed)`, 91 s, 0 deadlocks surfaced or retried; scenario 5: 45 calls/s, 66 on_hand
  rows/s, 206 polls, 0 gaps, slowest live invariant check 2.9 s. Scenario 4 alone (`--only=4`, twice): **56/s and 58/s**,
  within 15 % of 62/s (the pos task's 39/s, I59, was the cluster).
- **Reorder at scale** (`CW_REORDER_PERF=1 … --filter testTheBuildAtScale`): 15,000 items, 182,000 rows in 91 days: build
  **51.0 s** (I70: 49.0 s); the reorder list of all 15,000 lines plus the Why of a page of 200: **1.6 s** (CW stock) and **1.3
  s** (site stock). Peak memory of the whole test process 270 MB, mostly the build; the list alone in the UI pool (256 MB) is
  not measured separately: check before linking that many items.
- **Not re-run:** the live export. I76 changes the tool only for windows shorter than a year; the existing 12-month exports
  in `/root/cw_work/sales_history` are complete and are what the deploy loads. `SalesExportToolTest` (fake site schema)
  proves 1.1, including the new one-day case.

## Key spot-check and bulk confirm (slot `mpk1`, 2 Oct 2026)

The owner's two decisions of 2 Oct 2026 (the owner holds `reviewer` and `mapping_lead` on staging). Numbered M26-M28. Code:
`src/Matching/{Band,StoredBand}.php`, `src/Mapping/{ProposalBasis,KeyEligibility,Reband,KeySample,KeyBulk}.php`,
`src/Mapping/Proposals.php` (records the basis), `src/Schema/Grants.php`, `migrations/0012_key_bulk.sql` (written as 0010, see below),
`bin/{reband_proposals,sample_proposals,bulk_confirm_key,bulk_unlink}.php`, `src/Ui/Controller/SamplesController.php`,
`src/Ui/views/{samples,sample}.php`, `src/Ui/{Kernel,Controller/ReviewController}.php`, `src/Ui/views/listing.php`,
`src/Auth/Permissions.php` (menu), `tools/first_match/{assemble,judge_full}.php`; tests `tests/Unit/BandTest.php`,
`tests/Integration/Mapping/{KeyBulk,KeyBulkTools}Test.php`, `tests/Integration/UiKernel/KeySampleScreenTest.php`,
`tests/Support/KeyFixtures.php`, new cases in `GrantsTest`, `MenusTest`, `PermissionsTest`, `tests/matching/run.php`. Runbook:
`docs/ops.md`, "Key spot-check and bulk confirm".

**M26. Key from judge confidence 85 (Band b2.1; the owner's decision (1)).** When the barcode or transfer key and the
barcode-blind judge agree (the judge picks the lane target), Key starts at confidence 85 instead of 90 (b2.0, pilot-1
recommendation 9). Every other Key condition is unchanged: a barcode or transfer lane with one target, units per item 1, no
veto or soft flag on the target, no pending line alias or relabel, no Conflict, and (assemble.php's routing) no
`quote_not_verbatim` warning. `Band::KEY_MIN_CONFIDENCE` = 85, `VERSION` = b2.1, and `KEY_MIN_BY_VERSION` (b2.0: 90, b2.1: 85)
so a stored band can be replayed under the version that made it; `Band::final()` takes that threshold as an optional third
argument for the replay only. Tests: 85-89 with a clean agreeing key is Key on both lanes, 84 is Check, 85-89 without a key
(candidates lane, or no single target) stays Check, a vetoed target is Conflict and never Key at any confidence, a soft flag,
units 2 or a pending alias never Key (`BandTest`, `tests/matching/run.php`).
- **Measured on run3's files** (the source of what `cw_staging` holds; replayed offline, read-only): the b2.0 replay gives back
  all 2,623 stored bands (0 mismatches); b2.1 moves 435 Check proposals to Key and nothing else (confidence 85: 78, 86: 87,
  87: 75, 88: 190, 89: 5; 5,587 units in 365 days). The owner's "about 390" was an estimate; the staging dry run counts only
  the proposals still open, so it gives the real figure.
- **`CW\Matching\StoredBand`** replays a stored first-match proposal: Band's inputs rebuilt from `match_proposal.evidence`
  (lane, lane target, lane flags, target vetoes and soft flags, the judged candidates with their prescores and vetoes, the
  judge's outcome, pick, confidence and units; a match below 50 counts as cannot_tell, as in assemble.php), then assemble.php's
  two routing rules (pending relabels to Manual; a non-verbatim quote caps Key at Check), plus one guard: Key also needs
  `key_possible` true, `key_blocked_by` empty and no veto or soft flag on the judge's pick. The evidence holds the judged
  candidate list, not the engine's full list, so a New item / Can't tell result that depended on an unjudged candidate may not
  replay; the Key threshold depends on the lane target and the pick only.
- **`bin/reband_proposals.php`** (`CW\Mapping\Reband`): every OPEN proposal (decided and superseded ones are never read for a
  change) whose run's band version is known (`match_run.detail.band_version`, else the engine string). Its evidence must replay
  under THAT version to its stored band (else `evidence_mismatch`: left alone and counted); Manual proposals are skipped. A
  different band under the current version is a move, reported by `old>new`. Only Key<->Check moves are applied (the proposed
  item is the judge's pick in both); any other move is reported and needs a new matching run. A move is applied as a later
  run's proposal: under the listing's row lock a NEW proposal (run `reband-<version>`, source `reband`, engine
  `reband/<version>`, same item, judge answer and flags; `evidence.band`/`band_reasons` the new ones and `evidence.reband` the
  old proposal, band, version and reasons) supersedes the old one through `Proposals::add` (audited `mapping.propose`; a
  `mapping.reband` row sums the run). Not applied while the listing is linked, ignored or quarantined, has a decision waiting
  for a second person, has any decision since the proposal (a reject included: a band does not overrule a person who said
  "not this item"), or the proposal's basis (M27) is missing or no longer holds (the evidence would be stale). Dry run by
  default; a re-run moves nothing twice (a re-banded proposal replays to its own band under b2.1). A re-band is not a decision:
  the listing's status and `map_version` do not move.
- `tools/first_match/assemble.php` accepts answers built with any Band version this Band knows (b2.0 -> b2.1 changed only
  final()'s threshold, not provisional()), so run3 can still be re-assembled, now under b2.1.

**M27. What a proposal was made against: its basis (the owner's "hash check").** `match_proposal_basis` (0012, append-only for
the app login): the listing's `map_version` once the proposal and its own `suggest` were written (it moves on every link,
status and identity change, M20), the listing profile's `identity_hash`, a fingerprint of the proposed item
(`ProposalBasis::itemHash`: code, name, brand, sell policy, counted_at, the identity card, merged_into, origin listing), the
`identity_hash` of the listing the item was minted from, and the LINK OF THE LANE TARGET: the Vape and Go listing the barcode
or transfer key named (`target_listing_id`, its `map_version`, status, item and units per item), with `basis_hash` = sha256 of
all of it. The lane target is found as `bin/import_proposals.php` named the item: the listing with the variant id of
`evidence.lane_target` (`CWP-<id>`) that is linked to the proposed item (on the item's origin channel if several are); once
recorded it is followed by its listing id. `Proposals::add` records the basis for every new proposal in the transaction that
makes it (source `recorded`). `ProposalBasis::changes()` names what differs now: `listing_profile`, `listing_link`, `item`,
`item_origin`, `target_link`.
- **Why the lane target (review blocker, 2 Oct 2026).** A Key proposal stands on "the barcode/transfer key says this Electrofag
  listing is the item that Vape and Go listing V is linked to". If V is relinked to another item, ignored, or set to units per
  item 2 by two people after the proposal, the proposal no longer stands, yet nothing in the Electrofag listing, the item row
  or V's identity moves. Probe P5 of the review linked the Electrofag listing at u = 1 to the item that two people had just
  made a multiple for V. Now: `changed:target_link` (the basis) and `target_relinked` (V must be `mapped` to the proposed
  item with u = 1 now, whatever the basis says) both exclude it, and the bulk confirm reads V `FOR SHARE` before it links
  (no decision on V can commit in between) and checks V again after the link.
- **Older proposals** (imported before 0012) get a basis only when it can be PROVED that nothing changed since they were made
  (`ProposalBasis::prove`, source `backfill`, the proof in `detail`): the listing is still at the `map_version` its own
  `suggest` left (expected + 1; no suggest record: no proof), the item row was not written after the proposal
  (`sku.updated_at`: mint, policy, count and merge all write it), and the origin listing's and the lane target's
  `map_version`s are the ones their last decisions before the proposal left (an identity change moves them without a
  decision; `target_unproved` when the target is not linked to the item now or moved since). Otherwise none is written and the
  proposal is never acted on in bulk: a person decides it. `bin/sample_proposals.php --apply` and `bin/reband_proposals.php
  --apply` write the proved bases of the proposals they look at; their dry runs prove without writing.
- Why a separate table, not a column: the app login may update only `match_proposal.status` (M16), so a basis for an older
  proposal could not be added to it; a basis row is written once and never changed.

**M28. The spot-check and the bulk confirm (the owner's decision (2); amends U20, plan §7.1 for this step only).**
- **Eligibility** (`CW\Mapping\KeyEligibility`, shared by the sample and the bulk confirm). A proposal qualifies only when it is
  open, stored Key AND Key now (StoredBand under the current rules), its listing unmapped or suggested with no decision waiting,
  its item legacy, not counted (sku.counted_at, a balance count time or a `count` movement), not merged, with no quarantined
  listing, units per item 1, no flag for two people or a relabel (`two_person_confirm`, `relabel_pending`, `target_not_minted`,
  `merge_suggestion`), the proposed item both the key target and the judge's pick, no reject ever on the listing or on the item's
  family by any listing, no decision on the listing since the proposal, no undone bulk link on the listing, its listing never
  in the population of a FAILED sample (`failed_sample_population`; see the override below), the proposal in no other sample's
  population (`in_other_sample`), its basis (M27) present and unchanged, the lane target found (`target_unresolved`) and still
  linked to the item with u = 1 (`target_relinked`), and the titles the judge saw (`evidence.title`, `evidence.lane_target.title`,
  i.e. the Normalizer's title: the variant title, else the product title, cleaned) still the listings' titles, case and spacing
  aside (`evidence_title_differs`, `evidence_target_title_differs`: the basis dates from the import, the titles from the run's
  export). Everything that needs two people (plan §7.1: protected items, units per item other than 1,
  merges) or a person's judgement is therefore left out by construction; the report counts each proposal under its first reason.
- **The sample** (`bin/sample_proposals.php`, `CW\Mapping\KeySample`; tables `key_sample`, `key_sample_member`, append-only).
  `--by` must be an active mapping lead, and the sample is THEIRS: only their confirmations count, and only they run its bulk
  confirm. Size at least `KeySample::MIN_SIZE` = 20 (also a CHECK in the schema). The population is every open Key proposal that
  qualifies at that moment (written bases first). Strata by the judge's confidence: 90-100 (b2.0's Key) and 85-89 (what M26
  newly admits); proportional allocation, largest remainder, at least one per non-empty stratum (20 from 936 + 435 on run3's
  figures: 14 and 6). In a stratum the members with the lowest sha256("<seed>:<proposal id>"), positions 1..n in that order.
  The SEED is drawn by the server (`random_int`, 1..2^53-1) only on `--apply`, after the population is fixed; no caller can
  pass one (`--seed` is refused) and the dry run shows the population, the strata and the allocation, never members. Anyone
  can re-draw a stored sample from its stored seed and population (`--verify`, `KeySample::verify`). Stored: name (the bulk
  batch is then `key_bulk:<name>`), seed, method, band version, size, the strata, the excluded counts, the overrides, who drew
  it, and EVERY population member with its stratum and confidence (position set for the sample). Audited `mapping.key_sample`
  with the chosen proposal ids. Names are used once.
- **No re-draw past a failure.** A proposal is in at most one sample's population (`in_other_sample`), so nobody can draw sample
  after sample over the same proposals until one looks easy (every draw is stored and audited anyway). A FAILED sample's
  listings never go into a bulk confirm again (`failed_sample_population`), whoever's sample it is. The only way back is an
  explicit override, `--after-failed=<sample>`: allowed only when the band version changed since that sample was drawn or a new
  matching run (not a re-band, not an undo) was imported since, and then only for proposals made after that sample (the ones it
  judged stay one at a time); stored in `key_sample.overrides` with the reason and audited.
- **Confirmed** (`KeySample::status`): a sample member counts as confirmed only when an applied `link` decision named its
  proposal, to its item with units per item 1, outside any bulk batch, made by the sample's OWNER alone (no second person:
  `needs_second` empty), while the owner held `mapping_lead` (`staff_role` history), and that link is still the listing's
  current one. Verdict `complete` (all confirmed), `waiting` (some not decided yet), `failed` (any member rejected, decided
  otherwise, replaced by a later proposal, waiting for or decided with a second person, decided by anyone but the owner, by the
  owner without `mapping_lead`, or relinked since). A failure is final. The review screen of a member's listing says so: to the
  owner "#n of 20 in your Key spot-check", to anyone else "leave it to them: a decision by anyone else makes the spot-check fail".
- **Fit** (`status()['fit']`): a sample unlocks a bulk confirm only with at least 20 members, every non-empty stratum holding at
  least its allocated share (and at least one), and members exactly what its stored seed draws from its stored population
  (`sample_too_small`, `stratum_short:<stratum>`, `draw_not_reproducible`).
- **The screen** (`/ui/review/samples`, `/ui/review/samples/{id}`, `linking.view`, menu "Key spot-check" under Linking): the
  samples with "n of 20 decided" and their state; a sample's members with their listing, item, confidence and state (who and
  when), a link to each in the normal review screen, and what the bulk confirm will do. The review screen opened from a sample
  (`?sample=<id>`) leads back to it, and its forms carry the sample, so after a decision the owner lands on the same listing with
  the usual notice and the way back. Read-only: there is no bulk button (the reason U20 gave stands for the screens).
- **The bulk confirm** (`bin/bulk_confirm_key.php --sample --lead [--apply] [--limit] [--report]`, `CW\Mapping\KeyBulk::confirm`).
  `--lead` must be the sample's owner (else exit 2, `not_sample_owner`). Refuses (exit 1, every unconfirmed member listed)
  unless the verdict is `complete` and the sample is fit (`sample_unfit`). Acts on the sample's POPULATION only (a proposal that
  became Key after the draw is never included), on the members that qualify NOW (re-checked). Each listing in its own
  transaction: the lane target's listing read `FOR SHARE` first (a relink, ignore or unit change of it waits until this decision
  commits), the eligibility again, then `DecisionService::decide(link)` as the owner with u = 1, the proposal named,
  `expected_map_version` = the basis's (409 when the listing moved since), `bulk_batch_id` = `key_bulk:<sample>` and a reason
  naming the spot-check; a decision that would need a second person is rolled back, never left pending; under the locks the
  decision holds, the item fingerprint, the lane target's link and the rejects are checked again (rolled back on a difference).
  Every 25 links the sample's own verdict and fitness are read again (`stopped=sample_changed_during_run`, exit 1). So a failure
  leaves whole decisions only, and a re-run links only what is left (`already_in_batch` counts the earlier ones). `--limit=N`
  stops after N links (skips and failures do not count). The dry run reports the population, how many qualify, the excluded
  counts by reason and the units covered; `--report=<file.csv>` (never overwritten) lists every proposal of the population with
  its outcome (`eligible` / `excluded`), first and all reasons, listing, title, item code and name, confidence, stratum, units
  and lane target listing (cells a spreadsheet could run as a formula are prefixed with `'`). Audited per decision
  (`mapping.link`, with the batch) and per run (`mapping.key_bulk`).
- **The undo** (`bin/bulk_unlink.php --batch=key_bulk:<sample> --lead [--apply]`, `KeyBulk::undo`): only `key_bulk:` batches.
  Every listing still linked by a decision of the batch is unlinked through `DecisionService` as the lead (`bulk_batch_id`
  `undo:<batch>`), and its proposal re-opened as a new proposal (run `undo:<batch>`, source `key_bulk_undo`, `evidence.reopened`),
  so the listing is back in the Key queue; a listing relinked by hand since is left alone; an unlink that needs a second person
  (the item was counted since, M6) waits for one, and a re-run after the approval re-opens its proposal. A listing whose bulk
  link was undone never qualifies for a bulk confirm again (`bulk_undone_before`): one at a time from then on. Audited
  per decision and per run (`mapping.key_bulk_undo`).
- **Two people:** none of the bulk's decisions needs a second person by construction, and DecisionService checks the rule again
  at each one. A link that two people made on the lane target (a multiple, a relink) is never overridden by one bulk decision
  (M27). Attribution: every bulk decision's `decided_by` is the sample's owner (`--lead`); the sample's confirmations are the
  owner's own decisions on the screen; the re-band's new proposals are attributed with `bin/reband_proposals.php --by`.

**Review of M26-M28 (2 Oct 2026, slot mpk3; fixed in slot mpk4).** One blocker, two important findings, three minor ones and
three nits, all applied:
- Blocker: the basis did not cover the lane target's link (probes P1 relink, P1b ignore, P5 u = 2 by two people; P5 linked the
  Electrofag listing at u = 1 anyway). Fixed by the `target_link` part, `target_relinked`, the `FOR SHARE` read and the check
  after the link (M27). Tests: `KeyBulkTest` (basis, eligibility, the bulk run with all three probes).
- A sample of 1 unlocked the whole population (P2): `MIN_SIZE` 20 at create, in the schema, and `fit` at the bulk confirm.
- A failed sample could be replaced by a new one over the same population (P3), and the documented flow let the operator see
  members before choosing the seed: server-drawn seed on `--apply` only, no members in the dry run, `in_other_sample`,
  `failed_sample_population` and the override rule above.
- Any lead's (or a mapper's) confirmation counted, and needs_second was not checked: owner-only, one person, `--lead` = owner,
  and the banner on the review screen. Members stay in the normal Key queue (hiding them from others would make the queue
  counts disagree with the bands); the banner is the guard.
- The basis dates from the import, not the run's export: the title checks. The dry run gave counts only: `--report`.
- `--limit` counted skips; the re-band was attributed to the system; the migration number collided with phase I-2's
  `0009_suppliers.sql`: links only, `--by`, `0010_key_bulk.sql` (since renumbered `0012_key_bulk.sql`, below).

Open after M26-M28 (nothing was run on `cw_staging`):
- Deploy with `0012_key_bulk.sql` (`install_cron.sh --migrate`); then, as the owner, the runbook: re-band (dry run, then
  `--apply --by`), draw the sample (dry run, then `--apply`), confirm or reject the 20 on the screen, then the bulk confirm (dry
  run with `--report`, a canary, then `--apply`).
- Phase I-2 adds `0009_suppliers.sql`, `0010_purchase_orders.sql` and `0011_reorder.sql`; this branch's migration, written as
  `0010_key_bulk.sql`, was renamed `0012_key_bulk.sql` when it was merged after Phase I-2 (2 Oct 2026; content unchanged but for
  its header comment). The migrator records a migration by its file name and refuses a schema that holds a version with no
  file, so the rename is safe only because 0010_key_bulk had been applied to throw-away `cw_test_*` schemas alone (dropped and
  re-created on every PHPUnit run), never to `cw_staging`. The migrator applies every pending file in name order.


## Matching rule fixes from run3 (slot `mpk2`, 2 Oct 2026)

The five follow-ups that run3's review recorded. None changes a band threshold (M26 did) or anything already on `cw_staging`: the
proposals there keep the evidence run3 stored, and `StoredBand` replays stored vetoes, not new ones. They act on the next matching
run. Code: `src/Matching/{Form,JudgeScratch}.php` (new), `src/Matching/{Normalizer,TitlePattern,Flavour,Veto,JudgeCard,Band}.php`,
`tools/first_match/{run,judge_full,assemble,extract_fixture}.php`, `tests/fixtures/matching/golden_listings.json` (23 rows added,
the old ones byte-identical); tests in `tests/matching/run.php` (section 6), `tests/Unit/BandTest.php`, and
`tests/Unit/MatchingGoldenTest.php`, which runs `tests/matching/run.php` inside the suite. Engine `n2.1/c1.0/v2.1/b2.1/f2.1+fv1-aeaa0af8/tp1.1`.

**M29. The run3 follow-ups.**
- **(a) Riot Squad "BAR EDTN": a false `line_modifier` veto (TitlePattern tp1.1, Normalizer n2.1).** "xl" is on 3+ Riot Squad
  products on Electrofag, so it entered that brand's line lexicon. TitlePattern then started the line of "Mango XL Riot Squad BAR
  EDTN 10ml Nic Salt" at "xl", which gave the flavour "mango" and the modifier "xl". Vape and Go's "Mango XL Nic Salt E-Liquid by
  Riot Bar Edition" has the flavour "mango xl" and no modifier. The result was a one-sided "xl" and a hard veto on the true pair. In a
  flavour-first title, line modifiers at the head of the line now stay in the flavour, as the class already intended ("a modifier
  before the line belongs to the flavour"), even when the lexicon holds them. This applies only while a brand or lexicon word that
  is not a modifier is left in the line. Line-first titles ("Hayati Pro Max Cherry Ice") are unchanged. The golden fixture now
  carries the real lexicon, which is why the old test, run with an empty lexicon, missed the bug. Every Riot XL flavour exists only
  as XL in both catalogues, so "Mango XL" against a plain "Mango" has no real case. A test pins it anyway: it is a soft flag
  (`flavour_extra`), never Key.
- **(b) "B Gum" = Bubblegum (Flavour f2.1).** `BIGRAMS['b gum']` and `SYNONYMS['bgum']` canonicalise to "bubblegum". Hayati
  "Blueberry Bubblegum" against "Blueberry B Gum" was a `flavour_diff` Conflict. A stated "B Gum" is now a flavour word, so
  "Strawberry Watermelon" against "Watermelon B Gum / Strawberry B Gum" is a hard `flavour_superset` (before: the soft
  `flavour_extra`). The generated seed vocabulary (`fv1-aeaa0af8`, tied to the export) is unchanged. A rebuild would drop "bgum",
  which is now a synonym of a seed word.
- **(c) One scratch directory per judge (`CW\Matching\JudgeScratch`).** run3's 98 judges ran in parallel and all wrote into the
  one session scratchpad under the same names ("view.txt" 38 times, "compact.txt" 14 times, from the judge transcripts), so one
  judge could overwrite or read another's notes. The chunk builders (`run.php` pilot 2 and `judge_full.php`) now create one empty
  directory per chunk, mode 0700, under `--scratch` (default `<parent of --out>/judge_scratch/<run>`), before any chunk is
  written. The directory is named in the chunk file (`scratch_dir`, with the instruction in `purpose`) and in
  `<run>_chunks.json`, together with `scratch_root` and a `judge_task` note telling the orchestrator to hand each judge its own.
  The build refuses:
  - a root inside the run or private folder, or one that holds either;
  - a chunk named twice or a name that is a path;
  - a directory that already holds files (a previous attempt).

  The prompt of record (`judge_v2.md`, sha256 0bfb5977...) is unchanged; it already says "Work in your own scratch directory".
- **(d) One form enum (`CW\Matching\Form`).** `Form::ALL` (the 14 values), `CLASS_OF`, `SUBS`, `FLAVOURED`/`flavoured()`,
  `label()` and `canonical()` are now the one definition. `Normalizer::FORMS`/`FORM_CLASS` are aliases of it, and the Normalizer
  throws on a form outside it. `Veto::consumable()`, the Normalizer's flavour split and the seed vocabulary all use
  `Form::flavoured()`; they held three copies of the list before. Veto details name forms with `label()` ("pod_kit/prefilled",
  v2.1, no rule change). JudgeCard now shows `extracted.form` as the enum value and `extracted.form_sub` apart. run3's cards said
  "pod_kit (prefilled)", which is not a value the prompt allows, and the judges answered `listing_extract.form` with 11 spellings
  outside the enum ("prefilled pod kit", "prefilled_pod_kit", "vape_kit", "e-liquid", ...; 39 of 2,916 answers).
  `Form::canonical()` maps those, the old label and plurals onto the enum. `assemble.php` counts them in
  `proposals_summary.listing_extract_form` (information only; the judge's form never decides a band). Rebuilding run3's chunks
  with this code changes only `scratch_dir`, the form/form_sub split and the 5 Riot XL listing cards.
- **(e) Band's key-lane no-match label (b2.1, reasons only).** On the barcode or transfer lane, a judge `no_match_in_list` below
  80 (80 and above is a Conflict) fell through to `unrecognised_outcome` at 70-79, which `assemble.php` relabelled afterwards, and
  was `no_match_in_list_<conf>` below 70. Band now says `ai_no_match_on_key_below_80_<conf>` itself, and assemble's relabel is
  gone. `unrecognised_outcome` now means only an outcome outside `Band::OUTCOMES`, at any confidence. No band moves, so the
  version stays b2.1 (not deployed yet). `StoredBand` now replays the same reasons the four run3 proposals stored.

Measured offline on run3's inputs (read-only; scratch copies of the run folders):
- **Features.** All 38,119 listings of both exports were normalised with the old and new code. Only 20 Electrofag listings
  change, all Riot Squad "<Flavour> XL ... BAR EDTN": the flavour gains "xl" and the modifier "xl" goes. No Vape and Go listing
  changes, and no form or form_sub changes anywhere.
- **Pairs.** All 160,121 run3 pairs were vetoed again (lane targets, candidates and judge picks). No barcode or transfer
  lane-target pair changes. 21 candidate pairs lose every veto, all true:
  - 15 Riot XL pairs with the same flavour and strength;
  - 6 Bubblegum/B Gum pairs.

  10 unvetoed pairs gain a hard `flavour_superset`, all real differences: "Strawberry Watermelon" against a flavour with "B Gum"
  added. The other 369 changes stay vetoed: the false `line_modifier` goes (374 pairs in all), but flavour or strength still
  separates the pair.
- **Judge picks.** 7 of run3's 9 `ai_match_on_vetoed_pair` Conflicts were these false vetoes (A#280, 1076, 1077, 5820, 6484;
  3352, 3398; 13 units in 365 days). On a new run they would be Check (5) and Can't tell (2, judge confidence below 80). They
  are candidates-lane listings, so never Key.
- **Re-assembly.** Re-assembling run3 with the new and the old code gives byte-identical proposals (judgements identical but
  for paths). The `StoredBand` replay of all 2,623 proposals gives back every stored band, and now every non-Manual reason (the
  old code had 4 mismatches, the relabelled ones).
- **Tests.** The new golden cases fail on the old code (5 of 59) and pass on the new code (59 of 59).

Open after M29:
- Nothing changes on `cw_staging` by itself. The 7 listings above stay Conflict proposals there until a new run's proposals are
  imported, or a person decides them on the review screen. A person may link them today: a Conflict needs a mapping lead
  (M7), and the evidence shows the veto that no longer applies.
- The orchestrator of the next judge run should pass each judge its `scratch_dir` from `<run>_chunks.json`. The chunk file names
  it, but the run3 task text named only the prompt and the chunk.
- Staff can still type a free-text form into a new item's card (`DecisionService` card override `form`, 32 characters). It is
  not mapped through `Form::canonical()`. That is outside matching and was left alone here.

## Company details screen (slots `co1`–`co5`, 2 Oct 2026)

The owner's request of 2 Oct 2026: "add an option in the setting where I can add or edit these details" — the company details
every purchase order prints (legal and trading name, company number, VAT number, registered address, purchasing phone and
e-mail, delivery address). Until now they were the nine `company.*` settings of 0009, read-only for the app login and changed
only with `bin/settings.php --admin` on the server, and every PO PDF said "COMPANY DETAILS NOT CONFIRMED — DO NOT SEND" while
`company.confirmed` was false. Built in slot `co1`, reviewed three times (security, fraud and concurrency; validation, PDF and
migration; the owner's experience on a phone), review fixes in `co5`. Numbered I90–I99. Code: `migrations/0013_company_profile.sql`,
`src/Company/{CompanyDetails,CompanyInvariants}.php` (new), `src/Ui/Controller/CompanyController.php` (new),
`src/Ui/views/{company,company_form}.php` (new); changed: `src/Settings.php`, `bin/settings.php`, `src/Auth/Permissions.php`,
`src/Schema/Grants.php`, `src/Invariants.php`, `src/Ui/{Kernel,Context}.php`, `src/Ui/Controller/{Reference,Reviews,PurchaseOrders}Controller.php`,
`src/Ui/views/{settings,reviews,purchase_order,purchase_order_edit}.php`, `src/Documents/Documents.php` (`lockTask`),
`src/PurchaseOrders/{PurchaseOrders,PurchaseOrderPdf}.php`, `src/Output/Fpdf.php`, `public/ui/assets/app.css`; tests
`tests/Unit/CompanyDetailsCheckTest.php`, `tests/Integration/Company/CompanyDetailsTest.php`, `tests/Integration/Migration0013Test.php`,
`tests/Integration/UiKernel/CompanyScreensTest.php` (new), and updates of `PermissionsTest`, `PurchaseOrderPdfTest`,
`UiTemplatesTest`, `UiUnitTest`, `SettingsTest`, `Migration0009Test`, `GrantsTest`, `MenusTest`, `ReferenceScreensTest`,
`SupplierScreensTest`, `PurchaseOrderScreensTest`, `UiSecurityTest` (HTTP, slot ui) and `tests/Support/UiResponse.php`
(`form($action, true)` reads textareas).

**I90. Where and who (provisional, owner to confirm the roles).**
- **Where:** Reference › **Company details** (`/ui/reference/company`, a menu item for every role, `key` company), the top of
  Reference › Settings (a "Company details" card: confirmed or not, what is missing, "Add or change the company details" for
  those who may, "See the company details" for the others), and every purchase order whose PDF says "do not send" because of the
  company details (a note with the link on the draft editor and the order's page, and a link inside the "Send anyway" warning;
  the link reads "Add or confirm the company details" only for a person who can and while they are unconfirmed, else "See the
  company details"). The page shows the details as printed, the status, what a confirmation still needs and any problem, the
  checks of a confirmation (I94), the approved orders that carry a rejected change (I98), every version, and "See how a purchase
  order will look (PDF)". Changes are made on `/ui/reference/company/edit`: one form, every field, plain words, one column (I97).
- **Who:** everyone with `reference.view` (all 14 roles) reads. Two new permissions, both **reviewer** only (the owner holds
  reviewer + mapping_lead on staging): `company.edit` (save) and `company.confirm` (confirm; decide another reviewer's check,
  I94). **Never admin** (I12): admin holds neither, a set that breaks I12 is read fail-closed, the routes answer 403 and
  `CompanyDetails` refuses again inside its transaction (403 `admin_cannot_edit`, `admin_cannot_review`; a buyer
  `role_not_allowed`; a system caller `staff_required`: no CLI changes them). Two permissions rather than one so that the owner
  can later let buyers keep the details while only reviewers confirm them: one line each in `Permissions::MAP`.

**I91. One source of truth: `company_profile`, versioned and append-only (amends I38).**
- `company_profile` (0013) holds **one row per version**: `version` (the PRIMARY KEY, 1, 2, 3 ...), `kind` (seed, change,
  confirm, unconfirm), the nine fields (`vat_registered` 1 / 0 / NULL beside `vat_number`), `confirmed` with `confirmed_by`,
  `confirmed_actor`, `confirmed_at`, `baseline_version` (a confirmation: the confirmed version it was compared with, I94), the
  optional `reason`, `saved_by`, `saved_actor`, `saved_at`. The app login has SELECT and INSERT only (`Grants::APPEND_ONLY`), so
  no version is ever rewritten or removed. The highest version is in use. CHECKs (any login, admin SQL included): the
  confirmation columns agree with `confirmed`; `confirm` rows are confirmed, `change` / `unconfirm` rows are not; only a
  `confirm` has a `baseline_version`, always an earlier one; a VAT number is there exactly when `vat_registered` = 1; outside the
  seed the company number is `^([0-9]{8}|[A-Z]{2}[0-9]{6}|R[0-9]{7}|(IP|SP|NP)[0-9]{5}R)$`, the VAT number
  `^(GB|XI)([0-9]{9}|[0-9]{12})$` and `saved_by` is set.
- **Append-only does not stop the app login from ADDING a made-up version** (review finding: a forged confirmed row with other
  details, or version 4294967295, which froze every later save with a database error). Before 0013 the app login could not
  change the company details at all, so the nightly invariants now check every version (**C1–C4**, `CW\Company\CompanyInvariants`,
  run by `bin/invariants.php`, the hammer and every stock test, like D7 and P2 for the other write-once tables): C1 versions are
  exactly 1..n and only version 1 is a seed; C2 a confirm or unconfirm has the same nine fields as the version before it, a
  confirm was saved by its confirmer and its baseline is an earlier confirmed version; C3 every version has exactly one audit row
  of its own, by the actor that saved it (seed `company.change` by `system:migrate`, change `company.change`, confirm
  `company.confirm`, unconfirm the `company.review` whose `unconfirmed_version` it is); C4 a change was saved by someone holding
  `company.edit` then, a confirm and an unconfirm by someone holding `company.confirm`, none of them admin then (`staff_role`
  history, 5 minutes' tolerance for the app's and the database's clocks), and every check is on a confirm version, opened by its
  confirmer and decided by nobody involved. The history also shows field changes of a confirm or unconfirm (which the screen never
  makes) with "tell the person who looks after CW". A version number at the top of its range now stops a save with 500
  `company_versions_broken` and a plain sentence instead of a database error (C1 names the gap). **No trigger** (it would stop a
  forged row at once): migrations have no `DELIMITER`, so no triggers or procedures (D25, M1); a forger able to write as the app
  login can also write `audit_log`, so C1–C4 detect what they cannot prevent, as for every other write-once table.
- **0013** copies the nine `company.*` settings into version 1 (kind seed, actor `system:migrate`, audited `company.change`,
  reason "copied from the old company settings"), **tidied as the screen tidies what is typed** (review finding: a seed with a
  double space inside a line was "changed" by an untouched Save, which unconfirmed it): names on one line with single spaces,
  addresses one line per line (CR LF and CR read as LF, tabs as spaces, runs of spaces collapsed, no space at either end of a
  line, no blank line), the phone's spaces collapsed, the e-mail trimmed; the company and VAT numbers without spaces and in
  capitals when that gives a valid number (9 or 12 bare digits gain GB), otherwise as typed (the page lists the problem and a save
  must correct it). Only Unicode NFC is left to the first save (SQL cannot do it; a seed with a decomposed accent shows "save them
  once, then confirm", I94). `company.confirmed` = true is kept, with the actor and time of that settings row. It then **deletes the
  nine `company.*` rows from `app_setting`**: code still reading them fails loudly (`Settings::get` of an unknown key is a
  `\LogicException`), and nothing can drift between two copies. `app_setting` stays read-only. 0013 also adds `'company'` to
  `review_task.subject_type` (I94). Re-runnable: `CREATE TABLE IF NOT EXISTS`, the seed and its audit row behind NOT EXISTS
  guards, an idempotent DELETE and MODIFY (a run that stopped half way, or the file applied twice, doubles nothing:
  `Migration0013Test`).
- `Settings::company()` now returns `CompanyDetails::company()`: the version in use, read on every call (never cached), with
  `vat_registered` and `version` added to the old keys; version 0 with empty placeholders when there is no row (a test schema
  after `TestDb::clean`, which empties the table). Every PDF and sending path goes through it: `PurchaseOrderHandler::post`
  snapshots it at approval, `PurchaseOrders::pdfData` prints it for a draft. **A posted PO keeps the details it was approved
  with** (unchanged: its `company_snapshot`); its PDF and `sendWarnings()` read the snapshot as before (plus I98).
- `bin/settings.php --set=company.<anything>` (and `Settings::set`) is **refused** (400 `company_details`, exit 2): "the company
  details are no longer settings: a reviewer adds, changes and confirms them on the Company details screen (Reference > Company
  details, /ui/reference/company), which keeps every version". **No CLI write path is kept:** the details are not an
  emergency (a PO can always be drafted; the banner only warns), a second path would need its own audit and version rules,
  and the break-glass when nobody holds reviewer is `bin/reset_staff.php --roles=reviewer` for a real person (I12, I15).

**I92. What the form accepts (validation and normalisation, `CompanyDetails::check`).** Every problem is reported at once
(422 `company_invalid`, `detail.errors` field => one plain sentence, shown at the field; what was typed is kept). Saving is
allowed with fields still empty (the owner may fill them in bit by bit); a confirmation needs more (I94). The form is drawn from
the stored details as a save would store them (`CompanyDetails::tidy`), so an untouched Save changes nothing.
- **Text:** valid UTF-8, NFC (a typed "e" + combining accent becomes é), no control character (C0, DEL, C1). Names are one line
  (runs of spaces and tabs become one space): legal and trading name at most 160 characters (Companies House's limit; within
  `Settings::STRING_MAX`). Addresses: CR LF / CR read as LF, tabs as spaces, blank lines dropped, at most **8 lines of 100
  characters** (807 at most, within `Settings::TEXT_MAX`).
- **Printable on the PDF:** the PO's font is Helvetica, a PDF core font in Windows-1252 (I24): Western European letters, £, €,
  curly quotes and dashes print; Polish ł, Greek, Chinese, emoji or a right-to-left override would come out as "?" or vanish.
  They are **refused** with the character ("The legal name contains "Ł", which a purchase order cannot print ... type a plain
  letter instead"; an invisible one is "an invisible character"; no code points, which mean nothing to the owner), never
  changed silently. (Embedding a Unicode font would need FPDF 1.9's tFPDF route and `ext-gd`, I24: not for this task.)
- **Company number:** spaces and hyphens removed, capitals; 8 characters: 8 digits (leading zeros kept, never added: "1234567"
  is refused with "keep the leading zeros") or 2 letters + 6 digits (SC123456, NI..., OC...); also accepted, though the help
  text does not list them (review finding): R + 7 digits (a Northern Ireland company registered before 1922, R0000123) and IP,
  SP or NP + 5 digits + R (registered societies, IP12345R).
- **VAT:** a choice of three — "VAT registered" (a number required), "Not VAT registered" (the explicit choice: no number, and
  none may be typed), "Not known yet"; the three choices sit together, the number below them. A number typed with "Not known
  yet" means registered. The number: spaces, dots and hyphens removed, capitals, 9 or 12 bare digits gain **GB**; then GB or XI
  + 9 digits, or + 12 for a branch. Stored without spaces, shown and printed as "GB 123 4567 82". HMRC's check digits (weighted
  modulus 97, old and "9755" schemes) are a **warning on the page only, never a refusal** (`vatChecksumOk`): a wrong refusal
  would lock the owner out of their own number.
- **Phone:** digits, spaces and + ( ) - . only (the help text says so too), + only first, 7 to 15 digits, at most 32 characters.
  **E-mail:** `FILTER_VALIDATE_EMAIL`, at most 191. **Reason:** optional, one line, at most 500, kept in the version and the
  audit row.
- No bank details (decision 25, I39): the form has none and says "We never keep bank details here".

**I93. Concurrency and one effect per form.** The edit and confirm forms carry the **version** they were drawn with and a
FormOnce key (I46): the same form sent twice (a double tap) replays the first result, one version. A save or confirmation whose
version is no longer current is 409 `company_changed` and writes nothing ("Someone changed the company details while you had
them open (<who> saved version n at <time> UTC): nothing was saved" / "... nothing was confirmed"). Two saves of the same version
at the same moment both pass that check; the PRIMARY KEY on `version` turns the second away (1062 → the same 409) and its
transaction, audit row included, rolls back (an `unconfirm` racing a save lands the same way). After a stale save the form comes
back with **what the person typed**, the new version and "What changed meanwhile" (each field "Was:" / "Now:" on lines of their
own), so saving again is a deliberate overwrite (like I13's `roles_seen`); after a stale confirmation the page shows the details
as they are now ("check them, then confirm again"). A form that was saved and is sent again **with other values** (the back
button; FormOnce's `idempotency_key_reused`) comes back the same way: "You already saved this form once. What you typed is kept
below: check it and press Save again", at the current version with a fresh key, so the next Save works (review finding: it used to
come back stale and fail a second time).

**I94. Confirming; one person may change and confirm; a check when they confirm their own risky change (provisional, owner to
confirm).**
- "These details are correct" is a separate form on the details page (a reviewer, the version shown). It adds a `confirm`
  version with the same details. It needs: legal name, company number, registered address, a VAT number or the explicit "not VAT
  registered", purchasing e-mail and delivery address (trading name and phone are optional); else 422 `company_incomplete` naming
  what is missing (and no form on the page). A seeded value that breaks today's rules is 422 `company_invalid` (the page lists the
  problems, confirmed or not); one that is valid but not yet in its tidy form is 422 `company_unsaved`, and the page offers no
  button but says "Press "Change the details" and Save once (spaces and line breaks are tidied), then confirm them here".
- **Saving a change of any field makes the details unconfirmed again** (every field is printed): the next draft PDF says "do
  not send" until someone confirms them again. A save that changes nothing writes nothing ("Nothing changed"). A save opens no
  check: while the details are unconfirmed every PDF says "do not send".
- **The person who changed the details may confirm them.** The owner works alone on staging today; a two-person confirmation
  would lock them out. Instead the check is made **when details are confirmed** (review finding, blocker: it was made when a
  save changed *confirmed* details, so saving the phone first, or a second save before confirming, skipped it and the delivery
  address could be diverted unchecked). A confirmation is compared with its **baseline**: the newest earlier confirmed version that
  carries no value a reviewer rejected (I98); `baseline_version` records it. When a **watched field** — legal name, company number,
  VAT (registration or number), purchasing e-mail or delivery address (a changed delivery address on POs is the classic way to
  divert goods) — differs from the baseline (compared as tidied, so tidying alone is no change), however many saves it took, **and
  the confirmer saved a change of a watched field since the baseline** (they confirm their own change), the confirmation opens a
  **non-blocking check** (`review_task` subject `company`, subject_id = the confirm version, kind review, reason
  `company_changed`, due 7 days). A watched change confirmed by **someone else** had its second person already: no check. The
  first confirmation ever has no baseline and no check; if every earlier confirmed version carries a rejected value, the baseline
  is the empty details.
- The check is listed in Document reviews (type "Company details", "Company details, version n (delivery address, ...)", filter
  `?type=Company`, counted in the badge of those who may decide it) and decided on the Company details page, where its card shows
  every field that changed since the baseline ("Was:" / "Now:"). It is decided by a reviewer **not involved**
  (`CompanyDetails::involved`): not the confirmer, and nobody who saved a change after the baseline (`refusal`;
  `ck_review_task_not_own` holds the opener in SQL; never admin). "The change is right" records the check; "Reject the change"
  (a note of 3–500 characters) records why (I98). Documents' approve/reject refuse a company task (409 `company_task`).
- With one reviewer the check stays open: the card says "Nobody else holds the reviewer role yet, so this check stays open. It
  stops nothing; once a second person holds the reviewer role (People and roles), they can close it", with no due date and never
  "overdue" while nobody could decide it (review finding: a lone owner collected red overdue checks they could not act on). The
  People screen already warns while fewer than two people hold reviewer (decision 3). If the owner wants two people to confirm,
  `confirm()` refuses a person in `involved()` (a line, like `Suppliers::refusal`).

**I95. History and audit.** The page lists every version, newest first: "Version n · changed by / confirmed by / made unconfirmed
by <name> on <time> UTC", confirmed or not, the outcome of its check if any ("check: rejected"), the reason, and what changed
against the version before ("Was:" / "Now:"; the seed is "copied from the old settings"). People never read `system:*`: the seed
is by "the set-up (copied from the old settings)" and a confirmation copied from the settings by "the old settings". `audit_log`:
`company.change` {version, changed, before, after (only the fields changed), reason, was_confirmed} (the seed: actor
`system:migrate`, after = all fields), `company.confirm` {version, confirms_version, baseline_version, watched_changed,
review_task, details}, `company.review` {task, version, decision, note, unconfirmed_version}; entity `company_profile` / the
version (C3 checks one per version). The FormOnce rows add `ui.company.save` / `ui.company.confirm`.

**I96. The PDF.**
- The letterhead, the footer and the "Deliver to" box print `Settings::company()` (a draft) or the snapshot (an approved order),
  as before; the "COMPANY DETAILS NOT CONFIRMED — DO NOT SEND" banner goes once the details in use are confirmed (a draft) or
  were confirmed at approval (an order). **An order approved before the details were confirmed keeps saying it**: its page says
  so and that amending it (cancel + copy, I50) prints the confirmed details; the Company details page counts the approved orders
  not sent yet that carry unconfirmed details (for people with `purchasing.view`). An order approved with details a reviewer later
  rejected prints "COMPANY DETAILS REJECTED AT REVIEW — DO NOT SEND" (I98).
- The VAT number prints as "GB 123 4567 82" (an older snapshot prints as stored); "not VAT registered" prints **no VAT line**
  in the letterhead or footer; not said yet prints "VAT no. [to be confirmed]" as before. A long delivery-address line is
  **wrapped** in its box, never cut with "…" (a driver must read all of it); a footer too wide for the page (a long legal name) is
  cut with "…" so that the page number always shows (`Fpdf::Footer`). The Supplier and Deliver-to boxes are drawn **whole on one
  page**: when the letterhead leaves too little room for them, they start the next page (review finding: at the largest values
  the form accepts — names of 160 characters, both addresses 8 × 100 capitals, a 191-character e-mail — they ran off page 1 and
  the delivery address landed on page 3 outside its box; the largest box, about 116 mm, fits a fresh page).
- "See how a purchase order will look" (`/ui/reference/company/sample.pdf`, everyone with `reference.view`):
  `PurchaseOrderPdf::sampleData` — no PO number, banner "SAMPLE — NOT AN ORDER", "Order no. SAMPLE", a fictional supplier
  ("Example Supplier Ltd") and three lines, the details in use (with the "do not send" banner while they are not confirmed),
  today's UK date and `po.terms`. Served as an attachment under the download CSP like every PDF (I99).

**I97. Screens, measurements, open items.**
- Phone width: one column (the form's fields are full width, labels above them, `type=tel` / `email` and `autocomplete` for the
  phone keyboards), no table on either page (the history is a list; "Was:" and "Now:" of an address on lines of their own), the
  details list stacks below 600 px, and so do the check forms (`article.review form.inline`, this page only: review finding, the
  first rule changed every inline form of the app); the menu, CSRF, hardened headers and escaping are the kernel's (I11,
  U-entries). `CompanyScreensTest` checks the markup; it was not looked at on a real phone.
- Plain words on the owner's pages (review finding): no decision numbers, no "CW", no `system:*` actors, no code points; the hint
  under the status names the button it means; the status quotes the banner with the PDF's em dash.
- **Test runs (2 Oct 2026, after the review fixes):** full suite `scripts/remote.sh co5 vendor/bin/phpunit`: green, **741 tests,
  15,134 assertions, 75 skipped**, 10 min 54 s. HTTP: `scripts/remote.sh ui vendor/bin/phpunit --filter Ui`: 140 tests, 20,402
  assertions, OK (1 skip, the opt-in perf test); `scripts/remote.sh api vendor/bin/phpunit --filter Api`: 59 tests, 3,116
  assertions, OK (1 skip, `UiReviewFlowTest`). Hammer `scripts/remote.sh co5 php tests/concurrency/hammer.php --seed=20261002`:
  **RESULT: PASS (59 checks passed, 0 failed)**, 91 s, 0 errors or deadlocks surfaced. (Before the review fixes, in `co1`: 734
  tests, 15,095 assertions, 75 skipped; baseline of the unchanged tree: 709 tests.)
- **Deploy:** 0013 needs the code of this change in the same `install_cron.sh --migrate` run (old code on the new schema reads
  `company.*` settings that are gone: every PO PDF and approval would fail with a 500; new code on the old schema has no
  `company_profile`). Then the owner opens Reference › Company details, adds the details and confirms them (docs/ops.md).
- Open: the owner to confirm I90 (reviewer edits and confirms), I94 (one person may confirm, checked by another reviewer when
  they confirm their own change; which fields are watched), I98 (who may confirm a rejected change again), I92's 8 × 100 address
  limit and the refusal of characters the PDF cannot print. Approved orders that carry unconfirmed or rejected details are not
  amended automatically (a buyer decides, I96, I98).

**I98. A rejected change (review findings: a rejected change could be confirmed again at once by the person who made it, and
orders approved with it kept printing it with no warning).**
- A **rejection** records the reviewer's note. Its **rejected values** are each watched field the checked confirmation changed
  against its baseline, with its new value (an empty value is never one: it cannot be confirmed anyway). While the details in
  use are confirmed and still carry a rejected value, the rejection adds an **`unconfirm` version** ("a reviewer rejected the
  change confirmed in version n: <note>"): every new PO PDF says "do not send" until someone corrects and confirms them. When they
  no longer carry it (changed since, or not confirmed), nothing else changes ("The details in use are not confirmed with it, so
  nothing else changed").
- **The people involved in a rejected change may not confirm details that carry a rejected value again** (403 `rejected_change`,
  nothing written: "A reviewer rejected this change of the delivery address (<reviewer>: "<note>"). You made or confirmed that
  change, so another reviewer must confirm it; or change the details."). Tweaking another field does not get round it: any one
  rejected value counts. Such details are never a baseline, so a confirmation of them by anyone is compared with the last
  confirmed details without them.
- **Another reviewer may confirm them after all** (they decided the change is right: the rejecting reviewer included): that
  confirmation **lifts** the rejection (`CompanyDetails::rejections()` leaves out a rejection followed by a confirmation, by
  someone not involved in it, of details that carry its values). The owner alone can never be blocked by this: a rejection needs a
  second reviewer, who can then confirm.
- **Approved orders that carry a rejected value** (states approved, sent, part received; matched on the snapshot's watched
  values, tidied; a snapshot from before 0013 counts a VAT number as registered) are flagged until they are cancelled, received or
  the rejection is lifted: `PurchaseOrders::sendWarnings()` adds "The company details it was approved with include a change a
  reviewer rejected (see Company details): cancel or amend it rather than send it" (sending needs "Send anyway", I86), the PDF
  prints "COMPANY DETAILS REJECTED AT REVIEW — DO NOT SEND", the order's page says so with "See the company details", and the
  Company details page lists them with links (for people with `purchasing.view`). `decideReview()` returns how many there are.

**I99. Review findings applied and rejected (`co5`).** Applied: the check at confirmation against the baseline (I94, blocker);
rejected values (I98); the invariants C1–C4, `company_versions_broken` and the history alarm (I91); the flagged orders (I98); the
check card's "Was:" / "Now:" (I94); the boxes kept on one page (I96); the tidy seed and form, problems shown when confirmed, the
rarer company numbers, the phone's "." in the help (I91, I92, I94); no confirm button for an untidy seed, the form sent again,
the lone owner's calm check, the link words of an approved order, the scoped phone-width rule, plain words, the VAT choices
together (I93, I94, I97). Rejected, with reasons:
- *A BEFORE INSERT trigger to force `version = MAX + 1` and confirm-equals-previous:* no triggers in CW's migrations (D25, M1);
  C1–C4 find a made-up row each night, as D7 and P2 do for the other write-once tables, and the 1264 path no longer surfaces as a
  database error (I91).
- *Keep a check at save time as well:* dropped. While details are unconfirmed every PDF says "do not send"; the risk begins at
  confirmation, where the check now is. Two checks of one change would only add open tasks the lone owner cannot close.
- *Open the sample PDF inline on a phone:* kept as an attachment. Every CW download is served with the download CSP (`sandbox`),
  under which browsers' built-in PDF viewers do not display the file; loosening the CSP for one PDF is not worth it, and a phone
  opens a downloaded PDF in one tap.
- *Refuse the rejecting reviewer too:* no. A rejection needs a second reviewer; refusing them as well would leave nobody able to
  confirm the change if it was right after all. Their confirmation is recorded and lifts the rejection (I98).


## Holding screened listings back from the Key bulk confirm (slots `ksa1`, `ksa3`, 6 Oct 2026)

Before the bulk confirm runs, someone screens its dry-run report (`--report`) and finds proposals that a person should decide
one at a time: the titles show another pack size, a flavour that only looks the same, a strength. Until now nothing kept them
out. The bulk confirm links every proposal of the population that qualifies, so the only way was to decide each one on the screen
first. A list passed to one run would not have been enough: a re-run without it, or with an older copy, links them after all.
Numbered M30. Code: `migrations/0014_key_bulk_hold.sql`, `src/Mapping/KeyHold.php` (new), `src/Mapping/{KeyEligibility,KeyBulk}.php`,
`src/Schema/Grants.php`, `bin/key_bulk_hold.php` (new), `bin/bulk_confirm_key.php`, `src/Ui/Controller/{ReviewController,SamplesController}.php`,
`src/Ui/views/{listing,sample}.php`. Tests: `tests/Integration/Mapping/KeyBulkHoldTest.php` (new), new cases in `KeyBulkToolsTest`,
`tests/Integration/UiKernel/KeySampleScreenTest.php` and `GrantsTest`. Runbook: `docs/ops.md`, "Key spot-check and bulk confirm",
step 5.

**M30. A durable hold of screened listings (amends M28 for the bulk confirm and the sample's population).**
- **The hold is on the LISTING.** The file names each row by its proposal of the sample's population, because that is what the
  screened report lists, but what is held is that proposal's listing:
  - every proposal of the listing is held: the one the hold was written for, and any newer one (a new matching run's, a re-band's,
    or the undo's re-opened copy), whatever item it proposes;
  - for every sample: the hold keeps the listing out of the bulk confirm of the sample it was written for, out of every later
    sample's population, and out of the bulk confirm of a sample drawn before the hold.

  A hold keyed on the proposal was not enough (review blocker, below): a new matching run replaces every open proposal (M29's next
  run will), the replacement is in no population, so the next sample drew it and its bulk confirm linked the held listing.
- **Where the hold lives: `key_bulk_hold` (0014).** The table is append-only for the app login (SELECT, INSERT). A HOLD row names
  the sample, the proposal, its listing, the reason and the mapping lead. A RELEASE row names the hold it ends
  (`released_hold_id`). A listing is held while some hold row of it has no release row (`KeyHold::activeForListings`). Why a table:
  - A status on `match_proposal` is out. The app login may change only `match_proposal.status` (M16), and any status other than
    `open` would take the proposal out of the Key queue, the opposite of what is wanted. A proposal status would also not survive
    the proposal being replaced.
  - A file given to each bulk run is not durable.
  - The hold must outlive the process, be the same for every run and every screen, and show who let it go and why. A release is a
    row of its own for that reason, as `match_proposal_basis` and `key_sample` are.

  The schema checks what it can:
  - the foreign key `(sample_id, proposal_id)` → `key_sample_member` allows only a proposal of that sample's population;
  - `(released_hold_id, released_kind, sample_id, proposal_id)` → `key_bulk_hold (id, kind, sample_id, proposal_id)` makes a
    release end a HOLD row (never another release) of the same proposal. `released_kind` is a stored generated column, `hold` on a
    release and NULL on a hold, so nobody can write it;
  - the unique key on `released_hold_id` allows one release per hold;
  - CHECKs: a hold ends nothing, a release ends a row, and the reason is not blank.
- **Eligibility (`KeyEligibility`).** A new reason `held_for_review` comes right after `pending_decision`. It is given to every
  proposal whose listing is held, and each row carries `hold` (the sample and proposal it was written for, the reason, who and
  when) and `listing_status`. A held listing is not otherwise touched: its proposal stays `open` and Key, the listing stays
  `suggested` and in the Key queue, and anyone may decide it on the screen as usual. `KeySample::create` draws its population
  through the same check, so a held listing is never drawn (`excluded` counts it as `held_for_review`). The re-band does not treat
  a hold as a blocker: its new proposal is on the same listing, so it is held too (test: Key>Check, then Check>Key by the real
  re-band under b2.1).
- **The bulk confirm (`KeyBulk::confirm`, `bin/bulk_confirm_key.php`).** It reports `held=N`: the proposals of the population whose
  listing is held and still waiting (unmapped or suggested), whatever their first reason. That is the number the hold tool's
  `held_after` gave, unless a held listing was decided since (the sample's page then shows it as decided). It prints one line per
  such proposal: proposal, listing, who, when and why, and the sample and proposal the hold was written for when that is another.
  The `--report` CSV gains `hold_reason`, `held_by` and `held_at`, with cells escaped against formulas as before. `held` is audited
  too. Under the locks of each link it reads the holds of the LISTING again. If one arrived since the check, the link is rolled
  back (`failed`: `held_meanwhile`).
- **The race with a running bulk confirm (of any sample).** `KeyHold` writes its holds in one transaction. That transaction first
  reads the file's listings FOR SHARE, in id order, and only then checks every row. DecisionService locks the listing FOR UPDATE
  for a link. So either the bulk link commits first, and the hold then refuses that row (`listing_mapped`), or the link waits for
  the hold to commit, then finds it under its own lock, by the listing, and rolls back. Both run READ COMMITTED (Db). The test
  runs the bulk confirm as its own process, holds the listing's proposal in an open transaction, and commits once the bulk waits
  for the listing: `applied=11 failed={"held_meanwhile":1}`. With the check after the link removed, the same test links all 12.
- **The tool (`bin/key_bulk_hold.php --sample --file --by [--release] [--apply]`, `KeyHold::run`).**
  - **The file.** A header row names `proposal_id`, `listing_id` and `reason`, in any order. Other columns are ignored, so the
    `--report` CSV with a `reason` column added is a valid file. A BOM, blank rows, quotes and line breaks in a cell are handled.
    The reason has its spaces collapsed and is 1-500 characters with no control characters.
  - **Each row is checked and listed.** A row is refused when:
    - it cannot be read (`bad_proposal_id`, `bad_listing_id`, `no_reason`, `bad_reason`, `reason_too_long`);
    - it repeats a proposal (`duplicate_in_file`);
    - its proposal is outside the population (`not_in_population`). When its listing is held, the row says under which
      proposal and sample, e.g. a release that names the newer proposal instead of the one the hold was written for;
    - its proposal is one of the sample's own members (`sample_member`): the owner confirms those one at a time, and their state
      decides whether the bulk runs at all;
    - it names a listing that is not the proposal's (`listing_mismatch`, which catches a typo in either id);
    - a hold names a listing that is no longer waiting (`listing_mapped`, `listing_ignored`, `listing_quarantined`): a hold could
      not change it, and the operator must know it is linked already. A proposal that was replaced by a newer one, on a listing
      that still waits, IS held: the hold covers the newer proposal, which may already be in a later sample's population;
    - a release names a proposal that was never held (`not_held`).
  - **The rows that pass still go ahead,** and the run exits 1, listing the refused rows. A hold only takes listings out of the
    bulk, so holding the good rows is never less safe than holding none. Refusing the whole file would leave them unheld if the
    bulk were run anyway. The exit code 1 stops the runbook until the file is fixed.
  - **A re-run of the same file writes nothing.** A row whose listing is held already, by this proposal's hold or by another hold
    of the listing, is `already_held`; a released one is `already_released`. Neither is a refusal. The hold is checked before the
    listing's state, so a held listing that a person decided since is still `already_held`. A listing has at most one hold in force
    this way, so one release frees it.
  - **The counts.** `held_before` and `held_after` are the sample's holds in force on a listing still waiting (the dry run gives
    what `--apply` would leave).
  - **One run at a time.** A second run at the same moment exits 1 ("busy"), not with the job frame's silent "skipped": the
    caller relies on the holds.
  - **The dry run is the default.** It writes nothing, and with `--apply` an audit row is written only when something changed:
    `mapping.key_hold` / `mapping.key_hold_release`, entity `key_sample`, with each row written (hold or release id, proposal,
    listing, reason), the file's name and sha256, the refused rows and the count of `already` rows.
  - **The release** names the proposal the hold was written for, of the same sample, even after a newer proposal replaced it
    (the listing page and the sample's page show that proposal's number). Once released, the listing's current proposal may go
    into a later sample's population and bulk confirm.
- **Who.** Both the hold and the release need an active mapping lead (`--by`). Any mapping lead may do either, as with the undo, not
  only the sample's owner. Provisional: the release is not reserved to another person than the one who held. A hold is the safe
  direction; the release puts listings back into the bulk, so it is audited with its own reason.
- **The screens.** The listing's review page looks the hold up by the listing. It says "Held back from the bulk confirm:
  <reason>", then by whom, when, which spot-check (a link) and the proposal it was written for. Under a newer proposal it says
  that the hold covers it; on a listing decided since, that the hold stays on record. Its decision forms are there as usual. The
  sample's page has a section "Held back from the bulk confirm": the number still waiting, each held listing (a link), why, by
  whom and when, and what became of it: waiting (and its newer proposal, if any), decided since, or, flagged, linked by a bulk
  confirm (with the undo command; that should never happen). There is no hold or release button: like the bulk confirm, it is a
  server step (U20).
- **What a hold does not do.**
  - It does not stop a person's decision.
  - It does not look at the item: a newer proposal of a held listing for another item is held too, and a person decides it.
  - It does not undo a bulk link already made: for that, use `bin/bulk_unlink.php`, whose re-opened proposals are never bulk-linked
    again (`bulk_undone_before`).

**Review of M30 (6 Oct 2026, slot ksa2; fixed in slot ksa3).** One blocker, one minor finding and one nit, all applied:
- Blocker: the hold was keyed on the proposal. Probe: proposal 2 of sample pa held, a new first-match run replaced it with
  proposal 25, sample pb (another lead) drew 25 into its population, and pb's bulk confirm linked the listing
  (`key_bulk:pb`). Fixed as above: `KeyHold::activeForListings`, `held_for_review` by listing in `KeyEligibility` (and so in
  `KeySample::create`), the check after the link by listing, the review page by listing. Beyond the fix asked for, a hold of a
  replaced proposal whose listing still waits is now accepted (it was refused as `proposal_superseded`): with the hold on the
  listing it covers the newer proposal, which may already be in a later sample's population, and refusing it left that listing
  impossible to hold. Tests: `KeyBulkHoldTest` (a new run on all 12 listings; a later sample of another lead drawn without the held
  listing; a hold written for a replaced proposal after the later sample drew the listing, which that sample's bulk then leaves
  out; the release by the replaced proposal; a re-band round trip). Both new tests fail with the eligibility keyed on the proposal.
- `held`, the sample page and runbook step 6 counted holds whose listing was decided since: `held` and `held_before`/`held_after`
  now count holds on listings still waiting, the runbook compares `held` with step 5's `held_after`, and the sample page shows a
  decided listing as such and flags one a bulk batch linked.
- Nit: the schema let a release name another release. `released_kind` (above) makes the self foreign key accept a hold row only
  (test: 1452).

Tests (6 Oct 2026): the full suite in slot `ksa3`: `OK, but some tests were skipped! Tests: 749, Assertions: 15715, Skipped: 75`
(747 tests in `ksa1`, plus the two new `KeyBulkHoldTest` cases). The 75 skipped are the HTTP screen and API tests, which run only in
slots `ui` and `api`. In slot `ui`, `UiAuthTest`, `UiReviewFlowTest`, `UiSecurityTest` and `KeySampleScreenTest` gave `OK (41 tests)`.
One earlier full run in `ksa3`, made while slot `ui` ran its tests on the same droplet and cluster, failed only the timing bound of
`LockOrderTest::testALargeMovementDoesNotHoldTheFeedClockForOneRoundTripPerItem` (256 ms against 250 ms; 55 ms in the run before).
That test does not touch this change, and the run alone was green.

Open after M30 (nothing was run on `cw_staging`):
- Deploy `0014_key_bulk_hold.sql` with this code in one `install_cron.sh --migrate` run, never the code first. The review screen
  reads `key_bulk_hold` for every listing it shows, and the bulk, sample and re-band tools read it through the eligibility (the CLI
  tools refuse a schema behind the code with exit 3; the screens do not check). 0014 applies after 0012 and 0013 in name order;
  on `cw_staging` (at 0013 since 3 Oct) it is the only pending file.

## Duplicate listings handled by CW (slot `dup1`, 6 Oct 2026)

The owner's decision of 6 Oct 2026 (support@vapeandgo.co.uk, the only active mapping lead on staging): Vape and Go's own
duplicate listings, the same physical product on two pages (e.g. listings 23408 "Vaporesso Xros Corex 3.0 Pods (Pack of 4) - 0.4
ohm" and 25772 "Vaporesso Xros Corex Replacement Pods - 0.4ohm Corex 3.0 Pod - 4 Pack", open proposal 145, lane `vpg_duplicate`),
are handled by CW: both listings are linked to ONE CW item, so their sales and deliveries count once. Nothing changes on the shop
until Vape and Go goes live on CW; prices and reviews stay separate per page. And: one person may merge items that are not
counted or protected; two people as before once either item is counted or protected, or another two-person condition applies.
Numbered M31-M36. Code: `migrations/0015_duplicates.sql`, `src/Mapping/DecisionService.php`, `src/Mapping/{Proposals,ProposalBasis}.php`,
`src/Ops/OpeningRebase.php`, `bin/mint_vpg.php`, `src/Ui/Duplicates.php` (new), `src/Ui/Controller/DuplicatesController.php` (new),
`src/Ui/views/{duplicates,duplicate_group}.php` (new), `src/Ui/{Kernel,Context,Queries}.php`, `src/Auth/Permissions.php` (menu),
`src/Ui/Controller/{ReviewController,DashboardController,ItemController}.php`, `src/Ui/views/{dashboard,item,listing,pending_decision}.php`,
`public/ui/assets/app.css`. Tests: `tests/Integration/Mapping/DuplicateMergeTest.php`, `tests/Integration/UiKernel/DuplicatesScreenTest.php`
(new), new cases in `OpeningRebaseTest`, `UiSecurityTest` (slot `ui`), `UiUnitTest`; `MenusTest`, `PermissionsTest`, `UiTemplatesTest`,
`ReviewEvidenceTest` follow the menu and the dashboard. Runbook: `docs/ops.md`, "Duplicates".

**M31. Who merges (the owner's decision; amends M6, M7, M10, M21).**
- A merge (`merge_skus`) applies at once, one person, when the decider is a **mapping lead**, both items are `legacy` and not
  counted, and no listing that would move is linked with units per item other than 1.
- Otherwise it is stored `pending_second` with its reasons, and a different mapping lead approves it as before (M6):
  - `merge`: the decider is a mapper (a one-person merge is a lead's);
  - `counted_item`: either item is counted: `sku.counted_at`, a count time on one of its balances, or a `count` movement (the
    reading of KeyEligibility and the opening tools); **since M39** also when an item merged into it was counted, or a recount
    (`merge_recount`, `remap_correction`) is open on one of them;
  - `units_per_item`: a listing of the merged item is linked with u <> 1 (M21: a verified multiple is not moved by one person);
    **since M39** a listing of either item.
- **Protected items stay refused** (409 `protected_merge`, M10). The owner's words were "two people once either item is
  counted/protected ... as today", and today a protected item cannot be merged at all: its counted figure and its policy would
  need the recount flow, which is not built. If the owner wants protected merges with two people, that is a follow-up (the stock
  rule of M32 with a recount, and the site's managed stock). **Owner question (open, 7 Oct 2026):** this reading has not been
  confirmed by the owner; until it is, a protected item cannot be merged or split at all (the screen says "protected: cannot be
  merged"). Nothing on staging is protected today.
- A mapper may still ask for a merge through the API (it waits for a mapping lead, `merge`); the Duplicates screen's forms are a
  mapping lead's only (M34).
- The M22 reject rules stay: a merge that would contradict a match_reject is refused (409 `rejected_pair`), at the decision and at
  the approval.
- A one-person merge re-checks, under the balance locks it takes for the stock (M32), that neither item was counted in between
  (409 `counted_meanwhile`, nothing written): the item rows are read FOR SHARE, but a count locks only balances.
- The two-person reasons are listed in `match_decision.needs_second` (comment updated by 0015); the screens show them as before.
  On staging (6 Oct 2026, read-only): all 309 items of the 145 groups are `legacy`, none counted, every listing u = 1, so every
  merge there is a one-person merge. The owner is the only active mapping lead, so a counted case would wait for a second one.

**M32. What a merge does to stock (amends M10: "the merged item's buckets stay where they are").**
- The merged item's **available** stock per warehouse (`on_hand - allocated - held`, any sign) moves to the kept item through
  `CW\Stock`, the only writer: ONE `lock()` of every (warehouse, item) pair, then per warehouse a `merge_out` on_hand row on the
  merged item and a `merge_in` row on the kept one, both under `doc_ref = merge:<merge decision id>`, `idem_key =
  merge:<decision>`, actor the decider, `effective_at` the decision's time, then one `flush()`. So the kept item's availability is
  the sum of both, and the merged item ends at available 0 (on_hand 0 when nothing was in flight).
- **Units in flight keep their sale-time item** (`reservation_unit.sku_id`, I14; the invariants 1 and 4 hold the buckets to those
  snapshots). What the merged item's own held and allocated units still need stays on it, and their ship, cancel, release and
  return move its buckets as before. A cancelled or released unit therefore leaves its stock on the merged item (shown on the
  item's page; the next count settles it). Moving `allocated`/`held` too would break the invariants, and re-pointing the snapshots
  is what the brief forbids.
- The rows carry no cost (they are not cost-bearing movements, I1). Both are numbered in their items' value sequences by `flush()`
  (I3): invariants 7-9 hold. The value ledger (IM8, not built) must value `merge_in` at the merged item's moving average, paired
  with its `merge_out` by `doc_ref`; nothing books value today.
- The feed: `flush()` writes one stock row per item whose sellable availability changed (both), then one `link` row per moved
  listing, so the site re-reads both pages (both show the kept item's figure).
- Two people applying a merge that touches a counted item: the stock moves the same way, and a `count_review` (source
  `merge_recount`, dedupe `merge:<decision>:<item>:<warehouse>`) is opened on the kept item: a counted figure plus an estimate is
  settled by counting again.
- Audited in `mapping.merge_skus` (`stock`: from, to, moved per warehouse; `settled_proposals`, M34). In a group decision the stock
  of all merges is booked at the end and audited in `mapping.duplicates` (M34).
- The reorder demand needs nothing: `DemandBuilder` maps sales to items at read time through `channel_listing.sku_id` and leaves
  merged items out, so the next `bin/reorder_demand.php` gives the kept item both listings' sales (test). The reorder list's site
  stock (I78) likewise sums both listings.
- Lock order (M4, unchanged): listings (X) -> items (S; X for the merged item) -> balances -> value clocks -> feed clock. A
  `setPolicy` on the merged item (balances, then the item X) can deadlock with a merge; `Db::transaction` retries it, as M4 says.

**M33. The undo of a wrong merge: `split` (new action; 0015).** (Amended by M40: a split back to the former item undoes the whole
merge, every listing it moved; through two merges it is refused; to a new item moves stock only when the merge moved that listing
alone. The "once per merge" rule below is kept for a split to a new item.)
- **What it does.** A split moves ONE listing that a merge moved onto its item (the decision that opened the listing's current
  link period is an applied `merge_skus`) to the item it had before that merge (`split_to = former`; the item lives again:
  `merged_into_sku_id` back to NULL) or to a new item minted from the listing (`split_to = new`, card as for new_item). Same u,
  same status, one closed and one opened history period, `map_version + 1`, a feed row, audited `mapping.split`.
- **"Not this item".** The split records `match_reject(listing, the item it leaves)`: a later merge of the two is refused (M22)
  and no run suggests it again (M34). A wrong merge undone is not redone by accident.
- **The stock that came with it.** Per warehouse, what the merge moved onto the kept item (its `merge_in` rows under
  `merge:<merge decision>`) **less what this listing sold from the kept item since the merge** (its units on the kept item created
  since the merge, held, allocated or shipped: their stock left, or will leave, the kept item), moved back with `split_out` /
  `split_in` rows under the same doc_ref. Worked example (test): F 8 merged into K 30; B sells 3 from K (2 shipped, 1 to ship), A
  sells 1; the split moves 5 back: K ends at 30 - 1 = 29 once B's last unit ships, F at 8 - 3 = 5. Returned units are back on K's
  shelf and go back with the rest; cancelled units likewise.
- **Once per merge.** When a merge moved several listings (another site's listing of the merged item, say), the first split takes
  the merge's stock back and later splits of the same merge move none (they still relink and reject).
- **Who.** A mapping lead (403 `lead_required` for anyone else, through `DecisionService` and the route). Two people when either
  item is counted (`counted_item`; the approval opens `merge_recount` reviews on both items), when the listing is linked with u
  <> 1 (`units_per_item`), or when the listing rejected its former item before (`previously_rejected`). Protected items: 409
  `protected_split`. A listing not moved by a merge (or relinked since): 409 `not_merged`. A former item merged into yet another
  item since: 409 `former_merged_elsewhere` (split to a new item instead).
- **Why this is the closest safe undo, not an exact one.** The units sold since the merge keep their sale-time item (I14), the
  kept item's on_hand is one figure for both pages, and which physical unit of it "came with" the listing is unknowable. The rule
  above is exact when everything the listing sold since the merge was its own product (the definition of a wrong merge) and the
  merged item had one listing; with several listings of the merged item, the stock goes back with the first split. Both items
  are uncounted estimates; the next count settles any difference. The decision row (append-only) names the item it goes to
  (`sku_id`, NULL while a split to a new item waits), the item it leaves (`prev_sku_id`) and, in `detail`, the merge it undoes
  (`undoes_decision_id`) and `split_to`; `ck_match_decision_split` requires the detail.
- `ProposalBasis` counts a split among the decisions that move a listing's `map_version` (M27's proof).

**M34. The Duplicates screen (amends U12, U19; M8 and M19 for merge suggestions).** (Amended by M41 and M44: the rules' reasons
lead the page and a merge against them needs "I checked the live pages"; every suggestion the group's answers settle is settled
whatever the order; a page with another group's suggestion is decided there; decided groups are found from any of their listings.)
- **Where.** Linking -> Duplicates (`/ui/review/duplicates`, `linking.view`), with a badge: the number of open groups. Deciding is
  `mapping.approve` (mapping lead): `POST /ui/review/duplicates/{id}/decide` and `.../split` are `Route::LEAD`; DecisionService
  checks again. Viewer, mapper, warehouse, manager, auditor and admin see the pages and no form (admin never decides, I12).
- **A group** is the merge suggestions of one run with the same `evidence.group` (mint_vpg writes one per non-keeper listing,
  each proposing the run keeper's item); its id is its lowest proposal id. Its listings: the suggestions' listings and the
  listings the evidence names (`keeper`, `members`, by variant id on the suggestion's site); a suggestion of a later duplicate lane
  that names none brings the listings of the item it proposes. Duplicate lanes: `vpg_duplicate`, and any later `duplicate` or
  `<source>_duplicate` lane (`DecisionService::isDuplicateLane`). A group is open while one of its suggestions is open; the list
  shows the open ones, biggest sellers first (units in 365 days over the group, then 30 days), with "the titles differ in" tags,
  "partly decided" and "waiting for a second person", and below them the groups decided most recently.
- **The group page**, one card per listing, side by side (one column at phone width): product and variant titles, brand, every
  attribute, barcodes, price, units in 30 and 365 days from `sales_history_day` (to the last day loaded for the site; the listing
  profile when the site has no history), the site's latest stock and mode (`listing_stock_latest`), the live page
  (`https://www.vapeandgo.co.uk/product/<perma_link>`; an absolute http(s) perma_link as it is; other sites no link), the CW
  item (code, CWP id, counted or not, policy; "a merge needs two mapping leads" / "protected: cannot be merged"), and the other
  listings of that item (they move with it). Then a table of the identity fields from `listing_profile.features` (strength, nicotine
  type, ml, puffs, pack, ohm, colour, form, flavour words, model numbers, range words): a row is highlighted when two pages state
  different values, a word in bold when not every page has it, and marked "not on every page" when some do not state it.
- **The suggested keeper**: the most units in 365 days, then usable barcodes, then the older page (lower site variant id); after a
  merge, among the listings not moved by one. "Keep this one instead" (`?keeper=`) changes it; the buttons name its item.
- **The decision, one POST** (FormOnce key, CSRF, the map_version of every listing shown, the keeper's item): "Same product -
  merge into CW-x" and "Different products - keep separate" for all; in a group of three or more, also one choice per page (same /
  different / not sure yet) and "Save these choices". Worked out inside the form's one effect, so a double click replays the
  first answer. `DecisionService::decideGroup` runs it in ONE transaction: every listing of the group (and of the items folded
  away) locked first in id order, each listing's version checked, the merges (one per item: a listing of an item another listing
  merges moves with it) and rejects run as decide() would, the stock of all merges booked at the end with one `lock()` and one
  `flush()` (I7), then the feed rows, audited `mapping.duplicates`. Any refusal leaves the whole group as it was, and the page is
  drawn again as it is now with the reason in plain words (a stale form: "One of these listings changed since the page was drawn
  ... Nothing was saved"). Then the next open group (in list order), this group again while something in it is open, else the
  list ("No duplicates are left to decide").
- **Keeper changed.** When the person keeps another page than the run's keeper, the run keeper's item folds into the chosen one
  (anchor: the run keeper's listing, which has no suggestion of its own). The suggestions the merges fulfilled (the listing and the
  item it proposes are one item now, through `merged_into`) are settled: for the listings a merge moved, in the merge itself; for
  the group's listings, at the end of the group decision (both under the listings' locks; audited `settled_proposals`). This
  amends M19 ("a decision settles only the proposal it named") for merge suggestions only: nothing is left to decide for them.
- **"Different products"** is a reject (M8): `match_reject(listing, the kept item)`, or, when the keeper's own suggestion proposes
  that listing's item, a reject by the keeper's listing naming that suggestion. A reject now **settles the merge suggestion it
  names when it rejects the item that suggestion proposes** (or the item it was merged into); any other proposal stays open for
  another choice, as M8 says. A suggestion about another pair stays open (the group stays "partly decided").
- **Never suggested again.** `Proposals::add` refuses to (re)record a duplicate-lane suggestion that a person answered:
  `rejected_before` (a reject of the item's family by the listing, or one the merge would contradict, M22) or `same_item` (one
  item already); nothing is written, proposal id 0. `bin/mint_vpg.php` counts them (`answered=N`) and says so per group.
- **The note on merged items**, on the group page and the kept item's page: "Both pages now share one warehouse item. On the
  website they stay separate pages with their own price and reviews until Vape and Go switches to the warehouse system." The
  merged item's page says its listings and stock moved, less what its own orders in flight still needed.
- **The undo** is on the group page, for each listing a merge moved: "Split it off", back to its former item (or to a new item),
  `POST .../split` (FormOnce, CSRF, its map_version), M33.
- A split or merge waiting for a second person shows in Second approval (`pending_decision` names a split's two items).
- The dashboard's note links to the screen instead of saying "merges have no screen"; `Queries::openDuplicateSuggestions` is gone
  (`Duplicates::openCount`).

**M35. The opening rebase after merges (amends D40b; plan §8.1).** (Amended by M42: a merged item with units in flight is
`no change` or `merged_item`, never `no_mapped_listing`.) Merges happen between Vape and Go's estimate (booked 2 Oct) and
its T0 rebase, and D40a says nobody books stock on a site's items in between: the merge's rows are the exception, so the rebase
must understand them. `OpeningRebase::plan` now:
- counts the rows of `DecisionService::MERGE_MOVEMENTS` at the channel's warehouse towards the item's earlier rows (the kept item's
  estimate now holds the merged item's; its target sums both listings' T0 figures, so its delta is right), and never treats them
  as `moved`; such rows at another warehouse (or without an estimate) make the items `other_opening`;
- joins the items a merge or split connected (the items of one `merge:<id>` doc_ref; union-find over all of them) and judges them
  together: when one of them is `counted`, `moved` or `other_opening`, all of them are skipped for that reason (the kept item's
  figure holds the other's stock), and every item joined to one the rebase concerns is part of the plan;
- no longer lists as `no_mapped_listing` an item whose earlier rows net to 0 and that has no opening units (a merged item: nothing
  left to rebase).
Test: estimate A 10, B 4; B's item merged into A's (K 14); a goods-in on G, then H merged into G; an opening unit of page B; T0 A 6,
B 3: K's delta -4 (available 9 = both pages' T0 figures), F no change, G and H skipped `moved`.

**M36. Measurements, staging, open items.**
- Staging (read-only, 6 Oct 2026): 165 open `vpg_duplicate` suggestions in 145 groups (133 of two pages, 7 of three, 2 of four, 3 of
  five; 162 `identity_key`, 3 `shared_gtin`), run `run2-vpg-duplicates`; 165 items on the suggestion side and 144 keeper items (one
  keeper item is in two groups: the screen shows them as two groups, and after the first is merged the second still names the same
  keeper); every listing mapped, u = 1, features, a stock snapshot and sales history to 1 Oct 2026; 88 of the suggestion-side items
  carry the opening estimate (2,309 units, nothing allocated or held), so a merge of all of them would move at most 2,309 units onto
  keeper items (33,624 units on 89 of them). Nothing was merged or written on `cw_staging`.
- Tests (6 Oct 2026): the full suite in slot `dup1`: `OK, but some tests were skipped! Tests: 761, Assertions: 16007, Skipped: 75`
  (749 before, plus 8 in `DuplicateMergeTest`, 3 in `DuplicatesScreenTest` and one `OpeningRebaseTest` case; the 75 skipped are the
  HTTP screen and API tests). In slot `ui`, `UiAuthTest`, `UiReviewFlowTest`, `UiSecurityTest` (with the two Duplicates pages in its
  sign-in, role and hostile-text checks), `KeySampleScreenTest` and `DuplicatesScreenTest`: `OK (44 tests, 17465 assertions)`.
- Deploy `0015_duplicates.sql` with this code in one `deploy/staging/install_cron.sh --migrate` run, never the code first: the
  screens and DecisionService write `action = 'split'`, which the old ENUM refuses, and the CLI tools refuse a schema behind the code
  (exit 3). 0015 is the only pending file on `cw_staging` (at 0014).
- Open: protected merges and splits (M31; the recount flow); the residual stock a released or cancelled pre-merge unit leaves on a
  merged item (M32; shown on its page, settled by a count; a sweep could move it later); IM8 must value merge and split rows (M32).

## The wider duplicate sweep (slot `dup2`, 6 Oct 2026)

The owner's decision of 6 Oct 2026 (M31-M36) also asks for a WIDER sweep of Vape and Go's own mapped listings than run2's
identity-key and shared-barcode groups: pairs of different CW items whose listings are the same physical product even when one has
no barcode or the titles are worded differently (the Corex pair), fed to the Duplicates screen. High precision. Numbered M37-M38.
Code: `tools/vpg_duplicates/export.php`, `tools/vpg_duplicates/sweep.php`, `src/Matching/DuplicateSweep.php` (the rules),
`src/Matching/DuplicateSweepRun.php` (one run), `bin/import_vpg_duplicates.php` (new), `src/Ui/Duplicates.php` (`sweep()`),
`src/Ui/views/duplicate_group.php`. Tests: `tests/Unit/DuplicateSweepTest.php`, `tests/Unit/DuplicateSweepRunTest.php`,
`tests/Integration/Mapping/ImportVpgDuplicatesTest.php` (new), a case in `DuplicatesScreenTest`. Runbook: `docs/ops.md`, "The wider
duplicate sweep".

**M37. The sweep: export, rules, groups, import.** (Rules amended by M43, engine `ds1.1`; the importer by M41: a member with
another run's open suggestion is left out before the evidence is written.)
- **Export** (`tools/vpg_duplicates/export.php`): the site's mapped (and quarantined) listings with profile and stored features, their
  items' identity card, policy and "counted" (DecisionService::counted's reading), every link of those items on any site, merged
  items, every merge suggestion of a duplicate lane in any status, the rejects either way, decisions waiting for a second person and
  open proposals on these listings. SELECT only inside START TRANSACTION READ ONLY, `MAX_EXECUTION_TIME = 30000`, app login (`--admin`
  for test schemas); piped over ssh it needs no file on the staging box. Catalogue text only; the file stays in
  `/root/cw_work/vpg_dups/` (0700).
- **Features.** The sweep re-normalises every listing from the exported profile with the current engine (Normalizer n2.1, line
  lexicon built over the export as `tools/first_match/run.php` does): the stored `listing_profile.features` lack `full_tokens`, which
  the vetoes need. On `cw_staging` the result equals the stored features on every identity field; only `unit_price` differs (13,130
  listings): the profile holds the regular price, run2 used the sale price. The sweep compares the **regular** price, the one the
  Duplicates screen shows.
- **Candidates**: the matcher's blocking and prescore (`CW\Matching\Candidates`, top 25 per listing), plus a signature block (the
  same brand family, form class, strength, ml, puffs, ohm, pack and title words in any order; blocks above 60 are skipped). Recall is
  not limited by the candidates: top 60 (580,967 candidate pairs) accepted exactly the same 24 pairs and gave a byte-identical
  `groups.jsonl` as top 25 (235,968).
- **The rules** (`DuplicateSweep::judge`, engine `ds1.0`), a pair is a duplicate only when nothing speaks against it:
  every hard veto of `Veto::check`, run in BOTH directions (strength, nicotine type, form, line number, modifier, line word, flavour
  superset/difference, ml, puffs, colour, ohm, pack, multipack); the soft flags that mean "cannot compare" or "one side says more"
  (`internal_conflict`, `modifier_extra`, `flavour_extra`, `line_number_extra`, `line_number_one_side`, `line_alias_pending`,
  `relabelled_line_unconfirmed`, `colour_extra`, `volume_diff_attr`, `pack_one_side`, `listing_multiplier`, `price_outlier`); a field a
  page states twice with two values (`unreadable`: "2ml/5ml Replacement Pod - 5ml"); the brand (a shared brand-family word or the same
  brand); regular prices within 0.67x-1.5x; the form known on both and the same (pod_kit = kit only), prefilled/refillable not on one
  side only; **a value on one page only refuses** for strength, nicotine type, ml, ohm, colour, pack above 1, puffs (unless the other
  page's model number is that count), N-in-1 and VG/PG - except a VG/PG ratio or a pod's or tank's capacity that only an option row
  states (Vape and Go builds pages with different option sets); the **VG/PG ratio** (new: titles "50/50", "70VG/30PG", "Max VG";
  the "PG/VG" option row read PG first, "30/70" = VG 70); flavoured products must agree on the flavour; **every word** of each
  page's titles must be on the other page (brand, form, stop, descriptor and shop words aside; single letters other than e/a/n/s
  kept: "TPP" vs "TPP X", "Armour G" vs "GS"), model codes too ("G2" vs "P2", "F1" vs "F2"), and a part word (glass, coil, tank, kit,
  pod, cartridge, battery, charger, ...) on one page only refuses; two options of ONE product page pair only when their option texts
  hold the same words, numbers and codes, as often ("Cherry Ice / Blueberry" = "Blueberry / Cherry Ice"; "H Bubble / Strawberry H
  Bubble" is a twin, not "Strawberry H Bubble"); **both pages with usable barcodes must share one** (Vape and Go gives 50/50 and
  70/30, "Bar Salts" and "Bar Vape", "Riot Squad" and "Black Edition" their own EANs). Each accepted pair is scored 0-100 (identity
  fields that agree, name overlap, barcode, price) and explained (fields that agree, unknown on both, not applicable).
- **Kept out** (`DuplicateSweepRun`): a pair suggested before by any duplicate-lane suggestion in any status (`already_suggested`); kept
  separate, a reject either way (`rejected`, M22); a protected item (`protected`, M31); a quarantined or ignored listing of either
  item (`quarantined`); a decision waiting for a second person (`pending`); an item with a listing in an OPEN suggestion group
  (`in_open_group`); another open proposal (`open_proposal`). An open group is never extended from another run (one open proposal per
  listing, and run2's provenance): decide it, re-run the sweep, and the pair comes back against the item it then is.
- **Groups**: the accepted pairs, best score first, joined greedily into groups of at most 6 items in which EVERY two items are an
  accepted pair (`not_clique`, `group_cap` otherwise: no transitive A~B~C). Keeper: most units in 365 days, then usable barcodes,
  then the older page (the screen's rule). Deterministic: the same export gives the same `groups.jsonl` (tested).
- **Import** (`bin/import_vpg_duplicates.php`, dry run unless `--apply`): one `match_run` (source `vpg_dup_sweep`, run id
  `sweep-<engine>-<first 12 hex of the file's sha256>`, the sweep's `run_id`), one open proposal per member other than the keeper
  (lane `vpg_duplicate`, band Manual, proposing the keeper's item, flags `merge_suggestion` + `sweep`, written through
  `Proposals::add` with its basis, M27). Every member is re-checked against the database at import, under its listing's lock:
  `stale` (relinked; merges followed), `same_item`, `answered` (kept separate, M34), `protected`, `quarantined`, `pending`,
  `open_elsewhere` (an open proposal of another run is never superseded here); a keeper that is stale, protected, quarantined, pending
  or itself suggested skips its group. The evidence names only the members written (keeper, members, and `sweep`: engine, score, the
  explained pairs among them, the file's sha256), so the screen never offers a member that would refuse the whole group. Idempotent
  (`exists`), audited (`mapping.propose` per proposal, `mapping.import_duplicates` with the counts), no run row when nothing is written.
- **The screen** (M34 unchanged otherwise): a sweep group says "found by the duplicate sweep" and lists "Why the sweep suggested this"
  per pair (score, fields that agree, not stated on either page, barcode, price ratio, two options of one page). `Duplicates::sweep()`
  passes only known field names and typed values; text is escaped (tested with hostile evidence).

**M38. Measurements on `cw_staging` (export of 6 Oct 2026 22:57 UTC, read-only), open items.**
- 14,856 mapped Vape and Go listings, one item each, all legacy and uncounted, no rejects, no merges, nothing pending; 165 open
  `vpg_duplicate` suggestions (run2). Sweep: 235,968 candidate pairs judged (5 min, 1.5 GB, on the web server at nice 19), **24
  accepted**: 21 already suggested by run2, **3 new pairs, 3 new groups** (each of two pages, every one with a page without a
  barcode; one is two options of one product page): Corex 3.0 1.2 ohm 4-pack (CW-012847 / CW-010969, the owner's example at
  another resistance), Peeky Blenders "Goodfellas" / "Godfellas" (a typo page), Ploom EVO "Purple Option" / "Purple". Run id
  `sweep-ds1.0-ea706d79b79c`.
- **Hand check**: the rules accept only 24 pairs in all, so the sample is all 24 (seed 1), not 40: 22 are clearly the same product
  (the Corex pairs, 14 IVG flavour pages against the "IVG Nic Salt 10ml" page of the same range and strength, the RandM twin listed
  in both orders, the Hayati twin, the Peeky typo), 2 are probably the same and worth a look on the live pages (Ploom "Purple
  Option" vs "Purple", SKE "Berry" vs "Berry Edition"), none is clearly different: precision 22-24 of 24.
- What refuses most (every refusal of the 235,968 pairs counted): one-sided words 232,897, flavour not agreed 194,082, flavour
  difference 190,919, different barcodes 156,974, option text 73,057. Near misses inspected by hand (pairs that fail only on
  structure, not words or vetoes) were all different products: Xros Mini vs Xros 4 Mini, Gotek Pro vs Pro 2, Lost Mary BM6000 20mg vs
  "Zero Nicotine", Doozy 50/50 vs 70/30, Kingston 50/50 vs 70/30 (the "PG/VG" row), Bar Salts vs Bar Vape.
- **Run2's 165 suggestions under these rules**: 21 accepted; the other 144 have a reason against (different barcodes 93, a field
  stated twice 70, options of one page that differ 64, VG/PG 37, words 24, model codes 11, price 7, ...). They stay open for the owner
  (`summary.json` -> `earlier_suggestions`); the screen shows the differences.
- Tests (slot `dup2`, 7 Oct 2026): the full suite `OK, but some tests were skipped! Tests: 782, Assertions: 16314, Skipped: 75` (761
  after M36, plus 21: 15 `DuplicateSweepTest`, 2 `DuplicateSweepRunTest`, 3 `ImportVpgDuplicatesTest`, 1 `DuplicatesScreenTest`; the 75
  skipped are the HTTP screen and API tests of slots `ui`/`api`, not run here). New: `DuplicateSweepTest` (golden pairs: the Corex 0.4 ohm 4-pack pair =
  duplicate; 0.4 vs 0.6 ohm, 2 ml vs 10 ml, 600 vs 6000, Pro vs Pro Max, kit vs pods, 10 vs 20 mg, Blue Razz vs Blue Razz Lemonade,
  single vs 3-pack, 50/50 vs 70/30, TPP vs TPP X, F1 vs F2 = not; symmetric), `DuplicateSweepRunTest` (exclusions, cliques, keeper,
  determinism, file modes), `ImportVpgDuplicatesTest` (dry run, apply, re-run, every left-out reason, answered after a reject,
  same_item after a merge, export -> sweep -> import end to end), `DuplicatesScreenTest::testASweepGroupShowsWhyTheSweepSuggestedIt`.
- Open: the import waits for the deploy of 0015 with Task A's code (the importer and screen need M34); the 3 groups are one-person
  merges (legacy, uncounted). Pairs `in_open_group` were 0 on this export, but after the owner decides run2's groups the sweep should
  be re-run (a merged item's new pairs come back against the kept item). The IVG "(L)" suffix ("Blue Sour Raspberry (L)") is not
  understood, so those pages are refused as one-sided words; someone who knows the range can say whether they are the same product.

## Review fixes for duplicates (slot `dup5`, 7 Oct 2026)

Two reviews of the uncommitted M31-M38 build (the stock and mapping lens, slot `dup3`; the owner's lens, slot `dup4`) asked for
fixes before the deploy. All of their findings are applied, M39-M45, except where a finding left a choice (said in the entry).
Code: `src/Mapping/DecisionService.php`, `src/Ui/Duplicates.php`, `src/Ui/Controller/{Duplicates,Item,Review}Controller.php`,
`src/Ui/views/{duplicates,duplicate_group,item,listing}.php`, `public/ui/assets/app.css`, `src/Matching/DuplicateSweep.php`,
`src/Ops/OpeningRebase.php`, `bin/import_vpg_duplicates.php`, `tools/vpg_duplicates/export.php`. Tests: new cases in
`DuplicateMergeTest`, `DuplicatesScreenTest`, `ImportVpgDuplicatesTest`, `OpeningRebaseTest`, `DuplicateSweepTest`.

**M39. A merge's two-person test covers the merge family (amends M31, M6).**
- `DecisionService::counted()` (and so the merge, the split, the screen's "a merge needs two mapping leads" and the export): an
  item counts as counted when it OR any item merged into it (merged_into chains, the `fam` CTE of the reject checks) was counted
  (`sku.counted_at`, a balance count time, a `count` movement), or when a `merge_recount` or `remap_correction` count_review is open
  on one of them (a counted figure waiting for its recount). The review's case: A counted, merged into B by two people; then B into
  C was one person's and moved A's counted units again. Now it waits for a second mapping lead (test).
- A merge that two people apply carries every open `merge_recount` / `remap_correction` review of the merged item to the kept item
  (opened again there, dedupe `merge:<decision>:<kept item>:<warehouse>`, detail `carried_review_id`): the recount follows the stock
  instead of staying on an item with no listings and no stock. The merged item's review stays as it is (the count gate settles it).
- `units_per_item`: a mapped or quarantined listing of EITHER item linked with u <> 1 (was: of the merged item only). A verified
  pack listing on the kept item is the strongest sign that a pack item is being folded into a single one (or the reverse), and the
  pack page would sell the other item's units afterwards.

**M40. A split back to the former item undoes the merge whole (amends M33).**
- `split_to = former` is the merge's inverse: EVERY listing the merge moved that is still where it put it (its current link period
  was opened by the merge) goes back to the merged item, each with its own closed and opened period, `map_version + 1` and feed
  row (audited `with_listings`); the item is revived; the stock that came with the merge goes back: its `merge_in` rows on the kept
  item less what ALL the listings it moved sold from the kept item since (held, allocated, shipped). The reject is recorded for the
  listing named (`match_reject(listing, kept item)`), which M22 reads for the whole item, so the merge is never redone by accident.
  This is exact where M33 was not: when the merged item had pages on two sites (before, the first split took all the stock and the
  other page stayed on the kept item), and when the merged item holds items merged into it before (their pages and stock came in
  the merge and go back with it).
- Refused, 409 `split_chain`, when the listing named came onto the merged item through an EARLIER merge (its period before this
  merge was opened by a merge: X into K, then K into Z, then "split X's page"): which of the two merges was wrong cannot be told,
  and sending the page to K (the review's finding) would take K's stock with it. The page then goes to a new item.
- `split_to = new` takes the listing named alone. The merge's stock moves with it only when the merge moved that listing alone and
  not through a chain (exact); otherwise no stock moves (audit `stock.not_exact`; its share cannot be told apart; the next count of
  the legacy estimate settles it).
- Two people: either item counted (M39), a listing going back linked with u <> 1, or a listing going back that rejected the former
  item before; a pending decision on a listing going back refuses the split (409 `pending_second_exists`).
- The screen shows, before "Split it off", where the page goes, with which other pages, and how many units move per warehouse
  (`DecisionService::splitPreview`).
- Tests: B and another site's page E of F merged into K, split of B: both back to F with 5 units (8 came, B sold 3 since); X into K
  into Z: X's page refused (`split_chain`), K's page back to K with X's page and the 14 units; a two-page merge split to a new item
  moves nothing.

**M41. A group decision settles what its answers settle, and never decides another group's suggestion (amends M34, M37).**
- `decideGroup()` settles at its end, whatever the order of its requests, every open merge suggestion of the group's listings for
  which `duplicateBlocked(listing, proposed item)` is not null (`same_item` or `rejected_before`), restricted to the group's own
  suggestions (the screen passes them). Before, a reject booked before the merge that made it apply left that suggestion open: with
  a changed keeper and "different products" for one page, the group stayed in the list for ever (the review's P2).
- The group page: when the group still has an open suggestion but nothing is decidable against the suggested keeper (and nothing
  waits for a second person), the keeper is the item an open suggestion proposes (the first one, by id, with something decidable
  against it); "Keep this one instead" still changes it.
- A page whose open suggestion belongs to another group (or another queue) is shown "decide it there first" and is not part of the
  decision; the form names only this group's suggestions; a form that names another one, or that offered a page which has another
  open suggestion since, is refused (409, nothing saved).
- `bin/import_vpg_duplicates.php` checks `open_elsewhere` (an open proposal of another run) with the other member checks, BEFORE the
  group's evidence is built: the evidence never names such a page, so a merge on the screen cannot settle that other suggestion
  unseen (the review's P5). A re-run finds its own proposals (`exists`), never `open_elsewhere`.

**M42. The rebase and a merged item with units in flight (amends M35).** A merged item (no mapped listing on the channel) whose
earlier rows equal its opening units in flight has nothing to rebase (`no change`); one that holds anything else (what an order
outside the opening still needs, a residual) is skipped with the new reason `merged_item`: nothing to relink, the kept item's figure
is rebased on both pages, a count settles the rest. It is never `no_mapped_listing`, whose runbook advice (relink it) would undo the
merge. Tests: an opening unit of page B on F, F merged into K: F `no change`; with a later order of page B too: F `merged_item`.

**M43. The sweep's rules, engine `ds1.1` (amends M37).**
- Recall (the review found 9+ real duplicates refused by one soft flag): Veto's `flavour_extra` does not refuse two pages of
  hardware (neither is a flavoured product) whose extra words are only descriptors, generic, umbrella or brand words ("Vaporesso Xros
  Corex 2.0 Mesh Replacement Pod" vs "Vaporesso Xros Corex 2.0 Replacement Pods": the Normalizer read "xros corex" as a flavour on
  one page and compared the other page's "mesh"); `modifier_extra` does not refuse when every one-sided line word is an umbrella or
  generic word AND both pages state the same model numbers ("... by IVG 6000 Bar Salts" vs "IVG Nic Salt ... (IVG 6000)"). Every
  title word is still checked by the words rule.
- Precision (the review: brand and noise words left "barcodes differ" as the only guard): a same-site brand rule, `brand_text`:
  two pages with different brand texts are one brand only with a shared real brand word (not generic, not umbrella) AND every line
  word of each page on the other ("Bar Salts" vs "Bar Vape Salts" is refused; "IVG Nic Salts" + "IVG Intense" = "IVG" + "(Intense)");
  `edition`, `edtn`, `limited` and `special` are words (they were noise or descriptors): "Riot Squad" vs "Riot Squad Black Edition"
  is refused without its barcode.
- VG/PG: a ratio the title states wins over the "PG/VG" option row (Vape and Go writes it PG-first on some pages and VG-first on
  others); the option row counts only when the title states none; "unreadable" only for two different ratios in the title (or, with
  none there, in the option rows). The IVG 70/30 100 ml twins are no longer "unreadable"; they are refused by their price (6.99 vs
  10.99).
- Golden cases: Corex 2.0 Mesh = Corex 2.0 (and not at another ohm), IVG 6000 Bar Salts = "(IVG 6000)" (and not without the number),
  Riot Squad vs Black Edition and Bar Salts vs Bar Vape without barcodes = not, SKE Berry vs Berry Edition = not, a title ratio over
  the option row.

**M44. The Duplicates screen leads with what the rules say (amends M34; the owner-lens review's blocker).**
- The review drew 60 of the 168 pairs the owner would be asked about: 49 different products. The list said "nothing found" for 129
  of 145 groups, the group page highlighted no row for 70/30 vs 50/50 or F1 vs M1 coils, and one tap merged.
- Now every page is judged against the kept page with the sweep's rules, live (`DuplicateSweep::judge` on features re-normalised from
  the listing profile; without the site's line lexicon, which needs a 2 s scan of every profile: on the 198 pairs of 6 Oct the
  verdicts are the same, one refused pair lists one reason more), for run2's suggestions as for the sweep's.
- The list's column is "What the rules say": "may be different:" and the reasons' tags (VG/PG, barcodes, option, words, strength,
  ...), or "no reason against found: check the live pages", or "not checked (no listing profile)".
- The group page opens with "What the rules say": per page, every reason in plain words with the rules' detail ("Different VG/PG
  ratios: 50 vs 70", "Different barcodes (Vape and Go gives each product its own)", "Two options of one product page with different
  option text: ..."). With a reason against, "Different products - keep separate" is the main button, and a merge needs "I checked
  the live pages: the pages I mark as the same product are the same product" ticked; the POST judges again and refuses without it
  (422, nothing saved). The form says how many units a merge would move onto the kept item. The heading reads "suggested because the
  names match ... Check the differences below" (it said "found by the same name, size and strength").
- The comparison table: first the option text of each page (what the variant title adds to the product title), then VG/PG and the
  barcode (the same / its own / has one); a field a page states twice reads "unclear: stated twice"; the flavour row leaves out every
  brand and range word of the group's pages (the owner's Corex 3.0 pair no longer shows "corex" as a differing flavour); the column
  heads carry a short title; more than six attributes fold under "All N options and attributes" (phone width).
- The undo can be found again: "Decided recently" lists every decided group, found through the decisions on ANY of its listings (a
  group decided with the screen's keeper books its merge on the run keeper's listing, which has no suggestion), newest first, 25 a
  page; the item page and the listing page link to their duplicate groups.
- Run2's suggestions are not withdrawn or settled in bulk (the review's option (a)): that is the owner's call; with the reasons shown
  first, most of them are one "keep separate" each.

**M45. Measurements (7 Oct 2026).**
- Sweep `ds1.1` on the export of 6 Oct 2026 22:57 UTC (the same file as M38; nothing was merged on `cw_staging` since): 235,968
  candidate pairs, **33 accepted** (24 under ds1.0): 21 suggested by run2, 1 kept out `in_open_group` (Corex 2.0 0.4 ohm: one of its
  items is in an open run2 group), **11 new groups** of two pages (3 under ds1.0): Corex 2.0 0.6, 0.8 and 1.2 ohm, Corex 3.0 1.2 ohm,
  Peeky "Goodfellas"/"Godfellas", Ploom "Purple Option"/"Purple", and five IVG 6000 flavours (Berrylicious Blast, Pink Pop, Bubblegum
  Berry Wave, Arctic Apple, Blue Frost). SKE "Berry" vs "Berry Edition" is no longer accepted. Run id `sweep-ds1.1-0bcd02ccd123`,
  files in `/root/cw_work/vpg_dups/sweep_ds11_20261006T225658Z/` (0700). 5 min 55 s, 425 MB peak, nice 19.
- Hand check of every accepted pair (33, `sample.txt`, seed 20261007): 32 clearly the same product, 1 probable (Ploom "Purple
  Option" vs "Purple"), none different.
- Recall: the review's 2,217 close misses re-judged under ds1.1: 10 now accepted, 432 refused by exactly one rule (words 368, ohm
  30, model number 10, VG/PG 5, brand 5, option 4, line number on one side 3, barcodes 2, strength 2, ml 2, model code 1). A seeded
  random 30 of the 432 (seed 20261007, `sole_sample.txt`): none is the same product (other flavours of pouches and liquids, other
  resistances). The review's three misses (S21, S22, S25) are in the new groups.
- Run2's 165 suggestions under ds1.1: 21 accepted, 144 refused (different barcodes 93, a field stated twice 64, option text 64, VG/PG
  42, words 27, model codes 11, price 7, brand text 6, ...): the screen now shows these reasons first.
- Tests (7 Oct 2026): the full suite in slot `dup5`: `OK, but some tests were skipped! Tests: 796, Assertions: 16981, Skipped: 75`
  (782 after M38, plus 14: 4 `DuplicateMergeTest`, 4 `DuplicatesScreenTest`, 1 `ImportVpgDuplicatesTest`, 2 `OpeningRebaseTest`, 3
  `DuplicateSweepTest`; the 75 skipped are the HTTP screen and API tests of slots `ui`/`api`). In slot `ui`, `UiAuthTest`,
  `UiReviewFlowTest`, `UiSecurityTest`, `KeySampleScreenTest`, `DuplicatesScreenTest`: `OK (49 tests, 17767 assertions)`. The hammer
  (`--seed=20261006`, slot `dup5`): `RESULT: PASS (59 checks passed, 0 failed)`. The matching golden tests (`php tests/matching/run.php`):
  `{"passed":59,"failed":0}`.
- Staging (read-only, 7 Oct 2026): still no merge, reject or split decision, no merged item, 165 open `vpg_duplicate` suggestions, no
  `vpg_dup_sweep` run: the export of 6 Oct holds. Import the ds1.1 file after the deploy (`docs/ops.md`, "The wider duplicate sweep").
- Open: the owner question of M31 (protected merges); the reorder demand and the rebase need nothing more for M40's multi-page undo
  (both read links at read time).

## Inventory Phase I-3 (slot `im3a`, IM3 item card: legal and buying fields, barcodes)

IM3 of `docs/inventory-modules-plan.md` §3 (and the I-3 row of §7; the owner's decisions of §10, 2 Oct 2026; plan §2.1 and §7;
the memory note "flavour is not a field": propose, then a person confirms). Numbered I100–I112; the two code reviews' fixes are
I113–I124 (I100–I112 are amended to match them). Nothing books stock. Code:
`migrations/0016_item_cards.sql`, `src/Catalogue/` (`ItemRules`, `ItemCards`, `CardProposals`, `ItemCompliance`, `ItemBarcodes`,
`BarcodeReviews`, `BarcodeSync`, `ItemCardList`, `ItemCardCsv`, `ImportRolledBack`, `CatalogueInvariants`),
`src/Ui/Controller/{ItemCards,Barcodes}Controller.php` (new), `src/Ui/views/{item_card_form,item_cards,item_cards_import,
barcode_reviews}.php` (new), `bin/{sync_barcodes,import_item_cards}.php` (new); changed: `src/Ui/Controller/ItemController.php`,
`src/Ui/views/item.php`, `src/Ui/{Kernel,Context}.php`, `src/Auth/Permissions.php`, `src/Schema/Grants.php`, `src/Invariants.php`,
`src/Matching/Gtin.php` (`checkDigit`), `src/Mapping/BarcodeSeeder.php`, `src/Reorder/{ReorderList,DraftPos}.php`,
`src/Ui/Controller/ReorderController.php`, `src/Ui/views/reorder.php`, `src/PurchaseOrders/{PurchaseOrders,PurchaseOrderHandler}.php`,
`public/ui/assets/app.css`; tests `tests/Unit/{ItemRules,Gtin,ItemCardCheck}Test.php`, `tests/Integration/Catalogue/`,
`tests/Integration/Migration0016Test.php`, `tests/Integration/Reorder/ReorderItemCardTest.php`,
`tests/Integration/UiKernel/ItemCardScreensTest.php`, and updates of `PermissionsTest`, `MenusTest`, `UiTemplatesTest`, `GrantsTest`.

**I100. Design in one paragraph.** The legal card is a table of its own, `item_card`, one row per item, made on the first change:
not more columns on `sku`. `sku`'s identity card (strength, form, flavour, volume ...; plan §2.1, M9) is the MATCHER's, filled when
an item is minted and used to link listings; the legal card is a PERSON's, every value typed, accepted or imported by someone with
`catalogue.edit`, and its confirmation is what turns a warning into a block. Keeping them apart keeps the provenance plain (the
matcher's values become *suggestions* for the legal card, I104) and leaves `sku` (NO_DELETE, the stock core's FK target) alone.
`sku.brand` stays the catalogue brand used for linking and the reorder brand settings; `item_card.brand` is the brand on the box.

**I101. The tables and who may write them (0016).**
- `item_card`: product type (ENUM `e_liquid`, `shortfill`, `nic_shot`, `prefilled_pod`, `device_kit`, `single_use`, `coil`, `tank`,
  `accessory`), `liquid_ml` DECIMAL(6,1) (0 < ml ≤ 5000; a bottle's contents or what a tank, pod or device holds), `nicotine_mg`
  DECIMAL(5,2) (0–100 mg/ml), `duty_liable` and `single_use` (1 / 0 / NULL = not said yet), `ecid` (letters, digits, single hyphens,
  upper case), manufacturer, brand, flavour + `flavour_status` (proposed / confirmed; NULL iff no flavour), `discontinued` (0/1),
  `version` (moves with every write), `confirmed_by/actor/at` + `confirmed_breaches` (the rules the LAST confirmation
  acknowledged, kept until the next one, I113), `first_confirmed_at` (never cleared), `updated_*`. CHECKs hold the pairs together,
  refuse a `single_use` product type without `single_use = 1` (`<=>`: a NULL must not pass) and 0 ml on anything but a device / kit
  (I115). The app login: SELECT, INSERT, UPDATE (`Grants::NO_DELETE`).
- `item_card_change`: one row per write (kind change / accept / import / confirm; UNIQUE (sku, version)), with what changed
  (`changes`: field => before / after; NULL for a confirm, CHECK) and the whole card after it (`card`, `ItemCards::SNAPSHOT`, the
  held `confirmed_breaches` included), and `detail` (the proposal's sources, the import run and row, the rules acknowledged, the
  rules a confirmation lifted). APPEND_ONLY.
- Every write is also an `audit_log` row: `item_card.change` {kind, version, changes, unconfirmed, detail}, `item_card.confirm`
  {version, breaches, first, card}. `ItemCards` is the only writer (`grep` finds no other `item_card` writer in `src/` or `bin/`).
- Optimistic concurrency: every write names the version the form or file row was drawn with (0 = no card); another is 409
  `card_changed` with the current version; nothing changed writes nothing (`unchanged`). The item row is read FOR SHARE (a merge
  cannot slip in: 409 `merged_item`; a merged item's card is never changed) and the card FOR UPDATE.
- Who: a staff caller (403 `staff_required`), active (403 `staff_not_allowed`), never admin (403 `admin_cannot_edit`, I12, also
  for an admin set only admin SQL can write), holding `catalogue.edit` (403 `role_not_allowed`), roles re-read in the transaction.

**I102. The rules (`ItemRules`, pure).** TRPR 2016 reg 36 and the single-use ban (from 1 June 2025):
`trpr_refill_ml` an e-liquid, shortfill or nic shot with nicotine > 0 and over 10 ml; `trpr_tank_ml` a tank, prefilled pod, device /
kit or single-use vape holding over 2 ml; `trpr_nicotine` over 20 mg/ml, any type; `single_use` a person answered single-use (or the
single-use product type, which needs that answer). "Over" is strictly over: 10.0 ml, 2.0 ml and 20 mg/ml pass. A rule needs its
values: an unknown ml or strength breaks nothing, and the type-bound rules need the type, so data typed half-way never blocks by
accident. Values are compared as integers (tenths of a ml, hundredths of a mg), never floats. `sqlBreach()` is the same rule in SQL
for the list's "breaks a rule" filter and the summary; `ItemCardsTest::testTheSqlRuleMatchesThePhpRule` checks the two against each
other on 17 cards. `missing()`: a confirmation needs the product type and the duty answer always; the ml and the strength for liquids,
prefilled pods and single-use vapes; the capacity of a tank; the capacity of its tank or pod (0 = none, I115) and the single-use
answer for a device / kit. `advice()` never blocks: duty
"no" on a product that holds liquid (VPD covers nicotine-free liquid from 1 Oct 2026), duty "yes" on a coil, tank or accessory, an
ECID not shaped 12345-16-12345.

**I103. Warnings until a person confirms, blocks after (the IM3 rule, recorded as built; amended by I113, I119, I121).**
- **Warning** while no confirmation stands behind a rule the values break: shown on the item page (amber), the item cards list,
  the reorder list (flag `card_warning` with the rule, still suggested), the PO editor's warnings. Nothing is refused:
  half-entered or wrong catalogue data must not stop buying. This is the IM3 rule ("WARNINGS until a person confirms the item's
  fields, HARD BLOCKS after that"), in the spirit of the owner's decision 6 for the count screen ("warns first").
- **Confirming** says "these fields are right". It needs `missing()` empty (422 `card_incomplete`, detail.missing) and, when the card
  breaks a rule, the person's explicit acknowledgement (the box "I checked the packaging: these fields are right, and the item breaks
  the rules above, so it will be blocked"; without it 422 `card_breaches`, detail.rules). Refusing the confirmation outright was
  rejected: nothing could ever be blocked if a breaking card could not be confirmed.
- **Block**: the rules a person confirmed the card breaks, from that confirmation until the next one (I113). A later change of a
  legal field clears `confirmed_by/actor/at` ("changed since it was confirmed": confirm it again) but keeps `confirmed_breaches`,
  so no edit lifts a block by itself (correcting, emptying, retyping); a new confirmation of the corrected card does (its result
  and history say `lifted`). A rule an edit breaks on a card confirmed without it is a warning until someone confirms the card
  with the acknowledgement: no block without one. `discontinued` is a buying flag and does not unconfirm.
- **What a block stops** (`ItemCompliance`), TODAY: never suggested on the reorder list (k = 0, `never` "blocked by its item card:
  ...", flag `card_blocked` with the rule); skipped by "create draft PO" (with the reason); a purchase order with the item is
  refused at approval (`PurchaseOrderHandler::validate`: 422 `item_blocked`, detail.items CW code => rules, the cards read FOR
  SHARE, I122; drafts may hold it, the editor's warnings say so). NOT YET: receiving (IM6, I-3, will call
  `ItemCompliance::receiving()` / `assertAllowed($skus, 'receive', true)`) and the site stock writer (IM10, I-6, will call
  `assertAllowed($skus, 'sell')` and set the item's listings Out-Of-Stock). The screens say exactly this (I121). Not done here: a
  PO approved or sent before the block is not changed (receiving will refuse it).
- **Duty for receiving (decision 8, "refuse all unstamped deliveries from 1 Jan 2027"):** `receiving()` says `stamp_required` for a
  duty-liable item and for one whose card does not answer (no card, or `duty_liable` NULL: `duty_unknown`, fail closed), except a
  card that names a product holding no liquid (coil, tank, accessory: `stamp_required` false, `duty_unknown` still true, I119).
  I-Day is after 1 Apr 2027, so IM6 refuses or quarantines every unstamped duty-liable delivery; the "made before 1 Oct" logic
  stays in the pre-go-live register (§6.2).

**I104. Suggestions, never values (`CardProposals`; the memory note "flavour is not a field").** Computed when the item page is
drawn and again when one is accepted (409 `proposal_gone` when it is no longer offered: the card or the listings changed), from:
the item's identity card (`sku.strength_mg`, `volume_ml`, `form`, `flavour`, `brand`: the matcher's, M9); every listing LINKED to it
(`mapped`): its stored features (`listing_profile.features`, M12), or the Normalizer on its titles when it has none, and its brand;
and the card's own product type for the duty answer (liquids, pods, single-use: yes; coil, tank, accessory: no; a device / kit:
nothing). Mapped: strength → nicotine; volume → ml (to 0.1); form → type (e_liquid and nic_salt → e-liquid; pod_kit, kit, battery →
device / kit; a refill pod only when prefilled; **disposable → nothing**); flavour tokens → flavour in title case. **single-use,
ECID and manufacturer are never proposed.** A source that calls the item a "disposable" is listed ("whether it is SINGLE-USE is for a
person to answer from the box"): since June 2025 many "disposable-style" devices are rechargeable and refillable. Each suggestion
shows every source that says it, the most-said first, and "the sources disagree" when they do; a value already on the card is not
offered. Accepting writes kind `accept` with the sources in the history. Nothing is ever written by computing suggestions (tested).
The flavour: a NEW value typed on the form, or one accepted on the page, is `confirmed`; from a CSV file it is `proposed` (the page
offers "Confirm this flavour", the form marks it "proposed by a file, not confirmed"), and a form that sends it back unchanged
keeps it proposed (I114); confirming the card confirms it. A flavour report must read confirmed values only.

**I105. Discontinued and "do not reorder" (two flags, both honoured).** `item_card.discontinued` (catalogue.edit, a fact about the
product) and `item_reorder.do_not_reorder` (reorder.manage, the buyer's setting, I66) both make the reorder list never suggest the item
(`never` "discontinued (item card)" / "marked \"do not reorder\""). Neither stops a buyer ordering it on purpose ("create draft PO"
with typed packs; the PO editor warns "marked discontinued on its item card"). Merging the two was rejected: different people own them.

**I106. A person's barcodes (`ItemBarcodes`).** Add (8–14 digits with a valid GTIN check digit, stored as `Gtin::key`; a wrong check
digit is 422 `bad_barcode` saying which digit it should be: `Gtin::checkDigit`), with **units per scan** 1–10,000 (an outer case: its
own barcode, "this barcode = 10 units"; the PO scan lookup already picks the supplier's pack of that size, and IM6 will count a scan
as that many units); change units per scan (the form carries the units it showed: 409 `barcode_changed`); remove (409 `barcode_gone`
when it is not there). A barcode belongs to one item (`sku_barcode` is keyed by it): one already on another item is 409
`barcode_on_other_item` naming it, never moved. A removal is recorded as a decided `barcode_review` row (reason and decision
`removed`) so neither the sync nor the seeder adds the barcode back to that item (I118); adding it again by hand is allowed. The
item page puts "Remove…" behind a second step with what it does (I123). A barcode with an open review is added unusable (manually,
by the sync, and now by `BarcodeSeeder` too). Audit `sku_barcode.add` / `remove` / `units`.
`sku_barcode` keeps FULL grants (the removal is a DELETE, recorded in `barcode_review` and `audit_log`).

**I107. The barcode review queue (`BarcodeReviews`, `barcode_review`).** Reasons: `on_another_item` (a linked listing of item S
carries a barcode item H has; H's row was made unusable: "a barcode on two items is unusable until fixed", plan §2.2) with the
decisions keep_holder (usable again; the site's listing is wrong), move (to S, with units per scan: typed, else the row's; a decided
`moved_away` row for (barcode, H) in the same transaction, I116), unusable (a shared box or catch-all code: stays on H, unusable);
`multipack_listing` (a NEW barcode of a listing linked with u ≠ 1: the pack's barcode or the single unit's?) with add (units: typed,
else the listing's u) and dismiss (a row added meanwhile, unusable only because of the review, is usable again, I117). A decision
re-reads the barcode's row and
refuses what no longer fits (409 `review_stale`: keep when the holder no longer has it, move when a third item has it, add when another
item has it; it says where it is now). A barcode becomes usable again only when its LAST open review is decided ("(another review is
open)" in the note otherwise) and no person has ruled it shared since its row was made (I117). At most one open row per (barcode, claiming item): the stored generated `open_key` UNIQUE. The app
login: SELECT, INSERT and UPDATE of the decision columns only (`Grants::UPDATE_COLUMNS`). Audit `barcode_review.decide`. The queue
(Items › Barcode review) is for catalogue.edit, with the open count as the menu badge `barcodes_open`; the item page links its open
reviews.

**I108. The barcode sync (`BarcodeSync`, `bin/sync_barcodes.php`; plan change 16).** For every usable GTIN key K (I118: no letters, no
restricted-circulation number) of every LINKED listing L (item S, units per item u), in listing id order: K on S → `already`; the
latest decided review of (K, S) is keep_holder, unusable, dismiss, removed or moved_away (`KEEPS_AWAY`) → `skipped_decided` (a person
said so; never asked again; read again under the barcode's lock, I118); a review of (K, S) open →
`in_review`; no item has K and u = 1 → added (source `listing_sync`, note "listing L"); no item has K and u ≠ 1 → a
`multipack_listing` review; another item H has K → an `on_another_item` review and H's row made unusable. Unusable codes (text, shop
codes, bad check digits) are counted and skipped. **Dry run by default** (reads only; an overlay of what the run would have written
lets it count a key claimed twice within the run exactly as the real run does: tested equal); `--apply` writes one transaction per
1,000 listings, reads the chunk's rows, decisions and open reviews in three queries, and locks and re-checks only the barcodes it
writes (`FOR UPDATE`; a duplicate key from a concurrent writer is counted `raced`, the next run sees it); one audit row
`sku_barcode.sync` with the counts. Idempotent: a second run is all `already` / `in_review` / `skipped_decided`. `--channel`,
`--limit` (a canary). Exit 0 also when reviews were opened. **Not scheduled** (no cron line): the owner decides when it runs nightly,
after the listing pushes.

**I109. Screens and permissions (provisional, owner to confirm; screen changes of the review in I123).** `catalogue.edit` = mapping_lead, stock_controller,
purchasing_manager (the catalogue team as the plan describes it: the mapping lead knows the items, the stock controller has the box in
hand, the purchasing manager the supplier's papers); never admin. Items menu: Search, **Item cards** (`catalogue.view`, every role),
**Barcode review** (`catalogue.edit`, badge). Routes: `/ui/items/cards` (+ `.csv`), `/ui/items/cards/import` (GET/POST, catalogue.edit),
`/ui/items/{id}/card` (GET form, POST save), `/card/accept`, `/card/confirm`, `/ui/items/{id}/barcodes` (+ `/remove`, `/units`),
`/ui/items/barcodes` (+ `/{id}/decide`). A page or POST outside a person's permission is 403 at the route (I11), checked again in the
service. Every form carries a FormOnce key (the same form sent twice has one effect) and, for the card, the version (a stale form comes
back 409 with what the person typed, the current version and what changed meanwhile, so saving again is a deliberate overwrite; an
invalid one 422 with each field's reason). The item page: the Item card section (state, the rules with their reason, the fields, advice,
"Change / Fill in the item card", the suggestions with "Use this", the confirm form with the acknowledgement box when a rule is broken,
the history) above Identity and Barcodes (add / units / remove forms). The item cards list: every item not merged away, **ordered by the
stock it holds** (Σ max(on_hand, 0) over all warehouses; then id) so the 8,199 items with stock come first, 100 a page; filters: words
(code, name, brand, flavour), card state (no card / not confirmed / changed since confirmed / confirmed), holds stock, warned or
blocked, blocked (I121), product type (or not set), discontinued; the summary line; CSV of the same filters. Phone width: every table is `table.stack` in a
`div.scroll` with `data-label` cells, the definition lists and the barcode and confirm forms go to one column (app.css).

**I110. CSV export and import (`ItemCardCsv`, `bin/import_item_cards.php`).** Export: the list rows (filters apply) with `code`, name,
catalogue brand, stock held, the ten card columns (product type as its label), `flavour_status`, `card_version`, confirmed, confirmed by /
at and the warnings, through `CsvWriter` (UTF-8 BOM, formula-safe, I25). Import (`CsvReader`, I45): `code` required; the card columns
optional; `card_version`, when filled in, must be the card's version now (a row someone changed on the screen since the export is
refused, never overwritten); the export's read-only columns are read past; **any other column is refused** (400 `bad_file`: a typo
like "nicotine" must not be dropped silently); one row per item. **An empty cell changes nothing** (clearing is done on the item page:
a spreadsheet cannot tell "empty" from "not filled in"). The apostrophe `CsvWriter` put before a would-be formula is read past
(`cell()`), so an untouched export imports as no change (tested, `=1+1` and `@risk` included). **All or nothing, in two steps
(I120):** every row is checked against the cards as they are (bulk reads, no lock, no write; `ItemCards::check` and `plan`, the rules
of a save), and a dry run stops there; a real run with no refused row then saves the changing rows in one transaction, each through
`ItemCards::save` (version re-checked under the card's lock: a card changed between the steps refuses the whole file). The report
lists every problem as "row N (CW-...), column: message" (N = the data row, spreadsheet row N + 1; the first 200 shown, all counted).
Dry run by default on both the screen ("Check only") and the CLI (`--apply`). Each changed card is an `ItemCards::save` of kind
`import` (history, audit); a flavour from a file is proposed; **a file never confirms a card**. Every run, dry runs included, is an
`import_run` row (kind `item_cards`, 0016) with its counts and its origin (screen, or cli with the OS user); a real run is audited
`item_card.import` with the origin. Limits (I120): the screen 2 MiB and at most 2,000 CHANGED rows (unchanged rows cost almost
nothing, so the whole "holds stock" download fits; a changed row costs about 11–14 ms, I112, inside the UI pool's 60-second
request), up to 20,000 rows; the CLI 32 MiB / 20,000 rows, at most 5,000 changes unless `--max-changes` (the changed cards stay
locked until the run ends). On the screen the real run is inside FormOnce's transaction (one import however often the form and file
are sent: the request hash covers the file's sha256), so the import's second step uses a savepoint of its own there (a joined
`transaction()` rolls nothing back); a file refused as a whole there is recorded after the rollback.

**I111. Invariants IC1–IC3 (`CatalogueInvariants`, called by `Invariants::check`, so nightly, by the hammer and after every stock
test).** IC1: every card has history rows for exactly versions 1..version, and no history row is without a card. IC2: every card's
values are its latest history row's snapshot (the held `confirmed_breaches` included, so a block lifted with SQL is found), and a
confirm row's snapshot carries a confirmation (a card rewritten with SQL by the app login, which may UPDATE item_card, is found:
tested). IC3: a barcode with an open review is unusable. GrantsTest runs the whole flow as
a throwaway login with exactly the app login's rights (cards, confirm, blocked, the sync, a decision, add / units / remove, a CSV
import) and checks that it cannot delete a card or rewrite the history or what a review found.

**I112. Deviations, measurements, open items.**
- *Not as the brief says, or beyond it:* the confirmation of a card that breaks a rule is allowed with an explicit acknowledgement,
  not refused (I103: otherwise nothing could ever be blocked); the PO approval refuses a blocked item (the brief named receiving and
  selling; ordering an item that may not be sold or received is the same rule one step earlier); a CSV import is all or nothing and
  an empty cell changes nothing (I110); the screen's import is capped at 2,000 changed rows (I110, I120); `discontinued` sits beside the reorder
  settings' `do_not_reorder` rather than replacing it (I105); single-use is a person's answer and the single-use product type needs it
  (a CHECK and a 422), and "disposable" is not accepted as a product type (I104); `BarcodeSeeder` now adds a barcode with an open review
  unusable (IC3).
- *Files outside the brief's list:* `src/PurchaseOrders/{PurchaseOrders,PurchaseOrderHandler}.php` (the approval block and the
  warnings), `src/Reorder/{ReorderList,DraftPos}.php`, `src/Ui/{Controller/ReorderController,views/reorder}.php` (the flags),
  `src/Mapping/BarcodeSeeder.php`, `src/Matching/Gtin.php` (`checkDigit`), `tests/Integration/UiSecurityTest.php` (the new routes need a
  sign-in), `docs/HANDOFF.md` (the open decisions).
- *Measurements* (7 Oct 2026, slot `im3a`, a scratch probe on the slot's schema, not kept: 15,000 items, 8,200 holding stock, 15,000
  linked listings with one EAN-13 each and 50 shared, 2,000 cards): a card write through `ItemCards::save` about **14 ms** (2,000 in
  28.7 s, from a process on the staging box); the item cards list page 1 **308 ms** (holds stock + not confirmed 136 ms, breaks a rule
  284 ms), its count 80 ms, the summary 229 ms; the CSV of all 15,000 items **0.62 s** (1.4 MB); the suggestions of one item 42 ms; the
  barcode sync dry run **1.1 s**, the first real run **77 s** (14,950 barcodes added, 50 reviews, 50 made unusable: about 5 ms a written
  barcode, each locked and re-checked), a second run **1.3 s** (14,950 already, 50 in review); a 5,000-row CSV import 36 s as a dry run of
  unchanged rows, **56 s** when every row changes (about 11 ms a row: hence the screen's 2,000-row cap); every invariant 0.68 s. On
  `cw_staging` the first sync will mostly find "already": `BarcodeSeeder` put the 13,082 origin-listing barcodes in on 30 Sep (M25).
- *Tests* (7 Oct 2026): the full suite in slot `im3a`: `OK, but some tests were skipped! Tests: 847, Assertions: 18473, Skipped: 75`
  (796 before this task, plus 51: 19 unit in `ItemRulesTest`, `GtinTest`, `ItemCardCheckTest` and the new `PermissionsTest` case; 32
  integration: `Catalogue/` 20 with the two race tests, `Migration0016Test` 3, `ReorderItemCardTest` 1, `ItemCardScreensTest` 7 and the
  new `GrantsTest` case; the 75 skipped are the HTTP screen and API tests of slots `ui`/`api`). In slot `ui`: `UiAuthTest`,
  `UiReviewFlowTest`, `UiSecurityTest` (with the IM3 routes) and every `UiKernel` test: `OK (114 tests, 21094 assertions)`; the race
  tests three times more: OK. In slot `api`, the seven `Api*Test`: `OK (35 tests, 2453 assertions)`. The hammer
  (`--seed=20261007`, slot `im3a`): `RESULT: PASS (59 checks passed, 0 failed)`, 94 s. The matching golden tests: `{"passed":59,"failed":0}`.
- *Open:* deploy 0016 and the code to `cw_staging` and run the sync's dry run there (the owner's go; nothing in this task touched
  `cw_staging`); a nightly sync (no cron); the owner to confirm `catalogue.edit`'s roles (I109); IM6 (receiving) and IM10 (the site stock
  writer) call `ItemCompliance` when they are built; the count gate (plan §7.4) may confirm the card's fields from the box when counts
  are built (IM2); the card's fields are not in the API yet (nothing outside CW asks for them).

**Review fixes (two reviews of the uncommitted IM3 tree, 7 Oct 2026; slot `im3d`).** Every blocker and important finding is fixed,
and every minor and nit, except where I124 says why not.

**I113. A block follows the last confirmation (review blocker / important: "the block is not sticky").** As first built, the block
was computed from the values now plus `first_confirmed_at`, and an unknown value breaks nothing, so emptying the nicotine of a
confirmed 12 ml 20 mg/ml e-liquid, retyping a 5 ml tank as an accessory or answering single-use "not known" again lifted the block
with no confirmation (probed by both reviews), while an edit adding a breach to a confirmed card blocked the item at once with no
acknowledgement. Now (`ItemRules::status`): `confirmed_breaches` keeps the rules the LAST confirmation acknowledged (no longer cleared
when the card is edited; a CHECK allows it only once the card was confirmed, as a non-empty array), and they block until a person
confirms the card again; while the card is confirmed, every rule its values break blocks too (a rule made stricter later); every
other rule the values break is a warning. So a block always rests on a person's acknowledged confirmation, and only another
confirmation lifts it (`confirm()` returns and records `lifted`; the notice "no longer blocked"). Emptying a field a confirmation
needs also means the next confirmation needs it again (`missing()`). The alternative (refusing to empty a needed field on an enforced
card, b in review 2) was not taken: it would not stop the retyping case, and the held rules cover all three. `sqlBlocked()` and
`sqlFlagged()` are the same rules in SQL (the list's summary and filters; tested against `status()` on confirmed and edited cards);
`IC2` compares the held rules too. Tests: `ItemRulesTest::testWarningsUntilConfirmedThenBlocksThatFollowTheLastConfirmation`,
`ItemCardsTest::testNoEditLiftsABlock` (the four probes), `testWarningsUntilAPersonConfirmsThenBlocks`, the reorder and screen tests.

**I114. A form save keeps a proposed flavour proposed (review important).** The card form posts every field, so saving it for any
reason re-sent the flavour a CSV file had proposed and `apply()` confirmed it: a confirmation nobody made (the memory note "flavour
is not a field"). Now (`ItemCards::plan`) a flavour sent back unchanged keeps its status for kind `change` (and `import`); only a NEW
typed value, an accept, or the card's confirmation confirms it. The form marks a proposed flavour "proposed by a file, not
confirmed" and says saving leaves it so. Tests: `ItemCardsTest::testAFormSaveKeepsAProposedFlavourProposed`,
`ItemCardScreensTest::testTheFormKeepsAFileFlavourProposed`.

**I115. A device / kit needs its tank capacity; 0 ml = none (review important).** `missing()` asked a device / kit only for the
single-use answer, so a kit with a 3–5 ml tank could be confirmed "compliant" and the 2 ml rule never applied to kits, the commonest
TRPR problem. Now a kit needs `liquid_ml` too, and 0 is its answer "comes without a tank or pod" (a mod, a battery): the parser
accepts 0, `ItemCards::plan` refuses 0 on any other type (422 `card_invalid`, also when a kit at 0 ml is retyped), the CHECK
`ck_item_card_ml` allows 0 for `device_kit` only, and the screens show "0 ml (no tank)". Tests: `ItemCardsTest::
testAKitNeedsItsTankCapacityAndZeroMeansNone`, `ItemRulesTest`, `Migration0016Test`.

**I116. A "move" is not taken back by the old holder's own listing (both reviews, important).** `decide('move')` closed only the
claimant's review; the holder usually got the barcode from its own linked listing, so the next sync opened a reverse review and made
the moved barcode unusable again (probed). Now the move writes, in its transaction, a decided `on_another_item` row for (barcode,
old holder) with the new decision `moved_away` (0016's ENUM; a CHECK keeps it to decided `on_another_item` rows), or closes the
holder's own open claim with it, and `KEEPS_AWAY` includes `moved_away`. Test: `BarcodeSyncTest::
testAMoveIsNotUndoneByTheOldHoldersListing` (the holder's listing carries the barcode; two syncs after the move: nothing reopened,
still usable; and a three-way case where the move answers the holder's own open claim).

**I117. A barcode ruled shared stays unusable (review important) and a dismissed review frees a row added meanwhile (nit b).**
keep_holder (and move, add) made the barcode usable whenever no other review was open, undoing a person's earlier "Shared by both:
keep it unusable" for another claimant (probed with three items). Now usability comes back only when no other review is open AND no
decided `unusable` review of the barcode is newer than its `sku_barcode` row (`BarcodeReviews::ruledShared`; the row's `created_at`
scopes the ruling, so removing the barcode and adding it by hand starts afresh); the note says "kept unusable: barcode review N ruled
it shared". Dismissing the last open review of a barcode now makes a row usable again when it was unusable only because of the review
(its note carries "in barcode review", what every writer puts there). Tests: `BarcodeSyncTest::
testASharedRulingIsNotUndoneByALaterKeep`, `testDismissingTheLastReviewFreesARowAddedMeanwhile`.

**I118. The barcode sync and the seeder (review minors).**
- *A removal during a sync chunk* (minor): `writeLocked()` now reads the pair's latest decision again after locking the barcode row,
  so a person's removal committed after the chunk's prefetch is honoured (`testARemovalDuringASyncChunkIsHonoured`).
- *Junk codes* (minor): the sync reads a value with anything but digits, spaces and hyphens as junk ("SKU-12345670" is no longer the
  GTIN-8 12345670), a decimal number likewise, and skips GS1 restricted-circulation numbers (GTIN-13 020–029, 040–049, 200–299;
  GTIN-8 2…); counted `junk_codes` / `restricted_codes` in the dry run. The matcher's `Gtin::listingKeys` is unchanged (the golden
  tests), and a person may still add such a code by hand. Test: `testJunkAndRestrictedCodesAreSkipped`.
- *A listing unlinked later* (minor) and *after a merge* (minor): counted, never changed: `source_unlinked` (barcodes from a listing,
  sources `listing_sync` and `origin_listing`, whose listing is no longer linked to their item, as found before the run; the CLI
  lists the first 20; `unlinkedSources()`), `review_holder_merged` (reviews whose holder was merged into the claimant); the queue
  says so on such a row and pre-selects "move". A new review reason was rejected: the owner first sees the volume in the dry run.
  Test: `testUnlinkedSourcesAndMergedHoldersAreCounted`.
- *The seeder ignored decisions* (review 2, minor): `BarcodeSeeder` now skips a pair whose latest decision is in `KEEPS_AWAY`
  (`skipped_decided` in its counts and log line), so a re-run of `seed_barcodes` or `mint_vpg` never undoes a person (and no longer
  marks a kept barcode unusable again). Its clashes still make a barcode unusable without a review: ops.md now says to use
  `sync_barcodes`. Test: `testTheSeederHonoursDecisions`.

**I119. Receiving: dry products with no duty answer (review minor).** `receiving()` said `stamp_required` for every item whose card
does not answer duty-liable, coils, tanks and accessories included, so from 1 Jan 2027 IM6 would have refused unstamped hardware.
Now a card that names a dry type with duty NULL gives `stamp_required` false (`duty_unknown` stays true). An item with NO card stays
fail-closed (duty-liable), as decision 8 reads; **open for the owner** before IM6: give the hardware cards before 1 Jan 2027, or
decide that IM6 asks the receiver. Test: `ItemCardsTest::testWhatReceivingAsks`.

**I120. The CSV import (review 2 important; review 1 minor).** The screen refused any file over 2,000 rows, so the main job (the
8,199 items holding stock) could not go through it, while the cost is in the rows that CHANGE a card. Now the import checks every row
first without a lock or a write (bulk reads; `ItemCards::check` + `plan`), counts the changing rows, and refuses the whole file only
when they exceed the cap (`too_many_changes`, 413, saying what to do): 2,000 on the screen (which now reads up to 20,000 rows within
its 2 MiB), 5,000 by default on the CLI (`--max-changes`, up to 20,000). Only a real run takes locks, and only on the changing cards.
In the screen's FormOnce transaction a deadlock is rethrown as itself (InnoDB rolled the whole transaction back, so the savepoint is
gone; `ROLLBACK TO` would have replaced the 1213 with a 1305 and defeated the retry), and a lock wait (1205) on a row refuses the file
with "import it again in a minute" instead of a 500. A file refused as a whole on the screen's "Save the changes" is recorded after
FormOnce's rollback (nit c; `recordRefused`). Every run records its origin (`screen`, or `cli` with the OS user) in `import_run.summary`
and the `item_card.import` audit (review 2 nit). Measured (7 Oct 2026, slot `im3d`, a scratch probe not kept: 15,000 items, 2,000
cards): the export 0.62 s and 1.38 MB; the check of the untouched 15,000-row export **1.0 s** (dry run and real run alike; before,
about 7 ms a row: 36 s for 5,000 rows); a real run changing 2,000 cards **28.9 s** (14 ms a card, inside the 60-second request).
Tests: `ItemCardCsvTest::testTheCapCountsChangedRowsOnly`,
`testACardChangedDuringTheImportRefusesTheFile`, `testTheCli`, `ItemCardScreensTest::testTheCsvImportScreen`.

**I121. Say what a block stops today (review 2 important).** The banner, the confirm refusal and the notice said a blocked item
"cannot be ordered, received or sold", but only the reorder list and PO approval refuse it until IM6 and IM10 exist, and the website
keeps selling it. `ItemCards::BLOCK_EFFECT` is now the one sentence every screen uses: "it is never suggested for reorder, and a
purchase order with it cannot be approved. CW does not stop receiving or website sales yet: take it off sale on the website by hand."
Items › Item cards gains a **Blocked** filter (most stock first) for that hand work; `HANDOFF.md` is corrected. Change the sentence
when IM6 and IM10 call `ItemCompliance`.

**I122. PO approval reads the cards FOR SHARE (review minor).** `statusOf()`, `blocked()`, `assertAllowed()` and `receiving()` take
`$lock`; `PurchaseOrderHandler::validate` passes true, so a confirmation committing during an approval is either seen or waits for it.
IM6 (receiving) and IM10 (selling) are write paths and must pass true too.

**I123. Smaller screen and parser changes (reviews' minors and nits).** "pod" / "pods" are no longer product-type spellings (in UK
retail often an EMPTY refillable pod; "prefilled pod(s)" stays); removing a barcode is behind "Remove…" with what it does; the
"barcode on another item" refusal links to that item; the reorder tags name the rule ("blocked by its item card: tank or pod over
2 ml") and "create draft PO" names it in its skipped reason (nit a); the confirm box reads "I checked the packaging"; I103 cites the
IM3 rule; two docblocks corrected; `ItemRules::scaled` refuses a float, a bool or an array (a computed value must never skip a rule
silently) instead of reading it as "unknown".

**I124. Not taken, and the tests.**
- Not taken: committing the CLI import every N rows (a "partial" mode): it would break "a file with any refused row changes
  nothing"; the changed-row cap and the lock-free check do the job. A new review reason for unlinked sources (I118). Refusing to
  empty a needed field on a confirmed card (I113).
- *Tests* (7 Oct 2026, slot `im3d`): the full suite: `OK, but some tests were skipped! Tests: 860, Assertions: 18652,
  Skipped: 75` (847 after I112, plus 13: `ItemCardsTest` 3, `ItemCardCsvTest` 2, `BarcodeSyncTest` 7, `ItemCardScreensTest` 1; the 75
  skipped are the HTTP screen and API tests of slots `ui`/`api`). In slot `ui`: `UiAuthTest` (13), `UiReviewFlowTest` (14),
  `UiSecurityTest` (11) and every `UiKernel` test (77): `OK (115 tests)`. In slot `api`, the seven `Api*Test`: `OK (35 tests, 2453
  assertions)`. The hammer (`--seed=20261007`, slot `im3d`): `RESULT: PASS (59 checks passed, 0 failed)`, 102 s. The matching golden
  tests: `{"passed":59,"failed":0}`.


## Inventory Phase I-3 (slot `rcv1`, IM6 Receive (+ invoice) with duty-stamp checks)

IM6 of `docs/inventory-modules-plan.md` §3 (the I-3 row of §7: "book 5 real deliveries ... a box of 5, a PO copy-down and a
supplier-sheet import"; the owner's decision 8 of §10: unstamped deliveries refused from 1 Jan 2027; plan §9 "one route per item").
Numbered I125–I147. The GRN document type goes live; it books stock (goods_in through `Movements::bookForDocument`). Code:
`migrations/0017_receiving.sql`, `src/Receiving/` (`GoodsReceipts`, `GoodsReceiptHandler`, `ReceiptPlan`, `ReceiptMath`,
`ReceiptLinesFile`, `SellingModes`, `Incidents`, `ReceivingInvariants`), `src/Documents/ReviewInvolvement.php` (new),
`src/Ui/Controller/{Receiving,Incidents}Controller.php` (new), `src/Ui/views/{receipts,receipt,receipt_edit,receipt_bench,receipt_files,
bench_list,incidents}.php` (new); changed: `src/Documents/{Documents,DocumentHandlers}.php`, `src/Auth/Permissions.php`,
`src/Schema/Grants.php`, `src/Settings.php`, `src/Invariants.php`, `src/Catalogue/{ItemCards,ItemCompliance}.php` (the block now
stops receiving), `src/Ui/{Kernel,Context}.php`, `src/Ui/Controller/{Documents,Reviews}Controller.php`, `src/Ui/views/{document,documents,
item_cards}.php`, `public/ui/assets/app.css`; tests `tests/Integration/Receiving/` (with `ReceivingTestCase` and `grn_worker.php`),
`tests/Integration/UiKernel/ReceivingScreensTest.php`, `tests/Integration/Migration0017Test.php`, `tests/Unit/ReceiptMathTest.php`,
and updates of `GrantsTest`, `PermissionsTest`, `MenusTest`, `UiTemplatesTest`, `DocumentLifecycleTest`, `ReviewScreensTest`,
`ItemCardScreensTest`.

**I125. Design in one paragraph.** A goods receipt is a `document` of type GRN (warehouse MAIN, `doc_date` = the day the goods
arrived, `external_ref` = the supplier's invoice number) with a `goods_receipt` header extension and one `grn_line` per
`document_line` (the PO pattern, I50). The purchasing desk keys it as a draft (its creator); the goods-in bench records its check on
the same draft (anyone allowed to receive); posting (`Documents::post`) runs `GoodsReceiptHandler`: the receipt's plan
(`ReceiptPlan`) decides, per line, the units accepted into MAIN, put into VERIFY (damaged, wrong item, over), quarantined in
UNSTAMPED and refused, and refuses the posting while anything stops it; then the PO's receipts, the lines' posting fields, an
incident per exception, the items' selling modes, a write-once anchor, and last the stock in one `bookForDocument` at the line's
unit cost. Post first, a second person reviews (the seeded GRN rule: `all`, due 3 days, a rejected review reverses it). One plan
serves the editor and the bench view (what posting would do and every problem, before anyone presses post), `validate()` and
`post()` (built again under the posting's locks), so the screens never promise what the posting refuses.

**I126. Who keys, who checks, who posts (provisional, owner to confirm with decision 3).** `doc.GRN.post` (goods_in,
purchasing_desk, purchasing_manager: I16) drafts, does the bench check and posts. A draft's header and lines are changed only by
its creator (the document base's rule, I17; 403 `not_creator`, the bench is told to use the bench view); the bench findings by
anyone holding `doc.GRN.post`, the creator included ("it can be the same person", plan IM6); posting by anyone holding it. Admin
never (I12). The bench check is part of every receipt: posting needs "supplier and paperwork credible" answered, and refuses a
"not credible" (the delivery is refused and the receipt cancelled). Who reads: `receiving.view` (I141).

**I127. The tables (0017) and their grants.** `goods_receipt` (NO_DELETE: cancelled or reversed, never deleted): supplier, the PO
(optional), `invoice_key`, invoice date, delivery note, `received_at`, `paper_sheet` + `backdate_reason` (CHECK: a paper sheet has
its reason), the bench's `paperwork_ok`, `bench_note`, `checked_by/actor/at` (CHECK: a check has its answer). `grn_line` (FULL,
`ON DELETE CASCADE` with its draft `document_line`, like `po_line`): supplier item and code, purchase unit, units per pack, packs, pack
price, the PO line, how it was keyed (`entry`), the mode choice; the bench's stamp check (`stamp_on_pack`, `stamp_type`, `stamp_code`)
and exceptions (short, over, damaged, wrong item, unstamped + `unstamped_action` + `pre_october_evidence`; CHECKs: short + damaged +
wrong item + unstamped ≤ packs × units per pack, an action iff unstamped units, evidence iff accepted pre-October, a stamp type only
with a stamp on the pack); and the posting's fields, written once (`stamp_required`, `duty_ml`, `expected_duty`, `selling_mode`,
`mode_source`, `accepted/verify/quarantine/refused_units`, `po_units`; CHECK: all or none, `po_units` ≤ accepted). `grn_posting`
(APPEND_ONLY), `incident` (UPDATE of its resolution columns only), `item_selling_mode` (NO_DELETE), `item_selling_mode_log`
(APPEND_ONLY). No FK from `incident` or `item_selling_mode` to `sku` (they are written inside the posting, before its stock locks:
an FK would S-lock the item's `sku` row before the balances, the order `setPolicy` reverses, D15, I21); G5 and G6 check the ids.

**I128. The write-once anchor of a posted receipt (G3, like P2 and I33).** `document_posting` anchors the generic header and lines;
`grn_posting` anchors what only the receipt's tables hold: `goods_receipt` (all but `invoice_key`, which a reversal frees, and the
row's timestamps) and every `grn_line` column, after the posting wrote its fields (`GoodsReceiptHandler::content`). A bench finding
or a booked split changed after posting is found nightly.

**I129. Units are packs × units per pack, and where they go (`ReceiptMath::split`).** The paperwork's quantity is always packs of a
pack size (a supplier item's, a case barcode's units per scan, or typed), never "boxes for units" (the ERPNext bug, plan E10): the
`document_line.qty` is packs × units per pack. Of those units the bench records what did NOT arrive (short), arrived damaged, arrived
as another item (wrong item: booked to VERIFY under this line's item until stock control re-books it, IM2) and arrived without a
valid duty stamp; "over" is what arrived beyond the paperwork. Accepted (MAIN) = units − short − damaged − wrong item − unstamped,
plus unstamped units accepted on the supplier's pre-October evidence; VERIFY = damaged + wrong item + over; UNSTAMPED = unstamped
quarantined; not booked = short and unstamped refused. Only the accepted units count towards the PO line (`po_units`): damaged and
over units are the supplier's to credit or take back, so the order still expects what was not accepted.

**I130. The supplier invoice number and its copy (plan IM6 "compulsory, unique (supplier, number), PDF attached").** Compulsory at
posting (422 `invoice_number_required`; a draft may start without it). One live receipt per (supplier, invoice): `invoice_key` is the
number upper-cased with every space removed ("inv 001" = "INV001"; hyphens and slashes kept), UNIQUE with the supplier while the
receipt is draft, waiting or posted, and NULL once it is cancelled or reversed (the number is free to key again); a second receipt is
409 `duplicate_invoice` naming the first (draft or posted, and who keyed it). A draft holds its number too, so two people keying one
invoice find out at once (raced: `GoodsReceiptRaceTest`). **The copy:** a file attached with the role `supplier_invoice` whose
sniffed type is a PDF **or a photo (JPEG, PNG)**: a paper invoice photographed at the bench is the commonest copy, and the plan's
"PDF" is read as "a faithful copy kept in the store"; a spreadsheet or text file is refused before it is stored (415). Files go
through `FileStore` (sniffed, deduplicated, kept 7 years, attached for good): the invoice, a delivery note, photos, duty evidence.

**I131. The incident register (`incident`, `Incidents`).** Posting opens one incident per exception of a line (kinds unstamped,
damaged, wrong item, short, over; dedupe `grn:<document>:<line>:<kind>`), with where its units went (`verify`, `quarantine` with the
warehouse, `refused`, `not_received`), the supplier, the item and the supplier invoice in its detail. A person holding
`incidents.resolve` (purchasing desk, purchasing manager, stock controller; never admin) closes it, `resolved` or `dismissed`, with a
note of 3–500 characters (409 `incident_closed` when someone closed it meanwhile; audit `incident.resolve`). Closing moves no stock:
units leave VERIFY and UNSTAMPED through IM2's documents (I-4). Reversing a receipt dismisses its open incidents ("the receipt was
reversed by GRN-x"). Unstamped units accepted on the pre-October evidence open no incident: the line records the evidence and the
plan warns that they must be cleared by 31 Mar 2027 (IM13's duty register reads the lines). The menu's badge counts the open ones.

**I132. Quarantined unstamped units go to UNSTAMPED, not VERIFY (deviation from the brief's "exceptions -> VERIFY").** Damaged,
wrong-item and over units go to VERIFY as the brief says. Unstamped duty-liable units that are quarantined go to the UNSTAMPED
location, built for exactly this (plan §2.2, §15.1): IM13's "unstamped must be zero" alert watches it, the plan says it must be
empty from 1 Apr 2027, and keeping duty stock apart from damage keeps the VERIFY work list about damage. Both are non-sellable.

**I133. The person who checked a delivery at the bench never reviews its receipt (`ReviewInvolvement`).** The bench's findings decide
what is booked where, so the review rule's "creator, submitter, poster" (I19) is not enough. A handler may implement
`CW\Documents\ReviewInvolvement`: `involved($db, $doc)` (staff id → why) and `involvedSql()` (the same rule for the badge's count).
`Documents::refusalFor()` (new) adds it to `refusal()`; `approve()`/`reject()` refuse with it (403 `own_document`: "You checked this
delivery at the goods-in bench: another reviewer must review it."); `decidableCount()` leaves those tasks out; the receipt page, the
document page and the review queue use `refusalFor`. For a receipt, the bench checkers are every person with a `grn.bench` audit row
on it (append-only: a later check by someone else does not erase an earlier checker), for the receipt and its reversal. G8 finds a
decision that broke it. Small extra cost: one indexed audit lookup per open GRN task in the badge.

**I134. Duty stamps at goods-in (owner decision 8; plan IM6 "Duty at I-Day").** A line needs the stamp check when
`ItemCompliance::receiving()` says `stamp_required` (duty-liable, or not answered unless the card names a coil, tank or accessory;
an item with no card counts as duty-liable, I119) and some of its units arrived (not all short, damaged or wrong): the bench
records whether the UK duty stamp is on the outer retail pack and seals it (422 `stamp_check_required`), its type (digital or
transitional: 422 `stamp_type_required` when stamped units arrived) and, optionally, a scanned stamp code (for HMRC's promised
retailer scanning service). "Not on the outer pack" means every unit that arrived is unstamped (422 `stamp_not_on_pack` until the
bench says so). Unstamped units on an item that needs no stamp are refused (422 `unstamped_not_duty`). What happens to unstamped units
depends on `receiving.unstamped_refusal_from` (date, `2027-01-01`, decision 8, confirmed: provisional 0) compared with the day the
goods **arrived** (UK; a paper sheet keyed later keeps its date): from that date they are refused at the door (not booked) or
quarantined (UNSTAMPED), with an incident (422 `unstamped_refused_now` for an acceptance); before it they may also be accepted into
MAIN with the supplier's evidence that they were made or imported before 1 Oct 2026 (at least 10 characters, plus an evidence file if
there is one; anything made or imported later that arrives unstamped is always refused: the evidence is what tells them apart). No
date set: the refusal applies (fail closed). The "made before 1 Oct" path exists only for the rehearsals before I-Day: I-Day is after
1 Apr 2027, when even holding unstamped liquid is an offence (§6.1).

**I135. The expected duty, for information only.** Per unit, ml × `receiving.duty_pence_per_ml` (22p: £2.20 per 10 ml, FA 2026)
**rounded down to the penny**, times the units on the paperwork; only for a card that says duty-liable and names a product holding
liquid (not a coil, tank or accessory, and not a device / kit, whose ml is its tank's capacity) with its ml known. Shown per line and
in total on the editor ("for information"), kept on the posted line (`duty_ml`, `expected_duty`) for IM8 and IM13; nothing is booked
from it (duty as cost is IM8's, from the invoice's duty line).

**I136. The selling mode on receipt (`SellingModes`; plan IM6, IM10).** Each line carries `mode_choice`: `default` or a mode
(In-Stock, From-Warehouse, Out-Of-Stock; the sites' own labels, with CW's meaning beside each on the screen). Default = the item's
last mode: CW's own `item_selling_mode` row (set by an earlier receipt), else the mode its linked listings had on the sites' last stock
snapshot (`listing_stock_latest`, the newest first); an Out-Of-Stock item goes back to its previous mode (the mode it had before CW
set it Out-Of-Stock); an item with no known mode, or an Out-Of-Stock one whose previous mode is not known, gets
`receiving.mode_after_out_of_stock` (From-Warehouse, provisional; never Out-Of-Stock: the delivery is for sale). The lines of one
item must agree (422 `mode_conflict`). Every receipt writes the mode of every item it names, as an ERPNext invoice line does, even
when nothing of it was accepted; reversing a receipt leaves the mode (its quantity goes back; the next receipt or IM10's switch
changes a mode). **The mode is the item's, not per site:** IM10 decides how it lands on each site (plan: per site for legacy items,
"so Electrofag's In-Stock items keep selling as they do today") — today only Vape and Go reads ERPNext's mode, so the site writer
should apply a receipt's mode to Vape and Go only until the owner says otherwise (open for IM10). No feed row is written: the
availability views carry no mode yet (IM10 adds the mode to what the site writer sends, from `item_selling_mode_log`).

**I137. `item_selling_mode` and its log (G6).** One row per item CW has set a mode for (mode, previous mode — kept only while
Out-Of-Stock, CHECK — version, the receipt, who, when), written with `INSERT ... ON DUPLICATE KEY UPDATE version = version + 1`; one
`item_selling_mode_log` row per write (version, mode before, after, previous, why: last / previous / fallback / chosen, the receipt
and line). The app login may UPDATE the row, so G6 compares each row with its newest log row and the log's versions must be 1..n.

**I138. When the goods arrived; the CW-down paper sheet.** `received_at` (UTC; the editor shows and takes UK time) defaults to the
moment the receipt is started; `doc_date` follows its UK date. A receipt whose goods arrived on an earlier UK day than the day it was
**keyed** (started) needs a reason (`backdate_reason`, 422 `backdate_reason_required`); the "keyed from a paper receiving sheet (CW
was down)" box needs it at once. A delivery keyed on its day and posted the next morning is not backdated. At most
`receiving.backdate_max_days` (30, provisional) before the keying day (422 `received_too_early`), never after now (+5 min; a save
refuses more than a day ahead). The ledger rows keep `effective_at` = the booking time (D45): the frozen core is not changed for this;
the receipt's own `received_at` is the real time, linked by `document_id`, for IM8's valuation date and the counts (a count booked
between the arrival and the posting lists the receipt in its `count_after_movements` review, R13).

**I139. Blocked items are refused at receiving (`ItemCompliance`, I103, I122).** The plan reads `ItemCompliance::receiving()` with
the item cards FOR SHARE in `validate()` and again in `post()`: a blocked item refuses the posting (422 `item_blocked`, "it cannot be
received ... Refuse the goods otherwise"); a warned rule, an unanswered duty question and a discontinued item are warnings on the line.
`ItemCards::BLOCK_EFFECT` now says a blocked item "cannot be received"; the item cards page likewise (I121's sentence changed with it).

**I140. The supplier's invoice or packing-list spreadsheet (`ReceiptLinesFile`).** CSV (BOM, Windows-1252, `,` `;` TAB) or XLSX (its
first sheet, `XlsxReader`'s guards), at most 2 MiB and 2,000 rows. A supplier's sheet is not CW's lines file: the header is the
first of the first 30 rows naming a code column and a quantity column, the names compared as letters and digits only against the
supplier spellings of `ALIASES` ("Item Code", "SKU", "EAN-13", "Qty Shipped", "Unit Price (£)", ...), other columns ignored, a field
named twice an error. A row naming no item (totals, carriage, notes) is skipped and listed; every row naming one must be right or
nothing is imported (all or nothing, as I58). The item: a CW code, else this supplier's code, else a usable barcode (a case barcode
counts its units per scan); the first that resolves wins; a supplier code CW does not know is kept on the line when the barcode names
the item; a CW code in the code column is read as one. The pack: the supplier item's (a different pack size is "pack differs"), else
the sheet's, else the case barcode's; a units column must equal packs × units per pack (the boxes-for-units guard); the price column
is the price of one pack. `append` adds (the same supplier item gets the packs added), `replace` replaces every line (their bench
findings with them). Audit `grn.import_lines` with the file's sha256. A sample sheet: `/ui/receiving/template.csv`.

**I141. The screens and permissions (provisional, owner to confirm).** `receiving.view` (goods_in, purchasing_desk,
purchasing_manager, stock_controller, reviewer, accountant, auditor, manager), `incidents.view` (goods_in, purchasing_desk,
purchasing_manager, stock_controller, reviewer, auditor, manager), `incidents.resolve` (purchasing_desk, purchasing_manager,
stock_controller). Receiving menu: **Receive + invoice** (`/ui/receiving`: the list, the "new delivery" form with "receive all as
ordered", a sample sheet), **Goods-in bench** (`/ui/receiving/bench`, doc.GRN.post), **Incidents** (`/ui/receiving/incidents`,
badge `incidents_open`); Supplier invoices and returns stay I-4 placeholders. The acceptance menus change (amends I14, I40): the
reviewer and the auditor gain Receiving; the buyer does not. A receipt's page (`/ui/receiving/{id}`) is the one-form editor for its
creator while a draft (header; a scan box whose Enter adds a line and saves the table; per line packs, units per pack without a
supplier item, the provisional pack price, the PO line, the selling mode with the default spelled out, stock now (MAIN on hand and
the available units), the duty and bench state, a note; "Save" and "Save and post"; the "before posting" checklist with every
problem and warning and the split; "receive all as ordered"; the sheet import; the files; cancel), else the read-only page (what was
booked where, the stamp checks, the modes, the incidents, the review with its decide forms — decisions come back here — and the
reversal). The bench view (`/ui/receiving/{id}/bench`) is one card per line, 40 lines a page (11 fields a line, far below
max_input_vars), big touch targets, the item's barcodes to check against, the camera for photos; at phone width everything is one
column (`app.css`). The editor carries 6 fields a line and is offered while they fit (`editorFits`, about 160 lines); a longer receipt
is changed with the sheet import. Forms: FormOnce for "new delivery"; the version elsewhere (409 redraws the page with the data now).
Reviews of a GRN and its reversal are listed with a link to the receipt; the document page links "Open in Receiving".

**I142. Invariants G1–G8 (`ReceivingInvariants`, called by `Invariants::check`: nightly, by the hammer and after every stock test).**
G1 headers and invoice keys (live: the key of the number; cancelled or reversed: none); G2 line pairs; G3 the anchor (I128); G4 the
stock of each posted line per warehouse at its unit cost, `goods_in`, cost source `document`, nothing elsewhere; G5 an incident for
every exception of a posted line and nothing else, never open on a reversed receipt; G6 selling modes (I137); G7 a PO line that a
receipt line receives has `received_units` = Σ `po_units` of the posted receipts naming it (PO lines no receipt names are left to
P5: tests and the ERPNext import apply receipts directly); G8 no review of a receipt decided by its bench checker (I133).

**I143. Locks and races (extends I21, I54).** Posting: the GRN document row (`Documents::post`) → the supplier FOR SHARE and the item
cards FOR SHARE (the plan in `validate()`) → the GRN number series → `goods_receipt` FOR UPDATE → **the PO's document row, then
`purchase_order` and its `po_line` rows FOR UPDATE** → the plan built again (the selling-mode rows FOR UPDATE) → the PO's receipts →
`grn_line`, `incident`, `item_selling_mode` (+ log), `grn_posting` → the stock (balances → value clocks → feed clock). The PO's rows
are taken **after** the number series, not with the document rows as I54 foresaw: `Documents::reverse` calls `handler->reverse()`
after the series, so a receipt's reversal takes the PO there; taking it before the series in the posting would make the two wait
for each other. Every receipt path takes the GRN series before the PO; no PO path takes the GRN series: no cycle. The tolerance is
checked against the PO's receipts as they are under these locks (two receipts on one PO line queue on the series and the second sees
the first's). Tested with two processes (`GoodsReceiptRaceTest`): one receipt posted twice (one posting, 409 for the other, one
number); two receipts over a PO line's tolerance together (one posted, 422 `over_tolerance`); one invoice keyed twice at once (409
`duplicate_invoice`); one "new delivery" form from two processes (one draft); a posting against the PO's close (the close first: 422
`po_not_receivable`, the number rolled back). 0 deadlocks.

**I144. One route per delivery and per item (plan §9; IM6 "a delivery is booked by one route only").** A delivery: one live receipt
per supplier invoice (I130); the posting books once (the document's status and version; `bookForDocument` refuses a second booking
of a document; FormOnce on "new delivery"); the ledger's `doc_ref` is the receipt number. An item: when a site's ERPNext relay is
granted `goods_in` (`channel.movement_types`, R17), the items with a mapped listing on that site get their goods-in from the relay
until I-Day, so a CW receipt of them is refused (422 `relay_route`, naming the site). None is granted on staging, so CW is every
item's route there. The plan's break-glass ("a lead books against a named purchase invoice during a relay outage; the later relay
posts only the difference") is not built: it needs the relay connector (open item).

**I145. Receipts against a purchase order.** A receipt names at most one PO (the supplier's, approved, sent or part-received; 422
`po_not_receivable` / `po_other_supplier`), changed only while no line is received against it (409 `receipt_has_lines`). A PO that
stops being receivable meanwhile (closed, received) refuses the posting only when a line is received against it: with every line
set to "not against the order" it is a reference and the receipt posts (a warning). "Receive all as ordered" (`copyFromPo`) adds a
line for every PO item line still expecting units and not on the receipt yet, in the PO's packs when the outstanding units are whole
packs (else packs of 1 at the PO's unit cost, rounded half-up to 4 decimals) at the PO's price; the desk then edits what arrived
differently. A line keyed otherwise (a scan, the sheet) is received against the PO's line of its item that still expects units,
else its first line of the item; `po_line_no` 0 = "not against the order". The over-delivery tolerance (`po.over_delivery_tolerance_pct`,
10, provisional) caps a PO line's received units at ordered × (100 + %) / 100 rounded down, over every line of the receipt on it (422
`over_tolerance`, saying to keep the extra off the order or record it as "over"); within it, a warning. The PO's state follows
(`PurchaseOrders::applyReceipt`): part-received, received. A receipt against a PO closed since is not reversed (409 `po_closed`: its
receipts would reopen the order; I59's open question, decided "refuse" for now: correct it with an adjustment in I-4).

**I146. The provisional cost.** A line's pack price (GBP excl. VAT) is what the desk types (the invoice's), else the PO line's
(scaled to the pack size), else the supplier item's last price, else its last PO price, else £0 (a warning). The unit cost (half-up
to 6 decimals, `PoMath`) goes on every ledger row of the line with cost source `document` (C0, I1: compulsory on a receipt's lines).
It is provisional: the supplier invoice's match (IM7) and landed costs (IM8) settle it. A receipt writes no supplier price history
yet: whether the receipt's price becomes the supplier item's "last price" (IM4: "the last price comes from the latest invoice") is
IM7's, once an invoice is matched (open item).

**I147. Deviations, measurements, open items.**
- *Not as the brief says, or beyond it:* quarantined unstamped units go to UNSTAMPED (I132); the invoice copy may be a photo (I130);
  the bench checker is kept out of the review (I133, a document-base extension); "keyed late" is measured from the day the receipt
  was started, not the posting day (I138); the ledger's `effective_at` stays the booking time (the core is not changed: I138); a
  receipt against a closed PO is not reversed (I145); the selling mode is the item's, IM10 applies it per site (I136); no supplier
  price history from a receipt (I146).
- *Files outside the brief's list:* `src/Documents/Documents.php` (refusalFor, the involvement in decisions and the badge),
  `src/Ui/Controller/{Documents,Reviews}Controller.php` and `views/{document,documents}.php` (receipts' links and decisions),
  `src/Catalogue/{ItemCards,ItemCompliance}.php` and `views/item_cards.php` (the block now stops receiving),
  `tests/Integration/{Documents/DocumentLifecycleTest,UiKernel/ReviewScreensTest}.php` (their "type without a handler" is SINV now),
  `ItemCardScreensTest` (the sentence), `PermissionsTest`, `MenusTest`, `UiTemplatesTest`, `GrantsTest`, `UiSecurityTest` (the receiving
  routes in its sign-in sweep), `ReferenceScreensTest` (the GRN number series is live).
- *Open:* IM10 (the site stock writer on Vape and Go proto: quantity and mode together, from the stock feed and
  `item_selling_mode_log`, so the back-in-stock e-mails fire; per-site application of the mode); IM7 (invoice matching, the last
  price, the invoice total check); IM13 (the duty register from the receipt lines and incidents, the "unstamped must be zero"
  alert); the relay break-glass (I144); the owner to confirm the roles (I126, I141), the backdating limit and the Out-Of-Stock
  fallback mode (provisional settings); the typists' half-day and their sign-off on the screens (inventory plan step 9).
- *Tests* (7 Oct 2026, slot `rcv1`): the full suite: `OK, but some tests were skipped! Tests: 908, Assertions: 20689, Skipped: 75`
  (860 after I124, plus 48: `GoodsReceiptRulesTest` 10, `ReceivingInvariantsTest` 7, `DutyStampTest` 6, `ReceiptMathTest` 6,
  `GoodsReceiptRaceTest` 5, `ReceivingScreensTest` 4, `GoodsReceiptLifecycleTest` 3, `SupplierSheetImportTest` 3,
  `Migration0017Test` 3, `GrantsTest` 1), 18 min. In slot `ui`: `UiAuthTest` (13), `UiReviewFlowTest` (14), `UiSecurityTest` (11,
  the receiving routes in its sweep): OK. In slot `api`, the seven `Api*Test`: `OK (35 tests, 2453 assertions)`. The hammer
  (`--seed=20261007`, slot `rcv1`): `RESULT: PASS (59 checks passed, 0 failed)`, 112 s. An earlier full run that overlapped another
  slot's full suite failed `SupplierRaceTest::testTwoBuyersPreferringTwoSuppliesOfOneItem` (which worker took the lock first) and
  `SalesExportToolTest::testTheExplainGateRefusesBeforeAnyDataQuery` (the optimizer refused S1 before S3a); neither is touched by
  IM6, both pass alone and in the green run: timing and plan flakes under load, left as they are.

## Inventory Phase I-3 (slot `sw1`, IM10 Selling-mode switch and site stock writer, Vape and Go proto first)

IM10 of `docs/inventory-modules-plan.md` §3 (the I-3 row of §7: "an Out-Of-Stock item comes back on sale on proto and its back-in-stock
email fires"; plan §6.5 as changed by §12 point 4: CW writes quantity, mode and low-stock threshold for linked-legacy listings too).
Numbered I148–I166 (CW's half) and SC6–SC14 (the Vape and Go connector's half, 0.4.0, below). CW never writes a site: it says, in
every listing view of its feed, what the site writes; the site's worker writes it. Code: `migrations/0018_site_writer.sql`,
`src/SiteWriter/` (`SiteView`, `SiteModes`, `SiteWriterInvariants`), `src/Ui/Controller/SellingModeController.php` (new); changed:
`src/Availability.php`, `src/ChannelAdmin.php`, `bin/channel_set.php`, `src/Receiving/{GoodsReceiptHandler,SellingModes}.php`,
`src/Catalogue/{ItemCards,ItemCardCsv,ItemCompliance}.php`, `src/Settings.php`, `src/Schema/Grants.php`, `src/Auth/Permissions.php`,
`src/Invariants.php`, `src/Ui/{Kernel.php,Controller/ItemController.php,views/item.php,views/item_cards.php}`; tests
`tests/Unit/SiteViewTest.php`, `tests/Integration/SiteWriter/{SiteWriterFeedTest,ReceiptModesTest}.php`, `tests/Integration/Migration0018Test.php`,
`tests/Integration/UiKernel/SellingModeScreenTest.php`, and updates of `ChannelSetToolTest`, `GrantsTest`, `PermissionsTest`,
`ApiFeedTest`, `UiSecurityTest`, `ItemCardScreensTest`.

**I148. Design in one paragraph.** Every listing view of the feed (`/v1/changes`, `/v1/snapshot`, `/v1/availability`) carries a
`site` block: what the site stock writer writes for that listing on that site (`SiteView`). It is empty (`writer` false) until the
site's switch in CW is on (`channel.site_writer`, I149; off by default, I-Day turns it on) and for a listing that is not linked.
When on, a counted item follows its policy on every site (§7.4), a legacy item takes the selling mode CW set for that one site
(`item_channel_mode`, I151: the switch on the item page, I158, or a receipt for the receipt sites, I152) or keeps the site's own,
and a blocked item is Out-Of-Stock (I160). Each change that moves a block writes a feed row, so the site's version guard carries it
(I153); a changes page also names the movements behind it, so the site's stock log can say "goods in GRN-000045 +24" (I154).

**I149. The per-site switch (`channel.site_writer`, 0018).** `TINYINT(1) NOT NULL DEFAULT 0`, CHECK 0/1: every site starts off and
stays off on staging until the owner says (I-Day). `php bin/channel_set.php --code=<site> --writer=on|off [--actor=] [--apply]`
(ChannelAdmin::configure, A14 extended): a dry run unless `--apply`; prints `site_writer: off -> on`; warnings: what turning it on
does, "the channel is <mode>: nothing is written on the site until it is live", and what turning it off does (the site keeps the
last figures and its own screens are the writers again). With `--apply`: the channel row FOR UPDATE, the UPDATE, audit
`channel.site_writer` {from, to, by}, then LAST one channel-wide feed row (`Stock::channelChanged(..., 'site_writer')`, after the
channel row like `assignSellableWarehouse`, D39): the site re-snapshots and every `site` block changes at once. A usage error for
anything but on/off (exit 2). *Why a CW switch as well as the site's own* (SC6): either side alone can stop the writer, as for the
mode (§6.2), and a switch at CW stops every site's writer from one place.

**I150. The `site` block (`SiteView::rule`, pure; `SiteView::blocks` reads the switch, the per-site rows and the item cards).**
`writer` (bool), `why`, and when `writer`: `qty` (`floor(available / u)`, the feed's own figure; negative for a backorder item's
"arrange" signal), `mode` / `backorders` (the site's `prodt_stock_mode` / `prodt_allow_backorders`; `null` = leave the site's),
`low_stock_threshold` (`null` = leave the site's), `meaning` (CW's meaning of the label, IM10 "with CW's meaning shown beside each").
The rules, first match wins: blocked by its item card → Out-Of-Stock, 0 (`blocked`); a quarantined listing → Out-Of-Stock, 0;
policy stopped → Out-Of-Stock, 0; strict → From-Warehouse, 0; backorder → From-Warehouse, 1 (plan §6.5); legacy with a per-site mode
→ that mode, backorders left (`site_mode`; ERPNext never set the flag either); legacy without one → the mode left, the quantity
written (`site_own`). `writer_off`: the switch is off (one query per page, nothing else read); `unlinked`: not mapped or quarantined.
An unknown variant of `/v1/availability` answers `unlinked`.

**I151. Per-site modes (`item_channel_mode`, `item_channel_mode_log`, 0018).** One row per (item, site) CW has set: mode, the previous
mode while Out-Of-Stock (CHECK, as I137), the low-stock threshold (≤ 100,000 or NULL), version, source (`switch` | `receipt`, CHECK:
a receipt names its document), who, when; every write one log row (version, before / after, threshold before / after, source, the
switch's reason or the receipt's document and line). NO_DELETE / APPEND_ONLY for the app login (Grants); no FK to `sku` or `channel`
(written inside receipt postings before their stock locks, the I127 reason); W1–W2 check them (I163). *Why per site:* IM10 "mode is
per site for legacy items ... so Electrofag's In-Stock items keep selling as they do today until they are protected".

**I152. A receipt's mode lands on the receipt sites (`site_writer.receipt_mode_sites`, provisional).** A string setting of channel
codes, `vapeandgo` (I136: "apply a receipt's mode to Vape and Go only until the owner says otherwise"); `Settings` gains a `pattern`
rule for it (I161). `GoodsReceiptHandler::post` step 4 calls `SiteModes::fromReceipt()` after `SellingModes::write()`: for each
receipt site and each item of the receipt, the row becomes the receipt's mode (source receipt, its threshold kept), logged with the
document and line; the items whose mode changed get, LAST (after `bookForDocument`, so after the stock's own feed rows and with
nothing locked after), one feed row each (`Stock::skuChanged(..., 'mode')`). So a receipt that books nothing (every unit short,
refused or quarantined) still sends its mode, as an ERPNext invoice line does. A site not in the setting keeps its own mode.

**I153. Every change of a `site` block moves its version (D38).** The switch: a channel row (I149). A per-site mode or threshold: a
sku row `mode` (the switch, I158; a receipt, I152). A block: a sku row `card` (I160). The policy, the stock and the link: the rows
they already wrote. The values are read after the versions (Availability::views), so a block is never older than its version.

**I154. The movements behind a change (`site.moves`, `/v1/changes` only, writer on).** For the items of a changes page, the ledger
rows of the site's sellable warehouses created from 120 s before the page's first feed row to its last (a transaction's ledger rows
come before its feed row; `ix_stock_ledger_created`), at most 2,000 rows read, grouped by (type, document) — cancels, uncancels and
returns by type only — with their effect on availability (on_hand + / allocated and held -) and the newest ledger id (`last_id`), at
most 5 groups an item. The sale types the site logs itself are left out (`SiteView::SALE_TYPES`: reserve, release, expire, commit,
commit_release, ship, unship, opening, adopt). The window can bring a group again: the site logs a group once, by `last_id`
(SC9). Not on a snapshot (no window) and not while the switch is off (no cost).

**I155. A counted item: one policy for every site (§7.4).** Its mode on every site is its policy's (I150); a per-site row of it is
kept but unused, and the switch refuses it (409 `protected_item`: "change the policy instead").

**I156. The low-stock threshold.** Per site, on `item_channel_mode`: set by the switch (a whole number 0..100,000; empty keeps it),
kept by receipts, NULL = the site keeps its own. ERPNext's per-item "safety stock" → IM9's thresholds later (open).

**I157. The receipt's default mode honours the switch (amends I136).** `SellingModes::current()` takes the newest write of the item's
`item_selling_mode` row and its receipt sites' `item_channel_mode` rows (by `updated_at`; read FOR UPDATE with the rest under a
posting). So a switch to Out-Of-Stock on Vape and Go is what the next receipt brings the item back from (its previous mode there).

**I158. The selling-mode switch (the item page, `/ui/items/{id}`, "Selling mode on the websites").** Per website: its name, whether
its site stock writer is on and its channel mode, the item's linked listings there, the mode (+ back-orders) CW writes with CW's
meaning, the threshold, who set it (the switch or a receipt, when), and "receipts set it" on the receipt sites. For a legacy item
and `modes.set` (provisional, owner to confirm: purchasing_desk, purchasing_manager, stock_controller, manager; never admin) one
form: the mode (three labels, each with CW's meaning), the websites (ticked, or "All websites"), an optional threshold, a reason
(3–500 characters). `POST /ui/items/{id}/selling-mode` (`SellingModeController::set`) → `SiteModes::set()`: FormOnce; a stamp of
the rows the page showed (409 `selling_mode_changed` redraws the page with what was typed); 400 `bad_mode` / `bad_reason` /
`bad_threshold`; 422 `no_sites` / `unknown_site`; 409 `protected_item` / `merged_item`; 404 `unknown_item`. Only the sites whose
values really change are written (audit `selling_mode.set` {mode, sites, unchanged, threshold, reason}), then LAST one sku feed row.
A counted item shows its policy's mode and no form. *Permission name:* `modes.set` (permission names are `[a-z]+.[a-z]+`).

**I159. Locks (extends I143).** The switch: the item's `item_channel_mode` rows FOR UPDATE (the sku row is read without a lock: a
posting locks these rows, then balances, and `setPolicy` balances, then the sku row; a policy committing meanwhile only leaves the
row unused) → audit → the feed clock. A posting: `item_selling_mode` rows (the plan) → the receipt sites' `item_channel_mode` rows →
balances → value clocks → the feed clock (the mode rows of I152 after the stock's). `ChannelAdmin::configure`: the channel row →
the feed clock. No cycle.

**I160. A blocked item is written Out-Of-Stock (`ItemCompliance::selling()`, the 'sell' check of I103).** `SiteView::blocks` reads
`selling()` (= `blocked()`, per item instead of a refusal): a blocked item's listings are Out-Of-Stock on every site whose writer is
on, whatever their policy or per-site mode (the quantity is still written). `ItemCards` writes a sku feed row `card` whenever a
save, an accept or a confirmation changes the item's blocked rules (`ItemRules::status` before and after); the CSV import defers
them to the end of its transaction (`deferFeed`, `writeFeed`), so the global feed clock is held from there to the commit only.
`ItemCards::BLOCK_EFFECT` (and the item cards page) now say "A website whose site stock writer is on gets it written Out-Of-Stock by
CW; on the others take it off sale by hand" (I121 updated; every site is "the others" until I-Day).

**I161. The `pattern` setting rule.** `Settings::RULES[key]['pattern']` (a regular expression, `pattern_says` words the refusal):
`site_writer.receipt_mode_sites` = channel codes separated by commas, or empty.

**I162. Not done here, and why.** CW never writes a site's database (the site's worker does, SC6). No cost write-back (decision 12,
IM8). A legacy item's quantity is CW's shared figure (IM10 "as ERPNext does today"; the opening estimate is rebased at T0, D40a).
The other sites get a per-site mode only from the switch until the owner adds them to the receipt sites. The site writer for
Electrofag and Vape Big is I-6 ("IM10 (rest)").

**I163. Invariants W1–W2 (`SiteWriterInvariants`, called by `Invariants::check`: nightly, by the hammer and after every stock test).**
W1: each `item_channel_mode` row equals its newest log row (mode, previous, threshold, version, source, receipt) and each (item,
site)'s log versions are 1..n with a row behind them; W2: every row and log row names an existing item and site, and a receipt's a
GRN document.

**I164. Deviations from the brief.** The connector's figure is CW's plus this site's own held checkouts (SC7), not plan §6.5's
`floor(available/u)` alone: CW still sends `floor(available/u)` and the site adds its own holds back, so an abandoned checkout never
takes the last unit out of stock and back (with a back-in-stock email each time). The movements (I154) are a description only;
nothing is booked from them. The permission is `modes.set`.

**I165. Open items.** The owner: the receipt sites (I152), the switch's roles (I158), whether CW's per-site threshold or IM9's
should feed the site (I156). I-6: the writer for Electrofag and Vape Big (their ledger-based stock: the H5 gate in lib/sites.php),
per-site quantities (IM11 allocation). The writers the connector cannot hook (SC11) are corrected by its reconcile.

**I166. Tests (7 Oct 2026).** New: `SiteViewTest` (5), `SiteWriterFeedTest` (6), `ReceiptModesTest` (4), `Migration0018Test` (2),
`SellingModeScreenTest` (2), `ChannelSetToolTest::testTheSiteWriterSwitch`, `GrantsTest::testTheSiteWriterFlowAsTheAppLogin`,
`PermissionsTest::testTheSellingModeSwitch` (22 tests). The full suite, slot `sw1`: `OK, but some tests were skipped! Tests: 930,
Assertions: 19475, Skipped: 75` (24:53, run while other slots' suites ran). Slot `ui`: `UiAuthTest` (13), `UiReviewFlowTest` (14),
`UiSecurityTest` (11, the selling-mode route in its sweep): OK. Slot `api`, the seven `Api*Test`: 35 tests OK (`ApiFeedTest` asserts
the `site` block over HTTP; the envelope sorts keys, A1). The hammer (`--seed=20261007`, slot `sw1`): `RESULT: PASS (59 checks passed,
0 failed)`, 105 s.

## Site stock writer, site side (the Vape and Go connector 0.4.0, proto, 7 Oct 2026)

SC6–SC14 record how the connector (`App_proto/src/central_warehouse`, proto only, not committed) writes CW's `site` blocks into
Vape and Go. Code: `lib/writer.php` (new), `bin/cw_notify.php` (new), `sql/cw_connector_v3.sql` (new); changed: `lib/{feed,config,db,
sites,worker}.php`, `checkout.php` (H5 / H8), `admin_badges.php`, `bin/{cw_install_tables,cw_manifest,cw_status,cw_write_config}.php`,
`bootstrap.php` (0.4.0), `MANIFEST.json`, `src/app_modules/central_warehouse/cw_badges.js`, `tests/mock_cw/router.php`, the tests.
Hooks in the admin (one line each, `// CW connector hook [Hn]`): H11 `app_config/modules/order_stock.php`, H12
`app_config/modules/products.php`, H13 `src/app_modules/products/ajax/bulk_update_stock.php`, H14a-c
`src/app_modules/orders/ajax/restock_and_cancel_items.php`, `app_config/modules/refund_service.php`, `app_config/modules/order_return.php`.

**SC6. Two switches and live.** The worker writes only while the site's own switch (config `site_writer`, off unless set with
`bin/cw_write_config.php --site-writer=1`) AND CW's switch (the view's `site.writer`) are on AND the effective mode is live. Off and
shadow write nothing; turning either switch off leaves the last written values (nothing is restored by itself: the listing's mode and
back-order flag before CW first wrote them are kept in `cw_listing_state.pre_cw_*` for a person).

**SC7. The figure.** `prodt_stock = site.qty + this site's unpaid checkouts CW holds (cw_order_state held, no commit queued, younger
than hold_ttl_sec, default 2,400) - this site's paid orders CW never held and has not acknowledged (ever_held 0, commit pending or
dead: plan §5)`. So the site's figure keeps today's meaning (it falls when an order is paid), and CW still refuses a strict item's
reserve when short. H5 / H8 skip the site's own decrement for a writer listing whose units CW holds or committed (state held or
committed), and record it (`cw_decrement_skip`); an unreserved order is decremented by the site as today.

**SC8. Versions and what is written when.** The feed stores the `site` block under the version guard (`cw_feed_apply`; a malformed
block counts as not the writer's) and sets `writer_due`. Each worker loop (`writer` step, after the feed; at most 500 listings and
1.5 s a pass): the due listings; the listings this site's own orders moved (cw_order_state changes, 5 s after them, so the order's
own Stock-Out line exists); and every `writer_reconcile_sec` (900) every writer listing, a page a pass, so a writer outside CW (an
SQL script, the Mobile app's mode UPDATE) is corrected. Each listing: one short transaction on the connector's own connection (the
site's row FOR UPDATE; only what differs is written; `writer_due` cleared only when no newer view arrived meanwhile).
`--dry-run` reports the differences and writes nothing.

**SC9. The stock log (the Mobile app's stock screens).** Every write that changes something adds one `inventory_stock_log` line on the
variant's (default) SKU: Stock-In / Stock-Out with the change, or Stock-Mode 0 when only the mode, back-orders or threshold changed;
process type `cw_site_writer`; added by `writer_member_id` (1, as the ERP stock worker); the default warehouse. The description:
"Central Warehouse CW-000123: stock 0 -> 24 (+24): goods in GRN-000045 +24; mode Out-Of-Stock -> In-Stock (sold whatever the figure);
low-stock threshold none -> 3 [CW feed v301]" (a reconcile adds "corrected: ... changed outside CW"). A paid order's own Stock-Out line
is not logged again: the writer's line leaves out the sales of `cw_decrement_skip` whose commit is queued (absorbed once), and writes
no line when the sale is the whole change. So the site's lines add up to the figure's change since the writer took over.

**SC10. Back in stock.** After a write, a listing that went from Out-Of-Stock to In-Stock by the site's own `stock_check()` rules
(quantity or mode, whichever changed), with someone waiting (`product_stock_notify` psn_status 0), goes to the site's own path:
`bin/cw_notify.php` (a process of its own that loads the admin as its CLI jobs do, checks the database pins, and calls
`order_stock::email_stock_notify()`). On the Vape and Go proto tree that function is a no-op since 5 Oct (the grouped sweep sends; it
is never scheduled on proto), so the writer sends nothing on proto; on live the same call sends as today. EMAIL SAFETY:
`--only-test-addresses` refuses (exit 4, nothing called, no address printed) when one waiting address is not a test address; the tests
stub the path (`writer_notify_hook`) or run it on fake variants whose waiting list is proved to hold test addresses only.

**SC11. The site's local stock writers (plan §6.5).** H11, the first statement of `order_stock_add()` (every quantity path: the stock
screens, `update_stock()`, the bulk editor, `sku/update_stock.php`, `inventory_stock_scan`, the Mobile app, the ERP stock worker, the
sheet script): a writer listing is refused (ErrorException "This item's stock is kept by the Central Warehouse (CW-...): change it in
CW (<ui_base>/ui/search?q=CW-...)"), unless the caller passes `cw='event'`, which skips the local write. H14a-c pass it on the three
restocks the unit sweep reports to CW (a line cancel with restock, a refund with restock, a return received). H12 (`products::edit`)
keeps a writer listing's mode, back-order flag and threshold. H13 refuses a bulk-editor row that changes a writer listing's stock, mode
or cost. Each answers "not managed" in off and shadow and on any error. Not hooked (corrected by the reconcile, listed in
lib/sites.php's gates): the selling-mode UPDATEs of `inventory_stock_scan/ajax/update_stock.php`, the Mobile app and the ERP endpoints
(web-blocked at I-Day). A return or restock of an order CW does not know (paid before the site's T0) is skipped locally by H14 and has
no CW event: it is booked in CW (an adjustment, IM2) — open item.

**SC12. Read-only fields in the admin (proto).** The badge data carries `writer` (the writer active and CW's feed says writer); the
variant page then disables the selling-mode radios, the back-order switch and the low-stock threshold, says "Stock, selling mode,
back-orders and low-stock threshold are kept by the Central Warehouse" and shows "Open in CW" (CW's search for the code). H12 keeps the
fields on the server too.

**SC13. Deploy order and what is not committed.** SQL v3 (`bin/cw_install_tables.php`; applied on the proto database 7 Oct 2026) →
code → config. `order_stock.php` and `products.php` carry other people's uncommitted work: their hook lines are in the working tree
only (pre-hook copies in `/root/cw_backup_20261007c/`), never committed by this task; the static test compares each hooked file minus
its hooks with git HEAD, or with that copy. MANIFEST.json (0.4.0, 20 hooks) is regenerated and verifies.

**SC14. Tests (7 Oct 2026).** Connector, `php tests/run.php` (mock CW, proto database). New: `writer_core_test.php` (7),
`writer_hooks_test.php` (4), `writer_email_test.php` (2: EMAIL SAFETY, and the I-3 acceptance: a fake variant Out-Of-Stock → In-Stock
12 through the writer, the site's own path run for real, the sweep's email written to a temporary outbox for the test address only),
all 13 green; updates of the install, office (H5 / H8), static and manifest tests. The full run before 12:37 UTC: 202 passed, 2 failed
(both since fixed or foreign). At 12:37 UTC another session merged origin/master into Website_proto (team commit 36f7314e8 had
reformatted the storefront hooks H1–H5 into multi-line blocks, behaviour unchanged): the 19 checkout tests that evaluate or anchor
those one-line hooks fail since (185 passed, 19 failed in the last run; cleanup OK), which is outside this task (the hooks need
flattening again, or the tests a multi-line reading; open). Fixtures: fake variants (Draft, hidden, own product and SKU), test
sign-ups (example.com only), the writer's lines and skips, all removed by the run's cleanup. A fake variant's new id can collide
with a row of the connector's feed cache (`cw_listing_state` holds the LIVE site's variant ids, which run past proto's): the
fixture now puts such a row aside and restores it exactly; before that fix the test runs of this task deleted 86 cached rows
(prodt_id 45619–45704, live-only variants that do not exist on proto: no effect on proto, recreated by the next snapshot of the feed).

## Inventory Phase I-3, the reviews of IM6 and IM10 (slot `rcv7`, 7 Oct 2026)

Three reviews of the uncommitted IM6 + IM10 tree (receiving, the Vape and Go connector 0.4.0, the receiving screens) found fix-first
problems; this section records what was changed (I167–I178 for CW, SC15–SC22 for the connector, now 0.4.1), what was not, and why.
Earlier entries are amended where they say otherwise: I126 / I141 (the bench's findings and the screens), I129 / I132 (an unstamped
delivery's damaged and over units), I130 (one invoice copy), I136 / I152 (no mode from a receipt that accepts nothing), I138 (the
bench confirms the arrival), I140 (a case barcode and a supplier code that disagree), SC7 / SC10 / SC11 / SC12 (the writer).

**I167. An unstamped delivery's damaged and over units are unstamped too (amends I129, I132; review probe C).** On a line that needs a
duty stamp, when the stamp is not on the outer pack (`stamp_on_pack` 0) or some of its units are refused or quarantined as unstamped,
its damaged and over units follow those units: refused at the door, or quarantined in UNSTAMPED (quarantined when the bench counted
no unstamped unit because nothing undamaged arrived); never VERIFY as ordinary stock. Units accepted on the pre-October evidence keep
I129 (legal stock until 31 Mar 2027). Wrong-item units are another item: VERIFY, whatever the stamp. `ReceiptMath::split()` takes the
line's `stamp_required` and `stamp_on_pack` and returns `extras`; the damaged and over incidents carry that disposition and
`detail.unstamped`; G5 recomputes it (`ReceiptMath::extras`). Fail closed on a partly unstamped line: its damaged and over units may
have been stamped, but nothing can tell, and holding unstamped duty stock is the offence (§6.1). The bench also refuses "no stamp on
the pack" unless every unit that arrived is counted unstamped (it was only a posting problem).

**I168. The bench check covers what is posted (amends I126; review probes B and G).** Only `bench()` (audited `grn.bench`) writes the
bench's findings. `saveDraft()` ignores any it is sent: a line keeps the findings and the check time of the stored line it came from
(`line_no`, which `lines()` now returns) only while its item, supplier item, units per pack and packs are unchanged; a line added, or
whose quantity or item changed, starts unchecked. Posting refuses `line_not_checked` while any line is unchecked ("Waiting for the
goods-in bench: lines 1-20 not counted yet": one problem, not one a line; while the paperwork question is unanswered it is folded
into `bench_check_required`). A line is checked when the bench service is given it (`$lines` = the lines counted now; `checked` =>
false records its answers but takes the count back). The bench screen counts a line when its "Counted" box is ticked or one of its
answers changed, so saving the paperwork answer no longer marks every line on the page checked. The header's "paperwork credible"
is not reset by a desk change (it is about the supplier and the paperwork, not the count; the line check carries the count). The
editor's notice says which lines' checks a save cleared.

**I169. A receipt sets the selling mode only of what it accepts (amends I136, I152; reviews: probe F, the connector review).** An item
the receipt accepts nothing of into MAIN (all short, refused unstamped, quarantined, damaged) keeps its mode: no `item_selling_mode`
write, no `item_channel_mode` write, no feed row; its lines record `mode_source` 'kept' with the mode it kept (`selling_mode` NULL
when none was known; 0017's CHECK amended). So an Out-Of-Stock item never goes back to In-Stock with 0 units, and the site's
back-in-stock e-mails never fire for goods that were not accepted. A mode chosen on such a line is ignored, with a warning. Mode
conflicts between lines of an item are checked only for an item the receipt accepts something of. The posting's writes use the
posting's clock (`SellingModes::write`, `SiteModes::fromReceipt` take `$now`; `current()` compares those times).

**I170. When the goods arrived (amends I138).** The bench page says which arrival date the duty rule uses ("This delivery counts as
received on 7 Oct 2026 09:30 (UK)") and, when that is not today, offers "the goods arrived now, not on <day>" (`bench()` header
`arrived_now`: `received_at` and the document date become now, recorded in the `grn.bench` audit). The bench moves the time only
forward (a receipt keyed from the invoice before the goods came); backdating stays the desk's, with its reason. Posting refuses
`checked_before_arrival` when a bench check is more than 5 minutes older than the arrival time (the time was moved later since).

**I171. One invoice copy, one live receipt of the supplier (amends I130).** `attach()` refuses (409 `invoice_copy_elsewhere`) a supplier
invoice file (FileStore deduplicates by content) already attached as the supplier invoice of another live receipt of the same
supplier; the plan refuses the posting while that is so (a copy attached past the check, two people at once). The invoice key's
treatment of `-` `/` `.` stays as I130 (owner question, I176).

**I172. Someone who did not key a receipt sets its supplier invoice.** `setInvoice()` (`POST /ui/receiving/{id}/invoice`, `doc.GRN.post`,
the read-only page of a draft for anyone but its creator): the invoice number (one live receipt per supplier and number), its date and
the delivery note; version + 1; audit `grn.invoice`. A receipt the bench started from the delivery note is completed by the desk this
way; the lines stay with their keyer (the review's "creator", I19). A person who set the invoice of a receipt they did not key never
reviews it (`ReviewInvolvement` now covers `grn.bench` and `grn.invoice`; G8 likewise). *Not done:* "take over a draft" (moving
`document.created_by`): the app login may not change a document's creator (Grants), by design of I17 / I19; owner question (I176).

**I173. A barcode counts the units it stands for (amends I140; the blocker of two reviews).** Scanning on a receipt
(`GoodsReceipts::addLine` → `scanned()`; the PO editor's `PurchaseOrders::resolve` is unchanged): a barcode of k units (its
`units_per_scan`: 1 for a unit's own barcode) takes this supplier's supplier item of pack k (one pack); else the largest of its packs
that divides k (k / pack packs: a case of 10 = 10 singles or 2 packs of 5); else, when the supplier sells it only in packs that do
not divide k, a line in packs of k without a supplier item ("a case of 5, not this supplier's packs of 10: check the price"); no
supplier item at all, packs of k. One unit scanned when the supplier sells only bigger packs is ambiguous (a bottle, or a box?): the
receipt's own line of the item when there is exactly one, else `choices` asks once (one of its packs, or single units). The sheet
import refuses ("pack differs") a row whose case barcode and supplier code name different packs; a case barcode alone counts its
units (unchanged). `addLine`/`addChoice` take a pack price typed with the scan; the notice names the line, the item and the units
added ("Line 1, CW-000123 ...: +5 units (now 4 packs of 5 = 20 units)").

**I174. Over units beyond the line are confirmed at the bench.** More units over than the line has (a typo is likelier than a delivery
twice the invoice) are refused (`over_unconfirmed`) unless the bench ticks "the over count is right", or the same figure was already
recorded; the plan then warns. They stay in VERIFY at the line's cost: whether over units are valued provisionally is IM7's (open).

**I175. The desk and the bench do not throw away each other's work.** Both forms carry a stamp of what they show: the editor
`GoodsReceipts::deskStamp()` (the header and every line's own fields, not the bench's), the bench `benchStamps()` (the checklist and
the arrival time, each line's item, quantity, findings and check time). A save refused for the version (the other side saved first)
is saved again on the current version when the stamp is unchanged (the other side changed nothing this form holds: a bench answer
under the desk's price change, a price under the bench's count); otherwise the page is redrawn with what was typed (the bench: except
on the lines whose item or quantity changed, named in the message), nothing saved. The bench no longer loses its typed findings to a
desk scan.

**I176. Owner questions (new).** (1) A receipt against a PO closed after its posting is neither reversed nor rejected at review (409
`po_closed`, I145): the review box says so and offers no reject; keep "refuse", or record the rejection without booking and correct
by an adjustment? (2) Should the invoice key also drop `-` `/` `.` ("INV-001" = "INV001", I130)? (3) Hand-over of a draft's lines to
another person (needs a creator change the design forbids today), or is setting the invoice enough? (4) Over units valued at the
line's cost until IM7, or at £0? (5) A quarantined legacy listing: the site writer takes it off sale (I150), while CW keeps taking
its orders (R8, `Reservations` never refuses a legacy line): which? (none is quarantined on proto today). (6) The roles (I126, I141,
I158) and the provisional settings, as before.

**I177. The screens (review of the receiving screens).** The editor's invoice number is no longer `required` (a scanner's Enter was
blocked by the browser on a receipt started without one; posting still requires it); Save and Add carry `formnovalidate`. "Before
posting" is shown once (a refused post points to it); a one-line status ("Ready to post" / "3 things to settle") sits by the post
button in a sticky save bar; the waiting-for-the-bench state is one neutral problem and a "waiting for the bench" tag, not red errors
on every line. Errors name the screen's fields ("Line 3, short: ...", "the unstamped units"), not columns, settings or decision
numbers. The bench: "Find a line" (scan to jump to the card), "Every duty line here: stamp on the pack, digital" and "Tick every line
as counted" (they only fill the form), the stamp code's Enter moves on instead of saving, the unstamped action and evidence appear
only with unstamped units, "2 boxes of 10 = 20 units", a sticky save bar, the redirect back to the last card saved. Leaving a page
with unsaved edits through another form (attach, import, copy, cancel) asks first; a camera photo over 2 MiB is made smaller in the
browser (`createImageBitmap`: no blob or data URL, the CSP stands), anything else too large is refused before upload. Dates as
"1 Jan 2027", UK times only on the receiving pages (`$uk` view helper); the bench list says "Check: SUPPLIER, invoice ..."; the mode
column says which websites a receipt's mode reaches and whether their writer is on; "receive all as ordered" follows the PO chosen;
the incident page says stock control documents are Phase I-4. *Not done:* a fetch-based add without a page reload, sounds, a
per-row stacked editor below 1,100 px (the table's selects shrink instead), raising the UI pool's 2 MiB upload limit (a deploy change;
the browser shrinks photos instead).

**I178. A forced Out-Of-Stock ends (review).** `SiteView::FORCED` = blocked, quarantined, stopped. When one ends on a legacy listing CW
leaves the mode to (why site_own, mode null), the value to leave is not the Out-Of-Stock CW wrote: the connector keeps the mode and
back-order flag the force overwrote and puts them back (SC16). CW cannot: it never knew the site's mode. A listing with a mode CW set
for the site gets that mode back from CW itself.

**I179. Tests (7 Oct 2026, slot `rcv7`).** New: `ReceiptMathTest` (the extras), `GoodsReceiptLifecycleTest::testABarcodeCountsItsOwnUnits
WhateverTheSuppliersPack`, `GoodsReceiptRulesTest::{testTheBenchCheckCoversWhatIsPosted, testOverUnitsBeyondTheLineAreConfirmedAtTheBench,
testTheSameInvoiceCopyIsReceivedOnce, testAnotherPersonSetsTheSupplierInvoice, testTheBenchSaysWhenTheGoodsArrived}`,
`DutyStampTest::testAnUnstampedDeliverysDamagedAndOverUnitsAreUnstampedToo`, `ReceiptModesTest::testAReceiptThatAcceptsNothingKeepsTheMode
AndTheNextOneBringsItBack` (replaces the all-short test that asserted the opposite), `SiteViewTest::testAForcedOutOfStockEndsInTheSitesOwn
ModeOrCwsMode`, `ReceivingScreensTest::testTheDeskAndTheBenchKeepEachOthersWork`; updated: the lifecycle, rules, duty and screens
tests (one "waiting" problem; the bench counts lines; the new notices), `UiSecurityTest` (the invoice route), `UiTemplatesTest` (the
`uk` helper). The full suite, slot `rcv7`: `OK, but some tests were skipped! Tests: 939, Assertions: 20359, Skipped: 75` (19:02).
Slot `ui`: `UiAuthTest|UiSecurityTest|UiReviewFlowTest` `OK (38 tests, 18939 assertions)`. Slot `api`: `--filter 'Integration\Api'`
`OK (45 tests, 2851 assertions)`. The hammer (`--seed=20261008`, slot `rcv7`): `RESULT: PASS (59 checks passed, 0 failed)`, 150 s.
The matching golden tests: `{"passed":59,"failed":0}`. `app.js` (and the connector's `cw_badges.js`) parse (esprima 4.0.1); no
JavaScript engine runs on either box, so their behaviour in a browser is to be confirmed on staging.

## Site stock writer, site side: the review fixes (the Vape and Go connector 0.4.1, proto, 7 Oct 2026)

Code: `lib/writer.php`, `lib/config.php`, `lib/sites.php`, `lib/db.php`, `bin/{cw_write_config,cw_status,cw_install_tables,cw_manifest}.php`,
`sql/cw_connector_v4.sql` (new; applied on the proto database 7 Oct 2026), `bootstrap.php` (0.4.1), `MANIFEST.json`,
`src/app_modules/central_warehouse/cw_badges.js`, the tests (`tests/writer_review_test.php` new; `checkout_helpers_test.php`,
`checkout_static_test.php`, `checkout_office_test.php`, `writer_hooks_test.php`, `core_install_test.php`, `sweep_manifest_test.php`). Hook
lines changed (uncommitted, in files outside the connector): H14a-c pass the unit (`cw_ordi_id`); copies of the 0.4.0 lines in
`/root/cw_backup_20261007c/<path>.with-0.4.0-hooks`.

**SC15. The H5 / H8 skip only while the writer runs; the unwind (amends SC7; review: worker liveness).** `cw_writer_skip_decrement`
skips the site's own decrement only while `writer.last_pass` is under `CW_WRITER_FRESH_SEC` (30 s); every active pass now records it,
an empty one too. A stale writer (the worker down, lagging, stopped): the site decrements as today (the writer's next write then
changes nothing; `decrement_skip_stale` logged). A skip no writer will absorb any more (the writer not active, the listing no longer
the writer's or outside `writer_only`), older than 60 s and with its commit queued, is applied to the site's figure by
`cw_writer_unwind` (each worker pass, whatever the switches; the order's units of the listing from its own Stock-Out lines, else its
live order lines; no stock-log line: the order's own line describes the sale; `writer_unwound`). A write that includes a sale now
marks every reflected skip absorbed, with or without its Stock-Out line, also when the figure did not change (an offsetting move), so
the unwind never applies a sale twice.

**SC16. A forced Out-Of-Stock puts back what it overwrote (CW I178; SQL v4).** `cw_listing_state.forced_from_mode`,
`forced_from_backorders`, `forced_at`: when the writer writes a view whose `why` is blocked, quarantined or stopped and no force is
recorded, it keeps the site's mode and back-order flag first; when the view is the site's own again (site_own, mode null) it writes
them back once and clears the record ("the mode it had before CW took it off sale is put back" in the stock-log line); a mode CW sets
for the site clears the record. A site mode that was NULL before the force is not restored (the site's columns hold a mode).

**SC17. Back in stock, safely (amends SC10; review: proto e-mail safety).** On a proto tree (a non-empty `ref_prefix`) or with config
`writer_notify_test_only`, `bin/cw_notify.php` always runs with `--only-test-addresses` (`cw_writer_notify_cmd`): a real address
waiting means nothing is called. Back in stock needs In-Stock on CW's own figure (`site.qty`) as well as on the written one, so a
figure only this site's holds make positive never e-mails anyone. The dry run lists the Out-Of-Stock -> In-Stock flips and their
waiting sign-ups (`would_notify`). The notify still runs once per flip and is not retried: the grouped sweep must be scheduled on the
live site before I-Day (a gate).

**SC18. `writer_only` (config; `bin/cw_write_config.php --writer-only=`).** At most 200 variant ids the writer may touch; empty = every
writer listing. CW's switch is channel-wide, so without it the rehearsal's first pass makes every linked proto listing (about
14,800, including about 1,200 rows that name live-only variant ids a proto variant could take over) a writer listing. Outside it a
listing is not the writer's for the hooks either.

**SC19. The local writers (amends SC11; review).** H11: a write of 0 units is no write (the bulk editor's unchanged rows,
`update_stock()` of the same figure); `cw='event'` is honoured only for an order CW committed (the unit sweep reports its cancel or
return), found through the unit H14a-c now pass; a restock of an order CW does not know is refused ("book the units that came back
in CW (a stock adjustment), then do this again without restocking"), so nothing is marked restocked without a stock movement. H11
and H13 refuse, and H12 keeps CW's fields, when the writer is active but the connector's own connection fails (`cw_writer_managed(...,
strict)`). H13 refuses only a real change of the row (the editor sends every row with its current values). The badges keep a hidden
copy of each locked field's current value (a disabled field is not sent, and the variant save turned a missing back-order switch into
0). Not hooked, listed in the gates and the I-Day checklist (ops.md): `api/stock_reconcillation.php`, `api/stock_reconcile.php`,
`api/erp_stock_pull_sync.php`, `api/erp_mode_sync.php` (the last two CLI-capable: their crons off at I-Day).

**SC20. The writer only on a site that carries its hooks.** `cw_writer_active()` also needs the site profile (by the config's pinned
schema) to declare `writer_hooks`: Vape and Go. A config switch alone never starts it on Vape Big (its ledger stock) or Electrofag.

**SC21. A listing the reconcile fights over.** Corrections by the reconcile are counted per UK day (`cw_meta writer.corrections`); a
listing corrected 3 times is logged once (`writer_fighting`) and listed by `bin/cw_status.php` (`writer.fought_over_today`).

**SC22. Tests (7 Oct 2026).** `php tests/run.php` (mock CW, proto database): the 19 checkout tests that failed since the storefront's
reformat of H1, H2 and H5 into blocks (team commit 36f7314e8) read a hook as the block from its opening line to its marker
(`cw_tc_hook_span`), the anchors from its first line; new `writer_review_test.php` (5: the skip and the unwind, the forced mode put
back, back in stock and the dry run and the proto flag, `writer_only` and the site profile, the fought-over listing); `writer_hooks_test`
(H11's committed-only restock and the write of 0, H13's unchanged row, the hook lines' unit key); `core_install_test` (v4).

## Staff screens in plain words and design A, steps 1-3 (slots `uir1`, `ui`, 7 Oct 2026)

Code: `src/Ui/{Words,Tabs,Html,View,Context,Kernel,FormOnce,Queries}.php`, `src/Auth/Permissions.php` (menu only),
`src/Ui/views/{layout,error,login,password,home,dashboard,settings,cards}.php`, `src/Ui/Controller/{Auth,Dashboard}Controller.php`,
`public/ui/assets/{app.css,app.js}`; tests `tests/Unit/{Words,UiTemplates,UiUnit,Permissions}Test.php`, `tests/Support/{KernelUiTestCase,
UiResponse}.php`, `tests/Integration/UiKernel/MenusTest.php` and the words, menus and codes the other screen tests pin. Sources: the
wording plan `/root/cw_work/ui_clarity/plan.md` (steps 1 Foundation, 2 Frame, 3 Errors and sign-in) with the owner's corrections of
7 Oct, and the owner's design decision "A with B's parts" (`/root/cw_work/ui_design/compare.md`). Words, layout, help and the frame
only: `Permissions::MAP`, the routes, every service and its messages are unchanged; no behaviour item of plan §8.6 is in it.

**U25. One wording file: `Ui\Words` (plan §1, §8.2).** Constants and pure functions only, keyed by the internal code: jobs (`ROLE`,
`ROLE_PLURAL`, `ROLE_HELP`, `ROLE_GROUP`), the menu (`SECTION`, `MENU`, `MENU_HELP`, `TAB`, `BADGE`, `COMING_LATER`), page titles and
the 46 page intros (`PAGE_TITLE`, `PAGE_INTRO` as [what it is, what to do]), the bands and the matching evidence (`BAND`,
`BAND_TITLE`, `BAND_HELP`, `LANE`, `AI`, `FLAG`, `VETO`, `BAND_REASON`, `FIELD_STATE`), decisions (`NEEDS_SECOND`, `ACTION`,
`DECISION_STATE`, `LISTING_STATUS`), stock (`POLICY`, `STOCK`), the spot check (`SAMPLE_STATE`, `SAMPLE_RESULT`, `SPOT`), buying
(`PO_STATE`, `PO`, `SEND_VIA`, `REASON`, `SUPPLIER_STATUS`, `CHECK_REASON`, `CHECK_KIND`, `REVIEW_STATE`, `TASK_STATE`, `DOC_STATUS`,
`REORDER_FLAG`, `REORDER`, `SETTING`), errors (`ERROR_TITLE`, `ERROR`), the eight "?" texts (`HELP`), sign-in and password
(`SIGN_IN`), the frame's own words (`UI`) and the chip tone of each status (`TONE`). `of()` falls back to the code made
readable; `WordsTest` fails on any code of the system without a word (bands, roles, document, PO and supplier states, review
reasons and states, sample states, actions, decision and listing states, sell policies, buckets, lanes, AI outcomes, soft flags,
vetoes, reorder flags, menu keys) and on any word that breaks the writing rules (a snake_case code, "UTC", a phase code, a
server command or docs path, a second account). Most groups are used by steps 4-8; they are here now so every step takes its
words from one place. `Permissions::DESCRIPTIONS`, `StaffIdentity::rolesLabel()/rolesPhrase()` and `Document::label()` stay for
services, CLI tools and file names.

**U26. Provisional words (owner to confirm, plan §9).** The six band names Strong match / Likely match – check it / New product /
Not sure – you choose / Clues disagree / Renamed range; website product, warehouse product, match, Matching lead, second OK,
Reviewer; "TEST SYSTEM: nothing here is real"; "Coming later" with no dates. The person to ask is a fixed name, `Words::ASK` =
"Fazil" (plan §9 question 3 is open: reading the active admin's name would also name the owner, whose account holds Admin today).
The owner's corrections are applied over the plan: (a) the owner's account keeps Admin + Reviewer + Matching lead and every page
shows the strip "Your Reviewer and Matching lead jobs are switched off because this account also has Admin. Ask Fazil to take Admin
off this account."; no word anywhere suggests a second account (spot check owner-1 belongs to this account, and another account
deciding a member fails it); (b) "count" is only for shelf counts: legacy = "Website uses its own stock (not linked yet)", strict =
"Website sells warehouse stock only", protected_sku = "Website already sells warehouse stock", counted_item = "Stock was counted in
the warehouse"; (c) Strong match = "Same barcode, or copied from the other website, and the AI agrees", and Clues disagree also
names a rule against the barcode's product (`veto_on_key`); (d) card_blocked says the product is still on sale and must be taken
off by hand; (e) the reorder demand text; (f) `Words::PO['confirm_over_limit']`: an order over the approval limit is not
"confirmed" by its button, it goes to a reviewer first (used by step 7). compare.md §2.1: `Words::SPOT` holds the second step of
"Not a match" ("This stops the bulk link for good.") for the spot-check screen of step 5.

**U27. View helpers (plan §8.2).** Besides `$e $n $dec $dt $pct $u $partial`: `$word($group, $code)`, `$money` ("£10,500.00"), `$day`
("7 Oct 2026") and `$when` ("7 Oct 2026, 10:26": UTC converted to Europe/London, never "UTC"; a DATE is shown as it is), `$jobs`
("Admin · Matching lead (off)"), `$chip($tone, $text)` and `$stateChip($group, $code)` (one status chip: an icon shape and a word, tones
needs / done / waiting / blocked / info / off), `$intro($page, $lookOnly)`, `$explain($key)` (the "?": a `<details class="help">`, no
script), `$cards($list)` (task cards: B's job number, a chip, a count, a progress bar, "What happens:", one button; partial
`cards.php`) and `$empty($title, $text, $href, $button)`. The plan's `$w`, `$roles`, `$status` and `$help` were renamed: templates
already use `$w`, `$roles`, `$status` and `$help` as variables (a helper silently replaced them). `View::render` now refuses a
template variable named like a helper.

**U28. The frame: design A with B's parts (owner decision 7 Oct).** A left sidebar on a laptop (900 px and up: brand, the account
button, the find box, the menu); on a phone a short top bar (brand, "Menu", the account button), a bottom tab bar and the whole menu
as a sheet opened by "Menu" or "More" (`nav.menu:target`: no script). The tab bar is To do + up to three of the person's own items
in a fixed order of daily work (`Ui\Tabs`: the owner gets To do / Matches / Duplicates / Orders / More, a buyer To do / Orders / To buy /
Suppliers / More), with a lifted section's items first (an admin: Staff; a stock controller: Products, Barcodes). The account panel
names the person, their jobs in words and has "Change my password" and "Sign out". A skip link comes first. On staging (`app.env`
`environment=staging`, the key `TestRefPurge` already reads; `Kernel` reads it once per request, a config it cannot read says no) every
page, the sign-in and error pages too, carries the strip "TEST SYSTEM: nothing here is real". An account whose jobs Admin switches off
gets the yellow strip of U26 (a) with its "?" (`Permissions::switchedOff()`, pure, from `effective()`). The pinned menu markup is
kept (`nav.menu[aria-label=Main] > div.menu-group > span.menu-label + a`), the tab bar is a second nav after it ("Main tasks"). During
the forced password change there is no menu, no tab bar and no find box: every link led back to the password page (plan F066).

**U29. The task-based menu (plan §2.1-2.2).** Home first for everyone, then To check (Waiting for me), Match products (Products to
match, Waiting for 2nd OK, Spot check, Possible duplicates), Buying (What to buy, Purchase orders, Suppliers, Sales data), Products
(Product list, Barcodes to check), Records (All records), Staff (Staff and access), Settings (Company details, Settings and lists).
Every item keeps the permission of its route (`PermissionsTest` checks the route and the item hold the same one). `staff.manage`
lifts Staff to second place; `catalogue.edit` with no matching, buying or checking work lifts Products (`Permissions::LIFT`; order
only). Item > Search left the menu (the find box stays), and so did Reason codes and Number series (Settings and lists links to
them; those pages keep Settings marked). The screens of Phase I-3 to I-6 are no menu items any more: Home and the matching dashboard
say "Coming later: …" for the ones the person's jobs will use (`Permissions::COMING_LATER`), without phase codes. The home cards and
the merged Home page are step 4: `home.php` and `dashboard.php` are unchanged apart from that line.

**U30. Badges count only what the person can act on (plan F007; display only).** Waiting for 2nd OK and Possible duplicates show a
count to a matching lead only (`mapping.approve`), and the second-OK count leaves out the lead's own decisions
(`Queries::pendingCountFor`). Each badge carries its words for a screen reader and as a tooltip ("3 waiting for your second OK").
`Context::badges()` is computed once per request.

**U31. Errors and sign-in in words (plan step 3).** The error page's heading is by status (`Words::ERROR_TITLE`: "You cannot open this
page", "Page not found", …, also the tab title), its message by error code (`Words::ERROR`, the kernel's own codes plus
`unknown_staff` / `unknown_queue`; any other service message is shown as the service wrote it, since those reach the API), then
"If you report a problem, quote this number: <id>". The code is no longer printed: it is the section's `data-code`
(`UiResponse::errorCode()` in tests). A 403 names who the page is for and what the person is ("This page is for Reviewers. You
work as: Buyer. If you need it for your work, ask Fazil (the admin)."; "Only … can do this. Nothing was changed." for a button), or,
when Admin alone keeps it from them, that and the one fix (`Kernel::refusal`, `Permissions::blockedByAdmin`). New texts for the
expired form, a cross-site post, 404, 405, 413, a truncated form, 500 and the three 503s; `FormOnce`'s two refusals. The sign-in page
says what to use and who to ask, the code field names the code app, failure and lock-out texts are plain, and a password field has a
"Show" button (`app.js`, the only script change). The password page names the one-time password on the first sign-in. A request
whose cookie belonged to a session that ended (idle, 12 hours, signed out or switched off elsewhere) is sent to
`/ui/login?why=signed_out`, which says so (plan F056); a cookie nobody issued is still the same as none (`/ui/login`).

**U32. The stylesheet (design A, B's parts).** Tokens on `:root` with dark mode (`prefers-color-scheme`, guarded by
`:root:not([data-theme="light"])`, and `[data-theme="dark"]`); white cards on a grey page, one accent; a 17 px body; inputs at 16 px
or more and 48 px tall with 4.5 : 1 edges (B's stronger edge); buttons, menu links, row choices and tabs 44 px or taller; a 3 px
focus ring on everything. One chip with five tones (icon shape + word), and the older `.status`, `.tag`, `.notice`, `.note`, `.error`
restyled to the same look in place, so the 45 templates and the classes the tests pin are unchanged. The table-to-cards pattern:
`table.stack` (or `.rtable`) with `data-label` on each cell becomes one card per row when the page column is 640 px or narrower
(`.page` is the container), and any other table scrolls sideways inside itself instead of widening the page (the per-page
conversion is steps 5-8). B's parts are in it for the next steps: the "What happens:" line and job number (`.task-what`,
`.task-step`), "What each answer does" (`.answers`), the "Safer" label (`.safe-tag`), the 20-block strip with its legend
(`.segments`, `.segments-legend`, `.key`) and B's list cards (`table.list`, `.o-name` …). No imports, no external URLs, no data:
URIs, no inline style or script. The icons are CSS shapes (`.ico-*`), not design A's inline SVG: `UiSecurityTest` keeps refusing any
`<svg>` on a page (a page that shows hostile text must stay inert), and that check is not loosened.

**U33. Not done here, on purpose.** The behaviour items of plan §8.6 (back to the page after sign-in, `GET /ui/logout`, the expired
sign-in form shown again, …): not approved; the 405 page says "use the button on the page" instead. The Home cards (step 4), the
page-by-page words and the table conversions (steps 5-8; the plan's lint "every table is stack or in .scroll" waits for them), the
read-only notes built from `rolesPhrase` (step 5), the People page warning (step 8). Not checked in a browser yet: no browser on
this box or on staging; check the frame at 375 px and 1,280 px, light and dark, before the deploy.

*Tests* (7 Oct 2026): the full suite in slot `uir1`: `OK, but some tests were skipped! Tests: 873, Assertions: 22537, Skipped: 75`
(860 before, plus 13: `WordsTest` 8, `UiTemplatesTest` 2, `UiUnitTest` 1, `PermissionsTest` 1, `MenusTest` 1; the 75 skipped are
the HTTP screen and API tests of slots `ui`/`api`). In slot `ui`: `UiAuthTest`, `UiReviewFlowTest`, `UiSecurityTest` and every
`UiKernel` test: `OK (116 tests, 26115 assertions)`.

## Staff screens in plain words and design A, step 4: Home (slots `uir2`, `ui`, 7 Oct 2026)

Code: `src/Ui/{HomeTasks,HomeCounts,Context,Words}.php`, `src/Ui/Controller/DashboardController.php`,
`src/Ui/views/{home,matching_progress,cards,reviews,sample}.php` (`dashboard.php` became the partial `matching_progress.php`),
`public/ui/assets/app.css`, an optional `$kind` on `Documents::decidableCount()` and `Suppliers::decidableCount()`; tests
`tests/Unit/{HomeTasks,UiTemplates}Test.php`, `tests/Integration/UiKernel/{HomeScreen,Menus}Test.php`, `tests/Integration/UiReviewFlowTest.php`.
Source: plan §3 and F074-F093 (step 4), the owner's design decision "A with B's parts". Words, layout, help and the Home page only:
no permission, route or service message changed, no behaviour item of plan §8.6 is in it.

**U34. One Home for everyone (plan §3.1, F074, F087).** `/ui/` is the same page for every signed-in person (the linking jobs no
longer land on a separate dashboard). Top to bottom: h1 "Home" (the tab title, F031/F088), "Hello <name>. You work as: <jobs>."
(F089; the Admin strip stays the layout's, on every page); **What needs doing** (U35); **What is this system?** (plan §3.3, a
`<details>` open for the person's first 14 days after `staff_user.created_at`, then folded; "stock figure", not "stock number",
per correction b); **Matching progress** for `linking.view` (the former dashboard in plain words: "How sure" with the six band
names and their one-line meaning and the "?" H1, websites by name, "Not checked by the computer yet – nothing to do now", "Lists
show best sellers first", the second OK line with "Open" for matching leads only (F083), the duplicates note (F092), "How much of
what we sell is matched" with F086's columns and "no sales yet" instead of "-" (F093); both tables are `table.stack`, so one card per
list and per website on a phone (F077); "You can look; Matchers and Matching leads decide." for people who cannot decide);
**What you can use** (each menu item with its `Words::MENU_HELP` line, F079); **Coming later** (no dates).

**U35. The task cards (plan §3.2 C1-C20; `Ui\HomeTasks`, pure and unit-tested; `Ui\HomeCounts`, the queries).**
`HomeTasks::needs($roles)` names the facts a person's jobs use, so `HomeCounts` runs nothing else (a buyer's Home runs no matching
query; the owner's account with Admin runs only the staff one). `HomeTasks::build()` makes one card per kind of waiting work: a
plain sentence, a big number with its unit, B's "What happens:" line and ONE button to a page the person may open
(`HomeTasksTest` checks every link against the router and the roles). A card with nothing waiting is left out; with no card:
"Nothing is waiting for you right now." Sorted by `HomeTasks::RANK`: what holds other people up first (company details, approvals,
the second OK, staff access, orders a reviewer rejected or not sent), then the owner's own checks (spot check, set-aside matches,
duplicates, clues disagree, done work to check), then routine work; numbered (B's job numbers), the first "Start here" with its
button straight after its number so it is on the first phone screen (compare.md 2.2). Two cards are notes, not jobs (no number,
after the jobs, "Good to know"): C2 (the order PDFs say DO NOT SEND until a reviewer confirms the company details) and C17 (sales
data out of date: "Ask Fazil to load the new sales"). Where a badge counts the same thing, the card uses the badge's number
(`Context::badges()`), so a card never counts work the person cannot clear. Choices within the plan:
- C3/C4 split the review queue by kind: `Context::checks()` (once per request) = the decidable approvals and reviews of documents
  and suppliers (`decidableCount($id, $roles, $kind)`, the new optional filter; null counts both, as before) plus the company
  checks (always reviews); the `reviews_open` badge is their sum. The links go to `#approvals` / `#reviews` (ids added to the
  sections of `reviews.php`).
- C5 only for the spot check's own matching lead, while its verdict is `waiting`; "n of 20 checked", B's progress bar, and the
  button opens the next unanswered member by position (`?sample=`, so the page leads back). "About N more" = the stored population
  less the 20 and the matches set aside from it. The owner's account with Admin gets no spot-check card: its Matching lead job is off,
  so it cannot answer (only this account may: an answer from any other account fails the spot check).
- C7 per spot check that has not failed (a failed one's matches are all checked one at a time anyway), linking to its list
  (`id="held"` added in `sample.php`). C6 has the badge's count and opens the list (biggest sellers first), not the first group:
  finding that group means building every open group (`Duplicates::openGroups`, several queries over the sales history), too
  slow for the first page after every sign-in. C8 = `Queries::pendingCountFor` (not the lead's own). C9/C10 = the band counts,
  the same numbers as the lists (C10 keeps spot-check members in: the plan's simpler option). C10's "What happens:" adds "If a page
  says it is in someone else's spot check, leave it to them" (an answer by anyone but its owner fails a spot check; the listing
  page already says so), and for a lead whose own spot check is waiting: "If your spot check <name> passes, about N of them are
  confirmed together, so do the spot check first." C11 is one card for Likely / New product / Not sure / Renamed range, its button
  to the first list with work.
- C13 counts the person's own drafts; C14/C15 every buyer's (any buyer may send or correct an order). C16 has no number (What to
  buy is not one COUNT) and shows only when demand exists; it links to the whole list, urgent first (`?urgent=1` could be empty).
  C18 "due" leaves out inactive suppliers. C19 (`staff.manage`): test accounts that can sign in, fewer than
  `PeopleController::MIN_REVIEWERS` people whose Reviewer job works (effective roles), and each person whose jobs Admin switches off
  (on the owner's own account: the same words as the strip; on the admin's Home: "Untick Admin on their page and save. Their
  other jobs then work again.", the strip's one fix; never a second account, correction a). C20: no job, who to ask.
The card texts are `Words::TASK`, the page's words `Words::HOME`, the panel `Words::ABOUT` (all under `WordsTest`'s writing rules).
Each card's `<li>` carries `data-card` (its key) for tests. The notes are their own section ("Good to know", an h2), so every
card title is an h3 under an h2. The spot check's bar has an accessible name ("0 of 20 done", compare.md 3.2).

**U36. Not done here.** Timings on staging data (HomeCounts adds per lead one `KeySample::status()` per own waiting spot check
and one set-aside query per spot check that has holds; budget 100 ms over the old dashboard; time it on staging before the
deploy: this build may not read `cw_staging`). No "spot check passed" or "bulk link
waiting" card (not one of the plan's twenty). No count badge on the "To do" tab (the plan names no such badge). Not checked in a
browser (no browser on this box or on staging): check Home at 375 px and 1,280 px, light and dark, with the frame (U33).

*Tests* (7 Oct 2026): the full suite in slot `uir2`: `OK, but some tests were skipped! Tests: 886, Assertions: 23791, Skipped: 75`
(873 after step 3, plus 13: `HomeTasksTest` 8, `HomeScreenTest` 5; the 75 skipped are the HTTP tests of slots `ui`/`api`). In slot
`ui`: `UiAuthTest` (13), `UiReviewFlowTest` (14), `UiSecurityTest` (11) and every `UiKernel` test (83): all OK. An earlier run in
`uir2` failed once on `LockOrderTest`'s 250 ms timing limit (420 ms) while slot `ui` ran on the same server; it passed in the
final run, and step 4 touches no stock code.

## Staff screens in plain words and design A: the start and settings pages (slots `uir3`, `ui`, 7 Oct 2026)

Code: `src/Ui/Words.php` (new groups `REFUSAL`, `DOC_TYPE`, `DOC_TYPES`, `CHECK_STATE`, `CHECKS`, `RECORDS`, `RECORD`, `RECORD_NOTICE`,
`COMPANY`, `COMPANY_NOTICE`, `SETTINGS_PAGE`, `REASON_USE`, `SETTING_TOPIC`, `SETTING_HELP`, `STAFF`, `STAFF_NOTICE`; `SETTING` for every
setting; new `ERROR` codes; `SIGN_IN` labels; `say()`, `docType()`, `docTitle()`, `settingName()`, `settingHelp()`, `settingTopic()`,
`refusal()`), `src/Ui/{View,Html,Context}.php` (`$say`, `Html::size`, an optional way back on `Context::error`),
`src/Ui/Controller/{Reviews,Documents,Company,Reference,People}Controller.php`, `src/Ui/views/{reviews,documents,document,company,
company_form,settings,reasons,series,people,person,login,password,error}.php`, `public/ui/assets/app.css`; tests
`tests/Unit/{Words,UiTemplates}Test.php`, `tests/Integration/UiKernel/{ReviewScreens,CompanyScreens,ReferenceScreens,PeopleScreen,
SupplierScreens,PurchaseOrderScreens,StartSettingsWords}Test.php`. Source: plan §6.3-6.8, 6.13, 6.31-6.37 (F036-F073, F094-F102,
F140-F146, F405-F452, and F034/F035 of §6.2), §4 (intros), §5 (H4, H5), §7, the owner's corrections (a)-(f) and the design "A with
B's parts". Words, layout, help and display only: no permission, route or service message changed (the services' refusals are
translated by their error code on the page), no behaviour item of plan §8.6 is in it.

**U37. Things to check (`/ui/documents/reviews`, F094-F102).** Title "Things to check" (menu To check > Waiting for me), the intro, the
three-sentence "how" line and the "?" H4 (second OK). The two lists are `Words::CHECK_KIND` ("Needs your OK before anything happens",
"Already done: please check it"; the `#approvals` / `#reviews` anchors Home links to stay). Each check is one row of a `table.stack
list` (one card per check on a phone): what (`Words::docTitle`: an order by its supplier, "Order for Elux Wholesale (no number yet)",
"Cancellation of PO-000003 – …", "Supplier: …", "Company details changed (delivery address)"), why (`Words::CHECK_REASON`), value
(an order's net total in £ from `purchase_order`, a cancellation the cancelled order's; "N items" for a stock record; blank for
suppliers and the company details: review_task.units is never shown as money or as "units", F095 display part only), asked by, asked
on (UK time), check by (a red "Late" chip) and one "Open" button, or why this person cannot decide it (`Words::REFUSAL` by the
refusal's code: "This is your own work, so another reviewer must check it."). The kind filter lists only the kinds in use (the live
document types, Suppliers, Company details; a `?type=` for another kind still works and says "Nothing of this kind is waiting" with
"Show everything").

**U38. All records and one record (F405-F417).** "All records": the intro, a note naming the kinds in use and the ones that come
later (no phase codes), "Purchase orders are only shown to buying staff" for people without Purchasing (their empty list says so
too, F409), filters in words (`DOC_STATUS`; `CHECK_STATE` "Not needed / Waiting / OK / Not OK"), "N records, newest first",
`table.stack list` with Number, Status chip, What (a cancellation says what it cancels, an order names its supplier), Reviewer check,
Date, Supplier's reference, Made by, Final on (UK time); empty states with a way back. `DOC_STATUS['cancelled']` is now "Stopped before
it was final" so the status filter has no two "Cancelled". One record: the title "<kind> <number>" or "<kind> (no number yet)"
(`Document::label()` stays for file names and service messages), the intro, the decision FIRST with design B's "What each answer
does" (OK / "Not OK – say why", what each does for an approval, a review, a cancellation and a type whose rejection is only
recorded) and the "Safer" label on refusing a request (it books nothing); then the facts (supplier and value for an order, the
reason by its label, UK time, empty facts left out), "Download record (for the files – not for the supplier)" (F414), the
cancel ("reversal") form in words ("Make the cancellation record"), lines as cards with empty columns left out and £ per item / £
total, files as cards, the checks as sentences ("7 Oct 2026: Reviewer check asked for by …. Why: …." then "OK by … on …" or
"Closed: the record was cancelled (PO-000004)." for a check withdrawn by a cancellation, F416), the fingerprint and file hashes
in a "Technical details" fold. The record PDF is unchanged (a file for the records, `DownloadsTest`). The services' refusals on this
page are shown by code (`not_reversible`, `reversal_pending`, `not_reviewable`, `not_awaiting_approval`, the own-work refusals,
`note_required`, and "You cannot cancel this kind of record" for `role_not_allowed`/`admin_cannot_post` on the cancel form).

**U39. Company details (F140-F146; F034/F141 of the owner's account).** Crumb "Settings and lists", the intro, the status as a chip,
"Confirmed by … on <UK time>", the confirm form with "What happens: After this, new purchase order PDFs no longer say DO NOT SEND"
(F143), the orders still printing unconfirmed details named and linked, with "Open it and press Correct" (or "Confirm the details
first" while they are not confirmed, F140), the rejected-change orders with their state in words. Someone who cannot change them
reads "Only a reviewer can change these. Tell them if something is wrong."; the owner's account with Admin reads "You can only look
here: Admin switches off your Reviewer job." (`Permissions::blockedByAdmin`; with the strip; never a second account, correction a).
The checks of a change ("Changes another reviewer must check", F144) moved above the details, each with what changed, design B's
answers ("Yes, the change is right" / "Not OK – say why" and what each does), the refusal by code, the "nobody else is a Reviewer"
note. No version numbers and no UTC anywhere (F142): the history says "7 Oct 2026, 10:26 · Sam confirmed them (another reviewer still
has to check this change)". The form's words are `Words::COMPANY` (F145, F146). A stale save or confirm says who saved and when in
UK time (`company_changed` translated; the service message, with "version N … UTC", stays the API's).

**U40. Settings and lists, Reasons for stock changes, How record numbers are made (F418-F426).** The intro; the settings by topic
(Purchase orders, Suppliers, What to buy, Costs) in a fixed reading order, each a card on a phone: plain name with its key in small
code text, "Agreed" / "Not agreed yet", the value (Yes/No, "not set"), what it does (`Words::SETTING_HELP`, never the migrations'
"Phase I-2" / "plan IM9" texts, F420; the three promotion checks read on their own), changed "When CW was set up (7 Oct 2026)" or
"<UK time> (changed on the server)". No decision column, no server command: "To change a setting, tell Fazil the new value and why".
"Who checks what" (F421) is one sentence per kind of record in use ("Purchase orders: a reviewer checks every one within 7 days.
Over £10,000 needs a reviewer's OK first. If the reviewer says not OK, the order stays and the buyer cancels or corrects it.") with
the "?" H4. VAT codes as cards. The footer without decision numbers (F423). The two lists: titles and intros of the plan, the
reason's name first with its code kept in code text (the files and CSV use codes, plan §1.9), uses and direction in words; the
number series by kind ("Purchase orders", "Deliveries" …), "In use" / "Coming later" (no phase), limits in words. The reasons CSV is
unchanged.

**U41. Staff and access (F427-F451; F035).** Title "Staff and access"; for the admin the intro and "To add a new staff member, send
their name, e-mail and job to the developer" (no command, F427); for the auditor "You can look; only the Admin changes things." The
warnings say what to do here: reviewers counted by the job that works (a Reviewer with Admin does not count, and the warning says
so, F429), "Nobody is Admin … ask the developer", a test account that can sign in ("Stop this person signing in"), and each other
person whose jobs Admin switches off ("… Open their page, untick Admin and save."; the person's own clash is the strip's). The
list is `table.stack list`: name, Can sign in (Yes/No chip), jobs in words with "(off)" (and a line saying what "(off)" means, with
the "?" H5), e-mail, last signed in (UK time or "never"), added on. One person: crumb back, the intro, the clash warning "These jobs
do not fit together: while Admin is ticked, Reviewer and Matching lead do nothing. Untick Admin, then save. Their other jobs then
work again." (F035; correction a: the one fix), facts in words, the job boxes with plain names and help (`Words::ROLE`, `ROLE_HELP`,
`ROLE_GROUP`) and the code in small grey text for the developer (F436), the rule note (F444) with the "?" H5, "Save the jobs", "Stop
this person signing in" / "Let this person sign in again" with what they do (F441 words only: the confirm tick-box is behaviour item
4, not approved), the history as cards with jobs by name, UK time and "set up on the server" (F449). StaffAdmin's refusals are
shown by code (`role_conflict` names the jobs, `no_roles`, `roles_changed` with their jobs now and the ticks kept, `own_account`,
`placeholder_account`). An unknown person's 404 has a "Staff and access" button (`Context::error`'s new optional way back, F048).

**U42. Errors, sign-in and password (F036-F073) and the words of these pages.** Step 3's texts stand; the sign-in and password pages
now take every label from `Words::SIGN_IN` and the password intro from `PAGE_INTRO`. `Words::ERROR` gained the UI's own generic
codes `bad_version` (every "this form has no version" page of the app), `bad_form`, `unknown_document`, `unknown_task`,
`task_closed`, `note_required`. Templates use the new `$say($group, $code, ...$args)` (a word with its `%s` filled in, whole numbers
with thousands separators); `UiTemplatesTest::HELPERS` knows it. The notices of these pages are `Words::*_NOTICE` (the controllers'
`NOTICES` constants point at them).

**U43. Not done here, on purpose.** The behaviour items of plan §8.6 in this area: back to the page after sign-in (F057), `GET
/ui/logout` (F043), the expired sign-in form shown again (F059), the confirm tick-box before "Stop this person signing in" (F441),
the QR sheet for new staff (F452), and storing a check's money in its own column (F095's data part). `Words::CHECK_REASON
['all_documents']` stays "Every order is checked" (only purchase orders are live; a stock record's check will need its own words
when those screens come). Not checked in a browser (none on this box or on staging): check these pages at 375 px and 1,280 px,
light and dark, before the deploy.

*Tests* (7 Oct 2026): the full suite in slot `uir3`: `OK, but some tests were skipped! Tests: 890, Assertions: 27200, Skipped: 75`
(886 after step 4, plus 4: `WordsTest` 2, `UiTemplatesTest` 1 (every table of these pages is a `table.stack` with labelled cells),
`StartSettingsWordsTest` 1 (19 pages for six jobs and the sign-in: title, intro, no code / UTC / phase / command / "Your role", tables
as cards); the 75 skipped are the HTTP tests of slots `ui`/`api`). In slot `ui`: every `UiKernel` test (84), `UiAuthTest` (13),
`UiSecurityTest` (11), `UiReviewFlowTest` (14): all OK. `DownloadsTest` is unchanged (file names still come from `Document::label()`).

## Staff screens in plain words and design A: the matching pages (slots `uir3`, `ui`, 7 Oct 2026)

Code: `src/Ui/Words.php` (new groups `FIELD`, `FORM_VALUE`, `NIC_TYPE`, `AI_FIELD`, `AI_FIELD_STATE`, `ACTION_DONE`, `MOVEMENT`, `ORIGIN`, `QUEUE`,
`LISTING`, `PENDING`, `MATCH_NOTICE`, `MATCH_ERROR`, `SAMPLE`, `SAMPLE_FIT`, `DUPS`, `DUP_NOTICE`, `DUP_ERROR`, `SEARCH`, `ITEM`; `SPOT` grown; `ERROR`
codes `unknown_listing`, `unknown_item`, `unknown_group`, `unknown_sample`; tones of `FIELD_STATE`; `saleUses()`, `quoted()`),
`src/Ui/{Queries,Compare,Duplicates}.php`, `src/Ui/Controller/{Review,Samples,Duplicates,Search,Item}Controller.php`, `src/Ui/views/{queue,listing,
listing_form (new),pending,pending_actions,pending_decision,samples,sample,duplicates,duplicate_group,search,item}.php` (item.php: the matching
and stock parts only), `public/ui/assets/{app.css,app.js}`; tests `tests/Unit/{Words,UiTemplates}Test.php`, `tests/Integration/UiKernel/
{ReviewEvidence,ApprovalScreens,KeySampleScreen,DuplicatesScreen,MatchingWords (new)}Test.php`, `tests/Integration/{UiReviewFlow,UiSecurity}Test.php`.
Source: plan §6.9-6.12 and 6.14-6.18 (F103-F139, F147-F255), §4 (intros), §5 (H1, H2a, H2b, H3, H4, H6, H7, H8), §7, the owner's corrections (b), (c)
and the design "A with B's parts" with compare.md §2.1. Words, layout, help and display, plus behaviour item 5 of plan §8.6 (approved): no
permission or route changed, DecisionService and its messages are unchanged (its refusals are translated by error code on the page).

**U44. The words of the matching pages.** Every word on these pages comes from `Words` (`UiTemplatesTest` now fails on any word typed in one of
these templates). Bands by their six names everywhere (`Queries::BAND_LABELS` is `Words::BAND`; the URL values stay); the identity fields by
`Words::FIELD` (`Compare::LABELS` points there); the kind of product and the nicotine type in words wherever a value is shown; "1 sale = N
products" (`Words::saleUses`) instead of "N per item" / "xN"; products named in quotes in notices and refusals (`Words::quoted`) instead of
"listing #8". `ReviewController::plain($e, decide|approve|withdraw)` (public, unit-tested; `DuplicatesController::plain()` is the model) gives
each DecisionService code its sentence, by where it was refused (`lead_required` says "only a matching lead can decide when the clues
disagree" on the answer form, "only a matching lead can give the second OK" on Approve, and who may cancel on Cancel; `bad_card` names the
field and an example number); a code without words shows the service's message with "Nothing was saved." The duplicates page's own refusals
(`keeper_changed`, `elsewhere`, `confirm_needed` …) are made in `Words::DUP_ERROR`, naming the pages by their titles. Correction (b): "count" is
only a shelf count on these pages (`WordsTest` checks it), and the duplicates tags say "Website sells warehouse stock only: cannot be joined
here" / "Joining needs a second matching lead".

**U45. The lists (F154-F170).** Title `BAND_TITLE` with the "?" H1, the intro of each list (for a person who cannot decide: "You can look;
Matchers and Matching leads change this."), the tabs with their counts and "Waiting for 2nd OK" set apart, the renamed-range note, "Only a
matching lead can decide these. You can look." and a "Look" button for a Matcher on Clues disagree (F162), filters in words (Website by name,
Found by, Sold in the last 30 days, Name, brand, barcode or option number, Show), "N to decide, best sellers first.", the rows as B's list cards
(`table.stack list`: name first, website by name with the option number small, AI says "Same product (96% sure)", Watch out in words with the
matching's own codes left out, "Same barcode" as a chip, one Open button). An empty list says why: a filter that hides what the list still
has offers "Clear filters"; an empty list offers the next list with work ("Next: Likely matches – check them (1)"), else Home. An unknown list
is a 404 with a "Products to match" button.

**U46. One website product (F171-F237).** h1 = the product's name (also the tab title), its website and status chip under it, the intro, then in
this order: the spot-check box (U47), "Check this one by hand" (set aside) with its "?" H2a, the waiting box with what the decision does,
why a second OK, who decided, stale warnings and the answer per role (F208, F210), the duplicate group by its number (F236), the two cards (On
the website; Suggested warehouse product / Product you picked / Product this decision matches, with its stock rule chip and the "?" H8, no
CWP code), "Pick a different product" folded in the card (F222; hidden for people who cannot decide, F229, F205), where else the barcode is,
"Why the computer suggests this" (How sure with the "?" H1, Barcode as a chip, AI with "% sure", Why it is here, Watch out: what rules it out
first, then the fields that do not agree, then the warnings, Renamed range in a sentence, Possible matches, AI picked, Closest product, What
the AI says with C1, C2 … linked to their rows), the answers (U47 for a spot check), the comparison (rows with something on either side,
"not stated", values in words, the result as a chip; for a new product "What the website product says"), "Other products the AI looked at"
(No., Product, Score, Problems in words; role, lane, band reasons, flags, model, run and record numbers are in a folded "Technical
details"), and the history one line each ("7 Oct 2026, 10:14 · Aisha: Match CW-000001 (1 sale = 1 product) – Done."). The answers box has
design B's "What each answer does" for Yes / Create a new product / Ignore / No, wrong product, and "Not sure: skip it" with the "Safer"
label (it saves nothing); then the form (`listing_form.php`): the four answers in words (Yes greyed with "(pick a product first)"), "How many
does 1 sale of this website product use?" with its hint always shown and the "?" H7 (F183; app.js no longer hides it), the protected note in
correction (b)'s words, "Note (needed for Ignore)", "Details of the new product" with the kind of product and the nicotine type as
drop-downs of the known values (a website's own value stays offered; app.js opens the details when "create a new product" is chosen,
F199), "Save and open the next one". A refused form says what went wrong at the top with "Go to it" and again next to the field, which is
marked (F213). The back link names the list or the spot check; opened from Find a product, a product page or Possible duplicates it goes
back there (the Referer, same origin, those paths only), else Home (F217). Notices name the product and say "Here is the next one" when
they are shown on the next one (F201, F202). Behaviour items 11 (one sales source, F207) and 13 (no answer form on a matched product, F184)
are not approved: the sales line is the website's own, worded "N in 30 days, N in a year", and a matched product says "Matched to CW-…
(1 sale = 1 product)" and keeps its form.

**U47. A spot check's match: behaviour item 5 and the second step (F187, F215, compare.md §2.1).** Anyone but the spot check's own matching
lead sees "<owner> is checking this one in a spot check. Please do not decide it. A decision by anyone else fails the spot check, so the
answer forms are not shown here." and no quick yes, no answer form and no product search (the service still takes such a POST, as before:
the item is about the page). The owner sees the spot-check box (number n of 20, the 20 blocks with their written legend, "Say yes only if
it is right …") and design B's answers: "Yes, same product" (the same quick form as before, its sub-line true to what happens: the page
reloads with "Done" and "Check the next one (n of 20)"), "Not a match" as a `<details>` whose first tap only opens the second step: "This
stops the bulk link for good." with what it means, then the answer form ("What is it instead?", first answer "No, wrong product") and the
button "Yes, it is not a match: stop the bulk link"; nothing is sent before that second press. "Not sure" is a link to the next match to
answer: it saves nothing. "What each answer does" says the same, with "Safer" on Not sure. The redirect after a decision is unchanged.

**U48. The spot-check pages (F103-F119).** List: "Spot checks" with the "?" H6, the intro, "No spot check yet. Ask Fazil to start one.",
cards of Spot check / Result chip (`SAMPLE_RESULT`) / Checked n of 20 / Picked from / Started by (UK date) / Confirmed together. One spot
check: crumb "Spot checks", "Spot check <name>", the intro, "n of 20 checked" with its result chip, the 20 blocks and legend, for its owner
while it waits the main button "Check the next one (n of 20) →" (F108), the unusable / passed ("Ask Fazil to run it for you") / failed /
owner notes in words, the matches as cards (member states `SAMPLE_STATE`, "AI 99% sure", who and when in UK time), "Set aside to check by
hand" with the "?" H2a, "N still waiting. These are never confirmed with the rest.", and "Started by … on …, picked at random from N strong
matches." with the seed, method, rules version, bands, overrides and the bulk step in "Technical details (for audit)" (F112). No server
command anywhere.

**U49. Waiting for a second OK (F147-F153, F209-F212, F233).** Title and the "?" H4, the intro, "Nothing needs a second OK right now.", one
card per decision: the product, what it does in words ("Match to CW-000007 … (1 sale = 10 products)", "Create a new product "…" (1 sale = 2
products). Details: …", "Join products: CW-000038 … joins CW-000037 … (from Possible duplicates)", "Undo the join: …"), "Note: …", stale
warnings with "Cancel it and decide again.", why a second OK in `NEEDS_SECOND` words, who decided and when, and the buttons "Approve: it goes
live" / "Cancel this decision" ("Cancel <name>'s decision" on someone else's) with "Cancel takes the decision back; the website product
returns to its list."; "Waiting for a matching lead's second OK." for those who cannot approve.

**U50. Possible duplicates (F120-F139).** List: "Possible duplicates" with the "?" H3, the intro, the look-only note for people who are not a
matching lead, "N groups to decide, the biggest sellers first." with the main button "Start with the first group →" (F124), cards with
"Product (the one we keep)", pages, sold, and "Maybe different: strength, flavour" (F123); "Decided recently" in UK time. One group: the
heading says the answer once decided ("Decided: same product – the pages now share CW-…", F127), "Group 7" by the number of the run (no run
name, F126; the listing and product pages link by the same number through `Duplicates::groupNumbersOfListings`), why it was suggested,
then **what the rules say first** with the pages by name ("… compared with the one we keep, …", F130) and the rules' details in words
(`Duplicates::readableDetail`: "extra word: lemonade", "only on one page: …", forms in words, F125); then the answer box before the
evidence: "We keep CW-…", B's "What each answer does" (join: what moves and that it can be undone; "Different products – keep apart" with
the **Safer** label: nothing changes; "Not sure yet: skip it"), the tick "I opened the live pages …" (required by the browser for a join
when the rules found doubt; every other button has `formnovalidate`; the server check is unchanged), and the buttons: two pages "Different
products – keep apart" first and main when the rules found doubt, else "Same product – join them (both use CW-…)" first; three or more pages:
"Save my choices" (main), "All different", "All the same product" (F131). The page cards (website page by name and option, website stock "on
<date>", sales "(sales data to <date>)", the warehouse product with "stock not counted yet · <stock rule> · N free to sell", no CWP code),
the comparison without rows no page states and with values in words (F136; hidden when empty), "Why the computer thinks they match" with
prices instead of a ratio (F138), and "Joined by mistake?" with the "?" H3, "Undo: give it back its own product CW-… (N in stock)" / "Make it a
new product instead" and the button "Undo the join" (F134, F135). Notices name the group decided and say "Here is the next group." when they
show on the next one (F133): the redirect after a decision carries `prev=<group>` (display only).

**U51. Find a product and the product page (F238-F255).** Find a product: the intro, the hint on an empty page, the plan's label, "Warehouse
products (one per product; it holds the stock)" and "Website products (each website's own product pages)" as cards with the stock rule chip
and "Matched?" (Yes, to CW-… / Not yet / Ignored / On hold), the empty results in words, and "The website products matched to a warehouse
product are on its page." when only warehouse products match (F243). Product page: h1 "<name> (CW-…)", the intro, "This product was
joined into …" and the duplicate group by its number; "What the matching knows (from the website)" with only the fields it states, the
stock rule chip and "?" H8, "Made from: <website> product "…" (option …)" with the CWP code in small code text (F248, F249); "Website
products matched to it" as cards with "Matched to it now / to another product now", "1 sale uses", and the history as sentences ("Since 7 Oct
2026: this product, matched by Aisha (1 sale = 1 product)", F250); "Stock" with the plan's four words, "Total we can sell from" and the "?" H2b
(F247, F252); "Recent stock changes" as cards with what happened in words, the stock in words, the warehouse by name, the person by name ("set
up by CW" for system rows) and no "#0" note (F253). The product card block (IM3), the barcodes block and the suppliers block are left to
their own steps (step 9 and buying); the barcode source "the website product it was made from" (F255) is the one word changed there.

**U52. Not done here, on purpose.** Behaviour items 11 (F207) and 13 (F184), see U46. The confirm before "Not a match" is a `<details>`
(no script, so the CSP stays); a check in a real browser is still to do (none on this box or on staging): the list, one website product
(normal, a spot check's match as its owner and as another lead, waiting for a second OK), the second-OK list, the spot-check pages, Possible
duplicates (two pages and three), Find a product and the product page, at 375 px and 1,280 px, light and dark. The product page still says
"Item card" above "What the matching knows" until the IM3 words land. `Words::BADGE['linking_duplicates']` is now "waiting for you to decide"
(the badge said "1 groups").

*Tests* (7 Oct 2026): the full suite in slot `uir3`: `OK, but some tests were skipped! Tests: 894, Assertions: 32561, Skipped: 75` (890 before,
plus 4: `WordsTest` 2 (every code of the matching pages has a word, notices and refusals are sentences, "count" only for shelf counts; the
helpers `saleUses`, `quoted`, `Duplicates::readableDetail` and `ReviewController::plain`), `UiTemplatesTest` 1 (the matching templates: every
list is a `table.stack` with labelled cells or scrolls in its box, UK time, no word typed in a template), `MatchingWordsTest` 1 (19 pages for a
Matcher, a Matching lead, a look-only job and the owner's account with Admin: title, intro, no code / UTC / phase / command / "Your role" /
"proposal" / "Key queue", tables as cards); the 75 skipped are the HTTP tests of slots `ui`/`api`). In slot `ui`: every `UiKernel` test (85),
`UiAuthTest` (13), `UiSecurityTest` (11), `UiReviewFlowTest` (14): `OK (123 tests, 29566 assertions)`. The screen tests that pinned the old
words (`ReviewEvidenceTest`, `ApprovalScreensTest`, `KeySampleScreenTest`, `DuplicatesScreenTest`, `UiReviewFlowTest`, `UiSecurityTest`) now assert
`Words` constants, and `KeySampleScreenTest` also checks the second step of "Not a match" and that another lead gets no answer form.

## Staff screens in plain words and design A: buying and the product pages (slots `uir3`, `ui`, 7 Oct 2026)

Code: `src/Ui/Words.php` (new groups `PO_FILTER`, `PO_SOURCE`, `ORDERS`, `ORDER`, `PO_NOTICE`, `BUY_ERROR`, `PO_WARN`, `WHY`, `REORDER_ITEM`, `BRANDS`,
`ANOMALIES`, `REORDER_NOTICE`, `SALES`, `SUPPLIERS`, `SUPPLIER`, `SUPPLIER_FIELD`, `SUPPLIER_FORM`, `SUPPLIER_NOTICE`, `SUPPLIER_ITEMS`, `SI_NOTICE`, `CARD_FIELD`,
`CARDS`, `CARD_STATE`, `CARD`, `CARD_ERROR`, `CARD_NOTICE`, `CARD_IMPORT`, `BARCODE`, `BARCODE_SOURCE`, `BARCODE_REASON`, `BARCODE_DECISION`; `REORDER` grown;
the intros of What to buy, Sales data, Purchase orders and Change many product cards), `src/Ui/{PoWarnings,ReorderWhy}.php` (new),
`src/Reorder/ReorderList.php` (each line of `explain()` also carries `demand_detail`, the facts its "Why" is made of; `Explain`'s text and the CSV
are unchanged), `src/Ui/Controller/{PurchaseOrders,Reorder,SalesHistory,Suppliers,SupplierItems,Item,ItemCards,Barcodes}Controller.php`,
`src/Ui/views/{purchase_orders,purchase_order,purchase_order_edit,reorder,reorder_item,reorder_brands,reorder_anomalies,sales_history,suppliers,
supplier,supplier_form,supplier_items,supplier_item,supplier_item_form,item_cards,item_card_form,item_cards_import,barcode_reviews,item}.php`
(item.php: the product card, barcodes and suppliers blocks), `public/ui/assets/app.css` (a buying section); tests `tests/Unit/{BuyingWords (new),
UiTemplates}Test.php`, `tests/Integration/UiKernel/{BuyingWords (new),PurchaseOrderScreens,ReorderScreens,SupplierScreens,SalesHistoryScreen,
ItemCardScreens,CompanyScreens,ReviewEvidence}Test.php`. Source: plan §6.19-6.30 (F256-F404, every fix of step 7), the IM3 glossary pass of
step 9 (§1.1 "product card", "Product list", "Barcodes to check"), §4 (intros), §5 (H4), §7, the owner's corrections (b), (d), (e), (f) and the
design "A with B's parts". Words, layout, help and display only: no permission, route or service message changed, no behaviour item of plan §8.6
is in it (U59).

**U53. One place for the words of buying and the product pages.** Every word of these 18 templates comes from `Words` (`UiTemplatesTest` now
fails on a word typed in any of them, on a table that is neither a `table.stack` with labelled cells nor inside a `.scroll`, on "(UTC)",
`$dt(` and "(GBP)"; the product page `item.php` is now covered whole). The controllers' `NOTICES` and `FLAG_TEXT` constants are the `Words`
groups themselves, so the whitelist of notice keys and their words cannot drift. The services' refusals are translated by error code in each
controller's `plain()` (`PurchaseOrdersController`, `ReorderController`, `SuppliersController`, `SupplierItemsController`,
`ItemCardsController`; `ReviewController::plain()` is the model): the kernel's own codes take `Words::ERROR`, the buying codes `BUY_ERROR`,
the product card's `CARD_ERROR`; a code without words shows the service's message as a sentence followed by "Nothing was saved.". The
services' messages, `Document::label()` (file names: `DownloadsTest` unchanged), the CSV files and the URL values are unchanged. "Count" is a
shelf count only (correction b): the What to buy filter is "Stock to use", days are "used" or "left out", "Use these days again" (not the
plan's "Count these days again", F340).

**U54. Purchase orders (F256-F305).** The list: the "?" H4 next to the title, the intro (or "You can look; Buyers and Purchasing managers change
this."), "How an order goes" (F295) folded, "Start a new order" with suppliers by name and "not approved yet (you can prepare an order, but
not confirm it yet)" (F301), filters in words (F300), "N orders, newest first.", "Download for Excel (CSV)", and design B's list cards
(`table.stack list`): the supplier's name, the number or "No number yet (draft)", the status chip and one line saying the next step (for
people who cannot order, a draft reads "Not ordered yet: do not expect a delivery.": the words half of F305), dates in UK time, size, the
total in £, the reviewer check as a chip, "Sent 7 Oct 2026, 10:26, by e-mail", and one Open button; a cancelled order says "Cancelled on
7 Oct 2026 (cancellation record PO-000004)." (F296). Empty states say why (F304). One order: the title "PO-000001 – Elux Wholesale" /
"Order for … (no number yet)" (`PurchaseOrdersController::title`; F359-like), status chips, the intro, then near the top the reviewer's box
(F281, F287: "Needs your OK: over the approval limit" / "Reviewer check", by when, design B's "What each answer does", "Safer" on refusing a
request because it orders nothing) and "What you can do" (F282: confirm, "Cancel my request", and one small `<details>` form each for "I have
sent it…" (F276, F277, F284: "CW does not send e-mails", "How did you send it?", the DO NOT SEND stop with "I sent it anyway – record it"),
"Close…", "Cancel…", "Correct…" (F285), "Copy", and the five-line rule note (F286)); then the facts in words (F289, F290: "How it was made",
UK time, people by name, the cancellation with its reason label and record number, F280), totals with the VAT letter and its label, the
links (F293), the products as cards, files (F294) and the checks as sentences (F291). The editor (F256-F273): the title "New order for … (draft,
no PO number yet)", the scan box and its hint (F266), "Which product is "…"?" with "Set up with this supplier: their code …, box of 24"
(F261 words), the lines as cards with the plan's headers (F260), "Items per pack" and "Remember this pack for this supplier next time",
"Add a charge (delivery …)" and the lines file and "Cancel the draft…" folded (F258), the totals with the limit note (F269) and the two
buttons at the end of the form ("Save draft", and the confirm button; they stay after the scan box's Add, which a scanner's Enter presses).
**Correction f:** the confirm button says "Confirm order – gives it a PO number" with "It gets its PO number and is locked." and the note
"…If your changes take it over £10,000.00 before VAT, it goes to a reviewer first instead."; when the saved total is over the approval limit
it says "Ask a reviewer to OK this order" with "It goes to a reviewer first. It gets its PO number when the reviewer says yes." (the
read-only draft's button too; `PoMath::approvalUnits` against the limit, as the service decides). The services' warnings are said again by
`Ui\PoWarnings` (pure, unit-tested) with the supplier's and the products' names (F262, F263, F267, F284) and the lines file's problems as
"Row 3 (packs): …" (F270); a warning it does not know is shown as the service wrote it. Notices name the PO number and the limit (F275, F279,
F283, F292). The company-details note says what to do (F268) and links to the screen.

**U55. What to buy, why this amount, brand settings, days left out (F306-F343).** The sales-data box in UK dates with the websites by name, the
missing days and the "worked out" time as chips (F306), correction (e)'s sentence, "Work out sales again" with its hint (F313), the three
links (F320). The list is design B's list cards with six columns (F307, F310): the product (CW number, name, brand, main supplier, the flags
as chips in words with the product card's rules, correction d's words for a block), "Buy?", "Sells a day", "Have + ordered" with "Days left:
… / no sales" (F318: never ∞), "Suggest (packs)" with "packs of 24", £ and £ a pack, and "Why this amount?" folded: `Ui\ReorderWhy`'s plain
sentences (F308: sells about N a day, the days left out by name, the sales change, delivery + order every + spare days, the stock to have,
what you have, so buy N → packs, urgent, already on drafts) with the formula under "Show the maths" (Reorder\Explain, unchanged). A line on
a draft says "Already on a draft (72) – Open the draft. The draft covers this. / Buy 24 more." (F309; the first draft or waiting order with
the product). For people who cannot order: the look-only intro and "This is what the buyers are told to buy." (F324). The refusals (F314
with correction e, F316) and "Draft orders made: Elux Wholesale – 2 products, £294.00 … Not ordered: Elux Legend Mint – no main supplier"
(F323). One product: the title "What to buy: <name> (CW-…)", the facts one per line (F325), "Average sales" (F326), "Sold by month" (scrolls),
"Sales day by day" with "Vape and Go product 601 (1 sale = 1): about 12 a day. 82 of the last 91 days were used (9 left out)." and "Used?"
per day (F327), the settings form in words (F328, F332) or, read-only, "Normal settings are used for this product." / what is set (F333).
Brand settings and days left out: titles, intros, the "Change" button per brand (F337), people by name or "set up by CW" (F342).

**U56. Sales data (F344-F349).** The intro (no command or docs path), "No sales loaded yet. Ask Fazil…", per website by name "Sales loaded",
"Last loaded: sales up to …, loaded …", "Daily stock records: none, so sold-out days cannot be left out…", "Latest website stock", and the
top 50 not matched as cards with the problem as a chip ("Not known to CW", "Not matched yet", "Matched, but on hold", "Marked to ignore",
`SalesHistoryController::problem`); the loads are in "Technical details (for Fazil)" (F348). The CSV is unchanged.

**U57. Suppliers and their products (F350-F404).** The list: name first with the short code small (F350), the status as a chip, "Next
background check" with "Overdue" (F352, F353), "Approved" in UK time by name (F356), empty states (F357), cards on a phone. One supplier: the
title "<name> (<code>)" with its status chip (F359); the reviewer's boxes first, each titled by what it is (F363), with what each answer does
(F369, from `Suppliers::reject`'s real effect: a new supplier goes back to draft, a re-approval stays stopped, a route stays not approved, a
change or "no longer abroad" stops the supplier), "A reviewer decides this. You do not need to do anything." for a buyer (F364) and
`Words::REFUSAL` for one's own work (F367), "Cancel my request" (F368); "Can we order from this supplier?" (F360) with "Stop ordering from
this supplier…" folded (F361), "Ask a reviewer to approve" (F366) or what to fill in first (F362, `SuppliersController::fields`); the details
without empty rows and one "Not filled in: …" line (F373), the background check and the duty stamps in words (F371, F372, F377), the products
summary (F365), recent orders as list cards (F375) and the history as sentences (F376). The form: the plan's labels and hints (F378, F379 as a
hint, F383-F389), a refused field marked with its message under it and "Go to it" at the top (F381, F380; `SuppliersController::fieldError`
leaves the service's field label out and names the field in words). Supplier's products: titles by name (F402), the intro (F390), the
plan's columns (F391) as cards, money as £ with 2 decimals (F397), "Main supplier" (F393, F394, F404), the pack words (F395, F396), "Change how
this supplier sells it…" folded with "We still buy this from this supplier" (F399), the price history's source in words (F400) and "Price now:
£45.00 a pack (£1.88 an item), typed in on 7 Oct 2026" (F401), "Already set up with this supplier (box of 24)…" (F403).

**U58. The product pages: the IM3 glossary pass.** "Item card(s)" is "product card" / "Product list", "item" is "product", "listing" is "website
product", "Barcode review" is "Barcodes to check", MiB is MB, UTC is UK time. The product list: the intro (look-only for people without
catalogue.edit), the rules note, the numbers in words, filters, the card's state as a chip (`Words::CARD_STATE`), blocked / warning / not
sold any more as chips. The product card block of a product page: the state chip and who and when in UK time, the block in correction (d)'s
words ("Blocked: What to buy never suggests it, and an order with it cannot be confirmed. It is still on sale on the website: take it off by
hand."), the fields by `Words::CARD_FIELD`, suggestions with their sources in words ("what the matching read from the website names", 'website
product "…" (Vape and Go, option 601)'; `ItemController::source`), the history as "7 Oct 2026, 10:26 · Sam: used a suggestion: …". The barcodes
block and Barcodes to check: sources, reasons and answers in words (`BARCODE_SOURCE`, `BARCODE_REASON`, `BARCODE_DECISION`), items per scan,
UK time. The card form and "Change many product cards": the plan's words, the column names kept in `<code>` (plan §1.9), the kinds of product
by the words the import accepts. The services' rule labels and reasons (`ItemRules::RULES`, `WHY`, `advice()`) are plain already and stay.

**U59. Not done here, on purpose.** Behaviour items of plan §8.6 in this area are not approved and are left as they are: 6 (cancel and correct
reasons starting with "— choose a reason —" and required, "Correct" pre-selected: F278; only the labels changed), 7 (a purchasing manager
confirming another buyer's draft: kept, and reworded "Only … can change the products of this draft. You can still confirm it … or cancel it",
F274), 8 (drafts hidden from goods-in and the desk: they are labelled "Not ordered yet: do not expect a delivery." instead, F305), 9 (the
supplier form keeping what was typed after someone else saved: it says so and shows theirs, F382), 10 (one payment terms field: a hint only,
F385). F370's reviewer name and date are not stored with the supplier's last note (only the note is shown). F379's country drop-down is a hint.
F351's "Draft (not sent for checking)" stays "Draft" (the start stage's `SUPPLIER_STATUS`). Not checked in a browser (none on this box or on
staging): check What to buy, one product's Why, the orders list, the editor (scan, cards, the two buttons under and over the limit), one
order as buyer and reviewer, the suppliers pages and the product pages at 375 px and 1,280 px, light and dark.

*Tests* (7 Oct 2026): the full suite in slot `uir3`: `OK, but some tests were skipped! Tests: 902, Assertions: 42654, Skipped: 75` (894 before,
plus 8: `UiTemplatesTest` 1 (the 18 buying and product templates: tables as cards or scrolling in their box, labelled cells, UK time, no "(GBP)",
no word typed in a template; `item.php` now whole), the new unit `BuyingWordsTest` 6 (every code of these pages has a word, notices and refusals
are sentences, "count" only for shelf counts, corrections d, e, f, `Ui\PoWarnings`, `Ui\ReorderWhy`, the controllers' `plain()`,
`SuppliersController::fieldError`, `PurchaseOrdersController::screenRow/title/pack`, `ItemController::source`), the new `UiKernel\BuyingWordsTest` 1
(29 pages for a buyer, a purchasing manager, a reviewer, the purchasing desk, a stock controller and an auditor: title, intro or "You can look;",
no code / UTC / command / "Your role" / "(GBP)" / "∞" / old words, tables as cards); the 75 skipped are the HTTP tests of slots `ui`/`api`).
Then one more unit test, `BuyingWordsTest::testNoGroupOfWordsRepeatsAKey` (it found `REORDER_ITEM` naming `days` twice, which silently put
"Sales day by day" where "N days" belonged; fixed): `--testsuite unit` `OK (244 tests, 27158 assertions)`, and `ReorderScreensTest`,
`BuyingWordsTest`, `PurchaseOrderScreensTest` again `OK (9 tests, 1126 assertions)`. In slot `ui`: `UiAuthTest` (13), `UiReviewFlowTest` (14),
`UiSecurityTest` (11) and every `UiKernel` test (86): all OK. The screen tests that pinned the old words (`PurchaseOrderScreensTest`,
`ReorderScreensTest`, `SupplierScreensTest`, `SalesHistoryScreenTest`, `ItemCardScreensTest`, and one assertion each in `CompanyScreensTest` and
`ReviewEvidenceTest`) now assert `Words` constants; `PurchaseOrderScreensTest` also checks correction f (the editor's button under and over the
approval limit). `DownloadsTest` and the CSV assertions are unchanged.

## Staff screens in plain words and design A: the behaviour items of plan §8.6 (slots `uir4`, `ui`, 7 Oct 2026)

Code: `src/Ui/{Kernel,Duplicates,Words}.php`, `src/Ui/Controller/{Auth,People,PurchaseOrders,Suppliers,Review}Controller.php`,
`src/Ui/views/{login,person,purchase_order,purchase_order_edit,purchase_orders,supplier_form,listing}.php`, `public/ui/assets/app.css` (one rule);
tests `tests/Integration/UiKernel/BehaviourItemsTest.php` (new), `PeopleScreenTest`, `KeySampleScreenTest`, `SupplierScreensTest`,
`PurchaseOrderScreensTest`, `PasswordChangeTest`, `tests/Integration/{UiAuth,UiSecurity}Test.php`, `tests/Unit/UiUnitTest.php`. Source: plan §8.6
items 1-6, 8, 9, 11 and 13 (F057, F043, F059, F441, F187, F278, F305, F382, F207, F184), approved by the owner on 7 Oct as **provisional
defaults**: each decision below (U60-U69) may be reversed by the owner, item by item. No permission changed (`Permissions::MAP`, the routes'
permissions and every service, its checks and its messages are as they were; the one new route, `GET /ui/logout`, is public and only leads on).
Not done, on purpose: item 7 (a purchasing manager confirming another buyer's draft: kept, reworded only, U59), 10 (one payment-terms field:
a data-model change), 12 (the check's "Units or value": its label only, U37) and 14 (the QR sheet for new staff: ops). These decisions
supersede the "not approved" notes of U33, U43, U46, U52 and U59 for the items they name.

**U60. Back to the page asked for after signing in; a form sent while signed out says it was lost (item 1, F057; provisional).** A request
without a live session is sent to `/ui/login` with `back` (a GET: its own path and query, less `notice` / `prev`; a POST: the path of its
same-origin Referer, the page the form was on) and, for a POST, `why=lost`; `why=signed_out` stays for a GET whose session ended. The sign-in
page carries `back` in a hidden field and leads there after the sign-in (a forced password change still comes first); signed in already (a
second tab), `/ui/login?back=` leads straight there. Only a safe page is ever carried: `AuthController::safeBack` takes a local `/ui/...`
path with an optional query of plain URL characters (no `//`, no `/.`, no backslash, no scheme, no line breaks, at most 512 characters), and
never Home (the default anyway), the sign-in, sign-out or password pages, an asset or a download (`.csv`, `.pdf`, `.xlsx`, `/pdf`,
`/ui/files/`); anything else lands on Home. `HtmlResponse::redirect` still refuses any non-local target. `why=lost` shows, first and as an
alert, "What you sent was NOT saved, because you were not signed in any more. Sign in, then do it again." (`Words::UI['lost']`). Nothing of
the lost POST is kept or replayed (it was never read: no session, no CSRF check, no effect). A POST to `/ui/logout` without a session loses
nothing and goes to the plain sign-in page.

**U61. `GET /ui/logout` (item 2, F043; provisional).** The sign-out address opened from the history or a bookmark no longer gives a 405: a
new public route leads a signed-in person Home and anyone else to the sign-in page. It never signs anybody out (a GET can be sent by any
page; signing out stays the POST of the account panel, with its token). `UiUnitTest` lets this one public GET through its "every route but the
sign-in needs a session" rule.

**U62. A sign-in form open too long is shown again (item 3, F059; provisional).** The sign-in form's cookie lives one hour. A sign-in sent
after it expired (or without it, or with another browser's token) is answered with the sign-in form again: "This page was open too long.
Please sign in again." (`Words::SIGN_IN['expired']`), the e-mail and the way back kept, never the password, a fresh form cookie, the code
`csrf` in the message's `data-code`. Still 403, and still no sign-in attempt is made or counted (`login_attempt` untouched), so the lock-out
rules and `UiSecurityTest`'s forged-form checks are as before. A cross-site post (`Sec-Fetch-Site` / `Origin`) is still the error page.

**U63. A tick before "Stop this person signing in" (item 4, F441; provisional).** The button sits behind a required tick-box "Yes, sign
them out now" (no script: the browser's `required`). The server checks it too: without `confirm=1` the person page comes back (422) with "Tick
"Yes, sign them out now" first. Nothing was changed: the person can still sign in." and nothing is changed. "Let this person sign in again"
needs no tick (it takes nothing away).

**U64. Only the spot check's owner answers its matches (item 5, F187; provisional).** As built in U47: anyone but the spot check's own
matching lead (another lead, a Matcher) sees "<owner> is checking this one in a spot check. Please do not decide it." and no quick yes, no
answer form and no product search. `KeySampleScreenTest` now checks a Matcher too. The service still takes such a POST, as before.

**U65. Cancel and Correct start with "— choose a reason —" (item 6, F278; provisional).** The reason lists of "Cancel…", "Correct…" and the
draft editor's "Cancel the draft…" start with an empty "— choose a reason —" and are required; nothing is chosen for the person (the plan's
row F278 also suggested pre-selecting "Replaced by a corrected order" for Correct; the task's wording, "start with — choose a reason —", was
followed, so Correct starts empty too). Sent without a choice anyway, the service's own check refuses it (`bad_reason`, unchanged): the page
says "Choose why from the list. Nothing was changed.", that form opens again at its list (`aria-invalid`), and nothing is cancelled; a
Correct refused this way stores nothing under its form key, so the same form with a reason goes through.

**U66. Goods-in and the purchasing desk see no drafts unless they ask (item 8, F305; provisional).** `PurchaseOrdersController::
draftsOffByDefault`: a person whose working jobs include Goods-in or Purchasing desk and who cannot make orders (`doc.PO.post`) sees the orders
list without drafts, with "Drafts are not shown: they are not ordered yet, so no delivery is coming for them. Show drafts too", a "Show drafts
too (not ordered yet)" tick in the filters (`?drafts=1`), and the Drafts status filter shows them as before. Everyone else (buyers, purchasing
managers, reviewers, auditors, a person who is Goods-in and a Buyer) sees the list as before, without the tick. The list's CSV link carries
`drafts=0` when the page hides them, so the download is the list on the screen; the CSV address alone is unchanged (drafts included).
F305's other half, "Not ordered yet: do not expect a delivery." on a draft's card, stays (U54).

**U67. The supplier form keeps what was typed after someone else saved (item 9, F382; provisional).** A save refused because the supplier
changed meanwhile (`version_conflict`, the service unchanged) draws the form again with the current version, so Save works again, and says
"<who> changed this supplier on <UK time>, while you were editing. Nothing was saved. Your changes are kept below, and what they changed is
marked. Check it, then press Save again." What they changed is read from the `supplier.update` audit rows written since the form's version
(each carries the version it made and the columns it changed, before and after; the app login may read `audit_log`). A field only they
changed shows their value, marked "Changed meanwhile by <name>: their value is filled in here."; a field the person changed too keeps what was
typed, marked "Changed meanwhile by <name> to: <value>. Your value is kept here: check which is right."; a field typed the same as is saved
now is not marked. They are listed at the top too, each linked to its field. Choice within the item: taking their value where the person
typed nothing (rather than sending the stale value back) means pressing Save again cannot silently undo the other person's change; where both
changed a field, the person decides. The company form's own handling (U39) is unchanged.

**U68. One sales source on the website product's page (item 11, F207; provisional).** `Duplicates::soldFromHistory` (the code that already
gave Possible duplicates its numbers, moved into one public method) gives the website product's page its "Sold" line too: the units of the 30
and 365 days to the last day of sales loaded for its site, "(sales data to 1 Oct 2026)"; for a site with no sales loaded, the website's own
figures, "(from the website)". The two pages now show the same numbers for the same product. The lists keep sorting by the website's own
figures (`listing_profile`, unchanged: one indexed column).

**U69. An already matched website product has nothing to decide (item 13, F184; provisional).** A website product matched to a warehouse
product (status mapped or on hold, no decision waiting) has no answer form and no quick yes; the decide box says "Matched to CW-… <name> (1
sale = 1 product). Nothing to decide here. Wrong match? Ask a matching lead to change it.". With nothing picked or suggested, the page compares
it with the product it is matched to ("Warehouse product it is matched to"), and its own product and that product's other website products
are no longer listed under "This website product's barcode is also on" (no "may duplicate" warning about itself). Choice within the item: a
matching lead keeps a way to change a wrong match, folded under "Change this match" (closed until a product is picked; "Yes" and "No, wrong
product" need another product picked first, so no answer is about the current match itself); without it no screen could correct a wrong
match any more. A Matcher loses the answer form on a matched product (the change F184 names: "it removes a way to start an unlink from that
page"); the route and the service still accept a Matcher's decision, as before.

Not checked in a browser (none on this box or on staging): check at 375 px and 1,280 px, light and dark, the sign-in page with "NOT saved"
and with "open too long", a person page's tick-box, a confirmed order's Cancel… and Correct… lists, the orders list as Goods-in, the supplier
form after a stale save (both marks), and a matched website product as a Matcher and as a matching lead ("Change this match").

*Tests* (7 Oct 2026): the full suite in slot `uir4`: `OK, but some tests were skipped! Tests: 913, Assertions: 42198, Skipped: 75` (the new
`BehaviourItemsTest`, 10 tests: the safe way back, back after signing in, the lost form, `GET /ui/logout`, the expired sign-in form, cancel and
correct reasons, drafts for Goods-in and the desk, the stale supplier form, one sales source, the matched website product; the 75 skipped are the
HTTP tests of slots `ui`/`api`). In slot `ui`: `UiAuthTest` `OK (13 tests, 361 assertions)`, `UiSecurityTest` `OK (11 tests, 24910 assertions)`,
`UiReviewFlowTest` `OK (14 tests, 600 assertions)`, every `UiKernel` test `OK (96 tests, 4901 assertions)`. In slot `api`: the seven `Api*Test`
files `OK (35 tests, 2453 assertions)`. Tests changed with the behaviour: `UiAuthTest` (GET /ui/logout leads on), `UiSecurityTest` (the way back
and `why=lost` of a request without a session; the 405 example is now GET of a decide address), `UiUnitTest` (the same 405 example; the public
GET /ui/logout route), `PasswordChangeTest` (the way back), `PeopleScreenTest` (the tick-box), `PurchaseOrderScreensTest` (a reason chosen
for the draft's cancel), `SupplierScreensTest` (the stale form keeps what was typed), `KeySampleScreenTest` (a Matcher on a spot check's
match). `DownloadsTest` and the CSV assertions are unchanged.

## Staff screens in plain words and design A: the review fixes (slots `uir7`, `ui`, `api`, 7 Oct 2026)

Code: `src/Ui/{Words,Context,Duplicates,HomeTasks}.php`, `src/Ui/Controller/{Review,PurchaseOrders,Reorder,ItemCards,Documents,Files}Controller.php`,
`src/Ui/views/{listing,listing_decide (new),listing_form,pending_actions,duplicate_group,cards,login,password,purchase_order,purchase_order_edit,
barcode_reviews,queue}.php` and the 29 templates whose list tables now sit in a `.table-wrap`, `public/ui/assets/{app.css,app.js}`,
`src/Documents/Documents.php` and `src/Suppliers/Suppliers.php` (`decidableCounts()`, read only); tests `tests/Integration/UiKernel/{KeySampleScreen,
HomeScreen}Test.php`, `tests/Unit/{Words,HomeTasks,UiTemplates}Test.php`. Source: the two reviews of the uncommitted build (R1: permissions, routes,
services, CSP, escaping, forms, tests, the owner's corrections; R2: 1,207 rendered pages read as each job, the phone and laptop layout worked out from
`app.css`). Rule kept: words, layout, help, the Home dashboard and the approved behaviour items only; no permission changed (`Permissions::MAP`,
`ROLES`, the routes and every service check are as they were), no service message changed.

**U70. The provisional decisions of the plain-words work, in one list (the owner confirms or reverses each).**
1. The design "A with B's parts" as built (U28, U32): a sidebar on a laptop; on a phone a top bar, the bottom tab bar (the owner's: To do / Matches /
   Duplicates / Orders / More) and the menu as a sheet; white cards on grey, one accent, status = icon shape + word, list tables as cards at 640 px of
   page or less; B's job numbers and "What happens:" on Home, "What each answer does", the "Safer" label, the 20-block strip, B's list cards. Two
   departures from A's mock-up: icons are CSS shapes, not inline SVG (U32: `UiSecurityTest` refuses any `<svg>`), and the answers of a decision page
   are a box placed before the evidence, not A's sticky answer bar (U72).
2. The six band names: Strong match / Likely match – check it / New product / Not sure – you choose / Clues disagree / Renamed range (U26).
3. The core words: website product (a listing), warehouse product (an item), match, Matching lead, second OK, Reviewer, product card, Product list,
   Barcodes to check; "count" only for a shelf count (correction b's four words for the sell policies and the counted item).
4. The strip "TEST SYSTEM: nothing here is real" wherever `app.env` says `environment=staging`, which includes `cw_staging` (where the owner's
   matching answers are real work: the wording is the owner's call).
5. "Coming later" with no dates and no phase codes.
6. The person to ask is the fixed name "Fazil" (`Words::ASK`), not read from the staff list (plan §9 question 3: reading the active admin would also
   name the owner, whose account holds Admin today).
7. The owner's account keeps Admin + Reviewer + Matching lead, with the yellow strip and its one fix (correction a); no word suggests a second account.
8. The behaviour items 1-6, 8, 9, 11 and 13 of plan §8.6 (U60-U69), and this stage's guard of a spot check's confirmed match (U71).
9. Home's choices within the plan (U35): which cards show for whom, their order, the two "Good to know" notes, no "spot check passed" card.

**U71. A spot check's match after the reviews (R1 important, R2 blocker; compare.md §2.1, behaviour item 5).**
(a) *The owner's "Yes" is always "Yes".* The quick "Yes, same product" is built from the suggestion (its product and its units, what `KeySample`
counts as a yes), not from "no form was sent": it stays after a refused answer (R1's probe: "Not a match" with Ignore and no note left only a "Yes"
radio inside the second step, under the button "Yes, it is not a match: stop the bulk link", and pressing it confirmed the match) and with `?pick=`.
The second step never offers the suggestion. With another warehouse product picked, the second step offers "It is CW-… instead: match to it"
(matching any other product is `decided_otherwise`, which fails the spot check, so the button's words are true), and a note says that "Yes" still
matches the suggested product. A refused answer opens the second step again, unless it was the plain yes (a stale page, say).
(b) *A confirmed member stays the spot check's.* `ReviewController` finds the spot check by the open suggestion, else by the listing (the latest spot
check that has it among its 20); a member answered already is guarded while the spot check has not failed and the member is `confirmed`
(`KeySample::status`, through the strip it already drew; a failed spot check has nothing more to lose). Anyone but its owner then sees "<owner>
checked this one in a spot check. Please do not change its match. Any change to this match fails the spot check, so the forms to change it are not
shown here.", no "Change this match", no product search, and the intro "This website product is in a spot check. You can look; …". The owner keeps
"Change this match" (behaviour item 13), now titled "Change this match: stops the bulk link for good", opening on the red "This stops the bulk link
for good." box, with the danger button "Change it: stop the bulk link" and without "No, wrong product" (about another product it would change
nothing, so the button would be untrue). The spot box says "You said yes to this one. If you change its match now, …" and keeps "Check the next one".
The service still takes such a POST, as before (display and form only).

**U72. The answers before the evidence (R2 important; plan F203).** On a spot check's match (its owner) and on a strong match with the quick yes, the
answer box (`listing_decide.php`, one partial drawn in one of two places) comes straight after the two product cards (and the "barcode is also on"
warning), before "Why the computer suggests this", the comparison and the other products; elsewhere it stays after "Why". The spot box has "Go to
the answers ↓". On a 375 px phone the owner's "Yes" was about 2,000 px down (three screens for each of the 20); it is now after the spot box and the
two cards. A's sticky answer bar (`.decide-bar`, in the CSS since U32) stays unused: a bottom bar would put "Not a match" one tap under the thumb,
which compare.md §2.1 forbids, and it would hide the tab bar.

**U73. Wide lists scroll inside their own box on a tablet or laptop (R2 important).** Every `table.stack` not already in a `.scroll` (40 tables in 29
templates) is wrapped in `<div class="table-wrap">`: above 640 px of page it scrolls sideways inside itself (the page never does); as cards it never
overflows, so nothing is clipped there. The draft order's lines table is narrower (cells 8 px each side, a quantity or price field 6rem, the VAT list
8rem showing "S – Standard rate 20%" when open). Check at 768, 1,024 and 1,280 px (ops.md).

**U74. Refusals in words by code and detail (R1 minor, R2 minor).** The service messages are unchanged; the UI layer now also says: What to buy's
truncated form "The list was too long to send in one go, so nothing was ordered. Narrow the list (choose a brand or a supplier) and try again."
(not the generic "reload"); an `.xlsx` refused because a sheet is too big inside (the reader's own `too_large`, told apart from the page's upload
limit by its message) "This spreadsheet holds too much to open, although the file is small. …"; `note_required` naming the line when the service
names one (`Words::noteRequired`, records and orders); a refused order line by its field ("Line 1: the price per pack must be an amount in £ like
12.50 (at most 4 decimals, and at most £9,999,999.00 a pack). Nothing was saved.", `Words::LINE_FIELD`); `unknown_file`; the two download refusals
by job (`Words::whoCan`); the barcode refusals `bad_decision`, `barcode_exists`, `barcode_on_other_item` (naming the product that has it) and the
card import's unknown columns. A kind of product the matching knows only as a group (`pod_refill`) is shown in words in the new-product form.

**U75. Phone and screen details (R2 minor).** List cards: the status chip sits next to the name (it took a row of its own), and a labelled cell with
nothing in it is left out (`td[data-label]:empty`; the "Watch out" cell of the lists now prints nothing when empty). The tab labels are 12 px under
380 px of screen so "Duplicates" fits five across at 320 px; under 360 px the brand name is hidden from sight (kept for a screen reader) so the top bar
fits. "Show" (password) and "Why this amount?" are 44 px; "Use this product" in the candidates is a button. The red "wrong" block of the 20-block
strip and its legend carry a white bar (the blocked shape), so it differs from "yes" without colour. A "Good to know" card keeps its tone bar (the
amber frame of `.card.waiting` is not for task cards). Opening "Not a match" keeps it in place (no `order: 3`, which slid "Not sure" under the thumb).

**U76. Words that did not match the page (R2 minor and nits, R1 nits).** The website product's intro says "This website product is matched to a
warehouse product. Check the match. …" on a matched one; "Not sure: skip it" (and the duplicate group's "Not sure yet: skip it") always has a way
on: the next one, or "Leave it and go back to <list>"; the over-limit draft button's note adds "If your changes bring it to £10,000.00 or less before
VAT, it gets its PO number at once instead (no reviewer first)." (correction f both ways); a refused button press is headed "You cannot do this"
(`Words::errorTitle($status, $post)`), a page "You cannot open this page"; "1 product, 20 items"; "Set up by CW on <time>" on Barcodes to check;
"their history"; "£" on a confirmed order's price per pack; "Only these jobs still work: Look only (matching), Accountant, Auditor."; no "not stated
vs not stated" under a duplicates rule; "Order PDF (says DO NOT SEND for now)" while the company details print DO NOT SEND; one "waiting for a second
OK" line, not two; the owner's Home card "Your jobs are switched off" points to the strip ("The yellow note at the top of the page says why, and who
can fix it.") instead of repeating it; "should be left out when CW works out what to buy" (not "should not count", correction b); the password "Show"
/ "Hide" words come from `Words::SIGN_IN` through `data-` attributes (English if a page has none) and the button sits after the field's `<label>`,
so the field's name is the label's words only; the Home progress bar's "n of 20 done" is `Words::UI`.

**U77. The cost of the badge and Home (R1 minor).** `Context::checks()` (the review badge and Home's two cards, once per request) counts documents
and suppliers by kind in one `GROUP BY t.kind` query each (`Documents::decidableCounts`, `Suppliers::decidableCounts`; `decidableCount()` is their
sum, as before, and keeps its old signature): three queries again, as before Home, instead of five. Not measured on staging data (this build may
not read `cw_staging`): time `/ui/` and an ordinary page for the owner's working account, a lead and a reviewer before the deploy (ops.md).

**U78. Review findings not applied, and why.**
- R2 important "the branch is behind main 46aa62e": not rebased here. This work is committed on `ui-redesign` (from 4b59fc8) and the merge after the
  receiving build is the orchestrator's; what it must do is U79.
- R1 important, last part "show a picked other product's match outside the danger step with a neutral button": not done. `KeySample` counts a match
  to any product but the suggestion as `decided_otherwise`, which fails the spot check for good, so that answer belongs behind the second step; it is
  worded "It is CW-… instead: match to it" there (U71).
- R2 minor "the Buy? check-box is 22 px with no row-size label": it already sits in a `label.choice` (the whole row, 44 px tall).
- R1 minor "Home's cost not measured": the queries are fewer (U77); the timing on staging data stays open.
- Design A's answer bar: not wired, on purpose (U72).

**U79. What the merge with main (46aa62e: IM6 Receive + invoice, IM10 site stock writer) must do.** Files changed on both sides: `docs/{decisions,dev,
ops}.md`, `public/ui/assets/{app.css,app.js}`, `src/Auth/Permissions.php`, `src/Documents/Documents.php`, `src/Ui/{Context,Html,Kernel,View}.php`,
`src/Ui/Controller/{Documents,Item,Reviews}Controller.php`, `src/Ui/views/{document,documents,item,item_cards}.php`, `tests/Integration/UiKernel/
{ItemCardScreens,Menus,ReferenceScreens,ReviewScreens}Test.php`, `tests/Integration/UiSecurityTest.php`, `tests/Unit/{Permissions,UiTemplates}Test.php`.
1. `Documents::decidableCount`: main added the ReviewInvolvement exclusion (I133, a receipt's bench checker is not its reviewer). Here
   `decidableCount()` is the sum of the new `decidableCounts()`: put main's `$involved` clause and parameters into `decidableCounts()`'s query, or the
   badge and Home would offer a receipt's review to the person who bench-checked it.
2. The menu: main's "Receiving" section (Receive + invoice, Goods-in bench, Incidents with the badge `incidents_open`) must be added to the new task
   menu (`Permissions::MENU` with `Words::SECTION` / `MENU` / `MENU_HELP` keys, `Words::BADGE['incidents_open']`, `Tabs::PRIORITY` for goods-in and the
   purchasing desk), and `Permissions::COMING_LATER` must drop `receive` and `invoices`; the role texts that say "(coming later)" for Goods-in must
   change. `WordsTest::testEveryCodeOfTheSystemHasAWord` will fail until the new menu keys and badge have words.
3. Main's seven new templates (`receipts`, `receipt_edit`, `receipt_bench`, `bench_list`, `incidents`, `receipt_files`, `receipt`) had no plain-words
   pass: at least give them `PAGE_TITLE` / `PAGE_INTRO`, wrap their list tables in `.table-wrap`, and keep them out of the "no word typed in a template"
   lists of `UiTemplatesTest` until their own words pass. Their screens' services' messages stay as they are.
4. `app.css` and `app.js`: merge by hand (main added +48 and +187 lines): keep this branch's tokens and sections; main's rules for the receiving pages
   may use the old look's names; check them at 375 and 1,280 px. `app.js` keeps one file under the CSP.
5. `Context`: keep main's `goodsReceipts()` and the `incidents_open` badge beside this branch's `checks()`. `Kernel`: keep main's routes. `Html`,
   `View`: main added `Html::uk()` and the view helper `$uk` ("7 Oct 2026 14:05", UK time) for its receiving templates; keep it and add `uk` to
   `UiTemplatesTest::HELPERS` (or move those templates to this branch's `$when`, "7 Oct 2026, 14:05", the one UK-time form of the other pages).
6. `docs/decisions.md`: both sides append (no clash of numbers: main uses I125-I179 and SC6-SC22, this branch U25-U79).
7. Then the full suite, slots `ui` and `api`, the hammer and the golden again.

*Tests* (7 Oct 2026): the full suite in slot `uir7`: `OK, but some tests were skipped! Tests: 913, Assertions: 42733, Skipped: 75` (the 75
skipped are the HTTP tests of slots `ui`/`api`; the first run failed twice on tests that pinned the old words: `HomeScreenTest` (the owner's card
text, now a pointer to the strip) and `MatchingWordsTest` (a Matcher on another lead's spot-check match now reads `listing_spot`), both updated). In
slot `ui`: `UiAuthTest`, `UiSecurityTest`, `UiReviewFlowTest` `OK (38 tests, 26016 assertions)` and every `UiKernel` test `OK (96 tests, 4929
assertions)`. In slot `api`: the `Api*Test` files `OK (37 tests, 2484 assertions)`. The hammer (`--seed=20261009`, slot `uir7`): `RESULT: PASS (59
checks passed, 0 failed)`, 109 s. The matching golden tests: `{"passed":59,"failed":0}`. New and changed checks: `KeySampleScreenTest` (a refused
"Not a match" keeps the quick yes and nothing link-like in the second step; `?pick=` the suggestion and another product; the answers before "Why";
the owner's page after "Yes" with the guarded "Change this match" and no "No, wrong product" there; another lead on a confirmed member: no form, no
search, the look-only intro, the member still confirmed), `WordsTest` (the 403 title of a button press; "not stated vs not stated"),
`HomeTasksTest`/`HomeScreenTest` (the pointer card), `UiTemplatesTest` (`listing_decide.php` under the matching pages' rules). Not checked in a
browser (none on this box or on staging): the list in ops.md, "The staff UI", before the deploy.

## Merge of the plain-words redesign with IM6 Receive and IM10 site stock writer (slots `mrg2`, `ui`, `api`, 7 Oct 2026)

`main` (46aa62e: IM6 Receive (+ invoice), IM10 site stock writer, I125-I179, SC6-SC22) merged with `ui-redesign` (3ca90a7: U25-U79), as U79
asked. Both sides' decisions stand; this merge adds the following, all provisional (the owner confirms or reverses each, with U70).

**U80. Deliveries in the task menu.** Main's "Receiving" section is the menu section `receive`, heading **Deliveries**, right after Buying
(`Permissions::MENU`, `Words::SECTION`): **Receive + invoice** (`receiving.view`), **Goods-in bench** (`doc.GRN.post`) and **Incidents**
(`incidents.view`, badge `incidents_open`, "incidents still open" for a screen reader). The item names are main's page headings, so the menu, the
title and the page agree until the receiving screens get their own plain-words pass; each has its line on Home (`Words::MENU_HELP`). Supplier
invoices and returns are not built, so they stay off the menu and in "Coming later" (`Permissions::COMING_LATER` drops only `receive`; `invoices`
stays). Who sees Deliveries follows the permissions unchanged: goods in, the purchasing desk and manager, the stock controller, the reviewer and the
auditor (the reviewer and the stock controller without the bench), the accountant (Receive + invoice only) and the stock manager. The lift rule
(LIFT) is unchanged: a stock controller still gets Products second, Deliveries after Buying.

**U81. The phone tab bar with Deliveries.** `Tabs::PRIORITY` puts the bench before the orders (the delivery check is done on a tablet in the hand)
and Receive + invoice and Incidents after the suppliers, so the three bars the owner approved stay as they were (the owner: To do / Matches /
Duplicates / Orders; a buyer: To do / Orders / To buy / Suppliers; a stock controller: To do / Products / Barcodes / Suppliers). Goods in and the
purchasing desk get To do / Bench / Orders / Suppliers / More; the desk reaches Receive + invoice from More. If the owner wants Receive + invoice on
the desk's bar, the stock controller's third tab becomes Receive (they hold `receiving.view` too): one or the other, the owner's choice. The tab
icons are CSS shapes like the others (a parcel, a parcel with a tick, a warning circle).

**U82. The bench checker is never offered a receipt's review, in the badge and on Home either.** `Documents::decidableCounts()` (one query by kind,
behind the badge `reviews_open`, `Context::checks()` and Home's two cards, U77) carries main's ReviewInvolvement clause (I133): a receipt is not
counted for the person who did its goods-in bench check, or who set its supplier invoice without being its keyer (I172). `decidableCount()` stays
their sum. The review queue keeps listing such a task with the reason (`refusalFor`), as before; the queue's labels are the redesign's
(`Words::docTitle`), a receipt's link goes to its page in Receiving (main's). Test: `ReceivingScreensTest::
testReceiveAPoDeliveryCheckItAtTheBenchPostAndReview` (the bench checker, also a reviewer, gets one check fewer than another reviewer in the
counts, the "Waiting for me" badge and Home's "Done work to check" card).

**U83. Words for what main added to pages the redesign had already worded.**
- The product page's **Selling mode on the websites** section (IM10) takes its words from `Words::SELLING`, `SELLING_NOTICE` (the switch's notices)
  and `SITE_SYNC` (a website's stock link: not started / on trial / live): "site stock writer" reads "stock link", a time is UK time (`$when`),
  the stock rule in words (`Words::POLICY`); the mode names In-Stock, From-Warehouse and Out-Of-Stock are the websites' own and stay.
- A delivery's record page links to its page with "Open the delivery" (`Words::RECORD`); the records list and the review filter name deliveries.
- A block (I139, I160): `Words::CARD['blocked']`, `CARD_NOTICE['confirmed_blocked']` and `CARDS['rules']` add that a delivery of a blocked product
  cannot be booked in and that a website whose stock link is on shows it as Out-Of-Stock by itself; correction d's sentence ("It is still on sale on
  the website: take it off by hand.") stays, true today on every website (no stock link is on yet).
- Jobs: goods in "Checks deliveries at the goods-in bench and books them in." (no "coming later"); the purchasing desk and manager name only
  supplier invoices (and the desk returns and trade sales) as coming later. Home's "Done work to check" card and the "?" on second OKs name
  deliveries booked in (checked within 3 days) and the bench checker.
- Settings: the five settings of 0017 and 0018 have names, help and topics (Deliveries, Websites; `WordsTest` now reads those migrations too).
- Not worded yet, on purpose: main's seven receiving templates (`receipts`, `receipt`, `receipt_edit`, `receipt_bench`, `receipt_files`,
  `bench_list`, `incidents`), the receiving controllers' notices and refusals, and `app.js`'s receiving prompts. They keep main's words and `$uk`
  (kept in `View`, listed in `UiTemplatesTest::HELPERS`); they are not in `UiTemplatesTest`'s "no word typed in a template" lists. Their
  plain-words pass is the next step. Main's receiving rules in `app.css` use design A's tokens (`--surface` for `--panel`, `--needs-line` for
  `--warn-ink`); `app.js` stays one file: the redesign's two conveniences plus main's unsaved-edits guard, photo shrinking, scanner Enter, bench
  find/fill/tick and the PO tick (main's units-per-item note script is left out: the redesign's decision form has no such note).

**U84. Tests** (7 Oct 2026): the full suite in slot `mrg2`: `OK, but some tests were skipped! Tests: 992, Assertions: 44600, Skipped: 75` (the 75
are the HTTP tests of slots `ui`/`api`; the first runs failed on tests that pinned the old menu or the old words of the other side: the menus and
tab bars with Deliveries, the review filter and records list with deliveries, the settings page's receiving settings, the selling-mode words, the
viewport of design A, a receipt editor's error without its code). In slot `ui`: `UiAuthTest`, `UiSecurityTest`, `UiReviewFlowTest` `OK (38 tests,
26331 assertions)` (`/ui/receiving/template.csv` is a download: the sign-in page does not lead back to it, behaviour item 1) and every `UiKernel`
test `OK (103 tests, 5213 assertions)`. In slot `api`: the seven `Api*Test` `OK (35 tests, 2454 assertions)`. The hammer (`--seed=20261007`, slot
`mrg2`): `RESULT: PASS (59 checks passed, 0 failed)`, 105 s. The matching golden tests: `{"passed":59,"failed":0}`. Not checked in a browser (none
on this box or on staging): the Deliveries screens are on ops.md's list for the check before the deploy.

## The delivery screens in plain words and design A (slots `mrg3`, `ui`, 7 Oct 2026)

Code: `src/Ui/Words.php` (new groups `RECEIPT_STATE`, `BENCH_STATE`, `INCIDENT_WHERE`, `INCIDENT_KIND`, `INCIDENT_STATE`, `RECEIPT_FILE`, `STAMP_TYPE`,
`UNSTAMPED_ACTION`, `MODE_SOURCE`, `MODE_MEANING`, `RECEIVING`, `RECEIPT`, `BENCH`, `INCIDENTS`, `RECEIPT_NOTICE`, `RECEIPT_ADDED`, `RECEIPT_ERROR`,
`RECEIPT_PLAN`, `SELLING_ERROR`; `SELLING`, `PAGE_INTRO`, `HELP`, `TASK`, `TONE` grown), `src/Ui/ReceiptWords.php` (new),
`src/Ui/Controller/{Receiving,Incidents,ItemCards,SellingMode}Controller.php`, `src/Ui/views/{receipts,receipt,receipt_edit,receipt_bench,
receipt_files,bench_list,incidents,item}.php` (item.php: its "Selling mode on the websites" section), `src/Ui/{HomeTasks,HomeCounts,Context,Html,View}.php`,
`src/Documents/Documents.php` (`decidableCountsByType()`, read only), `public/ui/assets/{app.css,app.js}`; tests `tests/Unit/{ReceivingWords (new),
UiTemplates,HomeTasks}Test.php`, `tests/Integration/UiKernel/{ReceivingScreens,SellingModeScreen}Test.php`. The step U83 left open: main's seven
receiving templates, the receiving controllers' notices and refusals, `app.js`'s receiving prompts and the product page's selling mode, done the way
the redesign did the other pages (U53-U59). Words, layout, help, the Home cards and display only: no permission, route or service message changed
(`Permissions::MAP`, the services and their tests are as they were).

**U85. One place for the words of the deliveries (the glossary of these pages).** A receipt is a **delivery** (one supplier invoice; the menu
section is Deliveries, U80), posting it is **booking it in**, units are **items** ("3 boxes of 5 = 15 items", as the order pages), an item is a
**product**, the item card a **product card**; MAIN is **into stock**, VERIFY **set aside to check**, UNSTAMPED **the unstamped quarantine**, a
refusal **refused at the door**, short **short (not delivered)**, over **extra**, a wrong item **the wrong product**; "credible" paperwork
"looks right"; "duty evidence" is the supplier's **proof** it was made before 1 Oct 2026. The stock-place codes appear once, in the "?" beside
"Where the items went" ("Older notes and the stock records call the two places VERIFY and UNSTAMPED.", as `HELP['how_sure']` names "Key"). Every
word of the seven templates comes from `Words` (`UiTemplatesTest::testTheDeliveryPagesTurnIntoCardsAndTakeEveryWordFromWords`: no typed word, every
table a `table.stack` with labelled cells, no "(UTC)", `$dt(`, `$uk(`, "(GBP)" or "MiB"); the controllers' `NOTICES` are the `Words` groups. Times
are `$when` ("7 Oct 2026, 10:26"): main's `$uk` and `Html::uk()` ("7 Oct 2026 14:05", U79 point 5) had no other user and are removed, so a page has
one UK-time form. The page intros: Receive + invoice, a delivery being keyed (its keyer; someone else), a delivery booked in, the goods-in bench, a
bench check, Incidents; for a person who can only look, "You can look; <jobs> change this." (`Words::whoCan`). The "?" texts: what booking in does
(`posting`), the duty stamp check (`duty_stamp`), the unstamped rule with **1 Jan 2027** and 31 Mar 2027 (`unstamped_rule`; the bench page still
prints the dates of the setting itself), where the items go (`where_units_go`) and the selling modes (`selling_mode`). "Count" stays a shelf count
(correction b): the bench's tick is **Checked**, not "Counted".

**U86. The services' sentences, said again (the pattern of `Ui\PoWarnings`, U54).** The services keep their messages (they reach the tests, the
posting's refusal and the logs). `Ui\ReceiptWords` (pure, unit-tested on every sentence of `Receiving\ReceiptPlan` and the scan's choice note)
recognises the goods-in plan's 27 kinds of problem and its warnings and says them with the glossary's words, the product's name instead of its CW number,
the website's name instead of its code, the order's state in words and the screens' UK time; a sentence it does not know is shown as the service
wrote it. `ReceivingController::plain()` translates refusals by code (`RECEIPT_ERROR`; the kernel's codes by `Words::ERROR`): a refused booking in
says how many things to settle and points to "Before it can be booked in" (the list itself is the plan's, in words), `duplicate_invoice` and
`invoice_copy_elsewhere` name the other delivery in words, a code without words shows the service's sentence and "Nothing was saved."; the
controller's own refusals (the scan box, nothing found, the truncated form, a stale page, the bench's stale save) are `Words`. The reviewer's
refusal on a delivery says why in the page's words (`RECEIPT['refusal_posted' / 'refusal_created' / 'refusal_bench' / 'refusal_invoice']`; I133,
I172). The selling-mode switch's refusals (`bad_mode`, `bad_reason`, `bad_threshold`, `no_sites`, `unknown_site`, `protected_item`,
`selling_mode_changed`) are `Words::SELLING_ERROR`, through `ItemCardsController::plain` (the product page's one translator). `app.js`'s prompts
(unsaved edits, a photo still shrinking, a file too big, "Not on this page") come from the page's `data-` attributes, English if a page has none
(U76's pattern for "Show"/"Hide").

**U87. Home cards for deliveries (plan §3.2's pattern; `Ui\HomeTasks`, `Ui\HomeCounts`).** Four cards, each with B's "What happens:" line and one
button to a page the person may open: **Deliveries waiting for the goods-in bench** (`doc.GRN.post`: goods in, the desk, the purchasing manager;
deliveries with products whose paperwork is not answered or whose lines are not all checked, not one whose paperwork the bench found not right)
and **Checked deliveries to book in** (the same jobs; the paperwork looks right and every line is checked; the button opens the list's new "Checked
at the bench, not booked in yet" filter), both before the owner's own checks (rank 65 and 70: the goods are in the building and cannot be sold);
**Deliveries booked in to check** (reviewers; rank 145, after "Done work to check"), the GRN part of the reviews from
`Documents::decidableCountsByType()` (one query by type and kind, behind `decidableCounts()` and the badge as before, so **ReviewInvolvement holds**:
a bench checker, the person who set the invoice, its keyer and its booker are never counted, I133), taken out of "Done work to check" so the two
cards add up to the "Waiting for me" badge (`Context::checks()['deliveries']`); **Open incidents from deliveries** (`incidents.resolve`: the desk,
the purchasing manager, the stock controller; the badge's number; routine work, rank 217). One extra Home query for the people who key or check
deliveries (the drafts' bench state, over the few draft receipts).

**U88. Design A on the delivery pages.** The deliveries list and the bench list are B's list cards (`table.stack.list` in a `.table-wrap`: the
supplier's name first, a line with the number, invoice and order, the state, bench and reviewer check as chips, one button). A delivery's page:
its state chips, the intro, the reviewer's box first with **What each answer does** ("OK: it is right" / "Not OK: take the delivery back", what each
does; "Not OK (recorded only)" on a reversal; no Not OK while its order is closed, I176), "Before it can be booked in" with the "?" and **Book this
delivery in** saying what happens, the facts, **Where the items went** as stat tiles, the lines as cards, the problems found, the reviewer
checks as sentences with chips, and "Take this delivery back…" folded, its reason list starting with "— choose a reason —" and required (U65's
rule for Cancel and Correct; the controller already refused an empty reason). The editor follows the order editor (the lines as cards on a phone,
the sheet import and "Cancel this delivery…" folded, a status chip or "N things to settle" beside the buttons). **The bench is tablet first:** one
card per line with the product's name, what the paperwork says in large type, chips for the stamp and the check, the number fields two or three
across on a tablet and one under another on a phone (`auto-fill, minmax(15rem, 1fr)`), every field, tick and button 48-52 px. The save bar
(`.actions.sticky`, the editor's and the bench's) now sits **above the phone's tab bar**: main's sticky bar stuck to `bottom: 0` under the fixed tab
bar below 900 px. Incidents are one card each with its state chip and a small "Close this incident" form ("How it ended": dealt with / nothing
needed). Tap targets 44 px or more everywhere (file links, incident links, the sample-sheet link).

**U89. The product page's "Selling mode on the websites" (IM10).** Its heading has the "?" (`selling_mode`); the table is B's list cards (one card
per website: the stock link as a chip, the mode with its meaning in words (`Words::MODE_MEANING`, not `SellingModes::MEANING`), "how far the stock
link is", the website products as "option 601 (1 sale = 10 products)", the low-stock warning, "the switch, <who>, <UK time>"); the form's mode
choices are whole-row choices with the meaning, the threshold and the reason have hints, and the button says what happens ("Each ticked website
whose stock link is on takes it the next time it reads the warehouse stock."). The mode names In-Stock, From-Warehouse and Out-Of-Stock stay (the
websites' own labels, U83).

**U90. Not done here, and open.** The sheet import's row errors (`ReceiptLinesFile`: "row 2, supplier code: …") and the skipped rows are shown as
the service wrote them (they quote the supplier's own columns). The plan's unknown sentences are shown as written (a new problem is never lost).
`Document::label()` ("draft #12") still names files and service messages. The owner's choices of U81 stand (the desk reaches Receive + invoice from
More). Not checked in a browser (none on this box or on staging): the Deliveries screens on ops.md's list, the bench on a tablet first.

*Tests* (7 Oct 2026): the full suite in slot `mrg3`: `OK, but some tests were skipped! Tests: 999, Assertions: 49977, Skipped: 75` (992 after the
merge, plus 7: the new unit `ReceivingWordsTest` 4 (every code of the delivery pages has its words, the controllers' notices are the `Words` groups,
every problem code of `ReceiptPlan` has words; sentences, "count", no stock-place code outside its "?"; `Ui\ReceiptWords` on every sentence of the
plan; `ReceivingController::plain` and its row helpers, `IncidentsController::screenRow`), the delivery pages' test of
`UiTemplatesTest`, `HomeTasksTest::testTheDeliveryCards` and `ReceivingScreensTest::testTheDeliveryPagesSpeakPlainWordsForEachJob` (14 pages for the
desk, goods in, a reviewer, a stock controller, an auditor and an accountant: title, intro or "You can look;", no code / UTC / MiB / (GBP) / VERIFY /
UNSTAMPED / MAIN / "receipt" / "units" outside the "?", tables as cards; Home's four delivery cards in each state, the bench checker never counted,
the cards adding up to the badge, the list's "checked" filter); the 75 skipped are the HTTP tests of slots `ui`/`api`). Then two wording nits (a
supplier item without a code in the scan's choices; "where the items are" in lower case) and the unit suite again: `OK (263 tests, 33993
assertions)`. In slot `ui`: `UiAuthTest`, `UiSecurityTest`, `UiReviewFlowTest` `OK (38 tests, 26392 assertions)` and every `UiKernel` test `OK (104
tests, 5564 assertions)`. Tests changed with the words: `ReceivingScreensTest` (the screens' words now asserted as `Words` constants: the empty
list, the copied notice, "+5 items (now 4 packs of 5 = 20 items)", "= 15 items on the paperwork", the refusals, "13 into stock, 2 set aside to check",
the bench checker's refusal, Home's "Deliveries booked in to check" card in place of "Done work to check" for the receipt's review, the incident's
title, the reversal, the duplicate invoice, the truncated form, `table.stack.list`) and `SellingModeScreenTest` (the meanings and refusals as
`Words`). `app.js` parses (esprima 4.0.1, run on this box: the merge's "no JavaScript engine" note meant node). Not checked in a real browser (none
on this box or on staging): ops.md's list.
