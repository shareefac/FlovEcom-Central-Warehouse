<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Availability;
use CW\Caller;
use CW\Clock;
use CW\Db;
use CW\Invariants;
use CW\Movements;
use CW\OpResult;
use CW\Reservations;
use CW\Stock;
use DateTimeImmutable;

/**
 * Base for the stock-core tests: fixtures (sites, items, listings), shorthands for every
 * operation (each call gets a fresh Idempotency-Key unless one is given) and the nightly
 * invariant check asserted after every test.
 */
abstract class StockTestCase extends IntegrationTestCase
{
    /**
     * CW's clock in stock tests (Reservations and Movements), unless a test moves $this->now. It
     * is later than every event time the tests use on 26 Sep, because caller-reported times may
     * be at most Clock::MAX_AHEAD_SEC in the future and a count at most 24 h old (R5).
     */
    public const NOW = '2026-09-26 18:00:00.000000';
    /** When item() books its opening stock: before every count the tests make (R13 looks after counted_at). */
    public const FIXTURE_TIME = '2026-09-26 00:00:00.000000';

    protected Stock $stock;
    protected Reservations $res;
    protected Movements $moves;
    protected Availability $avail;
    /** The clock Reservations sees; tests move it. */
    protected DateTimeImmutable $now;
    private int $keySeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new DateTimeImmutable(self::NOW, Clock::utc());
        $this->stock = new Stock(self::$db);
        $this->res = new Reservations(self::$db, $this->stock, fn (): DateTimeImmutable => $this->now);
        $this->moves = new Movements(self::$db, $this->stock, fn (): DateTimeImmutable => $this->now);
        $this->avail = new Availability(self::$db);
    }

    /** The §14 nightly invariant check, after every test. */
    protected function assertPostConditions(): void
    {
        self::assertSame([], Invariants::check(self::$db), 'invariants violated');
    }

    // ---- fixtures --------------------------------------------------------------------------

    /** A channel selling from $warehouse. */
    protected function site(string $code = 'vpg', string $mode = 'live', string $warehouse = 'MAIN'): Caller
    {
        $id = self::makeChannel($code, $mode);
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$id, self::warehouseId($warehouse)]);
        return Caller::channel($id, $code);
    }

    /**
     * An item with a policy and an opening on_hand at MAIN (booked as goods-in at FIXTURE_TIME, so
     * it is journalled and lies before every count a test makes).
     */
    protected function item(string $policy = 'strict', int $onHand = 0, ?string $name = null): int
    {
        $id = self::makeSku($name ?? 'Item ' . bin2hex(random_bytes(3)));
        if ($policy !== 'legacy') {
            $this->ok($this->stock->setPolicy(self::staff(), $id, $policy, $this->key('policy')));
        }
        if ($onHand !== 0) {
            $now = $this->now;
            $this->now = new DateTimeImmutable(self::FIXTURE_TIME, Clock::utc());
            try {
                $this->book('goods_in', $id, $onHand);
            } finally {
                $this->now = $now;
            }
        }
        return $id;
    }

    /** A listing of $site; mapped to $sku (or unmapped when null). */
    protected function listing(Caller $site, string $variant, ?int $sku, int $u = 1, ?string $status = null): int
    {
        return self::$db->insert(
            'INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, ?, ?, ?, ?)',
            [$site->channelId, $variant, $sku, $u, $status ?? ($sku === null ? 'unmapped' : 'mapped')],
        );
    }

    /**
     * What the DecisionService will do: change a link, adopt the units the listing sold while it
     * was unlinked (R4), and tell the feed, in one transaction.
     */
    protected function relink(int $listingId, ?int $sku, string $status = 'mapped', ?int $u = null): void
    {
        self::$db->transaction(function (Db $db) use ($listingId, $sku, $status, $u): void {
            $db->exec(
                'UPDATE channel_listing SET sku_id = ?, status = ?, units_per_item = COALESCE(?, units_per_item), map_version = map_version + 1 WHERE id = ?',
                [$sku, $status, $u, $listingId],
            );
            $this->res->adoptUnlinkedUnits(self::staff(), $listingId);
            $this->stock->listingChanged($listingId, 'link');
        });
    }

    /** Grants a site ERP-relay movement types (R17; a channel starts with none). */
    protected function grant(Caller $site, string ...$types): void
    {
        self::$db->exec('UPDATE channel SET movement_types = ? WHERE id = ?', [json_encode(array_values($types), JSON_THROW_ON_ERROR), $site->channelId]);
    }

    protected static function staff(): Caller
    {
        return Caller::staff(1);
    }

    // ---- operations --------------------------------------------------------------------------

    protected function key(string $prefix = 'k'): string
    {
        return $prefix . '-' . (++$this->keySeq) . '-' . bin2hex(random_bytes(4));
    }

    /** @return array{variant_id: string, qty: int, unit_ids: list<string>} */
    protected static function line(string $variant, string ...$units): array
    {
        return ['variant_id' => $variant, 'qty' => count($units), 'unit_ids' => array_values($units)];
    }

    /** @param list<array<string, mixed>> $lines */
    protected function reserve(Caller $site, string $ref, array $lines, ?string $key = null): OpResult
    {
        return $this->res->reserve($site, $ref, $lines, $key ?? $this->key('reserve'));
    }

    /** @param list<array<string, mixed>> $lines */
    protected function commit(Caller $site, string $ref, array $lines, string $origin = 'reserved', ?string $key = null): OpResult
    {
        return $this->res->commit($site, $ref, $lines, $origin, $key ?? $this->key('commit'));
    }

    protected function release(Caller $site, string $ref, ?int $attempt = null, ?string $key = null): OpResult
    {
        return $this->res->release($site, $ref, $attempt, $key ?? $this->key('release'));
    }

    /** @param list<string> $units */
    protected function ship(Caller $site, string $ref, array $units, string $dispatchedAt = '2026-09-26T12:00:00Z', ?string $key = null): OpResult
    {
        return $this->res->ship($site, $ref, $units, $dispatchedAt, $key ?? $this->key('ship'));
    }

    /** Books a movement of one item as staff ($unitCost: the line's unit_cost, I1). */
    protected function book(string $type, int $sku, int $qty, string $warehouse = 'MAIN', ?string $countedAt = null, ?string $unitCost = null): OpResult
    {
        $req = ['type' => $type, 'warehouse' => $warehouse, 'doc_ref' => 'DOC-' . bin2hex(random_bytes(3)),
            'lines' => [['sku_id' => $sku, 'qty' => $qty] + ($unitCost === null ? [] : ['unit_cost' => $unitCost])]];
        if ($countedAt !== null) {
            $req['counted_at'] = $countedAt;
        }
        return $this->ok($this->moves->record(self::staff(), $req, $this->key('move')));
    }

    protected function ok(OpResult $r): OpResult
    {
        self::assertTrue($r->ok(), 'expected 2xx, got ' . $r->status . ' ' . json_encode($r->body));
        return $r;
    }

    // ---- reading -------------------------------------------------------------------------------

    /** @return array{on_hand: int, allocated: int, held: int} */
    protected function bal(int $sku, string $warehouse = 'MAIN'): array
    {
        $r = self::$db->one('SELECT on_hand, allocated, held FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId($warehouse), $sku]);
        return $r === null ? ['on_hand' => 0, 'allocated' => 0, 'held' => 0]
            : ['on_hand' => (int) $r['on_hand'], 'allocated' => (int) $r['allocated'], 'held' => (int) $r['held']];
    }

    protected function assertBal(int $onHand, int $allocated, int $held, int $sku, string $warehouse = 'MAIN'): void
    {
        self::assertSame(['on_hand' => $onHand, 'allocated' => $allocated, 'held' => $held], $this->bal($sku, $warehouse), "balance {$warehouse}:{$sku}");
    }

    protected function unitState(Caller $site, string $unit): ?string
    {
        $s = self::$db->value('SELECT state FROM reservation_unit WHERE channel_id = ? AND unit_id = ?', [$site->channelId, $unit]);
        return $s === null ? null : (string) $s;
    }

    /** @return array<string, mixed>|null */
    protected function reservation(Caller $site, string $ref): ?array
    {
        return self::$db->one('SELECT * FROM reservation WHERE channel_id = ? AND order_ref = ?', [$site->channelId, $ref]);
    }

    /** One listing view by variant. @return array<string, mixed> */
    protected function view(Caller $site, string $variant): array
    {
        $v = $this->avail->forVariants((int) $site->channelId, [$variant]);
        self::assertCount(1, $v);
        return $v[0];
    }

    protected function ledgerCount(): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger');
    }
}
