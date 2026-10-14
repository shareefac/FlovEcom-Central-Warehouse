<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * Pack G's screens through the real /ui kernel as cw_app (docs/decisions.md PG3, PG6, PG7, PG9): the list reads the stored lines with the
 * time they were worked out and "work out the list again now"; the filter "below the safety stock"; the suppliers' spare days; the
 * low-stock warnings with their click (idempotent, skipped lines listed); who may open and change each page; and the CSRF token on
 * every POST of the pack.
 */
final class ReorderEngineScreensTest extends KernelUiTestCase
{
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    /** @return list<string> the item codes of a list's rows, in order */
    private static function codes(UiResponse $r): array
    {
        $out = [];
        foreach ((new \DOMXPath($r->dom()))->query('//table[contains(@class, "reorder")]/tbody/tr/th/a[contains(@class, "o-name")]') ?: [] as $a) {
            $out[] = trim((string) $a->textContent);
        }
        return $out;
    }

    private static function skuCode(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    public function testTheListReadsTheStoredLinesAndFiltersBelowTheSafetyStock(): void
    {
        $s = $this->listScenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/purchasing/reorder');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(array_map(self::skuCode(...), [$s['c'], $s['b'], $s['d'], $s['a']]), self::codes($page));
        self::assertStringContainsString(Words::say('REORDER_ENGINE', 'figures_every', 15), $page->text());
        self::assertStringContainsString(Words::say('REORDER_ENGINE', 'safety_stock', 60), $page->text(), 'A\'s safety stock beside what we have');
        self::assertStringContainsString(Words::say('REORDER_ENGINE', 'below_chip', 25), $page->text(), 'C is below its safety stock');
        self::assertTrue($page->hasForm('/ui/purchasing/reorder/refresh'));
        // An order made outside the list: the stored list shows it after the next run, or at once with "work out the list again now".
        $pos = new PurchaseOrders(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
        $d = $pos->createDraft($s['buyer'], (int) $s['s1']['id'], []);
        $d = $pos->saveDraft($s['buyer'], $d->id, $d->version, [], [['supplier_item_id' => (int) $s['si']['a']['id'], 'packs' => 5]]);
        $pos->approve($s['buyer'], $d->id, $d->version);
        self::assertContains(self::skuCode($s['a']), self::codes($web->get('/ui/purchasing/reorder')), 'not worked out again yet');
        $r = $web->post('/ui/purchasing/reorder/refresh', ['csrf' => $this->token($web)]);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/purchasing/reorder?notice=refreshed', $r->location());
        $after = $web->follow($r);
        self::assertStringContainsString(Words::REORDER_NOTICE['refreshed'], $after->text());
        self::assertSame(array_map(self::skuCode(...), [$s['c'], $s['b'], $s['d']]), self::codes($after), 'A is covered by its order now');
        // Below the safety stock only: whatever there is to buy (A has 60, its safety stock: not below).
        $below = $web->get('/ui/purchasing/reorder', ['below' => '1']);
        self::assertSame(array_map(self::skuCode(...), [$s['c'], $s['b'], $s['d']]), self::codes($below));
        self::assertSame('1', $below->form('/ui/purchasing/reorder/draft')['below'], 'the draft form keeps the filter');
        $csv = $web->get('/ui/purchasing/reorder.csv', ['below' => '1', 'show' => 'all']);
        $rows = array_values(array_filter(preg_split('/\r\n/', substr($csv->body, 3)) ?: []));
        self::assertCount(1 + 3, $rows, 'the download of the same filter: header and three lines');
        // The website-stock view is still worked out on the page.
        self::assertSame(200, $web->get('/ui/purchasing/reorder', ['stock' => 'site'])->status);
        // A list longer than reorder.refresh_inline_max_items: the button works out the products of the page shown, the rest waits.
        $this->setting('reorder.refresh_inline_max_items', '0');
        $form = $web->get('/ui/purchasing/reorder')->form('/ui/purchasing/reorder/refresh');
        self::assertSame(implode(',', [$s['c'], $s['b'], $s['d']]), $form['items']);
        $r = $web->post('/ui/purchasing/reorder/refresh', $form);
        self::assertSame('/ui/purchasing/reorder?notice=refreshed_page', $r->location(), $r->describe());
        self::assertStringContainsString(Words::REORDER_NOTICE['refreshed_page'], $web->follow($r)->text());
        $r = $web->post('/ui/purchasing/reorder/refresh', ['csrf' => $form['csrf']]);
        self::assertSame(409, $r->status, 'no page to work out: the list is too long, said in words');
        self::assertStringContainsString(Words::say('REORDER_ENGINE', 'refresh_later', 5, 15), $r->text());
    }

    public function testWhoMayOpenAndChangeThePacksPages(): void
    {
        $s = $this->listScenario();
        $pages = ['/ui/purchasing/reorder/suppliers' => '/ui/purchasing/reorder/suppliers', '/ui/purchasing/reorder/low-stock' => '/ui/purchasing/reorder/low-stock'];
        foreach (['buyer', 'purchasing_manager'] as $job) {
            $web = $this->signIn($this->uiUser($job));
            foreach ($pages as $path => $form) {
                $p = $web->get($path, $path === '/ui/purchasing/reorder/suppliers' ? ['supplier' => (string) $s['s1']['id']] : []);
                self::assertSame(200, $p->status, "{$job} {$path} " . $p->describe());
                self::assertTrue($p->hasForm($form), "{$job} changes {$path}");
            }
            self::assertTrue($web->get('/ui/purchasing/reorder')->hasForm('/ui/purchasing/reorder/refresh'), $job);
        }
        foreach (['reviewer', 'auditor', 'manager'] as $job) {
            $web = $this->signIn($this->uiUser($job));
            foreach ($pages as $path => $form) {
                $p = $web->get($path, $path === '/ui/purchasing/reorder/suppliers' ? ['supplier' => (string) $s['s1']['id']] : []);
                self::assertSame(200, $p->status, "{$job} {$path}");
                self::assertFalse($p->hasForm($form), "{$job} only looks at {$path}");
                self::assertStringContainsString(Words::say('UI', 'look_only', Words::whoCan('reorder.manage')), $p->text(), "{$job}: says who changes it");
            }
            self::assertFalse($web->get('/ui/purchasing/reorder')->hasForm('/ui/purchasing/reorder/refresh'), $job);
            $t = $this->token($web);
            foreach (['/ui/purchasing/reorder/refresh' => [], '/ui/purchasing/reorder/suppliers' => ['supplier_id' => (string) $s['s1']['id'], 'version' => '0'],
                '/ui/purchasing/reorder/low-stock' => ['action' => 'keep', 'form_key' => str_repeat('a', 32), 'pick_' . $s['a'] => '1']] as $path => $fields) {
                self::assertSame(403, $web->post($path, ['csrf' => $t] + $fields)->status, "{$job} {$path}");
            }
        }
        foreach (['purchasing_desk', 'goods_in', 'stock_controller', 'admin'] as $job) {
            $web = $this->signIn($this->uiUser($job));
            foreach (array_keys($pages) as $path) {
                self::assertSame(403, $web->get($path)->status, "{$job} {$path}");
            }
        }
        self::assertNull(self::$db->value('SELECT low_stock_level FROM item_reorder WHERE sku_id = ?', [$s['a']]), 'nothing changed');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM reorder_supplier'));
    }

    public function testEveryPostOfThePackNeedsTheSessionsToken(): void
    {
        $s = $this->listScenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $low = $web->get('/ui/purchasing/reorder/low-stock')->form('/ui/purchasing/reorder/low-stock') + ['action' => 'safety', 'pick_' . $s['a'] => '1'];
        $sup = $web->get('/ui/purchasing/reorder/suppliers', ['supplier' => (string) $s['s1']['id']])->form('/ui/purchasing/reorder/suppliers');
        $draft = $web->get('/ui/purchasing/reorder')->form('/ui/purchasing/reorder/draft');
        $item = $web->get('/ui/purchasing/reorder/items/' . $s['a'])->form('/ui/purchasing/reorder/items/' . $s['a']);
        $t = $this->token($web);
        foreach (['/ui/purchasing/reorder/refresh' => ['csrf' => $t], '/ui/purchasing/reorder/low-stock' => $low, '/ui/purchasing/reorder/suppliers' => $sup,
            '/ui/purchasing/reorder/draft' => $draft, '/ui/purchasing/reorder/items/' . $s['a'] => $item, '/ui/purchasing/reorder/recalculate' => ['csrf' => $t]] as $path => $good) {
            foreach (['no token' => array_diff_key($good, ['csrf' => 1]), 'empty' => ['csrf' => ''] + $good, 'made up' => ['csrf' => 'forged'] + $good] as $what => $bad) {
                $r = $web->post($path, $bad);
                self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], "{$path}: {$what}");
            }
        }
        self::assertNull(self::$db->value('SELECT low_stock_level FROM item_reorder WHERE sku_id = ?', [$s['a']]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM purchase_order'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM reorder_supplier'));
    }

    public function testASuppliersSpareDays(): void
    {
        $s = $this->listScenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/purchasing/reorder/suppliers');
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString((string) $s['s2']['name'], $page->text());
        self::assertSame(['Suggestions', 'Brands', 'Anomalies', 'Sales history', 'Suppliers', 'Low-stock warnings'], array_column(self::segments($page), 'label'));
        self::assertSame('Suppliers', array_values(array_filter(self::segments($page), static fn (array $x): bool => $x['current']))[0]['label']);
        $form = $web->get('/ui/purchasing/reorder/suppliers', ['supplier' => (string) $s['s2']['id']])->form('/ui/purchasing/reorder/suppliers');
        self::assertSame(['0', (string) $s['s2']['id']], [$form['version'], $form['supplier_id']]);
        $form['safety_days'] = '2';
        $r = $web->post('/ui/purchasing/reorder/suppliers', $form);
        self::assertSame(303, $r->status, $r->describe());
        $done = $web->follow($r);
        self::assertStringContainsString(Words::REORDER_ENGINE_NOTICE['supplier_saved'], $done->text());
        $c = json_decode((string) self::$db->value('SELECT CAST(line AS CHAR) FROM reorder_line_detail WHERE sku_id = ?', [$s['c']]), true);
        self::assertSame([2, 'supplier', 100], [$c['safety'], $c['safety_source'], $c['target']], 'the stored line follows at once: 4 + 14 + 2 days × 5');
        // The same (stale) form: 409, redrawn with the current value; a bad value: 422, kept.
        $form['safety_days'] = '3';
        $stale = $web->post('/ui/purchasing/reorder/suppliers', $form);
        self::assertSame(409, $stale->status);
        self::assertSame('2', $stale->form('/ui/purchasing/reorder/suppliers')['safety_days']);
        $fresh = $stale->form('/ui/purchasing/reorder/suppliers');
        $fresh['safety_days'] = '95';
        $bad = $web->post('/ui/purchasing/reorder/suppliers', $fresh);
        self::assertSame(422, $bad->status);
        self::assertSame('95', $bad->form('/ui/purchasing/reorder/suppliers')['safety_days']);
    }

    public function testTheLowStockWarningsAndTheirClick(): void
    {
        $s = $this->listScenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/purchasing/reorder/low-stock');
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(array_map(self::skuCode(...), [$s['c'], $s['b'], $s['d'], $s['e'], $s['a']]), self::codes($page), 'every product of the list');
        self::assertStringContainsString(Words::REORDER_LOW['writers_none'], $page->text());
        $form = $page->form('/ui/purchasing/reorder/low-stock');
        $form['pick_' . $s['a']] = '1';
        $form['pick_' . $s['b']] = '1';
        $form['pick_' . $s['e']] = '1';
        $form['action'] = 'safety';
        $r = $web->post('/ui/purchasing/reorder/low-stock', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($r->location(), $web->post('/ui/purchasing/reorder/low-stock', $form)->location(), 'the same form twice: one change');
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM stock_change WHERE reason = 'low_stock'"));
        $done = $web->follow($r);
        self::assertStringContainsString(Words::REORDER_ENGINE_NOTICE['low_saved'], $done->text());
        self::assertStringContainsString(Words::say('REORDER_LOW', 'done_changed', 2), $done->text());
        self::assertStringContainsString(Words::REORDER_LOW_SKIP['never'], $done->text(), 'E is listed with its reason');
        self::assertSame([60, 10], array_map('intval', self::$db->column('SELECT low_stock_level FROM item_reorder WHERE sku_id IN (?, ?) ORDER BY sku_id', [$s['a'], $s['b']])));
        self::assertSame(array_map(self::skuCode(...), [$s['b'], $s['a']]), self::codes($web->get('/ui/purchasing/reorder/low-stock', ['low' => 'set'])));
        // Keep each website's own for A.
        $form = $web->get('/ui/purchasing/reorder/low-stock')->form('/ui/purchasing/reorder/low-stock');
        $form['pick_' . $s['a']] = '1';
        $form['action'] = 'keep';
        self::assertSame(303, $web->post('/ui/purchasing/reorder/low-stock', $form)->status);
        self::assertNull(self::$db->value('SELECT low_stock_level FROM item_reorder WHERE sku_id = ?', [$s['a']]));
        // Nothing ticked: 422 in words, nothing changed.
        $form = $web->get('/ui/purchasing/reorder/low-stock')->form('/ui/purchasing/reorder/low-stock');
        $form['action'] = 'safety';
        $none = $web->post('/ui/purchasing/reorder/low-stock', $form);
        self::assertSame(422, $none->status);
        self::assertStringContainsString(Words::BUY_ERROR['nothing_picked'], $none->text());
        // The product's page shows the figure for each website once a figure is kept.
        self::assertStringContainsString(Words::REORDER_LOW['item_figure'], $web->get('/ui/items/' . $s['b'])->text());
    }
}
