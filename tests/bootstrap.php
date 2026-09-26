<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap: autoload, UTC, and a fresh migrated test schema.
 *
 * The schema is CW_DB_NAME or cw_test_<CW_SLOT> (must start with cw_test_). It is dropped,
 * re-created and migrated once per run, as the admin login from /etc/cw/db.env.
 * CW_TEST_DB=0 skips the database entirely (unit tests only, e.g. on a box without db.env).
 * Run remotely: scripts/remote.sh <slot> vendor/bin/phpunit
 */

use CW\Tests\Support\TestDb;

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

if (getenv('CW_TEST_DB') === '0') {
    return;
}

try {
    $name = TestDb::name();
    putenv('CW_DB_NAME=' . $name);
    $started = hrtime(true);
    TestDb::reset();
    fwrite(STDERR, sprintf("[bootstrap] test schema %s reset + migrated in %d ms\n", $name, intdiv(hrtime(true) - $started, 1_000_000)));
} catch (\Throwable $e) {
    fwrite(STDERR, '[bootstrap] cannot prepare the test schema: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(2);
}
