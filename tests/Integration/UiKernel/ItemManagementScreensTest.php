<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Items\Families;
use CW\Items\Packs;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * Pack B's screens through the real /ui kernel as cw_app (docs/decisions.md PB1-PB16), with the production document types (the real
 * RPK): Products › Add Item (an item made by hand, its barcode refused when another item has it, the same name and identity warned
 * about, the form sent twice making one item), Products › Families (a family, a pasted list with the skipped lines listed, ticked items
 * taken out, the item page's family box), Products › Multipacks (a pack item, the suggestion that follows the owner's rule, the pack
 * links), Stock › Repack (booked, then cancelled on its record page); the Approval rules and Settings pages; who may open and post
 * what, and the CSRF token on every POST.
 */
final class ItemManagementScreensTest extends KernelUiTestCase
{
    /** The product card's "not known" answers as a browser sends them (the test client sends "on" for a checked radio with value=""). */
    private const NOT_KNOWN = ['c_duty_liable' => '', 'c_single_use' => ''];

    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    /** Posts a page's form as drawn, with $over. @param array<string, string> $over */
    private function submit(KernelBrowser $web, string $page, string $action, array $over = []): \CW\Tests\Support\UiResponse
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe() . ' ' . implode(' | ', self::$log));
        $form = $p->form($action, true);
        self::assertNotSame([], $form, "no form {$action}: " . $p->describe());
        return $web->post($action, $over + $form);
    }

    public function testAnItemIsAddedByHandOnTheScreen(): void
    {
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get('/ui/items/new');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['Items', 'Items'], [self::currentSection($page), self::currentTab($page)], 'the new item\'s form is a page of Items (RD1)');
        self::assertStringNotContainsString(Words::PAGE_INTRO['item_new'][0], $page->text(), 'no sentence under the heading (DC1)');
        $form = $page->form('/ui/items/new', true);
        self::assertArrayHasKey('form_key', $form);
        // (a radio checked with value="" is sent as "" by a browser; the test client sends "on": say so)
        $fill = ['name' => 'Trade Mango 10ml', 'brand' => 'Acme', 'strength_mg' => '20', 'c_product_type' => 'e_liquid', 'c_liquid_ml' => '10', 'barcode' => '5012345678900']
            + self::NOT_KNOWN;
        $r = $web->post('/ui/items/new', $fill + $form);
        self::assertSame(303, $r->status, ($r->errorCode() ?? "") . " " . $r->describe() . ' ' . implode(' | ', self::$log));
        $again = $web->post('/ui/items/new', $fill + $form);
        self::assertSame($r->location(), $again->location(), 'one item however often the form is sent');
        $id = (int) self::$db->value("SELECT id FROM sku WHERE origin = 'manual'");
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE origin = 'manual'"));
        self::assertStringStartsWith('/ui/items/' . $id, (string) $r->location());
        $item = $web->follow($r);
        self::assertSame(200, $item->status, $item->describe());
        self::assertStringContainsString(Words::ITEMS_NOTICE['item_made'], $item->text());
        self::assertStringContainsString(Words::FAMILY['in_none'], $item->text());
        self::assertSame('e_liquid', self::$db->value('SELECT product_type FROM item_card WHERE sku_id = ?', [$id]));

        // The same barcode again: refused, said in words, nothing made.
        $r = $this->submit($web, '/ui/items/new', '/ui/items/new', ['name' => 'Another thing', 'barcode' => '5012345678900'] + self::NOT_KNOWN);
        self::assertSame(409, $r->status);
        self::assertStringContainsString(Words::ITEMS_ERROR['barcode_on_other_item'], $r->text());
        self::assertSame('Another thing', $r->form('/ui/items/new')['name'], 'what was typed is kept');
        // The same name and identity: the other item is named; ticking "different" adds it.
        $r = $this->submit($web, '/ui/items/new', '/ui/items/new', ['name' => 'Trade Mango 10ml', 'brand' => 'Acme', 'strength_mg' => '20'] + self::NOT_KNOWN);
        self::assertSame(409, $r->status);
        self::assertStringContainsString(Words::ITEM_NEW['same_title'], $r->text());
        self::assertStringContainsString(sprintf('CW-%06d', $id), $r->text());
        $form = $r->form('/ui/items/new', true);
        self::assertArrayNotHasKey('anyway', $form, 'not ticked for the person');
        $ok = $web->post('/ui/items/new', ['anyway' => '1'] + self::NOT_KNOWN + $form);
        self::assertSame(303, $ok->status, ($ok->errorCode() ?? "") . " " . $ok->describe());
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE origin = 'manual'"));
        // The list of items made by hand (a view of its own, docs/decisions.md W11.3) names both.
        self::assertStringContainsString(sprintf('CW-%06d', $id), $web->get('/ui/items/new', ['show' => 'all'])->text());

        // With the second check switched on, another person checks a new item afterwards (it is usable at once).
        self::$db->exec("UPDATE app_setting SET value_json = 'true' WHERE setting_key = 'approvals.item_by_hand_check'");
        try {
            $r = $this->submit($web, '/ui/items/new', '/ui/items/new', ['name' => 'Checked later'] + self::NOT_KNOWN);
            self::assertSame(303, $r->status, ($r->errorCode() ?? "") . " " . $r->describe());
            self::assertStringContainsString(Words::ITEMS_NOTICE['item_made_waiting'], $web->follow($r)->text());
            $new = (int) self::$db->value("SELECT id FROM sku WHERE name = 'Checked later'");
            self::assertFalse($web->get('/ui/items/new', ['show' => 'waiting'])->hasForm('/ui/items/new/' . $new . '/check'), 'not by the person who made it');
            $lead = $this->signIn($this->uiUser('mapping_lead'));
            $waiting = $lead->get('/ui/items/new', ['show' => 'waiting']);
            $r = $lead->post('/ui/items/new/' . $new . '/check', ['note' => 'right'] + $waiting->form('/ui/items/new/' . $new . '/check'));
            self::assertSame(303, $r->status, ($r->errorCode() ?? "") . " " . $r->describe() . ' ' . implode(' | ', self::$log));
            self::assertStringContainsString(Words::ITEMS_NOTICE['checked'], $lead->follow($r)->text());
            self::assertSame('checked', self::$db->value('SELECT check_state FROM item_by_hand WHERE sku_id = ?', [$new]));
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = 'false' WHERE setting_key = 'approvals.item_by_hand_check'");
        }
    }

    public function testFamiliesAreMadeFilledAndEmptiedOnTheScreens(): void
    {
        $web = $this->signIn($this->uiUser('buyer'));
        $list = $web->get('/ui/items/families');
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(['Items', 'Item groups'], [self::currentSection($list), self::currentTab($list)]);
        $r = $this->submit($web, '/ui/items/families', '/ui/items/families', ['name' => 'Elux Legend']);
        self::assertSame(303, $r->status, ($r->errorCode() ?? "") . " " . $r->describe());
        $fid = (int) self::$db->value("SELECT id FROM item_family WHERE name = 'Elux Legend'");
        $page = $web->follow($r);
        self::assertStringContainsString(Words::ITEMS_NOTICE['family_made'], $page->text());

        [$a, $b, $joined] = [$this->item('legacy', 3, 'Legend Blue'), $this->item('legacy', 0, 'Legend Red'), $this->item('legacy', 0, 'Joined')];
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$a, $joined]);
        $path = '/ui/items/families/' . $fid;
        $r = $this->submit($web, $path, $path . '/add', ['list' => sprintf("CW-%06d\n%d\nCW-%06d\nnot-a-number", $a, $b, $joined)]);
        self::assertSame(200, $r->status, ($r->errorCode() ?? "") . " " . $r->describe());
        self::assertStringContainsString(sprintf(Words::FAMILY['result_added'], 2), $r->text());
        self::assertStringContainsString(sprintf(Words::FAMILY['result_skipped'], 2), $r->text());
        self::assertStringContainsString(Words::FAMILY_SKIP['merged'], $r->text());
        self::assertStringContainsString(Words::FAMILY_SKIP['not_found'], $r->text());
        self::assertSame(2, (new Families(self::$db))->get($fid)['items']);

        // Ticked items out; a rename; the item page's family box.
        $r = $this->submit($web, $path, $path . '/remove', ['pick_' . $b => '1']);
        self::assertSame(200, $r->status);
        self::assertStringContainsString(sprintf(Words::FAMILY['result_removed'], 1), $r->text());
        $r = $this->submit($web, $path, $path . '/rename', ['name' => 'Elux Legend 3500']);
        self::assertSame(303, $r->status);
        self::assertSame('Elux Legend 3500', self::$db->value('SELECT name FROM item_family WHERE id = ?', [$fid]));
        $item = $web->get('/ui/items/' . $b);
        self::assertStringContainsString(Words::FAMILY['in_none'], $item->text());
        $r = $this->submit($web, '/ui/items/' . $b, '/ui/items/' . $b . '/family', ['family' => 'elux legend 3500']);
        self::assertSame(303, $r->status, ($r->errorCode() ?? "") . " " . $r->describe());
        self::assertSame($fid, Families::ofItems(self::$db, [$b])[$b]['id'] ?? null);
        $r = $this->submit($web, '/ui/items/' . $b, '/ui/items/' . $b . '/family', ['family' => 'No such family']);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::ITEMS_ERROR['unknown_family'], $r->text());
        // By Item names the family (for those who see it).
        $this->site('vapeandgo', 'off');
        $lead = $this->signIn($this->uiUser('mapping_lead'));
        self::assertStringContainsString(sprintf(Words::FAMILY['of_item'], 'Elux Legend 3500'), $lead->get('/ui/review/products', ['q' => sprintf('CW-%06d', $a)])->text());
        // Switched off: kept, said on its page, and gone from the list.
        $r = $this->submit($web, $path, $path . '/switch');
        self::assertSame(303, $r->status);
        self::assertSame(Words::FAMILY['off'], trim((string) (new \DOMXPath($web->get($path)->dom()))->evaluate('string(//div[@class="doc-head"]//h1/span[contains(@class, "chip")])')),
            'its status block says it');
        self::assertNotContains($path, $web->get('/ui/items/families')->hrefs(), 'a switched-off family is left out of the list');
    }

    public function testPackItemsTheOwnersRuleAndThePackLinksOnTheScreens(): void
    {
        $lead = $this->signIn($this->uiUser('mapping_lead'));
        $site = $this->site('vapeandgo', 'off');
        [$box, $shot] = [$this->item('legacy', 2, 'Box 10 x 10ml'), $this->item('legacy', 30, 'Shot 10ml')];
        $page = $lead->get('/ui/items/packs');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['Items', 'Multipacks'], [self::currentSection($page), self::currentTab($page)]);
        self::assertSame(['Pack items', 'Pack links'], array_column(self::segments($page), 'label'));
        $r = $this->submit($lead, '/ui/items/packs', '/ui/items/packs', ['pack' => sprintf('CW-%06d', $box), 'single' => (string) $shot, 'units' => '10']);
        self::assertSame(303, $r->status, ($r->errorCode() ?? "") . " " . $r->describe());
        $list = $lead->follow($r);
        self::assertStringContainsString(Words::ITEMS_NOTICE['pack_made'], $list->text());
        self::assertStringContainsString(sprintf(Words::PACKS['equals'], 10), $list->text());
        self::assertStringNotContainsString(Words::PACKS['suggest_title'], $list->text(), 'no variant yet: nothing to suggest');
        self::assertStringContainsString(sprintf(Words::PACKS['pack_is'], 10), $lead->get('/ui/items/' . $box)->text(), 'the item page names the pack');

        // The box is sold as 1 unit, and the single on its own: the owner's rule suggests the box's variant as 10 singles.
        $boxVariant = $this->profiled($site, 'BOX', ['product_title' => 'Nic shot box'], $box);
        $this->profiled($site, 'SHOT', ['product_title' => 'Nic shot'], $shot);
        $list = $lead->get('/ui/items/packs');
        self::assertStringContainsString(Words::PACKS['suggest_title'], $list->text());
        self::assertContains('/ui/review/listing/' . $boxVariant . '?pick=' . $shot . '#decide', $list->hrefs());
        // A variant matched with 10 units a sale is a pack link; the pack item of 10 is named beside it.
        $this->profiled($site, 'SHOT10', ['product_title' => 'Nic shot 10 pack'], $shot, 10);
        $links = $lead->get('/ui/items/packs/links');
        self::assertSame(200, $links->status, $links->describe());
        self::assertStringContainsString(sprintf(Words::PACK_LINK_FLAG['pack_item_exists'], sprintf('CW-%06d', $box)), $links->text());
        // Refusals in words; switching off.
        $r = $this->submit($lead, '/ui/items/packs', '/ui/items/packs', ['pack' => (string) $box, 'single' => (string) $shot, 'units' => '5']);
        self::assertSame(409, $r->status);
        self::assertStringContainsString(Words::ITEMS_ERROR['pack_exists'], $r->text());
        $id = (int) self::$db->value('SELECT id FROM item_pack');
        $r = $lead->post('/ui/items/packs/' . $id . '/switch', ['csrf' => $this->token($lead), 'on' => '0']);
        self::assertSame(303, $r->status);
        self::assertNull((new Packs(self::$db))->activeOf($box));
        self::assertStringNotContainsString(sprintf(Words::PACKS['pack_is'], 10), $lead->get('/ui/items/' . $box)->text(), 'switched off: no longer said');
    }

    public function testARepackIsBookedOnTheScreenAndCancelledOnItsRecordPage(): void
    {
        $sc = $this->signIn($this->uiUser('stock_controller'));
        [$box, $shot] = [$this->item('legacy', 3, 'Box of 6'), $this->item('legacy', 0, 'Single')];
        $def = (new Packs(self::$db))->create($this->staffUser('mapping_lead'), $box, $shot, 6)['id'];
        $page = $sc->get('/ui/stock/repack');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['Inventory', 'Repack'], [self::currentSection($page), self::currentTab($page)]);
        $form = $page->form('/ui/stock/repack', true);
        self::assertSame([(string) $def, 'break', '1'], [$form['pack'], $form['direction'], $form['packs']]);
        $form = ['packs' => '2', 'warehouse' => (string) self::warehouseId('MAIN')] + $form;
        $r = $sc->post('/ui/stock/repack', $form);
        self::assertSame(303, $r->status, ($r->errorCode() ?? "") . " " . $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($r->location(), $sc->post('/ui/stock/repack', $form)->location(), 'booked once however often it is sent');
        $done = $sc->follow($r);
        self::assertStringContainsString(sprintf(Words::REPACK['done_posted'], 'RPK-000001'), $done->text());
        self::assertSame([1, 12], [(int) self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ?', [$box]),
            (int) self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ?', [$shot])]);
        $doc = (int) self::$db->value("SELECT id FROM document WHERE doc_type = 'RPK'");
        $record = $sc->get('/ui/documents/' . $doc);
        self::assertSame(200, $record->status, $record->describe());
        $r = $sc->post('/ui/documents/' . $doc . '/reverse', ['reason_code' => 'entered_in_error'] + $record->form('/ui/documents/' . $doc . '/reverse'));
        self::assertSame(303, $r->status, ($r->errorCode() ?? "") . " " . $r->describe());
        self::assertSame([3, 0], [(int) self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ?', [$box]),
            (int) self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ?', [$shot])]);
        // The item page names the record of each repack row.
        self::assertContains('/ui/documents/' . $doc, $sc->get('/ui/items/' . $box)->hrefs());
        // A refusal in words, nothing booked.
        $r = $sc->post('/ui/stock/repack', ['packs' => '0', 'warehouse' => (string) self::warehouseId('MAIN')] + $sc->get('/ui/stock/repack')->form('/ui/stock/repack', true));
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::ITEMS_ERROR['bad_packs'], $r->text());
    }

    public function testTheRulesAndSettingsPagesShowPackB(): void
    {
        $rev = $this->signIn($this->uiUser('reviewer'));
        $rules = $rev->get('/ui/reference/approvals');
        self::assertSame(200, $rules->status);
        self::assertStringContainsString(Words::RULE['approvals.item_by_hand_check']['title'], $rules->text());
        self::assertStringContainsString(Words::RULE['approvals.item_by_hand_check']['off'], $rules->text(), 'off by default');
        self::assertStringContainsString(Words::DOC_TYPES['RPK'], $rules->text());
        $settings = $rev->get('/ui/reference/settings');
        foreach (['items.pack_suggest_single_sold', 'items.family_bulk_max_rows', 'items.repack_carry_cost'] as $key) {
            self::assertStringContainsString(Words::SETTING[$key], $settings->text(), $key);
        }
    }

    public function testWhoMayOpenAndPostAndEveryPostNeedsTheCsrfToken(): void
    {
        $item = $this->item('legacy', 0, 'Some item');
        $fid = (new Families(self::$db))->create($this->staffUser('buyer'), 'A family')['id'];
        $gets = [
            '/ui/items/new' => ['stock_controller' => 200, 'mapping_lead' => 200, 'purchasing_manager' => 200, 'buyer' => 403, 'viewer' => 403, 'admin' => 403],
            '/ui/items/families' => ['viewer' => 200, 'buyer' => 200, 'admin' => 200, 'goods_in' => 200],
            '/ui/items/families/' . $fid => ['viewer' => 200, 'auditor' => 200],
            '/ui/items/packs' => ['viewer' => 200, 'buyer' => 200, 'goods_in' => 200],
            '/ui/items/packs/links' => ['viewer' => 200, 'mapper' => 200, 'buyer' => 403, 'stock_controller' => 403],
            '/ui/stock/repack' => ['stock_controller' => 200, 'warehouse' => 200, 'buyer' => 200, 'viewer' => 403, 'mapper' => 403],
        ];
        $web = [];
        foreach (['stock_controller', 'mapping_lead', 'purchasing_manager', 'buyer', 'viewer', 'admin', 'goods_in', 'auditor', 'mapper', 'warehouse'] as $role) {
            $web[$role] = $this->signIn($this->uiUser($role));
        }
        foreach ($gets as $path => $want) {
            foreach ($want as $role => $status) {
                self::assertSame($status, $web[$role]->get($path)->status, "{$role} GET {$path}");
            }
        }
        // The create buttons are for the people who may use them.
        self::assertFalse($web['viewer']->get('/ui/items/families')->hasForm('/ui/items/families'));
        self::assertFalse($web['buyer']->get('/ui/stock/repack')->hasForm('/ui/stock/repack'));
        self::assertFalse($web['buyer']->get('/ui/items/packs')->hasForm('/ui/items/packs'));

        $posts = [
            '/ui/items/new' => 'buyer', '/ui/items/new/' . $item . '/check' => 'buyer',
            '/ui/items/families' => 'viewer', '/ui/items/families/' . $fid . '/rename' => 'goods_in', '/ui/items/families/' . $fid . '/switch' => 'viewer',
            '/ui/items/families/' . $fid . '/add' => 'admin', '/ui/items/families/' . $fid . '/remove' => 'mapper', '/ui/items/' . $item . '/family' => 'viewer',
            '/ui/items/packs' => 'buyer', '/ui/items/packs/1/switch' => 'admin',
            '/ui/stock/repack' => 'buyer', '/ui/stock/repack/1/post' => 'mapping_lead', '/ui/stock/repack/1/cancel' => 'admin', '/ui/stock/repack/1/withdraw' => 'viewer',
        ];
        $who = ['/ui/items/new' => 'stock_controller', '/ui/items/families' => 'buyer', '/ui/items/packs' => 'mapping_lead', '/ui/stock/repack' => 'stock_controller',
            '/ui/items/' . $item . '/family' => 'buyer'];
        foreach ($posts as $path => $refused) {
            self::assertSame(403, $web[$refused]->post($path, ['csrf' => $this->token($web[$refused]), 'form_key' => str_repeat('a', 32), 'version' => '1'])->status,
                "{$refused} POST {$path}");
            $allowed = $who[$path] ?? (str_starts_with($path, '/ui/items/families') ? 'buyer' : (str_starts_with($path, '/ui/stock/repack') ? 'stock_controller'
                : (str_starts_with($path, '/ui/items/packs') ? 'mapping_lead' : 'stock_controller')));
            $r = $web[$allowed]->post($path, ['form_key' => str_repeat('b', 32), 'version' => '1', 'name' => 'x x', 'list' => '1']);
            self::assertSame(403, $r->status, "no token: {$allowed} POST {$path}");
            self::assertSame('csrf', $r->errorCode(), $path);
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_family'), 'nothing was made by a refused POST');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE origin = 'manual'"));
    }
}
