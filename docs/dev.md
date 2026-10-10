# Developing and testing CW

Code is edited in this repo (on the Vape and Go web server). **Nothing that executes CW code runs
here**: tests, migrations and scripts run on the CW **staging** server `46.101.55.135`
(`FlovEcom-Central-Warehouse`, PHP 8.3, 2 vCPU) against the staging managed MySQL (8.4, private
network, TLS). No live or proto site or database is ever touched.

## Quick start

```bash
scripts/remote.sh base vendor/bin/phpunit                          # everything
scripts/remote.sh base vendor/bin/phpunit --testsuite unit         # unit only
scripts/remote.sh base vendor/bin/phpunit --filter SmokeTest       # one class
scripts/remote.sh base 'vendor/bin/phpunit 2>&1 | tail -20'        # one argument = shell string
scripts/remote.sh base env CW_TEST_DB=0 vendor/bin/phpunit --testsuite unit   # no database
```

`scripts/remote.sh <slot> <command...>`:
1. rsyncs the repo (without `vendor/`, `.git/`, PHPUnit caches) to `root@46.101.55.135:/opt/cw-<slot>/`
   with `--delete` (the remote copy mirrors your tree; its `vendor/` is kept);
2. runs `composer install` there when `vendor/` is missing or `composer.json`/`composer.lock`
   changed (fingerprint in `vendor/.cw-composer-hash`); if the repo has no `composer.lock`, the one
   resolved on staging is copied back — commit it;
3. runs the command in `/opt/cw-<slot>` with `CW_SLOT=<slot>` and returns its exit status.

Runs for the same slot are serialised by a local lock (`$XDG_RUNTIME_DIR` or `/tmp`
`cw-remote-<slot>.lock`); different slots run in parallel. Use your own slot name
(`[a-z0-9_]{1,24}`) per workstream so directories and test schemas never collide. rsync runs
under `nice -n 19` because this box is the live web server; do not run heavy work locally.
Overrides: `CW_REMOTE_HOST`, `CW_REMOTE_KEY`.

## Test schemas

- `tests/bootstrap.php` drops, re-creates and migrates **`cw_test_<slot>`** (or `CW_DB_NAME`, which
  must start with `cw_test_`) once per PHPUnit run, as the admin login from `/etc/cw/db.env`.
  Only `cw_test_*` schemas are ever dropped. `CW_TEST_DB=0` skips the database.
- Integration tests extend `CW\Tests\Support\IntegrationTestCase`: `self::$db` is an admin
  connection to the test schema and every test starts with empty tables (seeded warehouses
  `MAIN`/`VERIFY`/`UNSTAMPED` and `schema_migrations` are kept). Helpers: `makeChannel()`,
  `makeSku()`, `warehouseId()`, `mysqlError(fn)` (returns the MySQL error code raised).
- `TestDb::connect()` opens an extra connection (concurrency tests); `DbTest` shows a real
  two-session deadlock driven with an async `mysqli` session.
- Suites: `unit` = `tests/Unit`, `integration` = `tests/Integration` (phpunit.xml).
- **Connection budget:** the staging cluster allows `max_connections = 76` in total, shared by
  all slots. A hammer test must use a bounded pool, not one connection per simulated request:
  `tests/concurrency/hammer.php` holds at most 30 (`docs/ops.md`), `WorkerPool` at most 12.

## Staging database

| Thing | Where |
|---|---|
| Admin login (doadmin) | `/etc/cw/db.env` on staging, DigitalOcean `key = value` (username, password, host, port, database, sslmode) |
| App login (`cw_app`) | `/etc/cw/app.env` on staging, 0640 root:www-data (php-fpm reads it), `db_user` / `db_password` / `db_name` / `db_host` / `db_port` |
| App schema | `cw_staging` |
| Test schemas | `cw_test_<slot>` (+ `cw_test_<slot>_mig` briefly, MigratorTest) |

Both files are **parsed** by `CW\Config` (never `source` them: sourcing executes each line).
Never print passwords or keys; say "present".

```bash
scripts/remote.sh base php bin/setup_staging.php          # idempotent: schema, cw_app, migrations, grants, checks
scripts/remote.sh base php bin/migrate.php --db=cw_staging          # apply pending migrations (+ refresh cw_app grants)
scripts/remote.sh base php bin/migrate.php --db=cw_staging --status
```

Server facts that matter: MySQL 8.4.8; the server default `sql_mode` is `ANSI` (ANSI_QUOTES,
PIPES_AS_CONCAT) — `CW\Db` pins its own session mode, so always use backticks for identifiers
and single quotes for strings; `sql_require_primary_key = 1` (every table needs a PK);
`innodb_autoinc_lock_mode = 2`; `lower_case_table_names = 0`; system time zone UTC.

## Configuration (`CW\Config`)

| Source | Keys |
|---|---|
| `/etc/cw/db.env` | username, password, host, port, database (ignored for CW), sslmode, optional ssl_ca; `KEY=value` and `DB_*` spellings are accepted too |
| `/etc/cw/app.env` | db_user, db_password, db_name, any other app key (`$config->get('key')`) |
| Environment | `CW_DB_ENV`, `CW_APP_ENV` (paths); `CW_DB_HOST`, `CW_DB_PORT`, `CW_DB_NAME`, `CW_DB_SSLMODE`, `CW_DB_SSL_CA` (both logins); `CW_DB_USER`/`CW_DB_PASSWORD` (app); `CW_DB_ADMIN_USER`/`CW_DB_ADMIN_PASSWORD` (admin); `CW_<KEY>` overrides app key; `CW_SLOT` |

`$config->dbAdmin()` = doadmin (migrations, setup, tests); `$config->dbApp()` = cw_app (the service).
`CW\Db::connect($settings)` gives TLS-only, UTC, READ COMMITTED, strict, native-typed connections.

## Writing a migration

1. Add `migrations/NNNN_short_name.sql` (next number; plain statements separated by `;`, `--`/`#`/block
   comments fine, no `DELIMITER`). Never edit an applied file — the migrator refuses a changed
   checksum; add a new file instead.
2. Run the suite (`bootstrap.php` re-creates the test schema from all files).
3. Apply to staging: `scripts/remote.sh <slot> php bin/migrate.php --db=cw_staging`. This also grants
   `cw_app` its rights on new tables. A new append-only table must be added to
   `CW\Schema\Grants::APPEND_ONLY` in the same change.
4. A migration that rewrites or backfills existing rows gets a test on a scratch schema:
   `[$db, $dir] = MigrationFixture::upTo('m6', '0005_listing_barcodes_index.sql')`, insert the rows as they were,
   `MigrationFixture::migrateRest($db, $dir, '0006_value_core.sql')`, assert, `MigrationFixture::drop('m6', $dir)` in tearDown
   (`tests/Integration/Migration0006Test.php`).
5. A migration that adds a configuration row (a setting, a reason, a document type, a warehouse) also inserts its version 1 into
   `config_change` (`action = 'baseline'`, `actor = 'system:migrate'`, the row's tracked fields as `ConfigHistory::TRACKED` lists
   them; see 0019): the nightly K1–K4 then hold (K4 reports a live row with no history at all), and `TestDb::clean` keeps the row (a
   reason without a baseline is removed as a test's leftover). A migration that UPDATEs a configuration row that has a history writes
   its next version N+1 in the same file (`action = 'change'`, `actor = 'system:migrate'`, a `reason` of 3 or more characters, the
   tracked row before it as `before_state` and after it as `state`; 0019's last statements do it for the reasons of the order screens),
   so K2 holds; `TestDb::clean` keeps every `system:migrate` version.

## The HTTP API on staging (slot `api`)

`GET|POST|PUT http://127.0.0.1:8080/v1/...` **on the staging box only**. Apache listens on
127.0.0.1:8080 and on nothing else for this site. Requests go to the php-fpm pool `cw-api`
(www-data), which serves `/opt/cw-api/public` as `cw_app` against **`cw_test_api`**, the api
slot's test schema (plan §3, §11; decisions A1–A12).

```bash
scripts/remote.sh api bash deploy/staging/install_api.sh     # (re)install pool + vhost; idempotent, self-checks
scripts/remote.sh api vendor/bin/phpunit --filter 'Integration\\Api'   # HTTP tests only
scripts/remote.sh api vendor/bin/phpunit                       # everything (API tests included)
# on the staging box, against the api slot's schema:
KEY=$(php bin/create_channel.php --code=demo --name=Demo --ips=127.0.0.1 --mode=live --db=cw_test_api)
curl -s -H "Authorization: Bearer $KEY" http://127.0.0.1:8080/v1/health
curl -s -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -H 'Idempotency-Key: demo-1' \
     -d '{"order_ref":"1","lines":[{"variant_id":"V1","qty":1,"unit_ids":["u1"]}]}' http://127.0.0.1:8080/v1/reservations
```

- `deploy/staging/install_api.sh` does the following:
  - sets `/etc/cw` to 0750 root:www-data, `app.env` to 0640 root:www-data and `db.env` to 0600 root;
  - adds `db_host`/`db_port` to `app.env`;
  - converges `cw_app`'s grants on `cw_test_api`;
  - installs `deploy/staging/php-fpm-cw-api.conf` and `apache-cw-api.conf`;
  - enables `proxy`, `proxy_fcgi`, `rewrite` and `setenvif`;
  - checks that :8080 is bound to 127.0.0.1 only and that a call without a key gets 401.
- Logs: `/var/log/cw-api/php-error.log` (request ids and failure details) and
  `/var/log/apache2/cw-api.{access,error}.log`.
- `tests/Integration/Api*Test.php` (base `tests/Support/ApiTestCase`) call the real vhost with
  curl. Event times sent over HTTP must be plausible against the server's real clock (R5), so
  these tests build them with `ApiTestCase::ago()`; in-process stock tests pin CW's clock to
  `StockTestCase::NOW` instead. They write fixtures with the admin connection and assert the invariants after every
  test. They are **skipped in every other slot**, because the vhost serves `cw_test_api` only
  (`CW_API_URL` and `CW_API_SCHEMA` override this).
- Channel keys: `bin/create_channel.php` / `bin/rotate_key.php` print a new key once on stdout
  (sha256 stored, never the key). `bin/channel_set.php` changes a channel's mode and/or allowlist
  (dry run unless `--apply`, audited; A14).
- `tests/Integration/ApiKernel/` (base `tests/Support/ApiKernelTestCase`) drive the same kernel
  in-process against the slot's own schema, so the API's rules are also tested in **every** slot
  (A13–A17).

## The staff UI on staging (slot `ui`)

`http://127.0.0.1:8080/ui/...` **on the staging box only**, with `Host: cw-ui.staging.invalid`. It is a
second, name-based vhost on the loopback listener the API vhost opened (every other `Host` still gets the
api slot). Requests go to the php-fpm pool `cw-ui` (www-data, `cw_app`), which serves `/opt/cw-ui/public`
against **`cw_test_ui`**, the ui slot's test schema (`CW_DB_NAME` in the pool). The same front controller
(`public/index.php`) answers `/v1/*` (API kernel) and `/ui/*` (UI kernel).

```bash
scripts/remote.sh ui bash deploy/staging/install_ui.sh                              # (re)install pool + vhost; idempotent, self-checks
scripts/remote.sh ui vendor/bin/phpunit --filter 'UiAuthTest|UiSecurityTest|UiReviewFlowTest'   # the HTTP tests
scripts/remote.sh ui vendor/bin/phpunit                                               # everything (API tests skip here)
scripts/remote.sh ui 'curl -s -i -H "Host: cw-ui.staging.invalid" http://127.0.0.1:8080/ui/login | head -20'
```

- `install_ui.sh` needs the api slot's vhost first (`install_api.sh`): it adds a name to that listener and
  never a second `Listen`. It fixes the `/etc/cw` modes, checks `ui_secret_key` in `app.env`, converges
  `cw_app`'s grants on `cw_test_ui`, installs the pool, the vhost and the hourly log rotation, then checks
  `/ui/assets/app.css` (200), `/ui/login` (200) and `/ui/` without a session (303).
- `tests/Integration/Ui*Test.php` (base `tests/Support/UiTestCase`, client `UiClient`, parser `UiResponse`)
  log in over real HTTP: TOTP codes come from `Totp::code`, staff rows are written with the admin connection,
  the web process runs as `cw_app`. They are **skipped in every other slot** (the vhost serves `cw_test_ui`
  only). A run takes about a minute; `tests/bootstrap.php` re-creates the schema every time.
- Logs: `/var/log/cw-ui/php-error.log` (one line per failure, with the request id shown on the error page) and
  `/var/log/apache2/cw-ui.{access,error}.log`.
- The HTTPS vhost for `warehouse-staging.floverfy.com` is **written but not installed or enabled**
  (`deploy/staging/apache-cw-https.conf`, `apache-cw-acme.conf`, `enable_https.sh`); see `docs/ops.md`.
- In-process screen tests run in **every** slot: `tests/Integration/UiKernel/`, `tests/Integration/Auth/`,
  `tests/Integration/Staff/` extend `tests/Support/KernelUiTestCase`, whose `KernelBrowser` drives the real `CW\Ui\Kernel`
  (routing, sessions, CSRF, roles, controllers, templates) as `cw_app` against the slot's schema, with the same cookie rules
  as `UiClient`. Use them for what the screens show and for auth logic; keep `Ui*Test` for what only Apache + php-fpm prove
  (headers on the wire, the vhost, assets). `LoginLimiterRaceTest` starts parallel sign-ins with `tests/Support/login_race_worker.php`
  (one `cw_app` connection each, at most 10).
- `tests/Unit/UiTemplatesTest` fails the build when a template prints anything that did not go through one of the
  helpers of `View::render` (`$e $n $dec $dt $u $pct $partial` and the plain-words helpers `$word $say $money $day $when $jobs
  $chip $stateChip $intro $explain $cards $empty`; main's `$uk` went with the delivery screens' plain-words pass, U85: `$when` is the
  one UK time), or uses an inline script, style or event handler, or a POST form lacks the
  CSRF field, or (on the pages it lists) a word is typed in the template instead of taken from `Ui\Words`, a list table is
  neither a `table.stack` with `data-label` cells nor inside a `.scroll`, or a date is printed as UTC. Add a helper in
  `View::render` and to that test together; a template variable may not be named like a helper (`View` refuses it).

## Writing for the staff screens (plain words; `docs/decisions.md` U25-U79)

The readers are warehouse and office staff and the owner, whose second language is English, often on a phone. Every word on
a screen comes from **`src/Ui/Words.php`** (constants keyed by the internal code, plus pure helpers such as `say()`,
`saleUses()`, `whoCan()`, `noteRequired()`); templates print them with `$word` / `$say`, controllers use the constants.
`tests/Unit/WordsTest.php` fails on a code of the system without a word and on a word that breaks rules 2, 10 and 12 below.
The rules (plan `/root/cw_work/ui_clarity/plan.md` §7):

1. Short sentences: about 15 words or fewer, one idea each.
2. Glossary words only. Never show a code: no table, column, enum or role code, file path, server command, decision number
   or phase code. Machine detail goes in a folded "Technical details".
3. A code staff must see (CW-000123, PO-000123, a VAT letter) is explained the first time it appears on the page.
4. Every page starts with one sentence saying what it is for and what to do (`Words::PAGE_INTRO`, `$intro`); a page where the
   person can only look says so ("You can look; … change this.").
5. Buttons are verbs that name the result ("Confirm order", "Save and open the next one"); never "Submit" or "OK". A button
   that cannot be undone, or that has a big effect, says the effect ("Yes, it is not a match: stop the bulk link").
6. After every click, say what happened and what comes next, naming the thing. A notice shown on the next item says so.
7. Errors say what went wrong, whether anything was saved, and what to do; a full sentence next to the field, with what the
   person typed kept.
8. Empty states say why the page is empty and what to do ("your filter hides everything" with Clear, versus "nothing is
   waiting" with the next list).
9. Numbers carry a unit or a meaning; money is £ with 2 decimals and thousands separators; never ∞, a bare "-" or "0.0%".
10. Dates in UK time ("7 Oct 2026, 10:26", `$when` / `$day`), never "UTC"; people by name, "set up by CW" for system rows.
11. Jobs, not role codes ("Reviewers can."); never "Your role (buyer) cannot …".
12. Name who to ask (`Words::ASK`, "Fazil"); never tell staff to run a command or read a docs file.
13. One name for one thing across the menu, title, crumbs, buttons, notices and errors. "Count" means a shelf count only
    (owner's correction b, 7 Oct 2026).
14. Phone first: no sideways scroll at 375 px; a list table is a `table.stack` with `data-label` (one card per row at 640 px of
    page or less) inside a `.table-wrap` (it scrolls in its own box on a wider screen), any other table sits in a `.scroll`;
    inputs 16 px or more; tap targets 44 px or more; the main answer reachable without scrolling past all the evidence.
15. Badges count only what this person can act on.
16. Fold rare or technical detail into "Technical details".
17. Keep the CSP: one stylesheet and `app.js` only, no inline script or style, no `data:` URIs, no SVG, no external asset. Help
    is a `<details class="help">` (`$explain`), a confirmation is a second step (`<details>`), a tick-box or a second button.
    Words `app.js` shows come from the page (`data-` attributes filled from `Words`).
18. Never rewrite a service (`CwException`) message for the screen: the JSON API returns them. Translate by error code (and
    its `detail`) in the UI layer: `Words::ERROR`, a controller's `plain()`; a code without words shows the service's message
    as a sentence followed by "Nothing was saved.". `Document::label()` stays (file names, service messages).
19. Tests assert words through the `Words` constants, not copied strings, so a wording change edits one place.
20. No hard-coding (the owner's rule of 8 Oct 2026, `docs/decisions.md` Y1-Y38): a number, limit, list or rule the owner may
    want to change is a setting (`Settings::RULES` gives its allowed values), a reason, a document rule or an approval switch
    (`Admin\ApprovalRules`), read where it is used, never a constant; it is changed on its page with a reason (the
    `config_history` partial shows its versions) and a form carries the version it was drawn with (`seen`). The words never
    repeat the number ("a spot check of the size on the Approval rules page", not "20").

Words that are provisional until the owner confirms them (band names, "website product" / "warehouse product", "match",
"Matching lead", "second OK", the staging strip, "Coming later" without dates, the behaviour items) are listed in
`docs/decisions.md` U70. Change them in `Words` only.

## Staff accounts, signing in, the public HTTPS vhost

- **Create a person** (on the staging box, in `/opt/cw-staging`, as root; `--db=cw_test_ui --admin` for the test copy):
  `php bin/create_staff.php --email=<address> --roles=<role>[,<role>...] --name="Name"` (I15; `--role=<one>` is an alias,
  never both). Roles: viewer, mapper, mapping_lead, warehouse, manager, admin, buyer, purchasing_manager, goods_in,
  purchasing_desk, stock_controller, reviewer, accountant, auditor (`CW\Auth\Permissions`); admin only with viewer,
  accountant, auditor (I12).
  It prints `password=` (one-time) and `otpauth=` (the TOTP seed as a URI) ONCE on stdout; hand them over on different
  channels. Stored: argon2id hash, `password_must_change = 1`, the seed sealed with `ui_secret_key` (app.env).
- **Recover a person**: `php bin/reset_staff.php --email=<address> [--new-password] [--new-totp] [--deactivate | --activate] [--roles=<list>]`
  (never re-create or delete an account; decisions name it). New secrets are printed once; every reset ends the person's sessions.
  `--roles=a,b` replaces the person's roles (stderr `roles: x -> a,b`, audited `staff.roles`; the break-glass when no admin can
  sign in); on its own it keeps their sessions, the new roles apply on their next request. Day to day an admin changes roles and
  switches accounts off on `/ui/people` (I13).
- **On the screens (Y20-Y22, Y40-Y44)**: an admin adds a person (a sign-up sheet: QR code + one-time set-up code), makes a new sign-up
  sheet, a new sign-in code or lets someone choose a new password on Staff → Staff and access. A sheet's secret works once (at /ui/enrol
  with the set-up code, or at /ui/login with the person's own password for a new sign-in code) and leads to `/ui/new-code`, the person's
  own page with a fresh secret confirmed before any session (`Staff\Enrolment`, cookie `cw_setup`); `staff_user.totp_state` says whose
  the secret is. The two resets refuse each other while the other is open (`ck_staff_user_one_factor`), so an admin never holds both
  factors of anybody. The server's tools above stay the break-glass (their secrets are the person's own: `own`).
- **Sign in**: `/ui/login` takes e-mail, password and the current 6-digit code together; the first sign-in forces
  `/ui/password`. Test copy (slot `ui`, schema `cw_test_ui`): `http://127.0.0.1:8080/ui/login` with
  `Host: cw-ui.staging.invalid`, on the staging box only (e.g. through an SSH tunnel: `ssh -L 8080:127.0.0.1:8080 ...` and a
  browser extension or `curl -H 'Host: cw-ui.staging.invalid'`). Real staging data (`cw_staging`) is served only by the public vhost.
- **Enable the public HTTPS vhost once the DNS record exists** (`docs/ops.md`, "Switching on the public HTTPS vhost"):
  1. DNS: `warehouse-staging.floverfy.com A 46.101.55.135`, DNS only (not proxied).
  2. `scripts/remote.sh <slot> bash deploy/staging/install_cron.sh` (code into `/opt/cw-staging`) and `install_ui.sh` once.
  3. Shred `/etc/cw/initial_staff.txt` after handing the credentials over, and deactivate the placeholder mapping_lead
     (`bin/reset_staff.php --deactivate`); create real staff (at least two who can approve).
  4. `scripts/remote.sh <slot> bash deploy/staging/enable_https.sh --check`, then `... enable_https.sh --email <address>`.
     It refuses, changing nothing, until the name resolves only to this box, the code and `cw-ui` are there, `app.env` names
     `cw_staging`, the credentials file is gone and no `.invalid` account is active.
  5. Open TCP 80/443 in the cloud firewall if one is attached; staff then sign in at `https://warehouse-staging.floverfy.com/ui/login`.

## Profiling the staff screens (`tests/perf/profile_pages.php`)

A dev-only tool, run on a COPY of `cw_staging` (it refuses any schema but `cw_test_*`, and writes to the copy: the app login's
grants, one session per profile, the profile accounts it lacks, the purchasing fixtures and what the actions do). It drives
`CW\Ui\Kernel` in-process with a session written straight into the copy (no web sign-in), times every GET page each profile may
open (realistic ids: the first row of each list) and the common actions (POST, then the page its 303 leads to; inside a rolled-back
transaction, except the creating forms, which own theirs), with every SQL statement's time and rows (a PDO statement class).

```bash
# on the staging box: the copy (mysqldump | mysql, ~2 min; wait until no phpunit runs: the cluster is small), then 0019+ on it
mysqldump --defaults-extra-file=<a 0600 file written from db.env> --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF \
    --hex-blob cw_staging | mysql --defaults-extra-file=<same> cw_test_perf          # after CREATE DATABASE cw_test_perf
scripts/remote.sh perf php bin/migrate.php --db=cw_test_perf
scripts/remote.sh perf php -d opcache.enable_cli=1 tests/perf/profile_pages.php --db=cw_test_perf --repeat=5 --fixed --explain=25
#   --pages / --actions (default both), --profiles=owner,admin,buyer,lead,goods_in, --only=REGEX, --json=FILE, --queries (every statement)
# drop the copy afterwards (DROP DATABASE cw_test_perf), and never name a slot "perf" for a PHPUnit run while the copy exists
# (tests/bootstrap.php would drop and re-create cw_test_perf)
```

Each request runs once to warm up, then `--repeat` times: the table gives the median and the worst, the queries, their time and the
page size; then, per row, the 3 slowest statements and any statement repeated 3 or more times (an N+1), and with `--explain=N` the
EXPLAIN ANALYZE of the N slowest SELECTs. `--fixed` times what every request pays before the page: the TCP connect, the TLS + login
handshake, `Db::connect`, one round trip. Numbers move with the cluster's load (its buffer pool is 32 MB: a page's first read after
another big page comes from disk), so compare runs made back to back, with no PHPUnit suite running.

## Where things are

| Path | What |
|---|---|
| `src/Config.php`, `src/DbSettings.php` | settings, env-file parser |
| `src/Db.php` | PDO factory, fetch helpers, `transaction()` with deadlock retry |
| `src/Schema/` | `Migrator`, `SqlSplitter`, `Grants` |
| `migrations/0001_core.sql` | every Part 1 table of plan §2.2 (matching tables come from the matching workstream) |
| `migrations/0002_stock_core.sql` | idempotency scope (channel or source), ledger/feed indexes, `feed_clock` (D29, D38, D39) |
| `migrations/0003_review_fixes.sql` | `channel_opening` (T0 watermarks + opening marker, off the channel row), `channel.movement_types` (R1, R12, R17) |
| `src/Stock.php` | the only writer of `stock_balance` / `stock_ledger` / `stock_change`: `lock()` → `apply()` → `flush()`; policy + warehouse assignment |
| `src/Reservations.php` | reserve / commit / release / cancel / uncancel (D46) / ship / unship / return / opening orders / `expireDue()` |
| `src/Movements.php` | goods_in, supplier_return, erp_sale, adjustment, count, write_off, transfer_out/in |
| `src/Availability.php` | per-listing views, `changes()` feed, `snapshot()` paging |
| `src/Idempotency.php`, `Caller.php`, `OpResult.php`, `CwException.php` | one effect per key (D27–D29) |
| `src/Invariants.php` | the nightly bucket check (D44; 10–11: the units' moves through VERIFY, D46), asserted after every stock test |
| `tests/Integration/Stock/` | stock-core tests (§14 CW automated, minus HTTP; `UncancelTest` / `UncancelInvariantsTest`: D46); `HammerTest` uses `tests/Support/WorkerPool` (≤ 12 connections); `LockOrderTest` / `LinkAdoptionTest` force interleavings with `tests/Support/OpWorkers` + `op_worker.php` (waits on `performance_schema.data_locks`) |
| `bin/migrate.php`, `bin/setup_staging.php` | CLI |
| `public/index.php`, `src/Api/` | the /v1 HTTP API: `Kernel` (pipeline + error mapping), `Router`, `Auth` + `IpAllowlist` + `ApiKey`, `Request`/`Response` (envelope), `Context`, `Input`, `Controller/*` (one per resource) |
| `src/Heartbeat.php`, `src/Purchasing.php`, `src/ChannelAdmin.php` | `POST /v1/heartbeat`, `GET /v1/purchasing`, channel creation / key rotation / mode and allowlist |
| `migrations/0004_matching.sql` | matching runs/proposals/decisions, link history, rejects, aliases, staff roles, sessions, login attempts (M1, M2) |
| `src/Mapping/DecisionService.php` | the only writer of a listing's link: link / unlink / new_item / ignore / reject / suggest / merge_skus (with the merged item's stock, M31-M32) / split (the undo of a merge, M33), a duplicate group's decisions in one transaction (`decideGroup`, M34), two-person approve / withdraw, the seed `mintAndLink`, the listing-row insert helper (M3–M10) |
| `src/Mapping/ListingIngestService.php`, `src/Mapping/Proposals.php` | `PUT /v1/listings` + the import tool (M11); match runs and proposals (M13) |
| `src/Staff/` | `StaffAdmin` (create, reset), `Totp`, `SecretBox` (TOTP secret at rest, `ui_secret_key`), `AppEnvFile` (M2, M15, U23) |
| `src/Mapping/BarcodeSeeder.php`, `bin/seed_barcodes.php` | `sku_barcode` from the listings the items were minted from (M25) |
| `migrations/0005_listing_barcodes_index.sql` | multi-valued index on `listing_profile.barcodes` (where else a barcode is, M25) |
| `bin/import_listings.php`, `bin/mint_vpg.php`, `bin/import_proposals.php`, `bin/create_staff.php`, `bin/reset_staff.php` | first-match load and staff accounts (`docs/ops.md`, M14, M15, U23) |
| `bin/reband_proposals.php`, `bin/sample_proposals.php`, `bin/key_bulk_hold.php`, `bin/bulk_confirm_key.php`, `bin/bulk_unlink.php` | Key spot-check, the hold of screened listings, and the bulk confirm (`docs/ops.md`, M26-M28, M30) |
| `src/Matching/`, `tools/first_match/`, `tests/matching/run.php`, `tests/fixtures/matching/golden_listings.json` | the matching engine (Normalizer, TitlePattern, Flavour, Veto, Candidates, Band, JudgeCard; `Form` = the one form enum, `JudgeScratch` = one scratch directory per judge chunk, M29) and the first-match tools; the plain golden runner (`php tests/matching/run.php`, no Composer) also runs inside the suite as `tests/Unit/MatchingGoldenTest.php`; regenerate the fixture with `tools/first_match/extract_fixture.php` |
| `migrations/0012_key_bulk.sql`, `migrations/0014_key_bulk_hold.sql`, `src/Matching/StoredBand.php`, `src/Mapping/{ProposalBasis,KeyEligibility,Reband,KeySample,KeyHold,KeyBulk}.php`, `src/Ui/Controller/SamplesController.php` | proposal bases, the replay of a stored band, the re-banding, the spot-check sample and its screen, the hold of a listing from every bulk confirm (by the listing, M30), the bulk confirm and its undo (M26-M28) |
| `tests/Integration/Mapping/`, `tests/Support/MappingTestCase.php`, `tests/fixtures/mapping/` | DecisionService rules, listing intake, the tools end to end on small fixtures |
| `migrations/0015_duplicates.sql`, `src/Ui/Duplicates.php`, `src/Ui/Controller/DuplicatesController.php`, `src/Ui/views/{duplicates,duplicate_group}.php` | Vape and Go's duplicate listings (M31-M36, M39-M44): the `split` action (a split back undoes the whole merge, M40), the Duplicates screen's read side (groups, side-by-side listings, what the rules say against each page, `verdicts()`, the field comparison, the suggested keeper, decided groups, a listing's groups) and its controller; the rebase's handling of merged stock is in `src/Ops/OpeningRebase.php` (M35, M42) |
| `tools/vpg_duplicates/{export,sweep}.php`, `src/Matching/{DuplicateSweep,DuplicateSweepRun}.php`, `bin/import_vpg_duplicates.php` | the wider duplicate sweep (M37): the read-only export of a site's mapped listings, the rules (`DuplicateSweep::judge`: vetoes both ways plus the same-site rules), one run (candidates, exclusions, clique groups, keeper), the importer of its groups as `vpg_duplicate` suggestions (dry run unless `--apply`). Tests: `tests/Unit/DuplicateSweep{,Run}Test.php`, `tests/Integration/Mapping/ImportVpgDuplicatesTest.php` |
| `tests/Integration/Mapping/DuplicateMergeTest.php`, `tests/Integration/UiKernel/DuplicatesScreenTest.php` | merges and their stock (in-flight units, value seqs, feed, reorder demand), the two-person cases, a group in one transaction, keep separate, the split; the screen (list, group, merge, keep separate, a choice per listing, stale form, counted item, roles, undo). The rebase after a merge: `OpeningRebaseTest::testAMergeBetweenTheEstimateAndT0RebasesTheKeptItemOnBothListings`; hostile text on both screens: `UiSecurityTest` (slot `ui`) |
| `bin/create_channel.php`, `bin/rotate_key.php`, `bin/channel_set.php` | channel, key, mode and allowlist tools (connect as `cw_app`; A11, A14) |
| `src/Auth/` | staff sign-in for the UI: `Login` (password + TOTP), `LoginLimiter`, `Sessions` (`staff_session`, hashed ids), `Csrf`, `StaffIdentity` (M2, U1-U4) |
| `src/Ui/` | the staff screens: `Kernel` (route, session, role, same-origin + CSRF, hardened headers), `Router`/`Route`, `Context`, `UiRequest`, `HtmlResponse`, `Html` (escaping, chips, help, money and UK time), `View` (templates and their helpers), `Words` (every word on a screen, U25), `Sections` (THE navigation map: seven sidebar sections, their tabs and segments, each page with its route's permission; `locate()` finds a request's section, tab and page; `frame()` gives the layout the sidebar, page bar, segments, create button and phone bar; U95-U99), `FlowCounts` (Purchasing's flow strip), `StockViews` + `Controller/StockController` (Stock › Overview and Movements, U101), `HomeTasks` + `HomeCounts` (Home's "What needs doing" cards and their counts), `PoWarnings`, `ReorderWhy`, `Queries` (read side), `QueueContext`, `Compare`, `Assets`; `Controller/*Controller`; templates in `src/Ui/views/`; the two static files in `public/ui/assets/` and the five self-hosted fonts in `public/ui/assets/fonts/` (SIL OFL, licence texts beside them; served by `Assets` with `font/woff2`, kept a year: U103). The look is design v4 (U103): every colour, size and font is a token at the top of `app.css` |
| `tests/Integration/Ui*Test.php`, `tests/Support/Ui{TestCase,Client,Response}.php` | the UI over HTTP (slot `ui` only); `tests/Unit/Ui{Unit,Templates}Test.php` need no server |
| `tests/Integration/{UiKernel,Auth,Staff}/`, `tests/Support/{KernelUiTestCase,KernelBrowser}.php`, `login_race_worker.php` | the UI and sign-in in-process as `cw_app` (every slot): approval screens, review evidence, password change, limiter race, staff reset |
| `deploy/staging/*-ui.conf`, `install_ui.sh` | the loopback UI vhost + pool for slot `ui` |
| `deploy/staging/apache-cw-https.conf`, `apache-cw-acme.conf`, `apache-cw-hardening.conf`, `php-fpm-cw-web.conf`, `logrotate-cw-web.conf`, `enable_https.sh` | the public HTTPS vhost (UI + API) for `warehouse-staging.floverfy.com`: NOT enabled (`docs/ops.md`) |
| `deploy/staging/` | php-fpm pool, Apache vhost, `install_api.sh` |
| `tests/Integration/Api*Test.php`, `tests/Support/ApiTestCase.php` | HTTP tests (slot `api` only) |
| `tests/Integration/ApiKernel/`, `tests/Support/ApiKernelTestCase.php` | the /v1 kernel in-process (every slot): mode header, feed head, heartbeat key, extended holds, the uncancel route |
| `tests/concurrency/hammer.php` | the §14 concurrency hammer: forked workers (≤ 30 connections), 4 scenarios, PASS/FAIL table (`docs/ops.md`) |
| `tests/Integration/Stock/IdempotencyRaceTest.php` | same-key races (H1); `tests/Integration/Ops/` — pruner, the bin/ jobs end to end |
| `src/Ops/` | `Cli` (shared job frame, H3), `Snapshot` (consistent read, H5), `ChangePruner` (H2), `ChannelHealth` (H8), `OpeningEstimate` + `OpeningRebase` (a site's opening and its rebase at T0, D40a, D40b), `TestRefPurge` (staging test leftovers, D47) |
| `bin/import_opening_estimate.php`, `bin/purge_test_refs.php` | the opening estimate and, with `--rebase`, its rebase at T0 (D40a, D40b); removing a test's reservations from a staging channel (D47; refuses unless app.env says `environment=staging`, and on a `live` channel) |
| `tests/Integration/Stock/Opening{Estimate,Rebase}Test.php`, `tests/Integration/Ops/PurgeTestRefsTest.php` | the estimate; the rebase proof (estimate → opening_orders → ships → rebase), its skips and the connector's T0 file through the CLI; the purge in-process, as `cw_app` and through the CLI |
| `bin/expire_reservations.php`, `bin/prune_changes.php`, `bin/invariants.php`, `bin/health_alert.php` | scheduled jobs (cron on staging: `docs/ops.md`) |
| `deploy/staging/install_cron.sh`, `cw-staging.cron`, `logrotate-cw.conf` | staging cron install (`/opt/cw-staging`, `/etc/cron.d/cw-staging`) |
| `scripts/remote.sh` | sync + run on staging |
| `docs/decisions.md` | choices made where the plan is silent |
| `docs/ops.md` | runbook: scheduled jobs, staging cron, the hammer |
| `migrations/0006_value_core.sql` | C0: cost (`unit_cost`, `cost_currency`, `cost_source`) and document link (`document_id`, `document_line`) on `stock_ledger`; `stock_value_clock`, `stock_value_seq` (backfilled), `stock_value_ledger` (empty, IM8's) (I1–I5) |
| `src/Stock.php` `assignValueSeq()` | the per-item value sequence: the first step of `flush()` numbers every on_hand row of the operation under the item clocks (sku_id order, before the feed clock); `lock()` refuses while rows wait for their seq (I3, I7), and once the transaction took value clocks or the feed clock through any `Stock` on the connection (I29: one balance-lock phase per transaction) |
| `src/Movements.php` `bookForDocument()`, `reverseDocument()` | a document posting's stock movements inside the posting's transaction (one `lock()`, one `flush()`), and their exact negation; `normaliseCost()` (I1, I2, I7) |
| `tests/Support/MigrationFixture.php` | a scratch schema `cw_test_<slot>_<suffix>` migrated up to a given file, to test what a later migration does to existing rows (`Migration0006Test`) |
| `migrations/0007_staff_roles.sql` | `staff_role` (several roles per person, revoked never deleted, one live grant per role), backfilled from `staff_user.role`, which is dropped (I10) |
| `src/Auth/Permissions.php` | THE permission map: the 14 roles, `MAP` (permission → roles), `can()`, `checkRoleSet()` (admin only with viewer/accountant/auditor), `COMING_LATER`; routes name a permission (I11, I12, I14, I16); the navigation built on it is `Ui\Sections` (U95) |
| `src/Staff/StaffRoles.php`, `StaffAdmin::setRoles`/`setActive` | read side of `staff_role` (live roles, history, counts) and the role/account changes of the People screen and `reset_staff --roles` (I13) |
| `src/Ui/Controller/PeopleController.php`, `views/{people,person,home}.php` | `/ui/people` (list, person page, role form, switch on/off) and the home page of roles without the linking screens (I13, I14) |
| `tests/Unit/PermissionsTest.php`, `tests/Integration/UiKernel/{Menus,PeopleScreen}Test.php`, `tests/Integration/Staff/StaffRolesTest.php`, `Migration0007Test` | the map, the owner's menu acceptance test (`tests/Unit/SectionsTest.php` on the map, `MenusTest` on the pages), the People screen, `StaffAdmin` + CLI, the 0007 backfill; `KernelUiTestCase::nav()` reads a page's sidebar (name => link and count), `sectionTabs()` / `tabLabels()` / `currentTab()` its tab bar, `segments()` its segmented filter, `currentSection()` the marked section, `tabs()` the phone bar; `uiUser()`/`staffUser()` take one role or a list |
| `migrations/0008_documents.sql` | the document base: `reason_code` (22, read-only), `document_type` (8, read-only; review and approval rules), `number_series` (one per prefix, gapless), `document` (header, status, version, `posted_hash`, `reverses_id`), `document_line`, `review_task` (reviews and blocking approvals, no FKs), `stored_file` + `document_file` (append-only) (I17–I23) |
| `src/Documents/` | `Documents` (drafts, post, reverse, approve, reject, withdraw; the lock order of I21), `Document` (a row), `DocumentHandler` (one per type) + `DocumentHandlers` (the registry: empty in I-1, I27), `NumberSeries` (I20), `DocumentInvariants` (D1–D7, called by `Invariants::check`) |
| `src/Files/` | `FileStorage` (no delete/overwrite), `LocalFileStorage` (content-addressed, write-once, staging), `FileStore` (MIME sniffing, 25 MiB, dedupe, 7-year retention, verify on read, attach), configured by app.env `file_store_dir` / env `CW_FILE_STORE_DIR` (I23) |
| `src/Output/` | `PdfWriter` on `Fpdf` (setasign/fpdf 1.8.2, core fonts, Windows-1252: I24), `CsvWriter` (BOM, CRLF, formula-injection safe: I25) |
| `src/Ui/Controller/{Documents,Reviews,Reference,Files}Controller.php`, `views/{documents,document,reviews,reasons,series}.php` | `/ui/documents` (list, page, PDF, reverse), `/ui/documents/reviews` (queue, approve, reject), `/ui/reference/{reasons,reasons.csv,series}`, `/ui/files/{id}`, `/ui/people.csv`; `FilesController::download` is the one way a download leaves the screens (attachment + CSP sandbox) |
| `bin/store_file.php`, `bin/verify_files.php`, `deploy/staging/install_file_store.sh`, `deploy/staging/seal_file_store.sh` | the file store's CLI (the only way files arrive in I-1), its staging install and the root sweep that makes stored files immutable (cron every minute, I36; neither run yet: `docs/ops.md`); `tests/Integration/Files/SealFileStoreTest.php` runs the sweep on a scratch directory under `/var/tmp` (root + chattr, else skipped) |
| `document_posting` (0008) | the write-once record of every posting (hash, poster, time, the canonical content): DocumentInvariants D7 checks every posted document against it; tests that write posted documents by SQL must add one (`FixtureDocuments::posted` does, I33) |
| `tests/Support/Documents/FixtureAdjustmentHandler.php` | a TEST-ONLY ADJ type (one signed adjustment per line): `KernelUiTestCase::kernel()` and the document tests register it; production registers none (I27) |
| `tests/Support/Documents/{FixtureDocuments,DocWorkerPool}.php`, `tests/Support/doc_worker.php` | posted document rows for tests that book with fixed document ids; parallel workers (≤ 12) for the number-series and posting races |
| `tests/Integration/Documents/`, `tests/Integration/Files/`, `tests/Integration/UiKernel/{ReviewScreens,ReferenceScreens,Downloads}Test.php`, `Migration0008Test` | the document base (lifecycle, review rules, invariants, races), the file store and its tools (temp directories via `CW_FILE_STORE_DIR`), the screens and downloads |
| `migrations/0009_suppliers.sql` | Phase I-2 suppliers task: `app_setting` (12 typed settings, read-only for cw_app), `vat_code` (6, read-only), `supplier` (no bank columns), `supplier_item` (pack, MOQ, preferred: generated `preferred_sku_id` + UNIQUE, last price), `supplier_item_price` (append-only history), `import_run` (ERPNext seed files) (I38–I47) |
| `src/Settings.php`, `bin/settings.php` | typed settings (`get`, `company` (from `company_profile` since 0013), `all`, `parse`, `RULES`, `set`); the CLI lists them (app login) and changes one with `--admin` only (audit `setting.change`; a `company.*` key is refused: I91) (I38) |
| `src/Suppliers/Suppliers.php` | the supplier record, its state machine (draft → pending_approval → active → inactive), the blocking activation / import-route approvals and the non-blocking change review (`review_task` subject `supplier`), `refusal()` (who may decide), `decidableCount()` (badge), `missing()` (completeness), `checkOverseas()` (a non-GB supplier is overseas); every route / overseas change of an active supplier is a blocking approval (I40, I42, I72) |
| `src/Suppliers/SupplierItems.php` | supplier items (pack → central units, MOQ, multiple, lead, preferred; `is_preferred = 'auto'` on create: I75), manual and import prices, the last-price rule (per pack size: I74), integer half-up unit prices (I43, I44) |
| `src/Suppliers/SupplierInvariants.php` | S1–S5, called by `Invariants::check` (I42) |
| `src/Suppliers/ErpSeedImport.php`, `bin/import_erp_suppliers.php` | the ERPNext supplier / supplier-item seed from CSV exports of the BACKUP copy: one `import_run` per file, savepoint per row, dry run, report CSV (I45; formats in `docs/ops.md`) |
| `src/Output/CsvReader.php` | reading people's CSV: gzip, BOM, Windows-1252 fallback, delimiter detection, normalised headers, row/byte caps (I45) |
| `src/Ui/FormOnce.php` | one effect per creating form: `form_key` → `Idempotency::run` under `ui:<staff id>:<form_key>` (I46) |
| `src/Ui/Controller/{Suppliers,SupplierItems}Controller.php`, `views/{suppliers,supplier,supplier_form,supplier_items,supplier_item,supplier_item_form,settings}.php` | `/ui/purchasing/suppliers…`, `/ui/purchasing/supplier-items/{id}…`, `/ui/reference/settings`; supplier tasks in `/ui/documents/reviews`; the Suppliers panel of `/ui/items/{id}` (I40, I46) |
| `tests/Integration/Suppliers/`, `tests/Integration/UiKernel/SupplierScreensTest.php`, `Migration0009Test`, `SettingsTest`, `tests/Unit/{CsvReader,SettingsParse}Test.php` | suppliers (lifecycle, items, invariants, ERP import end to end through the CLI, races with `supplier_worker.php`: two processes, one connection each), the screens, the schema, settings and the CSV reader |
| `migrations/0010_purchase_orders.sql` | Phase I-2 pos task: the PO `document_type` row (review `all` due 7, approval `over_value` 10000 whole GBP, `reject_action` `record`), `document_type.reject_action`, 3 PO reversal reasons, `po.*` settings, `purchase_order` (header extension: supplier, state after approval, totals, company + supplier snapshots, sent / closed), `po_line` (line extension, cascades with its draft line), `po_posting` (write-once anchor of the approved content) (I48–I59) |
| `src/PurchaseOrders/PurchaseOrderHandler.php` | the PO `DocumentHandler` (registered in `DocumentHandlers::all`): validate (active supplier FOR SHARE, approved import route and no open route approval (I72), sellable warehouse, the line formulas, VAT codes), approvalUnits = ceil(net), post (totals, snapshots, `po_posting`, `po` price history, `last_po_*`), reverse (refused with receipts; puts `last_po_*` back to the newest standing PO: I81); no stock (I50) |
| `src/PurchaseOrders/PurchaseOrders.php` | the PO service: createDraft, saveDraft, addLine (scan resolution), addSupplierItem, importLines, approve, markSent (PDF archived when a file store is given; `sendWarnings` need acknowledging: I86), cancel, withdraw, amend, copy, close, openLines / applyReceipt / reverseReceipt (for I-3), onOrder / inDrafts (for the reorder list), warnings, pdfData, exportRows (I52) |
| `src/PurchaseOrders/{PoMath,PurchaseInvariants,PoLinesFile,PurchaseOrderPdf,ErpOpenPoImport}.php` | integer money (half-up line amount, 6-decimal unit cost, per-line VAT, ceil approval units: I51); P1–P6 (called by `Invariants::check`); the lines file (CSV / XLSX export, all-or-nothing import rules: I58); the PO PDF on `Fpdf` (letterhead, banners, repeated table header: I52); the ERPNext open-PO import (I56) |
| `src/Output/{XlsxWriter,XlsxReader}.php` | XLSX through openspout ^4.32 (MIT): text cells always `StringCell` (never a formula), the reader's zip-bomb guard (16 MiB a part), the streaming 1,000-cells-a-row pre-scan and row / column caps (I58, I83); `Fpdf` takes an optional footer text |
| `bin/document_rules.php`, `bin/import_erp_open_pos.php` | document_type rule changes (`--admin`, audited `document_type.change`: I57); the ERPNext open-PO import (I56); both in `docs/ops.md` |
| `src/Ui/Controller/PurchaseOrdersController.php`, `views/{purchase_orders,purchase_order,purchase_order_edit}.php` | `/ui/purchasing/orders…`: list + CSV + new-order form, the one-form editor (version / line_count / lines_editable first; editable while `editorFields()` < max_input_vars; "Save and approve" in the same form: I73, I87), the order's view with its actions and the decide box, lines CSV / XLSX, the PDF; `UiRequest::fieldsMatching` reads the editor's rows (I53) |
| `tests/Integration/PurchaseOrders/`, `tests/Integration/UiKernel/PurchaseOrderScreensTest.php`, `Migration0010Test`, `DocumentRulesCliTest`, `tests/Unit/{PoMath,PoLinesFile,XlsxRoundTrip,PurchaseOrderPdf}Test.php` | POs: lifecycle, the receipt API, invariants P1–P6, races (`po_worker.php`: two processes), the ERPNext open-PO import end to end through the CLI (synthetic files), the screens, the schema, the rules CLI, money, the lines file, XLSX and the PDF |
| `migrations/0011_reorder.sql` | Phase I-2 reorder task: `sales_import_batch` (one per loaded export: files, sha256, range, unknown / unlinked counts; never deleted), `sales_history_day` (daily sales per site variant, mapped to items at read time), `channel_snapshot_day`, `listing_stock_day` (unsellable days), `listing_stock_latest` (the site's last snapshot), `item_reorder`, `reorder_brand`, `demand_anomaly` (seeded 14–22 Sep 2026; never deleted), `reorder_demand`, the 14 `reorder.*` settings (I60–I71) |
| `tools/sales_history/export.php` | the read-only sales export from the live sites, run on the Vape and Go box (standalone `mysqli`, no CW code): V1/O1/O2/S1–S3, READ ONLY per 7-day slice, the EXPLAIN gate (exit 3), the Threads_running guard, files only under `CW_SALES_EXPORT_ROOT`; stock days of every variant sold in the 365 days to `--to` (a lookback pass for shorter windows, tool 1.1) (I60, I76; `docs/ops.md`) |
| `src/Reorder/SalesHistoryImport.php`, `bin/import_sales_history.php` | loads an export: manifest and sha256 checks, the coverage rules (overlap replaces, gap refused), one transaction per 7-day slice, the mapping report (not `mapped` = unlinked), the latest site stock only when newer, the month and anomaly-uplift report (I61, I77) |
| `src/Reorder/{DemandMath,PromoDetector}.php` | pure: one listing's demand (exclusions, spike cap, 28/91-day blend, fallbacks; e4 integers) and the promotion days of a brand from revenue per unit (bcmath) (I62, I63) |
| `src/Reorder/DemandBuilder.php`, `bin/reorder_demand.php` | rebuilds `reorder_demand` (GET_LOCK, chunks of 1,000 items, one write transaction) and an item's day-by-day view; no cron (I62) |
| `src/Reorder/{ReorderMath,ReorderList,Explain,ReorderSettings,DraftPos}.php` | the reorder line (target, ROP, need, packs), the list (filters, CW or usable site stock with the `site_stock_unreliable` flag, on order / in drafts from `PurchaseOrders`; ROP capped by max stock), the "Why", item / brand settings and anomaly windows, "create draft PO" (one per preferred supplier) (I64–I67) |
| `src/Purchasing.php` `stockOf()` | CW's sellable stock of an arbitrary set of items (the reorder list) |
| `src/Ui/Controller/{Reorder,SalesHistory}Controller.php`, `views/{reorder,reorder_item,reorder_brands,reorder_anomalies,sales_history}.php` | `/ui/purchasing/reorder…` (list, CSV, draft — lines already in drafts not pre-ticked —, recalculate up to `UI_REBUILD_MAX_ITEMS` linked items, item, brands, anomalies; I79, I84) and `/ui/purchasing/sales-history` (+ `unlinked.csv`); live menu items (I68) |
| `tests/Integration/Reorder/`, `tests/Integration/UiKernel/{ReorderScreens,SalesHistoryScreen}Test.php`, `Migration0011Test`, `tests/Unit/{DemandMath,PromoDetector,ReorderMath,Explain}Test.php` | the export against a fake site schema (`cw_test_<slot>_site`), the import through the CLI, the demand build, the list against real POs, the drafts, the screens; `ReorderFixtures` (trait) / `ReorderTestCase`; opt-in: `RealDataRehearsalTest` (`CW_REHEARSAL_DIR`), `DemandBuilderTest::testTheBuildAtScale` (`CW_REORDER_PERF=1`) |
| `migrations/0013_company_profile.sql` | the company details printed on POs: `company_profile` (one row per version, append-only for cw_app; `baseline_version` on a confirmation), version 1 copied (tidied) from the nine `company.*` settings, which are then deleted from `app_setting`; `review_task.subject_type` + `company` (I90–I99) |
| `src/Company/CompanyDetails.php` | the company details: `current()`, `company()` (what a PO prints; `Settings::company()` returns it), `check()` / `tidy()` / `unsaved()` (validation and normalisation), `missing()`, `problems()`, `save()` / `confirm()` (version-checked, the PRIMARY KEY as the lock; the check of a person's confirmation of their own watched change against the baseline: `involved()`, I94), `decideReview()`, `refusal()`, `decidableCount()`, `reviews()`, rejected changes (`rejectedIn()`, `ordersWithRejectedDetails()`, I98), `history()`, `changesSince()`, `watched()`, `formatVat()`, `vatChecksumOk()`, `unprintable()` (Windows-1252) |
| `src/Company/CompanyInvariants.php` | C1–C4, called by `Invariants::check`: versions 1..n, a confirmation changes nothing, one audit row per version, the right roles at the time, checks decided by nobody involved (I91) |
| `src/Ui/Controller/CompanyController.php`, `views/{company,company_form}.php` | `/ui/reference/company` (details, status, problems, checks, orders carrying a rejected change, history), `/edit` (the one form, drawn tidied), POST save and `/confirm` (FormOnce + version; a form sent again comes back ready to save), `/reviews/{id}/{approve,reject}`, `/sample.pdf` (`PurchaseOrderPdf::sampleData`); the Settings page's company card; the PO pages' "do not send" note (I90–I99) |
| `tests/Unit/CompanyDetailsCheckTest.php`, `tests/Integration/Company/CompanyDetailsTest.php`, `Migration0013Test`, `tests/Integration/UiKernel/CompanyScreensTest.php` | the form's rules; the service (versions, stale and racing saves, confirm, the check at confirmation however many saves it took, rejected changes and their orders, roles, PO snapshots, the app login's grants and a made-up version found by C1–C4); the seed copy, its tidying and re-runs; the screens (roles, admin 403 on every POST, CSRF, stale and re-sent forms, required fields, an untidy seed, the review queue, the lone owner's check, flagged orders, phone-width markup) |
| `migrations/0016_item_cards.sql` | IM3: `item_card` (the legal and buying fields, one row per item, NO_DELETE; `first_confirmed_at` never cleared; `confirmed_breaches` = the rules the last confirmation blocked, kept until the next; 0 ml for a kit only), `item_card_change` (append-only history with the whole card after each write), `barcode_review` (the barcode review queue; append-only but for its decision columns; `open_key`; `moved_away`), `import_run.kind` + `item_cards` (I100–I124) |
| `src/Catalogue/ItemRules.php` | pure: the TRPR limits (refill 10 ml, tank / pod 2 ml, 20 mg/ml) and the single-use rule (`breaches`), blocked vs warnings (`status`: the last confirmation's rules block until the next one; `level`, `enforced`), what a confirmation needs (`missing`, `neededLabel`; a kit's capacity), `advice` (never blocks), the same rules in SQL (`sqlBreach`, `sqlBlocked`, `sqlFlagged`), integer comparisons (`scaled`, refuses floats) (I102, I103, I113, I115) |
| `src/Catalogue/ItemCards.php` | the only writer of `item_card` / `item_card_change`: `card`, `check` (the field parsers), `plan` (pure: what a write would do, the cross-field rules, the flavour status; the CSV check runs it too), `save` (kinds change / accept / import), `accept` (a proposal still offered), `confirm` (missing fields, the acknowledgement of a block, `lifted`), `history`, `editor` (catalogue.edit, never admin), `snapshot`, `BLOCK_EFFECT` (what a block stops today) (I101, I103, I113, I114, I121) |
| `src/Catalogue/CardProposals.php` | read-only suggestions from the matcher's identity card (`sku`) and the linked listings' features (or the Normalizer on their titles): nicotine, ml, product type (never from "disposable"), flavour, brand, and duty from the type; never single-use (I104) |
| `src/Catalogue/ItemCompliance.php` | what the other modules ask: `statusOf`, `blocked`, `assertAllowed($skus, order|receive|sell, $lock)` (422 item_blocked; a write path passes `$lock` = true: FOR SHARE), `receiving` (for IM6: blocked, warnings, stamp_required with an unknown duty answer treated as duty-liable unless the card names a dry type) (I103, I119, I122) |
| `src/Catalogue/{ItemBarcodes,BarcodeReviews,BarcodeSync}.php`, `bin/sync_barcodes.php` | a person's barcodes (add with units per scan, remove recorded as a decided review, change units; `parse` with the check digit); the review queue and its decisions (`KEEPS_AWAY`, stale refusals, `moved_away` for the item a move leaves, usability restored only when the last open review is decided and nobody ruled the barcode shared, `ruledShared`); the sync from linked listings (dry run by default, chunks of 1,000, idempotent, audited; `keys`: junk and restricted codes skipped; the pair's decision re-read under the lock; `unlinkedSources`) (I106–I108, I116–I118) |
| `src/Catalogue/{ItemCardList,ItemCardCsv,ImportRolledBack}.php`, `bin/import_item_cards.php` | the item cards list (filters incl. blocked, stock order, summary) and its CSV; the import in two steps (a lock-free check of every row, then the changing rows saved in one transaction; dry run by default, all or nothing, the cap on CHANGED rows, `card_version` guard, unknown columns refused, the export's formula apostrophe read past; a savepoint inside FormOnce's transaction that lets a deadlock through; `recordRefused`; the origin) (I109, I110, I120) |
| `src/Catalogue/CatalogueInvariants.php` | IC1–IC3, called by `Invariants::check`: history versions 1..n per card, each card equal to its latest snapshot, a barcode with an open review unusable (I111) |
| `src/Ui/Controller/{ItemController,ItemCardsController,BarcodesController}.php`, `views/{item,item_card_form,item_cards,item_cards_import,barcode_reviews}.php` | the item page's Item card section (state, rules, suggestions with "Use this", confirm with the acknowledgement box, history) and Barcodes (add / units / remove); `/ui/items/{id}/card` (form), `/ui/items/cards` (+ `.csv`, `/import`), `/ui/items/barcodes` (queue, `/{id}/decide`) (I109) |
| `tests/Unit/{ItemRules,Gtin,ItemCardCheck}Test.php`, `tests/Integration/Catalogue/`, `Migration0016Test`, `tests/Integration/Reorder/ReorderItemCardTest.php`, `tests/Integration/UiKernel/ItemCardScreensTest.php` | the rules at their edges, GTIN check digits, the parsers; the service (warn → block, who, versions, flavour status, suggestions, receiving, SQL = PHP, the invariants), the sync (dry run = real run, idempotent, decisions, removals, the CLI), the CSV (round trip, all or nothing, the CLI), races with two real connections (`CatalogueRaceTest` + `catalogue_worker.php`: two first saves, two saves, the sync against a person adding the barcode); the reorder list, draft POs and PO approval honouring the cards; the screens (roles, 403 on every POST, CSRF, stale and invalid forms, one effect per form, the queue, the list and CSV, the import, phone width, hostile text) |
| `migrations/0017_receiving.sql` | IM6 Receive (+ invoice): `goods_receipt` (the GRN header extension: supplier, PO, `invoice_key` UNIQUE with the supplier while live, received at, the paper-sheet reason, the bench's checklist), `grn_line` (the line extension: pack, packs, provisional pack price, PO line, mode choice, the bench's stamp check and exceptions, the posting's split and mode; cascades with its draft line), `grn_posting` (write-once anchor), `incident` (the register; append-only but its resolution), `item_selling_mode` + `item_selling_mode_log`, four `receiving.*` settings (I125–I147) |
| `src/Receiving/GoodsReceipts.php` | the receipt service: createDraft (FormOnce on the screen; a PO copied down), saveDraft (creator only; the bench findings carried with the lines), addLine (scan / supplier code / CW code / search: `PurchaseOrders::resolve`, a case barcode = a pack of its units), addChoice, copyFromPo ("receive all as ordered"), importLines (the supplier's sheet), bench (anyone holding doc.GRN.post), attach (FileStore; the invoice copy a PDF or a photo), post, cancel, the readers (lines, plan, receivableOrders, stockNow) (I125–I131, I138, I140, I145) |
| `src/Receiving/GoodsReceiptHandler.php` | the GRN `DocumentHandler` (registered in `DocumentHandlers::all`) and `ReviewInvolvement`: validate (formulas, the plan's problems), post (the PO's rows after the series, the plan again under locks, PO receipts, the lines' posting fields, incidents, selling modes, the anchor, audit, then ONE `bookForDocument` into MAIN / VERIFY / UNSTAMPED at the line cost), reverse (PO receipts back, incidents dismissed, the invoice key freed), `content()` (G3) (I128, I133, I143) |
| `src/Receiving/{ReceiptPlan,ReceiptMath,ReceiptLinesFile,SellingModes,Incidents,ReceivingInvariants}.php`, `src/Documents/ReviewInvolvement.php` | what posting would do and what stops it (one computation for the screens, validate and post); the pure arithmetic (invoice key, the split, the expected duty, the tolerance cap); the supplier sheet reader; the selling mode on receipt; the incident register; G1–G8; the document-base hook that keeps a bench checker out of the review (I129–I137, I140, I142) |
| `src/Ui/Controller/{Receiving,Incidents}Controller.php`, `views/{receipts,receipt,receipt_edit,receipt_bench,receipt_files,bench_list,incidents}.php` | `/ui/receiving…` (list + new delivery, the one-form editor and the read-only page, copy, import, files, post, cancel, reverse, the bench list and the bench check, `template.csv`), `/ui/receiving/incidents` (+ resolve); reviews of a GRN come back to its page (`ReviewsController`), the document page links it (I141). Plain words (U85-U90): every word from `Ui\Words` (`RECEIVING`, `RECEIPT`, `BENCH`, `INCIDENTS`, `RECEIPT_*`, …), refusals by code (`ReceivingController::plain`), the goods-in plan's sentences said again by `src/Ui/ReceiptWords.php` (pure; `tests/Unit/ReceivingWordsTest.php`) |
| `tests/Integration/Receiving/` (`ReceivingTestCase`, `grn_worker.php`), `tests/Integration/UiKernel/ReceivingScreensTest.php`, `Migration0017Test`, `tests/Unit/ReceiptMathTest.php` | the receipt's life (the box of 5, a PO copy-down, exceptions, a reversal), its rules (invoice number and copy, supplier, blocked items, the relay route, the paper sheet, the tolerance, the selling modes, the bench's figures, who may do what, the bench checker kept out of the review), the duty stamp and its date, the supplier sheet (CSV with a letterhead, all or nothing, XLSX), G1–G8, races with two real processes (one posting, the tolerance, one invoice, one form, the PO's close), the screens, the schema's CHECKs, the arithmetic; `GrantsTest::testTheReceivingFlowAsTheAppLogin` |
| `migrations/0018_site_writer.sql` | IM10: `channel.site_writer` (the per-site switch, off), `item_channel_mode` + `item_channel_mode_log` (a legacy item's selling mode and low-stock threshold per site; NO_DELETE / APPEND_ONLY), the setting `site_writer.receipt_mode_sites` (I148–I166) |
| `src/SiteWriter/{SiteView,SiteModes,SiteWriterInvariants}.php` | the `site` block of every feed view (rules, the movements behind a change); the per-site modes (the switch, a receipt's modes, the stamp); W1–W2 (I150–I163) |
| `src/Availability.php`, `src/ChannelAdmin.php`, `bin/channel_set.php` | the views carry `site`; `/v1/changes` adds `site.moves`; `--writer=on|off` (I149, I154) |
| `src/Ui/Controller/SellingModeController.php`, `views/item.php` (section `#selling-mode`) | the selling-mode switch on the item page, `POST /ui/items/{id}/selling-mode` (`modes.set`) (I158) |
| `tests/Unit/SiteViewTest.php`, `tests/Integration/SiteWriter/`, `Migration0018Test`, `tests/Integration/UiKernel/SellingModeScreenTest.php` | the rules; the feed (switch, blocks, per-site modes, blocks of the item card, moves, invariants); a receipt's modes on the feed; the schema; the screen |
| Vape and Go connector 0.4.0 (`App_proto/src/central_warehouse`: `lib/writer.php`, `bin/cw_notify.php`, `sql/cw_connector_v3.sql`, `tests/writer_*_test.php`) | the site's half (SC6–SC14): the worker's `writer` step, the guards H11–H14, the read-only admin fields, EMAIL SAFETY; not committed |
| `migrations/0019_set_it_yourself.sql` | the set-it-yourself pack (Y1–Y38): `config_change` (every configuration change as a version, baselines of every seeded row), `integrity_run`, `warehouse_location`, `staff_role_request`; warehouse owner/active/system columns; `staff_user.setup_until`; the supplier's approved-alone evidence; the `approvals.*` and `staff.*` settings |
| `src/Admin/{ConfigHistory,ConfigInvariants}.php` | the history of settings, reasons, document rules, warehouses and places (`record()` inside the change's transaction, `checkSeen()` for the form's version, `authorise()` = `settings.manage`) and its nightly checks K1–K4 (`Invariants::nightly()`); loosening an approval rule needs `staff.approve` (`ApprovalRules::loosens/authoriseLoosening`, `DocumentRules::loosens`, Y45) |
| `src/Admin/{DocumentRules,ReasonCodes,Warehouses,ApprovalRules}.php`, `src/Settings.php` `change()` | the services behind the screens and the CLI: a kind of record's rules, reasons, warehouses and places, the approval switches (`ApprovalRules::on/number`, read live); a setting changed with a reason and a version |
| `src/Admin/{Sites,AuditSearch}.php`, `src/Ops/IntegrityRuns.php` | read side of the Websites page, the audit log search and CSV, the safety check's runs (written by `bin/invariants.php`) |
| `src/Staff/{Enrolment,RoleRequests,StaffSessions,SetupCode}.php`, `StaffAdmin::enrol/newSheet/resetAuthenticator/resetPassword/setupInfo/signOut`, `src/Output/QrCode.php` | adding a person with a sign-up sheet (QR code + one-time set-up code), /ui/enrol and /ui/new-code (the person's own fresh secret, confirmed before any session; an admin never holds both factors: Y40-Y44), the resets, signed-in devices, the staff-grant and staff-reset requests; the QR matrix (`bacon/bacon-qr-code`) |
| `src/Ui/Controller/{Settings,Approvals,Reasons,Warehouses,System,Access,StaffRequests}Controller.php`, `src/Ui/ConfigWords.php`, views `setting`, `config_history`, `approvals`, `reason(s)`, `warehouse(s)`, `sites`, `integrity`, `audit`, `access`, `staff_sheet`, `enrol`, `staff_requests` | the set-it-yourself screens; `ConfigWords` says a version's who/what in words |
| `tests/Unit/SetItYourselfUnitTest.php`, `tests/Integration/Admin/`, `tests/Integration/Staff/StaffSetUpTest.php`, `Migration0019Test`, `tests/Integration/UiKernel/SetItYourselfScreensTest.php` | the services, the switches' effects (and S2/S3), the audit search, the staff set-up, the baselines, the screens end to end |
| `migrations/0021_mapping_bulk.sql`, `src/Mapping/BulkDecisions.php`, `src/Ui/Controller/{Bulk,StoreProducts}Controller.php`, views `mapping_overview`, `store_products`, `store_seg`, `bulk_bar`, `bulk_confirm`, `bulk_result` | store-wise review and bulk action on the matching screens (M46-M53, U106-U112): the store selector (`ReviewController::storeItems`, the `channel` value kept by `Sections::MAP` `keep`), the "By store" overview (`Queries::storeBands`), Store Products (`Queries::storeStateCounts`, `storeProducts`), the tick boxes and the action bar (app.js, section 21 of app.css), row-by-row decisions with skips and a batch (`mapping_batch`, `mapping_batch_row`, `screen:<id>` on every decision), the result/batch page, DecisionService's `unignore` and `send_back`, the settings `mapping.bulk_confirm_bands` (a `list_in` setting drawn as tick boxes), `mapping.bulk_max_rows` and the switch `approvals.mapping_bulk_second_ok` |
| `tests/Integration/Mapping/BulkDecisionsTest.php`, `tests/Integration/UiKernel/BulkScreensTest.php` | the service (skips with why, Second approval, the settings, order and batch, the two new decisions, who may) and the screens (the bar per list and person, the second steps, the refusals, the result page, CSRF and permissions, the store everywhere, Store Products' states, undo one match, the settings page) |
| `migrations/0022_stock_ops.sql` | pack A1: the kinds SIN, SOUT, TRF, REL (+ their series) and ADJ's sizes; `document_type.size_approval/size_units/size_value` (the OK first for a big record, off); `reason_code.needs_given_to/below_zero` and the uses `stock_in`, `stock_out`; new reasons with baselines, found/sample/other's next versions; `stock_op` (a stock record's header), `other_account_entry` (the balance owed, append-only) (SO1-SO16) |
| `src/StockOps/` | `StockOps` (the stock records' service: drafts, lines, add by barcode / CW number / words, post, stop, cancel, take back, files, read side), `StockOpHandler` (one handler for the five kinds: validate, approval units, size, post, reverse; never below zero), `OtherAccounts` (another account's room and the balance owed: payments and their reversal), `CostHints` (average cost so far, last supplier price), `StockOpsInvariants` (O1-O6), `StockOpPdf` (transfer note, release invoice); `src/Documents/SizeApproval.php` (the OK first for a big record) |
| `src/Ui/Controller/{StockOps,OtherAccounts}Controller.php`, views `stock_ops`, `stock_op`, `stock_op_fields`, `stock_accounts`, `decide_box` | Stock › Stock In / Stock Out / Adjustments / Transfers (Releases, Balance owed): the boards, the record page, the create and details form, the balance owed; the decide box shared with the generic record page |
| `tests/Integration/StockOps/`, `tests/Integration/UiKernel/StockOpsScreensTest.php` | each kind drafted, posted and reversed with its ledger and balances; the rules (given to, below zero, approvals, ownership, places); the release and the balance owed; the screens, roles and CSRF |
| `migrations/0023_reservations_screen.sql` | Stock › Reservations (RS1–RS12): the one index the screen needs, `reservation (channel_id, status, id)` (a store's reservations of one state, newest first), online; no data and no column changes |
| `src/Ui/ReservationViews.php`, `src/Ui/Controller/ReservationsController.php`, views `stock_reservations`, `stock_reservation` | Stock › Reservations, read only (GET routes only; `CW\Reservations` stays the only writer): the list by id (`before`), one index range per (store, state) pair merged (`ids()`), the search (an order reference whole, else the orders that hold a product now: `find()`), the figures (`totals()`, `heldByStore()`), one reservation (`one()`, `lines()`, `history()` = the stock ledger's rows of the order), the CSV (`export()`, 500 at a time by id); the controller turns the rows into the board's groups and words (`outcome()`). Every statement was checked with EXPLAIN on 60,000 orders (RS11): check a new one the same way before adding it |
| `tests/Integration/UiKernel/ReservationsScreenTest.php` | the screen through the real kernel, every reservation made by the engine itself: the tab and its place, each state and group, the store selector, the state filter, the search, the figures, one reservation with its lines and history, the CSV (type, header, formula-safe), the older pages by id and the export in batches, 404, 403, 405, nonsense in the address, the product page's links |

Document and file tests notes:
- `TestDb::clean()` keeps the seeded `reason_code` and `document_type` rows (a test that changes one restores it) and sets
  every `number_series` back to 0 (pad 6) instead of deleting it.
- Since 0019 `TestDb::clean()` also keeps 0019's `config_change` baselines (and deletes every other version), and deletes any
  `reason_code` without a baseline (one a test added, even if it died before its tearDown). A test that changes a setting, a
  document rule or a seeded warehouse through a service restores the row in tearDown (the history goes with `clean()`).
  `ConfigInvariants` is not in `Invariants::check()` (asserted after every stock test): a test that wants K1–K4 calls
  `ConfigInvariants::check()` itself. `TestDb::clean()` keeps every version a migration wrote (`actor = 'system:migrate'`: 0019's
  baselines and the version 2 of the order screens' reasons), not only the baselines.
- Staff set-up tests (`tests/Integration/Staff/StaffSetUpTest.php`): a sheet's code works once and the replay guard (`totp_last_step`)
  refuses the same 30 seconds twice, so a test clears `totp_last_step` before the next step (`next()`, as `signIn()` does). The
  throttle (10 failures per account in 15 minutes) is moved out of the way by dating the account's `login_attempt` rows a day back
  when a test tries many wrong answers on purpose. Loosening an approval rule needs a Reviewer: a test switches a rule off as a
  reviewer (`staffUser('reviewer')`) or as a server tool (`Caller::system(...)`), never as an admin.
- Composer: `bacon/bacon-qr-code` ^3.1 (with `dasprid/enum`; BSD-2-Clause) draws the sign-up QR code; only its encoder is used
  (no gd, no imagick: the page draws the matrix as HTML, Y21).
- A test that books stock with `Movements::bookForDocument` / `reverseDocument` directly must give its document ids real
  posted rows (`FixtureDocuments::posted($db, $id, 'ADJ', $no[, $reversesId])`, numbers 1, 2, ... per type): every
  `StockTestCase` asserts `Invariants::check`, which now includes the document checks D1–D7.
- Staff created for tests that go through `StaffAdmin` with a staff caller use `@test.example` addresses
  (`KernelUiTestCase::uiUser`): an address under `.invalid` is a placeholder account, which a screen never switches on or
  gives a role (I35).
- File store tests use a temporary root (`sys_get_temp_dir()`), never a directory inside the repo (refused) and never
  `/srv/cw-docs`; the screens find it through `CW_FILE_STORE_DIR` (`putenv` in the test), the CLI tools through the
  process environment.
- `*.csv` is git-ignored (`.gitignore`): tests write their CSV (and gzip, jsonl.gz) fixtures to temporary files
  (`sys_get_temp_dir()`), never into the repo (`CsvReaderTest`, `ErpSupplierImportTest`).
- `TestDb::SEED_TABLES` also keeps `app_setting` and `vat_code` (0009): a test that changes a setting does it with the admin
  connection and restores it (`SettingsTest` saves and restores every row; the app login cannot write them).
- Item cards (0016): write them through `ItemCards::save()` / `confirm()` as a person holding catalogue.edit
  (`CatalogueTestCase::editor()`; mapping_lead, stock_controller, purchasing_manager), never with SQL: the invariants after every
  stock-derived test include IC1–IC3, and a card written by hand has no history row (IC1). A test that forges one on purpose puts it
  back before it ends (`ItemCardsTest::testTheInvariantsFindACardChangedOutsideCw`). A `sku_barcode` row may still be inserted by
  hand, but a barcode with an open `barcode_review` must then be unusable (IC3).
- `UiResponse::form()` reads a checked radio whose value is `""` as `on` (a browser sends `""`): the item card form's "not known yet"
  answers; `ItemCardScreensTest::cardForm()` sets them back to `""`.
- A confirmed card keeps blocking after an edit (I113): a test that corrects a blocked card must confirm it again before it expects
  the item to be suggested, ordered or approved (`ReorderItemCardTest`). Two private steps are driven by reflection on purpose, to make
  a race deterministic without a hook in the code: `BarcodeSync::writeLocked` with a stale chunk state, `ItemCardCsv::write` with a card
  changed between the check and the save.
- Listing barcodes stored through `listing_profile.barcodes` (a JSON column) come back as integers or strings: MySQL keeps no float for
  `5012345678900.0`, so a "decimal number" code is tested on `BarcodeSync::keys()` directly.
- `company_profile` (0013) is **not** a seed table: `TestDb::clean` empties it, so every test starts with no company details
  (`Settings::company()` = empty placeholders, `confirmed` false, `version` 0: the "do not send" banner, as before 0013). A test that
  needs details saves them through `CompanyDetails::save()` / `confirm()` as a reviewer (staff callers only), or inserts a row with the
  admin connection (a `seed` row needs no `saved_by`). The `company.*` settings no longer exist: `Settings::get('company.x')` throws.
- The invariants after every stock-derived test include C1–C4 (`CompanyInvariants`): a row inserted by hand needs what the service
  would have written, or the test ends red: a `seed` needs its `company.change` audit row by `system:migrate` (entity_id `'1'`), a
  `change` its `company.change` row by its `saved_actor`, saved by someone holding reviewer; a test that forges a row on purpose
  deletes it before it ends (`CompanyDetailsTest::testTheAppLoginAddsVersionsButNeverRewritesThemAndAMadeUpOneIsFound`).
- `UiResponse::form($action, true)` also returns the form's textareas (the company form's addresses), as a browser would send them.
- Uploads in screen tests: `KernelBrowser::postMultipart($path, $form, ['file' => ['path' => <local file>, 'name' => <client
  name>]])` builds the `UiRequest` PHP would hand the kernel (optional `size`, `error`: `UPLOAD_ERR_INI_SIZE` with path `''`
  is a file over `upload_max_filesize`); a body over `post_max_size` arrives with no fields at all: `send('POST', $path, [],
  [], ['content-length' => '3145728'])` (413). The evidence upload stores through `CW_FILE_STORE_DIR` (a temp directory); a
  missing directory gives the 503 the staging UI shows until `install_file_store.sh` runs.
- A creating form carries `form_key` (`FormOnce::newKey()`): screen tests post the same form twice and assert one effect.
- `Kernel` refuses any POST that arrives with `UiRequest::maxInputVars()` (PHP's `max_input_vars`, 1,000) fields: 400
  `form_truncated` (PHP silently drops the rest; I73). A big form sizes itself below it (`PurchaseOrdersController::editorFields`);
  `KernelBrowser` builds the request directly, so a test simulates truncation by posting that many fields.
- `markSent` refuses while `sendWarnings()` lists anything (a test schema has no confirmed company details): service tests pass
  `$acknowledged = true`, screen tests post `send_anyway=1` (I86). The PO editor approves through its own form
  (`action=approve` posted to `/lines`), not `/approve` (I87).
- `KernelUiTestCase::kernel()` registers the production handlers (`DocumentHandlers::all`: PO since the I-2 pos task; GRN; the stock
  records SIN, SOUT, ADJ, TRF, REL since pack A1) with the test-only ADJ fixture in place of the real ADJ (`handlers()`; a test of the stock
  records overrides it to return `DocumentHandlers::all($db)`); `DocumentTestCase` still registers ADJ only (so `type_not_built` is tested with GRN there). A test that
  needs another type's row changed (`reject_action = 'record'` on ADJ in `ReviewRulesTest`) changes it with the admin
  connection and restores it (`document_type` is a seed table).
- **The staging box has a file store now** (`/srv/cw-docs`, named by app.env `file_store_dir`; observed 2 Oct 2026): a screen
  test that stores files (the PO "Mark as sent", evidence uploads) MUST point `CW_FILE_STORE_DIR` at a temporary directory
  (`putenv` in setUp, restored in tearDown), or it writes into the real store, whose shards are append-only. Service tests
  build `PurchaseOrders` without a file store (`$files` null: the PDF is not archived).
- PO tests (`tests/Integration/PurchaseOrders/PurchaseOrderTestCase`): suppliers made active by a second person through
  `SupplierTestCase`, `approvedPo()`, `draftPo()`; receipts are applied inside `self::$db->transaction(...)` (they refuse to run
  outside one). Lines files and open-PO CSVs are written to temporary directories (`*.csv` is git-ignored).
- Reorder tests (`tests/Integration/Reorder/ReorderFixtures`, used by `ReorderTestCase` and the screen tests): sales history is
  written straight into the tables (`history()`: one loaded batch and its rows) or as export files in a temporary directory
  (`exportFiles()`: gzip CSV + manifest, as the export tool writes them); `demand_anomaly` is NOT a seed table (the
  migration's 14–22 Sep row is gone after `TestDb::clean`): a test that needs it calls `stockpiling()`; settings changed with
  `setting()` are restored in tearDown. MySQL returns JSON objects in its own key order: compare decoded `detail` objects
  with `assertEquals`, not `assertSame`.
- `SalesExportToolTest` creates `cw_test_<slot>_site` (the live tables' columns and index names, filler rows outside the
  window and `ANALYZE TABLE` so that the optimizer reads by index as on live) and runs the tool as a subprocess with
  `CW_EXPORT_DB_*` and `CW_SALES_EXPORT_ROOT` set to a temporary directory; it never connects to a real site.
- Stock record tests (`tests/Integration/StockOps/StockOpsTestCase`): warehouses and places are inserted for the test (TestDb::clean removes
  them), a kind's rules (`rule()`) and a reason's rules (`reasonRule()`) are changed with the admin connection and put back in tearDown (call it
  BEFORE a service changes the row: it keeps the row as it was); the file store is a temporary directory. ADJ keeps its "stock put back without a
  supplier document" OK first (10 units a person a day), so a test that adds more on one day gets a record waiting for an OK.
- Composer: `openspout/openspout` ^4.32 (v4.32.0; needs ext-dom, fileinfo, filter, libxml, xmlreader, zip — all on staging —
  and no gd) is in `require` (I58).
- Composer: `setasign/fpdf` is in `require` (install_cron.sh runs `composer --no-dev`). It is pinned to 1.8.2 because
  every later release declares `ext-gd`, which staging lacks (I24): `composer require setasign/fpdf:^1.9` once
  `php8.3-gd` is installed. Change dependencies with `composer require` in a slot (`scripts/remote.sh <slot> 'COMPOSER_ALLOW_SUPERUSER=1
  composer require ...'`) and copy `composer.json` and `composer.lock` back with rsync: a hand-edited `composer.json`
  makes remote.sh's `composer install` refuse ("not present in the lock file").
- Receiving tests (`tests/Integration/Receiving/ReceivingTestCase`): the file store is a temporary directory (never `/srv/cw-docs`);
  `receiving.unstamped_refusal_from` is set a year ahead in setUp and restored in tearDown, so the duty tests do not change meaning
  when 1 Jan 2027 passes (a test of the refusal sets it to yesterday with `setting()`, which also rebuilds the services: `Settings`
  reads its rows once per instance). Posting needs the invoice copy and the bench check: `ready()` attaches a PDF and checks every
  line (stamped, digital); `bench()` merges a test's findings over that default. Duty-liable lines need a stamp check, so a test that
  is not about duty gives its items a coil card (`dry()`). The screen test pins the date and the file store the same way
  (`CW_FILE_STORE_DIR`). The race worker `grn_worker.php` runs one operation per process (post, create, create_once, close_po).
- A test that books a receipt's PO receipts directly (`PurchaseOrders::applyReceipt`) is fine on a PO line no receipt line names:
  G7 checks only the PO lines that receipts receive.

