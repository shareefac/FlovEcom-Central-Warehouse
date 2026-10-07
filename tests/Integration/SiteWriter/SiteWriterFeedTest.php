<?php

declare(strict_types=1);

namespace CW\Tests\Integration\SiteWriter;

use CW\Caller;
use CW\ChannelAdmin;
use CW\SiteWriter\SiteModes;
use CW\SiteWriter\SiteWriterInvariants;
use CW\Tests\Integration\Receiving\ReceivingTestCase;

/**
 * IM10, CW's half (docs/decisions.md I148-I166): what the change feed carries for the site stock writer. The per-site switch (off by
 * default; on: a channel-wide feed row, the site re-snapshots), the `site` block of each listing (policy for counted items, the
 * site's own mode for legacy items, Out-Of-Stock for a blocked item), the selling-mode switch (per site, every change a feed row),
 * and the movements behind a change (`moves`), the invariants W1-W2.
 */
final class SiteWriterFeedTest extends ReceivingTestCase
{
    /** @return array<string, array<string, mixed>> variant => view, from /v1/availability's in-process call */
    private function viewsOf(Caller $site, string ...$variants): array
    {
        return array_column($this->avail->forVariants((int) $site->channelId, $variants), null, 'variant_id');
    }

    private function writer(string $code, bool $on): void
    {
        (new ChannelAdmin(self::$db))->configure($code, null, null, 'tester', true, $on);
    }

    public function testTheSwitchIsOffByDefaultAndChannelWide(): void
    {
        $site = $this->site('vapeandgo');
        $other = $this->site('electrofag');
        $this->listing($site, 'S1', $this->item('strict', 5));
        $this->listing($other, 'E1', $this->item('strict', 5));
        self::assertSame(0, (int) self::$db->value("SELECT site_writer FROM channel WHERE code = 'vapeandgo'"), 'off by default');
        self::assertSame(['writer' => false, 'why' => 'writer_off'], $this->viewsOf($site, 'S1')['S1']['site']);
        self::assertSame(['writer' => false, 'why' => 'unlinked'], $this->viewsOf($site, 'NOPE')['NOPE']['site']);

        $after = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        $r = (new ChannelAdmin(self::$db))->configure('vapeandgo', null, null, 'tester', false, true);
        self::assertSame([['site_writer'], false], [$r['changed'], $r['applied']], 'a dry run');
        self::assertStringContainsString('site writer on: from the site\'s next feed poll CW writes', implode(' ', $r['warnings']), 'the dry run says what it does');
        self::assertStringNotContainsString('nothing is written on the site until it is live', implode(' ', $r['warnings']), 'the channel is live');
        self::assertSame(0, (int) self::$db->value("SELECT site_writer FROM channel WHERE code = 'vapeandgo'"));
        $this->writer('vapeandgo', true);
        self::assertSame(1, (int) self::$db->value("SELECT site_writer FROM channel WHERE code = 'vapeandgo'"));
        self::assertSame(['by' => 'tester', 'from' => false, 'to' => true], self::sorted((string) self::$db->value(
            "SELECT detail FROM audit_log WHERE action = 'channel.site_writer' ORDER BY id DESC LIMIT 1")));
        $c = $this->avail->changes((int) $site->channelId, $after);
        self::assertTrue($c['resync'], 'every listing of the site changed: re-snapshot');
        self::assertFalse($this->avail->changes((int) $other->channelId, $after)['resync'], 'only that site');
        $s1 = $this->viewsOf($site, 'S1')['S1'];
        self::assertSame([true, 5, 'From-Warehouse', 0, 'strict'], [$s1['site']['writer'], $s1['site']['qty'], $s1['site']['mode'], $s1['site']['backorders'],
            $s1['site']['why']]);
        self::assertGreaterThan($after, $s1['version'], 'the switch moved the version');
        self::assertSame(['writer' => false, 'why' => 'writer_off'], $this->viewsOf($other, 'E1')['E1']['site'], 'the other site is still off');

        // Off again: nothing written on the site from the next poll.
        $this->writer('vapeandgo', false);
        self::assertSame(['writer' => false, 'why' => 'writer_off'], $this->viewsOf($site, 'S1')['S1']['site']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'channel.site_writer'"));
    }

    public function testWhatEachListingGets(): void
    {
        $site = $this->site('vapeandgo');
        $this->writer('vapeandgo', true);
        $legacy = $this->item('legacy', 9);
        $this->listing($site, 'STRICT', $this->item('strict', 4));
        $this->listing($site, 'BACK', $this->item('backorder', 0));
        $this->listing($site, 'STOP', $this->item('stopped', 6));
        $this->listing($site, 'QUAR', $this->item('strict', 6), 1, 'quarantined');
        $this->listing($site, 'LEG', $legacy);
        $this->listing($site, 'TEN', $legacy, 4);
        $this->listing($site, 'UNL', null);
        $this->reserve($site, 'o1', [self::line('LEG', 'u1')]);

        $v = $this->viewsOf($site, 'STRICT', 'BACK', 'STOP', 'QUAR', 'LEG', 'TEN', 'UNL');
        $pick = static fn (array $x): array => [$x['site']['writer'], $x['site']['qty'] ?? null, $x['site']['mode'] ?? null, $x['site']['backorders'] ?? null,
            $x['site']['why']];
        self::assertSame([
            'STRICT' => [true, 4, 'From-Warehouse', 0, 'strict'],
            'BACK' => [true, 0, 'From-Warehouse', 1, 'backorder'],
            'STOP' => [true, 6, 'Out-Of-Stock', 0, 'stopped'],
            'QUAR' => [true, 6, 'Out-Of-Stock', 0, 'quarantined'],
            'LEG' => [true, 8, null, null, 'site_own'],
            'TEN' => [true, 2, null, null, 'site_own'],
            'UNL' => [false, null, null, null, 'unlinked'],
        ], array_map($pick, $v), 'a legacy item: its quantity (holds included), the site\'s own mode until CW sets one');
    }

    public function testTheSwitchSetsAModePerSiteAndEveryChangeReachesTheFeed(): void
    {
        $vpg = $this->site('vapeandgo');
        $ef = $this->site('electrofag');
        $this->writer('vapeandgo', true);
        $this->writer('electrofag', true);
        $sku = $this->item('legacy', 5);
        $this->listing($vpg, 'V1', $sku);
        $this->listing($ef, 'E1', $sku);
        $modes = new SiteModes(self::$db);
        $desk = $this->staffUser('purchasing_desk');

        $after = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        $r = $modes->set($desk, $sku, 'In-Stock', ['vapeandgo'], '3', 'arrives tomorrow, keep selling', SiteModes::stamp($modes->current([$sku])));
        self::assertSame([['vapeandgo'], [], 'In-Stock', 3], [$r['changed'], $r['unchanged'], $r['mode'], $r['threshold']]);
        $c = $this->avail->changes((int) $vpg->channelId, $after);
        self::assertSame(['V1'], array_column($c['listings'], 'variant_id'), 'a feed row for the item');
        self::assertSame(['In-Stock', 3, 'site_mode', 'sold whatever the figure'], [$c['listings'][0]['site']['mode'], $c['listings'][0]['site']['low_stock_threshold'],
            $c['listings'][0]['site']['why'], $c['listings'][0]['site']['meaning']]);
        self::assertSame([null, 'site_own'], [$this->viewsOf($ef, 'E1')['E1']['site']['mode'], $this->viewsOf($ef, 'E1')['E1']['site']['why']],
            'Electrofag keeps its own mode (IM10: "per site for legacy items")');

        // Out-Of-Stock on every site remembers the mode before it, per site; the threshold is kept.
        $modes->set($desk, $sku, 'Out-Of-Stock', ['*'], '', 'supplier stopped making it');
        $rows = $modes->current([$sku]);
        self::assertSame(['Out-Of-Stock', 'In-Stock', 3, 2], array_values(array_intersect_key($rows[SiteModes::key($sku, (int) $vpg->channelId)],
            ['mode' => 0, 'previous' => 0, 'threshold' => 0, 'version' => 0])));
        self::assertSame(['Out-Of-Stock', null, null, 1], array_values(array_intersect_key($rows[SiteModes::key($sku, (int) $ef->channelId)],
            ['mode' => 0, 'previous' => 0, 'threshold' => 0, 'version' => 0])));
        self::assertSame('Out-Of-Stock', $this->viewsOf($ef, 'E1')['E1']['site']['mode']);
        $u = $modes->set($desk, $sku, 'Out-Of-Stock', ['vapeandgo', 'electrofag'], '', 'again');
        self::assertSame([[], ['vapeandgo', 'electrofag']], [$u['changed'], $u['unchanged']], 'nothing changed: nothing written');
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'selling_mode.set'"));
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode_log WHERE sku_id = ?', [$sku]));

        // Refusals.
        $stale = SiteModes::stamp([]);
        foreach ([
            ['bad_mode', fn () => $modes->set($desk, $sku, 'Sometimes', ['vapeandgo'], '', 'why not')],
            ['bad_reason', fn () => $modes->set($desk, $sku, 'In-Stock', ['vapeandgo'], '', 'no')],
            ['bad_threshold', fn () => $modes->set($desk, $sku, 'In-Stock', ['vapeandgo'], '-1', 'negative')],
            ['bad_threshold', fn () => $modes->set($desk, $sku, 'In-Stock', ['vapeandgo'], '100001', 'too many')],
            ['no_sites', fn () => $modes->set($desk, $sku, 'In-Stock', [' '], '', 'no site')],
            ['unknown_site', fn () => $modes->set($desk, $sku, 'In-Stock', ['vapebig'], '', 'not a site')],
            ['selling_mode_changed', fn () => $modes->set($desk, $sku, 'In-Stock', ['vapeandgo'], '', 'what I saw', $stale)],
            ['unknown_item', fn () => $modes->set($desk, 999999, 'In-Stock', ['vapeandgo'], '', 'nothing')],
            ['protected_item', fn () => $modes->set($desk, $this->item('strict', 1), 'In-Stock', ['vapeandgo'], '', 'counted')],
        ] as [$code, $fn]) {
            $this->refusedCode($code, $fn);
        }
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode_log WHERE sku_id = ?', [$sku]), 'a refusal writes nothing');
        self::assertSame([], SiteWriterInvariants::check(self::$db));
    }

    public function testABlockedItemIsWrittenOutOfStockAndTheBlockReachesTheFeed(): void
    {
        $site = $this->site('vapeandgo');
        $this->writer('vapeandgo', true);
        $sku = $this->item('legacy', 5);
        $this->listing($site, 'BIG', $sku);
        (new SiteModes(self::$db))->set($this->staffUser('manager'), $sku, 'In-Stock', ['vapeandgo'], '', 'sold as today');
        self::assertSame('In-Stock', $this->viewsOf($site, 'BIG')['BIG']['site']['mode']);

        // A 20 ml nicotine refill: a warning until a person confirms it, then a block (I103) -> Out-Of-Stock on the site.
        $after = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        $this->card($sku, ['product_type' => 'e_liquid', 'liquid_ml' => '20', 'nicotine_mg' => '6', 'duty_liable' => 'yes']);
        self::assertSame([], $this->avail->changes((int) $site->channelId, $after, 5000, 0)['listings'], 'a warning changes nothing on the site');
        $this->card($sku, ['product_type' => 'e_liquid', 'liquid_ml' => '20', 'nicotine_mg' => '6', 'duty_liable' => 'yes'], true, true);
        $c = $this->avail->changes((int) $site->channelId, $after, 5000, 0);
        self::assertSame(['BIG'], array_column($c['listings'], 'variant_id'), 'the confirmation writes a feed row');
        self::assertSame(['Out-Of-Stock', 0, 'blocked', 'not sold: blocked by its item card (nicotine refill over 10 ml)'], [$c['listings'][0]['site']['mode'],
            $c['listings'][0]['site']['backorders'], $c['listings'][0]['site']['why'], $c['listings'][0]['site']['meaning']]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM stock_change WHERE sku_id = ? AND reason = 'card'", [$sku]));

        // Corrected and confirmed again: lifted, back to its site mode.
        $after = $c['next_after'];
        $this->card($sku, ['liquid_ml' => '10'], true);
        $c = $this->avail->changes((int) $site->channelId, $after, 5000, 0);
        self::assertSame(['In-Stock', 'site_mode'], [$c['listings'][0]['site']['mode'], $c['listings'][0]['site']['why']]);
    }

    public function testTheMovementsBehindAChangeAreNamedButNotTheSales(): void
    {
        $site = $this->site('vapeandgo');
        $this->writer('vapeandgo', true);
        $sku = $this->item('legacy', 5);
        $this->listing($site, 'M1', $sku);
        $after = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        $mark = (int) self::$db->value('SELECT MAX(id) FROM stock_ledger');
        $this->book('goods_in', $sku, 24);
        $this->reserve($site, 's1', [self::line('M1', 'a')]);
        $this->book('write_off', $sku, 2);
        $c = $this->avail->changes((int) $site->channelId, $after);
        // The window reaches back MOVES_MARGIN_SEC before the page's first feed row (a transaction's ledger rows come before its feed row),
        // so an earlier group can come again: the site logs a group once, by its last_id.
        $moves = array_values(array_filter($c['listings'][0]['site']['moves'], static fn (array $m): bool => $m['last_id'] > $mark));
        self::assertSame([['goods_in', 24], ['write_off', -2]], array_map(static fn (array $m): array => [$m['type'], $m['units']], $moves),
            'the hold is the site\'s own order: not named');
        self::assertGreaterThan($moves[0]['last_id'], $moves[1]['last_id']);
        self::assertSame(26, $c['listings'][0]['site']['qty']);

        // Not on a snapshot, nor while the switch is off.
        self::assertArrayNotHasKey('moves', $this->avail->snapshot((int) $site->channelId)['listings'][0]['site']);
        $this->writer('vapeandgo', false);
        $this->book('goods_in', $sku, 1);
        $c = $this->avail->changes((int) $site->channelId, $c['next_after']);
        self::assertArrayNotHasKey('moves', $c['listings'][0]['site']);
    }

    public function testTheInvariantsFindATamperedRow(): void
    {
        $site = $this->site('vapeandgo');
        $sku = $this->item('legacy', 1);
        $this->listing($site, 'T1', $sku);
        (new SiteModes(self::$db))->set($this->staffUser('stock_controller'), $sku, 'From-Warehouse', ['vapeandgo'], '2', 'normal stock item');
        self::assertSame([], SiteWriterInvariants::check(self::$db));
        self::$db->exec("UPDATE item_channel_mode SET mode = 'In-Stock' WHERE sku_id = ?", [$sku]);
        self::assertStringContainsString('is not its newest log row', implode("\n", SiteWriterInvariants::check(self::$db)));
        self::$db->exec("UPDATE item_channel_mode SET mode = 'From-Warehouse' WHERE sku_id = ?", [$sku]);
        self::$db->exec('INSERT INTO item_channel_mode_log (sku_id, channel_id, version, mode_after, source, reason, actor) VALUES (?, ?, 5, ?, ?, ?, ?)',
            [$sku, $site->channelId, 'In-Stock', 'switch', 'tamper', 'x']);
        self::assertStringContainsString('versions 1..5 in 2 rows', implode("\n", SiteWriterInvariants::check(self::$db)));
        self::$db->exec('DELETE FROM item_channel_mode_log WHERE sku_id = ? AND version = 5', [$sku]);
        self::$db->exec('INSERT INTO item_channel_mode (sku_id, channel_id, mode, source, updated_actor) VALUES (?, 999, ?, ?, ?)', [$sku, 'In-Stock', 'switch', 'x']);
        self::$db->exec('INSERT INTO item_channel_mode_log (sku_id, channel_id, version, mode_after, source, reason, actor) VALUES (?, 999, 1, ?, ?, ?, ?)',
            [$sku, 'In-Stock', 'switch', 'tamper', 'x']);
        self::assertStringContainsString('there is no such site', implode("\n", SiteWriterInvariants::check(self::$db)));
        self::$db->exec('DELETE FROM item_channel_mode_log WHERE channel_id = 999');
        self::$db->exec('DELETE FROM item_channel_mode WHERE channel_id = 999');
        self::assertSame([], SiteWriterInvariants::check(self::$db));
    }

    /** @return array<string, mixed> */
    private static function sorted(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        ksort($d);
        return $d;
    }

}
