<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\BulkDecisions;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\BulkController;
use CW\Ui\Words;

/**
 * Store Products' "Create new warehouse products for these" (docs/decisions.md NF1-NF8; the owner, 22 Oct 2026: "tick a
 * whole family, then create new products for these in one go"), through the real /ui kernel as cw_app: the "Not matched" filter
 * (no warehouse product, nothing suggested) and the forgiving search find a family; a matching lead ticks it and presses the
 * action; the preview shows each new warehouse product's details as the single "Create a new product" makes them, and every
 * reason a row is skipped (a name or a barcode the warehouse already has, two ticked rows that would make one product, a
 * suggestion to match instead, matched, ignored, a landing page, a decision waiting, no name) and saves nothing; the apply makes
 * one item per row, matched with 1 sale = 1, and ends on the batch page with the counts and links to the new items; sending it
 * again makes nothing twice; the owner's bulk switch keeps the two-person rule; only a matching lead may use it; the single
 * "Create a new product" is unchanged.
 */
final class StoreNewItemsScreenTest extends KernelUiTestCase
{
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

    private function big(): Caller
    {
        $big = $this->site('vbig', 'shadow');
        self::$db->exec("UPDATE channel SET name = 'Big Store' WHERE code = 'vbig'");
        return $big;
    }

    /** A website product of the Hayati Pro Max 4000 family, with the features a matching run stores. @param array<string, mixed> $over */
    private function hayati(Caller $site, string $variant, string $flavour, int $sold, array $over = []): int
    {
        $words = array_values(array_filter(explode(' ', strtolower($flavour))));
        return $this->profiled($site, $variant, $over + [
            'product_title' => 'Hayati Pro Max 4000 Disposable',
            'variant_title' => 'Hayati Pro Max 4000 ' . $flavour,
            'brand' => 'Hayati',
            'units_30d' => $sold,
            'units_365d' => $sold,
            'features' => ['strength_mg' => 20, 'nic_type' => 'nic_salt', 'form' => 'disposable', 'puffs' => 4000, 'flavour_tokens' => $words,
                'line_tokens' => ['hayati', 'pro', 'max'], 'line_numbers' => ['4000']],
        ]);
    }

    /** @return list<string> the titles of a Store Products page */
    private static function titles(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent), iterator_to_array($xp->query('//tbody/tr/th/a[@class="o-name"]')));
    }

    /** @return array<string, string> the bar's buttons: action => formaction ('' when it posts to the form's own address) */
    private static function bar(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//div[@data-bulkbar]//button[@name="action"]') as $b) {
            /** @var \DOMElement $b */
            $out[$b->getAttribute('value')] = $b->getAttribute('formaction');
        }
        return $out;
    }

    /**
     * The preview's POST for these rows, as the Store Products form sends it when they are ticked and the new action is pressed;
     * a row that has no tick box on the page (matched, waiting, a landing page) is added as a crafted form would add it.
     *
     * @param list<int> $ids
     * @return array<string, string>
     */
    private function ticked(KernelBrowser $web, UiResponse $page, array $ids): array
    {
        $form = $page->form('/ui/review/bulk');
        self::assertNotSame([], $form, 'the page has the bulk form: ' . $page->describe());
        foreach ($ids as $id) {
            if (!isset($form['ver_' . $id])) {
                $form['ver_' . $id] = (string) $this->version($id);
                $form['prop_' . $id] = (string) (self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$id]) ?? '');
            }
            $form['pick_' . $id] = '1';
        }
        return ['action' => 'new_item'] + $form;
    }

    /**
     * The preview's lines: listing => outcome, its words, why, the reasons for a second OK, the new product's details (label =>
     * value) and what the apply sends for it.
     *
     * @return array<int, array{outcome: string, label: string, why: string, needs: list<string>, card: array<string, string>, field: string}>
     */
    private static function lines(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//table[contains(@class, "store-new-preview")]/tbody/tr') as $tr) {
            /** @var \DOMElement $tr */
            $card = [];
            foreach ($xp->query('.//dl[contains(@class, "new-card")]/dt', $tr) as $dt) {
                $dd = $dt->nextSibling;
                while ($dd !== null && !($dd instanceof \DOMElement)) {
                    $dd = $dd->nextSibling;
                }
                $card[self::squash($dt->textContent)] = self::squash($dd?->textContent);
            }
            $out[(int) substr((string) $xp->evaluate('string(th/input[@type="hidden"]/@name)', $tr), 4)] = [
                'outcome' => $tr->getAttribute('data-outcome'),
                'label' => self::squash($xp->evaluate('string(td[1]/span[contains(@class, "chip")])', $tr)),
                'why' => self::squash($xp->evaluate('string(td[1]/span[@class="why"])', $tr)),
                'needs' => array_map(static fn (\DOMNode $n): string => self::squash($n->textContent), iterator_to_array($xp->query('td[1]/span[@class="tag"]', $tr))),
                'card' => $card,
                'field' => (string) $xp->evaluate('string(th/input[@type="hidden"]/@value)', $tr),
            ];
        }
        return $out;
    }

    private static function squash(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /** @return array<string, int> how many rows the tables a create writes hold */
    private static function counts(): array
    {
        $out = [];
        foreach (['sku', 'match_decision', 'mapping_batch', 'mapping_batch_row', 'audit_log', 'idempotency', 'listing_map_history'] as $t) {
            $out[$t] = (int) self::$db->value('SELECT COUNT(*) FROM ' . $t);
        }
        return $out;
    }

    public function testTheNotMatchedFilterShowsOnlyRowsWithNoItemAndNothingSuggestedAndOnlyALeadGetsTheAction(): void
    {
        $big = $this->big();
        $sku = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $lead = $this->staffUser('mapping_lead');
        $h1 = $this->hayati($big, 'H1', 'Blue Razz Ice', 90);
        $h2 = $this->hayati($big, 'H2', 'Cherry Ice', 80);
        $other = $this->profiled($big, 'O1', ['product_title' => 'Elux Legend 3500', 'variant_title' => 'Elux Legend 3500 Kiwi', 'units_30d' => 70]);
        $suggested = $this->queued($big, 'S1', 'Key', $sku, ['product_title' => 'Hayati Pro Max 4000 Suggested', 'units_30d' => 60]);
        $newSuggested = $this->queued($big, 'N1', 'New item', null, ['product_title' => 'Hayati Pro Max 4000 New', 'units_30d' => 50], ['proposed_new_item' => 1]);
        $linked = $this->profiled($big, 'L1', ['product_title' => 'Hayati Pro Max 4000 Linked', 'units_30d' => 40]);
        $this->decide($lead, 'link', $linked, ['sku_id' => $sku, 'units_per_item' => 1]);
        $ignored = $this->profiled($big, 'I1', ['product_title' => 'Hayati Pro Max 4000 Ignored', 'units_30d' => 30]);
        $this->decide($lead, 'ignore', $ignored, ['reason' => 'placeholder']);
        $landing = $this->profiled($big, 'P1', ['product_title' => 'Hayati Pro Max 4000 Landing', 'units_30d' => 20]);
        self::$db->exec("UPDATE channel_listing SET status = 'landing' WHERE id = ?", [$landing]);
        $waiting = $this->profiled($big, 'W1', ['product_title' => 'Hayati Pro Max 4000 Waiting', 'units_30d' => 10]);
        $this->decide($lead, 'link', $waiting, ['sku_id' => $sku, 'units_per_item' => 10]);

        $web = $this->signIn($this->uiUser('mapping_lead'));
        $page = $web->get('/ui/review/store', ['channel' => 'vbig', 'state' => 'not_matched']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['Hayati Pro Max 4000 Disposable', 'Hayati Pro Max 4000 Disposable', 'Elux Legend 3500'], self::titles($page),
            'no warehouse product and nothing suggested: not a suggested, matched, ignored, landing or waiting one');
        self::assertSame(['Hayati Pro Max 4000 Disposable', 'Hayati Pro Max 4000 Disposable'],
            self::titles($web->get('/ui/review/store', ['channel' => 'vbig', 'state' => 'not_matched', 'q' => 'hayati pro max 4000'])), 'the family by the forgiving search');
        $xp = new \DOMXPath($page->dom());
        self::assertSame(['pick_' . $h1, 'pick_' . $h2, 'pick_' . $other],
            array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'), iterator_to_array($xp->query('//input[@data-bulk-row]'))));
        // A matching lead's bar has the new action, which opens the preview; the others keep their address.
        self::assertSame(['ignore' => '', 'unignore' => '', 'send_back' => '', 'new_item' => '/ui/review/store/new-items'], self::bar($page));
        self::assertSame(Words::STORE_NEW['button'], self::squash($xp->evaluate('string(//div[@data-bulkbar]//button[@value="new_item"])')));
        self::assertStringContainsString(Words::STORE_NEW['hint'], self::squash($page->text()));
        // A matcher's bar has not.
        $matcher = $this->signIn($this->uiUser('mapper'));
        $theirs = $matcher->get('/ui/review/store', ['channel' => 'vbig', 'state' => 'not_matched']);
        self::assertSame(['ignore' => '', 'unignore' => '', 'send_back' => ''], self::bar($theirs));
        self::assertStringNotContainsString(Words::STORE_NEW['hint'], self::squash($theirs->text()));
    }

    public function testThePreviewShowsEachNewProductAsTheSingleCreateMakesItAndEverySkipReasonAndSavesNothing(): void
    {
        $big = $this->big();
        $lead = $this->staffUser('mapping_lead');
        $existing = $this->item('legacy', 0, 'Hayati Pro Max 4000 Cherry Ice');
        $barcoded = $this->item('legacy', 0, 'Some other product');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('5060000000017', ?)", [$barcoded]);
        $code = static fn (int $id): string => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$id]);

        $good = $this->hayati($big, 'H1', 'Blue Razz Ice', 99);
        $sameName = $this->profiled($big, 'H2', ['product_title' => 'Hayati', 'variant_title' => 'Cherry Ice - Hayati Pro Max 4000', 'units_30d' => 98]);
        $sameBarcode = $this->hayati($big, 'H3', 'Grape', 97, ['barcodes' => ['05060000000017']]);
        $twinA = $this->hayati($big, 'H4', 'Pink Lemonade', 96);
        $twinB = $this->profiled($big, 'H5', ['product_title' => 'Hayati', 'variant_title' => 'hayati pro max 4000 lemonade PINK', 'units_30d' => 95]);
        $codeA = $this->hayati($big, 'H6', 'Mango', 94, ['barcodes' => ['5060000000024']]);
        $codeB = $this->hayati($big, 'H7', 'Peach', 93, ['barcodes' => ['5060000000024']]);
        $suggested = $this->queued($big, 'S1', 'Key', $existing, ['product_title' => 'Hayati Pro Max 4000 Watermelon', 'units_30d' => 92]);
        $newSuggested = $this->queued($big, 'N1', 'New item', null, ['product_title' => 'Hayati Pro Max 4000 Lime', 'units_30d' => 91], ['proposed_new_item' => 1]);
        $linked = $this->profiled($big, 'L1', ['product_title' => 'Hayati Pro Max 4000 Linked', 'units_30d' => 5]);
        $this->decide($lead, 'link', $linked, ['sku_id' => $existing, 'units_per_item' => 1]);
        $ignored = $this->profiled($big, 'I1', ['product_title' => 'Hayati Pro Max 4000 Ignored', 'units_30d' => 4]);
        $this->decide($lead, 'ignore', $ignored, ['reason' => 'placeholder']);
        $landing = $this->profiled($big, 'P1', ['product_title' => 'Hayati Pro Max 4000 Landing', 'units_30d' => 3]);
        self::$db->exec("UPDATE channel_listing SET status = 'landing' WHERE id = ?", [$landing]);
        $waiting = $this->profiled($big, 'W1', ['product_title' => 'Hayati Pro Max 4000 Waiting', 'units_30d' => 2]);
        $this->decide($lead, 'link', $waiting, ['sku_id' => $existing, 'units_per_item' => 10]);
        $noName = $this->listing($big, 'X1', null); // no profile: no name to make a product from

        $web = $this->signIn($this->uiUser('mapping_lead'));
        $page = $web->get('/ui/review/store', ['channel' => 'vbig']);
        $all = [$good, $sameName, $sameBarcode, $twinA, $twinB, $codeA, $codeB, $suggested, $newSuggested, $linked, $ignored, $landing, $waiting, $noName];
        $before = self::counts();
        $preview = $web->post('/ui/review/store/new-items', $this->ticked($web, $page, $all));
        self::assertSame(200, $preview->status, $preview->describe() . implode("\n", self::$log));
        self::assertSame($before, self::counts(), 'the preview writes nothing');
        self::assertSame('Store products', self::currentTab($preview));
        $lines = self::lines($preview);
        self::assertSame($all, array_keys($lines), 'every ticked row, in the order they are done (listing id)');
        $outcomes = array_map(static fn (array $l): string => $l['outcome'] === 'skipped' ? $l['why'] : $l['outcome'], $lines);
        self::assertSame([
            $good => 'done',
            $sameName => Words::say('BULK_SKIP', 'name_elsewhere', $code($existing)),
            $sameBarcode => Words::say('BULK_SKIP', 'barcode_on_item', $code($barcoded)),
            $twinA => Words::BULK_SKIP['name_twice'],
            $twinB => Words::BULK_SKIP['name_twice'],
            $codeA => Words::BULK_SKIP['barcode_twice'],
            $codeB => Words::BULK_SKIP['barcode_twice'],
            $suggested => Words::say('BULK_SKIP', 'match_instead', $code($existing)),
            $newSuggested => 'done',
            $linked => Words::BULK_SKIP['linked'],
            $ignored => BulkController::why('not_waiting', ['status' => 'ignored']),
            $landing => Words::BULK_SKIP['landing_page'],
            $waiting => Words::BULK_SKIP['pending_second'],
            $noName => Words::BULK_SKIP['no_name'],
        ], $outcomes);
        self::assertSame(Words::STORE_NEW['will_create'], $lines[$good]['label']);
        self::assertSame(Words::STORE_NEW['will_skip'], $lines[$sameName]['label']);
        // The new product's details: exactly the single page's "Details of the new product", and 1 sale = 1.
        self::assertSame([
            Words::FIELD['name'] => 'Hayati Pro Max 4000 Blue Razz Ice',
            Words::FIELD['brand'] => 'Hayati',
            Words::FIELD['strength_mg'] => '20',
            Words::FIELD['nic_type'] => Words::NIC_TYPE['nic_salt'],
            Words::FIELD['line'] => 'hayati pro max 4000',
            Words::FIELD['form'] => Words::FORM_VALUE['disposable'],
            Words::FIELD['flavour'] => 'blue razz ice',
            Words::FIELD['volume_ml'] => Words::LISTING['not_stated'],
            Words::FIELD['puffs'] => '4000',
            Words::FIELD['pack_units'] => Words::LISTING['not_stated'],
            Words::STORE_NEW['sale'] => Words::saleUses(1),
        ], $lines[$good]['card']);
        $single = $this->decideForm($web, $good);
        foreach (['name', 'brand', 'strength_mg', 'line', 'flavour', 'puffs'] as $f) {
            self::assertSame($single['card_' . $f], $lines[$good]['card'][Words::FIELD[$f]], "{$f}: as the variant's own page fills it in");
        }
        self::assertSame('Hayati Pro Max 4000 Lime', $lines[$newSuggested]['card'][Words::FIELD['name']]);
        self::assertStringContainsString(Words::say('STORE_NEW', 'summary', 2, 0, 12), self::squash($preview->text()));
        $xp = new \DOMXPath($preview->dom());
        self::assertSame(Words::say('STORE_NEW', 'go', 2), self::squash($xp->evaluate('string(//form[contains(@action, "/new-items/apply")]//button[@type="submit"])')));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $preview->form('/ui/review/store/new-items/apply')['form_key'] ?? '');
        self::assertSame('/ui/review/store?channel=vbig', $xp->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));
    }

    public function testApplyMakesOneItemPerRowMatchedOneToOneAndTheBatchPageCountsAndSendingAgainMakesNothingTwice(): void
    {
        $big = $this->big();
        $existing = $this->item('legacy', 0, 'Hayati Pro Max 4000 Cherry Ice');
        $a = $this->hayati($big, 'H1', 'Blue Razz Ice', 99);
        $b = $this->hayati($big, 'H2', 'Grape', 98);
        $dup = $this->hayati($big, 'H3', 'Cherry Ice', 97);
        $c = $this->queued($big, 'N1', 'New item', null, ['product_title' => 'Hayati Pro Max 4000 Lime', 'units_30d' => 96], ['proposed_new_item' => 1]);
        $skusBefore = (int) self::$db->value('SELECT COUNT(*) FROM sku');

        $web = $this->signIn($this->uiUser('mapping_lead'));
        $page = $web->get('/ui/review/store', ['channel' => 'vbig', 'state' => 'not_matched', 'q' => 'hayati']);
        $preview = $web->post('/ui/review/store/new-items', $this->ticked($web, $page, [$a, $b, $dup, $c]) + ['state' => 'not_matched', 'q' => 'hayati']);
        self::assertSame(200, $preview->status, $preview->describe());
        $form = $preview->form('/ui/review/store/new-items/apply');
        $done = $web->post('/ui/review/store/new-items/apply', $form);
        self::assertSame(303, $done->status, $done->describe() . implode("\n", self::$log));
        self::assertMatchesRegularExpression('#^/ui/review/batches/([0-9]+)$#', (string) $done->location());
        $batch = (int) substr((string) $done->location(), strlen('/ui/review/batches/'));
        self::assertSame(['new_item', 'store'], array_values(self::$db->one('SELECT action, source FROM mapping_batch WHERE id = ?', [$batch])));

        // One item per row, made from the variant's own details, matched with 1 sale = 1.
        self::assertSame($skusBefore + 3, (int) self::$db->value('SELECT COUNT(*) FROM sku'));
        foreach ([$a => 'Hayati Pro Max 4000 Blue Razz Ice', $b => 'Hayati Pro Max 4000 Grape', $c => 'Hayati Pro Max 4000 Lime'] as $lid => $name) {
            $l = $this->link($lid);
            self::assertSame(['mapped', 1], [$l['status'], $l['units_per_item']], "{$name} is matched 1 to 1");
            $s = self::$db->one('SELECT name, brand, origin, origin_listing_id FROM sku WHERE id = ?', [$l['sku_id']]);
            self::assertSame([$name, 'new_item', $lid], [$s['name'], $s['origin'], (int) $s['origin_listing_id']]);
            self::assertSame('screen:' . $batch, self::$db->value("SELECT bulk_batch_id FROM match_decision WHERE listing_id = ? AND action = 'new_item'", [$lid]));
        }
        self::assertSame('Hayati', self::$db->value('SELECT brand FROM sku WHERE id = ?', [$this->link($a)['sku_id']]));
        self::assertSame('unmapped', $this->link($dup)['status'], 'the one whose name the warehouse has is left');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE open_listing_id = ?', [$c]), 'its "new product" suggestion is settled');

        // The batch page: the counts, the reasons, links to the new items, the way back to the list as it was.
        $result = $web->follow($done);
        self::assertSame(200, $result->status, $result->describe());
        $text = self::squash($result->text());
        self::assertStringContainsString(Words::say('STORE_NEW', 'result_summary', 3, 0, 1), $text);
        self::assertStringContainsString(Words::say('BULK_SKIP', 'name_elsewhere', (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$existing])), $text);
        foreach ([$a, $b, $c] as $lid) {
            self::assertContains('/ui/items/' . $this->link($lid)['sku_id'], $result->hrefs(), 'a link to each new item');
        }
        self::assertStringContainsString(Words::STORE_NEW['result_what'], $text);
        self::assertSame('/ui/review/store?channel=vbig', (new \DOMXPath($result->dom()))->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));

        // The same form again (a double click, Back and send): the same batch page, nothing made or recorded twice.
        $counts = self::counts();
        $again = $web->post('/ui/review/store/new-items/apply', $form);
        self::assertSame([303, '/ui/review/batches/' . $batch], [$again->status, $again->location()], $again->describe());
        self::assertSame($counts, self::counts());
        // The same key with other rows is no form of ours: refused, nothing saved.
        $other = $form;
        unset($other['row_' . $a]);
        $reused = $web->post('/ui/review/store/new-items/apply', $other);
        self::assertSame(422, $reused->status, $reused->describe());
        self::assertSame($counts, self::counts());
        // A new preview of the same rows: every one is matched now, so nothing is made.
        $preview2 = $web->post('/ui/review/store/new-items', $this->ticked($web, $page, [$a, $b, $c]));
        self::assertSame(['skipped', 'skipped', 'skipped'], array_values(array_map(static fn (array $l): string => $l['outcome'], self::lines($preview2))));
        self::assertSame('', (new \DOMXPath($preview2->dom()))->evaluate('string(//form[contains(@action, "/new-items/apply")]//button[@type="submit"])'), 'nothing to do: no button');
        self::assertSame($skusBefore + 3, (int) self::$db->value('SELECT COUNT(*) FROM sku'));
    }

    public function testTheOwnersBulkSwitchKeepsTheTwoPersonRuleNothingIsMadeUntilAnotherLeadSaysYes(): void
    {
        $big = $this->big();
        $this->setting('approvals.mapping_bulk_second_ok', 'true');
        $a = $this->hayati($big, 'H1', 'Blue Razz Ice', 99);
        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $me = $this->uiUser('mapping_lead');
        $web = $this->signIn($me);
        $page = $web->get('/ui/review/store', ['channel' => 'vbig']);
        $preview = $web->post('/ui/review/store/new-items', $this->ticked($web, $page, [$a]));
        $line = self::lines($preview)[$a];
        self::assertSame(['pending_second', Words::STORE_NEW['will_wait'], [Words::NEEDS_SECOND['bulk']]], [$line['outcome'], $line['label'], $line['needs']]);
        self::assertStringContainsString(Words::say('STORE_NEW', 'summary', 0, 1, 0), self::squash($preview->text()));
        $done = $web->post('/ui/review/store/new-items/apply', $preview->form('/ui/review/store/new-items/apply'));
        self::assertSame(303, $done->status, $done->describe());
        self::assertSame($skus, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'nothing is made before the second OK');
        self::assertSame('unmapped', $this->link($a)['status']);
        self::assertStringContainsString(Words::say('STORE_NEW', 'result_summary', 0, 1, 0), self::squash($web->follow($done)->text()));
        $decision = (int) self::$db->value("SELECT id FROM match_decision WHERE listing_id = ? AND state = 'pending_second'", [$a]);
        self::assertSame(['bulk'], json_decode((string) self::$db->value('SELECT needs_second FROM match_decision WHERE id = ?', [$decision]), true));
        self::refused(403, 'same_person', fn () => $this->ds->approve(Caller::staff($me['id']), $decision));
        $this->ds->approve($this->staffUser('mapping_lead'), $decision);
        self::assertSame($skus + 1, (int) self::$db->value('SELECT COUNT(*) FROM sku'));
        self::assertSame(['mapped', 1], [$this->link($a)['status'], $this->link($a)['units_per_item']]);
        self::assertSame('Hayati Pro Max 4000 Blue Razz Ice', self::$db->value('SELECT name FROM sku WHERE id = ?', [$this->link($a)['sku_id']]));
    }

    public function testOnlyAMatchingLeadMayUseItAndBothRoutesCheckTheToken(): void
    {
        $big = $this->big();
        $a = $this->hayati($big, 'H1', 'Blue Razz Ice', 99);
        $leadWeb = $this->signIn($this->uiUser('mapping_lead'));
        $page = $leadWeb->get('/ui/review/store', ['channel' => 'vbig']);
        $form = $this->ticked($leadWeb, $page, [$a]);
        $apply = $leadWeb->post('/ui/review/store/new-items', $form)->form('/ui/review/store/new-items/apply');
        // (a refused request and a sign-in write their own audit rows: what counts is that nothing of the matching is written)
        $matching = static fn (): array => array_diff_key(self::counts(), ['audit_log' => 0]);
        $before = $matching();
        foreach (['viewer', 'mapper'] as $role) {
            $web = $this->signIn($this->uiUser($role));
            $csrf = $this->token($web);
            foreach (['/ui/review/store/new-items' => $form, '/ui/review/store/new-items/apply' => $apply] as $path => $fields) {
                $r = $web->post($path, ['csrf' => $csrf] + $fields);
                self::assertSame(403, $r->status, "{$role} {$path}: " . $r->describe());
            }
        }
        self::assertSame($before, $matching());
        // Without the session's token, or without the form's own key: refused, nothing saved.
        self::assertSame(403, $leadWeb->post('/ui/review/store/new-items/apply', ['csrf' => 'x'] + $apply)->status);
        $noKey = $apply;
        unset($noKey['form_key']);
        self::assertSame(400, $leadWeb->post('/ui/review/store/new-items/apply', $noKey)->status);
        self::assertSame($before, $matching());
        // The old bulk route never makes items from Store Products without the preview.
        $old = $leadWeb->post('/ui/review/bulk', $form);
        self::assertSame(400, $old->status, $old->describe());
        self::assertSame('unmapped', $this->link($a)['status']);
    }

    public function testTheSingleCreateANewProductIsUnchangedAlsoForARowTheBulkActionSkips(): void
    {
        $big = $this->big();
        $this->item('legacy', 0, 'Hayati Pro Max 4000 Cherry Ice');
        $dup = $this->hayati($big, 'H3', 'Cherry Ice', 97);
        $web = $this->signIn($this->uiUser('mapper'));
        $form = $this->decideForm($web, $dup);
        self::assertSame('Hayati Pro Max 4000 Cherry Ice', $form['card_name']);
        $r = $web->post('/ui/review/listing/' . $dup . '/decide', ['action' => 'new_item', 'units_per_item' => '1'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        $l = $this->link($dup);
        self::assertSame(['mapped', 1], [$l['status'], $l['units_per_item']], 'a matcher still creates one on the variant\'s own page, a person who sees its details');
        self::assertNull(self::$db->value('SELECT bulk_batch_id FROM match_decision WHERE listing_id = ?', [$dup]));
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Hayati Pro Max 4000 Cherry Ice'"));
    }
}
