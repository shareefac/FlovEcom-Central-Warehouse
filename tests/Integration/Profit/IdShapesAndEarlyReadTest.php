<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\CwException;
use CW\Profit\OrderSnapshots;
use CW\Profit\SnapshotImport;
use CW\Tests\Support\StockTestCase;

/**
 * The last fix round before the third final review (final2/FIX_BRIEF_A3.md), on the database:
 * G1 (Codex r11 F12 PARTLY, HIGH, privacy), shown failing first on f2b6230: a snapshot whose order ref, line_id, variant_id, parent_ref or
 * sub_of is a person's name or an address is refused on every way in (the API's accept, a bulk file's store, a day file) and nothing of it is
 * stored: no order_snapshot, no queue row, no sale line. The connector's real shapes are stored.
 * G3 (Codex r11 F19 PARTLY): an order placed before the store's first reservation whose line is broken (qty 2), next to a valid order of the
 * same day file, refuses the file whole (its ref and placing are read before the rest of it): nothing written.
 */
final class IdShapesAndEarlyReadTest extends StockTestCase
{
    private const WORDS = ['Jane.Smith', 'jane.smith', '12 Preston Road'];
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_r12_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function sl(string $id, string $variant, int $price, array $over = []): array
    {
        return $over + ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    /** @return array<string, mixed> */
    private static function body(array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => '1567469', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z',
            'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'sub_of' => null, 'lines' => $lines,
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []];
    }

    /** A day file of these bodies (each with its "ref") and its matching .sum. @param list<array<string, mixed>> $bodies */
    private function dayFile(string $day, array $bodies): string
    {
        $file = "{$this->dir}/snapshots_vapeandgo_{$day}.jsonl";
        $lines = 0;
        $goods = 0;
        $text = '';
        foreach ($bodies as $b) {
            $text .= json_encode($b, JSON_THROW_ON_ERROR) . "\n";
            foreach ($b['lines'] as $l) {
                $lines++;
                $goods += $l['price_inc'] - $l['discount_inc'];
            }
        }
        file_put_contents($file, $text);
        file_put_contents("{$this->dir}/snapshots_vapeandgo_{$day}.sum", json_encode(['channel' => 'vapeandgo', 'day' => $day, 'orders' => count($bodies),
            'lines' => $lines, 'goods_inc' => $goods, 'delivery_inc' => 0, 'file_sha256' => hash_file('sha256', $file)], JSON_THROW_ON_ERROR));
        return $file;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} order_snapshot, the queue, sale_order, sale_line rows of the store */
    private static function stored(int $ch): array
    {
        return [(int) self::$db->value('SELECT COUNT(*) FROM order_snapshot WHERE channel_id = ?', [$ch]),
            (int) self::$db->value('SELECT COUNT(*) FROM order_snapshot_queue WHERE channel_id = ?', [$ch]),
            (int) self::$db->value('SELECT COUNT(*) FROM sale_order WHERE channel_id = ?', [$ch]),
            (int) self::$db->value('SELECT COUNT(*) FROM sale_line WHERE channel_id = ?', [$ch])];
    }

    /** The field a call refused (400 bad_snapshot), or null when it was taken. */
    private static function refusedField(\Closure $call): ?string
    {
        try {
            $call();
        } catch (CwException $e) {
            self::assertSame(['bad_snapshot', 400], [$e->errorCode, $e->httpStatus], $e->getMessage());
            return $e->detail['field'] ?? null;
        }
        return null;
    }

    /** G1: each of the four ids as "Jane.Smith", "jane.smith", "12 Preston Road": refused by the API, a bulk store and a day file; nothing stored. */
    public function testAPersonsNameAsAnIdIsRefusedEverywhereAndNothingIsStored(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $s = new OrderSnapshots(self::$db);
        $import = new SnapshotImport(self::$db);
        foreach (self::WORDS as $w) {
            $cases = [ // [the PUT path's ref, the body, the field named]
                [$w, self::body([self::sl('8812345', '40123', 1200)]), 'ref'],
                ['1567469', self::body([self::sl($w, '40123', 1200)]), 'lines[0].line_id'],
                ['1567469', self::body([self::sl('8812345', $w, 1200)]), 'lines[0].variant_id'],
                ['1567469', self::body([self::sl('8812345', '40123', 1200)], ['parent_ref' => $w]), 'parent_ref'],
                ['1567469', self::body([self::sl('8812345', '40123', 1200)], ['sub_of' => $w]), 'sub_of'],
            ];
            foreach ($cases as [$ref, $body, $field]) {
                self::assertSame($field, self::refusedField(static fn () => $s->accept($ch, $ref, $body, 'api')), "the API: {$field} {$w}");
                self::assertSame($field, self::refusedField(static fn () => $s->store($ch, $ref, $body, 'bulk')), "a bulk store: {$field} {$w}");
                $log = [];
                $r = $import->file('vapeandgo', $this->dayFile('2026-11-19', [['ref' => $ref] + $body]), false, function (string $m) use (&$log): void {
                    $log[] = $m;
                });
                self::assertSame([1, 0], [$r['refused'], $r['results']['stored']], "a day file: {$field} {$w}: " . implode(' | ', $log));
                self::assertStringContainsString("refused bad_snapshot ({$field})", implode("\n", $log), "{$field} {$w}");
                self::assertSame([0, 0, 0, 0], self::stored($ch), "nothing stored for {$field} {$w}");
            }
        }
        // The connector's real shapes, on the same ways in: stored.
        self::assertSame('stored', $s->accept($ch, '1567469', self::body([self::sl('8812345', '40123', 1200)]), 'api')['result']);
        self::assertSame('stored', $s->store($ch, 'proto1-1567470', self::body([self::sl('proto1-8812346', '40123', 1200)],
            ['sub_of' => 'proto1-1567469']), 'bulk')['result']);
        $r = $import->file('vapeandgo', $this->dayFile('2026-11-19', [['ref' => '1567471'] + self::body([self::sl('8812347', '40124', 1200)],
            ['parent_ref' => '1567469'])]), false, static fn (string $m) => null);
        self::assertSame([0, 1], [$r['refused'], $r['results']['stored']]);
        (new OrderSnapshots(self::$db))->applyQueued();
        self::assertSame(['1567469', '1567471', 'proto1-1567470'], array_map('strval', self::$db->column(
            'SELECT order_ref FROM sale_order WHERE channel_id = ? ORDER BY order_ref', [$ch])), 'the three orders of the real shapes');
        self::assertSame(['8812345', '8812347', 'proto1-8812346'], array_map('strval', self::$db->column(
            'SELECT line_id FROM sale_line WHERE channel_id = ? ORDER BY line_id', [$ch])));
    }

    /**
     * G3 (F19 PARTLY): the store's first reservation at 09:30 UTC; a day file with an order placed at 09:00 whose line is broken (qty 2) and a valid
     * order placed at 10:00: the file is refused whole (the early order named), nothing written; the same file without the early order is stored.
     */
    public function testAnEarlyOrderWithABrokenLineRefusesItsDayFileWhole(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $notBefore = '2026-11-19 09:30:00.000000';
        $early = ['ref' => '5301'] + self::body([self::sl('8813001', '40123', 1200, ['qty' => 2])], ['placed_at' => '2026-11-19T09:00:00Z']);
        $valid = ['ref' => '5302'] + self::body([self::sl('8813002', '40123', 1200)], ['placed_at' => '2026-11-19T10:00:00Z', 'paid_at' => '2026-11-19T10:01:00Z']);
        $log = [];
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $this->dayFile('2026-11-19', [$early, $valid]), false, function (string $m) use (&$log): void {
            $log[] = $m;
        }, $notBefore);
        $all = implode("\n", $log);
        self::assertSame(1, $r['file_refused'], 'the file refused whole: ' . $all);
        self::assertSame(0, $r['results']['stored'], $all);
        self::assertStringContainsString("order 5301 was placed before the store's first reservation", $all);
        self::assertStringContainsString('nothing written', $all);
        self::assertSame([0, 0, 0, 0], self::stored($ch), 'the valid sibling is not written either');
        // Without the early order the file is the store's: stored.
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $this->dayFile('2026-11-19', [$valid]), false, static fn (string $m) => null, $notBefore);
        self::assertSame([0, 1], [$r['file_refused'], $r['results']['stored']]);
    }
}
