<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\ChannelAdmin;
use CW\SiteWriter\SiteModes;
use CW\Tests\Support\KernelUiTestCase;

/**
 * The selling-mode switch on the item page through the real /ui kernel as cw_app (IM10; docs/decisions.md I158): per website, what
 * CW writes there (with CW's meaning of the label), whether its site stock writer is on, who set it; the form for a legacy item
 * (ticked websites or all, an optional threshold, a reason), one effect per form, a stale page refused (409) with what was typed
 * kept; a counted item has no form; who may (the desk) and who may not (a buyer: no form, 403 on POST).
 */
final class SellingModeScreenTest extends KernelUiTestCase
{
    public function testTheDeskSetsAModeOnOneWebsite(): void
    {
        $vpg = $this->site('vapeandgo');
        $ef = $this->site('electrofag');
        (new ChannelAdmin(self::$db))->configure('vapeandgo', null, null, 'tester', true, true);
        $sku = $this->item('legacy', 12, 'Switch liquid 10ml');
        $this->listing($vpg, '4501', $sku);
        $this->listing($ef, '77', $sku);
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $web->get("/ui/items/{$sku}");
        self::assertSame(200, $page->status, $page->describe());
        $text = $page->text();
        self::assertStringContainsString('Selling mode on the websites', $text);
        self::assertStringContainsString('the site keeps its own', $text);
        self::assertStringContainsString('receipts set it', $text, 'Vape and Go is the receipt site (I136)');
        self::assertStringContainsString('never set in CW', $text);
        self::assertTrue($page->hasForm('/selling-mode'));
        self::assertSame(['In-Stock', 'From-Warehouse', 'Out-Of-Stock'], $page->radios('mode'));
        self::assertStringContainsString('sold whatever the figure', $text, 'CW\'s meaning beside each label');

        $f = $page->form("/ui/items/{$sku}/selling-mode");
        self::assertSame(['csrf', 'form_key', 'stamp', 'threshold', 'reason'], array_keys($f));
        $send = ['mode' => 'In-Stock', 'site_vapeandgo' => '1', 'threshold' => '3', 'reason' => 'container landed'] + $f;
        $r = $web->post("/ui/items/{$sku}/selling-mode", $send);
        self::assertSame([303, "/ui/items/{$sku}?notice=selling_mode_set#selling-mode"], [$r->status, $r->location()], $r->describe());
        self::assertSame($r->location(), $web->post("/ui/items/{$sku}/selling-mode", $send)->location(), 'one effect per form');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode_log WHERE sku_id = ?', [$sku]));
        $page = $web->follow($r);
        self::assertStringContainsString('Selling mode saved.', $page->text());
        self::assertStringContainsString('In-Stock (sold whatever the figure)', $page->text());
        self::assertStringContainsString('the switch, Purchasing_desk', $page->text());
        $rows = (new SiteModes(self::$db))->current([$sku]);
        self::assertSame(['In-Stock', 3], [$rows[SiteModes::key($sku, (int) $vpg->channelId)]['mode'], $rows[SiteModes::key($sku, (int) $vpg->channelId)]['threshold']]);
        self::assertArrayNotHasKey(SiteModes::key($sku, (int) $ef->channelId), $rows, 'only the ticked website');

        // A page drawn before someone else's change: refused, nothing written, what was typed kept.
        $stale = $page->form("/ui/items/{$sku}/selling-mode");
        (new SiteModes(self::$db))->set($this->staffUser('manager'), $sku, 'From-Warehouse', ['*'], '', 'someone else');
        $r = $web->post("/ui/items/{$sku}/selling-mode", ['mode' => 'Out-Of-Stock', 'all_sites' => '1', 'reason' => 'typed text'] + $stale);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString('Someone changed this item\'s selling mode a moment ago', $r->text());
        self::assertSame('typed text', $r->form("/ui/items/{$sku}/selling-mode")['reason']);
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode_log WHERE sku_id = ?', [$sku]));
        // No website ticked: refused with the reason kept.
        $f = $web->get("/ui/items/{$sku}")->form("/ui/items/{$sku}/selling-mode");
        $r = $web->post("/ui/items/{$sku}/selling-mode", ['mode' => 'Out-Of-Stock', 'reason' => 'no site'] + $f);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('Tick the websites', $r->text());
    }

    public function testACountedItemHasNoFormAndABuyerMayNotSetIt(): void
    {
        $vpg = $this->site('vapeandgo');
        $counted = $this->item('strict', 4, 'Counted coil');
        $legacy = $this->item('legacy', 4, 'Legacy coil');
        $this->listing($vpg, '1', $counted);
        $this->listing($vpg, '2', $legacy);
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $desk->get("/ui/items/{$counted}");
        self::assertStringContainsString('counted and protected (policy strict)', $page->text());
        self::assertStringContainsString('From-Warehouse (sold while there is stock)', $page->text());
        self::assertFalse($page->hasForm('/selling-mode'));
        $f = $desk->get("/ui/items/{$legacy}")->form("/ui/items/{$legacy}/selling-mode");
        $r = $desk->post("/ui/items/{$counted}/selling-mode", ['mode' => 'In-Stock', 'all_sites' => '1', 'reason' => 'try anyway'] + $f);
        self::assertSame(409, $r->status);
        self::assertStringContainsString('is counted and protected', $r->text());

        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertFalse($buyer->get("/ui/items/{$legacy}")->hasForm('/selling-mode'));
        $r = $buyer->post("/ui/items/{$legacy}/selling-mode", ['mode' => 'In-Stock', 'all_sites' => '1', 'reason' => 'not mine'] + ['csrf' => $this->token($buyer),
            'form_key' => str_repeat('a', 32), 'stamp' => '']);
        self::assertSame(403, $r->status);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode'));
    }
}
