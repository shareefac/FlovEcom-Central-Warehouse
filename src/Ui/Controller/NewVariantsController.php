<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\Sites;
use CW\Clock;
use CW\Mapping\BulkDecisions;
use CW\Output\CsvWriter;
use CW\Settings;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Queries;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Products › New Variants (docs/decisions.md M60-M62, U131-U138; the owner, 10 Oct 2026: "new item add in any store how to here show
 * also seed option any new each site with"). In the owner's words a store's sellable option is a VARIANT (a channel_listing row) and
 * the warehouse record it is matched to is an ITEM (a sku row).
 *
 * The variants of ONE store (the store selector of the matching pages; the stores are the channel table's rows, none is named here)
 * first seen in CW within a period (channel_listing.created_at; the period the page opens with is the setting
 * `mapping.new_variants_days`), newest first, 50 a page: each with its option number, barcodes, price, units sold, when it was first
 * seen and what it is, in words: New (when the computer checked it, it found nothing like it), Suggested match, Not checked yet,
 * Matched, Waiting for a second OK, Ignored; and what a person should know before ticking it: that an item was already made from
 * it, that it changed on the website after the check, that it is a page heading or not published on the website. A filter (All
 * not matched by default, New, Suggested match, Not checked yet, All), Store Products' search and a CSV.
 *
 * The "seed" on every store: a person who may decide ticks rows and presses "Create as new items" in the existing action bar. That
 * posts to the existing bulk route (BulkController, source `new`), and each row is BulkDecisions' existing `new_item`, one
 * DecisionService decision with all its rules, in a batch with a result page. It takes ONLY rows marked New, and of those not
 * the doubtful ones; every other ticked row is skipped with its reason (BulkDecisions::seedCheck) and the page says so beside the
 * bar. A store where no computer check has ever marked a variant New is told so in the box at the top (Queries::everFoundNew):
 * there the seed can only skip, and each variant's item is created on its own page. Confirm match and Ignore…
 * are BulkDecisions' existing actions too. "Open" leads to the variant's existing page (match by hand, or create one item with its
 * details checked: also one the computer has not checked). This class only reads; it has no POST and no way to decide of its own.
 */
final class NewVariantsController
{
    public const PATH = '/ui/review/new-variants';
    /** The most variants a CSV holds (every variant of the filter: the page shows 50 of them). */
    public const CSV_MAX = 50_000;
    /** The filter's query values: All not matched (the default, no value), New, Suggested match, Not checked yet, All. */
    public const STATES = [...Queries::NEW_FILTERS, 'all'];
    public const DEFAULT_STATE = 'unmatched';
    /** The kinds a row can be ticked for: the ones still to match (a matched, on-hold, waiting or ignored variant changes on its own page). */
    private const OPEN_KINDS = ['new', 'suggested', 'not_checked'];
    /** The setting of the period the page opens with, and its default on a schema before 0026. */
    public const SETTING = 'mapping.new_variants_days';
    public const DEFAULT_DAYS = 30;
    /** At most this many barcodes of a variant on the page (the CSV has them all). */
    private const BARCODES_SHOWN = 3;

    public function index(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $q = $ctx->queries();
        $me = $ctx->me();
        $layout = ['title' => Words::MENU['new_variants'], 'active' => 'new_variants', 'notice' => null];
        $lookOnly = $me->canDecide() ? null : Words::whoCan('mapping.decide');
        $stores = $q->stores();
        if ($stores === []) {
            return $ctx->page('new_variants', ['store' => null, 'stores' => [], 'lookOnly' => $lookOnly], 200, $layout);
        }
        $f = self::filters($ctx, $stores);
        if ($f === null) {
            return $ctx->error(404, 'unknown_variants_store', 'no such store', [self::PATH, Words::MENU['new_variants']]);
        }
        $store = $f['store'];
        $range = $q->seenRange((int) $store['id']);
        $counts = $q->newVariantCounts((int) $store['id'], $f['since'], $range);
        $result = $f['nothing'] ? ['ids' => [], 'total' => 0, 'page' => 1, 'pages' => 1]
            : $q->newVariants((int) $store['id'], $f['since'], $f['filter'], $f['text'], $f['page'], $counts);
        $base = $f['query'];
        $bulkActions = ReviewController::bulkActions('new', null, $me->roles, BulkDecisions::confirmBands($ctx->db), 'NEW_VARIANT_ACTION');
        if (in_array($f['state'], ['new', 'not_checked'], true)) {
            // Nothing on these two lists has a suggested match to confirm. "Create as new items" stays on every list: the line
            // beside the bar says which rows it takes.
            $bulkActions = array_values(array_filter($bulkActions, static fn (array $a): bool => $a['action'] !== 'link'));
        }
        // Asked only when the period shows no row marked New: one that is there answers it.
        $everNew = $counts['new'] > 0 || $q->everFoundNew((int) $store['id']);
        $all = $req->param('all') === '1';
        $rows = [];
        foreach ($q->newVariantRows($result['ids']) as $r) {
            $row = self::row($r);
            [$row['barcodes_shown'], $row['barcodes_more']] = [array_slice($row['barcodes'], 0, self::BARCODES_SHOWN), max(0, count($row['barcodes']) - self::BARCODES_SHOWN)];
            // Only the rows an action of the bar applies to can be ticked: matched, on-hold, waiting and ignored ones change on their own page.
            $row['pickable'] = $bulkActions !== [] && in_array($row['state'], self::OPEN_KINDS, true);
            $row['picked'] = $row['pickable'] && $all;
            $rows[] = $row;
        }
        $states = [];
        foreach (self::STATES as $s) {
            $states[] = ['key' => $s, 'label' => Words::NEW_VARIANT_FILTER[$s], 'count' => Queries::newVariantTotal($counts, $s === 'all' ? null : $s),
                'href' => Html::url(self::PATH, array_replace($base, ['state' => $s === self::DEFAULT_STATE ? null : $s])), 'current' => $f['state'] === $s,
                'tone' => in_array($s, self::OPEN_KINDS, true) ? Words::tone('NEW_VARIANT_STATE', $s) : null];
        }
        $periods = [];
        foreach ([...self::periods($f['default']), null] as $days) {
            $periods[] = ['label' => self::periodLabel($days), 'current' => $f['days'] === $days,
                'href' => Html::url(self::PATH, array_replace($base, ['period' => self::periodValue($days, $f['default'])]))];
        }
        return $ctx->page('new_variants', [
            'store' => $store,
            'stores' => ReviewController::storeItems($stores, self::PATH, array_filter(['period' => $base['period'], 'state' => $base['state']],
                static fn (?string $v): bool => $v !== null), (string) $store['code'],
                $q->seenSinceByStore($f['since'], array_map(static fn (array $s): int => (int) $s['id'], $stores)), false),
            'storeLine' => Words::say('NEW_VARIANTS', self::sending($q->storeLink((int) $store['id'])), (string) $store['name']),
            'checkLine' => $everNew ? null : Words::say('NEW_VARIANTS', 'never_new', (string) $store['name']),
            'newest' => $range['last'],
            'periods' => $periods,
            'states' => $states,
            // The filter and the period as the address writes them (null: the default, written nowhere).
            'state' => $base['state'],
            'period' => $base['period'],
            'text' => $f['text'],
            'rows' => $rows,
            'total' => $result['total'],
            'page_no' => $result['page'],
            'pages' => $result['pages'],
            // Page 1 is the list's own address (no page value).
            'prev_link' => $result['page'] > 1 ? Html::url(self::PATH, $base + ['page' => $result['page'] > 2 ? $result['page'] - 1 : null]) : null,
            'next_link' => $result['page'] < $result['pages'] ? Html::url(self::PATH, $base + ['page' => $result['page'] + 1]) : null,
            'bulkActions' => $bulkActions,
            'bulkMax' => BulkDecisions::maxRows($ctx->db),
            'bulkKeep' => array_filter($base + ['page' => $result['page'] > 1 ? $result['page'] : null], static fn (mixed $v): bool => $v !== null && $v !== ''),
            'allLink' => Html::url(self::PATH, $base + ['page' => $result['page'] > 1 ? $result['page'] : null, 'all' => 1]),
            'clear_link' => Html::url(self::PATH, array_replace($base, ['q' => null])),
            'csv_link' => Html::url(self::PATH . '.csv', $base),
            'lookOnly' => $lookOnly,
        ], 200, $layout);
    }

    /**
     * The list as a CSV: every variant of the store, period, state and search (not only the page's 50), newest first, each with its
     * state in the page's words and the item it is matched to or the suggestion. Read 500 rows at a time.
     */
    public function csv(Context $ctx): HtmlResponse
    {
        $q = $ctx->queries();
        $stores = $q->stores();
        $f = $stores === [] ? null : self::filters($ctx, $stores);
        if ($f === null) {
            return $ctx->error(404, 'unknown_variants_store', 'no such store', [self::PATH, Words::MENU['new_variants']]);
        }
        $store = $f['store'];
        $text = self::csvText($q, $store, $f['since'], $f['filter'], $f['text'], $f['nothing']);
        return FilesController::download($text, 'text/csv; charset=utf-8', 'new-variants-' . $store['code'] . '-' . gmdate('Ymd') . '.csv');
    }

    /**
     * The CSV's text: at most $max variants, and a last line "Only the first … variants are in this file." only when the list holds
     * more. Whether it does is decided from the read of the ids alone: one more than $max is asked for, and the line is written
     * when that one came back. (A count read a moment earlier can differ from the ids read after it, while someone decides rows:
     * the line was then written under a file that was not cut.) $counts is the kinds' counts read before the ids (read here when
     * not given): the id read only uses them to skip itself when they say the list is empty, and the line never depends on them,
     * which a test shows by handing in counts that are wrong either way.
     *
     * @param array<string, mixed> $store the store's row (id, name)
     * @param array<string, int>|null $counts Queries::newVariantCounts for the store and the period
     */
    public static function csvText(Queries $q, array $store, ?string $since, ?string $filter, string $text, bool $nothing, int $max = self::CSV_MAX,
        ?array $counts = null): string
    {
        $ids = [];
        if (!$nothing) {
            $counts ??= $q->newVariantCounts((int) $store['id'], $since);
            $ids = $q->newVariants((int) $store['id'], $since, $filter, $text, null, $counts, $max + 1)['ids'];
        }
        $more = count($ids) > $max;
        $csv = new CsvWriter([['store', 'text'], ['option_number', 'text'], ['product', 'text'], ['variant', 'text'], ['brand', 'text'], ['barcodes', 'text'],
            ['price', 'number'], ['sold_30_days', 'number'], ['sold_1_year', 'number'], ['first_seen_utc', 'text'], ['state', 'text'], ['cw_number', 'text'],
            ['details', 'text']]);
        foreach (array_chunk(array_slice($ids, 0, $max), 500) as $chunk) {
            foreach ($q->newVariantRows($chunk) as $r) {
                $row = self::row($r);
                $csv->add([(string) $store['name'], $row['variant'], $row['title'], $row['variant_title'], $row['brand'], implode(' ', $row['barcodes']),
                    is_numeric($r['price']) ? (string) $r['price'] : null, $r['units_30d'] === null ? null : (int) $r['units_30d'],
                    $r['units_365d'] === null ? null : (int) $r['units_365d'], substr((string) $r['created_at'], 0, 19), Words::NEW_VARIANT_STATE[$row['state']],
                    $row['code'], trim(implode(' ', [$row['detail'], ...$row['notes']]))]);
            }
        }
        if ($more) {
            $csv->add([null, null, Words::say('NEW_VARIANTS', 'csv_more', $max), null, null, null, null, null, null, null, null, null, null]);
        }
        return $csv->output();
    }

    /**
     * The page's choices from the request: the store (the first by name when none is asked for; null when the code names none of
     * them), the period (the setting's by default; `all` for any time), the filter (All not matched by default), the search and the page.
     * A value that is not one of the page's own (a list, an unknown period) is the default; a search that is not UTF-8 text finds
     * nothing (`nothing`), its text shown cleaned.
     *
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return array{store: array<string, mixed>, default: int, days: ?int, since: ?string, state: string, filter: ?string, text: string, nothing: bool, page: int, query: array<string, ?string>}|null
     */
    private static function filters(Context $ctx, array $stores): ?array
    {
        $req = $ctx->req;
        $code = $req->param('channel');
        $store = $code === null || $code === '' ? $stores[0] : ReviewController::store($stores, $code);
        if ($store === null) {
            return null;
        }
        $default = self::defaultDays($ctx);
        $asked = $req->param('period');
        $days = $default;
        if ($asked === 'all') {
            $days = null;
        } elseif ($asked !== null && preg_match('/^[1-9][0-9]{0,2}$/D', $asked) === 1 && in_array((int) $asked, self::periods($default), true)) {
            $days = (int) $asked;
        }
        $state = in_array($req->param('state'), self::STATES, true) ? (string) $req->param('state') : self::DEFAULT_STATE;
        $raw = $req->param('q') ?? '';
        $nothing = !mb_check_encoding($raw, 'UTF-8');
        // White space of any kind around the text is not part of it (a pasted no-break or full-width space alone is no search).
        $text = mb_substr((string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $nothing ? mb_scrub($raw, 'UTF-8') : $raw), 0, 100);
        return [
            'store' => $store,
            'default' => $default,
            'days' => $days,
            'since' => $days === null ? null : Clock::db(Clock::now()->modify('-' . $days . ' days')),
            'state' => $state,
            'filter' => $state === 'all' ? null : $state,
            'text' => $text,
            'nothing' => $nothing,
            'page' => UiRequest::id($req->param('page')) ?? 1,
            'query' => ['channel' => (string) $store['code'], 'period' => self::periodValue($days, $default), 'state' => $state === self::DEFAULT_STATE ? null : $state,
                'q' => $text === '' ? null : $text],
        ];
    }

    /** The period the page opens with (the setting, read now; 1 to 365 days). */
    public static function defaultDays(Context $ctx): int
    {
        return min(365, max(1, Settings::number($ctx->db, self::SETTING, self::DEFAULT_DAYS)));
    }

    /** The periods offered, in days: 7, 30, 90 and the setting's own when it is another. @return list<int> */
    public static function periods(int $default): array
    {
        $out = array_values(array_unique([...Queries::NEW_PERIODS, $default]));
        sort($out);
        return $out;
    }

    /** A period's query value: none for the default (so a link follows the setting), `all` for any time, else its days. */
    public static function periodValue(?int $days, int $default): ?string
    {
        return match (true) {
            $days === null => 'all',
            $days === $default => null,
            default => (string) $days,
        };
    }

    public static function periodLabel(?int $days): string
    {
        return match (true) {
            $days === null => Words::NEW_VARIANTS['period_all'],
            $days === 1 => Words::NEW_VARIANTS['period_day'],
            default => Words::say('NEW_VARIANTS', 'period_days', $days),
        };
    }

    /**
     * Which sentence says whether the store sends its new variants (Words::NEW_VARIANTS `store_off`, `store_on`, `store_quiet`), from
     * what CW records and nothing else: the store's mode in CW and its connector's latest heartbeat (Queries::storeLink). "Sends"
     * only when the store is switched on in CW AND was heard from within the time the Stores page calls recent AND the website
     * itself did not report that it is off; switched on but silent, or reporting off, is `store_quiet` (it reads as "sends" otherwise
     * while nothing can arrive: an empty allowlist, no key, a connector that is not installed).
     *
     * @param array{mode: string, site_mode: ?string, age: ?int} $link
     */
    public static function sending(array $link): string
    {
        if ($link['mode'] === 'off') {
            return 'store_off';
        }
        return $link['age'] !== null && $link['age'] <= Sites::STALE_AFTER_SEC && $link['site_mode'] !== 'off' ? 'store_on' : 'store_quiet';
    }

    /**
     * One variant as the page and the CSV show it: its names, option number, barcodes, price, units, first seen, what it is (`state`,
     * one of Queries::NEW_KINDS), the CW number of the item it is matched to (or the suggested one), the details in words, and
     * `notes`: more lines under them for a row still to match. What a row says comes from the conditions the seed skips on
     * (BulkDecisions::seedCheck asks the same functions), so a row and its skip cannot disagree:
     *  - an item was already made from it (`made_code`): said in place of "found nothing like it" or "not checked yet" (under a
     *    suggested match: as a note);
     *  - a row marked New that changed on the website after the computer checked it (BulkDecisions::changedSinceCheck: its
     *    identity is not the suggestion's recorded one, or its title is not the one the computer judged): said in place of "found
     *    nothing like it";
     *  - a suggested match that names no item (BulkDecisions::matchNoItem: the matched main-store variant has no item yet): said
     *    with its strength, in place of the strength alone;
     *  - what the website said of it (a page heading; not published, from its stored features or else from what the check
     *    recorded: BulkDecisions::siteState): a note; none when it is on sale or not known.
     *
     * @param array<string, mixed> $r a row of Queries::newVariantRows
     * @return array<string, mixed>
     */
    private static function row(array $r): array
    {
        $st = (string) $r['kind'];
        $band = $r['proposal_id'] !== null ? (string) $r['band'] : null;
        $code = null;
        $name = null;
        $open = in_array($st, self::OPEN_KINDS, true);
        $made = $open ? self::s($r['made_code'] ?? null) : null;
        $madeBefore = $made === null ? null : Words::say('NEW_VARIANTS', 'made_before', $made);
        $detail = match ($st) {
            'new' => $madeBefore ?? (BulkDecisions::changedSinceCheck($r['basis_id'] ?? null, $r['basis_identity'] ?? null, $r['identity_hash'] ?? null,
                $r['check_title'] ?? null, is_string($r['product_title'] ?? null) ? $r['product_title'] : null, is_string($r['variant_title'] ?? null) ? $r['variant_title'] : null)
                ? Words::NEW_VARIANTS['changed'] : Words::NEW_VARIANTS['is_new']),
            'not_checked' => $madeBefore ?? Words::NEW_VARIANTS['not_checked'],
            'waiting' => Words::NEW_VARIANT_PENDING[(string) $r['pending_action']] ?? Words::NEW_VARIANT_PENDING['other'],
            default => '',
        };
        if ($st === 'linked' || $st === 'quarantined') {
            $code = self::s($r['sku_code']);
            $name = self::s($r['sku_name']);
            $detail = Words::say('NEW_VARIANTS', 'matched_to', (string) $code) . ($st === 'quarantined' ? ' · ' . Words::NEW_VARIANTS['on_hold'] : '');
        } elseif ($st === 'suggested' && $band !== null) {
            if ($r['proposed_code'] !== null) {
                $code = self::s($r['proposed_code']);
                $name = self::s($r['proposed_name']);
                $detail = Words::say('NEW_VARIANTS', 'suggested_item', Words::of('BAND', $band), (string) $code);
            } elseif (BulkDecisions::matchNoItem($band, $r['proposed_sku_id'])) {
                // A match to a variant of the main store that has no item yet: said, with its strength (the seed skips it for this).
                $detail = Words::say('NEW_VARIANTS', 'match_no_item', Words::of('BAND', $band));
            } else {
                // A suggestion the computer could not settle (not sure, clues disagree): its strength in words.
                $detail = Words::of('BAND', $band);
            }
        }
        $notes = [];
        if ($open) {
            if ($st === 'suggested' && $madeBefore !== null) {
                $notes[] = $madeBefore;
            }
            $site = BulkDecisions::siteState($r['site_status'] ?? null, $r['check_status'] ?? null);
            if (BulkDecisions::isPlaceholder($r['is_placeholder'] ?? null)) {
                $notes[] = Words::NEW_VARIANTS['site_heading'];
            } elseif ($site !== null && $site !== BulkDecisions::PUBLISHED) {
                $notes[] = Words::say('NEW_VARIANTS', 'site_status', $site);
            }
        }
        return [
            'listing_id' => (int) $r['listing_id'],
            'title' => self::s($r['product_title']),
            'variant_title' => self::s($r['variant_title']),
            'brand' => self::s($r['brand']),
            'variant' => self::s($r['external_variant_id']),
            'barcodes' => array_values(array_unique(Html::strings(Html::json($r['barcodes'])))),
            'price' => $r['price'],
            'units_30d' => $r['units_30d'],
            'units_365d' => $r['units_365d'],
            'seen' => (string) $r['created_at'],
            'state' => $st,
            'band' => $st === 'suggested' ? $band : null,
            'code' => $code,
            'name' => $name,
            'detail' => $detail,
            'notes' => $notes,
            'link' => '/ui/review/listing/' . (int) $r['listing_id'],
            'map_version' => (int) $r['map_version'],
            'proposal_id' => $r['proposal_id'] === null ? null : (int) $r['proposal_id'],
        ];
    }

    private static function s(mixed $v): ?string
    {
        return $v === null || $v === '' || !is_scalar($v) ? null : mb_substr((string) $v, 0, 500);
    }
}
