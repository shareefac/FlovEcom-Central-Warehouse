<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Db;
use CW\Mapping\BulkDecisions;
use CW\Settings;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\ByProductContext;
use CW\Ui\Controller\BulkController;
use CW\Ui\Controller\ByItemBulkController;
use CW\Ui\Controller\ByProductController;
use CW\Ui\Words;

/**
 * Products › By Item, more rows and the bulk action with its preview (docs/decisions.md M55-M59, U123-U130; the owner, 10 Oct 2026:
 * "by item here bulk preview and update option easy table need more item want to handle based on selection also all here possible
 * options need"), through the real /ui kernel as cw_app. Covered: 50, 100 or 200 items a page by the person's choice or the
 * setting; a tick on every item a store suggests a variant for, the header tick and the bar with its store choice, for people who
 * may decide only; the preview of each action (what will happen to every variant, every reason for a skip or a second OK, the
 * counts) and the proof that it writes nothing; the apply and the result page; a change between the preview and the apply; the
 * limit, which counts variants; the store choice; Back with the filters, the page and the ticks; the token and the permission of
 * both routes; odd and crafted values answered in words. Second round (M59): what a confirm leaves to a person one at a time (a
 * pair marked not a match before, an item that already has a variant on the store, several suggestions on one store), the cell
 * "Marked not a match" and "tick all", every suggestion of a row sent (never a part), numbers beyond their column refused.
 */
final class ByItemBulkTest extends KernelUiTestCase
{
    /** What a signed-in request writes for itself (the session's last use): nothing of the matching. */
    private const OWN_TABLES = ['staff_session'];

    /** @var array<string, string> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->saved = [];
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->saved[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    /** Three stores named as the channel table names them. @return array{a: Caller, b: Caller, c: Caller} */
    private function stores(): array
    {
        $out = [];
        foreach (['a' => ['alt', 'Alt Store'], 'b' => ['vbig', 'Big Store'], 'c' => ['cee', 'Cee Store']] as $key => [$code, $name]) {
            $out[$key] = $this->site($code, 'shadow');
            self::$db->exec('UPDATE channel SET name = ? WHERE code = ?', [$name, $code]);
        }
        return $out;
    }

    private function proposalOf(int $listing): int
    {
        return (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listing]);
    }

    /** What the list sends for a suggested variant: variant . suggestion . version. */
    private function cell(int $listing): string
    {
        return $listing . '.' . $this->proposalOf($listing) . '.' . $this->version($listing);
    }

    private static function squash(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /** @return list<int> the items of the list's rows, in order */
    private static function ids(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMElement $tr): int => (int) substr($tr->getAttribute('id'), 2), iterator_to_array($xp->query('//table[contains(@class, "by-product")]/tbody/tr')));
    }

    /** One cell of the list: what it reads, and its links (text => href). @return array{0: string, 1: array<string, string>} */
    private static function cellOf(UiResponse $r, int $sku, string $store): array
    {
        $xp = new \DOMXPath($r->dom());
        $td = $xp->query('//tr[@id="p-' . $sku . '"]/td[@data-label="' . $store . '"]')->item(0);
        self::assertNotNull($td, "item {$sku} has a cell for {$store}");
        $links = [];
        foreach ($xp->query('.//a', $td) as $a) {
            /** @var \DOMElement $a */
            $links[self::squash($a->textContent)] = $a->getAttribute('href');
        }
        return [self::squash($td->textContent), $links];
    }

    /**
     * Many suggested variants of one store for one item, written straight into the tables (a listing that waits, with an open
     * suggestion of the run `many`): what hundreds of computer suggestions leave behind, without hundreds of round trips.
     *
     * @return list<int> the listing ids, in order
     */
    private function manySuggested(Caller $site, int $sku, int $n, string $prefix): array
    {
        $run = (int) (self::$db->value("SELECT id FROM match_run WHERE run_id = 'many'") ?? self::$db->insert("INSERT INTO match_run (run_id, source) VALUES ('many', 'manual')"));
        $first = (int) self::$db->value('SELECT COALESCE(MAX(id), 0) FROM channel_listing') + 1;
        $listings = [];
        $proposals = [];
        for ($i = 0; $i < $n; $i++) {
            $listings[] = sprintf("(%d, %d, '%s%d', NULL, 1, 'suggested')", $first + $i, $site->channelId, $prefix, $i);
            $proposals[] = sprintf("(%d, %d, %d, 0, 'Key', 'candidates', 'open')", $first + $i, $run, $sku);
        }
        self::$db->pdo()->exec('INSERT INTO channel_listing (id, channel_id, external_variant_id, sku_id, units_per_item, status) VALUES ' . implode(',', $listings));
        self::$db->pdo()->exec('INSERT INTO match_proposal (listing_id, match_run_id, proposed_sku_id, proposed_new_item, band, lane, status) VALUES ' . implode(',', $proposals));
        return range($first, $first + $n - 1);
    }

    /** @return list<int> the items whose row is drawn ticked */
    private static function drawnTicked(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMElement $i): int => (int) substr($i->getAttribute('name'), 5), iterator_to_array($xp->query('//input[@data-bulk-row][@checked]')));
    }

    /** @return list<array{0: string, 1: bool, 2: string}> the "Rows" choice: number, current, link */
    private static function sizes(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMElement $a): array => [self::squash($a->textContent), $a->getAttribute('aria-current') === 'true', $a->getAttribute('href')],
            iterator_to_array($xp->query('//nav[@class="rows-choice"]/a')));
    }

    /**
     * The bulk form of By Item with these items ticked, this store chosen and this action pressed.
     *
     * @param list<int> $skus
     * @return array<string, string>
     */
    private static function ticked(UiResponse $page, array $skus, string $action, string $on = ''): array
    {
        $form = $page->form('/ui/review/products/bulk');
        self::assertNotSame([], $form, 'the page has the bulk form: ' . $page->describe());
        foreach ($skus as $sku) {
            self::assertArrayHasKey('cells_' . $sku, $form, "item {$sku} has a tick box");
            $form['pick_' . $sku] = '1';
        }
        return ['action' => $action, 'on' => $on] + $form;
    }

    /**
     * The preview's lines: variant => its item, store, name, what will happen (the code and the words), why it is skipped, the
     * reasons for a second OK, and what the apply sends for it.
     *
     * @return array<int, array{item: string, store: string, variant: string, outcome: string, label: string, why: string, needs: list<string>, field: string}>
     */
    private static function lines(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//table[contains(@class, "by-item-preview")]/tbody/tr') as $tr) {
            /** @var \DOMElement $tr */
            $out[(int) substr((string) $xp->evaluate('string(th/input[@type="hidden"]/@name)', $tr), 4)] = [
                'item' => self::squash($xp->evaluate('string(th/a)', $tr)),
                'store' => self::squash($xp->evaluate('string(td[1])', $tr)),
                'variant' => self::squash($xp->evaluate('string(td[2]/a)', $tr)),
                'outcome' => $tr->getAttribute('data-outcome'),
                'label' => self::squash($xp->evaluate('string(td[3]/span[contains(@class, "chip")])', $tr)),
                'why' => self::squash($xp->evaluate('string(td[3]/span[@class="why"])', $tr)),
                'needs' => array_map(static fn (\DOMNode $n): string => self::squash($n->textContent), iterator_to_array($xp->query('td[3]/span[@class="tag"]', $tr))),
                'field' => (string) $xp->evaluate('string(th/input[@type="hidden"]/@value)', $tr),
            ];
        }
        return $out;
    }

    /** The preview's one button: [its words, its class], or null when there is none. @return array{0: string, 1: string}|null */
    private static function button(UiResponse $r): ?array
    {
        $xp = new \DOMXPath($r->dom());
        $b = $xp->query('//form[contains(@action, "/bulk/apply")]//button[@type="submit"]')->item(0);
        return $b instanceof \DOMElement ? [self::squash($b->textContent), $b->getAttribute('class')] : null;
    }

    /**
     * Every table of the schema: how many rows, the checksum of their content and the next id it would give. A preview must leave
     * all three as they were (a write that is rolled back would still use up an id), except in the tables a signed-in request
     * keeps for itself (OWN_TABLES).
     *
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    private static function snapshot(): array
    {
        self::$db->pdo()->exec('SET SESSION information_schema_stats_expiry = 0');
        $out = [];
        foreach (self::$db->all("SELECT TABLE_NAME AS name, AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $t) {
            $name = (string) $t['name'];
            if (in_array($name, self::OWN_TABLES, true)) {
                continue;
            }
            $out[$name] = [(int) self::$db->value('SELECT COUNT(*) FROM ' . Db::ident($name)),
                (string) (self::$db->all('CHECKSUM TABLE ' . Db::ident($name))[0]['Checksum'] ?? ''), (string) ($t['next_id'] ?? '')];
        }
        return $out;
    }

    /** @return array<int, array{outcome: string, skip_code: ?string, decision_id: ?int}> variant => its row of a batch */
    private static function batchRows(int $batchId): array
    {
        $out = [];
        foreach (self::$db->all('SELECT listing_id, outcome, skip_code, decision_id FROM mapping_batch_row WHERE batch_id = ? ORDER BY seq', [$batchId]) as $r) {
            $out[(int) $r['listing_id']] = ['outcome' => (string) $r['outcome'], 'skip_code' => $r['skip_code'] === null ? null : (string) $r['skip_code'],
                'decision_id' => $r['decision_id'] === null ? null : (int) $r['decision_id']];
        }
        return $out;
    }

    private static function batches(): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch');
    }

    public function testAPageShows50100Or200ItemsByThePersonsChoiceOrTheSetting(): void
    {
        ['a' => $a] = $this->stores();
        $skus = [];
        for ($i = 1; $i <= 205; $i++) {
            $skus[] = self::makeSku(sprintf('Bulk item %03d', $i));
        }
        $this->profiled($a, 'M1', ['product_title' => 'Matched one'], $skus[149]);
        $web = $this->signIn($this->uiUser('viewer'));

        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertSame(array_slice($skus, 0, 50), self::ids($page), '50 as before: the setting\'s number');
        self::assertSame([['50', true, '/ui/review/products'], ['100', false, '/ui/review/products?n=100'], ['200', false, '/ui/review/products?n=200']], self::sizes($page));
        self::assertSame(Words::BY_PRODUCT['rows'], self::squash((new \DOMXPath($page->dom()))->evaluate('string(//nav[@class="rows-choice"]/span)')));
        self::assertSame('/ui/review/products?page=2', (new \DOMXPath($page->dom()))->evaluate('string(//nav[@class="pager"]/a[@rel="next"]/@href)'),
            'a list nobody resized keeps its plain addresses');

        $hundred = $web->get('/ui/review/products', ['n' => '100']);
        self::assertSame(array_slice($skus, 0, 100), self::ids($hundred));
        self::assertStringContainsString(Words::say('BY_PRODUCT', 'shown', 205, 100), $hundred->text());
        self::assertStringContainsString(Words::say('QUEUE', 'page', 1, 3), $hundred->text());
        self::assertSame('/ui/review/products?n=100&page=2', (new \DOMXPath($hundred->dom()))->evaluate('string(//nav[@class="pager"]/a[@rel="next"]/@href)'), 'the pager keeps the number');
        self::assertSame('100', $hundred->form('/ui/review/products')['n'] ?? null, 'and so does the Show row (W12.7)');
        self::assertContains('/ui/review/products.csv', $hundred->hrefs(), 'the file is every item: it has no pages');
        $second = $web->get('/ui/review/products', ['n' => '100', 'page' => '2']);
        self::assertSame(array_slice($skus, 100, 100), self::ids($second));
        self::assertSame([['50', false, '/ui/review/products?page=3'], ['100', true, '/ui/review/products?n=100&page=2'], ['200', false, '/ui/review/products?n=200']], self::sizes($second),
            'another number of rows keeps the place: the page that holds this page\'s first item');
        $two = $web->get('/ui/review/products', ['n' => '200']);
        self::assertCount(200, self::ids($two));
        self::assertSame(array_slice($skus, 200), self::ids($web->get('/ui/review/products', ['n' => '200', 'page' => '2'])));
        self::assertSame(array_slice($skus, 200), self::ids($web->get('/ui/review/products', ['n' => '200', 'page' => '999'])), 'past the end: the last page');
        // Back from a variant's page at that size (`at`): the page of 100 that holds the item, and its links keep the size.
        $at = $web->get('/ui/review/products', ['n' => '100', 'at' => (string) $skus[149]]);
        self::assertSame(array_slice($skus, 100, 100), self::ids($at));
        self::assertSame(1, (new \DOMXPath($at->dom()))->query('//tr[@id="p-' . $skus[149] . '"][contains(@class, "here")]')->length);
        // A number that is not one of the three is the setting's number, never an error.
        foreach (['75', '0', '-100', '1000', 'abc', '100.0', ' 100', str_repeat('9', 30)] as $odd) {
            $r = $web->get('/ui/review/products', ['n' => $odd]);
            self::assertSame([200, 50], [$r->status, count(self::ids($r))], $odd);
        }
        self::assertCount(50, self::ids($web->send('GET', '/ui/review/products', ['n' => ['100']], [])), 'a list where one value is expected is no value');

        // The setting (Settings › System › Settings): the number a page opens with.
        self::assertSame(50, ByProductController::pageSize(self::$db));
        $this->setting('mapping.by_item_page_size', '200');
        self::assertSame(200, ByProductController::pageSize(self::$db));
        $page = $web->get('/ui/review/products');
        self::assertCount(200, self::ids($page));
        self::assertSame([['50', false, '/ui/review/products?n=50'], ['100', false, '/ui/review/products?n=100'], ['200', true, '/ui/review/products']], self::sizes($page));
        self::assertSame('/ui/review/products?page=2', (new \DOMXPath($page->dom()))->evaluate('string(//nav[@class="pager"]/a[@rel="next"]/@href)'));
        self::assertCount(50, self::ids($web->get('/ui/review/products', ['n' => '50'])), 'the person\'s choice comes first');
        $this->setting('mapping.by_item_page_size', '75');
        self::assertSame(50, ByProductController::pageSize(self::$db), 'a stored number that is not one of the three is 50');
        self::assertCount(50, self::ids($web->get('/ui/review/products')));

        // It is changed on its Settings page like the other matching settings: one of 50, 100, 200, with a reason.
        $this->setting('mapping.by_item_page_size', '50');
        $admin = $this->signIn($this->uiUser('reviewer'));
        self::assertStringContainsString(Words::settingName('mapping.by_item_page_size'), $admin->get('/ui/reference/settings')->text());
        $edit = $admin->get('/ui/reference/settings/setting', ['key' => 'mapping.by_item_page_size']);
        self::assertSame(200, $edit->status, $edit->describe());
        self::assertStringContainsString(Words::SETTING_HELP['mapping.by_item_page_size'], $edit->text());
        self::assertStringContainsString('50, 100, 200', $edit->text(), 'the allowed numbers are said under the field');
        $form = $edit->form('/ui/reference/settings/setting');
        $refused = $admin->post('/ui/reference/settings/setting', ['value' => '75', 'reason' => 'Try another number'] + $form);
        self::assertSame(400, $refused->status, $refused->describe());
        self::assertSame('50', (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'mapping.by_item_page_size'"));
        $saved = $admin->post('/ui/reference/settings/setting', ['value' => '100', 'reason' => 'More rows for the owner'] + $form);
        self::assertSame(303, $saved->status, $saved->describe());
        self::assertSame('100', (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'mapping.by_item_page_size'"));
        self::assertCount(100, self::ids($web->get('/ui/review/products')));
        self::assertSame(['baseline', 'change'], self::$db->column("SELECT action FROM config_change WHERE subject_type = 'setting' AND subject_key = 'mapping.by_item_page_size' ORDER BY version"),
            'the migration\'s baseline, then the change with its reason');
    }

    public function testAPersonWhoMayDecideTicksItemsAndGetsTheBarWithTheStoreChoice(): void
    {
        ['a' => $a, 'b' => $b] = $this->stores();
        $alpha = $this->item('legacy', 0, 'Alpha kit');
        $bravo = $this->item('legacy', 0, 'Bravo kit');
        $charlie = $this->item('legacy', 0, 'Charlie kit');
        // Alpha: matched on Alt, where another variant is suggested for it too, and suggested on Big. Bravo: two suggestions on Alt.
        // Charlie: on no store, nothing suggested.
        $this->profiled($a, 'A1', ['product_title' => 'Alpha on Alt', 'units_30d' => 5], $alpha);
        $a4 = $this->queued($a, 'A4', 'Check', $alpha, ['product_title' => 'Alpha again on Alt']);
        $b1 = $this->queued($b, 'B1', 'Key', $alpha, ['product_title' => 'Alpha on Big']);
        $a2 = $this->queued($a, 'A2', 'Key', $bravo, ['product_title' => 'Bravo one']);
        $a3 = $this->queued($a, 'A3', 'Check', $bravo, ['product_title' => 'Bravo two']);

        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        $xp = new \DOMXPath($page->dom());
        self::assertSame(['pick_' . $alpha, 'pick_' . $bravo], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'), iterator_to_array($xp->query('//input[@data-bulk-row]'))),
            'a tick on every item a store suggests a variant for; none where there is nothing to act on');
        self::assertSame([], self::drawnTicked($page), 'nothing is ticked for the person');
        self::assertSame(1, $xp->query('//table[contains(@class, "by-product")]/thead//input[@type="checkbox"][@data-bulk-all]')->length, '"tick all on this page" in the header');
        self::assertSame(3, $xp->query('//table[contains(@class, "by-product")]/tbody/tr/td[contains(@class, "c-pick")]')->length, 'the tick column on every row');
        $form = $page->form('/ui/review/products/bulk');
        self::assertSame($this->cell($a4) . ' ' . $this->cell($b1), $form['cells_' . $alpha], 'each suggested variant with the suggestion and the version the page showed');
        self::assertSame($this->cell($a2) . ' ' . $this->cell($a3), $form['cells_' . $bravo]);
        self::assertArrayNotHasKey('cells_' . $charlie, $form);
        self::assertArrayNotHasKey('pick_' . $alpha, $form);
        self::assertNotSame('', $form['csrf']);
        // The bar: the store choice from the channel table, then the three actions of this screen.
        self::assertSame([['', Words::BY_ITEM_BULK['all_stores']], ['alt', 'Alt Store'], ['vbig', 'Big Store'], ['cee', 'Cee Store']],
            array_map(static fn (\DOMElement $o): array => [$o->getAttribute('value'), self::squash($o->textContent)], iterator_to_array($xp->query('//div[@data-bulkbar]//select[@name="on"]/option'))));
        self::assertSame(Words::BY_ITEM_BULK['on'], self::squash($xp->evaluate('string(//div[@data-bulkbar]//label[@class="bulkbar-choice"]/span)')));
        self::assertSame([['link', 'Confirm suggested matches'], ['reject', 'Not a match…'], ['send_back', 'Send back for matching…']],
            array_map(static fn (\DOMElement $b): array => [$b->getAttribute('value'), self::squash($b->textContent)], iterator_to_array($xp->query('//div[@data-bulkbar]//button[@name="action"]'))));
        self::assertSame([Words::BY_ITEM_BULK['selected_none'], Words::BY_ITEM_BULK['selected_one'], Words::BY_ITEM_BULK['selected_many'], Words::BY_ITEM_BULK['bar'], '0'],
            [$xp->evaluate('string(//div[@data-bulkbar]/@data-none)'), $xp->evaluate('string(//div[@data-bulkbar]/@data-one)'), $xp->evaluate('string(//div[@data-bulkbar]/@data-many)'),
                $xp->evaluate('string(//div[@data-bulkbar]/@aria-label)'), $xp->evaluate('string(//div[@data-bulkbar]/@data-max)')], 'app.js takes its words from the page: items, not products');
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'limit', 100), $page->text(), 'the limit is the setting\'s, and it counts variants');
        self::assertStringContainsString(Words::BY_ITEM_BULK['undo_note'], $page->text(), 'a match is undone one at a time, on its page, with a note (M53)');
        self::assertStringNotContainsString('unlink', implode(' ', array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'), iterator_to_array($xp->query('//button[@name="action"]')))));

        // "Tick all on this page" without app.js is a link that draws the page ticked.
        self::assertSame('/ui/review/products?all=1', $xp->evaluate('string(//a[@data-bulk-all-link]/@href)'));
        $all = $web->get('/ui/review/products', ['all' => '1']);
        self::assertSame([$alpha, $bravo], self::drawnTicked($all));
        self::assertSame(2, (new \DOMXPath($all->dom()))->query('//table[contains(@class, "by-product")]/tbody/tr[contains(@class, "picked")]')->length);
        self::assertSame(['1', '1'], [$all->form('/ui/review/products/bulk')['pick_' . $alpha], $all->form('/ui/review/products/bulk')['pick_' . $bravo]]);

        // A matching lead: the same ticks and the same bar.
        $lead = $this->signIn($this->uiUser('mapping_lead'))->get('/ui/review/products');
        self::assertSame(2, (new \DOMXPath($lead->dom()))->query('//input[@data-bulk-row]')->length);
        self::assertSame(3, (new \DOMXPath($lead->dom()))->query('//div[@data-bulkbar]//button[@name="action"]')->length);
        // People who only look, and an admin alone (I12): the list, no tick, no bar, no form.
        foreach (['viewer', 'admin'] as $job) {
            $look = $this->signIn($this->uiUser($job))->get('/ui/review/products', ['all' => '1']);
            self::assertSame(200, $look->status, $job);
            self::assertSame([$alpha, $bravo, $charlie], self::ids($look), $job);
            $lx = new \DOMXPath($look->dom());
            self::assertSame([0, 0, 0, 0], [$lx->query('//input[@type="checkbox"]')->length, $lx->query('//div[@data-bulkbar]')->length, $lx->query('//select[@name="on"]')->length,
                $lx->query('//td[contains(@class, "c-pick")] | //th[contains(@class, "c-pick")]')->length], $job);
            self::assertFalse($look->hasForm('/ui/review/products/bulk'), $job);
            self::assertStringNotContainsString(Words::BY_ITEM_BULK['undo_note'], $look->text(), $job);
        }
    }

    public function testThePreviewSaysWhatWillHappenToEveryVariantAndWritesNothingThenTheApplyDoesExactlyThat(): void
    {
        ['a' => $a, 'b' => $b] = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $q = fn (Caller $site, string $v, string $band, int $sku, string $title, array $p = []): int => $this->queued($site, $v, $band, $sku, ['product_title' => $title], $p);
        $plain = $this->item('legacy', 0, 'Plain kit');
        $pack = $this->item('legacy', 0, 'Pack kit');
        $prot = $this->item('strict', 0, 'Protected kit');
        $again = $this->item('legacy', 0, 'Again kit');
        $unsure = $this->item('legacy', 0, 'Unsure kit');
        $veto = $this->item('legacy', 0, 'Veto kit');
        $flag = $this->item('legacy', 0, 'Flag kit');
        $stale = $this->item('legacy', 0, 'Stale kit');
        $moved = $this->item('legacy', 0, 'Moved kit');
        $huge = $this->item('legacy', 0, 'Huge kit');
        $big = $this->item('legacy', 0, 'Big only kit');
        $l = [
            'plain' => $q($a, 'P1', 'Key', $plain, 'Plain on Alt'),
            'pack' => $q($a, 'P2', 'Key', $pack, 'Pack on Alt', ['ai_units_per_item' => 6]),
            'prot' => $q($a, 'P3', 'Check', $prot, 'Protected on Alt'),
            'again' => $q($a, 'P4', 'Check', $again, 'Again on Alt'),
            'unsure' => $q($a, 'P5', "Can't tell", $unsure, 'Unsure on Alt'),
            'veto' => $q($a, 'P6', 'Key', $veto, 'Veto on Alt', ['evidence' => ['target_vetoes' => ['strength']]]),
            'flag' => $q($a, 'P7', 'Key', $flag, 'Flag on Alt', ['flags' => ['two_person_confirm']]),
            'stale' => $q($a, 'P8', 'Key', $stale, 'Stale on Alt'),
            'moved' => $q($a, 'P9', 'Key', $moved, 'Moved on Alt'),
            // One sale would use more warehouse items than a match may say (the limit is DecisionService's): refused, in words.
            'huge' => $q($a, 'P10', 'Key', $huge, 'Huge on Alt', ['ai_units_per_item' => 2000]),
        ];
        $onBig = $q($b, 'Q1', 'Key', $big, 'Big only on Big');
        $plainBig = $q($b, 'Q2', 'Key', $plain, 'Plain on Big');
        // "Again" was marked "not this item" before: one at a time that match takes a second person; in bulk it is left to a person.
        $this->decide($lead, 'reject', $l['again'], ['sku_id' => $again, 'proposal_id' => $this->proposalOf($l['again'])]);

        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertStringStartsWith(Words::PRODUCT_STORE['rejected'] . ' Again on Alt', self::cellOf($page, $again, 'Alt Store')[0], 'the list says so in its cell');
        $form = self::ticked($page, [$plain, $pack, $prot, $again, $unsure, $veto, $flag, $stale, $moved, $huge, $big], 'link', 'alt');
        // While the page is open: someone decides one, and a new computer check replaces another's suggestion.
        $this->decide($lead, 'ignore', $l['stale'], ['reason' => 'placeholder', 'proposal_id' => $this->proposalOf($l['stale'])]);
        $this->propose($l['moved'], 'Key', $moved, [], false, 'later-run');

        $before = self::snapshot();
        $preview = $web->post('/ui/review/products/bulk', $form);
        self::assertSame(200, $preview->status, $preview->describe() . implode("\n", self::$log));
        self::assertSame($before, self::snapshot(), 'the preview writes nothing: no row, no changed row and no used-up id in any table (not even a batch)');
        self::assertSame(0, self::batches());
        self::assertSame('By item', self::currentTab($preview));
        $xp = new \DOMXPath($preview->dom());
        self::assertSame('Preview: confirm suggested matches', self::squash($xp->evaluate('string(//main//h1)')));
        self::assertSame('1 will be matched · 2 will wait for a second OK · 7 will be skipped', self::squash($xp->evaluate('string(//p[@class="alert-title"])')));
        $text = $preview->text();
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'scope', 'Alt Store', Words::say('BY_ITEM_BULK', 'selected_many', 11), Words::say('BY_ITEM_BULK', 'variants_many', 10)), $text, 'the chosen store, the items ticked, the variants: Big Store\'s are left out');
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'nothing_here_one', 'Alt Store'), $text, 'the item suggested on Big Store only');
        self::assertStringContainsString(Words::BY_ITEM_BULK['text_link'], $text);
        self::assertStringContainsString(Words::BY_ITEM_BULK['again'], $text);
        self::assertStringContainsString(Words::PAGE_INTRO['by_item_preview'][0], $text);
        $lines = self::lines($preview);
        self::assertSame(array_values($l), array_keys($lines), 'one line per item, store and variant, in the list\'s order; no variant of another store');
        self::assertSame(['item' => 'Plain kit', 'store' => 'Alt Store', 'variant' => 'Plain on Alt', 'outcome' => 'done', 'label' => Words::BY_ITEM_BULK['will_link'], 'why' => '', 'needs' => []],
            array_diff_key($lines[$l['plain']], ['field' => 1]));
        self::assertSame([['pending_second', Words::BY_ITEM_BULK['will_wait'], [Words::NEEDS_SECOND['units_per_item']]], ['pending_second', Words::BY_ITEM_BULK['will_wait'], [Words::NEEDS_SECOND['protected_sku']]]],
            array_map(static fn (int $id): array => [$lines[$id]['outcome'], $lines[$id]['label'], $lines[$id]['needs']], [$l['pack'], $l['prot']]), 'each with the reason for the second OK');
        $skipped = [$l['again'], $l['unsure'], $l['veto'], $l['flag'], $l['stale'], $l['moved'], $l['huge']];
        self::assertSame([
            Words::BY_ITEM_SKIP['rejected_before'],
            BulkController::why('band_not_allowed', ['band' => "Can't tell"], 'BY_ITEM_SKIP'),
            BulkController::why('vetoed', ['vetoes' => ['strength']], 'BY_ITEM_SKIP'),
            BulkController::why('flagged', ['flags' => ['two_person_confirm']], 'BY_ITEM_SKIP'),
            BulkController::why('changed', ['status' => 'ignored'], 'BY_ITEM_SKIP'),
            BulkController::why('suggestion_changed', [], 'BY_ITEM_SKIP'),
            'Not changed: ' . Words::say('MATCH_ERROR', 'bad_units', 1000),
        ], array_map(static fn (int $id): string => $lines[$id]['why'], $skipped), 'each skip with its reason, in the owner\'s words');
        self::assertStringContainsString('from 1 to 1,000.', $lines[$l['huge']]['why'], 'a refusal\'s number is filled in');
        self::assertStringNotContainsString('%', $lines[$l['huge']]['why']);
        foreach ($skipped as $id) {
            self::assertSame(['skipped', Words::BY_ITEM_BULK['will_skip'], []], [$lines[$id]['outcome'], $lines[$id]['label'], $lines[$id]['needs']]);
            self::assertStringEndsWith('.0', $lines[$id]['field'], 'a skipped line is not one of "these N"');
        }
        self::assertStringContainsString('different strength', $lines[$l['veto']]['why']);
        self::assertStringContainsString(Words::LISTING_STATUS['ignored'], $lines[$l['stale']]['why']);
        self::assertSame($plain . '.' . $this->proposalOf($l['plain']) . '.' . $this->version($l['plain']) . '.1', $lines[$l['plain']]['field']);
        self::assertSame(['Yes, do these 3', 'btn primary'], self::button($preview));
        self::assertSame(1, $xp->query('//form[contains(@action, "/bulk/apply")]//button')->length, 'one button');
        self::assertSame(['suggested', 'suggested', 'suggested'], [$this->link($l['plain'])['status'], $this->link($l['pack'])['status'], $this->link($onBig)['status']], 'nothing is decided yet');

        // The apply: the same rows again, checked again, then the result page of every bulk action.
        $apply = $preview->form('/ui/review/products/bulk/apply');
        self::assertSame(['link', 'alt'], [$apply['action'], $apply['on']]);
        $done = $web->post('/ui/review/products/bulk/apply', $apply);
        self::assertSame(303, $done->status, $done->describe() . implode("\n", self::$log));
        self::assertMatchesRegularExpression('#^/ui/review/batches/[0-9]+\?from=item$#', (string) $done->location());
        $batch = (int) substr((string) parse_url((string) $done->location(), PHP_URL_PATH), strlen('/ui/review/batches/'));
        self::assertSame(['mapped', $plain, 1], [$this->link($l['plain'])['status'], (int) $this->link($l['plain'])['sku_id'], (int) $this->link($l['plain'])['units_per_item']]);
        self::assertSame(['item', 'link', 10, (int) $a->channelId], array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v,
            array_values(self::$db->one('SELECT source, action, rows_asked, channel_id FROM mapping_batch WHERE id = ?', [$batch]) ?? [])));
        $rows = self::batchRows($batch);
        self::assertSame(array_values($l), array_keys($rows), 'every line of the preview is a row of the batch, in variant order');
        foreach ($lines as $id => $line) {
            self::assertSame($line['outcome'], $rows[$id]['outcome'], "{$line['variant']}: what the preview said is what was done");
        }
        self::assertSame(['rejected_before', 'band_not_allowed', 'vetoed', 'flagged', 'changed', 'suggestion_changed', 'refused'],
            array_map(static fn (int $id): ?string => $rows[$id]['skip_code'], $skipped));
        foreach (['pack' => ['units_per_item'], 'prot' => ['protected_sku']] as $key => $needs) {
            $d = self::$db->one('SELECT state, needs_second, bulk_batch_id, sku_id FROM match_decision WHERE id = ?', [$rows[$l[$key]]['decision_id']]);
            self::assertSame(['pending_second', $needs, 'screen:' . $batch], [$d['state'], json_decode((string) $d['needs_second'], true), $d['bulk_batch_id']], $key);
            self::assertSame('suggested', $this->link($l[$key])['status'], "{$key}: waits for a second matching lead, never matched by one person");
        }
        self::assertSame(['suggested', 'suggested', 'suggested', 'suggested', 'ignored', 'suggested', 'suggested'], array_map(fn (int $id): string => $this->link($id)['status'], $skipped),
            'a skipped variant is never changed');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_decision WHERE listing_id = ? AND bulk_batch_id = ?', [$l['again'], 'screen:' . $batch]),
            'a pair marked not a match before is not sent to Second approval in bulk');
        self::assertSame(['suggested', 'suggested'], [$this->link($onBig)['status'], $this->link($plainBig)['status']], 'the other store was not chosen');

        $result = $web->follow($done);
        self::assertSame(200, $result->status, $result->describe() . implode("\n", self::$log));
        self::assertSame('By item', self::currentTab($result), 'the result of a bulk action started on By Item stays under By Item');
        $rt = $result->text();
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('BULK_DONE', 'link', 1), 2, 7), $rt);
        self::assertStringContainsString(Words::BY_ITEM_SKIP['rejected_before'], $rt);
        self::assertStringContainsString('Not changed: ' . Words::say('MATCH_ERROR', 'bad_units', 1000), $rt, 'the refusal\'s number is filled in on the result page too');
        self::assertStringNotContainsString('%s', $rt);
        self::assertStringContainsString(Words::BY_ITEM_BULK['result_skipped_text'], $rt);
        self::assertStringContainsString(Words::BY_ITEM_BULK['result_ticked'], $rt);
        self::assertStringContainsString(BulkController::why('vetoed', ['vetoes' => ['strength']], 'BY_ITEM_SKIP'), $rt, 'the skipped ones at the end, each with why');
        self::assertStringContainsString(BulkController::why('changed', ['status' => 'ignored'], 'BY_ITEM_SKIP'), $rt);
        self::assertStringNotContainsString('website product', strtolower($rt), 'items and variants on this result page too');
        $rx = new \DOMXPath($result->dom());
        self::assertSame('/ui/review/products', $rx->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));
        self::assertSame(Words::MENU['by_product'], self::squash($rx->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a)')));
        self::assertSame(7, $rx->query('//table[contains(@class, "bulk-skipped")]/tbody/tr')->length);
        self::assertSame(10, $rx->query('//table[contains(@class, "bulk-rows")]/tbody/tr')->length);
        // The same batch opened without the mark of where it came from is still By Item's (its record says so).
        self::assertSame('/ui/review/products', (new \DOMXPath($web->get('/ui/review/batches/' . $batch)->dom()))->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));
        // Second approval lists the two, from this bulk action.
        self::assertStringContainsString(Words::say('BULK', 'from_batch', $batch), $web->get('/ui/review', ['queue' => 'pending'])->text());
    }

    public function testNotAMatchAndSendBackShowTheSamePreviewAndActOnlyOnItsButton(): void
    {
        ['a' => $a] = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $one = $this->item('legacy', 0, 'One kit');
        $two = $this->item('legacy', 0, 'Two kit');
        $clash = $this->item('legacy', 0, 'Clash kit');
        $said = $this->item('legacy', 0, 'Said kit');
        $l1 = $this->queued($a, 'R1', 'Check', $one, ['product_title' => 'One on Alt']);
        $l2 = $this->queued($a, 'R2', "Can't tell", $two, ['product_title' => 'Two on Alt']);
        $l3 = $this->queued($a, 'R3', 'Conflict', $clash, ['product_title' => 'Clash on Alt']);
        $l4 = $this->queued($a, 'R4', 'Check', $said, ['product_title' => 'Said on Alt']);
        $this->decide($lead, 'reject', $l4, ['sku_id' => $said, 'proposal_id' => $this->proposalOf($l4)]);

        // A matcher: "Not a match…" for every strength; Clues disagree is a matching lead's, as on the lists.
        $mapper = $this->signIn($this->uiUser('mapper'));
        $page = $mapper->get('/ui/review/products');
        $before = self::snapshot();
        $preview = $mapper->post('/ui/review/products/bulk', self::ticked($page, [$one, $two, $clash, $said], 'reject'));
        self::assertSame(200, $preview->status, $preview->describe() . implode("\n", self::$log));
        self::assertSame($before, self::snapshot(), 'the first press saves nothing');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject'), 'only the one made before');
        $xp = new \DOMXPath($preview->dom());
        self::assertSame('Preview: not a match', self::squash($xp->evaluate('string(//main//h1)')));
        self::assertSame('2 will be marked not a match · 2 will be skipped', self::squash($xp->evaluate('string(//p[@class="alert-title"])')));
        self::assertStringContainsString(Words::BY_ITEM_BULK['text_reject'], $preview->text(), 'this page is the second step of "Not a match"');
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'scope', Words::BY_ITEM_BULK['all_stores_lower'], Words::say('BY_ITEM_BULK', 'selected_many', 4), Words::say('BY_ITEM_BULK', 'variants_many', 4)), $preview->text());
        self::assertSame(1, $xp->query('//div[contains(@class, "confirm-step")]')->length);
        self::assertSame(['Yes, these 2 are not a match', 'btn risky'], self::button($preview), 'the risky button (red words: DS1), stating how many');
        $lines = self::lines($preview);
        self::assertSame([['done', Words::BY_ITEM_BULK['will_reject'], ''], ['done', Words::BY_ITEM_BULK['will_reject'], ''],
            ['skipped', Words::BY_ITEM_BULK['will_skip'], Words::BY_ITEM_SKIP['lead_only']], ['skipped', Words::BY_ITEM_BULK['will_skip'], Words::BY_ITEM_SKIP['already_rejected']]],
            array_map(static fn (int $id): array => [$lines[$id]['outcome'], $lines[$id]['label'], $lines[$id]['why']], [$l1, $l2, $l3, $l4]));
        $done = $mapper->post('/ui/review/products/bulk/apply', $preview->form('/ui/review/products/bulk/apply'));
        self::assertSame(303, $done->status, $done->describe() . implode("\n", self::$log));
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM match_reject'));
        self::assertSame([1, 1, 0], array_map(static fn (array $p): int => (int) self::$db->value('SELECT COUNT(*) FROM match_reject WHERE listing_id = ? AND sku_id = ?', $p),
            [[$l1, $one], [$l2, $two], [$l3, $clash]]));
        self::assertSame(['suggested', 'suggested', 'suggested'], [$this->link($l1)['status'], $this->link($l2)['status'], $this->link($l3)['status']], 'each stays suggested for another choice');
        $batch = (int) substr((string) parse_url((string) $done->location(), PHP_URL_PATH), strlen('/ui/review/batches/'));
        self::assertSame(['done', 'done', 'lead_only', 'already_rejected'], array_map(static fn (array $r): string => $r['skip_code'] ?? $r['outcome'], array_values(self::batchRows($batch))));
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('BULK_DONE', 'reject', 2), 0, 2), $mapper->follow($done)->text());

        // A matching lead: Clues disagree too. "Send back for matching…": the suggestion is closed, the variant waits for the next check.
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $page = $web->get('/ui/review/products');
        $before = self::snapshot();
        $preview = $web->post('/ui/review/products/bulk', self::ticked($page, [$one, $clash], 'send_back', 'alt'));
        self::assertSame(200, $preview->status, $preview->describe() . implode("\n", self::$log));
        self::assertSame($before, self::snapshot());
        self::assertSame('2 will be sent back for matching · 0 will be skipped', self::squash((new \DOMXPath($preview->dom()))->evaluate('string(//p[@class="alert-title"])')));
        self::assertSame(['Yes, send these 2 back for matching', 'btn risky'], self::button($preview));
        self::assertSame([Words::BY_ITEM_BULK['will_send_back'], Words::BY_ITEM_BULK['will_send_back']], array_column(self::lines($preview), 'label'));
        $done = $web->post('/ui/review/products/bulk/apply', $preview->form('/ui/review/products/bulk/apply'));
        self::assertSame(303, $done->status, $done->describe() . implode("\n", self::$log));
        self::assertSame(['unmapped', 'unmapped', 'suggested'], [$this->link($l1)['status'], $this->link($l3)['status'], $this->link($l2)['status']]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE open_listing_id IN (?, ?)', [$l1, $l3]));
        // Nothing left to act on for those two: they have no tick any more.
        $after = $web->get('/ui/review/products');
        self::assertSame(['pick_' . $two, 'pick_' . $said], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            iterator_to_array((new \DOMXPath($after->dom()))->query('//input[@data-bulk-row]'))));
    }

    public function testAVariantThatChangedBetweenThePreviewAndTheApplyIsSkippedAndNeverAppliedToSomethingElse(): void
    {
        ['a' => $a] = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $x = $this->item('legacy', 0, 'Xray kit');
        $y = $this->item('legacy', 0, 'Yankee kit');
        $z = $this->item('legacy', 0, 'Zulu kit');
        $w = $this->item('legacy', 0, 'Whisky kit');
        $u = $this->item('legacy', 0, 'Uniform kit');
        $lx = $this->queued($a, 'X1', 'Key', $x, ['product_title' => 'Xray on Alt']);
        $ly = $this->queued($a, 'Y1', 'Key', $y, ['product_title' => 'Yankee on Alt']);
        $lz = $this->queued($a, 'Z1', 'Key', $z, ['product_title' => 'Zulu on Alt']);
        $lu = $this->queued($a, 'U1', "Can't tell", $u, ['product_title' => 'Uniform on Alt']);
        $web = $this->signIn($this->uiUser('mapper'));
        $preview = $web->post('/ui/review/products/bulk', self::ticked($web->get('/ui/review/products'), [$x, $y, $z, $u], 'link'));
        self::assertSame(200, $preview->status, $preview->describe() . implode("\n", self::$log));
        self::assertSame(['done', 'done', 'done', 'skipped'], array_column(self::lines($preview), 'outcome'));
        self::assertSame(['Yes, do these 3', 'btn primary'], self::button($preview));
        $apply = $preview->form('/ui/review/products/bulk/apply');
        // Between the preview and the button: someone ignores one; a new computer check suggests ANOTHER item for the second; and the
        // owner lets "Not sure" be confirmed together, so the line the preview skipped would now pass every check.
        $this->decide($lead, 'ignore', $lx, ['reason' => 'placeholder', 'proposal_id' => $this->proposalOf($lx)]);
        $this->propose($ly, 'Key', $w, [], false, 'later-run');
        $this->setting('mapping.bulk_confirm_bands', '"Key,Check,Can\'t tell"');

        $done = $web->post('/ui/review/products/bulk/apply', $apply);
        self::assertSame(303, $done->status, $done->describe() . implode("\n", self::$log));
        $batch = (int) substr((string) parse_url((string) $done->location(), PHP_URL_PATH), strlen('/ui/review/batches/'));
        self::assertSame([$lx => 'changed', $ly => 'suggestion_changed', $lz => null, $lu => 'changed'], array_map(static fn (array $r): ?string => $r['skip_code'], self::batchRows($batch)));
        self::assertSame(['ignored', 'suggested', 'mapped', 'suggested'], [$this->link($lx)['status'], $this->link($ly)['status'], $this->link($lz)['status'], $this->link($lu)['status']]);
        self::assertNull($this->link($ly)['sku_id'], 'never matched to the item the new suggestion names: the person did not see it');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE listing_id IN (?, ?) AND action = 'link'", [$ly, $lu]),
            'and the line the preview skipped was not acted on, whatever it looks like now');
        $text = $web->follow($done)->text();
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('BULK_DONE', 'link', 1), 0, 3), $text);
        self::assertStringContainsString(BulkController::why('changed', ['status' => 'ignored'], 'BY_ITEM_SKIP'), $text, '"changed since you looked"');
        self::assertStringContainsString(BulkController::why('changed', ['status' => 'suggested'], 'BY_ITEM_SKIP'), $text);
        self::assertStringContainsString(Words::BY_ITEM_SKIP['suggestion_changed'], $text);
        self::assertStringStartsWith('It is not as it was when you looked', Words::BY_ITEM_SKIP['changed']);

        // A form that puts a variant under another item than its suggestion names (no page of ours draws that) is skipped the
        // same way, in the preview and on the apply: a variant is only ever matched to the item it was shown under.
        $lv = $this->queued($a, 'V1', 'Key', $w, ['product_title' => 'Whisky on Alt']);
        $crafted = ['csrf' => $this->token($web), 'action' => 'link', 'pick_' . $z => '1', 'cells_' . $z => $this->cell($lv)];
        $preview = $web->post('/ui/review/products/bulk', $crafted);
        self::assertSame(200, $preview->status, $preview->describe());
        self::assertSame([['skipped', Words::BY_ITEM_SKIP['suggestion_changed']]], array_map(static fn (array $l): array => [$l['outcome'], $l['why']], array_values(self::lines($preview))));
        self::assertNull(self::button($preview), 'nothing to do: no button');
        self::assertStringContainsString(Words::BY_ITEM_BULK['none_to_do'], $preview->text());
        $r = $web->post('/ui/review/products/bulk/apply', ['csrf' => $this->token($web), 'action' => 'link', 'row_' . $lv => $z . '.' . $this->proposalOf($lv) . '.' . $this->version($lv) . '.1']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['suggested', null], [$this->link($lv)['status'], $this->link($lv)['sku_id']]);
        // A variant number that names nothing is a skipped line, not an error.
        $gone = $web->post('/ui/review/products/bulk', ['csrf' => $this->token($web), 'action' => 'link', 'pick_' . $z => '1', 'cells_' . $z => '999999.5.0']);
        self::assertSame(200, $gone->status, $gone->describe());
        self::assertSame([Words::BY_ITEM_SKIP['unknown_listing']], array_column(self::lines($gone), 'why'));
    }

    public function testTheLimitCountsVariantsAndTheStoreChoiceNarrowsThem(): void
    {
        ['a' => $a, 'b' => $b] = $this->stores();
        $sku = $this->item('legacy', 0, 'Many kit');
        $other = $this->item('legacy', 0, 'Other kit');
        $a1 = $this->queued($a, 'L1', 'Key', $sku, ['product_title' => 'Many on Alt one']);
        $a2 = $this->queued($a, 'L2', 'Key', $sku, ['product_title' => 'Many on Alt two']);
        $b1 = $this->queued($b, 'L3', 'Key', $sku, ['product_title' => 'Many on Big']);
        $b2 = $this->queued($b, 'L4', 'Key', $other, ['product_title' => 'Other on Big']);
        $this->setting('mapping.bulk_max_rows', '2');
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $page = $web->get('/ui/review/products');
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'limit', 2), $page->text());

        // One ticked item, three suggested variants on all stores: more than the setting allows. Refused whole, in words.
        $before = self::snapshot();
        $r = $web->post('/ui/review/products/bulk', self::ticked($page, [$sku], 'link'));
        self::assertSame([422, 'too_many_rows'], [$r->status, $r->errorCode()], $r->describe());
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'too_many_rows', 3, Words::BY_ITEM_BULK['all_stores_lower'], 2), $r->text());
        self::assertSame($before, self::snapshot());
        self::assertContains('/ui/review/products?ticked=' . $sku, $r->hrefs(), 'and the way back keeps the tick');
        // On one store it is two variants: allowed. Two items there are two variants too.
        $alt = $web->post('/ui/review/products/bulk', self::ticked($page, [$sku, $other], 'link', 'alt'));
        self::assertSame(200, $alt->status, $alt->describe());
        self::assertSame([$a1, $a2], array_keys(self::lines($alt)));
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'nothing_here_one', 'Alt Store'), $alt->text());
        $big = $web->post('/ui/review/products/bulk', self::ticked($page, [$sku, $other], 'link', 'vbig'));
        self::assertSame([$b1, $b2], array_keys(self::lines($big)));
        self::assertSame(['Big Store', 'Big Store'], array_column(self::lines($big), 'store'));
        self::assertSame('vbig', $big->form('/ui/review/products/bulk/apply')['on']);
        // A store with nothing suggested for the ticked items, a store that is none, and no tick at all.
        $none = $web->post('/ui/review/products/bulk', self::ticked($page, [$sku], 'link', 'cee'));
        self::assertSame([422, 'nothing_to_do'], [$none->status, $none->errorCode()], $none->describe());
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'nothing_on', 'Cee Store'), $none->text());
        $unknown = $web->post('/ui/review/products/bulk', self::ticked($page, [$sku], 'link', 'nosuch'));
        self::assertSame([400, 'unknown_store'], [$unknown->status, $unknown->errorCode()], $unknown->describe());
        self::assertStringContainsString(Words::ERROR['unknown_store'], $unknown->text());
        $empty = $web->post('/ui/review/products/bulk', self::ticked($page, [], 'link'));
        self::assertSame([422, 'nothing_ticked'], [$empty->status, $empty->errorCode()]);
        self::assertStringContainsString(Words::BY_ITEM_BULK['nothing_ticked'], $empty->text());
        // The apply checks the limit and the store again: more rows than allowed are refused whole, a variant of another store than
        // the chosen one is skipped.
        $row = fn (int $l, int $item): string => $item . '.' . $this->proposalOf($l) . '.' . $this->version($l) . '.1';
        $r = $web->post('/ui/review/products/bulk/apply', ['csrf' => $this->token($web), 'action' => 'link', 'row_' . $a1 => $row($a1, $sku), 'row_' . $a2 => $row($a2, $sku),
            'row_' . $b1 => $row($b1, $sku)]);
        self::assertSame([422, 'too_many_rows'], [$r->status, $r->errorCode()], $r->describe());
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'too_many_rows', 3, Words::BY_ITEM_BULK['all_stores_lower'], 2), $r->text());
        self::assertSame(0, self::batches());
        $r = $web->post('/ui/review/products/bulk/apply', ['csrf' => $this->token($web), 'action' => 'send_back', 'on' => 'alt', 'row_' . $a1 => $row($a1, $sku), 'row_' . $b1 => $row($b1, $sku)]);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['unmapped', 'suggested', 'suggested'], [$this->link($a1)['status'], $this->link($b1)['status'], $this->link($a2)['status']]);
        self::assertSame('other_store', self::batchRows((int) self::$db->value('SELECT MAX(id) FROM mapping_batch'))[$b1]['skip_code']);
        self::assertSame(2, BulkDecisions::maxRows(self::$db));
    }

    public function testBackFromThePreviewIsTheListWithItsFilterItsPageItsRowsAndItsTicks(): void
    {
        ['a' => $a] = $this->stores();
        $fill = [];
        for ($i = 1; $i <= 52; $i++) {
            $fill[] = self::makeSku(sprintf('Filler kit %02d', $i));
        }
        $late = $this->item('legacy', 0, 'Late kit');
        $last = $this->item('legacy', 0, 'Last kit');
        $l1 = $this->queued($a, 'T1', 'Key', $late, ['product_title' => 'Late on Alt']);
        $l2 = $this->queued($a, 'T2', 'Key', $last, ['product_title' => 'Last on Alt']);
        $this->queued($a, 'T3', 'Key', $fill[0], ['product_title' => 'First on Alt']);
        $web = $this->signIn($this->uiUser('mapper'));
        // Page 2 of a searched list: the two items at its end are ticked, for Alt Store.
        $page = $web->get('/ui/review/products', ['q' => 'kit', 'page' => '2']);
        self::assertSame([$fill[50], $fill[51], $late, $last], self::ids($page));
        $preview = $web->post('/ui/review/products/bulk', self::ticked($page, [$late, $last], 'link', 'alt'));
        self::assertSame(200, $preview->status, $preview->describe() . implode("\n", self::$log));
        $back = "/ui/review/products?q=kit&page=2&on=alt&ticked={$late}.{$last}";
        $xp = new \DOMXPath($preview->dom());
        self::assertSame($back, $xp->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));
        self::assertSame([$back, Words::BY_ITEM_BULK['back']], [$xp->evaluate('string(//form[contains(@action, "/bulk/apply")]//p[contains(@class, "actions")]/a/@href)'),
            self::squash($xp->evaluate('string(//form[contains(@action, "/bulk/apply")]//p[contains(@class, "actions")]/a)'))]);
        $again = $web->get('/ui/review/products', ['q' => 'kit', 'page' => '2', 'on' => 'alt', 'ticked' => "{$late}.{$last}"]);
        self::assertSame(200, $again->status, $again->describe());
        self::assertSame([$fill[50], $fill[51], $late, $last], self::ids($again), 'the same filter and page');
        self::assertSame([$late, $last], self::drawnTicked($again), 'the same ticks');
        self::assertSame('alt', $again->form('/ui/review/products/bulk')['on'], 'the same store');
        self::assertSame(['kit', '2'], [$again->form('/ui/review/products/bulk')['q'], $again->form('/ui/review/products/bulk')['page']]);
        // With another filter and number of rows: all of it travels, and the result page leads back to that list too.
        $page = $web->get('/ui/review/products', ['cov' => 'suggested', 'n' => '100']);
        self::assertSame([$fill[0], $late, $last], self::ids($page));
        $preview = $web->post('/ui/review/products/bulk', self::ticked($page, [$late], 'link'));
        self::assertSame("/ui/review/products?cov=suggested&n=100&ticked={$late}", (new \DOMXPath($preview->dom()))->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));
        $apply = $preview->form('/ui/review/products/bulk/apply');
        self::assertSame(['suggested', '100', (string) $late], [$apply['cov'], $apply['n'], $apply['ticked']]);
        $done = $web->post('/ui/review/products/bulk/apply', $apply);
        self::assertSame(303, $done->status, $done->describe());
        self::assertMatchesRegularExpression('#^/ui/review/batches/[0-9]+\?from=item&cov=suggested&n=100$#', (string) $done->location());
        $result = $web->follow($done);
        self::assertSame('/ui/review/products?cov=suggested&n=100', (new \DOMXPath($result->dom()))->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));
        self::assertSame(['mapped', 'suggested'], [$this->link($l1)['status'], $this->link($l2)['status']]);
        // Ticks that are no item numbers, or of items not on the page, tick nothing; an unknown store chooses none.
        foreach (['x', '0', "{$last},{$late}", str_repeat('9', 12), '1.2.3.x', '//evil.example'] as $odd) {
            $r = $web->get('/ui/review/products', ['ticked' => $odd, 'on' => 'nosuch']);
            self::assertSame([200, []], [$r->status, self::drawnTicked($r)], $odd);
            self::assertSame('', $r->form('/ui/review/products/bulk')['on']);
        }
        self::assertSame([], self::drawnTicked($web->get('/ui/review/products', ['ticked' => (string) $last])), 'an item of another page');
        self::assertSame([$last], self::drawnTicked($web->get('/ui/review/products', ['ticked' => "{$fill[3]}.{$last}", 'page' => '2'])), 'an item with nothing suggested has no box');
        // A link back never leaves By Item, whatever the form said.
        $evil = $web->post('/ui/review/products/bulk', ['cov' => '//evil.example', 'q' => "x\r\nLocation: //evil.example", 'page' => '//evil.example', 'n' => 'javascript:alert(1)']
            + self::ticked($web->get('/ui/review/products', ['page' => '2']), [$last], 'link'));
        self::assertSame(200, $evil->status, $evil->describe());
        self::assertSame('/ui/review/products?q=x%0D%0ALocation%3A%20%2F%2Fevil.example&ticked=' . $last, (new \DOMXPath($evil->dom()))->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));
    }

    /**
     * M59 (the owner's "skip anything doubtful, finish the rest, show the skipped at the end"): a confirm from By Item leaves to a
     * person, one at a time, a pair marked not a match before, an item that already has a variant (or a match waiting) on that
     * store, and an item with several suggested variants on one store. The list says "Marked not a match" where that is all a store
     * suggests, and "tick all" leaves such a row alone. "Not a match…" and "Send back for matching…" still take every suggestion.
     */
    public function testAConfirmLeavesTheDoubtfulSuggestionsToAPersonAndTheListMarksTheOnesSaidNoToBefore(): void
    {
        ['a' => $a, 'b' => $b] = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $q = fn (Caller $site, string $v, int $sku, string $title, string $band = 'Key'): int => $this->queued($site, $v, $band, $sku, ['product_title' => $title]);
        $has = $this->item('legacy', 0, 'Has kit');       // matched on Alt already; suggested there again, and on Big
        $wait = $this->item('legacy', 0, 'Wait kit');     // a match waits for a second OK on Alt; suggested there again
        $twin = $this->item('legacy', 0, 'Twin kit');     // two suggestions on Alt, one on Big
        $said = $this->item('legacy', 0, 'Said kit');     // its only suggestion was marked not a match before
        $mix = $this->item('legacy', 0, 'Mix kit');       // two suggestions on Big, one of them marked not a match before
        $ok = $this->item('legacy', 0, 'Plain kit');      // one suggestion: nothing doubtful
        $this->profiled($a, 'H0', ['product_title' => 'Has matched on Alt'], $has);
        $s1 = $q($a, 'H1', $has, 'Has again on Alt');
        $s2 = $q($b, 'H2', $has, 'Has on Big');
        $w1 = $q($a, 'W1', $wait, 'Wait pack on Alt');
        self::assertSame('pending_second', $this->decide($lead, 'link', $w1, ['sku_id' => $wait, 'units_per_item' => 6, 'proposal_id' => $this->proposalOf($w1)])['state']);
        $w2 = $q($a, 'W2', $wait, 'Wait single on Alt');
        $t1 = $q($a, 'T1', $twin, 'Twin one on Alt');
        $t2 = $q($a, 'T2', $twin, 'Twin two on Alt', 'Check');
        $t3 = $q($b, 'T3', $twin, 'Twin on Big');
        $r1 = $q($a, 'R1', $said, 'Said on Alt');
        $m1 = $q($b, 'M1', $mix, 'Mix no on Big');
        $m2 = $q($b, 'M2', $mix, 'Mix maybe on Big', 'Check');
        $o1 = $q($a, 'O1', $ok, 'Plain on Alt');
        foreach ([[$r1, $said], [$m1, $mix]] as [$listing, $sku]) {
            $this->decide($lead, 'reject', $listing, ['sku_id' => $sku, 'proposal_id' => $this->proposalOf($listing)]);
        }

        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        // The list: a cell whose only suggestion was said no to before reads "Marked not a match", with its Review link; a cell
        // that has another suggestion too stays "Suggested" and names that one first.
        [$text, $links] = self::cellOf($page, $said, 'Alt Store');
        self::assertStringStartsWith(Words::PRODUCT_STORE['rejected'] . ' Said on Alt ' . Words::BAND['Key'], $text);
        self::assertStringNotContainsString(Words::PRODUCT_STORE['suggested'], $text);
        self::assertSame("/ui/review/listing/{$r1}?via=product&product={$said}&channel=alt", $links[Words::BY_PRODUCT['review']]);
        self::assertStringStartsWith(Words::PRODUCT_STORE['suggested'] . ' Mix maybe on Big ' . Words::BAND['Check'] . ' ' . Words::BY_PRODUCT['more_one'], self::cellOf($page, $mix, 'Big Store')[0]);
        self::assertStringStartsWith(Words::PRODUCT_STORE['matched'] . ' Has matched on Alt', self::cellOf($page, $has, 'Alt Store')[0]);
        // Its row can be ticked by hand (to send the suggestion back, say), but "tick all" leaves it alone; the page says so.
        $xp = new \DOMXPath($page->dom());
        self::assertSame(['pick_' . $said], array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'), iterator_to_array($xp->query('//input[@data-bulk-row][@data-bulk-manual]'))),
            'app.js leaves a box marked so out of "select all"');
        self::assertSame(6, $xp->query('//input[@data-bulk-row]')->length);
        self::assertStringContainsString(Words::BY_ITEM_BULK['manual_note'], $page->text());
        self::assertSame([$has, $wait, $twin, $mix, $ok], self::drawnTicked($web->get('/ui/review/products', ['all' => '1'])), 'without the script too');
        self::assertSame([$said], self::drawnTicked($web->get('/ui/review/products', ['ticked' => (string) $said])), 'a tick of the person\'s own comes back from a preview');
        // In the file a variant said no to before carries that note, in a cell that is otherwise "Suggested".
        $csv = $web->get('/ui/review/products.csv')->body;
        self::assertStringContainsString(Words::PRODUCT_STORE['rejected'] . ': R1 (' . Words::BAND['Key'] . '; ' . Words::PRODUCT_STORE['rejected'] . ')', $csv);
        self::assertStringContainsString(Words::PRODUCT_STORE['suggested'] . ': M2 (' . Words::BAND['Check'] . '), M1 (' . Words::BAND['Key'] . '; ' . Words::PRODUCT_STORE['rejected'] . ')', $csv);

        // Confirm, every item ticked (the marked one by hand), all stores.
        $before = self::snapshot();
        $preview = $web->post('/ui/review/products/bulk', self::ticked($page, [$has, $wait, $twin, $said, $mix, $ok], 'link'));
        self::assertSame(200, $preview->status, $preview->describe() . implode("\n", self::$log));
        self::assertSame($before, self::snapshot());
        self::assertSame('3 will be matched · 0 will wait for a second OK · 7 will be skipped', self::squash((new \DOMXPath($preview->dom()))->evaluate('string(//p[@class="alert-title"])')));
        self::assertStringContainsString(Words::BY_ITEM_BULK['text_link'], $preview->text(), 'the page says what a confirm leaves to a person');
        $lines = self::lines($preview);
        $onAlt = static fn (string $item): string => sprintf(Words::BY_ITEM_SKIP['item_on_store'], $item, 'Alt Store');
        $expected = [
            $s1 => ['skipped', $onAlt('Has kit')],                                                    // the item is matched on Alt already
            $s2 => ['done', ''],
            $w2 => ['skipped', $onAlt('Wait kit')],                                                   // a match waits for a second OK there
            $t1 => ['skipped', sprintf(Words::BY_ITEM_SKIP['several_suggested'], 'Twin kit', 'Alt Store')],
            $t2 => ['skipped', sprintf(Words::BY_ITEM_SKIP['several_suggested'], 'Twin kit', 'Alt Store')],
            $t3 => ['done', ''],
            $r1 => ['skipped', Words::BY_ITEM_SKIP['rejected_before']],
            $m1 => ['skipped', Words::BY_ITEM_SKIP['rejected_before']],
            $m2 => ['skipped', sprintf(Words::BY_ITEM_SKIP['several_suggested'], 'Mix kit', 'Big Store')],
            $o1 => ['done', ''],
        ];
        ksort($expected);
        $said2 = array_map(static fn (array $l): array => [$l['outcome'], $l['why']], $lines);
        ksort($said2);
        self::assertSame($expected, $said2);
        self::assertSame('Has kit already has a variant on Alt Store: open it and decide.', $lines[$s1]['why'], 'the reviewer\'s words');
        self::assertArrayNotHasKey($w1, $lines, 'the variant whose match waits is not a suggestion any more');
        self::assertSame(['Yes, do these 3', 'btn primary'], self::button($preview));

        $done = $web->post('/ui/review/products/bulk/apply', $preview->form('/ui/review/products/bulk/apply'));
        self::assertSame(303, $done->status, $done->describe() . implode("\n", self::$log));
        $batch = (int) substr((string) parse_url((string) $done->location(), PHP_URL_PATH), strlen('/ui/review/batches/'));
        $rows = self::batchRows($batch);
        self::assertSame([$s1 => 'item_on_store', $s2 => null, $w2 => 'item_on_store', $t1 => 'several_suggested', $t2 => 'several_suggested', $t3 => null, $r1 => 'rejected_before',
            $m1 => 'rejected_before', $m2 => 'several_suggested', $o1 => null], array_map(static fn (array $r): ?string => $r['skip_code'], $rows), 'the apply does what the preview said');
        self::assertSame(['mapped', 'mapped', 'mapped'], [$this->link($s2)['status'], $this->link($t3)['status'], $this->link($o1)['status']]);
        foreach ([$s1, $w2, $t1, $t2, $r1, $m1, $m2] as $left) {
            self::assertSame(['suggested', null], [$this->link($left)['status'], $this->link($left)['sku_id']], "variant {$left} is left for a person");
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_decision WHERE pending_listing_id IS NOT NULL'), 'nothing new waits for a second OK: only the one from before');
        $result = $web->follow($done)->text();
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('BULK_DONE', 'link', 3), 0, 7), $result);
        foreach ([$onAlt('Has kit'), $onAlt('Wait kit'), sprintf(Words::BY_ITEM_SKIP['several_suggested'], 'Twin kit', 'Alt Store'), Words::BY_ITEM_SKIP['rejected_before']] as $why) {
            self::assertStringContainsString($why, $result, 'the skipped ones at the end, each with why');
        }
        // A row sent to the apply as "one of these N" although the preview skipped it (a crafted form) is checked there the same way.
        $forced = $web->post('/ui/review/products/bulk/apply', ['csrf' => $this->token($web), 'action' => 'link',
            'row_' . $s1 => $has . '.' . $this->proposalOf($s1) . '.' . $this->version($s1) . '.1', 'row_' . $r1 => $said . '.' . $this->proposalOf($r1) . '.' . $this->version($r1) . '.1']);
        self::assertSame(303, $forced->status, $forced->describe());
        self::assertSame(['suggested', 'suggested'], [$this->link($s1)['status'], $this->link($r1)['status']]);

        // "Not a match…" and "Send back for matching…" take every open suggestion of the ticked items, as before.
        $page = $web->get('/ui/review/products');
        $preview = $web->post('/ui/review/products/bulk', self::ticked($page, [$has, $wait, $twin, $said, $mix], 'reject'));
        self::assertSame('5 will be marked not a match · 2 will be skipped', self::squash((new \DOMXPath($preview->dom()))->evaluate('string(//p[@class="alert-title"])')), $preview->describe());
        self::assertSame([$r1 => Words::BY_ITEM_SKIP['already_rejected'], $m1 => Words::BY_ITEM_SKIP['already_rejected']],
            array_filter(array_map(static fn (array $l): string => $l['why'], self::lines($preview)), static fn (string $w): bool => $w !== ''));
        $preview = $web->post('/ui/review/products/bulk', self::ticked($page, [$has, $wait, $twin, $said, $mix], 'send_back'));
        self::assertSame('7 will be sent back for matching · 0 will be skipped', self::squash((new \DOMXPath($preview->dom()))->evaluate('string(//p[@class="alert-title"])')), $preview->describe());
        self::assertSame(303, $web->post('/ui/review/products/bulk/apply', $preview->form('/ui/review/products/bulk/apply'))->status);
        self::assertSame(array_fill(0, 7, 'unmapped'), array_map(fn (int $id): string => $this->link($id)['status'], [$s1, $w2, $t1, $t2, $r1, $m1, $m2]));
    }

    /**
     * A ticked row sends every suggested variant its row showed, however many (review finding: the first version cut them at 50
     * without a word, and the store choice then said "no suggestion" where the page showed some). What bounds a request is the
     * limit of the setting, counted after the store is chosen. An item with more than the limit can ever be has no tick box.
     */
    public function testATickedRowSendsEverySuggestionItShowedAndOnlyTheLimitBoundsARequest(): void
    {
        ['a' => $a, 'b' => $b] = $this->stores();
        $wide = $this->item('legacy', 0, 'Wide kit');
        $onAlt = $this->manySuggested($a, $wide, 55, 'WA');
        $onBig = $this->manySuggested($b, $wide, 5, 'WB');
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        self::assertStringContainsString(Words::say('BY_PRODUCT', 'more_many', 4), self::cellOf($page, $wide, 'Big Store')[0], 'the page shows Big Store\'s five');
        $form = $page->form('/ui/review/products/bulk');
        self::assertCount(60, explode(' ', $form['cells_' . $wide]), 'every one of them travels with the tick: none is cut');
        // The store whose variants came after the first fifty: its five are there (it said "none of the ticked items has a suggestion").
        $big = $web->post('/ui/review/products/bulk', self::ticked($page, [$wide], 'send_back', 'vbig'));
        self::assertSame(200, $big->status, $big->describe() . implode("\n", self::$log));
        self::assertSame($onBig, array_keys(self::lines($big)));
        self::assertSame('5 will be sent back for matching · 0 will be skipped', self::squash((new \DOMXPath($big->dom()))->evaluate('string(//p[@class="alert-title"])')));
        // All stores: all sixty are counted and shown, and the limit of the setting is what refuses.
        $all = $web->post('/ui/review/products/bulk', self::ticked($page, [$wide], 'send_back'));
        self::assertSame([...$onAlt, ...$onBig], array_keys(self::lines($all)));
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'scope', Words::BY_ITEM_BULK['all_stores_lower'], Words::BY_ITEM_BULK['selected_one'], Words::say('BY_ITEM_BULK', 'variants_many', 60)), $all->text());
        $this->setting('mapping.bulk_max_rows', '50');
        $refused = $web->post('/ui/review/products/bulk', self::ticked($page, [$wide], 'send_back'));
        self::assertSame([422, 'too_many_rows'], [$refused->status, $refused->errorCode()], $refused->describe());
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'too_many_rows', 60, Words::BY_ITEM_BULK['all_stores_lower'], 50), $refused->text());
        self::assertSame(200, $web->post('/ui/review/products/bulk', self::ticked($page, [$wide], 'send_back', 'vbig'))->status, 'one store of it is within the limit');
        // A confirm of them: an item with several suggested variants on a store is left to a person, each line saying so.
        $this->setting('mapping.bulk_max_rows', '100');
        $confirm = $web->post('/ui/review/products/bulk', self::ticked($page, [$wide], 'link'));
        self::assertSame('0 will be matched · 0 will wait for a second OK · 60 will be skipped', self::squash((new \DOMXPath($confirm->dom()))->evaluate('string(//p[@class="alert-title"])')));
        self::assertSame([sprintf(Words::BY_ITEM_SKIP['several_suggested'], 'Wide kit', 'Alt Store'), sprintf(Words::BY_ITEM_SKIP['several_suggested'], 'Wide kit', 'Big Store')],
            array_values(array_unique(array_column(self::lines($confirm), 'why'))));
        // The five are sent back; the page then shows what is left.
        self::assertSame(303, $web->post('/ui/review/products/bulk/apply', $big->form('/ui/review/products/bulk/apply'))->status);
        self::assertSame(55, (int) self::$db->value("SELECT COUNT(*) FROM match_proposal WHERE status = 'open' AND proposed_sku_id = ?", [$wide]));

        // More suggested variants than one bulk action can ever take (the most the setting can be): no tick box, and the row says so.
        $vast = $this->item('legacy', 0, 'Vast kit');
        $this->manySuggested($a, $vast, ByItemBulkController::MAX_PER_ITEM + 1, 'VA');
        $page = $web->get('/ui/review/products');
        self::assertSame(200, $page->status, $page->describe() . implode("\n", self::$log));
        $form = $page->form('/ui/review/products/bulk');
        self::assertArrayNotHasKey('cells_' . $vast, $form, 'never a part of them');
        self::assertArrayHasKey('cells_' . $wide, $form);
        $xp = new \DOMXPath($page->dom());
        self::assertSame(0, $xp->query('//tr[@id="p-' . $vast . '"]//input[@type="checkbox"]')->length);
        self::assertStringContainsString(Words::say('BY_ITEM_BULK', 'row_too_many', ByItemBulkController::MAX_PER_ITEM + 1), self::squash($xp->evaluate('string(//tr[@id="p-' . $vast . '"]/th)')));
        self::assertSame([$wide], self::drawnTicked($web->get('/ui/review/products', ['all' => '1'])));
    }

    public function testBothRoutesNeedTheTokenAndAPersonWhoMayDecide(): void
    {
        ['a' => $a] = $this->stores();
        $sku = $this->item('legacy', 0, 'Guarded kit');
        $l = $this->queued($a, 'G1', 'Key', $sku, ['product_title' => 'Guarded on Alt']);
        $mapper = $this->signIn($this->uiUser('mapper'));
        $form = self::ticked($mapper->get('/ui/review/products'), [$sku], 'link');
        $row = ['action' => 'link', 'row_' . $l => $sku . '.' . $this->proposalOf($l) . '.' . $this->version($l) . '.1'];
        foreach (['/ui/review/products/bulk' => $form, '/ui/review/products/bulk/apply' => $row + ['csrf' => $form['csrf']]] as $path => $good) {
            // Without the session's token, or with another's: refused, nothing done.
            foreach (['no token' => array_diff_key($good, ['csrf' => 1]), 'empty' => ['csrf' => ''] + $good, 'made up' => ['csrf' => 'forged'] + $good] as $what => $bad) {
                $r = $mapper->post($path, $bad);
                self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], "{$path}: {$what}");
            }
            // People who cannot decide: 403 in words, with their own valid token. An admin alone decides nothing (I12).
            foreach (['viewer', 'buyer', 'admin'] as $job) {
                $web = $this->signIn($this->uiUser($job));
                $r = $web->post($path, ['csrf' => $this->token($web)] + $good);
                self::assertSame(403, $r->status, "{$path}: {$job} " . $r->describe());
                self::assertSame('role_not_allowed', $r->errorCode(), "{$path}: {$job}");
            }
            // They are forms: a GET of either address is not a page.
            self::assertSame(405, $mapper->get($path)->status, $path);
            self::assertSame('suggested', $this->link($l)['status'], $path);
            self::assertSame(0, self::batches(), $path);
        }
        // Signed out: to the sign-in page.
        $r = $this->browser()->post('/ui/review/products/bulk/apply', $row + ['csrf' => 'x']);
        self::assertSame([303, '/ui/login?why=lost'], [$r->status, $r->location()]);
        // And with all of it right, it works.
        self::assertSame(200, $mapper->post('/ui/review/products/bulk', $form)->status);
        self::assertSame(303, $mapper->post('/ui/review/products/bulk/apply', $row + ['csrf' => $form['csrf']])->status);
        self::assertSame('mapped', $this->link($l)['status']);
    }

    public function testOddAndCraftedValuesAreAnsweredInWordsAndChangeNothing(): void
    {
        ['a' => $a] = $this->stores();
        $sku = $this->item('legacy', 0, 'Odd kit');
        $two = $this->item('legacy', 0, 'Odd two');
        $l = $this->queued($a, 'O1', 'Key', $sku, ['product_title' => 'Odd on Alt']);
        $web = $this->signIn($this->uiUser('mapper'));
        $good = self::ticked($web->get('/ui/review/products'), [$sku], 'link');
        $cell = $this->cell($l);
        $before = self::snapshot();
        $cases = [
            'no action' => [400, array_diff_key($good, ['action' => 1])],
            'an action of another list' => [400, ['action' => 'ignore'] + $good],
            'undoing a match is not a bulk action' => [400, ['action' => 'unlink'] + $good],
            'an action as a list' => [400, ['action' => ['link']] + $good],
            'an action that is not text' => [400, ['action' => "link\xFF"] + $good],
            'a store that is not text' => [400, ['on' => "\xFF\xFE"] + $good],
            'a store that is a path' => [400, ['on' => '../alt'] + $good],
            'a huge store' => [400, ['on' => str_repeat('a', 100000)] + $good],
            'cells that are words' => [400, ['cells_' . $sku => 'all of them'] + $good],
            'cells with a sign' => [400, ['cells_' . $sku => '-1.2.3'] + $good],
            'cells with a fourth number' => [400, ['cells_' . $sku => $cell . '.1'] + $good],
            'cells with two spaces' => [400, ['cells_' . $sku => $cell . '  ' . $cell] + $good],
            'cells of more variants than one bulk action can ever take' => [400, ['cells_' . $sku => implode(' ', array_map(static fn (int $i): string => (1000 + $i) . '.1.0',
                range(1, ByItemBulkController::MAX_PER_ITEM + 1)))] + $good],
            'more ticks than a page has rows' => [400, array_fill_keys(array_map(static fn (int $i): string => 'pick_' . $i, range(1000, 1000 + ByProductContext::MAX_TICKED)), '1') + $good],
            // Numbers no variant or version can be (beyond INT UNSIGNED): refused before anything could be written (review finding A).
            'a variant number beyond its column' => [400, ['cells_' . $sku => '4294967296.1.0'] + $good],
            'a version beyond its column' => [400, ['cells_' . $sku => $l . '.' . $this->proposalOf($l) . '.4294967296'] + $good],
            'huge cells' => [400, ['cells_' . $sku => str_repeat('9', 200000)] + $good],
            'cells that are not text' => [400, ['cells_' . $sku => "1.2.3\xFF"] + $good],
            'one variant under two items' => [400, ['pick_' . $two => '1', 'cells_' . $two => $cell] + $good],
            'a tick that is a list' => [422, ['pick_' . $sku => ['1']] + $good],
            'cells that are a list' => [422, ['cells_' . $sku => [$cell]] + $good],
            'a tick of an item that is none' => [422, ['pick_999999' => '1'] + array_diff_key($good, ['pick_' . $sku => 1])],
        ];
        foreach ($cases as $what => [$status, $form]) {
            $r = $web->post('/ui/review/products/bulk', $form);
            self::assertSame($status, $r->status, $what . ': ' . $r->describe() . implode("\n", self::$log));
            self::assertStringContainsString('/ui/review/products', implode(' ', $r->hrefs()), "{$what}: the way back to the list");
        }
        // The list's own values, odd: read as the list reads them (none), and the preview is drawn.
        foreach ([['cov' => ['every']], ['cov' => 'missing-nosuch'], ['q' => ['a']], ['q' => "\xFF\u{00A0}"], ['q' => str_repeat('é', 5000)], ['page' => '-3'], ['page' => str_repeat('9', 40)],
            ['n' => '75'], ['n' => ['100']], ['ticked' => 'x'], ['on' => ['alt']], ['on' => '']] as $odd) {
            $r = $web->post('/ui/review/products/bulk', $odd + $good);
            self::assertSame(200, $r->status, json_encode($odd, JSON_INVALID_UTF8_SUBSTITUTE) . ': ' . $r->describe() . implode("\n", self::$log));
            self::assertSame([$l], array_keys(self::lines($r)));
        }
        // The apply.
        $token = ['csrf' => $good['csrf'], 'action' => 'link'];
        $row = $sku . '.' . $this->proposalOf($l) . '.' . $this->version($l);
        foreach ([
            'no row' => [422, $token],
            'a row that is words' => [400, $token + ['row_' . $l => 'yes']],
            'a row without its mark' => [400, $token + ['row_' . $l => $row]],
            'a row with another mark' => [400, $token + ['row_' . $l => $row . '.2']],
            'a row with a sign' => [400, $token + ['row_' . $l => '-' . $row . '.1']],
            'a huge row' => [400, $token + ['row_' . $l => str_repeat('1', 100000)]],
            'a row that is not text' => [400, $token + ['row_' . $l => $row . ".1\xFF"]],
            'a row that is a list' => [422, $token + ['row_' . $l => [$row . '.1']]],
            'no action' => [400, ['csrf' => $good['csrf'], 'row_' . $l => $row . '.1']],
            'another list\'s action' => [400, ['action' => 'new_item'] + $token + ['row_' . $l => $row . '.1']],
            'a store that is none' => [400, $token + ['on' => 'nosuch', 'row_' . $l => $row . '.1']],
            'a variant number beyond its column' => [400, $token + ['row_' . $l => $row . '.1', 'row_4294967296' => $sku . '.1.0.1']],
            'a variant number beyond its column, marked skipped' => [400, $token + ['row_' . $l => $row . '.1', 'row_9999999999' => $sku . '.1.0.0']],
            'a version beyond its column' => [400, $token + ['row_' . $l => $sku . '.' . $this->proposalOf($l) . '.4294967296.1']],
            'a version beyond its column, marked skipped' => [400, $token + ['row_' . $l => $sku . '.' . $this->proposalOf($l) . '.9999999999.0']],
        ] as $what => [$status, $form]) {
            $r = $web->post('/ui/review/products/bulk/apply', $form);
            self::assertSame($status, $r->status, $what . ': ' . $r->describe() . implode("\n", self::$log));
        }
        self::assertSame($before, self::snapshot(), 'none of it wrote anything');
        self::assertSame('suggested', $this->link($l)['status']);
        self::assertSame([Settings::RULES['mapping.bulk_max_rows']['max'], 500], [ByItemBulkController::MAX_PER_ITEM, ByItemBulkController::MAX_PER_ITEM],
            'a row sends at most what the limit can ever be set to: one number, the setting\'s rule');
        // The largest number that fits is an ordinary skipped line: in the preview, and recorded by the apply (no failure).
        $edge = $web->post('/ui/review/products/bulk', ['pick_' . $two => '1', 'cells_' . $two => '4294967295.1.4294967295'] + array_diff_key($good, ['pick_' . $sku => 1]));
        self::assertSame([200, [Words::BY_ITEM_SKIP['unknown_listing']]], [$edge->status, array_column(self::lines($edge), 'why')], $edge->describe());
        $edge = $web->post('/ui/review/products/bulk/apply', $token + ['row_4294967295' => $two . '.1.4294967295.1']);
        self::assertSame(303, $edge->status, $edge->describe());
        self::assertSame(['skipped', 'unknown_listing'], [self::batchRows((int) self::$db->value('SELECT MAX(id) FROM mapping_batch'))[4294967295]['outcome'],
            self::batchRows((int) self::$db->value('SELECT MAX(id) FROM mapping_batch'))[4294967295]['skip_code']]);
        // The batch page of By Item reads the list's values the same way.
        $done = $web->post('/ui/review/products/bulk/apply', $token + ['row_' . $l => $row . '.1']);
        self::assertSame(303, $done->status, $done->describe());
        $path = (string) parse_url((string) $done->location(), PHP_URL_PATH);
        foreach ([['from' => ['item']], ['from' => 'item', 'cov' => ['x'], 'q' => ['y'], 'page' => ['2'], 'n' => ['100']], ['from' => 'item', 'cov' => '//evil.example', 'q' => "\xFF", 'page' => '-1', 'n' => '7']] as $query) {
            $r = $web->send('GET', $path, $query, []);
            self::assertSame(200, $r->status, json_encode($query, JSON_INVALID_UTF8_SUBSTITUTE) . ': ' . $r->describe());
            self::assertStringStartsWith('/ui/review/products', (new \DOMXPath($r->dom()))->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));
        }
    }
}
