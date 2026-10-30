<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Clock;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\CountsController;
use CW\Ui\Html;
use CW\Ui\Words;

/**
 * Stock › Counts (pack A2a; docs/decisions.md CT14) through the real /ui kernel as cw_app, with the production document types: the tab
 * and its three segments; the plan's figures and "Next to count"; a sheet opened once however often the form is sent, counted by scan
 * and by typing (a quantity saved when Enter is pressed in its box; only the boxes changed on the page), checked on "Before you make it final" and made final; "warn first" on
 * the page with both answers; the differences accepted and rejected; the download and the upload with its preview; a blind count; the
 * item page's "Stock last counted"; who may open, enter, finish and answer, and the CSRF token on every POST.
 */
final class CountsScreensTest extends KernelUiTestCase
{
    private const PATH = CountsController::PATH;
    /** @var array<string, string> setting key => its value_json to put back */
    private array $savedSettings = [];

    protected function handlers(Db $db): array
    {
        return DocumentHandlers::all($db);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedSettings as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->savedSettings = [];
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->savedSettings[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    /** Opens a sheet for these items through the form of "Next to count" (sent twice: one sheet), and returns its page's path. @param list<int> $skus */
    private function start(KernelBrowser $web, array $skus, string $mode = 'ticked'): string
    {
        $list = $web->get(self::PATH);
        self::assertSame(200, $list->status, $list->describe() . ' ' . implode(' | ', self::$log));
        $form = $list->form(self::PATH);
        self::assertArrayHasKey('form_key', $form, 'the form is there for someone who counts');
        $fields = ['mode' => $mode] + $form;
        foreach ($skus as $sku) {
            $fields['pick_' . $sku] = '1';
        }
        $r = $web->post(self::PATH, $fields);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame($r->location(), $web->post(self::PATH, $fields)->location(), 'one sheet however often the form is sent');
        return (string) parse_url((string) $r->location(), PHP_URL_PATH);
    }

    /** Posts a form of a page (its fields as drawn, with $over). @param array<string, string> $over @param array<string, string> $headers */
    private function submit(KernelBrowser $web, string $page, string $action, array $over = [], array $headers = []): UiResponse
    {
        $p = $web->get($page);
        self::assertSame(200, $p->status, $p->describe() . ' ' . implode(' | ', self::$log));
        $form = $p->form($action);
        self::assertNotSame([], $form, "no form {$action} on {$page}");
        return $web->post($action, $over + $form, $headers);
    }

    private function onHand(int $sku): int
    {
        return (int) (self::$db->value('SELECT on_hand FROM stock_balance WHERE warehouse_id = 1 AND sku_id = ?', [$sku]) ?? 0);
    }

    /** @return list<string> the product names of a board or a sheet, in page order */
    private static function names(UiResponse $page): array
    {
        $out = [];
        foreach ((new \DOMXPath($page->dom()))->query('//main//table//th[contains(@class, "c-head")]/a') ?: [] as $a) {
            $out[] = trim((string) $a->textContent);
        }
        return $out;
    }

    /** @return list<string> the big figures of the page's strip (docs/decisions.md CV9) */
    private static function steps(UiResponse $page): array
    {
        $out = [];
        foreach ((new \DOMXPath($page->dom()))->query('//main//section[contains(@class, "doc-progress")]//p[contains(@class, "doc-step-big")]') ?: [] as $p) {
            $out[] = trim((string) $p->textContent);
        }
        return $out;
    }

    /**
     * The board's groups in page order (docs/decisions.md CV1, CV10): each its key, its tone, its name, its count words, and whether its
     * fold starts closed.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: bool}>
     */
    private static function boardGroups(UiResponse $page): array
    {
        $xp = new \DOMXPath($page->dom());
        $out = [];
        foreach ($xp->query('//main//div[contains(@class, "doc-group")]') ?: [] as $g) {
            /** @var \DOMElement $g */
            $classes = preg_split('/\s+/', trim($g->getAttribute('class'))) ?: [];
            $tone = array_values(array_intersect($classes, Words::TONES))[0] ?? '';
            $name = $xp->query('.//*[contains(@class, "grp-toggle") or contains(@class, "grp-name")]', $g)->item(0);
            $out[] = [$g->getAttribute('data-group'), $tone, trim((string) $name?->textContent), trim((string) $xp->evaluate('string(.//span[contains(@class, "grp-count")])', $g)),
                $xp->query('.//button[contains(@class, "grp-toggle") and @data-folded]', $g)->length === 1];
        }
        return $out;
    }

    /** @return array<string, string> a select's options (value => words) */
    private static function options(UiResponse $page, string $name): array
    {
        $out = [];
        foreach ((new \DOMXPath($page->dom()))->query('//select[@name="' . $name . '"]/option') ?: [] as $o) {
            /** @var \DOMElement $o */
            $out[$o->getAttribute('value')] = trim((string) $o->textContent);
        }
        return $out;
    }

    /** @return list<string> the words of the solid status cells of the page's boards, in page order */
    private static function cells(UiResponse $page): array
    {
        $out = [];
        foreach ((new \DOMXPath($page->dom()))->query('//main//td[contains(@class, "doc-st")]') ?: [] as $td) {
            $out[] = trim((string) preg_replace('/\s+/u', ' ', (string) $td->firstChild?->textContent));
        }
        return $out;
    }

    public function testTheTabItsSegmentsThePlanAndWhatToCountNext(): void
    {
        $best = $this->item('strict', 9, 'Best seller pod');
        $slow = $this->item('strict', 4, 'Slow liquid');
        $old = $this->item('strict', 3, 'Counted last year');
        self::$db->exec("INSERT INTO reorder_demand (sku_id, computed_at, rate, rate_raw_30, valid_days_short, valid_days_long, excluded_anomaly, excluded_promo, excluded_oos, "
            . "excluded_other, capped_days, units_365, monthly, detail) VALUES (?, UTC_TIMESTAMP(6), 7.5, 7.5, 30, 90, 0, 0, 0, 0, 0, 100, '{}', '{}'), "
            . "(?, UTC_TIMESTAMP(6), 0.25, 0.25, 30, 90, 0, 0, 0, 0, 0, 5, '{}', '{}')", [$best, $slow]);
        self::$db->exec("UPDATE stock_balance SET counted_at = '2025-09-01 10:00:00' WHERE sku_id = ?", [$old]);

        $web = $this->signIn($this->uiUser('stock_controller'));
        $page = $web->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['Inventory', 'Stock counts'], [self::currentSection($page), self::currentTab($page)], 'Stock › Counts: the sidebar\'s Counts (HV2)');
        self::assertSame([null, true], [array_column(self::sectionTabs($page), 'href', 'label')['Stock counts'], array_column(self::sectionTabs($page), 'current', 'label')['Stock counts']],
            'Stock counts, the entry shown (no link: CV13)');
        self::assertSame('/ui/stock/counts', array_column(self::sectionTabs($web->get(self::PATH . '/sheets')), 'href', 'label')['Stock counts'], 'from its other pages a link');
        self::assertSame([['Items to count', true], ['Count sheets', false], ['Differences', false]], array_map(static fn (array $s): array => [$s['label'], $s['current']], self::segments($page)));
        $xp = new \DOMXPath($page->dom());
        self::assertSame(Words::MENU['counts'], trim((string) $xp->evaluate('string(//main//h1)')));
        self::assertSame(0, $xp->query('//main//p[@class="lede"]')->length, 'no sentence under the heading (DC1)');
        // The board (docs/decisions.md CV1): Overdue (red), Never counted (purple), Inside the plan (green, folded at first); each heading
        // with the plan's figure for the warehouse (overdue here: counted, but longer ago than the plan); each row's status a solid cell.
        self::assertSame([['overdue', 'blocked', 'Overdue', '1 item', false], ['never', 'review', 'Never counted', '2 items', false]], self::boardGroups($page));
        self::assertStringContainsString(Words::say('COUNTS', 'plan', 120), $page->text());
        self::assertSame(['Counted last year', 'Best seller pod', 'Slow liquid'], self::names($page), 'overdue first, then never counted (best sellers first)');
        self::assertSame(['Overdue', 'Never counted', 'Never counted'], self::cells($page));
        // "Sells a week" as CT_Board draws it (docs/decisions.md DC36; it read "Sold a day" 216.3417): the same figure (reorder_demand.rate,
        // units a day) times 7, a whole number rounded half up as the item page's (7.5 a day: 52.5, shown 53; 0.25 a day: 1.75, shown 2).
        self::assertSame(['Sells a week'], array_values(array_unique(array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//table[contains(@class, "count-next")]/thead/tr/th[contains(@class, "num")]'))))));
        self::assertSame([Words::COUNTS['no_sales'], '53', '2'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//table[contains(@class, "count-next")]/tbody/tr/td[contains(@class, "num")]'))), 'Counted last year has no sales figure');
        self::assertSame(3, $xp->query('//table[contains(@class, "count-next")]//td[contains(@class, "c-pick")]//input[@type="checkbox"]')->length,
            'a tick box a row');
        self::assertSame(0, $xp->query('//table[contains(@class, "count-next")]//th[normalize-space() = "' . Words::COUNTS['stock_now'] . '"]')->length,
            'no stock figure on the board: a blind count would show it before the count (CV4)');
        // The bar (CV2): "N ticked", ONE Count menu, the shelf, ONE button. The four open buttons went; the next N is the Show choice.
        $form = $page->form(self::PATH);
        self::assertArrayHasKey('form_key', $form);
        self::assertSame(['ticked' => Words::COUNTS['count_ticked'], 'next' => Words::say('COUNTS', 'count_next', 50), 'empty' => Words::COUNTS['count_empty']],
            self::options($page, 'mode'));
        self::assertSame(['ticked', '50'], [$form['mode'] ?? null, $form['n'] ?? null]);
        $bar = '//form[@action="' . self::PATH . '"]//div[contains(@class, "doc-bar")]';
        self::assertSame(1, $xp->query('//form[@action="' . self::PATH . '"]//button[@type="submit"]')->length, 'one button opens the sheet');
        self::assertSame(Words::COUNTS['open_button'], trim((string) $xp->evaluate('string(' . $bar . '//button[@type="submit" and contains(@class, "primary")])')));
        self::assertSame(1, $xp->query($bar . '//select[@name="mode"]')->length);
        self::assertSame(1, $xp->query($bar . '//*[@data-ticked and @hidden]')->length, '"N ticked" is the script\'s: without it nothing says 0');
        self::assertNotContains('/ui/stock/counts/sheets?state=draft&warehouse=1', $page->hrefs(), 'no open sheet yet');
        // The setting moves the figures: with two years of days the old count is inside the plan, folded at first.
        $this->setting('counts.plan_days', '730');
        $wide = $web->get(self::PATH);
        self::assertSame([['never', 'review', 'Never counted', '2 items', false], ['inside', 'done', 'Inside the plan', '1 item', true]], self::boardGroups($wide));
        self::assertSame(['Best seller pod', 'Slow liquid', 'Counted last year'], self::names($wide), 'without the script every group is open');
        // The Show choice is the N of "the next N due".
        $more = $web->get(self::PATH, ['show' => '100']);
        self::assertSame(Words::say('COUNTS', 'count_next', 100), self::options($more, 'mode')['next'] ?? null);
        self::assertSame('100', $more->form(self::PATH)['n'] ?? null);
        // A search shows what it finds, whatever its turn: the menu offers everything found instead of the next ones.
        $found = $web->get(self::PATH, ['q' => 'liquid']);
        self::assertSame(['Slow liquid'], self::names($found));
        self::assertSame(['ticked' => Words::COUNTS['count_ticked'], 'found' => Words::say('COUNTS', 'count_found', 1), 'empty' => Words::COUNTS['count_empty']],
            self::options($found, 'mode'));
        self::assertSame([['never', 'review', 'Never counted', '1 item', false]], self::boardGroups($found), 'a search counts what it found');
        self::assertStringContainsString(Words::COUNTS['none_found'], $web->get(self::PATH, ['q' => 'no such thing'])->text());
        // The two other segments, empty.
        $sheets = $web->get(self::PATH . '/sheets');
        self::assertSame([200, 'Count sheets'], [$sheets->status, array_values(array_filter(self::segments($sheets), static fn (array $s): bool => $s['current']))[0]['label']]);
        self::assertStringContainsString(Words::COUNTS['no_sheets'], $sheets->text());
        $diffs = $web->get(self::PATH . '/differences');
        self::assertSame(200, $diffs->status, $diffs->describe());
        self::assertStringContainsString(Words::COUNTS['no_differences'], $diffs->text());
        self::assertStringContainsString(Words::say('COUNTS', 'second_none', 0), $diffs->text(), 'the second-person rule starts off (0029)');

        // Someone who reads records looks but opens nothing; a viewer has no counts at all.
        $buyer = $this->signIn($this->uiUser('buyer'));
        $look = $buyer->get(self::PATH);
        self::assertSame(200, $look->status);
        self::assertFalse($look->hasForm(self::PATH), 'no form for someone who does not count');
        self::assertSame(0, (new \DOMXPath($look->dom()))->query('//div[contains(@class, "doc-bar")] | //input[@type="checkbox"]')->length, 'no bar, no tick boxes');
        self::assertStringContainsString(sprintf(Words::UI['look_only'], Words::whoCan('doc.CNT.post')), $look->text(), 'who counts, under the heading\'s "?" (DC1)');
        self::assertSame(['Best seller pod', 'Slow liquid', 'Counted last year'], self::names($look));
        self::assertSame(403, $this->signIn($this->uiUser('viewer'))->get(self::PATH)->status);
        // The sidebar's "Every day" opens it (docs/decisions.md HV2).
        self::assertSame(self::PATH, array_column(self::tabs($web->get('/ui/')), 'href', 'label')['Count'], 'the phone bar\'s Count (Phone.dc.html)');
        self::assertSame('/ui/stock', self::nav($web->get('/ui/'))['Inventory']['href']);
    }

    public function testTheBoardsOneCountMenuOpensEachKindOfSheetAndARefusalKeepsTheChoice(): void
    {
        // docs/decisions.md CV2: the bar's one Count menu and its one button do what the four buttons did (the same mode, n and q the
        // route always took; FormOnce: one sheet however often it is sent); a refused open comes back with what was chosen.
        $a = $this->item('strict', 10, 'Alpha pod 2ml');
        $b = $this->item('strict', 5, 'Beta liquid');
        $c = $this->item('strict', 3, 'Gamma kit');
        $place = (int) self::$db->insert("INSERT INTO warehouse_location (warehouse_id, code, name, is_active) VALUES (1, 'A1', 'Aisle A', 1)");
        $web = $this->signIn($this->uiUser('stock_controller'));
        $lines = static fn (string $path): array => array_map('intval', self::$db->column('SELECT sku_id FROM count_sheet_line WHERE document_id = ? ORDER BY line_no', [(int) basename($path)]));
        $open = function (array $fields, array $query = []) use ($web): string {
            $r = $web->post(self::PATH, $fields + $web->get(self::PATH, $query)->form(self::PATH));
            self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
            return (string) parse_url((string) $r->location(), PHP_URL_PATH);
        };
        $page = $web->get(self::PATH);
        self::assertSame(['' => Words::COUNTS['no_place'], (string) $place => 'Aisle A'], self::options($page, 'location'), 'the shelf menu');
        // The next N due: N is the Show choice (50), the plan's own order (never counted: by CW number without a sales figure).
        $next = $open(['mode' => 'next']);
        self::assertSame([$a, $b, $c], $lines($next));
        // Everything found by a search.
        $found = $open(['mode' => 'found'], ['q' => 'liquid']);
        self::assertSame([$b], $lines($found));
        // An empty sheet, on a shelf, with a note.
        $empty = $open(['mode' => 'empty', 'location' => (string) $place, 'note' => 'top shelf']);
        self::assertSame([], $lines($empty));
        self::assertSame([(string) $place, 'top shelf'], array_map('strval', array_values(self::$db->one('SELECT cs.location_id, d.note FROM count_sheet cs JOIN document d ON d.id = cs.document_id WHERE cs.document_id = ?',
            [(int) basename($empty)]) ?? [])));
        // The ticked items.
        $ticked = $open(['mode' => 'ticked', 'pick_' . $c => '1']);
        self::assertSame([$c], $lines($ticked));
        // A refused open (a hand-made N) keeps the ticked boxes, the menu's choice, the shelf and the note.
        $r = $web->post(self::PATH, ['mode' => 'next', 'n' => '0', 'pick_' . $a => '1', 'location' => (string) $place, 'note' => 'aisle 3'] + $web->get(self::PATH)->form(self::PATH));
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('COUNT_ERROR', 'bad_next', 200), $r->text());
        $again = $r->form(self::PATH);
        self::assertSame(['next', '1', (string) $place, 'aisle 3', '50'], [$again['mode'] ?? null, $again['pick_' . $a] ?? null, $again['location'] ?? null, $again['note'] ?? null, $again['n'] ?? null]);
        self::assertArrayNotHasKey('pick_' . $b, $again);
        // The open sheets of the warehouse: a plain link in the head to the list of them (the four above).
        $list = $web->get(self::PATH);
        self::assertContains('/ui/stock/counts/sheets?state=draft&warehouse=1', $list->hrefs());
        self::assertStringContainsString(Words::say('COUNTS', 'open_sheets', 4), $list->text());
        self::assertSame(0, (new \DOMXPath($list->dom()))->query('//header//a[contains(@class, "primary")]')->length, 'the bar holds the one main action');
    }

    public function testASheetIsOpenedCountedCheckedAndMadeFinalAndItsDifferenceAnswered(): void
    {
        $a = $this->item('strict', 10, 'Alpha pod 2ml');
        $b = $this->item('strict', 5, 'Beta liquid');
        $c = $this->item('strict', 3, 'Gamma kit');
        $twin = $this->item('strict', 1, 'Twin one');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan) VALUES ('5060000000017', ?, 1, 1), ('5060000000024', ?, 1, 1), ('5060000000031', ?, 0, 1)",
            [$a, $c, $twin]);
        $web = $this->signIn($this->uiUser('stock_controller'));
        $sheet = $this->start($web, [$a, $b]);
        $id = (int) basename($sheet);
        $page = $web->post(self::PATH, ['csrf' => $this->token($web), 'form_key' => str_repeat('c', 32), 'warehouse' => '1', 'mode' => 'ticked']);
        self::assertSame(422, $page->status, 'nothing ticked');
        self::assertStringContainsString(Words::COUNT_ERROR['nothing_ticked'], $page->text());

        $open = $web->get($sheet, ['notice' => 'opened']);
        self::assertSame(200, $open->status, $open->describe() . ' ' . implode(' | ', self::$log));
        $xp = new \DOMXPath($open->dom());
        self::assertSame(Words::COUNTS['title_open'], trim((string) $xp->evaluate('string(//main//h1/span[contains(@class, "stmt")])')), 'the heading\'s name, before its state chip');
        self::assertStringContainsString(Words::COUNT_NOTICE['opened'], $open->text());
        self::assertSame(['Inventory', 'Stock counts'], [self::currentSection($open), self::currentTab($open)]);
        // The Codex review of f4d1126 (DC29): the opener is told, in one short line under the lines, that nothing is booked until the sheet is
        // final (its intro said it; CT_Sheet's muted line under the board is the place).
        self::assertStringContainsString('nothing is booked until you make the sheet final', trim((string) $xp->evaluate('string(//main//p[contains(@class, "count-booked")])')));
        self::assertSame(['Alpha pod 2ml', 'Beta liquid'], self::names($open));
        self::assertStringContainsString(Words::say('COUNTS', 'barcodes', '5060000000017'), $open->text(), 'a line shows the item\'s barcodes');
        self::assertSame(['10', '5'], array_map(static fn (\DOMNode $n): string => trim((string) $n->firstChild?->textContent),
            iterator_to_array($xp->query('//table[contains(@class, "count-sheet")]//td[@data-label="' . Words::COUNTS['stock_now'] . '"]'))), 'stock now beside each item (the setting)');
        self::assertSame(1, $xp->query('//form[contains(@action, "/scan")]//input[@name="code" and @autofocus]')->length, 'the scan box waits for the scanner');
        // The head (docs/decisions.md CV5): the status block in the heading, where and who on one line, "N of M counted" with its bar.
        self::assertSame([Words::COUNTS['counting'], 'chip info'], [trim((string) $xp->evaluate('string(//main//h1/span[contains(@class, "chip")])')),
            (string) $xp->evaluate('string(//main//h1/span[contains(@class, "chip")]/@class)')]);
        self::assertStringContainsString('Main', (string) $xp->evaluate('string(//main//header//p[contains(@class, "ph-meta")])'));
        self::assertSame([Words::say('COUNTS', 'lines_counted', 0, 2), '2', '0'], [trim((string) $xp->evaluate('string(//main//header//div[contains(@class, "count-progress")]/p)')),
            (string) $xp->evaluate('string(//main//header//div[contains(@class, "count-progress")]/progress/@max)'), (string) $xp->evaluate('string(//main//header//div[contains(@class, "count-progress")]/progress/@value)')]);
        // The lines (CV6): the big number box, then "Saved hh:mm" or "Not yet"; the line to count now is marked.
        self::assertSame([Words::COUNTS['not_yet'], Words::COUNTS['not_yet']], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//table[contains(@class, "count-sheet")]//*[contains(@class, "save-state")]'))));
        self::assertSame(['line-1'], array_map(static fn (\DOMElement $tr): string => $tr->getAttribute('id'), iterator_to_array($xp->query('//tr[contains(@class, "now")]'))));
        // The bar (CV7): More (count on paper, stop the sheet), Save, and the one main button "Save and review"; Enter saves (the form's
        // first button is the hidden Save).
        $bar = '//main//div[contains(@class, "doc-bar")]';
        self::assertSame(1, $xp->query($bar . '/details[contains(@class, "more")]//details[@id="paper"]//form[contains(@action, "/upload")]')->length);
        self::assertSame(1, $xp->query($bar . '/details[contains(@class, "more")]//details[@id="paper"]//a[contains(@href, "/sheet.csv")]')->length);
        self::assertSame(1, $xp->query($bar . '/details[contains(@class, "more")]//details[@id="stop"]//form[contains(@action, "/cancel")]')->length);
        self::assertSame([[Words::COUNTS['save'], 'count-lines', '', 'btn'], [Words::COUNTS['save_review'], 'count-lines', 'review', 'btn primary']],
            array_map(static fn (\DOMElement $b): array => [trim((string) $b->textContent), $b->getAttribute('form'), $b->getAttribute('value'), $b->getAttribute('class')],
                iterator_to_array($xp->query($bar . '/button[@type="submit"]'))));
        $first = $xp->query('//form[@id="count-lines"]//button[@type="submit"]')->item(0);
        self::assertSame([Words::COUNTS['save'], 'po-default'], [trim((string) $first?->textContent), $first?->getAttribute('class')], 'Enter saves, never reviews');

        // The scan box: on the sheet (its line, marked and ready for the quantity), not on it (an offer), on no item, on two items.
        $r = $this->submit($web, $sheet, $sheet . '/scan', ['code' => '5060000000017']);
        self::assertSame([303, $sheet . '?page=1&line=1#line-1'], [$r->status, $r->location()]);
        $at = $web->get($sheet, ['page' => '1', 'line' => '1']);
        $xp = new \DOMXPath($at->dom());
        self::assertSame(1, $xp->query('//tr[@id="line-1" and contains(@class, "marked")]//input[@name="q_1" and @autofocus]')->length);
        self::assertSame(0, $xp->query('//input[@name="code" and @autofocus]')->length);
        $r = $this->submit($web, $sheet, $sheet . '/scan', ['code' => '5060000000024']);
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('COUNTS', 'scan_not_on_sheet', 'Gamma kit', sprintf('CW-%06d', $c)), $r->text());
        $offer = $r->form($sheet . '/add');
        self::assertSame((string) $c, $offer['sku_id'] ?? null);
        $r = $web->post($sheet . '/add', $offer);
        self::assertSame([303, $sheet . '?notice=added&page=1&line=3#line-3'], [$r->status, $r->location()]);
        self::assertSame(['Alpha pod 2ml', 'Beta liquid', 'Gamma kit'], self::names($web->get($sheet)));
        $r = $this->submit($web, $sheet, $sheet . '/scan', ['code' => '5060000000999']);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('COUNTS', 'scan_unknown', '5060000000999'), $r->text());
        $r = $this->submit($web, $sheet, $sheet . '/scan', ['code' => '5060000000031']);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('COUNTS', 'scan_ambiguous', '5060000000031'), $r->text());
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ?', [$id]), 'it never guesses: nothing was added');

        // A quantity is saved the moment Enter is pressed in its box: the page's form goes, and only the boxes changed on it are kept.
        $token = $this->token($web);
        $drawn = $web->get($sheet)->form($sheet . '/lines');
        self::assertSame(['', '', ''], [$drawn['was_1'] ?? null, $drawn['was_2'] ?? null, $drawn['was_3'] ?? null], 'each box says what it was drawn with');
        $r = $web->post($sheet . '/lines', ['q_1' => '8'] + $drawn);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $saved = $web->follow($r);
        self::assertStringContainsString(Words::say('COUNT_NOTICE', 'lines', 1), $saved->text());
        self::assertSame(1, (new \DOMXPath($saved->dom()))->query('//form[contains(@action, "/scan")]//input[@name="code" and @autofocus]')->length,
            'then the scan box waits for the next item');
        self::assertSame(8, (int) self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$id]));
        $sxp = new \DOMXPath($saved->dom());
        self::assertSame(Words::say('COUNTS', 'saved_at', Html::clock((string) self::$db->value('SELECT counted_at FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$id]))),
            trim((string) $sxp->evaluate('string(//tr[@id="line-1"]//*[contains(@class, "save-state")])')), 'the moment the line was counted, kept per line');
        self::assertSame([Words::say('COUNTS', 'lines_counted', 1, 3), '1'], [trim((string) $sxp->evaluate('string(//main//header//div[contains(@class, "count-progress")]/p)')),
            (string) $sxp->evaluate('string(//main//header//div[contains(@class, "count-progress")]/progress/@value)')]);
        // A refused page saves nothing and keeps what was typed; a barcode in a quantity box is said as such.
        $r = $web->post($sheet . '/lines', ['q_2' => 'five'] + $drawn);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('COUNT_ERROR', 'bad_quantity', 2), $r->text());
        self::assertSame('five', $r->form($sheet . '/lines')['q_2'] ?? null, 'what was typed is still in its box');
        $r = $web->post($sheet . '/lines', ['q_2' => '5060000000017'] + $drawn);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('COUNT_ERROR', 'barcode_in_quantity', 2), $r->text());
        // Also a shorter barcode CW knows (review M-5, 14 Oct 2026): an EAN-8 that starts with 0, or a short code of the shop's own.
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan) VALUES ('01234565', ?, 1, 1), ('4711', ?, 1, 1)", [$b, $b]);
        foreach (['01234565', '4711'] as $code) {
            $r = $web->post($sheet . '/lines', ['q_2' => $code] + $drawn);
            self::assertSame(422, $r->status, $code);
            self::assertStringContainsString(Words::say('COUNT_ERROR', 'barcode_in_quantity', 2), $r->text(), $code);
        }
        self::$db->exec("DELETE FROM sku_barcode WHERE barcode IN ('01234565', '4711')");
        $r = $web->post($sheet . '/lines', ['q_2' => '5', 'q_3' => '1.5'] + $drawn);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('COUNT_ERROR', 'bad_quantity', 3), $r->text());
        self::assertNull(self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 2', [$id]), 'nothing of a refused page is saved');
        // The page drawn before line 1 was saved, sent again (left open in another tab): line 1 is neither emptied nor overwritten.
        $r = $web->post($sheet . '/lines', ['q_2' => '5', 'q_3' => ''] + $drawn);
        self::assertSame(303, $r->status, $r->describe());
        $saved = $web->follow($r);
        self::assertSame([8, 5], [(int) self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$id]),
            (int) self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 2', [$id])]);
        self::assertStringContainsString(Words::say('COUNT_NOTICE', 'lines', 1), $saved->text());
        self::assertStringContainsString(Words::say('COUNTS', 'lines_counted', 2, 3), $saved->text());
        self::assertSame(['Gamma kit'], self::names($web->get($sheet, ['show' => 'todo'])), 'the lines not counted yet');
        self::assertSame(10, $this->onHand($a), 'entering a quantity books nothing');

        // Before it is final: every counted line with its difference, the biggest first, and which are above the limits.
        $before = $web->get($sheet . '/differences');
        self::assertSame(200, $before->status, $before->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['Alpha pod 2ml', 'Beta liquid'], self::names($before));
        // The strip (CV9): counted, the same as the system, different, a reviewer checks; then the board of the lines that differ, and the
        // ones that are the same folded at first; each line's status a solid cell; "Count again" on every line.
        self::assertSame(['2', '1', '1', '1'], self::steps($before));
        self::assertSame([['differ', 'needs', Words::COUNTS['group_differ'], '1 item', false], ['same', 'done', Words::COUNTS['group_same'], '1 item', true]], self::boardGroups($before));
        self::assertSame([Words::COUNTS['st_reviewer'], Words::COUNTS['st_booked']], self::cells($before));
        self::assertStringContainsString(Words::say('COUNTS', 'minus', 2), $before->text());
        self::assertStringContainsString(Words::say('COUNTS', 'over_percent', 10), $before->text());
        $bxp = new \DOMXPath($before->dom());
        self::assertSame(2, $bxp->query('//main//table[contains(@class, "count-before")]//form[contains(@action, "/recount")]')->length, 'Count again on every line');
        self::assertSame(0, $bxp->query('//form[contains(@action, "/as-counted")]')->length, 'Keep my count only where stock moved');
        // The bar (CV10): back to the sheet, and ONE "Make the count final".
        self::assertSame([$sheet, Words::COUNTS['make_final_button']], [(string) $bxp->evaluate('string(//main//form[contains(@class, "doc-bar")]/a/@href)'),
            trim((string) $bxp->evaluate('string(//main//form[contains(@class, "doc-bar") and contains(@action, "/post")]/button[contains(@class, "primary")])'))]);
        // A quantity entered after the page was drawn: the old page cannot make the sheet final.
        $stale = $before->form($sheet . '/post');
        $web->post($sheet . '/lines', ['csrf' => $token, 'q_2' => '4']);
        $r = $web->post($sheet . '/post', $stale);
        self::assertSame(409, $r->status);
        self::assertStringContainsString(Words::COUNT_ERROR['version_conflict'], $r->text());
        self::assertSame(10, $this->onHand($a));
        $web->post($sheet . '/lines', ['csrf' => $token, 'q_2' => '5']);
        $r = $this->submit($web, $sheet . '/differences', $sheet . '/post');
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $done = $web->follow($r);
        self::assertStringContainsString(Words::say('COUNT_NOTICE', 'posted_check', 'CNT-000001'), $done->text());
        self::assertSame([8, 5, 3], [$this->onHand($a), $this->onHand($b), $this->onHand($c)], 'only the counted lines are booked');
        self::assertSame(Words::say('COUNTS', 'title_sheet', 'CNT-000001'), trim((string) (new \DOMXPath($done->dom()))->evaluate('string(//main//h1/span[contains(@class, "stmt")])')));
        self::assertStringContainsString(Words::COUNT_LINE['booked'], $done->text());
        self::assertStringContainsString(Words::COUNT_CHECK['open'], $done->text());
        self::assertStringContainsString(Words::COUNTS['not_counted'], $done->text(), 'the line left empty says so');
        self::assertFalse($done->hasForm($sheet . '/lines'), 'a final sheet takes no more quantities');
        self::assertSame(409, $web->post($sheet . '/lines', ['csrf' => $token, 'q_3' => '3'])->status);
        // A second "make it final" books nothing.
        $rows = (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger');
        self::assertSame(303, $web->get($sheet . '/differences')->status, 'the page leads back to the final sheet');
        self::assertSame(409, $web->post($sheet . '/post', $stale)->status);
        self::assertSame($rows, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger'));

        // The list of sheets, its search and its file; the movements; the item page's "Stock last counted".
        $list = $web->get(self::PATH . '/sheets');
        self::assertStringContainsString('CNT-000001', $list->text());
        self::assertStringContainsString(Words::COUNT_SHEET_GROUP['posted'], $list->text());
        self::assertStringContainsString(Words::say('COUNTS', 'next_check', 1), $list->text());
        self::assertStringContainsString('CNT-000001', $web->get(self::PATH . '/sheets', ['q' => 'cnt-1'])->text());
        self::assertStringContainsString('CNT-000001', $web->get(self::PATH . '/sheets', ['q' => 'alpha'])->text(), 'found by an item on it');
        self::assertStringContainsString(Words::COUNTS['no_sheet_found'], $web->get(self::PATH . '/sheets', ['q' => 'CNT-000009'])->text());
        $csv = $web->get(self::PATH . '/sheets.csv');
        self::assertSame(200, $csv->status);
        self::assertStringContainsString('CNT-000001', $csv->body);
        $countedAt = (string) self::$db->value('SELECT counted_at FROM stock_balance WHERE warehouse_id = 1 AND sku_id = ?', [$a]);
        $item = $web->get('/ui/items/' . $a);
        $facts = trim((string) (new \DOMXPath($item->dom()))->evaluate('string(//dt[normalize-space() = "' . Words::ITEM['last_counted'] . '"]/following-sibling::dd[1])'));
        self::assertSame(Html::when($countedAt), $facts, 'the item page says when its stock was last counted');
        self::assertNull(self::$db->value('SELECT counted_at FROM sku WHERE id = ?', [$a]), 'the count gate\'s own field is not touched');

        // The difference waits on Differences: accepted with a note, and it is gone.
        $diffs = $web->get(self::PATH . '/differences');
        self::assertSame(200, $diffs->status, $diffs->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(['Alpha pod 2ml'], self::names($diffs));
        self::assertStringContainsString(Words::COUNT_REVIEW['line'], $diffs->text());
        self::assertSame(1, array_column(self::segments($diffs), 'count', 'label')['Differences'], 'the badge counts what this person may answer');
        $action = self::PATH . '/differences/line/' . $id . '/1';
        $form = $diffs->form($action);
        $r = $web->post($action, ['answer' => 'accept', 'note' => ' '] + $form);
        self::assertSame(400, $r->status);
        self::assertStringContainsString(Words::COUNT_ERROR['note_required'], $r->text());
        $r = $web->post($action, ['answer' => 'accept', 'note' => 'two were damaged and binned'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        $after = $web->follow($r);
        self::assertStringContainsString(Words::COUNT_NOTICE['accepted'], $after->text());
        self::assertStringContainsString(Words::COUNTS['no_differences'], $after->text());
        self::assertSame(8, $this->onHand($a));
        self::assertStringContainsString(Words::COUNT_CHECK['accepted'], $web->get($sheet)->text());
    }

    public function testStockThatMovedAfterTheCountIsShownFirstWithBothAnswersAndARejectedDifferencePutsTheCountBack(): void
    {
        $a = $this->item('strict', 5, 'Alpha pod 2ml');
        $b = $this->item('strict', 60, 'Beta liquid');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $sheet = $this->start($web, [$a, $b]);
        $token = $this->token($web);
        $web->post($sheet . '/lines', ['csrf' => $token, 'q_1' => '5', 'q_2' => '60']);
        // The counts were entered five minutes ago; a delivery was booked a minute ago (after them, before now).
        self::$db->exec('UPDATE count_sheet_line SET counted_at = counted_at - INTERVAL 5 MINUTE, entered_at = entered_at - INTERVAL 5 MINUTE WHERE document_id = ?', [(int) basename($sheet)]);
        $this->now = Clock::now()->modify('-1 minute');
        $this->book('goods_in', $a, 4);
        $this->book('goods_in', $b, 1);
        $before = $web->get($sheet . '/differences');
        self::assertSame([Words::COUNTS['st_choose'], Words::COUNTS['st_choose']], self::cells($before), 'both lines wait for an answer (CV9)');
        self::assertSame(['c-status doc-st blocked', 'c-status doc-st blocked'], array_map(static fn (\DOMElement $td): string => $td->getAttribute('class'),
            iterator_to_array((new \DOMXPath($before->dom()))->query('//main//td[contains(@class, "doc-st")]'))), 'red: they stop the count from being made final');
        self::assertSame(2, (new \DOMXPath($before->dom()))->query('//form[contains(@action, "/as-counted")]')->length, 'Keep my count where stock moved');
        self::assertStringContainsString(Words::COUNTS['moved_title'], $before->text());
        self::assertStringContainsString(Words::of('MOVEMENT', 'goods_in'), $before->text(), 'it says what moved');
        self::assertStringContainsString(Words::COUNTS['make_final_blocked'], $before->text());
        self::assertFalse($before->hasForm($sheet . '/post'), 'no button until each such line has its answer');
        self::assertSame(1, (new \DOMXPath($before->dom()))->query('//main//div[contains(@class, "doc-bar")]/button[@disabled and contains(@class, "primary")]')->length,
            'the bar shows the main button switched off, with the reason beside it');
        // A hand-made form does not get past it either.
        $version = (string) self::$db->value('SELECT version FROM document WHERE id = ?', [(int) basename($sheet)]);
        $r = $web->post($sheet . '/post', ['csrf' => $token, 'version' => $version]);
        self::assertSame(409, $r->status);
        self::assertStringContainsString(Words::say('COUNT_ERROR', 'moved_since', '1, 2'), $r->text());
        self::assertSame([9, 61], [$this->onHand($a), $this->onHand($b)], 'nothing was booked');

        // Answer 1, line 1: keep the count. Answer 2, line 2: count it again.
        $keep = null;
        foreach ((new \DOMXPath($before->dom()))->query('//form[contains(@action, "/as-counted")]') ?: [] as $f) {
            /** @var \DOMElement $f */
            $fields = [];
            foreach ($f->getElementsByTagName('input') as $in) {
                $fields[$in->getAttribute('name')] = $in->getAttribute('value');
            }
            if ($fields['line'] === '1') {
                $keep = $fields;
            }
        }
        self::assertNotNull($keep);
        $r = $web->post($sheet . '/as-counted', $keep);
        self::assertSame([303, $sheet . '/differences?notice=as_counted'], [$r->status, $r->location()]);
        self::assertStringContainsString(Words::COUNTS['as_counted_chip'], $web->follow($r)->text());
        $r = $web->post($sheet . '/recount', ['csrf' => $token, 'line' => '2']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::COUNT_NOTICE['recount'], $web->follow($r)->text());
        self::assertNull(self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 2', [(int) basename($sheet)]));
        $web->post($sheet . '/lines', ['csrf' => $token, 'q_2' => '61']);
        $r = $this->submit($web, $sheet . '/differences', $sheet . '/post');
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame([5, 61], [$this->onHand($a), $this->onHand($b)], 'kept: the shelf figure is the count; counted again: nothing to change');

        // Differences: the line above the limits, and the engine's own review of what the count overwrote.
        $diffs = $web->get(self::PATH . '/differences');
        self::assertStringContainsString(Words::COUNT_REVIEW['line'], $diffs->text());
        self::assertStringContainsString(Words::COUNT_REVIEW['count_after_movements'], $diffs->text());
        self::assertStringContainsString(Words::say('COUNTS', 'why_after', 4, 1), $diffs->text());
        $review = (int) self::$db->value("SELECT id FROM count_review WHERE source = 'count_after_movements' AND sku_id = ?", [$a]);
        $action = self::PATH . '/differences/review/' . $review;
        $r = $web->post($action, ['answer' => 'reject', 'note' => 'the delivery was on the shelf when I counted'] + $diffs->form($action));
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        $after = $web->follow($r);
        self::assertStringContainsString(Words::say('COUNT_NOTICE', 'rejected', 'CNT-000002'), $after->text());
        self::assertStringContainsString(Words::COUNTS['no_differences'], $after->text(), 'the line\'s own check went with it');
        self::assertSame(9, $this->onHand($a), 'that one count is put back');
        // The record that put it back, and the sheet's line that names it.
        $back = (int) self::$db->value("SELECT document_id FROM count_sheet WHERE kind = 'line_reversal'");
        $record = $web->get(self::PATH . '/' . $back);
        self::assertSame(200, $record->status, $record->describe());
        self::assertStringContainsString(Words::say('COUNTS', 'puts_back', 1, 'CNT-000001'), $record->text());
        $final = $web->get($sheet);
        self::assertStringContainsString(Words::COUNT_LINE['put_back'], $final->text());
        self::assertStringContainsString(Words::COUNT_CHECK['rejected'], $final->text());
        self::assertStringContainsString(Words::COUNT_SHEET_GROUP['put_back'], $web->get(self::PATH . '/sheets')->text());
        // The whole sheet can no longer be cancelled; a draft can be stopped.
        $r = $this->submit($web, $sheet, $sheet . '/reverse', ['reason_code' => 'entered_in_error']);
        self::assertSame(409, $r->status);
        self::assertStringContainsString(Words::say('COUNT_ERROR', 'line_put_back', 1), $r->text());
        $draft = $this->start($web, [$b]);
        $r = $this->submit($web, $draft, $draft . '/cancel', ['reason' => 'opened by mistake']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::COUNT_NOTICE['stopped'], $web->follow($r)->text());
    }

    public function testEachBoxBringsTheMomentItWasFilledIn(): void
    {
        // Review M-7 (14 Oct 2026): app.js writes the moment a box was first typed into (from the time the page was drawn, so the
        // tablet's own clock does not matter) into at_<line>; the server keeps it when it is not after now and not before the line was
        // put on the sheet. Without it (no script), the moment Enter was pressed, as before.
        $a = $this->item('strict', 10, 'Alpha pod 2ml');
        $b = $this->item('strict', 5, 'Beta liquid');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $sheet = $this->start($web, [$a, $b]);
        $id = (int) basename($sheet);
        self::$db->exec('UPDATE count_sheet_line SET added_at = added_at - INTERVAL 10 MINUTE WHERE document_id = ?', [$id]);
        $page = $web->get($sheet);
        $xp = new \DOMXPath($page->dom());
        $drawnMs = (string) $xp->evaluate('string(//form[contains(@action, "/lines")]/@data-drawn)');
        self::assertMatchesRegularExpression('/^[0-9]{13}$/', $drawnMs, 'the page says when it was drawn, in milliseconds');
        self::assertLessThan(60_000, abs((int) $drawnMs - (int) Clock::now()->format('Uv')));
        $drawn = $page->form($sheet . '/lines');
        self::assertSame(['', ''], [$drawn['at_1'] ?? null, $drawn['at_2'] ?? null], 'each box has its moment, empty until it is typed into');
        $filled = Clock::now()->modify('-3 minutes');
        $ms = (int) $filled->format('Uv');
        $r = $web->post($sheet . '/lines', ['q_1' => '9', 'at_1' => (string) $ms] + $drawn);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame(Clock::db(\DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($ms, 1000), ($ms % 1000) * 1000))),
            self::$db->value('SELECT counted_at FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$id]), 'the moment the box was filled in');
        // A moment after now is refused: nothing is saved, the quantity stays in its box, and Enter again saves it with the time now.
        $r = $web->post($sheet . '/lines', ['q_2' => '4', 'at_2' => (string) ((int) Clock::now()->format('Uv') + 120_000)] + $drawn);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::say('COUNT_ERROR', 'bad_count_time', 2), $r->text());
        self::assertNull(self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 2', [$id]));
        $again = $r->form($sheet . '/lines');
        self::assertSame(['4', ''], [$again['q_2'] ?? null, $again['at_2'] ?? null]);
        // Anything that is not a moment is ignored (the time now).
        $r = $web->post($sheet . '/lines', ['q_2' => '4', 'at_2' => 'soon'] + $drawn);
        self::assertSame(303, $r->status, $r->describe());
        self::assertGreaterThan(Clock::db($filled->modify('+2 minutes')), (string) self::$db->value('SELECT counted_at FROM count_sheet_line WHERE document_id = ? AND line_no = 2', [$id]));
    }

    public function testSaveAndNextItemSavesThenOpensTheNextLineNotCounted(): void
    {
        // docs/decisions.md CV6 (the phone's one card): the line to count now carries "Save and next item", a button of the quantity form
        // (soft: "Save and review" stays the one main button). It saves the page exactly as Save does, then opens the next line not counted
        // after it (else the first one before it), marked, its box ready; when every line is counted, the sheet as after Save.
        $a = $this->item('strict', 10, 'Alpha pod 2ml');
        $b = $this->item('strict', 5, 'Beta liquid');
        $c = $this->item('strict', 3, 'Gamma kit');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $sheet = $this->start($web, [$a, $b, $c]);
        $id = (int) basename($sheet);
        $page = $web->get($sheet);
        $xp = new \DOMXPath($page->dom());
        self::assertSame(1, $xp->query('//tr[contains(@class, "now")]')->length, 'one line to count now');
        $next = $xp->query('//tr[@id="line-1" and contains(@class, "now")]//button[@type="submit" and @name="next" and @value="1"]')->item(0);
        self::assertNotNull($next);
        self::assertSame([Words::COUNTS['save_next'], 'btn soft lg save-next'], [trim((string) $next->textContent), $next->getAttribute('class')]);
        self::assertSame(Words::COUNTS['how_many'], (string) $xp->evaluate('string(//tr[@id="line-1"]//td[input[@name="q_1"]]/@data-label)'), 'the card asks the question');

        $r = $web->post($sheet . '/lines', ['q_1' => '9', 'next' => '1'] + $page->form($sheet . '/lines'));
        self::assertSame([303, $sheet . '?notice=lines&n=1&page=1&line=2#line-2'], [$r->status, $r->location()]);
        $then = $web->follow($r);
        $txp = new \DOMXPath($then->dom());
        self::assertSame(1, $txp->query('//tr[@id="line-2" and contains(@class, "now") and contains(@class, "marked")]//input[@name="q_2" and @autofocus]')->length);
        self::assertSame(0, $txp->query('//input[@name="code" and @autofocus]')->length);
        // Line 3 counted with its own button: none after it is left, so the first one before it.
        $r = $web->post($sheet . '/lines', ['q_3' => '2', 'next' => '3'] + $then->form($sheet . '/lines'));
        self::assertSame([303, $sheet . '?notice=lines&n=1&page=1&line=2#line-2'], [$r->status, $r->location()]);
        // A refused quantity: nothing saved, the sheet again with what was typed (as Save).
        $r = $web->post($sheet . '/lines', ['q_2' => 'four', 'next' => '2'] + $web->get($sheet)->form($sheet . '/lines'));
        self::assertSame(422, $r->status);
        self::assertSame('four', $r->form($sheet . '/lines')['q_2'] ?? null);
        // The last line: every line is counted, so the sheet as after Save (the scan box waits).
        $r = $web->post($sheet . '/lines', ['q_2' => '4', 'next' => '2'] + $web->get($sheet)->form($sheet . '/lines'));
        self::assertSame([303, $sheet . '?notice=lines&n=1&page=1&show=all'], [$r->status, $r->location()]);
        self::assertSame([9, 4, 2], array_map('intval', self::$db->column('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? ORDER BY line_no', [$id])));
        self::assertSame(0, (new \DOMXPath($web->follow($r)->dom()))->query('//tr[contains(@class, "now")]')->length, 'nothing left to count now');
    }

    public function testADifferenceCountedAgainSinceIsForAcceptingOnlyAsIsAFlagAboutACountsTime(): void
    {
        // Review C-1 and I-1 (14 Oct 2026) on the page: an older difference of an item counted again since says "counted again since:
        // accept it" instead of the reject button (a hand-made reject is refused); a flag about the time of a count (a parcel near a
        // count) is listed, with accept only.
        $a = $this->item('strict', 100, 'Alpha pod 2ml');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $first = $this->start($web, [$a]);
        $web->post($first . '/lines', ['csrf' => $this->token($web), 'q_1' => '80']);
        self::assertSame(303, $this->submit($web, $first . '/differences', $first . '/post')->status);
        $second = $this->start($web, [$a]);
        $web->post($second . '/lines', ['csrf' => $this->token($web), 'q_1' => '90']);
        self::assertSame(303, $this->submit($web, $second . '/differences', $second . '/post')->status);
        self::$db->exec("INSERT INTO count_review (warehouse_id, sku_id, source, ref, detail) VALUES (1, ?, 'ship_near_count', ?, ?)", [$a, '1:ORD-77:u9',
            json_encode(['unit_id' => 'u9', 'dispatched_at' => Clock::db(Clock::now()->modify('-2 minutes')), 'counted_at' => Clock::db(Clock::now()), 'applied' => 'normal'])]);
        $diffs = $web->get(self::PATH . '/differences');
        self::assertSame(200, $diffs->status, $diffs->describe() . ' ' . implode(' | ', self::$log));
        $xp = new \DOMXPath($diffs->dom());
        $old = self::PATH . '/differences/line/' . basename($first) . '/1';
        $new = self::PATH . '/differences/line/' . basename($second) . '/1';
        self::assertSame([0, 1], [$xp->query('//form[@action="' . $old . '"]//button[@value="reject"]')->length, $xp->query('//form[@action="' . $new . '"]//button[@value="reject"]')->length]);
        self::assertSame(1, $xp->query('//form[@action="' . $old . '"]//button[@value="accept"]')->length);
        self::assertStringContainsString(Words::COUNTS['counted_again'], (string) $xp->evaluate('string(//form[@action="' . $old . '"]/..)'));
        $r = $web->post($old, ['answer' => 'reject', 'note' => 'the first count was wrong'] + $diffs->form($old));
        self::assertSame(409, $r->status);
        self::assertStringContainsString(Words::COUNT_ERROR['counted_again'], $r->text());
        self::assertSame(90, $this->onHand($a), 'nothing was put back');
        // The flag about a count's time: its own group, the order it names, accept only.
        self::assertStringContainsString(Words::COUNT_REVIEW['ship_near_count'], $diffs->text());
        $review = (int) self::$db->value("SELECT id FROM count_review WHERE source = 'ship_near_count'");
        $action = self::PATH . '/differences/review/' . $review;
        self::assertSame([1, 0], [$xp->query('//form[@action="' . $action . '"]//button[@value="accept"]')->length, $xp->query('//form[@action="' . $action . '"]//button[@value="reject"]')->length]);
        self::assertStringContainsString('ORD-77', (string) $xp->evaluate('string(//form[@action="' . $action . '"]/ancestor::tr)'));
        $r = $web->post($action, ['answer' => 'accept', 'note' => 'the parcel had gone: the figure is right'] + $diffs->form($action));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('resolved', self::$db->value('SELECT status FROM count_review WHERE id = ?', [$review]));
    }

    public function testTooManyCountTimesMarksTheLinesToCountAgainAndOneButtonEmptiesThem(): void
    {
        // Review M-2 (14 Oct 2026): "too many count times" pointed at "the lines marked below", and none were. Now the lines of the
        // earliest times are marked, each with "Count it again", and one button empties all of them.
        $items = [];
        for ($i = 1; $i <= 21; $i++) {
            $items[] = $this->item('strict', 0, sprintf('Item %02d', $i));
        }
        $web = $this->signIn($this->uiUser('stock_controller'));
        $sheet = $this->start($web, $items);
        $id = (int) basename($sheet);
        $fields = ['csrf' => $this->token($web)];
        for ($i = 1; $i <= 21; $i++) {
            $fields['q_' . $i] = '1';
        }
        self::assertSame(303, $web->post($sheet . '/lines', $fields)->status);
        self::$db->exec('UPDATE count_sheet_line SET added_at = added_at - INTERVAL 300 MINUTE, counted_at = counted_at - INTERVAL ((21 - line_no) * 11) MINUTE, '
            . 'entered_at = entered_at - INTERVAL ((21 - line_no) * 11) MINUTE WHERE document_id = ?', [$id]);
        $before = $web->get($sheet . '/differences');
        self::assertSame(200, $before->status, $before->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::say('COUNTS', 'too_many_times', 20, 10, 2), $before->text());
        $xp = new \DOMXPath($before->dom());
        self::assertSame(2, $xp->query('//tr[contains(@class, "retime")]')->length);
        self::assertSame(['line-1', 'line-2'], array_map(static fn (\DOMElement $tr): string => $tr->getAttribute('id'), iterator_to_array($xp->query('//tr[contains(@class, "retime")]'))));
        self::assertFalse($before->hasForm($sheet . '/post'));
        $r = $this->submit($web, $sheet . '/differences', $sheet . '/recount', ['times' => '1']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('COUNT_NOTICE', 'recount_times', 2), $web->follow($r)->text());
        self::assertSame(['1', '2'], array_map('strval', self::$db->column('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND counted_qty IS NULL ORDER BY line_no', [$id])));
    }

    public function testTheApprovalRulesSayWhatTheStockCountRuleMeans(): void
    {
        // Review M-4 (14 Oct 2026): the "Stock count" card said "a reviewer checks those over 10 items within N days". Since CT8 the rule
        // is the second person who answers a count difference; and a stock count's Not OK only records it.
        $web = $this->signIn($this->uiUser('reviewer'));
        $page = $web->get('/ui/reference/approvals');
        self::assertSame(200, $page->status, $page->describe());
        $card = (new \DOMXPath($page->dom()))->query('//article[@id="rule-CNT"]')->item(0);
        self::assertNotNull($card);
        $text = (string) $card->textContent;
        self::assertStringContainsString(ucfirst(Words::SETTINGS_PAGE['rule_cnt_none']), $text);
        self::assertStringContainsString(Words::SETTINGS_PAGE['rule_record_cnt'], $text);
        self::assertStringContainsString(Words::APPROVALS['reject_record_only_cnt'], $text);
        self::assertStringNotContainsString('items within', $text);
        self::assertSame(0, (new \DOMXPath($page->dom()))->query('//article[@id="rule-CNT"]//select[@name="reject_action"]')->length);
        self::$db->exec("UPDATE document_type SET review_rule = 'over_limit' WHERE code = 'CNT'");
        try {
            $over = (string) (new \DOMXPath($web->get('/ui/reference/approvals')->dom()))->evaluate('string(//article[@id="rule-CNT"])');
            self::assertStringContainsString(ucfirst(Words::say('SETTINGS_PAGE', 'rule_cnt_over', 10)), $over);
        } finally {
            self::$db->exec("UPDATE document_type SET review_rule = 'none' WHERE code = 'CNT'");
        }
    }

    public function testTheDownloadTheUploadWithItsPreviewAndABlindCount(): void
    {
        $a = $this->item('strict', 10, 'Alpha pod 2ml');
        $b = $this->item('strict', 5, 'Beta liquid');
        $web = $this->signIn($this->uiUser('warehouse'));
        $sheet = $this->start($web, [$a, $b]);
        $id = (int) basename($sheet);
        $csv = $web->get($sheet . '/sheet.csv');
        self::assertSame([200, 'text/csv; charset=utf-8'], [$csv->status, $csv->header('content-type')]);
        foreach (['cw_code', 'stock_now', 'counted', sprintf('CW-%06d', $a), 'Alpha pod 2ml'] as $part) {
            self::assertStringContainsString($part, $csv->body);
        }

        $file = sys_get_temp_dir() . '/cw_count_ui_' . bin2hex(random_bytes(5)) . '.csv';
        try {
            file_put_contents($file, "cw_code,counted\n" . sprintf('CW-%06d', $a) . ",9\n" . sprintf('CW-%06d', $b) . ",x\nCW-999999,1\n");
            $form = $web->get($sheet)->form($sheet . '/upload');
            self::assertSame('check', $form['mode'] ?? null, 'checking is the default: nothing is saved by a first send');
            $check = $web->postMultipart($sheet . '/upload', $form, ['file' => ['path' => $file, 'name' => 'counted.csv']]);
            self::assertSame(200, $check->status, $check->describe() . ' ' . implode(' | ', self::$log));
            self::assertStringContainsString(Words::say('COUNTS', 'report_title', 'counted.csv'), $check->text());
            self::assertStringContainsString(Words::say('COUNTS', 'report_counts', 3, 1, 0, 0, 1, 1), $check->text());
            self::assertStringContainsString(Words::say('COUNTS', 'report_fill_new', 1, sprintf('CW-%06d', $a), 'Alpha pod 2ml', 9), $check->text());
            self::assertStringContainsString(Words::say('COUNTS', 'report_bad_row', 2, sprintf('CW-%06d', $b), Words::COUNT_BAD['not_whole'], 'x'), $check->text());
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ? AND counted_qty IS NOT NULL', [$id]), 'the preview wrote nothing');
            // The preview sits in the page; More and its paper fold come back open, so the file is sent again at once (CV7).
            self::assertSame(1, (new \DOMXPath($check->dom()))->query('//main//div[contains(@class, "doc-bar")]/details[contains(@class, "more") and @open]//details[@id="paper" and @open]')->length);
            $apply = $web->postMultipart($sheet . '/upload', ['mode' => 'apply', 'counted_at' => ''] + $form, ['file' => ['path' => $file, 'name' => 'counted.csv']]);
            self::assertSame(303, $apply->status, $apply->describe() . ' ' . implode(' | ', self::$log));
            self::assertStringContainsString(Words::say('COUNT_NOTICE', 'uploaded', 1), $web->follow($apply)->text());
            self::assertSame([9, 'upload', 'draft'], [(int) self::$db->value('SELECT counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$id]),
                self::$db->value('SELECT entered_how FROM count_sheet_line WHERE document_id = ? AND line_no = 1', [$id]),
                self::$db->value('SELECT status FROM document WHERE id = ?', [$id])], 'filled in, never made final');
            self::assertSame(10, $this->onHand($a));
            // A time it was counted, in UK time; one in the future is refused; a file that is no sheet; no file at all.
            file_put_contents($file, "cw_code,counted\n" . sprintf('CW-%06d', $b) . ",4\n");
            $yesterday = (new \DateTimeImmutable('yesterday 14:30', new \DateTimeZone('Europe/London')));
            $r = $web->postMultipart($sheet . '/upload', ['mode' => 'apply', 'counted_at' => $yesterday->format('Y-m-d\TH:i')] + $form, ['file' => ['path' => $file, 'name' => 'c.csv']]);
            self::assertSame(303, $r->status, $r->describe());
            self::assertSame(Clock::db($yesterday), self::$db->value('SELECT counted_at FROM count_sheet_line WHERE document_id = ? AND line_no = 2', [$id]));
            $r = $web->postMultipart($sheet . '/upload', ['mode' => 'apply', 'counted_at' => '2099-01-01T10:00'] + $form, ['file' => ['path' => $file, 'name' => 'c.csv']]);
            self::assertSame(400, $r->status);
            self::assertStringContainsString(Words::say('COUNT_ERROR', 'bad_counted_at', 90), $r->text());
            file_put_contents($file, "name,qty\nAlpha,3\n");
            $r = $web->postMultipart($sheet . '/upload', $form, ['file' => ['path' => $file, 'name' => 'other.csv']]);
            self::assertSame(400, $r->status);
            self::assertStringContainsString(Words::COUNT_ERROR['bad_file'], $r->text());
            $r = $web->post($sheet . '/upload', $form);
            self::assertSame(400, $r->status);
            self::assertStringContainsString(Words::COUNT_ERROR['no_file'], $r->text());
        } finally {
            @unlink($file);
        }

        // A blind count: the sheet and its file do not say what the system holds until a line is counted.
        $this->setting('counts.sheet_shows_stock', 'false');
        $c = $this->item('strict', 77, 'Gamma kit');
        $blind = $this->start($web, [$c]);
        $page = $web->get($blind);
        $bx = new \DOMXPath($page->dom());
        self::assertSame(Words::COUNTS['blind_tag'], trim((string) $bx->evaluate('string(//main//header//span[contains(@class, "tag")])')), 'the head says it is a blind count');
        self::assertSame(0, $bx->query('//table[contains(@class, "count-sheet")]//th[normalize-space() = "' . Words::COUNTS['stock_now'] . '"]')->length, 'no stock column');
        self::assertStringNotContainsString('77', (string) $bx->evaluate('string(//table[contains(@class, "count-sheet")])'));
        self::assertStringNotContainsString('stock_now', $web->get($blind . '/sheet.csv')->body);
        $web->post($blind . '/lines', ['csrf' => $this->token($web), 'q_1' => '70']);
        $after = new \DOMXPath($web->get($blind)->dom());
        self::assertSame('70', (string) $after->evaluate('string(//input[@name="q_1"]/@value)'));
        self::assertStringNotContainsString('77', (string) $after->evaluate('string(//table[contains(@class, "count-sheet")])'), 'never on the sheet, also once counted (CV8)');
    }

    public function testACancellationThatWaitsForAReviewerKeepsThePersonOnTheSheet(): void
    {
        $a = $this->item('strict', 30, 'Alpha pod 2ml');
        $web = $this->signIn($this->uiUser('stock_controller'));
        $sheet = $this->start($web, [$a]);
        $id = (int) basename($sheet);
        $web->post($sheet . '/lines', ['csrf' => $this->token($web), 'q_1' => '10']);
        self::assertSame(303, $this->submit($web, $sheet . '/differences', $sheet . '/post')->status);
        // Cancelling it puts 20 units back: it waits for a reviewer's OK, and the person stays on the sheet, which says so.
        $r = $this->submit($web, $sheet, $sheet . '/reverse', ['reason_code' => 'entered_in_error']);
        self::assertSame([303, $sheet . '?notice=cancel_waits'], [$r->status, $r->location()]);
        $page = $web->follow($r);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::COUNT_NOTICE['cancel_waits'], $page->text());
        $wait = (int) self::$db->value('SELECT id FROM document WHERE reverses_id = ?', [$id]);
        self::assertContains('/ui/documents/' . $wait, $page->hrefs(), 'the waiting cancellation is on History, where the reviewer decides');
        self::assertFalse($page->hasForm($sheet . '/reverse'), 'no second cancellation while one waits');
        self::assertSame(30 - 20, $this->onHand($a));
        // History's record page leads to the sheet.
        self::assertContains($sheet, $this->signIn($this->uiUser('auditor'))->get('/ui/documents/' . $wait)->hrefs());
    }

    public function testEveryPostNeedsTheJobTheOpenerAndTheCsrfToken(): void
    {
        $a = $this->item('strict', 10, 'Alpha pod 2ml');
        $opener = $this->signIn($this->uiUser('stock_controller'));
        $sheet = $this->start($opener, [$a]);
        $id = (int) basename($sheet);
        $buyer = $this->signIn($this->uiUser('buyer'));
        $admin = $this->signIn($this->uiUser('admin'));
        $other = $this->signIn($this->uiUser('warehouse'));
        $posts = ['/lines', '/scan', '/add', '/upload', '/recount', '/as-counted', '/post', '/cancel', '/reverse'];
        foreach ($posts as $action) {
            self::assertSame(403, $buyer->post($sheet . $action, ['csrf' => $this->token($buyer), 'version' => '1'])->status, "buyer {$action}");
            self::assertSame(403, $admin->post($sheet . $action, ['csrf' => $this->token($admin), 'version' => '1'])->status, "admin {$action}");
            self::assertSame(403, $opener->post($sheet . $action, ['version' => '1'])->status, "no token {$action}");
        }
        foreach ([self::PATH, self::PATH . '/differences/line/' . $id . '/1', self::PATH . '/differences/review/1'] as $path) {
            self::assertSame(403, $buyer->post($path, ['csrf' => $this->token($buyer), 'form_key' => str_repeat('a', 32), 'answer' => 'accept', 'note' => 'fine by me'])->status, "buyer {$path}");
            self::assertSame(403, $opener->post($path, ['form_key' => str_repeat('b', 32), 'answer' => 'accept', 'note' => 'fine by me'])->status, "no token {$path}");
        }
        // A buyer reads the sheet and the lists; an admin does not read records at all.
        $read = $buyer->get($sheet);
        self::assertSame(200, $read->status);
        self::assertFalse($read->hasForm($sheet . '/lines'));
        self::assertFalse($read->hasForm($sheet . '/scan'));
        self::assertSame(403, $admin->get($sheet)->status);
        // Someone else who counts sees the sheet, is told whose it is, and can neither enter nor finish it.
        $theirs = $other->get($sheet);
        self::assertSame(200, $theirs->status);
        self::assertStringContainsString(Words::COUNTS['not_opener'], $theirs->text());
        self::assertFalse($theirs->hasForm($sheet . '/lines'));
        $token = $this->token($other);
        $r = $other->post($sheet . '/lines', ['csrf' => $token, 'q_1' => '3']);
        self::assertSame(403, $r->status);
        self::assertStringContainsString(Words::COUNT_ERROR['not_creator'], $r->text());
        $opener->post($sheet . '/lines', ['csrf' => $this->token($opener), 'q_1' => '9']);
        $version = (string) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]);
        $r = $other->post($sheet . '/post', ['csrf' => $token, 'version' => $version]);
        self::assertSame(403, $r->status, 'a hand-made form by someone who did not open the sheet');
        self::assertSame(['draft', 10], [self::$db->value('SELECT status FROM document WHERE id = ?', [$id]), $this->onHand($a)]);
        // A record of another kind, or none, is not a count sheet; a GET of a form's address is not a page.
        self::assertSame(404, $opener->get(self::PATH . '/999999')->status);
        self::assertSame(404, $opener->post(self::PATH . '/999999/lines', ['csrf' => $this->token($opener), 'q_1' => '1'])->status);
        self::assertSame(405, $opener->get($sheet . '/post')->status);
    }
}
