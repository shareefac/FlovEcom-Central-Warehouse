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
use CW\Ui\Controller\ItemController;
use CW\Ui\Html;
use CW\Ui\Words;

/**
 * The item page, stock first (docs/decisions.md IV1-IV12; the owner's approved design IT_Item, IT_Phone of 29 Oct 2026), through the real
 * kernel: the head (the name, the status block, the code and its short identity line, the actions the person may use: Count it, Move stock,
 * Order more, More), the stock strip (on the shelf, held for orders, free to sell, coming in with its first order, sells a week and how long it
 * lasts), the recent movements with their solid cells and the way to all of them, where it sells, and the tabs that hold the sections and
 * forms of before (Details, Barcodes, Suppliers and packs, Store matching, History), which open where the work is.
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

    /** The head's status block and identity line. @return array{0: string, 1: string, 2: string} [status words, its tone, the identity line] */
    private static function head(UiResponse $r): array
    {
        $xp = self::xp($r);
        $chip = $xp->query('//div[contains(@class, "item-head")]//p[@class="ph-meta"]/span[contains(concat(" ", @class, " "), " chip ")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $chip, 'the status block: ' . $r->describe());
        $tone = array_values(array_diff(explode(' ', $chip->getAttribute('class')), ['chip']))[0] ?? '';
        return [self::squash($chip->textContent), $tone, self::squash($xp->evaluate('string(//div[contains(@class, "item-head")]//span[@class="item-id"])'))];
    }

    /** The head's actions as their words, in order, the main one marked "*". @return list<string> */
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

    /** The More menu's entries: words => link. @return array<string, string> */
    private static function more(UiResponse $r): array
    {
        $out = [];
        foreach (self::xp($r)->query('//div[contains(@class, "item-bar")]/details[contains(@class, "more")]//a[contains(@class, "menu-item")]') ?: [] as $a) {
            /** @var \DOMElement $a */
            $out[self::squash($a->textContent)] = $a->getAttribute('href');
        }
        return $out;
    }

    /** The stock strip: label => [the figure, the line under it]. @return array<string, array{0: string, 1: string}> */
    private static function strip(UiResponse $r): array
    {
        $xp = self::xp($r);
        $out = [];
        foreach ($xp->query('//section[contains(@class, "item-strip")]/div[contains(@class, "doc-step")]') ?: [] as $step) {
            $out[self::squash($xp->evaluate('string(p[contains(@class, "doc-step-label")])', $step))] = [
                self::squash($xp->evaluate('string(p[contains(@class, "doc-step-big")])', $step)), self::squash($xp->evaluate('string(p[contains(@class, "step-sub")])', $step))];
        }
        return $out;
    }

    /** Where it sells: one chip a store, its words => its tone. @return array<string, string> */
    private static function sells(UiResponse $r): array
    {
        $out = [];
        foreach (self::xp($r)->query('//section[contains(@class, "item-sells")]//span[contains(concat(" ", @class, " "), " chip ")]') ?: [] as $c) {
            /** @var \DOMElement $c */
            $out[self::squash($c->textContent)] = array_values(array_diff(explode(' ', $c->getAttribute('class')), ['chip']))[0] ?? '';
        }
        return $out;
    }

    /** The item's tabs: key => [label, link ('' for the open one: CV13), current, its panel open]. @return array<string, array{0: string, 1: string, 2: bool, 3: bool}> */
    private static function itemTabs(UiResponse $r): array
    {
        $xp = self::xp($r);
        $out = [];
        foreach ($xp->query('//nav[contains(concat(" ", @class, " "), " item-tabs ")]/a[not(contains(@class, "tab-back"))]') ?: [] as $i => $a) {
            /** @var \DOMElement $a */
            self::assertSame('tab', $a->getAttribute('class'), 'the one tabs component (DS2)');
            $key = ItemController::TABS[$i];
            if ($a->hasAttribute('href')) {
                parse_str((string) parse_url($a->getAttribute('href'), PHP_URL_QUERY), $q);
                self::assertSame($key, $q['tab'] ?? null, 'the tabs in their order');
            }
            $panel = $xp->query('//div[@id="t-' . $key . '"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $panel, "the panel of {$key}");
            $out[$key] = [self::squash($xp->evaluate('string(span[1])', $a)), $a->getAttribute('href'), $a->getAttribute('aria-current') === 'page',
                str_contains(' ' . $panel->getAttribute('class') . ' ', ' open ')];
        }
        return $out;
    }

    /** The key of the one open tab (marked current, its panel open), failing when there is not exactly one. */
    private static function openTab(UiResponse $r): string
    {
        $open = array_keys(array_filter(self::itemTabs($r), static fn (array $t): bool => $t[2] && $t[3]));
        self::assertCount(1, $open, 'one tab open: ' . json_encode(self::itemTabs($r)));
        self::assertCount(1, array_filter(self::itemTabs($r), static fn (array $t): bool => $t[2] || $t[3]), 'and only it');
        return $open[0];
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
        self::assertSame([Words::ITEM['st_selling'], 'done', $code . ' · Pod Salt · nic salt · ' . Words::say('ITEM', 'pack_from', 'box of 10', 'Prime Vape Supply')],
            self::head($page));
        self::assertSame([Words::ITEM['order_more'] . '*', Words::DOC['more']], self::actions($page));
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

        // The stock controller: Count it is the main action (no order); Move stock; the other stock records under More.
        $stock = $this->signIn($this->uiUser('stock_controller'));
        $page = $stock->get("/ui/items/{$sku}");
        self::assertSame([Words::COUNTS['item_count_it'] . '*', Words::ITEM['move_stock'], Words::DOC['more']], self::actions($page));
        self::assertSame([Words::ITEM['more_in'] => "/ui/stock/in?item={$sku}#new", Words::ITEM['more_out'] => "/ui/stock/out?item={$sku}#new",
            Words::ITEM['more_adjust'] => "/ui/stock/adjustments?item={$sku}#new"], self::more($page));
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
        $move = (string) self::xp($page)->evaluate('string(//div[contains(@class, "item-bar")]/a[normalize-space() = "' . Words::ITEM['move_stock'] . '"]/@href)');
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
        self::assertSame([Words::ITEM['on_shelf'], Words::ITEM['held_orders'], Words::ITEM['free'], Words::ITEM['coming'], Words::ITEM['weekly']], array_keys($strip));
        self::assertSame(['160', Words::say('COUNTS', 'item_counted', Html::day('2026-09-26 13:00:00.000000'))], $strip[Words::ITEM['on_shelf']]);
        self::assertSame(['3', Words::say('ITEM', 'held_paid', 2) . ' · ' . Words::say('ITEM', 'held_unpaid', 1)], $strip[Words::ITEM['held_orders']]);
        self::assertSame(['157', ''], $strip[Words::ITEM['free']], 'available: what the websites may sell');
        self::assertSame(['90', $first['number'] . ' · ' . Words::say('ITEM', 'coming_due', Html::day('2026-11-02'))], $strip[Words::ITEM['coming']],
            'every open order\'s units; the first one due');
        self::assertSame(['22', Words::say('ITEM', 'lasts_many', 7)], $strip[Words::ITEM['weekly']], '157 free at 22 a week');
        $links = array_values(array_filter($page->hrefs(), static fn (string $h): bool => str_starts_with($h, '/ui/stock/reservations')
            || preg_match('#^/ui/purchasing/orders/[0-9]+$#D', $h) === 1));
        self::assertSame(['/ui/stock/reservations?q=' . $code . '&state=committed', '/ui/stock/reservations?q=' . $code . '&state=held', '/ui/purchasing/orders/' . $first['id']], $links,
            'the held figures lead to the orders that hold them, the first order to its page');
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
        self::assertSame(['0', ''], self::strip($buyer->get("/ui/items/{$quiet}"))[Words::ITEM['coming']]);
        foreach ([['0.0000', ['0', '']], ['0.0100', [Words::ITEM['weekly_less'], Words::ITEM['lasts_year']]], ['0.5000', ['4', Words::ITEM['lasts_one']]],
            ['2.0000', ['14', Words::ITEM['lasts_less']]]] as [$rate, $want]) {
            self::$db->exec('DELETE FROM reorder_demand WHERE sku_id = ?', [$quiet]);
            self::rate($quiet, $rate);
            self::assertSame($want, self::strip($buyer->get("/ui/items/{$quiet}"))[Words::ITEM['weekly']], $rate);
        }
        $empty = $this->item('strict', 0, 'Empty coil');
        self::rate($empty, '1.0000');
        self::assertSame(['7', ''], self::strip($buyer->get("/ui/items/{$empty}"))[Words::ITEM['weekly']], 'nothing free: no "lasts"');
        self::assertSame(['0', Words::COUNTS['item_never']], self::strip($buyer->get("/ui/items/{$empty}"))[Words::ITEM['on_shelf']]);
    }

    // ------------------------------------------------------------------------------------------
    // Recent movements
    // ------------------------------------------------------------------------------------------

    public function testRecentMovementsAreSolidByKindAndLeadToAllOfThem(): void
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
            $cell = $xp->query('td[contains(@class, "doc-st")]', $tr)->item(0);
            self::assertInstanceOf(\DOMElement::class, $cell, 'each row has its solid cell');
            $tone = array_values(array_intersect(explode(' ', $cell->getAttribute('class')), Words::TONES));
            $rows[] = [self::squash($xp->evaluate('string(span[@class="chip"])', $cell)), $tone, self::squash($xp->evaluate('string(td[@data-label="' . Words::ITEM['m_units'] . '"])', $tr))];
        }
        self::assertSame([
            [Words::MOVE_KIND['commit'], [Words::tone('MOVE_KIND', 'commit')], '+1'],
            [Words::MOVE_KIND['commit'], [Words::tone('MOVE_KIND', 'commit')], '−1'],
            [Words::MOVE_KIND['reserve'], [Words::tone('MOVE_KIND', 'reserve')], '+1'],
            [Words::MOVE_KIND['count'], [Words::tone('MOVE_KIND', 'count')], '−4'],
            [Words::MOVE_KIND['goods_in'], [Words::tone('MOVE_KIND', 'goods_in')], '+20'],
        ], $rows, 'newest first, each cell in its kind\'s colour');
        self::assertSame(['review', 'done', 'info'], [Words::tone('MOVE_KIND', 'count'), Words::tone('MOVE_KIND', 'goods_in'), Words::tone('MOVE_KIND', 'commit')],
            'the design\'s colours: a count purple, goods in green, a sale blue');
        $all = (string) $xp->evaluate('string(//section[contains(@class, "item-moves")]//a[normalize-space() = "' . Words::ITEM['all_moves'] . '"]/@href)');
        self::assertSame('/ui/stock/movements?q=' . $code . '&figure=all', $all);
        $moves = self::open($web, $all);
        self::assertSame(200, $moves->status, $moves->describe());
        self::assertStringContainsString('Moving liquid', $moves->text());
        self::assertStringNotContainsString('Not this one', $moves->text(), 'only this item');
        self::assertStringContainsString(Words::ITEM['no_movements'], $web->get('/ui/items/' . self::makeSku('Never moved'))->text());
        unset($other);
    }

    // ------------------------------------------------------------------------------------------
    // Where it sells
    // ------------------------------------------------------------------------------------------

    public function testWhereItSellsSaysEachStoreInOneSolidChip(): void
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
        $chip = static fn (Caller $c, string $state, string ...$args): string => Words::say('ITEM', 'sells_chip', $name($c), Words::say('ITEM_SELLS', $state, ...$args));
        self::assertSame([$chip($vpg, 'on_sale') => 'done', $chip($big, 'not_on_sale', 'Draft') => 'needs', $chip($ef, 'suggested') => 'needs',
            $chip($alt, 'on_hold') => 'blocked'], self::sells($web->get("/ui/items/{$sku}")), 'the owner\'s order of the stores, then the others');
        // Matched with nothing known of its state; and a store with nothing for it.
        $plain = $this->item('strict', 0, 'Plain pod');
        $this->listing($vpg, 'V2', $plain);
        self::assertSame([$chip($vpg, 'matched') => 'done', $chip($big, 'not_linked') => 'waiting', $chip($ef, 'not_linked') => 'waiting',
            $chip($alt, 'not_linked') => 'waiting'], self::sells($web->get("/ui/items/{$plain}")));
    }

    // ------------------------------------------------------------------------------------------
    // The tabs
    // ------------------------------------------------------------------------------------------

    public function testTheTabsHoldTheSectionsAndOpenWhereTheWorkIs(): void
    {
        $vpg = $this->site('vapeandgo');
        $sku = $this->item('legacy', 4, 'Tabbed liquid');
        $this->listing($vpg, 'V1', $sku);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('4006381333931', ?), ('5012345000011', ?)", [$sku, $sku]);
        $s = $this->supplier('Tab Supplies');
        $this->supply((int) $s['id'], $sku, 10, 'TB-10');
        $lead = $this->signIn($this->uiUser(['mapping_lead', 'stock_controller']));
        $page = $lead->get("/ui/items/{$sku}");
        $tabs = self::itemTabs($page);
        $href = static fn (string $k): string => "/ui/items/{$sku}?tab={$k}#it-tabs";
        self::assertSame([
            'details' => [Words::ITEM['tab_details'], '', true, true],
            'barcodes' => [Words::ITEM['tab_barcodes'], $href('barcodes'), false, false],
            'packs' => [Words::ITEM['tab_packs'], $href('packs'), false, false],
            'stores' => [Words::ITEM['tab_stores'], $href('stores'), false, false],
            'history' => [Words::ITEM['tab_history'], $href('history'), false, false],
        ], $tabs, 'Details first and open, and no link: a click never reloads the page (CV13)');
        self::assertSame($href('details'), self::itemTabs($lead->get("/ui/items/{$sku}", ['tab' => 'barcodes']))['details'][1], 'a closed tab is a link');
        $count = static fn (UiResponse $r, string $k): string => self::squash((string) self::xp($r)->evaluate('string(//nav[contains(@class, "item-tabs")]/a[@data-tab="' . $k . '"][1]/span[contains(@class, "count")])'));
        self::assertSame(['2', '1', '1'], [$count($page, 'barcodes'), $count($page, 'packs'), $count($page, 'stores')], 'how many barcodes, supplies and matched products');
        // Each part in its tab, with its forms as before.
        $in = static fn (UiResponse $r, string $panel, string $xpath): int => self::xp($r)->query('//div[@id="t-' . $panel . '"]' . $xpath)->length;
        self::assertSame(1, $in($page, 'details', '//section[@id="card"]'));
        self::assertSame(1, $in($page, 'details', '//a[@href="/ui/items/' . $sku . '/card"]'));
        self::assertSame(1, $in($page, 'barcodes', '//form[contains(@action, "/barcodes")]//input[@name="barcode" and @type="text"]'));
        self::assertSame(1, $in($page, 'packs', '//section[@id="groups"]'));
        self::assertSame(1, $in($page, 'packs', '//table[contains(@class, "item-suppliers")]'));
        self::assertSame(1, $in($page, 'stores', '//table[contains(@class, "item-listings")]'));
        self::assertSame(1, $in($page, 'stores', '//section[@id="selling-mode"]'));
        self::assertSame(1, $in($page, 'history', '//*[contains(@class, "card-history")]'));

        // Plain links: each opens its tab; a nonsense one opens Details.
        foreach (['barcodes', 'packs', 'stores', 'history', 'details'] as $k) {
            self::assertSame($k, self::openTab($lead->get("/ui/items/{$sku}", ['tab' => $k])), $k);
        }
        self::assertSame('details', self::openTab($lead->get("/ui/items/{$sku}", ['tab' => 'x<b>'])));
        // After a change, the tab of what was changed; after a refusal too.
        $f = $page->form("/ui/items/{$sku}/barcodes");
        $r = $lead->post("/ui/items/{$sku}/barcodes", ['barcode' => '96385074', 'units' => '1'] + $f);
        self::assertSame("/ui/items/{$sku}?notice=barcode_added", $r->location(), $r->describe());
        self::assertSame('barcodes', self::openTab($lead->follow($r)));
        $f = $lead->get("/ui/items/{$sku}")->form("/ui/items/{$sku}/barcodes");
        $bad = $lead->post("/ui/items/{$sku}/barcodes", ['barcode' => '123', 'units' => '1'] + $f);
        self::assertSame(422, $bad->status, $bad->describe());
        self::assertSame('barcodes', self::openTab($bad), 'the refusal is said in its tab');
        self::assertSame(1, self::xp($bad)->query('//p[@class="error"]')->length);
        self::assertSame('123', (string) self::xp($bad)->evaluate('string(//form[@action="/ui/items/' . $sku . '/barcodes"]//input[@name="barcode"]/@value)'), 'what was typed is kept');
        self::assertSame('stores', self::openTab($lead->get("/ui/items/{$sku}", ['notice' => 'selling_mode_set'])));
        self::assertSame('packs', self::openTab($lead->get("/ui/items/{$sku}", ['notice' => 'family_set'])));
        self::assertSame('details', self::openTab($lead->get("/ui/items/{$sku}", ['notice' => 'card_saved'])));
        self::assertSame('barcodes', self::openTab($lead->get("/ui/items/{$sku}", ['notice' => 'barcode_added', 'tab' => 'barcodes'])));

        // Someone who only looks: the same tabs, no form to change anything.
        $viewer = $this->signIn($this->uiUser('viewer'))->get("/ui/items/{$sku}");
        self::assertSame(array_keys($tabs), array_keys(self::itemTabs($viewer)));
        self::assertSame(0, self::xp($viewer)->query('//div[contains(@class, "it-panel")]//form[@method="post"]')->length);
        self::assertNotContains("/ui/items/{$sku}/card", $viewer->hrefs());
        self::assertSame('', $count($viewer, 'packs'), 'no count of the supplies for someone who may not see them');
    }

    public function testTheLookOnlyAndTheEmptyHistory(): void
    {
        $sku = $this->item('strict', 0, 'Quiet item');
        $web = $this->signIn($this->uiUser('viewer'));
        $page = $web->get("/ui/items/{$sku}", ['tab' => 'history']);
        self::assertSame('history', self::openTab($page));
        self::assertStringContainsString(Words::ITEM['history_none'], self::squash((string) self::xp($page)->evaluate('string(//div[@id="t-history"])')));
        self::assertSame([], self::sells($page), 'no store yet: no chip');
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
        foreach (self::itemTabs($page) as $key => $t) {
            if ($t[1] !== '') {
                self::assertStringStartsWith("/ui/items/{$sku}?tab={$key}", $t[1], 'a tab is a link that leaves (the guard asks), never a #fragment (it would not ask)');
            }
        }
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
     * IV16 (the Codex review, LOW): an old link into a part (#barcodes) shows that part without the script; the tabs then say so and the open
     * tab is a link again (its hidden twin, shown only then), so there is always a way back to it.
     */
    public function testTheOpenTabHasAWayBackWhenAFragmentShowsAnotherPart(): void
    {
        $sku = $this->item('strict', 0, 'Fragment item');
        $web = $this->signIn($this->uiUser('viewer'));
        foreach (['details', 'barcodes'] as $open) {
            $page = $web->get("/ui/items/{$sku}", ['tab' => $open]);
            $xp = self::xp($page);
            $tabs = $xp->query('//nav[contains(@class, "item-tabs")]/a');
            $keys = [];
            foreach ($tabs ?: [] as $a) {
                /** @var \DOMElement $a */
                $keys[] = $a->getAttribute('data-tab') . ($a->getAttribute('aria-current') === 'page' ? '*' : '') . (str_contains($a->getAttribute('class'), 'tab-back') ? '<' : '');
            }
            $want = [];
            foreach (ItemController::TABS as $k) {
                $want[] = $k === $open ? $k . '*' : $k;
                if ($k === $open) {
                    $want[] = $k . '<';
                }
            }
            self::assertSame($want, $keys, 'each tab names its part; the open one has its twin right after it');
            $back = $xp->query('//nav[contains(@class, "item-tabs")]/a[contains(@class, "tab-back")]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $back);
            self::assertSame("/ui/items/{$sku}?tab={$open}#it-tabs", $back->getAttribute('href'));
            self::assertSame('tab tab-back', $back->getAttribute('class'));
            self::assertFalse($back->hasAttribute('aria-current'));
            self::assertSame(1, $xp->query('//div[@id="t-' . $open . '" and contains(@class, "open")]')->length);
        }
    }
}
