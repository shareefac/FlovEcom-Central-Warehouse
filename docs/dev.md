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
- `tests/Unit/UiTemplatesTest` fails the build when a template prints anything that did not go through
  `$e/$n/$dec/$dt/$u/$pct/$partial`, or uses an inline script, style or event handler, or a POST form lacks
  the CSRF field. Templates get no other helpers: add a helper in `View::render` and to that test together.

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
| `src/Mapping/DecisionService.php` | the only writer of a listing's link: link / unlink / new_item / ignore / reject / suggest / merge_skus, two-person approve / withdraw, the seed `mintAndLink`, the listing-row insert helper (M3–M10) |
| `src/Mapping/ListingIngestService.php`, `src/Mapping/Proposals.php` | `PUT /v1/listings` + the import tool (M11); match runs and proposals (M13) |
| `src/Staff/` | `StaffAdmin` (create, reset), `Totp`, `SecretBox` (TOTP secret at rest, `ui_secret_key`), `AppEnvFile` (M2, M15, U23) |
| `src/Mapping/BarcodeSeeder.php`, `bin/seed_barcodes.php` | `sku_barcode` from the listings the items were minted from (M25) |
| `migrations/0005_listing_barcodes_index.sql` | multi-valued index on `listing_profile.barcodes` (where else a barcode is, M25) |
| `bin/import_listings.php`, `bin/mint_vpg.php`, `bin/import_proposals.php`, `bin/create_staff.php`, `bin/reset_staff.php` | first-match load and staff accounts (`docs/ops.md`, M14, M15, U23) |
| `bin/reband_proposals.php`, `bin/sample_proposals.php`, `bin/key_bulk_hold.php`, `bin/bulk_confirm_key.php`, `bin/bulk_unlink.php` | Key spot-check, the hold of screened listings, and the bulk confirm (`docs/ops.md`, M26-M28, M30) |
| `src/Matching/`, `tools/first_match/`, `tests/matching/run.php`, `tests/fixtures/matching/golden_listings.json` | the matching engine (Normalizer, TitlePattern, Flavour, Veto, Candidates, Band, JudgeCard; `Form` = the one form enum, `JudgeScratch` = one scratch directory per judge chunk, M29) and the first-match tools; the plain golden runner (`php tests/matching/run.php`, no Composer) also runs inside the suite as `tests/Unit/MatchingGoldenTest.php`; regenerate the fixture with `tools/first_match/extract_fixture.php` |
| `migrations/0012_key_bulk.sql`, `migrations/0014_key_bulk_hold.sql`, `src/Matching/StoredBand.php`, `src/Mapping/{ProposalBasis,KeyEligibility,Reband,KeySample,KeyHold,KeyBulk}.php`, `src/Ui/Controller/SamplesController.php` | proposal bases, the replay of a stored band, the re-banding, the spot-check sample and its screen, the hold of a listing from every bulk confirm (by the listing, M30), the bulk confirm and its undo (M26-M28) |
| `tests/Integration/Mapping/`, `tests/Support/MappingTestCase.php`, `tests/fixtures/mapping/` | DecisionService rules, listing intake, the tools end to end on small fixtures |
| `bin/create_channel.php`, `bin/rotate_key.php`, `bin/channel_set.php` | channel, key, mode and allowlist tools (connect as `cw_app`; A11, A14) |
| `src/Auth/` | staff sign-in for the UI: `Login` (password + TOTP), `LoginLimiter`, `Sessions` (`staff_session`, hashed ids), `Csrf`, `StaffIdentity` (M2, U1-U4) |
| `src/Ui/` | the staff screens: `Kernel` (route, session, role, same-origin + CSRF, hardened headers), `Router`/`Route`, `Context`, `UiRequest`, `HtmlResponse`, `Html` (escaping), `View` (templates), `Queries` (read side), `QueueContext`, `Compare`, `Assets`; `Controller/{Auth,Dashboard,Review,Item,Search}Controller`; templates in `src/Ui/views/`; the two static files in `public/ui/assets/` |
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
| `src/Auth/Permissions.php` | THE permission map: the 14 roles, `MAP` (permission → roles), `MENU`, `can()`, `checkRoleSet()` (admin only with viewer/accountant/auditor), `menu()`; routes name a permission (I11, I12, I14, I16) |
| `src/Staff/StaffRoles.php`, `StaffAdmin::setRoles`/`setActive` | read side of `staff_role` (live roles, history, counts) and the role/account changes of the People screen and `reset_staff --roles` (I13) |
| `src/Ui/Controller/PeopleController.php`, `views/{people,person,home}.php` | `/ui/people` (list, person page, role form, switch on/off) and the home page of roles without the linking screens (I13, I14) |
| `tests/Unit/PermissionsTest.php`, `tests/Integration/UiKernel/{Menus,PeopleScreen}Test.php`, `tests/Integration/Staff/StaffRolesTest.php`, `Migration0007Test` | the map, the owner's menu acceptance test, the People screen, `StaffAdmin` + CLI, the 0007 backfill; `KernelUiTestCase::nav()` reads a page's menu; `uiUser()`/`staffUser()` take one role or a list |
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

Document and file tests notes:
- `TestDb::clean()` keeps the seeded `reason_code` and `document_type` rows (a test that changes one restores it) and sets
  every `number_series` back to 0 (pad 6) instead of deleting it.
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
- `KernelUiTestCase::kernel()` registers the production handlers (`DocumentHandlers::all`: PO since the I-2 pos task) plus the
  test-only ADJ fixture; `DocumentTestCase` still registers ADJ only (so `type_not_built` is tested with GRN there). A test that
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
- Composer: `openspout/openspout` ^4.32 (v4.32.0; needs ext-dom, fileinfo, filter, libxml, xmlreader, zip — all on staging —
  and no gd) is in `require` (I58).
- Composer: `setasign/fpdf` is in `require` (install_cron.sh runs `composer --no-dev`). It is pinned to 1.8.2 because
  every later release declares `ext-gd`, which staging lacks (I24): `composer require setasign/fpdf:^1.9` once
  `php8.3-gd` is installed. Change dependencies with `composer require` in a slot (`scripts/remote.sh <slot> 'COMPOSER_ALLOW_SUPERUSER=1
  composer require ...'`) and copy `composer.json` and `composer.lock` back with rsync: a hand-edited `composer.json`
  makes remote.sh's `composer install` refuse ("not present in the lock file").
