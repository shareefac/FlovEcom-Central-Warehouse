<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Mapping\Proposals;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Queries;

/** /ui/: queue counts per band and site, and how much of the sales the linking already covers. */
final class DashboardController
{
    public const NOTICES = [
        'password_changed' => 'Your password was changed.',
    ];

    public function index(Context $ctx): HtmlResponse
    {
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
        $notice = self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null;
        return $ctx->page('dashboard', [
            'channels' => $channels,
            'bands' => $bands,
            'unproposed' => $q->unproposed(),
            'pending' => $q->pendingCount(),
            'duplicates' => $q->openDuplicateSuggestions(),
            'coverage' => $coverage,
        ], 200, ['title' => 'Dashboard', 'active' => 'dashboard', 'notice' => $notice]);
    }
}
