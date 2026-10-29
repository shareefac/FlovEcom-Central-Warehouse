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
            'canFind' => $me->can('catalogue.view'),
            'later' => Words::comingLater($me->roles),
        ], 200, ['title' => Words::HOME['title'], 'active' => 'home', 'notice' => $notice]);
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
