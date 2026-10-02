<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\KeySample;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\KernelUiTestCase;

/**
 * The Key spot-check on the screens (M28), through the real /ui kernel as cw_app: the list and the sample page with
 * "n of 20 decided" and each member's state, a link from each member to the normal review screen that leads back, the
 * owner confirming one and rejecting another there, the warning another lead sees on a member's page, and the verdict
 * the bulk confirm will apply. Read-only pages: no bulk button anywhere.
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
