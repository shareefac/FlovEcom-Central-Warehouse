<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Mapping\Proposals;
use CW\Ui\Context;
use CW\Ui\Duplicates;
use CW\Ui\HtmlResponse;
use CW\Ui\Queries;

/**
 * /ui/: for people who may see the linking screens (linking.view), the queue counts per band and site and how much
 * of the sales the linking already covers; for everyone else a home page with their menu, live links and what is
 * coming in which phase (I14). Every signed-in person lands here after the sign-in.
 */
final class DashboardController
{
    public const NOTICES = [
        'password_changed' => 'Your password was changed.',
    ];

    public function index(Context $ctx): HtmlResponse
    {
        $notice = self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null;
        $me = $ctx->me();
        if (!$me->can('linking.view')) {
            return $ctx->page('home', ['name' => $me->displayName, 'roles' => $me->rolesLabel(), 'noRoles' => $me->roles === [], 'sections' => $ctx->menu()],
                200, ['title' => 'Home', 'active' => 'home', 'notice' => $notice]);
        }
        $q = $ctx->queries();
        $channels = $q->channels();
        $counts = $q->bandCounts();
        $bands = [];
        foreach (Proposals::BANDS as $band) {
            $row = ['band' => $band, 'label' => Queries::bandLabel($band), 'by_channel' => [], 'total' => 0];
            foreach ($channels as $c) {
                $n = $counts[$band][$c['id']] ?? 0;
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
        return $ctx->page('dashboard', [
            'channels' => $channels,
            'bands' => $bands,
            'unproposed' => $q->unproposed(),
            'pending' => $q->pendingCount(),
            'duplicates' => (new Duplicates($ctx->db))->openCount(),
            'coverage' => $coverage,
        ], 200, ['title' => 'Dashboard', 'active' => 'dashboard', 'notice' => $notice]);
    }
}
