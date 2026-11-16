<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\ChannelAdmin;
use CW\Mapping\ListingIngestService;
use CW\SiteWriter\SiteModes;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Html;
use CW\Ui\Words;

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
        self::assertStringContainsString('the website keeps its own', $text);
        self::assertStringContainsString('deliveries set it', $text, 'Vape and Go is the receipt site (I136)');
        self::assertStringContainsString('never set here', $text);
        self::assertTrue($page->hasForm('/selling-mode'));
        self::assertSame(['In-Stock', 'From-Warehouse', 'Out-Of-Stock', SiteModes::WEBSITE], $page->radios('mode'), 'and "Let the website decide" (T-556 option A)');
        self::assertStringContainsString(Words::MODE_MEANING['In-Stock'], $text, 'CW\'s meaning beside each label, in words (U89)');
        self::assertStringContainsString('<table class="stack list selling-sites">', $page->body, 'one card per website on a phone');

        $f = $page->form("/ui/items/{$sku}/selling-mode");
        self::assertSame(['csrf', 'form_key', 'stamp', 'threshold', 'reason'], array_keys($f));
        $send = ['mode' => 'In-Stock', 'site_vapeandgo' => '1', 'threshold' => '3', 'reason' => 'container landed'] + $f;
        $r = $web->post("/ui/items/{$sku}/selling-mode", $send);
        self::assertSame([303, "/ui/items/{$sku}?notice=selling_mode_set#selling-mode"], [$r->status, $r->location()], $r->describe());
        self::assertSame($r->location(), $web->post("/ui/items/{$sku}/selling-mode", $send)->location(), 'one effect per form');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode_log WHERE sku_id = ?', [$sku]));
        $page = $web->follow($r);
        self::assertStringContainsString('Selling mode saved.', $page->text());
        self::assertStringContainsString('In-Stock ' . Words::MODE_MEANING['In-Stock'], $page->text());
        self::assertStringContainsString('the switch, Purchasing_desk', $page->text());
        $rows = (new SiteModes(self::$db))->current([$sku]);
        self::assertSame(['In-Stock', 3], [$rows[SiteModes::key($sku, (int) $vpg->channelId)]['mode'], $rows[SiteModes::key($sku, (int) $vpg->channelId)]['threshold']]);
        self::assertArrayNotHasKey(SiteModes::key($sku, (int) $ef->channelId), $rows, 'only the ticked website');

        // A page drawn before someone else's change: refused, nothing written, what was typed kept.
        $stale = $page->form("/ui/items/{$sku}/selling-mode");
        (new SiteModes(self::$db))->set($this->staffUser('manager'), $sku, 'From-Warehouse', ['*'], '', 'someone else');
        $r = $web->post("/ui/items/{$sku}/selling-mode", ['mode' => 'Out-Of-Stock', 'all_sites' => '1', 'reason' => 'typed text'] + $stale);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::SELLING_ERROR['selling_mode_changed'], $r->text());
        self::assertSame('typed text', $r->form("/ui/items/{$sku}/selling-mode")['reason']);
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode_log WHERE sku_id = ?', [$sku]));
        // No website ticked: refused with the reason kept.
        $f = $web->get("/ui/items/{$sku}")->form("/ui/items/{$sku}/selling-mode");
        $r = $web->post("/ui/items/{$sku}/selling-mode", ['mode' => 'Out-Of-Stock', 'reason' => 'no site'] + $f);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::SELLING_ERROR['no_sites'], $r->text());
    }

    /**
     * T-556 step 2, option A (the owner, 16 Nov 2026): "Let the website decide" gives the ticked websites' mode back to them (the row
     * kept, its mode NULL, the feed told), with its own notice and "given back to the website" under "Set by"; and "Mode on the
     * website now" shows each website product's own mode as its connector last sent it ("not known yet" before; view only).
     */
    public function testTheDeskLetsTheWebsiteDecideAndSeesEachStoresOwnMode(): void
    {
        $vpg = $this->site('vapeandgo');
        (new ChannelAdmin(self::$db))->configure('vapeandgo', null, null, 'tester', true, true);
        $sku = $this->item('legacy', 12, 'Given back liquid 10ml');
        $this->listing($vpg, '4501', $sku);
        (new SiteModes(self::$db))->set($this->staffUser('manager'), $sku, 'From-Warehouse', ['vapeandgo'], '', 'held by CW at the switch');
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $web->get("/ui/items/{$sku}");
        $text = $page->text();
        self::assertStringContainsString(Words::SELLING['site_now'], $text);
        self::assertStringContainsString(Words::SELLING['site_not_known'], $text, 'an older connector sends no mode of its own');
        self::assertStringContainsString(Words::SELLING['website_decides'] . ': ' . Words::SELLING['website_decides_meaning'], $text, 'the choice, in words');

        // The store's connector (0.4.13) sends the website's own mode with the listing.
        (new ListingIngestService(self::$db))->push($vpg, [['variant_id' => '4501', 'product_title' => 'Given back liquid',
            'selling' => ['stock_mode' => 'From-Warehouse', 'allow_backorders' => 1, 'low_stock_threshold' => 3]]]);
        $at = (string) self::$db->value('SELECT s.as_of FROM listing_selling s JOIN channel_listing l ON l.id = s.listing_id WHERE l.external_variant_id = ?', ['4501']);
        $text = $web->get("/ui/items/{$sku}")->text();
        self::assertStringNotContainsString(Words::SELLING['site_not_known'], $text);
        self::assertStringContainsString('From-Warehouse ' . Words::SELLING['backorders'] . ' ' . Words::say('SELLING', 'site_threshold', '3') . ' '
            . Words::say('SELLING', 'site_as_of', Html::when($at)), $text);

        $f = $web->get("/ui/items/{$sku}")->form("/ui/items/{$sku}/selling-mode");
        $r = $web->post("/ui/items/{$sku}/selling-mode", ['mode' => SiteModes::WEBSITE, 'site_vapeandgo' => '1', 'reason' => 'staff set it on the website'] + $f);
        self::assertSame([303, "/ui/items/{$sku}?notice=selling_mode_website#selling-mode"], [$r->status, $r->location()], $r->describe());
        $page = $web->follow($r);
        $text = $page->text();
        self::assertStringContainsString(Words::SELLING_NOTICE['selling_mode_website'], $text);
        self::assertStringContainsString(Words::SELLING['own'], $text, 'Selling mode it gets: the website keeps its own');
        self::assertStringContainsString(Words::SELLING['by_website'] . ', Purchasing_desk', $text);
        $row = (new SiteModes(self::$db))->current([$sku])[SiteModes::key($sku, (int) $vpg->channelId)];
        self::assertSame([null, 2, 'switch'], [$row['mode'], $row['version'], $row['source']]);
        self::assertSame(['website', 'staff set it on the website'], array_values(array_intersect_key(json_decode((string) self::$db->value(
            "SELECT detail FROM audit_log WHERE action = 'selling_mode.set' ORDER BY id DESC LIMIT 1"), true), ['mode' => 0, 'reason' => 0])));
        // Again: nothing changes.
        $f = $page->form("/ui/items/{$sku}/selling-mode");
        $r = $web->post("/ui/items/{$sku}/selling-mode", ['mode' => SiteModes::WEBSITE, 'all_sites' => '1', 'reason' => 'again'] + $f);
        self::assertSame("/ui/items/{$sku}?notice=selling_mode_unchanged#selling-mode", $r->location());
    }

    public function testACountedItemHasNoFormAndABuyerMayNotSetIt(): void
    {
        $vpg = $this->site('vapeandgo');
        $counted = $this->item('strict', 4, 'Counted coil');
        $legacy = $this->item('legacy', 4, 'Legacy coil');
        $this->listing($vpg, '1', $counted);
        $this->listing($vpg, '2', $legacy);
        // The stock link is on only when both switches are: the site writer AND the website connection live (ChannelAdmin: nothing is
        // written on the site until it is live). Electrofag's writer is on while its connection is on trial: "switched on", not "on".
        $this->site('electrofag', 'shadow');
        (new ChannelAdmin(self::$db))->configure('electrofag', null, null, 'tester', true, true);
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $desk->get("/ui/items/{$counted}");
        self::assertStringContainsString(Words::SELLING['link_off'] . ' ', $page->text(), 'Vape and Go: live, writer off');
        self::assertStringContainsString(Words::SITE_SYNC['live'], $page->text());
        self::assertStringContainsString(Words::SELLING['link_waiting'], $page->text(), 'Electrofag: writer on, connection on trial');
        self::assertStringContainsString(Words::SITE_SYNC['shadow'], $page->text());
        self::assertStringNotContainsString(Words::SELLING['link_on'] . ' ', $page->text());
        (new ChannelAdmin(self::$db))->configure('vapeandgo', null, null, 'tester', true, true);
        self::assertStringContainsString(Words::SELLING['link_on'] . ' ', $desk->get("/ui/items/{$counted}")->text(), 'Vape and Go: live and writer on');
        self::assertStringContainsString('counted and protected (Website sells warehouse stock only)', $page->text());
        self::assertStringContainsString('From-Warehouse ' . Words::MODE_MEANING['From-Warehouse'], $page->text());
        self::assertFalse($page->hasForm('/selling-mode'));
        $f = $desk->get("/ui/items/{$legacy}")->form("/ui/items/{$legacy}/selling-mode");
        $r = $desk->post("/ui/items/{$counted}/selling-mode", ['mode' => 'In-Stock', 'all_sites' => '1', 'reason' => 'try anyway'] + $f);
        self::assertSame(409, $r->status);
        self::assertStringContainsString(Words::SELLING_ERROR['protected_item'], $r->text());

        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertFalse($buyer->get("/ui/items/{$legacy}")->hasForm('/selling-mode'));
        $r = $buyer->post("/ui/items/{$legacy}/selling-mode", ['mode' => 'In-Stock', 'all_sites' => '1', 'reason' => 'not mine'] + ['csrf' => $this->token($buyer),
            'form_key' => str_repeat('a', 32), 'stamp' => '']);
        self::assertSame(403, $r->status);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_channel_mode'));
    }
}
