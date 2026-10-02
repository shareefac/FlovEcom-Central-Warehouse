# Central Warehouse for Vape and Go, Vape Big and Electrofag — Plan

## At a glance
- **What:** a new, standalone **Central Warehouse (CW)** — its own small server + private database —
  that owns the real stock of the one physical warehouse. All three websites call it over HTTPS;
  each keeps its own database, prices, content and admin.
- **Part 2 — every ERPNext function moves into CW, then ERPNext is detached (§15):** an audit lists
  everything the business uses ERPNext for; each function is rebuilt in CW (items, stock, suppliers,
  purchase orders, goods received, supplier invoices and payments, valuation, sales records, trade
  invoices, the ledger, VAT, bank matching, reports). CW works out the VAT return; a cheap
  HMRC-recognised "bridging" tool only submits it; the accountant prepares year-end accounts from CW's
  trial balance. After a parallel run (CW only reads from ERPNext, never writes), everything switches
  on one day at a financial-year start — **~30 Apr 2028 with two finance engineers, ~30 Apr 2029 with
  one** — and ~6 weeks later ERPNext is disconnected and switched off (records kept as exports).
- **Urgent, before any of this (§15.1):** Vaping Products Duty starts **1 Oct 2026**, and unstamped
  stock must be off sale by 31 Mar 2027; the current VAT return basis should be checked by the
  accountant (discounts and some refunds never reach ERPNext).
- **Products:** one simple central record per physical item (`CW-000123`, name, brand, barcode + a
  few identity fields). Every site listing is linked to one central ID; each site's admin shows
  "Linked to CW-000123". Site-only products get their own central ID. The first match is done by me
  (rules + AI), **every link is confirmed by a person**; new products later are matched by the same
  rules + Claude in CW.
- **Customers:** nothing changes except that on protected items the race for the last unit is lost
  at "Place order" ("only N left"), not after paying. If CW is down, checkout keeps working (flagged).
- **Staff changes (§13):** stock is corrected and counted in CW, not in site admins; a one-off count
  of best-sellers (≈36–60 h for 80% of units, ≈130–210 h for 95%); a mapping review (≈40–60 h once);
  small daily queues (unmatched goods-in, count reviews, oversell events, new listings).
- **Time:** stock system (Part 1) ≈22–25 engineer-weeks → ~5–6 months with one engineer, ~3–3.5
  with two, then 6–8 weeks of warehouse-paced counting (§12). Purchasing + accounting (Part 2)
  ≈45–65 finance-engineer-weeks more (incl. 20% contingency).
- **Running cost:** CW server + DB + standby (required once CW keeps the books) + backups + write-once
  document storage ≈ $100–180/month; Claude for new-product matching ≈ $10–80/month (§7.5); bridging
  tool £30–200/year per VAT number; ERPNext kept read-only as an archive (~$24–48/month for 12–24
  months); a pen test before the ledger goes live (~£3–8k, estimate).
- **Finance workload after go-live:** ≈60–90 h/month across 2+ finance people (§13).
- **Decisions still needed:** §16 (none blocks starting Part 1).

## Live safety rule — proto first, live only after your sign-off (26 Sep 2026)
Nothing in this project changes the live sites (`App/`, `Website/`, the live databases, live crons or
supervisor) until the whole thing has been built and tested end to end on proto and you have
approved it.
- **Where the work happens:** CW runs on its own **staging** server + database. The connector is
  installed only in the proto copies: Vape and Go `App_proto` / `Website_proto` (`vpg-store.floverfy.com`,
  on the backup database — never the live one), Electrofag proto (`alectrofag_proto`,
  `alt-proto`/`alt-store.floverfy.com`) and Vape Big proto (`vapebig-proto`/`vapebig-store.floverfy.com`,
  once access exists). Proto payment gateways stay in sandbox.
- **Live data is only ever read**, never written: profiling and the first-time product match use
  read-only exports (SELECT inside a read-only transaction). Their results go into CW staging, not
  into any site.
- **Proto rehearsal before live (new Phase P):** every flow in §14 — orders, payments, failures,
  refunds, dispatch, counts, goods-in, outage and recovery, mapping, and (for Part 2) purchasing and
  the books — is run on the three proto sites against CW staging, in `shadow` and then `live` mode.
- **Going live is itself gradual and reversible:** after your sign-off, the connector is copied to a
  live site switched `off`, then `shadow` (the live site behaves exactly as today; CW only listens),
  and only later `live` — each step one config setting, each reversible (§12).
- **Changes that must touch live** (the §11 security fixes, moving the PayPal/Viva watchers off
  `App_proto`) are also tried on proto first and applied to live only with your explicit approval,
  one at a time, with a backup of every file changed.
- **Proto data is stale** (the Vape and Go proto database is a 19 May backup). For a realistic
  rehearsal, a fresh copy of the live databases can be restored into the proto clusters (never the
  other way round) — your decision, since it copies customer data into proto.

## Context
Three storefronts (Vape and Go — this box 161.35.163.191; Vape Big — 159.65.209.7, DB `vapebig`;
Electrofag/Alectrofag — 159.223.245.136, DB `alectrofag_live`) sell from ONE physical warehouse,
but only Vape and Go is wired to ERPNext and each site keeps its own stock number, so the sites
disagree and nothing stops two sites selling the last unit. The 1 Sep plan ("One Warehouse, Three
Shops") made ERPNext the hub; the business now wants a standalone central warehouse instead.

Even on Vape and Go alone the current model leaks: stock is checked at add-to-cart
(`Website/config/modules/cart.php:913-957`) and at order placement without a row lock
(`check_out.php:2318-2376`), never at payment; `stock_out()` subtracts blindly and can go negative;
the hourly ERP pull used to overwrite sales (13.6% reversed, "Phantom Stock" 28 Aug) and is now off,
so nothing reconciles; cancellations restock only when someone ticks a box.

**Decisions taken (26 Sep 2026):** if CW is unreachable, sites keep selling from their own copy and
the order is flagged · CW on its own server + database · progressive counting (each item becomes
protected once counted) · simple central product record linked to each site's listings ·
AI-assisted, human-confirmed matching, first run by me.

**Revised the same day — ERPNext is retired:** purchase orders, supplier invoices, supplier payments,
stock valuation and the accounting ledger all move into CW. Only the VAT *submission* leaves CW: CW
computes the nine boxes and a cheap HMRC-recognised **bridging** tool submits them under Making Tax
Digital. (Research correction to the first choice: Xero/QuickBooks used only for "VAT + bank" would
become a second set of books, because their journals cannot post to bank or supplier accounts — so
bank matching is done in CW from imported statements.) The accountant's software produces the
statutory accounts from CW's trial balance; payroll stays in payroll software. ERPNext's books are
switched off at a financial-year start after a full VAT quarter of parallel running; opening
balances, open purchase orders, unpaid supplier invoices and the supplier list move across, and
ERPNext stays read-only as the 6-year archive. **Open:** which companies trade on which site (public
records point to Love Vaping Ltd, Quick Vapes Ltd and UFA Distro Ltd) and whether they share a VAT
number — the accountant decides; CW's books are built for one or several companies. Fallback if the
accountant or auditor will not accept a home-built ledger: Xero as the ledger of record, fed by CW
(decided at the accountant workshop; Part 1 and the purchasing/stock modules are identical either way).

**Revised again — replace every ERPNext function, then detach:** every function the business uses
in ERPNext is first listed (an ERPNext usage audit), then built into CW, run in parallel, switched
over on one day (D), after which all site↔ERPNext code is removed and the ERPNext server is switched
off; its records are kept as verified exports for the 6 years HMRC requires. CW never writes to
ERPNext.

A draft spec already sits in the shared admin repo (`App/docs/central-warehouse-platform/`, written
1–2 Jul 2026, before the ERPNext v16 cutover). Its ERPNext-replacement decisions (D9/D10: central
procurement + double-entry ledger) are **re-adopted** in Part 2, with fixes (count gains to P&L,
freight and duty capitalised, returns valued at issue cost, income/VAT/deposit/clearing accounts
added). Its create-once central catalogue, price matrix and central dispatch console are not adopted,
and two of its checkout facts are wrong (`09-integration-analysis.md:58,68`). This plan supersedes it.

## 1. Architecture and who owns what

```
                 ┌──────────────────────────────────────────────┐
                 │  CENTRAL WAREHOUSE (new droplet + own DB)    │
                 │  products · stock · reservations · feed      │
                 │  purchasing · payables · valuation · ledger  │──► VAT boxes → bridging tool → HMRC
                 └───▲───────────────▲───────────────▲──────────┘──► trial balance → accountant
   HTTPS, per-site   │               │               │   ◄── bank / gateway statements imported
   key + IP allow-   │               │               │   sites call OUT; CW never
   list              │               │               │   touches a site database
            ┌────────┴────┐   ┌──────┴──────┐  ┌─────┴───────┐
            │ Vape and Go │   │  Vape Big   │  │ Electrofag  │
            │ own DB      │   │ own DB      │  │ own DB      │
            └─────┬───────┘   └─────────────┘  └─────────────┘
                  │ until the ledger cutover (D): sales invoices as today
            ┌─────┴─────┐  until D: purchase invoices → goods-in relayed to CW (read-only use)
            │ ERPNext   │  after D: detached and switched off; records kept as exports
            └───────────┘
```

| Fact | Owner |
|---|---|
| Physical quantity, promised stock, availability, sell policy per item | **CW** |
| Which warehouse(s) a site sells from | **CW** |
| Link between a site listing and a central item | **CW** (confirmed by staff in CW) |
| Listing on/off, price, images, copy, SEO | **each site** |
| Every other ERPNext function in use (suppliers, purchase orders, goods received, supplier invoices, payments, valuation, ledger, receivables, VAT, bank reconciliation, reports — full list from the audit, §15.2) | **CW** from the switch-over day D (ERPNext until then); VAT *submission* via the bridging tool |
| What to reorder (stock + demand across all sites + open POs) | **CW figures** (purchasing view) — ERPNext's own stock figures stop being used for reordering once sites are live |

Sites never talk to each other. CW never holds or uses site database credentials.

### The three sites today (verified 26 Sep, read-only)
| | Vape and Go | Electrofag | Vape Big |
|---|---|---|---|
| Completed orders/day | ~1,000–2,300 (≈1,475 avg) | 12–45 | ~27 (5 Sep) |
| Catalogue | 29,105 variant rows; In-Stock ("sell regardless") variants sold **77% of units** in 30 days | 9,014 variants, 7,076 In-Stock mode (almost all qty ≤ 0) | unknown |
| Stock source | ERPNext → `api_stock_update_logs` → `App/api/stock_worker.php` | typed in by hand on its From-Warehouse variants (none since 27 Aug); no feed | unknown; admin stock screens on by default |
| Code | one admin repo for all brands (`tabsyst/FlovEcom-Sandbox-Admin-Panel`, `$GB_MODE` switch); storefront repo per brand; live trees are **not** git checkouts | same code, ~3 months behind (21 vs 53 migrations) | same family |
| Reachable from here | local | DB (full-admin account) | DB **not** reachable (other DO account); SSH/443 open |

## 2. The Central Warehouse service

**Stack:** PHP 8.3 + Apache/php-fpm + MySQL 8 (the team's stack, no framework; Composer for PHPUnit
and the official Anthropic PHP SDK `anthropic-ai/sdk`, matching workers only). Repo
`tabsyst/FlovEcom-Central-Warehouse`. DO droplet in LON1 (2 vCPU/4 GB) + DO managed MySQL whose
trusted sources = the CW droplet only. `warehouse.floverfy.com` is **DNS-only (not behind
Cloudflare)** so the IP allowlist sees real client IPs; the allowlist holds each site's egress IP
(checked with a test call from each box) in CW's `channel` table. Supervisor + cron as on the shops.

**Backups & recovery:** managed MySQL daily backups + 7-day point-in-time restore; plus a nightly
encrypted `mysqldump` of the CW schema + grants to DO Spaces (30 days); weekly droplet backups;
secrets (`/etc/cw/*`, per-site keys) in the team password manager. Each site keeps delivered outbox
rows for 14 days, so after a restore `bin/cw_replay.php --since=<restore point − 10 min>` re-sends
them (every call is idempotent). Quarterly restore test into staging. A standby node is optional
(~$30/month) — CW downtime only makes orders "unreserved", it never stops sales.

```
central-warehouse/
  public/index.php     /v1/* API (bearer key) + /ui/* staff screens (session + TOTP)
  src/Stock.php        THE ONLY writer of stock_balance / stock_ledger / stock_change
  src/Reservations.php reserve / commit / release / cancel / ship / unship / return / expire
  src/Availability.php per-listing availability + change feed
  src/Matching/*       normalise, candidates, hard vetoes, bands, DecisionService (§7)
  src/Ai/*             Claude client, batch runner, schemas, prompts, cost log (§7.5)
  src/Api/* src/Ui/*   incl. review, count, queues, purchasing report
  migrations/*  bin/* (expiry cron, prune, replay, import)  tests/ (+ concurrency hammer)
  tools/first_match/   export + run scripts for the first-time match (§7.3)
```

### 2.1 The central product record (kept simple)
`sku`: `code` (`CW-000123`, never reused), `name`, `brand`, barcodes (`sku_barcode`), `sell_policy`,
`counted_at`, plus a small **identity card** — each field exists only because it separates items the
catalogue really confuses (NULL = unknown):

| Field | Stops this real mix-up |
|---|---|
| strength_mg, nic_type | 10 mg vs 20 mg; salt vs freebase |
| line (incl. modifier/number) | `Bar` vs `Bar Plus`; `Corex 2.0` vs `3.0`; `600` vs `6000` |
| form | kit vs refill pods; disposable vs e-liquid |
| flavour | "Blue Razz" vs "Blue Razz Lemonade" |
| volume_ml, puffs, pack_units | 2 ml vs 10 ml; 1-pack vs 2-pack |

Filled by the matcher, confirmed at the count (the counter reads the box). Prices, images and copy
stay on the sites.

### 2.2 Tables (InnoDB, one collation `utf8mb4_0900_ai_ci`, all timestamps UTC)
| Table | Key columns | Notes |
|---|---|---|
| `warehouse` | id, code, is_sellable, owner_entity (Part 2) | day one: `MAIN` + non-sellable `VERIFY` (doubtful cancels) and `UNSTAMPED` (duty sweep, §15.1); later `RETURNS` |
| `sku`, `sku_barcode` | §2.1; barcode PK, is_usable, units_per_scan | a barcode found on 2 items is unusable until fixed |
| `sku_erp_item` | item_code → sku | **override only**; ERP goods-in normally resolves via Vape and Go's listing link (§9) |
| `stock_balance` | (warehouse, sku) PK, on_hand, allocated, held | cached sums; **available = on_hand − allocated − held** |
| `stock_ledger` | id, warehouse, sku, **bucket** (on_hand/allocated/held), qty_delta, balance_after, movement_type, channel, order/unit ref, idem_key, actor, created_at | append-only (no UPDATE/DELETE grant); every bucket change journalled |
| `reservation` | (channel, order_ref) UNIQUE, status held/committed/released/expired, attempt, lines_hash, origin reserved/unreserved/opening, expires_at | state machine §3 |
| `reservation_unit` | (channel, unit_id = `ordi_id`) PK, reservation, listing, sku NULL, units_per_item, warehouse, state held/allocated/shipped/cancelled/released/returned, dispatched_at | one row per sold unit (`orders_items` has no qty column); snapshots the link at sale time, so reversals never depend on today's link |
| `channel` | code, mode off/shadow/live, api_key_hash, allowed_ips, reserve_ttl_sec, T0 watermarks | one row per website |
| `channel_warehouse` | (channel, warehouse), priority | **v1: exactly one sellable warehouse per channel** (constraint) |
| `channel_listing` | (channel, external_variant_id) UNIQUE, sku NULL, units_per_item, status unmapped/suggested/mapped/quarantined/ignored, map_version | the link; changed only by `DecisionService` |
| `listing_profile` | listing, titles, brand, attributes, barcodes, price, perma_link, units_30d/365d, hashes | matching inputs, written by `PUT /v1/listings` |
| `stock_change` | seq (global, AUTO_INCREMENT), sku NULL, listing NULL, channel NULL, reason | change feed; the global `seq` is every listing's version (§3) |
| `oversell_event`, `goods_in_suspense`, `count_review`, `policy_review`, `channel_health` | | the staff queues + per-site heartbeat |
| matching tables | `match_proposal`, `match_decision`, `listing_map_history`, `ai_request`, `ai_call_log`, `alias` | append-only, with provenance (§7) |
| `staff_user`, `audit_log`, `idempotency` | | TOTP logins; every action attributed; stored responses per (channel, idem key) |

### 2.3 Three buckets and three kinds of order line
- `on_hand` = units physically in the building. Falls only when goods leave (dispatch), rises on
  goods-in/returns. A count sets it.
- `allocated` = paid, not yet dispatched. `held` = unpaid checkouts (expire after the TTL).
- **All quantities inside CW are in central (SKU) units.** A listing unit converts with the link's
  `units_per_item` (u): a "10 x 10ml" listing moves 10 units; the site is shown floor(available / u).

| Line kind | Moves CW's buckets? | Can CW refuse it? | Does CW write the site's stock/mode? |
|---|---|---|---|
| **unlinked** (listing not linked) | no — recorded only (holding ledger) | no; the site's own placement check applies | no |
| **linked, `legacy`** (not yet counted) | **yes** (held → allocated → shipped) | no; the site's own placement check applies | no |
| **managed** = linked, policy `strict`/`backorder`/`stopped`, and the site is `live` | yes | `strict`: when short · `stopped`/quarantined: always · `backorder`: never | yes (§6.5) |

Because linked-legacy sales already move the buckets, when an item is counted and becomes protected,
every in-flight order is already inside `allocated` — nothing is lost at the switch.

## 3. API (JSON over HTTPS; `Authorization: Bearer <site key>` AND IP allowlist; every POST carries `Idempotency-Key`)
| Call | Sent when | Behaviour |
|---|---|---|
| `POST /v1/reservations` `{order_ref, lines:[{variant_id, qty, unit_ids[]}]}` | Place order, before the payment redirect | `order_ref` = the site's `ord_id`. Sums need per item across lines (qty × u), locks rows in fixed order, checks strict items only, `held += need`. By current state: new → 201 held / 409 short (per-line `available`); held + same lines → extend TTL; released/expired → fresh attempt (attempt+1) or 409; committed → 200 "already paid"; held + different lines → 422. `stopped` items and quarantined listings → 409. Unlinked lines return `unlinked`. |
| `POST …/{ref}/commit` `{lines, origin}` | payment captured | held → allocated (always carries the lines, so it also works with no hold: outage orders, late or resurrected payments). Never refused; a strict item going below zero → `oversell_event`. |
| `POST …/{ref}/release` `{attempt}` | payment failed/abandoned before paying | held → released; a stale `attempt` is ignored; on a committed order → 409 `use_cancel`. Unknown ref → stored tombstone (a late reserve then creates no hold). |
| `POST …/{ref}/cancel` `{unit_ids, restockable}` | paid units cancelled before dispatch | allocated → cancelled (goods never left); `restockable=false` → units move to the non-sellable `VERIFY` location + recount task; written off only when a person or the recount confirms the loss (staff often leave the tick off) |
| `POST …/{ref}/ship` / `unship` `{unit_ids, dispatched_at}` | worker saw units shipped / a dispatch reset | allocated −u, on_hand −u (unship reverses) |
| `POST …/{ref}/return` `{unit_ids}` | return received after dispatch | on_hand +u (later: into RETURNS for inspection); key `return:<ordi_id>` so a refund-with-restock and a return receipt never double-count |
| `POST /v1/opening_orders` | once, at a site's shadow start | paid-not-shipped units → committed (`origin=opening`) |
| `POST /v1/movements` `{type, lines, doc_ref}` | ERP relay, staff screens | `goods_in`, `supplier_return`, `erp_sale`, `adjustment`, `count` (with `counted_at`), `write_off`, `transfer_out/in` |
| `GET /v1/changes?after=<seq>` | site worker every 2 s | per **listing**: link status, central code, policy, u, available, state, version = global `seq`; stock, link, policy and warehouse changes all appear |
| `GET /v1/availability?variant_ids=` · snapshot | cart pre-check · every 15 min | current values, same version rule |
| `PUT /v1/listings` | on product save (15-min hash delta) + nightly | writes `listing_profile` only (titles, brand, attributes, all barcodes, price, perma_link, units); `DecisionService` creates the `unmapped` listing row |
| `POST /v1/heartbeat` | site worker every 60 s | outbox depth/age, dead-letters, last seq, site mode, connector version |
| `GET /v1/purchasing` | Vape and Go admin reports | per item: on_hand, allocated, held, available, policy, counted_at, 90-day units by site |
| `GET /v1/health` | circuit breaker | returns `channel.mode` |

Lock order in every transaction: reservation row → `stock_balance` rows by (warehouse, sku) →
`sku` rows. The expiry cron selects ids without locks, then processes each through the same path.

## 4. One sale, end to end (the oversell guarantee)
1. **Place order** → `reserve`. Two sites racing for the last unit of a strict item serialize on the
   same row lock; the loser gets 409 before paying and sees "only N left".
2. **Paid** → `commit` (held → allocated); the new availability is applied on the site at once.
3. **Failed / expired / abandoned** → `release`; CW also expires holds after the TTL (default 40 min:
   the GlobalPay watcher runs at 30 min + 5 min TTR). A failed order later resurrected and paid simply
   commits without a hold.
4. **Dispatched** → the site worker reports each shipped unit → on_hand and allocated fall together
   (availability unchanged).
5. **Every site** polls `/v1/changes` every 2 s → the site database and the product page's live data
   show the new figure within ~5 s; checkout is always decided by CW; listing pages keep their
   600 s cache (a site-wide cache flush would recreate the 19 Sep outage).

Values, not deltas, travel on the feed (CW is the only writer). Re-delivered or out-of-order changes
are harmless: a site applies a value only if its version (the global `seq`) is newer, and each poll
re-reads a small overlap window. **Residual windows**, always flagged as `oversell_event`, never
silent: a payment captured after its hold expired when the stock sold meanwhile, and orders taken
during a CW outage. Rule: the later-paid order is back-ordered or refunded by an operator.

## 5. When CW is unreachable (decided: keep selling, flag)
- Client: 1 s connect / 3 s total; the circuit opens after 5 failures in 60 s; probes `/v1/health`.
- While open, Place order runs the site's existing placement block (`check_out.php:2318-2376`)
  against its own `prodt_stock` (not `check_stock_available()`, which would count the cart twice),
  marks the order `unreserved`, and decrements the local copy at payment as today; the commit (with
  its lines) is queued.
- **Recovery, in order:** deliver all queued outbox rows (outage failures never count towards
  dead-lettering) → only then resume live reserves; until every `unreserved` commit is acknowledged,
  the worker writes `available − pending unreserved units` so the feed cannot re-open sold stock.
- CW flags any shortfall; nothing about checkout depends on CW being up.

## 6. The site connector (same module on all three sites)

### 6.1 Pieces and packaging
- Admin half in the shared admin repo (`App/app_config/modules/central_warehouse.php`,
  `App/src/central_warehouse/`), storefront half ported per brand
  (`Website/config/modules/central_warehouse.php`). **Shipped as a self-contained drop-in** (new files
  + one standalone SQL file + small edits at the listed hook sites + a checksum manifest), so
  Electrofag gets it without pulling 3 months of unrelated changes. Phase 0 re-locates every hook by
  file:line in each sister site's own code (their live trees differ).
- Local tables: `cw_outbox` (kind, order_ref, idem_key UNIQUE, payload, status, attempts,
  next_attempt_at, sent_at — kept 14 days), `cw_order_state` (ord_id, state, attempt),
  `cw_listing_state` (one row per pushed listing: link status, CW code, policy, u, version, managed
  flag, and the listing's pre-CW mode/backorders to restore if it leaves CW), `cw_unit_state`
  (ordi_id, ship/cancel/unship sent).
- Config outside web roots: `/etc/vpg/central_warehouse.php` (URL, channel, key, `CW_MODE`).
- **Linked badge:** the variant list/edit page shows "Linked to CW-000123 · strict" or "Not linked";
  the product list shows "3 of 4 variants linked" (from `cw_listing_state`).

### 6.2 Fail-safe rules
- `cw_mode()` returns `off` if the config is missing or unreadable; in `off` every hook returns before
  any SQL or HTTP. In `shadow`, any CW-related failure is caught and logged — it can never stop an
  order completing.
- **Effective mode = the lower of** the site's `CW_MODE` and CW's `channel.mode` (read from every
  response); a mismatch alerts. The site setting alone can always roll a site back, even with CW down.
- Deploy order per site: run the SQL → confirm the tables exist → deploy code → set `CW_MODE`.

### 6.3 The worker (`cw_sync_worker.php`, supervisor)
`catch (\Throwable)`, delayed releases, bury after N **definitive** failures (lessons from the payment
watchers). Each loop: (1) drain `cw_outbox` in id order, a row done only on CW's 2xx; (2) sweep open
committed orders' units → `ship` / `unship` / `cancel` (§6.4); (3) poll `/v1/changes` and apply with
the version guard; (4) every 10 min a **reconciler**: Completed orders with no acknowledged commit →
enqueue commit; Failed/Cancelled orders still `held` → release; (5) 15-min listing hash delta +
nightly full push; (6) heartbeat. Health: outbox depth/age, dead-letters and feed lag are added to
`App/tools/worker_health_check.php` (and deployed to the sister sites, which lack it); CW also
alerts centrally from `channel_health`. One webhook is configured in `/etc/vpg_worker_health.conf`
(0 today — email only).

**Gotcha:** supervisor runs the live PayPal and Viva timeout watchers from **`App_proto/pheanstalk/…`**
(`/etc/supervisor/conf.d/App-pheanstalk-{paypal,viva}-timeout-watcher.conf`) — fix those paths
before adding hooks, or the hooks never run on live.

### 6.4 Hook points (Vape and Go, verified; re-located per sister site in Phase 0)
| Event | Where today | Connector change |
|---|---|---|
| **Place order → reserve** | placement block in `item_to_order` (`check_out.php:2318-2376`, called from `convert_to_cart` :1492, before the cart is deleted at :1697) | move the block into `local_placement_check()`; in off/shadow run it on every line; in live run it on unmanaged lines **and** call `reserve` built from the inserted `orders_items` rows (fixes the upsell undercount at :2258); 409 → throw → existing rollback → `/checkout?error` |
| rollback after a reserve | any throw after the reserve (`convert_to_cart` catch :1710, widened to `\Throwable`) — covers all four gateways' deadlock retries | synchronous `release` (outside the DB transaction) + CW tombstone; TTL is the backstop |
| retry payment on the same order | `create_order.php` retry branches: GlobalPay :33-67, PayPal :44-72, Viva :33-63, Worldpay :33-63 | `reserve` again (extend / new attempt / "already paid") |
| **Paid → commit** | the one claim `check_out.php:789-793` → `stock_out()` :928-980; reached from `order-completed.php:489`, `webhook.php:415`, `cron_get_update.php:332`, GlobalPay worker :154, `mark_failed_for_retry.php:203`, `capture.php:220`, `retrieve_transaction.php:53`, `worldpay/webhook.php:132` | inside the claim, write the commit (with lines) to `cw_outbox`. Where the connection is in autocommit (PayPal `capture.php`, Worldpay webhook) wrap claim + outbox insert in a short transaction committed **before** `stock_out()`; never open one where autocommit is already off. Skip the local decrement (:969-980) only for managed lines of non-`unreserved` orders. The reconciler catches any lost commit. Idempotent per `ord_id`, which also kills the Completed→Processing→Completed double decrement (`order-completed.php:489`) |
| **Failed / cancelled before payment → release** | `update_order_status` Failed :824-828 and Cancelled :838-873; direct UPDATEs: `release_stranded_order` (:1208), `mark_failed_for_retry.php:221`, Viva worker :103, Worldpay worker :43, Payroc worker :43, `auto_order_cancel.php:69` | `release` if `cw_order_state` is held; **`cancel`** if it is committed |
| GlobalPay worker resurrects an order | `check_timeout_globalpay/timeout_watcher.php:205-240` | reserve again (new attempt) |
| **Staff/phone ("office") orders** | `App/app_config/modules/order.php:626-668` | reserve + commit under that order's own `ord_id` |
| **Whole paid order cancelled by staff** | `order.php` Cancelled branch :339-394 / :607-613 | `cancel` all open units, restockable (goods never left) |
| **Line cancels** (`restock_and_cancel_items.php`, both refund branches in `refund_service.php:421-436`, dispatch `cancel_and_restock_remaining.php:37-53`) | many files | **no per-file hooks:** the worker's unit sweep sees `ordi_iscancelled` 0→1 on committed orders and sends `cancel` with `restockable = ordi_restock` |
| **Dispatch** (`mark_as_dispatched.php`, `fetch_scanned_order*.php`, `dispatch.php:248,1045`, packing slips) | many files | **no edits:** the sweep sends `ship` per unit when `ordi_shipped=1` and its order's dispatch-log row is `Generated` (never on `Pending`/`Cancelled` rows); `dispatched_at` = that row's shipped/added time (London → UTC); units grouped by their own `ordi_ord_id` so office child orders ship against their own reservation; a reset by `clear_dispatch.php` → `unship`; nightly 30-day backstop |
| Return after dispatch / refund with restock after dispatch | `order_return.php:850-867`; `refund_service.php:421-434` | `return` keyed `return:<ordi_id>` (the two paths de-duplicate) |

### 6.5 Local stock writers, and what CW writes into the site
- **Default: refuse.** In live mode `order_stock_add()` / `update_stock()`
  (`App/app_config/modules/order_stock.php:5-78, 179-222`) throw for a managed item unless the caller
  passes `cw='event'` (it has already queued its CW event; the local write is skipped). Site admins
  never edit managed stock — a link sends them to CW. Guarded the same way: variant save
  `products.php:1242-1433` (mode/backorders), `products/ajax/bulk_update_stock.php:53-71`,
  `products/ajax/sku/update_stock.php`, `inventory_stock/ajax/transfer_stock.php`,
  `inventory_stock_scan/ajax/update_stock.php:58`, `Mobile_app/product_list/stock_update.php`, the ERP
  endpoints (§9), `App/src/correction_scripts/stock_from_sheet.php`; `src/woocommerce-fetch/` is
  web-blocked. Phase 0 greps each sister codebase for any other `prodt_stock`/`prodt_stock_mode` writer.
- **CW writes (managed listings only),** matching `stock_check()` (`Website/config/php_functions.php:445-473`,
  admin copy `order_stock.php:224-252`, cart copy `cart.php:959-982`): `strict` → `From-Warehouse`,
  backorders 0, `prodt_stock = floor(available/u)`; `backorder` → `From-Warehouse`, backorders 1,
  same figure (may be negative — still the purchasing "arrange" signal); `stopped` or a quarantined
  listing → `Out-Of-Stock`; legacy/unlinked → nothing. Leaving CW restores the stored pre-CW mode.
- Back-in-stock mail fires whenever `stock_check()` would flip Out-Of-Stock → In-Stock, whether the
  quantity or the mode changed (the mode-only case is what caused 81% of missed mails in August).
- Product page / `get_live_data.php` stock read becomes an uncached primary-key lookup.

## 7. Products: the central catalogue and AI-assisted matching

### 7.1 Scope and rules (decided with the business)
- **Every published listing on every site ends linked to one central ID or deliberately ignored**
  (placeholder/parent rows: Vape and Go 2,795, Electrofag 1,058; unsupported mixed bundles). "Not
  linked" is temporary, with a count and age per site on the dashboard.
- **Vape and Go is the seed:** one central ID per published, non-placeholder Vape and Go listing
  (~14,856; rules only — it cannot create a cross-site false merge), its own duplicate listings merged
  later by a person. New Vape and Go listings get a `legacy` central ID automatically.
- **Electrofag and Vape Big** listings either link to an existing central ID or get a new one
  (products Vape and Go doesn't sell). Order of work: by units sold, then the unsold long tail.
- The AI never links anything; a person confirms every cross-site link, one at a time, with the
  candidate pre-selected when a usable barcode and the AI agree (a few seconds each).
  *Owner's decision, 2 Oct 2026 (`docs/decisions.md` M26-M28):* Key starts at judge confidence 85; and once a
  mapping lead has confirmed every proposal of a seeded 20-proposal spot-check of the Key band, the rest of that
  sample's Key proposals may be confirmed by that lead in one CLI step (one DecisionService decision each, nothing that
  needs two people, undoable by batch). One rejection in the spot-check stops it.
  **Two people** only for: links/unlinks/merges touching an already-protected item,
  `units_per_item ≠ 1`, and mapping-driven stock corrections on protected items.

### 7.2 What the data says (profiling, 26 Sep)
- **Barcodes are the fast lane:** 2,072 barcoded Electrofag variants → 2,063 after removing
  placeholders → 1,977 clean one-to-one pairs with Vape and Go; they carry **≈94% of Electrofag's
  30-day units**. But 4 barcodes sit on >1 Vape and Go item, some Electrofag listings carry two EANs
  for two different items, and ~2,526 Vape and Go variants have extra barcodes (outer cases?).
- **Transfer lane:** 1,211 of 1,274 products copied to Electrofag with `db-transfer` since April
  resolve to exactly one Vape and Go variant by permalink; `App/db-transfer/transfer.php` will also
  record source → copy ids from now on.
- **Names alone are not safe:** a rules/fuzzy ranker reaches 97.0% precision at 68.6% coverage —
  3 wrong merges per 100, each a corrupted shared stock number.
- **Real traps:** strength siblings, salt vs freebase, `Bar` vs `Bar Plus`, `600` vs `6000`,
  `Corex 2.0/3.0`, kit vs refill, flavour supersets, "10 x 10ml" multiples, relabelled lines
  (Electrofag "Crystal Pro Max" ↔ Vape and Go "Hayati Pro Max" share 16 barcodes), distributor names
  used as brands.

### 7.3 The pipeline (rules first, AI second, a person last)
| Step | What happens | AI? |
|---|---|---|
| 1 Ingest | listing + all attributes + barcodes (`inventory_sku_barcode`, never `isku_no`) + units + perma_link | – |
| 2 Normalise | ported from the dormant `App/app_config/modules/product_mapping.php` (`normalize_variant` :121-277, `norm_barcode` :96-109, blocking :983-1073, `score_pair` :1218) with its bugs fixed (unknown pack defaulted to 1 at :146; an AI "no match" did not clear the suggestion at :2110-2118; wrong AI verdict shown at `controller.js:462,467`) + GTIN check digit, "6K"→6000, form as a field | – |
| 3 Extract | the model reads each name → brand/line, form, flavour, strength, nic type, ml, pack, puffs, colour — each with a verbatim quote; no quote → unknown | yes |
| 4 Candidates | usable barcode → transfer link → identity key (pack included) → brand/line block + confirmed aliases → top 15 | – |
| 5 **Hard vetoes** | plain PHP the AI cannot override: strength, nic type, form, line/modifier, ml, puffs, colour, pack × u, flavour superset/difference beyond confirmed synonyms | – |
| 6 Judge (barcode-blind) | the model picks one candidate or answers no-match / can't-tell / several-fit, field by field; it never sees barcodes or IDs, so it is an independent second check | yes |
| 7 Band | **Key** (barcode or transfer link + AI agree) · **Check** (AI only, or soft flags) · **New item** · **Can't tell** · **Conflict** (AI vs barcode, vetoed pair, barcode on 2 items → mapping lead) · **Ignore** | – |
| 8 Decide | the review screen: listing and central card side by side, differing fields highlighted, AI answer + quotes, scanner lookup; only `DecisionService` changes a link | – |

**First-time match (me):** committed code in `tools/first_match/`: `export.php` (SELECT-only inside a
read-only transaction; run on each site's own server — so Vape Big needs only someone to run it there,
or SSH, not a new DB user) → gzipped snapshots; steps 2, 4, 5, 7 are the shared `src/Matching`
classes; steps 3 and 6 are done by me in session against committed prompts and JSON schemas, every
answer stored with run id, model and prompt version. Before staff see anything I report accuracy
against the 1,977 barcode pairs (run barcode-blind) and a 300-item blind staff sample. Re-runnable by
anyone after answers to §16 Q2/Q3 or when Vape Big's export arrives.

**Keeping links safe after go-live:** links are sticky (an edited listing raises a flag, never a
silent unlink); a veto firing against a protected item **quarantines** that one listing (shown out of
stock) until reviewed; a wrong link found later is fixed by a recount of both items and a paired
correction movement; the dispatch scan that already logs 'Wrong item'
(`dispatch/ajax/item_scanned.php:68,90`) raises an alert when the scanned code belongs to another item;
a monthly 1% blind spot-check of confirmed links.

### 7.4 The count gate (before an item becomes protected)
The counter scans the box barcode (belongs to another central ID → hard stop and merge review),
confirms strength/flavour/ml/pack/form from the box, and ticks "these listings all describe this
item" for every linked listing on every site. A rules-only duplicate sweep for that item must be
clear, and **every site selling from that warehouse must be `live`**. The manager then picks the
policy, pre-filled from the listing's mode today: In-Stock → `backorder` (keeps selling below zero,
as today), From-Warehouse → `strict`, Out-Of-Stock → `stopped`. The choice is audited.

### 7.5 Claude inside CW (new and changed listings, after the first run)
- New listings without a barcode or transfer link go to a nightly Message Batch (50% price, results
  keyed by `customId`); a listing that would touch a protected item, or the "Ask AI" button, runs
  synchronously. Structured outputs (`outputConfig` → `json_schema`, `additionalProperties:false`,
  candidate refs as enums); frozen system prompt cached; `stop_reason` checked on every response
  (refusal / max_tokens / invalid → parked, never guessed); served model logged.
- Model: **`claude-opus-5`** by default; `claude-sonnet-5` or `claude-haiku-4-5` are your choice after
  the pilot's accuracy table (re-tested on that model).
- Only catalogue text leaves the building (serializer allow-list test); product names are data, not
  instructions. Key in `/etc/cw/anthropic.env` (worker only), own Console workspace + spend limit.

| Monthly estimate (~560 listings judged + duplicate checks) | Opus 5 | Sonnet 5 | Haiku 4.5 |
|---|---|---|---|
| Claude cost | ~$60–80 | ~$25–30 | ~$10–13 |

Proposed cap: $100/month ($15/day batch, $5/day sync). (Running the first match through the API
instead of by me would cost ~$315–475 once on Opus 5.)

Full matching design record (schemas, band rules, test sets, the 40 review fixes): workflow
`wf_761ea40a-40e` journal under
`/root/.claude/projects/-var-www-html-vpg-ecom/5e8efa21-e26d-42dc-be75-4ec4dcbf767f/subagents/workflows/`
— committed as `docs/matching-design.md` in the CW repo in Phase 0.

## 8. Opening stock, counting, and when the requirements are met

### 8.1 Starting a site (T0 = the moment it enters shadow)
- Record watermarks: highest completed `ord_id`, highest `api_stock_update_logs` id. Only events after
  them reach CW; goods-in before the watermark is already in the opening figure.
- **Opening orders:** every paid, not-shipped, not-cancelled unit (≈11.7k on Vape and Go today) is
  sent once as committed reservations → `allocated`, so their later dispatch balances.
- **Opening on_hand is an uncounted estimate, never shown to a customer:** Vape and Go's own
  `prodt_stock` (≥ 0) × u + its open paid units; 0 for items only on the sister sites. ERPNext's
  stock feed is **not** read: reading `get_product_stock_sync` flips pending purchase invoices to
  Synced as a side effect, which would stop their goods-in push.

### 8.2 Counting
- Order: by units sold (weekly re-ranked), on a tablet + scanner in the CW count screen.
- **Count everything in the building that is not already in a labelled parcel** (shelf, totes,
  packing bench). The count carries `counted_at` (when the counter starts the item): CW sets
  `on_hand = counted − ships already applied that were dispatched after counted_at`; a later ship
  dispatched before `counted_at` only reduces `allocated`; ships within ±10 min go to count review.
  So late dispatch reports can never subtract twice.
- The count is also the identity check and policy choice (§7.4).

### 8.3 Where each requirement stands
| Requirement | Protected items (`strict`/`backorder`/`stopped`) | Linked but not yet counted | Not yet linked |
|---|---|---|---|
| One source of truth; a sale anywhere updates it at once | met | met inside CW (sites keep their own figure) | not met |
| All sites show the same available stock | met (≤5 s; listings ≤600 s) | not met | not met |
| No overselling | met (residual windows flagged) | not met (today's behaviour) | not met |
| More sites / warehouses later | met by design (§10) | | |

**KPIs:** % of 30-day units on protected items (per site); % of items with any sale in 365 days that
are protected; units sold on unlinked listings per site. **End state:** every item with a sale in the
last 90 days is counted and protected by week 8 of counting; any other item joins the count queue on
its first sale.

## 9. ERPNext until it is retired (transitional — Part 1 only)
This section describes how CW lives alongside ERPNext **until the switch-over day D** of Part 2
(§15), when purchasing moves into CW, this relay is replaced by CW's own goods-received screen, and
ERPNext is detached. CW only ever *reads* from ERPNext.

**Goods-in rule — one route per item.** Items bought through ERPNext get goods-in **only** from the
ERPNext relay; CW's goods-in screen refuses them (break-glass: a lead books against a named purchase
invoice during a relay outage; the later relay posts only the difference). Items with no ERPNext item
(sister-site-only) get goods-in only from CW's screen, with supplier + document number as the key.

| ERP channel (verified) | Volume / 30 days | From the site's T0 (whatever its mode) |
|---|---|---|
| `update_purchase_invoice.php` → `api_stock_update_logs` → `stock_worker.php` (cron */3) | +514k units goods-in; −11.7k units ERPNext sales invoices | each line forwarded by document type, key = document name + line index (never per variant — 116 lines/month repeat a variant): purchase invoice +q → `goods_in`; −q (debit note) → `supplier_return`; sales invoice −q → `erp_sale` (ERPNext's own sales are a fourth consumer of the shelf); resolved via Vape and Go's listing link × u; unresolved → `goods_in_suspense`. The local write is skipped only when the item is managed and the site is live. `get_status.php` keeps answering ERPNext's poll. **No ERPNext change needed.** |
| `update_product.php` (mode + low-stock threshold) | ~4,000 | mode → CW **policy-review queue** (In-Stock → propose `backorder`, From-Warehouse → `strict`, Out-Of-Stock → `stopped`); threshold still applied |
| `stock_reconcillation.php` (target quantity) | ~131 | → CW **count-review queue** (a person confirms it as a count) |
| Sales invoices site → ERPNext (`salesorder.php`, `data_sync_processor.php`) | ~42k | unchanged |
| `erp_stock_pull_sync.php`, `erp_mode_sync.php` | off | stay off permanently |

**Purchasing view:** `GET /v1/purchasing` + nightly export feed the existing Vape and Go reorder
reports with CW figures (all sites' demand). ERPNext's own stock figures (and any auto-reorder) must
not drive reordering once sites are live — they never see Vape Big or Electrofag sales.

**Until D, ERPNext's books do not see the sister sites' sales** (they never have). Rather than CW
writing into ERPNext, CW produces a monthly per-site stock-issue and sales summary for the accountant
to journal in whatever holds the books until D (agreed at the workshop). Separately (found here):
three site→ERP refund channels have
**never sent anything** — `ordi_refund_syncd` / `ord_shipment_refund_syncd` are BIT(1) columns set
with the string `'0'` (`refund_service.php:273,442`): 9,425 cancelled items and 3,280 shipping refunds
(~£73k since Dec 2025) missing from ERPNext — for the accountant now (§15.1).

## 10. More warehouses and more sites later
- **New site:** a `channel` row + key + warehouse assignment + connector + listings push + mapping.
  No CW code change. A future storefront on another platform uses the same `/v1` API.
- **A site on its own warehouse:** new `warehouse` + change that site's assignment; stock moves between
  buildings by transfers; reservation units already record their warehouse. (v1 allows one sellable
  warehouse per site; selling one order from two buildings needs a split-allocation rule, added then.)
- **Non-sellable warehouses** (RETURNS, DAMAGED) never count towards availability.

## 11. Security gates (before any site goes live)
| Item | Action |
|---|---|
| CW API | per-site keys stored as sha256, compared with `hash_equals`, fail-closed if unconfigured, IP allowlist AND key; envelope + write audit (patterns in `App/api/v1/catalog/export.php`, `App/api/v1/seo/lib/{Auth,Response,Audit}.php`) |
| `App/api/update_purchase_invoice.php` (the goods-in door) | its only real gate is a static token hard-coded in the git-tracked admin repo (:12); the IP check trusts a forgeable `CF-Connecting-IP` (:49) and a matching Origin/Referer passes on its own (:58-81). **Fix before the relay goes live:** token moved to config and rotated, `REMOTE_ADDR` allowlist AND token, Origin branch removed, old ERP IP 178.128.171.123 removed |
| `App/api/get_status.php` | read-only; restrict to ERPNext's egress IP 139.59.165.219 by `REMOTE_ADDR` (no ERPNext change) |
| `erp_stock_update/ajax/stock_update_api.php` (+ copies in `rate_limit/`, `App/test/`), `syncdashboard/ajax/api.php` | can rewrite/re-queue stock jobs with no login → add session checks, drop `CORS *` |
| `App/api/bulkitem_sent.php`, `bulk_with_item.php`, `bulk_json.php` | unauthenticated, leak the ERP token / catalogue → web-block |
| `App/db-transfer/*.php` | keep the tool, add auth, move its DB password out of the docroot, use a least-privilege Electrofag user |
| ERP secret in `data_sync_log.dsl_headers` | stop logging auth headers; rotate the secret |
| Secrets | outside web roots and git — not the weak `encrypt()` (`php_functions.php:651`, passphrase in git-tracked `App/global_config.php`) |

## 12. Rollout — every step is a switch
**Switches:** effective site mode (min of site `CW_MODE` and CW `channel.mode`: off → shadow → live)
and per-item `sell_policy` (legacy → strict/backorder/stopped, and back).
- `off` — the connector does nothing.
- `shadow` — every event (reserve, commit, release, cancel, ship, return, goods-in) is sent to CW and
  CW keeps its books, but the site behaves exactly as today: CW's answers never block and CW writes
  nothing back (except marking out of stock any listing of an already-protected item — see rollback).
- `live` — CW decides managed lines (may refuse at Place order) and writes their stock and mode;
  everything not managed ("unmanaged": unlinked or linked-legacy lines) still follows the site's own rules. **All three sites go live
while every item is still `legacy`** (harmless: nothing is refused or written), and only then do items
become protected. **Rollback:** a site set back to shadow first adds back its own open holds to its
local figures, restores pre-CW modes, then resumes its local decrement; any item can go back to
`legacy`. Rolling back a site while protected items exist makes that site show those items out of
stock until it returns (never sells blind).

| Phase | What | Needs | Engineering |
|---|---|---|---|
| **0 Prerequisites** | CW **staging** droplet + DB + backups (production CW server later); on proto: move PayPal/Viva watcher paths and the security gates (§11) — applied to live only after testing and your approval; Electrofag discovery (hook file:line, writers, PHP/cron/supervisor); webhook alert; Anthropic workspace; warehouse answers Q5; Vape Big discovery when access arrives (does **not** block Phase 0 exit) | — | ~1 wk (1–2 wks calendar) |
| **1 CW core** | schema, `Stock.php`, reservations state machine, change feed, `/v1` API + auth, expiry cron, staff UI (item view + ledger, count, queues, oversell events, purchasing report), alerts, hammer tests, staging | 0 | 4–5 wks |
| **2a Vape and Go catalogue** | listing ingest, minimal `DecisionService` + review screen, Vape and Go mint | 1 | 1–2 wks |
| **2b First-time match** | export on each site, my matching run, accuracy report, staff review waves by units sold | 2a (Vape Big when its export arrives) | ~2 wks tooling (M1) + staff time |
| **3 Vape and Go connector (proto)** | pieces, fail-safes, all hooks, worker, opening orders — built and tested **only** on `App_proto`/`Website_proto` (`vpg-store.floverfy.com`, backup DB) against CW staging | 1, 2a | 2–3 wks |
| **4 Electrofag + Vape Big connectors (proto)** | port the storefront half, drop-in admin half, discovery fixes — on their proto sites only | 3 + that site's access | ~2 wks each |
| **P Proto rehearsal + your sign-off** | the full §14 test list on all three proto sites against CW staging, in shadow then live mode; fix everything found; written sign-off before anything reaches a live site | 3, 4 | ~1–2 wks |
| **5a Live shadow** | production CW server; connector copied to each live site `off` → `shadow` (live behaves exactly as today); ≥7 clean shadow days per site | P + your approval; security gates on live | ~1 wk + 7 days per site |
| **5b All sites live (items still legacy)** | turn on live mode + goods-in relay, one site at a time | 5a | ~1 wk |
| **6 Mapping safety (M2)** | count gate, quarantine, correction movements, duplicate sweep | 2a | 2–3 wks (parallel with 3–5) |
| **7 Counting + protection** | warehouse counts; items flip as counted | 5b, 6 | warehouse-paced, 6–8 wks |
| **8 Claude in CW (M3)** | nightly batch + sync for new listings, cost log | 2b | 1.5–2 wks (parallel with 7) |
| **9 Part 2 (replace ERPNext, then detach)** | F0 now (urgent items + ERPNext usage audit); F1 inside Phases 3–5; build every replacement module; parallel run; switch-over D at a financial-year start; detach — see §15.4 | §15.4 | §15.5 |
| **10 Later** | RETURNS inspection flow, bundles, second warehouse | as needed | — |

**Effort:** Part 1 ≈22–25 engineer-weeks → ~5–6 months with one engineer, ~3–3.5 months with two
(one on CW core + connectors, one on catalogue/matching from Phase 0). Counting then takes 6–8 weeks
of warehouse time. Part 2 adds ≈45–65 finance-engineer-weeks (§15.5). **Deliberately not doing
now:** create-once central catalogue, central price matrix, central dispatch console, site-to-site
links, direct DB connections, big-bang cutover (and, in Part 2, an in-house HMRC client, iXBRL,
payroll).

## 13. What changes for staff
| Who | Change | Time |
|---|---|---|
| Warehouse | counts in CW (top sellers first, after the day's dispatch, tablet + scanner); confirms identity at the count | ≈36–60 h for 80% of units (~720 items at 3–5 min), ≈130–210 h for 95% (~2,560 items) — about one full-time counter for 6 weeks; re-planned after the first 50 counts |
| Catalogue | reviews proposed links (pre-selected, a few seconds each) and new items; merges Vape and Go duplicates | ≈40–60 h once (incl. ~18 h labelling and the Electrofag long tail); then ~1–2 h/week for new listings |
| Purchasing | reorders from CW figures; handles `goods_in_suspense` lines; approves policy-review items; from D raises POs, books deliveries (with duty stamp fields) and enters supplier invoices in CW instead of ERPNext (trained on the CW sandbox during the parallel run) | ~15 min/day in Part 1; the ERPNext purchasing work moves across at D |
| Finance (Part 2) | from D: bank and gateway matching, supplier invoice approval, payment runs, month-end close, VAT return via the bridging tool; supplier bank changes need two people | ≈60–90 h/month across ≥ 2 people (§15.5) |
| Customer service / ops | handles `oversell_event` rows (later-paid order back-ordered or refunded) | expected rare; daily check |
| Everyone with stock rights | stock corrections move from site admins to CW; site stock fields become read-only for protected items | training session per team before Phase 5b |

## 14. Verification
**CW (automated):**
- Hammer: 200 parallel reserves from 3 simulated sites on a strict item with on_hand=10 → exactly 10
  held; multi-line orders in random order → no deadlocks, all-or-nothing; duplicate listings of one
  item in one basket and a "10 x" listing are summed in central units.
- Every call replayed 3× (also concurrently) → one effect; stale `attempt` releases ignored;
  release/expire/re-reserve/commit-without-hold; `stopped` item and quarantined listing → 409.
- Bucket arithmetic: legacy item with open paid orders → count → switch to strict → orders ship →
  `allocated` returns to 0, never negative; count with a late ship dispatched before `counted_at`;
  cancel restockable true/false; unship; return after refund (no double count); nightly invariant
  check (buckets = sums of `reservation_unit` states).
- Feed: out-of-order commits, remap of a listing between items, policy change and warehouse change
  all reach the site; the version guard never applies an older value.
- Goods-in relay: a payload with the same variant twice books the sum; debit notes and ERP sales
  lines book the right sign; unresolved lines land in suspense.

**Staging end to end** (`Website_proto`/`App_proto`, backup DB, sandbox gateways): place, pay, fail,
abandon, retry, cancel, refund, dispatch (incl. a `clear_dispatch` reset and office child orders),
return; a legacy From-Warehouse item with stock 2 and a cart of 20 is still refused locally (the
44128 regression); stop CW mid-checkout → local fallback, flagged order, recovery in the right order;
restore CW from backup → `cw_replay.php` brings it level; a second test site sees new figures in ~5 s.

**Shadow acceptance per site (≥7 days):** every Completed order has a committed reservation; shipped
units per day in CW = units with `ordi_shipped=1` on the site; 0 dead-letters; feed lag p95 < 5 s;
heartbeat healthy.

**Live acceptance:** `oversell_event` reviewed daily; checkout 409 rate and conversion vs the pre-live
baseline; KPIs (§8.3) trending to target.

**Part 2 (accounting):** automated test pack — every journal balances per company; each subledger
equals its control account after every event (stock value = inventory account, open supplier invoices
= creditors, unmatched deliveries = goods-received-not-invoiced, deposits = unshipped paid units);
valuation replay from any row is deterministic; VAT nine-box golden cases (discounts, partial and
post-dispatch refunds, exports with/without evidence, office replacements); shadow book never touches
the live book; cutover boundary and carve-out cases; the monthly bridge report against ERPNext
(§15.4) signed by the accountant before D.

**Matching:** accuracy report against the 1,977 barcode pairs and the 300-item blind sample before
staff review; PHPUnit golden pairs from profiling (strength siblings, Bar/Plus, 600/6000, Corex,
kit vs refill, Nic Nic multiples, relabels, placeholders) each vetoed or routed to Conflict, never Key.

## 15. Part 2 — Every ERPNext function rebuilt in CW, then ERPNext detached

### 15.1 Urgent now (F0 — business actions, no CW code needed)
| Action | Why | Who |
|---|---|---|
| **Vaping Products Duty, from 1 Oct 2026:** confirm in writing whether any of the companies imports vaping products or buys from overseas sellers (if so, hold post-1-Oct imports until an approved/stamping route exists); keep the 30 Sep stock snapshot (the nightly `product_stock_snapshot` job already takes one at 23:55 UTC — preserve it, plus an ERPNext Stock Balance export as at 30 Sep); from 1 Oct record for every delivery the stamp status and the supplier's "produced/imported before 1 Oct" declaration in a register (a spreadsheet until CW's goods-received screen has the fields); treat duty on supplier invoices as stock cost, not an expense | legal record-keeping; correct cost | owner, purchasing, accountant |
| **Unstamped stock off sale by 31 Mar 2027:** from Jan 2027 a weekly report of items whose last unstamped delivery still shows stock; stop reordering unstamped lines; a "stamped?" tick on the count screen; by ~24 Mar move remaining unstamped units to the non-sellable `UNSTAMPED` location; dispose/return as the accountant advises | holding unstamped stock from 1 Apr 2027 is an offence | warehouse, purchasing |
| **VAT return basis check:** who prepares today's return, and from what? If from ERPNext: discounts never reach it (~£488k/year gross), ~£73k of refunds since Dec 2025 never reached it (the BIT(1) bug), and exports are all at 20% — the business may be **overpaying** output VAT (4-year correction window) | money | accountant |
| **Which company sells on each site:** public records point to Love Vaping Ltd, Quick Vapes Ltd and UFA Distro Ltd, and ERPNext payloads name "Relier IT Ventures LLP"; confirm the seller per site, VAT registrations (Vape Big's operator publishes no VAT number), trading disclosures on each site, and whether today's shared-stock sales between companies are unrecorded intercompany supplies | legal | owner, accountant |
| **List every finance system in use** (accounting package, bank reconciliation, supplier payments, payroll, VAT filing software) — there are signs the books of record may not be ERPNext | decides where opening balances and the parallel-run baseline come from | owner |
| **ERPNext access plan:** a read-only ERPNext user (Company, Account, Fiscal Year, Supplier, Item, Bin, Stock Ledger, Purchase Order/Invoice, Sales Invoice + queue, Payment Entry, Journal Entry, GL Entry); exports (trial balance, creditors/debtors ageing, Stock Balance, open POs, suppliers, chart of accounts, VAT settings); re-verify the SSH host key; a full `bench backup`. **No write access is needed — CW never writes to ERPNext.** | every later step needs ERPNext data; today REST returns 403 and SSH is blocked | ERPNext admin |
| **ERPNext usage audit** (with the ERPNext admin, read-only): documents created per type over the last 24 months, active users/roles and what they touch, reports and print formats used, custom fields/doctypes/scripts/workflows/notifications/scheduled jobs in the custom app `vpg_customization`, integrations and emails sent | produces the complete list of functions CW must replace (§15.2); nothing is detached until every row is replaced or consciously dropped | ERPNext admin + me |

### 15.2 Every ERPNext function → its CW replacement (completed by the F0 usage audit)
| ERPNext function in use today (evidence) | Replaced in CW by | Part |
|---|---|---|
| Item master: items/templates/attributes created from Vape and Go products; enable/disable sync | central product record + site listing links (§7) | 1 |
| Stock ledger and quantities ("Main Warehouse" / "Stores - VPG") | CW buckets + ledger (§2) | 1 |
| Stock reconciliations / counts (~131/month) | CW counts + count review (§8) | 1 |
| Stock mode and safety stock per item (~4,000 item saves/month) | CW sell policy + reorder levels (safety stock, lead time) | 1 / 2 |
| Suppliers | CW suppliers (bank details under two-person control) | 2 |
| Purchase orders (exist in ERPNext; REST access denied) | CW purchase orders + order planner suggestions | 2 |
| Purchase invoices with stock (~290/month), debit notes (supplier returns) | CW goods received + supplier invoices (3-way match) + debit/credit notes | 2 |
| Supplier payments (Payment Entry — to confirm) | CW payment runs + bank file | 2 |
| Sales invoices from Vape and Go (~42k/month, via the custom Sales Invoice Queue) and their returns | CW sales records per supply (commit money block + refund sweep) and summary postings | 2 (F1) |
| ERPNext's own sales invoices (~51/month — trade customers?) | CW trade invoices + receivables | 2 |
| Gross Profit report and other stock/sales reports | CW reports (gross profit by item/brand/site, valuation, ageing…) | 2 |
| Ledger, journals, chart of accounts, fiscal years (if the books are kept there) | CW ledger | 2 |
| VAT (if the return is prepared from ERPNext) | CW VAT engine → bridging tool | 2 |
| Bank reconciliation (if done there) | CW statement import + matching | 2 |
| Custom app `vpg_customization` (site APIs, Sales Invoice Queue, item hooks, stock feed) | not needed — sites talk only to CW | 1 / 2 |
| Anything else the audit finds (e.g. HR, payroll, assets, projects, CRM, print formats, workflows, email alerts) | decided row by row: build in CW, move to a dedicated tool (payroll stays in payroll software), or drop — signed off by the owner | 2 |

**Detach checklist (after the rollback window, §15.4):** disable the 8 `data_sync` ERPNext endpoints
and the ERPNext parts of `App/src/syncronisation/run_all_sync.sh`; stop `data_sync_processor.php`
and the ERPNext relay in `App/api/stock_worker.php`; remove/web-block `update_purchase_invoice.php`,
`update_product.php`, `update_product_status.php`, `stock_reconcillation.php`, `stock_reconcile.php`,
`get_status.php`, `erp_stock_pull_sync.php`, `erp_mode_sync.php` and the bulk ERP scripts; delete
ERPNext keys from all configs (incl. `/var/www/html/vpg_ecom/.erp_sync_key`) and remove ERPNext IPs
from every allowlist; hide the ERP sync admin screens; remove `src/Erp` from CW after the final
import; take a final verified ERPNext backup + per-year exports (ledger, trial balance, invoice
registers, VAT reports) into write-once storage for ≥ 6 years; switch off the ERPNext server and
release its reserved IP.

### 15.3 What CW gains
- **Companies:** `legal_entity`, `vat_registration`; each site has a selling company, each warehouse an
  owner; every ledger/VAT row carries its company and every journal balances per company. Supports one
  company, a VAT group, or separate companies (intercompany sale at each dispatch, monthly VAT invoice
  pair). Gateway and bank accounts are keyed per company.
- **Purchasing:** suppliers (bank details under two-person change + call-back + 24 h payment hold; one
  master per phase — ERPNext until D, then imported with call-back verification), supplier items
  (supplier code, purchase unit → central units), purchase orders (draft → approved → sent → received →
  closed), goods received (PO optional; delivery note; duty stamp fields), supplier invoices (unique per
  supplier + number, VAT exactly as stated, lines for goods/freight/duty/service/expense/asset, 3-way
  match with tolerances; invoice-with-receipt is the default, as today), debit/credit notes, payment
  runs (preparer ≠ approver, bank file). Purchasing view gains open POs, on-order quantities, lead times.
- **Valuation:** moving weighted average per (company, item) in central units; only `on_hand` carries
  value (paid-not-dispatched units stay at cost, their revenue in customer deposits). One valuation
  consumer processes a per-item sequence written in the same transaction as each stock change (no
  skipped rows; `Stock.php` locks unchanged). The value ledger also holds value-only events (price
  differences, freight, duty, supplier credits, true-ups). Negative stock (backorder items): true-up at
  the next receipt; the capitalised share of a price difference is clamped to stock actually held.
  Returns come back at their original dispatch cost; an NRV provision is used up on issue. At every
  period close a hashed valuation snapshot is written — that is the year-end stock statement; count
  sheets are kept permanently.
- **Sales money** (added to Part 1's connector, no new hook sites): the commit carries a money block
  (paid time, gateway + reference, order type/parent, ship-to country, subtotal, shipping, discount,
  loyalty, per-unit prices); a worker **refund sweep** reads successful rows of `refund_attempts`
  (`ra_request_json` lines, routed by `ra_gateway` so manual refunds don't land on GlobalPay); a nightly
  **daybook** check against the site's own totals (GlobalPay from `globalpay_orders`, not the stale
  `payments_master`). CW keeps one sales record per supply (~1.5k/day) for VAT.
- **Posting principles** (full matrix signed off by the accountant): payment → customer deposit + VAT
  (tax point = payment); dispatch → revenue + cost of sales at average cost; per-unit deposit ledger so
  partial refunds and cancel-without-refund never leave residue; refunds after dispatch → returns
  account; staff replacement ("office" child) orders are zero-value sales with cost to replacements;
  gateway settlements clear fees and chargebacks; count variances to P&L (first counts shown
  separately); loyalty points deferred as FRS 102 section 23 now requires (accountant confirms); daily
  summary journals per company/site/gateway/VAT code (~50–150/day) with drill-down to every unit.
- **Ledger:** chart of accounts owned by the accountant (template supplied); periods open → soft-closed
  → closed, plus a separate VAT-period lock; posted journals are insert-only (drafts in separate tables,
  corrections are reversals, approvals are rows), with a per-company hash chain checked nightly;
  **separate `shadow` and `live` books** so the parallel run can never leak into the real books.
- **VAT:** codes per supply; zero-rated exports only with export evidence; purchase VAT as stated;
  refunds in the refund's period; the nine boxes with drill-down; pre-submission checks; period lock;
  late items flagged into the next period; CSV to the bridging tool; HMRC receipt stored.
- **Banking & receivables:** bank statement import and matching (open-banking feed optional later);
  gateway settlement imports (GlobalPay, PayPal ×2, Viva, Payroc); trade (B2B) invoices and receivables
  (ERPNext raises ~51 of its own sales invoices a month); prepayments/accruals; payroll journal import;
  fixed assets (a spreadsheet register + monthly journal in the MVP).
- **Reports:** trial balance, P&L, balance sheet (per company, combined, by site); aged creditors and
  debtors; goods-received-not-invoiced; stock valuation as at any close; gross profit by item/brand/site
  (replaces the ERPNext Gross Profit report); VAT listing; bank and gateway reconciliation; duty
  register; count archive; accountant export.
- **Controls built in from the first module (not bolted on later):** roles buyer / purchasing manager / goods-in / AP clerk / AP
  approver / payments / finance approver / accountant / auditor / admin (admin cannot post), all with
  TOTP; clerk ≠ approver; new suppliers approved by another person (VAT number + Companies House check);
  count variances and write-offs above a threshold approved by someone other than the counter;
  insert-only database grants; invoices and evidence in write-once storage (e.g. S3 Object Lock in
  London or Backblaze B2 Object Lock — DigitalOcean Spaces cannot enforce this), kept ≥ year end + 6 years.
- **Code:** `src/Valuation.php` (only writer of value), `src/Ledger/Posting.php` (only writer of the
  ledger), `src/Purchasing`, `src/Ap`, `src/Sales`, `src/Vat`, `src/Bank`, `src/Reports`, `src/Erp`
  (transitional, removed a year after D).

### 15.4 Phases: build everything, run in parallel, switch over, detach (dates assume two finance engineers from Q1 2027)
| Phase | What | When | Gate |
|---|---|---|---|
| **F0** | §15.1 incl. the ERPNext usage audit → final replacement list (§15.2) | now – Dec 2026 | owner signs the list |
| **F1** | money block, refund sweep, daybook, sales records + VAT working in shadow on each site | with Part 1 Phases 3–5 | monthly comparison with ERPNext and the accountant's figures |
| **Workshop** | companies, VAT numbers, financial year, VAT stagger, chart of accounts, policies, **acceptance of a CW ledger (or the Xero fallback)** | Feb–Mar 2027 | signed decisions |
| **Build** | every replacement module (§15.2–15.3): suppliers, POs, goods received, supplier invoices, debit/credit notes, payments, valuation, ledger, receivables/trade invoices, VAT, bank + settlement imports, reports, controls; staff sandbox for training | Mar 2027 – Jan 2028 | module acceptance tests (§14) |
| **Parallel run** | staff keep working in ERPNext; CW runs a `shadow` book fed automatically — site sales/refunds (F1), ERPNext goods-in (existing relay) and a nightly **read-only** import of ERPNext purchase invoices, payments and journals — so nothing is keyed twice; monthly **bridge report** (CW − named reconciling items = ERPNext; unexplained < 0.1% of revenue and < £500 per VAT box; accountant signs); a real VAT quarter computed in CW whose return is filed from the old system ≥ 4 weeks before D; a dry-run month close; staff training on the sandbox | from mid-2027; the checked quarter ends by 31 Jan 2028 | go/no-go 1 Oct 2027 (build ≥ 80%, bridge green 2 months) |
| **D — switch-over day** | at a financial-year start: **~30 Apr 2028** (one finance engineer: ~30 Apr 2029); per-company dates if year ends differ. From D, staff raise POs, book deliveries, enter and pay supplier invoices, and close the books in CW only; opening balances (J0), open POs, unpaid supplier invoices, open trade invoices and suppliers imported; opening average cost from ERPNext's Stock Balance rates (blank and £1 placeholder costs re-costed) | | gates below |
| **Rollback window** | D → D+6 weeks: ERPNext frozen but restorable, its feeds paused by a switch (not deleted); the first CW month-end close and VAT computation are the final go/no-go | | |
| **Detach** | the checklist in §15.2; ERPNext switched off; records kept as exports ≥ 6 years (accountant may keep a read-only copy until the last ERPNext year's statutory accounts are signed) | ~D+6 weeks | owner + accountant sign-off |

Moving purchasing into CW earlier than D is possible, but it would mean CW writing a mirror of every
supplier invoice back into ERPNext (needs an ERPNext write user, adds risk) — not recommended.

**D gates:** accountant (and any auditor) sign-off; ≥ 99% of sold units on linked listings; every item
with stock counted within the 12 months before D plus a full count of the rest in the old year's last
month (Companies Act s386 stocktaking records); every item that still gets deliveries is either
protected or covered by CW's **legacy stock writer** (CW pushes goods-received quantities to the sites
for linked-legacy listings, since the ERPNext relay stops — ~1 engineer-week); ≥ 1 quarter of
settlement and bank imports reconciled; ERPNext trial balance at D−1 agreed (opening journal posted
as provisional, trued up once the statutory accounts are signed); pen test done; bridging tool set up
and its CSV format verified.

**Cutover boundary:** an order belongs to ERPNext if its completion time (London) is before D,
otherwise to CW; ERPNext's scheduler stays on after D until its Sales Invoice Queue is empty and its
failures are resolved with the accountant (1,337 failed rows exist today); units paid before D count
as already costed only if their ERPNext sales invoice is confirmed (Vape and Go, SUCCESS) — sister-site
and failed-invoice units are costed in CW.

### 15.5 Effort, people, risks
- **Engineering:** ≈39–56 finance-engineer-weeks as designed, ≈45–65 with the review additions and a
  20% contingency; an MVP at D is ≈33–45 (deferring settlement automation, intercompany if one company,
  supplier-statement reconciliation, the fixed-asset module). **Whole programme ≈70–90 engineer-weeks.**
- **Accountant:** ≈8–12 days (workshop, posting matrix, parallel run, cutover); finance data clean-up
  3–5 days.
- **Finance workload after D:** daily bank + gateway clearing 30–60 min; ~290 supplier invoices/month
  5–10 h; weekly payment run ~1 h; month-end close 2–3 days; VAT return 0.5–1 day per VAT number per
  quarter → **≈60–90 h/month (0.4–0.6 FTE) across at least two finance people** (duties must be split).
- **Top risks:** company/VAT ambiguity (decided at the workshop; the model supports every set-up) ·
  ERPNext access blocked (F0 access plan) · the books of record may not be in ERPNext (F0 question) ·
  auditor rejects a home-built ledger (Xero fallback, decided at the workshop) · duty deadlines arrive
  before CW purchasing exists (F0 stop-gaps) · key-person risk (test pack, documentation, accountant at
  acceptance testing).
- **Not building:** an in-house HMRC submission client (+2–3 engineer-weeks, HMRC approval, pen test —
  available if you overrule the bridging tool), iXBRL / tax-return filing, payroll, per-unit ledger
  postings, duty-lot tracking.
- Full design record (tables, posting matrix, valuation rules, VAT codes, review fixes): workflow
  `wf_f06a0544-280` journal under
  `/root/.claude/projects/-var-www-html-vpg-ecom/5e8efa21-e26d-42dc-be75-4ec4dcbf767f/subagents/workflows/`
  — committed as `docs/finance-design.md` in the CW repo in Phase 0.

## 16. Answers still needed (none block starting Part 1 Phases 0–1)
| # | Question | Needed by |
|---|---|---|
| 1 | Vape Big: who gives SSH/deploy access (or runs the export there), and when? | Phase 2b / 4 |
| 2 | Is Electrofag "Crystal Pro Max" the same physical stock as Vape and Go "Hayati Pro Max"? Other relabels? Which Electrofag "brands" are really distributors? | Phase 2b |
| 3 | Pack policy: is a pre-packed multipack its own item? Are the Nic Nic "10 x 10ml" listings picked from single bottles? Are the ~2,526 extra Vape and Go barcodes outer cases? | Phase 2b |
| 4 | Who are the mappers, mapping leads (≥2), counters and the manager who protects items? Who labels the 300-item sample? | Phase 2b |
| 5 | What does the dispatch "cancel & restock remaining" button mean on the floor (item missing vs customer cancel)? How often is it used? | Phase 0 |
| 6 | May a mapping lead quarantine a doubtful listing (shows out of stock on that site)? | Phase 6 |
| 7 | Claude model for new-product matching after the pilot (Opus 5 default vs a cheaper model); who owns the Anthropic workspace and cap? | Phase 8 |
| 8 | Finance (with the accountant): which company sells on each site and VAT numbers/group; financial year and VAT stagger; who files VAT today and from what; every finance system in use; will a CW-built ledger be accepted (else Xero fallback); direct imports and foreign-currency suppliers; policies (revenue at dispatch, loyalty, price differences, first-count variances, export evidence); banks and file formats; ERPNext admin access (§15.1); finance staffing (≥ 2 people besides the accountant) | F0 / workshop (Feb–Mar 2027) |
| 9 | Mark the 1–2 Jul draft spec as superseded in the repo? | Phase 0 |
| 10 | Standby database: optional for Part 1; **required** once CW keeps the books (Part 2) | Phase 5a |

## 17. Adjacent findings (outside this project)
- Three site→ERPNext refund channels have never sent anything (BIT(1) bug, §9).
- Unauthenticated admin/API endpoints on Vape and Go (§11) — worth closing regardless.
- `App/db-transfer/*.php` has no auth and writes to Electrofag's live DB with a full-admin account.
- `App/tools/worker_health_check.php` ignores the email/WhatsApp/Klaviyo tubes (8 + 43 buried jobs,
  305 jobs with no watcher) and the ERP queues.
- Electrofag's PayPal responses appear to name a Vape and Go merchant email (substring match only,
  unverified) — worth a look by whoever owns the payment accounts.
