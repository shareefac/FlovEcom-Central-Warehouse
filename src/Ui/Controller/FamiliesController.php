<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Items\Families;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Products › Families (pack B; owner answer Q4; docs/decisions.md PB4-PB6): the families (search, the switched-off ones on request, a
 * page of 50), a new family (a form key: one family however often it is sent), one family's page (rename, switch off or on, its items
 * with stock, a pasted list put in at once and ticked items taken out, each with the skipped ones listed and why), and the item page's
 * family box (put in, move, take out). Every change goes through CW\Items\Families, which checks the person again (families.manage,
 * never admin). Everyone with catalogue.view reads.
 */
final class FamiliesController
{
    public const PATH = '/ui/items/families';

    public function index(Context $ctx, int $status = 200, ?CwException $error = null, array $typed = []): HtmlResponse
    {
        $req = $ctx->req;
        $q = mb_substr(trim($req->param('q') ?? ''), 0, 100);
        $off = $req->param('off') === '1';
        $page = max(1, min(10_000, UiRequest::id($req->param('page')) ?? 1));
        $list = (new Families($ctx->db))->list($q, $off, $page);
        $can = $ctx->me()->can('families.manage');
        $base = ['q' => $q === '' ? null : $q, 'off' => $off ? '1' : null];
        $notice = $req->param('notice');
        $rows = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (int) $r['is_active'] === 1,
            'items' => (int) $r['items'], 'href' => self::PATH . '/' . (int) $r['id'], 'group' => (int) $r['is_active'] === 1 ? 'on' : 'off'], $list['rows']);
        return $ctx->page('families', [
            'rows' => $rows,
            // The board (wave w11, docs/decisions.md W11.4): in use, then switched off (shown with ?off=1).
            'groups' => Html::groups($rows, ['on' => ['done', Words::FAMILY['on']], 'off' => ['off', Words::FAMILY['off']]]),
            'total' => $list['total'],
            'q' => $q,
            'off' => $off,
            'filtered' => $q !== '' || $off,
            'page_no' => $list['page'],
            'pages' => $list['pages'],
            'prev_link' => $list['page'] > 1 ? Html::url(self::PATH, $base + ['page' => $list['page'] - 1]) : null,
            'next_link' => $list['page'] < $list['pages'] ? Html::url(self::PATH, $base + ['page' => $list['page'] + 1]) : null,
            'canManage' => $can,
            'formKey' => $can ? ($typed['form_key'] ?? FormOnce::newKey()) : null,
            'typed' => ['name' => (string) ($typed['name'] ?? ''), 'note' => (string) ($typed['note'] ?? '')],
            'lookOnly' => $can ? null : Words::whoCan('families.manage'),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => Words::MENU['families'], 'notice' => $notice !== null ? (Words::ITEMS_NOTICE[$notice] ?? null) : null]);
    }

    public function create(Context $ctx): HtmlResponse
    {
        $typed = ['name' => $ctx->req->field('name') ?? '', 'note' => $ctx->req->field('note') ?? ''];
        try {
            $r = FormOnce::run($ctx, 'ui.family.create', $typed, static function (Db $db) use ($ctx, $typed): OpResult {
                $f = (new Families($db))->create($ctx->caller(), $typed['name'], $typed['note']);
                return OpResult::of(303, ['id' => $f['id'], 'redirect' => Html::url(self::PATH . '/' . $f['id'], ['notice' => 'family_made'])]);
            });
        } catch (CwException $e) {
            $key = $ctx->req->field(FormOnce::FIELD);
            return $this->index($ctx, $e->httpStatus, $e, $typed + ['form_key' => $e->errorCode === 'bad_form_key' || $e->errorCode === 'idempotency_key_reused'
                || $key === null ? FormOnce::newKey() : $key]);
        }
        return FormOnce::redirect($r);
    }

    public function show(Context $ctx): HtmlResponse
    {
        $notice = $ctx->req->param('notice');
        return $this->page($ctx, $ctx->id(), 200, null, $notice !== null ? (Words::ITEMS_NOTICE[$notice] ?? null) : null);
    }

    public function rename(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        try {
            $r = (new Families($ctx->db))->rename($ctx->caller(), $id, self::version($ctx), $ctx->req->field('name') ?? '');
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['name' => $ctx->req->field('name') ?? '']);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => $r['changed'] ? 'family_renamed' : 'unchanged']));
    }

    public function switch(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $on = $ctx->req->field('on') === '1';
        try {
            $r = (new Families($ctx->db))->setActive($ctx->caller(), $id, self::version($ctx), $on);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH . '/' . $id, ['notice' => !$r['changed'] ? 'unchanged' : ($on ? 'family_on' : 'family_off')]));
    }

    /** The pasted list put in the family: the page again, with what was put in and what was left out (and why). */
    public function add(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $list = $ctx->req->field('list') ?? '';
        try {
            $r = FormOnce::run($ctx, 'ui.family.add', ['family' => (string) $id, 'list' => $list], static function (Db $db) use ($ctx, $id, $list): OpResult {
                return OpResult::of(200, (new Families($db))->bulkAssign($ctx->caller(), $id, $list));
            });
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, ['list' => $list]);
        }
        return $this->page($ctx, $id, 200, null, null, [], ['added' => $r->body['added'] ?? [], 'removed' => [], 'skipped' => $r->body['skipped'] ?? []]);
    }

    /** The ticked items taken out: the page again, with what was taken out and what was left (and why). */
    public function remove(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $ids = [];
        foreach ($ctx->req->fieldsMatching('/^pick_[1-9][0-9]{0,9}$/D') as $name => $v) {
            if ($v === '1') {
                $ids[] = (int) substr($name, 5);
            }
        }
        try {
            $r = (new Families($ctx->db))->bulkRemove($ctx->caller(), $id, $ids);
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return $this->page($ctx, $id, 200, null, null, [], ['added' => [], 'removed' => $r['removed'], 'skipped' => $r['skipped']]);
    }

    /** The item page's family box: put the item in the family named, move it, or (an empty name) take it out. */
    public function itemFamily(Context $ctx): HtmlResponse
    {
        $sku = $ctx->id();
        $name = trim($ctx->req->field('family') ?? '');
        $seen = UiRequest::id($ctx->req->field('seen'));
        $fam = new Families($ctx->db);
        try {
            $to = null;
            if ($name !== '') {
                $to = ($fam->activeByName($name) ?? throw new CwException('unknown_family', 'no family in use has that name', 422, ['field' => 'family']))['id'];
            }
            $r = $fam->assign($ctx->caller(), $sku, $to, $seen);
        } catch (CwException $e) {
            return (new ItemController())->page($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/items/' . $sku, ['notice' => !$r['changed'] ? 'unchanged' : ($r['to'] === null ? 'family_out' : 'family_set')]) . '#groups');
    }

    /**
     * One family's page, also after a refused form ($error, answered under $status; $typed: what the form had) or a bulk change
     * ($result: what was done and what was left out).
     *
     * @param array<string, string> $typed
     * @param array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, skipped: list<array<string, mixed>>}|null $result
     */
    private function page(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null, array $typed = [], ?array $result = null): HtmlResponse
    {
        $svc = new Families($ctx->db);
        $f = $svc->get($id);
        if ($f === null) {
            return $ctx->error(404, 'unknown_family', Words::ITEMS_ERROR['unknown_family'], [self::PATH, Words::MENU['families']]);
        }
        $page = max(1, min(10_000, UiRequest::id($ctx->req->param('page')) ?? 1));
        $members = $svc->members($id, $page);
        $can = $ctx->me()->can('families.manage');
        $changed = (string) $f['updated_at'] !== (string) $f['created_at'];
        $active = (int) $f['is_active'] === 1;
        return $ctx->page('family', [
            // The record page (wave w11, docs/decisions.md W11.5): the paper card's party (the family and its note) and facts, the figures strip.
            'party' => ['label' => Words::FAMILY['party'], 'name' => (string) $f['name'], 'href' => null, 'lines' => $f['note'] === null ? [] : [(string) $f['note']]],
            'docFacts' => [
                ['label' => Words::FAMILY['fact_state'], 'value' => $active ? Words::FAMILY['on'] : Words::FAMILY['off']],
                ['label' => Words::FAMILY['fact_made'], 'value' => Words::say('FAMILY', 'made', (string) ($f['created_by_name'] ?? Words::AUDIT['cw']), Html::when((string) $f['created_at']))],
                ['label' => Words::FAMILY['fact_changed'], 'value' => $changed
                    ? Words::say('FAMILY', 'changed', (string) ($f['updated_by_name'] ?? Words::AUDIT['cw']), Html::when((string) $f['updated_at'])) : Words::FAMILY['not_changed']],
            ],
            'madeOn' => (string) $f['created_at'],
            'changedOn' => $changed ? (string) $f['updated_at'] : null,
            'f' => ['id' => $id, 'name' => (string) $f['name'], 'active' => (int) $f['is_active'] === 1, 'version' => (int) $f['version'], 'note' => $f['note'] === null ? null : (string) $f['note'],
                'items' => (int) $f['items'], 'made' => Words::say('FAMILY', 'made', (string) ($f['created_by_name'] ?? Words::AUDIT['cw']), Html::when((string) $f['created_at'])),
                'changed' => (string) $f['updated_at'] === (string) $f['created_at'] ? null
                    : Words::say('FAMILY', 'changed', (string) ($f['updated_by_name'] ?? Words::AUDIT['cw']), Html::when((string) $f['updated_at']))],
            'members' => array_map(static fn (array $m): array => ['id' => (int) $m['id'], 'code' => (string) $m['code'], 'name' => (string) $m['name'],
                'brand' => $m['brand'] === null ? null : (string) $m['brand'], 'flavour' => $m['flavour'] === null ? null : (string) $m['flavour'],
                'strength' => $m['strength_mg'] === null ? null : Html::dec($m['strength_mg']) . ' mg', 'on_hand' => (int) $m['on_hand'], 'since' => (string) $m['set_at'],
                'merged' => $m['merged_into_sku_id'] !== null], $members['rows']),
            'total' => $members['total'],
            'page_no' => $members['page'],
            'pages' => $members['pages'],
            'prev_link' => $members['page'] > 1 ? Html::url(self::PATH . '/' . $id, ['page' => $members['page'] - 1]) : null,
            'next_link' => $members['page'] < $members['pages'] ? Html::url(self::PATH . '/' . $id, ['page' => $members['page'] + 1]) : null,
            'canManage' => $can,
            'addKey' => $can ? FormOnce::newKey() : null,
            'typed' => ['name' => $typed['name'] ?? (string) $f['name'], 'list' => $typed['list'] ?? ''],
            'result' => $result === null ? null : [
                'added' => $result['added'], 'removed' => $result['removed'],
                'skipped' => array_map(static fn (array $s): array => ['text' => (string) $s['text'], 'sku_id' => $s['sku_id'], 'why' => $s['reason'] === 'in_other_family'
                    ? Words::say('FAMILY_SKIP', 'in_other_family', (string) ($s['other'] ?? '')) : Words::of('FAMILY_SKIP', (string) $s['reason'])], $result['skipped']),
            ],
            'lookOnly' => $can ? null : Words::whoCan('families.manage'),
            'error' => $error === null ? null : self::plain($error),
            'errorCode' => $error?->errorCode,
        ], $status, ['title' => (string) $f['name'], 'notice' => $notice]);
    }

    /** A refusal of the pages of pack B in words, by its code; a code without words keeps the service's message, saying nothing was saved. */
    public static function plain(CwException $e): string
    {
        $code = $e->errorCode;
        return match (true) {
            $code === 'too_many_rows' => Words::say('ITEMS_ERROR', 'too_many_rows', (int) ($e->detail['max'] ?? 0)),
            isset(Words::ITEMS_ERROR[$code]) && !str_contains(Words::ITEMS_ERROR[$code], '%s') => Words::ITEMS_ERROR[$code],
            isset(Words::ERROR[$code]) && !str_contains(Words::ERROR[$code], '%') => Words::ERROR[$code],
            isset(Words::CARD_ERROR[$code]) && !str_contains(Words::CARD_ERROR[$code], '%s') => Words::CARD_ERROR[$code],
            default => Words::say('BUY_ERROR', 'other', PurchaseOrdersController::sentence($e->getMessage())),
        };
    }

    /** The version the form was drawn with (400 bad_version when it sends none). */
    private static function version(Context $ctx): int
    {
        $v = UiRequest::id($ctx->req->field('version'));
        if ($v === null) {
            throw new CwException('bad_version', Words::ERROR['bad_version'] ?? 'the form has no version: reload the page', 400);
        }
        return $v;
    }
}
