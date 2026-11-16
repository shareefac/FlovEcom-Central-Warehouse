<?php

declare(strict_types=1);

namespace CW\Tests\Integration\SiteWriter;

use CW\ChannelAdmin;
use CW\Receiving\SellingModes;
use CW\SiteWriter\SiteModes;
use CW\SiteWriter\SiteWriterInvariants;
use CW\Tests\Integration\Receiving\ReceivingTestCase;

/**
 * "Let the website decide" (T-556 step 2, option A, the owner 16 Nov 2026; migration 0067): the selling-mode switch gives a site's mode
 * back to the website. Through the same service as a mode (SiteModes::set): per ticked site, a reason, the stamp, audited, logged
 * (item_channel_mode_log, version + 1), the row kept with a NULL mode, and LAST a feed row, so the site hears at once that CW no longer
 * holds its mode (why site_own, mode null: "leave the site's own"). A site with no row is left alone (it decides already); a threshold
 * stays CW's. A forced listing (blocked, quarantined) stays Out-Of-Stock. A receipt does not read the given-back row as CW's mode.
 */
final class WebsiteDecidesTest extends ReceivingTestCase
{
    private function writer(string $code): void
    {
        (new ChannelAdmin(self::$db))->configure($code, null, null, 'tester', true, true);
    }

    /** @return array<string, mixed> the listing's site block from /v1/availability's in-process call */
    private function siteOf(\CW\Caller $site, string $variant): array
    {
        return array_column($this->avail->forVariants((int) $site->channelId, [$variant]), null, 'variant_id')[$variant]['site'];
    }

    public function testTheSwitchGivesASitesModeBackToTheWebsiteAndTheFeedSaysSoAtOnce(): void
    {
        $vpg = $this->site('vapeandgo');
        $ef = $this->site('electrofag');
        $this->writer('vapeandgo');
        $this->writer('electrofag');
        $sku = $this->item('legacy', 5);
        $this->listing($vpg, 'V1', $sku);
        $this->listing($ef, 'E1', $sku);
        $modes = new SiteModes(self::$db);
        $desk = $this->staffUser('purchasing_desk');
        $modes->set($desk, $sku, 'From-Warehouse', ['vapeandgo'], '3', 'CW held it since the switch');
        self::assertSame(['From-Warehouse', 'site_mode', 3], [$this->siteOf($vpg, 'V1')['mode'], $this->siteOf($vpg, 'V1')['why'], $this->siteOf($vpg, 'V1')['low_stock_threshold']]);

        $after = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        $r = $modes->set($desk, $sku, SiteModes::WEBSITE, ['*'], '', 'staff set it on the website now', SiteModes::stamp($modes->current([$sku])));
        self::assertSame([['vapeandgo'], ['electrofag'], 'website', null], [$r['changed'], $r['unchanged'], $r['mode'], $r['threshold']],
            'Electrofag had no row and no threshold: it decides already, nothing written for it');
        $rows = $modes->current([$sku]);
        $v = $rows[SiteModes::key($sku, (int) $vpg->channelId)];
        self::assertSame([null, null, 3, 2, 'switch'], [$v['mode'], $v['previous'], $v['threshold'], $v['version'], $v['source']], 'the row stays, its mode NULL, the threshold kept');
        self::assertArrayNotHasKey(SiteModes::key($sku, (int) $ef->channelId), $rows, 'no row made for a site that decides already');
        $log = self::$db->one('SELECT mode_before, mode_after, previous_mode, threshold_before, threshold_after, source, reason FROM item_channel_mode_log '
            . 'WHERE sku_id = ? AND channel_id = ? AND version = 2', [$sku, $vpg->channelId]);
        self::assertSame(['From-Warehouse', null, null, 3, 3, 'switch', 'staff set it on the website now'], array_values($log));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'selling_mode.set' ORDER BY id DESC LIMIT 1"), true);
        self::assertSame(['website', ['vapeandgo'], ['electrofag'], 'staff set it on the website now'], [$audit['mode'], $audit['sites'], $audit['unchanged'], $audit['reason']]);

        // The feed: a row for the item at once (Stock::skuChanged 'mode'), the site's own mode from now on, CW's threshold still written.
        $c = $this->avail->changes((int) $vpg->channelId, $after);
        self::assertSame(['V1'], array_column($c['listings'], 'variant_id'));
        $s = $c['listings'][0]['site'];
        self::assertSame([true, null, null, 'site_own', 3, 'the site keeps its own selling mode'], [$s['writer'], $s['mode'], $s['backorders'], $s['why'],
            $s['low_stock_threshold'], $s['meaning']]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM stock_change WHERE sku_id = ? AND reason = 'mode' AND seq > ?", [$sku, $after]));

        // Again: nothing changes, nothing written.
        $u = $modes->set($desk, $sku, SiteModes::WEBSITE, ['vapeandgo', 'electrofag'], '', 'again');
        self::assertSame([[], ['vapeandgo', 'electrofag']], [$u['changed'], $u['unchanged']]);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode_log WHERE sku_id = ?', [$sku]));

        // A threshold with it: a site with no row gets one (mode NULL, the threshold CW writes).
        $t = $modes->set($desk, $sku, SiteModes::WEBSITE, ['electrofag'], '4', 'warn at four on Electrofag');
        self::assertSame([['electrofag'], 4], [$t['changed'], $t['threshold']]);
        $e = $modes->current([$sku])[SiteModes::key($sku, (int) $ef->channelId)];
        self::assertSame([null, null, 4, 1], [$e['mode'], $e['previous'], $e['threshold'], $e['version']]);
        self::assertSame([null, 'site_own', 4], [$this->siteOf($ef, 'E1')['mode'], $this->siteOf($ef, 'E1')['why'], $this->siteOf($ef, 'E1')['low_stock_threshold']]);

        // A mode again later: CW holds it once more (no "mode before" known: the website decided it meanwhile).
        $modes->set($desk, $sku, 'Out-Of-Stock', ['*'], '', 'stopped by the supplier');
        $rows = $modes->current([$sku]);
        self::assertSame(['Out-Of-Stock', null, 3], [$rows[SiteModes::key($sku, (int) $vpg->channelId)]['mode'], $rows[SiteModes::key($sku, (int) $vpg->channelId)]['previous'],
            $rows[SiteModes::key($sku, (int) $vpg->channelId)]['version']]);
        self::assertSame(['Out-Of-Stock', 'site_mode'], [$this->siteOf($vpg, 'V1')['mode'], $this->siteOf($vpg, 'V1')['why']]);
        self::assertSame(5, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode_log WHERE sku_id = ?', [$sku]));
        self::assertSame([], SiteWriterInvariants::check(self::$db));
    }

    public function testAForcedListingStaysOutOfStockWhenTheWebsiteDecides(): void
    {
        $site = $this->site('vapeandgo');
        $this->writer('vapeandgo');
        $modes = new SiteModes(self::$db);
        $desk = $this->staffUser('purchasing_desk');
        $sku = $this->item('legacy', 5);
        $this->listing($site, 'BIG', $sku);
        $modes->set($desk, $sku, 'In-Stock', ['vapeandgo'], '', 'sold as today');
        $modes->set($desk, $sku, SiteModes::WEBSITE, ['vapeandgo'], '', 'the website decides');
        self::assertSame([null, 'site_own'], [$this->siteOf($site, 'BIG')['mode'], $this->siteOf($site, 'BIG')['why']]);
        // Blocked by its item card: Out-Of-Stock whatever the mode; lifted: the website's own again.
        $this->card($sku, ['product_type' => 'e_liquid', 'liquid_ml' => '20', 'nicotine_mg' => '6', 'duty_liable' => 'yes'], true, true);
        self::assertSame(['Out-Of-Stock', 0, 'blocked'], [$this->siteOf($site, 'BIG')['mode'], $this->siteOf($site, 'BIG')['backorders'], $this->siteOf($site, 'BIG')['why']]);
        $this->card($sku, ['liquid_ml' => '10'], true);
        self::assertSame([null, 'site_own'], [$this->siteOf($site, 'BIG')['mode'], $this->siteOf($site, 'BIG')['why']]);

        // A quarantined listing (its link being checked): Out-Of-Stock, whatever the website decides.
        $other = $this->item('legacy', 2);
        $this->listing($site, 'QUAR', $other, 1, 'quarantined');
        $modes->set($desk, $other, SiteModes::WEBSITE, ['vapeandgo'], '2', 'the website decides, warn at two');
        self::assertSame(['Out-Of-Stock', 0, 'quarantined', 2], [$this->siteOf($site, 'QUAR')['mode'], $this->siteOf($site, 'QUAR')['backorders'],
            $this->siteOf($site, 'QUAR')['why'], $this->siteOf($site, 'QUAR')['low_stock_threshold']]);
        self::assertSame([], SiteWriterInvariants::check(self::$db));
    }

    public function testARefusalWritesNothing(): void
    {
        $vpg = $this->site('vapeandgo');
        $modes = new SiteModes(self::$db);
        $desk = $this->staffUser('purchasing_desk');
        $sku = $this->item('legacy', 1);
        $modes->set($desk, $sku, 'In-Stock', ['vapeandgo'], '', 'held by CW');
        $stale = SiteModes::stamp([]);
        foreach ([
            ['bad_reason', fn () => $modes->set($desk, $sku, SiteModes::WEBSITE, ['vapeandgo'], '', 'no')],
            ['bad_threshold', fn () => $modes->set($desk, $sku, SiteModes::WEBSITE, ['vapeandgo'], '-1', 'negative')],
            ['no_sites', fn () => $modes->set($desk, $sku, SiteModes::WEBSITE, [], '', 'no site')],
            ['unknown_site', fn () => $modes->set($desk, $sku, SiteModes::WEBSITE, ['vapebig'], '', 'not a site here')],
            ['selling_mode_changed', fn () => $modes->set($desk, $sku, SiteModes::WEBSITE, ['vapeandgo'], '', 'what I saw', $stale)],
            ['protected_item', fn () => $modes->set($desk, $this->item('strict', 1), SiteModes::WEBSITE, ['vapeandgo'], '', 'counted')],
            ['bad_mode', fn () => $modes->set($desk, $sku, 'Website', ['vapeandgo'], '', 'a word in other letters')],
        ] as [$code, $fn]) {
            $this->refusedCode($code, $fn);
        }
        self::assertSame(['In-Stock', 1], [$modes->current([$sku])[SiteModes::key($sku, (int) $vpg->channelId)]['mode'],
            (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode_log WHERE sku_id = ?', [$sku])]);
    }

    /**
     * A receipt site (site_writer.receipt_mode_sites; empty on live since 16 Nov, Vape and Go in a new schema): a row the switch gave back
     * is no mode of CW's, so the item's last mode for a receipt is not read from it; a posted receipt then sets the mode there again.
     */
    public function testAReceiptDoesNotReadAGivenBackRowAsCwsMode(): void
    {
        $vpg = $this->site('vapeandgo');
        $this->writer('vapeandgo');
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Given back liquid');
        $this->liquid($sku);
        $this->listing($vpg, 'V1', $sku);
        $modes = new SiteModes(self::$db);
        $modes->set($this->staffUser('purchasing_desk'), $sku, 'In-Stock', ['vapeandgo'], '', 'sold whatever the figure');
        self::assertSame(['In-Stock', 'cw'], [(new SellingModes(self::$db))->current([$sku])[$sku]['mode'], (new SellingModes(self::$db))->current([$sku])[$sku]['from']]);
        $modes->set($this->staffUser('purchasing_desk'), $sku, SiteModes::WEBSITE, ['vapeandgo'], '', 'the website decides');
        self::assertSame(['mode' => null, 'previous' => null, 'from' => null, 'version' => 0], (new SellingModes(self::$db))->current([$sku])[$sku],
            'nothing of CW\'s: not the given-back row');

        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 6, 'pack_price' => '6.00']]);
        $this->ready($desk, $d->id);
        $this->post($desk, $d->id);
        self::assertSame(['From-Warehouse', 'fallback'], array_values((array) self::$db->one('SELECT selling_mode, mode_source FROM grn_line WHERE document_id = ?', [$d->id])));
        $row = $modes->current([$sku])[SiteModes::key($sku, (int) $vpg->channelId)];
        self::assertSame(['From-Warehouse', null, 3, 'receipt'], [$row['mode'], $row['previous'], $row['version'], $row['source']], 'a receipt site: the delivery sets it again');
        self::assertSame(['From-Warehouse', 'site_mode'], [$this->siteOf($vpg, 'V1')['mode'], $this->siteOf($vpg, 'V1')['why']]);
        self::assertSame([], SiteWriterInvariants::check(self::$db));
    }
}
