<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Clock;
use CW\Output\CsvWriter;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\ReservationViews;
use CW\Ui\StockViews;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Stock › Reservations (docs/decisions.md RS1-RS12): the stock the engine (CW\Reservations) keeps for each order of a store, READ
 * ONLY, for everyone who sees a product's stock on its page (catalogue.view, as Stock › Overview and Movements): the engine and the
 * stores are the only ones who change a reservation, so this controller has GET routes only and no form.
 *
 *   /ui/stock/reservations        the list, newest first, as a board grouped by state (v4: Search, Filter, Export), for every store or
 *                                 one (the stores are the channel table's rows), with three figures over it
 *   /ui/stock/reservations.csv    the same filtered list as a file
 *   /ui/stock/reservations/{id}   one reservation: its facts, its products and what happened to their stock (the stock ledger's
 *                                 rows of the order: the only history the engine keeps)
 */
final class ReservationsController
{
    public const PATH = '/ui/stock/reservations';

    public function index(Context $ctx): HtmlResponse
    {
        $views = new ReservationViews($ctx->db);
        $stores = $views->stores();
        $f = self::filters($ctx->req, $stores);
        $now = Clock::db(Clock::now());
        $page = $views->page($f, $stores);
        $names = array_column($stores, 'name', 'id');
        $byGroup = [];
        foreach ($page['rows'] as $r) {
            $byGroup[(string) $r['group']][] = self::row($r, $names, $now);
        }
        $groups = [];
        foreach (ReservationViews::GROUPS as $g) {
            if (isset($byGroup[$g])) {
                $groups[$g] = ['name' => Words::RESV_GROUP[$g], 'tone' => Words::tone('RESV_GROUP', $g), 'rows' => $byGroup[$g]];
            }
        }
        $held = $views->heldByStore();
        $totals = $views->totals($f['channel']);
        $code = $f['store'] === null ? null : (string) $f['store']['code'];
        $keep = ['state' => $f['state'], 'q' => $f['q'] === '' ? null : $f['q']];
        $query = ['channel' => $code] + $keep;
        return $ctx->page('stock_reservations', [
            'f' => ['channel' => $code, 'state' => $f['state'], 'q' => $f['q']],
            'storeItems' => ReviewController::storeItems($stores, self::PATH, $keep, $code, $held, true),
            'states' => Words::RESV_STATE,
            'totals' => ['holds' => $f['channel'] === null ? array_sum($held) : (int) ($held[$f['channel']] ?? 0)] + $totals,
            'groups' => $groups,
            'rows_n' => count($page['rows']),
            'page_size' => ReservationViews::PAGE,
            'filtered' => $f['state'] !== null || $f['q'] !== '',
            'narrowed' => $f['state'] !== null || $f['q'] !== '' || $f['before'] !== null,
            'productSearch' => $page['by'] === 'product',
            'capped' => $page['capped'] ? ReservationViews::FOUND_LIMIT : null,
            'clear' => Html::url(self::PATH, ['channel' => $code]),
            'csv' => Html::url(self::PATH . '.csv', $query),
            'older_link' => $page['next'] === null ? null : Html::url(self::PATH, $query + ['before' => $page['next']]),
            'newest_link' => $f['before'] === null ? null : Html::url(self::PATH, $query),
        ], 200, ['title' => Words::MENU['reservations']]);
    }

    /** The filtered list as CSV (the same store, state and search; newest first; formula-safe, CsvWriter). Times are UTC, as in every export. */
    public function csv(Context $ctx): HtmlResponse
    {
        $views = new ReservationViews($ctx->db);
        $stores = $views->stores();
        $names = array_column($stores, 'name', 'id');
        $csv = new CsvWriter([['store', 'text'], ['order_reference', 'text'], ['state', 'text'], ['what_became_of_it', 'text'], ['products', 'number'],
            ['units', 'number'], ['items_not_linked', 'number'], ['started_utc', 'text'], ['kept_until_utc', 'text'], ['paid_utc', 'text'], ['ended_utc', 'text'],
            ['tries', 'number']]);
        $out = $views->export(self::filters($ctx->req, $stores), $stores);
        $utc = static fn (?string $t): ?string => $t === null ? null : substr($t, 0, 19);
        foreach ($out['rows'] as $r) {
            $csv->add([(string) ($names[$r['channel_id']] ?? ''), $r['ref'], Words::RESV_STATE[$r['status']], self::outcome($r)[0], $r['products'], $r['units'],
                $r['unlinked'], $utc($r['created_at']), $r['status'] === 'committed' ? null : $utc($r['expires_at']), $utc($r['committed_at']), $utc($r['released_at']),
                $r['attempt']]);
        }
        if ($out['more']) {
            $csv->add([null, null, Words::say('RESV', 'csv_more', ReservationViews::CSV_LIMIT), null, null, null, null, null, null, null, null, null]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'reservations.csv');
    }

    public function show(Context $ctx): HtmlResponse
    {
        $views = new ReservationViews($ctx->db);
        $r = $views->one($ctx->id());
        if ($r === null) {
            return $ctx->error(404, 'not_found', 'there is no such reservation', [self::PATH, Words::MENU['reservations']]);
        }
        $store = null;
        foreach ($views->stores() as $s) {
            if ($s['id'] === $r['channel_id']) {
                $store = $s;
            }
        }
        $status = (string) $r['status'];
        $group = (string) $r['group'];
        $now = Clock::db(Clock::now());
        [$outcome] = self::outcome($r);
        $facts = [
            [Words::RESV['f_store'], (string) ($store['name'] ?? '')],
            [Words::RESV['f_ref'], (string) $r['ref']],
            [Words::RESV['f_state'], Words::RESV_STATE[$status] . ($outcome === Words::RESV_STATE[$status] ? '' : ' · ' . $outcome)],
            [Words::RESV['f_started'], Html::when($r['created_at'])],
        ];
        if ($status === 'held') {
            $facts[] = [Words::RESV['f_expires'], Html::when($r['expires_at']) . ($r['expires_at'] !== null && $r['expires_at'] <= $now ? ' (' . Words::RESV['overdue'] . ')' : '')];
        } elseif ($status === 'committed') {
            $facts[] = [Words::RESV['f_paid'], Html::when($r['committed_at'])];
            $facts[] = [Words::RESV['f_origin'], Words::of('RESV_ORIGIN', (string) $r['origin'])];
        } else {
            if ($r['expires_at'] !== null) {
                $facts[] = [Words::RESV['f_was_due'], Html::when($r['expires_at'])];
            }
            $facts[] = [Words::RESV[$status === 'expired' ? 'f_ran_out' : 'f_freed'], Html::when($r['released_at'])];
        }
        if ($r['attempt'] > 1) {
            $facts[] = [Words::RESV['f_tries'], Words::say('RESV', 'f_tries_text', (int) $r['attempt'])];
        }
        $facts[] = [Words::RESV['f_products'], number_format((int) $r['products'])];
        $facts[] = [Words::RESV['f_units'], number_format((int) $r['units']) . ($r['unlinked'] === 0 ? '' : ' (' . self::unlinked((int) $r['unlinked']) . ')')];

        $lines = [];
        foreach ($views->lines((int) $r['id']) as $l) {
            // A line without a warehouse product is named as the store names it (its title, else its number there). Under the name:
            // the CW number (and how many warehouse units one sold item is), or that it is not linked; then when it was sent.
            $storeProduct = Words::say('RESV', 'l_store_product', (string) $l['variant']);
            $sub = $l['sku_id'] !== null ? [(string) $l['code'], $l['per_item'] > 1 ? Words::say('RESV', 'l_per_item', $l['per_item']) : null]
                : [Words::RESV['l_unlinked'], $l['title'] === null ? null : $storeProduct];
            $sub[] = $l['state'] === 'shipped' && $l['sent_at'] !== null ? Words::say('RESV', 'l_sent', Html::when($l['sent_at'])) : null;
            $lines[] = [
                'sku_id' => $l['sku_id'], 'name' => $l['name'] ?? $l['title'] ?? $storeProduct,
                'sub' => implode(' · ', array_filter($sub, static fn (?string $t): bool => $t !== null && $t !== '')),
                'warehouse' => $l['warehouse'], 'tone' => Words::tone('RESV_UNIT', $l['state']), 'state' => Words::of('RESV_UNIT', $l['state']),
                'items' => $l['items'], 'units' => $l['units'],
            ];
        }
        $steps = $views->history((int) $r['channel_id'], (string) $r['ref']);
        $who = (new StockViews($ctx->db))->actors(array_values(array_unique(array_column($steps, 'actor'))));
        $history = [];
        foreach ($steps as $h) {
            $figures = [];
            foreach (['held', 'allocated', 'on_hand'] as $bucket) {
                if ($h[$bucket] !== 0) {
                    $figures[] = Words::say('RESV', 'h_figure', Words::STOCK[$bucket], ($h[$bucket] > 0 ? '+' : '−') . number_format(abs($h[$bucket])));
                }
            }
            $history[] = ['what' => Words::of('MOVEMENT', $h['type']), 'at' => $h['at'], 'items' => $h['items'], 'figures' => implode(' · ', $figures),
                'who' => str_starts_with($h['actor'], 'system:') ? Words::RESV['h_cw'] : ($who[$h['actor']] ?? $h['actor'])];
        }
        $title = Words::say('RESV', 'title', (string) $r['ref']);
        return $ctx->page('stock_reservation', [
            'title' => $title,
            'tone' => Words::tone('RESV_GROUP', $group),
            'chipWord' => Words::RESV_CHIP[$group],
            'back' => Html::url(self::PATH, ['channel' => $store['code'] ?? null]),
            'facts' => $facts,
            'lines' => $lines,
            'history' => $history,
        ], 200, ['title' => $title]);
    }

    /**
     * What became of a reservation, in words, and a second line that says when: [words, when or null].
     *
     * @param array<string, mixed> $r ReservationViews row
     * @return array{0: string, 1: ?string}
     */
    public static function outcome(array $r): array
    {
        $when = static fn (string $key, ?string $at): ?string => $at === null ? null : Words::say('RESV', $key, Html::when($at));
        switch ((string) $r['status']) {
            case 'held':
                return [Words::RESV['out_held'], null];
            case 'released':
                return [Words::RESV[$r['tombstone'] ? 'out_tombstone' : 'out_released'], $when('freed_at', $r['released_at'])];
            case 'expired':
                return [Words::RESV['out_expired'], $when('ran_out_at', $r['released_at'])];
        }
        /** @var array<string, int> $by */
        $by = array_filter($r['by_state'], static fn (int $n): bool => $n > 0);
        if ($by === []) {
            $words = Words::RESV['out_none'];
        } elseif (count($by) === 1) {
            $state = (string) array_key_first($by);
            $words = Words::RESV['out_all_' . $state] ?? Words::of('RESV_UNIT', $state);
        } else {
            $parts = [];
            foreach ($by as $state => $n) {
                $parts[] = Words::say('RESV', 'out_part_' . $state, $n);
            }
            $words = implode(' · ', $parts);
        }
        return [$words, $when('paid_at', $r['committed_at'])];
    }

    /**
     * One row of the board.
     *
     * @param array<string, mixed> $r ReservationViews row
     * @param array<int, string> $names store id => name
     * @return array<string, mixed>
     */
    private static function row(array $r, array $names, string $now): array
    {
        $group = (string) $r['group'];
        [$outcome, $at] = self::outcome($r);
        $held = $r['status'] === 'held';
        return [
            'href' => self::PATH . '/' . (int) $r['id'], 'ref' => (string) $r['ref'], 'store' => (string) ($names[$r['channel_id']] ?? ''),
            'tone' => Words::tone('RESV_GROUP', $group), 'chipWord' => Words::RESV_CHIP[$group],
            'products' => (int) $r['products'], 'units' => (int) $r['units'], 'unlinked' => $r['unlinked'] === 0 ? null : self::unlinked((int) $r['unlinked']),
            'started' => (string) $r['created_at'], 'try' => $r['attempt'] > 1 ? Words::say('RESV', 'try', (int) $r['attempt']) : null,
            'expires' => $held ? $r['expires_at'] : null, 'overdue' => $held && $r['expires_at'] !== null && $r['expires_at'] <= $now,
            'outcome' => $outcome, 'outcome_at' => $at,
        ];
    }

    private static function unlinked(int $n): string
    {
        return $n === 1 ? Words::RESV['unlinked_one'] : Words::say('RESV', 'unlinked_many', $n);
    }

    /**
     * The list's filters from the address: a store (its code, one of the channel table's), a state of the engine, a search text and
     * the id the page starts before. Anything else (an unknown code, a list where a value is expected, nonsense) is ignored.
     *
     * @param list<array{id: int, code: string, name: string}> $stores
     * @return array{channel: ?int, store: ?array{id: int, code: string, name: string}, state: ?string, q: string, before: ?int}
     */
    private static function filters(UiRequest $req, array $stores): array
    {
        $store = ReviewController::store($stores, $req->param('channel'));
        $state = $req->param('state');
        return [
            'channel' => $store === null ? null : (int) $store['id'],
            'store' => $store,
            'state' => in_array($state, ReservationViews::STATES, true) ? $state : null,
            'q' => mb_substr(trim(mb_scrub((string) $req->param('q'), 'UTF-8')), 0, 100),
            'before' => UiRequest::id($req->param('before')),
        ];
    }
}
