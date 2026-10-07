<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\BarcodeSeeder;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;

/**
 * What the review screen shows of a proposal's evidence, so a person can check it (review findings,
 * data lens, measured against all 2,623 run3 screens): the judge's candidate refs (C1..C15, all of
 * them), the AI's pick, which fields disagree and how, vetoes apart from soft flags, the relabel band
 * with its rename and paired items, brands spelt differently, the items' barcodes (seeded or from
 * the listing they were minted from), where else a new item's barcode is, the queue order and the
 * quick confirm of a Key item. In-process through the real /ui kernel as cw_app.
 */
final class ReviewEvidenceTest extends KernelUiTestCase
{
    private const GTIN_A = '4006381333931';
    private const GTIN_B = '5012345678900';

    public function testTheCandidatesCarryTheirJudgeRefsAllOfThemAndTheAiPick(): void
    {
        $alt = $this->site('alt');
        $items = [];
        for ($i = 1; $i <= 15; $i++) {
            $items[$i] = $this->item('legacy', 0, "Candidate item {$i}");
        }
        $cands = [];
        foreach ($items as $i => $sku) {
            $cands[] = ['ref' => "C{$i}", 'cw_id' => "CWP-{$i}", 'sku_id' => $sku, 'role' => 'search', 'prescore' => 100 - $i,
                'vetoes' => $i === 2 ? ['strength'] : [], 'soft_flags' => $i === 3 ? ['price_outlier'] : []];
        }
        $l = $this->queued($alt, 'E1', 'Check', $items[13], ['product_title' => 'Nic shot 18mg 100VG', 'units_30d' => 5], ['evidence' => [
            'candidates' => $cands,
            'ai' => ['reason' => 'C13 fits; C12/C2 are 70VG', 'chosen' => ['cw_id' => 'CWP-13', 'sku_id' => $items[13], 'title' => 'Candidate item 13']],
        ]]);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/listing/' . $l);
        self::assertSame(200, $page->status, $page->describe());
        $rows = $this->rows($page, 'candidates');
        self::assertCount(15, $rows, 'every candidate the judge saw, not the first 10');
        self::assertSame(array_map(static fn (int $i): string => "C{$i}", range(1, 15)), array_map(static fn (array $r): string => $r[0], $rows), 'in judge order');
        self::assertStringContainsString('AI picked', $rows[12][1]);
        self::assertStringContainsString('proposed', $rows[12][1]);
        self::assertStringContainsString('veto: strength', $rows[1][4]);
        self::assertStringContainsString('price_outlier', $rows[2][4]);
        self::assertSame($this->skuCode($items[13]) . ' Candidate item 13 (the item shown above)', $this->dd($page, 'AI picked'));
        self::assertStringContainsString('(C1, C2, … are the rows of the candidates table below)', $this->dd($page, 'Reason'));

        // A Conflict proposal names no item; the AI's pick is still on the page, as a row and a line.
        $x = $this->item('legacy', 0, 'The chosen one');
        $l2 = $this->queued($alt, 'E2', 'Conflict', null, ['product_title' => 'Something'], ['evidence' => [
            'candidates' => [['ref' => 'C1', 'sku_id' => $items[1], 'cw_id' => 'CWP-1']],
            'ai' => ['reason' => 'C9 fits but the barcode is on two items', 'chosen' => ['cw_id' => 'CWP-99', 'sku_id' => $x, 'title' => 'The chosen one']],
        ]]);
        $p2 = $web->get('/ui/review/listing/' . $l2);
        $rows = $this->rows($p2, 'candidates');
        self::assertSame(['', $this->skuCode($x) . ' The chosen one AI picked'], [$rows[0][0], $rows[0][1]]);
        self::assertSame($this->skuCode($x) . ' The chosen one · use this item', $this->dd($p2, 'AI picked'));
        self::assertContains('/ui/review/listing/' . $l2 . '?pick=' . $x . '#decide', $p2->hrefs());
    }

    public function testFieldsThatDoNotAgreeAreNamedConflictsFirstAndFlagsAreGroupedByWeight(): void
    {
        $alt = $this->site('alt');
        $sku = $this->item('legacy', 0, 'Target item');
        $l = $this->queued($alt, 'E1', 'Check', $sku, ['product_title' => 'Listing'], [
            'flags' => ['ceiling:Check', 'price_outlier', 'strength'],
            'evidence' => ['target_vetoes' => ['strength'], 'target_soft_flags' => ['price_outlier'],
                'ai' => ['fields_not_agree' => ['pack' => 'unknown', 'volume' => 'unknown', 'brand_line' => 'conflict'],
                    'vetoes_on_chosen' => ['form'], 'soft_flags_on_chosen' => ['modifier_extra'], 'warnings' => ['quote_not_verbatim']]],
        ]);
        $page = $this->signIn($this->uiUser('mapper'))->get('/ui/review/listing/' . $l);
        self::assertSame('brand_line: conflict pack: unknown volume: unknown', $this->dd($page, 'Fields that do not agree'));
        self::assertSame("veto: strength (proposed item) veto: form (AI's pick)", $this->dd($page, 'Blocks a link'));
        self::assertSame("price_outlier (proposed item) modifier_extra (AI's pick)", $this->dd($page, 'Check'));
        self::assertSame('ceiling:Check', $this->dd($page, 'Other flags'));
        self::assertSame('quote_not_verbatim', $this->dd($page, 'AI warnings'));
        $xp = new \DOMXPath($page->dom());
        self::assertSame('brand_line: conflict', trim((string) $xp->evaluate('string(//span[contains(@class,"tag") and contains(@class,"bad")][1])')));
        // An old-style list still reads.
        $l2 = $this->queued($alt, 'E2', 'Check', $sku, [], ['evidence' => ['ai' => ['fields_not_agree' => ['flavour']]]]);
        self::assertSame('flavour', $this->dd($this->signIn($this->uiUser('mapper'))->get('/ui/review/listing/' . $l2), 'Fields that do not agree'));
    }

    public function testTheRelabelQueueIsNamedAndShowsTheRenameAndThePairedItems(): void
    {
        $alt = $this->site('alt');
        [$p1, $p2] = [$this->item('legacy', 0, 'Mad Blue Hayati Pro Max 10mg'), $this->item('legacy', 0, 'Blue Frozen Hayati Pro Max 10mg')];
        $note = 'Electrofag "Crystal Pro Max" = Vape and Go "Hayati Pro Max"';
        $l = $this->queued($alt, 'R1', 'Manual', null, ['product_title' => 'The Crystal Pro Max Mad Blue 10ml - 10mg', 'units_30d' => 70], [
            'ai_outcome' => 'cannot_tell', 'ai_confidence' => 85, 'flags' => ['relabel_pending'],
            'evidence' => ['band' => 'Manual (relabel)', 'relabel_pending' => $note, 'relabel_partners' => [
                ['cw_id' => 'CWP-28173', 'sku_id' => $p1, 'title' => 'Mad Blue', 'note' => $note], ['cw_id' => 'CWP-46948', 'sku_id' => $p2, 'title' => 'Blue Frozen', 'note' => $note],
            ]],
        ]);
        $web = $this->signIn($this->uiUser('mapper'));
        $dash = $web->get('/ui/');
        self::assertStringContainsString('Relabel (alias) 1 1', $dash->text());
        self::assertStringNotContainsString('Manual', $dash->text());
        $queue = $web->get('/ui/review', ['queue' => 'Manual']);
        self::assertStringContainsString('Relabel (alias) queue', $queue->text());
        self::assertStringContainsString('The rename itself (an alias) is not recorded on these screens', $queue->text());
        $row = $this->rows($queue, 'queue')[0];
        self::assertSame('cannot_tell', $row[5], 'no "85%" next to "Proposal: none"');
        self::assertStringContainsString('none', $row[4]);

        $page = $web->get('/ui/review/listing/' . $l, ['queue' => 'Manual']);
        self::assertSame('Relabel (alias) (the run says Manual (relabel))', $this->dd($page, 'Band'));
        self::assertStringStartsWith('relabel pending ' . $note, $this->dd($page, 'Rename'));
        self::assertStringStartsWith($this->skuCode($p1) . ' Mad Blue Hayati Pro Max 10mg · use this item ' . $this->skuCode($p2), $this->dd($page, 'Paired items'));
        self::assertContains('/ui/review/listing/' . $l . '?queue=Manual&pick=' . $p2 . '#decide', $page->hrefs());
        self::assertStringContainsString('← Relabel (alias) queue', $page->text());
    }

    public function testItemBarcodesShowFromTheMintingListingThenFromSkuBarcodeAndAreCompared(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        $lead = $this->staffUser('mapping_lead');
        $v = $this->profiled($vpg, '4711', ['product_title' => 'Elux Legend Blue Razz', 'brand' => 'Elux Vapes, Pods and E-Liquids', 'barcodes' => ['0' . self::GTIN_A, 'Black Grey']]);
        $sku = (int) $this->ds->mintAndLink($lead, $v, 0, ['name' => 'Elux Legend Blue Razz', 'brand' => 'Elux Nic Salt (Legend Salts) E-Liquids'], 'seed')['sku_id'];
        $l = $this->queued($alt, 'E1', 'Key', $sku, ['product_title' => 'Elux Legend Blue Razz', 'brand' => 'Elux Vapes, Pods and E-Liquids',
            'barcodes' => [self::GTIN_A], 'units_30d' => 3]);
        $web = $this->signIn($this->uiUser('mapper'));
        foreach (['before seeding' => false, 'after seeding' => true] as $when => $seed) {
            if ($seed) {
                (new BarcodeSeeder(self::$db))->seed(Caller::system('test'));
                self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sku_barcode WHERE sku_id = ?', [$sku]));
            }
            $page = $web->get('/ui/review/listing/' . $l, ['queue' => 'Key']);
            $xp = new \DOMXPath($page->dom());
            $card = trim(preg_replace('/\s+/', ' ', (string) $xp->evaluate('string(//section[@aria-labelledby="item-h"])')) ?? '');
            self::assertSame(self::GTIN_A, $this->dd($page, 'Barcodes', '//section[@aria-labelledby="item-h"]'), $when);
            self::assertStringContainsString('CWP-4711', $card, 'the first-match id of the item');
            $compare = [];
            foreach ($this->rows($page, 'compare') as $r) {
                $compare[$r[0]] = $r[3];
            }
            self::assertSame('same', $compare['Barcodes'], $when);
            self::assertSame('spelt differently', $compare['Brand'], 'a brand spelt differently is not a difference');
            $item = $web->get('/ui/items/' . $sku);
            self::assertStringContainsString(self::GTIN_A, $item->text());
            self::assertStringContainsString($seed ? 'origin_listing' : 'listing it was minted from', $item->text());
            self::assertStringNotContainsString('No barcode is known', $item->text());
        }
        // A scanned code with a leading zero finds the item.
        self::assertStringContainsString($this->skuCode($sku), $web->get('/ui/search', ['q' => '0' . self::GTIN_A])->text());
    }

    public function testANewItemShowsWhereElseItsBarcodeIsAndIsPreselectedOnlyWithoutAClash(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        $binned = $this->profiled($vpg, '9001', ['product_title' => 'Old Kit', 'variant_title' => 'Old Kit Black', 'barcodes' => [self::GTIN_B],
            'features' => ['variant_status' => 'Bin']]);
        $clash = $this->queued($alt, 'N1', 'New item', null, ['product_title' => 'Old Kit Black', 'barcodes' => [self::GTIN_B]], ['proposed_new_item' => true]);
        $fresh = $this->queued($alt, 'N2', 'New item', null, ['product_title' => 'Brand new kit', 'barcodes' => [self::GTIN_A]], ['proposed_new_item' => true]);
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get('/ui/review/listing/' . $clash, ['queue' => 'New item']);
        self::assertStringContainsString("This listing's barcode is also on vpg listing 9001 Old Kit Old Kit Black (unmapped, on the site: Bin)", $page->text());
        self::assertContains('/ui/review/listing/' . $binned, $page->hrefs());
        self::assertArrayNotHasKey('action', $page->form('/decide'), 'a possible duplicate is not preselected');
        $ok = $web->get('/ui/review/listing/' . $fresh, ['queue' => 'New item']);
        self::assertStringNotContainsString("This listing's barcode is also on", $ok->text());
        self::assertSame('new_item', $ok->form('/decide')['action'] ?? null, 'a New item proposal without a clash is preselected');
    }

    public function testTheQueueGoesBy365DaysAndAKeyItemIsConfirmedFromTheTop(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        $a = $this->item('legacy', 0, 'Item A');
        $l1 = $this->queued($alt, 'Q1', 'Key', $a, ['product_title' => 'Steady seller', 'units_30d' => 0, 'units_365d' => 900]);
        $l2 = $this->queued($alt, 'Q2', 'Key', $a, ['product_title' => 'Promo spike', 'units_30d' => 500, 'units_365d' => 600]);
        $l3 = $this->queued($alt, 'Q3', 'Key', $a, ['product_title' => 'Small', 'units_30d' => 40, 'units_365d' => 600]);
        $web = $this->signIn($this->uiUser('mapper'));
        $queue = $web->get('/ui/review', ['queue' => 'Key']);
        self::assertSame([$l1, $l2, $l3], $this->listed($queue), '365 days first, then 30 days');
        self::assertStringContainsString('best sellers first (units in 365 days, then 30 days)', $queue->text());
        $lanes = [];
        foreach ((new \DOMXPath($queue->dom()))->query('//select[@name="lane"]/option') ?: [] as $o) {
            $lanes[] = $o instanceof \DOMElement ? $o->getAttribute('value') : '';
        }
        self::assertSame(['', 'barcode', 'transfer', 'candidates'], $lanes, 'no lane that always shows nothing');

        // The quick confirm sits above the evidence and goes to the next listing.
        $page = $web->get('/ui/review/listing/' . $l1, ['queue' => 'Key']);
        $quick = null;
        foreach ($page->dom()->getElementsByTagName('form') as $f) {
            if (str_contains($f->getAttribute('class'), 'quick')) {
                $quick = $f;
            }
        }
        self::assertNotNull($quick);
        self::assertStringContainsString('Confirm link to ' . $this->skuCode($a) . ' and open the next listing', trim(preg_replace('/\s+/', ' ', $quick->textContent) ?? ''));
        $form = $page->form('/decide'); // both forms post to it; the first is the quick one
        self::assertSame(['link', (string) $a, '1'], [$form['action'], $form['sku_id'], $form['units_per_item']]);
        $r = $web->post('/ui/review/listing/' . $l1 . '/decide', $form);
        self::assertSame(303, $r->status, self::statusOf($r));
        self::assertStringStartsWith('/ui/review/listing/' . $l2 . '?', (string) $r->location());
        self::assertSame(['mapped', $a], [$this->link($l1)['status'], $this->link($l1)['sku_id']]);

        // Merge suggestions between Vape and Go items are counted on the dashboard, not queued: they have their own screen (M34).
        $v1 = $this->listing($vpg, 'D1', $this->item('legacy', 0, 'Item A again'));
        $this->propose($v1, 'Manual', $a, ['lane' => 'vpg_duplicate'], false, 'dups');
        $dash = $web->get('/ui/');
        self::assertStringContainsString('1 group of possible duplicate Vape and Go listings (the same product on two pages) waits on the Duplicates screen', $dash->text());
        self::assertContains('/ui/review/duplicates', $dash->hrefs());
    }

    // ---- helpers --------------------------------------------------------------------------------------

    /** Cell texts of the body rows of the first table whose class contains $class. @return list<list<string>> */
    private function rows(UiResponse $page, string $class): array
    {
        $out = [];
        $xp = new \DOMXPath($page->dom());
        foreach ($xp->query('(//table[contains(concat(" ", @class, " "), " ' . $class . ' ")])[1]/tbody/tr') ?: [] as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $c) {
                if ($c instanceof \DOMElement) {
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $c->textContent) ?? '');
                }
            }
            $out[] = $cells;
        }
        return $out;
    }

    /** @return list<int> listing ids in queue order */
    private function listed(UiResponse $page): array
    {
        $ids = [];
        foreach ($page->hrefs() as $h) {
            if (preg_match('#^/ui/review/listing/(\d+)\?#', $h, $m) === 1 && !in_array((int) $m[1], $ids, true)) {
                $ids[] = (int) $m[1];
            }
        }
        return $ids;
    }

    private function skuCode(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    /** The text of the <dd> after the <dt> named $term (in $within, an XPath, when given). */
    private function dd(UiResponse $page, string $term, string $within = ''): string
    {
        $xp = new \DOMXPath($page->dom());
        $v = (string) $xp->evaluate('string(' . $within . '//dt[normalize-space()="' . $term . '"]/following-sibling::dd[1])');
        return trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    }
}
