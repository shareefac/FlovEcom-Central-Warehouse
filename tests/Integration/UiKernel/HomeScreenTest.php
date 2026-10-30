<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\DashboardController;
use CW\Ui\Words;

/**
 * Home (plan §3, step 4; the approved HM_Home and HM_Phone of 29 Oct 2026, docs/decisions.md HV1), through the real /ui kernel as
 * cw_app: today's date, the greeting and one Find box; "Waiting for you", the rows each person gets from the real counts (HomeCounts),
 * in order, each with its big number, its name, one plain line and one button that opens the work (the first one the page's main
 * action), gone when nothing waits; the notes after them; the owner's account with Admin gets only its own clash and never the spot check
 * (its Matching lead job is off, so it cannot answer); up to four small figures; no About box, no second menu, no matching progress.
 */
final class HomeScreenTest extends KernelUiTestCase
{
    use KeyFixtures;
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    /**
     * The rows of "Waiting for you", in order: key, the big number drawn, title, what a screen reader hears for the number (the count with
     * its unit), button text, link and whether it is the main one, whether it is a note, all text.
     *
     * @return list<array{key: string, big: string, title: string, count: string, button: ?string, href: ?string, main: bool, note: bool, text: string}>
     */
    private static function cardsOf(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $norm = static fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', $s));
        $out = [];
        foreach ($xp->query('//main//*[@data-card]') ?: [] as $li) {
            /** @var \DOMElement $li */
            // (each row's action: a link at its right since the Main artboard, docs/decisions.md RD-C15; no row is the page's main button)
            $buttons = $xp->query('.//a[contains(concat(" ", @class, " "), " hm-go ")]', $li);
            self::assertLessThanOrEqual(1, $buttons === false ? 0 : $buttons->length, 'one button per row');
            $a = $buttons !== false && $buttons->length === 1 ? $buttons->item(0) : null;
            self::assertSame(1, $xp->query('.//h3', $li)->length, 'the job\'s name');
            self::assertSame(1, $xp->query('.//p[@class="hm-d"]', $li)->length, 'one plain line');
            $out[] = ['key' => $li->getAttribute('data-card'), 'big' => $norm((string) $xp->evaluate('string(.//span[@class="hm-n"])', $li)),
                'title' => $norm((string) $xp->evaluate('string(.//h3)', $li)), 'count' => $norm((string) $xp->evaluate('string(.//span[@class="hm-said"])', $li)),
                'button' => $a instanceof \DOMElement ? $norm(str_replace('›', '', $a->textContent)) : null, 'href' => $a instanceof \DOMElement ? $a->getAttribute('href') : null,
                'main' => $a instanceof \DOMElement && str_contains(' ' . $a->getAttribute('class') . ' ', ' primary '),
                'note' => $xp->query('ancestor::ul[contains(@class, "hm-notes")]', $li)->length === 1, 'text' => $norm($li->textContent)];
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $cards @return array<string, array<string, mixed>> the first card of each key */
    private static function byKey(array $cards): array
    {
        $out = [];
        foreach ($cards as $c) {
            $out[$c['key']] ??= $c;
        }
        return $out;
    }

    /** GET a card's link (its query string, no #fragment). */
    private static function open(KernelBrowser $web, string $href): UiResponse
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $web->get((string) parse_url($href, PHP_URL_PATH), $query);
    }

    /** A complete draft supplier made by $buyerId. @return array<string, mixed> */
    private function supplierDraft(int $buyerId, string $name): array
    {
        return (new Suppliers(self::$db))->create(Caller::staff($buyerId), ['name' => $name, 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyerId, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
    }

    /** Two Vape and Go pages of one product: one merge suggestion (a duplicate group). @return int the group (its suggestion) */
    private function duplicatePair(): int
    {
        $a = $this->vpgItem('Dup Bar Mango 20mg');
        $b = $this->vpgItem('Dup Bar Mango 20mg (page 2)');
        $la = self::vpgListingOf($a);
        $lb = self::vpgListingOf($b);
        $v = static fn (int $l): string => (string) self::$db->value('SELECT external_variant_id FROM channel_listing WHERE id = ?', [$l]);
        $members = [['vpg_variant_id' => $v($la), 'sku_id' => $a, 'units_30d' => 1], ['vpg_variant_id' => $v($lb), 'sku_id' => $b, 'units_30d' => 1]];
        $run = $this->proposals->run('run2-vpg-duplicates', 'vpg_duplicates');
        return $this->proposals->add(Caller::system('mint_vpg'), $lb, $run, ['proposed_sku_id' => $a, 'band' => 'Manual', 'lane' => 'vpg_duplicate',
            'flags' => ['identity_key', 'merge_suggestion'], 'evidence' => ['group' => 1, 'kind' => 'identity_key', 'keeper' => $members[0], 'members' => $members]], false)['proposal_id'];
    }

    /**
     * The owner today (plan §3.2): the company details, a supplier waiting for an OK, their spot check, a set-aside match,
     * a duplicate group, the strong matches. Then one answer moves the spot check on; a wrong answer fails it and its cards go.
     */
    public function testTheOwnersHomeToday(): void
    {
        $this->keySetup();
        $owner = $this->uiUser(['mapping_lead', 'reviewer']);
        for ($i = 0; $i < 25; $i++) {
            $this->firstMatch($this->vpgItem("Acme Bar {$i} 20mg"), 90 + $i % 10);
        }
        $s = (new KeySample(self::$db))->create(Caller::staff($owner['id']), 'home-1', 20, true);
        $sid = (int) $s['sample_id'];
        $rest = self::$db->all('SELECT proposal_id, listing_id FROM key_sample_member WHERE sample_id = ? AND position IS NULL ORDER BY proposal_id', [$sid]);
        self::assertCount(5, $rest);
        $hold = (new KeyHold(self::$db))->run(Caller::staff($owner['id']), 'home-1',
            KeyHold::parse("proposal_id,listing_id,reason\n{$rest[0]['proposal_id']},{$rest[0]['listing_id']},\"mango ice vs mango\"\n"), false, true);
        self::assertSame(1, $hold['written']);
        $this->duplicatePair();
        $buyer = $this->uiUser('buyer');
        $sup = $this->supplierDraft($buyer['id'], 'Waiting Supplies');
        (new Suppliers(self::$db))->requestActivation(Caller::staff($buyer['id']), (int) $sup['id'], (int) $sup['version']);

        $web = $this->signIn($owner);
        $home = $web->get('/ui/');
        self::assertSame(200, $home->status, $home->describe());
        $xp = new \DOMXPath($home->dom());
        // The head of the Main artboard (docs/decisions.md RD-C15): today's date, "Good morning, <first name>"; no Find box (the sidebar's).
        self::assertSame(\CW\Ui\Html::today(), trim((string) $xp->evaluate('string(//main//p[@class="ph-eyebrow"])')));
        self::assertMatchesRegularExpression('/^Good (morning|afternoon|evening), Mapping_lead-reviewer$/', trim((string) $xp->evaluate('string(//main//h1)')));
        self::assertSame(0, $xp->query('//main//form[@class="hm-find"]')->length, 'no Find box on Home');
        self::assertSame('Home', self::currentSection($home));
        self::assertSame('Home - Central Warehouse', trim((string) $xp->evaluate('string(//title)')));
        self::assertStringStartsWith(Words::HOME['needs'] . ' · ', trim((string) $xp->evaluate('string(//main//h2[@id="needs-h"])')), '"Waiting for you · N jobs"');
        $cards = self::cardsOf($home);
        // What holds others up first (the company details, an approval), then the owner's own checks, then routine work.
        // Then a note (no job): the people this test added on the server in the last days (Y45, the reviewers' "to look at").
        self::assertSame(['company_confirm', 'approvals', 'spot_check', 'set_aside', 'duplicates', 'strong', 'watch'], array_column($cards, 'key'));
        self::assertSame([false, false, false, false, false, false, false], array_column($cards, 'main'), 'no row is magenta: the page\'s main button is + New purchase order (RD-C15)');
        self::assertSame([false, false, false, false, false, false, true], array_column($cards, 'note'), 'the note after the jobs');
        self::assertSame(['!', '1', '0/20', '1', '1', '25'], array_slice(array_column($cards, 'big'), 0, 6), 'the big numbers: "!" where there is none');
        $by = self::byKey($cards);
        self::assertSame([Words::TASK['company_confirm']['button_missing'], '/ui/reference/company'], [$by['company_confirm']['button'], $by['company_confirm']['href']]);
        self::assertStringContainsString('Still missing: legal name', $by['company_confirm']['text']);
        self::assertSame(['1 waits for you', '/ui/documents/reviews#approvals'], [$by['approvals']['count'], $by['approvals']['href']]);
        // The approvals card counts what the menu badge counts: the same number, never one the person cannot clear.
        // The menu's Approvals entry opens Waiting for you (docs/decisions.md AV1, AV10), and its badge counts the same record.
        self::assertSame(['href' => '/ui/approvals', 'count' => 1], self::nav($home)['Approvals']);
        $first = $s['members'][0];
        self::assertSame('Spot check home-1: are these 20 matches right?', $by['spot_check']['title']);
        self::assertSame('0 of 20 checked', $by['spot_check']['count'], 'a screen reader hears the number with its unit');
        self::assertSame(['Check the next one (1 of 20)', '/ui/review/listing/' . $first['listing_id'] . '?sample=' . $sid],
            [$by['spot_check']['button'], $by['spot_check']['href']]);
        self::assertStringContainsString('If all 20 are right, about 4 more strong matches are confirmed together.', $by['spot_check']['text'],
            '25 in the spot check\'s population, less the 20 and the one set aside');
        self::assertSame(['1 to check', '/ui/review/samples/' . $sid . '#held'], [$by['set_aside']['count'], $by['set_aside']['href']]);
        self::assertSame(['1 group', '/ui/review/duplicates', Words::TASK['duplicates']['button']],
            [$by['duplicates']['count'], $by['duplicates']['href'], $by['duplicates']['button']]);
        self::assertSame(['href' => '/ui/match', 'count' => 1], self::nav($home)['Matching'], 'the duplicates card and the sidebar\'s count agree');
        self::assertSame([0, 1], [array_column(self::sectionTabs($web->get('/ui/review/duplicates')), 'count', 'label')['Mapping'],
            array_column(self::sectionTabs($web->get('/ui/review/duplicates')), 'count', 'label')['Duplicates']], '... and the Duplicates tab\'s');
        self::assertSame(1, array_column(self::moreTabs($web->get('/ui/match')), 'count', 'label')['Duplicates'], '... under the row\'s More on the other pages');
        self::assertSame(['25 to confirm', '/ui/review?queue=Key'], [$by['strong']['count'], $by['strong']['href']]);
        self::assertStringNotContainsString(Words::TASK['strong']['what'], $by['strong']['text'], 'one plain line: the "What happens:" line is gone (HV1)');
        self::assertStringContainsString('If your spot check home-1 passes, about 4 of them are confirmed together, so do the spot check first.', $by['strong']['text']);
        self::assertGreaterThan(0, $xp->query('//main//*[@data-card="watch"]//ul[@class="hm-lines"]/li')->length, 'the changes to look at keep their lines (Y45)');
        // Each button opens its work.
        foreach ($cards as $c) {
            self::assertSame(200, self::open($web, (string) $c['href'])->status, "{$c['key']}: {$c['href']}");
        }
        self::assertSame(1, (new \DOMXPath($web->get('/ui/review/samples/' . $sid)->dom()))->query('//section[@id="held"]')->length, 'the set-aside list\'s anchor');
        // No About box, no second menu, no matching progress (HV1): the progress is Matching › Mapping › To review's (HV5).
        self::assertSame(0, $xp->query('//main//details[@id="about"] | //main//ul[@class="uses"] | //main//section[@class="progress-box"]')->length);
        self::assertStringNotContainsString(Words::HOME['coverage'], $home->text());
        self::assertStringContainsString(Words::HOME['coverage'], $web->get('/ui/review')->text());
        // The four tinted tiles of the Main artboard (docs/decisions.md RD-C15): the stock value at cost, the units on the shelf, what waits for
        // this person's OK, the listings to match.
        self::assertSame(['value', 'units', 'inbox', 'matches'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('data-tile'),
            iterator_to_array($xp->query('//main//section[contains(@class, "hm-figs")]/a'))));
        self::assertSame(['/ui/reports/stock-value', '/ui/stock', '/ui/approvals', '/ui/review?queue=Key'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array($xp->query('//main//section[contains(@class, "hm-figs")]/a'))));
        self::assertMatchesRegularExpression('/^[0-9,]+$/', trim((string) $xp->evaluate('string(//main//a[@data-tile="units"]/span[@class="hm-fig-n"])')),
            'each a number');
        self::assertMatchesRegularExpression('/^£[0-9,]+\.\d\d$/', trim((string) $xp->evaluate('string(//main//a[@data-tile="value"]/span[@class="hm-fig-n"])')), 'the value in money');

        // One answer: 1 of 20, and the button opens the next member.
        // (the spot check's own "Same item": its own small form in the bar, docs/decisions.md W12.3)
        $form = [];
        foreach ((new \DOMXPath($web->get('/ui/review/listing/' . $first['listing_id'], ['sample' => (string) $sid])->dom()))->query('//form[contains(@class, "quick")]//input') ?: [] as $in) {
            if ($in instanceof \DOMElement) {
                $form[$in->getAttribute('name')] = $in->getAttribute('value');
            }
        }
        self::assertSame('link', $form['action'] ?? null);
        self::assertSame(303, $web->post('/ui/review/listing/' . $first['listing_id'] . '/decide', $form)->status);
        $by = self::byKey(self::cardsOf($web->get('/ui/')));
        self::assertSame(['1 of 20 checked', '1/20'], [$by['spot_check']['count'], $by['spot_check']['big']]);
        self::assertSame(['Check the next one (2 of 20)', '/ui/review/listing/' . $s['members'][1]['listing_id'] . '?sample=' . $sid],
            [$by['spot_check']['button'], $by['spot_check']['href']]);
        self::assertSame('24 to confirm', $by['strong']['count']);

        // A wrong answer fails the spot check for good: no card asks for answers any more, and its set-aside matches are
        // checked one at a time with all the others (the strong matches card).
        $second = $s['members'][1];
        $form = $this->decideForm($web, $second['listing_id'], ['sample' => (string) $sid]);
        self::assertSame(303, $web->post('/ui/review/listing/' . $second['listing_id'] . '/decide', ['action' => 'reject'] + $form)->status);
        self::assertSame('failed', (new KeySample(self::$db))->status($sid)['verdict']);
        $keys = array_column(self::cardsOf($web->get('/ui/')), 'key');
        self::assertNotContains('spot_check', $keys);
        self::assertNotContains('set_aside', $keys);
        self::assertContains('strong', $keys);
    }

    /**
     * Correction a (7 Oct): the owner's account keeps Admin with its two working jobs and the yellow strip. Home then shows no
     * work those jobs would do (not even its own spot check: Admin switches its Matching lead job off, so it cannot answer), only
     * the clash itself, and never a second account.
     */
    public function testTheOwnersAccountWithAdminSeesOnlyItsClash(): void
    {
        $this->keySetup();
        $owner = $this->uiUser(['mapping_lead', 'reviewer']);
        for ($i = 0; $i < 22; $i++) {
            $this->firstMatch($this->vpgItem("Acme Bar {$i} 20mg"), 95);
        }
        (new KeySample(self::$db))->create(Caller::staff($owner['id']), 'owner-1', 20, true);
        $this->duplicatePair();
        $this->uiUser('reviewer');
        $this->uiUser('reviewer');
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'admin')", [$owner['id']]);
        // The fixtures' seed lead has a placeholder address (a test account): give it a real one, so only the clash is left.
        self::$db->exec("UPDATE staff_user SET email = REPLACE(email, '.invalid', '.example') WHERE id = ?", [(int) $this->seedLead->staffUserId]);

        $home = $this->signIn($owner)->get('/ui/');
        self::assertSame(200, $home->status, $home->describe());
        self::assertSame(1, (new \DOMXPath($home->dom()))->query('//main//div[contains(@class, "admin-off")]')->length, 'the strip');
        $cards = self::cardsOf($home);
        self::assertSame(['own_clash'], array_column($cards, 'key'), 'no spot check, duplicates or company details: Admin switches those jobs off');
        self::assertSame(Words::TASK['own_clash']['title'], $cards[0]['title']);
        self::assertStringContainsString('Your Reviewer and Matching lead jobs are switched off because this account also has Admin. Ask Fazil to take Admin off this account.',
            (string) (new \DOMXPath($home->dom()))->evaluate('string(//main//div[contains(@class, "admin-off")])'), 'the strip says it');
        self::assertStringContainsString(Words::TASK['own_clash']['text'], $cards[0]['text'], 'the card points to the strip');
        self::assertStringNotContainsString('Ask Fazil to take Admin off this account', $cards[0]['text'], 'instead of repeating it');
        self::assertSame('/ui/people/' . $owner['id'], $cards[0]['href']);
        self::assertStringNotContainsString('second account', $home->text(), 'correction a');
        self::assertSame(0, array_sum(array_column(self::nav($home), 'count')), 'no count: nothing this account may decide');
        self::assertSame(0, (new \DOMXPath($home->dom()))->query('//a[contains(@class, "bell")]')->length, 'no bell: admin answers nothing in the inbox');
    }

    /** The admin: test accounts that can sign in, too few people who can approve work, and someone else's jobs switched off. */
    public function testTheAdminsHome(): void
    {
        $admin = $this->uiUser('admin');
        $test = $this->uiUser('viewer');
        self::$db->exec('UPDATE staff_user SET email = ? WHERE id = ?', ['old-test-1@cw.invalid', $test['id']]);
        $this->uiUser('reviewer');
        $owner = $this->uiUser(['mapping_lead', 'reviewer']);
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'admin')", [$owner['id']]);

        $web = $this->signIn($admin);
        $home = $web->get('/ui/');
        self::assertSame(200, $home->status, $home->describe());
        $cards = self::cardsOf($home);
        self::assertSame(['test_accounts', 'admin_clash', 'reviewers'], array_column($cards, 'key'));
        $by = self::byKey($cards);
        self::assertSame(['1 account', '/ui/people/' . $test['id'], Words::TASK['test_accounts']['button']],
            [$by['test_accounts']['count'], $by['test_accounts']['href'], $by['test_accounts']['button']]);
        self::assertSame('Mapping_lead-reviewer 4: jobs switched off by Admin', $by['admin_clash']['title']);
        self::assertStringContainsString('Mapping_lead-reviewer 4 has Admin, so these jobs do nothing: Reviewer and Matching lead.', $by['admin_clash']['text']);
        self::assertSame('/ui/people/' . $owner['id'], $by['admin_clash']['href']);
        self::assertSame(Words::TASK['reviewers']['title'], $by['reviewers']['title'], 'the owner\'s Reviewer job is off: one person can approve work');
        foreach ($cards as $c) {
            self::assertSame(200, self::open($web, (string) $c['href'])->status, "{$c['key']}: {$c['href']}");
        }
        self::assertSame(['1', '!', '!'], array_column($cards, 'big'), 'a row without a number shows "!"');
        self::assertSame(['1 account', Words::HOME['needs_you'], Words::HOME['needs_you']], array_column($cards, 'count'), '... and a screen reader hears its state');
        self::assertSame(['units', 'matches', 'products'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('data-tile'),
            iterator_to_array((new \DOMXPath($home->dom()))->query('//main//section[contains(@class, "hm-figs")]/a'))), 'the figures the admin may see');
    }

    /** The buyer: orders not sent or not OK, drafts, a supplier not ready, and two notes (DO NOT SEND, old sales data). */
    public function testTheBuyersHome(): void
    {
        $buyer = $this->uiUser('buyer');
        $bc = Caller::staff($buyer['id']);
        $reviewer = $this->uiUser('reviewer');
        $svc = new Suppliers(self::$db);
        $this->supplierDraft($buyer['id'], 'Draft Supplies');
        $s = $this->supplierDraft($buyer['id'], 'Home Supplies');
        $s = $svc->requestActivation($bc, (int) $s['id'], (int) $s['version']);
        $svc->approve(Caller::staff($reviewer['id']), (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'",
            [(int) $s['id']]), null);
        $si = (new SupplierItems(self::$db))->create($bc, (int) $s['id'], self::makeSku('Home item'), ['units_per_pack' => '1', 'supplier_code' => 'HOME-1'],
            ['pack_price' => '5.0000']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $pos = new PurchaseOrders(self::$db, $docs);
        $order = static function () use ($pos, $bc, $s, $si): \CW\Documents\Document {
            $d = $pos->createDraft($bc, (int) $s['id'], []);
            return $pos->saveDraft($bc, $d->id, $d->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 2]]);
        };
        $order();
        $a = $order();
        $pos->approve($bc, $a->id, $a->version);
        $b = $order();
        $pos->approve($bc, $b->id, $b->version);
        $docs->reject(Caller::staff($reviewer['id']), (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND state = 'open'",
            [$b->id]), 'The price is not the one we agreed.');
        $this->history($this->channelOf('vapeandgo'), '2026-06-01', '2026-06-30', []);
        // Another buyer's draft is theirs to confirm, not this buyer's.
        $other = $this->uiUser('buyer');
        $pos->createDraft(Caller::staff($other['id']), (int) $s['id'], []);

        $web = $this->signIn($buyer);
        $home = $web->get('/ui/');
        self::assertSame(200, $home->status, $home->describe());
        $cards = self::cardsOf($home);
        self::assertSame(['not_ok', 'not_sent', 'drafts', 'supplier_drafts', 'company_wait', 'old_sales'], array_column($cards, 'key'));
        self::assertSame([false, false, false, false, true, true], array_column($cards, 'note'));
        self::assertSame([false, false, false, false, false, false], array_column($cards, 'main'), 'no row is the page\'s main button (+ New purchase order is, RD-C15)');
        $by = self::byKey($cards);
        self::assertSame(['1 order', '/ui/purchasing/orders?rejected=1'], [$by['not_ok']['count'], $by['not_ok']['href']]);
        self::assertSame(['2 orders', '/ui/purchasing/orders?state=approved'], [$by['not_sent']['count'], $by['not_sent']['href']]);
        self::assertSame(['1 draft', '/ui/purchasing/orders?state=draft'], [$by['drafts']['count'], $by['drafts']['href']], 'this buyer\'s own draft only');
        self::assertSame(['1 draft', '/ui/purchasing/suppliers?status=draft'], [$by['supplier_drafts']['count'], $by['supplier_drafts']['href']]);
        self::assertSame(['!', '!'], [$by['company_wait']['big'], $by['old_sales']['big']], 'notes come after the jobs, without a number');
        self::assertSame([Words::HOME['waiting'], Words::HOME['out_of_date']], [$by['company_wait']['count'], $by['old_sales']['count']]);
        self::assertMatchesRegularExpression('/^Sales data is \d+ days old$/', $by['old_sales']['title']);
        self::assertSame('/ui/purchasing/sales-history', $by['old_sales']['href']);
        self::assertStringContainsString(Words::HOME['notes'], $home->text());
        foreach ($cards as $c) {
            self::assertSame(200, self::open($web, (string) $c['href'])->status, "{$c['key']}: {$c['href']}");
        }
        self::assertSame(['units', 'orders', 'products'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('data-tile'),
            iterator_to_array((new \DOMXPath($home->dom()))->query('//main//section[contains(@class, "hm-figs")]/a'))), 'no Goods In for a buyer: three figures');
    }

    /** Nothing waiting: the empty state says so; its counts are built (pack A2a), so nothing comes later; the sidebar's Counts. */
    public function testNothingWaiting(): void
    {
        $web = $this->signIn($this->uiUser('warehouse'));
        $home = $web->get('/ui/');
        self::assertSame(200, $home->status, $home->describe());
        self::assertSame([], self::cardsOf($home));
        self::assertStringContainsString(Words::HOME['nothing'] . ' ' . Words::HOME['nothing_text'], $home->text());
        self::assertStringNotContainsString(Words::UI['coming_later'], $home->text(), 'stock counts are built: nothing is coming later for the warehouse');
        self::assertSame(['href' => '/ui/stock/counts', 'count' => 0], self::nav($home)['Counts'], 'Every day: Counts');
        self::assertSame(0, (new \DOMXPath($home->dom()))->query('//main//*[contains(@class, "btn") and contains(@class, "primary")]')->length,
            'nothing waiting: no main button');
    }

    /** "Good morning" by the time of day in London, with the first word of the name; one row's number and what a screen reader hears. */
    public function testTheGreetingAndARow(): void
    {
        $at = static fn (string $utc): \DateTimeImmutable => new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        self::assertSame('Good morning, Bea', DashboardController::greeting('Bea Smith', $at('2026-10-29 10:59:00')), '10:59 in London (GMT)');
        self::assertSame('Good afternoon, Bea', DashboardController::greeting(' Bea  Smith ', $at('2026-10-29 12:00:00')));
        self::assertSame('Good evening, Bea', DashboardController::greeting('Bea', $at('2026-10-29 18:00:00')));
        self::assertSame('Good afternoon, Bea', DashboardController::greeting('Bea', $at('2026-07-01 16:59:00')), '17:59 in London (BST)');
        self::assertSame('Good evening, Bea', DashboardController::greeting('Bea', $at('2026-07-01 17:00:00')), '18:00 in London (BST)');
        self::assertSame(['1,250', '1,250 to confirm'], array_values(array_intersect_key(DashboardController::row(['key' => 'strong', 'count' => 1250,
            'unit' => 'to confirm']), ['big' => 0, 'said' => 0])));
        self::assertSame(['3/20', '3 of 20 checked'], array_values(array_intersect_key(DashboardController::row(['key' => 'spot_check', 'count' => 3,
            'unit' => 'of 20 checked', 'progress' => [3, 20]]), ['big' => 0, 'said' => 0])));
        self::assertSame(['!', 'Needs you'], array_values(array_intersect_key(DashboardController::row(['key' => 'reviewers', 'chip' => 'Needs you']), ['big' => 0, 'said' => 0])));
    }
}
