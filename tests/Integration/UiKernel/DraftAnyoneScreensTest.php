<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\StockOps\StockOps;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * A draft anyone allowed may change (docs/decisions.md DA1-DA5; the owner, 18 Nov 2026, seeing a draft purchase order open read-only for him:
 * "no that is not how should be. anyone who have access should be able to edit it."), through the real /ui kernel as cw_app:
 *  - another person who keeps that kind of record gets the same edit form as the person who started it (a stock record, a purchase
 *    order), saves it and makes it final; the record stays its starter's ("Started by"); its History of changes names each person;
 *  - a page drawn before someone else's save is refused (409) naming who changed it and when, not "another tab";
 *  - someone whose job does not keep that kind still only looks; admin is refused.
 */
final class DraftAnyoneScreensTest extends KernelUiTestCase
{
    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    /** Starts a record of $kind through its create form and returns its page's path. @param array<string, string> $over */
    private function start(KernelBrowser $web, string $kind, array $over = []): string
    {
        // An adjustment starts on its own page (docs/decisions.md AG1): its form is that page's, the create route stays.
        $from = StockOps::PATHS[$kind] . ($kind === 'adjust' ? '/new' : '');
        $list = $web->get($from);
        self::assertSame(200, $list->status, $list->describe());
        $r = $web->post(StockOps::PATHS[$kind], $over + $list->form($from, true));
        self::assertSame(303, $r->status, $r->describe());
        return (string) parse_url((string) $r->location(), PHP_URL_PATH);
    }

    /** A record page's form, as drawn now. @return array<string, string> */
    private static function formOf(KernelBrowser $web, string $page, string $action): array
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe());
        $form = $p->form($page . '/' . $action, true);
        self::assertNotSame([], $form, "no {$action} form: " . $p->describe());
        return $form;
    }

    private static function staffName(int $staffId): string
    {
        return (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$staffId]);
    }

    /** The history of changes as the page lists it. @return list<string> */
    private static function changes(UiResponse $page): array
    {
        $out = [];
        foreach ((new \DOMXPath($page->dom()))->query('//section[contains(@class, "doc-changes")]//li') as $li) {
            $out[] = trim((string) preg_replace('/\s+/', ' ', $li->textContent));
        }
        return $out;
    }

    /** One entry of the history says "$what, <time>" (the time as Html::clock says it). @param list<string> $changes */
    private static function assertListed(string $what, array $changes): void
    {
        foreach ($changes as $c) {
            if (preg_match('/^' . preg_quote($what, '/') . ', (\d{1,2} [A-Z][a-z]{2}, )?\d\d:\d\d$/D', $c) === 1) {
                self::assertTrue(true);
                return;
            }
        }
        self::fail("no \"{$what}, <time>\" in: " . implode(' | ', $changes));
    }

    public function testAnotherPersonChangesAStockRecordAndMakesItFinal(): void
    {
        $sku = $this->item('strict', 4, 'Shared draft pod');
        $starterUser = $this->uiUser('stock_controller');
        $otherUser = $this->uiUser('stock_controller');
        $starter = $this->signIn($starterUser);
        $page = $this->start($starter, 'in', ['reason_code' => 'found', 'external_ref' => 'shelf D']);
        $id = (int) basename($page);
        $r = $starter->post($page . '/add', ['q' => 'CW-' . $sku, 'qty' => '2', 'cost' => '1.50'] + self::formOf($starter, $page, 'add'));
        self::assertSame(303, $r->status, $r->describe());
        // The starter's page drawn now, before the other person's save.
        $stale = self::formOf($starter, $page, 'lines');

        // The other person gets the same edit form, no "only the person who started it" note, and saves.
        $other = $this->signIn($otherUser);
        $theirs = $other->get($page);
        self::assertSame(200, $theirs->status, $theirs->describe());
        self::assertTrue($theirs->hasForm($page . '/lines'), 'the edit form, as its starter has it');
        self::assertTrue($theirs->hasForm($page . '/details'));
        self::assertSame(0, (new \DOMXPath($theirs->dom()))->query('//p[contains(@class, "read-only")]')->length, 'no read-only note');
        $r = $other->post($page . '/lines', ['u_1' => '5'] + self::formOf($other, $page, 'lines'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(5, (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ? AND line_no = 1', [$id]));

        // The starter's page drawn before it: refused, naming who changed it and when; nothing of theirs saved.
        $refused = $starter->post($page . '/lines', ['u_1' => '9'] + $stale);
        self::assertSame(409, $refused->status, $refused->describe());
        $at = \CW\Ui\Html::clock((string) self::$db->value("SELECT MAX(created_at) FROM audit_log WHERE entity_type = 'document' AND entity_id = ? AND staff_user_id = ?",
            [(string) $id, $otherUser['id']]));
        self::assertStringContainsString(Words::say('DRAFT', 'conflict', self::staffName($otherUser['id']), $at), $refused->text());
        self::assertSame(5, (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ? AND line_no = 1', [$id]));
        // The same person's other tab says so, not someone else.
        $mine = self::formOf($other, $page, 'lines');
        $other->post($page . '/lines', ['u_1' => '6'] + self::formOf($other, $page, 'lines'));
        $again = $other->post($page . '/lines', ['u_1' => '7'] + $mine);
        self::assertSame(409, $again->status, $again->describe());
        self::assertStringContainsString(Words::say('DRAFT', 'conflict_you', \CW\Ui\Html::clock((string) self::$db->value(
            "SELECT MAX(created_at) FROM audit_log WHERE entity_type = 'document' AND entity_id = ?", [(string) $id]))), $again->text());

        // Its history of changes: who started it and each person who changed it.
        $changes = self::changes($starter->get($page));
        self::assertNotSame([], $changes);
        self::assertStringStartsWith('Started by ' . self::staffName($starterUser['id']) . ', ', $changes[0]);
        self::assertListed('Changed by ' . self::staffName($otherUser['id']), $changes);

        // The other person makes it final: it stays the starter's record.
        $r = $other->post($page . '/lines', ['action' => 'post'] + self::formOf($other, $page, 'lines'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['posted', $starterUser['id'], $otherUser['id']], array_values(array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v,
            self::$db->one('SELECT status, created_by, posted_by FROM document WHERE id = ?', [$id]))));
        self::assertSame(10, (int) self::$db->value("SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = (SELECT id FROM warehouse WHERE code = 'MAIN')", [$sku]));
    }

    public function testSomeoneWhoseJobDoesNotKeepTheKindOnlyLooks(): void
    {
        $starter = $this->signIn($this->uiUser('stock_controller'));
        $page = $this->start($starter, 'adjust');
        $buyer = $this->signIn($this->uiUser('buyer'));
        $look = $buyer->get($page);
        self::assertSame(200, $look->status, $look->describe());
        self::assertFalse($look->hasForm($page . '/lines'));
        self::assertFalse($look->hasForm($page . '/grid'));
        self::assertSame(403, $buyer->post($page . '/grid', ['csrf' => $this->token($buyer), 'version' => '1', 'lines' => '[]'])->status);
        $admin = $this->signIn($this->uiUser('admin'));
        self::assertSame(403, $admin->post($page . '/grid', ['csrf' => $this->token($admin), 'version' => '1', 'lines' => '[]'])->status);
        // A goods-in person keeps no adjustments either.
        $gi = $this->signIn($this->uiUser('goods_in'));
        self::assertSame(403, $gi->post($page . '/grid', ['csrf' => $this->token($gi), 'version' => '1', 'lines' => '[]'])->status);
    }

    public function testAnotherBuyerOpensTheDraftOrderInTheEditor(): void
    {
        $aUser = $this->uiUser('buyer');
        $bUser = $this->uiUser('buyer');
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($aUser['id']), ['name' => 'Shared Order Ltd', 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@sharedorder.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $aUser['id'], 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $sku = self::makeSku('Shared order pod');
        $d = $pos->createDraft(Caller::staff($aUser['id']), (int) $s['id'], []);
        $d = $pos->saveDraft(Caller::staff($aUser['id']), $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => 3, 'pack_price' => '2.50']]);
        $path = "/ui/purchasing/orders/{$d->id}";
        $a = $this->signIn($aUser);
        $stale = $a->get($path)->form('/lines');
        self::assertArrayHasKey('line_1_packs', $stale);

        // Fazil's draft opens in the editor for Shareef too (the owner's words), with no "Only Fazil can change" note.
        $b = $this->signIn($bUser);
        $ed = $b->get($path);
        self::assertSame(200, $ed->status, $ed->describe());
        $f = $ed->form('/lines');
        self::assertSame('3', $f['line_1_packs'] ?? null, 'the editor, not the read-only page');
        self::assertStringNotContainsString(sprintf('Only %s can change', self::staffName($aUser['id'])), $ed->text());
        $r = $b->post("{$path}/lines", ['line_1_packs' => '5', 'action' => 'save'] + $f);
        self::assertSame("{$path}?notice=saved#scan", $r->location(), $r->describe());
        self::assertSame([5, $aUser['id']], [(int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$d->id]),
            (int) self::$db->value('SELECT created_by FROM document WHERE id = ?', [$d->id])]);

        // The first buyer's page drawn before it: refused, naming who changed it.
        $refused = $a->post("{$path}/lines", ['line_1_packs' => '9', 'action' => 'save'] + $stale);
        self::assertSame(409, $refused->status, $refused->describe());
        self::assertStringContainsString(self::staffName($bUser['id']) . ' changed this draft at ', $refused->text());
        self::assertSame(5, (int) self::$db->value('SELECT packs FROM po_line WHERE document_id = ? AND line_no = 1', [$d->id]));
        // The order's page has ONE history since one look (docs/decisions.md LP10): the changes are in its History card.
        $events = array_map(static fn (\DOMNode $li): string => trim((string) preg_replace('/\s+/', ' ', $li->textContent)),
            iterator_to_array((new \DOMXPath($a->get($path)->dom()))->query('//section[contains(@class, "po-history")]//ol[contains(@class, "po-events")]/li')));
        self::assertNotEmpty(array_filter($events, static fn (string $ev): bool => str_ends_with($ev, Words::say('ORDER', 'ev_changed', self::staffName($bUser['id'])))),
            implode(' | ', $events));
    }
}
