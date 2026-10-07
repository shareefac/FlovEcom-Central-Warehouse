<?php

declare(strict_types=1);

namespace CW\Tests\Integration\SiteWriter;

use CW\ChannelAdmin;
use CW\SiteWriter\SiteModes;
use CW\SiteWriter\SiteWriterInvariants;
use CW\Tests\Integration\Receiving\ReceivingTestCase;

/**
 * The mode on receipt reaches the feed (IM6 I136 -> IM10; docs/decisions.md I152, I157): a posted receipt's mode becomes the item's
 * mode on the receipt sites (site_writer.receipt_mode_sites: Vape and Go only), with a feed row even when no stock moved; the
 * switch's Out-Of-Stock on Vape and Go is what the next receipt brings an item back from; other sites keep their own mode.
 */
final class ReceiptModesTest extends ReceivingTestCase
{
    public function testAReceiptsModeLandsOnTheReceiptSitesOnlyAndReachesTheFeed(): void
    {
        $vpg = $this->site('vapeandgo');
        $ef = $this->site('electrofag');
        foreach (['vapeandgo', 'electrofag'] as $code) {
            (new ChannelAdmin(self::$db))->configure($code, null, null, 'tester', true, true);
        }
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Mode liquid 10ml');
        $this->liquid($sku);
        $this->listing($vpg, 'V1', $sku);
        $this->listing($ef, 'E1', $sku);
        $after = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');

        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 2, 'units_per_pack' => 5, 'pack_price' => '10.00', 'mode_choice' => 'In-Stock']]);
        $this->ready($desk, $d->id);
        $this->post($desk, $d->id);

        $row = self::$db->one('SELECT mode, previous_mode, low_stock_threshold, version, source, document_id FROM item_channel_mode WHERE sku_id = ? AND channel_id = ?',
            [$sku, $vpg->channelId]);
        self::assertSame(['In-Stock', null, null, 1, 'receipt', $d->id], [$row['mode'], $row['previous_mode'], $row['low_stock_threshold'], (int) $row['version'],
            $row['source'], (int) $row['document_id']]);
        self::assertNull(self::$db->value('SELECT mode FROM item_channel_mode WHERE sku_id = ? AND channel_id = ?', [$sku, $ef->channelId]),
            'Electrofag is not a receipt site (I136)');
        $v = $this->avail->changes((int) $vpg->channelId, $after)['listings'][0];
        self::assertSame(['V1', 10, 'In-Stock', 'site_mode'], [$v['variant_id'], $v['site']['qty'], $v['site']['mode'], $v['site']['why']]);
        self::assertSame([['goods_in', 10]], array_map(static fn (array $m): array => [$m['type'], $m['units']], $v['site']['moves']));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM stock_change WHERE sku_id = ? AND reason = 'mode' AND seq > ?", [$sku, $after]));
        $e = $this->avail->changes((int) $ef->channelId, $after)['listings'][0];
        self::assertSame(['E1', 10, null, 'site_own'], [$e['variant_id'], $e['site']['qty'], $e['site']['mode'], $e['site']['why']],
            'the quantity is shared, the mode is per site');
        self::assertSame([], SiteWriterInvariants::check(self::$db));
    }

    /**
     * A receipt that accepts nothing of an item keeps its mode (I169; reviews of 7 Oct, probe F): a delivery that was all short,
     * refused unstamped at the door or quarantined never takes an Out-Of-Stock item back to In-Stock ("sold whatever the figure")
     * with no stock, which would also fire the site's back-in-stock e-mails. The next receipt that accepts units brings it back to
     * its previous mode, and the feed says so.
     */
    public function testAReceiptThatAcceptsNothingKeepsTheModeAndTheNextOneBringsItBack(): void
    {
        $vpg = $this->site('vapeandgo');
        (new ChannelAdmin(self::$db))->configure('vapeandgo', null, null, 'tester', true, true);
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Back in stock liquid');
        $this->liquid($sku);
        $this->listing($vpg, 'V1', $sku);
        $modes = new SiteModes(self::$db);
        $modes->set($this->staffUser('purchasing_desk'), $sku, 'In-Stock', ['vapeandgo'], '', 'sold whatever the figure');
        $modes->set($this->staffUser('purchasing_desk'), $sku, 'Out-Of-Stock', ['vapeandgo'], '', 'sold out at the supplier');
        $key = SiteModes::key($sku, (int) $vpg->channelId);
        self::assertSame(['Out-Of-Stock', 'In-Stock'], array_values(array_intersect_key($modes->current([$sku])[$key], ['mode' => 0, 'previous' => 0])));

        // Every unit short: nothing booked, the mode kept (not In-Stock with 0 units), no feed row for the mode.
        $after = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 6, 'pack_price' => '6.00']]);
        $this->ready($desk, $d->id, [1 => ['short_units' => '6']]);
        $plan = $this->grns->plan($d->id);
        self::assertStringContainsString('nothing of it is accepted into MAIN, so its selling mode stays Out-Of-Stock', implode(' ', $plan['warnings']));
        $this->post($desk, $d->id);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$d->id]), 'nothing booked');
        self::assertSame(['Out-Of-Stock', 'kept'], array_values((array) self::$db->one('SELECT selling_mode, mode_source FROM grn_line WHERE document_id = ?', [$d->id])));
        self::assertSame(['Out-Of-Stock', 'In-Stock', 2, 'switch'], array_values(array_intersect_key($modes->current([$sku])[$key], ['mode' => 0, 'previous' => 0,
            'source' => 0, 'version' => 0])), 'the site row untouched');
        self::assertNull(self::$db->value('SELECT mode FROM item_selling_mode WHERE sku_id = ?', [$sku]));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM stock_change WHERE sku_id = ? AND reason = 'mode' AND seq > ?", [$sku, $after]));
        self::assertNotContains('V1', array_column($this->avail->changes((int) $vpg->channelId, $after, overlapSec: 0)['listings'], 'variant_id'),
            'nothing new in the feed for it (the overlap window left out)');

        // Refused unstamped at the door (after the refusal date), or all quarantined: kept too.
        $this->setting('receiving.unstamped_refusal_from', json_encode(self::day('-1 day'), JSON_THROW_ON_ERROR));
        foreach (['refuse', 'quarantine'] as $action) {
            $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 6, 'pack_price' => '6.00']]);
            $this->ready($desk, $d->id, [1 => ['stamp_on_pack' => '0', 'unstamped_units' => '6', 'unstamped_action' => $action]]);
            $this->post($desk, $d->id);
            self::assertSame(['Out-Of-Stock', 'kept'], array_values((array) self::$db->one('SELECT selling_mode, mode_source FROM grn_line WHERE document_id = ?', [$d->id])), $action);
        }
        self::assertSame('Out-Of-Stock', $modes->current([$sku])[$key]['mode']);

        // A receipt that accepts units: back to its previous mode (In-Stock) on Vape and Go, and the feed carries it.
        $after = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 6, 'pack_price' => '6.00']]);
        $this->ready($desk, $d->id);
        $this->post($desk, $d->id);
        self::assertSame(['In-Stock', 'previous'], array_values((array) self::$db->one('SELECT selling_mode, mode_source FROM grn_line WHERE document_id = ?', [$d->id])));
        $c = $this->avail->changes((int) $vpg->channelId, $after);
        self::assertSame(['V1'], array_column($c['listings'], 'variant_id'));
        self::assertSame(['In-Stock', 'site_mode', 6], [$c['listings'][0]['site']['mode'], $c['listings'][0]['site']['why'], $c['listings'][0]['site']['qty']]);
        self::assertSame(['receipt', null], array_values((array) self::$db->one('SELECT source, previous_mode FROM item_channel_mode WHERE sku_id = ?', [$sku])));
        self::assertSame([], SiteWriterInvariants::check(self::$db));
        self::assertSame([], \CW\Receiving\ReceivingInvariants::check(self::$db));
    }

    public function testNoReceiptSiteMeansNoSiteMode(): void
    {
        $vpg = $this->site('vapeandgo');
        $this->setting('site_writer.receipt_mode_sites', '""');
        ['desk' => $desk, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Unsited liquid');
        $this->liquid($sku);
        $this->listing($vpg, 'V1', $sku);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'units_per_pack' => 1, 'pack_price' => '1.00']]);
        $this->ready($desk, $d->id);
        $this->post($desk, $d->id);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode WHERE sku_id = ?', [$sku]));
        self::assertSame('From-Warehouse', self::$db->value('SELECT mode FROM item_selling_mode WHERE sku_id = ?', [$sku]), 'the item\'s own mode is still kept');
    }

    public function testTheSettingIsChecked(): void
    {
        $settings = new \CW\Settings(self::$db);
        $settings->checkRule('site_writer.receipt_mode_sites', 'vapeandgo,electrofag');
        $settings->checkRule('site_writer.receipt_mode_sites', '');
        $this->refusedCode('bad_value', fn () => $settings->checkRule('site_writer.receipt_mode_sites', 'vapeandgo, electrofag'));
        $this->refusedCode('bad_value', fn () => $settings->checkRule('site_writer.receipt_mode_sites', 'Vape and Go'));
    }
}
