<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Output\CsvWriter;
use CW\Settings;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Kernel;
use CW\Ui\SalesViews;
use CW\Ui\Words;

/**
 * Stock › Sales (pack D, docs/decisions.md PD1-PD14) through the real /ui kernel as cw_app: each store's orders, one order with its
 * sold units and their stock changes, Dispatch and Returns, READ ONLY, for the people who look at sales (sales.view). Every order here
 * is made by the engine itself (reserve, commit, ship, unship, return, cancel, release, the expiry job), never by an INSERT; only the
 * placing time of the two oldest is moved back (they are placed first, so order ids still follow the time, as on a real store).
 * The tab, its segments and their place; the filters per store, state, way in and days; the page size, the default days and the late
 * hours read from their settings; paging by key; the CSVs; who may open what; every page escapes what a store or a person typed; no
 * POST; the migration's settings and indexes, and the plan of every statement.
 */
final class SalesScreensTest extends KernelUiTestCase
{
    private const ORDERS = '/ui/stock/sales';
    private const DISPATCH = '/ui/stock/sales/dispatch';
    private const RETURNS = '/ui/stock/sales/returns';

    private Caller $vpg;
    private Caller $alt;
    private int $alpha;
    private int $beta;
    private \DateTimeImmutable $real;
    /** @var array<string, string> setting key => value to put back */
    private array $settingsToRestore = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The screens read the real clock ("today", "this week", late), so the engine works at the real time here.
        $this->now = Clock::now();
        $this->real = $this->now;
    }

    protected function tearDown(): void
    {
        // app_setting is a seed table the tests keep: put each value back, never touching who changed it last.
        foreach ($this->settingsToRestore as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->settingsToRestore = [];
        parent::tearDown();
    }

    private function setting(string $key, int $value): void
    {
        $this->settingsToRestore[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [(string) $value, $key]);
    }

    /**
     * Two stores and their orders, all through the engine, placed in this order (order ids follow it):
     *   VPG V1998    1 x Alpha, paid and sent 40 days ago                       placed 40 days ago (moved back)
     *   VPG V1999    1 x Beta, reserved and freed by the store                  placed 10 days ago (moved back)
     *   VPG V2001    2 x Alpha, paid 30 hours ago, not sent                     -> waiting, late
     *   VPG V2002    1 x Alpha, paid now, not sent                              -> waiting, on time
     *   VPG V2003    2 x Alpha, paid, both sent today, one returned             -> sent today; c2 back in stock
     *   VPG V2004    1 x Alpha, paid, cancelled (restockable)                   -> back on sale
     *   VPG V2005    1 x Beta, paid, cancelled NOT restockable                  -> in VERIFY, waiting for a recount
     *   VPG V2006    1 x Beta in a checkout                                     -> reserved, not paid
     *   VPG V2007    1 x Beta reserved two hours ago, the expiry job freed it   -> ran out of time
     *   VPG V2008    1 x Alpha paid without a reservation                       -> waiting, came in unreserved
     *   VPG V2009    1 x Alpha, paid, sent, sending undone                      -> waiting again, undone before
     *   VPG V2010    1 x Beta in a checkout, cancelled before payment           -> cancelled while not paid
     *   VPG =SUM(A1) 1 unit of a variant not linked, paid, cancelled            -> no stock kept
     *   ALT A3001    3 x Alpha, paid, one sent today                            -> waiting (2 units), sent today (1)
     *
     * @return array<string, int> order reference => order id
     */
    private function orders(): array
    {
        $this->vpg = $this->site('vpg');
        $this->alt = $this->site('alt');
        $this->alpha = $this->item('strict', 50, 'Alpha liquid 10ml');
        $this->beta = $this->item('strict', 40, 'Beta pod 2ml');
        $this->profiled($this->vpg, 'A1', ['product_title' => 'Alpha', 'variant_title' => '10ml'], $this->alpha);
        $this->listing($this->vpg, 'B1', $this->beta);
        $this->profiled($this->vpg, 'X9', ['product_title' => 'Loose kit', 'variant_title' => 'Blue']);
        $this->listing($this->alt, 'A1', $this->alpha);
        $real = $this->real;
        $sentAt = self::sentAt($real);

        $this->now = $real->modify('-40 days');
        $this->paid($this->vpg, 'V1998', [self::line('A1', 'o1')]);
        $this->ok($this->ship($this->vpg, 'V1998', ['o1'], Clock::iso(Clock::db($this->now->modify('+1 minute')))));
        $this->now = $real;
        $this->ok($this->reserve($this->vpg, 'V1999', [self::line('B1', 'p1')]));
        $this->ok($this->release($this->vpg, 'V1999'));

        $this->now = $real->modify('-30 hours');
        $this->paid($this->vpg, 'V2001', [self::line('A1', 'a1', 'a2')]);
        $this->now = $real;
        $this->paid($this->vpg, 'V2002', [self::line('A1', 'b1')]);
        $this->paid($this->vpg, 'V2003', [self::line('A1', 'c1', 'c2')]);
        $this->ok($this->ship($this->vpg, 'V2003', ['c1', 'c2'], $sentAt));
        $this->ok($this->res->returnUnits($this->vpg, 'V2003', ['c2'], $this->key('return')));
        $this->paid($this->vpg, 'V2004', [self::line('A1', 'd1')]);
        $this->ok($this->res->cancel($this->vpg, 'V2004', ['d1'], true, $this->key('cancel')));
        $this->paid($this->vpg, 'V2005', [self::line('B1', 'e1')]);
        $this->ok($this->res->cancel($this->vpg, 'V2005', ['e1'], false, $this->key('cancel')));
        $this->ok($this->reserve($this->vpg, 'V2006', [self::line('B1', 'f1')]));
        $this->now = $real->modify('-2 hours');
        $this->ok($this->reserve($this->vpg, 'V2007', [self::line('B1', 'g1')]));
        $this->now = $real;
        self::assertSame(1, $this->res->expireDue(), 'the expiry job frees the hold nobody paid');
        $this->ok($this->commit($this->vpg, 'V2008', [self::line('A1', 'h1')], 'unreserved'));
        $this->paid($this->vpg, 'V2009', [self::line('A1', 'j1')]);
        $this->ok($this->ship($this->vpg, 'V2009', ['j1'], $sentAt));
        $this->ok($this->res->unship($this->vpg, 'V2009', ['j1'], null, $this->key('unship')));
        $this->ok($this->reserve($this->vpg, 'V2010', [self::line('B1', 'q1')]));
        $this->ok($this->res->cancel($this->vpg, 'V2010', ['q1'], true, $this->key('cancel')));
        $this->paid($this->vpg, '=SUM(A1)', [self::line('X9', 'k1')]);
        $this->ok($this->res->cancel($this->vpg, '=SUM(A1)', ['k1'], false, $this->key('cancel')));
        $this->paid($this->alt, 'A3001', [self::line('A1', 'x1', 'x2', 'x3')]);
        $this->ok($this->ship($this->alt, 'A3001', ['x1'], $sentAt));

        $ids = [];
        foreach (self::$db->all('SELECT id, order_ref FROM reservation ORDER BY id') as $r) {
            $ids[(string) $r['order_ref']] = (int) $r['id'];
        }
        self::assertCount(14, $ids);
        // The two oldest were placed long ago: the store's placing time, moved back (they are the lowest ids, as on a real store).
        self::$db->exec('UPDATE reservation SET created_at = ? WHERE id = ?', [Clock::db($real->modify('-40 days')), $ids['V1998']]);
        self::$db->exec('UPDATE reservation SET created_at = ? WHERE id = ?', [Clock::db($real->modify('-10 days')), $ids['V1999']]);
        return $ids;
    }

    /** Reserve, then pay. @param list<array<string, mixed>> $lines */
    private function paid(Caller $site, string $ref, array $lines): void
    {
        $this->ok($this->reserve($site, $ref, $lines));
        $this->ok($this->commit($site, $ref, $lines));
    }

    /** A dispatch time today in UK time and not after now: a minute ago, or midnight when that was yesterday. */
    private static function sentAt(\DateTimeImmutable $real): string
    {
        $today = Clock::fromDb(SalesViews::since($real)['today']);
        $t = $real->modify('-1 minute');
        return Clock::iso(Clock::db($t < $today ? $today : $t)) ?? '';
    }

    /** @param array<string, mixed> $query */
    private function page(KernelBrowser $web, string $path, array $query = []): UiResponse
    {
        $r = $web->send('GET', $path, $query, []);
        self::assertSame(200, $r->status, $path . ' ' . json_encode($query) . ' ' . $r->describe());
        return $r;
    }

    /** @return list<string> the first cells (order references or names) of a table's rows, in order */
    private static function refs(UiResponse $r, string $table): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//table[contains(@class, "' . $table . '")]/tbody/tr/th')));
    }

    /** The text of one row of a table whose first cell is $first, white space collapsed. */
    private static function rowOf(UiResponse $r, string $table, string $first): string
    {
        foreach ((new \DOMXPath($r->dom()))->query('//table[contains(@class, "' . $table . '")]/tbody/tr') as $tr) {
            $th = $tr->getElementsByTagName('th')->item(0);
            if ($th !== null && str_starts_with(trim((string) $th->textContent), $first)) {
                return trim((string) preg_replace('/\s+/u', ' ', (string) $tr->textContent));
            }
        }
        return '';
    }

    /** @return list<string> the figures over a list */
    private static function figures(UiResponse $r): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//main//li[@class="kpi"]/p[@class="kpi-value"]')));
    }

    /** The store selector: label, link, count, current. @return list<array{0: string, 1: string, 2: int, 3: bool}> */
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

    /** @return list<string> the pager's links */
    private static function pager(UiResponse $r): array
    {
        return array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//main//p[@class="pager"]/a')));
    }

    /** Opens a link of a page (its path and query). */
    private function open(KernelBrowser $web, string $href): UiResponse
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        return $this->page($web, (string) parse_url($href, PHP_URL_PATH), $query);
    }

    // ------------------------------------------------------------------------------------------
    // The tab and who may open it
    // ------------------------------------------------------------------------------------------

    public function testTheTabItsSegmentsAndWhoMayOpenIt(): void
    {
        $ids = $this->orders();
        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $this->page($web, self::ORDERS);
        self::assertSame(['Stock', 'Sales'], [self::currentSection($page), self::currentTab($page)]);
        self::assertSame(['Overview', 'Movements', 'Stock In', 'Stock Out', 'Hand-overs', 'Transfers', 'Adjustments', 'Counts', 'Reservations', 'Sales', 'Quality',
            'Repack'], self::tabLabels($page), 'Sales sits after Reservations (Repack, pack B, last)');
        self::assertSame([[Words::SEGMENT['sales_orders'], self::ORDERS, true], [Words::SEGMENT['sales_dispatch'], self::DISPATCH, false],
            [Words::SEGMENT['sales_returns'], self::RETURNS, false]],
            array_map(static fn (array $s): array => [$s['label'], $s['href'], $s['current']], self::segments($page)));
        $xp = new \DOMXPath($page->dom());
        self::assertSame(Words::MENU['sales_orders'], trim((string) $xp->evaluate('string(//main//h1)')));
        self::assertStringStartsWith(Words::PAGE_INTRO['sales_orders'][0], trim((string) $xp->evaluate('string(//main//p[@class="lede"])')));
        // The store chosen travels through the segments.
        $alt = $this->page($web, self::ORDERS, ['channel' => 'alt']);
        self::assertSame([self::ORDERS . '?channel=alt', self::DISPATCH . '?channel=alt', self::RETURNS . '?channel=alt'], array_column(self::segments($alt), 'href'));
        foreach ([[self::DISPATCH, 'sales_dispatch'], [self::RETURNS, 'sales_returns'], [self::ORDERS . '/' . $ids['V2003'], 'sales_orders']] as [$path, $segment]) {
            $p = $this->page($web, $path);
            self::assertSame(['Stock', 'Sales'], [self::currentSection($p), self::currentTab($p)], $path);
            self::assertSame([Words::SEGMENT[$segment]], array_column(array_filter(self::segments($p), static fn (array $s): bool => $s['current']), 'label'), $path);
        }
        // The Dashboard lists the tab with its line.
        $home = $web->get('/ui/');
        self::assertContains(self::ORDERS, $home->hrefs());
        self::assertStringContainsString(Words::MENU_HELP['sales'], $home->text());

        // Who may open it: the people who look at sales (sales.view); not the matching jobs and not admin.
        $paths = [self::ORDERS, self::ORDERS . '.csv', self::ORDERS . '/' . $ids['V2003'], self::DISPATCH, self::RETURNS, self::RETURNS . '.csv'];
        foreach (['viewer', 'warehouse', 'manager', 'buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'accountant', 'auditor'] as $role) {
            $w = $this->signIn($this->uiUser($role));
            foreach ($paths as $path) {
                self::assertSame(200, $w->get($path)->status, "{$role} {$path}");
            }
            self::assertContains('Sales', self::tabLabels($w->get('/ui/stock')), $role);
        }
        foreach (['mapper', 'mapping_lead', 'admin'] as $role) {
            $w = $this->signIn($this->uiUser($role));
            foreach ($paths as $path) {
                $refused = $w->get($path);
                self::assertSame(403, $refused->status, "{$role} {$path}");
                self::assertSame('role_not_allowed', $refused->errorCode());
                self::assertStringNotContainsString('V2003', $refused->body, 'nothing of the lists reaches a person without the permission');
            }
            self::assertNotContains('Sales', self::tabLabels($w->get('/ui/stock')), "{$role}: no tab it cannot open");
        }
        self::assertSame(303, $this->browser()->get(self::ORDERS)->status, 'signed out: to the sign-in page');
        // Read only: no form posts, and nothing can be sent to any of its addresses.
        foreach ($paths as $path) {
            $p = $web->get($path);
            if (!str_ends_with($path, '.csv')) {
                self::assertSame(0, (new \DOMXPath($p->dom()))->query('//main//form[translate(@method, "POST", "post") = "post"]')->length, "{$path}: read only");
            }
            self::assertSame(405, $web->post($path, ['csrf' => $this->token($web)])->status, "POST {$path}");
        }
        self::assertSame(405, $web->post(self::DISPATCH, ['csrf' => 'wrong'])->status, 'a POST is refused whatever its token');
    }

    // ------------------------------------------------------------------------------------------
    // Store orders
    // ------------------------------------------------------------------------------------------

    public function testStoreOrdersFilterByStoreStateWayInAndDays(): void
    {
        $this->orders();
        $web = $this->signIn($this->uiUser('buyer'));
        $all = $this->page($web, self::ORDERS);
        // The last 7 days (the setting), newest first: not V1999 (10 days ago) nor V1998 (40 days ago).
        $recent = ['A3001', '=SUM(A1)', 'V2010', 'V2009', 'V2008', 'V2007', 'V2006', 'V2005', 'V2004', 'V2003', 'V2002', 'V2001'];
        self::assertSame($recent, self::refs($all, 'sales-orders'));
        self::assertStringContainsString(Words::say('SALE', 'period_default', 7), $all->text());
        // One row: the store, the label, placed, paid, how it came in, the units, what became of it.
        $row = self::rowOf($all, 'sales-orders', 'V2003');
        self::assertStringContainsString('VPG test site ' . Words::RESV_CHIP['closed'], $row);
        self::assertStringContainsString(Words::SALE_ORIGIN['reserved'] . ' 2 ' . Words::say('RESV', 'out_part_shipped', 1) . ' · ' . Words::say('RESV', 'out_part_returned', 1), $row);
        self::assertStringContainsString(Words::SALE_ORIGIN['unreserved'], self::rowOf($all, 'sales-orders', 'V2008'));
        self::assertStringContainsString(Words::RESV['unlinked_one'], self::rowOf($all, 'sales-orders', '=SUM(A1)'));
        self::assertStringNotContainsString('UTC', $all->text(), 'UK time');
        // The store selector: All stores, then each store, with its paid orders waiting to ship (VPG: V2001, V2002, V2008, V2009).
        self::assertSame([[Words::BULK['all_stores'], self::ORDERS, 5, true], ['ALT test site', self::ORDERS . '?channel=alt', 1, false],
            ['VPG test site', self::ORDERS . '?channel=vpg', 4, false]], self::storeSeg($all));
        self::assertSame(['A3001'], self::refs($this->page($web, self::ORDERS, ['channel' => 'alt']), 'sales-orders'));
        $vpg = $this->page($web, self::ORDERS, ['channel' => 'vpg']);
        self::assertSame(array_slice($recent, 1), self::refs($vpg, 'sales-orders'));

        // Each state: the engine's order states, and the orders with a unit sent, cancelled or returned.
        $expect = [
            'held' => ['V2010', 'V2006'],
            'paid' => ['A3001', '=SUM(A1)', 'V2009', 'V2008', 'V2005', 'V2004', 'V2003', 'V2002', 'V2001'],
            'shipped' => ['A3001', 'V2003'],
            'cancelled' => ['=SUM(A1)', 'V2010', 'V2005', 'V2004'],
            'returned' => ['V2003'],
            'released' => [],
            'expired' => ['V2007'],
        ];
        self::assertSame(SalesViews::STATES, array_keys($expect));
        foreach ($expect as $state => $refs) {
            self::assertSame($refs, self::refs($this->page($web, self::ORDERS, ['state' => $state]), 'sales-orders'), $state);
            self::assertSame(array_values(array_diff($refs, ['A3001'])), self::refs($this->page($web, self::ORDERS, ['state' => $state, 'channel' => 'vpg']), 'sales-orders'), "vpg {$state}");
        }
        self::assertSame(['V1999'], self::refs($this->page($web, self::ORDERS, ['state' => 'released', 'period' => 'all']), 'sales-orders'));
        self::assertSame(['A3001', 'V2003', 'V1998'], self::refs($this->page($web, self::ORDERS, ['state' => 'shipped', 'period' => 'all']), 'sales-orders'));
        // The way in, alone and with a state.
        self::assertSame(['V2008'], self::refs($this->page($web, self::ORDERS, ['origin' => 'unreserved']), 'sales-orders'));
        self::assertSame(['V2008'], self::refs($this->page($web, self::ORDERS, ['origin' => 'unreserved', 'state' => 'paid']), 'sales-orders'));
        self::assertSame([], self::refs($this->page($web, self::ORDERS, ['origin' => 'unreserved', 'state' => 'cancelled']), 'sales-orders'));
        $none = $this->page($web, self::ORDERS, ['origin' => 'opening']);
        self::assertStringContainsString(Words::SALE['none_filtered'], $none->text());
        self::assertContains(self::ORDERS, $none->hrefs(), 'Clear the filters');

        // Days: any time; days of one's own (UK days, both included); a store's list keeps them.
        $any = $this->page($web, self::ORDERS, ['period' => 'all']);
        self::assertSame([...$recent, 'V1999', 'V1998'], self::refs($any, 'sales-orders'));
        self::assertStringContainsString(Words::SALE['period_all'], $any->text());
        $uk = $this->real->setTimezone(new \DateTimeZone('Europe/London'));
        $range = ['from' => $uk->modify('-12 days')->format('Y-m-d'), 'to' => $uk->modify('-8 days')->format('Y-m-d')];
        $ten = $this->page($web, self::ORDERS, $range);
        self::assertSame(['V1999'], self::refs($ten, 'sales-orders'));
        self::assertStringContainsString(Words::say('SALE', 'period_range', \CW\Ui\Html::day($range['from']), \CW\Ui\Html::day($range['to'])), $ten->text());
        self::assertSame(self::ORDERS . '?from=' . $range['from'] . '&to=' . $range['to'] . '&channel=vpg', self::storeSeg($ten)[2][1]);
        self::assertSame([], self::refs($this->page($web, self::ORDERS, $range + ['state' => 'shipped']), 'sales-orders'));
        $old = ['from' => $uk->modify('-41 days')->format('Y-m-d'), 'to' => $uk->modify('-39 days')->format('Y-m-d')];
        self::assertSame(['V1998'], self::refs($this->page($web, self::ORDERS, $old + ['state' => 'shipped']), 'sales-orders'), 'a filter on the units, within days');
        self::assertSame(['V1998'], self::refs($this->page($web, self::ORDERS, $old), 'sales-orders'));
        self::assertSame($recent, self::refs($this->page($web, self::ORDERS, ['from' => $uk->format('Y-m-d')]), 'sales-orders'), 'from today on');
        self::assertSame(['V1999', 'V1998'], self::refs($this->page($web, self::ORDERS, ['to' => $uk->modify('-1 day')->format('Y-m-d')]), 'sales-orders'), 'until yesterday');
        // A day that is not a real one, or the first after the last: the default days, and the page says so.
        foreach ([['from' => '2026-02-31'], ['from' => $range['to'], 'to' => $range['from']], ['to' => 'yesterday']] as $bad) {
            $p = $this->page($web, self::ORDERS, $bad);
            self::assertSame($recent, self::refs($p, 'sales-orders'), json_encode($bad));
            self::assertStringContainsString(Words::say('SALE', 'bad_dates', 7), $p->text());
        }

        // The days the list opens with are a setting.
        $this->setting('sales.default_days', 30);
        $month = $this->page($web, self::ORDERS);
        self::assertSame([...$recent, 'V1999'], self::refs($month, 'sales-orders'));
        self::assertStringContainsString(Words::say('SALE', 'period_default', 30), $month->text());
    }

    public function testOlderPagesAreReadByKeyAndThePageSizeIsTheSetting(): void
    {
        $vpg = $this->site('vpg');
        $this->listing($vpg, 'X9', null);
        $this->setting('sales.page_size', 25);
        $n = 27;
        for ($i = 1; $i <= $n; $i++) {
            $this->ok($this->reserve($vpg, 'p' . $i, [self::line('X9', 'pu' . $i)]));
            if ($i % 2 === 0) {
                $this->ok($this->commit($vpg, 'p' . $i, [self::line('X9', 'pu' . $i)]));
                $this->ok($this->res->cancel($vpg, 'p' . $i, ['pu' . $i], true, $this->key('cancel')));
            }
        }
        $web = $this->signIn($this->uiUser('viewer'));
        foreach ([[], ['channel' => 'vpg'], ['state' => 'held']] as $query) {
            $label = json_encode($query);
            $first = $this->page($web, self::ORDERS, $query);
            $want = array_values(array_filter(array_map(static fn (int $i): string => 'p' . $i, range($n, 1)),
                static fn (string $r): bool => ($query['state'] ?? null) !== 'held' || (int) substr($r, 1) % 2 === 1));
            self::assertSame(array_slice($want, 0, 25), self::refs($first, 'sales-orders'), $label);
            if (count($want) <= 25) {
                self::assertSame([], self::pager($first), $label);
                continue;
            }
            $older = self::pager($first);
            self::assertCount(1, $older, $label);
            $second = $this->open($web, $older[0]);
            self::assertSame(array_slice($want, 25), self::refs($second, 'sales-orders'), $label);
            self::assertSame([self::ORDERS . ($query === [] ? '' : '?' . http_build_query($query))], self::pager($second), 'back to the newest');
        }
        // A filter on the units: the page fills from the unit index (13 orders with a unit cancelled: p26 ... p2).
        $cancelled = $this->page($web, self::ORDERS, ['state' => 'cancelled']);
        self::assertSame(array_map(static fn (int $i): string => 'p' . $i, range(26, 2, -2)), self::refs($cancelled, 'sales-orders'));
        // The read stops at its limit on a filter it cannot find directly, says so, and reads on from where it stopped.
        $views = new SalesViews(self::$db);
        $stores = [(int) $vpg->channelId];
        $f = ['channel' => null, 'state' => 'cancelled', 'origin' => 'unreserved', 'from' => null, 'to' => null, 'before' => null];
        $stopped = $views->orderIds($f, $stores, 25, 5);
        self::assertSame([[], 5], [$stopped['ids'], $stopped['read']]);
        self::assertSame((int) self::$db->value("SELECT id FROM reservation WHERE order_ref = 'p18'"), $stopped['stopped'], 'the fifth order read');
        $rest = $views->orderIds(['before' => $stopped['stopped']] + $f, $stores, 25, 100);
        self::assertSame([[], null, null], [$rest['ids'], $rest['next'], $rest['stopped']], 'read to the end: nothing more');
        $f['origin'] = 'reserved';
        self::assertSame(13, count($views->orderIds($f, $stores, 25, 100)['ids']));
        // The file is read by key in batches, up to its limit, and says when there is more.
        $all = $views->exportOrders(['state' => null] + $f, $stores, 100, 100, 7);
        self::assertSame([$n, false], [count($all['rows']), $all['more']]);
        $cut = $views->exportOrders(['state' => null] + $f, $stores, 10, 100, 4);
        self::assertSame([10, true, 'p27', 'p18'], [count($cut['rows']), $cut['more'], $cut['rows'][0]['ref'], $cut['rows'][9]['ref']]);
        self::assertSame($n + 1, substr_count($web->get(self::ORDERS . '.csv', ['period' => 'all'])->body, "\r\n"), 'header and every order');
    }

    public function testStoreOrdersAsACsvAreTheFilteredListAndSafeForExcel(): void
    {
        $this->orders();
        $web = $this->signIn($this->uiUser('auditor'));
        self::assertContains(self::ORDERS . '.csv?channel=vpg&state=cancelled', $this->page($web, self::ORDERS, ['channel' => 'vpg', 'state' => 'cancelled'])->hrefs());
        $csv = $web->get(self::ORDERS . '.csv');
        self::assertSame(200, $csv->status, $csv->describe());
        self::assertSame('text/csv; charset=utf-8', $csv->header('content-type'));
        self::assertSame("attachment; filename=\"store-orders.csv\"; filename*=UTF-8''store-orders.csv", $csv->header('content-disposition'));
        self::assertSame(['sandbox', Kernel::CSP], $csv->headerValues('content-security-policy'));
        self::assertStringStartsWith(CsvWriter::BOM . '"store","order_reference","state","what_became_of_it","how_it_came_in","products","units","items_not_linked",'
            . '"placed_utc","paid_utc","ended_utc","tries"' . "\r\n", $csv->body);
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\r\n", substr($csv->body, strlen(CsvWriter::BOM))))));
        self::assertSame(['order_reference', 'A3001', "'=SUM(A1)", 'V2010', 'V2009', 'V2008', 'V2007', 'V2006', 'V2005', 'V2004', 'V2003', 'V2002', 'V2001'],
            array_column($rows, 1), 'the page\'s days and order; a reference a spreadsheet would run is text');
        self::assertStringContainsString('"VPG test site","V2008","' . Words::RESV_STATE['committed'] . '","' . Words::RESV['out_all_allocated'] . '","'
            . Words::SALE_ORIGIN['unreserved'] . '",1,1,0,', $csv->body);
        self::assertSame(['order_reference', "'=SUM(A1)", 'V2010', 'V2005', 'V2004'], array_column(array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''),
            array_values(array_filter(explode("\r\n", substr($web->get(self::ORDERS . '.csv', ['channel' => 'vpg', 'state' => 'cancelled', 'origin' => 'reserved'])->body,
            strlen(CsvWriter::BOM)))))), 1), 'the same filters as the page: the orders with a unit cancelled that were reserved first (V2010 is not paid yet)');
        self::assertSame(1, substr_count($web->get(self::ORDERS . '.csv', ['state' => 'cancelled', 'origin' => 'unreserved'])->body, "\r\n"), 'only the header');
    }

    // ------------------------------------------------------------------------------------------
    // One order
    // ------------------------------------------------------------------------------------------

    public function testOneOrderShowsEachUnitItsItemItsVariantAndItsStockChanges(): void
    {
        $ids = $this->orders();
        $web = $this->signIn($this->uiUser('accountant'));
        self::assertContains(self::ORDERS . '/' . $ids['V2003'], $this->page($web, self::ORDERS)->hrefs(), 'a row opens its order');
        $page = $this->page($web, self::ORDERS . '/' . $ids['V2003']);
        $xp = new \DOMXPath($page->dom());
        self::assertSame(Words::say('SALE_ORDER', 'title', 'V2003') . ' ' . Words::RESV_CHIP['closed'], trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(//main//h1)'))));
        self::assertSame(self::ORDERS . '?channel=vpg', $xp->query('//main//p[@class="crumbs"]/a')->item(0)?->getAttribute('href'), 'back to the list, at its store');
        $facts = [];
        foreach ($xp->query('//main//dl/dt') as $dt) {
            $facts[trim((string) $dt->textContent)] = trim((string) $xp->evaluate('string(following-sibling::dd[1])', $dt));
        }
        self::assertSame('VPG test site', $facts[Words::SALE_ORDER['f_store']]);
        self::assertSame(Words::RESV_ORIGIN['reserved'], $facts[Words::SALE_ORDER['f_origin']]);
        self::assertSame(['1', '2'], [$facts[Words::SALE_ORDER['f_products']], $facts[Words::SALE_ORDER['f_units']]]);
        self::assertArrayNotHasKey(Words::SALE_ORDER['f_waiting'], $facts, 'nothing waits to ship');
        self::assertContains('/ui/stock/reservations/' . $ids['V2003'], $page->hrefs(), 'the stock kept for it');
        // The sold units: the warehouse item (a link to its card), the unit number, the store variant, the warehouse, state, when sent.
        $a = sprintf('CW-%06d', $this->alpha);
        $main = (string) self::$db->value("SELECT name FROM warehouse WHERE code = 'MAIN'");
        self::assertSame(["Alpha liquid 10ml{$a}", "Alpha liquid 10ml{$a}"], self::refs($page, 'sales-units'));
        self::assertStringContainsString(' c1 ' . Words::say('SALE_ORDER', 'u_variant_text', 'A1') . " · Alpha 10ml {$main} " . Words::RESV_UNIT['shipped'] . ' ',
            self::rowOf($page, 'sales-units', 'Alpha'));
        $second = trim((string) preg_replace('/\s+/u', ' ', (string) $xp->query('//table[contains(@class, "sales-units")]/tbody/tr')->item(1)?->textContent));
        self::assertStringContainsString(' c2 ', $second);
        self::assertStringContainsString(Words::RESV_UNIT['returned'], $second);
        self::assertSame(['/ui/items/' . $this->alpha, '/ui/items/' . $this->alpha], array_map(static fn (\DOMElement $el): string => $el->getAttribute('href'),
            iterator_to_array($xp->query('//table[contains(@class, "sales-units")]/tbody/tr/th/a'))));
        // Each unit's stock changes, oldest first, each opening Movements at it (the item, every figure, from that row).
        $steps = [];
        foreach ($xp->query('//table[contains(@class, "sales-steps")]') as $t) {
            $steps[] = array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent), iterator_to_array($xp->query('tbody/tr/th', $t)));
        }
        self::assertSame([[Words::MOVEMENT['reserve'], Words::MOVEMENT['commit'], Words::MOVEMENT['ship']],
            [Words::MOVEMENT['reserve'], Words::MOVEMENT['commit'], Words::MOVEMENT['ship'], Words::MOVEMENT['return']]], $steps);
        self::assertStringContainsString(Words::STOCK['allocated'] . ' −1 · ' . Words::STOCK['on_hand'] . ' −1', $page->text(), 'the ship of one unit');
        $open = array_values(array_filter($page->hrefs(), static fn (string $h): bool => str_starts_with($h, '/ui/stock/movements?')));
        self::assertCount(7, $open);
        $lastShip = (int) self::$db->value("SELECT MAX(id) FROM stock_ledger WHERE unit_id = 'c1' AND movement_type = 'ship'");
        self::assertSame('/ui/stock/movements?q=' . $a . '&figure=all&before=' . ($lastShip + 1), $open[2]);
        $moves = $this->open($web, $open[2]);
        self::assertStringContainsString('V2003', $moves->text(), 'Movements opens at the step');
        // A unit not linked at the sale: named as the store names it, no stock, no steps.
        $loose = $this->page($web, self::ORDERS . '/' . $ids['=SUM(A1)']);
        self::assertStringContainsString('Loose kit Blue' . Words::SALE_ORDER['u_unlinked'], $loose->text());
        self::assertStringContainsString(Words::SALE_ORDER['steps_unlinked'], $loose->text());
        // A late order says how long it waits.
        $late = new \DOMXPath($this->page($web, self::ORDERS . '/' . $ids['V2001'])->dom());
        self::assertSame(Words::say('DISPATCH', 'hours_many', 30) . ' (' . Words::SALE_ORDER['late'] . ')',
            trim((string) $late->evaluate('string(//main//dl/dt[. = "' . Words::SALE_ORDER['f_waiting'] . '"]/following-sibling::dd[1])')));
        // An order that does not exist, and nonsense.
        $missing = $web->get(self::ORDERS . '/999999');
        self::assertSame([404, 'not_found'], [$missing->status, $missing->errorCode()]);
        self::assertContains(self::ORDERS, $missing->hrefs(), 'a way back to the list');
        foreach (['/0', '/abc', '/-1', '/' . $ids['V2003'] . '/edit', '/999999999999999999999'] as $tail) {
            self::assertSame(404, $web->get(self::ORDERS . $tail)->status, $tail);
        }
    }

    public function testAnOrderWithManyUnitsPagesThemByTheStoresUnitNumber(): void
    {
        $vpg = $this->site('vpg');
        $this->listing($vpg, 'X9', null);
        $this->setting('sales.page_size', 25);
        $units = array_map(static fn (int $i): string => sprintf('u%02d', $i), range(1, 27));
        $this->ok($this->reserve($vpg, 'BIG', [self::line('X9', ...$units)]));
        $id = (int) self::$db->value("SELECT id FROM reservation WHERE order_ref = 'BIG'");
        $web = $this->signIn($this->uiUser('reviewer'));
        $first = $this->page($web, self::ORDERS . '/' . $id);
        $unitCells = static fn (UiResponse $r): array => array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//table[contains(@class, "sales-units")]/tbody/tr/td[1]')));
        self::assertSame(array_slice($units, 0, 25), $unitCells($first));
        self::assertSame([self::ORDERS . '/' . $id . '?unit=u25'], self::pager($first));
        $second = $this->open($web, self::pager($first)[0]);
        self::assertSame(['u26', 'u27'], $unitCells($second));
        self::assertSame([self::ORDERS . '/' . $id], self::pager($second), 'back to the first units');
        self::assertSame(array_slice($units, 0, 25), $unitCells($this->page($web, self::ORDERS . '/' . $id, ['unit' => "x\ny"])), 'nonsense: the first page');
    }

    // ------------------------------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------------------------------

    public function testDispatchListsWhatWaitsOldestFirstWithTheLateHoursOfTheSetting(): void
    {
        $ids = $this->orders();
        $web = $this->signIn($this->uiUser('warehouse'));
        $page = $this->page($web, self::DISPATCH);
        // Waiting to ship, the late ones (paid more than 24 hours ago), sent today, sent this week, sending undone this week.
        self::assertSame(['5', '1', '2', '2', '1'], self::figures($page));
        self::assertStringContainsString(Words::say('DISPATCH', 'tile_waiting_sub', 7), $page->text());
        self::assertStringContainsString(Words::say('DISPATCH', 'tile_late_sub', 24), $page->text());
        self::assertStringContainsString(Words::say('DISPATCH', 'tile_today_sub', 3), $page->text(), 'V2003 (one since returned) and A3001');
        self::assertSame(['V2001', 'V2002', 'V2008', 'V2009', 'A3001'], self::refs($page, 'dispatch-waiting'), 'oldest order first');
        $late = self::rowOf($page, 'dispatch-waiting', 'V2001');
        self::assertStringContainsString(Words::say('DISPATCH', 'hours_many', 30) . ' ' . Words::DISPATCH_CHIP['late'] . ' 2', $late);
        self::assertStringContainsString(Words::DISPATCH['under_hour'] . ' ' . Words::DISPATCH_CHIP['on_time'] . ' 1', self::rowOf($page, 'dispatch-waiting', 'V2002'));
        self::assertStringContainsString(Words::DISPATCH['was_undone'], self::rowOf($page, 'dispatch-waiting', 'V2009'));
        self::assertStringContainsString(Words::DISPATCH_CHIP['on_time'] . ' 2', self::rowOf($page, 'dispatch-waiting', 'A3001'), 'two of its three units wait');
        self::assertContains(self::ORDERS . '/' . $ids['V2001'], $page->hrefs());
        self::assertSame([[Words::BULK['all_stores'], self::DISPATCH, 5, true], ['ALT test site', self::DISPATCH . '?channel=alt', 1, false],
            ['VPG test site', self::DISPATCH . '?channel=vpg', 4, false]], self::storeSeg($page));
        $alt = $this->page($web, self::DISPATCH, ['channel' => 'alt']);
        self::assertSame(['1', '0', '1', '1', '0'], self::figures($alt));
        self::assertSame(['A3001'], self::refs($alt, 'dispatch-waiting'));

        // The late hours are a setting, read on every page.
        $this->setting('sales.late_hours', 48);
        $later = $this->page($web, self::DISPATCH);
        self::assertSame('0', self::figures($later)[1]);
        self::assertStringContainsString(Words::DISPATCH_CHIP['on_time'], self::rowOf($later, 'dispatch-waiting', 'V2001'));
        self::assertStringContainsString(Words::say('DISPATCH', 'tile_late_sub', 48), $later->text());
        $this->setting('sales.late_hours', 1);
        self::assertSame('1', self::figures($this->page($web, self::DISPATCH))[1], 'paid under an hour ago: not late after 1 hour');
        $order = $this->page($web, self::ORDERS . '/' . $ids['V2002']);
        self::assertStringContainsString(Words::DISPATCH['under_hour'], $order->text());
        self::assertStringNotContainsString('(' . Words::SALE_ORDER['late'] . ')', $order->text());

        // Sent today and this week: newest sending first (A3001 and V2003 were sent at the same time: the newer order first).
        $today = $this->page($web, self::DISPATCH, ['show' => 'today']);
        self::assertSame(['A3001', 'V2003'], self::refs($today, 'dispatch-sent'));
        self::assertStringContainsString(Words::say('RESV', 'out_part_shipped', 1) . ' · ' . Words::say('RESV', 'out_part_returned', 1), self::rowOf($today, 'dispatch-sent', 'V2003'));
        self::assertSame(['A3001', 'V2003'], self::refs($this->page($web, self::DISPATCH, ['show' => 'week']), 'dispatch-sent'));
        self::assertSame(self::DISPATCH . '?show=today&channel=vpg', self::storeSeg($today)[2][1], 'the store selector keeps the list');
        self::assertSame(['V2003'], self::refs($this->page($web, self::DISPATCH, ['show' => 'today', 'channel' => 'vpg']), 'dispatch-sent'));
        // Sending undone: one row per unit, with who and what it is now; units not linked are not in the ledger, and the page says so.
        $undone = $this->page($web, self::DISPATCH, ['show' => 'undone']);
        self::assertSame(['V2009'], self::refs($undone, 'dispatch-undone'));
        self::assertStringContainsString('VPG test site j1 ', self::rowOf($undone, 'dispatch-undone', 'V2009'));
        self::assertStringEndsWith('VPG test site ' . Words::RESV_UNIT['allocated'], self::rowOf($undone, 'dispatch-undone', 'V2009'));
        self::assertStringContainsString(Words::DISPATCH['undone_unlinked'], $undone->text());
        self::assertStringContainsString(Words::DISPATCH['none_undone'], $this->page($web, self::DISPATCH, ['show' => 'undone', 'channel' => 'alt'])->text());

        // Paging by key: oldest first after an order; sent lists before an order.
        $this->setting('sales.page_size', 25);
        $views = new SalesViews(self::$db);
        $stores = array_map('intval', self::$db->column('SELECT id FROM channel ORDER BY id'));
        $two = $views->waiting($stores, null, 2);
        self::assertSame([[$ids['V2001'], $ids['V2002']], $ids['V2002']], [$two['ids'], $two['next']]);
        self::assertSame([$ids['V2008'], $ids['V2009']], $views->waiting($stores, $two['next'], 2)['ids']);
        $since = SalesViews::since($this->real)['today'];
        $one = $views->sent($stores, $since, null, 1);
        self::assertSame([[$ids['A3001']], $ids['A3001']], [array_column($one['rows'], 'id'), $one['next']]);
        self::assertSame([$ids['V2003']], array_column($views->sent($stores, $since, $one['next'], 1)['rows'], 'id'));
        self::assertSame([], $views->sent($stores, $since, 999999, 1)['rows'], 'an order not in the list has nothing after it');
    }

    /** The day and the week Dispatch counts from: UK midnight, and Monday's (summer and winter time, a Monday, a Sunday). */
    public function testTodayAndThisWeekAreUkDaysFromMonday(): void
    {
        $at = static fn (string $utc): \DateTimeImmutable => new \DateTimeImmutable($utc, Clock::utc());
        self::assertSame(['today' => '2026-10-13 23:00:00.000000', 'week' => '2026-10-11 23:00:00.000000'], SalesViews::since($at('2026-10-14 10:00:00')), 'a Wednesday, summer time');
        self::assertSame(['today' => '2026-10-11 23:00:00.000000', 'week' => '2026-10-11 23:00:00.000000'], SalesViews::since($at('2026-10-12 08:00:00')), 'a Monday');
        self::assertSame(['today' => '2026-10-17 23:00:00.000000', 'week' => '2026-10-11 23:00:00.000000'], SalesViews::since($at('2026-10-18 20:00:00')), 'a Sunday');
        self::assertSame(['today' => '2026-12-02 00:00:00.000000', 'week' => '2026-11-30 00:00:00.000000'], SalesViews::since($at('2026-12-02 00:30:00')), 'winter time');
        self::assertSame(['today' => '2026-10-13 23:00:00.000000', 'week' => '2026-10-11 23:00:00.000000'], SalesViews::since($at('2026-10-13 23:30:00')), 'after UK midnight, before UTC midnight');
        // The days of Store orders: the default, a range (both days included), any time, and what is not a day.
        $now = $at('2026-10-14 10:00:00');
        self::assertSame(['kind' => 'default', 'from' => '2026-10-07 23:00:00.000000', 'to' => null, 'from_day' => '2026-10-08', 'to_day' => '2026-10-14', 'bad' => false],
            SalesViews::period(null, null, false, 7, $now));
        self::assertSame(['kind' => 'range', 'from' => '2026-09-30 23:00:00.000000', 'to' => '2026-10-02 23:00:00.000000', 'from_day' => '2026-10-01', 'to_day' => '2026-10-02',
            'bad' => false], SalesViews::period('2026-10-01', '2026-10-02', false, 7, $now));
        self::assertSame(['kind' => 'range', 'from' => null, 'to' => '2026-11-01 00:00:00.000000', 'from_day' => null, 'to_day' => '2026-10-31', 'bad' => false],
            SalesViews::period('', '2026-10-31', false, 7, $now), 'the last day of summer time ends at midnight GMT');
        self::assertSame('all', SalesViews::period(null, null, true, 7, $now)['kind']);
        self::assertSame('range', SalesViews::period('2026-10-01', null, true, 7, $now)['kind'], 'days typed win over any time');
        foreach ([['2026-02-30', null], ['2026-10-02', '2026-10-01'], [null, '14/10/2026'], ['2026-1-1', null]] as [$from, $to]) {
            $p = SalesViews::period($from, $to, true, 7, $now);
            self::assertSame(['default', true], [$p['kind'], $p['bad']], json_encode([$from, $to]));
        }
        self::assertSame(['default', '2026-10-14'], [SalesViews::period(null, null, false, 1, $now)['kind'], SalesViews::period(null, null, false, 1, $now)['from_day']]);
    }

    // ------------------------------------------------------------------------------------------
    // Returns
    // ------------------------------------------------------------------------------------------

    public function testReturnsListEachUnitWithWhereItsStockIsNow(): void
    {
        $ids = $this->orders();
        $web = $this->signIn($this->uiUser('purchasing_desk'));
        $page = $this->page($web, self::RETURNS);
        $main = (string) self::$db->value("SELECT name FROM warehouse WHERE code = 'MAIN'");
        $verify = (string) self::$db->value("SELECT name FROM warehouse WHERE code = 'VERIFY'");
        // Newest order first: what happened, back on sale or not, and where the stock is now.
        $units = [];
        foreach ((new \DOMXPath($page->dom()))->query('//table[contains(@class, "sales-returns")]/tbody/tr') as $tr) {
            $units[] = trim((string) preg_replace('/\s+/u', ' ', (string) $tr->textContent));
        }
        $a = sprintf('CW-%06d', $this->alpha);
        $b = sprintf('CW-%06d', $this->beta);
        self::assertCount(5, $units);
        self::assertStringStartsWith('Loose kit Blue' . Words::SALE_ORDER['u_unlinked'] . ' =SUM(A1) VPG test site k1 ' . Words::SALE_RETURN_KIND['cancelled'], $units[0]);
        self::assertStringEndsWith(Words::SALE_RESTOCK['none'] . ' ' . Words::SALE_RESTOCK_TEXT['unlinked'], $units[0]);
        self::assertStringStartsWith("Beta pod 2ml{$b} V2010 VPG test site q1 " . Words::SALE_RETURN_KIND['cancelled'], $units[1]);
        self::assertStringEndsWith(Words::SALE_RESTOCK['yes'] . ' ' . sprintf(Words::SALE_RESTOCK_TEXT['cancelled_held'], $main), $units[1]);
        self::assertStringStartsWith("Beta pod 2ml{$b} V2005 VPG test site e1 ", $units[2]);
        self::assertStringEndsWith(Words::SALE_RESTOCK['no'] . ' ' . sprintf(Words::SALE_RESTOCK_TEXT['verify_open'], $verify), $units[2]);
        self::assertStringStartsWith("Alpha liquid 10ml{$a} V2004 VPG test site d1 ", $units[3]);
        self::assertStringEndsWith(Words::SALE_RESTOCK['yes'] . ' ' . sprintf(Words::SALE_RESTOCK_TEXT['cancelled'], $main), $units[3]);
        self::assertStringStartsWith("Alpha liquid 10ml{$a} V2003 VPG test site c2 " . Words::SALE_RETURN_KIND['returned'], $units[4]);
        self::assertStringEndsWith(Words::SALE_RESTOCK['yes'] . ' ' . sprintf(Words::SALE_RESTOCK_TEXT['returned'], $main), $units[4]);
        self::assertContains(self::ORDERS . '/' . $ids['V2005'], $page->hrefs(), 'a unit opens its order');
        // One figure: the cancelled units waiting in VERIFY for a recount; the store selector counts them per store.
        self::assertSame(['1'], self::figures($page));
        self::assertStringContainsString(Words::say('SALE_RETURNS', 'tile_verify_sub', $verify), $page->text());
        self::assertSame([[Words::BULK['all_stores'], self::RETURNS, 1, true], ['ALT test site', self::RETURNS . '?channel=alt', 0, false],
            ['VPG test site', self::RETURNS . '?channel=vpg', 1, false]], self::storeSeg($page));
        // The kind filter, a store, and the empty states.
        self::assertSame(['V2003'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent), iterator_to_array((new \DOMXPath(
            $this->page($web, self::RETURNS, ['kind' => 'returned'])->dom()))->query('//table[contains(@class, "sales-returns")]/tbody/tr/td[1]'))));
        self::assertCount(4, self::refs($this->page($web, self::RETURNS, ['kind' => 'cancelled', 'channel' => 'vpg']), 'sales-returns'));
        $alt = $this->page($web, self::RETURNS, ['channel' => 'alt']);
        self::assertStringContainsString(Words::SALE_RETURNS['none_store'], $alt->text());
        self::assertSame(['0'], self::figures($alt));
        self::assertStringContainsString(Words::SALE_RETURNS['none_filtered'], $this->page($web, self::RETURNS, ['channel' => 'alt', 'kind' => 'returned'])->text());
        // The recount done: the list says so, and nothing waits any more.
        self::$db->exec("UPDATE count_review SET status = 'resolved', resolution = 'counted', resolved_at = NOW(6) WHERE dedupe_key = ?",
            ['verify:' . $this->vpg->channelId . ':e1']);
        $done = $this->page($web, self::RETURNS);
        self::assertStringContainsString(sprintf(Words::SALE_RESTOCK_TEXT['verify_resolved'], $verify), $done->text());
        self::assertSame(['0'], self::figures($done));

        // Paging by key (order, then unit), and the file.
        $views = new SalesViews(self::$db);
        $stores = array_map('intval', self::$db->column('SELECT id FROM channel ORDER BY id'));
        $two = $views->returns($stores, SalesViews::KINDS, null, 2);
        self::assertSame(['k1', 'q1'], array_column($two['rows'], 'unit_id'));
        self::assertSame([$ids['V2010'], 'q1'], $two['next']);
        self::assertSame(['e1', 'd1'], array_column($views->returns($stores, SalesViews::KINDS, $two['next'], 2)['rows'], 'unit_id'));
        $this->setting('sales.page_size', 25);
        $csv = $web->get(self::RETURNS . '.csv', ['channel' => 'vpg']);
        self::assertSame(200, $csv->status, $csv->describe());
        self::assertSame("attachment; filename=\"returns-and-cancels.csv\"; filename*=UTF-8''returns-and-cancels.csv", $csv->header('content-disposition'));
        self::assertStringStartsWith(CsvWriter::BOM . '"store","order_reference","unit","cw_number","item","store_variant","what_happened","recorded_utc","back_on_sale",'
            . '"where_the_stock_is"' . "\r\n", $csv->body);
        self::assertSame(6, substr_count($csv->body, "\r\n"));
        self::assertStringContainsString('"VPG test site","\'=SUM(A1)","k1","","","X9","' . Words::SALE_RETURN_KIND['cancelled'] . '"', $csv->body);
        self::assertSame(2, substr_count($web->get(self::RETURNS . '.csv', ['kind' => 'returned'])->body, "\r\n"));
    }

    // ------------------------------------------------------------------------------------------
    // Escaping, nonsense, settings and the indexes
    // ------------------------------------------------------------------------------------------

    public function testEveryPageShowsWhatAStoreOrAPersonTypedAsText(): void
    {
        $h = '<b>x</b>"\'&';
        $site = $this->site('vpg');
        self::$db->exec('UPDATE channel SET name = ? WHERE id = ?', ['<script>alert(1)</script>', $site->channelId]);
        $item = $this->item('strict', 10, '<i>item</i>' . $h);
        $this->profiled($site, '<v>', ['product_title' => '<u>title</u>', 'variant_title' => $h], $item);
        $this->profiled($site, 'L<1>', ['product_title' => '<u>loose</u>', 'variant_title' => $h]);
        $ref = '<b>ref</b>';
        $this->paid($site, $ref, [self::line('<v>', '<unit>', 'u"2', 'w4'), self::line('L<1>', "u'3")]);
        $this->ok($this->ship($site, $ref, ['<unit>'], self::sentAt($this->real)));
        $this->ok($this->res->returnUnits($site, $ref, ['<unit>'], $this->key('return')));
        $this->ok($this->res->cancel($site, $ref, ['u"2', "u'3"], false, $this->key('cancel')));
        $staff = $this->uiUser('stock_controller');
        self::$db->exec('UPDATE staff_user SET display_name = ? WHERE id = ?', ['<b>person</b>', $staff['id']]);
        $id = (int) self::$db->value('SELECT id FROM reservation');
        $web = $this->signIn($staff);
        foreach ([[self::ORDERS, []], [self::ORDERS, ['state' => 'returned', 'period' => 'all']], [self::ORDERS . '/' . $id, []], [self::DISPATCH, []],
            [self::DISPATCH, ['show' => 'today']], [self::RETURNS, []]] as [$path, $query]) {
            $p = $this->page($web, $path, $query);
            foreach (['<script>alert', '<b>ref', '<i>item', '<u>title', '<u>loose', '<unit>', '<b>x</b>', '<v>'] as $raw) {
                self::assertStringNotContainsString($raw, $p->body, "{$path}: {$raw}");
            }
            self::assertStringContainsString('&lt;b&gt;ref&lt;/b&gt;', $p->body, $path);
        }
        $order = $this->page($web, self::ORDERS . '/' . $id);
        self::assertStringContainsString('&lt;i&gt;item&lt;/i&gt;&lt;b&gt;x&lt;/b&gt;&quot;&apos;&amp;', $order->body);
        self::assertStringContainsString('&lt;u&gt;loose&lt;/u&gt;', $order->body, 'a variant not linked is named by its title');
        $csv = $web->get(self::RETURNS . '.csv');
        self::assertStringContainsString('"<b>ref</b>"', $csv->body, 'a file holds the text itself (a download, never a page)');
        self::assertSame(['sandbox', Kernel::CSP], $csv->headerValues('content-security-policy'));
    }

    public function testNonsenseInTheAddressIsIgnoredNeverAnError(): void
    {
        $ids = $this->orders();
        $web = $this->signIn($this->uiUser('viewer'));
        $recent = self::refs($this->page($web, self::ORDERS), 'sales-orders');
        foreach ([
            ['state' => ['held'], 'channel' => ['vpg'], 'origin' => ['x'], 'before' => ['3'], 'from' => ['2026-01-01'], 'period' => ['all']],
            ['before' => '-5', 'state' => 'nope', 'channel' => 'no-such-store', 'origin' => 'HELD', 'period' => 'ALL'],
            ['before' => '0', 'state' => '', 'channel' => 'VPG', 'unit' => str_repeat('x', 200)],
            ['before' => '99999999999999999999999', 'show' => 'nope', 'kind' => 'gone', 'bu' => "a b", 'after' => 'x'],
        ] as $query) {
            $label = json_encode($query);
            self::assertSame($recent, self::refs($this->page($web, self::ORDERS, $query), 'sales-orders'), $label);
            self::assertSame(['V2001', 'V2002', 'V2008', 'V2009', 'A3001'], self::refs($this->page($web, self::DISPATCH, $query), 'dispatch-waiting'), $label);
            self::assertCount(5, self::refs($this->page($web, self::RETURNS, $query), 'sales-returns'), $label);
            self::assertSame(200, $web->send('GET', self::ORDERS . '/' . $ids['V2003'], $query, [])->status, $label);
            self::assertLessThan(500, $web->send('GET', self::ORDERS . '.csv', $query, [])->status, $label);
            self::assertLessThan(500, $web->send('GET', self::RETURNS . '.csv', $query, [])->status, $label);
        }
        // A key for an order that is not there: an empty page, never an error.
        self::assertSame([], self::refs($this->page($web, self::ORDERS, ['before' => '999999']), 'sales-orders'));
        self::assertSame(14, (int) self::$db->value('SELECT COUNT(*) FROM reservation'), 'nothing was changed by looking');
    }

    public function testTheSettingsHaveTheirBaselineRulesWordsAndPage(): void
    {
        $owner = $this->signIn($this->uiUser('reviewer'));
        $list = $owner->get('/ui/reference/settings');
        foreach (SalesViews::SETTINGS as [$key, $default]) {
            self::assertSame([(string) $default, 1, 'system:migrate'], [(string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]),
                (int) self::$db->value('SELECT provisional FROM app_setting WHERE setting_key = ?', [$key]),
                (string) self::$db->value('SELECT updated_actor FROM app_setting WHERE setting_key = ?', [$key])], $key);
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ? AND version = 1 "
                . "AND action = 'baseline' AND actor = 'system:migrate'", [$key]), "{$key}: version 1 in its history");
            self::assertStringContainsString(Words::settingName($key), $list->text(), $key);
            $one = $owner->get('/ui/reference/settings/setting', ['key' => $key]);
            self::assertSame(200, $one->status, $one->describe());
            self::assertStringContainsString(Words::SETTING_HELP[$key], $one->text());
            self::assertSame('Sales and dispatch', Words::settingTopic($key));
        }
        self::assertSame(['page' => 50, 'days' => 7, 'late_hours' => 24, 'csv_max' => 5000, 'scan_max' => 5000], SalesViews::settings(self::$db));
        // The rules: what the page may be set to.
        $settings = new Settings(self::$db);
        foreach ([['sales.page_size', 30], ['sales.page_size', 500], ['sales.default_days', 0], ['sales.default_days', 366], ['sales.late_hours', 0],
            ['sales.late_hours', 721], ['sales.csv_max_rows', 99], ['sales.csv_max_rows', 50_001], ['sales.scan_max_rows', 499]] as [$key, $value]) {
            try {
                $settings->checkRule($key, $value);
                self::fail("{$key} = {$value} is refused");
            } catch (CwException $e) {
                self::assertSame('bad_value', $e->errorCode, "{$key} = {$value}");
            }
        }
        foreach ([['sales.page_size', 100], ['sales.default_days', 365], ['sales.late_hours', 720], ['sales.csv_max_rows', 50_000], ['sales.scan_max_rows', 500]] as [$key, $value]) {
            $settings->checkRule($key, $value);
        }
        // A value the rule does not allow (only admin SQL could write one) reads as the migration's.
        $this->setting('sales.page_size', 7);
        $this->setting('sales.late_hours', 9);
        self::assertSame([50, 9], [SalesViews::settings(self::$db)['page'], SalesViews::settings(self::$db)['late_hours']]);
        // Changed on the page with a reason, it applies to the next page (the version check of the form as every setting).
        $form = $owner->get('/ui/reference/settings/setting', ['key' => 'sales.late_hours'])->form('/ui/reference/settings/setting');
        $saved = $owner->post('/ui/reference/settings/setting', ['value' => '12', 'reason' => 'night shift sends later'] + $form);
        self::assertSame(303, $saved->status, $saved->describe());
        self::assertSame(12, SalesViews::settings(self::$db)['late_hours']);
        self::assertSame(2, (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'sales.late_hours'"));
        self::assertStringContainsString(Words::say('DISPATCH', 'tile_late_sub', 12), $this->page($this->signIn($this->uiUser('warehouse')), self::DISPATCH)->text());
        self::$db->exec("UPDATE app_setting SET updated_actor = 'system:migrate' WHERE setting_key = 'sales.late_hours'");
    }

    public function testTheMigrationAddsTheIndexesEveryStatementReads(): void
    {
        $columns = static fn (string $table, string $index): array => array_column(self::$db->all('SELECT COLUMN_NAME FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', [$table, $index]), 'COLUMN_NAME');
        self::assertSame(['channel_id', 'status', 'origin', 'created_at', 'id'], $columns('reservation', 'ix_reservation_store_placed'));
        self::assertSame(['channel_id', 'state', 'reservation_id', 'unit_id'], $columns('reservation_unit', 'ix_reservation_unit_store_state'));
        self::assertSame(['channel_id', 'state', 'dispatched_at', 'reservation_id'], $columns('reservation_unit', 'ix_reservation_unit_store_sent'));
        self::assertSame(['channel_id', 'movement_type', 'id'], $columns('stock_ledger', 'ix_stock_ledger_store_type'));
        $this->orders();
        $ch = (int) $this->vpg->channelId;
        $plan = static function (string $sql, array $params): array {
            $rows = self::$db->all('EXPLAIN ' . $sql, $params);
            return [(string) $rows[0]['key'], (string) $rows[0]['Extra'], (string) $rows[0]['type']];
        };
        // Store orders: one range of the placed index per (store, state, way in), newest first, no sort.
        [$key, $extra] = $plan('SELECT /*+ INDEX(r ix_reservation_store_placed) */ r.id, r.created_at FROM reservation r WHERE r.channel_id = ? AND r.status = ? AND r.origin = ? '
            . 'AND r.created_at >= ? AND r.created_at <= ? AND (r.created_at < ? OR r.id < ?) ORDER BY r.created_at DESC, r.id DESC LIMIT 51',
            [$ch, 'committed', 'reserved', '2026-01-01', '2099-01-01', '2099-01-01', 999999]);
        self::assertSame('ix_reservation_store_placed', $key);
        self::assertStringNotContainsString('filesort', $extra);
        // Orders with a unit in a state, Dispatch's waiting orders and Returns: the unit index by order, from the index alone, no sort.
        foreach ([
            'SELECT /*+ INDEX(ru ix_reservation_unit_store_state) */ ru.reservation_id AS id FROM reservation_unit ru WHERE ru.channel_id = ? AND ru.state = ? '
                . 'AND ru.reservation_id < ? GROUP BY ru.reservation_id ORDER BY ru.reservation_id DESC LIMIT 51' => [$ch, 'shipped', 999999],
            'SELECT /*+ INDEX(ru ix_reservation_unit_store_state) */ ru.reservation_id AS id FROM reservation_unit ru WHERE ru.channel_id = ? AND ru.state = ? '
                . 'AND ru.reservation_id > ? GROUP BY ru.reservation_id ORDER BY ru.reservation_id LIMIT 51' => [$ch, 'allocated', 0],
            'SELECT /*+ INDEX(ru ix_reservation_unit_store_state) */ ru.channel_id, ru.unit_id, ru.reservation_id FROM reservation_unit ru WHERE ru.channel_id = ? '
                . 'AND ru.state = ? AND ru.reservation_id <= ? AND (ru.reservation_id < ? OR ru.unit_id < ?) ORDER BY ru.reservation_id DESC, ru.unit_id DESC LIMIT 51'
                => [$ch, 'cancelled', 999999, 999999, 'z'],
        ] as $sql => $params) {
            [$key, $extra] = $plan($sql, $params);
            self::assertSame('ix_reservation_unit_store_state', $key, $sql);
            self::assertStringContainsString('Using index', $extra, $sql);
            self::assertStringNotContainsString('filesort', $extra, $sql);
            self::assertStringNotContainsString('temporary', $extra, $sql);
        }
        // Units sent in a period: the sent index, from the index alone.
        [$key, $extra, $type] = $plan("SELECT /*+ INDEX(ru ix_reservation_unit_store_sent) */ COUNT(DISTINCT ru.reservation_id), COUNT(*) FROM reservation_unit ru "
            . "WHERE ru.channel_id = ? AND ru.state IN ('shipped', 'returned') AND ru.dispatched_at >= ?", [$ch, '2026-01-01']);
        self::assertSame(['ix_reservation_unit_store_sent', 'range'], [$key, $type]);
        self::assertStringContainsString('Using index', $extra);
        // Sending undone: one range of the ledger's new index, newest first, no sort; the week's first ledger id from (created_at).
        [$key, $extra] = $plan("SELECT /*+ INDEX(l ix_stock_ledger_store_type) */ l.id FROM stock_ledger l WHERE l.channel_id = ? AND l.movement_type = 'unship' "
            . 'AND l.id < ? ORDER BY l.id DESC LIMIT 52', [$ch, PHP_INT_MAX]);
        self::assertSame('ix_stock_ledger_store_type', $key);
        self::assertStringNotContainsString('filesort', $extra);
        [$key] = $plan('SELECT id FROM stock_ledger WHERE created_at >= ? ORDER BY created_at, id LIMIT 1', ['2026-01-01']);
        self::assertSame('ix_stock_ledger_created', $key);
    }

    public function testApplyingFiltersPreservesAnyTimeAndItsCsv(): void
    {
        $this->orders();
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $this->page($web, self::ORDERS, ['channel' => 'vpg', 'period' => 'all']);
        $dom = $page->dom();
        $form = (new \DOMXPath($dom))->query('//main//form[@method="get"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $form);
        $fields = (new UiResponse(200, [], (string) $dom->saveHTML($form)))->form('');
        self::assertSame('all', $fields['period'] ?? null);
        $fields['state'] = 'paid';
        $filtered = $this->page($web, self::ORDERS, $fields);
        self::assertContains('V1998', self::refs($filtered, 'sales-orders'), 'a paid order placed 40 days ago stays visible');
        self::assertStringContainsString(Words::SALE['period_all'], $filtered->text());
        self::assertContains(self::ORDERS . '.csv?channel=vpg&state=paid&period=all', $filtered->hrefs());
        $csv = $web->get(self::ORDERS . '.csv', $fields);
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('"V1998"', $csv->body);
        $fields['from'] = $fields['to'] = $this->real->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        self::assertNotContains('V1998', self::refs($this->page($web, self::ORDERS, $fields), 'sales-orders'), 'typed dates still override Any time');
    }
}
