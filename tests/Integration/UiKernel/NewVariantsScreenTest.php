<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\BulkDecisions;
use CW\Output\CsvWriter;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\ArrivalFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\BulkController;
use CW\Ui\Controller\NewVariantsController;
use CW\Ui\Html;
use CW\Ui\Queries;
use CW\Ui\Words;

/**
 * Products › New Variants (docs/decisions.md M60-M62, U131-U138), through the real /ui kernel as cw_app, with three stores made by
 * the test (no store is named in the code): the tab and its place, one store's variants first seen in a period (newest first, with
 * their facts and what each is, in words), the box that says whether the store sends (its mode and its latest heartbeat), the
 * period filter and its setting changed on the Settings page, the filter (All not matched, New, Suggested match, No suggestion,
 * All) with its counts, the search, the pager and the CSV, the action bar per person, the seed "Create as new items" (an item only
 * for a row the computer found new; each skip with its reason; the result page), Confirm match and Ignore… from this list, CSRF and
 * permissions, numbers out of range and a note that is not text, and odd values in the address. Round 2b: a variant an item was
 * already made from, one that changed on the website after the check, a name an item already has or two ticked rows share and a
 * suggestion the computer could not decide are skipped, each row saying so before it is ticked where the list can know it; the
 * CSV's last line is written only for a file that was cut; and a store where no check has marked a variant New is told so. The
 * website state (M66, U146): a row marked New says what the seed will find of its state, before it is ticked: not published (the
 * variant, or its product), a page heading, or that the warehouse does not know whether it is on sale now. The review of build 2a:
 * every row still to match says when it is a page heading or not published, also once its store's state is loaded (CS-A), and
 * what the features and the check's record say against a variant counts beside its stored state (F1).
 */
final class NewVariantsScreenTest extends KernelUiTestCase
{
    use ArrivalFixtures;

    private const PATH = NewVariantsController::PATH;

    /** @var list<array<string, mixed>> */
    private array $settings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting');
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['setting_key']]);
        }
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    /** Three stores, named as the channel table names them; the first is switched on, the others are off. @return array{alt: Caller, big: Caller, cee: Caller} */
    private function stores(): array
    {
        $alt = $this->site('alt', 'shadow');
        $big = $this->site('vbig', 'off');
        $cee = $this->site('cee', 'off');
        self::$db->exec("UPDATE channel SET name = 'Alt Store' WHERE code = 'alt'");
        self::$db->exec("UPDATE channel SET name = 'Big Store' WHERE code = 'vbig'");
        self::$db->exec("UPDATE channel SET name = 'Cee Store' WHERE code = 'cee'");
        return ['alt' => $alt, 'big' => $big, 'cee' => $cee];
    }

    /** Says when a variant was first seen: $minutes ago. */
    private function seen(int $listing, int $minutes): int
    {
        self::$db->exec('UPDATE channel_listing SET created_at = NOW(6) - INTERVAL ? MINUTE WHERE id = ?', [$minutes, $listing]);
        return $listing;
    }

    /** A variant the computer has not checked (no match, no suggestion), first seen $minutes ago. @param array<string, mixed> $p */
    private function variant(Caller $site, string $option, string $title, int $minutes, array $p = []): int
    {
        return $this->seen($this->profiled($site, $option, ['product_title' => $title] + $p), $minutes);
    }

    /** A variant with an open suggestion, first seen $minutes ago. @param array<string, mixed> $proposal @param array<string, mixed> $p */
    private function suggested(Caller $site, string $option, string $title, int $minutes, string $band, ?int $sku, array $proposal = [], array $p = []): int
    {
        return $this->seen($this->queued($site, $option, $band, $sku, ['product_title' => $title] + $p, $proposal), $minutes);
    }

    /**
     * A variant the computer checked and found new (an open "new product" suggestion): what the seed takes. What is known of its
     * state on the website (M66): it is on sale by its STORED state (the profile's own columns, as of now), as every variant is
     * once its store's state is loaded; unless $p gives the features of a matching run instead (`features`: no stored state, as
     * before 0028), or `site`: false (nothing at all is known) or the columns of a stored state that says something else.
     *
     * @param array<string, mixed> $p
     */
    private function found(Caller $site, string $option, string $title, int $minutes, array $p = []): int
    {
        $id = $this->suggested($site, $option, $title, $minutes, 'New item', null, ['proposed_new_item' => 1], array_diff_key($p, ['site' => true]));
        if (is_array($p['site'] ?? null) || (!isset($p['features']) && ($p['site'] ?? null) !== false)) {
            self::siteState($id, is_array($p['site'] ?? null) ? $p['site'] : []);
        }
        return $id;
    }

    private function proposalOf(int $listing): int
    {
        return (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listing]);
    }

    /** The store's connector called $seconds ago and said the website itself runs in $siteMode (null: it did not say). */
    private function heartbeat(Caller $site, int $seconds, ?string $siteMode): void
    {
        self::$db->exec('INSERT INTO channel_health (channel_id, received_at, site_mode) VALUES (?, NOW(6) - INTERVAL ? SECOND, ?)', [$site->channelId, $seconds, $siteMode]);
    }

    private function codeOf(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }

    /** @return list<string> the names of the list's rows, in order */
    private static function titles(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent), iterator_to_array($xp->query('//table[contains(@class, "new-variants")]/tbody/tr/th/a[@class="o-name"]')));
    }

    /**
     * One row of the list as a person reads it: its cells by their heading, its head, its Open link, and `state`: the chip that says
     * what the row is and what the "What the computer found" cell says of it (with the website's note, when there is one).
     *
     * @return array<string, string>
     */
    private static function row(UiResponse $r, int $n): array
    {
        $xp = new \DOMXPath($r->dom());
        $tr = $xp->query('//table[contains(@class, "new-variants")]/tbody/tr[' . $n . ']')->item(0);
        self::assertNotNull($tr, "row {$n}: " . $r->describe());
        $text = static fn (?\DOMNode $node): string => trim((string) preg_replace('/\s+/u', ' ', (string) ($node?->textContent ?? '')));
        $out = ['head' => $text($xp->query('th', $tr)->item(0)), 'state' => $text($xp->query('td[contains(@class, "c-status")]', $tr)->item(0)),
            'open' => (string) $xp->evaluate('string(td[contains(@class, "c-next")]/a/@href)', $tr)];
        foreach ($xp->query('td[@data-label]', $tr) as $td) {
            /** @var \DOMElement $td */
            $out[$td->getAttribute('data-label')] = $text($td);
        }
        $out['state'] = trim($out['state'] . ' ' . $out[Words::NEW_VARIANTS['item']]);
        return $out;
    }

    /** @return list<array{0: string, 1: bool}> a row of filter pills: label (with its count, when it has one) and whether it is the current one */
    private static function pills(UiResponse $r, string $class): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//nav[contains(@class, "' . $class . '")]/a') as $a) {
            /** @var \DOMElement $a */
            $out[] = [trim((string) preg_replace('/\s+/u', ' ', $a->textContent)), $a->getAttribute('aria-current') === 'page'];
        }
        return $out;
    }

    /** @return list<array{0: string, 1: bool, 2: int}> the store selector: name, current, count */
    private static function storeSeg(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query('//nav[contains(@class, "store-seg")]/a') as $a) {
            /** @var \DOMElement $a */
            $out[] = [trim((string) $xp->evaluate('string(span[1])', $a)), $a->getAttribute('aria-current') === 'page', (int) $xp->evaluate('string(span[contains(@class, "count")]/@title)', $a)];
        }
        return $out;
    }

    /** @return list<string> the actions of the page's action bar */
    private static function barActions(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return array_map(static fn (\DOMElement $b): string => $b->getAttribute('value'), iterator_to_array($xp->query('//div[@data-bulkbar]//button[@name="action"]')));
    }

    /** The bulk form of a page with these rows ticked and this action pressed. @param list<int> $ids @return array<string, string> */
    private static function ticked(UiResponse $page, array $ids, string $action): array
    {
        $form = $page->form('/ui/review/bulk');
        self::assertNotSame([], $form, 'the page has the bulk form: ' . $page->describe());
        foreach ($ids as $id) {
            self::assertArrayHasKey('ver_' . $id, $form, "row {$id} is on the page and can be ticked");
            $form['pick_' . $id] = '1';
        }
        return $form + ['action' => $action];
    }

    /** What became of each row of a batch. @return array<int, array{0: string, 1: ?string}> listing id => outcome, skip code */
    private static function batchRows(int $batch): array
    {
        $out = [];
        foreach (self::$db->all('SELECT listing_id, outcome, skip_code FROM mapping_batch_row WHERE batch_id = ? ORDER BY seq', [$batch]) as $r) {
            $out[(int) $r['listing_id']] = [(string) $r['outcome'], $r['skip_code'] === null ? null : (string) $r['skip_code']];
        }
        return $out;
    }

    private static function batchOf(UiResponse $redirect): int
    {
        self::assertSame(303, $redirect->status, $redirect->describe());
        self::assertMatchesRegularExpression('#^/ui/review/batches/[0-9]+$#', (string) $redirect->location());
        return (int) substr((string) $redirect->location(), strlen('/ui/review/batches/'));
    }

    public function testTheTabItsPlaceAndAStoresNewVariantsNewestFirstWithTheirFacts(): void
    {
        ['alt' => $alt, 'big' => $big, 'cee' => $cee] = $this->stores();
        $sku = $this->item('legacy');
        $older = $this->variant($alt, '9001', 'Older one', 3 * 1440);
        $newer = $this->variant($alt, '9002', 'Newer one', 60, ['variant_title' => 'Blue Razz 20mg', 'brand' => 'Elux',
            'barcodes' => ['5056168800011', '5056168800028'], 'units_30d' => 4, 'units_365d' => 31]);
        self::$db->exec('UPDATE listing_profile SET price = 4.99 WHERE listing_id = ?', [$newer]);
        $this->suggested($alt, '9003', 'Suggested one', 30, 'Key', $sku);
        $this->variant($big, '7001', 'Big one', 10);
        $ceeOne = $this->variant($cee, '5001', 'Cee one', 10);
        $this->variant($cee, '5002', 'Cee old one', 400 * 1440);
        $this->heartbeat($alt, 10, 'shadow');
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['All Products', 'Mapping', 'Store Products', 'By Item', 'New Variants', 'Duplicates', 'Families', 'Multipacks'], self::tabLabels($page),
            'the tab after By Item');
        self::assertSame(['New Variants', 'Products'], [self::currentTab($page), self::currentSection($page)]);
        self::assertSame([], self::segments($page), 'no segments');
        // The first store by name is chosen; the selector counts each store's variants first seen in the period, whatever their state.
        self::assertSame([['Alt Store', true, 3], ['Big Store', false, 1], ['Cee Store', false, 1]], self::storeSeg($page));
        self::assertSame(['Suggested one', 'Newer one', 'Older one'], self::titles($page), 'everything still to match, newest first');
        $row = self::row($page, 2);
        self::assertSame('Newer one Blue Razz 20mg Elux', $row['head'], 'the product on one line, the variant under it');
        self::assertSame('9002', $row[Words::NEW_VARIANTS['option']]);
        self::assertSame('5056168800011 5056168800028', $row[Words::NEW_VARIANTS['barcode']]);
        self::assertSame('£4.99', $row[Words::NEW_VARIANTS['price']]);
        self::assertSame(['4', '31'], [$row[Words::QUEUE['sold_30']], $row[Words::QUEUE['sold_365']]]);
        self::assertSame(Html::when((string) self::$db->value('SELECT created_at FROM channel_listing WHERE id = ?', [$newer])), $row[Words::NEW_VARIANTS['first_seen']]);
        self::assertSame(Words::NEW_VARIANT_STATE['not_checked'] . ' ' . Words::ARRIVAL_LEFT['off'], $row['state'], 'what it is, and why');
        self::assertSame('/ui/review/listing/' . $newer, $row['open'], 'Open is the variant\'s existing page');
        self::assertStringStartsWith(Words::NEW_VARIANT_STATE['suggested'] . ' ' . Words::say('NEW_VARIANTS', 'suggested_item', Words::BAND['Key'], $this->codeOf($sku)),
            self::row($page, 1)['state'], 'Suggested match, with its strength and the item');
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'board', 'Alt Store'), $page->text());
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'shown', 3, 3), $page->text());
        // First seen is UK time: a July morning in UTC is an hour later in London.
        self::$db->exec("UPDATE channel_listing SET created_at = '2021-07-01 11:30:00' WHERE id = ?", [$older]);
        $all = $web->get(self::PATH, ['period' => 'all', 'state' => 'all']);
        self::assertSame(['Suggested one', 'Newer one', 'Older one'], self::titles($all));
        self::assertSame('1 Jul 2021, 12:30', self::row($all, 3)[Words::NEW_VARIANTS['first_seen']]);

        // Where new variants come from, in words: the stores send them; nothing is made up. (Whether THIS store sends: the next test.)
        self::assertStringContainsString(Words::NEW_VARIANTS['where_from'], $page->text());
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'store_on', 'Alt Store'), $page->text());
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'newest', Html::when((string) self::$db->value('SELECT MAX(created_at) FROM channel_listing WHERE channel_id = ?', [$alt->channelId]))),
            $page->text());
        $other = $web->get(self::PATH, ['channel' => 'vbig']);
        self::assertSame(['Big one'], self::titles($other));
        self::assertSame([false, true, false], array_column(self::storeSeg($other), 1));
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'store_off', 'Big Store'), $other->text());
        // A store whose variants are all older than the period: the empty state says what will show here, and how it arrives.
        $this->seen($ceeOne, 399 * 1440);
        $empty = $web->get(self::PATH, ['channel' => 'cee']);
        self::assertSame(200, $empty->status, $empty->describe());
        self::assertSame([], self::titles($empty));
        self::assertStringContainsString(Words::NEW_VARIANTS['empty'], $empty->text());
        self::assertStringContainsString(Words::NEW_VARIANTS['empty_text'], $empty->text());
        self::assertStringContainsString(Words::NEW_VARIANTS['where_from'], $empty->text());
        self::assertFalse($empty->hasForm('/ui/review/bulk'), 'nothing to tick');
        self::assertSame(['Cee one', 'Cee old one'], self::titles($web->get(self::PATH, ['channel' => 'cee', 'period' => 'all'])), 'Any time shows them');
    }

    public function testWithNoStoreThePageSaysWhatWillShowHere(): void
    {
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get(self::PATH);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::NEW_VARIANTS['no_stores'], $page->text());
        self::assertStringContainsString(Words::NEW_VARIANTS['where_from'], $page->text());
        self::assertSame(404, $web->get(self::PATH . '.csv')->status, 'no store, no file');
    }

    public function testTheBoxSaysWhetherTheStoreSendsFromItsModeAndItsLatestHeartbeat(): void
    {
        ['alt' => $alt, 'big' => $big] = $this->stores(); // Alt Store is switched on in CW (shadow), Big Store is off
        $this->variant($alt, 'H1', 'Alt one', 10);
        $this->variant($big, 'H2', 'Big one', 10);
        $web = $this->signIn($this->uiUser('mapper'));
        $says = static fn (string $channel): string => $web->get(self::PATH, ['channel' => $channel])->text();
        $on = Words::say('NEW_VARIANTS', 'store_on', 'Alt Store');
        $quiet = Words::say('NEW_VARIANTS', 'store_quiet', 'Alt Store');
        // Switched on in CW, but its connector has never been heard from: it is not said to send.
        $text = $says('alt');
        self::assertStringContainsString($quiet, $text);
        self::assertStringNotContainsString($on, $text);
        self::assertStringContainsString(Words::ASK, $quiet, 'and whom to ask for a load');
        // Heard from a moment ago, the website itself in shadow: it sends.
        $this->heartbeat($alt, 10, 'shadow');
        $text = $says('alt');
        self::assertStringContainsString($on, $text);
        self::assertStringNotContainsString($quiet, $text);
        // Its latest heartbeat says the website itself is off (the lower of the two modes applies): not sending.
        $this->heartbeat($alt, 5, 'off');
        self::assertStringContainsString($quiet, $says('alt'));
        // Its latest heartbeat is old: not sending now.
        self::$db->exec('DELETE FROM channel_health');
        $this->heartbeat($alt, 3600, 'live');
        self::assertStringContainsString($quiet, $says('alt'));
        // A fresh one that does not say the website's mode: in contact, so it sends.
        $this->heartbeat($alt, 1, null);
        self::assertStringContainsString($on, $says('alt'));
        // A store that is off in CW: "sends nothing yet", whatever its connector reports.
        $this->heartbeat($big, 1, 'live');
        $text = $says('vbig');
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'store_off', 'Big Store'), $text);
        self::assertStringNotContainsString(Words::say('NEW_VARIANTS', 'store_on', 'Big Store'), $text);
        self::assertStringNotContainsString(Words::say('NEW_VARIANTS', 'store_quiet', 'Big Store'), $text);
        // The rule itself: on only when switched on in CW, heard from within the time the Stores page calls recent, and not reporting off.
        $stale = \CW\Admin\Sites::STALE_AFTER_SEC;
        self::assertSame(['store_off', 'store_quiet', 'store_quiet', 'store_quiet', 'store_on', 'store_on', 'store_on'], array_map(NewVariantsController::sending(...), [
            ['mode' => 'off', 'site_mode' => 'live', 'age' => 1], ['mode' => 'shadow', 'site_mode' => null, 'age' => null],
            ['mode' => 'live', 'site_mode' => 'live', 'age' => $stale + 1], ['mode' => 'live', 'site_mode' => 'off', 'age' => 1],
            ['mode' => 'shadow', 'site_mode' => 'shadow', 'age' => $stale], ['mode' => 'live', 'site_mode' => null, 'age' => 0], ['mode' => 'live', 'site_mode' => 'shadow', 'age' => 2],
        ]));
        self::assertSame(['mode' => 'off', 'site_mode' => null, 'age' => null], (new Queries(self::$db))->storeLink(999999), 'a store that is not there reads as off');
    }

    public function testThePeriodFilterAndItsSettingChangedOnTheSettingsPage(): void
    {
        ['alt' => $alt] = $this->stores();
        $this->variant($alt, 'P2', 'Two days', 2 * 1440);
        $this->variant($alt, 'P10', 'Ten days', 10 * 1440);
        $this->variant($alt, 'P20', 'Twenty days', 20 * 1440);
        $this->variant($alt, 'P45', 'Forty-five days', 45 * 1440);
        $this->variant($alt, 'P200', 'Two hundred days', 200 * 1440);
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get(self::PATH);
        self::assertSame(['Two days', 'Ten days', 'Twenty days'], self::titles($page), 'the setting\'s 30 days by default');
        self::assertSame([['Last 7 days', false], ['Last 30 days', true], ['Last 90 days', false], ['Any time', false]], self::pills($page, 'period-pills'));
        self::assertSame(['Two days'], self::titles($web->get(self::PATH, ['period' => '7'])));
        self::assertSame(['Two days', 'Ten days', 'Twenty days', 'Forty-five days'], self::titles($web->get(self::PATH, ['period' => '90'])));
        $any = $web->get(self::PATH, ['period' => 'all']);
        self::assertSame(['Two days', 'Ten days', 'Twenty days', 'Forty-five days', 'Two hundred days'], self::titles($any));
        self::assertSame([false, false, false, true], array_column(self::pills($any, 'period-pills'), 1));
        self::assertSame(['Two days', 'Ten days', 'Twenty days'], self::titles($web->get(self::PATH, ['period' => '12'])), 'a period the page does not offer is the default');
        // The default period's link carries no period, so it follows the setting; the others name theirs; the filter's pills keep the period.
        $xp = new \DOMXPath($any->dom());
        $hrefs = array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'), iterator_to_array($xp->query('//nav[contains(@class, "period-pills")]/a')));
        self::assertSame([self::PATH . '?channel=alt&period=7', self::PATH . '?channel=alt', self::PATH . '?channel=alt&period=90', self::PATH . '?channel=alt&period=all'], $hrefs);
        self::assertSame([self::PATH . '?channel=alt&period=all', self::PATH . '?channel=alt&period=all&state=new', self::PATH . '?channel=alt&period=all&state=suggested',
            self::PATH . '?channel=alt&period=all&state=not_checked', self::PATH . '?channel=alt&period=all&state=all'],
            array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'), iterator_to_array($xp->query('//nav[contains(@class, "state-pills")]/a'))));
        self::assertSame(['alt', 'all'], [$any->form(self::PATH)['channel'], $any->form(self::PATH)['period']], 'and so does the search');

        // The setting is on the Settings page, with its allowed values in words, and is changed there with a reason.
        self::assertSame(['30', 1], [(string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'mapping.new_variants_days'"),
            (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'mapping.new_variants_days' AND version = 1 "
                . "AND action = 'baseline' AND actor = 'system:migrate'")], '0026: the setting and its baseline');
        $owner = $this->signIn($this->uiUser('reviewer'));
        self::assertStringContainsString(Words::settingName('mapping.new_variants_days'), $owner->get('/ui/reference/settings')->text());
        $setting = $owner->get('/ui/reference/settings/setting', ['key' => 'mapping.new_variants_days']);
        self::assertSame(200, $setting->status, $setting->describe());
        self::assertStringContainsString(Words::SETTING_HELP['mapping.new_variants_days'], $setting->text());
        self::assertStringContainsString(sprintf(Words::SETTING_EDIT['range'], '1', '365'), $setting->text());
        $form = $setting->form('/ui/reference/settings/setting', true);
        self::assertSame('30', $form['value']);
        $bad = $owner->post('/ui/reference/settings/setting', ['value' => '0', 'reason' => 'no days at all'] + $form);
        self::assertSame([400, 'bad_value'], [$bad->status, $bad->errorCode()]);
        $noWhy = $owner->post('/ui/reference/settings/setting', ['value' => '14', 'reason' => ' '] + $form);
        self::assertSame([400, 'bad_reason'], [$noWhy->status, $noWhy->errorCode()]);
        $ok = $owner->post('/ui/reference/settings/setting', ['value' => '14', 'reason' => 'Two weeks is what we look at'] + $form);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertStringContainsString('Two weeks is what we look at', $owner->follow($ok)->text());
        self::assertSame(2, (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'mapping.new_variants_days'"));
        // The page now opens with 14 days, and offers them beside its own periods.
        $after = $web->get(self::PATH);
        self::assertSame(['Two days', 'Ten days'], self::titles($after));
        self::assertSame([['Last 7 days', false], ['Last 14 days', true], ['Last 30 days', false], ['Last 90 days', false], ['Any time', false]], self::pills($after, 'period-pills'));
        self::assertSame(['Two days', 'Ten days', 'Twenty days'], self::titles($web->get(self::PATH, ['period' => '30'])), '30 days is still offered, by its name');
        self::assertSame(['Two days', 'Ten days'], self::titles($web->get(self::PATH, ['period' => '14'])));
        // A matcher looks at the setting; only an admin or a reviewer changes it.
        $refused = $web->post('/ui/reference/settings/setting', ['csrf' => $this->token($web), 'key' => 'mapping.new_variants_days', 'seen' => '2', 'value' => '90',
            'reason' => 'I would like more']);
        self::assertSame(403, $refused->status, $refused->describe());
        self::assertSame('14', (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'mapping.new_variants_days'"));
    }

    public function testTheFilterTheSearchAndWhatEachVariantIsInWords(): void
    {
        ['alt' => $alt, 'big' => $big] = $this->stores();
        $sku = $this->item('legacy');
        $code = $this->codeOf($sku);
        $lead = $this->staffUser('mapping_lead');
        $this->variant($alt, '1001', 'Plain mango', 10, ['brand' => 'Elux', 'barcodes' => ['5056168800035']]);
        $this->suggested($alt, '1002', 'Suggested melon', 20, 'Key', $sku);
        $this->found($alt, '1003', 'Brand new cherry', 30);
        $this->suggested($alt, '1004', 'Unsure grape', 40, "Can't tell", null);
        $matched = $this->variant($alt, '1005', 'Matched lime', 50);
        $this->decide($lead, 'link', $matched, ['sku_id' => $sku, 'units_per_item' => 1]);
        $ignored = $this->variant($alt, '1006', 'Ignored page', 60);
        $this->decide($lead, 'ignore', $ignored, ['reason' => 'a placeholder']);
        $waiting = $this->variant($alt, '1007', 'Waiting pack', 70);
        $this->decide($lead, 'link', $waiting, ['sku_id' => $sku, 'units_per_item' => 6]);
        // What the website said of two of them when their features were stored: a page heading, and one in the bin. The heading
        // has a stored state too, as every variant of a store has once its state is loaded: its row still says it is a heading
        // (the review of build 2a, CS-A: after the load the line was gone from every row that is not marked New).
        self::siteState($this->variant($alt, '1008', 'Heading row', 80, ['features' => ['is_placeholder' => true, 'placeholder_reason' => 'landing_row', 'variant_status' => 'Published']]),
            ['site_is_landing' => 1]);
        $this->found($alt, '1009', 'Binned kiwi', 90, ['features' => ['is_placeholder' => false, 'variant_status' => 'Bin']]);
        $this->variant($big, '1001', 'Plain mango of another store', 10);
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get(self::PATH);
        self::assertSame(['Plain mango', 'Suggested melon', 'Brand new cherry', 'Unsure grape', 'Heading row', 'Binned kiwi'], self::titles($page),
            'the page opens with everything still to match');
        self::assertSame([['All not matched 6', true], ['New: the computer found nothing like it 2', false], ['Suggested match 2', false], ['No suggestion 2', false], ['All 9', false]],
            self::pills($page, 'state-pills'));
        $new = $web->get(self::PATH, ['state' => 'new']);
        self::assertSame(['Brand new cherry', 'Binned kiwi'], self::titles($new), 'the rows the computer checked and found new');
        self::assertSame([false, true, false, false, false], array_column(self::pills($new, 'state-pills'), 1));
        self::assertSame(['Suggested melon', 'Unsure grape'], self::titles($web->get(self::PATH, ['state' => 'suggested'])));
        self::assertSame(['Plain mango', 'Heading row'], self::titles($web->get(self::PATH, ['state' => 'not_checked'])));
        $all = $web->get(self::PATH, ['state' => 'all']);
        self::assertSame(['Plain mango', 'Suggested melon', 'Brand new cherry', 'Unsure grape', 'Matched lime', 'Ignored page', 'Waiting pack', 'Heading row', 'Binned kiwi'],
            self::titles($all));
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'shown', 9, 9), $all->text());
        $name = (string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]);
        self::assertSame([
            Words::NEW_VARIANT_STATE['not_checked'] . ' ' . Words::ARRIVAL_LEFT['off'],
            Words::NEW_VARIANT_STATE['suggested'] . ' ' . Words::say('NEW_VARIANTS', 'suggested_item', Words::BAND['Key'], $code) . ' ' . $name,
            Words::NEW_VARIANT_STATE['new'] . ' ' . Words::NEW_VARIANTS['is_new'],
            Words::NEW_VARIANT_STATE['suggested'] . ' ' . Words::BAND["Can't tell"],
            Words::NEW_VARIANT_STATE['linked'] . ' ' . Words::say('NEW_VARIANTS', 'matched_to', $code) . ' ' . $name,
            Words::NEW_VARIANT_STATE['ignored'],
            Words::NEW_VARIANT_STATE['waiting'] . ' ' . Words::NEW_VARIANT_PENDING['link'],
            Words::NEW_VARIANT_STATE['not_checked'] . ' ' . Words::ARRIVAL_LEFT['off'] . ' ' . Words::NEW_VARIANTS['site_heading'],
            Words::NEW_VARIANT_STATE['new'] . ' ' . Words::NEW_VARIANTS['is_new'] . ' ' . Words::say('NEW_VARIANTS', 'site_status', 'Bin'),
        ], array_map(static fn (int $n): string => self::row($all, $n)['state'], range(1, 9)), 'each row says what it is, and what the website said of it');
        self::assertSame(['New', 'Suggested match', 'No suggestion'], [Words::NEW_VARIANT_STATE['new'], Words::NEW_VARIANT_STATE['suggested'], Words::NEW_VARIANT_STATE['not_checked']]);
        self::assertStringNotContainsString(Words::BAND['New item'], (string) (new \DOMXPath($all->dom()))->evaluate('string(//table[contains(@class, "new-variants")])'),
            'this list says "New", in the owner\'s words');
        // A value that is no filter of this page is the default.
        self::assertSame(6, count(self::titles($web->send('GET', self::PATH, ['state' => 'linked'], []))));

        // Search, as Store Products: a word of the name or the brand, the option number whole, a barcode; within the filter shown.
        self::assertSame(['Plain mango'], self::titles($web->get(self::PATH, ['q' => 'mango'])));
        self::assertSame(['Plain mango'], self::titles($web->get(self::PATH, ['q' => 'elux'])));
        self::assertSame(['Plain mango'], self::titles($web->get(self::PATH, ['q' => '5056168800035'])));
        self::assertSame(['Matched lime'], self::titles($web->get(self::PATH, ['q' => '1005', 'state' => 'all'])));
        self::assertSame([], self::titles($web->get(self::PATH, ['q' => '100', 'state' => 'all'])), 'an option number is searched whole');
        self::assertSame([], self::titles($web->get(self::PATH, ['q' => 'lime'])), 'a matched one is not on the "still to match" list');
        $none = $web->get(self::PATH, ['q' => 'melon', 'state' => 'not_checked']);
        self::assertSame([], self::titles($none), 'the suggested one is not on the No suggestion list');
        self::assertStringContainsString(Words::NEW_VARIANTS['empty_search'], $none->text());
        self::assertContains(self::PATH . '?channel=alt&state=not_checked', $none->hrefs(), 'with the way out of the search');
        $found = $web->get(self::PATH, ['q' => 'melon', 'state' => 'suggested']);
        self::assertSame(['Suggested melon'], self::titles($found));
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'shown', 1, 1), $found->text());
        self::assertSame([['All not matched 6', false], ['New: the computer found nothing like it 2', false], ['Suggested match 2', true], ['No suggestion 2', false], ['All 9', false]],
            self::pills($found, 'state-pills'), 'the counts are the period\'s, not the search\'s');
        self::assertSame('melon', $found->form(self::PATH)['q']);
        self::assertSame(self::PATH . '.csv?channel=alt&state=suggested&q=melon', (new \DOMXPath($found->dom()))->evaluate('string(//form[@class="toolbar"]//a[contains(@href, ".csv")]/@href)'));
        self::assertSame(['Binned kiwi'], self::titles($web->get(self::PATH, ['q' => 'kiwi', 'state' => 'new'])), 'a search inside New');
        self::assertSame(['Plain mango of another store'], self::titles($web->get(self::PATH, ['channel' => 'vbig', 'q' => 'mango'])), 'one store at a time');

        // The counts of a period that holds every variant of the store come from the store's own narrow reads; a shorter period
        // is counted from its own rows: both ways say the same, and each filter's list is as long as its count.
        $q = new Queries(self::$db);
        $whole = $q->newVariantCounts((int) $alt->channelId, null);
        self::assertSame(['new' => 2, 'suggested' => 2, 'not_checked' => 2, 'linked' => 1, 'quarantined' => 0, 'waiting' => 1, 'ignored' => 1, 'landing' => 0], $whole);
        self::assertSame($whole, $q->newVariantCounts((int) $alt->channelId, '2020-01-01 00:00:00.000000'));
        $this->variant($alt, '1010', 'Very old one', 500 * 1440);
        $since = \CW\Clock::db(\CW\Clock::now()->modify('-30 days'));
        self::assertSame($whole, $q->newVariantCounts((int) $alt->channelId, $since), 'counted from the period\'s rows');
        self::assertSame(array_replace($whole, ['not_checked' => 3]), $q->newVariantCounts((int) $alt->channelId, null));
        foreach ([...Queries::NEW_FILTERS, null] as $filter) {
            foreach ([$since, null] as $from) {
                $counts = $q->newVariantCounts((int) $alt->channelId, $from);
                self::assertCount(Queries::newVariantTotal($counts, $filter), $q->newVariants((int) $alt->channelId, $from, $filter, '', null, $counts, 1000)['ids'],
                    json_encode([$filter, $from === null ? 'any time' : '30 days']));
            }
        }
    }

    public function testThePagerAndTheCsv(): void
    {
        ['alt' => $alt, 'cee' => $cee] = $this->stores();
        $sku = $this->item('legacy');
        $code = $this->codeOf($sku);
        for ($i = 1; $i <= 55; $i++) {
            $this->variant($cee, 'c' . $i, sprintf('Variant %02d', $i), $i);
        }
        $web = $this->signIn($this->uiUser('mapper'));
        $first = $web->get(self::PATH, ['channel' => 'cee']);
        self::assertSame(array_map(static fn (int $i): string => sprintf('Variant %02d', $i), range(1, 50)), self::titles($first), '50 a page, newest first');
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'shown', 55, 50), $first->text());
        self::assertStringContainsString(Words::say('QUEUE', 'page', 1, 2), $first->text());
        $xp = new \DOMXPath($first->dom());
        self::assertSame(self::PATH . '?channel=cee&page=2', $xp->evaluate('string(//nav[@class="pager"]/a[@rel="next"]/@href)'));
        self::assertSame('', $xp->evaluate('string(//nav[@class="pager"]/a[@rel="prev"]/@href)'));
        $second = $web->get(self::PATH, ['channel' => 'cee', 'page' => '2']);
        self::assertSame(['Variant 51', 'Variant 52', 'Variant 53', 'Variant 54', 'Variant 55'], self::titles($second));
        self::assertSame(self::PATH . '?channel=cee', (new \DOMXPath($second->dom()))->evaluate('string(//nav[@class="pager"]/a[@rel="prev"]/@href)'));
        self::assertSame('2', $second->form('/ui/review/bulk')['page'], 'a bulk action comes back to this page');
        self::assertSame(['Variant 51', 'Variant 52', 'Variant 53', 'Variant 54', 'Variant 55'], self::titles($web->get(self::PATH, ['channel' => 'cee', 'page' => '999999999999999999'])),
            'a page past the end is the last one');
        self::assertSame(50, count(self::titles($web->get(self::PATH, ['channel' => 'cee', 'page' => '0']))));
        self::assertSame(['Variant 51', 'Variant 52', 'Variant 53', 'Variant 54', 'Variant 55'], self::titles($web->get(self::PATH, ['channel' => 'cee', 'state' => 'not_checked', 'page' => '2'])),
            'a filter pages the same way');

        // The CSV: every variant of the store, period, filter and search (not only the page), newest first, in the page's words.
        $csv = $web->get(self::PATH . '.csv', ['channel' => 'cee']);
        self::assertSame(200, $csv->status);
        $file = 'new-variants-cee-' . gmdate('Ymd') . '.csv';
        self::assertSame(['text/csv; charset=utf-8', 'attachment; filename="' . $file . '"; filename*=UTF-8\'\'' . $file, 'sandbox'],
            [$csv->header('content-type'), $csv->header('content-disposition'), $csv->headerValues('content-security-policy')[0] ?? null]);
        self::assertStringStartsWith(CsvWriter::BOM, $csv->body);
        $lines = explode("\r\n", rtrim(substr($csv->body, strlen(CsvWriter::BOM)), "\r\n"));
        self::assertCount(56, $lines, 'the head and all 55, not only the page');
        self::assertSame(['store', 'option_number', 'product', 'variant', 'brand', 'barcodes', 'price', 'sold_30_days', 'sold_1_year', 'first_seen_utc', 'state', 'cw_number',
            'details'], str_getcsv($lines[0], ',', '"', ''));
        self::assertSame(['Cee Store', 'c1', 'Variant 01'], array_slice(str_getcsv($lines[1], ',', '"', ''), 0, 3));
        self::assertSame('c55', str_getcsv($lines[55], ',', '"', '')[1]);
        // The last line "Only the first … variants are in this file." is written only under a file that was cut: one more variant
        // than the file may hold is asked for, and the line follows when that one came back (never from a count read earlier).
        self::assertStringNotContainsString('Only the first', $csv->body, 'all 55 are in the file');
        $q = new Queries(self::$db);
        $store = ['id' => (int) $cee->channelId, 'name' => 'Cee Store'];
        $file = static fn (int $max, ?string $filter = null, string $text = ''): array => explode("\r\n", rtrim(substr(
            NewVariantsController::csvText($q, $store, null, $filter, $text, false, $max), strlen(CsvWriter::BOM)), "\r\n"));
        $cut = $file(54);
        self::assertCount(56, $cut, 'the head, the first 54 and the line that says so');
        self::assertSame(['c54', 'Only the first 54 variants are in this file.'], [str_getcsv($cut[54], ',', '"', '')[1], str_getcsv($cut[55], ',', '"', '')[2]]);
        foreach ([55, 56, 5000] as $max) {
            $whole = $file($max);
            self::assertCount(56, $whole, "a file that holds all 55 has no such line ({$max})");
            self::assertSame('c55', str_getcsv($whole[55], ',', '"', '')[1]);
        }
        self::assertSame(['c1', 'Only the first 1 variants are in this file.'], [str_getcsv($file(1, 'not_checked')[1], ',', '"', '')[1], str_getcsv($file(1, 'not_checked')[2], ',', '"', '')[2]],
            'a filter is cut the same way');
        self::assertCount(2, $file(1, null, 'Variant 07'), 'a search that finds one row: the head and the row');
        // The line is decided by the ids that came back, never by the counts read before them: handed counts that are wrong (as when
        // someone decides rows between the two reads), the file is right either way. Counts that say more than there is, under a
        // limit the 55 rows fit in: no line (a rule that compared the count with the rows would write it). Counts that say fewer
        // than there are, under a limit the rows do not fit in: the line (that rule would leave it out).
        $stale = static fn (int $max, int $said): array => explode("\r\n", rtrim(substr(NewVariantsController::csvText($q, $store, null, 'not_checked', '', false, $max,
            array_replace(array_fill_keys(Queries::NEW_KINDS, 0), ['not_checked' => $said])), strlen(CsvWriter::BOM)), "\r\n"));
        $over = $stale(60, 99);
        self::assertCount(56, $over, 'counts that overstate: the head and all 55, no line');
        self::assertSame(['c55', false], [str_getcsv($over[55], ',', '"', '')[1], str_contains(implode("\n", $over), 'Only the first')]);
        $under = $stale(54, 1);
        self::assertCount(56, $under, 'counts that understate: the head, the first 54 and the line');
        self::assertSame(['c54', 'Only the first 54 variants are in this file.'], [str_getcsv($under[54], ',', '"', '')[1], str_getcsv($under[55], ',', '"', '')[2]]);
        self::assertSame($file(54, 'not_checked'), $under, 'the same file as with the counts read just before');
        self::assertSame(1, substr_count(NewVariantsController::csvText($q, $store, null, null, '', true, 54), "\r\n"), 'a search that is not text: the head alone');

        $matched = $this->variant($alt, 'a1', 'Matched one', 5, ['variant_title' => '=cmd|x', 'brand' => 'Elux', 'barcodes' => ['5056168800042', '5056168800059'], 'units_30d' => 3, 'units_365d' => 9]);
        self::$db->exec('UPDATE listing_profile SET price = 12.50 WHERE listing_id = ?', [$matched]);
        self::$db->exec("UPDATE channel_listing SET created_at = '2021-03-01 08:15:30.250000' WHERE id = ?", [$matched]);
        $this->decide($this->staffUser('mapping_lead'), 'link', $matched, ['sku_id' => $sku, 'units_per_item' => 1]);
        $this->suggested($alt, 'a2', 'Suggested one', 10, 'Check', $sku);
        $this->variant($alt, 'a3', 'Plain one', 15);
        $this->found($alt, 'a4', 'Found new, in the bin', 20, ['features' => ['variant_status' => 'Bin']]);
        $rows = static function (UiResponse $r): array {
            $lines = explode("\r\n", rtrim(substr($r->body, strlen(CsvWriter::BOM)), "\r\n"));
            return array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), array_slice($lines, 1));
        };
        $all = $rows($web->get(self::PATH . '.csv', ['channel' => 'alt', 'state' => 'all', 'period' => 'all']));
        self::assertSame(['a2', 'a3', 'a4', 'a1'], array_column($all, 1));
        self::assertSame(['Alt Store', 'a1', 'Matched one', "'=cmd|x", 'Elux', '5056168800042 5056168800059', '12.50', '3', '9', '2021-03-01 08:15:30', Words::NEW_VARIANT_STATE['linked'], $code,
            Words::say('NEW_VARIANTS', 'matched_to', $code)], $all[3], 'a matched variant: its item, and a formula made harmless');
        self::assertSame([Words::NEW_VARIANT_STATE['suggested'], $code, Words::say('NEW_VARIANTS', 'suggested_item', Words::BAND['Check'], $code)], array_slice($all[0], 10));
        self::assertSame([Words::NEW_VARIANT_STATE['not_checked'], '', Words::ARRIVAL_LEFT['off']], array_slice($all[1], 10));
        self::assertSame([Words::NEW_VARIANT_STATE['new'], '', Words::NEW_VARIANTS['is_new'] . ' ' . Words::say('NEW_VARIANTS', 'site_status', 'Bin')], array_slice($all[2], 10),
            'and what the website said of it');
        self::assertSame(['a2', 'a3', 'a4'], array_column($rows($web->get(self::PATH . '.csv', ['channel' => 'alt', 'period' => 'all'])), 1), 'the filter is the file\'s too');
        self::assertSame(['a4'], array_column($rows($web->get(self::PATH . '.csv', ['channel' => 'alt', 'period' => 'all', 'state' => 'new'])), 1));
        self::assertSame(['a2'], array_column($rows($web->get(self::PATH . '.csv', ['channel' => 'alt', 'period' => 'all', 'state' => 'all', 'q' => 'suggested'])), 1), 'and the search');
        self::assertSame(['a2', 'a3', 'a4'], array_column($rows($web->get(self::PATH . '.csv', ['channel' => 'alt', 'state' => 'all', 'period' => '7'])), 1), 'and the period');
        // Everyone who sees the list may take its file.
        self::assertSame(200, $this->signIn($this->uiUser('viewer'))->get(self::PATH . '.csv', ['channel' => 'alt'])->status);
    }

    public function testTheActionBarOffersEachPersonOnlyWhatTheyMayDoAndSaysWhatTheSeedTakes(): void
    {
        ['alt' => $alt] = $this->stores();
        $sku = $this->item('legacy');
        $lead = $this->staffUser('mapping_lead');
        $plain = $this->variant($alt, '2001', 'Plain one', 10);
        $sug = $this->suggested($alt, '2002', 'Suggested one', 20, 'Key', $sku);
        $matched = $this->variant($alt, '2003', 'Matched one', 30);
        $this->decide($lead, 'link', $matched, ['sku_id' => $sku, 'units_per_item' => 1]);
        $ignored = $this->variant($alt, '2004', 'Ignored one', 40);
        $this->decide($lead, 'ignore', $ignored, ['reason' => 'a placeholder']);
        $new = $this->found($alt, '2005', 'Found new', 50);
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get(self::PATH);
        self::assertSame(['new_item', 'link', 'ignore'], self::barActions($page));
        $xp = new \DOMXPath($page->dom());
        self::assertSame([Words::NEW_VARIANT_ACTION['new_item'], Words::NEW_VARIANT_ACTION['link'], Words::NEW_VARIANT_ACTION['ignore']],
            array_map(static fn (\DOMNode $b): string => trim((string) $b->textContent), iterator_to_array($xp->query('//div[@data-bulkbar]//button[@name="action"]'))));
        self::assertSame('Create as new items', Words::NEW_VARIANT_ACTION['new_item'], 'the owner\'s words');
        self::assertSame((string) BulkDecisions::maxRows(self::$db), $xp->evaluate('string(//div[@data-bulkbar]/@data-max)'));
        $form = $page->form('/ui/review/bulk');
        self::assertSame(['new', 'alt'], [$form['source'], $form['channel']]);
        self::assertArrayNotHasKey('state', $form);
        // "Create as new items" is offered on every filter, and one line beside the bar says which rows it takes.
        foreach ([[], ['state' => 'new'], ['state' => 'suggested'], ['state' => 'not_checked'], ['state' => 'all']] as $filter) {
            $list = $web->get(self::PATH, $filter);
            self::assertContains('new_item', self::barActions($list), json_encode($filter));
            self::assertStringContainsString(Words::NEW_VARIANTS['seed_note'], $list->text(), json_encode($filter));
        }
        self::assertStringContainsString('takes only the rows marked New', Words::NEW_VARIANTS['seed_note']);
        // Confirm match is left out where no row has a suggested match.
        self::assertSame(['new_item', 'ignore'], self::barActions($web->get(self::PATH, ['state' => 'new'])));
        self::assertSame(['new_item', 'ignore'], self::barActions($web->get(self::PATH, ['state' => 'not_checked'])));
        self::assertSame(['new_item', 'link', 'ignore'], self::barActions($web->get(self::PATH, ['state' => 'suggested'])));
        $all = $web->get(self::PATH, ['state' => 'all', 'period' => '90']);
        self::assertSame(['new_item', 'link', 'ignore'], self::barActions($all));
        self::assertSame(['all', '90'], [$all->form('/ui/review/bulk')['state'], $all->form('/ui/review/bulk')['period']], 'a bulk action comes back to the list as it was');
        // Only the rows an action applies to can be ticked: a matched or ignored variant changes on its own page.
        $boxes = static fn (UiResponse $r): array => array_map(static fn (\DOMElement $i): string => $i->getAttribute('name'),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//input[@data-bulk-row]')));
        self::assertSame(['pick_' . $plain, 'pick_' . $sug, 'pick_' . $new], $boxes($all));
        self::assertSame(0, (new \DOMXPath($all->dom()))->query('//input[@data-bulk-row][@checked]')->length);
        // "Select all on this page" works without the script: the page comes back ticked.
        $tickedAll = $web->get(self::PATH, ['state' => 'all', 'all' => '1']);
        self::assertSame(3, (new \DOMXPath($tickedAll->dom()))->query('//input[@data-bulk-row][@checked]')->length);
        self::assertSame(self::PATH . '?channel=alt&period=90&state=all&all=1', (new \DOMXPath($all->dom()))->evaluate('string(//a[@data-bulk-all-link]/@href)'));
        // Confirm match is offered while the existing setting allows any strength at all.
        $this->setting('mapping.bulk_confirm_bands', '""');
        self::assertSame(['new_item', 'ignore'], self::barActions($web->get(self::PATH, ['state' => 'all'])));

        // Someone who can only look sees the list, no tick boxes and no bar; other jobs do not see the page.
        $viewer = $this->signIn($this->uiUser('viewer'));
        $look = $viewer->get(self::PATH, ['state' => 'all']);
        self::assertSame(200, $look->status, $look->describe());
        self::assertSame(['Plain one', 'Suggested one', 'Matched one', 'Ignored one', 'Found new'], self::titles($look));
        self::assertFalse($look->hasForm('/ui/review/bulk'));
        self::assertSame([], $boxes($look));
        self::assertSame(0, (new \DOMXPath($look->dom()))->query('//div[@data-bulkbar]')->length);
        self::assertStringNotContainsString(Words::NEW_VARIANTS['seed_note'], $look->text());
        self::assertStringContainsString(sprintf(Words::UI['look_only'], Words::whoCan('mapping.decide')), $look->text());
        self::assertContains('/ui/review/listing/' . $plain, $look->hrefs(), 'Open is for everyone who sees the list');
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame([403, 403], [$buyer->get(self::PATH)->status, $buyer->get(self::PATH . '.csv')->status]);
        self::assertNotContains('New Variants', self::tabLabels($buyer->get('/ui/items/cards')));
    }

    public function testCreateAsNewItemsTakesOnlyWhatTheComputerFoundNewAndSkipsEveryOtherRowWithItsReason(): void
    {
        ['alt' => $alt, 'big' => $big] = $this->stores();
        $sku = $this->item('legacy');
        $code = $this->codeOf($sku);
        $lead = $this->staffUser('mapping_lead');
        // Two the computer checked and found new: the only rows the seed takes. One says it is published on the website.
        $one = $this->found($alt, '3001', 'Elux Legend 3500', 10, ['variant_title' => 'Elux Legend 3500 Blue Razz', 'brand' => 'Elux',
            'features' => ['form' => 'disposable', 'strength_mg' => 20, 'flavour_tokens' => ['blue', 'razz'], 'variant_status' => 'Published', 'is_placeholder' => false]]);
        $two = $this->found($alt, '3002', 'Plain two', 11);
        // Not checked by the computer, whatever came before: never seen; sent back for matching. And one made into an item and
        // undone: it has no suggestion either, but an item was made from it, and that is what its row and its skip say.
        $neverSeen = $this->variant($alt, '3003', 'Never checked', 12);
        $undone = $this->found($alt, '3004', 'Seeded, then undone', 13);
        $this->decide($lead, 'new_item', $undone, ['proposal_id' => $this->proposalOf($undone), 'units_per_item' => 1]);
        $firstItem = (int) $this->link($undone)['sku_id'];
        $this->decide($lead, 'unlink', $undone, ['reason' => 'made by mistake']);
        $sentBack = $this->suggested($alt, '3005', 'Sent back for matching', 14, 'Key', $sku);
        $this->decide($lead, 'send_back', $sentBack, ['proposal_id' => $this->proposalOf($sentBack)]);
        // Found new, but the website said: a page heading; in the bin. And a page heading the computer never checked.
        $heading = $this->found($alt, '3006', 'Disposables', 15, ['features' => ['is_placeholder' => true, 'placeholder_reason' => 'landing_row', 'variant_status' => 'Published']]);
        $binned = $this->found($alt, '3007', 'Binned copy', 16, ['features' => ['is_placeholder' => false, 'variant_status' => 'Bin']]);
        $headingToo = $this->variant($alt, '3008', 'Another heading', 17, ['features' => ['is_placeholder' => true]]);
        // Found new, but its barcode is elsewhere: on an item; on another store's variant that holds it with a leading zero; and a
        // 12-digit code that the other variant holds padded to 13.
        $onItem = $this->found($alt, '3009', 'Barcode on an item', 18, ['barcodes' => ['5056168800066']]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id) VALUES ('5056168800066', ?)", [$sku]);
        $onVariant = $this->found($alt, '3010', 'Barcode on another variant', 19, ['barcodes' => ['5056168800073']]);
        $elsewhere = $this->variant($big, '8001', 'The other variant', 14, ['barcodes' => ['05056168800073']]);
        $padded = $this->found($alt, '3011', 'A shorter code, padded elsewhere', 20, ['barcodes' => ['012345678905']]);
        $this->variant($big, '8002', 'Its twin', 14, ['barcodes' => ['0012345678905']]);
        // The computer suggests an item; one that changes while the page is open; and rows that have no tick box at all.
        $suggestedMatch = $this->suggested($alt, '3012', 'Suggested match', 21, 'Key', $sku);
        $stale = $this->found($alt, '3013', 'Ignored while the page was open', 22);
        $matched = $this->variant($alt, '3014', 'Already matched', 23);
        $this->decide($lead, 'link', $matched, ['sku_id' => $sku, 'units_per_item' => 1]);
        $waiting = $this->variant($alt, '3015', 'Waiting for a second OK', 24);
        $this->decide($lead, 'link', $waiting, ['sku_id' => $sku, 'units_per_item' => 6]);
        $ignored = $this->variant($alt, '3016', 'Already ignored', 25);
        $this->decide($lead, 'ignore', $ignored, ['reason' => 'a placeholder']);
        $skusBefore = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $me = $this->uiUser('mapper');
        $web = $this->signIn($me);

        $page = $web->get(self::PATH, ['state' => 'all']);
        // Before ticking, each row says what it is: the sent-back one reads "No suggestion", as the never-seen one; the undone one
        // names the item that was made from it.
        $said = [];
        foreach (range(1, 16) as $n) {
            $said[self::row($page, $n)[Words::NEW_VARIANTS['option']]] = self::row($page, $n)['state'];
        }
        self::assertSame(array_fill(0, 2, Words::NEW_VARIANT_STATE['not_checked'] . ' ' . Words::ARRIVAL_LEFT['off']), [$said['3003'], $said['3005']]);
        self::assertSame(Words::NEW_VARIANT_STATE['not_checked'] . ' An item was already made from this variant (' . $this->codeOf($firstItem) . '): open it to decide.', $said['3004']);
        self::assertSame(Words::NEW_VARIANT_STATE['new'] . ' ' . Words::NEW_VARIANTS['is_new'], $said['3001'], 'published on the website: no note');
        self::assertSame(Words::NEW_VARIANT_STATE['new'] . ' ' . Words::NEW_VARIANTS['is_new'] . ' ' . Words::NEW_VARIANTS['site_heading'], $said['3006']);
        $ticked = [$one, $two, $neverSeen, $undone, $sentBack, $heading, $binned, $headingToo, $onItem, $onVariant, $padded, $suggestedMatch, $stale];
        $form = self::ticked($page, $ticked, 'new_item');
        // A matched, a waiting and an ignored variant have no tick box; a form that names them anyway (an old page, a crafted one)
        // is answered row by row. So is a variant of another store.
        foreach ([$matched, $waiting, $ignored, $elsewhere] as $id) {
            self::assertArrayNotHasKey('ver_' . $id, $form);
            $form += ['pick_' . $id => '1', 'ver_' . $id => (string) $this->version($id), 'prop_' . $id => ''];
        }
        $this->decide($lead, 'ignore', $stale, ['reason' => 'decided meanwhile', 'proposal_id' => $this->proposalOf($stale)]);
        $r = $web->post('/ui/review/bulk', $form);
        $batch = self::batchOf($r);

        $expected = [
            $one => ['done', null], $two => ['done', null],
            $neverSeen => ['skipped', 'not_checked'], $undone => ['skipped', 'made_before'], $sentBack => ['skipped', 'not_checked'],
            $heading => ['skipped', 'page_heading'], $binned => ['skipped', 'not_published'], $headingToo => ['skipped', 'page_heading'],
            $onItem => ['skipped', 'barcode_elsewhere'], $onVariant => ['skipped', 'barcode_elsewhere'], $padded => ['skipped', 'barcode_elsewhere'],
            $suggestedMatch => ['skipped', 'suggested_match'], $stale => ['skipped', 'changed'],
            $matched => ['skipped', 'linked'], $waiting => ['skipped', 'pending_second'], $ignored => ['skipped', 'not_waiting'], $elsewhere => ['skipped', 'other_store'],
        ];
        ksort($expected); // the rows are done in listing order, whatever the order they were ticked in
        self::assertSame($expected, self::batchRows($batch));
        // Each done row has its own new item, made from the variant, and is matched to it: one decision each, through DecisionService.
        self::assertSame($skusBefore + 2, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'two new items, none for a skipped row');
        $made = [];
        foreach ([$one, $two] as $id) {
            $link = $this->link($id);
            self::assertSame(['mapped', 1], [$link['status'], (int) $link['units_per_item']]);
            self::assertNotSame($sku, (int) $link['sku_id']);
            $made[$id] = self::$db->one('SELECT id, code, name, brand, origin_listing_id FROM sku WHERE id = ?', [$link['sku_id']]);
            self::assertSame(sprintf('CW-%06d', $made[$id]['id']), $made[$id]['code']);
            self::assertSame($id, (int) $made[$id]['origin_listing_id']);
            $d = self::$db->one('SELECT action, state, bulk_batch_id, decided_by FROM match_decision WHERE listing_id = ? ORDER BY id DESC LIMIT 1', [$id]) ?? [];
            self::assertSame(['new_item', 'applied', 'screen:' . $batch, (int) $me['id']], [(string) $d['action'], (string) $d['state'], (string) $d['bulk_batch_id'], (int) $d['decided_by']]);
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE open_listing_id = ?', [$id]), 'its suggestion is settled');
        }
        self::assertCount(2, array_unique(array_column($made, 'id')), 'an item each');
        self::assertSame(['Elux Legend 3500 Blue Razz', 'Elux'], [$made[$one]['name'], $made[$one]['brand']], 'the variant\'s own name and details');
        self::assertSame('Plain two', $made[$two]['name']);
        // Nothing changed on a skipped row: no second item for the variant whose seed was undone, none for the one sent back.
        self::assertSame(['unmapped', 'unmapped', 'unmapped', 'suggested', 'suggested', 'unmapped', 'suggested', 'suggested', 'suggested', 'suggested', 'ignored', 'mapped', 'unmapped',
            'ignored', 'unmapped'], array_map(fn (int $id): string => $this->link($id)['status'],
            [$neverSeen, $undone, $sentBack, $heading, $binned, $headingToo, $onItem, $onVariant, $padded, $suggestedMatch, $stale, $matched, $waiting, $ignored, $elsewhere]));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sku WHERE origin_listing_id = ?', [$undone]), 'the item of the undone seed is still the only one made from it');
        self::assertSame($firstItem, (int) self::$db->value('SELECT id FROM sku WHERE origin_listing_id = ?', [$undone]));
        self::assertSame($sku, (int) $this->link($matched)['sku_id']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE open_listing_id = ?', [$suggestedMatch]), 'its suggestion is still open');
        $b = self::$db->one('SELECT action, source, channel_id, band, rows_asked FROM mapping_batch WHERE id = ?', [$batch]) ?? [];
        self::assertSame(['new_item', 'new', (int) $alt->channelId, null, 17], [(string) $b['action'], (string) $b['source'], (int) $b['channel_id'], $b['band'], (int) $b['rows_asked']]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.bulk' AND entity_id = ?", [(string) $batch]));

        // The result page: the count in the owner's words, every skipped row with why and a link, and the way back to New Variants.
        $result = $web->follow($r);
        self::assertSame(200, $result->status, $result->describe());
        $text = $result->text();
        self::assertStringContainsString('Done: 2 new items created and matched, 0 sent to Second approval, 15 skipped.', $text);
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('NEW_VARIANT_DONE', 'new_item', 2), 0, 15), $text);
        foreach (['Nothing is suggested for this variant. Open it and decide.', 'It is a page heading on the website, not something that is sold. Open it and decide.',
            'An item was already made from this variant (' . $this->codeOf($firstItem) . '): open it and decide.',
            'It is not published on the website (Bin). Open it and decide.', BulkController::why('barcode_elsewhere', []),
            'The computer suggests it is ' . $code . ': open it and decide.', BulkController::why('changed', ['status' => 'ignored']),
            BulkController::why('linked', []), BulkController::why('pending_second', []), BulkController::why('not_waiting', ['status' => 'ignored']),
            BulkController::why('other_store', [])] as $why) {
            self::assertStringContainsString($why, $text);
        }
        self::assertSame(['Nothing is suggested for this variant. Open it and decide.', 'It is not published on the website (Bin). Open it and decide.'],
            [BulkController::why('not_checked', []), BulkController::why('not_published', ['site_status' => 'Bin'])]);
        $xp = new \DOMXPath($result->dom());
        self::assertSame(15, $xp->query('//table[contains(@class, "bulk-skipped")]/tbody/tr')->length);
        self::assertSame(17, $xp->query('//table[contains(@class, "bulk-rows")]/tbody/tr')->length, 'every row of the batch');
        self::assertContains('/ui/review/listing/' . $neverSeen, array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array($xp->query('//table[contains(@class, "bulk-skipped")]//th/a'))), 'a skipped row links to its page: open it and decide');
        self::assertSame(self::PATH . '?channel=alt', $xp->evaluate('string(//p[@class="crumbs"]/a/@href)'));
        self::assertSame(Words::MENU['new_variants'], trim((string) $xp->evaluate('string(//p[@class="crumbs"]/a)')));
        self::assertSame(Words::NEW_VARIANT_ACTION['new_item'], trim((string) $xp->evaluate('string(//dl[@class="wide"]/dd[1])')), 'What: in this list\'s words');
        self::assertSame(Words::MENU['new_variants'] . ' · Alt Store', trim((string) preg_replace('/\s+/u', ' ', (string) $xp->evaluate('string(//dl[@class="wide"]/dd[3])'))), 'Where');
        self::assertStringNotContainsString(Words::BULK_ACTION['new_item'], $text, 'this list says items');
        // Back on the list the new items show as matched, and the New filter holds what was skipped; a viewer may read the result too.
        $after = $web->get(self::PATH, ['state' => 'all', 'q' => 'Plain two']);
        self::assertStringContainsString(Words::say('NEW_VARIANTS', 'matched_to', (string) $made[$two]['code']), self::row($after, 1)['state']);
        self::assertSame(['Disposables', 'Binned copy', 'Barcode on an item', 'Barcode on another variant', 'A shorter code, padded elsewhere'], self::titles($web->get(self::PATH, ['state' => 'new'])));
        self::assertSame(200, $this->signIn($this->uiUser('viewer'))->get('/ui/review/batches/' . $batch)->status);
        // A variant the bulk seed will not take can still be made into an item one at a time, on its own page, where its details show.
        $single = $this->signIn($this->uiUser('mapper'));
        $own = $single->get('/ui/review/listing/' . $neverSeen);
        self::assertSame(200, $own->status, $own->describe());
        $decide = $own->form('/ui/review/listing/' . $neverSeen . '/decide');
        $ok = $single->post('/ui/review/listing/' . $neverSeen . '/decide', ['action' => 'new_item', 'units_per_item' => '1'] + $decide);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame('mapped', $this->link($neverSeen)['status'], 'Open, then "create a new product" there: still one person\'s decision');
    }

    /**
     * The seed is not tied to one store: a variant a computer check marked New is made into an item on whichever store it is. The
     * suggestions are written here by the test, on every test store. In production only a check of a store's list writes them
     * (tools/first_match + bin/import_proposals.php), and that check compares another store's list with the main store's: no tool
     * marks a main-store variant New, so there the seed has nothing to take (the next test; docs/decisions.md U138).
     */
    public function testTheSeedTakesAVariantMarkedNewOnWhicheverStoreItIsAndKeepsEveryRuleOfASingleDecision(): void
    {
        ['alt' => $alt, 'big' => $big, 'cee' => $cee] = $this->stores();
        $made = [];
        $web = $this->signIn($this->uiUser('mapper'));
        foreach (['alt' => $alt, 'vbig' => $big, 'cee' => $cee] as $code => $site) {
            $v = $this->found($site, 'S-' . $code, 'New on ' . $code, 5);
            $page = $web->get(self::PATH, ['channel' => $code]);
            self::assertStringNotContainsString('has not marked any variant', $page->text(), "{$code}: a store with a variant marked New is not told the check does not cover it");
            $batch = self::batchOf($web->post('/ui/review/bulk', self::ticked($page, [$v], 'new_item')));
            self::assertSame([$v => ['done', null]], self::batchRows($batch), $code);
            self::assertSame('mapped', $this->link($v)['status'], "{$code}: whichever store the variant marked New is on");
            $made[] = (int) $this->link($v)['sku_id'];
            self::assertSame((int) $site->channelId, (int) self::$db->value('SELECT channel_id FROM mapping_batch WHERE id = ?', [$batch]));
        }
        self::assertCount(3, array_unique($made));
        // A variant with no name cannot become an item: skipped, with what to do.
        $nameless = $this->found($alt, 'S-none', '', 6);
        self::$db->exec('UPDATE listing_profile SET product_title = NULL, variant_title = NULL WHERE listing_id = ?', [$nameless]);
        $batch = self::batchOf($web->post('/ui/review/bulk', self::ticked($web->get(self::PATH), [$nameless], 'new_item')));
        self::assertSame([$nameless => ['skipped', 'no_name']], self::batchRows($batch));
        // With the owner's switch on, a new item made from a ticked list waits for a second matching lead: nothing is made yet.
        $this->setting('approvals.mapping_bulk_second_ok', 'true');
        $waits = $this->found($alt, 'S-wait', 'Waits for a second OK', 7);
        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $r = $web->post('/ui/review/bulk', self::ticked($web->get(self::PATH), [$waits], 'new_item'));
        $batch = self::batchOf($r);
        self::assertSame([$waits => ['pending_second', null]], self::batchRows($batch));
        self::assertSame(['suggested', $skus], [$this->link($waits)['status'], (int) self::$db->value('SELECT COUNT(*) FROM sku')]);
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('NEW_VARIANT_DONE', 'new_item', 0), 1, 0), $web->follow($r)->text());
        self::assertSame(Words::NEW_VARIANT_STATE['waiting'] . ' ' . Words::NEW_VARIANT_PENDING['new_item'], self::row($web->get(self::PATH, ['state' => 'all', 'q' => 'S-wait']), 1)['state']);
        // More rows than the existing setting allows: refused whole, nothing saved.
        $this->setting('approvals.mapping_bulk_second_ok', 'false');
        $this->setting('mapping.bulk_max_rows', '1');
        $a = $this->found($alt, 'S-a', 'One of two', 8);
        $b = $this->found($alt, 'S-b', 'Two of two', 9);
        $batches = (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch');
        $tooMany = $web->post('/ui/review/bulk', self::ticked($web->get(self::PATH), [$a, $b], 'new_item'));
        self::assertSame(422, $tooMany->status, $tooMany->describe());
        self::assertStringContainsString(Words::say('BULK_ERROR', 'too_many_rows', 2, 1), $tooMany->text());
        self::assertSame([$batches, 'suggested', 'suggested'], [(int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'), $this->link($a)['status'], $this->link($b)['status']]);
        self::assertContains(self::PATH . '?channel=alt', $tooMany->hrefs(), 'with the way back to the list');
    }

    /**
     * A store where no computer check has ever marked a variant New (in production: the main store, which the check never judges,
     * and a store no check was run for): the box says so, and that each variant's item is created on its own page; "Create as new
     * items" skips every row there, with why. Read from the suggestions there are, of every state: one that was decided, or
     * replaced by a later check's, still shows that a check covers the store.
     */
    public function testAStoreWhereNoCheckEverMarkedAVariantNewIsToldSoAndTheSeedSkipsItsRows(): void
    {
        ['alt' => $alt, 'big' => $big, 'cee' => $cee] = $this->stores();
        $sku = $this->item('legacy');
        $lead = $this->staffUser('mapping_lead');
        // Alt Store: one variant marked New. Big Store: variants nobody checked, and one with a suggested match. Cee Store: its only
        // "new" suggestion was decided (ignored) long ago, and another was replaced by a later check's suggested match.
        $this->found($alt, 'A1', 'Marked new', 10);
        $plain = $this->variant($big, 'B1', 'Nobody checked this one', 10);
        $matchOnly = $this->suggested($big, 'B2', 'A suggested match only', 11, 'Check', $sku);
        $old = $this->found($cee, 'C1', 'Marked new, then ignored', 10);
        $this->decide($lead, 'ignore', $old, ['reason' => 'a placeholder', 'proposal_id' => $this->proposalOf($old)]);
        $replaced = $this->found($cee, 'C2', 'Marked new, then matched by a later check', 11);
        $this->propose($replaced, 'Check', $sku, [], true, 'later-run');
        $this->variant($cee, 'C3', 'Not checked', 12);
        $q = new Queries(self::$db);
        self::assertSame([true, false, true, false], [$q->everFoundNew((int) $alt->channelId), $q->everFoundNew((int) $big->channelId), $q->everFoundNew((int) $cee->channelId),
            $q->everFoundNew(999999)]);
        self::assertSame(0, $q->newVariantCounts((int) $cee->channelId, null)['new'], 'Cee Store has no row marked New now');

        $web = $this->signIn($this->uiUser('mapper'));
        $never = Words::say('NEW_VARIANTS', 'never_new', 'Big Store');
        self::assertSame('The computer check has not marked any variant of Big Store as New so far (it may not cover this store yet), so "Create as new items" will skip every row here. '
            . 'To make an item from one of its variants, open the variant and create the item on its own page.', $never);
        $page = $web->get(self::PATH, ['channel' => 'vbig']);
        self::assertSame($never, trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//div[contains(@class, "where-from")]/p[3])')), 'in the box at the top');
        foreach (['alt' => 'Alt Store', 'cee' => 'Cee Store'] as $code => $name) {
            $text = $web->get(self::PATH, ['channel' => $code, 'state' => 'all'])->text();
            self::assertStringNotContainsString('has not marked any variant', $text, $name);
        }
        self::assertStringContainsString($never, $this->signIn($this->uiUser('viewer'))->get(self::PATH, ['channel' => 'vbig'])->text(), 'everyone who sees the list is told');
        // The rule is not changed for such a store: the button is there, and it skips each row with its reason.
        self::assertSame(['new_item', 'link', 'ignore'], self::barActions($page));
        $r = $web->post('/ui/review/bulk', self::ticked($page, [$plain, $matchOnly], 'new_item'));
        self::assertSame([$plain => ['skipped', 'not_checked'], $matchOnly => ['skipped', 'suggested_match']], self::batchRows(self::batchOf($r)));
        self::assertStringContainsString(Words::BULK_SKIP['not_checked'], $web->follow($r)->text());
        // Its one way to an item: the variant's own page.
        $own = $web->get('/ui/review/listing/' . $plain);
        $ok = $web->post('/ui/review/listing/' . $plain . '/decide', ['action' => 'new_item', 'units_per_item' => '1'] + $own->form('/ui/review/listing/' . $plain . '/decide'));
        self::assertSame([303, 'mapped'], [$ok->status, $this->link($plain)['status']], $ok->describe());
        self::assertStringContainsString($never, $web->get(self::PATH, ['channel' => 'vbig', 'state' => 'all'])->text(), 'an item made by hand is not the check\'s word');
        // The read is the suggestions' own index (status, strength, variant), never a suggestion's row, then the variant by its key.
        $plan = self::$db->all("EXPLAIN SELECT /*+ JOIN_ORDER(p, cl) INDEX(p ix_match_proposal_open_band) */ 1 FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id "
            . "WHERE p.status IN ('open', 'decided', 'superseded') AND p.band = 'New item' AND cl.channel_id = ? LIMIT 1", [$big->channelId]);
        self::assertSame([['p', 'ix_match_proposal_open_band'], ['cl', 'PRIMARY']], [[$plan[0]['table'], $plan[0]['key']], [$plan[1]['table'], $plan[1]['key']]]);
        self::assertStringContainsString('Using index', (string) $plan[0]['Extra']);
    }

    /**
     * Round 2b (M61): what else the seed skips, and that a row says so before it is ticked wherever the list can know it: an item
     * was already made from the variant (whatever a later check says), it changed on the website after the check, it was not
     * published when the check saw it. A name an item already has, or two ticked rows share, is found when the rows are ticked.
     */
    public function testTheSeedSkipsWhatWasMadeBeforeChangedOrSharesANameAndTheRowSaysSoBeforeItIsTicked(): void
    {
        ['alt' => $alt, 'big' => $big] = $this->stores();
        $sku = $this->item('legacy');
        $lead = $this->staffUser('mapping_lead');
        $new = ['proposed_new_item' => 1];
        // An item was made from it, the match was undone, and a later check says "new" again.
        $again = $this->found($alt, '6001', 'Made before, new again', 10);
        $this->decide($lead, 'new_item', $again, ['proposal_id' => $this->proposalOf($again), 'units_per_item' => 1]);
        $firstCode = $this->codeOf((int) $this->link($again)['sku_id']);
        $this->decide($lead, 'unlink', $again, ['reason' => 'made by mistake']);
        $this->propose($again, 'New item', null, $new, true, 'later-run');
        // The same, where the later check suggests a match: the item made before is a note under the suggestion.
        $againMatch = $this->found($alt, '6002', 'Made before, now a suggested match', 11);
        $this->decide($lead, 'new_item', $againMatch, ['proposal_id' => $this->proposalOf($againMatch), 'units_per_item' => 1]);
        $secondCode = $this->codeOf((int) $this->link($againMatch)['sku_id']);
        $this->decide($lead, 'unlink', $againMatch, ['reason' => 'made by mistake']);
        $this->propose($againMatch, 'Check', $sku, [], true, 'later-run');
        // Renamed on the website after the check; and one that was in the bin when the check saw it, then renamed (its features are
        // gone with the rename: what the check recorded still says "Bin").
        $renamed = $this->found($alt, '6003', 'Before the rename', 12);
        $binned = $this->suggested($alt, '6004', 'In the bin, before the rename', 13, 'New item', null, $new + ['evidence' => ['variant_status' => 'Bin']],
            ['features' => ['variant_status' => 'Bin', 'is_placeholder' => false]]);
        $ingest = new \CW\Mapping\ListingIngestService(self::$db);
        $ingest->ingest(Caller::system('test'), (int) $alt->channelId, [['variant_id' => '6003', 'product_title' => 'After the rename'],
            ['variant_id' => '6004', 'product_title' => 'In the bin, after the rename']]);
        self::assertNull(self::$db->value('SELECT features FROM listing_profile WHERE listing_id = ?', [$binned]));
        // A name an item already has (made from another store's variant), typed with other capitals; two ticked rows of one name.
        $elsewhere = $this->found($big, '8101', 'Hayati Pro Max Cola', 5);
        $this->decide($lead, 'new_item', $elsewhere, ['proposal_id' => $this->proposalOf($elsewhere), 'units_per_item' => 1]);
        $nameCode = $this->codeOf((int) $this->link($elsewhere)['sku_id']);
        $sameName = $this->found($alt, '6005', 'HAYATI Pro Max cola', 14);
        $twinA = $this->found($alt, '6006', 'IVG 2400 Mint', 15);
        $twinB = $this->found($alt, '6007', 'ivg 2400 mint', 16);
        // The computer could not say what it is; and one it found new that nothing is wrong with.
        $unsure = $this->suggested($alt, '6008', 'Could be two things', 17, "Can't tell", null);
        $good = $this->found($alt, '6009', 'Nothing wrong with this one', 18);
        // Renamed between the check's export and its import: the suggestion's record is on the title of now, and only the title
        // the computer judged (its evidence) shows that it checked something else.
        $judgedOther = $this->suggested($alt, '6010', 'The title it has now', 19, 'New item', null, $new + ['evidence' => ['title' => 'The title the computer judged']]);
        // A strong match to a variant of the main store that has no item yet: a match with no item to name.
        $noItem = $this->suggested($alt, '6011', 'Matched to a variant with no item', 20, 'Key', null, ['flags' => ['target_not_minted']]);
        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get(self::PATH);
        $said = [];
        foreach (range(1, 11) as $n) {
            $said[self::row($page, $n)[Words::NEW_VARIANTS['option']]] = self::row($page, $n)['state'];
        }
        $chip = Words::NEW_VARIANT_STATE['new'];
        self::assertSame([
            '6001' => $chip . ' An item was already made from this variant (' . $firstCode . '): open it to decide.',
            '6002' => Words::NEW_VARIANT_STATE['suggested'] . ' ' . Words::say('NEW_VARIANTS', 'suggested_item', Words::BAND['Check'], $this->codeOf($sku)) . ' '
                . self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]) . ' An item was already made from this variant (' . $secondCode . '): open it to decide.',
            '6003' => $chip . ' It changed on the website after the computer checked it: open it to decide.',
            '6004' => $chip . ' It changed on the website after the computer checked it: open it to decide. Not published on the website (Bin).',
            '6005' => $chip . ' When the computer checked it, it found nothing like it.',
            '6006' => $chip . ' When the computer checked it, it found nothing like it.',
            '6007' => $chip . ' When the computer checked it, it found nothing like it.',
            '6008' => Words::NEW_VARIANT_STATE['suggested'] . ' ' . Words::BAND["Can't tell"],
            '6009' => $chip . ' When the computer checked it, it found nothing like it.',
            '6010' => $chip . ' It changed on the website after the computer checked it: open it to decide.',
            '6011' => Words::NEW_VARIANT_STATE['suggested'] . ' Strong match, to a variant of the main store that has no item yet: open it to decide.',
        ], $said, 'each row says what the seed will find, where the list can know it');
        $ids = [$again, $againMatch, $renamed, $binned, $sameName, $twinA, $twinB, $unsure, $good, $judgedOther, $noItem];
        $r = $web->post('/ui/review/bulk', self::ticked($page, $ids, 'new_item'));
        $batch = self::batchOf($r);
        self::assertSame([$again => ['skipped', 'made_before'], $againMatch => ['skipped', 'made_before'], $renamed => ['skipped', 'changed_since_check'],
            $binned => ['skipped', 'not_published'], $sameName => ['skipped', 'name_elsewhere'], $twinA => ['skipped', 'name_twice'], $twinB => ['skipped', 'name_twice'],
            $unsure => ['skipped', 'not_new'], $good => ['done', null], $judgedOther => ['skipped', 'changed_since_check'], $noItem => ['skipped', 'match_no_item']],
            self::batchRows($batch));
        self::assertSame($skus + 1, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'one item, for the one row nothing is wrong with');
        self::assertSame([1, 1], [(int) self::$db->value('SELECT COUNT(*) FROM sku WHERE origin_listing_id = ?', [$again]),
            (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Hayati Pro Max Cola'")], 'no second item from the variant, none of the name');
        $text = $web->follow($r)->text();
        self::assertStringContainsString('Done: 1 new items created and matched, 0 sent to Second approval, 10 skipped.', $text);
        self::assertStringContainsString('The computer matched it to a variant of the main store that has no item yet: open it and decide.', $text);
        self::assertStringNotContainsString('could not say what it is (' . Words::BAND['Key'] . ')', $text, 'a strong match is not "could not say"');
        self::assertSame(4, substr_count($text, 'It changed on the website after the computer checked it: open it and decide.'),
            'the renamed one and the one judged under another title (each in the skipped list and in the list of every row)');
        foreach (['An item was already made from this variant (' . $firstCode . '): open it and decide.', 'An item was already made from this variant (' . $secondCode . '): open it and decide.',
            'It changed on the website after the computer checked it: open it and decide.', 'It is not published on the website (Bin). Open it and decide.',
            'An item with the same name is already in the warehouse (' . $nameCode . '): open it and decide.',
            'Another row you ticked has the same name, so the two may be one item: open each and decide.',
            'The computer could not say what it is (Not sure – you choose): open it and decide.'] as $why) {
            self::assertStringContainsString($why, $text);
        }
        self::assertStringNotContainsString('may be confirmed together', $text, 'a row ticked for Create is not explained with the confirm sentence');
        self::assertSame(['An item was already made from this variant (an item you already have): open it and decide.', 'The computer could not say what it is (unknown): open it and decide.'],
            [BulkController::why('made_before', []), BulkController::why('not_new', [])], 'a record without its detail still reads as a sentence');
        // The line beside the bar names these reasons too; and one at a time, on its own page, a person may still make the item.
        foreach (['an item already made from it', 'changed on the website after the check', 'a name that an item already has or that two ticked rows share',
            'one the computer could not decide', 'a variant of the main store that has no item yet'] as $reason) {
            self::assertStringContainsString($reason, $page->text());
        }
        $own = $web->get('/ui/review/listing/' . $twinA);
        $ok = $web->post('/ui/review/listing/' . $twinA . '/decide', ['action' => 'new_item', 'units_per_item' => '1'] + $own->form('/ui/review/listing/' . $twinA . '/decide'));
        self::assertSame([303, 'mapped'], [$ok->status, $this->link($twinA)['status']], $ok->describe());
        // The CSV says the same of a row as the page.
        $csv = $web->get(self::PATH . '.csv', ['q' => '6004'])->body;
        self::assertStringContainsString('It changed on the website after the computer checked it: open it to decide. Not published on the website (Bin).', $csv);
    }

    public function testConfirmMatchAndIgnoreFromThisListAreTheExistingBulkActions(): void
    {
        ['alt' => $alt] = $this->stores();
        $sku = $this->item('legacy');
        $strong = $this->suggested($alt, '4001', 'Strong one', 10, 'Key', $sku);
        $unsure = $this->suggested($alt, '4002', 'Unsure one', 11, "Can't tell", $sku);
        $plain = $this->variant($alt, '4003', 'Plain one', 12);
        $web = $this->signIn($this->uiUser('mapper'));
        $page = $web->get(self::PATH);
        // Confirm match: only a row whose suggestion is of a strength the existing setting allows; the others are skipped with why.
        $r = $web->post('/ui/review/bulk', self::ticked($page, [$strong, $unsure, $plain], 'link'));
        $batch = self::batchOf($r);
        self::assertSame([$strong => ['done', null], $unsure => ['skipped', 'band_not_allowed'], $plain => ['skipped', 'no_suggestion']], self::batchRows($batch));
        self::assertSame(['mapped', $sku], [$this->link($strong)['status'], (int) $this->link($strong)['sku_id']]);
        self::assertSame(['suggested', 'unmapped'], [$this->link($unsure)['status'], $this->link($plain)['status']]);
        $result = $web->follow($r);
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('NEW_VARIANT_DONE', 'link', 1), 0, 2), $result->text());
        self::assertStringContainsString(BulkController::why('band_not_allowed', ['band' => "Can't tell"]), $result->text());
        // Ignore…: a second step that states the count and asks why; nothing is saved before its button.
        $page = $web->get(self::PATH, ['state' => 'not_checked', 'period' => '90']);
        $step = $web->post('/ui/review/bulk', self::ticked($page, [$plain], 'ignore'));
        self::assertSame(200, $step->status, $step->describe());
        self::assertStringContainsString(Words::say('BULK_CONFIRM', 'ignore_title', 1), $step->text());
        self::assertSame('unmapped', $this->link($plain)['status']);
        $confirm = $step->form('/ui/review/bulk');
        self::assertSame(['new', 'ignore', '1', 'alt', 'not_checked', '90'], [$confirm['source'], $confirm['action'], $confirm['confirm'], $confirm['channel'], $confirm['state'], $confirm['period']]);
        self::assertContains(self::PATH . '?channel=alt&period=90&state=not_checked', $step->hrefs(), '"No, go back" is the list as it was');
        $noWhy = $web->post('/ui/review/bulk', ['reason' => ''] + $confirm);
        self::assertSame(422, $noWhy->status, $noWhy->describe());
        self::assertSame('unmapped', $this->link($plain)['status']);
        $done = $web->post('/ui/review/bulk', ['reason' => 'A parent page'] + $confirm);
        self::assertSame([$plain => ['done', null]], self::batchRows(self::batchOf($done)));
        self::assertSame('ignored', $this->link($plain)['status']);
        self::assertSame(self::PATH . '?channel=alt', (new \DOMXPath($web->follow($done)->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)'));
        // An action this list does not offer (posted by hand) is refused whole.
        $page = $web->get(self::PATH, ['state' => 'all']);
        foreach (['reject', 'unignore', 'send_back'] as $action) {
            $refused = $web->post('/ui/review/bulk', self::ticked($page, [$unsure], $action) + ['confirm' => '1']);
            self::assertSame(400, $refused->status, $action . ': ' . $refused->describe());
        }
        self::assertSame('suggested', $this->link($unsure)['status']);
        // By Item's bulk action has its own route, with a preview first: this route does not take its list's name. A form that
        // names `item` here is read as a match-strength list's (it names no strength) and refused whole, whatever it asks for.
        $batches = (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch');
        foreach (BulkDecisions::ITEM_ACTIONS as $action) {
            $refused = $web->post('/ui/review/bulk', ['source' => 'item', 'confirm' => '1'] + self::ticked($page, [$unsure], $action));
            self::assertSame(400, $refused->status, 'item, ' . $action . ': ' . $refused->describe());
        }
        self::assertSame(['suggested', 1, $batches], [$this->link($unsure)['status'], (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE open_listing_id = ?', [$unsure]),
            (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch')], 'nothing was done and nothing recorded');
        self::assertSame(['review', 'store', 'item', 'new'], BulkDecisions::SOURCES);
    }

    public function testTheBulkFormNeedsItsTokenAndTheRightJob(): void
    {
        ['alt' => $alt] = $this->stores();
        $v = $this->found($alt, '5001', 'Found new', 10);
        $mapper = $this->signIn($this->uiUser('mapper'));
        $form = self::ticked($mapper->get(self::PATH), [$v], 'new_item');
        $noToken = $form;
        unset($noToken['csrf']);
        $r = $mapper->post('/ui/review/bulk', $noToken);
        self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()]);
        $r = $mapper->post('/ui/review/bulk', ['csrf' => 'forged'] + $form);
        self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()]);
        $other = $this->signIn($this->uiUser('mapper'));
        $r = $other->post('/ui/review/bulk', $form);
        self::assertSame([403, 'csrf'], [$r->status, $r->errorCode()], 'another person\'s token');
        $viewer = $this->signIn($this->uiUser('viewer'));
        self::assertSame(403, $viewer->post('/ui/review/bulk', ['csrf' => $this->token($viewer)] + $form)->status, 'looking is not deciding');
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->post('/ui/review/bulk', ['csrf' => $this->token($buyer)] + $form)->status);
        // The list is one store's: a form that names no store (or one that is none) did not come from it, and is refused whole.
        foreach ([null, 'nosuchstore'] as $channel) {
            $crafted = array_filter(['channel' => $channel] + $form, static fn (?string $v): bool => $v !== null);
            $r = $mapper->post('/ui/review/bulk', $crafted);
            self::assertSame(400, $r->status, json_encode($channel) . ': ' . $r->describe());
            self::assertStringContainsString(Words::BULK_ERROR['bad_request'], $r->text());
        }
        self::assertSame(['suggested', 0, 0], [$this->link($v)['status'], (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'), (int) self::$db->value('SELECT COUNT(*) FROM sku')]);
        self::assertSame(405, $mapper->post(self::PATH, $form)->status, 'the list itself takes no form');
        self::assertSame([$v => ['done', null]], self::batchRows(self::batchOf($mapper->post('/ui/review/bulk', $form))), 'with the token and the job it works');
        self::assertSame('mapped', $this->link($v)['status']);
    }

    public function testANumberOutOfRangeOrANoteThatIsNotTextIsRefusedWholeBeforeAnythingIsWritten(): void
    {
        ['alt' => $alt] = $this->stores();
        $a = $this->found($alt, '7001', 'Found new one', 10);
        $b = $this->found($alt, '7002', 'Found new two', 11);
        $web = $this->signIn($this->uiUser('mapper'));
        $form = self::ticked($web->get(self::PATH), [$a, $b], 'new_item');
        $untouched = function () use ($a, $b): void {
            self::assertSame(['suggested', 'suggested', 0, 0, 0], [$this->link($a)['status'], $this->link($b)['status'], (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'),
                (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch_row'), (int) self::$db->value('SELECT COUNT(*) FROM sku')]);
        };
        // A ticked number no variant can have (above the largest id there is), beside two real rows: refused whole. It was an error
        // page after the real rows had already been made into items.
        foreach ([['pick_4294967296' => '1', 'ver_4294967296' => '0', 'prop_4294967296' => ''], ['pick_9999999999' => '1', 'ver_9999999999' => '1'],
            ['ver_' . $b => '9999999999'], ['ver_' . $a => '4294967296']] as $crafted) {
            $r = $web->post('/ui/review/bulk', $crafted + $form);
            self::assertSame([400, 'bad_form'], [$r->status, $r->errorCode()], json_encode($crafted) . ': ' . $r->describe());
            self::assertStringContainsString(Words::ERROR['bad_form'], $r->text());
            $untouched();
        }
        // The largest number there is names no variant here: answered row by row, as any unknown one (nothing fails).
        $edge = $web->post('/ui/review/bulk', ['pick_4294967295' => '1', 'ver_4294967295' => '4294967295', 'prop_4294967295' => ''] + $web->get(self::PATH)->form('/ui/review/bulk') + ['action' => 'ignore',
            'confirm' => '1', 'reason' => 'the edge of the range']);
        self::assertSame([4294967295 => ['skipped', 'unknown_listing']], self::batchRows(self::batchOf($edge)));
        self::$db->exec('DELETE FROM mapping_batch_row');
        self::$db->exec('DELETE FROM mapping_batch');
        // A note that is not text (bytes that are not UTF-8). Ignore: its second step is shown again with why; nothing is saved.
        $step = $web->post('/ui/review/bulk', self::ticked($web->get(self::PATH), [$a], 'ignore'));
        $bad = $web->post('/ui/review/bulk', ['reason' => "caf\xE9 \xFF"] + $step->form('/ui/review/bulk'));
        self::assertSame(400, $bad->status, $bad->describe());
        self::assertStringContainsString(Words::BULK_ERROR['reason_text'], $bad->text());
        self::assertSame(['1', 'ignore'], [$bad->form('/ui/review/bulk')['confirm'] ?? null, $bad->form('/ui/review/bulk')['action'] ?? null], 'the second step again');
        $untouched();
        // The same note added by hand to an action that has no second step: refused in words, not an error page.
        $bad = $web->post('/ui/review/bulk', ['reason' => "\xFF"] + $form);
        self::assertSame([400, 'bad_reason'], [$bad->status, $bad->errorCode()], $bad->describe());
        self::assertStringContainsString(Words::BULK_ERROR['reason_text'], $bad->text());
        $untouched();
        // One at a time, on the variant's own page: the same check, the form shown again.
        $own = $web->get('/ui/review/listing/' . $a);
        $decide = $own->form('/ui/review/listing/' . $a . '/decide');
        $bad = $web->post('/ui/review/listing/' . $a . '/decide', ['action' => 'ignore', 'reason' => "\xFF\xFE"] + $decide);
        self::assertSame(400, $bad->status, $bad->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['reason_text'], $bad->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_decision WHERE listing_id = ? AND action = \'ignore\'', [$a]));
        $untouched();
        // With a note that is text, the same forms work.
        $ok = $web->post('/ui/review/bulk', ['reason' => 'café'] + $step->form('/ui/review/bulk'));
        self::assertSame([$a => ['done', null]], self::batchRows(self::batchOf($ok)));
        self::assertSame('café', (string) self::$db->value('SELECT reason FROM mapping_batch ORDER BY id DESC LIMIT 1'));
    }

    public function testOddValuesInTheAddressAreAnsweredInWordsNeverWithAnError(): void
    {
        ['alt' => $alt] = $this->stores();
        $this->variant($alt, '6001', 'Plain café', 10);
        $web = $this->signIn($this->uiUser('mapper'));
        $cases = [
            [['channel' => ['alt']], 200], [['channel' => 'nosuchstore'], 404], [['channel' => 'x"><script>'], 404], [['channel' => "\xFF"], 404],
            [['period' => ['7'], 'state' => ['all'], 'q' => ['a', 'b'], 'page' => ['2'], 'all' => ['1']], 200],
            [['period' => '-3'], 200], [['period' => '0'], 200], [['period' => '999999'], 200], [['period' => 'x"><b'], 200], [['period' => "\xFF"], 200],
            [['state' => 'nonsense'], 200], [['state' => "\xFF"], 200], [['state' => 'not_matched'], 200], [['state' => 'new', 'q' => "\xFF"], 200],
            [['state' => 'suggested', 'page' => '999999999999999999'], 200], [['state' => 'not_checked', 'period' => 'all', 'q' => 'x'], 200],
            [['page' => '999999999999999999'], 200], [['page' => '99999999999999999999999'], 200], [['page' => '0'], 200], [['page' => '-1'], 200], [['page' => 'abc'], 200],
            [['q' => "\xFF"], 200], [['q' => "caf\xE9"], 200], [['q' => "\u{00A0}"], 200], [['q' => "\u{3000}"], 200], [['q' => str_repeat('é', 2000)], 200],
            [['q' => '%_\\'], 200], [['q' => "' OR 1=1 --"], 200], [['q' => 'x', 'state' => 'all', 'period' => 'all', 'page' => '7'], 200],
        ];
        foreach ([self::PATH, self::PATH . '.csv'] as $path) {
            foreach ($cases as [$query, $status]) {
                $r = $web->send('GET', $path, $query, []);
                self::assertSame($status, $r->status, $path . ' ' . json_encode(array_map(static fn (mixed $v): mixed => is_string($v) ? bin2hex($v) : $v, $query)) . ': ' . substr($r->body, 0, 200));
            }
        }
        self::assertSame(Words::ERROR['unknown_variants_store'], Words::error('unknown_variants_store', 'x'));
        self::assertStringContainsString(Words::ERROR['unknown_variants_store'], $web->get(self::PATH, ['channel' => 'nosuchstore'])->text());
        // Text that is not UTF-8 finds nothing (the page and the file say so plainly); an accent is searched as it is.
        $bytes = $web->send('GET', self::PATH, ['q' => "caf\xE9"], []);
        self::assertSame([], self::titles($bytes));
        self::assertStringContainsString(Words::NEW_VARIANTS['empty_search'], $bytes->text());
        self::assertSame(1, substr_count($web->send('GET', self::PATH . '.csv', ['q' => "\xFF"], [])->body, "\r\n"), 'the head, no row');
        self::assertSame(['Plain café'], self::titles($web->get(self::PATH, ['q' => 'café'])));
        self::assertSame(['Plain café'], self::titles($web->send('GET', self::PATH, ['q' => "\u{00A0}"], [])), 'white space alone is no search');
        // A hostile value is never written back as markup.
        $evil = $web->get(self::PATH, ['q' => '"><script>alert(1)</script>', 'state' => 'all']);
        self::assertSame(200, $evil->status);
        self::assertStringNotContainsString('<script>alert(1)', $evil->body);
        // The bulk form with nonsense around it: refused in words, with a safe way back.
        $none = $web->post('/ui/review/bulk', ['csrf' => $this->token($web), 'source' => 'new', 'action' => 'new_item', 'channel' => 'alt', 'period' => 'x"><b', 'state' => 'zzz',
            'page' => '-4', 'q' => "\xFF"]);
        self::assertSame(422, $none->status, $none->describe());
        self::assertStringContainsString(Words::BULK_ERROR['nothing_ticked'], $none->text());
        self::assertStringNotContainsString('x"><b', $none->body);
    }

    public function testTheMigrationAddsTheIndexTheListReadsAndTheNewKindOfBatch(): void
    {
        $index = self::$db->all("SELECT COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channel_listing' "
            . "AND INDEX_NAME = 'ix_channel_listing_seen' ORDER BY SEQ_IN_INDEX");
        self::assertSame(['channel_id', 'created_at', 'id'], array_column($index, 'COLUMN_NAME'));
        // 0026 writes the column's whole list: what 0021 made, By Item's value (0025) and this list's, in that order.
        self::assertSame("enum('review','store','item','new')", strtolower((string) self::$db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'mapping_batch' AND COLUMN_NAME = 'source'")));
        self::assertContains('new', BulkDecisions::SOURCES);
        // The page's list is read from that index in its own order: a range of one store, newest first, no sort; also when the
        // open suggestion is joined for the New filter.
        ['alt' => $alt] = $this->stores();
        for ($i = 1; $i <= 30; $i++) {
            $i % 3 === 0 ? $this->found($alt, 'x' . $i, 'Variant ' . $i, $i) : $this->variant($alt, 'x' . $i, 'Variant ' . $i, $i);
        }
        $plan = self::$db->all('EXPLAIN SELECT /*+ INDEX(cl ix_channel_listing_seen) */ cl.id FROM channel_listing cl WHERE cl.channel_id = ? AND cl.created_at >= ? '
            . 'ORDER BY cl.created_at DESC, cl.id DESC LIMIT 50', [$alt->channelId, '2026-01-01 00:00:00']);
        self::assertSame('ix_channel_listing_seen', $plan[0]['key']);
        self::assertStringNotContainsString('filesort', (string) $plan[0]['Extra']);
        $joined = self::$db->all('EXPLAIN SELECT /*+ INDEX(cl ix_channel_listing_seen) JOIN_ORDER(cl, p) INDEX(p uq_match_proposal_open) */ cl.id FROM channel_listing cl '
            . 'JOIN match_proposal p ON p.open_listing_id = cl.id '
            . "WHERE cl.channel_id = ? AND cl.created_at >= ? AND (p.proposed_new_item = 1 AND p.band = 'New item') ORDER BY cl.created_at DESC, cl.id DESC LIMIT 50",
            [$alt->channelId, '2026-01-01 00:00:00']);
        self::assertSame(['cl', 'ix_channel_listing_seen'], [$joined[0]['table'], $joined[0]['key']], 'the listing first, in the index\'s order');
        self::assertSame('uq_match_proposal_open', $joined[1]['key']);
        self::assertStringNotContainsString('filesort', (string) $joined[0]['Extra'] . (string) $joined[1]['Extra']);
        $ends = self::$db->all('EXPLAIN SELECT MIN(created_at), MAX(created_at) FROM channel_listing WHERE channel_id = ?', [$alt->channelId]);
        self::assertStringContainsString('optimized away', (string) $ends[0]['Extra'], 'the oldest and the newest are the index\'s two ends');
        $q = new Queries(self::$db);
        $range = $q->seenRange((int) $alt->channelId);
        self::assertSame([(string) self::$db->value('SELECT MIN(created_at) FROM channel_listing'), (string) self::$db->value('SELECT MAX(created_at) FROM channel_listing')],
            [$range['first'], $range['last']]);
        self::assertSame(['first' => null, 'last' => null], $q->seenRange(999999));
        self::assertCount(10, $q->newVariants((int) $alt->channelId, null, 'new', '', 1, $q->newVariantCounts((int) $alt->channelId, null))['ids']);
    }

    /**
     * The check on arrival on this page (docs/decisions.md U144): the box at the top says whether it is on for the store, how many
     * of the store's variants wait for it and what its last run did there; a row with no suggestion says why it has none, from the
     * mark the check left; a row whose suggestion the check made says the reason in words (and the closest item when the check is
     * not sure), has no tick box and the line under its name. Switched off, the page says so and the rows that were never looked
     * at say so too.
     */
    /**
     * The website state on New Variants (M66, U146). A row marked New says, before it is ticked, what "Create as new items" will
     * find of its state: that it is not published (the variant, or its product), that it is a page heading (worked out now from
     * its stored state, as the seed works it out), or that the warehouse does not know whether it is on sale now (nothing known
     * at all; a stored state that lacks something; one older than the setting). One that is on sale says nothing more. The seed
     * then skips exactly those rows, each with the reason its row gave, and the result page says it in words. The stored state
     * comes before the features; but what the features say AGAINST a variant (a page heading, a status that is not Published)
     * is said, and skipped, beside a stored state that says it is on sale too (the review of build 2a, F1).
     * EVERY row still to match (New, a suggested match, no suggestion) says when it is not published or a page heading, the
     * heading worked out from its stored state as for a row marked New (the review of build 2a, CS-A). Only "the warehouse does
     * not know" stays with the rows marked New: the seed skips every other row for what it is.
     */
    public function testARowMarkedNewSaysWhatTheSeedWillFindOfItsStateOnTheWebsite(): void
    {
        ['alt' => $alt, 'big' => $big] = $this->stores();
        $sku = $this->item('legacy');
        $old = \CW\Clock::db(\CW\Clock::now()->modify('-60 days'));
        $range = ['site_product_type' => 'variable', 'site_product_id' => 'range-1'];
        $rows = [
            '7001' => [$this->found($alt, '7001', 'On sale', 1), '', 'done'],
            '7002' => [$this->found($alt, '7002', 'Nothing known', 2, ['site' => false]), Words::NEW_VARIANTS['site_unknown'], 'state_not_known'],
            '7003' => [$this->found($alt, '7003', 'A state of two months ago', 3, ['site' => ['site_state_at' => $old]]), Words::NEW_VARIANTS['site_unknown'], 'state_not_known'],
            '7004' => [$this->found($alt, '7004', 'No word on its product', 4, ['site' => ['site_product_status' => null]]), Words::NEW_VARIANTS['site_unknown'], 'state_not_known'],
            '7005' => [$this->found($alt, '7005', 'Nothing of a heading known', 5, ['site' => ['site_is_landing' => null]]), Words::NEW_VARIANTS['site_unknown'], 'state_not_known'],
            '7006' => [$this->found($alt, '7006', 'In the bin', 6, ['site' => ['site_variant_status' => 'Bin']]), Words::say('NEW_VARIANTS', 'site_status', 'Bin'), 'not_published'],
            '7007' => [$this->found($alt, '7007', 'Under a draft', 7, ['site' => ['site_product_status' => 'Draft']]), Words::say('NEW_VARIANTS', 'site_status', 'Draft'), 'not_published'],
            '7008' => [$this->found($alt, '7008', 'A landing row', 8, ['site' => ['site_is_landing' => 1]]), Words::NEW_VARIANTS['site_heading'], 'page_heading'],
            '7009' => [$this->found($alt, '7009', 'A range', 9, ['site' => $range + ['site_is_default' => 1]]), Words::NEW_VARIANTS['site_heading'], 'page_heading'],
            '7010' => [$this->found($alt, '7010', 'A range', 10, ['variant_title' => 'A flavour of the range', 'site' => $range + ['site_is_default' => 0]]), '', 'done'],
            // CHANGED ON PURPOSE (the review of build 2a, F1; this row was pinned as made, with no line under it). What the features
            // of a matching run say AGAINST a variant counts beside a stored state that says it is on sale, as it did before the
            // state was stored: a heading (said first), or a status that is not Published.
            '7011' => [$this->found($alt, '7011', 'On sale by its stored state, a binned heading by its features', 11, ['features' => ['variant_status' => 'Bin', 'is_placeholder' => true],
                'site' => []]), Words::NEW_VARIANTS['site_heading'], 'page_heading'],
            '7014' => [$this->found($alt, '7014', 'On sale by its stored state, a draft by its features', 14, ['features' => ['variant_status' => 'Draft'], 'site' => []]),
                Words::say('NEW_VARIANTS', 'site_status', 'Draft'), 'not_published'],
            // Features that agree with the stored state change nothing; and a stored "Bin" is not overruled by features that say Published.
            '7015' => [$this->found($alt, '7015', 'On sale by its stored state and by its features', 15, ['features' => ['variant_status' => 'Published', 'is_placeholder' => false],
                'site' => []]), '', 'done'],
            '7016' => [$this->found($alt, '7016', 'In the bin by its stored state, published by its features', 16, ['features' => ['variant_status' => 'Published'],
                'site' => ['site_variant_status' => 'Bin']]), Words::say('NEW_VARIANTS', 'site_status', 'Bin'), 'not_published'],
            // Known only from a matching run's features: no date, so never "not known"; as before.
            '7012' => [$this->found($alt, '7012', 'Published by its features', 12, ['features' => ['variant_status' => 'Published']]), '', 'done'],
            '7013' => [$this->found($alt, '7013', 'An old state that says Bin', 13, ['site' => ['site_variant_status' => 'Bin', 'site_state_at' => $old]]),
                Words::say('NEW_VARIANTS', 'site_status', 'Bin'), 'not_published'],
        ];
        // Not marked New: no word about an unknown state. A variant of another store shares the range's product number: not counted.
        $plain = $this->variant($alt, '7020', 'No suggestion, nothing known', 20);
        $match = $this->suggested($alt, '7021', 'A suggested match, nothing known', 21, 'Key', $sku);
        self::siteState($this->variant($big, '7030', 'Another store, the same product number', 5), $range + ['site_is_default' => 0]);
        // Not marked New, with a stored state (the review of build 2a, CS-A): each says a page heading or "not published" as a row
        // marked New does, [what its row says after what it is, what the seed skips it as]. None of them has features that say so.
        $stored = function (int $id, array $state) {
            self::siteState($id, $state);
            return $id;
        };
        $others = [
            '7022' => [$stored($this->variant($alt, '7022', 'No suggestion, a landing row', 22), ['site_is_landing' => 1]), Words::NEW_VARIANTS['site_heading'], 'not_checked'],
            '7023' => [$stored($this->suggested($alt, '7023', 'A suggested match, a landing row', 23, 'Key', $sku), ['site_is_landing' => 1]), Words::NEW_VARIANTS['site_heading'],
                'suggested_match'],
            // The default row of the range above: a heading by the published variants of its product, counted for this row too.
            '7024' => [$stored($this->variant($alt, '7024', 'A range', 24), $range + ['site_is_default' => 1]), Words::NEW_VARIANTS['site_heading'], 'not_checked'],
            '7025' => [$stored($this->variant($alt, '7025', 'No suggestion, on sale and no heading', 25), []), '', 'not_checked'],
            // Not on sale is said first (and its heading is not asked for); the seed says so before "no suggestion".
            '7026' => [$stored($this->variant($alt, '7026', 'No suggestion, a binned landing row', 26), ['site_is_landing' => 1, 'site_variant_status' => 'Bin']),
                Words::say('NEW_VARIANTS', 'site_status', 'Bin'), 'not_published'],
            // "The warehouse does not know" stays with the rows marked New: a state that lacks something says nothing here.
            '7027' => [$stored($this->variant($alt, '7027', 'No suggestion, nothing of a heading known', 27), ['site_is_landing' => null]), '', 'not_checked'],
            // What an old state knows against a variant still counts: a landing row by a state of two months ago.
            '7028' => [$stored($this->variant($alt, '7028', 'No suggestion, a landing row by an old state', 28), ['site_is_landing' => 1, 'site_state_at' => $old]),
                Words::NEW_VARIANTS['site_heading'], 'not_checked'],
            // A heading by its features, no heading by its stored state (F1): said, and skipped as a heading, as before the state was stored.
            '7029' => [$stored($this->variant($alt, '7029', 'No suggestion, a heading by its features', 29, ['features' => ['is_placeholder' => true, 'variant_status' => 'Published']]), []),
                Words::NEW_VARIANTS['site_heading'], 'page_heading'],
        ];
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get(self::PATH, ['state' => 'all']);
        $said = [];
        foreach (range(1, count($rows) + count($others) + 2) as $n) {
            $said[self::row($page, $n)[Words::NEW_VARIANTS['option']]] = self::row($page, $n)['state'];
        }
        $new = Words::NEW_VARIANT_STATE['new'] . ' ' . Words::NEW_VARIANTS['is_new'];
        foreach ($rows as $option => [$id, $note, $outcome]) {
            self::assertSame(trim($new . ' ' . $note), $said[$option], "{$option}: what the row says of its state");
        }
        $none = Words::NEW_VARIANT_STATE['not_checked'] . ' ' . Words::ARRIVAL_LEFT['off'];
        $suggested = Words::NEW_VARIANT_STATE['suggested'] . ' ' . Words::say('NEW_VARIANTS', 'suggested_item', Words::BAND['Key'], $this->codeOf($sku)) . ' '
            . self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]);
        self::assertSame([$none, $suggested], [$said['7020'], $said['7021']], 'a row that is not marked New says nothing of a state that is not known');
        foreach ($others as $option => [$id, $note, $outcome]) {
            self::assertSame(trim(((int) $option === 7023 ? $suggested : $none) . ' ' . $note), $said[$option], "{$option}: what a row that is not marked New says of its state");
        }
        self::assertSame('The warehouse does not know if it is on sale on the website now: open it to decide.', Words::NEW_VARIANTS['site_unknown']);
        self::assertStringContainsString('one the warehouse does not know to be on sale on the website now', $page->text(), 'the line beside the bar names the reason');
        // What the heading costs the list (CS-A): one more read for each PRODUCT among the rows still to match that are on sale by
        // a stored state which holds the heading's inputs, remembered for the call; none for a row that says "not published" or
        // "a heading" without it. Counted in statements sent (the session's own counter; its own read is one of them).
        $q = new Queries(self::$db);
        $sent = static function (array $ids) use ($q): int {
            $n = static fn (): int => (int) self::$db->pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
            $before = $n();
            $q->newVariantRows($ids);
            return $n() - $before - 1;
        };
        $base = $sent([$plain]); // (no stored state: the rows themselves and the items made from them)
        $with = static fn (string ...$options): array => array_map(static fn (string $o): int => ($rows + $others)[$o][0], $options);
        self::assertSame([2, 1, 1, 1, 1, 1, 1, 2, 3], array_map(static fn (array $ids): int => $sent($ids) - $base, [
            $with('7025'),                 // the setting of the age, and its product's published variants
            $with('7026'),                 // in the bin: the setting only
            $with('7029'),                 // a heading by its features: the setting only
            $with('7014'),                 // a draft by its features: the setting only
            $with('7027'),                 // no landing mark: nothing to work out
            $with('7005'),                 // the same under a row marked New
            $with('7013'),                 // an old state that says Bin
            $with('7009', '7010', '7024'), // three rows of one product: one read
            $with('7022', '7023'),         // two products: two reads
        ]), 'one read a product, and only where the answer is needed');
        self::assertLessThanOrEqual($base + 1 + count($rows) + count($others), $sent([...array_column($rows, 0), ...array_column($others, 0), $plain, $match]), 'never more than one a row');

        // The seed finds what each row said.
        $r = $web->post('/ui/review/bulk', self::ticked($page, [...array_column($rows, 0), $plain, $match, ...array_column($others, 0)], 'new_item'));
        $batch = self::batchOf($r);
        $expected = [$plain => ['skipped', 'not_checked'], $match => ['skipped', 'suggested_match']];
        foreach ([...$rows, ...$others] as [$id, $note, $outcome]) {
            $expected[$id] = $outcome === 'done' ? ['done', null] : ['skipped', $outcome];
        }
        ksort($expected);
        self::assertSame($expected, self::batchRows($batch));
        $text = $web->follow($r)->text();
        self::assertStringContainsString('Done: 4 new items created and matched, 0 sent to Second approval, 22 skipped.', $text);
        self::assertSame(8, substr_count($text, 'The warehouse does not know if it is on sale on the website now. Open it and decide.'),
            'four rows, each in the skipped list and in the list of every row');
        foreach (['It is not published on the website (Bin). Open it and decide.', 'It is not published on the website (Draft). Open it and decide.',
            'It is a page heading on the website, not something that is sold. Open it and decide.'] as $why) {
            self::assertStringContainsString($why, $text);
        }
        // The CSV says the same of a row as the page; and the setting decides what "too old" is.
        $csv = $web->get(self::PATH . '.csv', ['state' => 'new'])->body;
        self::assertSame(4, substr_count($csv, Words::NEW_VARIANTS['site_unknown']));
        self::assertSame(3, substr_count($csv, Words::NEW_VARIANTS['site_heading']));
        $csv = $web->get(self::PATH . '.csv', ['state' => 'not_checked'])->body;
        self::assertSame([4, 0], [substr_count($csv, Words::NEW_VARIANTS['site_heading']), substr_count($csv, Words::NEW_VARIANTS['site_unknown'])],
            'and of a row that is not marked New');
        $this->setting('mapping.arrival_state_max_days', '90');
        self::assertSame($new, self::row($web->get(self::PATH, ['state' => 'new', 'q' => '7003']), 1)['state'], 'two months is not too old when the setting allows three');
        self::assertSame([$rows['7003'][0] => ['done', null]], self::batchRows(self::batchOf($web->post('/ui/review/bulk',
            self::ticked($web->get(self::PATH, ['state' => 'new', 'q' => '7003']), [$rows['7003'][0]], 'new_item')))));
        // One at a time a person may still make the item of a variant whose state is not known: its own page is not touched.
        $own = $web->get('/ui/review/listing/' . $rows['7002'][0]);
        $ok = $web->post('/ui/review/listing/' . $rows['7002'][0] . '/decide', ['action' => 'new_item', 'units_per_item' => '1'] + $own->form('/ui/review/listing/' . $rows['7002'][0] . '/decide'));
        self::assertSame([303, 'mapped'], [$ok->status, $this->link($rows['7002'][0])['status']], $ok->describe());
    }

    public function testTheCheckOnArrivalSaysWhatItDidAndWhyARowHasNoSuggestion(): void
    {
        $alt = $this->site('alt', 'shadow');
        $vpg = $this->site('vpg', 'off');
        self::$db->exec("UPDATE channel SET name = 'Alt Store' WHERE code = 'alt'");
        self::$db->exec("UPDATE channel SET name = 'Main Store' WHERE code = 'vpg'");
        $this->arrivalSetup($vpg, $alt);
        $title = 'Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid';
        $item = $this->mainItem($title, '10mg', [self::gtin(1)]);
        $pack = $this->mainItem($title, '10mg');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source) VALUES (?, ?, 1, 10, 'test')", [ltrim(self::gtin(2), '0'), $pack['sku']]);
        $this->mainItem($title, '10mg', [self::gtin(3)]);
        $this->mainItem($title, '10mg', [self::gtin(3)]);
        $match = $this->seen($this->arrives($alt, $title, '10mg', [self::gtin(1)]), 10);
        $unsure = $this->seen($this->arrives($alt, $title, '10mg', [self::gtin(2)]), 20);
        $clash = $this->seen($this->arrives($alt, $title, '10mg', [self::gtin(3)]), 30);
        $noClue = $this->seen($this->arrives($alt, 'Nothing like it', 'Red'), 40);
        $touched = $this->seen($this->arrives($alt, 'Sent back once', 'Blue'), 50);
        $mapper = $this->staffUser('mapper');
        $this->decide($mapper, 'ignore', $touched, ['reason' => 'x']);
        $this->decide($mapper, 'unignore', $touched);
        $before = $this->variant($alt, '9100', 'Here before the check', 60);
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame([5, 1, 1, 1], [$r['looked'], $r['per_store']['alt']['match'], $r['per_store']['alt']['conflict'], $r['per_store']['alt']['not_sure']]);
        $waiting = $this->seen($this->arrives($alt, 'Arrived just now', 'Green'), 5);
        $web = $this->signIn($this->uiUser('mapper'));

        $page = $web->get(self::PATH, ['channel' => 'alt', 'state' => 'all', 'period' => 'all']);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame(['Arrived just now', $title, $title, $title, 'Nothing like it', 'Sent back once', 'Here before the check'], self::titles($page));
        // The box: on, what waits, and the last run.
        $box = trim((string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($page->dom()))->evaluate('string(//div[contains(@class, "where-from")])')));
        self::assertStringContainsString('The check on arrival is on for Alt Store: a new variant whose barcode, or whose web address copied from the main store, points to an item gets a suggestion. '
            . 'No AI looks at it. 1 variant is waiting for the check. Its last run on ' . Html::day(gmdate('Y-m-d H:i:s')) . ' looked at 5 here: 1 suggested as a match, 1 where the clues disagree, '
            . '1 not sure, 2 left with no suggestion.', $box);
        // Each row: what it is, and why.
        $code = static fn (array $i): string => (string) $i['code'];
        self::assertSame([
            'No suggestion Waiting for the check on arrival.',
            'Suggested match Likely match – check it: ' . $code($item) . ' (its barcode is on this item) ' . self::$db->value('SELECT name FROM sku WHERE id = ?', [$item['sku']]),
            'Suggested match Not sure – you choose, closest ' . $code($pack) . ' (its barcode is recorded as a pack of more than one)',
            'Suggested match Clues disagree (its barcode is on more than one item)',
            'No suggestion The check on arrival found no barcode and no copied web address for it: open it to decide.',
            'No suggestion A person or an earlier computer check has dealt with it, so the check on arrival leaves it: open it to decide.',
            'No suggestion It was here before the check on arrival, which has not looked at it: open it to decide.',
        ], array_map(static fn (int $n): string => self::row($page, $n)['state'], range(1, 7)));
        self::assertSame([['All not matched 7', false], ['New: the computer found nothing like it 0', false], ['Suggested match 3', false], ['No suggestion 4', false], ['All 7', true]],
            self::pills($page, 'state-pills'));
        // The rows the check suggested for have no tick box and say so under their name; the others keep theirs.
        $xp = new \DOMXPath($page->dom());
        $boxes = [];
        foreach ($xp->query('//tbody/tr//input[@type="checkbox"][@data-bulk-row]') as $b) {
            /** @var \DOMElement $b */
            $boxes[] = (int) substr($b->getAttribute('name'), strlen('pick_'));
        }
        self::assertEqualsCanonicalizing([$waiting, $noClue, $touched, $before], $boxes);
        self::assertSame(3, $xp->query('//tbody/tr/th/span[contains(@class, "no-bulk")][contains(., "Found by the check on arrival: no AI has looked at it.")]')->length);
        // The file says the same.
        $lines = explode("\r\n", rtrim(substr($web->get(self::PATH . '.csv', ['channel' => 'alt', 'state' => 'all', 'period' => 'all'])->body, strlen(CsvWriter::BOM)), "\r\n"));
        $csv = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), array_slice($lines, 1));
        self::assertSame(['No suggestion', '', 'Waiting for the check on arrival.'], array_slice($csv[0], 10));
        self::assertSame(['Suggested match', $code($item), 'Likely match – check it: ' . $code($item) . ' (its barcode is on this item)'], array_slice($csv[1], 10));
        self::assertSame(['Suggested match', '', 'Not sure – you choose, closest ' . $code($pack) . ' (its barcode is recorded as a pack of more than one)'], array_slice($csv[2], 10));

        // With clean matches allowed in a ticked list, the clean one gets its tick box; the other two never do.
        $this->arrivalSetting('mapping.arrival_bulk_confirm', 'true');
        $on = $web->get(self::PATH, ['channel' => 'alt', 'state' => 'suggested', 'period' => 'all']);
        self::assertSame(1, (new \DOMXPath($on->dom()))->query('//tbody/tr//input[@type="checkbox"][@data-bulk-row][@name="pick_' . $match . '"]')->length);
        self::assertSame(2, (new \DOMXPath($on->dom()))->query('//tbody/tr/th/span[contains(@class, "no-bulk")]')->length);
        self::assertSame(0, (new \DOMXPath($on->dom()))->query('//tbody/tr//input[@name="pick_' . $unsure . '" or @name="pick_' . $clash . '"]')->length);

        // Switched off for the store: the box says so (and nothing about a queue), and a variant the check never looked at says so.
        $this->arrivalSetting('mapping.arrival_stores', '""');
        $off = $web->get(self::PATH, ['channel' => 'alt', 'state' => 'not_checked', 'period' => 'all']);
        $box = trim((string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($off->dom()))->evaluate('string(//div[contains(@class, "where-from")])')));
        self::assertStringContainsString('The check on arrival is switched off for Alt Store, so its new variants get no suggestion by themselves', $box);
        self::assertStringNotContainsString('waiting for the check', $box);
        self::assertStringNotContainsString('Its last run', $box);
        self::assertSame(['No suggestion ' . Words::ARRIVAL_LEFT['off'], 'No suggestion ' . Words::ARRIVAL_LEFT['no_clue'], 'No suggestion ' . Words::ARRIVAL_LEFT['has_history'],
            'No suggestion ' . Words::ARRIVAL_LEFT['off']], array_map(static fn (int $n): string => self::row($off, $n)['state'], range(1, 4)),
            'what the check found when it looked is still true; what it never looked at says the check is off');
        // A store the check is not on for at all says so, whatever else is switched on.
        $this->arrivalSetting('mapping.arrival_stores', '"alt"');
        self::assertStringContainsString('The check on arrival is switched off for Main Store', $web->get(self::PATH, ['channel' => 'vpg', 'period' => 'all'])->text());
    }
}
