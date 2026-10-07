<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Catalogue;

use CW\Tests\Support\TestDb;

/**
 * Item card and barcode races with two real connections (catalogue_worker.php, one process each, the admin login on this slot's
 * schema; docs/decisions.md I101, I106, I108): two first saves of one card (no row to lock yet: the duplicate key decides, the loser
 * is 409 card_changed, never a 500); two saves of one card (the row lock queues them; the second is 409); the sync and a person
 * adding the same barcode to two items at once (one item has it, the other is refused or counted, never both).
 */
final class CatalogueRaceTest extends CatalogueTestCase
{
    /** @param list<array<string, mixed>> $jobs @return list<array<string, mixed>> */
    private static function race(array $jobs): array
    {
        $start = microtime(true) + 1.0;
        $procs = [];
        foreach ($jobs as $i => $job) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, __DIR__ . '/catalogue_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                dirname(__DIR__, 3), ['CW_SLOT' => (string) getenv('CW_SLOT'), 'CW_DB_NAME' => TestDb::name()] + getenv());
            self::assertIsResource($p, "worker {$i}");
            fwrite($pipes[0], json_encode(['start' => $start + (float) ($job['delay'] ?? 0)] + $job, JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $procs[] = [$p, $pipes];
        }
        $out = [];
        foreach ($procs as $i => [$p, $pipes]) {
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($p);
            $line = json_decode(trim($stdout), true);
            self::assertTrue($code === 0 && is_array($line), "worker {$i} failed (exit {$code}): " . substr($stderr . $stdout, 0, 2000));
            $out[] = $line;
        }
        return $out;
    }

    public function testTwoFirstSavesAndTwoSavesOfOneCard(): void
    {
        foreach ([0, 600] as $hold) {
            $sku = $this->item('legacy', 0, "Race {$hold}");
            [$a, $b] = [$this->editor(), $this->editor('mapping_lead')];
            $res = self::race([
                ['op' => 'save', 'staff_id' => $a->staffUserId, 'sku_id' => $sku, 'version' => 0, 'fields' => ['brand' => 'A'], 'hold_ms' => $hold],
                ['op' => 'save', 'staff_id' => $b->staffUserId, 'sku_id' => $sku, 'version' => 0, 'fields' => ['brand' => 'B'], 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            $statuses = array_column($res, 'status');
            sort($statuses);
            self::assertSame([200, 409], $statuses, json_encode($res));
            self::assertSame('card_changed', ($res[0]['status'] === 409 ? $res[0] : $res[1])['error']);
            if ($hold > 0) {
                self::assertSame(200, $res[0]['status'], 'the holder saves first');
                self::assertGreaterThanOrEqual(300, $res[1]['ms'], 'the second waited for the first insert');
            }
            self::assertSame([1, 1], [(int) self::$db->value('SELECT version FROM item_card WHERE sku_id = ?', [$sku]),
                (int) self::$db->value('SELECT COUNT(*) FROM item_card_change WHERE sku_id = ?', [$sku])]);

            // The card exists now: the row lock queues two saves of version 1; the second finds version 2.
            $res = self::race([
                ['op' => 'save', 'staff_id' => $a->staffUserId, 'sku_id' => $sku, 'version' => 1, 'fields' => ['manufacturer' => 'A'], 'hold_ms' => $hold],
                ['op' => 'save', 'staff_id' => $b->staffUserId, 'sku_id' => $sku, 'version' => 1, 'fields' => ['manufacturer' => 'B'], 'delay' => $hold > 0 ? 0.2 : 0],
            ]);
            $statuses = array_column($res, 'status');
            sort($statuses);
            self::assertSame([200, 409], $statuses, json_encode($res));
            self::assertSame(0, $res[0]['deadlocks'] + $res[1]['deadlocks']);
            self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM item_card_change WHERE sku_id = ?', [$sku]));
        }
    }

    public function testTheSyncAndAPersonAddingOneBarcodeToTwoItems(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Listing item');
        $b = $this->item('legacy', 0, 'Typed item');
        $this->linked($site, 'R1', $a, ['5012345678900']);
        $res = self::race([
            ['op' => 'sync'],
            ['op' => 'add_barcode', 'staff_id' => $this->editor()->staffUserId, 'sku_id' => $b, 'barcode' => '5012345678900'],
        ]);
        self::assertSame(200, $res[0]['status'], json_encode($res));
        self::assertNotSame(500, $res[1]['status'], json_encode($res));
        $holder = (int) self::$db->value("SELECT sku_id FROM sku_barcode WHERE barcode = '5012345678900'");
        self::assertContains($holder, [$a, $b]);
        if ($holder === $b) {
            // The person won: the sync found it on another item (a review) or lost the insert race (counted, the next run reviews it).
            $r = $res[0]['result'];
            self::assertSame(1, $r['review_on_another_item'] + $r['raced'], json_encode($r));
        } else {
            self::assertSame([409, 'barcode_on_other_item'], [$res[1]['status'], $res[1]['error']]);
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sku_barcode'));
    }
}
