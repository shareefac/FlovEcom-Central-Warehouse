<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Mapping\ArrivalCheck;
use CW\Mapping\OneAtATime;
use CW\Tests\Support\ArrivalFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The lists' Check & match fold (docs/decisions.md CM1-CM4; the owner's request of 20 Oct 2026 on Store Products: "why can't we add a
 * match button here so no need to go inside the page"), through the real /ui kernel as cw_app:
 *  - only a row found by the check on arrival with a Likely suggestion has the fold (To review's Likely list, Store Products), for
 *    someone who may decide; every other row, and the variant's page, are as they were;
 *  - the fold's fragment shows the variant against its suggestion; only a CLEAN one (no warning, the barcode not different, 1 sale = 1,
 *    not held back, not in a spot check, the clues not disagreeing, nothing waiting for a second OK) has the two answers, with what the
 *    variant page's forms carry (`seen` among them) and the way back to the list at the row;
 *  - its Yes saves the match and goes back to the list at the row after it; its No records "not this product";
 *  - a fold drawn before the variant was held back, its suggestion replaced or re-banded, or its item's barcodes changed is refused
 *    with the variant page's words, and nothing is saved.
 */
final class CheckMatchScreenTest extends KernelUiTestCase
{
    use KeyFixtures;
    use ArrivalFixtures;

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

    /**
     * The Likely list of store `alt`: a judged match, then two the check on arrival found: a clean one and one with a warning (no
     * strength stated). Best sellers first, in that order.
     *
     * @return array{judged: int, clean: int, warned: int, item: array{sku: int, listing: int, variant: string, code: string}, judged_sku: int}
     */
    private function arrivals(): array
    {
        $this->keySetup();
        foreach (['mapping.arrival_stores' => '"alt"', 'mapping.arrival_main_store' => '"vpg"', 'mapping.arrival_bulk_confirm' => 'false'] as $key => $json) {
            $this->setting($key, $json);
        }
        $this->arrivalMain = $this->vpgSite;
        $this->arrival = new ArrivalCheck(self::$db, $this->ds);
        $judgedSku = $this->item('legacy');
        $judged = $this->queued($this->altSite, 'J1', 'Check', $judgedSku, ['product_title' => 'Judged one', 'units_365d' => 90]);
        $title = 'RandM Cool Mint 10ml Nic Salts Eliquid';
        $item = $this->mainItem($title, '20mg', [self::gtin(1)]);
        $clean = $this->arrives($this->altSite, $title, '20mg', [self::gtin(1)]);
        $this->mainItem($title, '10mg', [self::gtin(2)]);
        $warned = $this->arrives($this->altSite, $title, '', [self::gtin(2)]); // no strength stated: a warning
        self::assertSame(2, $this->arrival->run(['apply' => true])['per_store']['alt']['match']);
        self::assertSame(['Check', 'Check'], [self::arrivalOpen($clean)['band'], self::arrivalOpen($warned)['band']]);
        return ['judged' => $judged, 'clean' => $clean, 'warned' => $warned, 'item' => $item, 'judged_sku' => $judgedSku];
    }

    /** The fold's address on a list's row, or null when the row has none. */
    private static function fold(UiResponse $page, int $listing): ?string
    {
        $v = (new \DOMXPath($page->dom()))->query('//tr[@id="l-' . $listing . '"]//details[@data-check-fold]/@data-check');
        return $v->length === 0 ? null : (string) $v->item(0)?->nodeValue;
    }

    /** GET an address as a page writes it (a path with its query). */
    private static function open(\CW\Tests\Support\KernelBrowser $web, string $url): UiResponse
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return $web->get((string) parse_url($url, PHP_URL_PATH), $query);
    }

    /** The fragment's root. */
    private static function root(UiResponse $frag): \DOMElement
    {
        $root = (new \DOMXPath($frag->dom()))->query('//*[@data-lc-fragment]');
        self::assertSame(1, $root->length, $frag->describe());
        $el = $root->item(0);
        self::assertInstanceOf(\DOMElement::class, $el);
        return $el;
    }

    /** @return array<string, string>|null the fields of the fragment's "yes" or "no" form, null when it has none */
    private static function answer(UiResponse $frag, string $which): ?array
    {
        $xp = new \DOMXPath($frag->dom());
        $form = $xp->query('//form[@data-answer="' . $which . '"]');
        if ($form->length === 0) {
            return null;
        }
        $out = [];
        foreach ($xp->query('.//input', $form->item(0)) as $in) {
            /** @var \DOMElement $in */
            $out[$in->getAttribute('name')] = $in->getAttribute('value');
        }
        return $out;
    }

    /** @return list<string> the reasons the fragment gives for no answer here */
    private static function blocks(UiResponse $frag): array
    {
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent), iterator_to_array((new \DOMXPath($frag->dom()))->query('//div[contains(@class, "lc-not")]//li')));
    }

    private function proposalOf(int $listing): int
    {
        return (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listing]);
    }

    /** A spot check that holds this variant's suggestion: in its population, or as one of its matches ($position). @return int its id */
    private function spotCheck(int $listing, ?int $position, string $name): int
    {
        $lead = (int) $this->staffUser('mapping_lead')->staffUserId;
        $sample = self::$db->insert("INSERT INTO key_sample (name, seed, method, band_version, sample_size, required_size, population, strata, created_by, actor) "
            . "VALUES (?, 1, 'm', 'b2.1', 20, 20, 100, JSON_ARRAY(), ?, 'staff:1')", [$name, $lead]);
        self::$db->exec("INSERT INTO key_sample_member (sample_id, proposal_id, listing_id, stratum, ai_confidence, position) VALUES (?, ?, ?, 'a', 96, ?)",
            [$sample, $this->proposalOf($listing), $listing, $position]);
        return $sample;
    }

    /** Holds the variant back (a hold in force, M30). @return array{0: int, 1: int} the hold's id and its spot check */
    private function hold(int $listing): array
    {
        $sample = $this->spotCheck($listing, null, 'sc-hold-' . $listing . '-' . random_int(1, 99999));
        $hold = self::$db->insert("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, reason, staff_user_id, actor) VALUES (?, ?, ?, 'hold', 'looked doubtful', ?, 'staff:1')",
            [$sample, $this->proposalOf($listing), $listing, $this->staffUser('mapping_lead')->staffUserId]);
        return [$hold, $sample];
    }

    private function releaseHold(int $listing, array $hold): void
    {
        self::$db->exec("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, released_hold_id, reason, staff_user_id, actor) VALUES (?, ?, ?, 'release', ?, 'fine', ?, 'staff:1')",
            [$hold[1], $this->proposalOf($listing), $listing, $hold[0], $this->staffUser('mapping_lead')->staffUserId]);
    }

    public function testTheFoldIsOnlyOnFoundOnArrivalLikelyRowsAndTheOtherRowsAndPagesAreAsTheyWere(): void
    {
        $f = $this->arrivals();
        $strong = $this->queued($this->altSite, 'K1', 'Key', $this->item('legacy'), ['product_title' => 'Strong one']);
        $web = $this->signIn($this->uiUser('mapper'));

        // To review › Likely matches: the two found on arrival have the fold (whether one is clean is asked when it opens), the judged
        // one has none; each keeps its line, its Open and no tick box.
        $list = $web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt']);
        self::assertSame(200, $list->status, $list->describe());
        self::assertNull(self::fold($list, $f['judged']));
        self::assertSame('/ui/review/listing/' . $f['clean'] . '/check?queue=Check&channel=alt&nx=' . $f['warned'], self::fold($list, $f['clean']));
        self::assertSame('/ui/review/listing/' . $f['warned'] . '/check?queue=Check&channel=alt', self::fold($list, $f['warned']), 'the last row: no row after it');
        $xp = new \DOMXPath($list->dom());
        foreach ([$f['clean'], $f['warned']] as $id) {
            $row = '//tr[@id="l-' . $id . '"]';
            self::assertSame(Words::ONE_AT_A_TIME['on_arrival'], trim((string) $xp->evaluate('string(' . $row . '//span[contains(@class, "no-bulk")])')));
            self::assertSame(Words::QUEUE['open'], trim((string) $xp->evaluate('string(' . $row . '/td[@class="c-next"]/a)')), 'Open stays');
            self::assertSame('/ui/review/listing/' . $id . '?queue=Check&channel=alt', (string) $xp->evaluate('string(' . $row . '//details[@data-check-fold]//p[@class="lc-page"]/a/@href)'),
                'without the script the fold leads to the variant\'s page');
            self::assertSame(Words::CHECK['fold'], trim((string) $xp->evaluate('string(' . $row . '//details[@data-check-fold]/summary)')));
            self::assertSame(0, $xp->query($row . '//input[@type="checkbox"]')->length);
        }
        self::assertSame(1, $xp->query('//tr[@id="l-' . $f['judged'] . '"]//input[@type="checkbox"][@data-bulk-row]')->length, 'the judged one keeps its tick box');
        // The fold reads nothing of the variants when the list is drawn: the list's address is all it needs.
        self::assertStringNotContainsString('data-lc-fragment', $list->body);
        // The strong matches' list has no fold (no row found on arrival).
        $key = $web->get('/ui/review', ['queue' => 'Key', 'channel' => 'alt']);
        self::assertSame(0, (new \DOMXPath($key->dom()))->query('//details[@data-check-fold]')->length);
        self::assertSame(1, (new \DOMXPath($key->dom()))->query('//tr[@id="l-' . $strong . '"]')->length);

        // Store Products: the same two rows, the list as the person has it in the address.
        $store = $web->get('/ui/review/store', ['channel' => 'alt', 'state' => 'suggested', 'sort' => 'sold_365']);
        self::assertSame(200, $store->status, $store->describe());
        self::assertNull(self::fold($store, $f['judged']));
        self::assertNull(self::fold($store, $strong));
        self::assertStringStartsWith('/ui/review/listing/' . $f['clean'] . '/check?via=store&channel=alt&state=suggested&sort=sold_365', (string) self::fold($store, $f['clean']));
        self::assertNotNull(self::fold($store, $f['warned']));
        self::assertSame(['/ui/review/listing/' . $f['clean'], Words::QUEUE['open']], [(string) (new \DOMXPath($store->dom()))->evaluate('string(//tr[@id="l-' . $f['clean'] . '"]/td[@class="c-next"]/a/@href)'),
            trim((string) (new \DOMXPath($store->dom()))->evaluate('string(//tr[@id="l-' . $f['clean'] . '"]/td[@class="c-next"]/a)'))], 'Open is as it was');

        // New Variants draws the same rows with the same line, and no fold (not this change).
        $new = $web->get('/ui/review/new-variants', ['channel' => 'alt', 'state' => 'all', 'period' => 'all']);
        self::assertSame(200, $new->status, $new->describe());
        self::assertSame(0, (new \DOMXPath($new->dom()))->query('//details[@data-check-fold]')->length);

        // Someone who can only look: no fold, and the route itself is not theirs.
        $look = $this->signIn($this->uiUser('viewer'));
        foreach ([$look->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt']), $look->get('/ui/review/store', ['channel' => 'alt'])] as $page) {
            self::assertSame(200, $page->status, $page->describe());
            self::assertSame(0, (new \DOMXPath($page->dom()))->query('//details[@data-check-fold]')->length);
        }
        self::assertSame(403, $look->get('/ui/review/listing/' . $f['clean'] . '/check', ['queue' => 'Check', 'channel' => 'alt'])->status);

        // The variant's page is as it was: its own forms, no fold, and its answers carry what it shows.
        $own = $web->get('/ui/review/listing/' . $f['clean'], ['queue' => 'Check', 'channel' => 'alt']);
        self::assertSame(200, $own->status, $own->describe());
        self::assertStringNotContainsString('data-check-fold', $own->body);
        self::assertSame(OneAtATime::ARRIVAL . ':' . $this->proposalOf($f['clean']), $own->form('/ui/review/listing/' . $f['clean'] . '/decide')['seen'] ?? null);
        self::assertArrayNotHasKey('from', $own->form('/ui/review/listing/' . $f['clean'] . '/decide'));
    }

    public function testACleanRowsFragmentShowsTheFactsAndHasBothAnswersWithWhatTheDecideRouteNeeds(): void
    {
        $f = $this->arrivals();
        $web = $this->signIn($this->uiUser('mapper'));
        $url = (string) self::fold($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt']), $f['clean']);
        $frag = self::open($web, $url);
        self::assertSame(200, $frag->status, $frag->describe());
        self::assertStringNotContainsString('<nav', $frag->body, 'a fragment: no layout');
        $root = self::root($frag);
        $sku = (string) $f['item']['sku'];
        self::assertSame([(string) $f['clean'], $sku, 'alt', 'yes'], [$root->getAttribute('data-listing-id'), $root->getAttribute('data-sku-id'), $root->getAttribute('data-channel'),
            $root->getAttribute('data-clean')]);
        // The facts, side by side, with the variant page's own states and lines.
        $xp = new \DOMXPath($frag->dom());
        self::assertSame('same', (string) $xp->evaluate('string(//div[@data-field="barcodes"]/@data-state)'));
        self::assertStringContainsString(self::gtin(1), (string) $xp->evaluate('string(//div[@data-field="barcodes"])'));
        self::assertStringContainsString($f['item']['code'], (string) $xp->evaluate('string(//div[@data-field="name"])'));
        self::assertStringContainsString('RandM Cool Mint 10ml Nic Salts Eliquid', (string) $xp->evaluate('string(//div[@data-field="name"])'));
        self::assertSame(1, $xp->query('//div[@data-field="price"]')->length);
        self::assertSame(1, $xp->query('//div[@data-field="units_365d"]')->length);
        self::assertStringContainsString(Words::BAND_HELP_RULES['Check'], $frag->text(), 'what the clue is, as on the variant\'s page');
        self::assertStringContainsString(Words::CHECK['clean'], $frag->text());
        self::assertSame([], self::blocks($frag));
        self::assertSame(0, $xp->query('//ul[contains(@class, "watch")]')->length, 'no Watch out line');

        $common = ['csrf' => $this->token($web), 'sku_id' => $sku, 'expected_map_version' => (string) $this->version($f['clean']),
            'proposal_id' => (string) $this->proposalOf($f['clean']), 'seen' => OneAtATime::ARRIVAL . ':' . $this->proposalOf($f['clean']),
            'queue' => 'Check', 'channel' => 'alt', 'nx' => (string) $f['warned'], 'from' => 'check'];
        $yes = self::answer($frag, 'yes');
        $no = self::answer($frag, 'no');
        self::assertEquals(['action' => 'link', 'units_per_item' => '1'] + $common, $yes);
        self::assertEquals(['action' => 'reject'] + $common, $no);
        foreach (['yes', 'no'] as $which) {
            $form = $xp->query('//form[@data-answer="' . $which . '"]')->item(0);
            self::assertInstanceOf(\DOMElement::class, $form);
            self::assertSame(['post', '/ui/review/listing/' . $f['clean'] . '/decide', (string) $f['clean'], $sku, 'alt'], [$form->getAttribute('method'), $form->getAttribute('action'),
                $form->getAttribute('data-listing-id'), $form->getAttribute('data-sku-id'), $form->getAttribute('data-channel')]);
            $button = $xp->query('.//button[@type="submit"]', $form)->item(0);
            self::assertInstanceOf(\DOMElement::class, $button);
            self::assertSame([(string) $f['clean'], $sku, 'alt'], [$button->getAttribute('data-listing-id'), $button->getAttribute('data-sku-id'), $button->getAttribute('data-channel')]);
        }
        self::assertSame(Words::say('CHECK', 'yes', $f['item']['code'], Words::saleUses(1)), trim((string) $xp->evaluate('string(//form[@data-answer="yes"]//button)')));
        self::assertSame(Words::CHECK['no'], trim((string) $xp->evaluate('string(//form[@data-answer="no"]//button)')));
        self::assertSame('/ui/review/listing/' . $f['clean'] . '?queue=Check&channel=alt', (string) $xp->evaluate('string(//p[@class="lc-open"]/a/@href)'));
    }

    public function testYesSavesTheMatchAndGoesBackToTheListAtTheRowAndNoRecordsTheReject(): void
    {
        $f = $this->arrivals();
        $web = $this->signIn($this->uiUser('mapper'));
        $decisions = $this->decisions();
        // Yes, from To review's Likely list: matched with 1 sale = 1, back to the same list at the row after it, with the usual notice.
        $frag = self::open($web, (string) self::fold($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt']), $f['clean']));
        $r = $web->post('/ui/review/listing/' . $f['clean'] . '/decide', (array) self::answer($frag, 'yes'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/review?queue=Check&channel=alt&notice=decided_link&prev=' . $f['clean'] . '#l-' . $f['warned'], $r->location());
        self::assertSame(['mapped', $f['item']['sku'], 1], [$this->link($f['clean'])['status'], $this->link($f['clean'])['sku_id'], $this->link($f['clean'])['units_per_item']]);
        self::assertSame(['link', 'applied', null], array_values((array) self::$db->one('SELECT action, state, bulk_batch_id FROM match_decision WHERE listing_id = ? ORDER BY id DESC LIMIT 1',
            [$f['clean']])));
        self::assertSame($decisions + 1, $this->decisions());
        $back = $web->follow($r);
        self::assertSame(200, $back->status, $back->describe());
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_link', Words::quoted('RandM Cool Mint 10ml Nic Salts Eliquid 20mg', ''), $f['item']['code']), $back->text());
        self::assertSame(1, (new \DOMXPath($back->dom()))->query('//tr[@id="l-' . $f['warned'] . '"]')->length, 'the row after it is there to go on with');

        // No, from Store Products (another arrival, clean): "not this product" recorded, back to the list as the person had it.
        $title = 'Elf Bar Cherry 10ml Nic Salt';
        $item = $this->mainItem($title, '20mg', [self::gtin(5)]);
        $other = $this->arrives($this->altSite, $title, '20mg', [self::gtin(5)]);
        $this->arrival->run(['apply' => true]);
        $list = $web->get('/ui/review/store', ['channel' => 'alt', 'sort' => 'sold_365', 'q' => 'Cherry']);
        $url = self::fold($list, $other);
        self::assertSame('/ui/review/listing/' . $other . '/check?via=store&channel=alt&sort=sold_365&lq=Cherry', $url);
        $frag = self::open($web, (string) $url);
        self::assertSame('yes', self::root($frag)->getAttribute('data-clean'));
        $no = (array) self::answer($frag, 'no');
        self::assertSame(['store', 'alt', 'sold_365', 'Cherry', 'check'], [$no['via'] ?? null, $no['channel'] ?? null, $no['sort'] ?? null, $no['lq'] ?? null, $no['from'] ?? null]);
        $r = $web->post('/ui/review/listing/' . $other . '/decide', $no);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/review/store?channel=alt&sort=sold_365&q=Cherry&notice=decided_reject&prev=' . $other . '#l-' . $other, $r->location());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject WHERE listing_id = ? AND sku_id = ?', [$other, $item['sku']]));
        self::assertSame('suggested', $this->link($other)['status'], 'still waiting for its match');
        // Its fold now says why it is not answered there: someone said no to this product.
        $again = self::open($web, (string) $url);
        self::assertSame([null, null], [self::answer($again, 'yes'), self::answer($again, 'no')]);
        self::assertSame([Words::CHECK['rejected_before']], self::blocks($again));
    }

    public function testANotCleanRowGetsTheFactsAndWhyButNoAnswer(): void
    {
        $f = $this->arrivals();
        $web = $this->signIn($this->uiUser('mapper'));
        $check = fn (int $id): UiResponse => $web->get('/ui/review/listing/' . $id . '/check', ['queue' => 'Check', 'channel' => 'alt']);
        $noAnswer = function (UiResponse $frag, array $why, string $what): void {
            self::assertSame(200, $frag->status, $frag->describe());
            self::assertSame('no', self::root($frag)->getAttribute('data-clean'), $what);
            self::assertSame([null, null], [self::answer($frag, 'yes'), self::answer($frag, 'no')], $what);
            self::assertStringNotContainsString('name="seen"', $frag->body, $what);
            self::assertSame($why, self::blocks($frag), $what);
            self::assertSame(Words::CHECK['open'], trim((string) (new \DOMXPath($frag->dom()))->evaluate('string(//p[@class="lc-open"]/a)')), $what);
        };
        // A "Watch out" line (the strength is not stated): shown, and no answer.
        $warned = $check($f['warned']);
        $noAnswer($warned, [Words::CHECK['warning']], 'a warning');
        self::assertStringContainsString(Words::FLAG['strength_missing'], $warned->text(), 'the warning itself, as the variant\'s page says it');

        // The barcodes are different (the website's barcode changed; nothing else did).
        self::$db->exec('UPDATE listing_profile SET barcodes = JSON_ARRAY(?) WHERE listing_id = ?', [self::gtin(9), $f['clean']]);
        $frag = $check($f['clean']);
        $noAnswer($frag, [Words::CHECK['barcode']], 'different barcodes');
        self::assertSame('differs', (string) (new \DOMXPath($frag->dom()))->evaluate('string(//div[@data-field="barcodes"]/@data-state)'));
        // No barcode on one side is not "different": unknown is fine.
        self::$db->exec('UPDATE listing_profile SET barcodes = NULL WHERE listing_id = ?', [$f['clean']]);
        $frag = $check($f['clean']);
        self::assertSame(['yes', 'unknown'], [self::root($frag)->getAttribute('data-clean'), (string) (new \DOMXPath($frag->dom()))->evaluate('string(//div[@data-field="barcodes"]/@data-state)')]);
        self::$db->exec('UPDATE listing_profile SET barcodes = JSON_ARRAY(?) WHERE listing_id = ?', [self::gtin(1), $f['clean']]);

        // The suggestion says more than 1 per sale.
        self::$db->exec('UPDATE match_proposal SET ai_units_per_item = 2 WHERE id = ?', [$this->proposalOf($f['clean'])]);
        $noAnswer($check($f['clean']), [Words::say('CHECK', 'units', Words::saleUses(2))], '2 per sale');
        self::$db->exec('UPDATE match_proposal SET ai_units_per_item = NULL WHERE id = ?', [$this->proposalOf($f['clean'])]);
        self::assertSame('yes', self::root($check($f['clean']))->getAttribute('data-clean'), 'clean again');

        // A match the check wrote with a warning the variant page has no line for (its item had nothing to compare it with): the list
        // row's own "Watch out" word is enough.
        self::$db->exec("UPDATE match_proposal SET flags = JSON_ARRAY('rules_only', 'arrival_warned') WHERE id = ?", [$this->proposalOf($f['clean'])]);
        $frag = $check($f['clean']);
        $noAnswer($frag, [Words::CHECK['warning']], 'warned, nothing else');
        self::assertStringContainsString(Words::FLAG['arrival_warned'], $frag->text());
        self::$db->exec("UPDATE match_proposal SET flags = JSON_ARRAY('rules_only') WHERE id = ?", [$this->proposalOf($f['clean'])]);

        // A row that is not found on arrival (the judged one), asked directly: no answer.
        $noAnswer($check($f['judged']), [Words::CHECK['not_arrival']], 'not found on arrival');
    }

    public function testHeldSpotCheckConflictAndWaitingRowsGetNoAnswer(): void
    {
        $f = $this->arrivals();
        $web = $this->signIn($this->uiUser('mapper'));
        $lead = $this->signIn($this->uiUser('mapping_lead'));
        $check = fn (int $id, $who = null): UiResponse => ($who ?? $web)->get('/ui/review/listing/' . $id . '/check', ['queue' => 'Check', 'channel' => 'alt']);
        $none = function (UiResponse $frag, array $why, string $what): void {
            self::assertSame(200, $frag->status, $frag->describe());
            self::assertSame([null, null], [self::answer($frag, 'yes'), self::answer($frag, 'no')], $what);
            self::assertSame($why, self::blocks($frag), $what);
        };
        $id = $f['clean'];

        // One of a spot check's matches: decided by its owner, one at a time, on its own page; the list has no fold for it either.
        $sample = $this->spotCheck($id, 1, 'sc-match');
        $none($check($id), [Words::CHECK['spot']], 'in a spot check');
        self::assertNull(self::fold($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt']), $id));
        self::$db->exec('UPDATE key_sample_member SET position = NULL WHERE sample_id = ?', [$sample]);
        self::assertNotNull(self::fold($web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt']), $id));

        // Held back: the same.
        $hold = $this->hold($id);
        $none($check($id), [Words::CHECK['held']], 'held back');
        self::assertNull(self::fold($web->get('/ui/review/store', ['channel' => 'alt']), $id));
        $this->releaseHold($id, $hold);
        self::assertSame('yes', self::root($check($id))->getAttribute('data-clean'));

        // The clues disagree (Conflict): a matching lead's, and never from the list.
        self::$db->exec("UPDATE match_proposal SET band = 'Conflict' WHERE id = ?", [$this->proposalOf($id)]);
        $none($check($id), [Words::CHECK['conflict']], 'Conflict, a Matcher');
        $none($check($id, $lead), [Words::CHECK['conflict']], 'Conflict, a matching lead');
        self::$db->exec("UPDATE match_proposal SET band = 'Check' WHERE id = ?", [$this->proposalOf($id)]);
        // Another strength: not offered either.
        self::$db->exec("UPDATE match_proposal SET band = 'Key' WHERE id = ?", [$this->proposalOf($id)]);
        $none($check($id), [Words::say('CHECK', 'not_likely', Words::BAND['Key'])], 'a strong match');
        self::$db->exec("UPDATE match_proposal SET band = 'Check' WHERE id = ?", [$this->proposalOf($id)]);

        // A decision waiting for a second OK (the variant's own page, 2 per sale).
        $own = $web->get('/ui/review/listing/' . $id, ['queue' => 'Check', 'channel' => 'alt']);
        $r = $web->post('/ui/review/listing/' . $id . '/decide', ['action' => 'link', 'units_per_item' => '2'] + $own->form('/ui/review/listing/' . $id . '/decide'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('pending_second', self::$db->value('SELECT state FROM match_decision WHERE listing_id = ? ORDER BY id DESC LIMIT 1', [$id]));
        $none($check($id), [Words::CHECK['pending']], 'waiting for a second OK');
    }

    public function testAStaleFoldIsRefusedWithTheVariantPagesWordsAndNothingIsSaved(): void
    {
        $f = $this->arrivals();
        $id = $f['clean'];
        $web = $this->signIn($this->uiUser('mapper'));
        $leadWeb = $this->signIn($this->uiUser('mapping_lead'));
        $draw = fn ($who = null): array => (array) self::answer(($who ?? $web)->get('/ui/review/listing/' . $id . '/check', ['queue' => 'Check', 'channel' => 'alt']), 'yes');
        $refused = function (UiResponse $r, int $status, string $words, string $what, bool $answer = true) use ($id): void {
            self::assertSame($status, $r->status, $what . ': ' . $r->describe());
            self::assertStringContainsString($words, $r->text(), $what);
            self::assertSame($answer, $r->hasForm('/ui/review/listing/' . $id . '/decide'), $what . ': the variant\'s page, to answer there if this person may');
            self::assertStringContainsString(Words::LISTING['on_website'], $r->text(), $what . ': the variant\'s page');
            self::assertSame('suggested', $this->link($id)['status'], $what . ': nothing was saved');
        };
        $decisions = $this->decisions();

        // Held back after the fold was drawn: the decide guard (DG2) refuses it.
        $yes = $draw();
        $hold = $this->hold($id);
        $refused($web->post('/ui/review/listing/' . $id . '/decide', $yes), 409, Words::MATCH_ERROR['one_at_a_time'], 'held meanwhile');
        $this->releaseHold($id, $hold);

        // Made one of a spot check's matches after it was drawn: its owner's now (DG1), and anyone else is refused before anything else.
        $yes = $draw();
        $sample = $this->spotCheck($id, 1, 'sc-late');
        $refused($web->post('/ui/review/listing/' . $id . '/decide', $yes), 403, explode('%s', Words::MATCH_ERROR['spot_check_other'])[1], 'a spot check meanwhile', false);
        self::$db->exec('UPDATE key_sample_member SET position = NULL WHERE sample_id = ?', [$sample]);

        // The suggestion was replaced: refused as the variant's page refuses it.
        $yes = $draw();
        $this->propose($id, 'Check', $f['item']['sku'], [], true, 'another-run');
        $refused($web->post('/ui/review/listing/' . $id . '/decide', $yes), 409, Words::MATCH_ERROR['proposal_closed'], 'a newer suggestion');
        self::assertSame($decisions, $this->decisions());
    }

    public function testTheFoldsQuestionIsAskedAgainUnderTheLock(): void
    {
        $f = $this->arrivals();
        $id = $f['clean'];
        $web = $this->signIn($this->uiUser('mapper'));
        $leadWeb = $this->signIn($this->uiUser('mapping_lead'));
        $draw = fn ($who): array => (array) self::answer($who->get('/ui/review/listing/' . $id . '/check', ['queue' => 'Check', 'channel' => 'alt']), 'yes');
        $refused = function (UiResponse $r, int $status, string $words, string $what, bool $answer = true) use ($id): void {
            self::assertSame($status, $r->status, $what . ': ' . $r->describe());
            self::assertStringContainsString($words, $r->text(), $what);
            self::assertSame($answer, $r->hasForm('/ui/review/listing/' . $id . '/decide'), $what . ': the variant\'s page, to answer there if this person may');
            self::assertStringContainsString(Words::LISTING['on_website'], $r->text(), $what . ': the variant\'s page');
            self::assertSame('suggested', $this->link($id)['status'], $what . ': nothing was saved');
        };
        $decisions = $this->decisions();

        // Re-banded in place to Conflict after the fold was drawn: a Matcher is refused as anywhere (a lead's), and a matching lead by
        // the fold's own question (no longer a likely match).
        $yesMapper = $draw($web);
        $yesLead = $draw($leadWeb);
        self::$db->exec("UPDATE match_proposal SET band = 'Conflict' WHERE id = ?", [$this->proposalOf($id)]);
        $refused($web->post('/ui/review/listing/' . $id . '/decide', $yesMapper), 403, Words::MATCH_ERROR['lead_required'], 'Conflict, a Matcher', false);
        $refused($leadWeb->post('/ui/review/listing/' . $id . '/decide', $yesLead), 409, Words::MATCH_ERROR['check_changed'], 'Conflict, a matching lead');
        self::$db->exec("UPDATE match_proposal SET band = 'Check' WHERE id = ?", [$this->proposalOf($id)]);

        // The website's barcode changed after the fold was drawn (nothing the decide guard pins): refused, its words.
        $yes = $draw($web);
        self::$db->exec('UPDATE listing_profile SET barcodes = JSON_ARRAY(?) WHERE listing_id = ?', [self::gtin(9), $id]);
        $refused($web->post('/ui/review/listing/' . $id . '/decide', $yes), 409, Words::MATCH_ERROR['check_changed'], 'the barcodes differ now');
        $no = (array) self::answer($web->get('/ui/review/listing/' . $id . '/check', ['queue' => 'Check', 'channel' => 'alt']), 'no');
        self::assertSame([], $no, 'and the fold offers no answer now');
        self::$db->exec('UPDATE listing_profile SET barcodes = JSON_ARRAY(?) WHERE listing_id = ?', [self::gtin(1), $id]);

        // A hand-made form from the fold that is not one of its two answers: another item, another number per sale, another action.
        $yes = $draw($web);
        $refused($web->post('/ui/review/listing/' . $id . '/decide', ['units_per_item' => '3'] + $yes), 409, Words::MATCH_ERROR['check_changed'], '3 per sale');
        $refused($web->post('/ui/review/listing/' . $id . '/decide', ['sku_id' => (string) $f['judged_sku']] + $yes), 409, Words::MATCH_ERROR['check_changed'], 'another item');
        $refused($web->post('/ui/review/listing/' . $id . '/decide', ['action' => 'ignore', 'reason' => 'not ours'] + $yes), 409, Words::MATCH_ERROR['check_changed'], 'ignore');
        self::assertSame($decisions, $this->decisions());

        // The fold as drawn, sent as drawn: saved.
        $r = $web->post('/ui/review/listing/' . $id . '/decide', $yes);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('mapped', $this->link($id)['status']);
    }

    public function testFromStoreProductsTheVariantsPageLeadsBackToTheList(): void
    {
        $f = $this->arrivals();
        $web = $this->signIn($this->uiUser('mapper'));
        $frag = self::open($web, (string) self::fold($web->get('/ui/review/store', ['channel' => 'alt', 'state' => 'suggested', 'page' => '1']), $f['warned']));
        $open = (string) (new \DOMXPath($frag->dom()))->evaluate('string(//p[@class="lc-open"]/a/@href)');
        self::assertSame('/ui/review/listing/' . $f['warned'] . '?via=store&channel=alt&state=suggested', $open);
        $page = self::open($web, $open);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame('/ui/review/store?channel=alt&state=suggested#l-' . $f['warned'], (string) (new \DOMXPath($page->dom()))->evaluate('string(//p[@class="crumbs"]/a[1]/@href)'));
        // Its own answer goes back to the list at the row (it may have left the filter: the list says what was done).
        $form = $page->form('/ui/review/listing/' . $f['warned'] . '/decide');
        self::assertSame(['store', 'alt', 'suggested'], [$form['via'] ?? null, $form['channel'] ?? null, $form['state'] ?? null]);
        $r = $web->post('/ui/review/listing/' . $f['warned'] . '/decide', ['action' => 'link', 'units_per_item' => '1'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/review/store?channel=alt&state=suggested&notice=decided_link&prev=' . $f['warned'] . '#l-' . $f['warned'], $r->location());
        // A crafted way back leads nowhere but to the list: an unknown store is no context at all.
        $odd = $web->get('/ui/review/listing/' . $f['clean'], ['via' => 'store', 'channel' => 'https://example.com']);
        self::assertSame(200, $odd->status);
        self::assertStringNotContainsString('example.com', $odd->body);
    }
}
