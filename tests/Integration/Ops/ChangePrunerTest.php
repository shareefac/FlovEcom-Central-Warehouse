<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ops;

use CW\Clock;
use CW\Db;
use CW\Ops\ChangePruner;
use CW\Tests\Support\StockTestCase;
use DateTimeImmutable;

/** bin/prune_changes.php: keep N days of stock_change without moving any listing's version (H2). */
final class ChangePrunerTest extends StockTestCase
{
    /** Moves feed rows into the past. @param list<int> $seqs */
    private static function age(array $seqs, string $modify): void
    {
        $at = Clock::db(Clock::now()->modify($modify));
        self::$db->exec('UPDATE stock_change SET created_at = ? WHERE seq IN (' . implode(',', array_fill(0, count($seqs), '?')) . ')', [$at, ...$seqs]);
    }

    /** @return list<int> */
    private static function seqs(): array
    {
        return array_map('intval', self::$db->column('SELECT seq FROM stock_change ORDER BY seq'));
    }

    /** @param list<string> $variants @return array<string, int> variant => version */
    private function versions(int $channelId, array $variants): array
    {
        $out = [];
        foreach ($this->avail->forVariants($channelId, $variants) as $v) {
            $out[(string) $v['variant_id']] = (int) $v['version'];
        }
        ksort($out);
        return $out;
    }

    public function testKeepsTheNewestRowOfEveryScopeSoNoVersionMoves(): void
    {
        $site = $this->site();
        $a = $this->item('strict', 5);
        $b = $this->item('strict', 5);
        $c = $this->item('backorder', 0);
        $la = $this->listing($site, 'VA', $a);
        $this->listing($site, 'VB', $b);
        $this->listing($site, 'VC', $c);
        $lx = $this->listing($site, 'VX', null);
        // Old history: several stock changes of a and b, a relink of VX, a channel-wide and a global row.
        $this->book('goods_in', $a, 3);
        $this->book('goods_in', $a, 4);
        $this->book('goods_in', $b, 2);
        $this->relink($lx, $b);
        $this->relink($lx, $a);
        self::$db->transaction(fn (Db $db): int => $this->stock->channelChanged((int) $site->channelId, 'mode'));
        self::$db->transaction(fn (Db $db): int => $this->stock->channelChanged((int) $site->channelId, 'mode'));
        self::$db->exec("INSERT INTO stock_change (reason) VALUES ('global')");
        self::$db->exec("INSERT INTO stock_change (reason) VALUES ('global')");
        $old = self::seqs();
        self::age($old, '-20 days');
        // Recent history: item b changes again (its old rows are superseded by a recent one).
        $this->book('goods_in', $b, 1);
        $recent = array_values(array_diff(self::seqs(), $old));
        self::assertNotEmpty($recent);

        $variants = ['VA', 'VB', 'VC', 'VX'];
        $before = $this->versions((int) $site->channelId, $variants);
        $headBefore = $this->avail->snapshot((int) $site->channelId)['seq'];

        // Superseded = every old row with a newer row in its own scope.
        $expect = self::$db->column(
            'SELECT s.seq FROM stock_change s WHERE EXISTS (SELECT 1 FROM stock_change n WHERE n.channel_id <=> s.channel_id '
            . 'AND n.sku_id <=> s.sku_id AND n.listing_id <=> s.listing_id AND n.seq > s.seq) ORDER BY s.seq',
        );
        $expect = array_values(array_intersect(array_map('intval', $expect), $old));
        self::assertGreaterThanOrEqual(6, count($expect), 'the fixture must leave something to prune');

        $pruner = new ChangePruner(self::$db, 3); // tiny windows: the loop must cross window boundaries
        $dry = $pruner->prune(ChangePruner::cutoff(14), true);
        self::assertSame(count($expect), $dry['deleted']);
        self::assertSame(count($old) + count($recent), count(self::seqs()), 'a dry run deletes nothing');

        $r = $pruner->prune(ChangePruner::cutoff(14));
        self::assertTrue($r['complete']);
        self::assertSame(count($expect), $r['deleted']);
        self::assertSame(count($old) - count($expect), $r['kept_newest']);
        self::assertGreaterThan(1, $r['windows']);
        self::assertSame([], array_values(array_intersect($expect, self::seqs())), 'superseded rows are gone');
        self::assertSame($recent, array_values(array_intersect($recent, self::seqs())), 'rows newer than the cutoff are all kept');

        self::assertSame($before, $this->versions((int) $site->channelId, $variants), 'no listing version moves');
        self::assertSame($headBefore, $this->avail->snapshot((int) $site->channelId)['seq'], 'the feed head does not move');
        // Each old scope keeps exactly one row: the listing VX, item a, item c, the channel, global; item b only its recent row.
        self::assertSame(1, self::$db->value('SELECT COUNT(*) FROM stock_change WHERE listing_id = ?', [$lx]));
        self::assertSame(1, self::$db->value('SELECT COUNT(*) FROM stock_change WHERE sku_id = ? AND listing_id IS NULL', [$a]));
        self::assertSame(1, self::$db->value('SELECT COUNT(*) FROM stock_change WHERE sku_id = ? AND listing_id IS NULL', [$b]));
        self::assertSame(1, self::$db->value('SELECT COUNT(*) FROM stock_change WHERE channel_id IS NOT NULL'));
        self::assertSame(1, self::$db->value('SELECT COUNT(*) FROM stock_change WHERE channel_id IS NULL AND sku_id IS NULL AND listing_id IS NULL'));
        self::assertNull(self::$db->value('SELECT seq FROM stock_change WHERE listing_id = ? LIMIT 1', [$la]), 'VA never had a listing row');

        // Idempotent: a second run finds nothing more.
        $again = $pruner->prune(ChangePruner::cutoff(14));
        self::assertSame(0, $again['deleted']);
        self::assertSame($before, $this->versions((int) $site->channelId, $variants));
    }

    public function testNothingNewerThanTheCutoffIsTouchedAndTheDeadlineStopsBetweenWindows(): void
    {
        $site = $this->site();
        $a = $this->item('strict', 1);
        $this->listing($site, 'VA', $a);
        for ($i = 0; $i < 6; $i++) {
            $this->book('goods_in', $a, 1);
        }
        $all = self::seqs();
        $r = (new ChangePruner(self::$db, 2))->prune(ChangePruner::cutoff(14));
        self::assertSame(0, $r['deleted'], 'every row is recent');
        self::assertSame($all, self::seqs());

        self::age($all, '-15 days');
        $late = (new ChangePruner(self::$db, 2))->prune(ChangePruner::cutoff(14), false, microtime(true) - 1);
        self::assertFalse($late['complete']);
        self::assertSame(0, $late['deleted'], 'a passed deadline starts no window');

        $r = (new ChangePruner(self::$db, 2))->prune(ChangePruner::cutoff(14, new DateTimeImmutable('now', Clock::utc())));
        self::assertSame(count($all) - 1, $r['deleted']);
        self::assertSame([max($all)], self::seqs(), 'the newest row of the item survives');
    }
}
