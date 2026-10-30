<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\BulkController;
use CW\Ui\Words;

/**
 * Store-wise review and bulk action on the screens (docs/decisions.md M46-M53, U106-U112), through the real /ui kernel as cw_app:
 * the store selector on every Mapping list and segment, the "By store" overview, Store Products and its states, the tick boxes and
 * the action bar (only the actions this person may take on this list), the second step of "Not a match" and Ignore, the refusals
 * of a strength the setting leaves out and of more rows than the setting allows, the result page, CSRF and each route's permission,
 * the one-at-a-time undo of a match on its page, and the band setting's tick boxes. Since New Variants' round 2b (M61): "Create as
 * new product" on the New products list skips what New Variants' seed skips, each with its reason on the result page.
 */
final class BulkScreensTest extends KernelUiTestCase
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

    /** Two stores named as the channel table names them, each with strong matches. @return array{alt: Caller, big: Caller} */
    private function stores(): array
    {
        $alt = $this->site('alt', 'shadow');
        $big = $this->site('vbig', 'shadow');
        self::$db->exec("UPDATE channel SET name = 'Alt Store' WHERE code = 'alt'");
        self::$db->exec("UPDATE channel SET name = 'Big Store' WHERE code = 'vbig'");
        return ['alt' => $alt, 'big' => $big];
    }

    /** The bulk form of a page with these rows ticked and this action pressed. @param list<int> $ids @return array<string, string> */
    private static function ticked(UiResponse $page, array $ids, string $action): array
    {
        $form = $page->form('/ui/review/bulk');
        self::assertNotSame([], $form, 'the page has the bulk form: ' . $page->describe());
        foreach ($ids as $id) {
            self::assertArrayHasKey('ver_' . $id, $form, "row {$id} is on the page");
            $form['pick_' . $id] = '1';
        }
        return $form + ['action' => $action];
    }

    /** @return list<string> the actions of the page's action bar */
    private static function barActions(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'), iterator_to_array($xp->query('//div[@data-bulkbar]//button[@name="action"]')));
    }

    /** @return list<array{label: string, current: bool, href: string}> the store selector */
    private static function storeSeg(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        // The store is a choice of the one Show row since the boards (docs/decisions.md W12.5): "Alt Store (3)".
        foreach ($xp->query('//form[contains(@class, "toolbar")]//select[@name="channel"]/option') as $o) {
            /** @var \DOMElement $o */
            $out[] = ['label' => trim((string) preg_replace('/\s*\(\d[\d,]*\)$/', '', trim($o->textContent))), 'current' => $o->hasAttribute('selected'),
                'value' => $o->getAttribute('value')];
        }
        return $out;
    }

    /** @return list<string> the titles of a list's rows */
    private static function titles(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent), iterator_to_array($xp->query('//tbody/tr/th/a[@class="o-name"]')));
    }

    public function testAListOffersTickBoxesAndOnlyTheActionsThisPersonMayTake(): void
    {
        ['alt' => $alt] = $this->stores();
        $sku = $this->item('legacy');
        $k1 = $this->queued($alt, 'K1', 'Key', $sku, ['product_title' => 'Strong one', 'units_365d' => 9]);
        $k2 = $this->queued($alt, 'K2', 'Key', $sku, ['product_title' => 'Strong two', 'units_365d' => 8]);
        $this->queued($alt, 'N1', 'New item', null, ['product_title' => 'New one'], ['proposed_new_item' => 1]);
        $this->queued($alt, 'C1', "Can't tell", null, ['product_title' => 'Unsure one']);
        $this->queued($alt, 'L1', 'Check', $sku, ['product_title' => 'Likely one']);
        $web = $this->signIn($this->uiUser('mapper'));

        $strong = $web->get('/ui/review', ['queue' => 'Key']);
        self::assertSame(200, $strong->status, $strong->describe());
        self::assertSame(['link', 'reject', 'ignore'], self::barActions($strong));
        $xp = new \DOMXPath($strong->dom());
        self::assertSame(2, $xp->query('//input[@type="checkbox"][@data-bulk-row]')->length);
        self::assertSame(0, $xp->query('//input[@data-bulk-row][@checked]')->length);
        self::assertSame(Words::BULK['selected_many'], $xp->evaluate('string(//div[@data-bulkbar]/@data-many)'), 'app.js takes its words from the page');
        self::assertSame((string) 100, $xp->evaluate('string(//div[@data-bulkbar]/@data-max)'));
        self::assertSame([Words::BULK_ACTION['link'], Words::BULK_ACTION['reject'], Words::BULK_ACTION['ignore']],
            array_map(static fn (\DOMNode $b): string => trim((string) $b->textContent), iterator_to_array($xp->query('//div[@data-bulkbar]//button[@name="action"]'))));
        // "Select all on this page" works without app.js: the page comes back ticked.
        $all = $web->get('/ui/review', ['queue' => 'Key', 'all' => '1']);
        self::assertSame(2, (new \DOMXPath($all->dom()))->query('//input[@data-bulk-row][@checked]')->length);
        $form = $all->form('/ui/review/bulk');
        self::assertSame(['1', '1'], [$form['pick_' . $k1], $form['pick_' . $k2]]);
        self::assertSame(['new_item', 'ignore'], self::barActions($web->get('/ui/review', ['queue' => 'New item'])));
        self::assertSame(['reject', 'ignore'], self::barActions($web->get('/ui/review', ['queue' => "Can't tell"])), 'a confirm only on the strengths of the setting');
        $this->setting('mapping.bulk_confirm_bands', '"Key"');
        self::assertSame(['reject', 'ignore'], self::barActions($web->get('/ui/review', ['queue' => 'Check'])));
        // Someone who can only look gets no tick boxes and no bar.
        $viewer = $this->signIn($this->uiUser('viewer'));
        $look = $viewer->get('/ui/review', ['queue' => 'Key']);
        self::assertSame(200, $look->status);
        self::assertFalse($look->hasForm('/ui/review/bulk'));
        self::assertSame(0, (new \DOMXPath($look->dom()))->query('//input[@data-bulk-row]')->length);
    }

    public function testConfirmingTickedRowsLinksThemAndTheResultPageSaysWhatHappenedToEach(): void
    {
        ['alt' => $alt] = $this->stores();
        $sku = $this->item('legacy');
        $a = $this->queued($alt, 'A1', 'Key', $sku, ['product_title' => 'Alpha', 'units_365d' => 9]);
        $b = $this->queued($alt, 'A2', 'Key', $sku, ['product_title' => 'Bravo', 'units_365d' => 8]);
        $c = $this->queued($alt, 'A3', 'Key', $sku, ['product_title' => 'Charlie', 'units_365d' => 7]);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review', ['queue' => 'Key', 'channel' => 'alt']);
        // Someone else decides one while the page is open.
        $this->decide($this->staffUser('mapping_lead'), 'ignore', $c, ['reason' => 'placeholder', 'proposal_id' => (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$c])]);
        $r = $web->post('/ui/review/bulk', self::ticked($page, [$a, $b, $c], 'link'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertMatchesRegularExpression('#^/ui/review/batches/[0-9]+$#', (string) $r->location());
        self::assertSame(['mapped', 'mapped', 'ignored'], [$this->link($a)['status'], $this->link($b)['status'], $this->link($c)['status']]);
        $result = $web->follow($r);
        self::assertSame(200, $result->status, $result->describe());
        $text = $result->text();
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('BULK_DONE', 'link', 2), 0, 1), $text);
        self::assertStringContainsString(BulkController::why('changed', ['status' => 'ignored']), $text, 'a stale row is skipped, never overwritten, and says why');
        self::assertStringContainsString(Words::BULK['no_undo'], $text);
        self::assertSame('Mapping', self::currentTab($result));
        $xp = new \DOMXPath($result->dom());
        self::assertSame(['/ui/review/listing/' . $c], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array($xp->query('//table[contains(@class, "bulk-skipped")]//th/a'))), 'each skipped row links to its listing');
        self::assertSame(3, $xp->query('//table[contains(@class, "bulk-rows")]/tbody/tr')->length, 'every row of the batch');
        self::assertSame('/ui/review?queue=Key&channel=alt', $xp->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'), 'back to the list it came from');
        $batch = (int) substr((string) $r->location(), strlen('/ui/review/batches/'));
        self::assertSame(['screen:' . $batch], array_values(array_unique(self::$db->column('SELECT bulk_batch_id FROM match_decision WHERE listing_id IN (?, ?) AND action = \'link\'', [$a, $b]))));
        self::assertSame(404, $web->get('/ui/review/batches/' . ($batch + 99))->status);
    }

    /**
     * One rule for every bulk "create as new" (M61): on the New products list a draft, a page heading, a website product an item
     * was already made from, one that changed on the website after the check and one whose name an item already has are skipped,
     * each with its reason on the result page; a row nothing is wrong with becomes a product. (Before round 2b this list made
     * every ticked row whose barcode was free.) Since the website state is stored (M66): a row whose state on the website the
     * warehouse does not know is skipped too, where it was made before; the rows this test makes are on sale by a stored state.
     */
    public function testCreateAsNewProductOnTheNewProductsListSkipsWhatTheSeedSkipsEachWithItsReason(): void
    {
        ['alt' => $alt] = $this->stores();
        $lead = $this->staffUser('mapping_lead');
        $new = ['proposed_new_item' => 1];
        $proposal = static fn (int $l): int => (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$l]);
        $good = $this->queued($alt, 'G1', 'New item', null, ['product_title' => 'A new kit nothing is wrong with'], $new);
        self::siteState($good);
        // Nothing is known of this one's state on the website (no stored state, no features, nothing in the suggestion's record).
        $unknown = $this->queued($alt, 'G7', 'New item', null, ['product_title' => 'Nothing known of its state'], $new);
        $draft = $this->queued($alt, 'G2', 'New item', null, ['product_title' => 'A draft', 'features' => ['variant_status' => 'Draft']], $new);
        $heading = $this->queued($alt, 'G3', 'New item', null, ['product_title' => 'A heading', 'features' => ['is_placeholder' => true]], $new);
        // Made into a product, the match undone, and a later check says "new" again.
        $again = $this->queued($alt, 'G4', 'New item', null, ['product_title' => 'Made before'], $new);
        $this->decide($lead, 'new_item', $again, ['proposal_id' => $proposal($again), 'units_per_item' => 1]);
        $firstCode = (string) self::$db->value('SELECT code FROM sku WHERE origin_listing_id = ?', [$again]);
        $this->decide($lead, 'unlink', $again, ['reason' => 'made by mistake']);
        $this->propose($again, 'New item', null, $new, true, 'later-run');
        // A name a product already has (capitals apart); and one renamed on the website after the check.
        $named = self::makeSku('A name that is taken');
        $sameName = $this->queued($alt, 'G5', 'New item', null, ['product_title' => 'a NAME that is taken'], $new);
        self::siteState($sameName);
        $renamed = $this->queued($alt, 'G6', 'New item', null, ['product_title' => 'Before the rename'], $new);
        (new \CW\Mapping\ListingIngestService(self::$db))->ingest(Caller::system('test'), (int) $alt->channelId, [['variant_id' => 'G6', 'product_title' => 'After the rename']]);
        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get('/ui/review', ['queue' => 'New item', 'channel' => 'alt']);
        self::assertSame(['new_item', 'ignore'], self::barActions($page));
        $r = $web->post('/ui/review/bulk', self::ticked($page, [$good, $draft, $heading, $again, $sameName, $renamed, $unknown], 'new_item'));
        self::assertSame(303, $r->status, $r->describe());
        $batch = (int) substr((string) $r->location(), strlen('/ui/review/batches/'));
        $codes = [];
        foreach (self::$db->all('SELECT listing_id, skip_code FROM mapping_batch_row WHERE batch_id = ? ORDER BY seq', [$batch]) as $row) {
            $codes[(int) $row['listing_id']] = $row['skip_code'];
        }
        self::assertSame([$good => null, $unknown => 'state_not_known', $draft => 'not_published', $heading => 'page_heading', $again => 'made_before', $sameName => 'name_elsewhere',
            $renamed => 'changed_since_check'], $codes);
        self::assertSame(['review', 'New item'], array_values(self::$db->one('SELECT source, band FROM mapping_batch WHERE id = ?', [$batch]) ?? []));
        self::assertSame([$skus + 1, 'mapped', 1], [(int) self::$db->value('SELECT COUNT(*) FROM sku'), $this->link($good)['status'],
            (int) self::$db->value('SELECT COUNT(*) FROM sku WHERE origin_listing_id = ?', [$again])], 'one new product; no second one from the same website product');
        $text = $web->follow($r)->text();
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('BULK_DONE', 'new_item', 1), 0, 6), $text);
        foreach (['It is not published on the website (Draft). Open it and decide.', 'It is a page heading on the website, not something that is sold. Open it and decide.',
            'The warehouse does not know if it is on sale on the website now. Open it and decide.',
            'An item was already made from this variant (' . $firstCode . '): open it and decide.',
            'An item with the same name is already in the warehouse (' . self::$db->value('SELECT code FROM sku WHERE id = ?', [$named]) . '): open it and decide.',
            'It changed on the website after the computer checked it: open it and decide.'] as $why) {
            self::assertStringContainsString($why, $text);
        }
        // One at a time, on its own page, a person may still make any of them.
        $own = $web->get('/ui/review/listing/' . $sameName);
        $ok = $web->post('/ui/review/listing/' . $sameName . '/decide', ['action' => 'new_item', 'units_per_item' => '1'] + $own->form('/ui/review/listing/' . $sameName . '/decide'));
        self::assertSame([303, 'mapped'], [$ok->status, $this->link($sameName)['status']], $ok->describe());
    }

    public function testNotAMatchAndIgnoreActOnlyOnTheSecondStepThatStatesTheCount(): void
    {
        ['alt' => $alt] = $this->stores();
        $sku = $this->item('legacy');
        $a = $this->queued($alt, 'R1', 'Check', $sku, ['product_title' => 'Rho']);
        $b = $this->queued($alt, 'R2', 'Check', $sku, ['product_title' => 'Sigma']);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review', ['queue' => 'Check']);
        $step = $web->post('/ui/review/bulk', self::ticked($page, [$a, $b], 'reject'));
        self::assertSame(200, $step->status, $step->describe());
        self::assertStringContainsString(Words::say('BULK_CONFIRM', 'reject_title', 2), $step->text());
        self::assertStringContainsString('Rho', $step->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_reject'), 'nothing is saved on the first press');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'));
        $confirm = $step->form('/ui/review/bulk');
        self::assertSame(['1', 'reject', 'Check'], [$confirm['confirm'], $confirm['action'], $confirm['queue']]);
        $done = $web->post('/ui/review/bulk', $confirm);
        self::assertSame(303, $done->status, $done->describe());
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM match_reject'));
        self::assertSame(['suggested', 'suggested'], [$this->link($a)['status'], $this->link($b)['status']], 'each stays in its list for another choice');

        // Ignore: the second step asks why, for all of them.
        $page = $web->get('/ui/review', ['queue' => 'Check']);
        $step = $web->post('/ui/review/bulk', self::ticked($page, [$a], 'ignore'));
        self::assertStringContainsString(Words::say('BULK_CONFIRM', 'ignore_title', 1), $step->text());
        $confirm = $step->form('/ui/review/bulk');
        $refused = $web->post('/ui/review/bulk', ['reason' => ''] + $confirm);
        self::assertSame(422, $refused->status, $refused->describe());
        self::assertStringContainsString(Words::BULK_ERROR['reason_required'], $refused->text());
        self::assertSame('suggested', $this->link($a)['status']);
        $ok = $web->post('/ui/review/bulk', ['reason' => 'A gift card page'] + $confirm);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame('ignored', $this->link($a)['status']);
    }

    public function testAStrengthTheSettingLeavesOutAndTooManyRowsAreRefusedWhole(): void
    {
        ['alt' => $alt] = $this->stores();
        $sku = $this->item('legacy');
        $u = $this->queued($alt, 'U1', "Can't tell", $sku, ['product_title' => 'Upsilon']);
        $k1 = $this->queued($alt, 'K1', 'Key', $sku);
        $k2 = $this->queued($alt, 'K2', 'Key', $sku);
        $web = $this->signIn($this->uiUser('mapping_lead'));
        // A confirm posted on a list that does not offer it (a crafted form) changes nothing.
        $page = $web->get('/ui/review', ['queue' => "Can't tell"]);
        $r = $web->post('/ui/review/bulk', self::ticked($page, [$u], 'link'));
        self::assertSame(403, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('BULK_ERROR', 'band_not_allowed', Words::BAND["Can't tell"]), $r->text());
        self::assertSame('suggested', $this->link($u)['status']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'));
        // The most rows at a time is the setting's.
        $this->setting('mapping.bulk_max_rows', '1');
        $page = $web->get('/ui/review', ['queue' => 'Key']);
        self::assertSame('1', (new \DOMXPath($page->dom()))->evaluate('string(//div[@data-bulkbar]/@data-max)'));
        $r = $web->post('/ui/review/bulk', self::ticked($page, [$k1, $k2], 'link'));
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::say('BULK_ERROR', 'too_many_rows', 2, 1), $r->text());
        self::assertSame(['suggested', 'suggested'], [$this->link($k1)['status'], $this->link($k2)['status']]);
        $none = $web->post('/ui/review/bulk', $page->form('/ui/review/bulk') + ['action' => 'link']);
        self::assertSame(422, $none->status);
        self::assertStringContainsString(Words::BULK_ERROR['nothing_ticked'], $none->text());
        // A row that names a number no website product or version can be (beyond its column) is refused whole as well, before
        // anything is written: it used to fail when its skip was recorded, in the middle of the batch (review finding, 10 Oct 2026).
        $before = [(int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'), (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch_row'), $this->decisions()];
        foreach ([
            'a product number beyond its column' => ['pick_4294967296' => '1', 'ver_4294967296' => '0', 'prop_4294967296' => ''] + $page->form('/ui/review/bulk') + ['action' => 'link'],
            'a version beyond its column' => ['ver_' . $k1 => '4294967296'] + self::ticked($page, [$k1], 'link'),
            'the same on a second step' => ['confirm' => '1', 'ver_' . $k1 => '9999999999'] + self::ticked($page, [$k1], 'reject'),
        ] as $what => $form) {
            $r = $web->post('/ui/review/bulk', $form);
            // The form's own check answers first on this route (400 `bad_form`, New Variants' build, M60); the bound in
            // BulkDecisions::checked() (400 `bad_request`) is what a caller past the form gets: By Item's route, and BulkDecisionsTest.
            self::assertSame([400, 'bad_form'], [$r->status, $r->errorCode()], $what . ': ' . $r->describe());
            self::assertStringContainsString(Words::ERROR['bad_form'], $r->text(), $what);
        }
        self::assertSame($before, [(int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'), (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch_row'), $this->decisions()]);
        self::assertSame(['suggested', 'suggested'], [$this->link($k1)['status'], $this->link($k2)['status']]);
    }

    /** A hand-made form on the lists that were there before: a number out of range or a note that is not text is refused in words, never an error page. */
    public function testANumberOutOfRangeOrANoteThatIsNotTextIsRefusedWholeOnTheStrengthListsAndStoreProducts(): void
    {
        ['alt' => $alt] = $this->stores();
        $sku = $this->item('legacy');
        $k1 = $this->queued($alt, 'K1', 'Key', $sku, ['product_title' => 'Strong one']);
        $k2 = $this->queued($alt, 'K2', 'Key', $sku, ['product_title' => 'Strong two']);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review', ['queue' => 'Key']);
        // It was an error page, after the rows before the bad one had already been matched.
        foreach ([['pick_4294967296' => '1', 'ver_4294967296' => '0', 'prop_4294967296' => ''], ['ver_' . $k2 => '9999999999']] as $crafted) {
            $r = $web->post('/ui/review/bulk', $crafted + self::ticked($page, [$k1, $k2], 'link'));
            self::assertSame([400, 'bad_form'], [$r->status, $r->errorCode()], json_encode($crafted) . ': ' . $r->describe());
        }
        $r = $web->post('/ui/review/bulk', ['reason' => "\xFF"] + self::ticked($page, [$k1], 'link'));
        self::assertSame([400, 'bad_reason'], [$r->status, $r->errorCode()], $r->describe());
        self::assertStringContainsString(Words::BULK_ERROR['reason_text'], $r->text());
        // Store Products' Ignore: its second step is shown again with why.
        $step = $web->post('/ui/review/bulk', self::ticked($web->get('/ui/review/store', ['channel' => 'alt']), [$k1], 'ignore'));
        self::assertSame(200, $step->status, $step->describe());
        $bad = $web->post('/ui/review/bulk', ['reason' => "caf\xE9"] + $step->form('/ui/review/bulk'));
        self::assertSame(400, $bad->status, $bad->describe());
        self::assertStringContainsString(Words::BULK_ERROR['reason_text'], $bad->text());
        self::assertSame(['ignore', '1'], [$bad->form('/ui/review/bulk')['action'] ?? null, $bad->form('/ui/review/bulk')['confirm'] ?? null]);
        self::assertSame(['suggested', 'suggested', 0, 0], [$this->link($k1)['status'], $this->link($k2)['status'], (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'),
            (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch_row')], 'nothing was written');
    }

    public function testEveryNewRouteChecksItsPermissionAndTheBulkFormItsToken(): void
    {
        ['alt' => $alt] = $this->stores();
        $sku = $this->item('legacy');
        $k = $this->queued($alt, 'K1', 'Key', $sku);
        $mapper = $this->signIn($this->uiUser('mapper'));
        $page = $mapper->get('/ui/review', ['queue' => 'Key']);
        $form = self::ticked($page, [$k], 'link');
        $noToken = $form;
        unset($noToken['csrf']);
        $r = $mapper->post('/ui/review/bulk', $noToken);
        self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()]);
        $r = $mapper->post('/ui/review/bulk', ['csrf' => 'forged'] + $form);
        self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()]);
        self::assertSame('suggested', $this->link($k)['status']);
        // Looking only: the lists, Store Products and a batch page yes; the bulk action no.
        $viewer = $this->signIn($this->uiUser('viewer'));
        $r = $viewer->post('/ui/review/bulk', ['csrf' => $this->token($viewer)] + $form);
        self::assertSame(403, $r->status, $r->describe());
        self::assertSame(200, $viewer->get('/ui/review/store', ['channel' => 'alt'])->status);
        self::assertSame(200, $viewer->get('/ui/review')->status, implode("\n", self::$log));
        $ok = $mapper->post('/ui/review/bulk', $form);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame(200, $viewer->get((string) $ok->location())->status);
        // Without the matching jobs: none of it.
        $buyer = $this->signIn($this->uiUser('buyer'));
        foreach (['/ui/review', '/ui/review/store', (string) $ok->location()] as $path) {
            self::assertSame(403, $buyer->get($path)->status, $path);
        }
        self::assertSame(403, $buyer->post('/ui/review/bulk', ['csrf' => $this->token($buyer)] + $form)->status);
    }

    public function testTheStoreChosenAppliesToEveryListSegmentAndTheOverview(): void
    {
        ['alt' => $alt, 'big' => $big] = $this->stores();
        $sku = $this->item('legacy');
        $this->queued($alt, 'A1', 'Key', $sku, ['product_title' => 'Alt strong']);
        $this->queued($big, 'B1', 'Key', $sku, ['product_title' => 'Big strong']);
        $this->queued($big, 'B2', 'Check', $sku, ['product_title' => 'Big likely']);
        $lead = $this->uiUser('mapping_lead');
        $other = $this->staffUser('mapping_lead');
        $pa = $this->queued($alt, 'A2', 'Key', $sku, ['product_title' => 'Alt pack']);
        $pb = $this->queued($big, 'B3', 'Key', $sku, ['product_title' => 'Big pack']);
        foreach ([$pa, $pb] as $l) {
            $this->decide($other, 'link', $l, ['sku_id' => $sku, 'units_per_item' => 6, 'proposal_id' => (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$l])]);
        }
        $web = $this->signIn($lead);
        $both = $web->get('/ui/review', ['queue' => 'Key']);
        self::assertSame(['Alt strong', 'Big strong'], self::titles($both));
        self::assertSame([['All stores', true], ['Alt Store', false], ['Big Store', false]],
            array_map(static fn (array $s): array => [$s['label'], $s['current']], self::storeSeg($both)));
        $one = $web->get('/ui/review', ['queue' => 'Key', 'channel' => 'alt']);
        self::assertSame(['Alt strong'], self::titles($one));
        self::assertSame([false, true, false], array_column(self::storeSeg($one), 'current'));
        self::assertSame([null, '/ui/review/samples?channel=alt', '/ui/review?queue=pending&channel=alt'], array_column(self::segments($one), 'href'),
            'the store travels through the segments (the one shown has no link: CV13)');
        $xp = new \DOMXPath($one->dom());
        self::assertSame('Check', $xp->evaluate('string(//select[@name="queue"]/option[2]/@value)'), 'and through the strength lists (the same Show row)');
        self::assertStringEndsWith('(0)', trim((string) $xp->evaluate('string(//select[@name="queue"]/option[2])')), 'whose counts are the store\'s');
        // Second approval and the overview.
        $pending = $web->get('/ui/review', ['queue' => 'pending', 'channel' => 'vbig']);
        self::assertSame(['Big pack'], self::titles($pending));
        self::assertSame(['Alt pack', 'Big pack'], self::titles($web->get('/ui/review', ['queue' => 'pending'])));
        $overview = $web->get('/ui/review', ['channel' => 'vbig']);
        self::assertSame(200, $overview->status, $overview->describe());
        $oxp = new \DOMXPath($overview->dom());
        self::assertSame(['Big Store'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent), iterator_to_array($oxp->query('//section[contains(@class, "by-store")]//button[@class="grp-toggle"]'))));
        self::assertSame(['Alt Store', 'Big Store'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array((new \DOMXPath($web->get('/ui/review')->dom()))->query('//section[contains(@class, "by-store")]//button[@class="grp-toggle"]'))));
        self::assertSame('To review', self::segments($overview)[0]['label']);
        self::assertTrue(self::segments($overview)[0]['current']);
        // One row per strength, the products no computer check has suggested anything for, and the store's total.
        $rows = $oxp->query('//table[contains(@class, "by-store-table")]/tbody/tr');
        self::assertSame(8, $rows->length);
        $strong = $rows->item(0);
        self::assertSame('1', trim((string) $oxp->evaluate('string(td[1])', $strong)), 'Big Store: 1 strong match waiting');
        self::assertSame('/ui/review?queue=Key&channel=vbig', $oxp->evaluate('string(td[4]/a/@href)', $strong));
        self::assertStringContainsString(Words::say('BY_STORE', 'of_products', 0, 2), (string) $oxp->evaluate('string(td[2])', $strong),
            'its 2 strong-match products: 1 waiting for a person, 1 for a second OK, none matched yet');
        $likely = $rows->item(1);
        self::assertSame('/ui/review?queue=Check&channel=vbig', $oxp->evaluate('string(td[4]/a/@href)', $likely));
        self::assertStringContainsString(Words::BY_STORE['nothing'], (string) $oxp->evaluate('string(td[4])', $rows->item(2)), 'New product: nothing waiting, no button');
    }

    public function testStoreProductsShowsEveryProductOfTheStoreWithItsStateAndTakesTheStoreActions(): void
    {
        ['alt' => $alt, 'big' => $big] = $this->stores();
        $sku = $this->item('legacy');
        $lead = $this->staffUser('mapping_lead');
        $linked = $this->profiled($alt, 'L1', ['product_title' => 'Linked one', 'units_30d' => 5, 'units_365d' => 1]);
        $this->decide($lead, 'link', $linked, ['sku_id' => $sku, 'units_per_item' => 1]);
        $suggested = $this->queued($alt, 'S1', 'Key', $sku, ['product_title' => 'Suggested one', 'units_30d' => 4, 'units_365d' => 50]);
        $unmatched = $this->profiled($alt, 'U1', ['product_title' => 'Unmatched one', 'units_30d' => 3, 'units_365d' => 2]);
        $ignored = $this->profiled($alt, 'I1', ['product_title' => 'Ignored one', 'units_30d' => 2, 'units_365d' => 3]);
        $this->decide($lead, 'ignore', $ignored, ['reason' => 'placeholder']);
        $waiting = $this->queued($alt, 'W1', 'Key', $sku, ['product_title' => 'Waiting one', 'units_30d' => 1, 'units_365d' => 4]);
        $this->decide($lead, 'link', $waiting, ['sku_id' => $sku, 'units_per_item' => 10, 'proposal_id' => (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$waiting])]);
        $held = $this->profiled($alt, 'Q1', ['product_title' => 'On hold one'], $sku);
        self::$db->exec("UPDATE channel_listing SET status = 'quarantined' WHERE id = ?", [$held]);
        $this->profiled($big, 'X1', ['product_title' => 'Other store one']);

        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get('/ui/review/store', ['channel' => 'alt']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame('Store products', self::currentTab($page));
        // A board (docs/decisions.md W12.6): grouped by state, what is still to match first, best sellers first (30 days) in each.
        self::assertSame(['Suggested one', 'Unmatched one', 'Waiting one', 'On hold one', 'Linked one', 'Ignored one'], self::titles($page), 'by state, then best sellers first');
        $xp = new \DOMXPath($page->dom());
        $state = static fn (int $row): string => trim((string) $xp->evaluate('string((//tbody/tr)[' . $row . ']/td[contains(@class, "c-status")])'));
        self::assertSame([Words::STORE_STATE['suggested'], Words::STORE_STATE['not_matched'], Words::STORE_STATE['waiting'], Words::STORE_STATE['quarantined'],
            Words::STORE_STATE['linked'], Words::STORE_STATE['ignored']], array_map($state, range(1, 6)));
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        self::assertStringContainsString($code, (string) $xp->evaluate('string((//tbody/tr)[5]/td[contains(@class, "c-wide")])'));
        self::assertStringContainsString(Words::saleUses(1), (string) $xp->evaluate('string((//tbody/tr)[5]/td[contains(@class, "c-wide")])'));
        self::assertStringContainsString(Words::BAND['Key'], (string) $xp->evaluate('string((//tbody/tr)[1]/td[contains(@class, "c-wide")])'));
        self::assertStringContainsString(Words::say('STORE_PRODUCTS', 'pending', Words::ACTION['link']), (string) $xp->evaluate('string((//tbody/tr)[3]/td[contains(@class, "c-wide")])'));
        // Only the rows a store action applies to can be ticked: a matched, waiting or on-hold one changes on its own page.
        $boxes = array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'), iterator_to_array($xp->query('//input[@data-bulk-row]')));
        self::assertSame(['pick_' . $suggested, 'pick_' . $unmatched, 'pick_' . $ignored], $boxes);
        self::assertSame(['ignore', 'unignore', 'send_back'], self::barActions($page));
        // The state filter, with each state's count, and the sort.
        $pills = array_map(static fn (\DOMNode $a): string => trim((string) preg_replace('/\s+/', ' ', $a->textContent)), iterator_to_array($xp->query('//select[@name="state"]/option')));
        self::assertSame(['All (6)', 'Waiting for a second OK (1)', 'On hold (1)', 'Ignored (1)', 'Matched (1)', 'Suggested (1)', 'Not matched (1)'], $pills, 'the Show row\'s state (W12.6)');
        self::assertSame(['Ignored one'], self::titles($web->get('/ui/review/store', ['channel' => 'alt', 'state' => 'ignored'])));
        self::assertSame(['Suggested one', 'Unmatched one', 'Waiting one', 'On hold one', 'Linked one', 'Ignored one'],
            self::titles($web->get('/ui/review/store', ['channel' => 'alt', 'sort' => 'sold_365'])), 'by state, then the sort');
        self::assertSame(['Unmatched one'], self::titles($web->get('/ui/review/store', ['channel' => 'alt', 'q' => 'Unmatch'])));
        self::assertSame(['Other store one'], self::titles($web->get('/ui/review/store', ['channel' => 'vbig'])));
        self::assertSame([['Alt Store', true], ['Big Store', false]], array_map(static fn (array $s): array => [$s['label'], $s['current']], self::storeSeg($page)),
            'no "All stores" here: a store is chosen');

        // Ignore (a second step with the note), take off the ignored list (at once), send back for matching (a second step).
        $step = $web->post('/ui/review/bulk', self::ticked($page, [$unmatched], 'ignore'));
        self::assertSame(200, $step->status, $step->describe());
        $done = $web->post('/ui/review/bulk', ['reason' => 'A parent page'] + $step->form('/ui/review/bulk'));
        self::assertSame(303, $done->status, $done->describe());
        self::assertSame('ignored', $this->link($unmatched)['status']);
        self::assertSame('/ui/review/store?channel=alt', (new \DOMXPath($web->follow($done)->dom()))->evaluate('string(//p[@class="crumbs" or contains(@class, "ph-back")]/a/@href)'));
        $page = $web->get('/ui/review/store', ['channel' => 'alt']);
        $r = $web->post('/ui/review/bulk', self::ticked($page, [$ignored], 'unignore'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('unmapped', $this->link($ignored)['status']);
        $step = $web->post('/ui/review/bulk', self::ticked($page, [$suggested], 'send_back'));
        self::assertStringContainsString(Words::say('BULK_CONFIRM', 'send_back_title', 1), $step->text());
        self::assertSame(303, $web->post('/ui/review/bulk', $step->form('/ui/review/bulk'))->status);
        self::assertSame('unmapped', $this->link($suggested)['status']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE open_listing_id = ?', [$suggested]));
        self::assertStringNotContainsString(Words::STORE_PRODUCTS['unlink_note'], $page->text(), 'no help paragraph on the board: undoing a match is on its page (W12.6)');
    }

    public function testAMatchIsUndoneOneAtATimeOnItsPageWithANote(): void
    {
        ['alt' => $alt] = $this->stores();
        $sku = $this->item('legacy');
        $l = $this->profiled($alt, 'L1', ['product_title' => 'Linked one']);
        $this->decide($this->staffUser('mapping_lead'), 'link', $l, ['sku_id' => $sku, 'units_per_item' => 1]);
        $web = $this->signIn($this->uiUser('mapping_lead'));
        $page = $web->get('/ui/review/listing/' . $l);
        self::assertContains('unlink', $page->radios('action'), 'a matching lead\'s "Change this match" offers to undo it');
        $form = $page->form('/ui/review/listing/' . $l . '/decide');
        $r = $web->post('/ui/review/listing/' . $l . '/decide', ['action' => 'unlink', 'reason' => ''] + $form);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['unlink_why'], $r->text());
        self::assertSame('mapped', $this->link($l)['status']);
        $r = $web->post('/ui/review/listing/' . $l . '/decide', ['action' => 'unlink', 'reason' => 'Wrong flavour'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('unmapped', $this->link($l)['status']);
        self::assertStringContainsString(Words::say('MATCH_NOTICE', 'decided_unlink', '"Linked one"'), $web->follow($r)->text());
        $mapper = $this->signIn($this->uiUser('mapper'));
        self::assertNotContains('unlink', $mapper->get('/ui/review/listing/' . $l)->radios('action'));
    }

    public function testTheStrengthsSettingIsTickedOnItsPageAndTheSecondOkIsAnApprovalRule(): void
    {
        $this->setting('mapping.bulk_confirm_bands', '"Key,Check"');
        $web = $this->signIn($this->uiUser('reviewer'));
        $page = $web->get('/ui/reference/settings/setting', ['key' => 'mapping.bulk_confirm_bands']);
        self::assertSame(200, $page->status, $page->describe());
        $form = $page->form('/ui/reference/settings/setting');
        self::assertSame(['1', '1'], [$form['item_0'], $form['item_1']]);
        self::assertArrayNotHasKey('item_2', $form);
        self::assertStringContainsString(Words::BAND['Key'] . ', ' . Words::BAND['Check'], $page->text(), 'the value in words, never the codes');
        unset($form['item_1']);
        $r = $web->post('/ui/reference/settings/setting', ['item_2' => '1', 'reason' => 'New products too'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('"Key,New item"', (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'mapping.bulk_confirm_bands'"));
        $again = $web->follow($r);
        self::assertStringContainsString(Words::BAND['Key'] . ', ' . Words::BAND['New item'], $again->text());
        self::assertStringContainsString(Words::settingName('mapping.bulk_max_rows'), $web->get('/ui/reference/settings')->text());
        $rules = $web->get('/ui/reference/approvals');
        self::assertSame(1, (new \DOMXPath($rules->dom()))->query('//article[@id="rule-approvals-mapping-bulk-second-ok"]')->length);
        self::assertStringContainsString(Words::RULE['approvals.mapping_bulk_second_ok']['off'], $rules->text(), 'off by default');
    }
}
