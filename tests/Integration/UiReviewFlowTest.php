<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\UiClient;
use CW\Tests\Support\UiResponse;
use CW\Tests\Support\UiTestCase;
use CW\Ui\Words;

/**
 * The work the screens exist for (plan §7.1): sign in, see what waits, review one listing at a time
 * beside the item CW proposes, decide, land on the next listing, and see the result on the item page.
 * Every step goes over HTTP; the database is read to check what really changed (or did not).
 */
final class UiReviewFlowTest extends UiTestCase
{
    // ---- the main path -------------------------------------------------------------------------

    public function testAReviewerWorksThroughTheKeyQueueBestSellersFirst(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend 3500 Blue Razz');
        $b = $this->item('legacy', 0, 'Elux Legend 3500 Mint');
        $c = $this->item('legacy', 0, 'Elux Legend 3500 Grape');
        // Made in the wrong order on purpose: the queue goes by sales, not by id.
        $l10 = $this->queued($site, 'V10', 'Key', $c, ['product_title' => 'Elux Legend Grape', 'units_30d' => 10, 'units_365d' => 90]);
        $l100 = $this->queued($site, 'V100', 'Key', $a, ['product_title' => 'Elux Legend Blue Razz', 'units_30d' => 100, 'units_365d' => 500]);
        $l50 = $this->queued($site, 'V50', 'Key', $b, ['product_title' => 'Elux Legend Mint', 'units_30d' => 50, 'units_365d' => 300]);
        $check = $this->queued($site, 'VC', 'Check', $a, ['product_title' => 'Elux Legend Blue Razz 2 pack', 'units_30d' => 999]);
        $base = $this->decisions();

        $user = $this->uiUser('mapper');
        $web = $this->signIn($user);

        // Home's matching progress counts what waits, and links into the list of the site.
        $dash = $web->get('/ui/');
        self::assertSame(['3', '3'], self::row($dash, Words::HOME['to_match'], Words::BAND['Key']));
        self::assertSame(['1', '1'], self::row($dash, Words::HOME['to_match'], Words::BAND['Check']));
        self::assertContains('/ui/review?queue=Key&channel=vpg', $dash->hrefs());

        // Best sellers first, and the Check listing (999 units) is not in the Key queue.
        $queue = $web->get('/ui/review', ['queue' => 'Key', 'channel' => 'vpg']);
        self::assertSame(200, $queue->status);
        self::assertSame([$l100, $l50, $l10], self::listed($queue));
        self::assertStringContainsString(Words::say('QUEUE', 'total_many', 3), $queue->text());
        self::assertSame(Words::BAND_TITLE['Key'], trim((string) (new \DOMXPath($queue->dom()))->evaluate('string(//main//h1)')), 'the list by its plain name (F154)');
        self::assertContains("/ui/review/listing/{$l100}?queue=Key&channel=vpg", $queue->hrefs(), 'the row link carries the queue');

        $expect = [[$l100, $a], [$l50, $b], [$l10, $c]];
        $seen = [];
        foreach ($expect as $i => [$listing, $sku]) {
            $page = $web->get("/ui/review/listing/{$listing}", ['queue' => 'Key', 'channel' => 'vpg']);
            self::assertSame(200, $page->status, $page->describe());
            self::assertStringNotContainsString("Listing #{$listing}", $page->text(), 'the product by its name, not a number (F171)');
            self::assertSame((string) self::$db->value('SELECT product_title FROM listing_profile WHERE listing_id = ?', [$listing]),
                trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//main//h1)')));
            self::assertStringContainsString(Words::LISTING['suggested'], $page->text());
            self::assertStringContainsString(Words::say('LISTING', 'yes', $this->skuCode($sku)), $page->text());
            self::assertStringContainsString($i < 2 ? Words::LISTING['quick'] : Words::LISTING['quick_last'], $page->text(), 'the last one has no next one to open');
            $form = $page->form("/ui/review/listing/{$listing}/decide");
            self::assertSame('link', $form['action'], 'a Key proposal is preselected');
            self::assertSame((string) $sku, $form['sku_id']);
            self::assertSame('1', $form['units_per_item']);
            self::assertSame('Key', $form['queue']);
            self::assertSame('vpg', $form['channel']);
            self::assertSame((string) $this->version($listing), $form['expected_map_version']);
            if ($i < 2) {
                $nextId = $expect[$i + 1][0];
                self::assertContains("/ui/review/listing/{$nextId}?queue=Key&channel=vpg", $page->hrefs(), 'a way to skip to the next one');
            }

            $r = $web->post("/ui/review/listing/{$listing}/decide", $form);
            self::assertSame(303, $r->status, $r->describe());
            $to = self::where($r);
            if ($i < 2) {
                self::assertSame('/ui/review/listing/' . $expect[$i + 1][0], $to['path'], 'after an action, the next listing');
                self::assertSame(['queue' => 'Key', 'channel' => 'vpg', 'notice' => 'decided_link', 'prev' => (string) $listing], $to['query']);
                $next = $web->follow($r);
                self::assertSame(200, $next->status);
                $name = (string) self::$db->value('SELECT product_title FROM listing_profile WHERE listing_id = ?', [$listing]);
                self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_link', '"' . $name . '"', $this->skuCode($sku)) . ' ' . Words::MATCH_NOTICE['next'],
                    $next->text(), 'the notice names what was done, on the next one (F201)');
            } else {
                self::assertSame('/ui/review', $to['path']);
                self::assertSame(['queue' => 'Key', 'channel' => 'vpg', 'notice' => 'queue_done'], $to['query']);
                $done = $web->follow($r);
                self::assertStringContainsString(Words::MATCH_NOTICE['queue_done'], $done->text());
                self::assertStringContainsString(Words::QUEUE['empty'], $done->text());
                self::assertContains('/ui/review?queue=Check&channel=vpg', $done->hrefs(),
                    'an empty list leads to the next list with work (F161), of the store chosen (U106)');
            }
            $link = $this->link($listing);
            self::assertSame(['mapped', $sku, 1], [$link['status'], $link['sku_id'], $link['units_per_item']], "listing {$listing}");
            $seen[] = $listing;
        }
        self::assertSame($base + 3, $this->decisions());
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE state = 'applied' AND action = 'link' AND decided_by = ?", [$user['id']]));
        self::assertSame(3, self::auditCount('mapping.link', $user['id']));
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM match_proposal WHERE status <> 'open'"), 'the three proposals are settled');
        self::assertSame('suggested', $this->link($check)['status'], 'the Check listing was not touched');

        // The item page shows the link, now and as history.
        $item = $web->get('/ui/items/' . $a);
        self::assertSame(200, $item->status);
        self::assertStringContainsString(Words::ITEM['now_here'], $item->text());
        self::assertStringContainsString(Words::ITEM['h_this'] . ', ' . Words::ACTION_DONE['link'] . ' by Mapper 1 (' . Words::saleUses(1) . ')', $item->text());
        self::assertContains('/ui/review/listing/' . $l100, $item->hrefs());
        self::assertNotContains('/ui/review/listing/' . $check, $item->hrefs(), 'only linked listings are on the item');

        // Home has moved on.
        $after = $web->get('/ui/');
        self::assertSame(['0', '0'], self::row($after, Words::HOME['to_match'], Words::BAND['Key']));
        $cover = self::row($after, Words::HOME['coverage'], 'VPG test site');
        self::assertSame('4', $cover[0], 'four listings');
        self::assertSame('3', $cover[1], 'three of them linked');
    }

    public function testAPossibleMatchIsNotPreselectedAndNeedsAChoice(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $l = $this->queued($site, 'V1', 'Check', $a, ['product_title' => 'Elux Legend Blue Razz', 'units_30d' => 4]);
        $web = $this->signIn($this->uiUser('mapper'));
        $base = $this->decisions();

        $form = $this->decideForm($web, $l, ['queue' => 'Check']);
        self::assertArrayNotHasKey('action', $form, 'only Key proposals are preselected');
        self::assertSame((string) $a, $form['sku_id']);

        // Nothing chosen: refused on the same page, the form is kept.
        $r = $this->submit($web, $l, $form, ['reason' => 'because']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['choose'], $r->text());
        self::assertContains('#f-action', $r->hrefs(), 'the message leads to the field (F213)');
        self::assertSame('because', $r->form('/decide')['reason'], 'what was typed is kept');
        self::assertSame('1', $r->form('/decide')['units_per_item']);
        self::assertSame($base, $this->decisions());

        // Each field is checked before anything is written.
        foreach ([
            [['action' => 'hack'], Words::MATCH_ERROR['choose'], 422],
            [['action' => 'link', 'units_per_item' => 'abc'], Words::say('MATCH_ERROR', 'units', 1000), 422],
            [['action' => 'link', 'units_per_item' => '0'], Words::say('MATCH_ERROR', 'units', 1000), 422],
            [['action' => 'link', 'units_per_item' => '1001'], Words::say('MATCH_ERROR', 'units', 1000), 422],
            [['action' => 'link', 'units_per_item' => '-1'], Words::say('MATCH_ERROR', 'units', 1000), 422],
            [['action' => 'link', 'sku_id' => ''], Words::MATCH_ERROR['pick_first'], 422],
            [['action' => 'link', 'sku_id' => 'abc'], Words::MATCH_ERROR['pick_first'], 422],
            [['action' => 'link', 'sku_id' => '999999'], Words::MATCH_ERROR['unknown_sku'], 404],
            [['action' => 'ignore'], Words::MATCH_ERROR['ignore_why'], 422],
            [['action' => 'link', 'reason' => str_repeat('x', 501)], Words::say('MATCH_ERROR', 'reason_long', 500), 422],
            [['action' => 'link', 'expected_map_version' => 'x'], Words::ERROR['bad_form'], 400],
            [['action' => 'link', 'proposal_id' => '999999'], Words::MATCH_ERROR['proposal_mismatch'], 422],
        ] as [$over, $message, $status]) {
            $r = $this->submit($web, $l, $form, $over);
            self::assertSame($status, $r->status, json_encode($over) . ' ' . $r->describe());
            self::assertStringContainsString($message, $r->text(), json_encode($over));
            self::assertTrue($r->hasForm('/decide'), 'the form is shown again: ' . json_encode($over));
        }
        self::assertSame($base, $this->decisions(), 'no refusal wrote a decision');
        self::assertSame('suggested', $this->link($l)['status']);

        $r = $this->submit($web, $l, $form, ['action' => 'link']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['mapped', $a], [$this->link($l)['status'], $this->link($l)['sku_id']]);
    }

    public function testAStaleFormIsRefusedAndKeptThenWorksAfterAReload(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $l = $this->queued($site, 'V1', 'Key', $a, ['units_30d' => 4]);
        $web = $this->signIn($this->uiUser('mapper'));

        $form = $this->decideForm($web, $l, ['queue' => 'Key']);
        $base = $this->decisions();
        // Someone else changed the listing after this page was drawn.
        self::$db->exec('UPDATE channel_listing SET map_version = map_version + 1 WHERE id = ?', [$l]);

        $r = $this->submit($web, $l, $form);
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['map_version_conflict'], $r->text(), 'the refusal in words, by its code (F186)');
        self::assertStringNotContainsString('reload it', $r->text());
        self::assertTrue($r->hasForm('/decide'), 'the form is kept');
        self::assertSame($base, $this->decisions());
        self::assertSame('suggested', $this->link($l)['status']);

        // The page that answered already carries the new version: submitting it works.
        $again = $r->form('/decide');
        self::assertSame((string) $this->version($l), $again['expected_map_version']);
        $ok = $this->submit($web, $l, $again + ['action' => 'link']);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame('mapped', $this->link($l)['status']);

        // A closed proposal (the listing was linked meanwhile) is refused too, not applied twice.
        $late = $this->submit($web, $l, $form + ['action' => 'link']);
        self::assertContains($late->status, [409, 422], $late->describe());
        self::assertSame($base + 1, $this->decisions());
    }

    public function testIgnoringNeedsAReasonAndShowsInTheCoverage(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $gift = $this->queued($site, 'GC', 'Check', $a, ['product_title' => 'Gift card 25', 'units_30d' => 20, 'units_365d' => 200]);
        $this->profiled($site, 'V2', ['product_title' => 'Linked one', 'units_30d' => 60, 'units_365d' => 600], $a);
        $rest = $this->queued($site, 'V3', 'Check', $a, ['product_title' => 'Nothing sold yet']);
        $web = $this->signIn($this->uiUser('mapper'));

        $form = $this->decideForm($web, $gift, ['queue' => 'Check']);
        $r = $this->submit($web, $gift, $form, ['action' => 'ignore']);
        self::assertSame(422, $r->status);
        self::assertStringContainsString(Words::MATCH_ERROR['ignore_why'], $r->text());
        self::assertSame('suggested', $this->link($gift)['status']);

        $r = $this->submit($web, $gift, $form, ['action' => 'ignore', 'reason' => '  Not a product: gift card  ']);
        self::assertSame(303, $r->status, $r->describe());
        $to = self::where($r);
        self::assertSame('/ui/review/listing/' . $rest, $to['path']);
        self::assertSame(['queue' => 'Check', 'notice' => 'decided_ignore', 'prev' => (string) $gift], $to['query']);
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_ignore', '"Gift card 25"') . ' ' . Words::MATCH_NOTICE['next'], $web->follow($r)->text());
        $link = $this->link($gift);
        self::assertSame(['ignored', null], [$link['status'], $link['sku_id']]);
        self::assertSame('Not a product: gift card', self::$db->value("SELECT reason FROM match_decision WHERE listing_id = ? AND action = 'ignore'", [$gift]));
        self::assertSame(1, self::auditCount('mapping.ignore'));

        $page = $web->get('/ui/review/listing/' . $gift);
        self::assertStringContainsString(Words::LISTING_STATUS['ignored'], $page->text());
        self::assertStringContainsString(Words::say('LISTING', 'h_note', 'Not a product: gift card'), $page->text(), 'the history shows why');

        // Ignored units stay in the total (the coverage is honest) and are shown on their own.
        $cover = self::row($web->get('/ui/'), Words::HOME['coverage'], 'VPG test site');
        self::assertSame(['3', '1', '80', '75.0%', '800', '75.0%', '20'], $cover);
    }

    public function testANewItemIsMintedFromTheCardAndLinked(): void
    {
        $site = $this->site('vpg');
        $l = $this->queued($site, 'V1', 'New item', null, ['product_title' => 'Vaporesso XROS 3 Kit', 'brand' => 'Vaporesso', 'units_30d' => 5], ['proposed_new_item' => true]);
        $rest = $this->queued($site, 'V2', 'New item', null, ['product_title' => 'Another new thing'], ['proposed_new_item' => true]);
        $web = $this->signIn($this->uiUser('mapper'));
        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');

        $page = $web->get('/ui/review/listing/' . $l, ['queue' => 'New item']);
        self::assertStringContainsString(Words::LISTING['suggest_new'], $page->text());
        $form = $page->form('/decide');
        self::assertSame('new_item', $form['action'] ?? null, 'a New item proposal whose barcode is on no other listing or item is preselected');
        self::assertSame('Vaporesso XROS 3 Kit', $form['card_name'], 'the card starts from the listing');
        self::assertSame('Vaporesso', $form['card_brand']);

        // A value that is not valid is refused with the field named, and nothing is minted.
        $r = $this->submit($web, $l, $form, ['action' => 'new_item', 'card_strength_mg' => 'abc']);
        self::assertSame(400, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('MATCH_ERROR', 'bad_card_number', Words::FIELD['strength_mg'], '20'), $r->text(), 'the field by its name (F214)');
        self::assertStringNotContainsString('strength_mg', $r->text());
        self::assertSame('abc', $r->form('/decide')['card_strength_mg']);
        self::assertTrue((new \DOMXPath($r->dom()))->query('//details[@id="new-product"]')->item(0)?->hasAttribute('open'), 'the details open at the field');
        $r = $this->submit($web, $l, $form, ['action' => 'new_item', 'card_name' => '']);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['name_required'], $r->text());
        self::assertSame($skus, (int) self::$db->value('SELECT COUNT(*) FROM sku'));
        self::assertSame('suggested', $this->link($l)['status']);

        $r = $this->submit($web, $l, $form, ['action' => 'new_item', 'card_name' => 'Vaporesso XROS 3 Kit (black)', 'card_strength_mg' => '20', 'card_flavour' => 'Black']);
        self::assertSame(303, $r->status, $r->describe());
        $to = self::where($r);
        self::assertSame('/ui/review/listing/' . $rest, $to['path']);
        self::assertSame('decided_new_item', $to['query']['notice']);
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_new_item', '"Vaporesso XROS 3 Kit"'), $web->follow($r)->text());
        self::assertSame($skus + 1, (int) self::$db->value('SELECT COUNT(*) FROM sku'));

        $sku = self::$db->one('SELECT * FROM sku WHERE origin_listing_id = ?', [$l]);
        self::assertNotNull($sku);
        self::assertSame('Vaporesso XROS 3 Kit (black)', $sku['name']);
        self::assertSame('Vaporesso', $sku['brand'], 'a field left alone comes from the listing');
        self::assertSame('20', rtrim(rtrim((string) $sku['strength_mg'], '0'), '.'));
        self::assertSame('Black', $sku['flavour']);
        self::assertSame(['new_item', 'legacy', sprintf('CW-%06d', $sku['id'])], [$sku['origin'], $sku['sell_policy'], $sku['code']]);
        $link = $this->link($l);
        self::assertSame(['mapped', (int) $sku['id']], [$link['status'], $link['sku_id']]);
        self::assertSame(1, self::auditCount('mapping.new_item'));

        $item = $web->get('/ui/items/' . $sku['id']);
        self::assertSame(200, $item->status);
        self::assertStringContainsString('Vaporesso XROS 3 Kit (black)', $item->text());
        self::assertStringContainsString(Words::say('ITEM', 'made_from_line', 'VPG test site', 'Vaporesso XROS 3 Kit', 'V1'), $item->text(), 'made from, by name (F249)');
        self::assertContains('/ui/review/listing/' . $l, $item->hrefs());
        self::assertStringContainsString(Words::ITEM['now_here'], $item->text());
    }

    // ---- reject and the second person ---------------------------------------------------------

    public function testARejectedProposalStaysInTheQueueAndAnyLaterLinkNeedsTwoPeople(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $one = $this->queued($site, 'V1', 'Check', $a, ['units_30d' => 20]);
        $two = $this->queued($site, 'V2', 'Check', $a, ['units_30d' => 10]);
        $only = $this->queued($site, 'V3', 'Manual', $a, ['units_30d' => 1]);
        $web = $this->signIn($this->uiUser('mapper'));

        // With another listing waiting, a rejection moves on to it; the rejected one stays in the queue.
        $form = $this->decideForm($web, $one, ['queue' => 'Check']);
        $r = $this->submit($web, $one, $form, ['action' => 'reject']);
        self::assertSame(303, $r->status, $r->describe());
        $to = self::where($r);
        self::assertSame('/ui/review/listing/' . $two, $to['path']);
        self::assertSame(['queue' => 'Check', 'notice' => 'decided_reject', 'prev' => (string) $one], $to['query']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject WHERE listing_id = ? AND sku_id = ?', [$one, $a]));
        self::assertSame('open', self::$db->value('SELECT status FROM match_proposal WHERE listing_id = ?', [$one]));
        self::assertSame('suggested', $this->link($one)['status']);
        self::assertSame([$one, $two], self::listed($web->get('/ui/review', ['queue' => 'Check'])), 'both still wait');

        // The only listing left in a queue: stay on it, and say that it stays.
        $form = $this->decideForm($web, $only, ['queue' => 'Manual']);
        $r = $this->submit($web, $only, $form, ['action' => 'reject']);
        self::assertSame(303, $r->status, $r->describe());
        $to = self::where($r);
        self::assertSame('/ui/review/listing/' . $only, $to['path']);
        self::assertSame('decided_reject', $to['query']['notice']);
        $page = $web->follow($r);
        $onlyName = '"' . self::$db->value('SELECT product_title FROM listing_profile WHERE listing_id = ?', [$only]) . '"';
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_reject', $onlyName), $page->text());
        self::assertStringNotContainsString(Words::MATCH_NOTICE['queue_done'], $page->text());
        self::assertStringNotContainsString(Words::MATCH_NOTICE['next'], $page->text(), 'it stays on the same one');
        self::assertStringContainsString(Words::LISTING['previously_rejected'], $page->text());

        // Linking it to the item it was rejected for waits for a lead.
        $form = $page->form('/decide');
        $r = $this->submit($web, $only, $form, ['action' => 'link']);
        self::assertSame(303, $r->status, $r->describe());
        $to = self::where($r);
        self::assertSame('/ui/review', $to['path']);
        self::assertSame(['queue' => 'Manual', 'notice' => 'pending_second', 'prev' => (string) $only], $to['query']);
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'pending_second', $onlyName), $web->follow($r)->text());
        $d = self::$db->one("SELECT state, needs_second FROM match_decision WHERE listing_id = ? AND action = 'link'", [$only]);
        self::assertNotNull($d);
        self::assertSame('pending_second', $d['state']);
        self::assertContains('previously_rejected', json_decode((string) $d['needs_second'], true));
        self::assertSame('suggested', $this->link($only)['status'], 'nothing applied yet');
    }

    public function testATwoPersonDecisionWaitsForALeadWhoIsAnotherPerson(): void
    {
        $site = $this->site('vpg');
        $legacy = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $strict = $this->item('strict', 0, 'Elux Legend Protected');
        $many = $this->queued($site, 'V3', 'Key', $legacy, ['product_title' => 'Elux Legend 3 pack', 'units_30d' => 9]);
        $prot = $this->queued($site, 'V1', 'Key', $strict, ['product_title' => 'Elux Legend Protected', 'units_30d' => 1]);
        $mapperUser = $this->uiUser('mapper');
        $mapper = $this->signIn($mapperUser);
        $lead = $this->signIn($this->uiUser('mapping_lead'));

        // Three units per item: saved, not applied.
        $form = $this->decideForm($mapper, $many, ['queue' => 'Key']);
        $r = $this->submit($mapper, $many, $form, ['units_per_item' => '3']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('pending_second', self::where($r)['query']['notice']);
        $d = self::$db->one("SELECT id, state, needs_second, decided_by FROM match_decision WHERE listing_id = ? AND action = 'link'", [$many]);
        self::assertNotNull($d);
        self::assertSame(['pending_second', $mapperUser['id']], [$d['state'], (int) $d['decided_by']]);
        self::assertSame(['units_per_item'], json_decode((string) $d['needs_second'], true));
        self::assertSame(['suggested', null, 1], [$this->link($many)['status'], $this->link($many)['sku_id'], $this->link($many)['units_per_item']]);
        $did = (int) $d['id'];

        // The listing shows the waiting decision and no way to decide again; the queue no longer lists it.
        $page = $mapper->get('/ui/review/listing/' . $many, ['queue' => 'Key']);
        self::assertStringContainsString(Words::LISTING['waiting'], $page->text());
        self::assertStringContainsString(Words::LISTING['wait_own'], $page->text(), 'the decider is told what waits and that they can cancel it (F210)');
        self::assertStringContainsString(Words::NEEDS_SECOND['units_per_item'], $page->text());
        self::assertFalse($page->hasForm('/decide'));
        self::assertFalse($page->hasForm('/approve'), 'the decider cannot approve');
        self::assertTrue($page->hasForm('/withdraw'));
        self::assertSame([$prot], self::listed($mapper->get('/ui/review', ['queue' => 'Key'])));
        $list = $mapper->get('/ui/review', ['queue' => 'pending']);
        self::assertContains('/ui/review/listing/' . $many, $list->hrefs());
        self::assertStringContainsString(Words::PENDING['wait'], $list->text(), 'the decider sees that a matching lead\'s OK is needed');
        self::assertStringContainsString(Words::NEEDS_SECOND['units_per_item'], $list->text(), 'why, in words (F147)');
        self::assertFalse($list->hasForm('/approve'));

        // A protected item needs two people as well: the page says so before anyone presses Save.
        $protPage = $mapper->get('/ui/review/listing/' . $prot, ['queue' => 'Key']);
        self::assertStringContainsString(Words::LISTING['protected_note'], $protPage->text());
        self::assertStringContainsString(Words::POLICY['strict'], $protPage->text());

        // The lead approves from the second-approval list.
        $pending = $lead->get('/ui/review', ['queue' => 'pending']);
        self::assertTrue($pending->hasForm("/ui/review/decision/{$did}/approve"));
        $approve = $lead->post("/ui/review/decision/{$did}/approve", array_replace($pending->form("/ui/review/decision/{$did}/approve"), ['reason' => 'checked the pack size']));
        self::assertSame(303, $approve->status, $approve->describe());
        $to = self::where($approve);
        self::assertSame('/ui/review', $to['path']);
        self::assertSame(['queue' => 'pending', 'notice' => 'approved', 'prev' => (string) $many], $to['query']);
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'approved', '"Elux Legend 3 pack"'), $lead->follow($approve)->text());
        $link = $this->link($many);
        self::assertSame(['mapped', $legacy, 3], [$link['status'], $link['sku_id'], $link['units_per_item']]);
        self::assertSame('applied', self::$db->value('SELECT state FROM match_decision WHERE id = ?', [$did]));
        self::assertSame(1, self::auditCount('mapping.approve'));
        self::assertStringContainsString(Words::saleUses(3), $lead->get('/ui/items/' . $legacy)->text());

        // A lead cannot approve their own decision, even by forcing the request.
        $leadUser = $this->uiUser('mapping_lead');
        $own = $this->signIn($leadUser);
        $form = $this->decideForm($own, $prot, ['queue' => 'Key']);
        self::assertSame(303, $this->submit($own, $prot, $form)->status);
        $did2 = (int) self::$db->value("SELECT id FROM match_decision WHERE listing_id = ? AND action = 'link'", [$prot]);
        self::assertSame('pending_second', self::$db->value('SELECT state FROM match_decision WHERE id = ?', [$did2]));
        $mine = $own->get('/ui/review', ['queue' => 'pending']);
        self::assertFalse($mine->hasForm('/approve'));
        self::assertStringContainsString(Words::PENDING['wait'], $mine->text());
        $forced = $own->post("/ui/review/decision/{$did2}/approve", ['csrf' => $this->token($own)]);
        self::assertSame(403, $forced->status, $forced->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['same_person'], $forced->text(), 'by its code, in words (F214)');
        self::assertSame('pending_second', self::$db->value('SELECT state FROM match_decision WHERE id = ?', [$did2]));

        // The first lead is another person: this one goes through.
        $ok = $lead->post("/ui/review/decision/{$did2}/approve", ['csrf' => $this->token($lead)]);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame(['mapped', $strict], [$this->link($prot)['status'], $this->link($prot)['sku_id']]);
        self::assertSame(2, self::auditCount('mapping.approve'));
    }

    public function testTheDeciderOrALeadCanWithdrawAWaitingDecision(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $one = $this->queued($site, 'V1', 'Key', $a, ['units_30d' => 3]);
        $two = $this->queued($site, 'V2', 'Key', $a, ['units_30d' => 2]);
        $mapper = $this->signIn($this->uiUser('mapper'));
        $other = $this->signIn($this->uiUser('mapper'));
        $lead = $this->signIn($this->uiUser('mapping_lead'));

        foreach ([$one, $two] as $l) {
            $form = $this->decideForm($mapper, $l, ['queue' => 'Key']);
            self::assertSame(303, $this->submit($mapper, $l, $form, ['units_per_item' => '2'])->status);
        }
        $d1 = (int) self::$db->value("SELECT id FROM match_decision WHERE listing_id = ? AND action = 'link'", [$one]);
        $d2 = (int) self::$db->value("SELECT id FROM match_decision WHERE listing_id = ? AND action = 'link'", [$two]);
        self::assertSame([], self::listed($mapper->get('/ui/review', ['queue' => 'Key'])));

        // Another mapper may not.
        $r = $other->post("/ui/review/decision/{$d1}/withdraw", ['csrf' => $this->token($other)]);
        self::assertSame(403, $r->status, $r->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['lead_required_withdraw'], $r->text());
        self::assertSame('pending_second', self::$db->value('SELECT state FROM match_decision WHERE id = ?', [$d1]));

        // The decider withdraws from the listing page and lands back on it, free to decide again.
        $page = $mapper->get('/ui/review/listing/' . $one);
        $r = $mapper->post("/ui/review/decision/{$d1}/withdraw", $page->form("/ui/review/decision/{$d1}/withdraw"));
        self::assertSame(303, $r->status, $r->describe());
        $to = self::where($r);
        self::assertSame('/ui/review/listing/' . $one, $to['path']);
        self::assertSame('withdrawn', $to['query']['notice']);
        $back = $mapper->follow($r);
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'withdrawn', '"Product V1"'), $back->text());
        self::assertTrue($back->hasForm('/decide'));
        self::assertSame('withdrawn', self::$db->value('SELECT state FROM match_decision WHERE id = ?', [$d1]));
        self::assertSame('suggested', $this->link($one)['status']);
        self::assertSame([$one], self::listed($mapper->get('/ui/review', ['queue' => 'Key'])), 'back in the queue');
        self::assertSame(1, self::auditCount('mapping.withdraw'));

        // A lead withdraws someone else's decision from the list.
        $pending = $lead->get('/ui/review', ['queue' => 'pending']);
        $r = $lead->post("/ui/review/decision/{$d2}/withdraw", $pending->form("/ui/review/decision/{$d2}/withdraw"));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['queue' => 'pending', 'notice' => 'withdrawn', 'prev' => (string) $two], self::where($r)['query']);
        self::assertSame('withdrawn', self::$db->value('SELECT state FROM match_decision WHERE id = ?', [$d2]));
        self::assertStringContainsString(Words::PENDING['none'], $lead->follow($r)->text());

        // Withdrawing twice is refused.
        $again = $lead->post("/ui/review/decision/{$d2}/withdraw", ['csrf' => $this->token($lead)]);
        self::assertSame(409, $again->status, $again->describe());
    }

    // ---- the dashboard, the queue, choosing an item ------------------------------------------

    public function testTheDashboardCountsQueuesAndCoverage(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        $item = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $this->queued($vpg, 'K1', 'Key', $item);
        $this->queued($vpg, 'K2', 'Key', $item);
        $this->queued($alt, 'K3', 'Key', $item);
        $this->queued($vpg, 'C1', 'Check', $item);
        $this->queued($vpg, 'X1', 'Conflict', $item);
        $this->queued($alt, 'N1', 'New item', null, [], ['proposed_new_item' => true]);
        $this->profiled($vpg, 'U1');   // unmapped, no run has looked at it
        $this->profiled($vpg, 'U2');
        $this->profiled($alt, 'U3');
        // Coverage of vpg: 60 of 160 units on linked listings, 30 on an ignored one.
        $this->profiled($vpg, 'L1', ['units_30d' => 40, 'units_365d' => 400], $item);
        $this->profiled($vpg, 'L2', ['units_30d' => 20, 'units_365d' => 200], $item);
        $this->profiled($vpg, 'L3', ['units_30d' => 70, 'units_365d' => 700]);
        $this->profiled($vpg, 'L4', ['units_30d' => 30, 'units_365d' => 300], null);
        self::$db->exec("UPDATE channel_listing SET status = 'ignored' WHERE channel_id = ? AND external_variant_id = 'L4'", [$vpg->channelId]);
        // One decision waits for a second person.
        $pendingListing = $this->queued($vpg, 'P1', 'Key', $item);
        $decider = $this->staffUser('mapper');
        $this->decide($decider, 'link', $pendingListing, ['sku_id' => $item, 'units_per_item' => 2,
            'proposal_id' => (int) self::$db->value("SELECT id FROM match_proposal WHERE listing_id = ? AND status = 'open'", [$pendingListing])]);

        $web = $this->signIn($this->uiUser('viewer'));
        $dash = $web->get('/ui/');
        self::assertSame(200, $dash->status);

        // Columns are the sites in code order (alt, vpg), by name, then the total; each list by its plain name (plan F075-F082).
        $w = Words::HOME['to_match'];
        self::assertSame(['1', '2', '3'], self::row($dash, $w, Words::BAND['Key']));
        self::assertSame(['0', '1', '1'], self::row($dash, $w, Words::BAND['Check']));
        self::assertSame(['0', '1', '1'], self::row($dash, $w, Words::BAND['Conflict']));
        self::assertSame(['1', '0', '1'], self::row($dash, $w, Words::BAND['New item']));
        self::assertSame(['0', '0', '0'], self::row($dash, $w, Words::BAND['Manual']), 'band Manual is named for what it is');
        self::assertSame(['1', '3', '4'], self::row($dash, $w, Words::HOME['not_checked']), 'U1, U2 and L3 (unlinked, never proposed) on vpg, U3 on alt');
        self::assertStringContainsString('ALT test site', $dash->text(), 'the website by its name, not its code');
        self::assertStringContainsString(Words::HOME['look_match'], $dash->text(), 'a viewer looks; matchers decide');
        self::assertContains('/ui/review?queue=Key&channel=alt', $dash->hrefs());
        self::assertContains('/ui/review?queue=Key', $dash->hrefs());
        self::assertNotContains('/ui/review?queue=Manual', $dash->hrefs(), 'an empty queue is not a link');
        self::assertContains('/ui/review?queue=pending', $web->get('/ui/review', ['queue' => 'Key'])->hrefs(), 'Second approval is a segment of Products > Mapping');
        self::assertStringContainsString(Words::HOME['pending_one'], $dash->text());

        // website products, matched, sold 30d, matched share 30d (a bar and the %), sold 1 year, matched share 1 year, sold but ignored 30d
        $c = Words::HOME['coverage'];
        self::assertSame(['11', '2', '160', '37.5%', '1,600', '37.5%', '30'], self::row($dash, $c, 'VPG test site'), '60 of 160 units in 30 days, 600 of 1600 in 365; the ignored 30 stay in the total');
        self::assertSame(['3', '0', '0', Words::HOME['no_sales'], '0', Words::HOME['no_sales'], '0'], self::row($dash, $c, 'ALT test site'), 'nothing sold: no percentage, but words');
    }

    public function testTheQueueFiltersAndPages(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        $item = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $ids = [];
        for ($i = 1; $i <= 55; $i++) {
            $ids[$i] = $this->queued($vpg, "V{$i}", 'Check', $item, ['product_title' => 'Bulk product ' . $i, 'units_30d' => 100 - $i]);
        }
        $zebra = $this->queued($vpg, 'ZEB', 'Check', $item, ['product_title' => 'Zebra Stripe Kit', 'brand' => 'Stripey', 'barcodes' => ['5012345678900'], 'units_30d' => 1], ['lane' => 'barcode']);
        $altOne = $this->queued($alt, 'A1', 'Check', $item, ['product_title' => 'Alt site product', 'units_30d' => 500], ['lane' => 'transfer']);
        $web = $this->signIn($this->uiUser('mapper'));

        // 57 listings: 50 on the first page, best sellers first (the alt one sells 500).
        $p1 = $web->get('/ui/review', ['queue' => 'Check']);
        self::assertStringContainsString(Words::say('QUEUE', 'total_many', 57), $p1->text());
        $first = self::listed($p1);
        self::assertCount(50, $first);
        self::assertSame($altOne, $first[0]);
        self::assertSame($ids[1], $first[1]);
        self::assertStringContainsString(Words::say('QUEUE', 'page', 1, 2), $p1->text());
        $next = array_values(array_filter($p1->hrefs(), static fn (string $h): bool => str_contains($h, 'page=2')));
        self::assertSame(['/ui/review?queue=Check&page=2'], $next);
        $p2 = $web->get($next[0]);
        $second = self::listed($p2);
        self::assertCount(7, $second);
        self::assertSame([], array_intersect($first, $second));
        self::assertSame($zebra, $second[6], 'the smallest seller is last');
        self::assertStringContainsString(Words::say('QUEUE', 'page', 2, 2), $p2->text());
        // A page number that does not exist is the last page; one that is not a number is the first.
        self::assertSame($second, self::listed($web->get('/ui/review', ['queue' => 'Check', 'page' => '99'])));
        self::assertSame($first, self::listed($web->get('/ui/review', ['queue' => 'Check', 'page' => 'abc'])));

        // Filters.
        self::assertSame([$altOne], self::listed($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt'])));
        self::assertSame([$zebra], self::listed($web->get('/ui/review', ['queue' => 'Check', 'lane' => 'barcode'])));
        self::assertSame([$altOne], self::listed($web->get('/ui/review', ['queue' => 'Check', 'lane' => 'transfer'])));
        self::assertSame([$altOne, $ids[1], $ids[2]], self::listed($web->get('/ui/review', ['queue' => 'Check', 'min' => '98'])));
        self::assertSame([$zebra], self::listed($web->get('/ui/review', ['queue' => 'Check', 'q' => 'zebra'])), 'by title');
        self::assertSame([$zebra], self::listed($web->get('/ui/review', ['queue' => 'Check', 'q' => 'Stripey'])), 'by brand');
        self::assertSame([$zebra], self::listed($web->get('/ui/review', ['queue' => 'Check', 'q' => '5012345678900'])), 'by barcode');
        self::assertSame([$zebra], self::listed($web->get('/ui/review', ['queue' => 'Check', 'q' => 'ZEB'])), 'by variant id');
        self::assertSame([], self::listed($web->get('/ui/review', ['queue' => 'Check', 'q' => '%'])), 'a wildcard is only a character');
        self::assertSame([$ids[5]], self::listed($web->get('/ui/review', ['queue' => 'Check', 'q' => 'product 5', 'channel' => 'vpg', 'min' => '90'])));
        // An unknown site or lane is ignored, not an error.
        self::assertCount(50, self::listed($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'nowhere', 'lane' => 'nonsense'])));

        // The context travels: the row link, the form and the next listing keep the filters.
        $filtered = $web->get('/ui/review', ['queue' => 'Check', 'channel' => 'vpg', 'min' => '98', 'q' => 'product']);
        self::assertSame([$ids[1], $ids[2]], self::listed($filtered));
        self::assertContains("/ui/review/listing/{$ids[1]}?queue=Check&channel=vpg&min=98&fq=product", $filtered->hrefs());
        $page = $web->get("/ui/review/listing/{$ids[1]}", ['queue' => 'Check', 'channel' => 'vpg', 'min' => '98', 'fq' => 'product']);
        $form = $page->form('/decide');
        self::assertSame(['Check', 'vpg', '98', 'product'], [$form['queue'], $form['channel'], $form['min'], $form['fq']]);
        $r = $this->submit($web, $ids[1], $form, ['action' => 'link']);
        self::assertSame(303, $r->status, $r->describe());
        $to = self::where($r);
        self::assertSame('/ui/review/listing/' . $ids[2], $to['path'], 'the next listing of this filtered queue');
        self::assertSame(['Check', 'vpg', '98', 'product'], [$to['query']['queue'], $to['query']['channel'], $to['query']['min'], $to['query']['fq']]);
    }

    public function testChoosingAnotherItemBySearchAndPick(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $b = $this->item('legacy', 0, 'Elux Bar Mint');
        $c = $this->item('legacy', 0, 'Elux Bar Grape');
        $d = $this->item('legacy', 0, 'Vozol Star Mint');
        $this->barcode($d, '5060123456789');
        $l = $this->queued($site, 'V1', 'Check', $a, ['product_title' => 'Elux Bar Mint 600 puffs', 'units_30d' => 3], [
            'closest_sku_id' => $c,
            'evidence' => ['candidates' => [
                ['sku_id' => $b, 'cw_id' => $this->skuCode($b), 'role' => 'alternative', 'prescore' => '0.61', 'vetoes' => [], 'soft_flags' => ['flavour']],
                ['sku_id' => $c, 'cw_id' => $this->skuCode($c), 'role' => 'alternative', 'prescore' => '0.40', 'vetoes' => ['strength'], 'soft_flags' => []],
            ]],
        ]);
        $web = $this->signIn($this->uiUser('mapper'));
        $pick = static fn (int $sku): string => "/ui/review/listing/{$l}?queue=Check&pick={$sku}#decide";

        // The page offers the closest item and each candidate.
        $page = $web->get("/ui/review/listing/{$l}", ['queue' => 'Check']);
        self::assertStringContainsString(Words::LISTING['closest'] . $this->skuCode($c) . ' Elux Bar Grape', $page->text());
        self::assertContains($pick($c), $page->hrefs(), 'use the closest item');
        self::assertContains($pick($b), $page->hrefs(), 'use a candidate');
        self::assertStringContainsString(Words::LISTING['candidates'], $page->text());

        // A search on the page lists items with a way to use each (d is offered nowhere else).
        self::assertNotContains($pick($d), $page->hrefs());
        $found = $web->get("/ui/review/listing/{$l}", ['queue' => 'Check', 's' => 'Vozol']);
        self::assertStringContainsString('Vozol Star Mint', $found->text());
        self::assertContains($pick($d), $found->hrefs());
        self::assertNotContains($pick($a), $found->hrefs(), 'the search only lists what matches');
        self::assertContains($pick($d), $web->get("/ui/review/listing/{$l}", ['queue' => 'Check', 's' => '5060123456789'])->hrefs(), 'by barcode');
        self::assertContains($pick($a), $web->get("/ui/review/listing/{$l}", ['queue' => 'Check', 's' => $this->skuCode($a)])->hrefs(), 'by code');
        self::assertStringContainsString(Words::SEARCH['no_items'], $web->get("/ui/review/listing/{$l}", ['s' => 'zzzzqq'])->text());

        // The picked item takes the place of the proposal, and is what the form links to.
        $picked = $web->get("/ui/review/listing/{$l}", ['queue' => 'Check', 'pick' => (string) $b]);
        self::assertStringContainsString(Words::LISTING['picked'], $picked->text());
        self::assertStringContainsString(Words::say('LISTING', 'yes', $this->skuCode($b)), $picked->text());
        $form = $picked->form('/decide');
        self::assertSame((string) $b, $form['sku_id']);
        self::assertArrayNotHasKey('action', $form, 'a picked item is never preselected');
        $r = $this->submit($web, $l, $form, ['action' => 'link']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['mapped', $b], [$this->link($l)['status'], $this->link($l)['sku_id']]);
        self::assertSame($b, (int) self::$db->value("SELECT sku_id FROM match_decision WHERE listing_id = ? AND action = 'link'", [$l]));
    }

    public function testAPickThatDoesNotExistFallsBackToTheProposal(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend Blue Razz');
        $l = $this->queued($site, 'V1', 'Check', $a, ['units_30d' => 3]);
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get("/ui/review/listing/{$l}", ['queue' => 'Check', 'pick' => '999999']);
        self::assertSame(200, $page->status);
        self::assertStringContainsString(Words::LISTING['pick_missing'], $page->text());
        self::assertStringContainsString(Words::LISTING['suggested'], $page->text(), 'a pick that failed does not claim to be the picked item');
        self::assertStringNotContainsString(Words::LISTING['picked'], $page->text());
        self::assertSame((string) $a, $page->form('/decide')['sku_id'], 'the proposal is still the target');
        foreach (['abc', '0', '-3', '1.5', str_repeat('9', 30)] as $junk) {
            $r = $web->get("/ui/review/listing/{$l}", ['queue' => 'Check', 'pick' => $junk]);
            self::assertSame(200, $r->status, $junk);
            self::assertSame((string) $a, $r->form('/decide')['sku_id'], $junk);
        }

        // A merged item cannot be picked.
        $gone = $this->item('legacy', 0, 'Elux Legend Old');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$a, $gone]);
        $r = $web->get("/ui/review/listing/{$l}", ['queue' => 'Check', 'pick' => (string) $gone]);
        self::assertStringContainsString(Words::LISTING['pick_merged'], $r->text());
        self::assertSame((string) $a, $r->form('/decide')['sku_id']);
        $post = $this->submit($web, $l, $r->form('/decide'), ['action' => 'link', 'sku_id' => (string) $gone]);
        self::assertSame(409, $post->status, $post->describe());
        self::assertSame('suggested', $this->link($l)['status']);
    }

    // ---- search and the item page ---------------------------------------------------------------

    public function testSearchFindsItemsAndListings(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 0, 'Elux Legend 3500 Blue Razz');
        $this->barcode($a, '5060111122223');
        $b = $this->item('strict', 0, 'Vozol Star 20000 Mint');
        $la = $this->profiled($site, 'VAR-77', ['product_title' => 'Elux Blue Razz 3500', 'barcodes' => ['5060999888777']], $a);
        $lb = $this->profiled($site, 'VAR-88', ['product_title' => 'Vozol Star Mint']);
        $web = $this->signIn($this->uiUser('viewer'));
        $codeA = $this->skuCode($a);

        $r = $web->get('/ui/search', ['q' => 'blue razz']);
        self::assertSame(200, $r->status);
        self::assertContains('/ui/items/' . $a, $r->hrefs());
        self::assertContains('/ui/review/listing/' . $la, $r->hrefs());
        self::assertNotContains('/ui/items/' . $b, $r->hrefs());
        self::assertNotContains('/ui/review/listing/' . $lb, $r->hrefs());

        foreach ([$codeA, strtolower($codeA), 'CW' . substr($codeA, 3), '5060111122223'] as $q) {
            self::assertContains('/ui/items/' . $a, $web->get('/ui/search', ['q' => $q])->hrefs(), "item by {$q}");
        }
        $listing = $web->get('/ui/search', ['q' => 'VAR-88']);
        self::assertContains('/ui/review/listing/' . $lb, $listing->hrefs(), 'a listing by its variant id');
        self::assertStringContainsString(Words::SEARCH['not_yet'], $listing->text());
        $byBarcode = $web->get('/ui/search', ['q' => '5060999888777']);
        self::assertContains('/ui/review/listing/' . $la, $byBarcode->hrefs(), 'a listing by its own barcode');
        self::assertContains('/ui/items/' . $a, $byBarcode->hrefs(), 'and the item it is linked to');
        self::assertContains('/ui/items/' . $b, $web->get('/ui/search', ['q' => 'vozol star'])->hrefs(), 'every word must match, in any order');
        self::assertContains('/ui/items/' . $b, $web->get('/ui/search', ['q' => 'mint vozol'])->hrefs());
        self::assertNotContains('/ui/items/' . $b, $web->get('/ui/search', ['q' => 'vozol razz'])->hrefs());

        self::assertStringContainsString('Type at least two characters.', $web->get('/ui/search', ['q' => 'x'])->text());
        $none = $web->get('/ui/search', ['q' => 'zzzzqqq']);
        self::assertStringContainsString(Words::SEARCH['no_items'], $none->text());
        self::assertStringContainsString(Words::SEARCH['no_listings'], $none->text());
        self::assertStringContainsString(Words::SEARCH['no_items'], $web->get('/ui/search', ['q' => '%%'])->text(), 'a wildcard is only a character');
        self::assertStringContainsString(Words::SEARCH['no_items'], $web->get('/ui/search', ['q' => '__'])->text());
        self::assertSame(200, $web->get('/ui/search')->status, 'an empty search is a blank form');
        self::assertSame(200, $web->get('/ui/search', ['q' => str_repeat('a', 5000)])->status, 'a long query is cut, not an error');
        self::assertSame(200, $web->get('/ui/search', ['q' => "a'\"\\; DROP TABLE sku; --"])->status);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'sku'"));
    }

    public function testAnItemPageShowsItsStockLedgerListingsAndLinkHistory(): void
    {
        $site = $this->site('vpg');
        $a = $this->item('legacy', 5, 'Stocked Item');
        $b = $this->item('legacy', 0, 'Other Item');
        $this->barcode($a, '5060777766665');
        $l = $this->profiled($site, 'V1', ['product_title' => 'Stocked Item listing', 'units_30d' => 7, 'units_365d' => 70]);
        $who = $this->staffUser('mapper');
        $this->decide($who, 'link', $l, ['sku_id' => $a]);
        $this->decide($who, 'unlink', $l);
        $this->decide($who, 'link', $l, ['sku_id' => $b, 'units_per_item' => 1]);
        $web = $this->signIn($this->uiUser('viewer'));

        $item = $web->get('/ui/items/' . $a);
        self::assertSame(200, $item->status, $item->describe());
        self::assertStringContainsString($this->skuCode($a), $item->text());
        self::assertStringContainsString('5060777766665', $item->text());
        $main = (string) self::$db->value("SELECT COALESCE(name, code) FROM warehouse WHERE code = 'MAIN'");
        self::assertSame(['5', '0', '0', '5'], array_slice(self::row($item, Words::ITEM['stock'], $main), 0, 4), 'in the building, sold, reserved, free to sell');
        self::assertStringContainsString(Words::MOVEMENT['goods_in'], $item->text(), 'the ledger shows the opening movement, in words (F253)');
        self::assertStringContainsString(Words::ITEM['now_elsewhere'], $item->text(), 'it moved to another item');
        self::assertMatchesRegularExpression('/\d{1,2} [A-Z][a-z]{2} 20\d\d – \d{1,2} [A-Z][a-z]{2} 20\d\d: ' . preg_quote(Words::ITEM['h_this'] . ', ' . Words::ACTION_DONE['link']
            . ' by mapper 1 (' . Words::saleUses(1) . ')', '/') . '/', $item->text(), 'the closed period of the history');
        self::assertContains('/ui/review/listing/' . $l, $item->hrefs());

        $other = $web->get('/ui/items/' . $b);
        self::assertStringContainsString(Words::ITEM['now_here'], $other->text());
        self::assertMatchesRegularExpression('/Since \d{1,2} [A-Z][a-z]{2} 20\d\d: ' . preg_quote(Words::ITEM['h_this'] . ', ' . Words::ACTION_DONE['link'], '/') . ' by mapper 1/', $other->text());

        // A merged item points to the one it went into.
        $c = $this->item('legacy', 0, 'Merged away');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$b, $c]);
        $merged = $web->get('/ui/items/' . $c);
        self::assertStringContainsString(Words::ITEM['merged_into'], $merged->text());
        self::assertContains('/ui/items/' . $b, $merged->hrefs());
    }

    // ---- helpers ------------------------------------------------------------------------------

    private function skuCode(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }

    /** @param array<string, string> $form @param array<string, string> $over */
    private function submit(UiClient $web, int $listing, array $form, array $over = []): UiResponse
    {
        return $web->post("/ui/review/listing/{$listing}/decide", array_replace($form, $over));
    }

    /** @return array{path: string, query: array<string, string>} where a redirect goes */
    private static function where(UiResponse $r): array
    {
        $loc = $r->location();
        self::assertNotNull($loc, $r->describe());
        $parts = parse_url($loc);
        self::assertIsArray($parts);
        parse_str($parts['query'] ?? '', $query);
        /** @var array<string, string> $query */
        return ['path' => $parts['path'] ?? '', 'query' => $query];
    }

    /** The listing ids a queue page links to, in the order shown. @return list<int> */
    private static function listed(UiResponse $r): array
    {
        $ids = [];
        foreach ($r->hrefs() as $href) {
            if (preg_match('#^/ui/review/listing/([0-9]+)(?:\?|$)#', $href, $m) === 1) {
                $ids[(int) $m[1]] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * The cells of the row whose row heading starts with $head, in the first table after the h2 that
     * contains $heading (text of each cell, whitespace collapsed).
     *
     * @return list<string>
     */
    private static function row(UiResponse $r, string $heading, string $head): array
    {
        $xp = new \DOMXPath($r->dom());
        $rows = $xp->query("//*[self::h2 or self::h3][contains(., '{$heading}')]/following::table[1]//tr[th[starts-with(normalize-space(.), '{$head}')]]");
        self::assertNotFalse($rows);
        self::assertGreaterThan(0, $rows->length, "no row '{$head}' under '{$heading}': " . $r->describe());
        $tr = $rows->item(0);
        self::assertInstanceOf(\DOMElement::class, $tr);
        $out = [];
        foreach ($tr->getElementsByTagName('td') as $td) {
            $out[] = trim((string) preg_replace('/\s+/u', ' ', $td->textContent));
        }
        return $out;
    }
}
