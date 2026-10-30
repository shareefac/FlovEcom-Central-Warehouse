<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Clock;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\ReservationViews;
use CW\Ui\SalesViews;
use CW\Ui\StockViews;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Stock › Sales › Dispatch (pack D, docs/decisions.md PD6, PD7, PD9-PD12): READ ONLY, for the people who look at sales (sales.view). A board
 * (docs/decisions.md W10): the list chosen in the Show menu, its rows grouped (late / on time; a day of sending; sending undone).
 *
 *   /ui/stock/sales/dispatch   for every store or one: five figures (orders and units waiting to ship; the late ones, paid more than
 *                              `sales.late_hours` hours ago; sent today; sent this week, from Monday, UK time; units whose sending was
 *                              undone this week) and one of four lists (`show`): waiting to ship, oldest order first (each with its
 *                              payment time, how long it has waited, Late / On time, and whether its sending was undone before);
 *                              sent today; sent this week (newest sending first); sending undone (newest first, one row per unit).
 * The stores send orders and undo a sending; nothing here changes either.
 */
final class DispatchController
{
    public const PATH = '/ui/stock/sales/dispatch';

    public function index(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $views = new SalesViews($ctx->db);
        $set = SalesViews::settings($ctx->db);
        $stores = (new ReservationViews($ctx->db))->stores();
        $store = ReviewController::store($stores, $req->param('channel'));
        $show = in_array($req->param('show'), SalesViews::SHOWS, true) ? (string) $req->param('show') : 'waiting';
        $storeIds = array_column($stores, 'id');
        $chosen = $store === null ? $storeIds : [(int) $store['id']];
        $now = Clock::now();
        $since = SalesViews::since($now);
        $cutoff = Clock::db($now->modify('-' . $set['late_hours'] . ' hours'));

        $counts = $views->waitingCounts($storeIds);
        $mine = array_intersect_key($counts, array_flip($chosen));
        $late = $views->late($mine, $cutoff, $set['scan_max']);
        $today = $views->sentFigures($chosen, $since['today']);
        $week = $views->sentFigures($chosen, $since['week']);
        $undoneWeek = $views->undoneSince($chosen, $since['week']);
        $sum = static fn (array $per, string $k): int => array_sum(array_map(static fn (array $x): int => (int) $x[$k], $per));
        $figures = [
            'waiting' => $sum($mine, 'orders'), 'waiting_units' => $sum($mine, 'units'),
            'late' => $sum($late, 'late'), 'late_capped' => in_array(true, array_column($late, 'capped'), true),
            'today' => $sum($today, 'orders'), 'today_units' => $sum($today, 'units'),
            'week' => $sum($week, 'orders'), 'week_units' => $sum($week, 'units'),
            'undone' => array_sum($undoneWeek),
        ];

        $code = $store === null ? null : (string) $store['code'];
        $base = ['channel' => $code, 'show' => $show === 'waiting' ? null : $show];
        $names = array_column($stores, 'name', 'id');
        $list = match ($show) {
            'waiting' => $this->waitingList($ctx, $views, $chosen, $names, $set, $base),
            'today', 'week' => $this->sentList($ctx, $views, $chosen, $names, $since[$show], $set, $base),
            default => $this->undoneList($ctx, $views, $chosen, $names, $set, $base),
        };
        // The Show menu (docs/decisions.md W10): the four lists, the one shown chosen; a GET form of `show`, as the links were.
        $shows = [];
        foreach (SalesViews::SHOWS as $s) {
            $shows[] = ['value' => $s, 'label' => Words::DISPATCH['show_' . $s], 'current' => $show === $s];
        }
        return $ctx->page('sales_dispatch', [
            'channel' => $code,
            'storeItems' => ReviewController::storeItems($stores, self::PATH, ['show' => $base['show']], $code,
                array_map(static fn (array $c): int => $c['orders'], $counts), true),
            'figures' => $figures,
            'lateHours' => $set['late_hours'],
            'lateCapped' => $figures['late_capped'] ? Words::say('DISPATCH', 'late_capped', $set['scan_max']) : null,
            'shows' => $shows,
            'show' => $show,
        ] + $list, 200, ['title' => Words::MENU['sales_dispatch']]);
    }

    /** How long ago a payment was, in words: "under an hour", "1 hour", "26 hours", "3 days" (two days or more). */
    public static function age(string $paidAt, ?\DateTimeImmutable $now = null): string
    {
        $sec = max(0, (int) round(Clock::diff(Clock::fromDb($paidAt), $now ?? Clock::now())));
        $hours = intdiv($sec, 3600);
        return match (true) {
            $hours < 1 => Words::DISPATCH['under_hour'],
            $hours === 1 => Words::DISPATCH['hours_one'],
            $hours < 48 => Words::say('DISPATCH', 'hours_many', $hours),
            default => Words::say('DISPATCH', 'days_many', intdiv($hours, 24)),
        };
    }

    /** Whether a paid order not sent is late: paid more than $hours hours ago (`sales.late_hours`). */
    public static function isLate(string $paidAt, int $hours, ?\DateTimeImmutable $now = null): bool
    {
        return $paidAt < Clock::db(($now ?? Clock::now())->modify('-' . $hours . ' hours'));
    }

    /**
     * Waiting to ship, oldest order first, after the order `after`.
     *
     * @param list<int> $stores
     * @param array<int, string> $names
     * @param array{page: int, late_hours: int} $set
     * @param array<string, ?string> $base
     * @return array<string, mixed>
     */
    private function waitingList(Context $ctx, SalesViews $views, array $stores, array $names, array $set, array $base): array
    {
        $after = UiRequest::id($ctx->req->param('after'));
        $page = $views->waiting($stores, $after, $set['page']);
        $orders = $views->rows($page['ids']);
        $refs = [];
        foreach ($orders as $r) {
            $refs[(int) $r['channel_id']][] = (string) $r['ref'];
        }
        $undone = [];
        foreach ($refs as $store => $list) {
            $undone[$store] = $views->undoneOrders($store, $list);
        }
        $rows = [];
        foreach ($orders as $r) {
            $paid = $r['committed_at'] === null ? null : (string) $r['committed_at'];
            $isLate = $paid !== null && self::isLate($paid, $set['late_hours']);
            $notes = [];
            if (isset($undone[(int) $r['channel_id']][(string) $r['ref']])) {
                $notes[] = Words::DISPATCH['was_undone'];
            }
            if ($r['unlinked'] > 0) {
                $notes[] = $r['unlinked'] === 1 ? Words::DISPATCH['unlinked_one'] : Words::say('DISPATCH', 'unlinked_many', (int) $r['unlinked']);
            }
            $rows[] = ['href' => SalesController::PATH . '/' . (int) $r['id'], 'ref' => (string) $r['ref'], 'store' => (string) ($names[$r['channel_id']] ?? ''),
                'paid' => $paid, 'waited' => $paid === null ? '' : self::age($paid), 'group' => $isLate ? 'late' : 'on_time', 'tone' => Words::tone('DISPATCH_CHIP', $isLate ? 'late' : 'on_time'),
                'chipWord' => Words::DISPATCH_CHIP[$isLate ? 'late' : 'on_time'], 'units' => (int) $r['by_state']['allocated'], 'note' => implode(' · ', $notes)];
        }
        // The board's groups (docs/decisions.md W10): the late ones, then the ones on time, oldest first in each.
        $groups = [];
        foreach (['late', 'on_time'] as $g) {
            $in = array_values(array_filter($rows, static fn (array $row): bool => $row['group'] === $g));
            if ($in !== []) {
                $groups[] = ['id' => 'g-' . $g, 'name' => Words::DISPATCH_CHIP[$g], 'tone' => Words::tone('DISPATCH_CHIP', $g), 'rows' => $in];
            }
        }
        return ['groups' => $groups,
            'next_link' => $page['next'] === null ? null : Html::url(self::PATH, $base + ['after' => $page['next']]),
            'next_word' => Words::DISPATCH['later'], 'start_link' => $after === null ? null : Html::url(self::PATH, $base),
            'none' => [Words::DISPATCH['none_waiting'], Words::DISPATCH['none_waiting_text']], 'extra' => null];
    }

    /**
     * Sent in the period (today or this week), newest sending first, after the order `before`.
     *
     * @param list<int> $stores
     * @param array<int, string> $names
     * @param array{page: int} $set
     * @param array<string, ?string> $base
     * @return array<string, mixed>
     */
    private function sentList(Context $ctx, SalesViews $views, array $stores, array $names, string $since, array $set, array $base): array
    {
        $before = UiRequest::id($ctx->req->param('before'));
        $page = $views->sent($stores, $since, $before, $set['page']);
        $orders = [];
        foreach ($views->rows(array_column($page['rows'], 'id')) as $r) {
            $orders[(int) $r['id']] = $r;
        }
        $rows = [];
        foreach ($page['rows'] as $s) {
            $r = $orders[$s['id']] ?? null;
            if ($r === null) {
                continue;
            }
            $rows[] = ['href' => SalesController::PATH . '/' . (int) $r['id'], 'ref' => (string) $r['ref'], 'store' => (string) ($names[$r['channel_id']] ?? ''),
                'sent' => $s['sent_at'], 'units' => $s['units'], 'now' => ReservationsController::outcome($r)[0],
                'tone' => Words::tone('RESV_GROUP', (string) $r['group']), 'chipWord' => Words::RESV_CHIP[(string) $r['group']]];
        }
        // The board's groups (docs/decisions.md W10): a group a UK day of sending, newest first (the list's own order); the status cell says
        // what the order is now (still to ship, or dealt with).
        $groups = [];
        foreach ($rows as $row) {
            $day = Html::day((string) $row['sent']);
            if ($groups === [] || $groups[count($groups) - 1]['name'] !== $day) {
                $groups[] = ['id' => 'g-day-' . (count($groups) + 1), 'name' => $day, 'tone' => 'done', 'rows' => []];
            }
            $groups[count($groups) - 1]['rows'][] = $row;
        }
        $today = $base['show'] === 'today';
        return ['groups' => $groups,
            'next_link' => $page['next'] === null ? null : Html::url(self::PATH, $base + ['before' => $page['next']]),
            'next_word' => Words::DISPATCH['earlier'], 'start_link' => $before === null ? null : Html::url(self::PATH, $base),
            'none' => [Words::DISPATCH[$today ? 'none_today' : 'none_week'], Words::DISPATCH['none_sent_text']], 'extra' => null];
    }

    /**
     * Sending undone, newest first, one row per unit, older than the ledger id `before`.
     *
     * @param list<int> $stores
     * @param array<int, string> $names
     * @param array{page: int} $set
     * @param array<string, ?string> $base
     * @return array<string, mixed>
     */
    private function undoneList(Context $ctx, SalesViews $views, array $stores, array $names, array $set, array $base): array
    {
        $before = UiRequest::id($ctx->req->param('before'));
        $page = $views->undone($stores, $before, $set['page']);
        $who = (new StockViews($ctx->db))->actors(array_values(array_unique(array_column($page['rows'], 'actor'))));
        $rows = [];
        foreach ($page['rows'] as $u) {
            $rows[] = ['href' => $u['reservation_id'] === null ? null : SalesController::PATH . '/' . $u['reservation_id'], 'ref' => $u['ref'],
                'store' => (string) ($names[$u['channel_id']] ?? ''), 'unit' => $u['unit_id'], 'at' => $u['at'],
                'who' => str_starts_with($u['actor'], 'system:') ? Words::SALE_ORDER['s_cw'] : ($who[$u['actor']] ?? $u['actor']),
                'now' => $u['state'] === null ? '' : Words::of('RESV_UNIT', $u['state']), 'tone' => $u['state'] === null ? 'waiting' : Words::tone('RESV_UNIT', $u['state'])];
        }
        // One group (docs/decisions.md W10); the status cell is what the unit is now.
        return ['groups' => $rows === [] ? [] : [['id' => 'g-undone', 'name' => Words::DISPATCH['show_undone'], 'tone' => 'needs', 'rows' => $rows]],
            'next_link' => $page['next'] === null ? null : Html::url(self::PATH, $base + ['before' => $page['next']]),
            'next_word' => Words::DISPATCH['earlier'], 'start_link' => $before === null ? null : Html::url(self::PATH, $base),
            'none' => [Words::DISPATCH['none_undone'], Words::DISPATCH['none_undone_text']], 'extra' => Words::DISPATCH['undone_unlinked']];
    }
}
