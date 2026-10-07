<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Receiving\Incidents;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;

/**
 * The incident register (IM6 line exceptions; docs/decisions.md I131, I141): incidents.view looks (the open ones first, oldest
 * first; filters status and kind); incidents.resolve closes one with a note (resolved: dealt with; dismissed: nothing needed),
 * checked again by CW\Receiving\Incidents. Closing an incident moves no stock.
 */
final class IncidentsController
{
    public const NOTICES = ['resolved' => 'Incident closed.'];

    public function index(Context $ctx, int $status = 200, ?string $error = null): HtmlResponse
    {
        $req = $ctx->req;
        $state = $req->param('status');
        $state = in_array($state, Incidents::STATUSES, true) ? $state : ($state === 'all' ? null : 'open');
        $kind = $req->param('kind');
        $kind = isset(Incidents::KINDS[$kind ?? '']) ? $kind : null;
        return $ctx->page('incidents', [
            'rows' => (new Incidents($ctx->db))->list($state, $kind),
            'status' => $state ?? 'all',
            'kind' => $kind,
            'kinds' => Incidents::KINDS,
            'dispositions' => Incidents::DISPOSITIONS,
            'canResolve' => $ctx->me()->can('incidents.resolve'),
            'error' => $error,
        ], $status, ['title' => 'Incidents', 'active' => 'incidents', 'notice' => self::NOTICES[$req->param('notice') ?? ''] ?? null]);
    }

    public function resolve(Context $ctx): HtmlResponse
    {
        try {
            (new Incidents($ctx->db))->resolve($ctx->caller(), $ctx->id(), $ctx->req->field('status') ?? '', $ctx->req->field('note') ?? '');
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e->getMessage());
        }
        return HtmlResponse::redirect(Html::url('/ui/receiving/incidents', ['notice' => 'resolved']));
    }
}
