<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Tests\Support\StockTestCase;

/**
 * I6: Idempotency-Keys stored before C0 still replay after it. The three request hashes below were
 * printed by this test on the code BEFORE C0 (b390a40, 2 Oct 2026, slot i1c0) and pinned: a request
 * without `unit_cost` must keep its canonical form byte for byte, or a site's or a person's retry of
 * a movement booked before the deploy would answer 422 idempotency_key_reused instead of replaying.
 *
 * The items have fixed ids and codes, because `sku_id` is part of the hashed request.
 */
final class MovementHashCompatTest extends StockTestCase
{
    private const HASH_GOODS_IN = 'c8b9eaf4b9e03f7476e56fb69b49b0cd60be3b4dc0d821cb83820d653d85dcca';
    private const HASH_COUNT = '1a7213a95241e0fc91b09308b6c73f025d816eda151c4f74c7f02820067ecd2f';
    private const HASH_ADJUSTMENT = '45a533dcaa787b55a2230b5dd7b02cc7ea3379968846ff9093dd23f015d66485';

    private const SKU_A = 900_001;
    private const SKU_B = 900_002;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::SKU_A, self::SKU_B] as $id) {
            self::$db->exec('INSERT INTO sku (id, code, name) VALUES (?, ?, ?)', [$id, sprintf('CW-%06d', $id), 'Compat item ' . $id]);
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> name => [request, pinned hash] */
    private static function requests(): array
    {
        return [
            // (a) goods_in by sku_id, with line_index, warehouse and note
            'goods_in' => [['type' => 'goods_in', 'doc_ref' => 'PINV-COMPAT-1', 'warehouse' => 'MAIN', 'note' => 'compat golden',
                'lines' => [['sku_id' => self::SKU_A, 'qty' => 12, 'line_index' => 3]]], self::HASH_GOODS_IN],
            // (b) a backdated count with counted_at
            'count' => [['type' => 'count', 'warehouse' => 'VERIFY', 'counted_at' => '2026-09-20T09:30:00Z', 'backdated' => true,
                'lines' => [['sku_code' => 'CW-900001', 'qty' => 4]]], self::HASH_COUNT],
            // (c) an adjustment with two lines (one at another warehouse)
            'adjustment' => [['type' => 'adjustment', 'doc_ref' => 'ADJ-COMPAT-1', 'note' => 'two lines',
                'lines' => [['sku_id' => self::SKU_A, 'qty' => -2], ['sku_code' => 'CW-900002', 'qty' => 5, 'warehouse' => 'VERIFY']]], self::HASH_ADJUSTMENT],
        ];
    }

    public function testRequestHashesAreThoseStoredBeforeC0(): void
    {
        $pinned = [];
        $stored = [];
        foreach (self::requests() as $name => [$req, $hash]) {
            $key = 'compat-' . $name;
            $this->ok($this->moves->record(self::staff(), $req, $key));
            $pinned[$name] = $hash;
            $stored[$name] = (string) self::$db->value("SELECT request_hash FROM idempotency WHERE source = 'staff' AND idem_key = ?", [$key]);
            fwrite(STDERR, "\n[hash-compat] {$name} {$stored[$name]}");
        }
        self::assertSame($pinned, $stored, 'a request hash changed: keys stored before C0 would answer 422 idempotency_key_reused');
    }

    /** A key stored by the old code (its row written here with the pinned hash) replays: no 422, no second effect. */
    public function testAKeyStoredBeforeC0StillReplays(): void
    {
        foreach (self::requests() as $name => [$req, $pinned]) {
            $key = 'old-' . $name;
            $stored = ['result' => 'recorded', 'type' => $req['type'], 'stored' => 'before C0'];
            self::$db->exec(
                "INSERT INTO idempotency (channel_id, source, idem_key, method, path, request_hash, response_status, response_body) "
                . "VALUES (NULL, 'staff', ?, 'POST', '/v1/movements', ?, 200, ?)",
                [$key, $pinned, json_encode($stored, JSON_THROW_ON_ERROR)],
            );
            $before = $this->ledgerCount();
            $r = $this->moves->record(self::staff(), $req, $key);
            self::assertSame(200, $r->status, $name . ' ' . json_encode($r->body));
            self::assertTrue($r->replayed, $name);
            self::assertEquals($stored, $r->body, $name); // a JSON column: key order is the server's
            self::assertSame($before, $this->ledgerCount(), "{$name}: the replay booked something");
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger'));
    }
}
