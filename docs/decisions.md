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
`nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, a restrictive `Permissions-Policy`, COOP/CORP `same-origin`,
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
revising the plan's "a few seconds each" (plan.md is the spec; no measured figure exists yet).

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

Open after round 2:
- `/etc/cw/initial_staff.txt` still exists on staging and staff 2 (placeholder mapping_lead) is still active: a person hands the
  credentials over, shreds the file and deactivates staff 2 (`docs/ops.md`, "Staff accounts"); `enable_https.sh` refuses until then.
- Not built: the alias decision (U18), merge and unlink screens (U12), the remap correction movement (M24), bulk confirm (U20, rejected).
