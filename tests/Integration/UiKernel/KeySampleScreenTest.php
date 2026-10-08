<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * The spot check on the screens (M28; in plain words, plan §6.9, 6.10), through the real /ui kernel as cw_app: the list and the
 * spot check's page with "n of 20 checked", the 20 blocks, each match's state and the button to the next one, a link from each
 * match to its own page that leads back, the owner confirming one ("Yes") and saying "Not a match" to another there (a second
 * step that says it stops the bulk link for good, compare.md §2.1), another lead who sees why and no answer forms (behaviour
 * item 5), and the result the bulk step will read. Read-only pages: no bulk button anywhere. A website product set aside from
 * the bulk step (M30) stays in Strong matches, its page says why (a newer suggestion of it too), and the spot check's page lists
 * it with what became of it (one a bulk step matched is flagged).
 */
final class KeySampleScreenTest extends KernelUiTestCase
{
    use KeyFixtures;

    public function testTheOwnerWorksThroughTheSampleOnTheReviewScreen(): void
    {
        $this->keySetup();
        $owner = $this->uiUser(['mapping_lead', 'reviewer']);
        for ($i = 0; $i < 22; $i++) {
            $this->firstMatch($this->vpgItem("Acme Bar {$i} 20mg"), 90 + $i % 10);
        }
        $s = (new KeySample(self::$db))->create(Caller::staff($owner['id']), 'screen-1', 20, true);
        $sid = (int) $s['sample_id'];
        $web = $this->signIn($owner);

        $list = $web->get('/ui/review/samples');
        self::assertSame(200, $list->status, $list->describe());
        self::assertStringContainsString('screen-1', $list->text());
        self::assertStringContainsString('0 of 20', $list->text());
        self::assertSame(['Products', 'Mapping'], [self::currentSection($list), self::currentTab($list)], 'Products › Mapping');
        self::assertSame([['To review', false], ['Spot check', true], ['Second approval', false]],
            array_map(static fn (array $s): array => [$s['label'], $s['current']], self::segments($list)), '... › Spot check');
        self::assertSame('/ui/review/samples', self::segments($list)[1]['href']);

        $page = $web->get('/ui/review/samples/' . $sid);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::say('SPOT', 'progress', 0, 20), $page->text());
        self::assertSame(20, substr_count($page->text(), Words::SAMPLE_STATE['open']));
        $xp = new \DOMXPath($page->dom());
        self::assertSame(20, $xp->query('//ol[@class="segments"]/li[@class="todo"]')->length, 'the 20 blocks (design B)');
        self::assertSame($xp->query('//table')->length, $xp->query('//table[contains(@class, "stack")]')->length, 'the matches as cards on a phone');
        $tech = (string) $xp->evaluate('string(//details[contains(@class, "tech-details")])');
        self::assertStringContainsString(Words::SAMPLE['seed'], $tech, 'the seed is for the audit, folded away (F112)');
        self::assertStringContainsString((string) $s['seed'], $tech);
        self::assertStringContainsString(Words::SAMPLE['seed_text'], $tech);
        foreach ($s['members'] as $m) {
            self::assertContains('/ui/review/listing/' . $m['listing_id'] . '?sample=' . $sid, $page->hrefs());
        }
        self::assertFalse($page->hasForm('/ui/review/samples'), 'the bulk confirm is never a button');
        $next = $xp->query('//section[contains(@class, "spot-box")]//a[contains(@class, "primary")]')->item(0);
        self::assertSame(Words::say('SPOT', 'next', 1, 20) . ' →', trim((string) $next?->textContent), 'the main button opens the next one (F108)');
        self::assertSame('/ui/review/listing/' . $s['members'][0]['listing_id'] . '?sample=' . $sid, $next instanceof \DOMElement ? $next->getAttribute('href') : '');
        foreach (['bin/', 'Key', 'proposal', 'population', 'drawn'] as $word) {
            self::assertStringNotContainsString($word, (string) preg_replace('/\s+/', ' ', str_replace($tech, '', (string) $xp->evaluate('string(//main)'))), $word);
        }

        // The first member: opened from the sample, confirmed with the quick form, and back.
        $first = $s['members'][0];
        $listing = $web->get('/ui/review/listing/' . $first['listing_id'], ['sample' => (string) $sid]);
        self::assertSame(200, $listing->status, $listing->describe());
        self::assertContains('/ui/review/samples/' . $sid, $listing->hrefs());
        self::assertStringContainsString(Words::say('SPOT', 'box', 'screen-1', 1, 20), $listing->text());
        self::assertStringContainsString(Words::SPOT['mine'], $listing->text());
        $lxp = new \DOMXPath($listing->dom());
        self::assertSame(1, $lxp->query('//ol[@class="segments"]/li[@class="now"]')->length, 'this one, in the 20 blocks');
        // The owner's answers (design B, compare.md §2.1): "Yes" saves at once; "Not a match" is a second step that says it stops the
        // bulk link for good before its form; "Not sure" saves nothing and opens the next one.
        self::assertStringContainsString(Words::UI['what_each_answer_does'], $listing->text());
        self::assertSame(Words::SPOT['unsure'] . ' ' . Words::UI['safer'], trim((string) preg_replace('/\s+/', ' ',
            (string) $lxp->evaluate('string(//div[contains(@class, "answer") and contains(@class, "safe")]/dt)'))), 'the safe answer is marked (design B)');
        $step = $lxp->query('//details[@id="not-a-match"]')->item(0);
        self::assertNotNull($step);
        self::assertFalse($step instanceof \DOMElement && $step->hasAttribute('open'), 'nothing of "Not a match" is sent with one tap');
        self::assertSame(Words::SPOT['no_confirm_title'], trim((string) $lxp->evaluate('string(//details[@id="not-a-match"]//p[@class="alert-title"])')));
        self::assertSame(1, $lxp->query('//details[@id="not-a-match"]//form[contains(@action, "/decide")]')->length, 'the answer form is behind the second step');
        self::assertSame(Words::SPOT['no_confirm_button'], trim((string) $lxp->evaluate('string(//details[@id="not-a-match"]//button[@type="submit"])')));
        self::assertSame(0, $lxp->query('//details[@id="not-a-match"]//input[@name="action" and @value="link"]')->length, 'Yes is its own answer');
        self::assertSame('/ui/review/listing/' . $s['members'][1]['listing_id'] . '?sample=' . $sid,
            (string) $lxp->evaluate('string(//div[contains(@class, "spot-answers")]/a/@href)'), '"Not sure" opens the next one and saves nothing');
        // The answers come straight after the two product cards (plan F203), before "Why the computer suggests this", and the spot
        // box leads to them.
        $order = array_map(static fn (\DOMElement $el): string => $el->getAttribute('id'), iterator_to_array($lxp->query('//section[@id="decide" or contains(@class, "why")]')));
        self::assertSame('decide', $order[0] ?? null, 'the answers before the evidence');
        self::assertContains('#decide', $listing->hrefs());
        $yesForm = $lxp->query('//div[contains(@class, "spot-answers")]/form[contains(@class, "quick")]');
        self::assertSame(1, $yesForm->length, 'Yes is its own button');
        $proposed = (int) self::$db->value('SELECT proposed_sku_id FROM match_proposal WHERE id = ?', [$first['proposal_id']]);

        // R1 review (7 Oct): a refused "Not a match" (Ignore needs a note) opens the second step again and keeps the quick yes; nothing
        // in the second step can match the suggested product under the button that says it stops the bulk link.
        $quick = $listing->form('/ui/review/listing/' . $first['listing_id'] . '/decide');
        $refused = $web->post('/ui/review/listing/' . $first['listing_id'] . '/decide', ['action' => 'ignore', 'reason' => ''] + $quick);
        self::assertSame(422, $refused->status, $refused->describe());
        $rxp = new \DOMXPath($refused->dom());
        self::assertSame(1, $rxp->query('//div[contains(@class, "spot-answers")]/form[contains(@class, "quick")]')->length, 'the quick yes stays after a refusal');
        self::assertSame((string) $proposed, (string) $rxp->evaluate('string(//form[contains(@class, "quick")]/input[@name="sku_id"]/@value)'), 'yes = the suggestion');
        self::assertSame('1', (string) $rxp->evaluate('string(//form[contains(@class, "quick")]/input[@name="units_per_item"]/@value)'));
        $step = $rxp->query('//details[@id="not-a-match"]')->item(0);
        self::assertTrue($step instanceof \DOMElement && $step->hasAttribute('open'), 'the refused answer is shown where it was given');
        self::assertSame(0, $rxp->query('//details[@id="not-a-match"]//input[@name="action" and @value="link"]')->length, 'nothing link-like in the second step');
        // ?pick= the suggested product: the same.
        $picked = $web->get('/ui/review/listing/' . $first['listing_id'], ['sample' => (string) $sid, 'pick' => (string) $proposed]);
        $pxp = new \DOMXPath($picked->dom());
        self::assertSame(1, $pxp->query('//div[contains(@class, "spot-answers")]/form[contains(@class, "quick")]')->length);
        self::assertSame(0, $pxp->query('//details[@id="not-a-match"]//input[@name="action" and @value="link"]')->length);
        // ?pick= another product: matching it is an answer of the second step (it fails the spot check too), worded as such; the quick yes
        // still matches the suggestion, and the page says so.
        $otherSku = (int) self::$db->value('SELECT id FROM sku WHERE id <> ? AND merged_into_sku_id IS NULL ORDER BY id LIMIT 1', [$proposed]);
        $otherCode = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$otherSku]);
        $proposedCode = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$proposed]);
        $picked = $web->get('/ui/review/listing/' . $first['listing_id'], ['sample' => (string) $sid, 'pick' => (string) $otherSku]);
        $pxp = new \DOMXPath($picked->dom());
        self::assertSame((string) $proposed, (string) $pxp->evaluate('string(//form[contains(@class, "quick")]/input[@name="sku_id"]/@value)'));
        self::assertStringContainsString(Words::say('SPOT', 'picked_other', $otherCode, $proposedCode, $otherCode), $picked->text());
        self::assertSame(Words::say('SPOT', 'instead_link', $otherCode), trim((string) preg_replace('/\s+/', ' ',
            (string) $pxp->evaluate('string(//details[@id="not-a-match"]//label[input[@name="action" and @value="link"]])'))));
        // Another mapping lead opening a member is told it is the owner's, and gets no answer form (behaviour item 5, F187).
        $other = $this->signIn($this->uiUser('mapping_lead'), $this->browser('198.51.100.22'));
        $seen = $other->get('/ui/review/listing/' . $first['listing_id']);
        self::assertSame(200, $seen->status, $seen->describe());
        self::assertStringContainsString(Words::say('SPOT', 'other', 'Mapping_lead-reviewer 1') . ' ' . Words::SPOT['other_text'], $seen->text());
        self::assertFalse($seen->hasForm('/decide'), 'no quick yes and no answer form for anyone but the owner');
        self::assertStringNotContainsString(Words::LISTING['pick_other'], $seen->text(), 'and no product search');
        // A Matcher (not a lead) likewise: only the spot check's owner sees the quick yes and the answer form.
        $matcher = $this->signIn($this->uiUser('mapper'), $this->browser('198.51.100.23'));
        $seen = $matcher->get('/ui/review/listing/' . $first['listing_id']);
        self::assertSame(200, $seen->status, $seen->describe());
        self::assertStringContainsString(Words::say('SPOT', 'other', 'Mapping_lead-reviewer 1') . ' ' . Words::SPOT['other_text'], $seen->text());
        self::assertFalse($seen->hasForm('/decide'), 'no quick yes and no answer form for a Matcher either');
        $form = $listing->form('/ui/review/listing/' . $first['listing_id'] . '/decide');
        self::assertSame(['link', (string) $sid], [$form['action'] ?? null, $form['sample'] ?? null]);
        $r = $web->post('/ui/review/listing/' . $first['listing_id'] . '/decide', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/review/listing/' . $first['listing_id'] . '?notice=decided_link&sample=' . $sid, $r->location());
        $after = $web->follow($r);
        $profile = self::$db->one('SELECT product_title, variant_title FROM listing_profile WHERE listing_id = ?', [$first['listing_id']]);
        $name = Words::quoted(trim(((string) $profile['product_title']) . ' ' . ((string) $profile['variant_title'])), '');
        $code = (string) self::$db->value('SELECT s.code FROM channel_listing cl JOIN sku s ON s.id = cl.sku_id WHERE cl.id = ?', [$first['listing_id']]);
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_link', $name, $code), $after->text(), 'the notice names the product (F201)');
        self::assertContains('/ui/review/samples/' . $sid, $after->hrefs());
        self::assertContains('/ui/review/listing/' . $s['members'][1]['listing_id'] . '?sample=' . $sid, $after->hrefs(), 'then the next one');
        self::assertStringContainsString(Words::say('SPOT', 'next', 2, 20), $after->text());
        // R2 review (7 Oct): the confirmed member stays the spot check's. Its owner can still change the match, but only behind the
        // warning that this stops the bulk link for good, with a button that says so; another lead gets no form to change it.
        self::assertStringContainsString(Words::SPOT['mine_done'], $after->text());
        $axp = new \DOMXPath($after->dom());
        self::assertSame(Words::SPOT['change'], trim((string) $axp->evaluate('string(//details[@id="change-match"]/summary)')));
        self::assertSame(Words::SPOT['no_confirm_title'], trim((string) $axp->evaluate('string(//details[@id="change-match"]//p[@class="alert-title"])')));
        self::assertSame(Words::SPOT['change_button'], trim((string) $axp->evaluate('string(//details[@id="change-match"]//button[@type="submit"])')));
        self::assertSame('danger', (string) $axp->evaluate('string(//details[@id="change-match"]//button[@type="submit"]/@class)'));
        self::assertSame(0, $axp->query('//details[@id="change-match"]//input[@name="action" and @value="reject"]')->length,
            'every answer left there changes the match, so the button is true');
        $seen = $other->get('/ui/review/listing/' . $first['listing_id']);
        self::assertSame(200, $seen->status, $seen->describe());
        self::assertStringContainsString(Words::say('SPOT', 'other_done', 'Mapping_lead-reviewer 1') . ' ' . Words::SPOT['other_done_text'], $seen->text());
        self::assertFalse($seen->hasForm('/decide'), 'no form to change a spot check\'s confirmed match for anyone but its owner');
        self::assertStringNotContainsString(Words::LISTING['pick_other'], $seen->text(), 'and no product search');
        self::assertStringContainsString(Words::PAGE_INTRO['listing_spot'][0], $seen->text(), 'the intro says it is for looking');
        self::assertSame('confirmed', (new KeySample(self::$db))->status($sid)['members'][0]['state'], 'looking changed nothing');
        $page = $web->get('/ui/review/samples/' . $sid);
        self::assertStringContainsString(Words::say('SPOT', 'progress', 1, 20), $page->text());
        self::assertStringContainsString(Words::SAMPLE_STATE['confirmed'], $page->text());
        self::assertStringContainsString('Mapping_lead-reviewer 1', $page->text(), 'who confirmed it');

        // The second: "Not a match" (the form behind the second step). The spot check fails, and says so.
        $second = $s['members'][1];
        $form = $web->get('/ui/review/listing/' . $second['listing_id'], ['sample' => (string) $sid])->form('/ui/review/listing/' . $second['listing_id'] . '/decide');
        $r = $web->post('/ui/review/listing/' . $second['listing_id'] . '/decide', ['action' => 'reject'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('sample=' . $sid, (string) $r->location());
        $page = $web->get('/ui/review/samples/' . $sid);
        self::assertStringContainsString(Words::say('SPOT', 'progress', 2, 20), $page->text());
        self::assertStringContainsString(Words::SAMPLE_STATE['rejected'], $page->text());
        self::assertStringContainsString(Words::say('SAMPLE', 'failed_one', 20), $page->text());
        self::assertStringContainsString(Words::SAMPLE_RESULT['failed'], $web->get('/ui/review/samples')->text());
        self::assertSame('failed', (new KeySample(self::$db))->status($sid)['verdict']);

        self::assertSame(404, $web->get('/ui/review/samples/999999')->status);
        $buyer = $this->signIn($this->uiUser('buyer'), $this->browser('198.51.100.21'));
        self::assertSame(403, $buyer->get('/ui/review/samples')->status, 'linking.view only');
        self::assertSame(403, $buyer->get('/ui/review/samples/' . $sid)->status);
    }

    public function testAHeldProposalStaysInTheKeyQueueAndItsPageSaysWhy(): void
    {
        $this->keySetup();
        $owner = $this->uiUser(['mapping_lead', 'reviewer']);
        for ($i = 0; $i < 22; $i++) {
            $this->firstMatch($this->vpgItem("Acme Bar {$i} 20mg"), 90 + $i % 10);
        }
        $s = (new KeySample(self::$db))->create(Caller::staff($owner['id']), 'screen-3', 20, true);
        $sid = (int) $s['sample_id'];
        $rest = self::$db->all('SELECT proposal_id, listing_id FROM key_sample_member WHERE sample_id = ? AND position IS NULL ORDER BY proposal_id', [$sid]);
        self::assertCount(2, $rest);
        [$held, $free] = $rest;
        $hold = static fn (bool $release, string $why): array => (new KeyHold(self::$db))->run(Caller::staff($owner['id']), 'screen-3',
            KeyHold::parse("proposal_id,listing_id,reason\n{$held['proposal_id']},{$held['listing_id']},\"{$why}\"\n"), $release, true);
        self::assertSame(1, $hold(false, 'Flavour differs: <b>mango ice</b> vs mango')['written']);
        $web = $this->signIn($owner);

        // Still in the Key queue, to be decided one at a time like any other.
        $queue = $web->get('/ui/review', ['queue' => 'Key']);
        self::assertSame(200, $queue->status, $queue->describe());
        $listed = [];
        foreach ($queue->hrefs() as $h) {
            if (preg_match('#^/ui/review/listing/(\d+)\?#', $h, $m) === 1) {
                $listed[] = (int) $m[1];
            }
        }
        self::assertContains($held['listing_id'], $listed);

        // Its page says why (as text, never markup), leads to the sample, and the decision forms are there as usual.
        $page = $web->get('/ui/review/listing/' . $held['listing_id'], ['queue' => 'Key']);
        self::assertSame(200, $page->status, $page->describe());
        $heldLine = 'Check this one by hand: "Flavour differs: <b>mango ice</b> vs mango" (set aside by Mapping_lead-reviewer 1 on ';
        self::assertStringContainsString($heldLine, $page->text());
        self::assertContains('/ui/review/samples/' . $sid, $page->hrefs());
        self::assertTrue($page->hasForm('/ui/review/listing/' . $held['listing_id'] . '/decide'));
        self::assertStringNotContainsString('Check this one by hand', $web->get('/ui/review/listing/' . $free['listing_id'])->text());

        // The spot check's page lists what is set aside, why and by whom.
        $sp = $web->get('/ui/review/samples/' . $sid);
        self::assertSame(200, $sp->status, $sp->describe());
        self::assertStringContainsString(Words::SAMPLE['held_one'], $sp->text());
        self::assertStringContainsString('Flavour differs: <b>mango ice</b> vs mango', $sp->text());
        self::assertStringContainsString(Words::SAMPLE['held_waiting'], $sp->text());
        self::assertContains('/ui/review/listing/' . $held['listing_id'] . '?sample=' . $sid, $sp->hrefs());

        // A new matching run replaces the held proposal: the hold is on the listing, so both pages still show it.
        $run4 = $this->proposals->run('run4-sold', 'first_match', null, 'n2.1/c1.0/v2.1/b2.1', null, ['band_version' => 'b2.1']);
        $old = self::proposalRow((int) $held['proposal_id']);
        $newer = $this->proposals->add(Caller::system('import_proposals'), (int) $held['listing_id'], $run4, ['band' => 'Key',
            'proposed_sku_id' => (int) $old['proposed_sku_id'], 'lane' => 'barcode', 'ai_outcome' => 'match', 'ai_confidence' => (int) $old['ai_confidence'],
            'ai_units_per_item' => 1, 'evidence' => json_decode((string) $old['evidence'], true)])['proposal_id'];
        $page = $web->get('/ui/review/listing/' . $held['listing_id'], ['queue' => 'Key']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString($heldLine, $page->text());
        self::assertStringContainsString(Words::say('SAMPLE', 'title', 'screen-3'), $page->text());
        self::assertStringContainsString(Words::LISTING['held_newer'], $page->text());
        self::assertTrue($page->hasForm('/ui/review/listing/' . $held['listing_id'] . '/decide'));
        self::assertNotSame(0, $newer);
        $sp = $web->get('/ui/review/samples/' . $sid);
        self::assertStringContainsString(Words::SAMPLE['held_one'], $sp->text());
        self::assertStringContainsString(Words::SAMPLE['held_waiting'] . ' ' . Words::SAMPLE['held_newer'], $sp->text());

        // Released by a mapping lead, naming the proposal the hold was written for (replaced since): neither page shows it.
        self::assertSame(1, $hold(true, 'Checked: the same flavour')['written']);
        self::assertStringNotContainsString('Check this one by hand', $web->get('/ui/review/listing/' . $held['listing_id'])->text());
        self::assertStringContainsString(Words::SAMPLE['held_none'], $web->get('/ui/review/samples/' . $sid)->text());

        // A held listing linked since: by a person, the page says it was decided since; by a bulk confirm (it never should be:
        // simulated here by writing the hold row straight into the table after the bulk link), the sample's page flags it.
        $p = self::proposalRow((int) $free['proposal_id']);
        $this->ds->decide(Caller::staff($owner['id']), ['action' => 'link', 'listing_id' => (int) $free['listing_id'], 'sku_id' => (int) $p['proposed_sku_id'],
            'units_per_item' => 1, 'proposal_id' => (int) $free['proposal_id'], 'bulk_batch_id' => 'key_bulk:screen-3', 'reason' => 'test',
            'expected_map_version' => (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$free['listing_id']])]);
        self::$db->insert("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, reason, staff_user_id, actor) VALUES (?, ?, ?, 'hold', ?, ?, ?)",
            [$sid, $free['proposal_id'], $free['listing_id'], 'Held too late', $owner['id'], 'staff:test']);
        $sp = $web->get('/ui/review/samples/' . $sid);
        self::assertStringContainsString(Words::say('SAMPLE', 'held_many', 0), $sp->text());
        self::assertStringContainsString(Words::SAMPLE['held_decided_one'], $sp->text());
        self::assertStringContainsString(Words::SAMPLE['held_bulk'], $sp->text());
        self::assertStringNotContainsString('bin/', $sp->text(), 'no server command (F118)');
        $fp = $web->get('/ui/review/listing/' . $free['listing_id']);
        self::assertStringContainsString('Check this one by hand: "Held too late"', $fp->text());
        self::assertStringContainsString(Words::LISTING['held_decided'], $fp->text());
    }

    public function testAllConfirmedSaysTheBulkConfirmMayRun(): void
    {
        $this->keySetup();
        $owner = $this->uiUser('mapping_lead');
        for ($i = 0; $i < 21; $i++) {
            $this->firstMatch($this->vpgItem(), 90 + $i % 10);
        }
        $s = (new KeySample(self::$db))->create(Caller::staff($owner['id']), 'screen-2', 20, true);
        foreach ($s['members'] as $m) {
            $this->confirm(Caller::staff($owner['id']), $m['proposal_id']);
        }
        $page = $this->signIn($owner)->get('/ui/review/samples/' . $s['sample_id']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::say('SPOT', 'progress', 20, 20), $page->text());
        self::assertStringContainsString(Words::say('SAMPLE', 'passed', 20), $page->text(), 'the next step, in words (F109)');
        self::assertStringNotContainsString('bin/', $page->text(), 'no server command');
        self::assertSame(Words::SAMPLE_RESULT['passed'], trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//section[contains(@class, "spot-box")]//span[contains(@class, "chip")])')));
        self::assertFalse($page->hasForm('/ui/review/samples'), 'confirming the rest together is never a button');
    }
}
