<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Receiving\Incidents;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * The incident register (IM6 line exceptions; docs/decisions.md I131, I141): incidents.view looks (the open ones first, oldest
 * first; filters status and kind); incidents.resolve closes one with a note (resolved: dealt with; dismissed: nothing needed),
 * checked again by CW\Receiving\Incidents. Closing an incident moves no stock. Plain words (U85-U90): Words::INCIDENTS,
 * INCIDENT_KIND, INCIDENT_WHERE, INCIDENT_STATE; refusals by code (ReceivingController::plain).
 */
final class IncidentsController
{
    /** The notices named in a redirect (their words: Words::RECEIPT_NOTICE). */
    public const NOTICES = ['resolved' => Words::RECEIPT_NOTICE['resolved']];

    public function index(Context $ctx, int $status = 200, ?string $error = null): HtmlResponse
    {
        $req = $ctx->req;
        $state = $req->param('status');
        $state = in_array($state, Incidents::STATUSES, true) ? $state : ($state === 'all' ? null : 'open');
        $kind = $req->param('kind');
        $kind = isset(Incidents::KINDS[$kind ?? '']) ? $kind : null;
        $canResolve = $ctx->me()->can('incidents.resolve');
        return $ctx->page('incidents', [
            'rows' => array_map(self::screenRow(...), (new Incidents($ctx->db))->list($state, $kind)),
            'status' => $state ?? 'all',
            'kind' => $kind,
            'kinds' => Words::INCIDENT_KIND,
            'statuses' => [...Incidents::STATUSES, 'all'],
            'canResolve' => $canResolve,
            'lookOnly' => $canResolve ? null : Words::whoCan('incidents.resolve'),
            'error' => $error,
        ], $status, ['title' => Words::MENU['incidents'], 'active' => 'incidents', 'notice' => self::NOTICES[$req->param('notice') ?? ''] ?? null]);
    }

    public function resolve(Context $ctx): HtmlResponse
    {
        try {
            (new Incidents($ctx->db))->resolve($ctx->caller(), $ctx->id(), $ctx->req->field('status') ?? '', $ctx->req->field('note') ?? '');
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e->errorCode === 'note_required' ? Words::RECEIPT_ERROR['note_length'] : ReceivingController::plain($e));
        }
        return HtmlResponse::redirect(Html::url('/ui/receiving/incidents', ['notice' => 'resolved']));
    }

    /**
     * One incident as the register shows it: its title in words ("Damaged: 2 items of <product>"), where it came from, where the
     * items are, who found it and when, and how it was closed.
     *
     * @param array<string, mixed> $r an Incidents::list() row
     * @return array<string, mixed>
     */
    public static function screenRow(array $r): array
    {
        $closed = $r['status'] === 'open' ? null : Words::say('INCIDENTS', 'closed', Words::of('INCIDENT_STATE', (string) $r['status']), Html::when((string) $r['resolved_at']),
            (string) ($r['resolved_by_name'] ?? $r['resolved_actor'] ?? ''), (string) $r['resolution']);
        return $r + [
            'title' => Words::say('INCIDENTS', 'title', Words::of('INCIDENT_KIND', (string) $r['kind']), (int) $r['units'], (string) ($r['sku_name'] ?? $r['sku_code'])),
            'from' => $r['external_ref'] === null || $r['external_ref'] === ''
                ? Words::say('INCIDENTS', 'from_no_invoice', (string) $r['number'], (int) $r['line_no'], (string) $r['supplier_name'])
                : Words::say('INCIDENTS', 'from', (string) $r['number'], (int) $r['line_no'], (string) $r['external_ref'], (string) $r['supplier_name']),
            'where' => Words::say('INCIDENTS', 'where', mb_strtolower(Words::of('INCIDENT_WHERE', (string) $r['disposition']))),
            'opened' => Words::say('INCIDENTS', 'opened', Html::when((string) $r['opened_at']), (string) ($r['opened_by_name'] ?? '')),
            'closed' => $closed,
        ];
    }
}
