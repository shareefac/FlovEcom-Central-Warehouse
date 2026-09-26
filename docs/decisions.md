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
settled with the UI work.

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
- The DecisionService (not built) must call `adoptUnlinkedUnits` inside every link transaction
  (R4); a periodic sweep calling it for linked listings with unlinked held/allocated units would be
  a cheap safety net.
- The connector contract changes: final opening batch after all others answered 200 and with `t0`
  (R1, R12); release always with its attempt (R9); unship with `at` (R10); the ERP-relaying site
  gets `--movement-types` (R17).

