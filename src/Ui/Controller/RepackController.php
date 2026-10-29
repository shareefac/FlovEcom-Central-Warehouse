<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Items\RepackHandler;
use CW\Items\Repacks;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Stock › Repack (pack B; owner answer Q3; docs/decisions.md PB9-PB13): the repack records, newest first (state and search filters), and
 * the form that books one: a warehouse, a pack item (Products › Multipacks), which way and how many packs (CW\Items\Repacks::book: made
 * and made final in one go; a form key makes it happen once however often it is sent). A record opens on its own page (Approvals ›
 * History: Documents), where it is cancelled once final. A repack left a draft (its maker took an OK request back) is made final or
 * stopped here; one waiting for an OK is taken back here. Who: documents.view looks; doc.RPK.post books (checked again by the document
 * base, never admin).
 */
final class RepackController
{
    public const PATH = '/ui/stock/repack';

    /**
     * @param array<string, string> $typed what a refused form sent
     */
    public function index(Context $ctx, int $status = 200, ?CwException $error = null, array $typed = []): HtmlResponse
    {
        $req = $ctx->req;
        $me = $ctx->me();
        $canPost = Documents::mayPost($me->roles, RepackHandler::TYPE);
        $state = in_array($req->param('state'), Document::STATUSES, true) ? (string) $req->param('state') : null;
        $q = mb_substr(trim($req->param('q') ?? ''), 0, 100);
        $repacks = new Repacks($ctx->db, $ctx->documents());
        $rows = array_map(fn (array $r): array => $this->row($r, $me->id, $canPost), $repacks->list(['state' => $state, 'q' => $q]));
        $choices = $repacks->choices();
        $done = null;
        $doneId = \CW\Ui\UiRequest::id($req->param('done'));
        if ($doneId !== null) {
            foreach ($rows as $r) {
                if ($r['id'] === $doneId) {
                    $done = ['text' => match ($r['status']) {
                        'posted' => Words::say('REPACK', 'done_posted', (string) $r['number']),
                        'awaiting_approval' => Words::REPACK['done_waiting'],
                        default => Words::REPACK['done_other'],
                    }, 'href' => $r['href']];
                }
            }
        }
        $packId = \CW\Ui\UiRequest::id($req->param('pack'));
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['status']][] = $r;
        }
        return $ctx->page('repacks', [
            'rows' => $rows,
            // The board's groups (docs/decisions.md W8), in the statuses' order.
            'groups' => array_filter(array_map(static fn (string $st): array => $groups[$st] ?? [], array_combine(Document::STATUSES, Document::STATUSES)),
                static fn (array $g): bool => $g !== []),
            'rows_n' => count($rows),
            'limit' => Repacks::LIST_LIMIT,
            'f' => ['state' => $state, 'q' => $q],
            'filtered' => $state !== null || $q !== '',
            'states' => array_intersect_key(Words::DOC_STATUS, array_flip(Document::STATUSES)),
            'canPost' => $canPost,
            'formKey' => $canPost ? ($typed['form_key'] ?? FormOnce::newKey()) : null,
            'warehouses' => array_map(static fn (array $w): array => ['id' => (int) $w['id'], 'name' => (string) $w['name']], $choices['warehouses']),
            'packs' => array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'label' => Words::say('REPACK', 'pack_choice', (string) $p['pack_code'],
                (string) $p['pack_name'], (int) $p['units'], (string) $p['single_code'], (string) $p['single_name'])], $choices['packs']),
            'typed' => ['warehouse' => $typed['warehouse'] ?? (string) ($choices['warehouses'][0]['id'] ?? ''), 'pack' => $typed['pack'] ?? ($packId === null ? '' : (string) $packId),
                'direction' => $typed['direction'] ?? 'break', 'packs' => $typed['packs'] ?? '1', 'note' => $typed['note'] ?? ''],
            'directions' => Words::REPACK_DIRECTION,
            'lookOnly' => $canPost ? null : Words::whoCan('doc.RPK.post'),
            'done' => $done,
            'error' => $error === null ? null : FamiliesController::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::MENU['repack']]);
    }

    public function book(Context $ctx): HtmlResponse
    {
        $typed = [];
        foreach (['warehouse', 'pack', 'direction', 'packs', 'note'] as $f) {
            $typed[$f] = trim($ctx->req->field($f) ?? '');
        }
        try {
            $in = ['warehouse' => \CW\Ui\UiRequest::id($typed['warehouse']), 'pack' => \CW\Ui\UiRequest::id($typed['pack']), 'direction' => $typed['direction'],
                'packs' => preg_match('/^[0-9]{1,8}$/D', $typed['packs']) === 1 ? (int) $typed['packs'] : null, 'note' => $typed['note']];
            $r = FormOnce::run($ctx, 'ui.repack.book', $typed, static function (Db $db) use ($ctx, $in): OpResult {
                $doc = (new Repacks($db, $ctx->documents()))->book($ctx->caller(), $in);
                return OpResult::of(303, ['id' => $doc->id, 'redirect' => Html::url(self::PATH, ['done' => $doc->id])]);
            });
        } catch (CwException $e) {
            $key = $ctx->req->field(FormOnce::FIELD);
            return $this->index($ctx, $e->httpStatus, $e, $typed + ['form_key' => in_array($e->errorCode, ['bad_form_key', 'idempotency_key_reused'], true)
                || $key === null ? FormOnce::newKey() : $key]);
        }
        return FormOnce::redirect($r);
    }

    /** A draft repack made final (its maker had taken an OK request back). */
    public function post(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, fn (Repacks $r): Document => $r->post($ctx->caller(), $ctx->id(), (int) ($ctx->req->field('version') ?? 0)));
    }

    /** A draft repack stopped (never numbered, nothing booked). */
    public function cancel(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, fn (Repacks $r): Document => $r->cancel($ctx->caller(), $ctx->id(), (int) ($ctx->req->field('version') ?? 0),
            $ctx->req->field('reason') ?? ''));
    }

    /** A repack waiting for a reviewer's OK taken back (it becomes a draft). */
    public function withdraw(Context $ctx): HtmlResponse
    {
        return $this->act($ctx, fn (Repacks $r): Document => $r->withdraw($ctx->caller(), $ctx->id()));
    }

    /** @param \Closure(Repacks): Document $fn */
    private function act(Context $ctx, \Closure $fn): HtmlResponse
    {
        try {
            $doc = $fn(new Repacks($ctx->db, $ctx->documents()));
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH, ['done' => $doc->id]));
    }

    /** @param array<string, mixed> $r Repacks::list() row @return array<string, mixed> */
    private function row(array $r, int $me, bool $canPost): array
    {
        $packs = (int) $r['packs'];
        $singles = $packs * (int) $r['units_per_pack'];
        $what = $r['direction'] === 'break'
            ? Words::say('REPACK', 'line_break', $packs, (string) $r['pack_code'], $singles, (string) $r['single_code'])
            : Words::say('REPACK', 'line_make', $singles, (string) $r['single_code'], $packs, (string) $r['pack_code']);
        $status = (string) $r['status'];
        $mine = $canPost && (int) ($r['created_by'] ?? 0) === $me;
        return [
            'id' => (int) $r['id'], 'number' => $r['number'] === null ? null : (string) $r['number'], 'status' => $status,
            'title' => Words::docTitle(RepackHandler::TYPE, $r['number'] === null ? null : (string) $r['number'], null,
                $r['reverses_number'] === null ? null : (string) $r['reverses_number']),
            'date' => (string) ($r['posted_at'] ?? $r['created_at']), 'where' => (string) ($r['warehouse'] ?? ''), 'way' => Words::of('REPACK_DIRECTION', (string) $r['direction']),
            'what' => $what, 'made_by' => (string) ($r['created_by_name'] ?? ''), 'href' => '/ui/documents/' . (int) $r['id'], 'version' => (int) $r['version'],
            'canFinal' => $status === 'draft' && $canPost, 'canStop' => $status === 'draft' && $canPost, 'canTakeBack' => $status === 'awaiting_approval' && $mine,
        ];
    }
}
