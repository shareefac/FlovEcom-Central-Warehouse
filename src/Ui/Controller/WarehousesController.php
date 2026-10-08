<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Admin\ConfigHistory;
use CW\Admin\Warehouses;
use CW\CwException;
use CW\Ui\ConfigWords;
use CW\Ui\Context;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\Words;

/**
 * Warehouses and the optional places inside them (G04, docs/decisions.md Y14-Y19; the owner's Q2, Q6, Q9). Everyone looks
 * (reference.view): the list with the stock each holds, whose stock it is and the websites selling from it. An admin or a reviewer
 * (settings.manage) adds a warehouse, and on its page renames it, says whose stock it holds, lets websites sell from it or stops
 * that (with a confirmation tick), switches it off (only when empty) or on, and adds, renames and switches off places, each with a
 * reason, through Admin\Warehouses (which re-checks everything). Nothing is deleted. Refusals come back in words, typed values kept.
 */
final class WarehousesController
{
    public const NOTICES = Words::WAREHOUSE_NOTICE;
    /** The changes the warehouse page posts (`do`). */
    private const DO = ['rename', 'owner', 'sellable', 'switch_off', 'switch_on', 'place_add', 'place_rename', 'place_off', 'place_on'];

    public function index(Context $ctx): HtmlResponse
    {
        return $this->list($ctx, 200, null, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function add(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $typed = ['code' => (string) ($req->field('code') ?? ''), 'name' => (string) ($req->field('name') ?? ''), 'owner' => (string) ($req->field('owner') ?? 'own'),
            'owner_name' => (string) ($req->field('owner_name') ?? ''), 'sellable' => $req->field('sellable') === '1', 'confirm' => $req->field('confirm') === '1',
            'note' => (string) ($req->field('note') ?? ''), 'reason' => (string) ($req->field('reason') ?? '')];
        try {
            $w = (new Warehouses($ctx->db))->add($ctx->caller(), $typed['code'], $typed['name'], $typed['sellable'], $typed['confirm'], $typed['owner'],
                $typed['owner_name'], $typed['note'], $typed['reason']);
        } catch (CwException $e) {
            return $this->list($ctx, $e->httpStatus, $e, $typed);
        }
        return HtmlResponse::redirect(Html::url('/ui/reference/warehouses/' . $w['id'], ['notice' => 'added']));
    }

    public function show(Context $ctx): HtmlResponse
    {
        return $this->page($ctx, $ctx->id(), 200, null, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function change(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $id = $ctx->id();
        $do = (string) ($req->field('do') ?? '');
        $seen = $req->field('seen');
        $typed = ['do' => $do, 'name' => (string) ($req->field('name') ?? ''), 'note' => (string) ($req->field('note') ?? ''),
            'owner' => (string) ($req->field('owner') ?? ''), 'owner_name' => (string) ($req->field('owner_name') ?? ''), 'confirm' => $req->field('confirm') === '1',
            'code' => (string) ($req->field('code') ?? ''), 'place' => (int) ($req->field('place') ?? 0), 'reason' => (string) ($req->field('reason') ?? '')];
        if (!in_array($do, self::DO, true) || ($do !== 'place_add' && ($seen === null || preg_match('/^\d{1,9}$/D', $seen) !== 1))) {
            return $this->page($ctx, $id, 400, new CwException('bad_form', Words::ERROR['bad_form'], 400), $typed);
        }
        $s = new Warehouses($ctx->db);
        $c = $ctx->caller();
        $v = $seen === null ? null : (int) $seen;
        if ($do !== 'place_add' && in_array($do, ['switch_off', 'sellable'], true) && !$typed['confirm']) {
            return $this->page($ctx, $id, 422, new CwException('unconfirmed', Words::CONFIG_ERROR['unconfirmed'], 422), $typed);
        }
        try {
            $r = match ($do) {
                'rename' => $s->rename($c, $id, $typed['name'], $typed['note'], $typed['reason'], $v),
                'owner' => $s->setOwner($c, $id, $typed['owner'], $typed['owner_name'], $typed['confirm'], $typed['reason'], $v),
                'sellable' => $s->setSellable($c, $id, $req->field('sellable') === '1', $typed['confirm'], $typed['reason'], $v),
                'switch_off', 'switch_on' => $s->setActive($c, $id, $do === 'switch_on', $typed['reason'], $v),
                'place_add' => ['changed' => true] + $s->addPlace($c, $id, $typed['code'], $typed['name'], $typed['note'], $typed['reason']),
                'place_rename' => $s->renamePlace($c, $this->placeOf($ctx, $id, $typed['place']), $typed['name'], $typed['note'], $typed['reason'], $v),
                default => $s->setPlaceActive($c, $this->placeOf($ctx, $id, $typed['place']), $do === 'place_on', $typed['reason'], $v),
            };
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, $typed);
        }
        $notice = match (true) {
            !$r['changed'] => 'unchanged',
            $do === 'switch_off' => 'off',
            $do === 'switch_on' => 'on',
            $do === 'place_add' => 'place_added',
            str_starts_with($do, 'place_') => 'place_saved',
            default => 'saved',
        };
        return HtmlResponse::redirect(Html::url('/ui/reference/warehouses/' . $id, ['notice' => $notice]) . (str_starts_with($do, 'place_') ? '#places' : ''));
    }

    /** A refusal of Admin\Warehouses in words, by its code; another code keeps the service's message. */
    public static function plain(CwException $e, string $about = 'warehouse'): string
    {
        return match ($e->errorCode) {
            'bad_code' => Words::CONFIG_ERROR[$about === 'place' ? 'bad_code_place' : 'bad_code_warehouse'],
            'changed_meanwhile' => Words::say('CONFIG_ERROR', 'changed_meanwhile', ConfigWords::who((string) ($e->detail['by'] ?? ''), null),
                Html::when(is_string($e->detail['at'] ?? null) ? $e->detail['at'] : null)),
            'warehouse_in_use' => Words::say('CONFIG_ERROR', 'warehouse_in_use', implode(', ', array_map('strval', (array) ($e->detail['websites'] ?? [])))),
            'warehouse_not_empty', 'owner_not_empty' => Words::say('CONFIG_ERROR', $e->errorCode, self::whyNotEmpty((array) ($e->detail['why'] ?? []))),
            default => Words::CONFIG_ERROR[$e->errorCode] ?? Words::error($e->errorCode, $e->getMessage()),
        };
    }

    /** Why a warehouse is not empty, in words ("it holds stock, a website uses it"). @param array<mixed> $why Warehouses::notEmpty() */
    public static function whyNotEmpty(array $why): string
    {
        return implode(', ', array_map(static fn (mixed $w): string => Words::of('WHY_NOT_EMPTY', (string) $w), $why));
    }

    /** The place $placeId when it is inside warehouse $id (else 404 through the service). */
    private function placeOf(Context $ctx, int $id, int $placeId): int
    {
        return $ctx->db->value('SELECT id FROM warehouse_location WHERE id = ? AND warehouse_id = ?', [$placeId, $id]) === null
            ? throw new CwException('unknown_place', 'there is no such place in this warehouse', 404) : $placeId;
    }

    /** @param array<string, mixed>|null $typed */
    private function list(Context $ctx, int $status, ?CwException $error, ?array $typed, ?string $notice = null): HtmlResponse
    {
        $canEdit = $ctx->me()->can('settings.manage');
        $rows = [];
        foreach ((new Warehouses($ctx->db))->all() as $w) {
            $rows[] = $w + ['href' => '/ui/reference/warehouses/' . $w['id']];
        }
        return $ctx->page('warehouses', [
            'warehouses' => $rows,
            'canEdit' => $canEdit,
            'lookOnly' => $canEdit ? null : Words::CONFIG['look_only'],
            'typed' => $typed ?? ['code' => '', 'name' => '', 'owner' => 'own', 'owner_name' => '', 'sellable' => false, 'confirm' => false, 'note' => '', 'reason' => ''],
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::MENU['warehouses'], 'active' => 'warehouses', 'notice' => $notice]);
    }

    /** @param array<string, mixed>|null $typed */
    private function page(Context $ctx, int $id, int $status, ?CwException $error, ?array $typed, ?string $notice = null): HtmlResponse
    {
        $w = (new Warehouses($ctx->db))->get($id);
        if ($w === null) {
            return $ctx->error(404, 'no_warehouse', 'there is no such warehouse', ['/ui/reference/warehouses', Words::MENU['warehouses']]);
        }
        $canEdit = $ctx->me()->can('settings.manage');
        $do = (string) ($typed['do'] ?? '');
        $places = [];
        foreach ($w['places_list'] as $p) {
            $mine = $typed !== null && (int) ($typed['place'] ?? 0) === $p['id'];
            $places[] = $p + ['history' => ConfigWords::history($ctx->db, 'location', $p['key']),
                'typed' => $mine ? $typed : ['name' => $p['name'], 'note' => (string) $p['note'], 'reason' => '', 'do' => ''], 'open' => $mine];
        }
        return $ctx->page('warehouse', [
            'w' => $w,
            'canEdit' => $canEdit,
            'lookOnly' => $canEdit ? null : Words::WAREHOUSES['look_only'],
            'seen' => ConfigHistory::version($ctx->db, 'warehouse', $w['code']),
            'places' => $places,
            'notEmpty' => implode(', ', array_map(static fn (string $c): string => Words::of('WHY_NOT_EMPTY', $c), $w['not_empty'])),
            'typed' => [
                'do' => $do,
                'name' => in_array($do, ['rename'], true) ? (string) $typed['name'] : $w['name'],
                'note' => in_array($do, ['rename'], true) ? (string) $typed['note'] : (string) $w['note'],
                'owner' => $do === 'owner' ? (string) $typed['owner'] : $w['stock_owner'],
                'owner_name' => $do === 'owner' ? (string) $typed['owner_name'] : (string) $w['owner_entity'],
                'reason' => (string) ($typed['reason'] ?? ''),
                'place_code' => $do === 'place_add' ? (string) $typed['code'] : '',
                'place_name' => $do === 'place_add' ? (string) $typed['name'] : '',
                'place_note' => $do === 'place_add' ? (string) $typed['note'] : '',
            ],
            'history' => ConfigWords::history($ctx->db, 'warehouse', $w['code']),
            'error' => $error === null ? null : self::plain($error, str_starts_with($do, 'place') ? 'place' : 'warehouse'),
            'errorCode' => $error?->errorCode,
            'errorAt' => $do,
        ], $status, ['title' => $w['name'], 'active' => 'warehouses', 'notice' => $notice]);
    }
}
