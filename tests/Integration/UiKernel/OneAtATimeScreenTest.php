<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Mapping\ArrivalCheck;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Mapping\OneAtATime;
use CW\Tests\Support\ArrivalFixtures;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\BulkController;
use CW\Ui\Html;
use CW\Ui\Queries;
use CW\Ui\Words;

/**
 * The rows no bulk action takes, on the screens (docs/decisions.md M63, U139-U143), through the real /ui kernel as cw_app. The owner
 * bulk-confirmed a store's strong matches until page 1 of the list held only rows a bulk action always skips (held back, or a match
 * of a spot check), and "tick all + Confirm" did nothing without saying why. So:
 *  - such a row has no tick box on a match-strength list, on Store Products and on New Variants, and says so under its name (a
 *    held-back one: when and by whom, and that its own page says why; the hold's note is not printed on a list); "Select all on
 *    this page" leaves it; a notice above the match-strength list says how many of the page have no box and leads to the others;
 *  - "Show" on a match-strength list: All, Ready for a bulk action, Held back, In a spot check, each with its count; each lists
 *    exactly its rows; it travels wherever the list's other choices travel; after the last row of a choice (or of any other
 *    filter) the list is not called done;
 *  - "Ready for a bulk action" + tick all + Confirm match takes a full page and skips none of them as held or in a spot check;
 *  - the engine is unchanged: a hand-made form that names such a row is skipped as before, on every list.
 *
 * Since the check on arrival (M64, U144) there is a third kind of such a row: a variant whose open suggestion the check on arrival
 * made (a barcode or a copied web address, no AI). The same promises hold for it: no tick box and a line under its name on the three
 * lists, its own choice of "Show" ("Found on arrival") with its count, never among "Ready for a bulk action", and All is still the
 * sum of the choices. With the setting `mapping.arrival_bulk_confirm` on, a clean match of the check is an ordinary row again; one
 * with a warning never. And while the check has written nothing, every list and every count reads exactly as before.
 */
final class OneAtATimeScreenTest extends KernelUiTestCase
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
     * One store (`alt`) with strong matches, best sellers first in this order: 9 of a first match, of which a spot check `sc1`
     * holds 5 (drawn by the server: which five is not known beforehand) and 2 of the other 4 are held back, the last 2 free; then
     * $more free ones made after the spot check (in no spot check's population), all suggested for one item.
     *
     * @return array{lead: Caller, sample: int, spot: list<int>, held: list<int>, free: list<int>, ready: list<int>, all: list<int>, sku: int, proposal: array<int, int>}
     */
    private function strongList(int $more = 3): array
    {
        $this->keySetup();
        $this->setting('approvals.spot_check_size', '5');
        $lead = $this->staffUser('mapping_lead');
        $pop = [];
        for ($i = 0; $i < 9; $i++) {
            $pop[] = $this->firstMatch($this->vpgItem(), 90 + $i, [], [], null, [], 900 - $i);
        }
        $sample = (new KeySample(self::$db))->create($lead, 'sc1', 5, true);
        $spot = array_map('intval', array_column($sample['members'], 'listing_id'));
        $others = array_values(array_filter($pop, static fn (array $m): bool => !in_array($m['listing'], $spot, true)));
        self::assertCount(4, $others);
        $h = (new KeyHold(self::$db))->run($lead, 'sc1', KeyHold::parse("proposal_id,listing_id,reason\n{$others[0]['proposal']},{$others[0]['listing']},Pack size differs\n"
            . "{$others[1]['proposal']},{$others[1]['listing']},Flavour is not the same\n"), false, true);
        self::assertSame(2, $h['held_after']);
        $free = [$others[2]['listing'], $others[3]['listing']];
        $sku = $this->item('legacy');
        $ready = $free;
        for ($i = 0; $i < $more; $i++) {
            $ready[] = $this->queued($this->altSite, 'R' . $i, 'Key', $sku, ['product_title' => 'Ready one ' . $i, 'units_365d' => 500 - $i]);
        }
        $proposal = [];
        foreach (self::$db->all('SELECT id, open_listing_id FROM match_proposal WHERE open_listing_id IS NOT NULL') as $p) {
            $proposal[(int) $p['open_listing_id']] = (int) $p['id'];
        }
        return ['lead' => $lead, 'sample' => (int) $sample['sample_id'], 'spot' => $spot, 'held' => [$others[0]['listing'], $others[1]['listing']], 'free' => $free,
            'ready' => $ready, 'all' => [...array_column($pop, 'listing'), ...array_slice($ready, 2)], 'sku' => $sku, 'proposal' => $proposal];
    }

    private static function squash(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /** @return list<int> the listings of a list's rows, in order */
    private static function listed(UiResponse $r): array
    {
        $out = [];
        foreach ((new \DOMXPath($r->dom()))->query('//tbody/tr/th/a[@class="o-name"]') as $a) {
            /** @var \DOMElement $a */
            self::assertSame(1, preg_match('#^/ui/review/listing/(\d+)(\?|$)#', $a->getAttribute('href'), $m), $a->getAttribute('href'));
            $out[] = (int) $m[1];
        }
        return $out;
    }

    /** @return list<int> the listings whose row has a tick box (with $ticked: a ticked one) */
    private static function boxes(UiResponse $r, bool $ticked = false): array
    {
        $out = [];
        foreach ((new \DOMXPath($r->dom()))->query('//tbody/tr//input[@type="checkbox"][@data-bulk-row]' . ($ticked ? '[@checked]' : '')) as $box) {
            /** @var \DOMElement $box */
            $out[] = (int) substr($box->getAttribute('name'), strlen('pick_'));
        }
        return $out;
    }

    /** The row of a listing on a list (its name links to the listing). */
    private static function row(UiResponse $r, int $listing): string
    {
        return '//tbody/tr[th/a[@class="o-name"][@href="/ui/review/listing/' . $listing . '" or starts-with(@href, "/ui/review/listing/' . $listing . '?")]]';
    }

    /** The line under a row's name that says why it has no tick box, or null when it has none. */
    private static function whyLine(UiResponse $r, int $listing): ?string
    {
        $xp = new \DOMXPath($r->dom());
        $span = $xp->query(self::row($r, $listing) . '/th/span[contains(@class, "no-bulk")]');
        self::assertLessThanOrEqual(1, $span->length);
        return $span->length === 0 ? null : self::squash($span->item(0)?->textContent);
    }

    /** @return list<string> the "Show" filter: each choice with its count ("Held back 2") */
    private static function shows(UiResponse $r): array
    {
        return array_map(static fn (\DOMNode $a): string => self::squash($a->textContent),
            iterator_to_array((new \DOMXPath($r->dom()))->query('//nav[contains(@class, "show-tabs")]/a')));
    }

    private static function shown(UiResponse $r): string
    {
        return self::squash((new \DOMXPath($r->dom()))->evaluate('string(//nav[contains(@class, "show-tabs")]/a[@aria-current="page"])'));
    }

    /** @return array{0: string, 1: string} the notice above the list (its text, and where its link leads; '' for none) */
    private static function notice(UiResponse $r): array
    {
        $xp = new \DOMXPath($r->dom());
        return [self::squash($xp->evaluate('string(//div[contains(@class, "no-box")])')), (string) $xp->evaluate('string(//div[contains(@class, "no-box")]//a/@href)')];
    }

    /** What a hand-made form sends for a row: ticked, with the version and the suggestion the list would show. @return array<string, string> */
    private function ticked(int $listing): array
    {
        return ['pick_' . $listing => '1', 'ver_' . $listing => (string) $this->version($listing),
            'prop_' . $listing => (string) (self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listing]) ?? '')];
    }

    /** @return array<int, array{outcome: string, skip_code: ?string, detail: mixed}> listing => its row of the batch a redirect leads to */
    private static function batch(UiResponse $redirect): array
    {
        self::assertSame(303, $redirect->status, $redirect->describe());
        self::assertSame(1, preg_match('#^/ui/review/batches/(\d+)(\?|$)#', (string) $redirect->location(), $m), (string) $redirect->location());
        $out = [];
        foreach (self::$db->all('SELECT listing_id, outcome, skip_code, CAST(detail AS CHAR) AS detail FROM mapping_batch_row WHERE batch_id = ? ORDER BY seq', [(int) $m[1]]) as $row) {
            $out[(int) $row['listing_id']] = ['outcome' => (string) $row['outcome'], 'skip_code' => $row['skip_code'] === null ? null : (string) $row['skip_code'],
                'detail' => $row['detail'] === null ? null : json_decode((string) $row['detail'], true)];
        }
        return $out;
    }

    public function testAHeldRowAndASpotCheckRowHaveNoTickBoxSayWhyAndSelectAllLeavesThem(): void
    {
        $f = $this->strongList();
        $web = $this->signIn($this->uiUser('mapper'));
        $query = ['queue' => 'Key', 'channel' => 'alt'];
        $page = $web->get('/ui/review', $query);
        self::assertSame(200, $page->status, $page->describe());
        self::assertEqualsCanonicalizing($f['all'], self::listed($page));
        self::assertCount(12, self::listed($page));

        // A box on the rows a bulk action can take, and only there: the others send nothing at all (no box, no version, no suggestion).
        self::assertEqualsCanonicalizing($f['ready'], self::boxes($page));
        $form = $page->form('/ui/review/bulk');
        foreach ([...$f['held'], ...$f['spot']] as $id) {
            self::assertSame([false, false, false], [isset($form['pick_' . $id]), isset($form['ver_' . $id]), isset($form['prop_' . $id])], "row {$id} is not in the form");
            self::assertSame(1, (new \DOMXPath($page->dom()))->query(self::row($page, $id) . '/td[contains(@class, "c-pick")]')->length, 'its tick cell is there, and empty');
            self::assertSame('', self::squash((new \DOMXPath($page->dom()))->evaluate('string(' . self::row($page, $id) . '/td[contains(@class, "c-pick")])')));
        }
        foreach ($f['ready'] as $id) {
            self::assertTrue(isset($form['ver_' . $id], $form['prop_' . $id]), "row {$id} is in the form");
            self::assertNull(self::whyLine($page, $id), 'a row a bulk action can take says nothing under its name');
        }

        // What it is, and what to do. Held back: the day and who set it aside, and where to see why. The hold's note is not printed
        // on a list (a stored note is a long rule text; a build decision of 12 Oct 2026): the row's own page says it in full.
        $hold = self::$db->one('SELECT h.created_at, u.display_name FROM key_bulk_hold h JOIN staff_user u ON u.id = h.staff_user_id WHERE h.listing_id = ?', [$f['held'][0]]) ?? [];
        $by = (string) $hold['display_name'];
        $day = Html::day((string) $hold['created_at']);
        self::assertSame('Held back on ' . $day . ' (set aside by ' . $by . '). Open it to see why and to decide it.', self::whyLine($page, $f['held'][0]));
        self::assertSame(Words::say('ONE_AT_A_TIME', 'held', $day, $by), self::whyLine($page, $f['held'][1]));
        foreach (['Pack size differs', 'Flavour is not the same'] as $note) {
            self::assertStringNotContainsString($note, $page->text(), 'a hold\'s note is not printed on the list');
        }
        $own = $web->get('/ui/review/listing/' . $f['held'][0], $query);
        self::assertStringContainsString(Words::say('LISTING', 'held', 'Pack size differs', $by, $day), $own->text(), 'its own page says why, in full, with the same name and day');
        // A match of a spot check: its name, and the way to it.
        foreach ($f['spot'] as $id) {
            self::assertSame('In spot check sc1: it is answered in the spot check. Open the spot check', self::whyLine($page, $id));
            self::assertSame('/ui/review/samples/' . $f['sample'], (new \DOMXPath($page->dom()))->evaluate('string(' . self::row($page, $id) . '/th/span[contains(@class, "no-bulk")]/a/@href)'));
        }
        self::assertSame(200, $web->get('/ui/review/samples/' . $f['sample'])->status);

        // "Select all on this page" without the script (`all=1`): the rows that have a box, and no other.
        $all = $web->get('/ui/review', $query + ['all' => '1']);
        self::assertEqualsCanonicalizing($f['ready'], self::boxes($all, true));
        $sent = array_keys(array_filter($all->form('/ui/review/bulk'), static fn (string $v, string $k): bool => str_starts_with($k, 'pick_') && $v === '1', ARRAY_FILTER_USE_BOTH));
        self::assertEqualsCanonicalizing(array_map(static fn (int $id): string => 'pick_' . $id, $f['ready']), $sent);
        $axp = new \DOMXPath($all->dom());
        self::assertSame(count($f['ready']), $axp->query('//tbody/tr[contains(@class, "picked")]')->length);
        self::assertSame(0, $axp->query('//tbody/tr[contains(@class, "picked")][th/span[contains(@class, "no-bulk")]]')->length, 'a row with no box is never drawn as ticked');
        // With the script: its "select all" ticks the boxes marked data-bulk-row, and the rows without a box have none.
        self::assertSame(count($f['ready']), (new \DOMXPath($page->dom()))->query('//input[@data-bulk-row]')->length);
        self::assertSame(1, (new \DOMXPath($page->dom()))->query('//input[@data-bulk-all]')->length);

        // The notice above the list: how many of the page have no box, why, and the way to the ones a bulk action can take.
        self::assertSame(['2 on this page are held back and 5 are in a spot check: they are decided one at a time and have no tick box. '
            . 'Show only the ones a bulk action can take (5)', '/ui/review?queue=Key&channel=alt&show=ready'], self::notice($page));
        self::assertSame(1, (new \DOMXPath($page->dom()))->query('//div[contains(@class, "no-box")]')->length);
        $xp = new \DOMXPath($page->dom());
        self::assertLessThan((int) $xp->evaluate('count(//table[contains(@class, "queue")]/preceding::*)'), (int) $xp->evaluate('count(//div[contains(@class, "no-box")]/preceding::*)'),
            'above the list');

        // A page where no row can be ticked (the page the owner was stuck on): no bar and no "select all" that could only answer
        // "tick a row first"; the notice says why and leads on.
        $stuck = $web->get('/ui/review', $query + ['show' => 'held']);
        self::assertEqualsCanonicalizing($f['held'], self::listed($stuck));
        self::assertFalse($stuck->hasForm('/ui/review/bulk'));
        $sxp = new \DOMXPath($stuck->dom());
        self::assertSame([0, 0, 0], [$sxp->query('//input[@data-bulk-row or @data-bulk-all]')->length, $sxp->query('//*[@data-bulk-all-link]')->length,
            $sxp->query('//div[@data-bulkbar]')->length]);
        self::assertSame(['2 on this page are held back: they are decided one at a time and have no tick box. Show only the ones a bulk action can take (5)',
            '/ui/review?queue=Key&channel=alt&show=ready'], self::notice($stuck));
        $spots = $web->get('/ui/review', $query + ['show' => 'spot']);
        self::assertSame('5 on this page are in a spot check: they are decided one at a time and have no tick box. Show only the ones a bulk action can take (5)',
            self::notice($spots)[0]);

        // No notice and no line on a page whose every row has its box.
        $this->queued($this->altSite, 'L1', 'Check', $f['sku'], ['product_title' => 'Likely one']);
        $likely = $web->get('/ui/review', ['queue' => 'Check', 'channel' => 'alt']);
        self::assertSame(['', ''], self::notice($likely));
        self::assertSame(0, (new \DOMXPath($likely->dom()))->query('//span[contains(@class, "no-bulk")]')->length);
        self::assertCount(1, self::boxes($likely));
        $readyOnly = $web->get('/ui/review', $query + ['show' => 'ready']);
        self::assertSame(['', ''], self::notice($readyOnly));
        self::assertEqualsCanonicalizing($f['ready'], self::boxes($readyOnly));

        // Someone who can only look has no boxes at all, so no notice about them; the rows still say what they are.
        $look = $this->signIn($this->uiUser('viewer'))->get('/ui/review', $query);
        self::assertSame(200, $look->status, $look->describe());
        self::assertSame([[], ['', '']], [self::boxes($look), self::notice($look)]);
        self::assertSame(Words::say('ONE_AT_A_TIME', 'held', $day, $by), self::whyLine($look, $f['held'][0]));
    }

    public function testShowListsExactlyItsRowsWithItsCountAndAllIsTheSum(): void
    {
        $f = $this->strongList();
        // One of the spot check's matches is held back as well (the hold tool refuses that; the table does not): it is "held back",
        // the first of the two that is asked, and is listed and counted once.
        $both = $f['spot'][0];
        self::$db->exec("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, reason, staff_user_id, actor) VALUES (?, ?, ?, 'hold', 'Held as well', ?, ?)",
            [$f['sample'], $f['proposal'][$both], $both, $f['lead']->staffUserId, $f['lead']->actor]);
        $parts = ['ready' => $f['ready'], 'held' => [...$f['held'], $both], 'spot' => array_values(array_diff($f['spot'], [$both]))];
        $web = $this->signIn($this->uiUser('mapper'));
        $query = ['queue' => 'Key', 'channel' => 'alt'];
        $order = self::listed($web->get('/ui/review', $query));
        self::assertCount(12, $order);

        foreach (['all' => $f['all']] + $parts as $show => $ids) {
            $page = $web->get('/ui/review', $query + ($show === 'all' ? [] : ['show' => $show]));
            self::assertSame(200, $page->status, $page->describe());
            self::assertSame(array_values(array_intersect($order, $ids)), self::listed($page), "{$show}: exactly its rows, in the list's order (best sellers first)");
            self::assertCount(count($ids), self::listed($page), $show);
            self::assertSame(['All 12', 'Ready for a bulk action 5', 'Held back 3', 'In a spot check 4'], self::shows($page), "{$show}: each choice with its count");
            self::assertSame(Words::QUEUE_SHOW[$show] . ' ' . count($ids), self::shown($page), "{$show}: the count of the choice is its list");
            self::assertStringContainsString(Words::say('QUEUE', 'in_all', count($ids), count($ids)), $page->text(), $show);
            self::assertSame(Words::QUEUE['show'], self::squash((new \DOMXPath($page->dom()))->evaluate('string(//nav[contains(@class, "show-tabs")]/@aria-label)')));
        }
        self::assertSame(12, count($parts['ready']) + count($parts['held']) + count($parts['spot']), 'All is the sum of the three');
        self::assertSame(['/ui/review?queue=Key&channel=alt', '/ui/review?queue=Key&channel=alt&show=ready', '/ui/review?queue=Key&channel=alt&show=held',
            '/ui/review?queue=Key&channel=alt&show=spot'], array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array((new \DOMXPath($web->get('/ui/review', $query + ['show' => 'held', 'page' => '1'])->dom()))->query('//nav[contains(@class, "show-tabs")]/a'))),
            'a choice opens the list from its first page');

        // The three parts are OneAtATime's own answer for each row: what the list shows and what a bulk action skips cannot disagree.
        $never = OneAtATime::of(self::$db, $f['all']);
        foreach ($f['all'] as $id) {
            self::assertContains($id, $parts[['held' => 'held', 'in_spot_check' => 'spot'][$never[$id]['code'] ?? ''] ?? 'ready'], "row {$id}");
        }
        $q = new Queries(self::$db);
        $alt = (int) $this->altSite->channelId;
        foreach (Queries::SHOWS as $show) {
            $r = $q->queue('Key', $alt, '', null, 0, 1, $show);
            self::assertSame(['all' => 12, 'ready' => 5, 'held' => 3, 'spot' => 4, 'arrival' => 0], $r['shows'], $show);
            self::assertSame([], $r['arrival_runs'], 'nothing found by the check on arrival: nothing of it is read or named');
            self::assertSame([$r['shows'][$show], $r['shows'][$show]], [$r['total'], count($r['rows'])], $show);
        }

        // An unknown word is every row, as if it were not there; it is not carried on.
        foreach (['nonsense', 'READY', 'in_spot_check', ''] as $junk) {
            $page = $web->get('/ui/review', $query + ['show' => $junk]);
            self::assertSame($order, self::listed($page), $junk);
            self::assertSame('All 12', self::shown($page), $junk);
            self::assertContains('/ui/review/listing/' . $order[0] . '?queue=Key&channel=alt', $page->hrefs(), $junk);
        }

        // The counts are of the list as it is filtered: a search, the units sold, another store.
        $variant = (string) self::$db->value('SELECT external_variant_id FROM channel_listing WHERE id = ?', [$f['held'][0]]);
        $found = $web->get('/ui/review', $query + ['q' => $variant]);
        self::assertSame([[$f['held'][0]], ['All 1', 'Ready for a bulk action 0', 'Held back 1', 'In a spot check 0']], [self::listed($found), self::shows($found)]);
        self::assertSame([$f['held'][0]], self::listed($web->get('/ui/review', $query + ['q' => $variant, 'show' => 'held'])));
        $none = $web->get('/ui/review', $query + ['q' => $variant, 'show' => 'ready']);
        self::assertSame([], self::listed($none));
        self::assertStringContainsString(Words::QUEUE['empty_filter'], $none->text(), 'a choice that hides every row says so, with the way out');
        self::assertContains('/ui/review?queue=Key&channel=alt', $none->hrefs());
        self::assertSame(['All 1', 'Ready for a bulk action 0', 'Held back 1', 'In a spot check 0'], self::shows($none), 'and the choices stay, to pick another');
        // (the first match's nine sold 89 or 90 in 30 days, the ones made after it none)
        self::assertSame(['All 9', 'Ready for a bulk action 2', 'Held back 3', 'In a spot check 4'], self::shows($web->get('/ui/review', $query + ['min' => '1'])));
        self::assertEqualsCanonicalizing($f['free'], self::listed($web->get('/ui/review', $query + ['min' => '1', 'show' => 'ready'])));
        $big = $this->site('vbig', 'shadow');
        $other = $this->queued($big, 'B1', 'Key', $f['sku'], ['product_title' => 'Big strong']);
        self::assertSame(['All 13', 'Ready for a bulk action 6', 'Held back 3', 'In a spot check 4'], self::shows($web->get('/ui/review', ['queue' => 'Key'])), 'all stores');
        self::assertSame(['All 1', 'Ready for a bulk action 1', 'Held back 0', 'In a spot check 0'], self::shows($web->get('/ui/review', ['queue' => 'Key', 'channel' => 'vbig'])));
        self::assertSame([$other], self::listed($web->get('/ui/review', ['queue' => 'Key', 'channel' => 'vbig', 'show' => 'ready'])));

        // A hold released again, and a match of the spot check answered by its owner: the list and its counts follow.
        (new KeyHold(self::$db))->run($f['lead'], 'sc1', KeyHold::parse("proposal_id,listing_id,reason\n{$f['proposal'][$f['held'][1]]},{$f['held'][1]},It is fine after all\n"), true, true);
        $this->confirm($f['lead'], $f['proposal'][$f['spot'][1]]);
        $after = $web->get('/ui/review', $query + ['show' => 'ready']);
        self::assertSame(['All 11', 'Ready for a bulk action 6', 'Held back 2', 'In a spot check 3'], self::shows($after));
        self::assertEqualsCanonicalizing([...$f['ready'], $f['held'][1]], self::listed($after));

        // An empty list has no parts to choose from.
        $empty = $web->get('/ui/review', ['queue' => 'Conflict', 'channel' => 'alt', 'show' => 'held']);
        self::assertSame(200, $empty->status, $empty->describe());
        self::assertSame([[], []], [self::listed($empty), self::shows($empty)]);
        self::assertSame(['all' => 0, 'ready' => 0, 'held' => 0, 'spot' => 0, 'arrival' => 0], $q->queue('Conflict', $alt, '', null, 0, 1, 'held')['shows']);
        // "Found on arrival" is offered only where the check on arrival found something (U144): here it is not, and a request for it
        // lists nothing and still shows the four choices, with itself as the fifth while it is the one chosen.
        $noneFound = $web->get('/ui/review', $query + ['show' => 'arrival']);
        self::assertSame([[], 'Found on arrival 0'], [self::listed($noneFound), self::shown($noneFound)]);
        self::assertSame(['All 11', 'Ready for a bulk action 6', 'Held back 2', 'In a spot check 3', 'Found on arrival 0'], self::shows($noneFound));
    }

    public function testWhatTheListShowsTravelsThroughThePagerTheVariantPageTheNextOneTheBulkFormAndBack(): void
    {
        $f = $this->strongList(53);
        // A known order at the top: a ready one, a held one, a spot check's, a ready one.
        [$first, $second] = [$f['ready'][2], $f['ready'][3]];
        foreach ([[$first, 2000], [$f['held'][0], 1999], [$f['spot'][0], 1998], [$second, 1997], [$f['held'][1], 1996], [$f['spot'][1], 1995]] as [$id, $units]) {
            self::$db->exec('UPDATE listing_profile SET units_365d = ?, units_30d = 0 WHERE listing_id = ?', [$units, $id]);
        }
        $web = $this->signIn($this->uiUser('mapper'));
        $query = ['queue' => 'Key', 'channel' => 'alt', 'show' => 'ready'];
        $list = '/ui/review?queue=Key&channel=alt&show=ready';

        // The pager: 55 ready ones are two pages of the same choice.
        $p1 = $web->get('/ui/review', $query);
        self::assertSame(200, $p1->status, $p1->describe());
        self::assertSame([50, $first, $second], [count(self::listed($p1)), self::listed($p1)[0], self::listed($p1)[1]]);
        $xp = new \DOMXPath($p1->dom());
        self::assertSame($list . '&page=2', $xp->evaluate('string(//nav[contains(@class, "pager")]/a[@rel="next"]/@href)'));
        $p2 = $web->get('/ui/review', $query + ['page' => '2']);
        self::assertCount(5, self::listed($p2));
        self::assertSame($list . '&page=1', (new \DOMXPath($p2->dom()))->evaluate('string(//nav[contains(@class, "pager")]/a[@rel="prev"]/@href)'));
        self::assertSame([], array_intersect(self::listed($p1), self::listed($p2)));
        self::assertEqualsCanonicalizing($f['ready'], [...self::listed($p1), ...self::listed($p2)]);
        // The store selector, the strength tabs and the search box keep it.
        $stores = array_map(static fn (\DOMElement $a): string => $a->getAttribute('href'), iterator_to_array($xp->query('//nav[contains(@class, "store-seg")]/a')));
        self::assertContains('/ui/review?queue=Key&show=ready', $stores, 'All stores');
        self::assertContains('/ui/review?queue=Key&show=ready&channel=alt', $stores);
        self::assertSame(['/ui/review?queue=Key&channel=alt&show=ready', '/ui/review?queue=Check&channel=alt&show=ready'],
            [$xp->evaluate('string(//nav[contains(@class, "legend")]/a[1]/@href)'), $xp->evaluate('string(//nav[contains(@class, "legend")]/a[2]/@href)')]);
        self::assertSame('ready', $xp->evaluate('string(//form[contains(@class, "toolbar")]/input[@name="show"]/@value)'));
        self::assertSame(0, (new \DOMXPath($web->get('/ui/review', ['queue' => 'Key', 'channel' => 'alt'])->dom()))->query('//form[contains(@class, "toolbar")]/input[@name="show"]')->length);
        // The pages around the lists do not take it: the segments of Mapping lead where they always led.
        self::assertSame(['/ui/review?channel=alt', '/ui/review/samples?channel=alt', '/ui/review?queue=pending&channel=alt'], array_column(self::segments($p1), 'href'));

        // A variant's page, the way back, and "the next one": the next READY one, where the whole list's next one is a held one.
        self::assertContains('/ui/review/listing/' . $first . '?queue=Key&channel=alt&show=ready', $p1->hrefs());
        $page = $web->get('/ui/review/listing/' . $first, $query);
        self::assertSame(200, $page->status, $page->describe());
        $lxp = new \DOMXPath($page->dom());
        self::assertSame([$list, '/ui/review/listing/' . $second . '?queue=Key&channel=alt&show=ready'],
            [$lxp->evaluate('string(//p[@class="crumbs"]/a[1]/@href)'), $lxp->evaluate('string(//p[@class="crumbs"]/a[@class="next-link"]/@href)')]);
        self::assertSame('/ui/review/listing/' . $f['held'][0] . '?queue=Key&channel=alt', (new \DOMXPath($web->get('/ui/review/listing/' . $first, ['queue' => 'Key', 'channel' => 'alt'])->dom()))
            ->evaluate('string(//p[@class="crumbs"]/a[@class="next-link"]/@href)'), 'every row: the next one is the next of them all');
        self::assertSame('/ui/review/listing/' . $f['held'][1] . '?queue=Key&channel=alt&show=held', (new \DOMXPath($web->get('/ui/review/listing/' . $f['held'][0],
            ['show' => 'held'] + $query)->dom()))->evaluate('string(//p[@class="crumbs"]/a[@class="next-link"]/@href)'), 'the held ones: the next held one');
        self::assertSame('/ui/review/listing/' . $f['spot'][1] . '?queue=Key&channel=alt&show=spot', (new \DOMXPath($web->get('/ui/review/listing/' . $f['spot'][0],
            ['show' => 'spot'] + $query)->dom()))->evaluate('string(//p[@class="crumbs"]/a[@class="next-link"]/@href)'), 'a spot check\'s: its next one');
        $q = new Queries(self::$db);
        $alt = (int) $this->altSite->channelId;
        self::assertSame([$f['held'][0], $second, $f['held'][0], $f['spot'][0], $f['held'][0]], [$q->nextInQueue('Key', $alt, '', null, 0, $first),
            $q->nextInQueue('Key', $alt, '', null, 0, $first, 'ready'), $q->nextInQueue('Key', $alt, '', null, 0, $first, 'held'), $q->nextInQueue('Key', $alt, '', null, 0, $first, 'spot'),
            $q->nextInQueue('Key', $alt, '', null, 0, $first, 'nonsense')], 'the next one of each choice after the same row; an unknown word is every row');
        // Deciding it there opens the next ready one, with the choice carried on.
        $form = $page->form('/ui/review/listing/' . $first . '/decide');
        self::assertSame(['Key', 'alt', 'ready', 'link'], [$form['queue'] ?? null, $form['channel'] ?? null, $form['show'] ?? null, $form['action'] ?? null]);
        $r = $web->post('/ui/review/listing/' . $first . '/decide', $form);
        self::assertSame(303, $r->status, $r->describe());
        parse_str((string) parse_url((string) $r->location(), PHP_URL_QUERY), $to);
        self::assertSame(['/ui/review/listing/' . $second, ['queue' => 'Key', 'channel' => 'alt', 'show' => 'ready', 'notice' => 'decided_link', 'prev' => (string) $first]],
            [parse_url((string) $r->location(), PHP_URL_PATH), $to]);
        self::assertSame('mapped', $this->link($first)['status']);

        // The bulk form keeps it; the second step of "Not a match" keeps it, and so does its way back.
        $p1 = $web->get('/ui/review', $query);
        $bulk = $p1->form('/ui/review/bulk');
        self::assertSame(['review', 'Key', 'alt', 'ready'], [$bulk['source'] ?? null, $bulk['queue'] ?? null, $bulk['channel'] ?? null, $bulk['show'] ?? null]);
        $third = self::listed($p1)[1];
        $step = $web->post('/ui/review/bulk', ['pick_' . $third => '1', 'action' => 'reject'] + $bulk);
        self::assertSame(200, $step->status, $step->describe());
        self::assertSame('ready', $step->form('/ui/review/bulk')['show'] ?? null);
        self::assertSame($list, (new \DOMXPath($step->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)'));
        // The result page leads back to the list as it was shown.
        $done = $web->post('/ui/review/bulk', $step->form('/ui/review/bulk'));
        self::assertSame(303, $done->status, $done->describe());
        self::assertMatchesRegularExpression('#^/ui/review/batches/[0-9]+\?show=ready$#', (string) $done->location());
        $result = $web->follow($done);
        self::assertSame(200, $result->status, $result->describe());
        self::assertSame($list, (new \DOMXPath($result->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)'));
        $batch = (string) parse_url((string) $done->location(), PHP_URL_PATH);
        self::assertSame(['/ui/review?queue=Key&channel=alt', '/ui/review?queue=Key&channel=alt', '/ui/review?queue=Key&channel=alt&show=held'], array_map(
            static fn (array $more): string => (string) (new \DOMXPath($web->get($batch, $more)->dom()))->evaluate('string(//p[@class="crumbs"]/a/@href)'),
            [[], ['show' => 'nonsense'], ['show' => 'held']]), 'the batch page reads the choice as the list does: one of its own, or none');
        // A refusal's way back keeps it too.
        $refused = $web->post('/ui/review/bulk', ['action' => 'link'] + $bulk);
        self::assertSame(422, $refused->status, $refused->describe());
        self::assertSame($list, (new \DOMXPath($refused->dom()))->evaluate('string(//p[@class="actions"]/a[contains(@class, "primary")]/@href)'));
        // A batch of another list never carries it.
        $store = $web->get('/ui/review/store', ['channel' => 'alt']);
        $other = $web->post('/ui/review/bulk', $this->ticked($second) + ['action' => 'send_back', 'confirm' => '1', 'show' => 'ready'] + $store->form('/ui/review/bulk'));
        self::assertSame(303, $other->status, $other->describe());
        self::assertMatchesRegularExpression('#^/ui/review/batches/[0-9]+$#', (string) $other->location());
    }

    public function testReadyTickAllConfirmTakesAFullPageAndSkipsNoneAsHeldOrInASpotCheck(): void
    {
        $f = $this->strongList(50);
        $web = $this->signIn($this->uiUser('mapper'));
        $query = ['queue' => 'Key', 'channel' => 'alt'];
        // The list as it was: its first page holds the 7 that no bulk action takes, so "tick all" there ticks 43.
        $whole = $web->get('/ui/review', $query + ['all' => '1']);
        self::assertSame([50, 43], [count(self::listed($whole)), count(self::boxes($whole, true))]);
        self::assertSame('2 on this page are held back and 5 are in a spot check: they are decided one at a time and have no tick box. Show only the ones a bulk action can take (52)',
            self::notice($whole)[0]);

        // "Ready for a bulk action", "Select all on this page", "Confirm match": a full page, every row of it done.
        $ready = $web->get('/ui/review', $query + ['show' => 'ready', 'all' => '1']);
        self::assertSame(200, $ready->status, $ready->describe());
        $rows = self::listed($ready);
        self::assertCount(50, $rows);
        self::assertSame($rows, self::boxes($ready, true), 'every row of the page has a box, and "select all" ticks it');
        self::assertSame([], array_intersect($rows, [...$f['held'], ...$f['spot']]));
        self::assertSame(0, (new \DOMXPath($ready->dom()))->query('//span[contains(@class, "no-bulk")]')->length);
        $r = $web->post('/ui/review/bulk', $ready->form('/ui/review/bulk') + ['action' => 'link']);
        $batch = self::batch($r);
        self::assertEqualsCanonicalizing($rows, array_keys($batch), 'the batch is the page');
        self::assertSame([], array_values(array_filter(array_column($batch, 'skip_code'), static fn (?string $c): bool => in_array($c, ['held', 'in_spot_check', 'held_meanwhile'], true))),
            'no row skipped as held back or in a spot check');
        self::assertSame(['done' => 50], array_count_values(array_column($batch, 'outcome')));
        self::assertSame(50, (int) self::$db->value("SELECT COUNT(*) FROM channel_listing WHERE status = 'mapped' AND id IN (" . implode(',', $rows) . ')'));
        self::assertStringContainsString(Words::say('BULK', 'summary', Words::say('BULK_DONE', 'link', 50), 0, 0), $web->follow($r)->text());
        // The seven are as they were, and the next page of the ready ones is what is left of them.
        foreach ([...$f['held'], ...$f['spot']] as $id) {
            self::assertSame('suggested', $this->link($id)['status'], "row {$id}");
        }
        $left = $web->get('/ui/review', $query + ['show' => 'ready']);
        self::assertSame(['All 9', 'Ready for a bulk action 2', 'Held back 2', 'In a spot check 5'], self::shows($left));
        self::assertEqualsCanonicalizing(array_values(array_diff($f['ready'], $rows)), self::listed($left));
    }

    public function testAHandMadeFormThatTicksAHeldRowIsStillSkippedAsHeldOnEveryList(): void
    {
        $f = $this->strongList(1);
        [$held, $spot, $free] = [$f['held'][0], $f['spot'][0], $f['ready'][2]];
        $web = $this->signIn($this->uiUser('mapper'));
        $csrf = $this->token($web);
        $hold = self::$db->one('SELECT h.created_at, u.display_name FROM key_bulk_hold h JOIN staff_user u ON u.id = h.staff_user_id WHERE h.listing_id = ?', [$held]) ?? [];
        $decisions = $this->decisions();
        foreach ([
            'a match-strength list: Confirm match' => ['source' => 'review', 'queue' => 'Key', 'channel' => 'alt', 'action' => 'link'],
            'a match-strength list: Not a match' => ['source' => 'review', 'queue' => 'Key', 'channel' => 'alt', 'action' => 'reject', 'confirm' => '1'],
            'Store Products: Send back for matching' => ['source' => 'store', 'channel' => 'alt', 'action' => 'send_back', 'confirm' => '1'],
            'Store Products: Take off the ignored list' => ['source' => 'store', 'channel' => 'alt', 'action' => 'unignore'],
            'New Variants: Create as new items' => ['source' => 'new', 'channel' => 'alt', 'action' => 'new_item'],
            'New Variants: Confirm match' => ['source' => 'new', 'channel' => 'alt', 'action' => 'link'],
        ] as $what => $list) {
            // The pages draw no box for these two rows, so no page sends them: this form is made by hand.
            $r = $web->post('/ui/review/bulk', ['csrf' => $csrf] + $list + $this->ticked($held) + $this->ticked($spot));
            $batch = self::batch($r);
            self::assertEquals([$held => ['skipped', 'held'], $spot => ['skipped', 'in_spot_check']],
                array_map(static fn (array $b): array => [$b['outcome'], $b['skip_code']], $batch), $what);
            self::assertEquals(['reason' => 'Pack size differs', 'by' => (string) $hold['display_name'], 'at' => (string) $hold['created_at']], $batch[$held]['detail'], $what);
            self::assertEquals(['sample' => 'sc1'], $batch[$spot]['detail'], $what);
            $text = $web->follow($r)->text();
            self::assertStringContainsString(BulkController::why('held', ['reason' => 'Pack size differs']), $text, $what);
            self::assertStringContainsString(BulkController::why('in_spot_check', ['sample' => 'sc1']), $text, $what);
            self::assertSame(['suggested', 'suggested', $decisions], [$this->link($held)['status'], $this->link($spot)['status'], $this->decisions()], $what . ': nothing was decided');
        }
        self::assertSame('Held back to be checked one at a time: Pack size differs', BulkController::why('held', ['reason' => 'Pack size differs']), 'the words of the skip, as before');
        // The same form with a row a bulk action can take: that one is done, the two are skipped as before.
        $r = $web->post('/ui/review/bulk', ['csrf' => $csrf, 'source' => 'review', 'queue' => 'Key', 'channel' => 'alt', 'action' => 'link'] + $this->ticked($held) + $this->ticked($free)
            + $this->ticked($spot));
        self::assertEquals([$held => 'held', $spot => 'in_spot_check', $free => null], array_map(static fn (array $b): ?string => $b['skip_code'], self::batch($r)));
        self::assertSame(['suggested', 'suggested', 'mapped'], [$this->link($held)['status'], $this->link($spot)['status'], $this->link($free)['status']]);
        self::assertSame('waiting', (new KeySample(self::$db))->status('sc1')['verdict'], 'the spot check is untouched');
    }

    /**
     * After the last row of what a list is showing the list is not called done (review finding of 12 Oct 2026): with a choice of
     * "Show" or a search (and equally found by or units sold: QueueContext::filtered()) the list itself may hold more. The notice
     * then has words of its own; the last row of a list that is not narrowed still says "This list is done.".
     */
    public function testTheLastRowOfWhatAListShowsDoesNotSayTheListIsDone(): void
    {
        $f = $this->strongList(1);
        $web = $this->signIn($this->uiUser('mapper'));
        $decide = function (int $id, array $query) use ($web): UiResponse {
            $r = $web->post('/ui/review/listing/' . $id . '/decide', ['action' => 'link'] + $this->decideForm($web, $id, $query) + ['units_per_item' => '1']);
            self::assertSame([303, 'mapped'], [$r->status, $this->link($id)['status']], $r->describe());
            return $r;
        };
        // Show > Held back: the first of the two opens the second, the second is the last one of that choice.
        $query = ['queue' => 'Key', 'channel' => 'alt', 'show' => 'held'];
        [$first, $last] = self::listed($web->get('/ui/review', $query));
        self::assertEqualsCanonicalizing($f['held'], [$first, $last]);
        self::assertSame('/ui/review/listing/' . $last . '?queue=Key&channel=alt&show=held&notice=decided_link&prev=' . $first, $decide($first, $query)->location());
        $r = $decide($last, $query);
        self::assertSame('/ui/review?queue=Key&channel=alt&show=held&notice=shown_done', $r->location());
        $page = $web->follow($r);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString('That was the last one of what this list is showing.', $page->text());
        self::assertStringNotContainsString(Words::MATCH_NOTICE['queue_done'], $page->text(), 'the list is not done: it still has rows');
        self::assertSame(['All 8', 'Ready for a bulk action 3', 'Held back 0', 'In a spot check 5'], self::shows($page));
        self::assertStringContainsString(Words::QUEUE['empty_filter'], $page->text());
        self::assertContains('/ui/review?queue=Key&channel=alt', $page->hrefs(), 'the way to the whole list');
        // The same after the last row of a search (the notice was "This list is done." there too).
        $found = $f['ready'][2];
        $r = $decide($found, ['queue' => 'Key', 'channel' => 'alt', 'fq' => 'Ready one']);
        self::assertSame('/ui/review?queue=Key&channel=alt&q=Ready%20one&notice=shown_done', $r->location());
        $page = $web->follow($r);
        self::assertStringContainsString(Words::MATCH_NOTICE['shown_done'], $page->text());
        self::assertStringNotContainsString(Words::MATCH_NOTICE['queue_done'], $page->text());
        self::assertStringContainsString(Words::QUEUE['empty_filter'], $page->text(), 'the search finds nothing more; the list itself has rows');
        // A list that is not narrowed: its last row is the list's last, and the notice says so, as before.
        $only = $this->queued($this->altSite, 'L1', 'Check', $f['sku'], ['product_title' => 'Likely one']);
        $r = $decide($only, ['queue' => 'Check', 'channel' => 'alt']);
        self::assertSame('/ui/review?queue=Check&channel=alt&notice=queue_done', $r->location());
        $page = $web->follow($r);
        self::assertStringContainsString('This list is done.', $page->text());
        self::assertStringNotContainsString(Words::MATCH_NOTICE['shown_done'], $page->text());
        // Only a notice the page knows is ever shown, whatever the address names.
        self::assertStringContainsString(Words::MATCH_NOTICE['shown_done'], $web->get('/ui/review', ['queue' => 'Key', 'channel' => 'alt', 'notice' => 'shown_done'])->text());
        $none = $web->get('/ui/review', ['queue' => 'Key', 'channel' => 'alt', 'notice' => 'shown_done_x'])->text();
        self::assertStringNotContainsString(Words::MATCH_NOTICE['shown_done'], $none);
        self::assertStringNotContainsString(Words::MATCH_NOTICE['queue_done'], $none);
    }

    /**
     * The Likely list of a store: two judged matches, then two the check on arrival found (a clean one and one with a warning).
     * Before the check has written anything the list reads as it always did.
     *
     * @return array{judged: list<int>, clean: int, warned: int, item: array<string, mixed>}
     */
    private function likelyListWithArrivals(): array
    {
        $this->keySetup();
        foreach (['mapping.arrival_stores' => '"alt"', 'mapping.arrival_main_store' => '"vpg"', 'mapping.arrival_bulk_confirm' => 'false'] as $key => $json) {
            $this->setting($key, $json);
        }
        $this->arrivalMain = $this->vpgSite;
        $this->arrival = new ArrivalCheck(self::$db, $this->ds);
        $judged = [$this->queued($this->altSite, 'J1', 'Check', $this->item('legacy'), ['product_title' => 'Judged one', 'units_365d' => 90]),
            $this->queued($this->altSite, 'J2', 'Check', $this->item('legacy'), ['product_title' => 'Judged two', 'units_365d' => 80])];
        $q = new Queries(self::$db);
        $alt = (int) $this->altSite->channelId;
        self::assertSame([], OneAtATime::arrivalRuns(self::$db));
        self::assertSame([['all' => 2, 'ready' => 2, 'held' => 0, 'spot' => 0, 'arrival' => 0], []], [$q->queue('Check', $alt, '', null, 0, 1)['shows'], $q->queue('Check', $alt, '', null, 0, 1)['arrival_runs']]);
        $title = 'Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid';
        $item = $this->mainItem($title, '10mg', [self::gtin(1)]);
        $clean = $this->arrives($this->altSite, $title, '10mg', [self::gtin(1)]);
        $this->mainItem($title, '20mg', [self::gtin(2)]);
        $warned = $this->arrives($this->altSite, $title, '', [self::gtin(2)]); // no strength stated: a warning
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame(2, $r['per_store']['alt']['match']);
        self::assertSame([['rules_only'], ['arrival_warned', 'rules_only', 'strength_missing']], [self::arrivalOpen($clean)['flags'], self::arrivalOpen($warned)['flags']]);
        return ['judged' => $judged, 'clean' => $clean, 'warned' => $warned, 'item' => $item];
    }

    public function testARowFoundByTheCheckOnArrivalHasNoTickBoxItsOwnChoiceOfShowAndIsNeverAmongTheReadyOnes(): void
    {
        $f = $this->likelyListWithArrivals();
        $line = 'Found by the check on arrival: no AI has looked at it. Open it to check and decide.';
        $web = $this->signIn($this->uiUser('mapper'));
        $query = ['queue' => 'Check', 'channel' => 'alt'];
        $page = $web->get('/ui/review', $query);
        self::assertSame(200, $page->status, $page->describe());
        self::assertSame([...$f['judged'], $f['clean'], $f['warned']], self::listed($page), 'best sellers first, as always');

        // No tick box, and the line under the name; the rows a bulk action can take are as they always were.
        self::assertSame($f['judged'], self::boxes($page));
        $form = $page->form('/ui/review/bulk');
        foreach ([$f['clean'], $f['warned']] as $id) {
            self::assertSame([false, false, false], [isset($form['pick_' . $id]), isset($form['ver_' . $id]), isset($form['prop_' . $id])], "row {$id} is not in the form");
            self::assertSame($line, self::whyLine($page, $id));
            self::assertSame(1, substr_count(self::squash((new \DOMXPath($page->dom()))->evaluate('string(' . self::row($page, $id) . ')')), 'no AI has looked at it'),
                'the row says it once: under its name, not again among its warnings');
        }
        $warnedRow = self::squash((new \DOMXPath($page->dom()))->evaluate('string(' . self::row($page, $f['warned']) . ')'));
        self::assertStringContainsString(Words::FLAG['arrival_warned'], $warnedRow);
        self::assertStringContainsString(Words::FLAG['strength_missing'], $warnedRow);
        self::assertStringNotContainsString(Words::FLAG['arrival_warned'], self::squash((new \DOMXPath($page->dom()))->evaluate('string(' . self::row($page, $f['clean']) . ')')));
        foreach ($f['judged'] as $id) {
            self::assertNull(self::whyLine($page, $id));
        }
        self::assertSame($f['judged'], self::boxes($web->get('/ui/review', $query + ['all' => '1']), true), '"select all" leaves them');
        self::assertSame(['2 on this page were found by the check on arrival: they are decided one at a time and have no tick box. Show only the ones a bulk action can take (2)',
            '/ui/review?queue=Check&channel=alt&show=ready'], self::notice($page));

        // "Show": a fifth choice with its count; each lists exactly its rows; All is the sum; Ready holds none of them.
        $parts = ['ready' => $f['judged'], 'held' => [], 'spot' => [], 'arrival' => [$f['clean'], $f['warned']]];
        foreach (['all' => [...$f['judged'], $f['clean'], $f['warned']]] + $parts as $show => $ids) {
            $p = $web->get('/ui/review', $query + ($show === 'all' ? [] : ['show' => $show]));
            self::assertSame($ids, self::listed($p), $show);
            self::assertSame(['All 4', 'Ready for a bulk action 2', 'Held back 0', 'In a spot check 0', 'Found on arrival 2'], self::shows($p), $show);
            self::assertSame(Words::QUEUE_SHOW[$show] . ' ' . count($ids), self::shown($p), $show);
        }
        self::assertSame(4, array_sum(array_map('count', $parts)), 'All is the sum of the four');
        self::assertContains('/ui/review?queue=Check&channel=alt&show=arrival', $page->hrefs());
        $q = new Queries(self::$db);
        $alt = (int) $this->altSite->channelId;
        $runs = OneAtATime::arrivalRuns(self::$db);
        self::assertCount(2, $runs, 'the run\'s two rows: its clean matches, and the rest');
        foreach (Queries::SHOWS as $show) {
            $r = $q->queue('Check', $alt, '', null, 0, 1, $show);
            self::assertSame(['all' => 4, 'ready' => 2, 'held' => 0, 'spot' => 0, 'arrival' => 2], $r['shows'], $show);
            self::assertSame([$r['shows'][$show], $r['shows'][$show], $runs], [$r['total'], count($r['rows']), $r['arrival_runs']], $show);
        }
        // The parts are OneAtATime's own answer for each row, and the counts follow the list's filters.
        $never = OneAtATime::of(self::$db, [...$f['judged'], $f['clean'], $f['warned']]);
        self::assertSame([$f['clean'] => 'on_arrival', $f['warned'] => 'on_arrival'], array_map(static fn (array $n): string => $n['code'], $never));
        self::assertSame(['All 2', 'Ready for a bulk action 0', 'Held back 0', 'In a spot check 0', 'Found on arrival 2'], self::shows($web->get('/ui/review', $query + ['lane' => 'barcode'])));
        self::assertSame([[], []], [self::listed($web->get('/ui/review', $query + ['lane' => 'transfer', 'show' => 'arrival'])),
            self::shows($web->get('/ui/review', $query + ['lane' => 'transfer', 'show' => 'arrival']))], 'nothing found that way: an empty list has no parts to choose from');
        self::assertSame([$f['judged'][1], $f['clean'], $f['judged'][0]], [$q->nextInQueue('Check', $alt, '', null, 0, $f['judged'][0], 'ready'),
            $q->nextInQueue('Check', $alt, '', null, 0, $f['judged'][1]), $q->nextInQueue('Check', $alt, '', null, 0, $f['judged'][1], 'ready')],
            'the next ready one after the last ready one is the first again: never one of the check\'s');
        self::assertSame($f['warned'], $q->nextInQueue('Check', $alt, '', null, 0, $f['clean'], 'arrival'));
        // The other strengths' lists and the other stores are untouched; another list offers no fifth choice.
        $strong = $this->queued($this->altSite, 'K1', 'Key', $this->item('legacy'), ['product_title' => 'Strong one']);
        self::assertSame(['All 1', 'Ready for a bulk action 1', 'Held back 0', 'In a spot check 0'], self::shows($web->get('/ui/review', ['queue' => 'Key', 'channel' => 'alt'])));
        self::assertSame([$strong], self::boxes($web->get('/ui/review', ['queue' => 'Key', 'channel' => 'alt'])));

        // "Ready for a bulk action" + tick all + Confirm match: the page is the judged ones, and none is skipped as one of the kinds.
        $ready = $web->get('/ui/review', $query + ['show' => 'ready', 'all' => '1']);
        self::assertSame([$f['judged'], $f['judged']], [self::listed($ready), self::boxes($ready, true)]);
        self::assertSame(0, (new \DOMXPath($ready->dom()))->query('//span[contains(@class, "no-bulk")]')->length);
        $r = $web->post('/ui/review/bulk', $ready->form('/ui/review/bulk') + ['action' => 'link']);
        $batch = self::batch($r);
        self::assertEqualsCanonicalizing($f['judged'], array_keys($batch));
        self::assertSame(['done' => 2], array_count_values(array_column($batch, 'outcome')));
        self::assertSame([], array_values(array_filter(array_column($batch, 'skip_code'))), 'none skipped');
        self::assertSame(['suggested', 'suggested'], [$this->link($f['clean'])['status'], $this->link($f['warned'])['status']], 'the check\'s two are as they were');
        // What is left of the list is the check's two: no bar, and the notice says why.
        $left = $web->get('/ui/review', $query);
        self::assertSame([[$f['clean'], $f['warned']], []], [self::listed($left), self::boxes($left)]);
        self::assertFalse($left->hasForm('/ui/review/bulk'));
        self::assertSame('2 on this page were found by the check on arrival: they are decided one at a time and have no tick box. Nothing in this list is ready for a bulk action.',
            self::notice($left)[0]);
        // Each is decided on its own page, which says what the clue is and that no AI looked.
        $own = $web->get('/ui/review/listing/' . $f['clean'], $query + ['show' => 'arrival']);
        self::assertSame(200, $own->status, $own->describe());
        self::assertStringContainsString(Words::BAND_HELP_RULES['Check'], $own->text());
        self::assertStringNotContainsString(Words::BAND_HELP['Check'], $own->text(), 'not "the AI thinks"');
        self::assertStringContainsString('Its barcode is on this item', $own->text());
        self::assertTrue($own->hasForm('/ui/review/listing/' . $f['clean'] . '/decide'), 'and it can be decided there');
        // There, and only there (docs/decisions.md DG2): the decision's fields without what its page showed (a script, By Item's quick
        // match) are refused and nothing is saved; the page's own form is saved.
        $form = $own->form('/ui/review/listing/' . $f['clean'] . '/decide');
        $r = $web->post('/ui/review/listing/' . $f['clean'] . '/decide', ['action' => 'ignore', 'reason' => 'not ours'] + array_diff_key($form, ['seen' => true]));
        self::assertSame(409, $r->status, $r->describe());
        self::assertStringContainsString(Words::MATCH_ERROR['one_at_a_time'], $r->text());
        self::assertSame('suggested', $this->link($f['clean'])['status'], 'nothing was saved');
        self::assertSame(OneAtATime::ARRIVAL . ':' . self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$f['clean']]), $form['seen'] ?? null);
        $r = $web->post('/ui/review/listing/' . $f['clean'] . '/decide', ['action' => 'ignore', 'reason' => 'not ours'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('ignored', $this->link($f['clean'])['status']);
    }

    public function testAHandMadeFormThatTicksARowFoundOnArrivalIsSkippedOnEveryListAndTheOtherListsGiveItNoTickBox(): void
    {
        $f = $this->likelyListWithArrivals();
        $line = Words::ONE_AT_A_TIME['on_arrival'];
        $web = $this->signIn($this->uiUser('mapper'));
        $csrf = $this->token($web);
        $decisions = $this->decisions();
        foreach ([
            'a match-strength list: Confirm match' => ['source' => 'review', 'queue' => 'Check', 'channel' => 'alt', 'action' => 'link'],
            'a match-strength list: Not a match' => ['source' => 'review', 'queue' => 'Check', 'channel' => 'alt', 'action' => 'reject', 'confirm' => '1'],
            'a match-strength list: Ignore' => ['source' => 'review', 'queue' => 'Check', 'channel' => 'alt', 'action' => 'ignore', 'confirm' => '1', 'reason' => 'x'],
            'Store Products: Send back for matching' => ['source' => 'store', 'channel' => 'alt', 'action' => 'send_back', 'confirm' => '1'],
            'New Variants: Create as new items' => ['source' => 'new', 'channel' => 'alt', 'action' => 'new_item'],
            'New Variants: Confirm match' => ['source' => 'new', 'channel' => 'alt', 'action' => 'link'],
        ] as $what => $list) {
            $r = $web->post('/ui/review/bulk', ['csrf' => $csrf] + $list + $this->ticked($f['clean']) + $this->ticked($f['warned']));
            $batch = self::batch($r);
            self::assertEquals([$f['clean'] => ['skipped', 'on_arrival'], $f['warned'] => ['skipped', 'on_arrival']],
                array_map(static fn (array $b): array => [$b['outcome'], $b['skip_code']], $batch), $what);
            self::assertStringContainsString(BulkController::why('on_arrival', []), $web->follow($r)->text(), $what);
            self::assertSame(['suggested', 'suggested', $decisions], [$this->link($f['clean'])['status'], $this->link($f['warned'])['status'], $this->decisions()], $what . ': nothing was decided');
        }
        self::assertSame('Its suggestion was found by the check on arrival, and no AI has looked at it: open it to check and decide.', BulkController::why('on_arrival', []));

        // Store Products and New Variants: no tick box and the same line; the judged rows keep theirs.
        foreach (['Store Products' => ['/ui/review/store', ['channel' => 'alt']], 'New Variants' => ['/ui/review/new-variants', ['channel' => 'alt', 'state' => 'all', 'period' => 'all']]] as $screen => [$path, $query]) {
            $page = $web->get($path, $query);
            self::assertSame(200, $page->status, $screen . ': ' . $page->describe());
            self::assertEqualsCanonicalizing([...$f['judged'], $f['clean'], $f['warned']], self::listed($page), $screen);
            self::assertEqualsCanonicalizing($f['judged'], self::boxes($page), $screen);
            self::assertSame([$line, $line], [self::whyLine($page, $f['clean']), self::whyLine($page, $f['warned'])], $screen);
            self::assertEqualsCanonicalizing($f['judged'], self::boxes($web->get($path, $query + ['all' => '1']), true), $screen);
        }
        self::assertDoesNotMatchRegularExpression('/\bproducts?\b/i', $line);
        // By Item ticks items, not variants: its preview lists such a variant as skipped, with the words in items and variants.
        self::assertSame('Its suggestion was found by the check on arrival, and no AI has looked at it: open it to check and decide.', BulkController::why('on_arrival', [], 'BY_ITEM_SKIP'));
    }

    public function testWithTheSettingOnACleanMatchOfTheCheckIsAnOrdinaryRowAndOneWithAWarningNeverIs(): void
    {
        $f = $this->likelyListWithArrivals();
        $this->setting('mapping.arrival_bulk_confirm', 'true');
        $web = $this->signIn($this->uiUser('mapper'));
        $query = ['queue' => 'Check', 'channel' => 'alt'];
        $page = $web->get('/ui/review', $query);
        // The clean one has its tick box now, and still says what found it (among its warnings, as a flag of the row).
        self::assertSame([...$f['judged'], $f['clean']], self::boxes($page));
        self::assertNull(self::whyLine($page, $f['clean']));
        self::assertStringContainsString(Words::FLAG['rules_only'], self::squash((new \DOMXPath($page->dom()))->evaluate('string(' . self::row($page, $f['clean']) . ')')));
        self::assertSame(Words::ONE_AT_A_TIME['on_arrival'], self::whyLine($page, $f['warned']));
        self::assertSame(['All 4', 'Ready for a bulk action 3', 'Held back 0', 'In a spot check 0', 'Found on arrival 1'], self::shows($page));
        self::assertSame('1 on this page was found by the check on arrival: it is decided on its own and has no tick box. Show only the ones a bulk action can take (3)', self::notice($page)[0]);
        self::assertSame([$f['warned']], self::listed($web->get('/ui/review', $query + ['show' => 'arrival'])));
        self::assertSame([$f['warned'] => 'on_arrival'], array_map(static fn (array $n): string => $n['code'], OneAtATime::of(self::$db, [...$f['judged'], $f['clean'], $f['warned']])));
        self::assertCount(1, OneAtATime::arrivalRuns(self::$db), 'only the run row of the ones with a warning');

        // Ready + tick all + Confirm match: the clean one is matched to the item its barcode names; the warned one is not on the page.
        $ready = $web->get('/ui/review', $query + ['show' => 'ready', 'all' => '1']);
        self::assertSame([...$f['judged'], $f['clean']], self::boxes($ready, true));
        $batch = self::batch($web->post('/ui/review/bulk', $ready->form('/ui/review/bulk') + ['action' => 'link']));
        self::assertSame(['done' => 3], array_count_values(array_column($batch, 'outcome')));
        self::assertSame(['mapped', $f['item']['sku']], [$this->link($f['clean'])['status'], $this->link($f['clean'])['sku_id']]);
        self::assertSame('suggested', $this->link($f['warned'])['status']);
        // A hand-made form for the warned one is skipped, setting or no setting.
        $r = $web->post('/ui/review/bulk', ['csrf' => $this->token($web), 'source' => 'review', 'queue' => 'Check', 'channel' => 'alt', 'action' => 'link'] + $this->ticked($f['warned']));
        self::assertEquals([$f['warned'] => 'on_arrival'], array_map(static fn (array $b): ?string => $b['skip_code'], self::batch($r)));
        self::assertSame('suggested', $this->link($f['warned'])['status']);
        // Switched off again: the list reads as with the setting off.
        $this->setting('mapping.arrival_bulk_confirm', 'false');
        self::assertSame(['All 1', 'Ready for a bulk action 0', 'Held back 0', 'In a spot check 0', 'Found on arrival 1'], self::shows($web->get('/ui/review', $query)));
    }

    public function testStoreProductsAndNewVariantsGiveSuchARowNoTickBoxAndTheSameLine(): void
    {
        $f = $this->strongList(1);
        // One of the spot check's matches was answered by its owner: matched, and the spot check still waits for the other four.
        $answered = $f['spot'][4];
        $this->confirm($f['lead'], $f['proposal'][$answered]);
        self::assertSame(['mapped', 'waiting'], [$this->link($answered)['status'], (new KeySample(self::$db))->status('sc1')['verdict']]);
        $spot = array_slice($f['spot'], 0, 4);
        $hold = self::$db->one('SELECT h.created_at, u.display_name FROM key_bulk_hold h JOIN staff_user u ON u.id = h.staff_user_id WHERE h.listing_id = ?', [$f['held'][0]]) ?? [];
        $heldLine = Words::say('ONE_AT_A_TIME', 'held', Html::day((string) $hold['created_at']), (string) $hold['display_name']);
        $spotLine = Words::say('ONE_AT_A_TIME', 'in_spot_check', 'sc1') . ' ' . Words::ONE_AT_A_TIME['open_spot'];
        $web = $this->signIn($this->uiUser('mapper'));

        foreach (['Store Products' => ['/ui/review/store', ['channel' => 'alt']], 'New Variants' => ['/ui/review/new-variants', ['channel' => 'alt', 'state' => 'all']]] as $screen => [$path, $query]) {
            $page = $web->get($path, $query);
            self::assertSame(200, $page->status, $screen . ': ' . $page->describe());
            self::assertEqualsCanonicalizing($f['all'], self::listed($page), $screen);
            // A box on the rows a bulk action can take; none on the held ones, the spot check's, or the matched one (as before).
            self::assertEqualsCanonicalizing($f['ready'], self::boxes($page), $screen);
            $form = $page->form('/ui/review/bulk');
            foreach ([...$f['held'], ...$spot] as $id) {
                self::assertSame([false, false], [isset($form['pick_' . $id]), isset($form['ver_' . $id])], "{$screen}: row {$id} is not in the form");
            }
            self::assertSame([$heldLine, $heldLine], [self::whyLine($page, $f['held'][0]), self::whyLine($page, $f['held'][1])], $screen);
            self::assertStringNotContainsString('Pack size differs', $page->text(), $screen . ': a hold\'s note is not printed on the list');
            foreach ($spot as $id) {
                self::assertSame($spotLine, self::whyLine($page, $id), $screen);
                self::assertSame('/ui/review/samples/' . $f['sample'], (new \DOMXPath($page->dom()))->evaluate('string(' . self::row($page, $id) . '/th/span[contains(@class, "no-bulk")]/a/@href)'), $screen);
            }
            foreach ([...$f['ready'], $answered] as $id) {
                self::assertNull(self::whyLine($page, $id), "{$screen}: row {$id} says nothing under its name");
            }
            // "Select all on this page" (`all=1`) ticks the rows that have a box.
            self::assertEqualsCanonicalizing($f['ready'], self::boxes($web->get($path, $query + ['all' => '1']), true), $screen);
            // No filter and no notice here: the row treatment only.
            self::assertSame([[], ['', '']], [self::shows($page), self::notice($page)], $screen);
        }
        // New Variants speaks of variants and items: the line names neither, and no "product".
        foreach ([$heldLine, $spotLine] as $line) {
            self::assertDoesNotMatchRegularExpression('/\bproducts?\b/i', $line);
        }
        // Someone who can only look: no boxes, as before; the rows still say what they are.
        $look = $this->signIn($this->uiUser('viewer'))->get('/ui/review/store', ['channel' => 'alt']);
        self::assertSame([[], $heldLine], [self::boxes($look), self::whyLine($look, $f['held'][0])]);
    }
}
