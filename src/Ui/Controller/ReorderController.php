<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Audit;
use CW\CwException;
use CW\Db;
use CW\Documents\Documents;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Reorder\DemandBuilder;
use CW\Reorder\DemandMath;
use CW\Reorder\DraftPos;
use CW\Reorder\Explain;
use CW\Reorder\ReorderList;
use CW\Reorder\ReorderSettings;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;

/**
 * The reorder list and its settings (IM9 basic, Phase I-2; spec §7.4, §7.5, §8.1; docs/decisions.md I68): the list (filters
 * brand, preferred supplier, words, urgent only, need / all, CW or site stock; 200 lines a page; a "Why" per line; CSV), the
 * "create draft PO" form, Recalculate, an item's demand day by day with its settings, the brands' factor and safety days, the
 * anomaly windows.
 *
 * reorder.view looks (buyers, purchasing managers, reviewers, auditors, managers); reorder.manage changes settings and
 * recalculates (buyers, purchasing managers); doc.PO.post makes drafts. The draft form carries `form_key` (FormOnce: the same
 * form twice gives the same drafts) and `row_count` FIRST: fewer `packs_<sku>` fields than it says is 400 form_truncated (PHP
 * drops fields past max_input_vars = 1000); a page holds at most 200 lines. Settings forms carry the version they were drawn
 * with (409: the page is redrawn with the current values).
 */
final class ReorderController
{
    public const NOTICES = [
        'recalculated' => 'Demand recalculated from the loaded sales history.',
        'drafts' => 'Draft purchase orders created from the ticked lines: open each one, check it and approve it.',
        'item_saved' => 'Item settings saved: the list uses them at once.',
        'brand_saved' => 'Brand settings saved: the list uses them at once.',
        'anomaly_added' => 'Window added: it is excluded from the demand at the next recalculation (Recalculate on the reorder list).',
        'anomaly_ended' => 'Window ended: its days count as demand again at the next recalculation.',
    ];
    public const FLAG_TEXT = ['urgent' => 'urgent', 'no_supplier' => 'no preferred supplier', 'supplier_draft' => 'supplier not approved yet',
        'supplier_pending_approval' => 'supplier waiting for approval', 'supplier_inactive' => 'supplier inactive', 'no_price' => 'no last price', 'merged' => 'merged',
        'do_not_reorder' => 'do not reorder', 'no_history' => 'no sales history', 'site_stock_unreliable' => 'site stock not reliable',
        'in_draft' => 'already in a draft order'];
    public const SKIPPED_IN_URL = 50;
    /** The most linked items "Recalculate" rebuilds inside a UI request (I84); more: bin/reorder_demand.php. */
    public const UI_REBUILD_MAX_ITEMS = 3000;

    // ------------------------------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------------------------------

    public function index(Context $ctx): HtmlResponse
    {
        return $this->listPage($ctx, ReorderList::filters($this->query($ctx->req)), 200, null);
    }

    public function csv(Context $ctx): HtmlResponse
    {
        $list = $this->list($ctx);
        $csv = new CsvWriter([['item', 'text'], ['name', 'text'], ['brand', 'text'], ['supplier', 'text'], ['supplier_code', 'text'], ['demand_per_day', 'number'],
            ['rate', 'number'], ['rate_raw_30', 'number'], ['factor', 'number'], ['lead_days', 'number'], ['review_days', 'number'], ['safety_days', 'number'],
            ['cover_days', 'number'], ['target', 'number'], ['reorder_point', 'number'], ['stock_source', 'text'], ['available', 'number'], ['on_order', 'number'],
            ['in_drafts', 'number'], ['need', 'number'], ['packs', 'number'], ['units_per_pack', 'number'], ['purchase_unit', 'text'], ['units', 'number'],
            ['pack_price', 'number'], ['value', 'number'], ['cover_now_days', 'text'], ['urgent', 'text'], ['flags', 'text'], ['why', 'text']]);
        $f = ReorderList::filters($this->query($ctx->req));
        foreach (array_chunk($list->lines($f), 1000) as $chunk) {
            foreach ($list->explain($chunk) as $l) {
                $csv->add([$l['code'], $l['name'], $l['brand'], $l['supplier'], $l['supplier_code'], DemandMath::e4(DemandMath::halfUpDiv($l['d_e6'], 100)),
                    DemandMath::e4($l['rate_e4']), DemandMath::e4($l['rate_raw_30_e4']), intdiv($l['factor_e2'], 100) . '.' . str_pad((string) ($l['factor_e2'] % 100), 2, '0', STR_PAD_LEFT),
                    $l['lead'], $l['review'], $l['safety'], $l['cover_days'], $l['target'], $l['rop'], $l['stock_source'], $l['available'], $l['on_order'], $l['in_drafts'],
                    $l['need'], $l['packs'], $l['upp'], $l['purchase_unit'], $l['units'], $l['pack_price'], ReorderList::amount($l['value_e4']),
                    $l['cover_now_e1'] === null ? '' : ReorderList::cover($l['cover_now_e1']), $l['urgent'] ? 'yes' : 'no', implode(' ', $l['flags']), $l['explain']]);
            }
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', 'reorder.csv');
    }

    /** POST /ui/purchasing/reorder/draft: the ticked lines -> one draft PO per preferred supplier (FormOnce). */
    public function draft(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $f = ReorderList::filters($this->formFilters($req));
        $count = $req->field('row_count');
        if ($count === null || preg_match('/^(0|[1-9][0-9]{0,3})$/D', $count) !== 1) {
            return $ctx->error(400, 'form_truncated', 'the form arrived incomplete (no line count): nothing was ordered. Reload the page and try again.');
        }
        $count = (int) $count;
        $rows = $req->fieldsMatching('/^packs_[1-9][0-9]{0,9}$/');
        if ($count > ReorderList::PAGE || count($rows) < $count) {
            return $ctx->error(400, 'form_truncated', "the form arrived incomplete ({$count} lines sent, " . count($rows) . ' arrived): nothing was ordered. '
                . 'Narrow the list (a brand or a supplier) and try again.');
        }
        $picks = [];
        try {
            foreach ($rows as $name => $packs) {
                $sku = (int) substr($name, 6);
                if ($req->field("pick_{$sku}") !== '1') {
                    continue;
                }
                $p = trim($packs);
                if (preg_match('/^\d{1,7}$/D', $p) !== 1) {
                    throw new CwException('bad_pick', 'packs: a whole number (0 leaves the line out)', 422);
                }
                $picks[] = ['sku_id' => $sku, 'packs' => (int) $p];
            }
            usort($picks, static fn (array $a, array $b): int => $a['sku_id'] <=> $b['sku_id']);
            $r = FormOnce::run($ctx, 'ui.reorder.draft', ['picks' => $picks, 'stock' => $f['stock']], function (Db $db) use ($ctx, $picks, $f): OpResult {
                $res = $this->draftPos($ctx)->create($ctx->caller(), $picks, $f['stock'], $ctx->req->field(FormOnce::FIELD));
                if ($res['drafts'] === []) {
                    throw new CwException('nothing_ordered', 'No draft was made: ' . implode('; ', array_map(static fn (array $s): string => "{$s['code']}: {$s['reason']}",
                        array_slice($res['skipped'], 0, 10))) . (count($res['skipped']) > 10 ? '; ...' : ''), 422);
                }
                $redirect = count($res['drafts']) === 1 && $res['skipped'] === []
                    ? Html::url('/ui/purchasing/orders/' . $res['drafts'][0]['document_id'], ['notice' => 'created'])
                    : Html::url('/ui/purchasing/reorder', ['notice' => 'drafts', 'drafts' => implode(',', array_column($res['drafts'], 'document_id')),
                        'skipped' => implode(',', array_slice(array_column($res['skipped'], 'sku_id'), 0, self::SKIPPED_IN_URL)),
                        'skipped_n' => count($res['skipped']) > self::SKIPPED_IN_URL ? (string) count($res['skipped']) : null,
                        'brand' => $f['brand'], 'supplier' => $f['supplier'], 'stock' => $f['stock'] === 'cw' ? null : $f['stock']]);
                return OpResult::of(303, ['result' => 'drafts', 'drafts' => array_column($res['drafts'], 'document_id'), 'skipped' => array_column($res['skipped'], 'sku_id'),
                    'redirect' => $redirect]);
            });
        } catch (CwException $e) {
            return $this->listPage($ctx, $f, $e->httpStatus, $e->getMessage());
        }
        return FormOnce::redirect($r);
    }

    /**
     * POST /ui/purchasing/reorder/recalculate (reorder.manage): rebuild the demand now, when it is small enough to finish
     * inside the UI pool's limits (max_execution_time 50 s, request_terminate_timeout 60 s): at most UI_REBUILD_MAX_ITEMS
     * linked items (review finding, I84: 15,000 items took 49 s in a test, and a killed worker rebuilds nothing). A larger
     * catalogue is rebuilt by bin/reorder_demand.php (409 rebuild_on_server, saying so).
     */
    public function recalculate(Context $ctx): HtmlResponse
    {
        try {
            (new ReorderSettings($ctx->db))->manager($ctx->caller());
            $items = (int) $ctx->db->value("SELECT COUNT(DISTINCT sku_id) FROM channel_listing WHERE status = 'mapped'");
            if ($items > self::UI_REBUILD_MAX_ITEMS) {
                throw new CwException('rebuild_on_server', 'The demand of ' . number_format($items) . ' linked items is too big to rebuild from this page (at most '
                    . number_format(self::UI_REBUILD_MAX_ITEMS) . '): it is rebuilt on the server by bin/reorder_demand.php (after each sales import, and nightly '
                    . 'once that job is installed). The list keeps using the demand computed last.', 409);
            }
            $b = (new DemandBuilder($ctx->db, $ctx->settings()))->rebuild();
        } catch (CwException $e) {
            return $this->listPage($ctx, ReorderList::filters($this->formFilters($ctx->req)), $e->httpStatus, $e->getMessage());
        }
        Audit::write($ctx->db, $ctx->caller(), 'reorder.recalculate', null, null, null, ['items' => $b['items'], 'listings' => $b['listings'], 'ms' => $b['ms'],
            'channels' => $b['channels']]);
        return HtmlResponse::redirect(Html::url('/ui/purchasing/reorder', ['notice' => 'recalculated']));
    }

    // ------------------------------------------------------------------------------------------
    // One item
    // ------------------------------------------------------------------------------------------

    public function item(Context $ctx): HtmlResponse
    {
        return $this->itemPage($ctx, $ctx->id(), 200, null, null, self::NOTICES[$this->notice($ctx) ?? ''] ?? null);
    }

    public function saveItem(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $version = $req->field('version');
        $fields = [];
        foreach (ReorderSettings::ITEM_FIELDS as $k) {
            $fields[$k] = $req->field($k);
        }
        try {
            if ($version === null || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $version) !== 1) {
                throw new CwException('bad_version', 'this form has no version: reload the page', 400);
            }
            (new ReorderSettings($ctx->db))->saveItem($ctx->caller(), $id, (int) $version, $fields);
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->itemPage($ctx, $id, 409, 'These settings were changed since you opened them (here are the current ones): make your change again.', null, null);
            }
            return $this->itemPage($ctx, $id, $e->httpStatus, $e->getMessage(), $fields, null);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/reorder/items/' . $id, ['notice' => 'item_saved']));
    }

    // ------------------------------------------------------------------------------------------
    // Brands
    // ------------------------------------------------------------------------------------------

    public function brands(Context $ctx): HtmlResponse
    {
        $brand = trim($ctx->req->param('brand') ?? '');
        return $this->brandsPage($ctx, $brand === '' ? null : mb_substr($brand, 0, 128), 200, null, null, self::NOTICES[$this->notice($ctx) ?? ''] ?? null);
    }

    public function saveBrand(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $brand = trim($req->field('brand') ?? '');
        $fields = ['demand_factor' => $req->field('demand_factor'), 'safety_days' => $req->field('safety_days'), 'note' => $req->field('note')];
        try {
            $version = $req->field('version');
            if ($version === null || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $version) !== 1) {
                throw new CwException('bad_version', 'this form has no version: reload the page', 400);
            }
            $row = (new ReorderSettings($ctx->db))->saveBrand($ctx->caller(), $brand, (int) $version, $fields);
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->brandsPage($ctx, $brand, 409, 'The settings of this brand were changed since you opened them (here are the current ones): make your change again.',
                    null, null);
            }
            return $this->brandsPage($ctx, $brand === '' ? null : mb_substr($brand, 0, 128), $e->httpStatus, $e->getMessage(), $fields, null);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/reorder/brands', ['brand' => (string) $row['brand'], 'notice' => 'brand_saved']));
    }

    // ------------------------------------------------------------------------------------------
    // Anomalies
    // ------------------------------------------------------------------------------------------

    public function anomalies(Context $ctx): HtmlResponse
    {
        return $this->anomaliesPage($ctx, 200, null, null, self::NOTICES[$this->notice($ctx) ?? ''] ?? null);
    }

    public function addAnomaly(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $fields = [];
        foreach (['date_from', 'date_to', 'channel_id', 'brand', 'label'] as $k) {
            $fields[$k] = $req->field($k);
        }
        try {
            $r = FormOnce::run($ctx, 'ui.reorder.anomaly_add', $fields, static function (Db $db) use ($ctx, $fields): OpResult {
                $a = (new ReorderSettings($db))->addAnomaly($ctx->caller(), $fields);
                return OpResult::of(303, ['result' => 'anomaly_added', 'anomaly_id' => (int) $a['id'],
                    'redirect' => Html::url('/ui/purchasing/reorder/anomalies', ['notice' => 'anomaly_added'])]);
            });
        } catch (CwException $e) {
            return $this->anomaliesPage($ctx, $e->httpStatus, $e->getMessage(), $fields, null);
        }
        return FormOnce::redirect($r);
    }

    public function endAnomaly(Context $ctx): HtmlResponse
    {
        try {
            (new ReorderSettings($ctx->db))->endAnomaly($ctx->caller(), $ctx->id());
        } catch (CwException $e) {
            return $this->anomaliesPage($ctx, $e->httpStatus, $e->getMessage(), null, null);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/reorder/anomalies', ['notice' => 'anomaly_ended']));
    }

    // ------------------------------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------------------------------

    /** @param array{brand: ?string, supplier: ?int, q: string, urgent: bool, show: string, stock: string} $f */
    private function listPage(Context $ctx, array $f, int $status, ?string $error): HtmlResponse
    {
        $list = $this->list($ctx);
        $all = $list->lines($f);
        $pages = max(1, intdiv(count($all) + ReorderList::PAGE - 1, ReorderList::PAGE));
        $page = min($pages, max(1, (int) (UiRequest::id($ctx->req->param('page') ?? $ctx->req->field('page')) ?? 1)));
        $rows = array_map(static fn (array $l): array => $l + ['show_per_day' => ReorderList::perDay($l['d_e6']), 'show_value' => ReorderList::money($l['value_e4']),
            'show_cover' => ReorderList::cover($l['cover_now_e1'])], $list->explain(array_slice($all, ($page - 1) * ReorderList::PAGE, ReorderList::PAGE)));
        $me = $ctx->me();
        $canDraft = Documents::mayPost($me->roles, 'PO');
        $notice = $this->notice($ctx);
        return $ctx->page('reorder', [
            'rows' => $rows,
            'total' => count($all),
            'page' => $page,
            'pages' => $pages,
            'filters' => $f,
            'history' => $list->header(),
            'brands' => $list->brands(),
            'suppliers' => $list->suppliers(),
            'canDraft' => $canDraft,
            'canManage' => $me->can('reorder.manage'),
            'formKey' => $canDraft ? ($error !== null ? ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey()) : FormOnce::newKey()) : null,
            'error' => $error,
            'created' => $notice === 'drafts' ? $this->created($ctx) : null,
            'flagText' => self::FLAG_TEXT,
            'query' => ['brand' => $f['brand'], 'supplier' => $f['supplier'], 'q' => $f['q'] === '' ? null : $f['q'], 'urgent' => $f['urgent'] ? '1' : null,
                'show' => $f['show'] === 'need' ? null : $f['show'], 'stock' => $f['stock'] === 'cw' ? null : $f['stock']],
        ], $status, ['title' => 'Reorder list', 'active' => 'reorder', 'notice' => self::NOTICES[$notice ?? ''] ?? null]);
    }

    /**
     * The drafts a "create draft PO" made and the items it skipped (from the redirect's ids, re-read and re-checked).
     *
     * @return array{drafts: list<array<string, mixed>>, skipped: list<array<string, mixed>>, more: int}
     */
    private function created(Context $ctx): array
    {
        $ids = self::ids($ctx->req->param('drafts'), 100);
        $drafts = $ids === [] ? [] : $ctx->db->all(
            'SELECT d.id, d.status, d.number, s.code AS supplier, s.name AS supplier_name, s.min_order_value, po.net_total, '
            . '(SELECT COUNT(*) FROM document_line l WHERE l.document_id = d.id) AS `lines` FROM document d JOIN purchase_order po ON po.document_id = d.id '
            . "JOIN supplier s ON s.id = po.supplier_id WHERE d.doc_type = 'PO' AND po.source = 'reorder' AND d.id IN (" . implode(', ', array_fill(0, count($ids), '?'))
            . ') ORDER BY d.id', $ids);
        foreach ($drafts as &$d) {
            $d['below_minimum'] = $d['min_order_value'] !== null && PoMath::e2((string) $d['net_total']) < PoMath::e2((string) $d['min_order_value']);
        }
        unset($d);
        $skus = self::ids($ctx->req->param('skipped'), self::SKIPPED_IN_URL);
        $skipped = [];
        if ($skus !== []) {
            foreach ($this->list($ctx)->lines(ReorderList::filters(['show' => 'all']), $skus) as $l) {
                $skipped[] = ['sku_id' => $l['sku_id'], 'code' => $l['code'], 'name' => $l['name'], 'reason' => match (true) {
                    $l['never'] !== null && in_array('merged', $l['flags'], true) => (string) $l['never'],
                    $l['supplier_item_id'] === null => 'no preferred supplier',
                    $l['supplier_status'] === 'inactive' => "preferred supplier {$l['supplier']} is inactive",
                    default => 'skipped',
                }];
            }
        }
        $more = UiRequest::id($ctx->req->param('skipped_n'));
        return ['drafts' => $drafts, 'skipped' => $skipped, 'more' => $more === null ? 0 : max(0, $more - count($skus))];
    }

    /** @param array<string, ?string>|null $typed */
    private function itemPage(Context $ctx, int $id, int $status, ?string $error, ?array $typed, ?string $notice): HtmlResponse
    {
        $sku = $ctx->db->one('SELECT s.id, s.code, s.name, s.brand, s.merged_into_sku_id, m.code AS merged_code FROM sku s LEFT JOIN sku m ON m.id = s.merged_into_sku_id '
            . 'WHERE s.id = ?', [$id]);
        if ($sku === null) {
            return $ctx->error(404, 'unknown_item', 'there is no such item');
        }
        $list = $this->list($ctx);
        $line = $list->explain($list->lines(ReorderList::filters(['show' => 'all']), [$id]))[0] ?? null;
        $demand = $ctx->db->one('SELECT rate, rate_short, rate_long, rate_raw_30, computed_at, units_365, first_sale_date, last_sale_date, CAST(monthly AS CHAR) AS monthly '
            . 'FROM reorder_demand WHERE sku_id = ?', [$id]);
        $live = null;
        $liveError = null;
        try {
            $live = (new DemandBuilder($ctx->db, $ctx->settings()))->item($id);
        } catch (CwException $e) {
            $liveError = $e->getMessage();
        }
        $settings = (new ReorderSettings($ctx->db))->item($id);
        $brand = $sku['brand'] === null ? null : (new ReorderSettings($ctx->db))->brand((string) $sku['brand']);
        return $ctx->page('reorder_item', [
            'sku' => $sku,
            'line' => $line,
            'demand' => $demand,
            'monthly' => $demand === null ? ($live['monthly'] ?? []) : Html::json($demand['monthly']),
            'live' => $live,
            'liveError' => $liveError,
            'settings' => $settings,
            'brand' => $brand,
            'params' => $list->params(),
            'version' => $settings === null ? 0 : (int) $settings['version'],
            'typed' => $typed,
            'canManage' => $ctx->me()->can('reorder.manage'),
            'error' => $error,
            'reasonText' => ['anomaly' => 'anomaly'] + Explain::REASON_TEXT,
        ], $status, ['title' => 'Reorder ' . $sku['code'], 'active' => 'reorder', 'notice' => $notice]);
    }

    /** @param array<string, ?string>|null $typed */
    private function brandsPage(Context $ctx, ?string $brand, int $status, ?string $error, ?array $typed, ?string $notice): HtmlResponse
    {
        $rows = $ctx->db->all(
            'SELECT b.brand, b.items, b.rate, rb.demand_factor, rb.safety_days, rb.note, rb.version, rb.updated_at, u.display_name AS updated_by_name FROM ('
            . 'SELECT s.brand, COUNT(*) AS items, SUM(d.rate) AS rate FROM reorder_demand d JOIN sku s ON s.id = d.sku_id WHERE s.brand IS NOT NULL GROUP BY s.brand '
            . 'UNION SELECT rb2.brand, 0, NULL FROM reorder_brand rb2 WHERE NOT EXISTS (SELECT 1 FROM reorder_demand d2 JOIN sku s2 ON s2.id = d2.sku_id WHERE s2.brand = rb2.brand)'
            . ') b LEFT JOIN reorder_brand rb ON rb.brand = b.brand LEFT JOIN staff_user u ON u.id = rb.updated_by ORDER BY b.brand');
        $current = $brand === null ? null : (new ReorderSettings($ctx->db))->brand($brand);
        $p = ReorderSettings::params($ctx->settings());
        return $ctx->page('reorder_brands', [
            'rows' => $rows,
            'brand' => $current !== null ? (string) $current['brand'] : $brand,
            'current' => $current,
            'version' => $current === null ? 0 : (int) $current['version'],
            'typed' => $typed,
            'defaultSafety' => $p['safety'],
            'canManage' => $ctx->me()->can('reorder.manage'),
            'error' => $error,
        ], $status, ['title' => 'Reorder: brands', 'active' => 'reorder', 'notice' => $notice]);
    }

    /** @param array<string, ?string>|null $typed */
    private function anomaliesPage(Context $ctx, int $status, ?string $error, ?array $typed, ?string $notice): HtmlResponse
    {
        $canManage = $ctx->me()->can('reorder.manage');
        return $ctx->page('reorder_anomalies', [
            'rows' => $ctx->db->all('SELECT a.*, c.code AS channel_code, cu.display_name AS created_by_name, eu.display_name AS ended_by_name FROM demand_anomaly a '
                . 'LEFT JOIN channel c ON c.id = a.channel_id LEFT JOIN staff_user cu ON cu.id = a.created_by LEFT JOIN staff_user eu ON eu.id = a.ended_by '
                . 'ORDER BY a.is_active DESC, a.date_from DESC, a.id DESC'),
            'channels' => $ctx->db->all('SELECT id, code, name FROM channel ORDER BY code'),
            'canManage' => $canManage,
            'formKey' => $canManage ? ($error !== null ? ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey()) : FormOnce::newKey()) : null,
            'typed' => $typed,
            'error' => $error,
            'maxDays' => ReorderSettings::ANOMALY_MAX_DAYS + 1,
        ], $status, ['title' => 'Reorder: anomaly windows', 'active' => 'reorder', 'notice' => $notice]);
    }

    // ------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------

    private function list(Context $ctx): ReorderList
    {
        return new ReorderList($ctx->db, $this->pos($ctx), $ctx->settings());
    }

    private function pos(Context $ctx): PurchaseOrders
    {
        return new PurchaseOrders($ctx->db, $ctx->documents(), $ctx->settings());
    }

    private function draftPos(Context $ctx): DraftPos
    {
        $pos = $this->pos($ctx);
        return new DraftPos($ctx->db, $pos, new ReorderList($ctx->db, $pos, $ctx->settings()));
    }

    /** @return array<string, ?string> */
    private function query(UiRequest $req): array
    {
        $out = [];
        foreach (['brand', 'supplier', 'q', 'urgent', 'show', 'stock'] as $k) {
            $out[$k] = $req->param($k);
        }
        return $out;
    }

    /** The list's filters a POST carries (hidden fields), so a refused form redraws the same list. @return array<string, ?string> */
    private function formFilters(UiRequest $req): array
    {
        $out = [];
        foreach (['brand', 'supplier', 'q', 'urgent', 'show', 'stock'] as $k) {
            $out[$k] = $req->field($k);
        }
        return $out;
    }

    private function notice(Context $ctx): ?string
    {
        $n = $ctx->req->param('notice');
        return $n !== null && isset(self::NOTICES[$n]) ? $n : null;
    }

    /** @return list<int> the positive ids of a comma list (at most $max) */
    private static function ids(?string $v, int $max): array
    {
        $out = [];
        foreach (explode(',', (string) $v) as $x) {
            $id = UiRequest::id(trim($x));
            if ($id !== null) {
                $out[$id] = $id;
            }
        }
        return array_slice(array_values($out), 0, $max);
    }
}
