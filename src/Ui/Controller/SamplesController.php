<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Mapping\KeySample;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;

/**
 * The Key spot-check (docs/decisions.md M28): the samples drawn by bin/sample_proposals.php, and for one sample its
 * members with what became of each, "n of 20 decided", and whether the bulk confirm may run (every member confirmed by
 * the sample's owner, and the sample fit: 20 or more, every stratum represented, its seed's draw). Read-only: the owner
 * confirms or rejects each member on the normal review screen (each row links there, and that page links back), and
 * the bulk confirm itself is a CLI step (bin/bulk_confirm_key.php), never a button.
 */
final class SamplesController
{
    public const STATE_LABELS = [
        'open' => 'Not decided yet',
        'confirmed' => 'Confirmed',
        'rejected' => 'Rejected',
        'waiting_second' => 'Waiting for a second person',
        'needed_second' => 'Confirmed with a second person',
        'superseded' => 'Replaced by a later proposal',
        'decided_otherwise' => 'Decided otherwise',
        'confirmed_by_other' => 'Decided by someone other than the sample\'s owner',
        'confirmed_not_by_lead' => 'Confirmed, but not while a mapping lead',
        'changed_since' => 'Confirmed, but relinked or unlinked since',
    ];
    public const FIT_LABELS = [
        'sample_too_small' => 'fewer than ' . KeySample::MIN_SIZE . ' proposals',
        'draw_not_reproducible' => 'its members are not what its seed draws',
    ];

    public function index(Context $ctx): HtmlResponse
    {
        $rows = [];
        foreach ((new KeySample($ctx->db))->all() as $s) {
            $rows[] = ['id' => $s['id'], 'name' => $s['name'], 'size' => $s['size'], 'decided' => $s['decided'], 'confirmed' => $s['confirmed'],
                'failed' => $s['failed'], 'verdict' => $s['verdict'], 'fit' => $s['fit'], 'created_by' => $s['created_by'], 'created_at' => $s['created_at'],
                'population' => $s['population'], 'bulk_linked' => $s['bulk_linked'], 'bulk_undone' => $s['bulk_undone']];
        }
        return $ctx->page('samples', ['rows' => $rows], 200, ['title' => 'Key spot-check', 'active' => 'samples']);
    }

    public function show(Context $ctx): HtmlResponse
    {
        try {
            $s = (new KeySample($ctx->db))->status($ctx->id());
        } catch (CwException $e) {
            return $ctx->error($e->httpStatus, $e->errorCode, $e->getMessage());
        }
        $members = [];
        foreach ($s['members'] as $m) {
            $members[] = $m + [
                'state_label' => self::STATE_LABELS[$m['state']] ?? $m['state'],
                'bad' => in_array($m['state'], KeySample::FAILED_STATES, true),
                'link' => Html::url('/ui/review/listing/' . $m['listing_id'], ['sample' => $s['id']]),
            ];
        }
        $fit = array_map(static fn (string $f): string => self::FIT_LABELS[$f]
            ?? (str_starts_with($f, 'stratum_short:') ? 'too few proposals from stratum ' . substr($f, strlen('stratum_short:')) : $f), $s['fit']);
        return $ctx->page('sample', ['s' => $s, 'members' => $members, 'fit' => $fit], 200, ['title' => 'Key spot-check ' . $s['name'], 'active' => 'samples']);
    }
}
