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
and a return receipt never double-count.

**D37. Cancel with `restockable = false`.** The unit leaves `allocated` at its sale warehouse, then
a `transfer_out` (on_hand −u there) and a `transfer_in` (on_hand +u at `VERIFY`), noted
`cancel_not_restockable`, and a `count_review` at VERIFY (`verify_recount`, dedupe
`verify:<channel>:<unit>`). Nothing is written off. A person later books `write_off` at VERIFY or
moves the unit back with `transfer_out`/`transfer_in`.

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
- The tool has no rebase mode yet. It must be built and tested (estimate → opening_orders → some ships
  → rebase ⇒ available equals the site figure at T0, and on_hand equals max(S_T0, 0) once all opening
  units have shipped) before Phase 3/P reaches T0.
- Until then, nobody books CW counts or adjustments on a site's items between its estimate and its T0.

*Booked so far:* only `cw_staging`, on 2 Oct 2026. The owner chose the "duty-day stock": Vape and Go's
figure at 00:00 BST on 1 Oct 2026, from the archived VPD workbook (xlsx sha256 aecf0178…, input CSV
sha256 3561293a…). 8,199 items, 296,599 units, doc_ref `opening:vapeandgo:2026-10-01T00:00+01:00`.
- Excluded: 20,031 rows at or below zero, 897 rows of unlinked (mostly deleted or draft) listings
  holding 26,191 units, the open paid units, and every movement between 1 Oct and T0.
- It is provisional: the T0 rebase above replaces it in effect.
*Why the actor is a system job:* the booking is mechanical, from an archived file. The approver is
named in the note (`--approved-by`) and here.

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
  takes the lower of that and its own (§12).

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
  (every heartbeat adds one row to each of the three); and a tool to change a channel's allowlist
  or mode (today that is SQL). The hold-expiry cron now exists (`bin/expire_reservations.php`, H4).
- API: each request opens its own TLS database connection. On staging a health call takes
  ~40 ms and a reserve ~100 ms, well inside the site's 3 s budget.
- API: after a restore from backup, CW's `seq` can be lower than versions a site already holds.
  The site then needs a full resync with its versions reset (a connector and runbook step, §2
  "Backups & recovery").
- API: a body over 12 MB is refused by Apache with its own HTML 413 page, not the envelope.
- Hammer/ops: the scheduled jobs run as root on staging because `/etc/cw/app.env` is root-readable
  (H6). Production should run them as a dedicated user that can read `app.env` only.
- Hammer/ops: `bin/health_alert.php` only prints (H8). Wire it to the alert webhook (§6.3) and
  schedule it once there is one.
- Hammer/ops: the feed clock (D39) serialises every stock-changing transaction from its flush to
  its commit. The hammer measured about 70–100 mixed operations/s with 22 concurrent callers
  against the staging cluster: far above today's peak, but it is the ceiling to watch.

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
`merge` (every merge_skus) and `previously_rejected` (M8). A **different** `mapping_lead` approves
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
not offered (it stays a backend action; the tests call it directly).

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
("not in these queues ... nothing merges them on its own"); `docs/ops.md` says the same.

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

## Key spot-check and bulk confirm (slot `mpk1`, 2 Oct 2026)

The owner's two decisions of 2 Oct 2026 (the owner holds `reviewer` and `mapping_lead` on staging). Numbered M26-M28. Code:
`src/Matching/{Band,StoredBand}.php`, `src/Mapping/{ProposalBasis,KeyEligibility,Reband,KeySample,KeyBulk}.php`,
`src/Mapping/Proposals.php` (records the basis), `src/Schema/Grants.php`, `migrations/0010_key_bulk.sql`,
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

**M27. What a proposal was made against: its basis (the owner's "hash check").** `match_proposal_basis` (0010, append-only for
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
- **Older proposals** (imported before 0010) get a basis only when it can be PROVED that nothing changed since they were made
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
  `0009_suppliers.sql`: links only, `--by`, `0010_key_bulk.sql`.

Open after M26-M28 (nothing was run on `cw_staging`):
- Deploy with `0010_key_bulk.sql` (`install_cron.sh --migrate`); then, as the owner, the runbook: re-band (dry run, then
  `--apply --by`), draw the sample (dry run, then `--apply`), confirm or reject the 20 on the screen, then the bulk confirm (dry
  run with `--report`, a canary, then `--apply`).
- Phase I-2 adds `0009_suppliers.sql`; this branch's migration is `0010_key_bulk.sql`. The migrator applies every pending file in
  name order, whichever branch merges first.


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
