<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\ListingIngestService;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * The second person approves what the screens show them (plan §7.1 two-person rule, M6, U11): the
 * identity card a pending new_item will mint, and the item a pending link really links to, on both
 * approval screens (the second-approval list and the listing page); and a decision that went stale
 * (its proposal was superseded, the site renamed the listing) says so there and cannot be approved.
 * Driven in-process through the real /ui kernel as cw_app (runs in every slot).
 */
final class ApprovalScreensTest extends KernelUiTestCase
{
    /** Regression: the lead used to approve a new_item seeing only "new_item, 2 per item" (review finding, security lens). */
    public function testTheApproverOfANewItemSeesTheCardThatWillBeMinted(): void
    {
        $site = $this->site('vpg');
        $l = $this->queued($site, 'NEW1', 'New item', null, ['product_title' => 'Acme Mango Ice 10ml', 'brand' => 'Acme', 'units_30d' => 12],
            ['proposed_new_item' => true]);
        $mapper = $this->uiUser('mapper');
        $web = $this->signIn($mapper);
        $form = $this->decideForm($web, $l, ['queue' => 'New item']);
        $typed = 'Zeta Grape 50mg Shortfill';
        $r = $web->post('/ui/review/listing/' . $l . '/decide', array_replace($form, [
            'action' => 'new_item', 'units_per_item' => '2', 'card_name' => $typed, 'card_brand' => 'Zeta', 'card_strength_mg' => '50',
        ]));
        self::assertSame(303, $r->status, self::statusOf($r));
        $did = (int) self::$db->value("SELECT id FROM match_decision WHERE listing_id = ? AND state = 'pending_second'", [$l]);
        self::assertGreaterThan(0, $did);

        $lead = $this->signIn($this->uiUser('mapping_lead'));
        $list = $lead->get('/ui/review', ['queue' => 'pending']);
        self::assertTrue($list->hasForm("/ui/review/decision/{$did}/approve"));
        foreach ([$typed, 'Brand: Zeta', 'Strength (mg): 50', Words::saleUses(2)] as $shown) {
            self::assertStringContainsString($shown, $list->text(), 'second-approval list');
        }
        $page = $lead->get('/ui/review/listing/' . $l);
        self::assertTrue($page->hasForm("/ui/review/decision/{$did}/approve"));
        $xp = new \DOMXPath($page->dom());
        $cardBox = self::squash((string) $xp->evaluate('string(//section[@aria-labelledby="item-h"])'));
        self::assertStringContainsString(Words::LISTING['new_by_decision'], $cardBox);
        self::assertStringContainsString("Name {$typed}", $cardBox);
        self::assertStringContainsString('Brand Zeta', $cardBox);
        self::assertStringContainsString('Strength (mg) 50', $cardBox);
        $waiting = self::squash((string) $xp->evaluate('string(//section[@aria-labelledby="waiting-h"])'));
        self::assertStringContainsString($typed, $waiting, 'the waiting box names the card too');

        // The approval mints exactly that card.
        $ok = $lead->post("/ui/review/decision/{$did}/approve", ['csrf' => $this->token($lead)]);
        self::assertSame(303, $ok->status, self::statusOf($ok));
        $sku = self::$db->one('SELECT s.name, s.brand, s.strength_mg FROM channel_listing cl JOIN sku s ON s.id = cl.sku_id WHERE cl.id = ?', [$l]);
        self::assertSame([$typed, 'Zeta', '50.00'], [$sku['name'] ?? null, $sku['brand'] ?? null, $sku['strength_mg'] ?? null]);
    }

    /** Regression: the listing page compared the listing with the PROPOSAL's item, not the one being approved. */
    public function testTheApprovalPageComparesTheListingWithTheItemBeingApproved(): void
    {
        $site = $this->site('vpg');
        $proposed = $this->item('legacy', 0, 'Elux Legend Blue Razz 20mg');
        $other = $this->item('legacy', 0, 'Hayati Pro Max Cherry Ice');
        $this->barcode($proposed, '5056168800011');
        $this->barcode($other, '5056168899999');
        $l = $this->queued($site, 'BR20', 'Key', $proposed, ['product_title' => 'Elux Legend Blue Razz 20mg', 'barcodes' => ['5056168800011'], 'units_30d' => 30]);
        $mapper = $this->signIn($this->uiUser('mapper'));
        $form = $this->decideForm($mapper, $l, ['queue' => 'Key', 'pick' => $other]);
        self::assertSame((string) $other, $form['sku_id']);
        $r = $mapper->post('/ui/review/listing/' . $l . '/decide', array_replace($form, ['action' => 'link', 'units_per_item' => '2']));
        self::assertSame(303, $r->status, self::statusOf($r));
        $d = self::$db->one("SELECT id, sku_id FROM match_decision WHERE listing_id = ? AND state = 'pending_second'", [$l]);
        self::assertNotNull($d);
        self::assertSame($other, (int) $d['sku_id']);

        $lead = $this->signIn($this->uiUser('mapping_lead'));
        foreach ([[], ['pick' => $proposed]] as $query) { // a ?pick cannot swap the item under the approver either
            $page = $lead->get('/ui/review/listing/' . $l, $query);
            self::assertTrue($page->hasForm("/ui/review/decision/{$d['id']}/approve"));
            $xp = new \DOMXPath($page->dom());
            $compareHead = trim((string) $xp->evaluate('string(//table[contains(@class,"compare")]/thead/tr/th[3])'));
            $card = self::squash((string) $xp->evaluate('string(//section[@aria-labelledby="item-h"])'));
            self::assertSame($this->skuCode($other), $compareHead, 'the comparison is with the item being approved: ' . json_encode($query));
            self::assertStringContainsString(Words::LISTING['pending_target'] . ' ' . $this->skuCode($other), $card);
            self::assertStringContainsString('5056168899999', $card, "the approved item's barcode");
            self::assertStringNotContainsString($this->skuCode($proposed), $card);
            $barcodeRow = self::squash((string) $xp->evaluate('string(//table[contains(@class,"compare")]//tr[th="Barcodes"])'));
            self::assertStringContainsString(Words::FIELD_STATE['differs'], $barcodeRow, 'the listing barcode is not the approved item\'s');
        }
    }

    /** A decision whose proposal was superseded, or whose listing the site renamed, is flagged on both screens and refused. */
    public function testAStaleDecisionIsFlaggedAndCannotBeApproved(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend 10ml');
        $ingest = new ListingIngestService(self::$db);
        $row = ['variant_id' => 'S10', 'product_title' => 'Elux Legend 10ml e-liquid', 'variant_title' => 'Blue Razz - 10 x 10ml', 'brand' => 'Elux'];
        $ingest->ingest(Caller::system('test'), (int) $site->channelId, [$row]);
        $l = (int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = 'S10'");
        $pid = $this->propose($l, 'Check', $a);
        $mapper = $this->signIn($this->uiUser('mapper'));
        $form = $this->decideForm($mapper, $l, ['queue' => 'Check']);
        self::assertSame((string) $pid, $form['proposal_id']);
        $r = $mapper->post('/ui/review/listing/' . $l . '/decide', array_replace($form, ['action' => 'link', 'units_per_item' => '10']));
        self::assertSame(303, $r->status, self::statusOf($r));
        $did = (int) self::$db->value("SELECT id FROM match_decision WHERE listing_id = ? AND state = 'pending_second'", [$l]);

        $lead = $this->signIn($this->uiUser('mapping_lead'));
        self::assertStringNotContainsString(Words::LISTING['stale_fix'], $lead->get('/ui/review', ['queue' => 'pending'])->text());

        // The site renames the variant (identity change): the listing moves on, the decision is stale.
        $ingest->ingest(Caller::system('test'), (int) $site->channelId, [['variant_title' => 'Blue Razz - 5 x 10ml'] + $row]);
        $list = $lead->get('/ui/review', ['queue' => 'pending']);
        self::assertStringContainsString(Words::LISTING['stale_version'] . ' ' . Words::LISTING['stale_fix'], $list->text());
        // A later run supersedes the proposal: stale for that reason too.
        $this->propose($l, 'Conflict', null, ['lane' => 'barcode'], true, 'run-2');
        $page = $lead->get('/ui/review/listing/' . $l);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::LISTING['stale_proposal'], $page->text());
        self::assertStringContainsString(Words::LISTING['stale_version'], $page->text());

        $refused = $lead->post("/ui/review/decision/{$did}/approve", ['csrf' => $this->token($lead)]);
        self::assertSame(409, $refused->status, self::statusOf($refused));
        self::assertStringContainsString(Words::MATCH_ERROR['map_version_conflict_settle'], $refused->text(), 'the refusal in words, by its code (F214)');
        self::assertSame('pending_second', self::$db->value('SELECT state FROM match_decision WHERE id = ?', [$did]));
        self::assertSame(['suggested', null], [$this->link($l)['status'], $this->link($l)['sku_id']]);
    }

    private function barcode(int $sku, string $code): void
    {
        self::$db->exec('INSERT INTO sku_barcode (barcode, sku_id, source) VALUES (?, ?, ?)', [$code, $sku, 'test']);
    }

    private function skuCode(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    private static function squash(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
}
