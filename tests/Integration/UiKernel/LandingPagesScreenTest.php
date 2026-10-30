<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Reservations;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Words;

/**
 * Landing pages on the screens (docs/decisions.md LP6, LP8) through the real /ui kernel as cw_app: Store Products' own state with why
 * each is one; the variant's page says what it is, and a matching lead (only) puts it back as a real product, with a note; a product
 * still to match can be marked by whoever decides it; Approvals › From the stores › Website bugs lists an order for one and marks it
 * handled; Home tells the people who sort orders out.
 */
final class LandingPagesScreenTest extends KernelUiTestCase
{
    private const TITLE = 'Blue Razz Cherry Elux Legend Nic Salt 10ml';

    /** @return array{0: Caller, 1: int, 2: int} the store, an option still to match, its landing page (marked by the website's flag) */
    private function store(): array
    {
        $site = $this->site('electrofag');
        self::$db->exec("UPDATE channel SET name = 'Electrofag' WHERE id = ?", [$site->channelId]);
        $option = $this->profiled($site, '958', ['product_title' => self::TITLE, 'variant_title' => self::TITLE . ' - 10mg']);
        $landing = $this->profiled($site, '960', ['product_title' => self::TITLE, 'variant_title' => self::TITLE, 'units_365d' => 4]);
        $this->ds->decide(Caller::system('landing_pages'), ['action' => 'landing', 'listing_id' => $landing, 'expected_map_version' => $this->version($landing),
            'landing' => ['reason' => 'website_flag', 'source' => 'tool']]);
        return [$site, $option, $landing];
    }

    public function testStoreProductsShowsTheLandingPagesApartWithWhy(): void
    {
        [, , $landing] = $this->store();
        $web = $this->signIn($this->uiUser('mapper'));
        $all = $web->get('/ui/review/store', ['channel' => 'electrofag']);
        self::assertSame(200, $all->status, $all->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::STORE_STATE['landing'], $all->text());
        $r = $web->get('/ui/review/store', ['channel' => 'electrofag', 'state' => 'landing']);
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringContainsString(self::TITLE, $r->text());
        self::assertStringContainsString(Words::LANDING['why_website_flag'], $r->text());
        self::assertStringContainsString('by the warehouse system', $r->text());
        self::assertStringContainsString(Words::STORE_STATE['landing'], $r->text());
        $none = $web->get('/ui/review/store', ['channel' => 'electrofag', 'state' => 'not_matched']);
        self::assertStringNotContainsString('/ui/review/listing/' . $landing . '"', $none->body, 'not in "Not matched"');
    }

    public function testOnlyAMatchingLeadPutsItBackAsARealProductWithANote(): void
    {
        [, , $landing] = $this->store();
        $mapper = $this->signIn($this->uiUser('mapper'));
        $page = $mapper->get('/ui/review/listing/' . $landing);
        self::assertSame(200, $page->status, $page->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::LANDING['is_landing'], $page->text());
        self::assertStringContainsString(Words::LANDING['lead_only'], $page->text());
        self::assertFalse($page->hasForm('/ui/review/listing/' . $landing . '/decide'), 'nothing to match on a landing page');

        $lead = $this->signIn($this->uiUser('mapping_lead'));
        $page = $lead->get('/ui/review/listing/' . $landing);
        self::assertStringContainsString(Words::ACTION['not_landing'], $page->text());
        $form = $page->form('/ui/review/listing/' . $landing . '/decide');
        self::assertSame('not_landing', $form['action'] ?? null);
        self::assertArrayHasKey('seen', $form, 'what the page showed goes with it, as on every decision form (Codex R7: a held-back one)');
        $bare = $lead->post('/ui/review/listing/' . $landing . '/decide', ['reason' => ''] + $form);
        self::assertSame(422, $bare->status, $bare->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['real_why'], $bare->text());
        self::assertSame('landing', (string) self::$db->value('SELECT status FROM channel_listing WHERE id = ?', [$landing]));
        $r = $lead->post('/ui/review/listing/' . $landing . '/decide', ['reason' => 'customers buy it as it is'] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame('unmapped', (string) self::$db->value('SELECT status FROM channel_listing WHERE id = ?', [$landing]));
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_not_landing', Words::quoted(self::TITLE . ' ' . self::TITLE, '')), $lead->follow($r)->text());
    }

    public function testAProductStillToMatchCanBeMarkedByWhoeverDecidesIt(): void
    {
        [, $option] = $this->store();
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/listing/' . $option);
        // Under the bar's More (W12.2): its button sends the page's own small form (form="mark-landing").
        self::assertSame(1, (new \DOMXPath($page->dom()))->query('//details[contains(@class, "more")]//button[@form="mark-landing" and normalize-space()="'
            . Words::ACTION['landing'] . '"]')->length);
        self::assertSame('landing', (string) (new \DOMXPath($page->dom()))->evaluate('string(//form[@id="mark-landing"]/input[@name="action"]/@value)'));
        $r = $web->post('/ui/review/listing/' . $option . '/decide', ['csrf' => $this->token($web), 'action' => 'landing',
            'expected_map_version' => (string) $this->version($option), 'seen' => '']);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertSame('landing', (string) self::$db->value('SELECT status FROM channel_listing WHERE id = ?', [$option]));
        self::assertSame('person', json_decode((string) self::$db->value("SELECT CAST(detail AS CHAR) FROM match_decision WHERE listing_id = ? AND action = 'landing'", [$option]), true)['landing']['reason']);
        self::assertStringContainsString(Words::LANDING['why_person'], $web->get('/ui/review/store', ['channel' => 'electrofag', 'state' => 'landing'])->text());
    }

    public function testFindShowsALandingPageAsOneCodexR10(): void
    {
        $this->store();
        $r = $this->signIn($this->uiUser('mapper'))->get('/ui/search', ['q' => 'Blue Razz Cherry']);
        self::assertSame(200, $r->status, $r->describe());
        self::assertStringContainsString(Words::LISTING_STATUS['landing'], $r->text());
    }

    public function testWebsiteBugsListsTheOrderAndMarksItHandledAndHomeSaysSo(): void
    {
        [$site] = $this->store();
        (new Reservations(self::$db))->commit($site, '5001', [['variant_id' => '960', 'qty' => 2, 'unit_ids' => ['a', 'b']]], 'unreserved', 'c-5001');
        $id = (int) self::$db->value('SELECT id FROM landing_order');

        $buyer = $this->signIn($this->uiUser('buyer'));
        $look = $buyer->get('/ui/queues/landing');
        self::assertSame(200, $look->status, $look->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString('You can look', $look->text());
        self::assertSame(['Lines to place', 'ERPNext corrections', 'Mode changes', 'Oversold', 'Website bugs'], array_slice(array_column(self::segments($look), 'label'), -5),
            'the stores\' queues, last in Approvals\' Show (RD7)');

        $web = $this->signIn($this->uiUser('stock_controller'));
        $home = $web->get('/ui/');
        self::assertStringContainsString(Words::TASK['website_bugs']['title'], $home->text());
        $page = $web->get('/ui/queues/landing');
        self::assertStringContainsString(Words::say('WEBSITE_BUG', 'title', '5001', self::TITLE), $page->text());
        self::assertStringContainsString(Words::say('WEBSITE_BUG', 'option', '960'), $page->text());
        self::assertStringContainsString(Words::WEBSITE_BUG['no_stock'], $page->text());
        self::assertStringContainsString(Words::say('WEBSITE_BUG', 'units', 2), $page->text(), 'Codex R9');
        self::assertNotContains('/ui/review/listing/' . (int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = '960'"), $page->hrefs(),
            'a stock controller may not open the matching page: no link to it (Codex R8)');
        self::assertSame(1, array_column(self::segments($page), 'count', 'label')['Website bugs']);
        $form = $page->form('/ui/queues/landing/' . $id . '/handle');
        self::assertSame('1', $form['paid'] ?? null, 'the answer says what the person saw (Codex R6)');
        $r = $web->post('/ui/queues/landing/' . $id . '/handle', ['resolution' => 'fixed_by_hand', 'note' => 'sent the 20mg'] + $form);
        self::assertSame(303, $r->status, $r->describe() . ' ' . implode(' | ', self::$log));
        self::assertStringContainsString(Words::QUEUE_NOTICE['handled'], $web->follow($r)->text());
        self::assertSame('resolved', (string) self::$db->value('SELECT CAST(status AS CHAR) FROM landing_order WHERE id = ?', [$id]));
        self::assertStringNotContainsString(Words::TASK['website_bugs']['title'], $web->get('/ui/')->text());
        $again = $web->post('/ui/queues/landing/' . $id . '/handle', ['resolution' => 'refunded', 'note' => 'again'] + $form);
        self::assertSame(409, $again->status);
        self::assertStringContainsString(Words::QUEUE_ERROR['queue_settled'], $again->text());
    }
}
