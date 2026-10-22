<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\LandingPages;
use CW\Mapping\LandingRule;
use CW\Mapping\ListingIngestService;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;
use CW\Ui\Queries;

/**
 * Landing pages (docs/decisions.md LP1-LP6, LP10): a website product that is the page where the customer picks an option is marked
 * `landing` through DecisionService, by the website's own flag or the rule; by the classifying tool (a dry run that writes nothing,
 * then --apply, audited, re-runnable) and on arrival (a push); never back and forth (a person's "real product" is final for the
 * system; a mark the rule no longer finds stays); it leaves every matching list and count; and a real product with one option, or
 * any other unmatched product, behaves as before.
 */
final class LandingPagesTest extends MappingTestCase
{
    private const TITLE = 'Blue Razz Cherry Elux Legend Nic Salt 10ml';
    private Caller $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = $this->site('electrofag');
    }

    /** One website product with its profile, as the connector pushes it (no website state). @param array<string, mixed> $p */
    private function product(string $variant, ?string $option, array $p = [], ?int $sku = null): int
    {
        $attrs = [['attr_id' => 28, 'name' => 'Bottle Size', 'value' => '10ml', 'is_variable' => 0]];
        if ($option !== null) {
            $attrs[] = ['attr_id' => 4, 'name' => 'Strength', 'value' => $option, 'is_variable' => 1];
        }
        $title = $p['product_title'] ?? self::TITLE;
        $id = $this->listing($this->shop, $variant, $sku);
        self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, attributes, barcodes, perma_link, units_365d) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $id, $title, $p['variant_title'] ?? ($option === null ? $title : $title . ' - ' . $option), json_encode(['items' => $p['attributes'] ?? $attrs]),
            json_encode($p['barcodes'] ?? ($option === null ? [] : ['50610267' . $variant])), $p['perma_link'] ?? ('blue-razz-cherry-elux-legend-nic-salt-10ml'
            . ($option === null ? '' : '/' . strtolower($option))), $p['units_365d'] ?? 0]);
        return $id;
    }

    /** @return array{0: int, 1: int, 2: int} the page's 10mg, 20mg and its landing page (options 958, 959, 960) */
    private function page(): array
    {
        return [$this->product('958', '10mg'), $this->product('959', '20mg'), $this->product('960', null, ['units_365d' => 4])];
    }

    private function listingStatus(int $id): string
    {
        return (string) self::$db->value('SELECT CAST(status AS CHAR) FROM channel_listing WHERE id = ?', [$id]);
    }

    protected function tearDown(): void
    {
        self::$db->exec("UPDATE app_setting SET value_json = 'true' WHERE setting_key = 'mapping.landing_rule_on_arrival'");
        parent::tearDown();
    }

    private function svc(): LandingPages
    {
        return new LandingPages(self::$db, $this->ds);
    }

    public function testTheRuleMarksTheLandingPageWithWhyAndNeverAProductWithOneOption(): void
    {
        [$mg10, $mg20, $landing] = $this->page();
        $single = $this->product('S1', null, ['product_title' => 'Voopoo Doric Galaxy Replacement Pods 2ml', 'perma_link' => 'voopoo-doric-galaxy-replacement-pods-2ml/']);
        $proposal = $this->propose($landing, 'Check', $this->item('legacy'));
        self::assertSame('suggested', $this->listingStatus($landing));

        $plan = $this->svc()->plan((int) $this->shop->channelId);
        self::assertSame([$landing], array_column(array_filter($plan['rows'], static fn (array $r): bool => $r['outcome'] === 'mark'), 'listing_id'));
        self::assertSame(['same_page' => 1], $plan['reasons']);
        self::assertSame(3, $plan['counts']['not_landing'], 'the two options and the single product');
        self::assertSame('suggested', $this->listingStatus($landing), 'a plan writes nothing');

        $r = $this->svc()->apply(Caller::system('landing_pages'), $plan, 'tool');
        self::assertSame(['marked' => 1, 'refused' => [], 'ids' => [$landing]], $r);
        self::assertSame(['landing', 'unmapped', 'unmapped', 'unmapped'], [$this->listingStatus($landing), $this->listingStatus($mg10), $this->listingStatus($mg20), $this->listingStatus($single)]);
        $d = self::$db->one("SELECT decided_by, actor, prev_status, CAST(detail AS CHAR) AS detail FROM match_decision WHERE listing_id = ? AND action = 'landing'", [$landing]);
        self::assertSame([null, 'system:landing_pages', 'suggested'], [$d['decided_by'], $d['actor'], $d['prev_status']]);
        $why = json_decode((string) $d['detail'], true)['landing'];
        self::assertSame(['same_page', 'tool', 'lp1', [$mg10, $mg20]], [$why['reason'], $why['source'], $why['rule'], $why['evidence']['options']]);
        self::assertSame('decided', (string) self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$proposal]), 'its suggestion is settled: it leaves the lists');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.landing' AND entity_id = ?", [(string) $landing]));
        self::assertSame(['marked' => 0, 'refused' => [], 'ids' => []], $this->svc()->apply(Caller::system('landing_pages'), $this->svc()->plan((int) $this->shop->channelId), 'tool'),
            'a second run marks nothing twice');
        self::assertSame(1, $this->svc()->plan((int) $this->shop->channelId)['counts']['already']);
    }

    public function testTheWebsitesOwnFlagMarksAndItsNoKeepsTheRuleAway(): void
    {
        [, , $landing] = $this->page();
        $flagged = $this->product('A1', null, ['product_title' => 'Lost Mary BM600', 'barcodes' => ['5056'], 'perma_link' => 'lost-mary-bm600']);
        self::siteState($flagged, ['site_is_landing' => 1, 'site_product_type' => 'variable', 'site_product_id' => '77']);
        self::siteState($landing, ['site_is_landing' => 0, 'site_product_type' => 'variable', 'site_product_id' => '206']);
        $plan = $this->svc()->plan((int) $this->shop->channelId);
        self::assertSame([$flagged => 'website_flag'], array_column(array_filter($plan['rows'], static fn (array $r): bool => $r['outcome'] === 'mark'), 'reason', 'listing_id'),
            'the website says so (a barcode or no option beside it does not matter); the website says no for 960: the rule is not asked');
        self::assertSame(1, $plan['not']['website_says_no']);
        // an old state is not known: the rule decides again
        self::siteState($landing, ['site_is_landing' => 0, 'site_state_at' => '2026-01-01 00:00:00.000000']);
        self::assertSame('same_page', array_column($this->svc()->plan((int) $this->shop->channelId)['rows'], 'reason', 'listing_id')[$landing]);
    }

    public function testTheToolsDryRunWritesNothingAndApplyMarksAuditsAndReRuns(): void
    {
        [, , $landing] = $this->page();
        $before = [self::$db->value('SELECT COUNT(*) FROM match_decision'), self::$db->value('SELECT COUNT(*) FROM audit_log'), $this->listingStatus($landing)];
        $dry = self::tool('--channel=electrofag', '--list');
        self::assertSame(0, $dry['code'], $dry['err']);
        self::assertStringContainsString('DRY RUN (nothing written)', $dry['err'] . $dry['out']);
        self::assertStringContainsString('would_mark=1 (same_page:1)', $dry['err'] . $dry['out']);
        self::assertStringContainsString("mark listing {$landing} variant 960 (unmapped): " . self::TITLE, $dry['out']);
        self::assertSame($before, [self::$db->value('SELECT COUNT(*) FROM match_decision'), self::$db->value('SELECT COUNT(*) FROM audit_log'), $this->listingStatus($landing)]);
        self::assertSame(2, self::tool('--channel=electrofag', '--dry-run', '--apply')['code'], 'one of the two');
        self::assertSame(2, self::tool('--channel=nowhere')['code']);

        $apply = self::tool('--channel=electrofag', '--apply');
        self::assertSame(0, $apply['code'], $apply['err']);
        self::assertStringContainsString('marked=1', $apply['err'] . $apply['out']);
        self::assertSame('landing', $this->listingStatus($landing));
        $run = json_decode((string) self::$db->value("SELECT CAST(detail AS CHAR) FROM audit_log WHERE action = 'landing.run'"), true);
        self::assertSame(['electrofag', 'lp1', true, 1], [$run['channel'], $run['rule'], $run['apply'], $run['marked']]);
        $again = self::tool('--channel=electrofag', '--apply');
        self::assertStringContainsString('would_mark=0', $again['err'] . $again['out']);
        self::assertStringContainsString('already=1', $again['err'] . $again['out']);
    }

    public function testARealProductIsFinalForTheSystemAndAMarkNeverFlipsBack(): void
    {
        [$mg10, $mg20, $landing] = $this->page();
        $this->svc()->apply(Caller::system('landing_pages'), $this->svc()->plan((int) $this->shop->channelId), 'tool');
        $mapper = $this->staffUser('mapper');
        $lead = $this->staffUser('mapping_lead');
        self::refused(403, 'lead_required', fn () => $this->decide($mapper, 'not_landing', $landing, ['reason' => 'sold as is']));
        self::refused(409, 'landing_page', fn () => $this->decide($lead, 'link', $landing, ['sku_id' => $this->item('legacy')]));
        self::refused(409, 'landing_page', fn () => $this->decide($lead, 'ignore', $landing));
        $r = $this->decide($lead, 'not_landing', $landing, ['reason' => 'customers buy it as it is']);
        self::assertSame(['applied', 'unmapped'], [$r['state'], $r['status']]);
        self::refused(409, 'not_landing', fn () => $this->decide($lead, 'not_landing', $landing, ['reason' => 'again']));

        $plan = $this->svc()->plan((int) $this->shop->channelId);
        self::assertSame('said_real', array_column($plan['rows'], 'outcome', 'listing_id')[$landing]);
        self::assertSame(0, $this->svc()->apply(Caller::system('landing_pages'), $plan, 'tool')['marked']);
        self::assertSame(0, $this->svc()->onArrival((int) $this->shop->channelId, [$landing])['marked'], 'nor on arrival');
        self::siteState($landing, ['site_is_landing' => 1]);
        self::assertSame('said_real', array_column($this->svc()->plan((int) $this->shop->channelId)['rows'], 'outcome', 'listing_id')[$landing],
            'not even when the website says so later');
        // A person may still mark it again by hand.
        $this->decide($lead, 'landing', $landing);
        self::assertSame('landing', $this->listingStatus($landing));

        // A mark the rule no longer finds (its options gone from the page, the website's word not known) stays, and is listed.
        self::$db->exec('UPDATE listing_profile SET site_state_at = NULL WHERE listing_id = ?', [$landing]);
        self::$db->exec("UPDATE listing_profile SET product_title = 'Something else' WHERE listing_id IN (?, ?)", [$mg10, $mg20]);
        $plan = $this->svc()->plan((int) $this->shop->channelId);
        self::assertSame('no_longer', array_column($plan['rows'], 'outcome', 'listing_id')[$landing]);
        $this->svc()->apply(Caller::system('landing_pages'), $plan, 'tool');
        self::assertSame('landing', $this->listingStatus($landing));
    }

    public function testLinkedIgnoredWaitingAndOneAtATimeAreLeftForAPerson(): void
    {
        [, , $landing] = $this->page();
        $sku = $this->item('legacy');
        $linked = $this->product('L1', null, ['product_title' => 'Linked Page', 'perma_link' => 'linked-page'], $sku);
        $this->product('L2', '10mg', ['product_title' => 'Linked Page', 'perma_link' => 'linked-page/10mg']);
        $lead = $this->staffUser('mapping_lead');
        $this->decide($lead, 'ignore', $landing, ['reason' => 'a page heading']);
        $plan = $this->svc()->plan((int) $this->shop->channelId);
        $outcomes = array_column($plan['rows'], 'outcome', 'listing_id');
        ksort($outcomes);
        self::assertSame([$landing => 'ignored', $linked => 'linked'], $outcomes);
        self::assertSame(0, $this->svc()->apply(Caller::system('landing_pages'), $plan, 'tool')['marked']);
        self::assertSame(['mapped', 'ignored'], [$this->listingStatus($linked), $this->listingStatus($landing)]);
        $with = $this->svc()->plan((int) $this->shop->channelId, null, true);
        self::assertSame(1, $this->svc()->apply(Caller::system('landing_pages'), $with, 'tool')['marked'], '--include-ignored');
        self::assertSame('landing', $this->listingStatus($landing));
        self::refused(409, 'linked', fn () => $this->decide($lead, 'landing', $linked));
    }

    public function testASuggestionOfTheCheckOnArrivalIsSettledByTheMarkItConfirmsNoMatch(): void
    {
        [, , $landing] = $this->page();
        $proposal = $this->propose($landing, 'Check', $this->item('legacy'), [], true, 'arrival-test');
        self::$db->exec('UPDATE match_run SET source = ? WHERE id = (SELECT match_run_id FROM match_proposal WHERE id = ?)', [\CW\Mapping\ArrivalCheck::SOURCE_WARNED, $proposal]);
        self::assertStringStartsWith(\CW\Mapping\OneAtATime::ARRIVAL . ':', (string) \CW\Mapping\OneAtATime::seen(self::$db, $landing), 'decided one at a time by a person');
        $plan = $this->svc()->plan((int) $this->shop->channelId);
        self::assertSame('mark', array_column($plan['rows'], 'outcome', 'listing_id')[$landing]);
        self::assertSame(1, $this->svc()->apply(Caller::system('landing_pages'), $plan, 'tool')['marked']);
        self::assertSame(['landing', 'decided'], [$this->listingStatus($landing), (string) self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$proposal])]);
    }

    public function testOnArrivalAPushMarksTheLandingPageWhenItsOptionsArrive(): void
    {
        $push = static fn (string $v, ?string $opt, array $extra = []): array => $extra + ['variant_id' => $v, 'product_title' => 'Elux Legend Cola Nic Salt 10ml',
            'variant_title' => 'Elux Legend Cola Nic Salt 10ml' . ($opt === null ? '' : ' - ' . $opt), 'perma_link' => 'elux-legend-cola' . ($opt === null ? '' : '/' . $opt),
            'attributes' => ['items' => $opt === null ? [['name' => 'Strength', 'value' => 'Choose an Option', 'is_variable' => 1]] : [['name' => 'Strength', 'value' => $opt, 'is_variable' => 1]]],
            'barcodes' => $opt === null ? [] : ['50' . $v]];
        $svc = new ListingIngestService(self::$db);
        $svc->push($this->shop, [$push('29695', null)]);
        $id = (int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = '29695'");
        self::assertSame('unmapped', $this->listingStatus($id), 'alone, the rule is not sure');
        $r = $svc->push($this->shop, [$push('29696', '10mg'), $push('29697', '20mg')]);
        self::assertArrayNotHasKey('landing_check', $r, 'the site is answered what it always was');
        self::assertSame('landing', $this->listingStatus($id), 'its options arrived: the page is looked at again');
        self::assertSame('arrival', json_decode((string) self::$db->value("SELECT CAST(detail AS CHAR) FROM match_decision WHERE listing_id = ? AND action = 'landing'", [$id]), true)['landing']['source']);

        // The setting off: the rule marks nothing new on arrival; the website's own flag still does.
        self::$db->exec("UPDATE app_setting SET value_json = 'false' WHERE setting_key = 'mapping.landing_rule_on_arrival'");
        $svc->push($this->shop, [$push('31049', null, ['product_title' => 'Firerose SlideGo 40k', 'variant_title' => 'Firerose SlideGo 40k', 'perma_link' => 'slidego']),
            $push('31050', 'Blue', ['product_title' => 'Firerose SlideGo 40k', 'variant_title' => 'Firerose SlideGo 40k - Blue', 'perma_link' => 'slidego/blue'])]);
        self::assertSame('unmapped', $this->listingStatus((int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = '31049'")));
        $svc->push($this->shop, [$push('31036', null, ['product_title' => 'Firerose Slidego 20K Pods', 'barcodes' => ['5099'],
            'website' => ['is_landing' => true, 'product_id' => '88', 'product_type' => 'variable', 'is_default' => true, 'variant_status' => 'Published', 'product_status' => 'Published']])]);
        self::assertSame('landing', $this->listingStatus((int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = '31036'")));
    }

    public function testLandingPagesLeaveEveryMatchingListAndCount(): void
    {
        [$mg10, , $landing] = $this->page();
        $item = $this->item('legacy');
        $this->propose($landing, 'Check', $item);
        $this->propose($mg10, 'Check', $item);
        $q = new Queries(self::$db);
        $ch = (int) $this->shop->channelId;
        self::assertSame(2, $q->listLeft('Check', $ch));
        $coverageBefore = $q->coverage()[$ch];
        $this->svc()->apply(Caller::system('landing_pages'), $this->svc()->plan($ch), 'tool');

        self::assertSame(1, $q->listLeft('Check', $ch), 'the match-strength list');
        self::assertSame([$mg10], array_map(static fn (array $r): int => (int) $r['listing_id'], $q->queue('Check', $ch, '', null, 0, 1)['rows']));
        $counts = $q->storeStateCounts($ch);
        self::assertSame([1, 1, 1], [$counts['landing'], $counts['suggested'], $counts['not_matched']], 'Store Products: its own state');
        self::assertSame([$landing], array_map(static fn (array $r): int => (int) $r['listing_id'], $q->storeProducts($ch, 'landing', '', 'sold_30', 1, $counts)['rows']));
        self::assertSame('landing', $q->storeProducts($ch, null, '', 'sold_30', 1, $counts)['rows'][0]['state'] ?? null, 'best sellers first: the landing page sold 4');
        self::assertNotContains($landing, array_map(static fn (array $r): int => (int) $r['listing_id'], $q->storeProducts($ch, 'not_matched', '', 'sold_30', 1, $counts)['rows']));
        $coverage = $q->coverage()[$ch];
        self::assertSame([$coverageBefore['listings'] - 1, $coverageBefore['u365'] - 4, 1], [$coverage['listings'], $coverage['u365'], $coverage['landing']],
            'coverage: not a product, not in the figures');
        self::assertSame(1, $q->newVariantCounts($ch, null)['landing']);
        self::assertSame(['same_page'], array_column($q->landingWhy([$landing]), 'reason'));
        self::assertNotContains($landing, $q->waitingListings($ch, '', 1)['ids'], 'the pickers');
    }

    public function testAProductNumberIsNotOverriddenByTheAddressOfAnotherProductCodexR3(): void
    {
        // A real product with one option (the website's product 100) whose address another product (200) of the same title hangs
        // an option under: the address finds no option of ITS page.
        $single = $this->product('100', null, ['perma_link' => 'blue-razz']);
        $other = $this->product('201', '10mg', ['perma_link' => 'blue-razz/10mg']);
        self::$db->exec('UPDATE listing_profile SET site_product_id = ? WHERE listing_id = ?', ['100', $single]);
        self::$db->exec('UPDATE listing_profile SET site_product_id = ? WHERE listing_id = ?', ['200', $other]);
        $plan = $this->svc()->plan((int) $this->shop->channelId);
        self::assertSame([], $plan['rows']);
        // an option of the same product number, or one whose number is not known, still counts
        self::$db->exec('UPDATE listing_profile SET site_product_id = NULL WHERE listing_id = ?', [$other]);
        self::assertSame([$single], array_column($this->svc()->plan((int) $this->shop->channelId)['rows'], 'listing_id'));
    }

    public function testAPlanThatIsNoLongerTrueUnderTheLockMarksNothingCodexR4(): void
    {
        $flagged = $this->product('A1', null, ['product_title' => 'Lost Mary BM600', 'perma_link' => 'lost-mary-bm600']);
        self::siteState($flagged, ['site_is_landing' => 1, 'site_product_type' => 'variable', 'site_product_id' => '77']);
        $plan = $this->svc()->plan((int) $this->shop->channelId);
        self::assertSame('mark', $plan['rows'][0]['outcome']);
        self::siteState($flagged, ['site_is_landing' => 0, 'site_product_type' => 'variable', 'site_product_id' => '77']); // the website says no since
        $r = $this->svc()->apply(Caller::system('landing_pages'), $plan, 'tool');
        self::assertSame(['marked' => 0, 'refused' => ['landing_changed' => 1], 'ids' => []], $r);
        self::assertSame('unmapped', $this->listingStatus($flagged));
    }

    public function testTheHourlyRunMarksWhatArrivedLatelyAndLeavesTheRestCodexR5(): void
    {
        [, , $landing] = $this->page();
        $old = $this->product('O1', null, ['product_title' => 'Old Page', 'perma_link' => 'old-page']);
        $this->product('O2', '10mg', ['product_title' => 'Old Page', 'perma_link' => 'old-page/10mg']);
        self::$db->exec('UPDATE channel_listing SET created_at = UTC_TIMESTAMP(6) - INTERVAL 5 DAY WHERE external_variant_id IN (?, ?)', ['O1', 'O2']);
        $r = self::tool('--recent-hours=48', '--apply');
        self::assertSame(0, $r['code'], $r['err'] . $r['out']);
        self::assertSame(['landing', 'unmapped'], [$this->listingStatus($landing), $this->listingStatus($old)], 'older products are the owner\'s dry run and --apply');
        self::assertSame('recent', json_decode((string) self::$db->value("SELECT CAST(detail AS CHAR) FROM match_decision WHERE listing_id = ? AND action = 'landing'", [$landing]), true)['landing']['source']);
        self::assertSame(2, self::tool('--recent-hours=48', '--channel=electrofag')['code'], 'every store: no --channel with it');
        foreach (['staging/cw-staging.cron' => 'cw_staging', 'production/cw-prod.cron' => 'cw_prod'] as $file => $db) {
            self::assertMatchesRegularExpression('#^23 \* \* \* \*  root  cd /opt/cw-[a-z]+ && php bin/landing_pages\.php --db=' . $db . ' --recent-hours=48 --apply >> /var/log/cw/landing_pages\.log 2>&1$#m',
                (string) file_get_contents(dirname(__DIR__, 3) . '/deploy/' . $file));
        }
    }

    public function testUnmatchedRealProductsAreAsBefore(): void
    {
        [$mg10, $mg20] = $this->page();
        $lead = $this->staffUser('mapping_lead');
        $this->decide($lead, 'ignore', $mg10, ['reason' => 'old stock']);
        $this->decide($lead, 'unignore', $mg10);
        $this->decide($lead, 'link', $mg20, ['sku_id' => $this->item('legacy')]);
        self::assertSame(['unmapped', 'mapped'], [$this->listingStatus($mg10), $this->listingStatus($mg20)]);
        self::assertSame(LandingRule::VERSION, 'lp1');
    }

    /** @return array{code: int, out: string, err: string} */
    private static function tool(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/landing_pages.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
