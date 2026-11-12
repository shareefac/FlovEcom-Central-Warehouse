<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Counts\StockDoubt;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\MainButtons;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Inventory › Doubtful stock (0066; docs/decisions.md DS1-), through the real kernel as the app login: the page shows ONLY the open items of
 * one store, in the order to check them, with the shop's figure, CW's figure now and why; a search and the filter by why; "Save count" books one
 * count of that item and says what it booked, and the row leaves the list; a second press says the same and books nothing more; a bad number
 * is refused next to its box, nothing booked; "Not needed" needs a note; the Checked tab says who, when, counted and booked; Home says how many
 * are left to check; a phone gets cards (no sideways table); a buyer only looks.
 */
final class DoubtfulStockScreensTest extends KernelUiTestCase
{
    private const PATH = '/ui/stock/doubtful';

    private int $store;

    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = (int) $this->site('vapeandgo')->channelId;
    }

    private static function cw(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }

    /** @param list<array{0: int, 1: string, 2: ?int, 3: int, 4: ?int}> $rows [item, why, shop, cw, sold] */
    private function load(array $rows, ?int $store = null, string $batch = 'list_12nov'): void
    {
        $parsed = [];
        foreach ($rows as $i => [$sku, $why, $shop, $cw, $sold]) {
            $parsed[] = ['row' => $i + 1, 'cw_code' => self::cw($sku), 'shop_qty' => $shop, 'cw_qty' => $cw, 'reason' => $why, 'sold_30d' => $sold, 'note' => null];
        }
        (new StockDoubt(self::$db))->load(Caller::system('doubt_load'), $store ?? $this->store, $parsed, $batch, 'list.csv');
    }

    private static function openId(int $sku): int
    {
        return (int) self::$db->value("SELECT id FROM stock_doubt WHERE sku_id = ? AND status = 'open'", [$sku]);
    }

    private static function cntDocs(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'CNT'");
    }

    private static function onHand(int $sku): int
    {
        return (int) self::$db->value("SELECT on_hand FROM stock_balance WHERE sku_id = ? AND warehouse_id = (SELECT id FROM warehouse WHERE code = 'MAIN')", [$sku]);
    }

    private static function str(UiResponse $r, string $xpath, ?\DOMNode $at = null): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($r->dom()))->evaluate('string(' . $xpath . ')', $at)));
    }

    /** @return list<array{sku: int, cells: array<string, string>, count: ?string, dismiss: ?string}> the rows of the open list, in order */
    private static function rows(UiResponse $r): array
    {
        self::assertSame(200, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//table[contains(@class, "doubt-list")]/tbody/tr') ?: [] as $tr) {
            /** @var \DOMElement $tr */
            $cells = [];
            foreach ($xp->query('./td[@data-label]', $tr) ?: [] as $td) {
                /** @var \DOMElement $td */
                $cells[$td->getAttribute('data-label')] = trim((string) preg_replace('/\s+/u', ' ', $td->textContent));
            }
            $link = $xp->query('./th//a[contains(@class, "o-name")]', $tr)->item(0);
            $count = $xp->query('.//form[contains(@action, "/count")]', $tr)->item(0);
            $dismiss = $xp->query('.//form[contains(@action, "/dismiss")]', $tr)->item(0);
            $out[] = ['sku' => $link instanceof \DOMElement ? (int) basename($link->getAttribute('href')) : 0, 'cells' => $cells,
                'count' => $count instanceof \DOMElement ? $count->getAttribute('action') : null, 'dismiss' => $dismiss instanceof \DOMElement ? $dismiss->getAttribute('action') : null];
        }
        return $out;
    }

    private function saveCount(KernelBrowser $web, int $sku, string $qty, array $query = []): UiResponse
    {
        $action = self::PATH . '/' . self::openId($sku) . '/count';
        $page = $web->get(self::PATH, $query);
        $form = $page->form($action);
        self::assertNotSame([], $form, 'no Save count form: ' . $page->describe());
        return $web->post($action, ['qty' => $qty] + $form);
    }

    // ------------------------------------------------------------------------------------------

    public function testThePageShowsOnlyTheOpenItemsOfTheStoreInTheOrderToCheckThem(): void
    {
        $pods = $this->item('strict', 112, 'Xros Corex pods');
        $blue = $this->item('strict', 30, 'Elux Mr Blue');
        $mint = $this->item('strict', 0, 'Fumot Cool Mint');
        $sour = $this->item('strict', 0, 'Elux Blueberry Sour');
        $fine = $this->item('strict', 8, 'Not in doubt');
        $this->load([[$blue, 'differs', 25, 30, 4], [$mint, 'below_zero', 0, -2, 10], [$pods, 'differs', 75, 112, 182], [$sour, 'below_zero', null, -1, 900]]);
        $ef = (int) $this->site('electrofag')->channelId;
        $this->load([[$fine, 'other', null, 8, null]], $ef, 'ef_list');

        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get(self::PATH);
        $rows = self::rows($page);
        self::assertSame([$pods, $blue, $sour, $mint], array_column($rows, 'sku'), 'the store that has rows first; differs by size, then below zero by units sold');
        $col = static fn (string $w): string => Words::DOUBT[$w];
        self::assertSame(['75', '25', '-', '0'], array_column(array_column($rows, 'cells'), $col('col_shop')));
        self::assertSame(['112', '30', '0', '0'], array_column(array_column($rows, 'cells'), $col('col_cw')), 'CW\'s figure now');
        self::assertSame([Words::DOUBT_WHY['differs'], Words::DOUBT_WHY['differs'], Words::DOUBT_WHY['below_zero'], Words::DOUBT_WHY['below_zero']],
            array_column(array_column($rows, 'cells'), $col('col_why')));
        self::assertSame(['182', '4', '900', '10'], array_column(array_column($rows, 'cells'), $col('col_sold')));
        foreach ($rows as $r) {
            self::assertSame(self::PATH . '/' . self::openId($r['sku']) . '/count', $r['count']);
            self::assertSame(self::PATH . '/' . self::openId($r['sku']) . '/dismiss', $r['dismiss'], 'Not needed, under More');
        }
        self::assertStringNotContainsString('Not in doubt', $page->text(), 'another store\'s items are not on this store\'s page');
        self::assertSame('Doubtful stock', self::str($page, '//h1'));
        self::assertSame(['vapeandgo', 'electrofag'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'),
            iterator_to_array((new \DOMXPath($page->dom()))->query('//form[@method="get"]//select[@name="store"]/option') ?: [])), 'two stores have items to check: a choice');
        self::assertSame([$fine], array_column(self::rows($web->get(self::PATH, ['store' => 'electrofag'])), 'sku'));
        self::assertSame([$pods, $blue, $sour, $mint], array_column(self::rows($web->get(self::PATH, ['store' => 'no_such'])), 'sku'), 'an unknown store: the default');
        $nav = self::nav($page);
        self::assertSame(5, $nav['Inventory']['count'] ?? null, 'the sidebar counts what waits for this person');

        // The search (code or name) and the filter by why.
        self::assertSame([$blue, $sour], array_column(self::rows($web->get(self::PATH, ['q' => 'elux'])), 'sku'));
        self::assertSame([$mint], array_column(self::rows($web->get(self::PATH, ['q' => self::cw($mint)])), 'sku'));
        self::assertSame([$sour, $mint], array_column(self::rows($web->get(self::PATH, ['why' => 'below_zero'])), 'sku'));
        self::assertSame([$pods, $blue], array_column(self::rows($web->get(self::PATH, ['why' => 'differs'])), 'sku'));
        self::assertSame([$pods, $blue, $sour, $mint], array_column(self::rows($web->get(self::PATH, ['why' => 'nonsense'])), 'sku'));
        $none = $web->get(self::PATH, ['q' => 'nothing like it']);
        self::assertSame([], self::rows($none));
        self::assertStringContainsString(Words::DOUBT['none_found'], $none->text());

        // A buyer looks: the list, no forms.
        $buyer = $this->signIn($this->uiUser('buyer'));
        $look = $buyer->get(self::PATH);
        self::assertSame([$pods, $blue, $sour, $mint], array_column(self::rows($look), 'sku'));
        self::assertSame([null, null, null, null], array_column(self::rows($look), 'count'));
        self::assertSame([null, null, null, null], array_column(self::rows($look), 'dismiss'));
    }

    public function testSaveCountBooksOneCountSaysWhatItBookedAndTheRowLeavesTheList(): void
    {
        $pods = $this->item('strict', 40, 'Xros Corex pods');
        $mint = $this->item('strict', 3, 'Fumot Cool Mint');
        $this->load([[$pods, 'differs', 41, 40, 12], [$mint, 'below_zero', 0, -2, 10]]);
        $id = self::openId($pods);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $action = self::PATH . '/' . $id . '/count';
        $form = $web->get(self::PATH, ['why' => 'differs'])->form($action);
        self::assertSame('differs', $form['why'] ?? null, 'the filter travels with the answer');

        $r = $web->post($action, ['qty' => '42'] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $after = $web->follow($r);
        $said = sprintf(Words::DOUBT_NOTICE['counted'], '42', self::cw($pods), '40', '+2');
        self::assertSame('Counted 42 of ' . self::cw($pods) . '. CW had 40: booked +2.', $said);
        self::assertStringContainsString($said, $after->text());
        self::assertSame([], array_column(self::rows($after), 'sku'), 'the row left the list (the filter kept: differs)');
        self::assertSame([$mint], array_column(self::rows($web->get(self::PATH)), 'sku'));
        self::assertSame([1, 42], [self::cntDocs(), self::onHand($pods)]);

        // The same press again (a second click, a resend): the first answer, nothing more booked.
        $again = $web->post($action, ['qty' => '42'] + $form);
        self::assertSame([303, $r->location()], [$again->status, $again->location()]);
        self::assertSame([1, 42], [self::cntDocs(), self::onHand($pods)]);
        // Another answer for a row already counted: refused, said.
        $other = $web->post($action, ['qty' => '50'] + $form);
        self::assertSame(409, $other->status, $other->describe());
        self::assertStringContainsString(Words::DOUBT_ERROR['doubt_settled'], $other->text());
        self::assertSame([1, 42], [self::cntDocs(), self::onHand($pods)]);

        // Checked: who, when, counted, booked; newest first.
        $checked = $web->get(self::PATH . '/checked');
        self::assertSame(200, $checked->status, $checked->describe());
        $xp = new \DOMXPath($checked->dom());
        $row = $xp->query('//table[contains(@class, "doubt-checked")]/tbody/tr')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row);
        self::assertStringContainsString(self::cw($pods), $row->textContent);
        $cell = static fn (string $col): string => trim((string) $xp->evaluate('string(./td[@data-label="' . Words::DOUBT[$col] . '"])', $row));
        self::assertSame(['42', '+2'], [$cell('col_counted'), $cell('col_booked')]);
        self::assertStringContainsString('Stock_controller', $cell('col_who'));
        self::assertNotSame('', $cell('col_when'));
        self::assertStringContainsString(Words::DOUBT_STATE['cleared'], $row->textContent);
    }

    public function testABadNumberIsRefusedNextToItsBoxAndNothingIsBooked(): void
    {
        $pods = $this->item('strict', 40, 'Xros Corex pods');
        $this->load([[$pods, 'differs', 41, 40, 12]]);
        self::$db->exec("INSERT INTO sku_barcode (sku_id, barcode, is_usable) VALUES (?, '50123456', 1)", [$pods]);
        $web = $this->signIn($this->uiUser('stock_controller'));
        foreach (['ten' => 'bad_quantity', '-3' => 'bad_quantity', '' => 'bad_quantity', '4.5' => 'bad_quantity', '5012345678901' => 'barcode_in_quantity',
            '50123456' => 'barcode_in_quantity'] as $typed => $code) {
            $r = $this->saveCount($web, $pods, (string) $typed);
            self::assertSame(422, $r->status, $typed . ': ' . $r->describe());
            $xp = new \DOMXPath($r->dom());
            $box = $xp->query('//form[contains(@action, "/' . self::openId($pods) . '/count")]//input[@name="qty"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $box);
            self::assertSame((string) $typed, $box->getAttribute('value'), 'what was typed stays in the box');
            self::assertSame('true', $box->getAttribute('aria-invalid'));
            $red = $xp->query('//*[@id="' . $box->getAttribute('aria-describedby') . '"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $red, 'the red line belongs to the box');
            self::assertSame(Words::DOUBT_ERROR[$code], trim($red->textContent));
            self::assertStringContainsString('error', $red->getAttribute('class'));
            self::assertSame($code, $xp->query('//*[@data-code]')->item(0)?->getAttribute('data-code'));
        }
        self::assertSame([0, 40], [self::cntDocs(), self::onHand($pods)], 'nothing booked');
        self::assertSame('open', (string) self::$db->value('SELECT status FROM stock_doubt WHERE sku_id = ?', [$pods]));
    }

    public function testNotNeededNeedsANoteAndBooksNothing(): void
    {
        $pods = $this->item('strict', 40, 'Xros Corex pods');
        $this->load([[$pods, 'differs', 41, 40, 12]]);
        $web = $this->signIn($this->uiUser('warehouse'));
        $action = self::PATH . '/' . self::openId($pods) . '/dismiss';
        $form = $web->get(self::PATH)->form($action);
        self::assertArrayHasKey('note', $form);
        $bad = $web->post($action, ['note' => 'ok'] + $form);
        self::assertSame(400, $bad->status, $bad->describe());
        self::assertStringContainsString(Words::DOUBT_ERROR['note_required'], $bad->text());
        self::assertSame('open', (string) self::$db->value('SELECT status FROM stock_doubt WHERE sku_id = ?', [$pods]));
        $ok = $web->post($action, ['note' => 'The shop figure is right'] + $form);
        self::assertSame(303, $ok->status, $ok->describe());
        $twice = $web->post($action, ['note' => 'The shop figure is right'] + $form);
        self::assertSame([303, $ok->location()], [$twice->status, $twice->location()], 'a second press: the first answer, not "someone already checked it"');
        self::assertStringContainsString(sprintf(Words::DOUBT_NOTICE['dismissed'], self::cw($pods)), $web->follow($ok)->text());
        self::assertSame([0, 40], [self::cntDocs(), self::onHand($pods)]);
        $checked = $web->get(self::PATH . '/checked');
        self::assertStringContainsString('The shop figure is right', $checked->text());
        self::assertStringContainsString(Words::DOUBT_STATE['dismissed'], $checked->text());
    }

    public function testOnlyThePeopleWhoCountAnswer(): void
    {
        $pods = $this->item('strict', 40, 'Xros Corex pods');
        $this->load([[$pods, 'differs', 41, 40, 12]]);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $csrf = $this->token($buyer);
        $id = self::openId($pods);
        self::assertSame(403, $buyer->post(self::PATH . '/' . $id . '/count', ['csrf' => $csrf, 'qty' => '41'])->status);
        self::assertSame(403, $buyer->post(self::PATH . '/' . $id . '/dismiss', ['csrf' => $csrf, 'note' => 'Not mine to say'])->status);
        $counter = $this->signIn($this->uiUser('stock_controller'));
        self::assertSame(403, $counter->post(self::PATH . '/' . $id . '/count', ['qty' => '41'])->status, 'no CSRF token: refused');
        self::assertSame(404, $counter->post(self::PATH . '/' . ($id + 99) . '/count', ['csrf' => $this->token($counter), 'qty' => '41'])->status);
        self::assertSame([0, 40], [self::cntDocs(), self::onHand($pods)]);
    }

    public function testHomeSaysHowManyAreLeftToCheck(): void
    {
        $pods = $this->item('strict', 40, 'Xros Corex pods');
        $mint = $this->item('strict', 3, 'Fumot Cool Mint');
        $this->load([[$pods, 'differs', 41, 40, 12], [$mint, 'below_zero', 0, -2, 10]]);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $xp = new \DOMXPath($web->get('/ui/')->dom());
        $row = $xp->query('//main//li[@data-card="doubt"]/a[@class="hm-job"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $row, 'Waiting for you has the line');
        self::assertSame(self::PATH, $row->getAttribute('href'));
        self::assertSame(Words::TASK['doubt']['title'], trim((string) $xp->evaluate('string(.//span[@class="hm-job-t"]/text())', $row)));
        self::assertSame('2 to check', trim((string) $xp->evaluate('string(.//span[@class="visually-hidden"])', $row)));
        self::assertSame(0, (new \DOMXPath($this->signIn($this->uiUser('buyer'))->get('/ui/')->dom()))->query('//li[@data-card="doubt"]')->length, 'a buyer counts nothing');
        $this->saveCount($web, $pods, '40');
        $this->saveCount($web, $mint, '3');
        self::assertSame(0, (new \DOMXPath($web->get('/ui/')->dom()))->query('//li[@data-card="doubt"]')->length, 'nothing left: no line');
    }

    public function testOnAPhoneEachItemIsACardWithOneBoxAndOneButton(): void
    {
        $pods = $this->item('strict', 40, 'Xros Corex pods');
        $this->load([[$pods, 'differs', 41, 40, 12]]);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get(self::PATH);
        $xp = new \DOMXPath($page->dom());
        $table = $xp->query('//table[contains(@class, "doubt-list")]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $table);
        self::assertStringContainsString('stack', $table->getAttribute('class'), 'a table that turns into cards under 700 px (CW\'s .stack)');
        self::assertSame(0, $xp->query('//table[contains(@class, "doubt-list")]//td[not(@data-label)]')->length, 'every cell says what it is on a card');
        self::assertSame(0, $xp->query('//main//*[@style] | //main//*[@width]')->length, 'no fixed widths');
        $box = $xp->query('//form[contains(@action, "/count")]//input[@name="qty"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $box);
        self::assertSame(['numeric', '[0-9]*', 'off'], [$box->getAttribute('inputmode'), $box->getAttribute('pattern'), $box->getAttribute('autocomplete')]);
        self::assertSame(Words::DOUBT['on_shelf'], trim((string) $xp->evaluate('string(//label[@for="' . $box->getAttribute('id') . '"])')));
        self::assertSame(1, $xp->query('//form[contains(@action, "/count")]//button[@type="submit"]')->length);
        self::assertSame(1, $xp->query('//details[contains(@class, "more")]//form[contains(@action, "/dismiss")]')->length, 'Not needed is under More');
        self::assertSame(0, $xp->query('//main//script')->length, 'no script');
        self::assertSame([], MainButtons::problems($page->body), 'no main button repeated on every row');
    }

    public function testFiftyAPageWithPlainLinks(): void
    {
        $rows = [];
        for ($i = 1; $i <= StockDoubt::PAGE + 2; $i++) {
            $rows[] = [$this->item('strict', 0, 'Paged pod ' . $i), 'below_zero', 0, -1, $i];
        }
        $this->load($rows);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $one = $web->get(self::PATH);
        self::assertCount(StockDoubt::PAGE, self::rows($one));
        $next = (new \DOMXPath($one->dom()))->query('//p[@class="pager"]/a')->item(0);
        self::assertInstanceOf(\DOMElement::class, $next);
        parse_str((string) parse_url($next->getAttribute('href'), PHP_URL_QUERY), $q);
        self::assertSame('2', $q['page'] ?? null);
        self::assertCount(2, self::rows($web->get(self::PATH, ['page' => '2'])));
    }
}
