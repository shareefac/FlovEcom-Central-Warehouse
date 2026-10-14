<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\OpResult;
use CW\Reorder\LowStockLevels;
use CW\Reorder\ReorderLines;
use CW\Reorder\ReorderList;
use CW\Reorder\ReorderSettings;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;
use CW\Ui\Words;

/**
 * Pack G's pages inside Purchasing › Reorder (docs/decisions.md PG6, PG7, PG9):
 *
 *   Suppliers           (/ui/purchasing/reorder/suppliers)  each supplier's delivery days and order cycle (set on the supplier's own page)
 *                       and its spare days of stock for the items it is the main supplier of; reorder.manage changes the spare days
 *                       (the version the form was drawn with: 409 redraws the current values; 422 keeps what was typed).
 *   Low-stock warnings  (/ui/purchasing/reorder/low-stock)  every listed item with its safety stock and the low-stock figure CW keeps for the
 *                       websites (or "each website's own"), from the stored list; filters brand, main supplier, words, below the safety
 *                       stock, set / own; reorder.manage ticks rows and either uses the safety stock as the figure or lets each website keep
 *                       its own (FormOnce: the same form twice is one change; skip-and-list, LowStockLevels).
 *   Work out again      (POST /ui/purchasing/reorder/refresh, reorder.manage)  the stored list worked out now (ReorderController::refreshAll).
 * Every POST carries the CSRF field (the kernel refuses it without) and is refused to a person without reorder.manage (the route's permission).
 */
final class ReorderEngineController
{
    public const NOTICES = Words::REORDER_ENGINE_NOTICE;
    public const SKIPPED_IN_URL = 50;

    // ------------------------------------------------------------------------------------------
    // Suppliers
    // ------------------------------------------------------------------------------------------

    public function suppliers(Context $ctx): HtmlResponse
    {
        $id = UiRequest::id($ctx->req->param('supplier'));
        return $this->suppliersPage($ctx, $id, 200, null, null, self::NOTICES[$this->notice($ctx) ?? ''] ?? null);
    }

    public function saveSupplier(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $id = UiRequest::id($req->field('supplier_id'));
        $fields = ['safety_days' => $req->field('safety_days'), 'note' => $req->field('note')];
        try {
            $version = $req->field('version');
            if ($id === null || $version === null || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $version) !== 1) {
                throw new CwException('bad_version', Words::ERROR['bad_version'], 400);
            }
            (new ReorderSettings($ctx->db))->saveSupplier($ctx->caller(), $id, (int) $version, $fields);
            (new ReorderController())->refreshSome($ctx, array_map('intval', $ctx->db->column('SELECT sku_id FROM reorder_line WHERE supplier_id = ? AND listed = 1', [$id])));
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->suppliersPage($ctx, $id, 409, ReorderController::plain($e), null, null);
            }
            return $this->suppliersPage($ctx, $id, $e->httpStatus, $e->errorCode === 'bad_version' ? $e->getMessage() : ReorderController::plain($e), $fields, null);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/reorder/suppliers', ['supplier' => (string) $id, 'notice' => 'supplier_saved']));
    }

    /** @param array<string, ?string>|null $typed */
    private function suppliersPage(Context $ctx, ?int $id, int $status, ?string $error, ?array $typed, ?string $notice): HtmlResponse
    {
        $rows = $ctx->db->all(
            'SELECT sup.id, sup.code, sup.name, sup.status, sup.default_lead_days, sup.review_days, '
            . '(SELECT COUNT(*) FROM supplier_item si WHERE si.supplier_id = sup.id AND si.preferred_sku_id IS NOT NULL) AS items, '
            . 'rs.safety_days, rs.note, rs.version, rs.updated_at, u.display_name AS updated_by_name FROM supplier sup '
            . 'LEFT JOIN reorder_supplier rs ON rs.supplier_id = sup.id LEFT JOIN staff_user u ON u.id = rs.updated_by '
            . "WHERE sup.status <> 'inactive' OR rs.supplier_id IS NOT NULL ORDER BY sup.name, sup.id");
        $current = null;
        foreach ($rows as $r) {
            if ($id !== null && (int) $r['id'] === $id) {
                $current = $r;
            }
        }
        $p = ReorderSettings::params($ctx->settings());
        $canManage = $ctx->me()->can('reorder.manage');
        return $ctx->page('reorder_suppliers', [
            'rows' => $rows,
            'current' => $current,
            'version' => $current === null || $current['version'] === null ? 0 : (int) $current['version'],
            'typed' => $typed,
            'defaults' => ['lead' => $p['lead'], 'review' => $p['review'], 'safety' => $p['safety']],
            'canManage' => $canManage,
            'error' => $error,
            'lookOnly' => $canManage ? null : Words::whoCan('reorder.manage'),
        ], $status, ['title' => Words::title('reorder_suppliers'), 'active' => 'reorder', 'notice' => $notice]);
    }

    // ------------------------------------------------------------------------------------------
    // Low-stock warnings
    // ------------------------------------------------------------------------------------------

    public function lowStock(Context $ctx): HtmlResponse
    {
        return $this->lowStockPage($ctx, self::filters(fn (string $k): ?string => $ctx->req->param($k)), 200, null, self::NOTICES[$this->notice($ctx) ?? ''] ?? null);
    }

    /** POST /ui/purchasing/reorder/low-stock: the ticked rows -> LowStockLevels::apply (FormOnce). */
    public function saveLowStock(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $f = self::filters(fn (string $k): ?string => $req->field($k));
        $action = $req->field('action');
        $skus = [];
        foreach ($req->fieldsMatching('/^pick_[1-9][0-9]{0,9}$/') as $name => $v) {
            if ($v === '1') {
                $skus[] = (int) substr($name, 5);
            }
        }
        sort($skus);
        try {
            if (!in_array($action, LowStockLevels::ACTIONS, true)) {
                throw new CwException('bad_action', Words::REORDER_ENGINE_ERROR['bad_action'], 400);
            }
            $r = FormOnce::run($ctx, 'ui.reorder.low_stock', ['action' => $action, 'skus' => $skus], function (Db $db) use ($ctx, $action, $skus, $f): OpResult {
                $c = new ReorderController();
                $res = (new LowStockLevels($db, new ReorderList($db, $c->purchaseOrders($ctx), $ctx->settings()), $c->refresher($ctx), $ctx->settings()))
                    ->apply($ctx->caller(), (string) $action, $skus);
                $skipped = array_slice(array_map(static fn (array $s): string => $s['sku_id'] . ':' . $s['why'], $res['skipped']), 0, self::SKIPPED_IN_URL);
                return OpResult::of(303, ['result' => 'low_stock', 'changed' => array_column($res['changed'], 'sku_id'), 'skipped' => array_column($res['skipped'], 'sku_id'),
                    'redirect' => Html::url('/ui/purchasing/reorder/low-stock', ['notice' => 'low_saved', 'changed' => (string) count($res['changed']),
                        'skipped' => $skipped === [] ? null : implode(',', $skipped),
                        'skipped_n' => count($res['skipped']) > self::SKIPPED_IN_URL ? (string) count($res['skipped']) : null] + self::query($f))]);
            });
        } catch (CwException $e) {
            return $this->lowStockPage($ctx, $f, $e->httpStatus, ReorderController::plain($e), null);
        }
        return FormOnce::redirect($r);
    }

    /**
     * @param array<string, mixed> $f
     */
    private function lowStockPage(Context $ctx, array $f, int $status, ?string $error, ?string $notice): HtmlResponse
    {
        $stored = new ReorderLines($ctx->db);
        $page = max(1, (int) (UiRequest::id($ctx->req->param('page') ?? $ctx->req->field('page')) ?? 1));
        $res = $stored->page($f, $page, ReorderList::PAGE);
        $pages = max(1, intdiv($res['total'] + ReorderList::PAGE - 1, ReorderList::PAGE));
        if ($page > $pages) {
            $page = $pages;
            $res = $stored->page($f, $page, ReorderList::PAGE);
        }
        $canManage = $ctx->me()->can('reorder.manage');
        $rows = array_map(static fn (array $l): array => $l + [
            'per_day' => ReorderList::perDay((int) $l['d_e6']),
            'safety_line' => Words::say('REORDER_LOW', 'safety_line', (int) $l['safety'], ReorderList::perDay((int) $l['d_e6'])),
        ], $res['lines']);
        return $ctx->page('reorder_low_stock', [
            'rows' => $rows,
            'total' => $res['total'],
            'page' => $page,
            'pages' => $pages,
            'filters' => $f,
            'filtered' => $f['brand'] !== null || $f['supplier'] !== null || $f['q'] !== '' || $f['below'] || $f['low'] !== 'any',
            'brands' => $stored->brands(),
            'suppliers' => (new ReorderList($ctx->db, (new ReorderController())->purchaseOrders($ctx), $ctx->settings()))->suppliers(),
            'writers' => $ctx->db->all("SELECT code, name FROM channel WHERE site_writer = 1 AND mode = 'live' ORDER BY name"),
            'figures' => ReorderController::figures($ctx),
            'canManage' => $canManage,
            'bulkMax' => ReorderSettings::engine($ctx->settings())['low_stock_bulk_max'],
            'formKey' => $canManage ? ($error !== null ? ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey()) : FormOnce::newKey()) : null,
            'error' => $error,
            'done' => $this->notice($ctx) === 'low_saved' ? $this->done($ctx) : null,
            'lookOnly' => $canManage ? null : Words::whoCan('reorder.manage'),
            'query' => self::query($f),
        ], $status, ['title' => Words::title('reorder_low_stock'), 'active' => 'reorder', 'notice' => $notice]);
    }

    /**
     * What a low-stock click did (from the redirect, the skipped items re-read for their names).
     *
     * @return array{changed: int, skipped: list<array{sku_id: int, code: string, name: string, reason: string}>, more: int}
     */
    private function done(Context $ctx): array
    {
        $skipped = [];
        $why = [];
        foreach (explode(',', (string) $ctx->req->param('skipped')) as $part) {
            if (preg_match('/^([1-9][0-9]{0,9}):([a-z_]{1,16})$/D', trim($part), $m) === 1 && in_array($m[2], LowStockLevels::SKIP, true)) {
                $why[(int) $m[1]] = $m[2];
            }
        }
        $why = array_slice($why, 0, self::SKIPPED_IN_URL, true);
        if ($why !== []) {
            $names = [];
            foreach ($ctx->db->all('SELECT id, code, name FROM sku WHERE id IN (' . implode(', ', array_fill(0, count($why), '?')) . ')', array_keys($why)) as $r) {
                $names[(int) $r['id']] = $r;
            }
            foreach ($why as $id => $w) {
                if (isset($names[$id])) {
                    $skipped[] = ['sku_id' => $id, 'code' => (string) $names[$id]['code'], 'name' => (string) $names[$id]['name'], 'reason' => Words::of('REORDER_LOW_SKIP', $w)];
                }
            }
        }
        $more = UiRequest::id($ctx->req->param('skipped_n'));
        return ['changed' => max(0, (int) (UiRequest::id($ctx->req->param('changed')) ?? 0)), 'skipped' => $skipped,
            'more' => $more === null ? 0 : max(0, $more - count($skipped))];
    }

    // ------------------------------------------------------------------------------------------
    // Work out the list again
    // ------------------------------------------------------------------------------------------

    /**
     * POST /ui/purchasing/reorder/refresh (reorder.manage): the stored list worked out now while it holds at most
     * reorder.refresh_inline_max_items items; a longer list: the products of the page the person was looking at (the form's `items`,
     * at most a page), the rest at the next scheduled run.
     */
    public function refresh(Context $ctx): HtmlResponse
    {
        $ids = [];
        foreach (explode(',', (string) $ctx->req->field('items')) as $x) {
            $id = UiRequest::id(trim($x));
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }
        $ids = array_slice(array_values($ids), 0, ReorderList::PAGE);
        $notice = 'refreshed';
        try {
            (new ReorderSettings($ctx->db))->manager($ctx->caller());
            $c = new ReorderController();
            try {
                $c->refreshAll($ctx);
            } catch (CwException $e) {
                if ($e->errorCode !== 'refresh_later' || $ids === []) {
                    throw $e;
                }
                $c->refresher($ctx)->items($ids);
                $notice = 'refreshed_page';
            }
        } catch (CwException $e) {
            return $ctx->error($e->httpStatus, $e->errorCode, ReorderController::plain($e), ['/ui/purchasing/reorder', Words::MENU['reorder']]);
        }
        $back = $ctx->req->field('back') === 'low-stock' ? '/ui/purchasing/reorder/low-stock' : '/ui/purchasing/reorder';
        return HtmlResponse::redirect(Html::url($back, ['notice' => $notice]));
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    /**
     * The filters of Low-stock warnings: the list's (every product, CW stock) and `low`.
     *
     * @param \Closure(string): ?string $get
     * @return array<string, mixed>
     */
    public static function filters(\Closure $get): array
    {
        $f = ReorderList::filters(['brand' => $get('brand'), 'supplier' => $get('supplier'), 'q' => $get('q'), 'below' => $get('below'), 'show' => 'all', 'stock' => 'cw']);
        $low = $get('low');
        $f['low'] = in_array($low, ReorderLines::LOW, true) ? (string) $low : 'any';
        return $f;
    }

    /**
     * @param array<string, mixed> $f
     * @return array<string, ?string>
     */
    private static function query(array $f): array
    {
        return ['brand' => $f['brand'], 'supplier' => $f['supplier'] === null ? null : (string) $f['supplier'], 'q' => $f['q'] === '' ? null : (string) $f['q'],
            'below' => $f['below'] ? '1' : null, 'low' => $f['low'] === 'any' ? null : (string) $f['low']];
    }

    private function notice(Context $ctx): ?string
    {
        $n = $ctx->req->param('notice');
        return $n !== null && isset(self::NOTICES[$n]) ? $n : null;
    }
}
