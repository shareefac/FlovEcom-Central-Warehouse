<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Ui\Context;
use CW\Ui\DashboardTiles;
use CW\Ui\HomeCounts;
use CW\Ui\HomeTasks;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * /ui/: Home, the same page for everyone (the approved HM_Home and HM_Phone of 29 Oct 2026; docs/decisions.md HV1). Every signed-in person
 * lands here after the sign-in. Top to bottom:
 *
 *  1. today's date, "Good morning, <first name>" and one Find box (catalogue.view);
 *  2. "Waiting for you": one row per kind of work waiting for THIS person (Ui\HomeTasks from Ui\HomeCounts, in its order: what holds
 *     others up first), each with a big number in the task's colour, its name, one plain line and ONE button; then the notes (no job:
 *     something the person waits for or should look at);
 *  3. up to DashboardTiles::MAX small figures the person may see;
 *  4. "Coming later": the screens not built yet that the person's jobs will use (no dates), when there are any.
 *
 * The matching progress is the "By store" overview of Matching › Mapping (HV5); the menu is the sidebar's.
 */
final class DashboardController
{
    public const NOTICES = [
        'password_changed' => Words::SIGN_IN['changed'],
        'signed_in_new_code' => Words::NEW_CODE['done'],
    ];

    public function index(Context $ctx): HtmlResponse
    {
        $notice = self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null;
        $me = $ctx->me();
        $counts = new HomeCounts($ctx);
        $home = HomeTasks::build($me->id, $me->roles, $counts->facts(HomeTasks::needs($me->roles)));
        return $ctx->page('home', [
            'greeting' => self::greeting($me->displayName),
            'tasks' => array_map(self::row(...), $home['jobs']),
            'notes' => array_map(self::row(...), $home['notes']),
            'figures' => DashboardTiles::build($ctx, $counts),
            'later' => Words::comingLater($me->roles),
            // The approved Main artboard of 30 Oct 2026 (docs/decisions.md RD-C15): Receive a delivery and + New purchase order beside the greeting
            // (the main one magenta), each for whoever may do it; Open Approvals beside "Waiting for you"; the Stores card and the Today card.
            'receive' => $me->can('doc.GRN.post') ? '/ui/receiving/new' : null,
            'newOrder' => $me->can('doc.PO.post') ? '/ui/purchasing/orders/new' : null,
            'inbox' => InboxController::kinds($me->roles) !== [] ? InboxController::PATH : null,
            'stores' => $me->can('system.view') ? self::stores($ctx) : [],
            'today' => self::today($ctx),
        ], 200, ['title' => Words::HOME['title'], 'active' => 'home', 'notice' => $notice]);
    }

    /**
     * The Stores card (docs/decisions.md RD-C15): each store, its mode in CW (live and watching only in green, off in grey) and when its
     * connector was last heard from, from what the Stores page reads (Ui\Queries::stores, storeLink: one heartbeat a store).
     *
     * @return list<array{name: string, mode: string, tone: string, line: string}>
     */
    private static function stores(Context $ctx): array
    {
        $q = $ctx->queries();
        $out = [];
        foreach ($q->everyStore() as $s) {
            $link = $q->storeLink((int) $s['id']);
            $age = $link['age'];
            $out[] = ['name' => (string) $s['name'], 'mode' => Words::of('MODE', $link['mode']), 'tone' => $link['mode'] === 'off' ? 'off' : 'done',
                'line' => match (true) {
                    $age === null => Words::SITES['no_contact_ever'],
                    $age < 3600 => Words::say('RD_HOME', 'heard_min', intdiv($age, 60)),
                    $age < 172800 => Words::say('RD_HOME', 'heard_hours', intdiv($age, 3600)),
                    default => Words::say('RD_HOME', 'heard_days', intdiv($age, 86400)),
                }];
        }
        return $out;
    }

    /**
     * The Today card (docs/decisions.md RD-C15): only figures CW already has, each for whoever may see its page: the store orders taken today and
     * their units (the sales report's day, Reports\ReportViews::sales), the deliveries being checked (Goods received's drafts, as Home's figure
     * counts them) and the oversold records still open (Approvals' badge).
     *
     * @return list<array{label: string, value: string, href: string}>
     */
    private static function today(Context $ctx): array
    {
        $me = $ctx->me();
        $out = [];
        if ($me->can('reports.view')) {
            $day = \CW\Reports\ReportRefresh::ukDay(\CW\Clock::db(\CW\Clock::now()));
            $sales = (new \CW\Reports\ReportViews($ctx->db))->sales($day, $day, null);
            $orders = 0;
            $units = 0;
            foreach ($sales['stores'] as $x) {
                $orders += (int) $x['orders'];
                $units += (int) $x['units_sold'];
            }
            $href = Html::url('/ui/reports/sales-by-store', ['period' => 'choose', 'from' => $day, 'to' => $day]);
            $out[] = ['label' => Words::RD_HOME['orders_today'], 'value' => Html::int($orders), 'href' => $href];
            $out[] = ['label' => Words::RD_HOME['units_today'], 'value' => Html::int($units), 'href' => $href];
        }
        if ($me->can('receiving.view')) {
            $out[] = ['label' => Words::RD_HOME['deliveries_open'], 'value' => Html::int((int) $ctx->db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'GRN' AND status = 'draft'")),
                'href' => '/ui/receiving'];
        }
        $b = $ctx->badges();
        if (array_key_exists('queue_oversold', $b)) {
            $out[] = ['label' => Words::RD_HOME['oversold'], 'value' => Html::int((int) $b['queue_oversold']), 'href' => '/ui/queues/oversold'];
        }
        return $out;
    }

    /** "Good morning, Bea": by the time of day in London (before noon, before 6 pm, after), with the first word of the person's name. */
    public static function greeting(string $displayName, ?\DateTimeImmutable $now = null): string
    {
        $hour = (int) ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('Europe/London'))->format('G');
        $first = preg_split('/\s+/u', trim($displayName), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
        $key = $hour < 12 ? 'greet_morning' : ($hour < 18 ? 'greet_afternoon' : 'greet_evening');
        return sprintf(Words::HOME[$key], $first);
    }

    /**
     * One row of "Waiting for you" from a task card: the big number (a spot check's "answered/of"; "!" where the card has no number) and
     * what a screen reader hears for it (the number with its unit, or the card's state).
     *
     * @param array<string, mixed> $c a card of HomeTasks::build()
     * @return array<string, mixed>
     */
    public static function row(array $c): array
    {
        if (isset($c['progress'])) {
            $big = Html::int($c['progress'][0]) . '/' . Html::int($c['progress'][1]);
        } elseif (isset($c['count'])) {
            $big = Html::int($c['count']);
        } else {
            $big = '!';
        }
        $said = isset($c['count']) ? trim(Html::int($c['count']) . ' ' . (string) ($c['unit'] ?? '')) : (string) ($c['chip'] ?? '');
        return $c + ['big' => $big, 'said' => $said];
    }
}
