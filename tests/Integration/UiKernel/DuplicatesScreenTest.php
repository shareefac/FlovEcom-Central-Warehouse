<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\DecisionService;
use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\KernelUiTestCase;

/**
 * The Duplicates screen (docs/decisions.md M34) through the real /ui kernel as cw_app: the list (biggest sellers first, the
 * menu badge), a group side by side (titles, attributes, barcodes, price, units from the sales history, the site's stock and
 * mode, the live page, the CW item, the differences highlighted, a suggested keeper the person can change), "Same product -
 * merge into CW-x" and "Different products - keep separate" (one POST each, FormOnce + CSRF, the next group after it), one
 * choice per listing in a group of three, a stale form, a counted item (two people), the roles that only look, and the undo
 * of a merge (split). The stock invariants are asserted after every test.
 */
final class DuplicatesScreenTest extends KernelUiTestCase
{
    use ReorderFixtures;

    private int $batch = 0;
    private int $vpg = 0;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    /** The site, with a loaded sales history and stock snapshot to 1 Oct 2026. */
    private function vapeandgo(): Caller
    {
        $site = $this->site('vapeandgo', 'off');
        $this->vpg = (int) $site->channelId;
        return $site;
    }

    /**
     * A Vape and Go listing linked to its own item (minted for it), with profile, daily sales and the site's stock.
     *
     * @param array<string, mixed> $p profile (product_title, variant_title, brand, barcodes, features, price, perma_link, attributes)
     * @return array{listing: int, sku: int}
     */
    private function page(Caller $site, string $variant, int $onHand, int $perDay, array $p, int $stock = 5, string $mode = 'From-Warehouse'): array
    {
        $sku = $this->item('legacy', $onHand, (string) ($p['product_title'] ?? 'Item') . ' ' . (string) ($p['variant_title'] ?? ''));
        $id = $this->profiled($site, $variant, $p, $sku);
        self::$db->exec('UPDATE listing_profile SET price = ?, perma_link = ?, attributes = ? WHERE listing_id = ?',
            [$p['price'] ?? null, $p['perma_link'] ?? null, isset($p['attributes']) ? json_encode(['items' => $p['attributes']], JSON_THROW_ON_ERROR) : null, $id]);
        self::$db->exec('UPDATE sku SET origin = ?, origin_listing_id = ? WHERE id = ?', ['vpg_mint', $id, $sku]);
        $batch = $this->history($this->vpg, '2026-07-04', '2026-10-01', $perDay > 0 ? self::daily($variant, '2026-07-04', '2026-10-01', $perDay) : []);
        self::$db->exec('INSERT INTO listing_stock_latest (channel_id, external_variant_id, snapshot_date, stock, stock_mode, sellable, batch_id) VALUES (?, ?, ?, ?, ?, 1, ?)',
            [$this->vpg, $variant, '2026-10-01', $stock, $mode, $batch]);
        return ['listing' => $id, 'sku' => $sku];
    }

    /** mint_vpg's merge suggestions of one group: one per non-keeper, proposing the keeper's item. @param list<array{listing: int, sku: int}> $pages */
    private function group(int $number, array $pages, array $variants): array
    {
        $run = $this->proposals->run('run2-vpg-duplicates', 'vpg_duplicates');
        $members = array_map(static fn (array $pg, string $v): array => ['vpg_variant_id' => $v, 'sku_id' => $pg['sku'], 'units_30d' => 1], $pages, $variants);
        $ids = [];
        foreach (array_slice($pages, 1) as $pg) {
            $ids[] = $this->proposals->add(Caller::system('mint_vpg'), $pg['listing'], $run, ['proposed_sku_id' => $pages[0]['sku'], 'band' => 'Manual',
                'lane' => 'vpg_duplicate', 'flags' => ['identity_key', 'merge_suggestion'],
                'evidence' => ['group' => $number, 'kind' => 'identity_key', 'keeper' => $members[0], 'members' => $members]], false)['proposal_id'];
        }
        return $ids;
    }

    /** @return array{a: array{listing: int, sku: int}, b: array{listing: int, sku: int}, pb: int} the Corex-like pair (B sells more) */
    private function corex(Caller $site): array
    {
        $a = $this->page($site, '23408', 30, 2, ['product_title' => 'Vaporesso Xros Corex 3.0 Pods (Pack of 4)', 'variant_title' => '0.4 ohm', 'brand' => 'Vaporesso',
            'barcodes' => [], 'price' => '9.99', 'perma_link' => 'vaporesso-xros-corex-3-0-pods',
            'attributes' => [['attr_id' => 1, 'name' => 'Resistance', 'value' => '0.4 ohm', 'is_variable' => 1]],
            'features' => ['form' => 'pod_refill', 'resistance_ohm' => 0.4, 'pack_units' => 4, 'line_numbers' => ['3.0']]], 40, 'In-Stock');
        $b = $this->page($site, '25772', 8, 5, ['product_title' => 'Vaporesso Xros Corex Replacement Pods', 'variant_title' => '0.4ohm Corex 3.0 Pod - 4 Pack',
            'brand' => 'Vaporesso', 'barcodes' => ['6943498631842'], 'price' => '8.49', 'perma_link' => 'vaporesso-xros-corex-replacement-pods',
            'features' => ['form' => 'pod_refill', 'resistance_ohm' => 0.4, 'pack_units' => 4, 'line_numbers' => ['3.0']]], 3);
        // The run kept A (more units in 30 days when it ran); the screen suggests B (more in 365 days, a barcode).
        [$pb] = $this->group(7, [$a, $b], ['23408', '25772']);
        return ['a' => $a, 'b' => $b, 'pb' => $pb];
    }

    public function testTheListTheGroupSideBySideMergeKeepSeparateAndUndo(): void
    {
        $site = $this->vapeandgo();
        $x = $this->corex($site);
        // A second, smaller group whose titles differ in strength: not the same product.
        $c = $this->page($site, '31001', 4, 1, ['product_title' => 'Elfliq Blue Razz Nic Salt', 'variant_title' => '10mg', 'brand' => 'Elfliq',
            'features' => ['form' => 'e_liquid', 'strength_mg' => 10, 'volume_ml' => 10, 'flavour_tokens' => ['blue', 'razz']]]);
        $d = $this->page($site, '31002', 2, 1, ['product_title' => 'Elfliq Blue Razz Lemonade Nic Salt', 'variant_title' => '20mg', 'brand' => 'Elfliq',
            'features' => ['form' => 'e_liquid', 'strength_mg' => 20, 'volume_ml' => 10, 'flavour_tokens' => ['blue', 'razz', 'lemonade']]]);
        [$pd] = $this->group(8, [$c, $d], ['31001', '31002']);
        $lead = $this->uiUser('mapping_lead');
        $web = $this->signIn($lead);

        $list = $web->get('/ui/review/duplicates');
        self::assertSame(200, $list->status, $list->describe());
        self::assertContains(['label' => 'Duplicates 2', 'href' => '/ui/review/duplicates'], self::nav($list)['Linking'], 'the badge counts open groups');
        $rows = (new \DOMXPath($list->dom()))->query('//table[contains(@class, "dups")]/tbody/tr/th/a');
        self::assertSame(['/ui/review/duplicates/' . $x['pb'], '/ui/review/duplicates/' . $pd], [$rows->item(0)?->getAttribute('href'), $rows->item(1)?->getAttribute('href')],
            'the biggest sellers first (365 days)');
        self::assertStringContainsString('Vaporesso Xros Corex Replacement Pods', (string) $rows->item(0)?->textContent, 'the suggested keeper: more sold, a barcode');
        // What the rules say (M44): nothing against the Corex pair, a different strength (and more) for the Elfliq pair.
        self::assertStringContainsString('no reason against found: check the live pages', $list->text());
        self::assertStringContainsString('may be different: strength', $list->text());

        // The group page: both pages side by side.
        $g = $web->get('/ui/review/duplicates/' . $x['pb']);
        self::assertSame(200, $g->status, $g->describe());
        $t = $g->text();
        foreach (['Vaporesso Xros Corex 3.0 Pods (Pack of 4)', '0.4ohm Corex 3.0 Pod - 4 Pack', '9.99', '8.49', '6943498631842', 'Resistance', 'In-Stock',
            'From-Warehouse', 'not counted yet', '60 in 30 days, 180 in 365 days', '150 in 30 days, 450 in 365 days', sprintf('CW-%06d', $x['a']['sku']), 'CWP-23408'] as $want) {
            self::assertStringContainsString($want, $t);
        }
        self::assertContains('https://www.vapeandgo.co.uk/product/vaporesso-xros-corex-3-0-pods', $g->hrefs());
        self::assertContains('https://www.vapeandgo.co.uk/product/vaporesso-xros-corex-replacement-pods', $g->hrefs());
        $xp = new \DOMXPath($g->dom());
        self::assertSame(0, $xp->query('//table[contains(@class, "dup-compare")]//tr[@class="differs"]')->length, 'ohm, pack and model number agree');
        $form = $g->form('/ui/review/duplicates/' . $x['pb'] . '/decide');
        self::assertSame((string) $x['b']['listing'], $form['keeper'], 'suggested keeper');
        self::assertSame((string) $x['b']['sku'], $form['keep_sku']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $form['form_key']);
        self::assertStringContainsString('Same product - merge into ' . sprintf('CW-%06d', $x['b']['sku']), $t);
        // The person keeps A instead (the run's keeper).
        $g = $web->get('/ui/review/duplicates/' . $x['pb'], ['keeper' => (string) $x['a']['listing']]);
        self::assertStringContainsString('Same product - merge into ' . sprintf('CW-%06d', $x['a']['sku']), $g->text());
        $form = $g->form('/ui/review/duplicates/' . $x['pb'] . '/decide');
        self::assertSame((string) $x['a']['listing'], $form['keeper']);

        // Same product: one POST; the next group opens with the note.
        $r = $web->post('/ui/review/duplicates/' . $x['pb'] . '/decide', $form + ['do' => 'merge_all']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/review/duplicates/' . $pd . '?notice=merged', $r->location());
        self::assertSame($x['a']['sku'], $this->link($x['b']['listing'])['sku_id']);
        $this->assertBal(38, 0, 0, $x['a']['sku']);
        $this->assertBal(0, 0, 0, $x['b']['sku']);
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$x['pb']]));
        // The same form again (a double click): replayed, nothing twice.
        $again = $web->post('/ui/review/duplicates/' . $x['pb'] . '/decide', $form + ['do' => 'merge_all']);
        self::assertSame([303, $r->location()], [$again->status, $again->location()]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE action = 'merge_skus'"));

        $next = $web->follow($r);
        self::assertStringContainsString('Both pages now share one warehouse item. On the website they stay separate pages with their own price and reviews until '
            . 'Vape and Go switches to the warehouse system.', $next->text());
        // The strength (and a flavour word) differ: highlighted.
        $xp = new \DOMXPath($next->dom());
        $differs = array_map(static fn (\DOMNode $n): string => trim((string) $n->firstChild?->textContent),
            iterator_to_array($xp->query('//table[contains(@class, "dup-compare")]//tr[@class="differs"]/th')));
        self::assertSame(['Strength (mg)', 'Flavour words'], $differs);
        self::assertSame(['lemonade'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//span[contains(@class, "word") and contains(@class, "odd")]'))));

        // Different products: kept separate for good; the list is empty now.
        $r = $web->post('/ui/review/duplicates/' . $pd . '/decide', $next->form('/ui/review/duplicates/' . $pd . '/decide') + ['do' => 'separate_all']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/review/duplicates?notice=done', $r->location());
        self::assertSame([$c['sku'], $d['sku'], 'decided'], [$this->link($c['listing'])['sku_id'], $this->link($d['listing'])['sku_id'],
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pd])]);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject WHERE listing_id = ? AND sku_id = ?', [$d['listing'], $c['sku']]));
        $done = $web->follow($r);
        self::assertStringContainsString('No duplicates are left to decide.', $done->text());
        self::assertStringContainsString('Decided recently', $done->text());
        self::assertContains(['label' => 'Duplicates', 'href' => '/ui/review/duplicates'], self::nav($done)['Linking']);

        // The merged group: the undo. B goes back to its own item with its 8.
        $g = $web->get('/ui/review/duplicates/' . $x['pb']);
        self::assertStringContainsString('Undo a wrong merge', $g->text());
        $undo = $g->form('/ui/review/duplicates/' . $x['pb'] . '/split');
        self::assertSame(['former', (string) $x['b']['listing']], [$undo['to'], $undo['listing']]);
        $r = $web->post('/ui/review/duplicates/' . $x['pb'] . '/split', $undo);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/review/duplicates/' . $x['pb'] . '?notice=split', $r->location());
        self::assertSame($x['b']['sku'], $this->link($x['b']['listing'])['sku_id']);
        $this->assertBal(30, 0, 0, $x['a']['sku']);
        $this->assertBal(8, 0, 0, $x['b']['sku']);
        self::assertStringContainsString('Split off', $web->follow($r)->text());
    }

    /** A group of three: one choice per page; "not sure yet" leaves one for later; the badge and the list follow. */
    public function testAGroupOfThreeWithAChoicePerListing(): void
    {
        $site = $this->vapeandgo();
        $a = $this->page($site, '500', 10, 3, ['product_title' => 'Lost Mary BM600 Cola', 'features' => ['form' => 'disposable', 'puffs' => 600]]);
        $b = $this->page($site, '501', 4, 1, ['product_title' => 'Lost Mary BM600 Cola Disposable', 'features' => ['form' => 'disposable', 'puffs' => 600]]);
        $c = $this->page($site, '502', 2, 1, ['product_title' => 'Lost Mary BM6000 Cola', 'features' => ['form' => 'disposable', 'puffs' => 6000]]);
        [$pb, $pc] = $this->group(3, [$a, $b, $c], ['500', '501', '502']);
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $g = $web->get('/ui/review/duplicates/' . $pb);
        self::assertSame(200, $g->status, $g->describe());
        self::assertSame(['merge', 'separate', 'later'], $g->radios('c_' . $b['listing']));
        self::assertStringContainsString('Puffs differs', $g->text());
        $form = $g->form('/ui/review/duplicates/' . $pb . '/decide');
        self::assertSame((string) $a['listing'], $form['keeper']);
        self::assertSame(['later', 'later'], [$form['c_' . $b['listing']], $form['c_' . $c['listing']]]);

        // Nothing chosen: refused in plain words, nothing saved.
        $r = $web->post('/ui/review/duplicates/' . $pb . '/decide', $form + ['do' => 'save']);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('Choose "same product" or "different products" for at least one listing', $r->text());
        // The rules see reasons against (these test pages state no brand and no price): a merge needs "I checked the live pages".
        $r = $web->post('/ui/review/duplicates/' . $pb . '/decide', ['c_' . $b['listing'] => 'merge', 'do' => 'save'] + $form);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('The rules found reasons that listing #' . $b['listing'] . ' is a different product', $r->text());
        self::assertSame($b['sku'], $this->link($b['listing'])['sku_id'], 'nothing saved');
        // B is the same product (checked); C not sure yet.
        $r = $web->post('/ui/review/duplicates/' . $pb . '/decide', ['c_' . $b['listing'] => 'merge', 'do' => 'save', 'confirm' => '1'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/review/duplicates/' . $pb . '?notice=merged', $r->location(), 'no other group, C still open: this group again');
        self::assertSame([$a['sku'], $c['sku']], [$this->link($b['listing'])['sku_id'], $this->link($c['listing'])['sku_id']]);
        self::assertSame(['decided', 'open'], [self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pb]),
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pc])]);
        $list = $web->get('/ui/review/duplicates');
        self::assertStringContainsString('partly decided', $list->text());
        self::assertContains(['label' => 'Duplicates 1', 'href' => '/ui/review/duplicates'], self::nav($list)['Linking']);
        // C: a different product (6000 puffs).
        $g = $web->get('/ui/review/duplicates/' . $pb);
        self::assertStringContainsString('Same warehouse item as the kept one', $g->text());
        self::assertSame([], $g->radios('c_' . $b['listing']), 'B is decided');
        $r = $web->post('/ui/review/duplicates/' . $pb . '/decide', $g->form('/ui/review/duplicates/' . $pb . '/decide') + ['do' => 'separate_all']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pc]));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject WHERE listing_id = ? AND sku_id = ?', [$c['listing'], $a['sku']]));
        $this->assertBal(14, 0, 0, $a['sku']);
        $this->assertBal(2, 0, 0, $c['sku']);
    }

    /** A stale form, a counted item, and the roles that only look. */
    public function testStaleFormsCountedItemsAndRoles(): void
    {
        $site = $this->vapeandgo();
        $x = $this->corex($site);
        $lead = $this->uiUser('mapping_lead');
        $web = $this->signIn($lead);
        $form = $web->get('/ui/review/duplicates/' . $x['pb'])->form('/ui/review/duplicates/' . $x['pb'] . '/decide');

        // The site renamed page A after the page was drawn (its map_version moved): the whole form is refused, nothing saved.
        DecisionService::identityChanged(self::$db, [$x['a']['listing']]);
        $r = $web->post('/ui/review/duplicates/' . $x['pb'] . '/decide', $form + ['do' => 'merge_all']);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString('changed since the page was drawn', $r->text());
        self::assertSame([$x['a']['sku'], $x['b']['sku']], [$this->link($x['a']['listing'])['sku_id'], $this->link($x['b']['listing'])['sku_id']]);
        self::assertTrue($r->hasForm('/ui/review/duplicates/' . $x['pb'] . '/decide'), 'drawn again as it is now');
        self::assertSame([], $this->mergeLedger());

        // A counted item: the merge waits for a second mapping lead.
        $this->book('count', $x['b']['sku'], 8, 'MAIN', '2026-09-26T10:00:00Z');
        $g = $web->get('/ui/review/duplicates/' . $x['pb']);
        self::assertStringContainsString('a merge needs two mapping leads', $g->text());
        $r = $web->post('/ui/review/duplicates/' . $x['pb'] . '/decide', $g->form('/ui/review/duplicates/' . $x['pb'] . '/decide') + ['do' => 'merge_all']);
        self::assertSame('/ui/review/duplicates/' . $x['pb'] . '?notice=pending', $r->location());
        self::assertSame(['pending_second', '["counted_item"]'], array_values((array) self::$db->one("SELECT state, CAST(needs_second AS CHAR) FROM match_decision WHERE action = 'merge_skus'")));
        $g = $web->get('/ui/review/duplicates/' . $x['pb']);
        self::assertStringContainsString('Waiting for a second person', $g->text());
        self::assertStringContainsString('fold', $web->get('/ui/review', ['queue' => 'pending'])->text(), 'in the second-approval list');

        // Viewer, mapper and admin: they look, they do not decide.
        foreach ([['viewer'], ['mapper'], ['admin']] as $roles) {
            $w = $this->signIn($this->uiUser($roles));
            $p = $w->get('/ui/review/duplicates/' . $x['pb']);
            self::assertSame(200, $p->status, implode(',', $roles) . ': ' . $p->describe());
            self::assertFalse($p->hasForm('/decide'), implode(',', $roles));
            self::assertStringContainsString('but not decide them: merging and keeping separate is for a mapping lead', $p->text());
            $r = $w->post('/ui/review/duplicates/' . $x['pb'] . '/decide', ['csrf' => $this->token($w), 'do' => 'separate_all']);
            self::assertSame(403, $r->status, implode(',', $roles));
            $r = $w->post('/ui/review/duplicates/' . $x['pb'] . '/split', ['csrf' => $this->token($w)]);
            self::assertSame(403, $r->status, implode(',', $roles));
        }
        // A proposal that is not a merge suggestion has no group page.
        self::assertSame(404, $web->get('/ui/review/duplicates/999999')->status);
    }

    /** A group of the wider duplicate sweep (M37, bin/import_vpg_duplicates.php) says how it was found and why, escaped, known fields only. */
    public function testASweepGroupShowsWhyTheSweepSuggestedIt(): void
    {
        $site = $this->vapeandgo();
        $a = $this->page($site, '41619', 10, 2, ['product_title' => 'Vaporesso Xros Corex 3.0 Pods (Pack of 4)', 'variant_title' => '1.2 ohm', 'brand' => 'Vaporesso',
            'features' => ['form' => 'refill_pod_cartridge', 'resistance_ohm' => 1.2, 'pack_units' => 4]]);
        $b = $this->page($site, '44206', 4, 3, ['product_title' => 'Vaporesso Xros Corex Replacement Pods', 'variant_title' => '1.2ohm Corex 3.0 Pod - 4 Pack', 'brand' => 'Vaporesso',
            'features' => ['form' => 'refill_pod_cartridge', 'resistance_ohm' => 1.2, 'pack_units' => 4]]);
        $run = $this->proposals->run('sweep-ds1.0-0123456789ab', 'vpg_dup_sweep', null, 'ds1.0');
        $members = [['vpg_variant_id' => '44206', 'sku_id' => $b['sku']], ['vpg_variant_id' => '41619', 'sku_id' => $a['sku']]];
        $pid = $this->proposals->add(Caller::system('import_vpg_duplicates'), $a['listing'], $run, ['proposed_sku_id' => $b['sku'], 'band' => 'Manual',
            'lane' => 'vpg_duplicate', 'flags' => ['merge_suggestion', 'sweep'], 'evidence' => ['group' => 1, 'kind' => 'sweep', 'key' => "ds1.0:{$a['sku']}-{$b['sku']}",
                'keeper' => $members[0], 'members' => $members, 'sweep' => ['engine' => 'ds1.0', 'score' => 58, 'pairs' => [
                    ['a' => '44206', 'b' => '41619', 'score' => 58, 'agree' => ['form', 'resistance', 'pack', '<script>x</script>'], 'unknown' => ['strength', 'flavour'],
                        'barcode' => 'one_side', 'price_ratio' => 0.909, 'same_product_page' => false],
                    ['a' => '<b>bold</b>', 'b' => '41619', 'score' => 'high', 'agree' => 'all', 'barcode' => 'maybe'],
                ]]]], false)['proposal_id'];
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $g = $web->get('/ui/review/duplicates/' . $pid);
        self::assertSame(200, $g->status, $g->describe());
        $t = $g->text();
        foreach (['suggested because the duplicate sweep found no difference (same brand, form, size, strength and flavour; the titles may be worded differently)', 'Why the sweep suggested this',
            'Score 58 of 100.', 'score 58; the same form, ohm, pack; neither page states strength, flavour; a barcode on one page only; price ratio 0.91.',
            'variant <b>bold</b>'] as $want) {
            self::assertStringContainsString($want, $t);
        }
        self::assertStringNotContainsString('<script>', $g->body, 'a field the screen does not know is dropped, text is escaped');
        self::assertStringNotContainsString('<b>bold</b>', $g->body);
        self::assertContains('/ui/review/listing/' . $b['listing'], $g->hrefs(), 'a page of the group is linked by its listing');
        self::assertTrue($g->hasForm('/decide'), 'decided on the screen like any other group');
    }

    /**
     * M44 (review of 7 Oct 2026): the group page says, first and in plain words, what the rules find against merging (here a
     * different VG/PG ratio); "Different products - keep separate" is then the main button, and a merge needs "I checked the live
     * pages" (refused without it, nothing saved). The comparison has the option text, VG/PG and barcode rows.
     */
    public function testTheRulesReasonsComeFirstAndAMergeAgainstThemNeedsTheLivePagesChecked(): void
    {
        $site = $this->vapeandgo();
        $p = ['brand' => 'DarkStar', 'price' => '1.99', 'barcodes' => []];
        $a = $this->page($site, '165', 3, 2, ['product_title' => 'Dark Star Nic Shot 18mg 50VG/50PG'] + $p);
        $b = $this->page($site, '50', 2, 1, ['product_title' => 'Dark Star Nic Shot 18mg 70VG/30PG'] + $p);
        [$pb] = $this->group(3, [$a, $b], ['165', '50']);
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $list = $web->get('/ui/review/duplicates');
        self::assertStringContainsString('may be different: VG/PG', $list->text());

        $g = $web->get('/ui/review/duplicates/' . $pb);
        self::assertSame(200, $g->status, $g->describe());
        $t = $g->text();
        self::assertStringContainsString('The rules found reasons these may be different products.', $t);
        self::assertStringContainsString('Different VG/PG ratios: 50 vs 70', $t);
        self::assertLessThan(strpos($t, 'What the titles and options say'), strpos($t, 'What the rules say'), 'the reasons come before the cards and the table');
        $xp = new \DOMXPath($g->dom());
        self::assertSame('separate_all', $xp->query('//form[contains(@class, "dup-decide")]//button[contains(@class, "primary")]')->item(0)?->getAttribute('value'));
        $differs = array_map(static fn (\DOMNode $n): string => trim((string) $n->firstChild?->textContent),
            iterator_to_array($xp->query('//table[contains(@class, "dup-compare")]//tr[@class="differs"]/th')));
        self::assertContains('VG/PG', $differs);
        self::assertStringContainsString('VG 50 / PG 50', $t);

        $form = $g->form('/ui/review/duplicates/' . $pb . '/decide');
        self::assertArrayNotHasKey('confirm', array_filter($form, static fn (string $v): bool => $v !== ''), 'not ticked for the person');
        $r = $web->post('/ui/review/duplicates/' . $pb . '/decide', $form + ['do' => 'merge_all']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString('Nothing was saved: open the live pages, and if they are the same product tick "I checked the live pages"', $r->text());
        self::assertSame([], $this->mergeLedger());
        self::assertSame($b['sku'], $this->link($b['listing'])['sku_id']);
        // The person checked the live pages: merged.
        $r = $web->post('/ui/review/duplicates/' . $pb . '/decide', ['confirm' => '1'] + $form + ['do' => 'merge_all']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($a['sku'], $this->link($b['listing'])['sku_id']);
    }

    /**
     * M41 (review of 7 Oct 2026): a page of the group whose open suggestion belongs to ANOTHER group is shown as such and is not
     * decided here; merging the group leaves that suggestion open.
     */
    public function testAPageWithAnotherGroupsSuggestionIsDecidedThere(): void
    {
        $site = $this->vapeandgo();
        $p = ['brand' => 'Vaporesso', 'price' => '9.99', 'barcodes' => [], 'features' => ['form' => 'pod_refill', 'resistance_ohm' => 0.6, 'pack_units' => 4]];
        $a = $this->page($site, '700', 9, 3, ['product_title' => 'Vaporesso Xros Pods (Pack of 4) - 0.6 ohm'] + $p);
        $b = $this->page($site, '701', 3, 1, ['product_title' => 'Vaporesso Xros Pods (Pack of 4) - 0.6 ohm Mesh'] + $p);
        $c = $this->page($site, '702', 2, 1, ['product_title' => 'Vaporesso Xros Pods (Pack of 4) - 0.6 ohm'] + $p);
        $z = $this->page($site, '703', 1, 1, ['product_title' => 'Vaporesso Xros Pods (Pack of 4) - 0.6 ohm'] + $p);
        [$pb] = $this->group(4, [$a, $b, $c], ['700', '701', '702']);
        // C's suggestion of this group was answered by another run's suggestion (C vs Z), which is open now.
        self::$db->exec("UPDATE match_proposal SET status = 'decided' WHERE listing_id = ?", [$c['listing']]);
        $run3 = $this->proposals->run('run3-vpg-duplicates', 'vpg_duplicates');
        $other = $this->proposals->add(Caller::system('mint_vpg'), $c['listing'], $run3, ['proposed_sku_id' => $z['sku'], 'band' => 'Manual', 'lane' => 'vpg_duplicate',
            'evidence' => ['group' => 1, 'kind' => 'identity_key', 'keeper' => ['vpg_variant_id' => '703'], 'members' => [['vpg_variant_id' => '703'], ['vpg_variant_id' => '702']]]],
            false)['proposal_id'];
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $g = $web->get('/ui/review/duplicates/' . $pb);
        self::assertSame(200, $g->status, $g->describe());
        self::assertStringContainsString('Has an open suggestion in another group or queue: decide it there first', $g->text());
        $form = $g->form('/ui/review/duplicates/' . $pb . '/decide');
        self::assertArrayNotHasKey('p_' . $c['listing'], $form);
        self::assertArrayNotHasKey('v_' . $c['listing'], $form);
        $r = $web->post('/ui/review/duplicates/' . $pb . '/decide', ['confirm' => '1', 'do' => 'merge_all'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([$a['sku'], $c['sku']], [$this->link($b['listing'])['sku_id'], $this->link($c['listing'])['sku_id']]);
        self::assertSame('open', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$other]), 'decided in its own group, not here');
        // A form naming another group's suggestion is refused, nothing saved.
        $r = $web->post('/ui/review/duplicates/' . $pb . '/decide', ['p_' . $c['listing'] => (string) $other, 'do' => 'merge_all', 'confirm' => '1', 'form_key' => str_repeat('a', 32)] + $form);
        self::assertSame(409, $r->status, $r->describe());
        self::assertSame('open', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$other]));
    }

    /**
     * M41: a group whose suggested keeper has nothing left to decide against while a suggestion is still open (B said "different"
     * from A, A said "different" from the run keeper K; B's suggestion asks about K) opens with K as the keeper.
     */
    public function testAGroupWithNothingLeftAgainstTheSuggestedKeeperOffersTheOpenSuggestionsItem(): void
    {
        $site = $this->vapeandgo();
        $p = ['brand' => 'Elfliq', 'price' => '3.99', 'barcodes' => []];
        $k = $this->page($site, '800', 2, 1, ['product_title' => 'Elfliq Cola Nic Salt 10ml 10mg'] + $p);
        $a = $this->page($site, '801', 9, 5, ['product_title' => 'Elfliq Cola Nic Salt 10ml 10mg'] + $p);
        $b = $this->page($site, '802', 3, 2, ['product_title' => 'Elfliq Cola Nic Salt 10ml 10mg'] + $p);
        [$pa, $pb] = $this->group(5, [$k, $a, $b], ['800', '801', '802']);
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $form = $web->get('/ui/review/duplicates/' . $pa)->form('/ui/review/duplicates/' . $pa . '/decide');
        self::assertSame((string) $a['listing'], $form['keeper'], 'A sells most');
        $r = $web->post('/ui/review/duplicates/' . $pa . '/decide', ['c_' . $k['listing'] => 'separate', 'c_' . $b['listing'] => 'separate', 'do' => 'save'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['decided', 'open'], [self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pa]),
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pb])], 'B vs K is not answered yet');
        $g = $web->get('/ui/review/duplicates/' . $pa);
        $form = $g->form('/ui/review/duplicates/' . $pa . '/decide');
        self::assertSame((string) $k['listing'], $form['keeper'], 'the item the open suggestion proposes');
        self::assertArrayHasKey('v_' . $b['listing'], $form);
        $r = $web->post('/ui/review/duplicates/' . $pa . '/decide', $form + ['do' => 'separate_all']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pb]));
        self::assertSame(0, (new \CW\Ui\Duplicates(self::$db))->openCount());
    }

    /**
     * M44 (review of 7 Oct 2026): a group decided with the screen's suggested keeper (not the run's: the merge is booked on the run
     * keeper's listing, which has no suggestion) is in "Decided recently", and the item and listing pages link to the group page
     * with its undo.
     */
    public function testADecidedGroupIsFoundFromTheListTheItemAndTheListing(): void
    {
        $site = $this->vapeandgo();
        $x = $this->corex($site);
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $form = $web->get('/ui/review/duplicates/' . $x['pb'])->form('/ui/review/duplicates/' . $x['pb'] . '/decide');
        self::assertSame((string) $x['b']['listing'], $form['keeper'], 'the suggested keeper, not the run keeper A');
        $r = $web->post('/ui/review/duplicates/' . $x['pb'] . '/decide', $form + ['do' => 'merge_all', 'confirm' => '1']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame($x['b']['sku'], $this->link($x['a']['listing'])['sku_id']);
        self::assertSame((int) $x['a']['listing'], (int) self::$db->value("SELECT listing_id FROM match_decision WHERE action = 'merge_skus'"), 'booked on the run keeper');

        $list = $web->get('/ui/review/duplicates');
        self::assertStringContainsString('Decided recently', $list->text());
        self::assertStringContainsString('1 decided group', $list->text());
        self::assertContains('/ui/review/duplicates/' . $x['pb'], $list->hrefs());
        foreach (['/ui/items/' . $x['b']['sku'], '/ui/items/' . $x['a']['sku'], '/ui/review/listing/' . $x['a']['listing'], '/ui/review/listing/' . $x['b']['listing']] as $page) {
            $pg = $web->get($page);
            self::assertSame(200, $pg->status, $page . ': ' . $pg->describe());
            self::assertContains('/ui/review/duplicates/' . $x['pb'], $pg->hrefs(), $page);
        }
        $g = $web->get('/ui/review/duplicates/' . $x['pb']);
        self::assertStringContainsString('Undo a wrong merge', $g->text());
        $undo = $g->form('/ui/review/duplicates/' . $x['pb'] . '/split');
        self::assertSame([(string) $x['a']['listing'], 'former'], [$undo['listing'], $undo['to']]);
        self::assertStringContainsString('back to ' . sprintf('CW-%06d', $x['a']['sku']) . ', with 30 units of stock', $g->text());
    }

    /** @return list<string> */
    private function mergeLedger(): array
    {
        return array_map('strval', self::$db->column("SELECT movement_type FROM stock_ledger WHERE movement_type IN ('merge_out', 'merge_in', 'split_out', 'split_in') ORDER BY id"));
    }
}
