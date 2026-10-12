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

    /**
     * A staff user holding $roles (one role or several; staff_role rows, 0007), active unless told otherwise.
     *
     * @param string|list<string> $roles
     */
    protected function staffUser(string|array $roles, bool $active = true): Caller
    {
        $roles = is_string($roles) ? [$roles] : $roles;
        $n = ++$this->staffSeq;
        $label = implode('-', $roles);
        $id = self::$db->insert(
            'INSERT INTO staff_user (username, display_name, email, password_hash, is_active) VALUES (?, ?, ?, ?, ?)',
            ["{$label}{$n}@test.invalid", "{$label} {$n}", "{$label}{$n}@test.invalid", 'x', $active ? 1 : 0],
        );
        foreach ($roles as $role) {
            self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$id, $role]);
        }
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

    /**
     * Stores a website state on a variant's profile (the columns of 0028; docs/decisions.md M66) as the state-only load writes
     * it: the columns and nothing else, so no mark of the check on arrival moves. On sale and something that is sold, a product
     * of its own, as of now, unless $over says otherwise (column => value; `site_state_at` a UTC time). A profile no test gave a
     * state has none: nothing is known of it.
     *
     * @param array<string, mixed> $over
     */
    protected static function siteState(int $listingId, array $over = []): void
    {
        $s = $over + ['site_variant_status' => 'Published', 'site_product_status' => 'Published', 'site_product_type' => 'simple', 'site_product_id' => 'p' . $listingId,
            'site_is_landing' => 0, 'site_is_default' => 1, 'site_product_link' => null, 'site_state_at' => \CW\Clock::db(\CW\Clock::now())];
        self::$db->exec('UPDATE listing_profile SET ' . implode(' = ?, ', array_keys($s)) . ' = ? WHERE listing_id = ?', [...array_values($s), $listingId]);
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
