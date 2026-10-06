<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\KernelUiTestCase;

/**
 * The Key spot-check on the screens (M28), through the real /ui kernel as cw_app: the list and the sample page with
 * "n of 20 decided" and each member's state, a link from each member to the normal review screen that leads back, the
 * owner confirming one and rejecting another there, the warning another lead sees on a member's page, and the verdict
 * the bulk confirm will apply. Read-only pages: no bulk button anywhere. A listing held back from the bulk confirm (M30)
 * stays in the Key queue, its page says why (a newer proposal of it too), and the sample's page lists it with what became
 * of it (a held listing a bulk confirm linked is flagged).
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
        self::assertContains(['label' => 'Key spot-check', 'href' => '/ui/review/samples'], self::nav($list)['Linking']);
        $current = (new \DOMXPath($list->dom()))->query('//nav[@aria-label="Main"]//a[@aria-current="page"]');
        self::assertSame('Key spot-check', trim((string) $current->item(0)?->textContent));

        $page = $web->get('/ui/review/samples/' . $sid);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString('0 of 20 decided', $page->text());
        self::assertSame(20, substr_count($page->text(), 'Not decided yet'));
        self::assertStringContainsString('Seed', $page->text());
        self::assertStringContainsString((string) $s['seed'], $page->text());
        self::assertStringContainsString('drawn by the server', $page->text());
        foreach ($s['members'] as $m) {
            self::assertContains('/ui/review/listing/' . $m['listing_id'] . '?sample=' . $sid, $page->hrefs());
        }
        self::assertFalse($page->hasForm('/ui/review/samples'), 'the bulk confirm is never a button');

        // The first member: opened from the sample, confirmed with the quick form, and back.
        $first = $s['members'][0];
        $listing = $web->get('/ui/review/listing/' . $first['listing_id'], ['sample' => (string) $sid]);
        self::assertSame(200, $listing->status, $listing->describe());
        self::assertContains('/ui/review/samples/' . $sid, $listing->hrefs());
        self::assertStringContainsString('Key spot-check screen-1', $listing->text());
        self::assertStringContainsString('This proposal is #1 of 20 in your Key spot-check screen-1', $listing->text());
        // Another mapping lead opening a member is told to leave it to the owner (their decision would fail the spot-check).
        $other = $this->signIn($this->uiUser('mapping_lead'), $this->browser('198.51.100.22'));
        $seen = $other->get('/ui/review/listing/' . $first['listing_id']);
        self::assertSame(200, $seen->status, $seen->describe());
        self::assertStringContainsString('This proposal is #1 of the Key spot-check screen-1 of Mapping_lead-reviewer 1', $seen->text());
        self::assertStringContainsString('Leave it to them: a decision by anyone else makes the spot-check fail.', $seen->text());
        $form = $listing->form('/ui/review/listing/' . $first['listing_id'] . '/decide');
        self::assertSame(['link', (string) $sid], [$form['action'] ?? null, $form['sample'] ?? null]);
        $r = $web->post('/ui/review/listing/' . $first['listing_id'] . '/decide', $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/review/listing/' . $first['listing_id'] . '?notice=decided_link&sample=' . $sid, $r->location());
        $after = $web->follow($r);
        self::assertStringContainsString('Linked listing #' . $first['listing_id'] . '.', $after->text());
        self::assertContains('/ui/review/samples/' . $sid, $after->hrefs());
        $page = $web->get('/ui/review/samples/' . $sid);
        self::assertStringContainsString('1 of 20 decided', $page->text());
        self::assertStringContainsString('Confirmed', $page->text());
        self::assertStringContainsString('Mapping_lead-reviewer 1', $page->text(), 'who confirmed it');

        // The second: rejected. The sample fails, and says so.
        $second = $s['members'][1];
        $form = $web->get('/ui/review/listing/' . $second['listing_id'], ['sample' => (string) $sid])->form('/ui/review/listing/' . $second['listing_id'] . '/decide');
        $r = $web->post('/ui/review/listing/' . $second['listing_id'] . '/decide', ['action' => 'reject'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('sample=' . $sid, (string) $r->location());
        $page = $web->get('/ui/review/samples/' . $sid);
        self::assertStringContainsString('2 of 20 decided', $page->text());
        self::assertStringContainsString('Rejected', $page->text());
        self::assertStringContainsString('The bulk confirm refuses this sample', $page->text());
        self::assertStringContainsString('not confirmed: no bulk confirm', $web->get('/ui/review/samples')->text());
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
        self::assertStringContainsString('Held back from the bulk confirm: Flavour differs: <b>mango ice</b> vs mango', $page->text());
        self::assertStringContainsString('Set aside for one-at-a-time review by Mapping_lead-reviewer 1', $page->text());
        self::assertContains('/ui/review/samples/' . $sid, $page->hrefs());
        self::assertTrue($page->hasForm('/ui/review/listing/' . $held['listing_id'] . '/decide'));
        self::assertStringNotContainsString('Held back from the bulk confirm', $web->get('/ui/review/listing/' . $free['listing_id'])->text());

        // The sample's page lists what is held, why and by whom.
        $sp = $web->get('/ui/review/samples/' . $sid);
        self::assertSame(200, $sp->status, $sp->describe());
        self::assertStringContainsString('Held back now: 1 of this sample\'s population', $sp->text());
        self::assertStringContainsString('Flavour differs: <b>mango ice</b> vs mango', $sp->text());
        self::assertStringContainsString('waiting for a decision', $sp->text());
        self::assertContains('/ui/review/listing/' . $held['listing_id'] . '?sample=' . $sid, $sp->hrefs());

        // A new matching run replaces the held proposal: the hold is on the listing, so both pages still show it.
        $run4 = $this->proposals->run('run4-sold', 'first_match', null, 'n2.1/c1.0/v2.1/b2.1', null, ['band_version' => 'b2.1']);
        $old = self::proposalRow((int) $held['proposal_id']);
        $newer = $this->proposals->add(Caller::system('import_proposals'), (int) $held['listing_id'], $run4, ['band' => 'Key',
            'proposed_sku_id' => (int) $old['proposed_sku_id'], 'lane' => 'barcode', 'ai_outcome' => 'match', 'ai_confidence' => (int) $old['ai_confidence'],
            'ai_units_per_item' => 1, 'evidence' => json_decode((string) $old['evidence'], true)])['proposal_id'];
        $page = $web->get('/ui/review/listing/' . $held['listing_id'], ['queue' => 'Key']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString('Held back from the bulk confirm: Flavour differs: <b>mango ice</b> vs mango', $page->text());
        self::assertStringContainsString("Key spot-check screen-3, proposal #{$held['proposal_id']})", $page->text());
        self::assertStringContainsString('The hold is on this listing, so it covers its newer proposal too.', $page->text());
        self::assertTrue($page->hasForm('/ui/review/listing/' . $held['listing_id'] . '/decide'));
        $sp = $web->get('/ui/review/samples/' . $sid);
        self::assertStringContainsString('Held back now: 1 of this sample\'s population still waiting', $sp->text());
        self::assertStringContainsString("waiting for a decision (now proposal #{$newer}: still held)", $sp->text());

        // Released by a mapping lead, naming the proposal the hold was written for (replaced since): neither page shows it.
        self::assertSame(1, $hold(true, 'Checked: the same flavour')['written']);
        self::assertStringNotContainsString('Held back from the bulk confirm', $web->get('/ui/review/listing/' . $held['listing_id'])->text());
        self::assertStringContainsString('None. A mapping lead holds listings', $web->get('/ui/review/samples/' . $sid)->text());

        // A held listing linked since: by a person, the page says it was decided since; by a bulk confirm (it never should be:
        // simulated here by writing the hold row straight into the table after the bulk link), the sample's page flags it.
        $p = self::proposalRow((int) $free['proposal_id']);
        $this->ds->decide(Caller::staff($owner['id']), ['action' => 'link', 'listing_id' => (int) $free['listing_id'], 'sku_id' => (int) $p['proposed_sku_id'],
            'units_per_item' => 1, 'proposal_id' => (int) $free['proposal_id'], 'bulk_batch_id' => 'key_bulk:screen-3', 'reason' => 'test',
            'expected_map_version' => (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$free['listing_id']])]);
        self::$db->insert("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, reason, staff_user_id, actor) VALUES (?, ?, ?, 'hold', ?, ?, ?)",
            [$sid, $free['proposal_id'], $free['listing_id'], 'Held too late', $owner['id'], 'staff:test']);
        $sp = $web->get('/ui/review/samples/' . $sid);
        self::assertStringContainsString('Held back now: 0 of this sample\'s population still waiting', $sp->text());
        self::assertStringContainsString('1 more held listing was decided since.', $sp->text());
        self::assertStringContainsString('Linked by the bulk confirm key_bulk:screen-3 A held listing should never be', $sp->text());
        self::assertStringContainsString('bin/bulk_unlink.php --batch=key_bulk:screen-3', $sp->text());
        $fp = $web->get('/ui/review/listing/' . $free['listing_id']);
        self::assertStringContainsString('Held back from the bulk confirm: Held too late', $fp->text());
        self::assertStringContainsString('This listing was decided since; the hold stays on record.', $fp->text());
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
        self::assertStringContainsString('20 of 20 decided', $page->text());
        self::assertStringContainsString('may run', $page->text());
        self::assertStringContainsString('bin/bulk_confirm_key.php --sample=screen-2', $page->text());
    }
}
