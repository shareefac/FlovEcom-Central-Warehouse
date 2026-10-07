<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Clock;
use CW\Mapping\Proposals;
use CW\Ui\Context;
use CW\Ui\Duplicates;
use CW\Ui\HomeCounts;
use CW\Ui\HomeTasks;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * /ui/: Home, the same page for everyone (plan §3; design A with B's parts). Every signed-in person lands here after the
 * sign-in. Top to bottom:
 *
 *  1. "Home", hello and the person's jobs in words (the Admin strip, when it applies, is the layout's, on every page);
 *  2. "What needs doing": one card per kind of work waiting for THIS person (Ui\HomeTasks from Ui\HomeCounts), hidden when
 *     nothing waits, sorted what-holds-others-up first, with B's job numbers and "What happens:" line and one button each; then
 *     the notes (no job: something the person waits for);
 *  3. "What is this system?": open for a person's first ABOUT_OPEN_DAYS days, then folded;
 *  4. "Matching progress" (linking.view): what still waits per list and website, and how much of what we sell is matched (the
 *     former matching dashboard, in plain words; "You can look" for people who cannot decide);
 *  5. "What you can use": each menu item with one line on what it is for;
 *  6. "Coming later": the screens not built yet that the person's jobs will use (no dates).
 */
final class DashboardController
{
    public const NOTICES = [
        'password_changed' => Words::SIGN_IN['changed'],
    ];
    /** "What is this system?" is open for this many days after the account was made (plan §3.1). */
    public const ABOUT_OPEN_DAYS = 14;

    public function index(Context $ctx): HtmlResponse
    {
        $notice = self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null;
        $me = $ctx->me();
        $counts = new HomeCounts($ctx);
        $home = HomeTasks::build($me->id, $me->roles, $counts->facts(HomeTasks::needs($me->roles)));
        $jobs = count($home['jobs']);
        $uses = [];
        foreach ($ctx->menu() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['key'] !== 'home') {
                    $uses[] = ['label' => $item['label'], 'path' => $item['path'], 'query' => $item['query'] ?? [], 'help' => Words::MENU_HELP[$item['key']] ?? ''];
                }
            }
        }
        $created = $ctx->db->value('SELECT created_at FROM staff_user WHERE id = ?', [$me->id]);
        $aboutOpen = $created === null
            || Clock::fromDb((string) $created) > Clock::now()->modify('-' . self::ABOUT_OPEN_DAYS . ' days');
        return $ctx->page('home', [
            'hello' => sprintf(Words::HOME['hello'], $me->displayName),
            'myRoles' => $me->roles,
            'tasks' => $home['jobs'],
            'notes' => $home['notes'],
            'summary' => $me->roles === [] || $jobs === 0 ? null : ($jobs === 1 ? Words::HOME['jobs_one'] : sprintf(Words::HOME['jobs_many'], number_format($jobs))),
            'aboutOpen' => $aboutOpen,
            'about' => Words::ABOUT,
            'progress' => $me->can('linking.view') ? $this->progress($ctx, $counts) : null,
            'uses' => $uses,
            'later' => Words::comingLater($me->roles),
        ], 200, ['title' => Words::HOME['title'], 'active' => 'home', 'notice' => $notice]);
    }

    /**
     * Matching progress (the former dashboard, plan F075-F093): the open suggestions per list ("How sure") and website, those no
     * computer check has looked at yet, the second OKs and possible duplicates waiting, and per website how much of what was
     * sold is on matched website products.
     *
     * @return array<string, mixed> the variables of matching_progress.php
     */
    private function progress(Context $ctx, HomeCounts $counts): array
    {
        $me = $ctx->me();
        $q = $ctx->queries();
        $channels = $q->channels();
        $byBand = $counts->bandCounts();
        $bands = [];
        foreach (Proposals::BANDS as $band) {
            $row = ['band' => $band, 'by_channel' => [], 'total' => 0];
            foreach ($channels as $c) {
                $n = $byBand[$band][$c['id']] ?? 0;
                $row['by_channel'][$c['id']] = $n;
                $row['total'] += $n;
            }
            $bands[] = $row;
        }
        $coverage = [];
        $figures = $q->coverage();
        foreach ($channels as $c) {
            $f = $figures[$c['id']] ?? array_fill_keys(['listings', 'linked_listings', 'u30', 'l30', 'i30', 'u365', 'l365', 'i365'], 0);
            $coverage[] = ['channel' => $c] + $f;
        }
        $pending = $q->pendingCount();
        $duplicates = (int) ($ctx->badges()['linking_duplicates'] ?? (new Duplicates($ctx->db))->openCount());
        return [
            'channels' => $channels,
            'bands' => $bands,
            'unproposed' => $q->unproposed(),
            'pending' => $pending,
            'pendingText' => match (true) {
                $pending === 0 => Words::HOME['pending_none'],
                $pending === 1 => Words::HOME['pending_one'],
                default => sprintf(Words::HOME['pending_many'], number_format($pending)),
            },
            'duplicates' => $duplicates,
            'duplicatesText' => sprintf(Words::HOME['duplicates'], number_format($duplicates)),
            'howSure' => Words::HOME['how_sure'],
            'coverage' => $coverage,
            'lookOnly' => !$me->can('mapping.decide'),
            'lead' => $me->can('mapping.approve'),
        ];
    }
}
