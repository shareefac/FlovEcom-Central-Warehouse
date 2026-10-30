<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\Items\Families;
use CW\Items\Packs;
use CW\OpResult;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Products › Multipacks (pack B; owner answer Q3; docs/decisions.md PB6-PB8). Two segments:
 *  - Pack items (/ui/items/packs, catalogue.view): the pre-packed multipack items, each "1 pack = N singles of" its single item, with
 *    both items' stock, what the page finds to check (CW\Items\Packs::FLAGS), and the owner's rule: the pack item's variants are suggested
 *    as N singles a sale only while the single is sold on its own (items.pack_suggest_single_sold), as links to each variant's page where
 *    a person decides (the matching's rules, unchanged). The catalogue team (catalogue.edit) defines a pack item (a form key: one however
 *    often it is sent) and switches one off or on; never deleted.
 *  - Pack links (/ui/items/packs/links, linking.view): every variant matched with more than 1 unit a sale, and what the pack items say
 *    of it. Read only.
 */
final class PacksController
{
    public const PATH = '/ui/items/packs';
    public const LINKS_PATH = '/ui/items/packs/links';

    /**
     * @param array<string, string> $typed what a refused create form sent
     */
    public function index(Context $ctx, int $status = 200, ?CwException $error = null, array $typed = []): HtmlResponse
    {
        $req = $ctx->req;
        $q = mb_substr(trim($req->param('q') ?? ''), 0, 100);
        $off = $req->param('off') === '1';
        $page = max(1, min(10_000, UiRequest::id($req->param('page')) ?? 1));
        $list = (new Packs($ctx->db))->list($off, $q, $page);
        $me = $ctx->me();
        $can = $me->can('catalogue.edit');
        $base = ['q' => $q === '' ? null : $q, 'off' => $off ? '1' : null];
        $notice = $req->param('notice');
        $rows = array_map(fn (array $r): array => $this->row($r), $list['rows']);
        return $ctx->page('packs', [
            'rows' => $rows,
            // The board (wave w11, docs/decisions.md W11.6): in use, then switched off (shown with ?off=1).
            'groups' => Html::groups($rows, ['on' => ['done', Words::PACKS['on']], 'off' => ['off', Words::PACKS['off']]]),
            'total' => $list['total'],
            'q' => $q,
            'off' => $off,
            'filtered' => $q !== '' || $off,
            'page_no' => $list['page'],
            'pages' => $list['pages'],
            'prev_link' => $list['page'] > 1 ? Html::url(self::PATH, $base + ['page' => $list['page'] - 1]) : null,
            'next_link' => $list['page'] < $list['pages'] ? Html::url(self::PATH, $base + ['page' => $list['page'] + 1]) : null,
            'canEdit' => $can,
            'canLink' => $me->can('linking.view'),
            'canRepack' => $me->can('doc.RPK.post'),
            'formKey' => $can ? ($typed['form_key'] ?? FormOnce::newKey()) : null,
            'typed' => ['pack' => $typed['pack'] ?? '', 'single' => $typed['single'] ?? '', 'units' => $typed['units'] ?? '', 'note' => $typed['note'] ?? ''],
            'lookOnly' => $can ? null : Words::whoCan('catalogue.edit'),
            'error' => $error === null ? null : FamiliesController::plain($error),
            'errorCode' => $error?->errorCode,
            'errorField' => is_string($error?->detail['field'] ?? null) ? (string) $error->detail['field'] : null,
        ], $status, ['title' => Words::title('packs'), 'notice' => $notice !== null ? (Words::ITEMS_NOTICE[$notice] ?? null) : null]);
    }

    public function create(Context $ctx): HtmlResponse
    {
        $typed = ['pack' => trim($ctx->req->field('pack') ?? ''), 'single' => trim($ctx->req->field('single') ?? ''), 'units' => trim($ctx->req->field('units') ?? ''),
            'note' => $ctx->req->field('note') ?? ''];
        try {
            $pack = Families::resolveOne($ctx->db, $typed['pack']) ?? throw new CwException('unknown_item', 'no item has that CW number or barcode', 422, ['field' => 'pack']);
            $single = Families::resolveOne($ctx->db, $typed['single']) ?? throw new CwException('unknown_item', 'no item has that CW number or barcode', 422, ['field' => 'single']);
            if (preg_match('/^[0-9]{1,5}$/D', $typed['units']) !== 1) {
                throw new CwException('bad_units', 'singles in one pack is a whole number', 422, ['field' => 'units']);
            }
            $units = (int) $typed['units'];
            $r = FormOnce::run($ctx, 'ui.pack.create', $typed, static function (Db $db) use ($ctx, $pack, $single, $units, $typed): OpResult {
                $p = (new Packs($db))->create($ctx->caller(), $pack, $single, $units, $typed['note']);
                return OpResult::of(303, ['id' => $p['id'], 'redirect' => Html::url(self::PATH, ['notice' => 'pack_made'])]);
            });
        } catch (CwException $e) {
            $key = $ctx->req->field(FormOnce::FIELD);
            return $this->index($ctx, $e->httpStatus, $e, $typed + ['form_key' => in_array($e->errorCode, ['bad_form_key', 'idempotency_key_reused'], true)
                || $key === null ? FormOnce::newKey() : $key]);
        }
        return FormOnce::redirect($r);
    }

    public function switch(Context $ctx): HtmlResponse
    {
        $on = $ctx->req->field('on') === '1';
        try {
            $r = (new Packs($ctx->db))->setActive($ctx->caller(), $ctx->id(), $on);
        } catch (CwException $e) {
            return $this->index($ctx, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url(self::PATH, ['notice' => !$r['changed'] ? 'unchanged' : ($on ? 'pack_on' : 'pack_off'), 'off' => $on ? null : '1']));
    }

    public function links(Context $ctx): HtmlResponse
    {
        $page = max(1, min(10_000, UiRequest::id($ctx->req->param('page')) ?? 1));
        $list = (new Packs($ctx->db))->links($page);
        $rows = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'store' => (string) $r['channel_name'], 'variant' => trim(((string) ($r['product_title'] ?? '')) . ' ' . ((string) ($r['variant_title'] ?? ''))),
                'option' => (string) $r['external_variant_id'], 'units' => (int) $r['units_per_item'], 'item' => ['id' => (int) $r['sku_id'], 'code' => (string) $r['sku_code'],
                    'name' => (string) $r['sku_name']],
                'flags' => array_map(static fn (string $f): array => ['tone' => Words::tone('PACK_LINK_FLAG', $f), 'text' => $f === 'pack_item_exists'
                    ? Words::say('PACK_LINK_FLAG', $f, (string) ($r['pack_item']['code'] ?? '')) : Words::of('PACK_LINK_FLAG', $f)], $r['flags']),
                'pack' => $r['pack_item'],
                'group' => $r['flags'] === [] ? 'fine' : 'check',
            ], $list['rows']);
        return $ctx->page('pack_links', [
            'rows' => $rows,
            // The board (wave w11, docs/decisions.md W11.7): the variants something needs a look at first.
            'groups' => Html::groups($rows, ['check' => ['needs', Words::PACKS['group_check']], 'fine' => ['done', Words::PACKS['group_fine']]]),
            'total' => $list['total'],
            'page_no' => $list['page'],
            'pages' => $list['pages'],
            'prev_link' => $list['page'] > 1 ? Html::url(self::LINKS_PATH, ['page' => $list['page'] - 1]) : null,
            'next_link' => $list['page'] < $list['pages'] ? Html::url(self::LINKS_PATH, ['page' => $list['page'] + 1]) : null,
        ], 200, ['title' => Words::title('pack_links')]);
    }

    /** @param array<string, mixed> $r Packs::list() row @return array<string, mixed> what the page draws */
    private function row(array $r): array
    {
        $units = (int) $r['units'];
        return [
            'id' => (int) $r['id'], 'active' => (int) $r['is_active'] === 1, 'units' => $units, 'group' => (int) $r['is_active'] === 1 ? 'on' : 'off',
            'pack' => ['id' => (int) $r['pack_sku_id'], 'code' => (string) $r['pack_code'], 'name' => (string) $r['pack_name']],
            'single' => ['id' => (int) $r['single_sku_id'], 'code' => (string) $r['single_code'], 'name' => (string) $r['single_name']],
            'stock' => Words::say('PACKS', 'stock_line', (int) $r['on_hand']['pack'], (int) $r['on_hand']['single']),
            'flags' => array_values(array_map(static fn (string $f): array => ['tone' => Words::tone('PACK_FLAG', $f), 'text' => Words::of('PACK_FLAG', $f)],
                array_filter($r['flags'], static fn (string $f): bool => $f !== 'suggest'))),
            'suggest' => in_array('suggest', $r['flags'], true) ? array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'store' => (string) $l['channel_name'],
                'title' => trim(((string) ($l['product_title'] ?? '')) . ' ' . ((string) ($l['variant_title'] ?? ''))) ?: (string) $l['external_variant_id'],
                'href' => Html::url('/ui/review/listing/' . (int) $l['id'], ['pick' => (int) $r['single_sku_id']]) . '#decide'], $r['suggest_listings']) : [],
            'ownProduct' => !in_array('suggest', $r['flags'], true) && (int) $r['links']['pack_u1'] > 0 && !in_array('single_sold', $r['flags'], true),
            'note' => $r['note'] === null ? null : (string) $r['note'],
        ];
    }
}
