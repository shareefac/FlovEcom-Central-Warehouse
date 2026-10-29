<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Movements;
use CW\Relay\RelayIntake;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\StoreQueuesController;
use CW\Ui\Words;

/**
 * Approvals › From the stores › Lines to place as a board (docs/decisions.md LQ1-LQ8; the owner's OK of 29 Oct 2026): the waiting lines in two
 * groups (goods in, blue; corrections, amber), each a coloured heading with its count and a table card with a coloured left edge, the status
 * cell filled solid; per row what came in, its status, its units (a correction's "Set to"), the item in CW (the order page's dropdown: a name or
 * a barcode, the choices with their CW code and stock, "+ Add new item" last; without the script the CW code typed), units each, the note, and
 * Place (or "Send to corrections"); closing a line is in the row's menu. A choice found by name books exactly what typing its code books; a
 * correction goes onward without booking stock; a refused answer keeps what was typed in its row; no help paragraphs.
 */
final class LinesToPlaceBoardTest extends KernelUiTestCase
{
    private const PATH = StoreQueuesController::PATH . '/suspense';

    /** @return array{goods: list<int>, correction: int, site: int} two goods-in lines and one correction waiting for an item */
    private function fill(): array
    {
        $site = self::makeChannel('vpg', 'live');
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$site, self::warehouseId('MAIN')]);
        self::$db->exec('UPDATE channel SET movement_types = ? WHERE id = ?', [json_encode(['goods_in', 'erp_reconciliation']), $site]);
        $caller = Caller::channel($site, 'vpg');
        (new Movements(self::$db))->record($caller, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-9', 'lines' => [['variant_id' => 'NEW1', 'qty' => 2, 'description' => 'Mango 10ml'],
            ['variant_id' => 'NEW2', 'qty' => 2, 'description' => 'Mango 10ml again']]], 'relay-lq');
        (new RelayIntake(self::$db))->reconciliations($caller, ['doc_ref' => 'MAT-RECO-9', 'lines' => [['variant_id' => 'NEW3', 'qty' => 20, 'description' => 'Cherry 100ml']]], 'reco-lq');
        $goods = array_map('intval', self::$db->column("SELECT id FROM goods_in_suspense WHERE movement_type = 'goods_in' ORDER BY id"));
        $correction = (int) self::$db->value("SELECT id FROM goods_in_suspense WHERE movement_type = ?", [RelayIntake::KIND_RECONCILIATION]);
        self::assertCount(2, $goods);
        self::assertGreaterThan(0, $correction);
        return ['goods' => $goods, 'correction' => $correction, 'site' => $site];
    }

    private static function xp(UiResponse $page): \DOMXPath
    {
        return new \DOMXPath($page->dom());
    }

    private static function onHand(int $sku): int
    {
        return (int) (self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = ?', [$sku, self::warehouseId('MAIN')]) ?? 0);
    }

    public function testTheWaitingLinesAreABoardOfTwoGroups(): void
    {
        $ids = $this->fill();
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($page);

        // Two groups: a coloured heading with its count, then the table card with its coloured edge.
        $groups = $xp->query('//main//section[contains(@class, "lq-group")]');
        self::assertSame(2, $groups->length);
        $g1 = $groups->item(0);
        $g2 = $groups->item(1);
        self::assertStringContainsString('info', (string) $g1?->getAttribute('class'), 'goods in: blue');
        self::assertStringContainsString('needs', (string) $g2?->getAttribute('class'), 'corrections: amber');
        self::assertSame(Words::LQ['goods_waiting'], trim((string) $xp->evaluate('string(.//*[contains(@class, "lq-gname")])', $g1)));
        self::assertSame(Words::LQ['corrections_waiting'], trim((string) $xp->evaluate('string(.//*[contains(@class, "lq-gname")])', $g2)));
        self::assertStringContainsString(Words::say('LQ', 'count_many', 2), (string) $xp->evaluate('string(.//*[contains(@class, "grp-count")])', $g1));
        self::assertStringContainsString(Words::LQ['count_one'], (string) $xp->evaluate('string(.//*[contains(@class, "grp-count")])', $g2));
        self::assertSame(1, $xp->query('.//div[contains(@class, "doc-board")]//table', $g1)->length, 'the table card: the shared board\'s, its coloured edge');
        self::assertStringContainsString('doc-group', (string) $g1?->getAttribute('class'), 'the shared board group (its heading in its colour, folding)');

        // The columns: what came in, status, units (a correction: "Set to"), item in CW, units each, note, the action.
        $heads = static fn (\DOMNode $g): array => array_map(static fn (\DOMNode $th): string => trim((string) $th->textContent),
            iterator_to_array($xp->query('.//thead//th', $g) ?: []));
        self::assertSame([Words::LQ['col_what'], Words::LQ['col_status'], Words::LQ['col_units'], Words::LQ['col_item'], Words::LQ['col_each'], Words::LQ['col_note'],
            Words::LQ['col_action']], $heads($g1));
        self::assertSame(Words::LQ['col_set_to'], $heads($g2)[2]);

        // The status cell is filled solid; the units as relayed.
        $row = $xp->query('.//tr[@id="suspense-' . $ids['goods'][0] . '"]', $g1)->item(0);
        self::assertNotNull($row);
        $st = $xp->query('.//td[contains(@class, "doc-st")]', $row)->item(0);
        self::assertSame(Words::LQ['st_waiting'], trim((string) $st?->textContent));
        self::assertStringContainsString('Mango 10ml', (string) $row->textContent);
        $c = $xp->query('.//tr[@id="suspense-' . $ids['correction'] . '"]', $g2)->item(0);
        self::assertSame(Words::LQ['st_correction'], trim((string) $xp->evaluate('string(.//td[contains(@class, "doc-st")])', $c)));
        self::assertSame('20', trim((string) $xp->evaluate('string(.//td[contains(@class, "num")][1])', $c)));

        // The row's answer: one form named by its fields (they sit in their cells), Place / Send to corrections; the item box is the
        // order page's dropdown (the route of its choices, "+ Add new item" last for someone who may add items).
        $pick = self::PATH . '/' . $ids['goods'][0] . '/pick';
        $form = $page->form($pick);
        self::assertEqualsCanonicalizing(['csrf', 'item', 'units', 'note'], array_keys($form), 'the row\'s answer: its boxes name its form');
        $box = $xp->query('.//input[@name="item"]', $row)->item(0);
        self::assertNotNull($box);
        self::assertSame(self::PATH . '/items?', $box->getAttribute('data-options'));
        self::assertSame(Words::LQ['add_new'], $box->getAttribute('data-add'), 'a stock controller adds items: "+ Add new item" last');
        self::assertSame('/ui/items/new?name=Mango%2010ml#new', $box->getAttribute('data-add-href'), 'to the new item page, the line\'s name filled in');
        self::assertSame(1, $xp->query('.//ul[@data-lq-list]', $row)->length);
        self::assertSame(Words::LQ['place'], trim((string) $xp->evaluate('string(.//button[@type="submit" and ancestor::form[contains(@action, "/pick")]])', $row)));
        self::assertSame(Words::LQ['to_corrections'], trim((string) $xp->evaluate('string(.//button[@type="submit" and ancestor::form[contains(@action, "/pick")]])', $c)));
        // Closing it is in the row's menu.
        self::assertSame(1, $xp->query('.//details[contains(@class, "lq-menu")]//form[contains(@action, "/' . $ids['goods'][0] . '/dismiss")]', $row)->length);
        // No help paragraphs.
        foreach (['Find the item under Products', 'Leave 1 unless the line counts packs', 'This books the stock now', 'This books nothing'] as $gone) {
            self::assertStringNotContainsString($gone, $page->text(), $gone);
        }
        // The new item page takes the name the link brings (nothing is made until it is sent).
        $new = $web->get('/ui/items/new', ['name' => 'Mango 10ml']);
        self::assertSame(200, $new->status, $new->describe());
        self::assertSame('Mango 10ml', $new->form('/ui/items/new')['name'] ?? null);
        // Someone who places lines but does not add items is told whom to ask, as the list's last line.
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $box = self::xp($desk->get(self::PATH))->query('//tr[@id="suspense-' . $ids['goods'][0] . '"]//input[@name="item"]')->item(0);
        self::assertSame('', (string) $box?->getAttribute('data-add'));
        self::assertSame(Words::askToAdd('items.create'), $box?->getAttribute('data-ask'));
    }

    public function testTheItemBoxFindsByNameOrBarcodeAndPickByNameBooksTheSameAsTheCode(): void
    {
        $ids = $this->fill();
        $sku = self::makeSku('Mango 10ml 20mg');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan) VALUES ('5060000004442', ?, 1, 1)", [$sku]);
        $web = $this->signIn($this->uiUser('stock_controller'));

        // The box's choices: a fragment (not a page), each with its words, its CW code and its stock.
        $r = $web->get(self::PATH . '/items', ['q' => 'mango 20']);
        self::assertSame(200, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $xp = self::xp($r);
        self::assertSame(1, $xp->query('/html/body/*[@data-lq-fragment]')->length, 'a fragment, not a page');
        $li = $xp->query('//ul[@data-lq-choices]/li')->item(0);
        self::assertNotNull($li);
        self::assertSame($code, $li->getAttribute('data-code'));
        self::assertSame('Mango 10ml 20mg · ' . $code, $li->getAttribute('data-label'));
        self::assertStringContainsString(Words::say('LQ', 'in_stock', 0), (string) $li->textContent);
        // A barcode scanned finds its item; one letter finds nothing; nothing found says so.
        self::assertSame($code, self::xp($web->get(self::PATH . '/items', ['q' => '5060000004442']))->evaluate('string(//ul[@data-lq-choices]/li/@data-code)'));
        self::assertSame(0, self::xp($web->get(self::PATH . '/items', ['q' => 'm']))->query('//ul[@data-lq-choices]/li')->length);
        self::assertStringContainsString(Words::say('LQ', 'none', 'zzqq'), $web->get(self::PATH . '/items', ['q' => 'zzqq'])->text());
        // Only those who place lines ask it.
        self::assertSame(403, $this->signIn($this->uiUser('buyer'))->get(self::PATH . '/items', ['q' => 'mango'])->status);

        // Row 1 placed with the code typed, row 2 with the choice found by name: the same booking.
        $page = $web->get(self::PATH);
        $typed = $web->post(self::PATH . '/' . $ids['goods'][0] . '/pick', ['item' => $code, 'units' => '1', 'note' => 'typed the code'] + $page->form(self::PATH . '/' . $ids['goods'][0] . '/pick'));
        self::assertSame(303, $typed->status, $typed->describe());
        self::assertSame(2, self::onHand($sku));
        $picked = $web->post(self::PATH . '/' . $ids['goods'][1] . '/pick', ['item' => (string) $li->getAttribute('data-code'), 'units' => '1', 'note' => 'picked by name']
            + $web->get(self::PATH)->form(self::PATH . '/' . $ids['goods'][1] . '/pick'));
        self::assertSame(303, $picked->status, $picked->describe());
        self::assertSame(4, self::onHand($sku));
        $rows = self::$db->all("SELECT movement_type, qty_delta, warehouse_id FROM stock_ledger WHERE sku_id = ? ORDER BY id", [$sku]);
        self::assertCount(2, $rows);
        self::assertSame($rows[0], $rows[1], 'the same movement, units and warehouse either way');
        self::assertSame(['resolved', 'resolved'], array_map('strval', self::$db->column('SELECT CAST(status AS CHAR) FROM goods_in_suspense WHERE id IN (?, ?)', $ids['goods'])));

        // A correction goes onward to the corrections, no stock booked.
        $before = self::onHand($sku);
        $r = $web->post(self::PATH . '/' . $ids['correction'] . '/pick', ['item' => $code, 'units' => '1', 'note' => 'the cherry is this one']
            + $web->get(self::PATH)->form(self::PATH . '/' . $ids['correction'] . '/pick'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($before, self::onHand($sku));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE sku_id = ? AND CAST(status AS CHAR) = 'open'", [$sku]));

        // Placed lines show what they were placed on, with no form.
        $done = self::xp($web->get(self::PATH, ['status' => 'resolved']));
        self::assertStringContainsString($code, (string) $done->evaluate('string(//tr[@id="suspense-' . $ids['goods'][1] . '"])'));
        self::assertSame(0, $done->query('//tr[@id="suspense-' . $ids['goods'][1] . '"]//input')->length);
    }

    public function testARefusedAnswerKeepsItsRowAndItsChosenItem(): void
    {
        $ids = $this->fill();
        $sku = self::makeSku('Mango 10ml 20mg');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $pick = self::PATH . '/' . $ids['goods'][0] . '/pick';
        // A known item with a note too short: refused; the row keeps the code, units and note, and the box says which item it names.
        $r = $web->post($pick, ['item' => $code, 'units' => '2', 'note' => 'x'] + $web->get(self::PATH)->form($pick));
        self::assertSame(400, $r->status, $r->describe());
        self::assertSame([$code, '2', 'x'], [$r->form($pick)['item'] ?? null, $r->form($pick)['units'] ?? null, $r->form($pick)['note'] ?? null]);
        $box = self::xp($r)->query('//tr[@id="suspense-' . $ids['goods'][0] . '"]//input[@name="item"]')->item(0);
        self::assertSame('Mango 10ml 20mg · ' . $code, $box?->getAttribute('data-picked'), 'the item chosen is drawn as picked again');
        // An unknown code: kept as typed, nothing named.
        $r = $web->post($pick, ['item' => 'CW-999999', 'units' => '1', 'note' => 'the new mango'] + $web->get(self::PATH)->form($pick));
        self::assertGreaterThanOrEqual(400, $r->status);
        $box = self::xp($r)->query('//tr[@id="suspense-' . $ids['goods'][0] . '"]//input[@name="item"]')->item(0);
        self::assertSame(['CW-999999', ''], [$box?->getAttribute('value'), (string) $box?->getAttribute('data-picked')]);
        // A refused close keeps its note with the menu open.
        $close = self::PATH . '/' . $ids['goods'][1] . '/dismiss';
        $r = $web->post($close, ['note' => 'x'] + $web->get(self::PATH)->form($close));
        self::assertSame(400, $r->status);
        self::assertSame('x', $r->form($close)['note'] ?? null);
        self::assertSame(1, self::xp($r)->query('//details[contains(@class, "lq-menu") and @open]//form[contains(@action, "' . $close . '")]')->length);
    }
}
