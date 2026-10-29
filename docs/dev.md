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
   **Say how a table is altered, and do not trust `ALGORITHM = INSTANT` on staging's database server** (MySQL 8.4.8, a managed
   build; measured 12 Oct 2026, `tests/Integration/Migration0027Test.php`): it accepts the word for ADD COLUMN and rebuilds the
   table all the same. On `listing_profile`, which has the multi-valued barcode index of 0005, no column can be added in place at
   all (MySQL refuses `INPLACE`), and "INSTANT" there is silently a table copy. So: an index is added `ALGORITHM = INPLACE,
   LOCK = NONE` (not a rebuild: 0020, 0021, 0024, 0026, 0027, 0029); a column on `listing_profile` is `ALGORITHM = COPY, LOCK = SHARED`,
   every column and index of the change in ONE statement (one copy: about 9 seconds for its 111 MB, reads go on, a listing push
   waits), written first in the file, and the test holds the file to it (0027, and 0028 for the website state:
   `Migration0028Test` also runs the statements of the code of before the migration on the new schema).
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

<!-- ==== Pack E ==== -->
## Who can do what, and the reports (pack E; `docs/decisions.md` PE1-PE14)

- **The permission map lives in `role_permission`** (0034), one row per permission. `Permissions::MAP` is what 0034 seeded and the
  default of a permission with no row; `Permissions::map()` / `can()` read the map in force, which `Auth\RoleMap::load()` puts there
  on every /ui request and inside `StaffRoles::active()`. To **add a permission**: add it to `MAP` (and `Words::PERMISSION`) as before;
  it works at once with those holders, and `RoleMapTest` checks it obeys the rules (a key that only lets people look ends in `.view`;
  admin and viewer/accountant/auditor never hold one that changes anything). To **change who holds an existing permission**: on the
  screen (Settings › Users › Roles and permissions), or a migration that updates the row and writes its next `config_change` version
  (rule 5 above); editing `MAP` alone no longer changes a permission that has a row. `Permissions::LOCKED` are never changed on the
  screen; `Permissions::REQUIRED` always stay with their guarded job. Only `Admin\RolePermissions` writes the table.
- A test that changes the map puts the rows back itself (`role_permission` is a seed table `TestDb::clean` keeps) and calls
  `Permissions::useStored(null)` in its tear-down (the map in force is a static of the process).
- **The reports** read `stock_balance` as it is (like Stock › Overview) and the report job's own tables (`report_item`,
  `report_sales_day`, `report_refresh`), never the ledger. The job: `php bin/refresh_reports.php [--force] [--rebuild] [--status]`
  (every 15 minutes on staging; `--rebuild` empties its tables and the next runs read everything again, bounded by the settings
  `reports.refresh_max_rows` and `reports.refresh_max_days`).
- **EXPLAIN on a filled schema:** `CW_RUN_SLOT=<slot> scripts/remote.sh <slot> php tests/perf/report_explain.php` re-creates the slot's
  own test schema (it refuses any other), fills it (about 20,000 items, 335,000 ledger rows, 100,000 paid orders), runs the job to the
  end, then prints every report page's statements with their EXPLAIN ANALYZE. Run it only when no PHPUnit runs on the staging box.
<!-- ==== end Pack E ==== -->

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
| `src/Auth/` | staff sign-in for the UI: `Login` (password + TOTP), `LoginLimiter`, `Sessions` (`staff_session`, hashed ids; `limits()` = the settings `session.idle_minutes` / `session.absolute_hours` of 0047, read once per request through `Ui\Context::sessions()`, `liveSql()` = the one condition of a live session; SL1-SL8), `SecondStep` (the setting `auth.totp_off_until` of 0048: until when the sign-in asks for no 6-digit code, a UK time, fail closed; TF1-TF6), `Csrf`, `StaffIdentity` (M2, U1-U4) |
| `src/Ui/` | the staff screens: `Kernel` (route, session, role, same-origin + CSRF, hardened headers), `Router`/`Route`, `Context`, `UiRequest`, `HtmlResponse`, `Html` (escaping, chips, help, money and UK time), `View` (templates and their helpers), `Words` (every word on a screen, U25), `Sections` (THE navigation map: seven sidebar sections, their tabs and segments, each page with its route's permission; `locate()` finds a request's section, tab and page; `frame()` gives the layout the sidebar, page bar, segments, create button and phone bar; U95-U99), `FlowCounts` (Purchasing's flow strip), `StockViews` + `Controller/StockController` (Stock › Overview and Movements, U101), `HomeTasks` + `HomeCounts` (Home's "What needs doing" cards and their counts), `PoWarnings`, `ReorderWhy`, `Queries` (read side), `QueueContext`, `Compare`, `Assets`; `Controller/*Controller`; templates in `src/Ui/views/`; the three static files in `public/ui/assets/` (`app.css`, `app.js`, the icon sprite `icons.svg`: one `<view>` per icon, drawn by `app.css` as a mask, named there by its version) and the four self-hosted Poppins files in `public/ui/assets/fonts/` (SIL OFL, licence text beside them; served by `Assets` with `font/woff2`, kept a year: U103, V5.3). The look is design v5 (V5.1-V5.9): every colour, size and radius is a token at the top of `app.css`; every page template opens with `$head(...)` (`View::head`, `Html::statement`, `views/page_frame.php`) |
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
| `migrations/0024_by_product.sql`, `src/Ui/Controller/ByProductController.php`, `src/Ui/ByProductContext.php`, views `by_product`, `by_product_map` | Products › By Item (M54, U113-U122; the paths and keys still say `products` / `by_product`): one row per warehouse item (a `sku` row), one column per row of `channel` (`Queries::everyStore`), a cell's state and the item's parent product ("Product: …", the best-selling matched variant's `product_title`) from three reads (`Queries::productCells`), the coverage filters and their counts (`productCoverage`, `productIds`, `productsBefore`), the search (`productText`: a text with no usable word finds nothing), the CSV (each variant with its own note), and the picker of a store's variant for one item (`suggestedFor`, `waitingListings`, `pickerRows`; a plain opening runs its pre-filled search only when nothing is suggested). It only reads: "Choose" opens the variant's page (the website product's page) with `?pick=`, and `ByProductContext` (`via=product`, an item's number, a store code, the list's filter and search, and the picker's own search and page `pq` / `pp`) carries "came from By Item" there and back (`ReviewController::show` / `decide`): Back is `pickerUrl()`, the redirect after a decision `listUrl()` (never `pq` / `pp`); 0024 is two indexes (`channel_listing (sku_id, channel_id)`, `match_proposal (status, proposed_sku_id, listing_id, band)`); section 22 of app.css. **The quick match** (the owner's request of 20 Oct 2026): a "Not on this store" cell of someone who may decide has "Match…" as a `<details data-quick-pick>` with the picker's search box; its box and button name (`form=`) a GET form of the picker drawn after the list (the list sits inside the bulk form; a form inside a form is no form), so without app.js Search opens the picker. With app.js the fold shows `GET /ui/review/products/{id}/map/options` (`ByProductController::options`, Route::DECIDE, read only, `Context::fragment`: no layout; view `by_product_options`, root `data-qp-fragment`, rows and buttons with `data-listing-id`, `data-sku-id`, `data-channel`): the picker's two lists (`choices()`, shared with `map()`), each variant with its details and a "Match to …" POST of the variant's own decide route carrying `expected_map_version`, the open `proposal_id`, the units and `ByProductContext::query()` (with `pq`), so success returns to the list at the row and a refusal lands on the variant's page with its words; no "Match to …" for a row `OneAtATime` names or a Conflict for a non-lead ("Choose" and the lists' line instead; the fragment asks `OneAtATime::ofWaiting()`, of()'s answer for variants that still wait in a fixed number of reads: no spot check's verdict is needed, since a waiting variant is never a confirmed match). The decide route refuses such a row itself (`DecisionService`, docs/decisions.md DG1, DG2): another lead's spot check's match (403 `spot_check_other`), and any variant decided one at a time sent without its own page's `seen` (409 `one_at_a_time`), so a fold drawn before the variant became one lands on the variant's page with the refusal in words. app.js's one `fetch` (`fragment()`) serves this fragment and the lists' Check & match fold (same origin, parsed by DOMParser, shown only with the root of the route it was asked of: `UiUnitTest::testTheAssetsNeedNothingFromOutsideAndNoEval`). **Check & match** (docs/decisions.md CM1-CM4): on To review's Likely list and Store Products a row found by the check on arrival with a Likely suggestion has `check_fold` (a `<details data-check-fold>`); app.js shows `GET /ui/review/listing/{id}/check` (`ReviewController::check` / `foldFacts`, view `listing_check`, root `data-lc-fragment`), which alone decides whether the row is clean (`FOLD_BLOCKS`, from the variant page's own `whyOf()` / `unitsOf()` / `Compare::rows`) and then draws Yes / No POST forms of the decide route with `seen`, `from=check`, the list's way back (QueueContext or `StoreContext`, `lp`, `nx`); `decide()` passes `foldRecheck()` to `DecisionService::decide(..., $recheck)`, asked under the listing's lock (409 `check_changed`). The search of the store's waiting variants (`Queries::waitingListings`, picker and fold) asks every word (up to 6, AND; the longest first) of `WAITING_WORD`, an OR cheapest first: option number, names, brand, `site_product_id`, `perma_link` (LIKE), any part of a barcode, any attribute value and the features' flavour and range words (JSON_SEARCH with the word in utf8mb4_0900_ai_ci: case and accents ignored, JSON keys are no words); a word with a digit also of `WAITING_NUMBER` (an attribute's numeric `$**.value`, the features' product id, strength, size and range numbers, as text); one LIKE scan of the store's waiting profiles, no query per row (on 10,000 profiles about twice the names-only search of before: 0.2-0.35 s on cw-test). `UiResponse::form()` honours `form=` |
| `migrations/0026_new_variants.sql`, `src/Ui/Controller/NewVariantsController.php`, view `new_variants` | Products › New Variants (M60-M62, U131-U138): one store's variants (`channel_listing` rows) first seen in a period (`created_at`), newest first, each with what it is (`Queries::NEW_KINDS`: New = an open "new product" suggestion, Suggested match, No suggestion (`not_checked`) = no open suggestion, Matched, …) and what the website said of it where the profile's features hold it (`variant_status`, `is_placeholder`); the filter (`NEW_FILTERS`), Store Products' search, the pager and the CSV (`Queries::seenRange`, `seenSinceByStore`, `newVariantCounts`, `newVariants`, `newVariantRows`, all on the index `channel_listing (channel_id, created_at, id)` named in a hint; the open suggestion joined after the listing, `JOIN_ORDER`); whether the store sends, from its mode and its latest heartbeat (`Queries::storeLink`, `NewVariantsController::sending`); the period the page opens with is the setting `mapping.new_variants_days` (`Settings::RULES` 1 to 365; baseline in 0026). It only reads. The "seed" is the existing bulk route with `source=new`: `BulkDecisions::NEW_ACTIONS` (create as new items, confirm match, ignore) and `seedCheck()` + `nameChecks()`, ONE rule for every bulk "create as new" (New Variants and the New products list, M61): ONLY a variant with an open "new product" suggestion becomes an item; skipped with why, in this order: `made_before` (`sku.origin_listing_id`, `madeFrom()`), `suggested_match` (New Variants only), `page_heading`, `not_published` (the features' state, else the open suggestion's evidence: `siteState()`), `not_checked` / `no_suggestion`, `match_no_item` (New Variants: a Strong or Likely match that names no item, `matchNoItem()`) or `not_new` / `band_not_allowed`, `changed_since_check` (`match_proposal_basis.identity_hash` against the profile's, or the title in the suggestion's evidence against the title now, `KeyEligibility::sameTitle`: `changedSinceCheck()`), `barcode_elsewhere`, `name_elsewhere` (the name `DecisionService::cardFrom` gives, one look-up per name on `sku (name)` of 0026: `namesTaken()`), `name_twice` (two ticked rows of one name), and the existing skips; each "create as new" reads its name again just before its decision (`one()`: a read, not a lock); the list's rows say the same before ticking by asking the same static functions (`NewVariantsController::row`), and a store with no "New product" suggestion in any state is told so (`Queries::everFoundNew`, `never_new`); the CSV asks for one id more than it may hold and writes its last line only when that one came back (`csvText`); `mapping_batch.source` `new` (0026, the column's whole list: review, store, item, new); `BulkController` (the way back, the result page's words `NEW_VARIANT_ACTION` / `NEW_VARIANT_DONE`). For every list of the route: `Gtin::forms()` (a barcode is looked up as stored, as its key and zero-padded up to 14 digits, every barcode of the listing), a ticked number above INT UNSIGNED and a note that is not UTF-8 are refused whole before anything is written (`BulkController`, `BulkDecisions::run`; `DecisionService::reason` checks the note's text for the single page). No decision, role or two-person rule of `DecisionService` changed. Every statement was checked with EXPLAIN on 38,000 variants (U136): check a new one the same way |
| `tests/Integration/UiKernel/NewVariantsScreenTest.php`, `tests/Integration/Mapping/BulkDecisionsTest.php`, `tests/Integration/UiKernel/BulkScreensTest.php`, `tests/Unit/GtinTest.php` (and `UiReviewFlowTest`, `UiSecurityTest` in slot `ui`) | three stores made by the test: the tab and its place, a store's variants newest first with their facts and what each is in words, the sentence about the store from its mode and heartbeat (off, in contact, quiet), the empty states, the period filter and its setting changed on the Settings page, the filter with counts (both ways of counting agree, each list as long as its count), search, pager, CSV, the bar per person and its line, "Create as new items" (an item only for a New row; each skip with its reason: not checked incl. a sent-back variant, an item made from it before incl. after a later check says "new" again (both orders), page heading, not published incl. from the check's record after a rename, changed since the check, a name an item has or two ticked rows share, one the computer could not decide, suggested match, barcode elsewhere incl. the zero-padded twin, matched, waiting, ignored; each row saying so before it is ticked; the result page's words; the one-at-a-time way still open), the same guards on the New products list (`BulkDecisionsTest`, every test over both lists), the seed on whichever store has a variant marked New and the sentence for a store that has none, the CSV's last line on both sides of the limit and with counts that are wrong either way (`csvText`'s last parameter), a variant judged under another title, a match that names no item, the name read again at a row's turn (two rows that differ by an accent; and `prechecks()` then `one()` driven by reflection around an item made in between, as `BarcodeSync::writeLocked` is), the second-OK switch and the most rows, Confirm match and Ignore… from this list, CSRF and jobs, numbers out of range and a note that is not text (New Variants, the strength lists, Store Products, the single page), odd values in the address, the indexes and the plans of the list, the name look-up, the item-made-before read and `everFoundNew` (EXPLAIN); what counts as a suggestion to make on the existing lists unchanged; the owner's naming is pinned in `WordsTest` |
| `tests/Integration/UiKernel/ByProductScreenTest.php`, `tests/Unit/ByProductContextTest.php` (and `UiReviewFlowTest`, `UiSecurityTest` in slot `ui`) | the tab, a cell's states over three stores and a fourth row of `channel`, the parent product, who sees "Match…", filters with counts, search (also with no usable word), pager, CSV (two variants of one store, each with its own note), the picker (when its search runs, its pager with an emptied search), Choose and the decision made on the variant's page leading back with its notice, Back to the picker as it was left, the context's crafted values, odd addresses in words; the owner's naming is pinned in `WordsTest` |
| `src/Mapping/OneAtATime.php`, `KeyHold::IN_FORCE`, `Queries::SHOWS` / `queue()` / `nextInQueue()`, `QueueContext::$show`, view `one_at_a_time` | the rows no bulk action takes (M63, U139-U143): `OneAtATime::of()` is the ONE read of them (`held`: a hold in force; `in_spot_check`: a match of a spot check that is not complete), asked by `BulkDecisions::prechecks()` and `one()` and by the three lists whose rows are variants (`ReviewController::queue`, `StoreProductsController`, `NewVariantsController`; `ReviewController::oneAtATime()` words the row's line: for a held-back row the day and the name only, never the hold's note, which is a long rule text and stays on the variant's page; `noBoxNotice()` words the notice, on the match-strength lists only). Products › By Item ticks ITEMS, not variants, and does not ask it itself: its preview and its apply go through `BulkDecisions::preview()` / `run()`, which do, so a held variant reads "Will be skipped" there (M56). A new list whose rows are variants asks it the same way and draws no box for the rows it names; a new rule of "never in bulk" belongs in `OneAtATime`, not in a screen. The "Show" filter of the match-strength lists is `QueueContext::$show` (a value of `Queries::SHOWS`; `showValue()` is null for All, so old addresses are unchanged): anything that builds a link or a form for a list takes it from `query()` / `pageQuery()`; `BulkController` keeps it by name (`show`) on its second step, its way back and the redirect to the batch page. `QueueContext::filtered()` says whether anything narrows the list (Show, found by, units, a search): `ReviewController::decide()` then ends on the notice `shown_done` instead of `queue_done`, because the last row shown is not the list's last. Its SQL is `OneAtATime::heldSql()` and `spotSql()` (the ids of `spotMatchesToDecide()`); its counts come from the small side (`Queries::queueShows()`), never from a second count of the list. To see the plans: `/root/cw_work/runs/ready_explain_script.php` (copy it to `tests/perf/` in a worktree, run it in a free slot; it fills and then resets that slot's test schema) |
| `tests/Integration/UiKernel/OneAtATimeScreenTest.php`, `tests/Unit/QueueContextTest.php`, `BulkDecisionsTest::testTheOneReadOfWhatNoBulkActionTakesAgreesWithThePreChecksRowByRow`, `WordsTest::testTheListsSayWhichRowsNoBulkActionTakesAndWhatAListShows`, `UiReviewFlowTest::testAHeldRowHasNoTickBoxAndTheReadyListIsConfirmedTogether` (slot `ui`) | a held-back row and a spot check's row on the three screens (no box, the line, `all=1`, the notice and its numbers); each choice of "Show" lists exactly its rows, with its count, and All is their sum (a search, units, another store, a hold released, a match answered); the choice through the pager, a variant's page and back, "the next one", the bulk form, its second step, the result page and a refusal; the notice after the last row of a choice or a search, and of a list nothing narrows; Ready + tick all + Confirm takes a full page and skips none of them as held or in a spot check; a hand-made form naming such a row is skipped as before on every list; the one read against the pre-checks, row by row, for every list and action, while a spot check waits and after it failed |
| `migrations/0025_by_item_bulk.sql`, `src/Ui/Controller/ByItemBulkController.php`, view `by_item_preview`, `BulkDecisions::preview()` / `checked()` / `itemChecks()` / `confirmScope()` / `rejectedBefore()` / `predict()` | Products › By Item, more rows and the bulk action with its preview (M55-M59, U123-U130): 50 / 100 / 200 items a page (`n`, the setting `mapping.by_item_page_size`, `ByProductController::pageSize`, `ByProductContext::PAGE_SIZES` and its `ln`); a tick on every item with a suggested variant and the bar with the store choice (`bulk_bar` takes optional `words` and `choice`; `app.js`: a box marked `data-bulk-manual` is left out of "select all"; a row sends `cells_<item>` = "variant.suggestion.version …" with EVERY suggestion it showed, and has no tick box above `ByItemBulkController::MAX_PER_ITEM` = the most `mapping.bulk_max_rows` can be); `POST /ui/review/products/bulk` is the preview (it saves nothing: `BulkDecisions::preview()` = the request checks `checked()` moved out of `run()`, `prechecks()`, `itemChecks()` and `predict()`, which states DecisionService's answers for a waiting variant and is held to them by tests); `POST /ui/review/products/bulk/apply` sends the preview's rows (`row_<variant>` = item.suggestion.version.1|0) through `BulkDecisions::run()` with the list context `item` (`mapping_batch.source`, 0025) and ends on the batch page (`BulkController::show`: By Item's words, tab `from=item` and way back); the limit `mapping.bulk_max_rows` counts variants; a matched variant is never changed in bulk (M53); a confirm leaves to a person a pair marked not a match before, an item that already has a variant or a waiting match on the store, and several suggestions on one store (`confirmScope()`, M59), and the list's cell says "Marked not a match" (`rejectedBefore()`: `match_reject` by its unique index); `checked()` refuses, for every list, a row whose variant number or version is beyond INT UNSIGNED (400, before anything is written). **`mapping_batch.source`:** 0025 adds `item`; a later migration that adds a value restates every existing value; an applied migration is never edited |
| `tests/Integration/UiKernel/ByItemBulkTest.php`, `BulkDecisionsTest` (the four `…Preview…` / `…ByItem…` / `…BeyondItsColumn…` tests), `BulkScreensTest` (the same bound on `/ui/review/bulk`), `UiReviewFlowTest::testByItemsBulkAction…` and `UiSecurityTest` (slot `ui`) | the rows choice and its setting; ticks and the bar per person; each action's preview (counts, every skip reason, the reasons for a second OK) and the proof that it writes nothing (every table's rows, checksum and next id); the apply and the result page; a change between the preview and the apply; rows the preview skipped; what a confirm leaves to a person and the "Marked not a match" cell with "tick all"; a row of more than 50 and of more than 500 suggestions; numbers beyond their column; a refusal's number filled in; the limit in variants; the store choice; Back with filter, page, rows and ticks; CSRF, permissions, odd and crafted values; over HTTP a page of 200 ticked items previewed and applied |
| `src/Ui/Controller/StoreNewItemsController.php`, view `store_new_preview`, `BulkDecisions::STORE_NEW` / `newChecks()` / `newApplyChecks()` / `openBatch()` / `nameWords()` / `cardHash()`, the bar's `formaction` (`bulk_bar`), the batch page's new items (`BulkController::show`, `bulk_result`) | Store Products' "Create new warehouse products for these" (NF1-NF8): a matching lead ticks a family that has no warehouse product (filter "Not matched" and the search), `POST /ui/review/store/new-items` previews the new product each row gets (the single page's card, 1 sale = 1; WILL CREATE / WAIT / SKIP with why; writes nothing), `POST /ui/review/store/new-items/apply` makes them row by row through `DecisionService` `new_item` (batch `screen:<id>`, source `store`, action `new_item`); a form key (`Idempotency`, key `bulk:<staff>:<key>`) makes one batch however often it is sent |
| `tests/Integration/UiKernel/StoreNewItemsScreenTest.php`, `tests/Integration/Mapping/StoreNewItemsTest.php` | the filter, the preview's cards and every skip, the apply and the batch page, sending again, the two-person rule, who may, the single create unchanged; held / spot check / on arrival, card changed, a name waiting, the form key |
| `src/Ui/Controller/SimpleMatchController.php`, views `simple_match`, `simple_match_options`, `ReviewController::decisionRequest()`, `Queries::productTotal()` / `listingByOption()`, the `simple-matching` blocks of `app.js` and `app.css` | Products › Simple matching (SM1-SM11; the owner's requests of 25 and 26 Oct 2026), the first matching tab: `GET /ui/match` (linking.view) is By Item's list and search with one column per store in the owner's order (`STORE_ORDER`: vapeandgo, vapebig, electrofag, then any other store), 50 rows a page, each row `#m-<sku>`; a cell lists the products matched there (name on the store, ID) and those waiting for a second OK; for `mapping.decide` ONE box (a text box with the list `app.js` draws at the page's top level: two lines per choice, the full name then "ID · status on the store · strength", opening to the left near the right edge, SM11) and Apply, at once in an empty cell, behind "+" in a full one. `GET /ui/match/options?sku=&channel=&q=` is the cell's choices (a fragment, `data-sm-fragment`: the item's suggestions for an empty box, else `Queries::waitingListings`, at most 20, each with its listing id, map_version and open suggestion); `POST /ui/match/apply` checks the product is the store's and still waiting at the version the form names (without the script a typed ID comes back chosen in the box, `confirm`, for a second Apply), then sends the decide route's own request (`ReviewController::decisionRequest`, shared with `decide()`) to `DecisionService::decide` with `addOnly()` as its recheck under the lock, and goes back to the row with the matching notice or a refusal said in the cell (`SAID`, `saidOf()`). It never changes or undoes a match (the product's page) |
| `tests/Integration/UiKernel/SimpleMatchScreenTest.php`, `tests/Integration/UiKernel/StoreProductsPagerTest.php`, `WordsTest::testSimpleMatchingSaysEveryRefusalInWords` | the tab and the four columns in order (a fourth store after them), a cell's matches and the waiting label, search, pager, who sees the box, the choices (words, ID; never matched, landing, ignored, waiting or another store's; the suggestions of an empty box), Apply through DecisionService (audit, the way back, the notice), a duplicate, every refusal in words, no token, the typed ID without the script (confirmed, then decided against that page), a match made while Apply waits (a second session), the exact ID first, the second OK, the units of the item's own suggestion; Store Products' Previous and Next carry the page (SM8) |
| `migrations/0051_hide_unsold.sql`, the HU block of `Queries` (`UNSOLD_SETTING`, `UNSOLD_HIDDEN_SQL`, `STOPPED_SQL`, `unsoldBefore()`, `unsoldShown()`, `exactIds()`, `unsoldHidden()`, `unsoldOfState()`), `StoreProductsController::unsoldLine()`, tests `HideUnsoldScreenTest`, `Migration0051Test` | The matching lists leave out what does not matter (HU1-HU9; the owner's yes of 26 Oct 2026): a website product not matched yet, with nothing sold in a year (`units_365d` 0 or NULL) and first seen (`channel_listing.created_at`) more than a year ago or at or before its store's first load (`channel.first_load_at`: set by 0051 from the stores' rows, by `bin/import_listings.php` for a new store, `ListingIngestService::recordFirstLoad`; read once per request, `unsoldBefore($store)`), is left out of Store Products (Not matched, Suggested, All, and their counts), To review (lists, counts, `bandCounts()`, `listLeft()`), New Variants ("Any time") and Simple matching's choices (`waitingListings`/`suggestedFor` with `hideUnsold`; By Item's picker keeps every one); its exact store ID or barcode still finds it (`exactIds()`); Store Products ends with ONE quiet line, "N products not sold in a year are hidden. Show them" (`unsold=1`, kept by its links). The setting `mapping.hide_unsold_days` is 365 (on) or 0 (off). Simple matching and the website product's "Pick a different product" (`productIds`/`productTotal` with `matchable`, `searchSkus(…, matching: true)`) leave out a warehouse product not sold any more (`item_card.discontinued`) or joined; its exact CW number shows it with no box and no "Use" (SAID `stopped`). To hide on another list: add `unsoldShown()` to its WHERE (with `exactIds()` for its search) and take `unsoldHidden()` off its counts. HU9 (answered "b"): the products already there at a store's first load count as old, so the about 12,900 are hidden now; the toolbar keeps `unsold=1` |
| `migrations/0027_arrival_check.sql`, `src/Matching/ArrivalRules.php`, `src/Mapping/ArrivalCheck.php`, `bin/check_arrivals.php`, the mark in `ListingIngestService`, `OneAtATime::ARRIVAL` / `arrivalRuns()` / `arrivalSql()`, `Queries::SHOWS` `arrival`, `Queries::arrivalCheck()`, `NewVariantsController::left()` / `arrivalLines()` | the check on arrival, build 1 (M64, M65, U144, U145): a rules-only suggestion for a never-touched variant whose barcode or copied web address points at an item. `ArrivalRules::decide(facts)` is pure (no database): the table of its docblock, `BANDS` (Check, Can't tell, Conflict: never Key, Manual or New item), `REASONS` (each an exact key of `Words::BAND_REASON`), `REFUSALS`, `permaKey()` (= `tools/first_match/run.php`'s). `ArrivalCheck::look(id)` reads the facts without a lock (the refusals of `state()`, the barcode look-ups on `sku_barcode` and the 0005 index, the address look-up on `perma_key`, the item's representative variant normalised afresh, "is the item taken"); `write(look)` is ONE transaction: the listing's row lock, `state()` again, the snapshot (identity, address, `arrival_seq`, its own open suggestion) still the same, the run row made on first use, `Proposals::add()`, the basis's identity compared, a `CwException arrival_moved` to roll everything back; then `mark()`, a compare-and-set on `arrival_seq` outside the transaction. `run(opts)` is what the tool calls (dry by default; `apply`, `channel`, `backfill`, `limit`, `max_seconds`, `after_id`, `each`); `score(store, run)` is read only. A run writes two `match_run` rows under one run id: source `arrival` (clean matches) and `arrival_warned` (the rest), so the lists tell the kinds by run (`OneAtATime::arrivalRuns()`, on the index `match_proposal (match_run_id, status, band, lane, listing_id)` of 0027). The mark: `listing_profile.arrival_state` (NULL = was there before the check, `queued`, `done`; `names` is kept for build 2), `arrival_seq`, `arrival_channel_id`, `arrival_checked_at`, `arrival_outcome`; `perma_key` is a VIRTUAL generated column. To add an outcome: a line in `ArrivalRules::decide()`, its key in `REASONS` and in `Words::BAND_REASON`, a case in `ArrivalRulesTest` and in `ArrivalCheckTest::testEveryOutcome…`. To add a refusal: `ArrivalCheck::state()` (it is asked twice by construction), `ArrivalRules::REFUSALS`, `Words::ARRIVAL_LEFT`, and ONE test of its own in `ArrivalCheckTest` that makes the refusal the only thing the variant has. Build 2 (not started): the website's state, the name search, New |
| `tests/Unit/ArrivalRulesTest.php`, `tests/Integration/Mapping/ArrivalCheckTest.php`, `tests/Integration/Migration0027Test.php`, `tests/Support/ArrivalFixtures.php` (and additions to `ListingIngestTest`, `BulkDecisionsTest`, `KeyBulkTest`, `OneAtATimeScreenTest`, `NewVariantsScreenTest`, `WordsTest`, `QueueContextTest`, `DeployTest`) | the rules line by line and their order, never Key / Manual / New, `permaKey` run against the batch tool's own function body; every outcome end to end; one test per refusal (nothing in `match_run`, `match_proposal`, `match_decision`, no audit row but the run's own record); the refusals again under the lock; a variant that moved between the look and the write; a dry run that changes no table (`CHECKSUM TABLE` of every table); the timer's form, the alarm, the backfill; its own suggestion kept, replaced, and replaced by "no longer"; `Reband` and `KeyEligibility` leave it alone; the score; the tool through `proc_open`; the locks against a listing push (workers, the feed clock held: `op_worker.php` op `check_arrivals`); the migration on a scratch schema with profiles and the 0005 index, and why it says COPY; the intake's mark and both hashes pinned to main's values; the third one-at-a-time kind on the engine and on the three lists, with the setting off and on. `ArrivalFixtures`: `arrivalSetup(main, …stores)`, `mainItem()` (an item made from a main-store variant, the seed's way), `arrives()`, `arrivesAgain()`, `gtin(n)` (a real GTIN-13), `arrivalMark()`, `arrivalOpen()`; a test class that uses it puts the settings back (`arrivalTearDown()`, or its own restore) |
| `migrations/0028_arrival_site_state.sql`, `src/Mapping/SiteState.php`, the state in `ListingIngestService` (`ingest()`, `stateOnly()`), `bin/import_listings.php --state-only`, `BulkDecisions::seedCheck()`, the evidence and the snapshot of `ArrivalCheck`, `Queries::newVariantRows()` | the check on arrival, build 2a: the website state (M66, U146). Whether a variant is on sale on its website and whether it is a page heading: eight columns of `listing_profile` (the raw inputs and ONE date), in neither hash and not in the identity. `SiteState` is the one reader and is pure but for two reads: `fromExport()` / `parse()` (what a caller of the intake supplies; lenient: a wrong type is ignored and counted), `read()` (the stored columns, then the features, then a suggestion's evidence; none: not known), `against()` (the one thing asked of the features and the evidence BESIDE a stored state: what they say against the variant, a heading or a status that is not Published; the seed and New Variants ask it, so a stored state never makes them less strict than they were: the review of build 2a, F1), `notOnSale()`, `tooOld()` / `doubt()` (why a state cannot be relied on: `not_known`, `too_old` by the setting `mapping.arrival_state_max_days`), `heading()` (asks `Normalizer::normalize()` with the stored inputs and `publishedSiblings()`; null when an input is missing). "Not known" is never read as "on sale" or as "not a heading": write new readers through `SiteState`, never `features.variant_status` by hand. The intake writes a supplied state in the statement of whichever branch a profile takes (the listing's optional `website`, attached after both hashes); `stateOnly()` writes the eight columns and nothing else where the line's `profile_hash` is the stored one, and nothing at all without `--apply`. `bin/import_listings.php` reads its command line whole (`Cli::main(..., strict: true)`): write values with `=`. The seed skips `state_not_known` (a rule reversed on purpose: before, a state known from nowhere skipped nothing) |
| `tests/Unit/SiteStateTest.php`, `tests/Integration/Migration0028Test.php` (and additions to `ListingIngestTest`, `ImportToolsTest`, `BulkDecisionsTest`, `ArrivalCheckTest`, `NewVariantsScreenTest`, `BulkScreensTest`, `WordsTest`, `UiReviewFlowTest`; `MappingTestCase::siteState()`) | the lenient parse, the readers' order, the age, the heading against the Normalizer over every combination of its inputs and "not known" for each missing one; the migration (one copy, the columns exactly, the old code's statements on the new schema); the state in the three branches of the intake with its date, both hashes pinned with and without `website`, the queue rules; the state-only load through the tool (every table's checksum after a dry run; nothing but the state after an apply; lines that differ, unknown variants; unreadable command lines); the seed's new skips and the reversed rule; the evidence and the moved state of the check; the rows of New Variants. **A test profile has NO state unless the test gives it one**: `self::siteState($listingId, [column => value])` stores one as the state-only load does (on sale, a product of its own, as of now, unless told otherwise); `BulkDecisionsTest::withProfile()` and `NewVariantsScreenTest::found()` do it for an ordinary variant, and take `false` / `'site' => false` for "nothing is known". A bulk "create as new" of a row with no state is skipped (`state_not_known`) |
| `migrations/0029_stock_counts.sql` | Stock › Counts (pack A2a, CT1–CT18): `count_sheet` (a CNT record's header: sheet, reversal, line_reversal; the optional place) and `count_sheet_line` (an item once a sheet: what was held, the quantity counted or NULL, when, by whom, how; what the posting booked; the check of its difference), never deleted; the indexes `stock_balance (warehouse_id, counted_at, sku_id)` and `count_review (status, source, id)` online; the settings `counts.*` with baselines; the CNT rule switched off, its Not OK only recording, as its next version (review of 14 Oct 2026: changed in place, never applied to staging) |
| `src/Counts/` | `CountSheets` (open, add an item, enter quantities, recount, keep a count, post, stop, cancel; the scan box `find()`, the CSV and the upload with its preview; the read side), `CountSheetHandler` (CNT: books a sheet's counted lines through the engine's `count` movements, a line put back as an adjustment, the whole-sheet cancellation's header), `CountPosting::plan()` (what posting would do now: one answer for the page, validate() and post()), `CountTimes` (which lines share a count time; pure), `CountTolerance` (which differences are checked, the second person; pure), `CountReviews` (the differences to check: the list, the badge, accept, reject), `CountPlan` (the figures and "Next to count"), `CountInvariants` (C1–C5). The engine (`Movements`, `Stock`) is not changed by this pack: a count books only through `Movements::bookForDocument` |
| `src/Ui/Controller/CountsController.php`, views `stock_counts`, `stock_count_sheets`, `stock_count_reviews`, `stock_count`, `stock_count_before` | Stock › Counts: three segments (Next to count, Count sheets, Differences), a sheet's page and "Before you make it final". A quantity is saved by Enter in its box: the page's form, of which only the boxes changed on it (each carries `was_<line>`) are kept; no script talks to the network. Every statement of the lists was checked with EXPLAIN on a filled schema (CT13, `runs/a2_explain.txt`): check a new one the same way |
| `tests/Unit/{CountRulesTest,CountWordsTest}.php`, `tests/Integration/Migration0029Test.php`, `tests/Integration/Counts/`, `tests/Integration/UiKernel/CountsScreensTest.php` (and `UiSecurityTest` in slot `ui`) | the rules, the words, the migration, the service against the real engine (`CountsTestCase` pins the clock and puts settings and the CNT rule back), the nightly checks damaged one by one, the screens through the real kernel |
| `migrations/0033_sales_dispatch_views.sql` (pack D; the integrator may renumber it) | Stock › Sales (PD1-PD14): five settings (`sales.page_size`, `sales.default_days`, `sales.late_hours`, `sales.csv_max_rows`, `sales.scan_max_rows`) with their baselines, and four online indexes: `reservation (channel_id, status, origin, created_at, id)`, `reservation_unit (channel_id, state, reservation_id, unit_id)` and `(channel_id, state, dispatched_at, reservation_id)`, `stock_ledger (channel_id, movement_type, id)`; no data or column changes |
| `src/Ui/SalesViews.php`, `src/Ui/Controller/{SalesController,DispatchController,SalesReturnsController}.php`, views `sales_orders`, `sales_order`, `sales_dispatch`, `sales_returns` | Stock › Sales, read only (GET routes only, `sales.view`; `CW\Reservations` stays the only writer): Store orders by store, days (placed, UK days), state and way in, paged by key (`orderIds()`: one range of the placed index per (store, state, way in), merged; the unit states through the unit index with a scan limit, `byUnit()`), its CSV (`exportOrders()`); one order's sold units and each unit's ledger steps (`units()`, `steps()`); Dispatch (`waiting()`, `waitingCounts()`, `late()`, `sent()`, `sentFigures()`, `undone()`, `undoneSince()`, `undoneOrders()`; `since()` = UK midnight and Monday); Returns (`returns()`, `restock()`, `verifyOpen()`) and its CSV. The five settings are read in one statement (`settings()`), a bad stored value reads as the migration's. Check every new statement with EXPLAIN on a filled schema (PD12) before adding it |
| `tests/Integration/UiKernel/SalesScreensTest.php` (and `SectionsTest`, `WordsTest`, `UiTemplatesTest`, the Stock tab lists of `StockScreensTest`, `ReservationsScreenTest`, `StockOpsScreensTest`, `MenusTest`, the GETs of `UiSecurityTest` in slot `ui`) | the screens through the real kernel, every order made by the engine itself: the tab, its segments and who may open them (403 for the matching jobs and admin, 405 for a POST), each state, way in and days, the default days, page size and late hours read from their settings, paging by key and the scan limit, the CSVs, one order's units and steps with their Movements links, Dispatch's figures and four lists, Returns' outcomes (back in stock, back on sale, VERIFY with its recount, not paid, not linked), hostile text, nonsense in the address, the settings' baselines and rules, the indexes and the plan of every statement shape |
| `migrations/0032_supplier_invoices.sql` | pack C (IM7, `docs/decisions.md` PC1-PC17): the credit note kind (SCN), the invoice and return rules (no check after posting, the OK firsts off), the `over_difference` approval, `supplier_invoice` + `sinv_line`, `supplier_return` + `dn_line`, `purchase_doc_posting` (anchor), `invoice_value_event` (value-only changes waiting for IM8), seven settings |
| `migrations/0040_invoice_price_wins.sql` | pack C follow-up (`docs/decisions.md` PC19, the owner's decision of 15 Oct 2026): the switch `invoices.check_price` (off: the invoice's price is the right price, a price difference is information only; the units are checked either way). `InvoiceMath::line()` reads it from the rules; `Settings::UNUSED_WHEN_OFF` greys the two price tolerances on the Settings list while it is off |
| `src/Invoices/` | `SupplierInvoices` (invoices and credit notes: drafts, the quick way from a delivery, deliveries / orders / items / charges / credits, post, accept, the read side: lists, "To match", "Not invoiced yet"), `SupplierInvoiceHandler` (SINV, SCN: problems, posting, value events), `InvoiceMatch` (the 3-way match, stored and refreshed; `lockReceipts`; what each delivery has left to invoice, `storeReceipts`), `InvoiceMath` (pure: tolerances, a line's match, spreading to the penny), `SupplierReturns` + `SupplierReturnHandler` (DN: the return that is our debit note), `InvoiceInvariants` (V1-V7) |
| `src/Ui/Controller/{Invoices,SupplierReturns}Controller.php`, `views/{invoices,invoices_to_match,invoices_not_invoiced,supplier_invoice,supplier_returns,supplier_return}.php` | Purchasing › Invoices (Invoices, To match, Not invoiced yet, Credit notes) and Purchasing › Returns |
| `tests/Integration/Invoices/`, `tests/Integration/UiKernel/InvoiceScreensTest.php`, `Migration0032Test`, `tests/Unit/{InvoiceMath,InvoiceTemplates}Test.php` | the papers on the services (`InvoicesTestCase` extends the receipt test base: `orderedAndDelivered()`, `delivered()`, `invoiceDelivery()`, `postWithTotals()`, `rule()`), the screens, the schema, the arithmetic, the templates |
| **Pack F (go-live, CW side; `docs/decisions.md` PF1-PF14)** | |
| `migrations/0036_golive_queues_alerts.sql` | `relay_line` (one row per relayed ERPNext document line: booked or queued once, PF1), `queue_note` (append-only notes on the queues' rows, PF9), `alert_state` (what was alerted and when, PF10), the settings `alerts.*`, `housekeeping.*`, `relay.*` with their baselines |
| `src/Relay/RelayLines.php`, `src/Relay/RelayIntake.php`, `src/Api/Controller/RelayController.php` | the per-line claim of a store's relayed lines (used by `Movements::record` for goods_in / supplier_return / erp_sale), and `POST /v1/relay/reconciliations` (to the count-review queue) and `POST /v1/relay/modes` (to the policy-review queue, filtered) (PF1-PF3) |
| `src/Queues/{QueueRules,Suspense,Corrections,ModeChanges,Oversells}.php`, `src/Ui/Controller/StoreQueuesController.php`, views `queue_suspense`, `queue_corrections`, `queue_modes`, `queue_oversold`, `queue_filter` | Approvals › From the stores: the four queues and their answers (pick the item and book through `Movements::bookInTransaction`; confirm a correction as an adjustment at its moment; accept a mode change; handle an oversold order, notes) (PF5-PF9) |
| `src/Ops/{Alerts,AlertDispatch,AlertTransport,AlertSender,Housekeeping}.php`, `bin/health_alert.php`, `bin/housekeeping.php` | the alerts to a person (checks, once per window, webhook and e-mail, both empty by default; tests inject a transport and an answer-time probe) and the nightly clean-up (chunked, retention settings; the app login keeps the audit log) (PF10-PF12) |
| `bin/channel_set.php --relay=on\|off\|<grants>` | give or end a store's ERPNext relay (`ChannelAdmin::RELAY_GRANTS`; audited `channel.relay`, PF4) |
| `tests/Integration/{Relay,Queues}/`, `tests/Integration/Ops/{Alerts,Housekeeping}Test.php`, `tests/Integration/ApiKernel/RelayRoutesTest.php`, `tests/Integration/UiKernel/StoreQueuesScreensTest.php`, `tests/Unit/GoLiveQueuesUnitTest.php` | pack F's tests; `QueueListPlansTest` fills a schema and checks every new read's plan (PF13) |
| `migrations/0037_reorder_engine.sql` | Pack G, the reorder engine (PG1-PG16): `reorder_sales_day` (CW's own record of each store's sales by variant and UK day) and `reorder_fold_day`, `reorder_supplier` (spare days), `item_reorder.low_stock_level`, `reorder_line` (the stored list's compact columns, indexes in list order) and `reorder_line_detail` (each whole line as JSON), `reorder_job` (each step's last run), nine settings with their baselines |
| `src/Reorder/{SalesFold,ReorderRefresh,ReorderLines,ReorderJobs,LowStockLevels}.php`, `bin/reorder_refresh.php` | the fold of the stock ledger into the own record by UK day; the list worked out in chunks into the stored list (changed lines only, `read_at` keeps the later read, never deletes); the reader of the list's pages and download; the schedule (fold daily, demand once a UK day from `reorder.demand_hour`, list every `reorder.refresh_minutes`; cron every 5 minutes); the low-stock click (safety stock or each website's own, skip-and-list, feed rows last) |
| `src/Ui/Controller/ReorderEngineController.php`, views `reorder_suppliers`, `reorder_low_stock` | Purchasing › Reorder › Suppliers (spare days) and Low-stock warnings, and "Work out the list again now" |
| `tests/Unit/ReorderEngineUnitTest.php`, `tests/Integration/Migration0037Test.php`, `tests/Integration/Reorder/{ReorderEngine,SalesFold,LowStockLevels,ReorderJobs,ReorderToReceipt}Test.php`, `tests/Integration/UiKernel/ReorderEngineScreensTest.php` | worked examples and the safety stock, the own record by UK day and the demand's split, the stored list and its refresh, the low-stock figure on each variant, the schedule, packs to single items on the shelf, the screens, roles and CSRF |
| <!-- Pack H (0038) --> `src/Handovers/` | `Handovers` (hand-overs, TRD: drafts, lines with a price defaulting to the customer's last, add by barcode / CW number / words, post, stop, cancel, copy, read side, `invoice()` with the company-details gate), `HandoverHandler` (TRD: validate, size, post: the sums, snapshots, remembered prices, the customer's entry, then `trade_sale` through `Movements::bookForDocument`; reverse), `HandoverMath` (the invoice's sums; pure), `Counterparties` (the customers, their remembered prices, the CSV of old trade-invoice prices with skipped rows), `CounterpartyAccounts` (what each customer owes: payments received and their reversal), `HandoverPdf` (the invoice), `HandoverInvariants` (H1-H5). `docs/decisions.md` PH1-PH20 |
| `src/Duty/DutyRegister.php`, `src/Ui/Controller/{Handovers,Duty}Controller.php`, views `handovers`, `handover`, `handover_fields`, `handover_customers`, `handover_customer`, `handover_customer_fields`, `handover_balances`, `duty_register` | the duty register read from the receipts (no table) with the unstamped stock and its alarm; Stock > Hand-overs (Hand-overs, Customers, Balances) and Purchasing > Goods In > Duty register |
| `tests/Unit/HandoverUnitTest.php`, `tests/Integration/Handovers/`, `tests/Integration/Duty/DutyRegisterTest.php`, `tests/Integration/UiKernel/HandoverScreensTest.php` | pack H's tests: `HandoversTestCase` (on `StockOpsTestCase`) adds the services, settings put back in tearDown, `customer()`, `draftTo()`, `postedTo()` and `company()` (saved by a reviewer, confirmed or not: TestDb::clean empties `company_profile`, so a hand-over needs a VAT code on itself, its customer or the company); the duty register test builds real receipts on `ReceivingTestCase`; `tests/Support/HookedStatement` puts another session's write or a deadlock between a service's read and its write (on an extra `TestDb::connect()` connection only) <!-- end of pack H --> |
| <!-- Pack J (0041) --> `migrations/0041_incoming_stock.sql` | pack J (`docs/decisions.md` PJ1-PJ16): `stock_balance.incoming` and the ledger bucket `incoming` (both tables copied ONCE, ALGORITHM=COPY, LOCK=SHARED: about 40 microseconds a ledger row, PJ1), the invoice's way (`receive_mode`) and time (`submit_*`), `sinv_line.stamp_answer`, `invoice_arrival`, `goods_receipt_invoice`, `supplier_arrival_pref`, three settings (`arrivals.*`) with their baselines |
| `src/Arrivals/`, `bin/submit_invoices.php` | `ArrivalBooking` (what the invoice's and the receipt's postings and reversals write: the arrival rows and the `incoming` / `arrive` lines, inside those postings), `Arrivals` (a supplier's choices, Goods In › Arriving, "Receive", what a receipt did not bring and its claim, "book as received"), `InvoiceSchedule` (the timed postings; cron every minute), `ArrivalInvariants` (J1-J4, in `Invariants::check`), `UkTime` (a UK time typed: one moment, two in the hour the clocks go back, none in the hour they go forward) |
| `src/Ui/Controller/ArrivalsController.php`, views `arrivals`, `invoices_timed` (and boxes on `supplier_invoice`, `receipt`, `supplier`, `item`) | Purchasing › Goods In › Arriving, Purchasing › Invoices › Timed, the claim POST, the supplier's "Goods on their way" |
| `tests/Integration/Arrivals/` (`ArrivalsTestCase` on `InvoicesTestCase`: `invoiceOfOrder()`, `incoming()`, `arrivalRows()`, `job()`; `ArrivalsRaceTest` with `arrival_worker.php`, `ArrivalsReviewTest`: the Codex review's findings, with `HookedStatement`), `tests/Integration/Stock/IncomingStockTest.php`, `tests/Integration/UiKernel/ArrivalScreensTest.php`, `Migration0041Test` (and additions to `SectionsTest`, `MenusTest`, `ReceivingScreensTest`, `InvoiceScreensTest`, `SupplierInvoicesTest`, `WordsTest`, `DeployTest`) | pack J's tests <!-- end of pack J --> |

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
- Pack G: the list's pages and download read the STORED list (`reorder_line`), so a test that changes stock, orders or settings
  behind the services' back calls `ReorderFixtures::refreshLines()` before it looks (`listScenario()` ends with one); the services
  that change a line (drafts, item / brand / supplier settings, the low-stock click) refresh their own items. `SalesFold` and
  `ReorderJobs` take a clock: the own-record tests move ledger rows to chosen UK days with an UPDATE of `created_at` (admin login).
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
- Supplier invoice and return tests (`tests/Integration/Invoices/InvoicesTestCase`, pack C): built on the receipt test base, so a
  delivery is posted in one call (`orderedAndDelivered()`: an approved order and its delivery; `delivered()`: a delivery of given lines).
  After every test the invariants run, V4 among them: every posted invoice's stored match is worked out again, so a code path that
  changes what a match reads and forgets `InvoiceMatch::refresh` fails the test that takes it. A test that changes a tolerance uses
  `setting()` (the services are rebuilt; the match reads the settings on every call). Reversing a return of more than 10 units waits for
  a reviewer's OK (I32): the tests reverse small ones.


### Pack B: Item Management (items by hand, families, multipacks, repack; `docs/decisions.md` PB1-PB17)

| Path | What |
|---|---|
| `migrations/0031_items_families_repack.sql` | `item_family`, `item_family_member`, `item_pack`, `item_by_hand`, `item_name_guard`, `repack`; document type RPK and its series; four settings with baselines; `ix_channel_listing_units` (online) |
| `src/Items/HandMade.php` | an item made by hand (one transaction: checks, `DecisionService::mintByHand`, barcode, family, card), the check afterwards |
| `src/Items/Families.php` | families: create, rename, switch, one item in / out / moved, a pasted list in and ticked items out with the skip list |
| `src/Items/Packs.php` | pack items (N x a single), their flags beside the pack links, the owner's Q3 rule (`items.pack_suggest_single_sold`) |
| `src/Items/Repacks.php`, `RepackHandler.php` | Repack records: book in one step, the RPK handler (two lines, `adjustment` rows, the carried cost, never below zero for a protected item) |
| `src/Items/ItemsInvariants.php` | B1-B3, run by `Invariants::check` |
| `src/Ui/Controller/{HandMade,Families,Packs,Repack}Controller.php` | Products › Add Item, Families, Multipacks (Pack items, Pack links); Stock › Repack |

- Tests: `tests/Integration/Items/` (services against the real document base; `RepackTest` and `PackLinksUnchangedTest` extend
  `StockOpsTestCase`; `ItemsRaceTest` races two `items_worker.php` processes), `tests/Integration/UiKernel/ItemManagementScreensTest.php` (the screens as `cw_app`, who may, the CSRF token on
  every POST), `tests/Unit/ItemManagementUnitTest.php` (templates, words, permissions, tabs, settings). A test that changes one of the four
  settings puts its VALUE back (never `updated_actor`).


### Pack PO: "select or add new" on the purchase order, and the PO review fixes (`docs/decisions.md` PO1-PO12)

| Path | What |
|---|---|
| `migrations/0042_po_select_or_add.sql` | `purchase_order.terms_snapshot` (the terms as approved), `goods_receipt.invoice_part` and the UNIQUE key (supplier, invoice key, part) of a live invoice number |
| `src/Ui/Controller/PoAddNew.php` (was `PoSelectOrAddController`), view `po_supplier_needs` | "+ Add new supplier" / "+ Add new item" on the order's one page since PX (the step page and its routes are gone); a supplier not usable yet, in one line |
| `src/PurchaseOrders/PurchaseOrders.php` | + `createWithNewSupplier`, `setNewSupplier`, `addNewItem` (one transaction each), `reopen`; `reverseReceipt` on a closed order; `pdfData` prints the terms as approved |
| `src/PurchaseOrders/PoLinesFile.php` | `PRECEDENCE`: a row's most precise identifier names its line, the others are resolved within it |
| `src/Reorder/DraftPos.php`, `ReorderController` | a pick carries the supplier item and pack the list showed; a changed one is skipped (`why` changed, `?changed=` on the redirect) |
| `src/Receiving/GoodsReceipts.php` (`invoicePart`), `ReceiptPlan`, view `receipt_split` | one supplier invoice for several deliveries of one order (`setInvoice` `invoice_split`) |

- Tests: `tests/Integration/PurchaseOrders/{PoSelectOrAddTest,PurchaseOrderReviewFixesTest}.php`, `tests/Integration/Receiving/
  ClosedOrderAndSplitInvoiceTest.php`, `tests/Integration/Reorder/ReorderDraftAsShownTest.php`, `tests/Integration/UiKernel/
  PurchaseOrderFixesScreensTest.php` (every new POST: its CSRF token and its route's permission), `Migration0042Test`.
  A screen test that changes the PO rule (`document_type`, a seed table) puts its row back in tearDown. (`PoSelectOrAddScreensTest` and the
  supplier list's search box went with PO on one page, below.)


### PO on one page: the order's single view (`docs/decisions.md` PX1-PX10)

| Path | What |
|---|---|
| `src/Ui/Controller/PurchaseOrdersController.php` | `newPage` (`GET /ui/purchasing/orders/new`, no draft), `create` (`POST /ui/purchasing/orders`: FormOnce; the draft and the form applied in one transaction, rolled back when nothing is kept), `lines` (a draft's one form; `new_supplier` / `new_item` also FormOnce), `apply`, `asked`, `oneData`, `products` (`GET /ui/purchasing/orders/products`, the product box's choices) |
| `src/Ui/Controller/PoAddNew.php` | the "+ Add new" small forms' fields (`new_supplier_*`, `item_*`) and their refusals in words |
| `src/PurchaseOrders/PurchaseOrders.php` | `newSupplier` (the supplier alone, before the order exists), `setNewSupplier` (+ the details and the charge typed with it) |
| views `purchase_order_edit` (the one page: the board of products, "+ Add product" as its last row, the summary card), `po_products` (fragment) | |
| `public/ui/assets/app.js` | `combo(input, list, cfg)`: SM11's dropdown as one engine for Simple matching's cells, the supplier box and the product box; the "+ Add new" last line, `openPanel`, the folds `details[data-po-panel]` |
| `src/Ui/Words.php` | `PO_ONE`, `PO_ADD`, `askToAdd()` |

- Tests: `tests/Integration/UiKernel/PoOnePageScreensTest.php` (the page, the draft rule, both "+ Add new", who sees what, CSRF on every POST,
  without the script, the choices fragment), `PoSelectOrAddTest` (the services), `UiSecurityTest::testThePurchaseOrdersOnePageOverHttp` (slot ui),
  `UiUnitTest::testOneDropdownForTheMatchingCellsTheSupplierAndTheProduct` (the JS and CSS pins), `WordsTest::testThePurchaseOrderPageSaysAddNewAndWhoToAsk`.
  The new page's form is `$r->form('/ui/purchasing/orders')` (`csrf`, `form_key` first); a draft's is still `$r->form('/lines')` (`csrf`, `version`,
  `line_count`, `lines_editable` first). The page draws no lines-file form while the order fits it: a test posts that route with the page's
  `csrf` and `version`. A draft's "Cancel draft" (PX11) is `$r->form('/cancel')`: its reason and note sit in the summary card with
  `form="po-cancel"`, its token and version in the form `po-cancel` after the order's form. The dropdowns' behaviour (a choice kept as itself, nothing marked after new words, the choice kept after a
  refusal) is pinned by strings in `UiUnitTest`; the repo has no JS runner, and the node harness of the Codex review (a minimal DOM, app.js's
  own code) lives outside it (`/root/cw_work/runs/poone_js_harness/`, run on the test machine).


### The supplier invoice on one page, and the shared document pieces (`docs/decisions.md` PV1-PV17)

| Path | What |
|---|---|
| `src/Ui/Controller/InvoiceOnePage.php` | `newPage` (`GET /ui/purchasing/invoices/new`, nothing written), `create` (`POST /ui/purchasing/invoices` for an invoice: FormOnce, the draft and the form applied in one transaction), `lines` (an invoice draft's `POST {id}/lines`: the one form), `makeFinal` ("Save and make final": after the save commits, `SupplierInvoices::submit` as pack C/J post, PV16), `products` (`GET /ui/purchasing/invoices/products`, the item box's choices: the order page's `po_products` fragment), `editPage`, `sources` ("From"), `linesFit` (the field budget from `max_input_vars`, PV17) |
| `src/Ui/Controller/InvoicesController.php` | the routes; an invoice draft of its creator goes to `InvoiceOnePage::editPage`; the saved document's `party`, `docFacts`, `totalRows`; the list's due date and balance |
| `src/Invoices/SupplierInvoices.php` | header `due_date` (0052), `addItem(..., $supplierItemId, $unitsPerPack)`, `creditedOn()` |
| `migrations/0052_invoice_due_date.sql` | `supplier_invoice.due_date` (DATE NULL, INSTANT) |
| views `invoice_edit` (the one page), `invoices`, `supplier_invoice`, `purchase_orders`, `purchase_order`, `purchase_order_edit` | the lists, the new/edit pages and the saved documents in the shared look |
| `public/ui/assets/app.js` | `ALSO` (the invoice's item box: the same fragment from its own route), `select[data-pi-from]` (keeps the chosen supplier's deliveries and orders), `input[data-pi-due]` (the due date from the terms); the supplier and item boxes are the order page's (no new `combo()`) |

**The shared document pieces** (for the order, the supplier invoice and, later, the sales invoice; CSS in `app.css`'s "pi-one-page" block):

| Piece | Class / partial | Notes |
|---|---|---|
| Header grid | `.doc-grid` (on a `fieldset`; in `form.po-editor` too) | two columns, one under 640 px; `.doc-wide` spans both; `.doc-field` + `.doc-pair` = a select and its button |
| Lines table | `table.stack.list.board.doc-lines` | headings in `thead`; numbers `.num`; `.o-sub` the line under a cell; the last row `tr.grp-add` adds a line |
| Under the lines | `.doc-foot` | notes/files left (`.doc-notes`), totals right |
| Totals block | partial `doc_totals` (`label`, `rows`: label + value, `grand`, or `input` name/id/value) → `section.doc-totals` | right-aligned, `max-width` 24rem; inside the order's summary it is a `div.doc-totals` |
| Action bar (bottom) | partial `doc_bar` (`back`, `buttons`: value, label, primary) → `div.doc-bar` | sticky at the bottom of its form; on a phone it replaces the tab bar; "Leave without saving" first, the primary button last |
| Status pill | `.chip` via `$chip` / `$stateChip` | the one status-to-colour mapping (`Ui\Html::chip`) |
| Clean list | `table.stack.list.doc-list` with `a.row-link` in the number's cell | the whole row opens the document; in a card the number and pill come first |
| Document view | `article.doc-view` + partial `doc_party` (`party`: label, name, href, lines; `facts`: label, value, href) → `.doc-meta` | the other party's block beside the facts |
| Document actions (top) | `div.doc-actions` with buttons, links, inline forms and `details.doc-act` (summary as a button, `.doc-act-panel` under the bar) | an open step takes its own row under the bar |
| Words | `Words::DOC`, `Words::DOC_LIST` | "Leave without saving", Sub total, Total, Balance; the list's columns and filter |

- Tests: `tests/Integration/UiKernel/InvoiceOnePageScreensTest.php`, `tests/Integration/Invoices/InvoiceOnePageServiceTest.php`,
  `tests/Integration/Invoices/Migration0052Test.php`, `UiUnitTest::testTheInvoicePageUsesTheOrdersDropdownsAndTheSharedLayout` (the JS and CSS
  pins), `WordsTest::testTheInvoicePageSaysWhatTheOrdersPageSays`. An invoice draft's form is `$r->form('/ui/purchasing/invoices/{id}/lines')`
  (`csrf`, `version`, `line_count`, `form_key` first; "make final" is `action=post`); the new page's is `$r->form('/ui/purchasing/invoices')`
  (`csrf`, `form_key` first). Pass `true` as `form()`'s second argument to get the note (a text area).
- The page's JavaScript was driven in node with a minimal DOM (`/root/cw_work/runs/pione_js_harness/`, outside the repo: copy it into the
  worktree for a run through the slot runner, `/root/poone_js/node` on cw-test, and remove it); the repo pins it by strings in `UiUnitTest`.

**The approved design's shared classes** (`docs/decisions.md` PV18-PV20; CSS in `app.css`'s "pi-design" block, CW tokens only; the sales
invoice reuses them):

| Piece | Class / partial | Notes |
|---|---|---|
| Board group | `div.grp-block.doc-group.<tone>` > `h3.grp-title` (`button.grp-toggle` + `span.grp-count`) | the tone class (`needs`, `waiting`, `done`, `info`, `review`, `off`) colours the heading; it folds by `aria-controls` (app.js) |
| Board card | `div.table-wrap.doc-board` (id = the toggle's `aria-controls`) > `table.stack.list.board.doc-list` | white card, 6 px left edge in the group's `--t-fill`; last row `tr.grp-add` with `a.add-item` ("+ New ...") |
| Status cell | `td.c-status.doc-st.<tone>` | filled with the status colour, white words, centred, about 160 px; a pill-like block on a phone |
| Received meter | `span.doc-meter` > `progress` + the "160 / 240" words (`DOC_LIST received_of`) | green bar |
| Soft tag | `span.tag.ok` or `span.tag.warn` (board) or `span.tag.band.<tone>` (beside a title) | the match, the reviewer check |
| Passed date | `td.late` | red, semi-bold: due passed with a balance left |
| Toolbar Show menu | `select[name=show]` in `.tb-state` | orders: '' / `all` / `closed`; invoices: '' / `all` / `cancelled` |
| Status block | `div.doc-head` around `$head`; the chip in the `h1` (invoice) or `.ph-meta` (order) | 8 px corners, 32 px high |
| Action bar | `div.doc-actions` > `details.doc-more` (`summary` "More", `div.doc-more-menu`) + the next step (`.primary`) | the order's `po_action`, the invoice's `si_action` partial draw one action in the bar or the menu; `PurchaseOrdersController::actions()` picks the order's next step |
| Progress strip | `section.doc-progress` > `div.doc-step` (`p.doc-step-label`, `p.doc-step-big`, `progress.done` / `progress.review`) | four columns, two on a phone |
| Differences panel | `section.doc-diff#match` > `div.doc-diff-head` (h2 + total) + `table.stack.doc-diff-lines` with `td.diff` | amber left edge; cells that differ tinted amber |
| Large balance | `doc_party`'s `big` (label, value) → `div.doc-big` and `div.doc-meta.with-big` | right of the supplier's block; under it on a phone |
| Links in the facts | `doc_party`'s fact `links` (list of label + href) | "For PO-12 · GRN-4" |
| Soft button | `.btn.soft` | accent-soft fill ("Fill the lines") |
| Drop area | `.doc-drop` on the attach form or field | dashed box; a plain file input |
| Due hint | `span.hint[data-pi-due-hint][data-words]` in the due date's label | app.js fills `%s` with the chosen supplier's terms; hidden without terms |
| Phone lines | `table.stack.doc-lines` under 640 px | Qty, Price, Amount side by side (3 columns); the bar's two buttons equal |

- Words: `Words::ORDERS` (`group_*`, `show_*`, `add_row`), `Words::INVOICES` (`group_*`, `tag_*`, `show_*`, `needs_look`), `Words::DOC`
  (the strip and the panel), `Words::DOC_LIST` (the board's columns), `INVOICE_KIND` (`group_one`, `group_many`, `col`), `PI_ONE terms_hint`.
- Migration `0053_po_board_recent_days.sql`: the setting `po.board_recent_days` (14).
- Tests: `UiUnitTest::testTheBoardsAndTheDocumentsFollowTheApprovedDesign` (CSS pins; the shared pieces close every tag), the three
  `InvoiceOnePageScreensTest` screen tests named in PV20, `Migration0053Test`.


### Pack RB: the rollback file (`docs/decisions.md` RB1-RB34; `docs/ops.md` "The way back to ERPNext")

| Path | What |
|---|---|
| `migrations/0046_rollback_export.sql` | the settings `rollback.cw_warehouses`, `rollback.erp_warehouse`, `rollback.erp_company`, `rollback.max_rows` and their baselines (no table changes) |
| `src/Ops/RollbackExport.php` | the rules: which rows are carried (`WEBSITE_TYPES`, `CARRIED_TYPES`, `JOIN_TYPES`, `REASONS`), the ERPNext item of a CW item (through `ErpItemResolver`), one entry per document added up per item, the eight files; `run()` reads everything in one snapshot, two passes by id |
| `src/Queues/Corrections.php`, `Suspense.php` | `KEY`: the Idempotency-Key prefix of a queue booking (the rollback file knows ERPNext's bookings by it) |
| `bin/rollback_export.php` | the command: a new directory, 0700 / 0600, `manifest.json` last; `--since`, `--item-list` |
| `src/Ops/ErpOpeningFiles.php` | `itemMap()` also returns `codes`: each item code as ERPNext wrote it |
| `src/Ui/Words.php` | `ROLLBACK_REASON`, `ROLLBACK_SET`, the four settings' names and help, the topic `rollback` |

- Tests: `tests/Integration/Ops/RollbackExportTest.php` (extends `HandoversTestCase`: real stock records, hand-overs, relay and queue bookings;
  the cut-off is the database clock, as `stock_ledger.created_at`; the script run out of process on the test schema),
  `tests/Integration/Ops/RollbackListsTest.php` (extends `PurchaseOrderTestCase`), `Migration0046Test`. A new movement type is either in
  `CARRIED_TYPES` or in `WEBSITE_TYPES`; anything else is listed as `unknown_type` and never carried.
