<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Clock;
use CW\Output\CsvWriter;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Kernel;
use CW\Ui\ReservationViews;
use CW\Ui\Words;

/**
 * Stock › Reservations (docs/decisions.md RS1-RS12) through the real /ui kernel as cw_app: the stock the engine keeps for each
 * store's orders, read only, for everyone who sees a product's stock (catalogue.view). Every reservation here is made by the engine
 * itself (reserve, commit, ship, release, the expiry job), never by an INSERT: the tab and its place, the board grouped by state,
 * the store selector and the state filter, the search (order reference, product name, CW number), the three figures, one
 * reservation's page with its products and its stock history, the CSV, the older pages by id, an unknown id (404), a person
 * without the permission (403), and nonsense in the address.
 */
final class ReservationsScreenTest extends KernelUiTestCase
{
    private const PATH = '/ui/stock/reservations';

    private Caller $vpg;
    private Caller $alt;
    private int $alpha;
    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();
        // The screen reads the real clock ("kept until", "run out within the hour"), so the engine works at the real time here.
        $this->now = Clock::now();
    }

    /**
     * Two stores and eight orders, one of every kind, all through the engine:
     *   VPG V1005     reserved two hours ago, never paid: the expiry job freed it                   -> ran out of time
     *   VPG V1001     2 x Alpha + 1 ten-pack of Beta, paid, one Alpha sent                          -> sold, waiting to ship
     *   VPG V1002     1 x Beta in a checkout (kept 40 minutes)                                      -> reserved now
     *   VPG V1003     1 x Alpha, paid and sent                                                      -> sold: sent
     *   VPG V1004     1 x Alpha, freed by the store                                                 -> freed
     *   ALT A2001     3 x Alpha in a checkout (this store keeps a reservation two hours)            -> reserved now
     *   VPG =SUM(A1)  a product that is not linked to a warehouse product (no stock kept)           -> reserved now
     *   ALT ghost     a release for an order CW never saw                                           -> freed (nothing reserved)
     * The references are not bare numbers: the search reads a bare number as a CW number too.
     *
     * @return array<string, int> order reference => reservation id
     */
    private function orders(): array
    {
        $this->vpg = $this->site('vpg');
        $this->alt = $this->site('alt');
        self::$db->exec('UPDATE channel SET reserve_ttl_sec = 7200 WHERE id = ?', [$this->alt->channelId]);
        $this->alpha = $this->item('strict', 50, 'Alpha liquid 10ml');
        $this->beta = $this->item('strict', 40, 'Beta pod 2ml');
        $this->listing($this->vpg, 'A1', $this->alpha);
        $this->listing($this->vpg, 'B1', $this->beta);
        $this->listing($this->vpg, 'B10', $this->beta, 10);
        $this->listing($this->vpg, 'X9', null);
        $this->listing($this->alt, 'A1', $this->alpha);

        $real = $this->now;
        $this->now = $real->modify('-2 hours');
        $this->ok($this->reserve($this->vpg, 'V1005', [self::line('B1', 'e1', 'e2')]));
        $this->now = $real;
        self::assertSame(1, $this->res->expireDue(), 'the expiry job frees the hold nobody paid');

        $order = [self::line('A1', 'a1', 'a2'), self::line('B10', 't1')];
        $this->ok($this->reserve($this->vpg, 'V1001', $order));
        $this->ok($this->commit($this->vpg, 'V1001', $order));
        $sent = $real->modify('-1 minute')->format('Y-m-d\TH:i:s\Z');
        $this->ok($this->ship($this->vpg, 'V1001', ['a1'], $sent));
        $this->ok($this->reserve($this->vpg, 'V1002', [self::line('B1', 'b1')]));
        $this->ok($this->reserve($this->vpg, 'V1003', [self::line('A1', 'c1')]));
        $this->ok($this->commit($this->vpg, 'V1003', [self::line('A1', 'c1')]));
        $this->ok($this->ship($this->vpg, 'V1003', ['c1'], $sent));
        $this->ok($this->reserve($this->vpg, 'V1004', [self::line('A1', 'd1')]));
        $this->ok($this->release($this->vpg, 'V1004'));
        $this->ok($this->reserve($this->alt, 'A2001', [self::line('A1', 'x1', 'x2', 'x3')]));
        $this->ok($this->reserve($this->vpg, '=SUM(A1)', [self::line('X9', 'u1')]));
        $this->ok($this->release($this->alt, 'ghost'));

        $ids = [];
        foreach (self::$db->all('SELECT id, order_ref FROM reservation') as $r) {
            $ids[(string) $r['order_ref']] = (int) $r['id'];
        }
        self::assertCount(8, $ids);
        return $ids;
    }

    /** @param array<string, scalar|null> $query */
    private function page(KernelBrowser $web, array $query = []): UiResponse
    {
        $r = $web->get(self::PATH, $query);
        self::assertSame(200, $r->status, json_encode($query) . ' ' . $r->describe());
        return $r;
    }

    /** @return list<string> the order references of a page's boards, in order */
    private static function refs(UiResponse $r): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//table[contains(@class, "reservations")]/tbody/tr/th/a')));
    }

    /** @return list<string> the group titles of a page's boards, in order */
    private static function groupTitles(UiResponse $r): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//main//h3[contains(@class, "grp-title")]/button')));
    }

    /** @return list<string> the figures over the list, in order */
    private static function figures(UiResponse $r): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//main//li[@class="kpi"]/p[@class="kpi-value"]')));
    }

    /** The text of one order's row, white space collapsed. */
    private static function rowOf(UiResponse $r, string $ref): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($r->dom()))->evaluate(
            'string(//table[contains(@class, "reservations")]/tbody/tr[th/a[. = "' . $ref . '"]])')));
    }

    /** The store selector: label, link, the number after the label, current. @return list<array{0: string, 1: string, 2: int, 3: bool}> */
    private static function storeSeg(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//nav[contains(@class, "store-seg")]/a') as $a) {
            /** @var \DOMElement $a */
            $out[] = [trim((string) $xp->evaluate('string(span[1])', $a)), $a->getAttribute('href'), (int) $xp->evaluate('string(span[2]/text()[1])', $a),
                $a->getAttribute('aria-current') === 'page'];
        }
        return $out;
    }

    /** @return list<string> the links under the list (older reservations, back to the newest) */
    private static function pager(UiResponse $r): array
    {
        return array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//main//p[@class="pager"]/a')));
    }

    /** Opens a link of a page (its path and query). */
    private function open(KernelBrowser $web, string $href): UiResponse
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        $r = $web->send('GET', (string) parse_url($href, PHP_URL_PATH), $query, []);
        self::assertSame(200, $r->status, $href . ' ' . $r->describe());
        return $r;
    }

    public function testTheTabItsPlaceAndTheEmptyList(): void
    {
        $web = $this->signIn($this->uiUser('viewer'));
        $page = $this->page($web);
        self::assertSame(['Stock', 'Reservations'], [self::currentSection($page), self::currentTab($page)]);
        self::assertSame([['Overview', '/ui/stock'], ['Movements', '/ui/stock/movements'], ['Counts', null], ['Reservations', self::PATH], ['Quality', null]],
            array_map(static fn (array $t): array => [$t['label'], $t['href']], self::sectionTabs($page)),
            'Reservations keeps its place between Counts and Quality (both still "Soon"); a viewer reads no stock records');
        $xp = new \DOMXPath($page->dom());
        self::assertSame(Words::MENU['reservations'], trim((string) $xp->evaluate('string(//main//h1)')));
        self::assertStringStartsWith(Words::PAGE_INTRO['reservations'][0], trim((string) $xp->evaluate('string(//main//p[@class="lede"])')));
        // Nothing reserved yet: the empty state in words, the figures at nothing, no export, and no form that sends anything.
        self::assertStringContainsString(Words::RESV['none'], $page->text());
        self::assertStringContainsString(Words::RESV['none_text'], $page->text());
        self::assertSame(['0', '0', '0'], self::figures($page));
        self::assertSame([], self::refs($page));
        self::assertNotContains(self::PATH . '.csv', $page->hrefs(), 'nothing to export');
        self::assertSame(0, $xp->query('//main//form[translate(@method, "POST", "post") = "post"]')->length, 'read only: no form posts');
        self::assertSame([[Words::BULK['all_stores'], self::PATH, 0, true]], self::storeSeg($page), 'no store yet: only "All stores"');
        // The Dashboard lists the tab with its line; the stock records' people see it between their tabs.
        $home = $web->get('/ui/');
        self::assertContains(self::PATH, $home->hrefs());
        self::assertStringContainsString(Words::MENU_HELP['reservations'], $home->text());
        self::assertSame(['Overview', 'Movements', 'Stock In', 'Stock Out', 'Transfers', 'Adjustments', 'Counts', 'Reservations', 'Quality'],
            self::tabLabels($this->page($this->signIn($this->uiUser('stock_controller')))));
    }

    public function testTheBoardGroupsEveryStateNewestFirstWithTheFiguresAndTheStores(): void
    {
        $this->orders();
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $this->page($web);
        self::assertSame([Words::RESV_GROUP['held'], Words::RESV_GROUP['to_ship'], Words::RESV_GROUP['closed'], Words::RESV_GROUP['released'], Words::RESV_GROUP['expired']],
            self::groupTitles($page), 'the engine\'s states; a paid order waits to ship until none of its items does');
        self::assertSame(['=SUM(A1)', 'A2001', 'V1002', 'V1001', 'V1003', 'ghost', 'V1004', 'V1005'], self::refs($page), 'newest first inside each group');

        // One row: the store, the label, products and units (a ten-pack keeps 10 warehouse units), and what became of it.
        $row = self::rowOf($page, 'V1001');
        self::assertStringContainsString('VPG test site ' . Words::RESV_CHIP['to_ship'] . ' 2 12 ', $row);
        self::assertStringContainsString(Words::say('RESV', 'out_part_allocated', 2) . ' · ' . Words::say('RESV', 'out_part_shipped', 1), $row);
        self::assertStringContainsString(Words::RESV['out_all_shipped'], self::rowOf($page, 'V1003'));
        self::assertStringContainsString(Words::RESV['out_held'], self::rowOf($page, 'V1002'));
        self::assertStringContainsString(Words::RESV['out_released'], self::rowOf($page, 'V1004'));
        self::assertStringContainsString(Words::RESV['out_tombstone'], self::rowOf($page, 'ghost'));
        self::assertStringContainsString(Words::RESV['out_expired'], self::rowOf($page, 'V1005'));
        self::assertStringContainsString('ALT test site ' . Words::RESV_CHIP['held'] . ' 1 3 ', self::rowOf($page, 'A2001'));
        // A product that is not linked keeps no stock: 0 units, and the row says why.
        self::assertStringContainsString(Words::RESV_CHIP['held'] . ' 1 0 ', self::rowOf($page, '=SUM(A1)'));
        self::assertStringEndsWith(Words::RESV['out_held'] . Words::RESV['unlinked_one'], self::rowOf($page, '=SUM(A1)'));
        $xp = new \DOMXPath($page->dom());
        self::assertGreaterThan(0, $xp->query('//table[contains(@class, "reservations") and contains(@class, "stack")]//td[@data-label="' . Words::RESV['units'] . '"]')->length,
            'one card per order on a phone');
        // A hold says until when it is kept; nothing else does.
        self::assertSame(3, $xp->query('//table[contains(@class, "reservations")]/tbody/tr/td[@data-label="' . Words::RESV['expires'] . '"][normalize-space(.) != ""]')->length);

        // The figures: units reserved now (1 Beta + 3 Alpha; the unlinked product keeps none), reservations that run out within the hour
        // (the two of the store that keeps them 40 minutes), units sold and waiting to ship (1 Alpha + the ten-pack: the stock figure).
        self::assertSame(['4', '2', '11'], self::figures($page));
        self::assertStringContainsString(Words::say('RESV', 'tile_held_sub', 3), $page->text());
        self::assertSame(11, (int) self::$db->value('SELECT SUM(allocated) FROM stock_balance'));
        self::assertSame(4, (int) self::$db->value('SELECT SUM(held) FROM stock_balance'), 'the same units the stock figures call reserved');

        // The store selector: All stores, then each store of the channel table by name, with its reservations held now.
        self::assertSame([[Words::BULK['all_stores'], self::PATH, 3, true], ['ALT test site', self::PATH . '?channel=alt', 1, false],
            ['VPG test site', self::PATH . '?channel=vpg', 2, false]], self::storeSeg($page));
        $alt = $this->page($web, ['channel' => 'alt']);
        self::assertSame(['A2001', 'ghost'], self::refs($alt));
        self::assertSame([false, true, false], array_column(self::storeSeg($alt), 3));
        self::assertSame(['3', '0'], self::figures($alt), 'this store keeps a reservation two hours: none runs out within the hour');
        self::assertStringContainsString(Words::RESV['to_ship_all'], $alt->text(), 'units waiting to ship are not kept per store: left out, and the page says so');
        $vpg = $this->page($web, ['channel' => 'vpg']);
        self::assertSame(['=SUM(A1)', 'V1002', 'V1001', 'V1003', 'V1004', 'V1005'], self::refs($vpg));
        self::assertSame(['1', '2'], self::figures($vpg));
        self::assertStringContainsString(Words::say('RESV', 'tile_held_sub', 2), $vpg->text());

        // The state filter (the engine's four states), alone and with a store; the store's links keep the state.
        self::assertSame(['=SUM(A1)', 'A2001', 'V1002'], self::refs($held = $this->page($web, ['state' => 'held'])));
        self::assertSame([Words::RESV_GROUP['held']], self::groupTitles($held));
        self::assertSame(self::PATH . '?state=held&channel=vpg', self::storeSeg($held)[2][1]);
        $paid = $this->page($web, ['state' => 'committed']);
        self::assertSame(['V1001', 'V1003'], self::refs($paid));
        self::assertSame([Words::RESV_GROUP['to_ship'], Words::RESV_GROUP['closed']], self::groupTitles($paid));
        self::assertSame(['ghost', 'V1004'], self::refs($this->page($web, ['state' => 'released'])));
        self::assertSame(['V1004'], self::refs($this->page($web, ['state' => 'released', 'channel' => 'vpg'])));
        self::assertSame(['V1005'], self::refs($this->page($web, ['state' => 'expired'])));
        $none = $this->page($web, ['state' => 'expired', 'channel' => 'alt']);
        self::assertSame([], self::refs($none));
        self::assertStringContainsString(Words::RESV['none_filtered'], $none->text());
        self::assertContains(self::PATH . '?channel=alt', $none->hrefs(), 'Clear the filters keeps the store');
        self::assertStringContainsString(Words::RESV['none_store'], $this->page($this->signIn($this->uiUser('viewer')), ['channel' => $this->emptyStore()])->text());
    }

    /** A third store with nothing reserved. */
    private function emptyStore(): string
    {
        self::makeChannel('new', 'off');
        return 'new';
    }

    public function testTheSearchFindsAnOrderByItsReferenceAndTheOrdersThatHoldAProductNow(): void
    {
        $this->orders();
        $web = $this->signIn($this->uiUser('goods_in'));
        // The store's order reference, whole: that order and nothing else (the text is not read as a product as well).
        self::assertSame(['V1003'], self::refs($byRef = $this->page($web, ['q' => 'V1003'])));
        self::assertStringNotContainsString(Words::RESV['product_search'], $byRef->text());
        self::assertSame(['=SUM(A1)'], self::refs($this->page($web, ['q' => ' =SUM(A1) '])));
        self::assertSame([], self::refs($this->page($web, ['q' => 'V100'])), 'not a part of a reference');
        self::assertSame([], self::refs($this->page($web, ['q' => 'A2001', 'channel' => 'vpg'])), 'another store\'s order');
        // A product by words of its name (any order): the orders that hold it now, reserved or sold and waiting to ship.
        $beta = $this->page($web, ['q' => 'pod beta']);
        self::assertSame(['V1002', 'V1001'], self::refs($beta), 'not V1005: its reservation ran out and holds nothing');
        self::assertStringContainsString(Words::RESV['product_search'], $beta->text());
        // A product by its CW number, with the store and the state.
        $code = sprintf('CW-%06d', $this->alpha);
        self::assertSame(['A2001', 'V1001'], self::refs($this->page($web, ['q' => $code])), 'not V1003 (sent) and not V1004 (freed)');
        self::assertSame(['V1001'], self::refs($this->page($web, ['q' => $code, 'channel' => 'vpg'])));
        self::assertSame(['A2001'], self::refs($this->page($web, ['q' => strtolower($code), 'state' => 'held'])));
        $none = $this->page($web, ['q' => 'nothing like this']);
        self::assertStringContainsString(Words::RESV['none_filtered'], $none->text());
        self::assertContains(self::PATH, $none->hrefs(), 'Clear the filters');

        // The product's page leads here: its reserved figures link to the orders that hold them.
        $item = $web->get('/ui/items/' . $this->alpha);
        self::assertSame(200, $item->status, $item->describe());
        $links = array_values(array_filter($item->hrefs(), static fn (string $h): bool => str_starts_with($h, self::PATH)));
        self::assertSame([self::PATH . '?q=' . $code . '&state=committed', self::PATH . '?q=' . $code . '&state=held'], $links);
        self::assertSame(['V1001'], self::refs($this->open($web, $links[0])), 'sold, waiting to ship');
        self::assertSame(['A2001'], self::refs($this->open($web, $links[1])), 'reserved in a checkout');
        $plain = $web->get('/ui/items/' . $this->item('legacy', 5, 'Gamma kit'));
        self::assertSame([], array_values(array_filter($plain->hrefs(), static fn (string $h): bool => str_starts_with($h, self::PATH))), 'nothing reserved: a plain figure');
    }

    public function testOneReservationShowsItsFactsItsProductsAndWhatHappenedToTheStock(): void
    {
        $ids = $this->orders();
        $web = $this->signIn($this->uiUser('accountant'));
        $list = $this->page($web);
        self::assertContains(self::PATH . '/' . $ids['V1001'], $list->hrefs(), 'a row opens its reservation');
        $page = $web->get(self::PATH . '/' . $ids['V1001']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['Stock', 'Reservations'], [self::currentSection($page), self::currentTab($page)]);
        $xp = new \DOMXPath($page->dom());
        self::assertSame(Words::say('RESV', 'title', 'V1001') . ' ' . Words::RESV_CHIP['to_ship'], trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(//main//h1)'))));
        self::assertSame(self::PATH . '?channel=vpg', $xp->query('//main//p[@class="crumbs"]/a')->item(0)?->getAttribute('href'), 'back to the list, at its store');
        $facts = [];
        foreach ($xp->query('//main//dl/dt') as $dt) {
            $facts[trim((string) $dt->textContent)] = trim((string) $xp->evaluate('string(following-sibling::dd[1])', $dt));
        }
        self::assertSame('VPG test site', $facts[Words::RESV['f_store']]);
        self::assertSame('V1001', $facts[Words::RESV['f_ref']]);
        self::assertSame(Words::RESV_STATE['committed'] . ' · ' . Words::say('RESV', 'out_part_allocated', 2) . ' · ' . Words::say('RESV', 'out_part_shipped', 1), $facts[Words::RESV['f_state']]);
        self::assertSame(Words::RESV_ORIGIN['reserved'], $facts[Words::RESV['f_origin']]);
        self::assertSame(['2', '12'], [$facts[Words::RESV['f_products']], $facts[Words::RESV['f_units']]]);
        self::assertNotSame('', $facts[Words::RESV['f_started']]);
        self::assertNotSame('', $facts[Words::RESV['f_paid']]);
        self::assertStringNotContainsString('UTC', $page->text(), 'UK time');

        // Every line: the product with its CW number and a link to its page, the warehouse, the state, the sold items and the units.
        $main = (string) self::$db->value("SELECT name FROM warehouse WHERE code = 'MAIN'");
        $lines = [];
        foreach ($xp->query('//table[contains(@class, "reservation-lines")]/tbody/tr') as $tr) {
            $lines[] = trim((string) preg_replace('/\s+/u', ' ', (string) $tr->textContent));
        }
        $a = sprintf('CW-%06d', $this->alpha);
        $b = sprintf('CW-%06d', $this->beta);
        self::assertCount(3, $lines);
        self::assertSame("Alpha liquid 10ml{$a} {$main} " . Words::RESV_UNIT['allocated'] . ' 1 1', $lines[0]);
        self::assertStringStartsWith("Alpha liquid 10ml{$a} · " . Words::say('RESV', 'l_sent', ''), $lines[1]);
        self::assertStringEndsWith(" {$main} " . Words::RESV_UNIT['shipped'] . ' 1 1', $lines[1]);
        self::assertSame("Beta pod 2ml{$b} · " . Words::say('RESV', 'l_per_item', 10) . " {$main} " . Words::RESV_UNIT['allocated'] . ' 1 10', $lines[2]);
        self::assertSame(['/ui/items/' . $this->alpha, '/ui/items/' . $this->alpha, '/ui/items/' . $this->beta], array_map(
            static fn (\DOMElement $el): string => $el->getAttribute('href'), iterator_to_array($xp->query('//table[contains(@class, "reservation-lines")]/tbody/tr/th/a'))));

        // What happened to the stock: the stock ledger's rows of the order, a step per call of the engine, oldest first.
        $history = [];
        foreach ($xp->query('//table[contains(@class, "reservation-history")]/tbody/tr') as $tr) {
            $history[] = trim((string) preg_replace('/\s+/u', ' ', (string) $tr->textContent));
        }
        self::assertCount(3, $history);
        self::assertStringStartsWith(Words::MOVEMENT['reserve'], $history[0]);
        self::assertStringEndsWith(' 3 ' . Words::STOCK['held'] . ' +12 VPG test site', $history[0]);
        self::assertStringStartsWith(Words::MOVEMENT['commit'], $history[1]);
        self::assertStringEndsWith(' 3 ' . Words::STOCK['held'] . ' −12 · ' . Words::STOCK['allocated'] . ' +12 VPG test site', $history[1]);
        self::assertStringStartsWith(Words::MOVEMENT['ship'], $history[2]);
        self::assertStringEndsWith(' 1 ' . Words::STOCK['allocated'] . ' −1 · ' . Words::STOCK['on_hand'] . ' −1 VPG test site', $history[2]);
        self::assertSame(0, $xp->query('//main//form')->length, 'read only: no form at all');
        self::assertSame(0, (new \DOMXPath($list->dom()))->query('//main//form[translate(@method, "POST", "post") = "post"]')->length, 'the list posts nothing either');

        // A hold: until when it is kept. A reservation that ran out: who freed it. A product that is not linked: no stock, no history.
        $hold = $web->get(self::PATH . '/' . $ids['A2001']);
        self::assertStringContainsString(Words::RESV['f_expires'], $hold->text());
        self::assertStringContainsString(Words::RESV_STATE['held'] . ' · ' . Words::RESV['out_held'], $hold->text());
        $ranOut = $web->get(self::PATH . '/' . $ids['V1005']);
        self::assertStringContainsString(Words::RESV['f_ran_out'], $ranOut->text());
        self::assertStringContainsString(Words::MOVEMENT['expire'], $ranOut->text());
        self::assertStringContainsString(Words::RESV['h_cw'], $ranOut->text(), 'CW\'s own job freed it');
        $loose = $web->get(self::PATH . '/' . $ids['=SUM(A1)']);
        self::assertSame(200, $loose->status, $loose->describe());
        self::assertStringContainsString(Words::say('RESV', 'l_store_product', 'X9') . Words::RESV['l_unlinked'] . ' ', $loose->text());
        self::assertStringContainsString(Words::RESV['no_history'], $loose->text());
        self::assertStringContainsString('0 (' . Words::RESV['unlinked_one'] . ')', $loose->text());
        $ghost = $web->get(self::PATH . '/' . $ids['ghost']);
        self::assertStringContainsString(Words::RESV['no_lines'], $ghost->text());
        self::assertStringContainsString(Words::RESV['out_tombstone'], $ghost->text());
    }

    public function testTheListAsACsvIsTheFilteredListAndSafeForExcel(): void
    {
        $this->orders();
        $web = $this->signIn($this->uiUser('auditor'));
        self::assertContains(self::PATH . '.csv', $this->page($web)->hrefs(), 'Export');
        self::assertContains(self::PATH . '.csv?channel=alt&state=held', $this->page($web, ['channel' => 'alt', 'state' => 'held'])->hrefs(), 'Export keeps the store and the state');
        $csv = $web->get(self::PATH . '.csv');
        self::assertSame(200, $csv->status, $csv->describe());
        self::assertSame('text/csv; charset=utf-8', $csv->header('content-type'));
        self::assertSame("attachment; filename=\"reservations.csv\"; filename*=UTF-8''reservations.csv", $csv->header('content-disposition'));
        self::assertSame(['sandbox', Kernel::CSP], $csv->headerValues('content-security-policy'));
        self::assertStringStartsWith(CsvWriter::BOM . '"store","order_reference","state","what_became_of_it","products","units","items_not_linked","started_utc","kept_until_utc",'
            . '"paid_utc","ended_utc","tries"' . "\r\n", $csv->body);
        self::assertSame(9, substr_count($csv->body, "\r\n"), 'the header and the eight orders, CRLF');
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\r\n", substr($csv->body, strlen(CsvWriter::BOM))))));
        self::assertSame(['order_reference', 'ghost', "'=SUM(A1)", 'A2001', 'V1004', 'V1003', 'V1002', 'V1001', 'V1005'], array_column($rows, 1), 'every order, newest first');
        // A reference that a spreadsheet would run as a formula is written as text.
        self::assertStringContainsString('"VPG test site","\'=SUM(A1)","' . Words::RESV_STATE['held'] . '","' . Words::RESV['out_held'] . '",1,0,1,', $csv->body);
        self::assertStringContainsString('"VPG test site","V1001","' . Words::RESV_STATE['committed'] . '","' . Words::say('RESV', 'out_part_allocated', 2) . ' · '
            . Words::say('RESV', 'out_part_shipped', 1) . '",2,12,0,', $csv->body);
        self::assertMatchesRegularExpression('/"ALT test site","A2001","[^"]+","[^"]+",1,3,0,"\d{4}-\d\d-\d\d \d\d:\d\d:\d\d","\d{4}-\d\d-\d\d \d\d:\d\d:\d\d","","",1\r\n/', $csv->body, 'numbers bare, times as text');
        // The same filters as the page.
        $one = $web->get(self::PATH . '.csv', ['channel' => 'alt', 'state' => 'held']);
        self::assertSame(2, substr_count($one->body, "\r\n"));
        self::assertStringContainsString('"A2001"', $one->body);
        self::assertSame(3, substr_count($web->get(self::PATH . '.csv', ['q' => 'beta pod'])->body, "\r\n"));
        self::assertSame(1, substr_count($web->get(self::PATH . '.csv', ['q' => 'nothing like this'])->body, "\r\n"), 'only the header');
    }

    public function testOlderPagesAreReadByIdAndTheFileInBatches(): void
    {
        $vpg = $this->site('vpg');
        $this->listing($vpg, 'X9', null);
        $n = ReservationViews::PAGE + 2;
        for ($i = 1; $i <= $n; $i++) {
            $this->ok($this->reserve($vpg, 'p' . $i, [self::line('X9', 'pu' . $i)]));
        }
        $web = $this->signIn($this->uiUser('viewer'));
        foreach ([[], ['channel' => 'vpg'], ['state' => 'held'], ['channel' => 'vpg', 'state' => 'held']] as $query) {
            $label = json_encode($query);
            $first = $this->page($web, $query);
            self::assertSame(array_map(static fn (int $i): string => 'p' . $i, range($n, 3)), self::refs($first), $label);
            $older = self::pager($first);
            self::assertCount(1, $older, "one link, to the older reservations {$label}");
            parse_str((string) parse_url($older[0], PHP_URL_QUERY), $q);
            self::assertSame($query + ['before' => (string) self::$db->value("SELECT id FROM reservation WHERE order_ref = 'p3'")], $q,
                'the page starts before the last id shown, and the link keeps the store and the state');
            $second = $this->open($web, $older[0]);
            self::assertSame(['p2', 'p1'], self::refs($second), $label);
            self::assertSame([self::PATH . ($query === [] ? '' : '?' . http_build_query($query))], self::pager($second), 'back to the newest, and nothing older');
        }
        // The file is read in batches by id, up to its limit, and says when there is more.
        $views = new ReservationViews(self::$db);
        $stores = $views->stores();
        $f = ['channel' => null, 'state' => 'held', 'q' => '', 'before' => null];
        $all = $views->export($f, $stores, 20, 100);
        self::assertSame([$n, false, 'p' . $n, 'p1'], [count($all['rows']), $all['more'], $all['rows'][0]['ref'], $all['rows'][$n - 1]['ref']]);
        $cut = $views->export($f, $stores, 20, 40);
        self::assertSame([40, true, 'p' . ($n - 39)], [count($cut['rows']), $cut['more'], $cut['rows'][39]['ref']]);
        self::assertSame($n + 1, substr_count($web->get(self::PATH . '.csv', ['channel' => 'vpg'])->body, "\r\n"));
    }

    public function testWhoMayOpenItAnUnknownReservationAndNonsenseInTheAddress(): void
    {
        $ids = $this->orders();
        // Everyone who sees a product's stock (catalogue.view), as Stock › Overview and Movements; nobody else.
        foreach (['viewer', 'buyer', 'goods_in', 'admin', 'accountant', 'reviewer'] as $role) {
            $web = $this->signIn($this->uiUser($role));
            foreach ([self::PATH, self::PATH . '.csv', self::PATH . '/' . $ids['V1001']] as $path) {
                self::assertSame(200, $web->get($path)->status, "{$role} {$path}");
            }
        }
        self::assertSame(303, $this->browser()->get(self::PATH)->status, 'signed out: to the sign-in page');
        $none = $this->uiUser('viewer');
        $web = $this->signIn($none);
        self::$db->exec('UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ?', [$none['id']]);
        foreach ([self::PATH, self::PATH . '.csv', self::PATH . '/' . $ids['V1001']] as $path) {
            $refused = $web->get($path);
            self::assertSame(403, $refused->status, "{$path}: " . $refused->describe());
            self::assertSame('role_not_allowed', $refused->errorCode());
            self::assertStringNotContainsString('V1001', $refused->body, 'nothing of the list reaches a person without the permission');
        }
        // Read only: nothing can be sent to it.
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(405, $buyer->post(self::PATH, ['csrf' => $this->token($buyer)])->status);
        self::assertSame(405, $buyer->post(self::PATH . '/' . $ids['V1001'], ['csrf' => $this->token($buyer)])->status);

        // A reservation that does not exist.
        $missing = $buyer->get(self::PATH . '/999999');
        self::assertSame(404, $missing->status, $missing->describe());
        self::assertSame('not_found', $missing->errorCode());
        self::assertSame('Stock', self::currentSection($missing));
        self::assertContains(self::PATH, $missing->hrefs(), 'a way back to the list');
        foreach (['/0', '/abc', '/-1', '/' . $ids['V1001'] . '/edit', '/999999999999999999999'] as $tail) {
            self::assertSame(404, $buyer->get(self::PATH . $tail)->status, $tail);
        }

        // Nonsense in the address is ignored, never an error: a list where a value is expected, a negative page, an unknown store or state.
        $all = ['=SUM(A1)', 'A2001', 'V1002', 'V1001', 'V1003', 'ghost', 'V1004', 'V1005'];
        foreach ([
            ['state' => ['held'], 'channel' => ['vpg'], 'q' => ['x'], 'before' => ['3'], 'page' => ['2']],
            ['page' => '-1', 'before' => '-5', 'state' => 'nope', 'channel' => 'no-such-store'],
            ['before' => '0', 'state' => '', 'channel' => 'VPG'],
            ['before' => '99999999999999999999999', 'state' => 'HELD'],
        ] as $query) {
            $page = $buyer->send('GET', self::PATH, $query, []);
            self::assertSame(200, $page->status, json_encode($query) . ' ' . $page->describe());
            self::assertSame($all, self::refs($page), json_encode($query));
            self::assertLessThan(500, $buyer->send('GET', self::PATH . '.csv', $query, [])->status, json_encode($query));
            self::assertLessThan(500, $buyer->send('GET', self::PATH . '/' . $ids['V1001'], $query, [])->status, json_encode($query));
        }
        foreach ([str_repeat('x', 500), "\xff\xfe", "o'; DROP TABLE reservation; --", '%', '100%', "CW-000001\n", '0', str_repeat('9', 30), '<b>x</b>'] as $text) {
            $page = $buyer->send('GET', self::PATH, ['q' => $text], []);
            self::assertSame(200, $page->status, json_encode(bin2hex($text)) . ' ' . $page->describe());
            self::assertStringNotContainsString('<b>x</b>', $page->body, 'a search text is only ever text');
            self::assertLessThan(500, $buyer->send('GET', self::PATH . '.csv', ['q' => $text, 'state' => 'held'], [])->status);
        }
        self::assertSame(8, (int) self::$db->value('SELECT COUNT(*) FROM reservation'), 'nothing was changed by looking');
    }
}
