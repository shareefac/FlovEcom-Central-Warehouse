<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Html;
use CW\Ui\Words;

/**
 * The item page, stock first (docs/decisions.md IV1-IV12) and one page in sections since the redesign of 30 Oct 2026 (artboard Item, RD-A4),
 * through the real kernel: the head (the name, its status label, the fact line, More and the ONE main action the person may use: Order more,
 * else Count it, else Move stock), the stock card (on the shelf, held for orders, free to sell, coming in with what it sells a week and its
 * first order), the recent stock history and the way to all of it, the Store listings card, and the folds that hold what the tabs of before
 * held (each warehouse, selling on the stores, the item group and packs, the card's history), which open where the work is.
 */
final class ItemPageScreensTest extends KernelUiTestCase
{
    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed> an active supplier (made by a buyer, approved by a reviewer) */
    private function supplier(string $name): array
    {
        $buyer = $this->uiUser('buyer')['id'];
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyer), ['name' => $name, 'address_line1' => '2 Mill Lane', 'city' => 'Leeds', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyer, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyer), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    /** A box of $upp from $supplierId, the main supply unless told otherwise. @return array<string, mixed> */
    private function supply(int $supplierId, int $sku, int $upp, string $code, bool $main = true): array
    {
        $si = (new SupplierItems(self::$db))->create(Caller::staff($this->uiUser('buyer')['id']), $supplierId, $sku,
            ['units_per_pack' => (string) $upp, 'purchase_unit' => 'box', 'supplier_code' => $code], ['pack_price' => '9.00']);
        self::$db->exec('UPDATE supplier_item SET is_preferred = ? WHERE id = ?', [$main ? 1 : 0, (int) $si['id']]);
        return $si;
    }

    /** A confirmed order of $packs boxes of $upp of the item, due on $due (or with no date). @return array{id: int, number: string} */
    private function order(int $supplierId, int $sku, int $packs, int $upp, ?string $due): array
    {
        $buyer = Caller::staff($this->uiUser('buyer')['id']);
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft($buyer, $supplierId, []);
        $d = $pos->saveDraft($buyer, $d->id, $d->version, $due === null ? [] : ['expected_date' => $due],
            [['sku_id' => $sku, 'packs' => $packs, 'units_per_pack' => $upp, 'pack_price' => '2.00']]);
        $d = $pos->approve($buyer, $d->id, $d->version);
        return ['id' => $d->id, 'number' => (string) $d->number];
    }

    /** The reorder engine's sales rate of an item (central units a day), as its nightly job stores it. */
    private static function rate(int $sku, string $perDay): void
    {
        self::$db->exec('INSERT INTO reorder_demand (sku_id, computed_at, rate, rate_raw_30, valid_days_short, valid_days_long, excluded_anomaly, excluded_promo, '
            . "excluded_oos, excluded_other, capped_days, units_365, monthly, detail) VALUES (?, NOW(6), ?, ?, 30, 365, 0, 0, 0, 0, 0, 100, '{}', '[]')",
            [$sku, $perDay, $perDay]);
    }

    /** GETs a link as the page draws it (its path and query). */
    private static function open(KernelBrowser $web, string $href): UiResponse
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
        return $web->get((string) parse_url($href, PHP_URL_PATH), array_map('strval', $q));
    }

    private static function xp(UiResponse $r): \DOMXPath
    {
        return new \DOMXPath($r->dom());
    }

    private static function squash(?string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
    }

    /** The head's status label and fact line. @return array{0: string, 1: string, 2: string} [status words, its tone, the fact line] */
    private static function head(UiResponse $r): array
    {
        $xp = self::xp($r);
        $tag = $xp->query('//div[contains(@class, "item-head")]//div[@class="ph-title"]/span[contains(concat(" ", @class, " "), " tag ")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $tag, 'the status label: ' . $r->describe());
        $tone = array_values(array_diff(explode(' ', $tag->getAttribute('class')), ['tag']))[0] ?? '';
        return [self::squash($tag->textContent), $tone, self::squash($xp->evaluate('string(//div[contains(@class, "item-head")]//span[@class="item-id"])'))];
    }

    /** The head's actions as their words, in order (More, then the main one marked "*"). @return list<string> */
    private static function actions(UiResponse $r): array
    {
        $xp = self::xp($r);
        $out = [];
        foreach ($xp->query('//div[contains(@class, "item-bar")]/*') ?: [] as $el) {
            /** @var \DOMElement $el */
            $btn = $el->nodeName === 'form' ? $xp->query('.//button', $el)->item(0) : ($el->nodeName === 'details' ? $xp->query('summary', $el)->item(0) : $el);
            self::assertInstanceOf(\DOMElement::class, $btn);
            $out[] = self::squash($btn->textContent) . (str_contains(' ' . $btn->getAttribute('class') . ' ', ' primary ') ? '*' : '');
        }
        return $out;
    }

    /** The More menu's links: words => link. @return array<string, string> */
    private static function more(UiResponse $r): array
    {
        $out = [];
        foreach (self::xp($r)->query('//div[contains(@class, "item-bar")]/details[contains(@class, "more")]//a[contains(@class, "menu-item")]') ?: [] as $a) {
            /** @var \DOMElement $a */
            $out[self::squash($a->textContent)] = $a->getAttribute('href');
        }
        return $out;
    }

    /** The stock card: label => [the figure, its lines under it joined by " · "]. @return array<string, array{0: string, 1: string}> */
    private static function strip(UiResponse $r): array
    {
        $xp = self::xp($r);
        $out = [];
        foreach ($xp->query('//section[contains(@class, "item-strip")]/div[contains(@class, "doc-step")]') ?: [] as $step) {
            $subs = array_map(static fn (\DOMNode $p): string => self::squash($p->textContent), iterator_to_array($xp->query('p[contains(@class, "step-sub")]', $step) ?: []));
            $out[self::squash($xp->evaluate('string(p[contains(@class, "doc-step-label")])', $step))] = [
                self::squash($xp->evaluate('string(p[contains(@class, "doc-step-big")])', $step)), implode(' · ', $subs)];
        }
        return $out;
    }

    /** The Store listings card: store => [its state's words, its tone] ("Match…" for a store to match). @return array<string, array{0: string, 1: string}> */
    private static function sells(UiResponse $r): array
    {
        $xp = self::xp($r);
        $out = [];
        foreach ($xp->query('//section[contains(@class, "item-stores")]/div[contains(@class, "side-row")][p[@class="side-line"]]') ?: [] as $row) {
            $tag = $xp->query('p[@class="side-line"]/span[contains(concat(" ", @class, " "), " tag ")]', $row)->item(0);
            $out[self::squash($xp->evaluate('string(p[@class="side-line"]/span[@class="side-name"])', $row))] = $tag instanceof \DOMElement
                ? [self::squash($tag->textContent), array_values(array_diff(explode(' ', $tag->getAttribute('class')), ['tag']))[0] ?? '']
                : [self::squash($xp->evaluate('string(p[@class="side-line"]/a)', $row)), 'match'];
        }
        return $out;
    }

    /** The folds open on the page (what the tabs of before held: RD-A4). @return list<string> their ids */
    private static function openFolds(UiResponse $r): array
    {
        return array_map(static fn (\DOMElement $d): string => $d->getAttribute('id'),
            array_values(iterator_to_array(self::xp($r)->query('//div[contains(@class, "item-main")]/details[contains(@class, "it-fold") and @open]') ?: [])));
    }

    // ------------------------------------------------------------------------------------------
    // The head and its actions
    // ------------------------------------------------------------------------------------------

    public function testTheHeadSaysWhoItIsAndOffersOnlyWhatThePersonMayUse(): void
    {
        $vpg = $this->site('vapeandgo');
        $sku = $this->item('strict', 160, 'Pod Salt Bar X 10ml Lemon Lime 10mg');
        self::$db->exec("UPDATE sku SET brand = 'Pod Salt', nic_type = 'salt' WHERE id = ?", [$sku]);
        $this->listing($vpg, 'V1', $sku);
        $s = $this->supplier('Prime Vape Supply');
        $si = $this->supply((int) $s['id'], $sku, 10, 'PS-10');
        $code = sprintf('CW-%06d', $sku);

        // Someone who only looks: who it is, no action.
        $viewer = $this->signIn($this->uiUser('viewer'))->get("/ui/items/{$sku}");
        self::assertSame(200, $viewer->status, $viewer->describe());
        self::assertSame('Pod Salt Bar X 10ml Lemon Lime 10mg', self::squash(self::xp($viewer)->evaluate('string(//main//h1/span[contains(@class, "stmt")])')));
        self::assertSame([Words::ITEM['st_selling'], 'done', $code . ' · Pod Salt · nic salt'], self::head($viewer), 'no supplier for someone who may not see them');
        self::assertSame([], self::actions($viewer));
        self::assertFalse($viewer->hasForm('/ui/stock/counts'));

        // The buyer: Order more is the main action; the main supplier's box is named.
        $buyer = $this->signIn($this->uiUser('buyer'));
        $page = $buyer->get("/ui/items/{$sku}");
        self::assertSame([Words::ITEM['st_selling'], 'done', $code . ' · Pod Salt · nic salt'], self::head($page), 'the box is the Details\' BUYING (RD-A4)');
        self::assertSame([Words::ITEM_PAGE['more'], Words::ITEM['order_more'] . '*'], self::actions($page));
        self::assertSame(['Prime Vape Supply', \CW\Ui\Controller\PurchaseOrdersController::pack('box', 10)],
            array_slice(array_map(static fn (\DOMNode $d): string => self::squash($d->textContent), iterator_to_array(self::xp($page)->query('//div[contains(@class, "facts-buying")]//dd') ?: [])), 0, 2));
        self::assertSame([Words::ITEM['more_reorder'] => "/ui/purchasing/reorder/items/{$sku}"], self::more($page));
        $order = (string) self::xp($page)->evaluate('string(//div[contains(@class, "item-bar")]/a[contains(@class, "primary")]/@href)');
        parse_str((string) parse_url($order, PHP_URL_QUERY), $q);
        self::assertSame('/ui/purchasing/orders/new', parse_url($order, PHP_URL_PATH));
        self::assertSame(['supplier' => (string) $s['id'], 'pick_si' => (string) $si['id']], $q, 'the main supplier and its box, and nothing else (IV14)');
        $new = self::open($buyer, $order);
        self::assertSame(200, $new->status, $new->describe());
        $f = $new->form('/ui/purchasing/orders');
        self::assertSame([(string) $s['id'], (string) $si['id'], 'Pod Salt Bar X 10ml Lemon Lime 10mg · ' . $code . ' · box of 10'],
            [$f['supplier_id'] ?? null, $f['pick_si'] ?? null, $f['q'] ?? null],
            'the new order\'s page has the supplier and the box chosen: nothing is written until Add');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'PO'"));
        $r = $buyer->post('/ui/purchasing/orders', $f + ['action' => 'add']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([[$sku, (int) $si['id'], 10]], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['supplier_item_id'], (int) $l['units_per_pack']],
            self::$db->all('SELECT dl.sku_id, pl.supplier_item_id, pl.units_per_pack FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no')));

        // The stock controller: Count it is the main action (no order); Move stock and the other stock records under More.
        $stock = $this->signIn($this->uiUser('stock_controller'));
        $page = $stock->get("/ui/items/{$sku}");
        self::assertSame([Words::ITEM_PAGE['more'], Words::COUNTS['item_count_it'] . '*'], self::actions($page));
        self::assertSame([Words::ITEM['move_stock'] => "/ui/stock/transfers?item={$sku}#new", Words::ITEM['more_in'] => "/ui/stock/in?item={$sku}#new",
            Words::ITEM['more_out'] => "/ui/stock/out?item={$sku}#new", Words::ITEM['more_adjust'] => "/ui/stock/adjustments?item={$sku}#new"], self::more($page));
        // Count it opens a count sheet of this item through the count route (the board's own form: FormOnce, CSRF).
        $f = $page->form('/ui/stock/counts');
        self::assertSame(['csrf', 'form_key', 'warehouse', 'mode', 'pick_' . $sku], array_keys($f));
        self::assertSame([(string) self::warehouseId('MAIN'), 'ticked', '1'], [$f['warehouse'], $f['mode'], $f['pick_' . $sku]]);
        $r = $stock->post('/ui/stock/counts', $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($r->location(), $stock->post('/ui/stock/counts', $f)->location(), 'one sheet however often it is pressed');
        self::assertSame(1, preg_match('#^/ui/stock/counts/(\d+)\?notice=opened$#', (string) $r->location(), $m), (string) $r->location());
        self::assertSame([$sku], array_map('intval', self::$db->column('SELECT sku_id FROM count_sheet_line WHERE document_id = ?', [(int) $m[1]])));
        unset($f['csrf']);
        self::assertSame(403, $stock->post('/ui/stock/counts', $f)->status, 'no token: refused');
        // Move stock: the transfer's new form with this item; the draft's add box holds it.
        $move = (string) self::xp($page)->evaluate('string(//div[contains(@class, "item-bar")]//a[normalize-space() = "' . Words::ITEM['move_stock'] . '"]/@href)');
        self::assertSame("/ui/stock/transfers?item={$sku}#new", $move);
        $list = $stock->get('/ui/stock/transfers', ['item' => (string) $sku]);
        self::assertSame(200, $list->status, $list->describe());
        self::assertSame(1, self::xp($list)->query('//details[@id="new" and @open]')->length, 'the new form is open');
        self::assertStringContainsString(Words::say('STOCK_OPS', 'for_item', $code, 'Pod Salt Bar X 10ml Lemon Lime 10mg'), $list->text());
        $f = $list->form('/ui/stock/transfers', true);
        self::assertSame((string) $sku, $f['item'] ?? null);
        $r = $stock->post('/ui/stock/transfers', ['to_warehouse' => (string) self::warehouseId('VERIFY')] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, preg_match('#^/ui/stock/transfers/(\d+)\?notice=created&add=' . $code . '$#', (string) $r->location()), (string) $r->location());
        $draft = $stock->follow($r);
        self::assertSame($code, $draft->form('/add')['q'] ?? null, 'the add box holds the item');
        // An item that is not there, or joined into another, is not carried.
        self::assertSame([], array_filter([$stock->get('/ui/stock/transfers', ['item' => '999999'])->form('/ui/stock/transfers', true)['item'] ?? '']));
        $other = self::makeSku('Joined away');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$sku, $other]);
        self::assertSame([], array_filter([$stock->get('/ui/stock/transfers', ['item' => (string) $other])->form('/ui/stock/transfers', true)['item'] ?? '']));
        $draftPath = (string) parse_url((string) $r->location(), PHP_URL_PATH);
        self::assertSame('', $stock->get($draftPath, ['add' => 'CW-1 OR 1'])->form('/add')['q'] ?? null, 'only a CW number is put in the box');

        // A joined item's page offers no stock action.
        self::assertSame([], self::actions($stock->get("/ui/items/{$other}")));
        self::assertSame([Words::ITEM['st_merged'], 'off'], array_slice(self::head($stock->get("/ui/items/{$other}")), 0, 2));
    }

    public function testTheStatusBlockSaysWhetherItSells(): void
    {
        $vpg = $this->site('vapeandgo');
        $web = $this->signIn($this->uiUser('viewer'));
        $none = $this->item('strict', 0, 'Not on a store');
        self::assertSame([Words::ITEM['st_not_selling'], 'waiting'], array_slice(self::head($web->get("/ui/items/{$none}")), 0, 2));
        $stopped = $this->item('stopped', 0, 'Stopped');
        $this->listing($vpg, 'S1', $stopped);
        self::assertSame([Words::POLICY['stopped'], 'blocked'], array_slice(self::head($web->get("/ui/items/{$stopped}")), 0, 2));
        $gone = $this->item('strict', 0, 'Gone');
        $this->listing($vpg, 'G1', $gone);
        (new \CW\Catalogue\ItemCards(self::$db))->save(Caller::staff($this->uiUser('stock_controller')['id']), $gone, 0, ['discontinued' => '1']);
        self::assertSame([Words::CARD_FIELD['discontinued'], 'off'], array_slice(self::head($web->get("/ui/items/{$gone}")), 0, 2));
    }

    // ------------------------------------------------------------------------------------------
    // The stock strip
    // ------------------------------------------------------------------------------------------

    public function testTheStripSaysWhatIsOnTheShelfHeldFreeComingAndHowLongItLasts(): void
    {
        $vpg = $this->site('vapeandgo');
        $sku = $this->item('strict', 150, 'Strip liquid');
        $this->book('count', $sku, 160, 'MAIN', '2026-09-26T13:00:00Z');
        $code = sprintf('CW-%06d', $sku);
        $this->listing($vpg, 'V1', $sku);
        // 2 sold and waiting to ship, 1 reserved in a checkout (the engine's own figures).
        $this->ok($this->reserve($vpg, 'O1', [self::line('V1', 'a', 'b')]));
        $this->ok($this->commit($vpg, 'O1', [self::line('V1', 'a', 'b')]));
        $this->ok($this->reserve($vpg, 'O2', [self::line('V1', 'c')]));
        $s = $this->supplier('Strip Supplies');
        $later = $this->order((int) $s['id'], $sku, 5, 10, '2026-11-20');
        $first = $this->order((int) $s['id'], $sku, 3, 10, '2026-11-02');
        $this->order((int) $s['id'], $sku, 1, 10, null);
        self::rate($sku, '3.1429'); // 22 a week

        $buyer = $this->signIn($this->uiUser('buyer'));
        $page = $buyer->get("/ui/items/{$sku}");
        $strip = self::strip($page);
        self::assertSame([Words::ITEM['on_shelf'], Words::ITEM['held_orders'], Words::ITEM['free'], Words::ITEM['coming']], array_keys($strip), 'the artboard\'s four (RD-A4)');
        self::assertSame(['160', ''], $strip[Words::ITEM['on_shelf']]);
        self::assertSame(['3', ''], $strip[Words::ITEM['held_orders']], '2 sold and waiting to ship, 1 in a checkout');
        self::assertSame(['157', ''], $strip[Words::ITEM['free']], 'available: what the websites may sell');
        self::assertSame(['90', Words::say('ITEM_PAGE', 'sells_week', '22') . ' · ' . $first['number'] . ' · ' . Words::say('ITEM', 'coming_due', Html::day('2026-11-02'))],
            $strip[Words::ITEM['coming']], 'every open order\'s units; what it sells a week; the first one due');
        $links = array_values(array_filter($page->hrefs(), static fn (string $h): bool => str_starts_with($h, '/ui/stock/reservations')
            || preg_match('#^/ui/purchasing/orders/[0-9]+$#D', $h) === 1));
        self::assertSame(['/ui/stock/reservations?q=' . $code, '/ui/purchasing/orders/' . $first['id']], $links,
            'the held figure leads to the orders that hold them, the first order to its page');
        self::assertContains(Html::when('2026-09-26 13:00:00.000000'), array_map(static fn (\DOMNode $d): string => self::squash($d->textContent),
            iterator_to_array(self::xp($page)->query('//div[contains(@class, "facts-product")]//dd') ?: [])), 'when it was last counted: a fact of PRODUCT');
        self::assertNotContains('/ui/purchasing/orders/' . $later['id'], $page->hrefs());
        // Someone who may not open an order sees its number, not a link.
        $viewer = $this->signIn($this->uiUser('viewer'))->get("/ui/items/{$sku}");
        self::assertSame($strip[Words::ITEM['coming']], self::strip($viewer)[Words::ITEM['coming']]);
        self::assertSame([], array_values(array_filter($viewer->hrefs(), static fn (string $h): bool => str_starts_with($h, '/ui/purchasing/'))), 'no order link, no Order more');
        // The per-warehouse figures are still there, under the strip.
        self::assertSame(1, self::xp($page)->query('//details[contains(@class, "item-wh")]//table[contains(@class, "stock")]')->length);

        // No sales rate known: no "Sells a week"; none sold: 0 and no "lasts"; little sold: under 1; a long time; nothing free.
        $quiet = $this->item('strict', 5, 'Quiet coil');
        self::assertSame([Words::ITEM['on_shelf'], Words::ITEM['held_orders'], Words::ITEM['free'], Words::ITEM['coming']],
            array_keys(self::strip($buyer->get("/ui/items/{$quiet}"))));
        self::assertSame(['0', ''], self::strip($buyer->get("/ui/items/{$quiet}"))[Words::ITEM['coming']], 'no sales rate known: no "sells a week"');
        foreach ([['0.0000', '0'], ['0.0100', Words::ITEM['weekly_less']], ['0.5000', '4'], ['2.0000', '14']] as [$rate, $want]) {
            self::$db->exec('DELETE FROM reorder_demand WHERE sku_id = ?', [$quiet]);
            self::rate($quiet, $rate);
            self::assertSame(['0', Words::say('ITEM_PAGE', 'sells_week', $want)], self::strip($buyer->get("/ui/items/{$quiet}"))[Words::ITEM['coming']], $rate);
        }
        $empty = $this->item('strict', 0, 'Empty coil');
        self::rate($empty, '1.0000');
        self::assertSame(['0', Words::say('ITEM_PAGE', 'sells_week', '7')], self::strip($buyer->get("/ui/items/{$empty}"))[Words::ITEM['coming']]);
        self::assertSame(['0', ''], self::strip($buyer->get("/ui/items/{$empty}"))[Words::ITEM['on_shelf']]);
    }

    // ------------------------------------------------------------------------------------------
    // Recent movements
    // ------------------------------------------------------------------------------------------

    public function testTheRecentStockHistorySaysWhatChangedAndLeadsToAllOfIt(): void
    {
        $vpg = $this->site('vapeandgo');
        $sku = $this->item('strict', 20, 'Moving liquid');
        $code = sprintf('CW-%06d', $sku);
        $this->listing($vpg, 'V1', $sku);
        $this->book('count', $sku, 16, 'MAIN', '2026-09-26T13:00:00Z');
        $this->ok($this->reserve($vpg, 'O1', [self::line('V1', 'a')]));
        $this->ok($this->commit($vpg, 'O1', [self::line('V1', 'a')]));
        $other = $this->item('strict', 3, 'Not this one');
        $web = $this->signIn($this->uiUser('viewer'));
        $page = $web->get("/ui/items/{$sku}");
        $xp = self::xp($page);
        $rows = [];
        foreach ($xp->query('//section[contains(@class, "item-moves")]//table/tbody/tr') ?: [] as $tr) {
            $rows[] = [self::squash($xp->evaluate('string(td/span[@class="m-what"])', $tr)), self::squash($xp->evaluate('string(td[@data-label="' . Words::ITEM['m_units'] . '"])', $tr))];
        }
        self::assertSame([
            [Words::MOVE_KIND['commit'], '+1'],
            [Words::MOVE_KIND['commit'], '−1'],
            [Words::MOVE_KIND['reserve'], '+1'],
            [Words::MOVE_KIND['count'], '−4'],
            [Words::MOVE_KIND['goods_in'], '+20'],
        ], $rows, 'newest first: when, what, the units (the artboard Item, RD-A4)');
        self::assertSame(3, $xp->query('//section[contains(@class, "item-moves")]//table/tbody/tr[1]/td')->length, 'three columns');
        $all = (string) $xp->evaluate('string(//section[contains(@class, "item-moves")]//a[normalize-space() = "' . Words::ITEM_PAGE['all_history'] . '"]/@href)');
        self::assertSame('/ui/stock/movements?q=' . $code . '&figure=all', $all);
        $moves = self::open($web, $all);
        self::assertSame(200, $moves->status, $moves->describe());
        self::assertStringContainsString('Moving liquid', $moves->text());
        self::assertStringNotContainsString('Not this one', $moves->text(), 'only this item');
        self::assertStringContainsString(Words::ITEM_PAGE['no_history'], $web->get('/ui/items/' . self::makeSku('Never moved'))->text());
        unset($other);
    }

    // ------------------------------------------------------------------------------------------
    // Where it sells
    // ------------------------------------------------------------------------------------------

    public function testTheStoreListingsCardSaysEachStoresStateAndItsMatch(): void
    {
        $vpg = $this->site('vapeandgo');
        $big = $this->site('vapebig');
        $ef = $this->site('electrofag');
        $alt = $this->site('zeta');
        $sku = $this->item('strict', 5, 'Selling pod');
        $onSale = $this->profiled($vpg, 'V1', [], $sku);
        self::siteState($onSale);
        $draft = $this->profiled($big, 'B1', [], $sku);
        self::siteState($draft, ['site_variant_status' => 'Draft']);
        $this->queued($ef, 'E1', 'Check', $sku);
        $held = $this->profiled($alt, 'Z1', [], $sku);
        self::$db->exec("UPDATE channel_listing SET status = 'quarantined' WHERE id = ?", [$held]);
        $web = $this->signIn($this->uiUser('viewer'));
        $name = static fn (Caller $c): string => (string) self::$db->value('SELECT name FROM channel WHERE id = ?', [$c->channelId]);
        $state = static fn (string $s): string => ucfirst(Words::of('ITEM_SELLS', $s));
        $page = $web->get("/ui/items/{$sku}");
        self::assertSame([$name($vpg) => [$state('on_sale'), 'done'], $name($big) => [$state('matched'), 'needs'], $name($ef) => [$state('suggested'), 'needs'],
            $name($alt) => [$state('on_hold'), 'blocked']], self::sells($page), 'the owner\'s order of the stores, then the others');
        self::assertSame(Words::say('ITEM', 'sells_chip', $name($big), Words::say('ITEM_SELLS', 'not_on_sale', 'Draft')),
            (string) self::xp($page)->evaluate('string(//section[contains(@class, "item-stores")]/div[contains(@class, "side-row")][2]/@title)'), 'why it is not on sale, in full');
        self::assertSame(['/ui/review/listing/' . $onSale], array_slice(array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array(self::xp($page)->query('//section[contains(@class, "item-stores")]//a[normalize-space() = "' . Words::ITEM_PAGE['listing_change'] . '"]') ?: [])), 0, 1),
            'the store product matched there, its Change');
        // Matched with nothing known of its state; and a store with nothing for it (Match… for who may decide).
        $plain = $this->item('strict', 0, 'Plain pod');
        $this->listing($vpg, 'V2', $plain);
        self::assertSame([$name($vpg) => [$state('matched'), 'done'], $name($big) => [$state('not_linked'), 'waiting'], $name($ef) => [$state('not_linked'), 'waiting'],
            $name($alt) => [$state('not_linked'), 'waiting']], self::sells($web->get("/ui/items/{$plain}")));
        $mapper = $this->signIn($this->uiUser('mapper'))->get("/ui/items/{$plain}");
        self::assertSame([$name($big), Words::ITEM_PAGE['match'], 'match'], [array_keys(self::sells($mapper))[1], ...self::sells($mapper)[$name($big)]]);
        self::assertSame('/ui/review/products/' . $plain . '/map?channel=vapebig', (string) self::xp($mapper)->evaluate('string(//section[contains(@class, "item-stores")]//a[normalize-space() = "'
            . Words::ITEM_PAGE['match'] . '"]/@href)'), 'the store\'s picker');
    }

    // ------------------------------------------------------------------------------------------
    // The sections and the folds (no tab row: RD-A4)
    // ------------------------------------------------------------------------------------------

    public function testTheSectionsHoldWhatTheTabsHeldAndAFoldOpensWhereTheWorkIs(): void
    {
        $vpg = $this->site('vapeandgo');
        $sku = $this->item('legacy', 4, 'Tabbed liquid');
        $this->listing($vpg, 'V1', $sku);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('4006381333931', ?), ('5012345000011', ?)", [$sku, $sku]);
        $s = $this->supplier('Tab Supplies');
        $this->supply((int) $s['id'], $sku, 10, 'TB-10');
        $lead = $this->signIn($this->uiUser(['mapping_lead', 'stock_controller']));
        $page = $lead->get("/ui/items/{$sku}");
        $xp = self::xp($page);
        self::assertSame(0, $xp->query('//main//nav[contains(@class, "tabs")]')->length, 'no tab row');
        self::assertSame([], self::openFolds($page), 'every fold closed at first');
        // Each part where the artboard puts it, with its forms as before.
        self::assertSame(1, $xp->query('//div[contains(@class, "item-main")]/section[@id="card"]//a[@href="/ui/items/' . $sku . '/card"]')->length, 'Details and its Change');
        self::assertSame(1, $xp->query('//aside[contains(@class, "item-side")]/section[@id="barcodes"]//form[contains(@action, "/barcodes")]//input[@name="barcode" and @type="text"]')->length);
        self::assertSame(2, $xp->query('//section[@id="barcodes"]//li[contains(@class, "side-row")]')->length, 'the two barcodes');
        self::assertSame(1, $xp->query('//aside[contains(@class, "item-side")]/section[contains(@class, "item-suppliers")]')->length);
        self::assertSame(1, $xp->query('//details[@id="it-groups"]//section[@id="groups"]')->length);
        self::assertSame(1, $xp->query('//details[@id="it-selling"]//table[contains(@class, "item-listings")]')->length);
        self::assertSame(1, $xp->query('//details[@id="it-selling"]//section[@id="selling-mode"]')->length);
        self::assertSame(1, $xp->query('//details[@id="it-history"]//*[contains(@class, "card-history")]')->length);
        self::assertSame(1, $xp->query('//details[@id="it-wh"]')->length);

        // An old link's tab opens its fold; Details and Barcodes are always shown; a nonsense one opens none.
        foreach (['barcodes' => [], 'packs' => ['it-groups'], 'stores' => ['it-selling'], 'history' => ['it-history'], 'details' => [], 'x<b>' => []] as $k => $want) {
            self::assertSame($want, self::openFolds($lead->get("/ui/items/{$sku}", ['tab' => $k])), $k);
        }
        self::assertSame(['it-wh'], self::openFolds($lead->get("/ui/items/{$sku}", ['part' => 'warehouses'])));
        // After a change, the barcode is in its card; after a refusal its form is open with what was typed.
        $f = $page->form("/ui/items/{$sku}/barcodes");
        $r = $lead->post("/ui/items/{$sku}/barcodes", ['barcode' => '96385074', 'units' => '1'] + $f);
        self::assertSame("/ui/items/{$sku}?notice=barcode_added", $r->location(), $r->describe());
        self::assertSame(3, self::xp($lead->follow($r))->query('//section[@id="barcodes"]//li[contains(@class, "side-row")]')->length);
        $f = $lead->get("/ui/items/{$sku}")->form("/ui/items/{$sku}/barcodes");
        $bad = $lead->post("/ui/items/{$sku}/barcodes", ['barcode' => '123', 'units' => '1'] + $f);
        self::assertSame(422, $bad->status, $bad->describe());
        self::assertSame(1, self::xp($bad)->query('//section[@id="barcodes"]/details[contains(@class, "card-add") and @open]')->length, 'the refusal opens the add form');
        self::assertSame(1, self::xp($bad)->query('//p[@class="error"]')->length);
        self::assertSame('123', (string) self::xp($bad)->evaluate('string(//form[@action="/ui/items/' . $sku . '/barcodes"]//input[@name="barcode"]/@value)'), 'what was typed is kept');
        self::assertSame(['it-selling'], self::openFolds($lead->get("/ui/items/{$sku}", ['notice' => 'selling_mode_set'])));
        self::assertSame(['it-groups'], self::openFolds($lead->get("/ui/items/{$sku}", ['notice' => 'family_set'])));
        self::assertSame([], self::openFolds($lead->get("/ui/items/{$sku}", ['notice' => 'card_saved'])));

        // Someone who only looks: the same sections, no form to change anything, no suppliers.
        $viewer = $this->signIn($this->uiUser('viewer'))->get("/ui/items/{$sku}");
        self::assertSame(0, self::xp($viewer)->query('//main//form[@method="post"]')->length);
        self::assertNotContains("/ui/items/{$sku}/card", $viewer->hrefs());
        self::assertSame(0, self::xp($viewer)->query('//section[contains(@class, "item-suppliers")] | //div[contains(@class, "facts-buying")]')->length,
            'no supplier for someone who may not see them');
    }

    public function testTheLookOnlyAndTheEmptyHistory(): void
    {
        $sku = $this->item('strict', 0, 'Quiet item');
        $web = $this->signIn($this->uiUser('viewer'));
        $page = $web->get("/ui/items/{$sku}", ['tab' => 'history']);
        self::assertSame(['it-history'], self::openFolds($page));
        self::assertStringContainsString(Words::ITEM['history_none'], self::squash((string) self::xp($page)->evaluate('string(//details[@id="it-history"])')));
        self::assertSame([], self::sells($page), 'no store yet');
        self::assertStringContainsString(Words::ITEM['sells_none'], $page->text());
    }
    // ------------------------------------------------------------------------------------------
    // The Codex review of e15fe51 (docs/decisions.md IV13-IV16)
    // ------------------------------------------------------------------------------------------

    /**
     * IV13: a value typed in a tab's form (a barcode, items per scan, a reason, the selling mode) is not lost by a click on another tab: every
     * form that keeps typed values carries main's guard (data-unsaved, CV14: a link that leaves asks first), and every form of the page that
     * leaves it asks first when another one holds typed values (data-leaves).
     */
    public function testATabLinkAsksFirstWhenAFormHoldsTypedValues(): void
    {
        $vpg = $this->site('vapeandgo');
        $sku = $this->item('legacy', 4, 'Guarded liquid');
        $this->listing($vpg, 'V1', $sku);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('4006381333931', ?)", [$sku]);
        $page = $this->signIn($this->uiUser(['mapping_lead', 'stock_controller']))->get("/ui/items/{$sku}");
        $xp = self::xp($page);
        $editable = [];
        foreach ($xp->query('//main//form[@method="post"]') ?: [] as $form) {
            /** @var \DOMElement $form */
            $action = (string) parse_url($form->getAttribute('action'), PHP_URL_PATH);
            self::assertSame('1', $form->getAttribute('data-leaves'), "{$action}: leaving with typed values elsewhere asks first");
            $typed = $xp->query('.//input[not(@type="hidden")] | .//select | .//textarea', $form)->length > 0;
            if ($typed) {
                $editable[] = (string) preg_replace('#^/ui/items/[0-9]+#', '', $action);
                self::assertSame('1', $form->getAttribute('data-unsaved'), "{$action}: what is typed here is guarded");
                self::assertSame(Words::OP_FINAL['unsaved'], $form->getAttribute('data-unsaved-text'), "{$action}: in the page's words");
            } else {
                self::assertFalse($form->hasAttribute('data-unsaved'), "{$action}: nothing to type");
            }
        }
        sort($editable);
        self::assertSame(['/barcodes', '/barcodes/remove', '/barcodes/units', '/family', '/selling-mode'], $editable);
        self::assertSame(0, $xp->query('//main//nav[contains(@class, "tabs")]')->length, 'no tab link to leave by (RD-A4): the folds are on the page');
    }

    /**
     * IV14 (the Codex review, MEDIUM 2): "Order more" keeps its box for an item whose name is as long as a name may be: the new order's page
     * names the box from the box itself, so the address's words (cut at 255 characters) no longer decide.
     */
    public function testOrderMoreKeepsTheBoxOfAnItemWithALongName(): void
    {
        $name = 'Long ' . str_repeat('flavour ', 30);
        $name = mb_substr($name . str_repeat('x', 255), 0, 255);
        $sku = $this->item('strict', 0, $name);
        $s = $this->supplier('Long Supplies');
        $si = $this->supply((int) $s['id'], $sku, 12, 'LS-12');
        $buyer = $this->signIn($this->uiUser('buyer'));
        $order = (string) self::xp($buyer->get("/ui/items/{$sku}"))->evaluate('string(//div[contains(@class, "item-bar")]/a[contains(@class, "primary")]/@href)');
        self::assertGreaterThan(255, mb_strlen(\CW\Ui\Controller\PurchaseOrdersController::productChoice(['sku_id' => $sku, 'sku_code' => sprintf('CW-%06d', $sku),
            'name' => $name, 'brand' => null, 'supplier_item_id' => (int) $si['id'], 'units_per_pack' => 12, 'supplier_code' => 'LS-12', 'purchase_unit' => 'box'])['label']),
            'the box\'s words are longer than the address keeps');
        $new = self::open($buyer, $order);
        $f = $new->form('/ui/purchasing/orders');
        self::assertSame([(string) $s['id'], (string) $si['id']], [$f['supplier_id'] ?? null, $f['pick_si'] ?? null], 'the box is chosen');
        $r = $buyer->post('/ui/purchasing/orders', $f + ['action' => 'add']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([(int) $si['id'], 12], array_map('intval', array_values((array) self::$db->one('SELECT supplier_item_id, units_per_pack FROM po_line'))));
        // Another supplier's box in the address is not chosen, whatever the words say.
        $other = $this->supplier('Other Supplies');
        $theirs = $this->supply((int) $other['id'], $sku, 6, 'OT-6', false);
        $f = $buyer->get('/ui/purchasing/orders/new', ['supplier' => (string) $s['id'], 'pick_si' => (string) $theirs['id']])->form('/ui/purchasing/orders');
        self::assertSame(['', ''], [$f['pick_si'] ?? null, $f['q'] ?? null]);
    }

    /**
     * IV15 (the Codex review, MEDIUM 3): the same "Start" form sent again (a retry, the same key) does what it did the first time, even when
     * the item was joined into another one in between: the request is the item as sent; whether it is carried is decided once, inside.
     */
    public function testARetriedNewRecordReplaysAfterItsItemIsJoined(): void
    {
        $a = $this->item('strict', 3, 'Retried coil');
        $b = $this->item('strict', 0, 'Survivor coil');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $f = $web->get('/ui/stock/transfers', ['item' => (string) $a])->form('/ui/stock/transfers', true);
        $send = ['to_warehouse' => (string) self::warehouseId('VERIFY')] + $f;
        $first = $web->post('/ui/stock/transfers', $send);
        self::assertSame(303, $first->status, $first->describe());
        self::assertStringContainsString('add=' . sprintf('CW-%06d', $a), (string) $first->location());
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$b, $a]);
        $again = $web->post('/ui/stock/transfers', $send);
        self::assertSame([303, $first->location()], [$again->status, $again->location()], 'the retry replays: ' . $again->describe());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'TRF'"));
        // A new form for the joined item: a new record, nothing carried.
        $f = $web->get('/ui/stock/transfers', ['item' => (string) $a])->form('/ui/stock/transfers', true);
        self::assertArrayNotHasKey('item', $f);
        $r = $web->post('/ui/stock/transfers', ['to_warehouse' => (string) self::warehouseId('VERIFY'), 'item' => (string) $a] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringNotContainsString('add=', (string) $r->location(), 'a joined item is not put in the box');
        // The same key with another item is another request: refused.
        $other = $web->post('/ui/stock/transfers', ['item' => (string) $b] + $send);
        self::assertSame(422, $other->status, $other->describe());
    }

    /**
     * IV16 then, RD-A4 now: an old link into a part (#barcodes, #card) lands on a section that is always shown (no tab row to keep in step).
     */
    public function testAnOldFragmentLinkLandsOnASectionAlwaysShown(): void
    {
        $sku = $this->item('strict', 0, 'Fragment item');
        $page = $this->signIn($this->uiUser('viewer'))->get("/ui/items/{$sku}", ['tab' => 'barcodes']);
        $xp = self::xp($page);
        foreach (['barcodes', 'card'] as $id) {
            self::assertSame(1, $xp->query('//*[@id="' . $id . '"]')->length, $id);
            self::assertSame(0, $xp->query('//details//*[@id="' . $id . '"]')->length, "{$id}: not inside a fold");
        }
    }
}
