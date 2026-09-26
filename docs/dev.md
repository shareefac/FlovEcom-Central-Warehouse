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
  (sha256 stored, never the key). Changing a channel's allowlist or mode is SQL for now.

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
| `src/Reservations.php` | reserve / commit / release / cancel / ship / unship / return / opening orders / `expireDue()` |
| `src/Movements.php` | goods_in, supplier_return, erp_sale, adjustment, count, write_off, transfer_out/in |
| `src/Availability.php` | per-listing views, `changes()` feed, `snapshot()` paging |
| `src/Idempotency.php`, `Caller.php`, `OpResult.php`, `CwException.php` | one effect per key (D27–D29) |
| `src/Invariants.php` | the nightly bucket check (D44), asserted after every stock test |
| `tests/Integration/Stock/` | stock-core tests (§14 CW automated, minus HTTP); `HammerTest` uses `tests/Support/WorkerPool` (≤ 12 connections); `LockOrderTest` / `LinkAdoptionTest` force interleavings with `tests/Support/OpWorkers` + `op_worker.php` (waits on `performance_schema.data_locks`) |
| `bin/migrate.php`, `bin/setup_staging.php` | CLI |
| `public/index.php`, `src/Api/` | the /v1 HTTP API: `Kernel` (pipeline + error mapping), `Router`, `Auth` + `IpAllowlist` + `ApiKey`, `Request`/`Response` (envelope), `Context`, `Input`, `Controller/*` (one per resource) |
| `src/ListingProfiles.php`, `src/Heartbeat.php`, `src/Purchasing.php`, `src/ChannelAdmin.php` | `PUT /v1/listings`, `POST /v1/heartbeat`, `GET /v1/purchasing`, channel creation / key rotation |
| `bin/create_channel.php`, `bin/rotate_key.php` | channel + key tools (connect as `cw_app`) |
| `deploy/staging/` | php-fpm pool, Apache vhost, `install_api.sh` |
| `tests/Integration/Api*Test.php`, `tests/Support/ApiTestCase.php` | HTTP tests (slot `api` only) |
| `tests/concurrency/hammer.php` | the §14 concurrency hammer: forked workers (≤ 30 connections), 4 scenarios, PASS/FAIL table (`docs/ops.md`) |
| `tests/Integration/Stock/IdempotencyRaceTest.php` | same-key races (H1); `tests/Integration/Ops/` — pruner, the bin/ jobs end to end |
| `src/Ops/` | `Cli` (shared job frame, H3), `Snapshot` (consistent read, H5), `ChangePruner` (H2), `ChannelHealth` (H8) |
| `bin/expire_reservations.php`, `bin/prune_changes.php`, `bin/invariants.php`, `bin/health_alert.php` | scheduled jobs (cron on staging: `docs/ops.md`) |
| `deploy/staging/install_cron.sh`, `cw-staging.cron`, `logrotate-cw.conf` | staging cron install (`/opt/cw-staging`, `/etc/cron.d/cw-staging`) |
| `scripts/remote.sh` | sync + run on staging |
| `docs/decisions.md` | choices made where the plan is silent |
| `docs/ops.md` | runbook: scheduled jobs, staging cron, the hammer |
