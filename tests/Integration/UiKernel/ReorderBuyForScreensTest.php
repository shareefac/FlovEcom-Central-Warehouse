<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * "Buy for N days" on Purchasing › Reorder › Suggestions through the real /ui kernel as cw_app (docs/decisions.md BF1-BF12): the choice
 * (the usual cycles, 1, 2 and 4 weeks, a typed number that wins, refusals in words), the list, its figures, its "Why" and its download
 * bought for N, "Create draft orders" with N (the packs shown and N's suggestion on each draft line, N kept after the redirect, the same
 * form twice one effect, N changed in the form refused), the presets from the setting, and the website-stock view.
 */
final class ReorderBuyForScreensTest extends KernelUiTestCase
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

    /** @return list<array{0: string, 1: string, 2: bool}> the choices of "Buy for": value, words, selected */
    private static function choices(UiResponse $r): array
    {
        $out = [];
        foreach ((new \DOMXPath($r->dom()))->query('//form[contains(@class, "toolbar")]//select[@name="buy_for"]/option') ?: [] as $o) {
            \assert($o instanceof \DOMElement);
            $out[] = [$o->getAttribute('value'), trim((string) $o->textContent), $o->hasAttribute('selected')];
        }
        return $out;
    }

    /** @return list<list<string>> the CSV's rows (header first) */
    private static function csv(UiResponse $r): array
    {
        return array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), array_values(array_filter(preg_split('/\r\n/', substr($r->body, 3)) ?: [])));
    }

    public function testTheChoiceTheListItsFiguresAndItsDownload(): void
    {
        $s = $this->listScenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $usual = $web->get('/ui/purchasing/reorder');
        self::assertSame(200, $usual->status, $usual->describe());
        self::assertSame([['', Words::REORDER_BUY_FOR['usual'], true], ['7', '1 week', false], ['14', '2 weeks', false], ['28', '4 weeks', false]], self::choices($usual));
        $xp = new \DOMXPath($usual->dom());
        $box = $xp->query('//form[contains(@class, "toolbar")]//input[@name="buy_days"]')->item(0);
        \assert($box instanceof \DOMElement);
        self::assertSame(['number', '1', '180', ''], [$box->getAttribute('type'), $box->getAttribute('min'), $box->getAttribute('max'), $box->getAttribute('value')]);
        self::assertSame(1, $xp->query('//label[@for="tb-buyfor"][normalize-space(.)="' . Words::REORDER_BUY_FOR['label'] . '"]')->length);
        self::assertSame(1, $xp->query('//label[@for="tb-days"]')->length, 'the box has its label');
        self::assertSame('', $usual->form('/ui/purchasing/reorder/draft')['buy_for']);
        self::assertSame('5', $usual->form('/ui/purchasing/reorder/draft')['packs_' . $s['a']], 'A: 5 boxes the usual way');
        self::assertStringNotContainsString(Words::say('REORDER_BUY_FOR', 'chosen', '2 weeks'), $usual->text());

        $two = $web->get('/ui/purchasing/reorder', ['buy_for' => '14']);
        self::assertSame(200, $two->status, $two->describe());
        self::assertSame(array_map(self::skuCode(...), [$s['c'], $s['b'], $s['d'], $s['a']]), self::codes($two));
        self::assertSame(['', '7', '14', '28'], array_column(self::choices($two), 0));
        self::assertSame(['14'], array_column(array_filter(self::choices($two), static fn (array $c): bool => $c[2]), 0));
        $form = $two->form('/ui/purchasing/reorder/draft');
        self::assertSame(['14', '9', '5', '20', '21'], [$form['buy_for'], $form['packs_' . $s['a']], $form['packs_' . $s['b']], $form['packs_' . $s['c']], $form['packs_' . $s['d']]],
            'the packs for 2 weeks: A 22 × 12 = 264 - 60 -> 9 boxes; B 42 -> 5; C 115 -> 20; D 21');
        self::assertStringContainsString(Words::say('REORDER_BUY_FOR', 'chosen', '2 weeks'), $two->text(), 'the figures say the days chosen');
        self::assertStringContainsString(Words::say('WHY', 'cover_buy_for', 3, 14, 5, 22), $two->text(), 'A\'s Why: bought for 14 days (you chose)');
        self::assertStringContainsString('3 lead + 14 bought for (you chose, in place of 7 review) + 5 safety = 22 days', $two->text(), 'the maths');
        $xp = new \DOMXPath($two->dom());
        $href = (string) $xp->evaluate('string(//a[contains(@href, "reorder.csv")]/@href)');
        self::assertStringContainsString('buy_for=14', html_entity_decode($href), 'the download of the list shown');
        // Cost on the page: A 9 × £45 + B 5 × £8 + C 20 × £12 = £685.00 (D has no price).
        self::assertSame('£685.00', trim((string) $xp->evaluate('string(//section[contains(@class, "rp-figs")]/div[3]/p[contains(@class, "doc-step-big")])')));

        // A number typed wins over the choice and is shown as the choice; out of range: said, the choice stands.
        $typed = $web->get('/ui/purchasing/reorder', ['buy_for' => '14', 'buy_days' => '45']);
        self::assertSame(['45', '45 days', true], self::choices($typed)[4] ?? null);
        self::assertSame(['45', '24'], [$typed->form('/ui/purchasing/reorder/draft')['buy_for'], $typed->form('/ui/purchasing/reorder/draft')['packs_' . $s['a']]],
            'A: 53 × 12 = 636 - 60 = 576 -> 24 boxes');
        foreach (['500', '0', '2.5', 'two'] as $bad) {
            $r = $web->get('/ui/purchasing/reorder', ['buy_for' => '14', 'buy_days' => $bad]);
            self::assertSame(200, $r->status, $bad);
            self::assertStringContainsString(Words::say('REORDER_BUY_FOR', 'bad_typed', 1, 180), $r->text(), $bad);
            self::assertSame('14', $r->form('/ui/purchasing/reorder/draft')['buy_for'], "{$bad}: the choice stands");
        }
        $out = $web->get('/ui/purchasing/reorder', ['buy_for' => '181']);
        self::assertStringContainsString(Words::say('REORDER_BUY_FOR', 'out_of_range', 1, 180), $out->text());
        self::assertSame(['', '5'], [$out->form('/ui/purchasing/reorder/draft')['buy_for'], $out->form('/ui/purchasing/reorder/draft')['packs_' . $s['a']]], 'the usual list');
        self::assertStringContainsString(Words::say('REORDER_BUY_FOR', 'out_of_range', 1, 180), $web->get('/ui/purchasing/reorder', ['buy_for' => 'x'])->text());

        // The pages keep N (the pager and the filters' links carry it; the website-stock view works it out on the page).
        $site = $web->get('/ui/purchasing/reorder', ['buy_for' => '14', 'stock' => 'site']);
        self::assertSame(200, $site->status, $site->describe());
        self::assertSame(['14', '11'], [$site->form('/ui/purchasing/reorder/draft')['buy_for'], $site->form('/ui/purchasing/reorder/draft')['packs_' . $s['a']]],
            'no website stock: 264 -> 11 boxes');

        // The download bought for N: its review days, cover, packs and Why; the usual download as before.
        $rows = self::csv($web->get('/ui/purchasing/reorder.csv', ['buy_for' => '14', 'show' => 'all']));
        $col = array_flip($rows[0]);
        $byCode = array_column(array_slice($rows, 1), null, 0);
        self::assertCount(6, $rows, 'the header and every product');
        $a = $byCode[self::skuCode($s['a'])];
        self::assertSame(['14', '22', '264', '9', '216'], [$a[$col['review_days']], $a[$col['cover_days']], $a[$col['target']], $a[$col['packs']], $a[$col['units']]]);
        self::assertStringContainsString('14 bought for (you chose, in place of 7 review)', $a[$col['why']]);
        $usualRows = self::csv($web->get('/ui/purchasing/reorder.csv', ['show' => 'all']));
        self::assertSame($rows[0], $usualRows[0], 'the same columns');
        $ua = array_column(array_slice($usualRows, 1), null, 0)[self::skuCode($s['a'])];
        self::assertSame(['7', '15', '5'], [$ua[$col['review_days']], $ua[$col['cover_days']], $ua[$col['packs']]]);
        $typedCsv = array_column(array_slice(self::csv($web->get('/ui/purchasing/reorder.csv', ['buy_days' => '45'])), 1), null, 0);
        self::assertSame('24', $typedCsv[self::skuCode($s['a'])][$col['packs']], 'a typed number in the download too');

        // A cost past what a whole number holds (the Codex re-check of 9df1a0e, MEDIUM): the page, its total and its download still work.
        self::$db->exec("UPDATE reorder_line_detail SET line = JSON_SET(line, '$.rate_e4', 10000000000, '$.factor_e2', 100, '$.lead', 0, '$.safety', 0, "
            . "'$.available', 999999, '$.upp', 1, '$.moq', 1, '$.pack_price', '9999999.9999') WHERE sku_id = ?", [$s['d']]);
        self::$db->exec('UPDATE reorder_line SET d_e6 = 1000000000000, lead_days = 0, safety_days = 0, stock_position = 999999, units_per_pack = 1, moq_packs = 1, '
            . 'pack_price_e4 = 99999999999 WHERE sku_id = ?', [$s['d']]);
        $huge = $web->get('/ui/purchasing/reorder', ['buy_for' => '180']);
        self::assertSame(200, $huge->status, $huge->describe());
        self::assertSame('179000001', $huge->form('/ui/purchasing/reorder/draft')['packs_' . $s['d']]);
        self::assertStringContainsString('£100,000,000,000,000.00', $huge->text(), 'held at the largest cost');
        self::assertSame(200, $web->get('/ui/purchasing/reorder.csv', ['buy_for' => '180'])->status);

        // The choices come from the setting (a number outside 1-180 is not offered).
        $this->setting('reorder.buy_for_presets', '"21,7,200,7"');
        self::assertSame([['', Words::REORDER_BUY_FOR['usual'], true], ['21', '3 weeks', false], ['7', '1 week', false]], self::choices($web->get('/ui/purchasing/reorder')));
    }

    public function testCreateDraftOrdersWithN(): void
    {
        $s = $this->listScenario();
        $web = $this->signIn($this->uiUser('buyer'));
        $page = $web->get('/ui/purchasing/reorder', ['buy_for' => '14']);
        $form = $page->form('/ui/purchasing/reorder/draft');
        self::assertSame('14', $form['buy_for']);

        // N changed in the form: refused, nothing ordered, the usual list drawn with the refusal.
        $bad = $web->post('/ui/purchasing/reorder/draft', ['buy_for' => '999'] + $form);
        self::assertSame(422, $bad->status, $bad->describe());
        self::assertStringContainsString(Words::REORDER_ENGINE_ERROR['bad_buy_for'], $bad->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM purchase_order'));
        self::assertSame(422, $web->post('/ui/purchasing/reorder/draft', ['buy_for' => 'abc'] + $form)->status);

        // A, B at Elux; C at Mary; D has no supplier: two drafts, D skipped; back to the list bought for 2 weeks.
        $r = $web->post('/ui/purchasing/reorder/draft', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertMatchesRegularExpression('#^/ui/purchasing/reorder\?notice=drafts&drafts=\d+%2C\d+&skipped=' . $s['d'] . '&buy_for=14$#', (string) $r->location());
        $lines = array_map(static fn (array $x): array => array_map('intval', array_values($x)), self::$db->all(
            'SELECT pl.supplier_item_id, pl.packs, pl.suggested_units FROM po_line pl JOIN purchase_order po ON po.document_id = pl.document_id '
            . "WHERE po.source = 'reorder' ORDER BY pl.supplier_item_id"));
        self::assertSame([[(int) $s['si']['a']['id'], 9, 216], [(int) $s['si']['b']['id'], 5, 50], [(int) $s['si']['c']['id'], 20, 120]], $lines,
            'the packs shown, and the suggestion for 2 weeks stored with each line');
        $done = $web->follow($r);
        self::assertSame(200, $done->status, $done->describe());
        self::assertStringContainsString(Words::REORDER_NOTICE['drafts'], $done->text());
        self::assertSame(['14'], array_column(array_filter(self::choices($done), static fn (array $c): bool => $c[2]), 0), 'still bought for 2 weeks');
        self::assertStringContainsString(Words::say('REORDER_BUY_FOR', 'chosen', '2 weeks'), $done->text());
        // The same form again: the same drafts (one effect).
        $again = $web->post('/ui/purchasing/reorder/draft', $form);
        self::assertSame($r->location(), $again->location());
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM purchase_order WHERE source = 'reorder'"));
        $audit = json_decode((string) self::$db->value("SELECT CAST(detail AS CHAR) FROM audit_log WHERE action = 'reorder.draft_pos'"), true);
        self::assertSame(14, $audit['buy_for'] ?? null);
    }
}
