<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Availability;
use CW\Reorder\LowStockLevels;
use CW\Reorder\ReorderRefresh;
use CW\Reorder\ReorderSettings;
use CW\SiteWriter\SiteModes;

/**
 * Pack G (docs/decisions.md PG7; plan part 3b, E11 and "five rows" (b)): the low-stock figure CW keeps per item. A click sets the
 * ticked items' figures to their safety stock, or clears them (each website keeps its own), skipping and listing the doubtful ones;
 * every change writes the item's feed row, so the site writer writes it on each linked variant in that variant's units, unless the
 * selling-mode switch set a figure for that website.
 */
final class LowStockLevelsTest extends ReorderTestCase
{
    private function levels(): LowStockLevels
    {
        return new LowStockLevels(self::$db, $this->reorderList(), new ReorderRefresh(self::$db, $this->pos));
    }

    private function feedRows(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM stock_change WHERE reason = 'low_stock'");
    }

    public function testUseTheSafetyStockOrKeepEachWebsitesOwn(): void
    {
        $s = $this->listScenario();
        $z = $this->brandItem('Kept at zero', 'Elux');
        self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 0, 'system:test')", [$z]);
        $this->refreshLines();
        $r = $this->levels()->apply($s['buyer'], 'safety', [$s['a'], $s['b'], $s['e'], $z, 999_999]);
        self::assertSame([[$s['a'], null, 60], [$s['b'], null, 10]], array_map(static fn (array $c): array => [$c['sku_id'], $c['before'], $c['after']], $r['changed']),
            'A: 12 a day × 5 spare days; B: 2 × 5');
        self::assertSame([[$s['e'], 'never'], [$z, 'zero'], [999_999, 'not_listed']], array_map(static fn (array $x): array => [$x['sku_id'], $x['why']], $r['skipped']));
        self::assertSame([60, 10], array_map('intval', self::$db->column('SELECT low_stock_level FROM item_reorder WHERE sku_id IN (?, ?) ORDER BY sku_id', [$s['a'], $s['b']])));
        self::assertSame(2, $this->feedRows(), 'one feed row per changed item: the site writer sends it');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'reorder.low_stock'"));
        self::assertSame(60, (int) self::$db->value('SELECT low_stock_level FROM reorder_line WHERE sku_id = ?', [$s['a']]), 'the stored list at once');
        // Again: nothing changes, nothing is written.
        $again = $this->levels()->apply($s['buyer'], 'safety', [$s['a']]);
        self::assertSame([[], 'already'], [$again['changed'], $again['skipped'][0]['why']]);
        self::assertSame(2, $this->feedRows());
        // Keep the website's own: cleared.
        $keep = $this->levels()->apply($s['buyer'], 'keep', [$s['a'], $s['c']]);
        self::assertSame([[$s['a'], 60, null]], array_map(static fn (array $c): array => [$c['sku_id'], $c['before'], $c['after']], $keep['changed']));
        self::assertSame([[$s['c'], 'already']], array_map(static fn (array $x): array => [$x['sku_id'], $x['why']], $keep['skipped']), 'C had none');
        self::assertNull(self::$db->value('SELECT low_stock_level FROM item_reorder WHERE sku_id = ?', [$s['a']]));
        self::assertSame(3, $this->feedRows());
        // Guards: a reviewer cannot, too many products, an unknown action, nothing ticked.
        self::refused(403, 'role_not_allowed', fn () => $this->levels()->apply($this->staffUser('reviewer'), 'safety', [$s['c']]));
        $this->setting('reorder.low_stock_bulk_max', '1');
        self::refused(422, 'too_many_lines', fn () => $this->levels()->apply($s['buyer'], 'safety', [$s['c'], $s['d']]));
        self::refused(400, 'bad_action', fn () => $this->levels()->apply($s['buyer'], 'half', [$s['c']]));
        self::refused(422, 'nothing_picked', fn () => $this->levels()->apply($s['buyer'], 'safety', []));
        self::assertSame(3, $this->feedRows());
        // The item's own settings form sets and clears it too; an unchanged save writes no feed row.
        $set = new ReorderSettings(self::$db);
        $row = $set->saveItem($s['buyer'], $s['c'], 0, ['low_stock_level' => '7']);
        self::assertSame([7, 4], [(int) $row['low_stock_level'], $this->feedRows()]);
        $set->saveItem($s['buyer'], $s['c'], 1, ['low_stock_level' => '7']);
        self::assertSame(4, $this->feedRows());
        self::refused(422, 'bad_field', fn () => $set->saveItem($s['buyer'], $s['c'], 1, ['low_stock_level' => (string) (SiteModes::THRESHOLD_MAX + 1)]));
    }

    public function testTheSiteWriterWritesTheFigureInEachVariantsUnits(): void
    {
        $s = $this->listScenario();
        $vpg = $s['vpg'];
        $this->listingOf($vpg, '601-10', $s['a'], 10); // a box of 10 sold as one
        self::$db->exec('UPDATE channel SET site_writer = 1 WHERE id = ?', [$vpg]);
        $avail = new Availability(self::$db);
        $site = static fn (): array => array_map(static fn (array $v): ?int => $v['site']['low_stock_threshold'],
            array_column($avail->forVariants($vpg, ['601', '601-10']), null, 'variant_id'));
        self::assertSame(['601' => null, '601-10' => null], $site(), 'no figure: each website keeps its own');
        $before = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        $this->levels()->apply($s['buyer'], 'safety', [$s['a']]);
        self::assertSame(['601' => 60, '601-10' => 6], $site(), '60 single items; 6 boxes of 10');
        $c = $avail->changes($vpg, $before);
        self::assertSame(['601', '601-10'], array_column($c['listings'], 'variant_id'), 'both variants are in the feed again');
        // A figure set for this website on the product's page wins.
        $modes = new SiteModes(self::$db);
        $modes->set($this->staffUser('purchasing_desk'), $s['a'], 'From-Warehouse', ['vapeandgo'], '9', 'set for this website by hand', SiteModes::stamp($modes->current([$s['a']])));
        self::assertSame(['601' => 9, '601-10' => 9], $site());
        // Off: nothing at all.
        self::$db->exec('UPDATE channel SET site_writer = 0 WHERE id = ?', [$vpg]);
        self::assertSame(['601' => null, '601-10' => null], array_map(static fn (array $v): ?int => $v['site']['low_stock_threshold'] ?? null,
            array_column($avail->forVariants($vpg, ['601', '601-10']), null, 'variant_id')));
    }
}
