<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Caller;
use CW\CwException;
use CW\Mapping\DecisionService;
use CW\Mapping\Proposals;

/**
 * Base for the linking tests (tests/Integration/Mapping): staff users with roles, a
 * DecisionService sharing the stock test's Stock/Reservations, proposals of a test run, and the
 * stock invariants asserted after every test (StockTestCase).
 */
abstract class MappingTestCase extends StockTestCase
{
    protected DecisionService $ds;
    protected Proposals $proposals;
    private int $staffSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ds = new DecisionService(self::$db, $this->stock, $this->res);
        $this->proposals = new Proposals(self::$db, $this->ds);
    }

    /** A staff user with $role (active unless told otherwise). */
    protected function staffUser(string $role, bool $active = true): Caller
    {
        $n = ++$this->staffSeq;
        $id = self::$db->insert(
            'INSERT INTO staff_user (username, display_name, email, role, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?)',
            ["{$role}{$n}@test.invalid", "{$role} {$n}", "{$role}{$n}@test.invalid", $role, 'x', $active ? 1 : 0],
        );
        return Caller::staff($id);
    }

    /** @param array<string, mixed> $req @return array<string, mixed> */
    protected function decide(Caller $who, string $action, int $listingId, array $req = []): array
    {
        return $this->ds->decide($who, ['action' => $action, 'listing_id' => $listingId,
            'expected_map_version' => $req['expected_map_version'] ?? $this->version($listingId)] + $req);
    }

    protected function version(int $listingId): int
    {
        return (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$listingId]);
    }

    /** @return array{sku_id: ?int, units_per_item: int, status: string, map_version: int} */
    protected function link(int $listingId): array
    {
        /** @var array{sku_id: ?int, units_per_item: int, status: string, map_version: int} */
        return self::$db->one('SELECT sku_id, units_per_item, status, map_version FROM channel_listing WHERE id = ?', [$listingId]);
    }

    /** A proposal of the run "test-run" (optionally moving the listing to suggested). @param array<string, mixed> $p */
    protected function propose(int $listingId, string $band, ?int $sku = null, array $p = [], bool $suggest = true, string $run = 'test-run'): int
    {
        $runId = $this->proposals->run($run, 'manual');
        return $this->proposals->add(Caller::system('test'), $listingId, $runId, ['band' => $band, 'proposed_sku_id' => $sku] + $p, $suggest)['proposal_id'];
    }

    /** Asserts that $fn throws a CwException with this status and code; returns it. */
    protected static function refused(int $status, string $code, callable $fn): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame([$status, $code], [$e->httpStatus, $e->errorCode], $e->getMessage());
            return $e;
        }
        self::fail("expected {$status} {$code}, nothing was thrown");
    }

    protected function decisions(): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM match_decision');
    }
}
