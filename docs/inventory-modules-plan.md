# Central Warehouse: Inventory Modules Plan (final)

*For the owner, 2 Oct 2026. This builds on the approved plan (`/root/central-warehouse/docs/plan.md`) and `docs/decisions.md`. Section 12 lists every change it makes to them. Two independent reviews have been applied. Nothing is built until you say "start" for each phase, and nothing touches a live site until you sign off in writing.*

---

## In short

**What this is.** A list of everything you use today for stock and buying, in ERPNext and in the website admins, with how much each one is used and where it goes in the Central Warehouse (CW). Each function is either:
- rebuilt in CW;
- left on the websites (selling, dispatch, refunds and returns); or
- dropped, because nobody uses it (the evidence is in §2).

Nothing that is in use is left out.

**What ERPNext really does for you today:**
- It is your **goods-in book**. Staff type each supplier invoice in as the delivery, the cost and the bill at once: about 300 a month, 300–520k units, £0.6–0.9M. **Each line also sets the item's selling mode** (In-Stock / From-Warehouse / Out-Of-Stock) on the website.
- It holds your **purchase orders** (about 90 a month since April) and prints them for suppliers.
- It holds your **stock corrections** (about 100 a month).
- It is how stock is **handed to Electrofag, Vape Big and the shop units**: about 50 invoices and 10–12k units a month.
- It is **not a real set of accounts**. There are no supplier payments and no bank, and £7.1M of supplier invoices show as unpaid. Its stock figures are also unreliable: 707 items are below zero.

**What CW gets: 15 modules in two waves (§3).**
- **Needed on switch-over day:**
  - staff roles;
  - an item card with the legal duty fields;
  - suppliers;
  - purchase orders with reorder suggestions for all three shops;
  - one "Receive + invoice" screen with duty-stamp checks and the selling mode on each line;
  - supplier invoices and returns;
  - counts and corrections;
  - stock value (cost);
  - the In-Stock / From-Warehouse / Out-Of-Stock switch, which CW writes into each site;
  - trade and inter-site issues;
  - the duty records;
  - reports and the accountant's files;
  - switch-over tools.
- **Soon after (about 2–3 months):**
  - invoice tolerance checks and a hold queue;
  - write-down and month-end close screens;
  - returns inspection;
  - transfers with "in transit";
  - gross profit by site;
  - a few smaller reports.

**How it runs without ERPNext (§4).** On switch-over day ("I-Day") staff stop buying and correcting stock in ERPNext. From then on they do all of this in CW only:
- raise orders;
- book deliveries;
- enter supplier invoices;
- count stock.

CW keeps all three websites' stock figures and selling modes right. CW never connects to ERPNext. It only takes one-off copies of ERPNext data, restored from a backup (§8).

**Accounts until CW has its own ledger (§5).** Your accountant chooses. Recommended for I-Day: the accountant keeps entering supplier bills exactly as today, and CW sends a monthly stock file (stock value, cost of sales per site, write-offs, goods not yet invoiced). Bills are never entered twice. Later, CW can become the supplier-bill record.

**Duty stamps (§6).** CW cannot be live before 31 Mar 2027, so the run-down of unstamped stock is handled now: the register, stock counts and a weekly report. From 1 Apr 2027 even *holding* unstamped vaping liquid is a criminal offence. By 31 Mar 2027 it must be sold, returned, exported or destroyed; a quarantine shelf is not enough. Free gifts of vaping products to the public become an offence on **29 Oct 2026**.

**Time (§9).**
- Target switch-over: **1 Sep 2027**. The likely window is Aug–Oct 2027 with two engineers, or Feb–Jun 2028 with one.
- Build: about **57–80 engineer-weeks to I-Day**, including the site connectors that are already planned (±30%).
- The long poles are not coding. They are your accountant, the ERPNext admin's data, staff testing, the spring 2027 stock counts, Vape Big access and the live trial weeks.
- We re-plan after Phases I-1 and I-3, using the pace we have actually measured.

**One trade-off for you (decision 1).** If everything goes live together, as you asked, the shared-stock protection from Part 1 (no overselling across the sites) also waits until summer 2027. That is about 4–6 months later than in the approved plan.

**Safety.** Everything is built and tested on CW staging and the proto sites. A live change happens only after your written sign-off, and each one is a switch that can be turned back.

---

## Your steps now (before any building starts)

I will wait for your "start Phase I-1". Only these steps are needed now. Every other decision is asked when the phase that needs it comes up (§10).

| # | What | Who | When |
|---|---|---|---|
| 1 | Answer the 8 "now" decisions (§10, group A) | you | this week |
| 2 | Stop free giveaways and large promotional discounts on vaping and nicotine products to the public (an offence from **29 Oct 2026**). Check website promotions and £0 lines | you, marketing | before 29 Oct |
| 3 | ~~Security (urgent): sftp.json~~ **Checked 2 Oct 2026: not exposed.** The file appeared 25 Sep 18:57:01. Its only 2 successful downloads came from the server itself (127.0.0.1) in the next minute. A deny-all `.htaccess` followed at 18:58:06, and every request since gets 403/404. The logs cover the file's whole life. No login change is needed. Tidy-up only: move it out of the web root | — | done |
| 4 | Duty register: add four columns: stamp type (digital or transitional); stamp on the outer pack and sealing it (yes/no); supplier checked (yes/no); ECID if printed. Add an **incidents** tab and a **supplier checks** tab. Confirm in writing whether any of your companies imports vaping products | purchasing, you | October |
| 5 | Buy write-once storage for the duty evidence and later for CW documents: AWS S3 (London) with Object Lock, or Backblaze B2 with Object Lock. DigitalOcean Spaces cannot lock files | you | October |
| 6 | Approve the stock-snapshot fix going live on Vape and Go (UK time; month-end rows kept 7 years; built and tested on proto). Run the snapshot cron line on Electrofag and Vape Big from their consoles | you | October |
| 7 | ERPNext admin: (a) take a full `bench backup` off-peak and restore it on a separate box for our read-only copy; (b) fix the "boxes sent instead of units" bug and the "marked Synced but never sent" bug, testing on a copy first; (c) re-send the missing quantities (20 invoices / 9,571 units, plus 336 units) | ERPNext admin | Oct–Nov |
| 8 | Vape Big: give access, or name who can run our read-only export on its server | you | as soon as possible |
| 9 | Let me watch the two people who key purchase invoices and purchase orders for half a day: 5 real invoices, timed. Their sign-off on screen mock-ups comes before Phase I-3 | you arrange | before Phase I-2 |
| 10 | Book a first call with your accountant (the questions are in §5) | you | before Feb 2027 |
| 11 | Before Phase I-3 testing: two tablets with barcode scanners, and a Wi-Fi check at the goods-in bench | you | by Jan 2027 |
| 12 | Say "start Phase I-1" | you | — |

---

## 1. What the research found

**ERPNext (audited read-only, 2 Oct 2026).** Real data only starts on 15 Dec 2025, because trial data was wiped 10 times. That gives about 9.5 months of history, not 24.

- **Set-up:** one company (VAPE AND GO, GBP), one warehouse (Stores - VPG), moving-average cost, negative stock allowed.
  - ERPNext's fiscal year runs 1 Apr–31 Mar.
  - Love Vaping Ltd's accounting reference date at Companies House is **29 April**.
  - Which of these applies to the company that owns the stock must be confirmed (decision 13).
- **Since the v16 cutover (1 Sep–2 Oct 2026):** 322 purchase invoices, 88 POs, 107 stock reconciliations, 56 manual trade/sister-site invoices, 48,135 website sales invoices, 277 new items and 287 PO printouts.
- **Selling mode travels with stock documents:**
  - Purchase-invoice lines carry a stock mode, and so do many stock-reconciliation lines (865 lines in August, 1,625 in September). ERPNext copies it onto the item and pushes it to the site.
  - On Vape and Go, 17,420 of the 18,807 relayed goods-in lines in 90 days carried a mode. `App/api/stock_worker.php:106-113` writes it into `prodt_stock_mode`.
  - The 4,752 mode pushes since 1 Sep are mostly side effects of those saves, because every item save pushes. The net real change is only 6–21 items a day.
- **People:** 3–4 people do all the buying and stock work, and one person did almost all of it until 25 Aug. All 11 staff users hold every role.
- **Suppliers and invoices:** the 47 supplier records are names only. The supplier's invoice number is missing on 2,049 of 2,218 purchase invoices.
- **Open POs:** 99 POs are open (538 lines, 38,362 units on order), and many of them are stale part-receipts. 96 POs are "To Receive and Bill", and 53 of the 88 September POs were only partly received.
- **Never used:** Material Request, RFQ, Supplier Quotation, Purchase Receipt (488 abandoned drafts), Landed Cost, Delivery Note, Pick List, batch/serial/expiry, Quality Inspection, barcodes, pack-size conversions, reorder levels, stock reservations, Payment Entry, Journal Entry and bank. There is nothing at all for Vaping Products Duty.
- **Confirmed defects:**
  - goods-in is sent to the site in boxes instead of units (336 units short on 29–30 Sep);
  - 20 purchase invoices (9,571 units) are marked "Synced" but were never pushed;
  - 1,881 failed website sales were never retried (527 of them in September).
- **Debit notes:** ERPNext shows 6. The earlier finance design counted 7 (−4,716 units). This is checked in the first data copy.
- **The audit query incident:** an audit query on the live ERPNext database (process 45308) was killed on 2 Oct with your approval, and load fell from 4.5 to 1.5. The lesson is built into §8: data comes from a backup copy, not from queries on live.

**Website admins.**
- The heavily used stock functions are the **selling and fulfilment** ones. They stay on the sites, with CW hooks:
  - checkout stock-out (about 5,100 rows a day);
  - office orders;
  - dispatch scanning (about 122k orders in 90 days);
  - cancel, refund and return restocks;
  - back-in-stock emails;
  - the Mobile app stock viewers, the most-used stock screens of all.
- Vape and Go's own warehouse screens (Inventory > Stock, Stock Scan, Warehouses, Batches, Vendors) are essentially unused. Stock entry is switched off there, and ERPNext does that job instead.
- Electrofag staff type 271 stock-ins by hand in bursts (+7,169 units in 90 days), with no supplier or document number. These may be the receiving side of the ERPNext invoices that move stock to "Alectrofag". That is checked in Phase I-0.
- Vape Big has not been audited yet: we have no access.

**CW today (commit 27e979e).**
- *Built:*
  - the central catalogue (14,856 items, 13,082 barcodes);
  - cross-site matching with review screens;
  - stock buckets with an append-only ledger;
  - reservations and the movements engine;
  - the VERIFY and UNSTAMPED locations;
  - the opening estimate (296,599 units on staging);
  - a purchasing API;
  - TOTP staff login.
- *Not built:*
  - any staff screen for stock movements;
  - the queue screens;
  - costs or values of any kind;
  - suppliers, purchase orders, invoices and documents;
  - a way for new site barcodes to reach CW: `PUT /v1/listings` writes only the matching profile (`src/Api/Controller/ListingsController.php:11`).
- All three site channels are switched off.

---

## 2. Every function in use, and where it goes

Modules IM1–IM15 and C0 are described in §3. "Stays" means it stays on the website, and the connector already planned in plan.md §6 tells CW about it. "W2" means Wave 2: after I-Day, within about 2–3 months.

### 2.1 ERPNext

| # | ERPNext function | Use (volume) | Goes to | Notes |
|---|---|---|---|---|
| E1 | Purchase Invoice with stock: goods-in, cost and bill in one | **Heavy.** 2,218 since Dec 2025; 290–330 a month; 300–520k units and £0.6–0.9M a month | IM6 Receive (+invoice) and IM7 | One screen, as today. The invoice number becomes compulsory and unique. Each line keeps a selling mode (IM6) |
| E2 | Debit notes (returns to supplier) | Rare: 6 (the finance design counted 7) | IM7 | Count checked in the data copy |
| E3 | Purchase Order | **Heavy.** 467 since 13 Apr 2026; 82–113 a month | IM5 | 99 open, many of them stale part-receipts. At I-Day these are closed or imported (§8) |
| E4 | PO "Fetch Item" suggestion and "Download Items" XLSX | Regular (the PO list was opened 1,552 times since 3 Jul) | IM9 "create draft PO"; IM5 XLSX | ERPNext ignores what is already on order, lead times, pack sizes and the sister sites |
| E5 | PO printouts (3 print formats, one of them "Love Vaping Ltd") | **Heavy.** 859 PDFs, 287 since 1 Sep | IM5 PDF, with the buying company's letterhead | Not emailed from ERPNext today |
| E6 | PO data import | Rare: 2 | IM5 import | |
| E7 | Supplier master | Regular: 47 names, 16–26 used a month | IM4 | Names only; the details are captured fresh |
| E8 | Supplier fields on items (main on 10,048 items, secondary on 2,650) | Regular (set automatically) | IM4 supplier items | "Secondary" just means the last different supplier |
| E9 | Standard Buying price list (8,221 rows) | Regular, but never updated after it is first created | IM4 last price and price history | ERPNext pre-fills POs with the first price ever typed |
| E10 | Pack sizes (UOM conversions) | Never (18 hand-typed "Box ×5" lines) | IM4 pack per supplier item; IM6 converts to units | Removes the 336-unit bug |
| E11 | Monthly average-sales and safety-stock job | Regular: about 17.6k variants | IM9 (nightly, all three sites) | |
| E12 | Items Below Safety Stock report; Item Management page | Regular: 13 views and 35 exports; 13–20 page loads a day | IM9 lists; IM3 "discontinue" | |
| E13 | Stock Reconciliation (counts, negative-stock fixes, untracked receipts, mode changes) | **Heavy.** 973 in total; about 100 a month | IM2 (count, adjustment, write-off, each with a mode per line) | No reasons recorded today; +644k vs −128k units overall (investigated in I-0) |
| E14 | Stock Entry | Rare: 7 ever | IM2; opening via IM15 | |
| E15 | Stock mode and safety stock pushed to the site on every item save | **Heavy:** 9,920 pushes since 1 Aug, mostly side effects of saving invoice and reconciliation lines; 6–21 real changes a day | IM6/IM2 mode per line; IM10 switch; IM9 thresholds | |
| E16 | Moving-average valuation and Repost Item Valuation | **Heavy.** 9,385 reposts Jul–Sep | IM8 | |
| E17 | Landed Cost Voucher | Never | IM8 (freight and duty lines on the same invoice) | A "Carriage Inwards" item has been used on purchase invoices since 24 Sep |
| E18 | Bin (stock per item; 707 negative) | **Heavy** | CW stock buckets (built) | ERPNext quantities are not used as a source |
| E19 | Warehouse (1) | Regular | CW MAIN / VERIFY / UNSTAMPED (built) | No shelf locations anywhere |
| E20 | Item master (22,632 templates and variants) | **Heavy.** 277 new since 1 Sep | CW catalogue (built) and IM3 | Products are created on the website |
| E21 | Item create/edit API from the website | Regular | Retired at I-Day **only if** website sales stop going to ERPNext (decision 17); otherwise it stays | |
| E22 | Item enable/disable sync | Unknown | IM3 "discontinued" plus listing status | ERPNext's flag is inverted; do not copy it |
| E23 | Brand (288) | Regular | IM3; IM9 filter | |
| E24 | Item Group (5 flat groups) | Not meaningful | Dropped; IM3 product type replaces it | |
| E25 | Website sales invoices via the queue | **Heavy.** About 40–45k a month | Stock: CW reservations and dispatch (connectors). Money: decision 17, later Part 2 F1 | 1,881 failed rows were never retried |
| E26 | Manual sales invoices moving stock to Alectrofag, Vape Big, Unit 6, Unit 7, Ashton, MYV, Exchange and others | Regular: about 50 a month, 10–12k units | IM11 | "Exchange" (29 invoices, 445 units) and 4 small customers are classified in I-0. They may be supplier swaps (IM7) rather than trade |
| E27 | Goods-in push to the site, plus the status poll | **Heavy** | Relay during the shadow weeks; then IM10 site stock writer. The doors are blocked at I-Day | 2 confirmed bugs (fixed in I-0) |
| E28 | Stock reconciliation push to the site (sets an absolute number) | Regular | IM2; the door is blocked at I-Day | |
| E29 | Stock feed endpoint (changes data when it is read) | Rare | Retired; never called | |
| E30 | Reports: Stock Ledger (91), Stock Balance (27 views, 42 exports), Gross Profit (19), Item List exports (24) | Regular | IM14 (gross profit in W2) | |
| E31–E33 | P&L, General Ledger, automatic GL postings; no Payment Entry, Journal Entry or bank | By-product, or never used | §5 bridge; later the Part 2 ledger | Not real books |
| E34 | Company, currencies, fiscal year, VAT template | Regular | Buying company, VAT code and currency on POs and invoices | Statutory year end to confirm (29 Apr vs 31 Mar) |
| E35 | Users and roles (all users hold all 43 roles) | Regular | IM1 | |
| E36 | Data Import | Rare | IM3 bulk CSV; IM4/IM5 imports; IM15 | |
| E37 | Naming series, default warehouse, item search, PO pack-size script | Regular | IM1 numbering; CW search; IM4 packs | |
| E38–E39 | Custom logs, queues, 5 per-minute jobs, notifications | Plumbing | Dropped | |
| E40–E42 | Material Request, RFQ, Supplier Quotation, Item Reorder, Purchase Receipt (488 abandoned drafts), Delivery Note, Pick List | Never | Dropped; picking and dispatch stay on the site | |
| E43 | Batch, serial, expiry, Quality Inspection, stock reservation, barcodes | Never (0 rows) | Batch and expiry dropped (decision 24); checks done in IM6 | |
| E44 | Guest bulk item-import endpoints | Unknown | Not copied; the ERPNext admin removes them | Security |
| E45 | Vaping Products Duty and stamps | Nothing exists | IM3, IM6, IM13 | New |
| E46 | History since 15 Dec 2025 | — | IM15 archive (6-year record) | |
| E47 | Standard Selling price list (12,443 rows, created once; used to value £0 lines) | Background | Not needed in CW (selling prices stay on the sites). Trade prices come from past trade-invoice lines (IM11) | |

### 2.2 Website admin, APIs and crons

These are Vape and Go figures unless stated. Volumes cover 90 days; access-log counts cover 19 Sep–2 Oct.

| # | Site function | Use | Decision | Where |
|---|---|---|---|---|
| S1–S2 | Checkout and office-order stock-out | About 5,100 rows a day; 815 office orders | Stays | CW reserve → commit |
| S3 | Goods-in from ERPNext purchase invoices (push queue and stock worker; it also sets the mode) | 864 invoices, +1.41M units | Moves | Relay **required** from each site's T0 (shadow) until I-Day; then IM6 |
| S4 | Supplier returns (negative lines) | 3 lines | Moves | IM7 |
| S5 | ERPNext-raised sales invoices taking site stock | 152 documents, −33,594 units | Moves | IM11 |
| S6 | ERPNext stock reconciliation push | 293 messages | Moves | IM2 |
| S7 | ERPNext mode and safety-stock push | About 4,800 a month | Moves | IM6/IM2 mode per line; IM10; IM9 |
| S8–S9 | "ERP Stock Update" monitor; ERP status poll | 7 loads; 3,995 polls | Retired at I-Day | CW queues; site push status |
| S10, S13 | ERP Sync Dashboard; ERP→site reconcilers | 0; off | Retired | — |
| S11–S12 | Site → ERPNext sales invoices, cancellations and item pushes | 123,000 + 656; 60 / 253 / 695 | Decision 17 | — |
| S14 | Inventory > Stock (manual in/out, transfer, ledger edit/delete) | Vape and Go about 0; Electrofag 271 hand-typed stock-ins | Moves; ledger edit/delete retired now | Electrofag stock-ins checked against ERPNext invoices in I-0: if they are transfers, they become IM11 allocations; real deliveries go through IM6 |
| S15–S19 | Stock Scan, Warehouses, Batch, transfers, Vendor | 0 | Retired | IM6/IM2, CW warehouses, IM4 |
| S20–S21 | Bulk stock/mode/cost editor; variant "General" stock fields | 36 and 302 saves in 14 days | Split: stock, mode and cost fields become read-only for CW-managed items, with an **"Open in CW" link** beside each locked field; prices, text and the order limit stay | IM10, IM8 |
| S22–S23 | SKU tab; SKU and barcode creation (including dispatch "assign barcode") | 1,174 list calls; 603 new barcodes in 90 days | Stays | **New barcode sync** into CW (IM3) |
| S24 | Dispatch scanning and labels | 124,510 labels | Stays | CW "ship" via the connector |
| S25 | Dispatch "cancel & restock remaining" | 52 in 14 days | Screen stays | Sent to CW as **not restockable → VERIFY** until plan §16 Q5 says what the button means on the floor |
| S26–S28 | Order-line cancel, refund and RMA restocks | +3,647, 2,264 and +682 units | Stays | CW events; returns inspection in W2 (IM12) |
| S29–S30 | Back-in-stock emails; notification list | 1,534 sign-ups | Stays | Fires on every CW write of quantity or mode |
| S31 | Inventory reports (Stock Status ×2, Below Safety Stock, Order Planner, Out-of-Stock Review) | 5–13 real opens each | Move; site versions kept until I-Day | IM9. They also run on every admin page load (a bug) |
| S32 | Nightly stock snapshot | 29,127 variants a night | Kept as duty evidence (after the UK-time fix) until I-Day; then IM14 | Electrofag and Vape Big get the cron |
| S33 | Replenishment build (fixed 2-day lead time) | 9,367 variants a night | Moves | IM9 |
| S34 | Mobile app stock screens | About 3,300 calls in 14 days | Stays | The CW site stock writer adds **descriptive stock-log lines**, so `stock_log` still shows goods-in, counts and write-offs |
| S35 | Product Mapping, catalogue export, db-transfer | Low | Product Mapping retired; db-transfer stays for now | |
| S36–S37 | SKU label flag; old stock writers | Never; 0 hits | Retired / web-blocked before any site goes live | |
| S38 | Vape Big stock functions | Not audited | Audited when access arrives (decision 5) | |

### 2.3 Needed, but done nowhere today

| Need | Why | Where |
|---|---|---|
| Stamp checks at goods-in, a duty record per delivery, an incident register | HMRC guidance; civil penalties since 1 Oct 2026; criminal offence from 1 Apr 2027 | Now: the register (§6). From I-Day: IM6, IM13 |
| Quarterly counts and the 2027 year-end stocktake, with count sheets kept | HMRC guidance; Companies Act s.386(4) | Now: paper or spreadsheet sheets (§6). From I-Day: IM2 |
| A record of stamped vs unstamped stock on the sales side during the grace period | HMRC record-keeping guidance | §6 interim record |
| Supplier due-diligence record and an import flag | HMRC guidance; Excise Notice 206 | Now: register tab. Then IM4 |
| Freight and duty in stock cost; write-down to selling price | FRS 102 13.6, 13.4, 27 | IM8 |
| Reasons and review on write-offs and count differences | Audit trail | IM2 |
| Stock owned per company; inter-company records | Companies Act s.386(4)(c), if there is more than one company | IM8, IM11 |
| Free gifts and samples kept apart from sales | Tobacco and Vapes Act (29 Oct 2026); VAT on gifts | IM2 reason code |
| Single-use and TRPR limits | Single-use ban; TRPR reg 36 | IM3 (warning until a person confirms the item's fields) |
| Evidence kept 6 years, off the server | HMRC; the duty-start record | Object Lock storage (owner step 5) |

---

## 3. The modules

Effort is in engineer-weeks, before the 20% contingency added in §9. **Must** means needed on I-Day; **W2** means the second wave.

### C0 Core change (once, at the start)
- **What:** the frozen stock core gets, in one reviewed migration (0006):
  - a cost and a document (receipt) link on goods-in;
  - a value ledger;
  - a per-item value sequence written inside `Stock.php`'s transaction.
- **Checks:** the stress ("hammer") tests are re-run, and the throughput of the serialised change-feed transaction is re-measured with the extra insert.
- **Why first:** later phases then book test data with values from day one, instead of changing the core twice.
- **Effort:** Must, 1–1.5.

### IM1 Foundations: roles, documents, numbering, reasons
- **Replaces:** E35, E37.
- **Roles:**
  - buyer, purchasing manager, goods-in, purchasing desk (receive and invoice), stock controller, reviewer, accountant (read and exports), auditor (read-only);
  - plus today's viewer, mapper, mapping lead, warehouse, manager and admin;
  - **several roles per person** (today it is one).
- **Review rule:** "post first, a second person reviews". The only *blocking* approvals are:
  - activating a new supplier;
  - positive stock adjustments without a supplier document above a small limit (IM2).
- **Backup approver:** you, holding the reviewer role, which is separate from admin. With 3–4 people, holidays must not stop work.
- **Admin:** cannot post documents.
- **Corrections:** always reversals, never edits or deletes. Everything goes to the audit log.
- **Document store:**
  - every PDF and photo is kept with its sha256 fingerprint;
  - on staging, files are stored locally;
  - before live, the store switches to write-once storage (S3 London or B2 with Object Lock), kept at least 7 years.
- **Also:** number series (PO-, GRN-, SINV-, DN-, CNT-, ADJ-, WO-, TRD-), reason codes, and PDF/CSV output.
- **Effort:** Must, 1.5–2.5.

### IM2 Stock control: counts, adjustments, write-offs, VERIFY, queues
- **Replaces:** E13, E14, S6, S8, S14, S15.
- **Must:**
  - **Count screen** (tablet and scanner). Each line shows **stock now** and a **selling mode**, plus an "unstamped found" button that quarantines the units and opens an incident. The built "as-of" rule stops late dispatches from being subtracted twice.
  - **Count sessions:** each session is a count sheet kept permanently. These are the stocktake records the Companies Act requires.
  - **"Next to count" list:** sorted by sales and by days since the item was last counted.
  - **Adjustments and write-offs** with a compulsory reason: damaged, faulty, lost/theft, found, wrong item booked, supplier collection, destroyed, free gift/sample (kept separate), or other with a note.
  - **VERIFY work list:** doubtful cancellations and failed goods-in checks go back to stock, back to the supplier, or are written off.
  - **Queues:** count review, policy review, oversell events, goods-in suspense.
  - **T0 rebase** of the opening figure. It must exist before any count is booked on staging.
- **Review rules:**
  - Counts and stock decreases post at once, so the shelf figure is right straight away. Differences above a limit are reviewed by a second person within a few days and reversed if rejected.
  - **Increases without a supplier document** (for example "found") above a small limit wait for approval and must answer "stamped?". Otherwise untracked receipts could skip the stamp check and the cost record.
  - Before the reason list is fixed, the +644k vs −128k unit history of ERPNext reconciliations (April alone +285k) is examined in the I-0 data copy.
  - Limits are in units until costs exist (Phase I-5).
  - ERPNext's "set to this number" pushes never become counts automatically.
- **W2:**
  - a count-programme scheduler with coverage targets;
  - transfer documents with "in transit", needed only if shop units become separate locations (decision 10).
- **Effort:** the count screens (3–4) and the T0 rebase (0.5–1) are already in Part 1's budget. New work: Must 1–2, W2 1–1.5.

### IM3 Item card: legal and buying fields, barcodes
- **Replaces:** E12 (the disable button), E20, E22–E24, the item-update imports.
- **Fields:**
  - liquid ml (to 0.1) and nicotine mg/ml;
  - product type (e-liquid, shortfill, nic shot, prefilled pod, device/kit, single-use, coil, tank, accessory);
  - duty-liable (yes/no);
  - ECID/GBID, manufacturer, brand;
  - flavour (proposed, then confirmed, because it is not a field anywhere today);
  - single-use flag; discontinued / do-not-reorder flag.
- **Rules:**
  - TRPR limits (nicotine refill over 10 ml, tank over 2 ml, nicotine over 20 mg/ml) and the single-use rule are **warnings until a person has confirmed the item's fields**, and hard blocks after that.
  - The single-use flag is set by a person, never inferred from a "disposable" form.
- **Barcodes:**
  - outer-case barcodes ("this barcode = 10 units");
  - a **barcode sync** from site listings into CW's barcode table, because about 603 barcodes are added on the sites every 90 days, many at dispatch. A barcode already belonging to another item goes to review.
- **Bulk:** CSV export and import.
- **Staff time:** filling the legal fields is catalogue work, an estimated 40–80 hours, starting with the 8,199 items that hold stock.
- **Effort:** Must, 1.5–2.5.

### IM4 Suppliers and supplier items
- **Replaces:** E7–E10, S19.
- **Supplier record:** name, company number, VAT number, address, contacts, payment terms, UK or overseas, and the import-route evidence if overseas.
- **Due diligence:** date checked, who checked, evidence, next review date.
- **Supplier items:** supplier code, purchase unit, units per purchase unit, minimum order, lead days, preferred or alternate, last price, full price history.
- **Rules:**
  - A new supplier is inactive until a second person approves it.
  - Overdue due diligence shows a warning on POs.
  - An overseas supplier's POs are blocked until an approved stamping route is recorded.
  - The last price comes from the latest invoice.
  - **No bank details** are stored until the payments part is built.
- **W2:** importing supplier price lists.
- **Effort:** Must 1.5–2, W2 0.5.

### IM5 Purchase orders
- **Replaces:** E3–E6.
- **Screens:** PO list; PO editor with lines added from the reorder list, by scan, search, brand or supplier; quantities in the supplier's pack, with the unit total shown; price pre-filled from the last invoice.
- **PDF:** with the **buying company's letterhead**. It replaces the 3 ERPNext print formats.
- **Also:** XLSX export and import.
- **Status flow:** draft → sent → part-received → received → closed, or cancelled with a reason.
- **Approval:** an optional limit, set high at first (decision 11). A weekly report lists POs for review instead of blocking them, so the "order today, delivery tomorrow" rhythm stays quick.
- **Rules:** VAT code on every line; buying company on every PO. Over-delivery tolerance is proposed at 10% (ERPNext allows 200%). A PO is optional at goods-in: only 472 of 2,218 invoices were linked to one.
- **Effort:** Must, 2–3.

### IM6 Receive (+ invoice) with duty-stamp checks
- **Replaces:** the goods-in half of E1; E27, S3, S7 (for receipts), S14 deliveries, S15. From I-Day it also replaces the spreadsheet register.
- **Who keys it:** the **purchasing desk**, the same people who type purchase invoices today. The **goods-in bench** does the physical check on a tablet. It can be the same person.
- **Fast line entry:**
  - "receive all as ordered" from the PO, then edit;
  - **import the supplier's invoice or packing-list spreadsheet**;
  - look up by supplier code;
  - or scan.
- **Each line shows:** pack, units per pack, units (always packs × units per pack, which ends the boxes-for-units bug), provisional cost, **stock now**, and a **selling mode on receipt**.
  - The mode defaults to the item's last selling mode.
  - An Out-Of-Stock item automatically goes back to its previous selling mode on receipt, and the line can override this.
  - The site stock writer then sends quantity and mode together, as ERPNext does today, so back-in-stock emails fire.
- **Bench checklist:** stamp on the outer retail pack and sealing it; stamp type; supplier and paperwork credible; photos.
- **Line exceptions:** unstamped, damaged, wrong item, short or over. Each one sends the units to VERIFY and opens an incident.
- **Rules:**
  - The supplier invoice number is compulsory, and the pair (supplier, invoice number) must be unique. The PDF must be attached.
  - **Duty at I-Day:** I-Day is after 1 Apr 2027, so every duty-liable liquid must arrive stamped. Anything else is refused or quarantined, with an incident. The "made before 1 Oct" logic lives only in the pre-go-live register.
  - Expected duty (ml × £0.22, rounded down to the penny) is shown for information only when the invoice shows duty separately.
  - A scanned-stamp-code field is ready for HMRC's promised retailer scanning service.
  - Single-use and over-limit items get the IM3 warning or block.
  - A delivery is booked by one route only.
  - Sister-site-only items use this screen from Phase 5b (plan §9).
- **Acceptance:** a typical invoice must be **no slower than in ERPNext**, measured against the timings from step 9.
- **CW-down fallback:** a paper receiving sheet, keyed later with the real received time.
- **Effort:** Must, 3–4.5.

### IM7 Supplier invoices, credit/debit notes, supplier returns
- **Replaces:** the bill half of E1; E2, S4.
- **Must:**
  - an invoice entered with the delivery (the default), or later against the deliveries it covers;
  - credit and debit notes;
  - **supplier return** as a simple negative receipt with a reason, followed by the credit note;
  - an invoice-total check;
  - a **price-variance report** against the PO or last price;
  - a goods-received-not-invoiced (GRNI) list.
- **Rules:**
  - A return made before the invoice reduces GRNI, so stock value is never reduced twice.
  - Supplier credits include the duty, because a retailer cannot reclaim duty on damaged or destroyed stock.
  - Supplier payments are **not** made in CW yet.
- **W2:** the tolerance engine (price ±2% or ±£5 per line; total ±£0.05 × lines) and the hold queue. With payments outside CW, a hold blocks nothing. They return when CW becomes the supplier-bill record (option A1 in §5) or in Part 2.
- **Effort:** Must 2–2.5, W2 1–1.5.

### IM8 Valuation and landed cost
- **Replaces:** E16, E17, the stock value in E18, the cost part of S20.
- **Must:**
  - moving weighted average cost per item (per owning company if there is more than one), as ERPNext does today;
  - freight and duty invoice lines spread over the lines of **the same invoice**, by value or by ml;
  - a negative-stock true-up at the next receipt;
  - customer returns valued at their original cost;
  - valuation at any date;
  - a fingerprinted month-end snapshot (the year-end one is the statutory stock statement);
  - the opening cost load;
  - optional nightly write-back of the CW average cost to the site's cost field for linked listings (decision 12).
- **Rules (FRS 102):**
  - cost = price less discounts, plus duty, plus inbound freight; reclaimable VAT is not cost;
  - FIFO or weighted average, never LIFO;
  - a back-dated document gets one explicit correction instead of ERPNext's constant reposting.
- **W2:**
  - a write-down (lower of cost and selling price less costs to sell) register and month-end close screens. Until then the accountant judges write-downs from the valuation report at each reporting date;
  - freight allocated across different invoices.
- **Effort:** Must 2.5–3.5, W2 1–1.5.

### IM9 Reorder suggestions
- **Replaces:** E4, E11, E12, S31, S33.
- **Basic list in Phase I-2:** built from the existing purchasing API and imported sales history, so "suggestion → draft PO → PDF" can be tried early.
- **Full engine in Phase I-6:** demand recalculated nightly from all three sites; on-order quantities; supplier lead times; packs and minimum orders.
- **Sales-history import:** Vape and Go's `consolidate_product_sale` and Electrofag's order lines, with Vape Big added when access arrives. Without it, CW would start with only a few weeks of history.
- **Rules:**
  - Out-of-stock days are left out of averages.
  - The **14–22 Sep 2026 stockpiling** (+43% units a day) and promotions (such as the Elux 6-for-£10 from 23 Sep, detectable from revenue per unit) are flagged, not trusted.
  - Suggestion = daily demand × (lead days + review days + safety days) − available − on order, rounded up to the pack and minimum order. Safety days default to 5, as today.
  - Discontinued, single-use and over-limit items are never suggested.
  - The suggestion is advice: the buyer edits the draft.
- **Effort:** Must, 3.5–4.

### IM10 Selling-mode switch and site stock writer
- **Replaces:** E15, E27, E28, S7; the mode, stock and cost fields in S20–S21.
- **Labels stay familiar:** **In-Stock / From-Warehouse / Out-Of-Stock**, with CW's meaning shown beside each.
- **Items not yet counted ("legacy"):**
  - From I-Day, CW writes the site's own **quantity, mode and low-stock threshold**, as ERPNext does today. This changes plan §6.5, which wrote nothing for legacy items. Without it, the switch would reach no site on I-Day, because counting starts after I-Day.
  - Mode is **per site** for legacy items. A change applies to the sites you tick (with an "all sites" option), so Electrofag's In-Stock items keep selling as they do today until they are protected.
- **Counted ("protected") items:** one policy for all sites (plan §7.4), with In-Stock → backorder, From-Warehouse → strict, Out-Of-Stock → stopped.
- **Every write:**
  - adds a descriptive line to the site's stock log (for the Mobile app);
  - fires the back-in-stock email whenever the item becomes available again, whether the quantity or the mode changed.
- **Site side:** the stock, mode and cost fields become read-only for CW-managed items, with an "Open in CW" link. This is a site change, made on proto first.
- **Build order:** built on Vape and Go proto **from Phase I-3**, so every later phase tests through it; then the Electrofag and Vape Big variants.
- **Effort:** Must, 3–4 (re-estimated from 1–1.5).

### IM11 Trade, inter-site and shop-unit issues
- **Replaces:** E26, S5, and probably Electrofag's hand-typed stock-ins.
- **Must:**
  - an issue document with delivery-note and invoice PDFs;
  - 12 trade customers, with prices from their past invoice lines;
  - **allocation** for legacy items: moves site-figure quantity from Vape and Go to a sister site, with nothing changing in the building. If I-0 confirms the hand-typed Electrofag stock-ins are transfers, this removes a double entry staff do today;
  - shop units handled as issues at I-Day (decision 10);
  - **Vape Big without a connector** (decision 5): treated as a trade customer, so an issue reduces CW stock, as the ERPNext invoices do today.
- **Records:** the issue register names buyer and seller (Companies Act s.386(4)(c)).
- **W2:** the monthly inter-company statement (a filtered report covers it at I-Day).
- **Receivables:** stay with the accountant. In ERPNext they were never settled: Alectrofag £169k, Vape Big £125k.
- **Effort:** Must 1.5–2, W2 0.5.

### IM12 Returns inspection (W2)
- **At I-Day:** returns go back on sale as today; doubtful cancellations go to VERIFY (built); electrical-waste take-back stays in a spreadsheet.
- **W2:** a RETURNS and DAMAGED shelf with resell / supplier / write-off decisions, plus the take-back log (kept 4 years).
- **Effort:** W2, 1–1.5.

### IM13 Duty (VPD) records
- **Must:**
  - a duty register built from receive documents, exportable for HMRC;
  - an incident register;
  - a supplier-checks-due list;
  - an "unstamped must be zero" alert (any "unstamped found", or anything in UNSTAMPED);
  - the free-gift reason flagged on write-offs.
- **Before go-live:** the rebuilt stop-gap report (§6).
- **W2:** an MHRA ECID check (if the list's format allows it) and a fuller free-gift report.
- **Effort:** Must 1.5–2.5 (including the stop-gap, 0.5–1), W2 0.5–1.

### IM14 Reports and the accountant's files
- **Must:**
  - stock balance at any date, with value;
  - stock ledger with value;
  - movement summary;
  - GRNI;
  - valuation;
  - count archive;
  - daily and month-end snapshots (they replace the site snapshot from I-Day);
  - item export;
  - the accountant's pack (§5).
- **W2:**
  - gross profit by item, brand and site. It needs the unit sale price in each sale message ("F1-lite", budgeted with the connectors);
  - supplier spend.
- **Effort:** Must 2–2.5, W2 1–1.5.

### IM15 Switch-over tools
- **Imports:**
  - a seed import now and a final import on the night before I-Day, both from the ERPNext backup copy;
  - suppliers, supplier items and last prices;
  - **open PO lines (mandatory)**;
  - opening cost;
  - duty-register rows;
  - sales history;
  - trade customers and prices;
  - the T0 correction list.
- **Archive and runbook:**
  - the ERPNext archive export into write-once storage;
  - the I-Day runbook;
  - a **rollback file**: CW receipts and corrections in ERPNext's Data Import layout, imported by the ERPNext admin only if we roll back.
- **Practice replay:** the last 2 weeks of real ERPNext documents loaded as pre-filled drafts on proto.
- **Effort:** Must, 2–3.

### Ops and security for I-Day (new line)
- A production CW server.
- A **standby database with point-in-time backups and a tested restore**. The plan required this "once CW keeps the books"; from I-Day CW holds the only copy of POs, receipts and count sheets.
- The Object Lock storage back end.
- The remaining security gates, including the token and IP fix on `update_purchase_invoice.php` before the relay goes live.
- An external pen test, and fixes.
- A CW-down runbook.
- **Effort:** Must 2–3, plus the pen-test fee and hosting costs.

### Rehearsal, training, hypercare
- Practice by replay, role guides (SOPs), half to one day of training per role, and 2 weeks of daily CW-vs-site comparison with someone on call after I-Day.
- **Effort:** Must, 2–3.

### Not in scope unless you decide otherwise
- Shelf/bin locations and labels: 1.5–2.5. None exist today.
- Batch and expiry tracking: never used today.

---

## 4. A normal week from I-Day

| Who | What they do in CW |
|---|---|
| Buyer | Opens the reorder list for a supplier or brand → "create draft PO" → adjusts it → sends the PDF |
| Goods-in bench | Checks each delivery on the tablet: stamps, damage, short or over, photos. Problems go to VERIFY with an incident |
| Purchasing desk | "Receive + invoice": copies lines from the PO or imports the supplier's sheet, checks quantities and the mode per line, attaches the invoice, posts. Protected items reach the sites within seconds; legacy items go through the site stock writer |
| Stock controller | Works through "next to count"; makes corrections and write-offs with reasons; clears VERIFY; issues trade, inter-site and shop stock |
| Reviewer (manager, or you as backup) | Weekly: big count differences, write-offs, price variances. Approves new suppliers and "found" stock |
| Accountant | Monthly: imports the pack (§5). Pays suppliers from their own system, as today |

**On I-Day:**
- **1 working day before:**
  - new ERPNext POs stop;
  - staff close stale part-received POs;
  - the final data copy is taken from a fresh backup;
  - open PO lines, last prices and suppliers are imported into CW.
- **ERPNext:**
  - the ERPNext admin sets ERPNext's "stock frozen up to" date to I-Day − 1 and removes buying and stock rights;
  - ERPNext becomes read-only for buying and stock.
- **Live sites:**
  - `update_purchase_invoice.php`, `stock_reconcillation.php` and `update_product.php` are web-blocked;
  - `get_status.php` follows once its queue is empty;
  - the relay is switched off and the CW site stock writer switched on.
  - These are live changes, made only after your sign-off.
- **Cut-off:**
  - delivered and booked in ERPNext before I-Day → stays in ERPNext, and its bill stays with the accountant;
  - delivered before I-Day but not booked by then → booked in CW, with the real received date on the receipt (stock posts when it is booked).
- **Website sales into ERPNext:** decision 17 sets the conditions.

**Rollback window (4–6 weeks).** If buying in CW has to stop:
- the doors are reopened and the stock writer is switched off;
- the ERPNext admin imports CW's rollback file;
- this is a supervised import, instead of re-keying 300–450 invoices.

---

## 5. Accounts until CW has its own ledger

**Why the bridge is to the real books, not ERPNext.** ERPNext's ledger is a by-product of stock documents:
- no payments, no bank, no journals;
- "Creditors" shows −£7.1M;
- one VAT account mixes input and output VAT.

The company files VAT under VRN GB295311204, and ERPNext core cannot submit MTD returns, so returns have been filed from other software since at least 2019. Your real books are therefore kept elsewhere, and the accountant very probably already enters supplier bills there from the paper or email invoices.

**First question for the accountant:** "How do supplier bills get into your books today: paper, email, Dext, something else?" The answer decides the option. Importing CW's 300 bills a month into books that already capture them would **double purchases and input VAT**.

| | **A2: stock file only (recommended for I-Day)** | A1: CW becomes the supplier-bill record | B: Xero as the books, fed by CW | C: keep ERPNext as the books |
|---|---|---|---|---|
| How | The accountant keeps entering bills as today. Each month CW sends a periodic stock file: closing stock value, cost of sales per site, write-offs (gifts separate), count differences (first counts separate), write-downs, GRNI | Bills are entered only in CW and imported into the books as bills (file or API). The accountant stops capturing them elsewhere | CW pushes approved bills and a stock journal into Xero by API; payments, bank and VAT are done in Xero | CW writes copies into ERPNext |
| For | Nothing entered twice; the smallest change for the accountant (estimated 2–4 h a month); purchase VAT stays in their existing digital chain | One bill entry; the stepping stone to Part 2; the CW tolerance and hold queue become useful | Proper books now | No new tool |
| Against | Bills keyed twice (in CW for cost and duty records, and by the accountant), though the records are not duplicated in the books | The accountant changes process; needs the W2 tolerance and hold queue | Subscription; 1.5–2.5 more weeks; reopens the long-term decision | Needs write access to ERPNext; not real books; rejected by the approved plan |
| Recommendation | **Yes, for I-Day** | Later, or at I-Day if the accountant prefers | If the books need a new home anyway | No |

**Rules for every option:**
- Figures move by file import or API, never by retyping. Copy-and-paste is not a digital link (VAT Notice 700/22 §3.2.1).
- Files are built in Xero-compatible layouts.

**A2 month-end journal (for the accountant to confirm):**
- change in stock value: Dr Stock / Cr Cost of sales (or the reverse);
- GRNI accrual: Dr Purchases / Cr Accruals, reversed next month;
- the analysis lines above, for reclassification;
- **opening at I-Day:** CW's opening valuation is compared with the stock value in the books at I-Day − 1, and the difference is explained (uncounted figures, negative stock, cost basis) and journalled once by the accountant.

**A1 (perpetual) posting map:**

| Event | Debit | Credit |
|---|---|---|
| Opening stock at I-Day | Stock | Opening stock difference (agreed with the books) |
| Delivery booked | Stock | GRNI |
| Supplier invoice matched | GRNI (price difference to Stock, or to Cost of sales for the part already sold); Input VAT | Creditors |
| Freight or duty line | Stock (the share still held) / Cost of sales (the share sold) | Creditors |
| Dispatch, per site | Cost of sales – site | Stock |
| Customer return | Stock | Cost of sales – site |
| Supplier return, before / after the invoice | GRNI / Creditors | Stock |
| Write-off, damage, gift | Stock write-off (gifts separate) | Stock |
| Count difference | Stock count difference | Stock (or the reverse) |
| Trade / inter-company issue | Cost of sales – trade | Stock |
| Write-down and reversal | Stock write-down | Stock provision |

Sales revenue and output VAT are not part of inventory. They keep coming from wherever they come from today until Part 2 (F1). **Needed by about February 2027**, before Phase I-5.

---

## 6. Duty and other rules

### 6.1 The rules and where CW meets them

| Rule | Source | Where |
|---|---|---|
| VPD of £2.20 per 10 ml on all vaping liquids, including nicotine-free, from 1 Oct 2026 | FA 2026 s.115–116 | IM3 ml and duty flag; IM8 duty counted as cost |
| Goods made or imported from 1 Oct 2026 must arrive stamped; civil penalties of £2,500–£10,000 | SI 2026/338 reg 2(1)(b); FA 2026 s.125; CC/FS87 | Now: the register and refusal. From I-Day: IM6 |
| Unstamped pre-October stock may be sold only until 31 Mar 2027. From 1 Apr 2027 possessing, displaying or selling it is an offence (up to 2 years), and the goods can be forfeited | SI 2026/338 reg 2(1)(a); FA 2026 s.130, 132, 124, 141(5) | §6.2 run-down; IM13 "must be zero" alert; IM6 refuses |
| Goods-in checks: stamp on the outer pack and sealing it; supplier credible | HMRC handling guidance (9 Jul 2026) | Register now; IM6 and IM4 later |
| Records: each delivery; purchases and sales with stamp presence; stock with ml, duty status and location; counts at least quarterly; incidents | HMRC record-keeping guidance | §6.2 now; IM6, IM13, IM2 later |
| Keep records 6 years from the end of the financial year | Companies records; VAT Notice 700/21; Excise Notice 206 | Object Lock now; IM1 later |
| Year-end stock statement and the count sheets behind it | Companies Act s.386(4) | Spring 2027 stocktake (§6.2); IM8 and IM2 later |
| FIFO or weighted average, never LIFO; duty and freight in cost; lower of cost and selling price less costs to sell, with reversals | FRS 102 13.4, 13.6, 13.18, 27.2–27.4 | IM8 |
| Customer returns at their former carrying amount | FRS 102 23.53–23.55 | IM8 |
| Digital links to the accounts | VAT Notice 700/22 | §5 |
| A retailer cannot reclaim duty on damaged stock | SI 2026/331 Part 6 | IM7 credits include duty |
| No free gifts or substantial promotional discounts to the public from 29 Oct 2026 | Tobacco and Vapes Act 2026 | Owner step 2; IM2 reason code |
| Single-use ban; TRPR limits | Single-use ban; TRPR reg 36 | IM3 |
| ECID/GBID: the producer's duty, but good due-diligence evidence for you | TRPR 31, 35, 50 | IM3 field |
| WEEE take-back records kept 4 years | WEEE guidance | Spreadsheet; IM12 in W2 |
| An importer pays duty at the border, needs stamps before arrival, and becomes a TRPR and WEEE producer | HMRC guidance | IM4 import block; decision 21 |

### 6.2 Interim compliance: now until I-Day (CW is not live)

1. **The register** (owner step 4):
   - every delivery from 1 Oct, with the new columns, an incidents tab and a supplier-checks tab;
   - deliveries made or imported from 1 Oct that arrive unstamped are always refused or quarantined;
   - **from 1 Jan 2027, all unstamped deliveries are refused** (decision 8), because they could not be sold in time.
2. **Evidence archive:**
   - the register, the 1 Oct duty-start record (`/root/cw_work/duty/`), the snapshot-timing evidence and the count sheets are copied monthly into the Object Lock storage;
   - the snapshot rows are deleted from the database on 5 Nov 2027, and the timing logs rotate out around 1 Nov 2026.
3. **The snapshot fix** goes live on Vape and Go (owner step 6), and the cron is added on Electrofag and Vape Big.
4. **Counts** (decision 19):
   - **December 2026:** count the best-selling liquids and every item the stop-gap shows with unstamped stock.
   - **March 2027:** a stamp-check count of all duty-liable liquids on printed or spreadsheet count sheets, with columns for item, barcode, counted, stamped, unstamped, counter and time. CW will not be live, so this is not done on a CW screen.
   - **Year-end:** a full stocktake at the statutory year end, kept as the Companies Act stock statement. If the year end is 31 Mar, this and the March stamp check are one count. If it is 29 Apr, the March count covers liquids only and the full count is at the end of April (decision 18).
   - **Entering counts:** results are entered into ERPNext as stock reconciliations, as staff do today, so the site figures are better at T0. The sheets are archived.
5. **Rebuilt stop-gap report** (weekly from January 2027):
   - it runs on staging from **read-only export files** made on the site boxes, never from a staging-to-live database connection;
   - the base is the **positive duty-day figure of 322,790 units**, which includes the 26,191 units on unlinked listings and the sister-only items that the staging estimate (296,599) leaves out;
   - it adds deliveries the register declares as made before 1 Oct;
   - it deducts known sales (Vape and Go, Electrofag, ERPNext trade invoices) in two ways:
     - the **upper figure** assumes sales take stamped stock first (worst case);
     - the **lower figure** assumes the oldest stock sells first;
   - Vape Big sales are not deducted, which errs high;
   - **we act on the upper figure**, and the counts settle it.
   - (The draft's "oldest sells first" figure erred *low*.)
6. **Sales-side stamp record.**
   - Stamp presence cannot be recorded on each website sale line without changing dispatch.
   - Instead:
     - unstamped units are kept physically apart and picked first where practical;
     - each item gets a dated record: "all stock stamped from date X", set by a count or check. Sales after that date are stamped; sales before it are mixed, and the stop-gap estimates them.
   - Ask your adviser whether this is enough (unconfirmed).
7. **Shop units** (Unit 6, Unit 7, Ashton): if they hold stock, the same 31 Mar clearance and records apply to them (decision 20).
8. **By 31 Mar 2027:**
   - every unstamped unit is sold, returned, exported or destroyed, with evidence (return notes, destruction certificates) kept 6 years;
   - listings with only unstamped stock are set Out-Of-Stock on all sites;
   - the UNSTAMPED location must be **empty**, not used as a holding shelf, from 1 Apr 2027.

**Points the legal research could not confirm:**
- whether HMRC expects quarterly counts from retailers;
- the MHRA list format;
- duty drawback on exports;
- whether any of your companies imports;
- whether the item-level dated stamp record is enough.

---

## 7. Build order (staging and proto first; nothing live until you sign off)

Two engineers:
- **A** builds the inventory modules;
- **B** builds the planned site connectors (plan §12) and joins the inventory work where the two meet.

With one engineer, the same phases run one after another. Effort is in engineer-weeks before contingency.

| Phase | Builds | Where | Eng-wks | Alongside (plan §12) | You can test at the end |
|---|---|---|---|---|---|
| **I-0 Get ready** (Oct–Dec 2026) | Your steps; ERPNext fixes (by the admin); backup copy and seed import; checks: Electrofag stock-ins vs ERPNext invoices, the "Exchange" customer, the +644k correction history, how many items will only have an estimated cost; stop-gap report | Staging; export files only | 1–1.5 | Phase 2b staff mapping review | Your suppliers and last prices on staging; first stop-gap report in January |
| **I-1 Foundations** | C0 core change, IM1 | Staging | 2.5–4 | B: Phase 3 starts (Vape and Go connector, proto) | Sign in as buyer, desk and reviewer and see different menus; stress tests green |
| **I-2 Purchasing** | IM4, IM5, IM9 basic list, sales-history import | Staging | 5–6.5 | B: Phase 3, F1-lite | A supplier approved by a second person; a reorder list for a brand → draft PO → PDF, compared with ERPNext "Fetch Item" and a real PO |
| **I-3 Receiving** | IM3, IM6, site stock writer on Vape and Go proto | Staging + VPG proto | 6–9 | B: Phase 6 mapping safety | Book 5 real deliveries from last week's paperwork, including a "box of 5", a PO copy-down and a supplier-sheet import; an Out-Of-Stock item comes back on sale on proto and its back-in-stock email fires; timed against ERPNext |
| **I-4 Invoices and stock control** | IM7, IM2 (with Part 1's count screens and the T0 rebase) | Staging | 6.5–9.5 (3.5–5 from Part 1) | B: Phase 4 Electrofag | A duplicate invoice is refused; a supplier return with credit; count 20 items with tablet and scanner; "found" stock needs approval |
| **I-5 Valuation and accountant files** | IM8, IM14 pack, opening cost | Staging | 4–5 | B: Phase 4 Vape Big (if access) | With your accountant: a test month on staging; they import the pack into a test company and the totals agree |
| **I-6 All sites** | IM10 (rest), IM11, IM9 full | Staging + the proto sites that exist | 5–7 | Needs Phases 3–4 | Change a mode on one site only; issue stock to Electrofag and watch both site figures move; a trade issue to Unit 6 |
| **I-7 Duty, reports, switch-over** | IM13, IM14 reports, IM15; ops and security work | Staging + proto | 3.5–5 (+2–3 ops) | Production server, standby, pen test | Export the duty register; a practice I-Day from a fresh backup copy; the rollback file imports into an ERPNext test copy |
| **P+ Rehearsal** | Plan §14 tests plus every inventory flow; **practice by replay** (2 weeks of real documents as pre-filled drafts, plus about 20 timed from paper per person, instead of 2–4 weeks of double keying); training | Proto + staging | 2–3 (+1–2 Part 1) | = Phase P | **Your written sign-off** |
| **Live** | 5a shadow (relay on, ≥7 clean days per site) → 5b live (items still legacy) → **I-Day** on the 1st of a month → 2 weeks of hypercare and a 4–6 week rollback window → Phase 7 counting, protecting items as they are counted | Live, after sign-off | ~2 | — | Daily CW vs site comparison; the first accountant pack |

**How this fits the plan:**
- Under decision 1, option A, Phases 5a/5b happen just before I-Day, not months earlier.
- The relay (plan §9) is **required** from each site's T0 until I-Day. Otherwise CW misses about 514k units a month of goods-in for legacy items.
- The count gate (§7.4) still needs every site that sells from the warehouse to be live, so no item is protected until Vape Big is live (decision 5).

**Checkpoints.** After I-1 and after I-3 we re-plan using the measured pace. If we are ahead, the Wave 2 items move before I-Day. I-Day itself is not pulled earlier than the gates in §9 allow.

---

## 8. Data brought across

**Method.**
- CW never connects to ERPNext.
- Data comes from **two copies**: a seed copy now (I-0) and a final copy the night before I-Day. Both are queried from a `bench backup` restored on a separate box, never from the live ERPNext database.
- If a live query is ever unavoidable:
  - use a dedicated read-only database user (not the site user, which can write);
  - set `max_statement_time=30`;
  - run EXPLAIN first;
  - no IN-subqueries on large tables;
  - work in small id ranges.

| Data | Source | How | Notes |
|---|---|---|---|
| Items, barcodes | CW already (14,856 items, 13,082 barcodes) | Listing sync plus the new barcode sync | ERPNext items not needed |
| Legal item fields | CW data (ml on 10,194, strength on 12,332) plus the catalogue team | IM3 bulk CSV | 40–80 staff hours (estimate) |
| Suppliers | Copy (47 names) + the accountant's supplier list + keyed details | IM15 → second-person approval | No bank details. If no copy is allowed: type the 47 by hand (1–2 hours) |
| Supplier items | Purchase-invoice lines + the supplier fields on items | IM15 | Pack sizes and supplier codes are captured at the first delivery |
| Last prices | Purchase-invoice lines per unit, the latest before I-Day (final copy) | IM15 | Not the Standard Buying list |
| **Open POs** | Final copy | **Mandatory.** Staff close stale part-received POs first; real open lines are imported as CW POs "sent", quoting the ERPNext number | Otherwise IM9 sees nothing on order and over-orders |
| Sales history | Vape and Go `consolidate_product_sale`, Electrofag order lines, Vape Big later | IM9 import | September 2026 stockpiling and promotions flagged |
| Opening quantities | Each site's figure at its T0 (plan §8.1), **after the I-0 corrections** (9,571 + 336 units re-sent) | T0 rebase | ERPNext quantities are not used. Dispatch "cancel & restock remaining" is not corrected until Q5 is answered |
| Sister-only items | The sister site's own figure where it is 0 or more, marked uncounted | Opening tools | Changes D40a (which opened them at 0); counted first in Phase 7 |
| Opening cost | Weighted average of recent purchase-invoice lines covering the quantity on hand, **plus their share of freight (Carriage Inwards) and duty lines**. Fallback: the site cost field where it is not a £1 placeholder. Otherwise "estimate" | IM8 load, signed by the accountant | Vape and Go has a cost on 13,957 of 29,105 variants; Electrofag on 1 of 9,014. The size of the "estimate" group is measured in I-0 |
| Opening value | CW opening valuation vs the books at I-Day − 1 | §5 opening line | The accountant signs |
| Unpaid supplier invoices | The accountant's books | Not migrated | |
| Reorder levels | Calculated fresh | — | |
| Trade customers and prices | 12 customers + their trade-invoice line rates | IM11 | |
| Duty register | Spreadsheet + the 1 Oct record files | IM15 → IM13; files to write-once storage | |
| 6-year history | ERPNext registers and the stock ledger, 15 Dec 2025 → I-Day | Archive export | |

---

## 9. Effort and calendar

| Item | Must at I-Day | Wave 2 |
|---|---|---|
| C0 core change | 1–1.5 | |
| IM1 Foundations | 1.5–2.5 | |
| IM2 Stock control (beyond Part 1's screens) | 1–2 | 1–1.5 |
| IM3 Item card and barcode sync | 1.5–2.5 | |
| IM4 Suppliers | 1.5–2 | 0.5 |
| IM5 Purchase orders | 2–3 | |
| IM6 Receive (+invoice) | 3–4.5 | |
| IM7 Supplier invoices | 2–2.5 | 1–1.5 |
| IM8 Valuation | 2.5–3.5 | 1–1.5 |
| IM9 Reorder | 3.5–4 | |
| IM10 Mode switch and site stock writer | 3–4 | |
| IM11 Trade / inter-site | 1.5–2 | 0.5 |
| IM12 Returns inspection | — | 1–1.5 |
| IM13 Duty records (incl. stop-gap) | 1.5–2.5 | 0.5–1 |
| IM14 Reports and accountant files | 2–2.5 | 1–1.5 |
| IM15 Switch-over tools | 2–3 | |
| Ops and security for I-Day | 2–3 | |
| Rehearsal, training, hypercare | 2–3 | |
| **Subtotal** | **33.5–48** | **6.5–9.5** |
| **With 20% contingency** | **≈40–58** | **≈8–11.5** |
| Part 1 remaining work (its own budget): count screens 3–4; T0 rebase 0.5–1; Vape and Go connector 2–3; Electrofag and Vape Big connectors ~4; mapping safety 2–3; Claude-in-CW (Phase 8) 1.5–2; proto rehearsal 1–2; shadow → live 2; F1-lite (unit prices in sale messages) 1–1.5 | **≈17–22.5** | |
| **Total to I-Day** | **≈57–80** | |
| Optional: shelves and labels | | 1.5–2.5 |

**Costs that are not engineering:** pen-test fee; production server and standby (monthly); Object Lock storage; tablets and scanners; Wi-Fi at the bench.

**Calendar, starting mid-October 2026:**

| | One engineer | Two engineers |
|---|---|---|
| Build, test and rehearsal (+10% for holidays and live support) | ~63–88 weeks | ~39–55 weeks |
| Likely I-Day (1st of a month, not Nov–Dec) | **~Feb–Jun 2028** | **~Aug–Oct 2027; target 1 Sep 2027** |

**The gates that set the date, whatever the coding pace:**
- the accountant's test month (I-5, about Feb–Apr 2027);
- the unstamped clearance and spring counts (Jan–Apr 2027, when staff are busy);
- staff mock-up sign-offs and replay practice;
- Vape Big access;
- at least 7 clean shadow days per site;
- after I-Day: the 4–6 week rollback window and 6–8 weeks of counting.

The CW core and matching were built 26 Sep–2 Oct, much faster than estimated. If that pace holds, coding is not the critical path, but these gates still place I-Day around summer 2027. The remaining work is integration with three older site codebases, where greenfield pace may not hold, so the engineer-week figures are kept as sizing.

**Staff time:**
- half to one day of training per role;
- replay practice (about 20 timed documents per person);
- 40–80 hours of catalogue work;
- the spring 2027 counts;
- Phase 7 counting: about 36–60 hours for 80% of units (plan §13);
- a full quarterly count of everything would be about 130 items a day, close to one full-time counter (decision 19).

**Compared with the approved plan:**
- About 20–28 of Part 2's 45–65 weeks (suppliers, POs, receiving, invoices, valuation) now come before I-Day.
- This plan adds pieces Part 2 never designed: the reorder engine, the mode switch, the site stock writer for legacy items, trade documents, the duty records, the switch-over tools, and the production standby at I-Day instead of at D.
- The remaining finance work (ledger, VAT, bank, payments, receivables, sales records) is about 25–37 weeks after I-Day.
- The whole programme becomes roughly **95–135 engineer-weeks** (approved: 70–90), ±30%.

---

## 10. Decisions for you (asked when each one is needed)

**A. Now (before Phase I-1)**
1. Go-live order: everything together (A) or Part 1 first (B).
2. Build pace: one engineer, two, or the measured pace with a re-plan after I-1.
3. People for each role, and you as backup approver.
4. ERPNext data: copies from a backup (two copies), capped live queries, or no copy.
5. Vape Big: give access now; the fallback if it is still missing by March 2027.
6. Count rule: warn first, or adjust automatically.
7. ERPNext bug fixes now, by the ERPNext admin.
8. Refuse all unstamped deliveries from 1 Jan 2027.

**B. Before Phase I-2**

9. Companies: which company buys and owns the stock; are the sister sites separate companies?
10. Shop units: issues, as today, or separate locations.
11. Approval rules and limits.
12. Should CW write its average cost into the site cost field?

**C. With your accountant (by about Feb 2027, before Phase I-5)**

13. Where the books are, who files VAT, and the real year end (29 Apr or 31 Mar).
14. Accounts bridge: A2, A1, B or C.
15. Opening cost method.
16. Cost method: moving average or FIFO.
17. Website sales into ERPNext after I-Day.

**D. Before the spring 2027 counts (by Feb 2027)**

18. One stocktake or two (depends on decision 13).
19. How often to count (HMRC guidance says quarterly).
20. Unstamped clearance: who signs off destruction, and whether shop units are included.
21. Does any company import?

**E. Before the proto rehearsal (P+)**

22. I-Day date.
23. Customer returns at I-Day.
24. Leave shelves, labels and batch/expiry out.
25. Keep supplier bank details out of CW.
26. Approve the live website changes for go-live.

---

## 11. Risks, and things found on the way

**Risks to the programme:**
- **The duty deadline comes before CW.** The 31 Mar 2027 clearance depends on the register, the counts and the stop-gap, and on staff following them.
- **Long life left for ERPNext.** ERPNext stays the goods-in system for about 10 more months, so its defects must be fixed in I-0, not left alone.
- **Key people.** 3–4 people do all purchasing, and one person did almost all of it until August. Approvals therefore review after posting, with you as backup.
- **Vape Big.** No access yet: no audit, no connector, and no protection of any item until it is live.
- **A mid-year I-Day.** If the year end is 29 Apr and I-Day is 1 Sep, purchases for that year are split between ERPNext and CW. The accountant combines them; the books are theirs anyway.
- **Data quality.** Supplier names only, missing invoice numbers, no pack sizes, many items with no cost.
- **Rollback.** It is now a supervised import, but it still needs the ERPNext admin.

**Found on the way.** These are outside this plan. Each would be fixed on proto or a copy first, and on live only with your approval.

1. **ERPNext:**
   - goods-in sent in boxes instead of units;
   - 20 invoices never pushed;
   - 1,881 failed sales never retried;
   - about 103k error-log rows;
   - every user holds every role;
   - guest item-import endpoints, one of which runs as Administrator and pulls from a vendor's development server;
   - API keys written into 5 code files.
2. **Website:**
   - the bulk editor can still write stock, mode and cost on Vape and Go;
   - the ledger edit/delete endpoints can rewrite stock history and pass ids straight into SQL. Retire them now;
   - five inventory reports run on every admin page load;
   - the Order Planner's "already ordered" figure is always 0;
   - dispatch `item_scanned` returned 5,960 server errors in 12 days.
3. **Security:** `Mobile_app_proto/.vscode/sftp.json` can be downloaded by anyone (owner step 3).
4. **The live audit query (process 45308):** killed on 2 Oct with your approval. Nothing to do now; the query-safety rules are in §8.

---

## 12. Changes to the approved plan (plan.md / decisions.md)

1. **Purchasing moves into CW at I-Day, before the ledger switch D.** There is no mirror into ERPNext, because the bridge is file exports (§5; A2 by default).
2. **Part 1 go-live moves (if decision 1 = A).** Phases 5a/5b come just before I-Day (about summer 2027), so cross-site oversell protection arrives about 4–6 months later than planned.
3. **The relay (§9)** is required from each site's T0 until I-Day, and switched off at I-Day. CW's receive screen serves sister-only items from Phase 5b, as §9 says.
4. **§6.5:** from I-Day CW also writes quantity, mode and low-stock threshold for **linked-legacy** listings, per site, with descriptive stock-log lines. The plan's "legacy stock writer" (a D gate, about 1 week, quantities only) becomes an I-Day requirement of about 3–4 weeks.
5. **§7.4 count gate:** unchanged. Every site selling from the warehouse must be live, so without Vape Big no item can be protected.
6. **§15.1 unstamped stock:** "by ~24 Mar move to UNSTAMPED" becomes "cleared with evidence by 31 Mar 2027; UNSTAMPED empty from 1 Apr". The "stamped?" tick is done on paper count sheets in March 2027.
7. **Duty-lot tracking** stays out. IM6 refuses unstamped duty-liable goods from I-Day.
8. **Opening cost** comes from recent purchase-invoice lines including freight and duty, not ERPNext Stock Balance rates.
9. **Opening quantities:**
   - known site-figure errors are corrected before T0;
   - sister-only items open from their own site figure (0 or more) instead of 0 (D40a).
10. **§15.4 parallel run and §15.3 supplier master:** after I-Day ERPNext holds no purchases, so the Part 2 baseline and bridge report use the accountant's books, and the supplier master is in CW from I-Day.
11. **§16 Q10:** the standby database is required at I-Day, not D. The pen test also comes before I-Day.
12. **Roles and approvals:**
    - `staff_user.role` (one per person, M2) becomes several roles per person, plus the purchasing and finance roles;
    - approvals review after posting, except new suppliers and positive adjustments without a document.
13. **The frozen core** changes once (migration 0006, at the start of I-1): cost and document link on goods-in, plus a value sequence. The stress tests are re-run.
14. **New types:** `trade_sale` (replacing `erp_sale` after I-Day) and reason codes. Transfer documents come in Wave 2.
15. **Dispatch "cancel & restock remaining"** goes to VERIFY until §16 Q5 is answered.
16. **Barcode sync** from site listings to `sku_barcode` is new work (today `PUT /v1/listings` writes only `listing_profile`).
17. **F1-lite** (unit price in sale messages) moves into the Part 1 connector work.
18. **RETURNS inspection** stays "later" (Wave 2), as in plan Phase 10.
