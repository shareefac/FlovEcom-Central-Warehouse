<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Schema\Migrator;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * The undo of profit step 1 (the final review of 6d59eda, a-HIGH1 / b-HIGH1; docs/decisions.md PR94). Once 0074 and 0075 are recorded in
 * schema_migrations, the code of the release before (c40c050) refuses the schema: its CLI guard (Cli::requireCurrentSchema, every cron job,
 * the reservation expiry too) and its installer (bin/migrate.php --status) stop at "migrations this code does not have", so an undo to it as
 * it is would stop the expiry of unpaid holds. The undo is the ROLLBACK RELEASE: c40c050's code with exactly these migration files of this
 * release added (no code). This test holds the recipe to the branch: every migration this branch adds after c40c050's last (0073) is in
 * ROLLBACK_FILES, and with them a schema migrated by this release passes the guard of a release that has no code of its own for them, while
 * without them it is refused as above.
 */
final class RollbackReleaseTest extends IntegrationTestCase
{
    /**
     * The last migration of the release before. CW-1155 (docs/decisions.md RR20): the release before was b35949d (profit step 1, live), whose
     * last is 0075; the rollback release of CW-1155 was b35949d's code with 0076 added, byte for byte (profit step 1's was c40c050 with 0074 and
     * 0075, the same recipe). The delivery bills (docs/decisions.md DL22): the release before is b54163a (CW-1155, live), whose last is 0076;
     * the rollback release is b54163a's code with 0077 added, byte for byte. Royal Mail's bills (docs/decisions.md DL69): the release before is
     * 14c6e31 (DPD bills live), whose last is 0077; the rollback release is 14c6e31's code with 0078 added, byte for byte. The expenses (CW-1150,
     * docs/decisions.md EX) go live on top of Royal Mail's bills: the release before is ae5f36e (Royal Mail's bills), whose last is 0078; the
     * plain rollback source is ae5f36e's code with 0079 added, byte for byte.
     */
    public const BEFORE_LAST = '0079_expenses.sql';
    /**
     * The migration files the rollback release takes from this release (README of build/A, "Undo"; CW-1155: RR20; the delivery bills: DL22).
     * PayPal's fees (CW-1172, docs/decisions.md PPF20): the release before is 27dea71 (the expense total with VAT, live 25 Nov 12:31; f044e2f
     * and 0e3db2a before it), whose last is 0079; the plain rollback source is 27dea71's code with 0080 added, byte for byte (no undo release is
     * built: CW is fixed forward).
     */
    public const ROLLBACK_FILES = ['0080_paypal_fees.sql'];

    private ?string $dir = null;
    private ?string $rollback = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('rb', $this->dir);
        }
        if ($this->rollback !== null) {
            exec('rm -rf ' . escapeshellarg($this->rollback));
        }
    }

    public function testTheRollbackReleaseNamesEveryMigrationOfThisRelease(): void
    {
        $files = array_map('basename', glob(dirname(__DIR__, 3) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $after = array_values(array_filter($files, static fn (string $f): bool => strcmp($f, self::BEFORE_LAST) > 0));
        self::assertSame(self::ROLLBACK_FILES, $after, 'a migration added to this release is added to the rollback release (README "Undo") too');
    }

    public function testTheReleaseBeforeRunsOnTheSchemaOnlyWithTheseFiles(): void
    {
        $files = array_map('basename', glob(dirname(__DIR__, 3) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        [$db, $this->dir] = MigrationFixture::upTo('rb', (string) end($files));
        self::assertSame([], (new Migrator($db, $this->dir))->pending(), 'migrated by this release');
        $applied = array_map('strval', $db->column('SELECT version FROM schema_migrations'));

        // The release before as it is: its migrations stop at BEFORE_LAST.
        $this->rollback = sys_get_temp_dir() . '/cw_rollback_' . bin2hex(random_bytes(4));
        mkdir($this->rollback, 0700);
        foreach ($files as $f) {
            if (strcmp($f, self::BEFORE_LAST) <= 0) {
                copy("{$this->dir}/{$f}", "{$this->rollback}/{$f}");
            }
        }
        $before = new Migrator($db, $this->rollback);
        self::assertSame(self::ROLLBACK_FILES, array_values(array_diff($applied, array_keys($before->files()))),
            'the CLI guard of the release before (Cli::requireCurrentSchema) finds these unknown and refuses every job, the expiry too');
        try {
            $before->pending();
            self::fail('the installer\'s status of the release before refuses the schema');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString(self::ROLLBACK_FILES[0], $e->getMessage());
        }

        // The rollback release: the same code with these files (byte for byte) added.
        foreach (self::ROLLBACK_FILES as $f) {
            copy(dirname(__DIR__, 3) . "/migrations/{$f}", "{$this->rollback}/{$f}");
        }
        $rollback = new Migrator($db, $this->rollback);
        self::assertSame([], $rollback->pending(), 'nothing pending, every checksum the one recorded: the installer switches without --migrate');
        self::assertSame([], array_values(array_diff($applied, array_keys($rollback->files()))), 'no migration unknown: every job runs');
        self::assertSame([], array_values(array_diff(array_keys($rollback->files()), $applied)), 'none pending: every job runs');
    }
}
