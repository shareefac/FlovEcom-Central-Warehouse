<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * The spot check (docs/decisions.md M28; in plain words since 7 Oct 2026, plan §6.9, 6.10): the spot checks started on the
 * server, and for one its 20 matches with what became of each, "n of 20 checked", the 20 blocks (design B), the button to the
 * next one for its owner, and whether the rest may be confirmed together (every match confirmed by the spot check's owner,
 * and the spot check fit: 20 or more, every band represented, its seed's draw). Read-only: the owner answers each match on
 * its own page (each row links there, and that page leads back), and confirming the rest together is a server step that
 * Fazil runs, never a button. The matches set aside to check by hand (M30) are listed with why, by whom and when, and what
 * became of each since (one a bulk step matched is flagged). The seed and the method are in a "Technical details" fold.
 */
final class SamplesController
{
    /** Member state => its words (Words::SAMPLE_STATE; the keys are KeySample's states). */
    public const STATE_LABELS = Words::SAMPLE_STATE;

    public function index(Context $ctx): HtmlResponse
    {
        // The store selector (U106): the spot checks with a match on the chosen store's website products.
        $q = $ctx->queries();
        $stores = $q->stores();
        $store = ReviewController::store($stores, $ctx->req->param('channel'));
        $only = $store === null ? null : array_fill_keys($q->samplesOfStore($store['id']), true);
        $rows = [];
        foreach ((new KeySample($ctx->db))->all() as $s) {
            if ($only !== null && !isset($only[(int) $s['id']])) {
                continue;
            }
            $rows[] = ['id' => $s['id'], 'name' => $s['name'], 'size' => $s['size'], 'decided' => $s['decided'], 'result' => self::result($s['verdict'], $s['fit']),
                'created_by' => $s['created_by'], 'created_at' => $s['created_at'], 'population' => $s['population'], 'bulk_linked' => $s['bulk_linked'],
                'bulk_undone' => $s['bulk_undone']];
        }
        return $ctx->page('samples', ['rows' => $rows, 'stores' => ReviewController::storeItems($stores, '/ui/review/samples', [], $store['code'] ?? null,
            $q->spotOpenByStore(), true)], 200, ['title' => Words::title('samples'), 'active' => 'samples']);
    }

    public function show(Context $ctx): HtmlResponse
    {
        try {
            $s = (new KeySample($ctx->db))->status($ctx->id());
        } catch (CwException $e) {
            return $ctx->error($e->httpStatus, $e->errorCode, $e->getMessage(), ['/ui/review/samples', Words::title('samples')]);
        }
        $names = [];
        foreach ($ctx->queries()->channels() as $c) {
            $names[(string) $c['code']] = (string) $c['name'];
        }
        $members = [];
        $blocks = [];
        $next = null;
        foreach ($s['members'] as $m) {
            $state = $m['state'] === 'confirmed' ? 'yes' : ($m['state'] === 'open' ? 'todo' : 'no');
            $members[] = $m + [
                'website' => $names[$m['channel']] ?? $m['channel'],
                'bad' => in_array($m['state'], KeySample::FAILED_STATES, true),
                'link' => Html::url('/ui/review/listing/' . $m['listing_id'], ['sample' => $s['id']]),
            ];
            $blocks[] = ['state' => $state, 'title' => Words::say('SPOT', ['yes' => 'block_yes', 'no' => 'block_wrong', 'todo' => 'block_todo'][$state], (int) $m['position'])];
            if ($state === 'todo' && $next === null) {
                $next = ['position' => (int) $m['position'], 'link' => Html::url('/ui/review/listing/' . $m['listing_id'], ['sample' => $s['id']])];
            }
        }
        $holds = [];
        foreach (KeyHold::ofSample($ctx->db, $s['id']) as $h) {
            $holds[] = $h + ['link' => Html::url('/ui/review/listing/' . $h['listing_id'], ['sample' => $s['id']]),
                'website' => $names[(string) $h['channel']] ?? (string) $h['channel'],
                'newer' => $h['open_proposal_id'] !== null && $h['open_proposal_id'] !== $h['proposal_id']];
        }
        $heldOpen = count(array_filter($holds, static fn (array $h): bool => $h['open']));
        $me = $ctx->me();
        $mine = $s['created_by_id'] === $me->id && $me->isLead();
        $fit = array_map(static fn (string $f): string => Words::SAMPLE_FIT[$f] ?? (str_starts_with($f, 'stratum_short:') ? Words::SAMPLE_FIT['stratum_short'] : $f), $s['fit']);
        return $ctx->page('sample', ['s' => $s, 'members' => $members, 'blocks' => $blocks, 'fit' => $fit, 'holds' => $holds, 'held_open' => $heldOpen,
            'result' => self::result($s['verdict'], $s['fit']), 'next' => $mine && $s['verdict'] === 'waiting' ? $next : null, 'mine' => $mine,
            'counts' => ['yes' => $s['confirmed'], 'wrong' => $s['failed'], 'todo' => $s['size'] - $s['decided']]], 200,
            ['title' => Words::say('SAMPLE', 'title', $s['name']), 'active' => 'samples']);
    }

    /** A spot check's result (Words::SAMPLE_RESULT): in progress, passed, failed, or all right but unusable. @param list<string> $fit */
    private static function result(string $verdict, array $fit): string
    {
        return match (true) {
            $verdict === 'complete' && $fit !== [] => 'unusable',
            $verdict === 'complete' => 'passed',
            $verdict === 'failed' => 'failed',
            default => 'waiting',
        };
    }
}
