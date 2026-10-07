# Central Warehouse: handoff (7 Oct 2026)

This page has two jobs:

- It tells **the owner** where the Central Warehouse project stands and what to do next. Sections 1 to 6 are for you.
- It lets **the next engineer, or the next Claude session,** carry on without the chat history. Sections 7 to 9 are for them.

**How the numbers were checked.** Every staging figure comes from read-only checks on 7 Oct 2026 between 04:23 and 04:26 UTC,
re-checked at about 05:10 UTC (nothing had changed). Repo and proto figures come from git on the same morning. Anything done
after that is not reflected here, so count again before you act. Where the session notes and the servers disagreed, this page
follows the servers and says so (section 3, "Corrections to the earlier notes"). Anything that could not be checked against a
server or a file is marked **(unverified)**.

No passwords, keys or authenticator codes appear on this page, and none should ever be added to it.

---

## 1. In one page

### What the Central Warehouse is, and why

Vape and Go, Electrofag and Vape Big all sell from **one physical warehouse**, but each site keeps its own stock number, so
nothing stops two sites from selling the last unit. Only Vape and Go is linked to ERPNext, and that link has known bugs (for
example, deliveries sent in boxes instead of units, and invoices marked "Synced" but never sent).

The **Central Warehouse (CW)** is a separate system, with its own server and database, that holds the one true stock figure:

- Each site keeps its own database, prices, pictures and admin. It asks CW over the internet before it sells.
- If CW cannot be reached, the sites keep selling and the order is flagged. A sale is never lost because CW is down.
- Every site product page (a "listing") is linked to one CW product record (an "item", code like `CW-000123`). The AI suggests
  the links, and a person confirms them.
- Stock is counted bit by bit. Once an item has been counted it is "protected", and CW can then stop an oversell.
- Later, CW takes over everything ERPNext does: stock and purchasing first (by "I-Day", target **1 Sep 2027**), then the
  accounts (switch-over day "D", about **30 Apr 2028**). ERPNext is then detached and kept only as archived exports.

### Where we are today (7 Oct 2026)

- **CW runs on a test server ("staging")**, https://warehouse-staging.floverfy.com. Sign-in: a password plus an app code.
- **Vape and Go's catalogue is loaded:** 14,856 CW items, one for each Vape and Go product variant on sale, each linked to its
  page. The other 14,249 variants (binned, discontinued or placeholders) are not linked.
- **Starting stock:** Vape and Go's "duty-day" figure (00:00 BST, 1 Oct 2026): 296,599 units on 8,199 items. An estimate, not a
  count. The duty record says 215,981 because it is a net total (items below zero subtract); CW counts below-zero items as 0
  and leaves out 26,191 units on unlinked pages.
- **Electrofag's 2,623 selling products have suggested matches.** Nobody has confirmed any of them yet.
- **Screens are built** for linking, the spot-check, duplicates, company details, suppliers, POs, reordering and sales history.
- **The Vape and Go connector** (the code that lets the shop talk to CW) **is built on the proto copy only, and is switched
  off.** One test with 3 proto orders reached CW on 2 Oct; the link was then switched back off.
- **Nothing from CW runs on the live shops**, and all three sites are "off" in CW. Section 3 lists the live changes made for
  or alongside CW, and the other (non-CW) live work of the same period.
- **This server can now log in to the Electrofag and Vape Big servers** (since 7 Oct, tested 05:00 UTC with the key
  `/root/.ssh/cw_sister_sites`; both host fingerprints checked by you).
- **The Electrofag and Vape Big connectors are being built** on their proto sites (started 7 Oct 04:54 UTC, at your request).
  Discovery is done; the build is running. See "Later on 7 Oct" in section 3.
- **Your staging account holds admin as well as reviewer and mapping lead.** You chose to keep admin (7 Oct). While admin
  is held, the system switches your reviewer and mapping-lead permissions off, so the spot check, company details and
  duplicates cannot be done from this account. Option: a second login with only reviewer and mapping lead.
- **Tests:** 796 automated tests pass (7 Oct), plus the screen tests, the stress test and the matching tests.

### The 5 things waiting on you

| # | What | Where | Why it matters |
|---|---|---|---|
| 1 | **Finish the 20-item Key spot-check** (0 of 20 done). "Key" = the matches where the barcode and the AI agree | Linking > Key spot-check > owner-1 (section 6.2) | If all 20 are right, about 1,097 more Electrofag matches are confirmed in one go. Otherwise each one is checked by hand. |
| 2 | **Enter and confirm the company details** (empty today) | Reference > Company details (section 6.4) | Until you confirm them, every purchase order PDF says "DO NOT SEND". |
| 3 | **Decide the Vape and Go duplicate groups** (156 open, 0 decided) | Linking > Duplicates (section 6.5) | Two pages of the same product must share one stock figure. Most old suggestions are really different products. |
| 4 | **Change the leaked sister-site secrets:** revoke the GitHub token that was in the Electrofag servers' git settings, and change the Electrofag and Vape Big database and payment passwords | GitHub, DigitalOcean, the payment accounts | Their `.git` folders could be downloaded until 7 Oct. The proto connector build started anyway at your request (it touches proto only); changing these is still urgent. |
| 5 | **Say "go" for Phase I-3 Receiving**, and arrange the typists' half-day (checklist #48) | Reply in chat | It is designed and ready to start (below). |

Everything else waiting on you is in section 2, "Waiting on you".

### The next build: Phase I-3 Receiving (waiting for your go)

1. **The item card:** buying details, barcodes and pack sizes for each item.
2. **Receiving a delivery** against a purchase order, with the duty-stamp check (your decision 8: unstamped stock made or
   imported from 1 Oct 2026 is refused now; every unstamped delivery is refused from 1 Jan 2027).
3. **The site stock writer on Vape and Go proto:** a delivery booked in CW puts the stock on the proto shop, and back-in-stock
   emails go out.

About 6 to 9 engineer-weeks, on staging and the Vape and Go proto copy only. The two people who type invoices and POs approve
the delivery screen's mock-ups before it is built (#48). Your test at the end needs real suppliers in CW (from the ERPNext
backup copy, or the 47 typed in by hand, about 1 to 2 hours) and the tablets and scanners (#49): book 5 real deliveries from
last week's paperwork (including a "box of 5"), and see an out-of-stock item come back on sale on proto with its email.

---

## 2. Status board

### Done

| Item | One line |
|---|---|
| The plan | Approved 26 Sep 2026. The inventory plan followed on 2 Oct, and you agreed all 8 "now" decisions the same day. |
| Staging server and database | Live since 26 Sep. Public HTTPS address since 1 Oct; the certificate is valid to 30 Dec 2026 and renews itself. |
| Stock engine and API (Phase 1 core) | The heart of CW: it keeps each item's stock, holds units while a customer pays, tells each shop when a figure changes, lets only our own shops talk to it, and checks its sums every night. Stress test: 200 customers trying to buy the last 10 units at the same moment: exactly 10 got them, and nothing jammed. |
| Staff screens and sign-in | Password plus authenticator code. The root address sends you to the sign-in page. |
| Vape and Go catalogue (Phase 2a) | 14,856 items created, one for each Vape and Go variant on sale, each linked to its page. |
| Electrofag first match (Phase 2b, the AI part) | The AI judged Electrofag's 2,623 selling products: 0 wrong in the 294 test pairs whose answer we knew. On 3 Oct, 435 moved up from Check to Key under your 85% rule (936 + 435 = 1,371 Key). A second, stricter screen then held 254 of the Key matches back, to be checked by hand. |
| Starting stock estimate | Vape and Go's duty-day figure loaded: 8,199 items, 296,599 units. The nightly checks pass. |
| Phase I-1 Foundations | The groundwork: what stock is worth, one person can hold several jobs, numbered paperwork (orders, deliveries), and a safe store for files. Deployed 2 Oct. |
| Phase I-2 Purchasing | Suppliers (activated by a second person), purchase orders (approval above £10,000), reorder list with a "Why", sales history. Deployed 2 Oct. |
| Sales history and demand | 12 months of Vape and Go sales (602,739 rows) and Electrofag sales (11,481 rows, which start on 8 May 2026), up to 1 Oct, loaded on 3 Oct. Demand worked out for 11,790 items. This was a one-off load: no daily refresh is set up yet. |
| Company details screen | Built 2 Oct, as you asked. Only the reviewer role may edit or confirm. |
| Key spot-check and hold-back | Sample "owner-1" drawn 3 Oct. 254 doubtful matches held back from any bulk confirm on 6 Oct. |
| Duplicates | Screen, one-person merge for uncounted items, undo ("split") and a wider search for duplicates. Deployed 7 Oct; 11 new groups added. |
| Vape and Go connector on proto (Phase 3 code) | Built in App_proto and Website_proto, ships switched off. One listen-only test with 3 proto orders on 2 Oct. |
| Connector follow-ups | Extra safety for the shop link: the shop always knows whether CW is off, listening or live; it notices if CW was restored from a backup; an order cancelled and then paid again gets its stock back; and a tool resets the starting stock at the moment a shop connects (T0). |
| Live, with your approval | 2 Oct: the live PayPal and Viva payment checkers now run from the live folder, not proto. 2 Oct: a stuck ERPNext query was stopped. |
| Live snapshot fix | The nightly stock snapshot now runs at 23:55 **UK** time and keeps month-ends for 7 years (live since 2 Oct). **Please confirm you approved this** (section 4). |
| Server access to Electrofag and Vape Big | 7 Oct: this server's key `/root/.ssh/cw_sister_sites` (made 04:27 UTC) logs in to both as root. The same day both servers stopped serving `.git` folders, on your "go block" (not CW work; from the memory notes; unverified: access not re-tested). |
| Duty records | The duty-start stock record and the goods-in register v2 were delivered. You handle duty records from here. |
| ERPNext fix brief | Brief v2 for the ERPNext administrator, written 2 Oct at 09:27 UTC, before the VPG 2 push (section 4). |

### On staging, waiting for your test

| Screen | State today |
|---|---|
| Linking > Key spot-check | 0 of 20 decided |
| Reference > Company details | Version 1, empty, not confirmed |
| Reference > Settings | 20 settings; 19 are marked "provisional" (a guess you have not confirmed) |
| Linking > Duplicates | 156 groups open, 0 decided |
| Purchasing > Suppliers, Purchase orders, Reorder list, Sales history | 0 suppliers, 0 purchase orders: nothing has been tried yet |
| The held-back list | 254 listings on the sample page, plus your spreadsheet |
| People and roles (for the admin) | Fazil Nazer is the admin. Your account holds reviewer, mapping lead **and admin** (admin is back on it since 2 Oct; on 7 Oct you chose to keep it). Admin switches your reviewer and mapping-lead permissions off, so use a second login for those tests. |

### In progress

| Item | One line |
|---|---|
| Electrofag matching | 2,623 suggested, 0 confirmed. The Key bulk waits for the 20-item spot-check. What is left after it: the table below. |
| Vape and Go duplicates | 156 groups (176 suggestions), 0 decided. |
| Mapping safety (plan Phase 6) | Partly done: the duplicate sweep, merge and split (6 and 7 Oct) and the hold-back. The count gate is not built yet. Quarantine and correction movements were not checked for this page (unverified). |
| Naming the team | The admin is named. Still needed: a second reviewer, at least 2 mapping leads, buyers, goods-in, invoice desk, a stock controller, counters. How an account is made: section 6.9. |

**What is left of Electrofag's matching after the bulk confirm** (staging, 7 Oct):

| Pile | Listings | Who decides |
|---|---|---|
| The spot-check sample | 20 | You |
| Key, linked by the bulk confirm (after 20 of 20) | about 1,097 | One run, recorded as your decisions, undoable |
| Key, held back | 254 | A mapping lead, one at a time |
| Check | 593 | A mapping lead, one at a time |
| Can't tell | 420 | A mapping lead, one at a time |
| Conflict (the evidence disagrees) | 73 | A mapping lead, one at a time |
| New item (no match found) | 119 | A mapping lead, one at a time |
| Manual (relabels) | 47 | You (checklist #10, below) |
| Electrofag's unsold products, not matched at all yet | 6,391 | Claude suggests first (#12), then a person |

The plan puts the catalogue review at about 40 to 60 staff hours once (plan §13). Some links need a second mapping lead (units
per item other than 1, merges of counted items), so those wait until a second one is named.

### Waiting on you

| # | Item | Recommendation |
|---|---|---|
| a | The 20-item spot-check, company details, duplicates, the leaked secrets, go for I-3 | The top 5 above |
| b | An old, unused access code for the Vape and Go link is still in a settings file on the test server. Remove it (one command, section 6.7) | Yes. Claude's edit was blocked by the safety system. |
| c | When 20 of 20 are confirmed, approve Claude's request to run `bin/bulk_confirm_key.php`, or run the command it gives you | Yes. The safety system blocked even its dry run on 6 Oct. |
| d | Mark the staging server as "staging", so the 3 proto test orders can be removed (section 6.7) | Yes |
| e | A 14–22 Sep "pre-duty" window is already left out of the reorder figures (provisional). Extend it to 30 Sep, or add 23–30 Sep. It is added on Purchasing > Reorder > Anomaly windows by someone with the buyer role (nobody has it yet), or by the engineer with your OK | Yes (section 4) |
| f | Keep the 15-minute cancel grace | Yes, for now (section 4) |
| g | Open **Reference > Settings**: it lists every value we guessed, marked "provisional", with its decision number. Also provisional: one company owns all stock (decision 9), £10,000 PO approval limit and 7-day review (11), no cost write-back (12), no supplier bank details (25) | Confirm each one, or tell us what to change |
| h | Live fixes on the list: remove the old ERPNext IP from one live file; the background programs that send Klaviyo, Lipscore and SMS messages for the live shop still run from the test copy's folder (#51); one old web address shows every folder on this server, including the test copies (#52); one live image tool reads from the test database (#53); two shop admin bugs (#43); two leftover comment lines in the live crontab | Yes, one at a time, proto first (section 4) |
| i | Staff names. The ERPNext admin applies brief v2 and sends exports X1 (deliveries ERPNext sent in boxes instead of units) and X2 (every stock invoice, with what it should have sent) | Needed for I-0 and for the real supplier list |
| j | Copy the latest CW code to GitHub as a backup: 8 saved changes exist only on this server (GitHub has 0e53d35, checked 7 Oct) | Yes |
| k | #10: map the 4 relabel families (for example, is Electrofag "Crystal Pro Max" the same stock as Vape and Go "Hayati Pro Max"?). 47 suggestions wait in the Manual pile | Before the Electrofag connector |
| l | Also from the 4 Oct proto finding: the live Vape and Go database password was in App_proto's git history, so treat it as leaked until it is changed | Yes, with item 4 of the top 5 |
| m | The open questions in section 4: VPG 2, the re-plan, the dispatch "cancel and restock" button, the T0 reset, the security gates | Answer when you can; each row says when it is needed |
| n | Dated items on your checklist: #4 free-gift promotions (due 28 Oct 2026), #46 write-once storage (31 Oct 2026), #47 ERPNext admin (30 Nov 2026), #49 tablets and scanners (31 Jan 2027); #48 the typists' half-day has no date but comes before I-3's delivery screen | Your call |

### Blocked

| Item | Blocked by |
|---|---|
| Vape Big matching (first match) | Its database is in another DigitalOcean account: run the export on its own server (now possible over SSH) |
| Real supplier list and open purchase orders in CW | Needs a restored ERPNext backup copy on a separate server (the ERPNext admin, I-0). Whoever restores it must follow brief v2 "Step 0" first (pause the scheduler, block the live site), or the copy will write to the live shop |
| ERPNext stock-sync fixes | Waiting for the ERPNext admin to apply brief v2. The brief does not yet cover the VPG 2 push (section 4) |
| Key bulk confirm | Waiting for 20/20 and permission to run the tool |
| Removing the proto test orders on staging | Waiting for the staging marker (item d) |
| A real go-live-moment (T0) test against staging | The proto connector refuses it on purpose until you give the go |

### Not started yet (in the plan)

- **Phase I-0 "Get ready":** your steps are partly done; the ERPNext part (the seed copy, the checks, the stop-gap report due
  in January) waits for the backup copy.
- Phase I-3 (proposed), I-4 to I-7; F1-lite (unit prices in sale messages, part of the connector work); the security gates of
  plan §11 (section 4); the Phase 0 leftovers (webhook alert, Anthropic workspace).
- The rehearsals (P and P+), live shadow and live (5a and 5b), counting (Phase 7), Claude inside CW (Phase 8), the production CW
  server, and the finance half (Part 2).
- The re-plan the inventory plan promised after I-1 has not been done (section 4).

---

## 3. What changed, by date (26 Sep to 7 Oct 2026)

Where: **CW staging** = the test server 46.101.55.135 and its database. **CW repo** = the code at
`/root/central-warehouse`. **Proto** = the proto copies App_proto and Website_proto on this server, and the proto database.
**Live** = the live shops, their database, crons or background programs.

### 26 Sep 2026

| Change | Where | Touched live? |
|---|---|---|
| Plan approved, with your decisions of 26 Sep (section 4) | Plan file | No |
| CW repo created (0a9fc06): the approved plan and a read-only catalogue export tool | CW repo | No |
| Read-only catalogue exports: Vape and Go (29,105 variants) and Electrofag (9,014) | Files in `/root/cw_work/first_match/` | Read only |
| Matching engine v1 (4203b6a): the blind pilot agreed on 60 of 60 barcode pairs, with 0 of 30 false merges | CW repo | No |
| CW core (a137d73): stock engine, holds, change feed, API with site keys, nightly jobs. 195 tests; stress test 50 of 50 | CW repo | No |
| Staging server and database set up; migrations 0001 to 0003 applied; nightly jobs installed | CW staging | No |
| Duty goods-in register v1 | Work files | No |

### 30 Sep 2026

| Change | Where | Touched live? |
|---|---|---|
| Matching engine v2 (124b543): 0 wrong matches and 0 false merges in pilot 2 | CW repo | No |
| Run3 (d4df0fc): the AI judged Electrofag's 2,623 selling products. 936 Key, 0 wrong matches, all 294 test pairs safe | Work files, CW repo | No |
| Review and linking (8e83563): the only service allowed to change links, the staff sign-in, review screens, import tools | CW repo | No |
| Data loaded: the 14,856 Vape and Go items, Electrofag's 2,623 proposals and 165 Vape and Go duplicate suggestions. Migrations 0004 and 0005 | CW staging | No |

### 1 Oct 2026

| Change | Where | Touched live? |
|---|---|---|
| Public HTTPS address switched on (1ddfb74); certificate to 30 Dec 2026 | CW staging | No |
| Sign-in fix (eae2f6f): Chrome refused every real sign-in before it | CW repo, CW staging | No |
| Duty-start stock record built from the 30 Sep snapshot: 215,981 units net at 00:00 BST (322,790 counting only items at or above zero) | Work files (`/root/cw_work/duty/`) | Read only |

### 2 Oct 2026

| Change | Where | Touched live? |
|---|---|---|
| **Payment checkers moved:** the live PayPal and Viva timeout watchers now run from App, not App_proto (09:23 UTC, with your approval; backup `/root/supervisor-backup-20261002T092341Z-watchers-to-App`) | Live supervisor | **Yes, approved** |
| **Stuck ERPNext audit query stopped** (about 11 minutes, with your OK) | ERPNext database | **Yes, approved** |
| ERPNext fix brief v2 written for the ERPNext admin (09:27 UTC) | Work files | No |
| Duty goods-in register v2 delivered | Work files | No |
| Starting stock estimate loaded (27e979e): 8,199 items, 296,599 units. Only linked items are booked, below-zero figures as 0; 897 unlinked rows (26,191 units, mostly deleted or draft pages) are left out | CW staging | No |
| Inventory modules plan (b748334) and your "agree all" to the 8 "now" decisions (b390a40) | CW repo | No |
| Root address sends you to the sign-in page (5f361f5) | CW staging | No |
| Admin account created for Fazil Nazer (11:18 UTC). Your account became reviewer and mapping lead, with admin removed (12:03 UTC) | CW staging | No |
| **Phase I-1** (0e53d35) deployed 12:03 UTC: migrations 0006 to 0008. The document file store `/srv/cw-docs` was created | CW staging | No |
| **Not CW work, done by ERPNext (about 12:00 UTC):** a new ERPNext company "VPG 2" got 1.4 million units of opening stock, and ERPNext's stock sync pushed it onto 878 live Vape and Go variants, because that sync has no company or warehouse filter. Found and reverted on 5 Oct (below) | Live database (written by ERPNext) | **Yes, not by us** (unverified: from the memory notes) |
| **Vape and Go connector built on proto,** switched off: App_proto c52a7e62 and Website_proto b07f3349c (13:22 UTC). Its tables were installed in the proto database | Proto | No |
| Listen-only test: 3 proto orders (proto1-1285548, -49, -50) reached CW staging (13:17 UTC). The link was set back to off at 13:18 | Proto, CW staging | No |
| Connector follow-ups on proto: App_proto b6dfc40a (18:05 UTC); its database step applied at 15:24 | Proto | No |
| 12-month sales exports taken read-only from both sites (15:56 to 15:57 UTC) | Work files | Read only |
| Built and merged on CW: Key spot-check and bulk confirm (7555592, f380816), Phase I-2 purchasing (89ccfd9), connector follow-ups (dfbc571, 4d78794), company details (406d025). Deployed about 20:25 UTC: migrations 0009 to 0013 | CW repo, CW staging | No |
| **Nightly snapshot fix made live** (22:46 to 22:47 UTC): live crontab line 70 changed, then `App/tools/stock_snapshot_build.php` replaced with the proto version (App_proto ec58821e). The proto rehearsal cron line was removed at about 22:59 UTC, but its two comment lines are still in the crontab (lines 92 and 93). It has run correctly every night since | Live crontab and one live file | **Yes. Approval not recorded in the notes: please confirm** |

### 3 Oct 2026

| Change | Where | Touched live? |
|---|---|---|
| Sales history loaded (batches 1 and 2); demand worked out for 11,790 items (10:57 UTC) | CW staging | No |
| Re-band under your 85% rule: 435 matches moved from Check to Key (10:58 UTC) | CW staging | No |
| Spot-check sample "owner-1" drawn: 20 matches out of 1,371 (10:59 UTC) | CW staging | No |
| Rule check and AI check of the other 1,351 Key matches started, to find doubtful ones (finished 6 Oct) | Work files (`/root/cw_work/key_screen/`) | No |
| Work paused at your request, to save tokens. A half-built version of the hold feature was put aside in a git stash (12:21 UTC) | CW repo | No |
| Not CW work: a check request (HEAD) to the live shop script `Website/update_review.php` (13:50 UTC) ran its two review-average updates on the live database by accident. They almost certainly changed nothing (the admin recalculates the same figure), but that cannot be proven. You were told the same day | Live | **Yes, by accident** (unverified: from the memory notes) |

### 4 Oct and 5 Oct 2026

No CW work. Live events from other (non-CW) work, recorded only in the memory notes and not re-checked for this page
(unverified):

- 5 Oct, with your "apply": the "VPG 2" push of 2 Oct was reverted on 876 live variants, and one invoice that a feed read had
  swallowed (ACC-PINV-2026-02157, 200 units) was re-queued and applied. Still open: ERPNext's own item modes still say
  In-Stock for 318 of those items (any ERPNext message would flip them back), and the sync's missing warehouse filter.
- 5 Oct, your decision: VPG 2 is your separate company and warehouse, and nothing from it should ever sync to the website.

### 6 Oct 2026

| Change | Where | Touched live? |
|---|---|---|
| Hold feature (5ca669b) deployed 20:02 UTC: migration 0014 | CW repo, CW staging | No |
| 254 doubtful Key matches held back from any bulk confirm (20:03 UTC). Your sheet: `Held_back_matches_owner-1.xlsx` | CW staging, work files | No |
| Your decision: let CW handle Vape and Go's duplicate pages | Notes | No |
| Read-only export of the Vape and Go listings on staging, for the duplicate search (22:57 UTC) | Work files | No |

### 7 Oct 2026

| Change | Where | Touched live? |
|---|---|---|
| Duplicates (ff0e204) deployed 01:26 UTC: migration 0015, the Duplicates screen, merge and split | CW repo, CW staging | No |
| Wider duplicate search (rules "ds1.1") imported 11 new groups (01:26 UTC), so 156 groups are open | CW staging | No |
| Not CW work: a key for this server (`/root/.ssh/cw_sister_sites`, 04:27 UTC) and root access to the Electrofag and Vape Big servers; both servers blocked `.git` downloads server-wide on your "go block" | Electrofag and Vape Big servers (one hosts the live Electrofag admin) | **Yes, with your go** (unverified: from the memory notes) |
| `git fetch` in App_proto (04:50 UTC): 2 new commits from other developers are now upstream (section 7.1) | Proto (git only) | No |
| Read-only checks for this handoff, and this page | CW repo (this file only) | No |

### Later on 7 Oct 2026

| Change | Where | Touched live? |
|---|---|---|
| **Login to the sister servers works.** You added this server's key (`cw_sister_sites`, restricted to this server's address) from your Mac; you verified both host fingerprints (Electrofag `SHA256:Zm66r6…`, Vape Big `SHA256:m1ZtYM…`), now pinned in this server's known_hosts | Electrofag and Vape Big servers | Key added by you |
| **`.git` exposure closed on both servers ("go block").** The proto admin and shop on both servers, and the **live Electrofag admin** (www.alt.floverfy.com), served their git folders: source code, `global_config.php` / `db-conn.php` (database settings), payment settings, and on Electrofag a GitHub token in the git address. The Electrofag logs showed 8,489 `.git` requests. Fix: `/etc/apache2/conf-available/zz-block-git.conf` (server-wide, all sites), config test, reload. All 24 checked `.git` addresses now answer 404 (403 at Cloudflare for www.alectrofag.co.uk) and every site still loads. **Never remove that file.** | Electrofag and Vape Big servers (Apache) | **Yes, with your go** |
| **Electrofag and Vape Big connectors started** (workflow wf_8aea3eb8-ab2, 04:54 UTC): discovery finished read-only. Both proto shops use their proto databases (`alectrofag_proto`, `vapebig_proto`), which share a server and login with live, so every write checks the database name. Electrofag's admin half was already present from GitHub and matches the reference; the shop half needs adapting (ledger-based stock, a Payroc gateway). Vape Big gets the full drop-in. The build backs up every file to `/root/cw_backup_20261007/` on each server and never commits, pulls or resets there | Proto only | No |

**Found and not yet fixed (need your decision):**
- **The Electrofag proto shop takes real money through Viva:** on alt-store.floverfy.com Viva is set to LIVE with the real Viva servers. Our tests never use real checkouts. Switch Viva to sandbox there, or warn anyone who tests on it.
- **Vape Big's admin (proto and live vapebig.floverfy.com) has no sign-in check on its background (AJAX) requests,** the gap closed on Vape and Go on 30 Sep. Fixing it is a live change and needs your go.
- **Vape Big's proto sites show folder listings** (Apache `AllowOverride None` plus autoindex), so `.htaccess` rules are ignored there.
- **The connectors must allow each server's real outgoing address in CW,** not its SSH address: Electrofag 161.35.40.166, Vape Big 178.62.70.230 (159.223.245.136 and 159.65.209.7 are reserved IPs for incoming traffic only).
- **Change the leaked secrets** (the top 5 list, item 4): the GitHub token, and the Electrofag and Vape Big database and payment passwords.

### Other live changes in the same period (not CW work)

From the memory notes only, not re-checked for this page, and maybe not complete (unverified). The storefront work has its own
list in memory note `fix-tracker-oct3`:

- 30 Sep: the sign-in gate on the admin's AJAX pages (proto commit 60eb71bd), live the same day.
- 2 Oct: the broken-link clean-up on the shop (11 files, including the Cloudflare e-mail obfuscation fix; backup
  `/var/backups/vpg-broken-links-20261002-121326`) and the social-share tags fix, both live the same day.
- 2 Oct: the VPG 2 push (by ERPNext, above). 3 Oct: the Netcore tracking fix on /guides/ and /product-warranty/, and the
  accidental update_review.php run (above).
- 4 Oct: the proto `.git` folders were blocked from the web (a proto change, but it protects live secrets: item l in section 2).
- 5 Oct: the VPG 2 revert and the re-queued invoice (above). 7 Oct: the `.git` block on the two sister servers (above).

### Corrections to the earlier notes

The session notes said four things that the servers contradict. This page follows the servers:

1. **The snapshot fix is live** (since 2 Oct, 22:47 UTC). The notes said it would "wait for go-live". The live file is
   byte-identical to proto's, and the live log shows UK-time runs every night since.
2. **The proto commits were pushed.** The notes said "not pushed". The connector commits and ec58821e reached the proto GitHub
   remotes on 2 Oct, together with other people's pushes from this server.
3. **`App_proto/tools/stock_snapshot_build.php` is committed** (ec58821e), not "uncommitted on purpose".
4. **HTTPS and the sign-in fix were 1 Oct, not 2 Oct** (git 1ddfb74 at 05:20 and eae2f6f at 06:22 UTC on 1 Oct; the
   certificate starts on 1 Oct at 04:21 UTC).

---

## 4. Decisions

### Decisions you made

| Date | Decision | Recorded in |
|---|---|---|
| 26 Sep | CW is a standalone system with its own server and database | Plan |
| 26 Sep | If CW is down, the sites keep selling and the order is flagged | Plan §5 |
| 26 Sep | Counting is progressive: an item becomes protected once counted | Plan §8 |
| 26 Sep | A simple central product record (`CW-000123`) is linked to each site listing | Plan §2.1 |
| 26 Sep | Matching is AI-assisted and a person confirms it; Claude does the first run | Plan §7 |
| 26 Sep | Every ERPNext function is rebuilt in CW, then ERPNext is detached. Only VAT filing goes out, through HMRC-recognised bridging software | Plan §15 |
| 26 Sep | Proto first: nothing changes on live until it is built, tested and signed off by you in writing | Plan, memory note |
| 2 Oct | The 8 "now" decisions, "agree all": (1) everything goes live together, so the protection against two sites selling the last unit starts around summer 2027, 4 to 6 months later than in the first plan; (2) two engineers, target 1 Sep 2027, re-plan after I-1 and I-3; (3) name the staff now, at least 2 reviewers, you as backup; (4) ERPNext data only from backup copies restored on a separate server; (5) Vape Big access now, with a trade-customer fallback in Mar 2027; (6) the count screen warns first; (7) the ERPNext admin fixes the 2 sync bugs on a copy first; (8) refuse unstamped stock made or imported from 1 Oct 2026 now, and every unstamped delivery from 1 Jan 2027 | `docs/inventory-modules-plan.md` §10 |
| 2 Oct | Staging's starting stock is the duty-day stock (00:00 BST, 1 Oct 2026) | `docs/decisions.md` D40a |
| 2 Oct | Standing go: build the CW phases back to back on staging and proto. Ask before any live change or real business decision | Memory note |
| 2 Oct | In every bulk action, skip anything alarming, finish the rest, and show the skipped list at the end | Memory note |
| 2 Oct | You handle duty records, invoices, the accountant and off-system stock yourself | Memory note |
| 2 Oct | Key matches: you spot-check 20; if all 20 are right, the rest are confirmed in bulk. 85–89% confidence plus an agreeing barcode counts as Key | `docs/decisions.md` M26–M28 |
| 2 Oct | Approved: move the PayPal and Viva watchers to App; stop the stuck ERPNext query; create Fazil's admin account | Notes |
| 2 Oct | A screen for the company details that only the reviewer can edit | `docs/decisions.md` I90–I99 |
| 3 Oct | Pause the work to save tokens. Since then each new phase waits for your go (section 7.4) | Notes |
| 5 Oct | Proto keeps sending real e-mail, as now (no test-only address list) | Memory note `proto-email-stays-live` |
| 5 Oct | VPG 2 is your separate company and warehouse; nothing from it should ever sync to the website | Memory note `erp-vpg2-opening-stock-push` |
| 6 Oct | "Let the warehouse system handle it": duplicate Vape and Go pages are linked to ONE CW item, and nothing changes on the shop. One person merges uncounted items; two people once an item is counted or has a pack listing | `docs/decisions.md` M31–M34 |

### Decisions still open, with my recommendation

| Decision | My recommendation |
|---|---|
| **Was the live snapshot fix of 2 Oct approved?** It is live and working. It was made from another session (da179d50, the storefront work), whose transcript should show whether you approved it (unverified) | Confirm it, so the record is straight. Then, with your OK: Electrofag has the script and tables but no cron, so add the cron line there; Vape Big has no snapshot tool at all (1 Oct check), so deploy the tool and its tables first, then the cron line (checklist #50). On each site, change the crontab line first, then copy the new `stock_snapshot_build.php` (ec58821e), never the other way round |
| **Anomaly window 23–30 Sep** for the reorder list (Elux Nic Salt sold 35,453 units on 30 Sep, against about 7,000 on a normal day). A 14–22 Sep window is already in place | Yes: extend the existing window to 30 Sep, or add 23–30 Sep. It stops the pre-duty rush from inflating the reorder figures |
| **Cancel grace** (how long a cancelled order keeps its stock before CW puts it back on sale): 15 minutes today | Keep 15 minutes for now. Look at real numbers during the listen-only phase; 2 to 6 hours would cover most re-completions seen on proto. (Engineer: it is a constant in the proto connector, `CW_US_ORDER_CANCEL_GRACE_SEC = 900` in `unit_sweep.php`, not a setting) |
| **Merging protected items** (two people) | Decide later, before counting starts. Today a protected item cannot be merged at all |
| **The item card (IM3, built on the test copies, not yet on staging):** who fills in and confirms the legal fields and decides the barcode review (built as provisional: mapping lead, stock controller, purchasing manager; never admin); when the barcode sync runs nightly (no cron yet) | Confirm the three roles; run the sync by hand first (`docs/ops.md`, "Item cards and barcodes"), then nightly. Rules as built: a card that breaks a legal limit (refill over 10 ml, tank or pod over 2 ml, over 20 mg/ml, single-use) only warns until a person confirms it; once confirmed, the item is blocked until someone confirms a corrected card: never suggested for reorder, and a purchase order with it cannot be approved. CW does not stop receiving or website sales yet (IM6, IM10): take a blocked item off sale on the website by hand (Items › Item cards › Blocked). Also open: items with no card count as duty-liable for receiving from 1 Jan 2027, hardware included (`docs/decisions.md` I103, I113, I119, I121) |
| **Purchasing defaults, built as provisional** (inventory plan decisions 9 to 12 and 25): one company buys and owns all stock; no supplier bank details in CW; CW does not write its cost into the sites; a second person reviews every PO within 7 days; POs over £10,000 net wait for approval. Also the reorder defaults: lead time 2 days, review 7, safety 5, demand windows of 28 and 91 days | Confirm them on Reference > Settings, or tell us the changes. All can be changed later |
| **VPG 2.** It is a separate company and warehouse (your 5 Oct decision). Does that change decision 9, "one company buys and owns all stock"? | Tell us. Brief v2 must also gain a fix: ERPNext's stock sync sends only the website company's warehouse ("Stores - VPG"), never VPG 2 (the purchase-invoice push needs the same check) |
| **ERPNext re-send.** Your decision 7 and checklist #47 say "re-send 9,571 + 336 units". The newer brief v2 says never re-send from ERPNext: a re-send would also switch products' In-Stock / Out-of-Stock setting back to what it was weeks ago | Follow brief v2: the ERPNext admin fixes the bugs and sends exports X1 and X2; Claude then works out the missing stock and adds it on the website side, after your OK on the final list (brief v2 Part 2) |
| **Live: remove the old ERPNext IP 178.128.171.123** from `App/api/update_purchase_invoice.php` (still there on 7 Oct) | Yes, proto first, then live with your OK |
| **Live: move the Klaviyo, Lipscore and SMS watchers** from App_proto to App (checklist #51). The live e-mail watcher already runs from App | Yes, the same safe way as PayPal and Viva |
| **Live: an old web address (ecom.tabsyst.com) shows every folder on this server**, including the test copies (checklist #52) | Narrow it to what it needs, after a careful look at what depends on it |
| **Live: a live image tool (`API/files/webp_converter.php`) reads from the old test database** instead of the live one (checklist #53) | Check it and fix it |
| **Security gates before any site goes live** (plan §11, checklist #32). Done by other work: the admin AJAX sign-in gate (30 Sep). Checked: `sftp.json` was not exposed (2 Oct). Still open as far as the notes show (unverified): the goods-in door's token and IP trust, `get_status`, the ERP stock-update pages, the bulk ERP scripts, db-transfer sign-in, the ERP secret in `data_sync_log`, secrets in git | Proto first, then live one at a time with your OK |
| **Plan question 5:** when staff press "cancel and restock remaining" in dispatch, is the item missing, or did the customer cancel? Related bugs (#43): that button does not restock, and the order planner's "already ordered" is always 0 | Needed before any site connects: opening stock is not corrected for it until it is answered |
| **How strict the go-live stock reset is** (D40b): keep it strict and settle the few "moved" items by hand, or let it accept sales made after the reset moment | Keep it strict. Needed before the first site's T0 |
| **The re-plan after I-1** (your decision 2) has not been done. The 1 Sep 2027 target assumes two engineers; nobody is named for the second | Re-plan now, with the pace measured from 26 Sep to 7 Oct, and say who the engineers are |
| **Push the CW repo to GitHub** | Yes. Nothing is pushed without your permission |
| **A fresh copy of live data into proto** for the final rehearsal (checklist #8). It copies customer data | Decide before the rehearsal (P), not now |
| Plan §16 questions: pack policy, whether a mapping lead may quarantine a listing, the Claude model and spending cap, marking the July draft spec as superseded (the relabel families are now item k in section 2) | Answer when each phase needs it |
| Accountant questions (inventory plan decisions 13 to 17) and the spring-count questions (18 to 21: one stocktake or two, how often to count, unstamped clearance, imports) | 13 to 17 with your accountant; 18 to 21 before the spring 2027 counts; both by about Feb 2027 |

---

## 5. How it fits together

```
  Vape and Go                 Electrofag                  Vape Big
  live: App + Website         159.223.245.136             159.65.209.7
  proto: App_proto +          proto: alt-proto +          proto: vapebig-proto +
         Website_proto               alt-store                   vapebig-store
                              (the live Electrofag admin
                               is on the same server)
       |                           |                           |
  connector                   connector                   connector
  BUILT on proto, OFF         not built yet               not built yet
       |                           |                           |
       +-------- HTTPS /v1, site key + allowed IP address -----+
                                   |
                     Central Warehouse (CW)
           staging: 46.101.55.135, https://warehouse-staging.floverfy.com
           staff screens /ui  -  API /v1  -  nightly checks
           database: managed MySQL 8.4, schema cw_staging
                                   |
                       (no link to ERPNext)

  ERPNext ----- today's stock sync -----> Vape and Go only
  (until "detach": CW never connects to ERPNext; ERPNext data reaches CW
   only from backup copies restored on a separate server)
```

How a sale will work once a site is connected: the customer places an order and CW holds the units. Payment turns the hold
into "allocated". A failed payment releases it, and an unpaid hold expires after 40 minutes. Dispatch takes the units off the
shelf figure. Each site's connector is **off**, **shadow** (listening only: CW records but never refuses) or **live**. Both
ends have a switch, and the more careful one wins: if either the shop or CW says "off", the link is off, and it is "live" only
when both say live. Each step up is one switch, and can be switched back.

### Which server does what

| Server | What runs there |
|---|---|
| **This server** (the Vape and Go web box; 161.35.163.191 is the one address CW's vapeandgo link allows) | Live App and Website; proto App_proto and Website_proto; the CW repo `/root/central-warehouse`; the work files `/root/cw_work/`. No CW app code, tests or migrations run here. Exceptions: the read-only export and sweep tools under `tools/`, and the proto connector (`App_proto/src/central_warehouse`) and its tests |
| **CW staging** 46.101.55.135 | The deployed CW `/opt/cw-staging` (public HTTPS site and nightly jobs), the test copies `/opt/cw-<slot>`, imported files `/srv/cw-import`, the document store `/srv/cw-docs` |
| **CW staging database** (DigitalOcean managed MySQL 8.4, private network) | `cw_staging` (the staging data) and `cw_test_<slot>` (test schemas, rebuilt by the tests) |
| **Live shop database** (remote DigitalOcean MySQL) | Read only for this project |
| **Proto database** (the 19 May backup copy) | The proto shops and the connector's `cw_*` tables |
| **Electrofag** 159.223.245.136 | The proto sites alt-proto and alt-store, and the live Electrofag admin www.alt.floverfy.com. Root SSH from here with `/root/.ssh/cw_sister_sites` since 7 Oct (unverified: not re-tested) |
| **Vape Big** 159.65.209.7 | The proto sites vapebig-proto and vapebig-store. Root SSH from here with the same key since 7 Oct (unverified: not re-tested); its database is in another DigitalOcean account |
| **ERPNext** (erp-v16-vpg) | Today's stock and purchasing. CW never calls it |

---

## 6. How to use what exists today (owner steps)

Use **real Chrome**. The browser panel inside the Claude desktop app is not a valid test. Never paste console output that shows
a password, a one-time password or a QR or `otpauth://` code into chat. If that happens, say so, and the secret gets changed.

### 6.1 Sign in

1. Open https://warehouse-staging.floverfy.com/ui/login.
2. Enter your e-mail, your password and the 6-digit code from your authenticator app, all in one form.
3. Ten wrong tries for one e-mail lock it for 15 minutes. If you lose your phone or password, the engineer gives you the
   command to run on the server yourself (`bin/reset_staff.php`). Claude cannot run it for you.

### 6.2 The Key spot-check (20 items)

"Key" is the pile where the barcode (or a transfer link) and the AI agree: the matches we are surest of.

1. Menu **Linking > Key spot-check** (`/ui/review/samples`), then open **owner-1**. It shows "n of 20 decided".
2. Open each of the 20. Compare the Electrofag product with the Vape and Go item.
3. Press **confirm** only when it is the same product, with 1 unit per item.
4. Only you can decide these 20. If any one is rejected or decided another way, the sample fails for good, and the remaining
   Key matches are then checked one by one. That is the safe outcome, not a disaster.
5. When all 20 are confirmed, tell Claude. The engineer runs a dry run first. You see the numbers (expect about 1,097 to be
   linked), and nothing is applied without your "go". You may need to allow the tool, or run it yourself. The bulk links are
   recorded as your decisions (one per listing, batch `key_bulk:owner-1`), and they can all be undone together.

### 6.3 The held-back list (254 doubtful matches)

- Your spreadsheet: `/root/cw_work/key_screen/Held_back_matches_owner-1.xlsx`, one tab "Held back (254)". It shows how doubtful
  each one is, the Electrofag listing, the Vape and Go item and why it was held.
- On the screens: the owner-1 sample page lists them, and each held listing says "Held back from the bulk confirm: <reason>".
- They stay in the normal Key queue, to be decided one at a time. There is no rush. The bulk confirm never touches them.

### 6.4 Company details

1. **Reference > Company details** (`/ui/reference/company`).
2. **Add or change the details**, then **Save**. You can leave fields empty and fill them in later.
3. Check every detail against Companies House and the VAT certificate. Then press **These details are correct**. The button
   appears once the legal name, company number, registered address, VAT number (or "Not VAT registered"), purchasing e-mail
   and delivery address are all filled in.
4. **See how a purchase order will look (PDF)** shows a sample order with your details.

Until you confirm them, every purchase order PDF says "COMPANY DETAILS NOT CONFIRMED — DO NOT SEND". If you both save and
confirm the key details yourself, the screen asks another reviewer to check them. With only one reviewer that check stays open.
That is expected, and it blocks nothing.

### 6.5 Duplicates

1. **Linking > Duplicates** (`/ui/review/duplicates`). The biggest sellers come first.
2. Open a group. Read **"What the rules say"** first (VG/PG, barcodes, options, strength and so on). Use "open the live page" to
   check both pages on the real shop.
3. Decide:
   - **Same product - merge into CW-x:** both pages share one item and one stock figure.
   - **Different products - keep separate:** recorded for good; the pair is never suggested again.
   - In a group of 3 or more: one choice per page, including "Not sure yet".
4. When the rules found a reason against, a merge needs the box **"I checked the live pages"** ticked.
5. A wrong merge: open the group (Duplicates > "Decided recently"), then **Undo a wrong merge > Split it off**.

Expect most of the 145 older groups to be **different** products: a review found 49 of 60 sampled pairs were different, and
only 21 of the 165 older suggestions pass the strict rules. The 11 new groups from 7 Oct were all checked by hand as the same
product (one of them only "probably"). Nothing changes on the shop: each page keeps its own price, reviews and stock field.

### 6.6 Purchasing test (needs two people)

This test needs two people: a buyer who makes the order, and a reviewer who checks it (nobody checks their own work). You are
already a reviewer, so ask Fazil to make a second person a buyer on **People and roles**. Or he makes you the buyer, and
someone else must then be the reviewer.

1. **Purchasing > Suppliers:** the buyer creates a test supplier (call it "TEST ..."), fills it in and presses "Ask a second
   person to activate it". The reviewer approves it in **Document reviews**.
2. **Purchasing > Reorder list:** pick a brand, read the "Why" on a few lines, and compare with ERPNext's "Fetch Item".
   Sales history stops at 1 Oct, so the list warns that it is out of date. For brands that sold extra in September, expect
   suggestions about 15 to 20% lower than ERPNext's 30-day average; that is intended.
3. Tick lines and **Create draft orders**. Open the draft, change a few packs, then **Save and approve the order**.
4. Open the PDF. It says "DO NOT SEND" until the company details are confirmed.
5. The reviewer reviews the order within 7 days.

Notes: staging has 0 suppliers today. The real ones come from the ERPNext backup copy. Documents are never deleted, so the test
orders stay in staging's history. That is fine on staging. The stock shown is the 1 Oct estimate; "Vape and Go's own stock" is
also offered for comparison. A daily sales-history refresh is not set up yet (engineer: `docs/ops.md` "Sales history", an
incremental export with `--from`).

### 6.7 Two small server steps only you can run

Run them in the Terminal tab beside this chat (that is this server). Neither prints a secret.

Remove the old access code. The command should print only names, one per line, with no `CW_KEY_VAPEANDGO` among them:

```bash
ssh -i /root/.ssh/cw_staging root@46.101.55.135 "sed -i '/^CW_KEY_VAPEANDGO=/d' /etc/cw/channel_keys.env && cut -d= -f1 /etc/cw/channel_keys.env"
```

Mark the staging server as staging (adds one line, prints nothing). The 3 proto test orders can then be removed:

```bash
ssh -i /root/.ssh/cw_staging root@46.101.55.135 "grep -q '^environment=' /etc/cw/app.env || printf '\nenvironment=staging\n' >> /etc/cw/app.env"
```

If you see anything else, stop and tell Claude what happened, without pasting the output.

### 6.8 Your checklist page

https://claude.ai/artifact/1XyBFj6qNLxhoDZBpJTYVs ("Central Warehouse Go-Live", 53 items). Its last update was 2 Oct 20:30 UTC,
so it is behind. These rows are out of date:

| Row | Says | Actually |
|---|---|---|
| #6 Name the team | Admin named | Also: your account holds admin + reviewer + mapping lead (admin switches the other two off) |
| #9 The 85–89% matches | Decide (390 matches): recommend Check | You decided Key (with an agreeing barcode); 435 moved on 3 Oct |
| #13 Vape Big: export and match | Blocked | Server access works since 7 Oct; change the leaked secrets first |
| #14 Vape and Go duplicates (165) | Two people each | One person for uncounted items; 176 suggestions in 156 groups now |
| #15 Matching rule fixes | To do | Done in 7555592 (2 Oct); they act on the next matching run |
| #17 Suppliers | Company details page "under Settings" | It is Reference > Company details |
| #18 Purchase orders | Sales history not loaded | Loaded 3 Oct |
| #28 Refresh the starting stock on connection day | To do | The tool is built (D40b, `--rebase`, dfbc571); it runs at each site's T0 |
| #29 Vape and Go connector | 165/165 tests; committed locally, not pushed | 180 mock tests now; pushed to the proto GitHub remote |
| #30 Electrofag connector | Blocked: login refused | Updated 7 Oct: in progress (discovery done, build running) |
| #31 Vape Big connector | Blocked: login refused | Updated 7 Oct: in progress (discovery done, build running) |
| #32 Security fixes | To do | Partly done: admin AJAX gate (30 Sep, other work); sftp.json not exposed |
| #40 Snapshot fix goes live | To do | Live since 2 Oct |
| #42 ERPNext live bugs | Boxes, unsent invoices, failed sales | Add: the stock sync has no company or warehouse filter (the VPG 2 push) |
| #47 ERPNext admin | Re-send 9,571 + 336 units | Brief v2: no re-send; corrections booked on the website side |
| #50 Snapshot fix live now? | To do | Live on Vape and Go; Electrofag needs the cron line; Vape Big needs the tool first |
| #51 Klaviyo and Lipscore watchers | Klaviyo and Lipscore | The live SMS watcher also runs from App_proto |

Claude updates the rows itself (the page keeps its rows in a small database).

### 6.9 Adding a person

New people can only be created on the server. Send the engineer each person's name, e-mail and job. The engineer creates the
account and shows you the first password and QR code on screen, never pasted into chat; hand them over by two different
routes. Fazil then adjusts their roles on **People and roles**. Until a second mapping lead exists, multipack matches and some
merges stay open.

---

## 7. How to continue (for the engineer)

### 7.1 Repos and branches

| Repo | Where | State on 7 Oct 2026 |
|---|---|---|
| CW | `/root/central-warehouse`, remote `git@github.com:shareefac/FlovEcom-Central-Warehouse.git` | `main` at **ff0e204**, clean except this file (`docs/HANDOFF.md` is untracked: commit it, or keep it out of the next deploy). 21 commits. GitHub `main` is 0e53d35 (checked 7 Oct with the deploy key, below), so 8 commits are unpushed: 7555592 to ff0e204 |
| App_proto | `/var/www/html/vpg_ecom/App_proto` (git, `master`), remote tabsyst/FlovEcom-Sandbox-Admin-Panel | HEAD ec58821e, **2 behind** `origin/master` (665a010f) after a fetch at 04:50 UTC on 7 Oct. Connector commits c52a7e62 and b6dfc40a are in origin. 28 uncommitted entries belong to other work (stock-notify, auth, and so on), not CW. Upstream 0d30eacc (another developer) changes `app_config/mail/order_mail_builder.php`, which also has uncommitted local changes: look before pulling |
| Website_proto | `/var/www/html/vpg_ecom/Website_proto` (git, `master`), remote tabsyst/FlovEcom-Sandbox-VapeAndGo-Website | HEAD = `origin/master` as of its last fetch (2 Oct; not re-fetched). Connector commit b07f3349c is in origin. 46 uncommitted entries from other work; one of them adds 13 lines to `config/modules/check_out.php`, which shifts hooks H1 to H5 by 13 lines (their anchors still match) |
| Live App and Website | `/var/www/html/vpg_ecom/App`, `/Website` | **Not git.** No CW code at all. Never copy whole files between proto and live: port hook lines and new files only |

Local branches `connector-followups` and `mapping-key-bulk` are fully merged into `main`.

**Leftovers you can clean up (look first, then ask the owner):**

```bash
cd /root/central-warehouse
git worktree remove /root/cw-followups      # connector-followups at dfbc571, clean, merged (4d78794)
git worktree remove /root/cw-mapping        # mapping-key-bulk at 7555592, clean, merged (f380816)
git branch -d connector-followups mapping-key-bulk
git stash show --stat stash@{0}             # "key-hold partial build, stopped 3 Oct 2026": replaced by 5ca669b (M30)
git stash drop stash@{0}
```

**Commit identity.** No identity is set in the repo config; set it on each commit, and end every message with the
Co-Authored-By line, as all 21 commits do:

```bash
git -c user.name="Claude (CW build)" -c user.email=support@vapeandgo.co.uk commit
```

**Pushing** needs the owner's permission. The default ssh key is refused; use the repo deploy key. Reading the remote with it
works (`ls-remote`, 7 Oct):

```bash
GIT_SSH_COMMAND="ssh -i /root/.ssh/cw_repo_deploy -o IdentitiesOnly=yes" git ls-remote --heads origin
GIT_SSH_COMMAND="ssh -i /root/.ssh/cw_repo_deploy -o IdentitiesOnly=yes" git fetch origin && git status -sb
GIT_SSH_COMMAND="ssh -i /root/.ssh/cw_repo_deploy -o IdentitiesOnly=yes" git push origin main     # only with the owner's OK
```

### 7.2 Tests: slots and `scripts/remote.sh`

No CW app code, tests or migrations run on this server, because it is the live web server (`docs/dev.md`). The exceptions are
the read-only export and sweep tools under `tools/` (at `nice`), and the proto connector and its tests (below).
`scripts/remote.sh <slot> <command>` copies the working tree to `/opt/cw-<slot>` on staging (rsync with `--delete`), runs
`composer install` when needed, and runs the command there against the test schema `cw_test_<slot>`. Use your own slot name
per workstream (`[a-z0-9_]{1,24}`).

```bash
scripts/remote.sh <slot> vendor/bin/phpunit                         # full suite (HTTP screen/API tests skip here)
scripts/remote.sh ui vendor/bin/phpunit                             # includes the screen tests (vhost cw-ui, cw_test_ui)
scripts/remote.sh api vendor/bin/phpunit                            # includes the HTTP API tests (vhost cw-api, cw_test_api)
scripts/remote.sh hammer php tests/concurrency/hammer.php --seed=<n> # the stress test, at most 30 connections
scripts/remote.sh <slot> php tests/matching/run.php                 # matching golden tests
```

Last results (7 Oct 2026, `docs/decisions.md` M45): full suite in slot `dup5` **796 tests, 16,981 assertions, 75 skipped**
(the HTTP ones). Slot `ui`: 5 screen test classes, 49 tests OK. Hammer `--seed=20261006`: PASS, 59 checks. Matching golden: 59
passed, 0 failed. **The full HTTP suites (`scripts/remote.sh ui vendor/bin/phpunit` and the same with slot `api`) were last run
in full on 2 Oct (406d025); run both before the next deploy.** The staging cluster allows 76 connections in all, shared by
every slot. Details: `docs/dev.md`.

The **proto connector tests** run on this server, from `App_proto/src/central_warehouse`: `php tests/run.php`. They are NOT
read-only: they insert fake orders into the proto database and clean them up afterwards. 180 mock tests are registered. The
last recorded run (2 Oct 16:31 UTC, workflow output `tasks/wk3mijhde.output`) says 180 passed, 0 failed; the saved log
`scratchpad/conn_tests.log` (15:52) says 177. b6dfc40a was committed later (18:05), so run them again before relying on them.
`--staging-readonly` (6 tests) only reads.

### 7.3 Deploying to staging

There is one deploy command. It copies the slot to `/opt/cw-staging` (no `tests/` or `tools/`), applies pending migrations,
installs the cron and log rotation, and runs every job once. The public HTTPS site serves `/opt/cw-staging` directly, so this
also deploys the screens and the API.

**Before:**
1. Commit first. The deploy copies your working tree, untracked files included, and nothing checks this for you: run
   `git status -sb` and make sure it says `## main` with no changes.
2. Full suite green in your slot, plus `ui` (if screens changed), `api` (if the API changed), the hammer (if stock code changed)
   and the golden tests (if matching changed).
3. Check what is pending:
   `ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/migrate.php --db=cw_staging --status'`

**Deploy:**

```bash
scripts/remote.sh hammer bash deploy/staging/install_cron.sh --migrate
```

Migrations are forward-only. Never edit an applied migration file; add the next number instead (`docs/dev.md`, "Writing a
migration"). A migration and the code that needs it go out together in this one command, never the code first.

**After:**
1. The installer's smoke runs all say ok.
2. `ssh -i /root/.ssh/cw_staging root@46.101.55.135 'cd /opt/cw-staging && php bin/invariants.php --db=cw_staging'` says
   `ok: invariants hold`.
3. `curl -s -o /dev/null -w '%{http_code}\n' https://warehouse-staging.floverfy.com/ui/login` gives 200, and `/v1/health`
   without a key gives 401. This is CW staging, not a live shop.
4. Sign in with real Chrome and open the screen that changed.
5. Optional: `diff <(ssh -i /root/.ssh/cw_staging root@46.101.55.135 cat /etc/cron.d/cw-staging) deploy/staging/cw-staging.cron`

Installed jobs (`/etc/cron.d/cw-staging`, UTC): expire holds every minute; prune the change feed 03:17; invariants 03:47; seal
the file store every minute; verify stored files 04:27. Logs are in `/var/log/cw/`. `health_alert.php` is a stub and is not
scheduled, so **nobody is alerted when a nightly job fails**: failures only reach `/var/log/cw/invariants.log`,
`verify_files.log` and `journalctl -t cw-invariants`.

### 7.4 The way of working

1. **Design**: for a phase, write down what to build and the questions it raises.
2. **Build**: in its own slot or worktree.
3. **Review**: adversarial reviews (two reviewers looking for bugs).
4. **Fix**: every finding gets an ID in `docs/decisions.md` (for example I72–I89, M39–M45).
5. **Commit**: one commit per finished piece, with test figures in the message.
6. **Deploy**: to staging (7.3).
7. **Report** to the owner: what was built, the test results, and the owner's own test steps as a short numbered list.

The standing go of 2 Oct covered building and deploying on **staging and proto**. Since the owner paused the work on 3 Oct to
save tokens, each new phase (starting with I-3) waits for an explicit go; the standing go applies again only if the owner says
so. Always ask before any live change and before any real business decision. Where the plan recommends something the owner
has not decided, build it as a provisional setting and say so.

### 7.5 Safety rules (do not skip)

- **Proto first, live read-only.** Live is App, Website, the live database, crons, supervisor and ERPNext, and now also the
  Electrofag and Vape Big servers (the Electrofag one hosts the live Electrofag admin). Read it only (SELECT inside a READ ONLY
  transaction). Every live change is tried on proto first, then made one at a time, with the owner's explicit OK and a backup
  of each file.
- **Time caps on every database session.** MySQL (shop and CW): `SET SESSION MAX_EXECUTION_TIME=30000`. MariaDB (ERPNext):
  `SET SESSION max_statement_time=30`. Run EXPLAIN first. Subagents cannot kill a runaway query, so put the caps in their
  prompts.
- **CW staging reads** go through the app login, as SELECTs in `START TRANSACTION READ ONLY`, run as PHP piped over ssh with
  `chdir('/opt/cw-staging')` and `CW\Config::load()->dbApp()->withDatabase('cw_staging')`.
- **Secrets.** Never print or copy a password, key, token or authenticator seed. That includes `/etc/cw/*.env` on staging and
  the proto connector's config `/etc/vpg/central_warehouse_proto.php`, which holds the site key. Parse `/etc/cw/db.env`; never
  `source` it, because sourcing runs each line. The owner tends to paste full console output, so give commands that print no
  secret.
- **Never read the ERPNext stock feed** (`get_product_stock_sync`), not even with curl or a "review mode". Each read marks
  waiting purchase invoices as Synced without sending them; on 5 Oct one read swallowed 200 units. Never press Retry in admin
  `erp_stock_update`.
- **Never send a request, not even HEAD, to a live PHP URL** to test it. PHP runs it. Read the file and the access logs instead.
- **Skip anything alarming in a bulk action.** Put an alarm screen before every apply. Never apply a flagged row and never drop
  it silently. Finish the safe rows, then give the owner one "skipped for you" list.
- **Crontab:** install from stdin (`crontab - < file`), back up first, change one line, diff afterwards.
- **Proto keeps sending real email** on purpose (the owner's choice; do not propose a test-only list). In your own tests use
  example.com addresses or the approved test address. Proto's database is a copy of real customers, so a back-in-stock
  trigger on proto mails every real subscriber of that item: check the item's list first.
- **`.git` folders.** The proto ones are blocked from the web by an unanchored rule in the proto `.htaccess` files. After any
  proto git work, check that `/.git/HEAD` still gives 404 on vpg-proto, vpg-store and the ecom.tabsyst.com paths. The two
  sister servers have a server-wide block since 7 Oct (`/etc/apache2/conf-available/zz-block-git.conf`); never remove it.
- **The owner handles duty records.** Do not remind them about the duty zip, the accountant or the register.
- Give your own view before you comply, and never blame the owner for a change you cannot explain: check your own steps first.

### 7.6 Known tool blocks

| Block | What to do |
|---|---|
| The auto-mode safety system blocked `bin/bulk_confirm_key.php`, even its dry run (6 Oct) | Ask the owner to allow it, or give them the command |
| It blocked editing `/etc/cw/channel_keys.env` | The owner runs the command in 6.7 |
| It blocks `bin/reset_staff.php` (account resets) | The owner runs it; the output holds a secret |
| `git ls-remote origin` fails with the default key | Use the deploy key (7.1) |
| ssh to Electrofag (159.223.245.136) or Vape Big (159.65.209.7) | Works since 7 Oct with `-i /root/.ssh/cw_sister_sites` (root; unverified: not re-tested). These are live servers: read only, like this one |
| `cw_t0.php --capture` refuses any CW but the local mock | Lifted only with the owner's go, at the Vape and Go T0 rehearsal |

### 7.7 Where the decisions and runbooks are

| File | What |
|---|---|
| `docs/plan.md` | The approved plan of 26 Sep, kept up to date (API additions, the 2 Oct Key rule, the T0 rebase). The copy in `/root/.claude/plans/we-need-a-plan-imperative-star.md` is the original, without those changes |
| `docs/inventory-modules-plan.md` | Moving stock and purchasing into CW: 15 modules, phases I-0 to I-7, the 26 owner decisions (§10) |
| `docs/decisions.md` | Every choice the plan leaves open, by ID: D (stock), A (API), SC (site connector), H (jobs), R (review fixes), M (matching and linking), U (screens), I (inventory), plus "Open items". Note: "D1–D7" in ops.md and the documents code are document checks, not decisions D1–D7; C1–C4 are company-details checks |
| `docs/ops.md` | Runbooks: jobs, channels and keys, the hammer, first match, Key spot-check and bulk (l.386), Duplicates (l.522), the wider sweep (l.590), opening stock (l.649), a site's T0 (l.681), test leftovers (l.752), staff screens and accounts (l.838), file store, purchasing, purchase orders, sales history and reorder |
| `docs/dev.md` | How to develop and test |
| `App_proto/src/central_warehouse/MANIFEST.json` | The connector: 78 files with checksums, 14 hook points in 9 site files, the port checklist. There is no README there; the connector is described in `docs/plan.md` §6 and `docs/ops.md` "A site's T0" |
| `/root/cw_work/erpnext/ERPNext_stock_sync_fixes_2026-10-02.md` | Brief v2: Part 1 for the ERPNext admin (Step 0 is a blocker for any restored copy), Part 2 (P1 to P4) for Claude |
| `/root/cw_work/handoff_src/` | The owner notes and the checklist export this page was built from |
| Memory notes | `/root/.claude/projects/-var-www-html-vpg-ecom/memory/`. CW ones: central-warehouse-plan, cw-staging-environment, cw-paused-oct3, build-all-standing-go, guide-step-by-step-before-starting, skip-alarming-show-at-end, proto-first-no-live-changes, never-read-erp-stock-feed, vpd-duty-start-stock-record, erp-vpg2-opening-stock-push, proto-git-exposure, head-request-executes-php. For I-3 also: back-in-stock-email-architecture (availability comes from the stock mode, not the quantity, so hook both routes), proto-storefront-testbed, proto-email-stays-live |
| Workflow journals | Under `/root/.claude/projects/-var-www-html-vpg-ecom/5e8efa21-e26d-42dc-be75-4ec4dcbf767f/subagents/workflows/` (for example `wf_761ea40a-40e` matching, `wf_16fccc3e-4f2` inventory plan, `wf_55c3a218-7ba` Phase I-1) |

### 7.8 Next steps, in order

**Step 1. Re-count before you act.** Owner activity after 7 Oct 05:10 UTC is not on this page. Read-only checks:
`match_decision` by action, `key_sample_member` decisions for sample 1, `company_profile` versions, open `vpg_duplicate`
proposals, `MAX(id)` of `audit_log` (20,984 on 7 Oct). Read the last lines of `/var/log/cw/invariants.log` and
`verify_files.log` on staging (all "ok" on 7 Oct; verify_files shows the 1 known orphan).

**Step 2. The Key bulk confirm, once the owner has confirmed 20 of 20** (`docs/ops.md` "Key spot-check and bulk confirm",
step 6). The hold step (5) is done: 254 held, from a separate rule and AI screen (`/root/cw_work/key_screen/`, applied from
`/root/key_bulk_owner-1_hold.csv` on staging on 6 Oct at 20:03). The tool's own step-4 report has **not** been run (it refuses
until 20/20), so screen the dry-run report below carefully. On the staging box:

```bash
ssh -i /root/.ssh/cw_staging root@46.101.55.135
cd /opt/cw-staging
# dry run, writes nothing: expect held=254 and about 1,097 eligible (1,351 - 254; not yet computed by the tool)
php bin/bulk_confirm_key.php --sample=owner-1 --lead=support@vapeandgo.co.uk --report=/root/key_bulk_owner-1_dry.csv
#   Screen the report (skip-alarming rule). Hold any new doubtful row with bin/key_bulk_hold.php (a file with
#   proposal_id, listing_id, reason). Show the owner the numbers. Apply only on their go:
php bin/bulk_confirm_key.php --sample=owner-1 --lead=support@vapeandgo.co.uk --apply --limit=10   # a canary: check on the screens
php bin/bulk_confirm_key.php --sample=owner-1 --lead=support@vapeandgo.co.uk --apply              # the rest
# undo, only if needed (dry run first, then --apply):
php bin/bulk_unlink.php --batch=key_bulk:owner-1 --lead=support@vapeandgo.co.uk
```

If the sample fails, nothing is bulk-linked. The Key queue is then decided one at a time, and a new sample may follow only after
a new matching run (`--after-failed`).

**Step 3. Remove the proto test orders, once the owner has added the staging marker** (6.7; `docs/ops.md` l.782). Expect 3
reservations with 5 units, 9 idempotency rows and 4 heartbeat rows, plus their 4 idempotency rows:

```bash
UNTIL=$(date -u +%Y-%m-%dT%H:%M:%SZ)
ssh -i /root/.ssh/cw_staging root@46.101.55.135 "cd /opt/cw-staging && php bin/purge_test_refs.php --db=cw_staging --channel=vapeandgo --prefix=proto1- --heartbeats-until=$UNTIL"
#   if every number matches, the same with --actor='<your name>' --apply; then run the invariants
```

Before any real orders reach that server, remove the marker again: `sed -i '/^environment=staging$/d' /etc/cw/app.env`.

**Step 4. Phase I-3 Receiving, on the owner's explicit go** (section 1). Plan: `docs/inventory-modules-plan.md` §7 (the owner
test there also includes a PO copy-down, a supplier-sheet import and timing against ERPNext). The site stock writer goes on
Vape and Go proto only. Before the delivery screen: the typists' half-day and their sign-off on mock-ups (#48). For the test:
real suppliers (the backup copy, or the 47 typed by hand), items that also exist on proto (a 19 May copy), and an
out-of-stock item whose back-in-stock list holds only example.com or the approved test address.

**Step 5. Re-run the duplicate sweep after the 145 older groups are decided** (`docs/ops.md` l.590). One pair is waiting for
this: Corex 2.0 0.4 ohm, listings 14652 and 14681.

```bash
umask 077; TS=$(date -u +%Y%m%dT%H%M%SZ)
ssh -i /root/.ssh/cw_staging root@46.101.55.135 'php -- --root=/opt/cw-staging --db=cw_staging' \
    < tools/vpg_duplicates/export.php > /root/cw_work/vpg_dups/export_$TS.jsonl
nice -n 19 php tools/vpg_duplicates/sweep.php --export=/root/cw_work/vpg_dups/export_$TS.jsonl \
    --out=/root/cw_work/vpg_dups/sweep_$TS --sample=40
# read sweep_$TS/sample.txt and summary.json first, then copy the groups file to staging:
scp -i /root/.ssh/cw_staging /root/cw_work/vpg_dups/sweep_$TS/groups.jsonl root@46.101.55.135:/srv/cw-import/vpg_dup_sweep_$TS.jsonl
# on the staging box, in /opt/cw-staging:
php bin/import_vpg_duplicates.php --groups=/srv/cw-import/vpg_dup_sweep_$TS.jsonl            # dry run
php bin/import_vpg_duplicates.php --groups=/srv/cw-import/vpg_dup_sweep_$TS.jsonl --apply
```

**Step 6. When the ERPNext admin sends X1 and X2:** brief v2 Part 2 (P1 to P4): remove the old IP; work out the corrections
read-only; book them with one reviewed script, proto first, after the owner approves the list; then add repeat protection.
Before that, add the VPG 2 fix (send only the website warehouse) to the brief.

**Step 7. Tidy up.**
- Commit this file (`docs/HANDOFF.md`) with the Co-Authored-By line.
- Fix stale lines in `docs/ops.md`: l.41 (the schema is now at 0015, not 0005); l.877 ("Real staging data: not served by
  anything yet": the public site serves it); l.950 (the HTTPS heading says "NOT done": it is on since 1 Oct); l.990 (the file
  store "not done yet": it exists).
- `docs/plan.md` §15.1 (l.637) still says the snapshot runs at 23:55 UTC; it now runs at 23:55 UK time. Set `UK_TIME_FROM` in
  `/root/cw_work/duty/vpd_snapshot_export.php` (still `null` at line 20); the first UK-time snapshot is `pss_date` 2026-10-02.
- With the owner's OK, remove the two orphan comment lines (92 and 93) of the old proto rehearsal from the live root crontab,
  using the crontab rules in 7.5.
- Update the checklist rows listed in 6.8: the page keeps its rows in a database, so write them with the `ArtifactData` tool on
  https://claude.ai/artifact/1XyBFj6qNLxhoDZBpJTYVs. Update the memory notes that are behind: cw-staging-environment (it says
  the rebase tool cannot rebase yet, but dfbc571 added `--rebase`) and central-warehouse-plan (50 steps; there are 53).
- Single copies: the 8 unpushed commits exist only in `/root/central-warehouse`, and `/root/cw_work` (547 MB: run3 outputs,
  the held-back sheet, sales exports, duty files) is not in git and has no known backup. Confirm the backups of `cw_staging`
  and the staging droplet (unverified), and copy the snapshot-fix backups out of `/tmp` (section 8).
- `docs/finance-design.md` was promised by plan §15.5 and does not exist yet.
- Clean up the worktrees and the stash (7.1).

**Before any proto shadow test or any site's T0:**
- Restart the four App_proto payment watchers: `App-proto-pheanstalk-paypal-timeout-watcher`,
  `App-proto-pheanstalk-viva-timeout-watcher`, `fve-App-proto-pheanstalk-globalpay-timeout-watcher` (in
  `App-pheanstalk-globalpay-timeout-watcher.conf`) and `fve-App-proto-pheanstalk-worldpay-timeout-watcher` (in
  `timeout-watcher-worldpay-pheanstalk.conf`). The last two files also hold a live App program: restart by program name only.
  They started on 24 Sep, before the hooks existed, so they run code without the connector. That is harmless while the mode is
  off.
- The connector's sync worker is not installed under supervisor: only the example file
  `App_proto/src/central_warehouse/deploy/supervisor/App-proto-cw-sync-worker.conf.example` exists. Supervisor on this box
  also runs live programs, so installing it needs the owner's OK.
- Re-import the Vape and Go listings (`bin/import_listings.php`, `docs/ops.md` l.321) and link the new ones: the shop's 1 Oct
  snapshot has 29,127 variants and CW has 29,105, so 22 variants are unknown to CW.

### 7.9 Small open questions found during the checks

- `/srv/cw-docs` holds one 2,899-byte file from 2 Oct that no `stored_file` row names, so `verify_files` reports 1 orphan.
  It is probably an install test file; it was not opened (unverified).
- Electrofag's sales history starts on 8 May 2026, although the export asked for 12 months. `docs/decisions.md` I70 notes
  "history from May 2026", so the shop's own order data probably starts then (unverified against the shop).
- `listing_stock_latest` (the shops' own stock, loaded with the sales history) has 29,127 rows for Vape and Go, 22 of them
  variants CW does not know. They were probably created after the 26 Sep catalogue export (unverified); see the last step of
  7.8.
- The inactive placeholder account (staff 2) still holds an unrevoked mapping_lead grant. It cannot sign in. Whether any tool
  counts it was not checked (unverified).
- Open items in `docs/decisions.md` (l.1091–1151) before production: switch the DB TLS check to VERIFY_CA, chunk the
  invariant check, a TLS site for warehouse.floverfy.com, pruning of idempotency, channel_health and audit_log, jobs running as
  root, a real health alert, and the feed-clock ceiling (about 70 to 100 mixed operations a second).

---

## 8. Where everything lives

| What | Where |
|---|---|
| Staff screens (staging) | https://warehouse-staging.floverfy.com/ui/login |
| API (staging) | https://warehouse-staging.floverfy.com/v1/ (a site key and an allowed IP are needed) |
| Proto shop (test copy of Vape and Go) | https://vpg-store.floverfy.com (Website_proto, no Cloudflare, the 19 May backup database) |
| Proto admin | https://vpg-proto.floverfy.com (App_proto; once the connector is out of "off", products show "Linked to CW-..." badges there) |
| Owner's checklist | https://claude.ai/artifact/1XyBFj6qNLxhoDZBpJTYVs |
| CW repo | `/root/central-warehouse` (GitHub `shareefac/FlovEcom-Central-Warehouse`) |
| CW docs | `/root/central-warehouse/docs/` (plan, inventory-modules-plan, decisions, ops, dev, this file) |
| Original plan file | `/root/.claude/plans/we-need-a-plan-imperative-star.md` |
| CW staging server | `ssh -i /root/.ssh/cw_staging root@46.101.55.135` |
| Electrofag and Vape Big servers | `ssh -i /root/.ssh/cw_sister_sites root@159.223.245.136` (Electrofag) or `root@159.65.209.7` (Vape Big). Live servers: read only |
| Deployed CW on staging | `/opt/cw-staging` (a copy, not git) |
| Test copies on staging | `/opt/cw-<slot>`; internal sites `/opt/cw-api` and `/opt/cw-ui` on 127.0.0.1:8080 |
| Imported files on staging | `/srv/cw-import/` (listing exports, the opening input, run2 and run3, the sweep file, sales exports) |
| Document store on staging | `/srv/cw-docs/` (write-once) |
| Job logs on staging | `/var/log/cw/` |
| Staging settings (secrets, never print) | `/etc/cw/db.env`, `/etc/cw/app.env`, `/etc/cw/channel_keys.env` |
| Connector code (proto) | `/var/www/html/vpg_ecom/App_proto/src/central_warehouse/` |
| Connector config (proto) | `/etc/vpg/central_warehouse_proto.php` (**holds the site key: never print or copy it**): mode off, channel vapeandgo, prefix proto1-, base URL staging `/v1`. The live config `/etc/vpg/central_warehouse.php` does not exist |
| Connector state and log (proto) | `/var/lib/vpg_cw_proto/mode.json` (off), `/var/log/vpg_cw_proto/connector.log` |
| Work files | `/root/cw_work/` |
| Duty records | `/root/cw_work/duty/` (duty-start record zip and workbook, the 30 Sep snapshot, goods-in register v1 and v2, the opening input) |
| ERPNext fix brief | `/root/cw_work/erpnext/ERPNext_stock_sync_fixes_2026-10-02.md` |
| First match | `/root/cw_work/first_match/` (exports, run1 to run3; `private/` must never reach a blind judge) |
| Key spot-check | `/root/cw_work/key_sample_owner-1.txt` (the 20) |
| Held-back list | `/root/cw_work/key_screen/Held_back_matches_owner-1.xlsx`, `hold_owner-1.csv` |
| Duplicate sweeps | `/root/cw_work/vpg_dups/` (the ds1.1 run imported on 7 Oct: `sweep_ds11_20261006T225658Z/`) |
| Sales exports | `/root/cw_work/sales_history/` |
| Connector build evidence | `/root/cw_work/phase3/` |
| Live snapshot job | Root crontab line 70 on this server; `/var/log/stock_snapshot.log` |
| Backups of the live changes | Watchers: `/root/supervisor-backup-20261002T092341Z-watchers-to-App`. Snapshot fix: `crontab.before`, `crontab.before-rehearsal-removal` and `stock_snapshot_build.php.live-before` in `/tmp/claude-0/-var-www-html-vpg-ecom/da179d50-345f-48f3-b290-472177315cec/scratchpad/bak/` (under /tmp: copy them somewhere safer), plus `/root/crontab-backup-20261001T071138Z-before-proto-snapshot-rehearsal` |

---

## 9. Glossary

| Word | Meaning |
|---|---|
| **CW** | The Central Warehouse: the separate system that holds the real stock of the one warehouse |
| **Live** | The real shops customers use (App, Website, the live database) |
| **Proto** | The test copies of the shops on this server (App_proto at vpg-proto.floverfy.com, Website_proto at vpg-store.floverfy.com), on a 19 May backup database. Real email still goes out from proto |
| **Staging** | CW's test server and database. Nothing on it is real business yet |
| **Production CW** | The real CW server, not built yet |
| **Channel** | One website, as CW sees it (vapeandgo, electrofag, vapebig) |
| **Connector** | The code inside a site that talks to CW |
| **Off / shadow / live mode** | Off: the connector does nothing. Shadow: CW records everything but never refuses an order ("listen-only"). Live: CW may refuse an order when an item is short |
| **Listing** | One product page variant on one site |
| **Item** | One CW product record (code `CW-000123`). Several listings, from one site or several, can link to one item |
| **Link / map** | Joining a listing to an item. Only a person confirms a link; the AI only suggests |
| **Proposal** | A suggested link from a matching run |
| **Band** | How sure a proposal is. **Key**: the barcode or transfer link and the AI agree (from 85% confidence with an agreeing barcode). **Check**: likely, a person looks. **Can't tell**, **Conflict** (the evidence disagrees), **New item** (no match; make a new item), **Manual** (relabels and duplicate suggestions) |
| **Spot-check / sample** | 20 Key proposals drawn at random by the server. If the owner confirms all 20, the rest of that pile is linked in bulk |
| **Bulk confirm** | Linking the rest of the Key pile in one run, one recorded decision each, and undoable |
| **Hold (held back)** | A listing kept out of every bulk confirm, to be decided one at a time |
| **Duplicate group** | Two or more Vape and Go pages that may be the same product |
| **Merge** | Making two items one: the pages of both link to the kept item, and its available stock moves with it |
| **Split** | Undoing a merge |
| **Protected item** | An item that has been counted; changes to it need two people |
| **Legacy item** | Not counted yet. The site sells it as today, and CW only records |
| **on_hand / allocated / held / available** | On the shelf / paid but not dispatched / in an unpaid checkout / on_hand minus allocated minus held |
| **Opening estimate** | The starting stock before any count: here Vape and Go's duty-day figure |
| **T0** | The moment a site starts talking to CW. Its stock figure is then reset ("rebased") to that moment |
| **Rebase** | The one-off correction of the opening estimate at T0 |
| **Invariants** | The nightly self-check that every stock total adds up. Nothing is ever repaired automatically |
| **Migration** | A numbered database change (0001 to 0015). Applied once and never edited |
| **Provisional (setting)** | A default we guessed and built in, which the owner has not confirmed yet |
| **Slot** | A private test copy on the staging server (`/opt/cw-<slot>`, schema `cw_test_<slot>`) |
| **Hammer** | The stress test: many processes buying at once |
| **Reviewer** | The role that checks other people's documents. Never the person who made them |
| **Mapping lead** | The role that decides links and merges |
| **Admin** | The role that manages people and roles only. It never posts, reviews or decides |
| **I-Day** | The day stock and purchasing move from ERPNext to CW (target 1 Sep 2027) |
| **D** | The later day the accounts move (about 30 Apr 2028) |
| **Detach** | Switching ERPNext off for good, keeping it as exports |
| **Phase 0–10, I-0 to I-7, P, P+, 5a, 5b** | Plan phases (`docs/plan.md` §12, `docs/inventory-modules-plan.md` §7). 5a = live shadow, 5b = all sites live |
| **Duty-day stock** | Vape and Go's own stock figure at 00:00 BST on 1 Oct 2026, the start of Vaping Products Duty |
| **ERPNext feed** | `get_product_stock_sync`. Never read it: reading it has side effects |
| **VPG 2** | A second ERPNext company and warehouse, set up on 2 Oct. The owner says it is their separate company and warehouse, and nothing from it should reach the website |
| **X1 / X2** | The two ERPNext exports brief v2 asks for: X1 = lines sent in boxes instead of units; X2 = every stock invoice with what it should have sent |
| **Skip-alarming rule** | In any bulk action: skip doubtful rows, finish the rest, list the skipped ones at the end |
