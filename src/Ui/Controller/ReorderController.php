<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Audit;
use CW\Catalogue\ItemRules;
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
use CW\Reorder\ReorderJobs;
use CW\Reorder\ReorderLines;
use CW\Reorder\ReorderList;
use CW\Reorder\ReorderRefresh;
use CW\Reorder\ReorderSettings;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\ReorderWhy;
use CW\Ui\UiRequest;
use CW\Ui\Words;

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
 *
 * Pack G (docs/decisions.md PG3, PG9): the list with CW's stock, its pages and its download read the stored list (ReorderLines, written by
 * ReorderRefresh: "Figures worked out on ..."); the website-stock view still works its lines out on the page. The filter "below the safety
 * stock only" (whatever there is to buy). After "create draft orders", an item's or a brand's settings and Recalculate, the lines they
 * touch are worked out again at once (a brand's only up to reorder.refresh_inline_max_items items; the schedule does the rest).
 */
final class ReorderController
{
    /** The notices named in a redirect (their words: Words::REORDER_NOTICE). */
    public const NOTICES = Words::REORDER_NOTICE;
    /** The flags of a line (ReorderList::lines() `flags`) and their words. */
    public const FLAG_TEXT = Words::REORDER_FLAG;
    /** The flags shown as "blocked" and as "needs you"; the rest are information. */
    private const FLAG_BLOCKED = ['urgent', 'supplier_inactive', 'merged', 'card_blocked'];
    private const FLAG_NEEDS = ['no_supplier', 'supplier_draft', 'supplier_pending_approval', 'no_price', 'site_stock_unreliable', 'card_warning', 'discontinued'];
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
        $stored = new ReorderLines($ctx->db);
        $chunks = $f['stock'] === 'cw' ? array_chunk($stored->ids($f), 1000) : array_chunk($list->lines($f), 1000);
        foreach ($chunks as $chunk) {
            foreach ($list->explain($f['stock'] === 'cw' ? $stored->lines($chunk) : $chunk) as $l) {
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
            return $ctx->error(400, 'form_truncated', Words::BUY_ERROR['reorder_truncated']);
        }
        $count = (int) $count;
        $rows = $req->fieldsMatching('/^packs_[1-9][0-9]{0,9}$/');
        if ($count > ReorderList::PAGE || count($rows) < $count) {
            return $ctx->error(400, 'form_truncated', Words::BUY_ERROR['reorder_truncated']);
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
                    throw new CwException('bad_pick', Words::BUY_ERROR['bad_pick'], 422);
                }
                $picks[] = ['sku_id' => $sku, 'packs' => (int) $p];
            }
            usort($picks, static fn (array $a, array $b): int => $a['sku_id'] <=> $b['sku_id']);
            $r = FormOnce::run($ctx, 'ui.reorder.draft', ['picks' => $picks, 'stock' => $f['stock']], function (Db $db) use ($ctx, $picks, $f): OpResult {
                $res = $this->draftPos($ctx)->create($ctx->caller(), $picks, $f['stock'], $ctx->req->field(FormOnce::FIELD));
                if ($res['drafts'] === []) {
                    throw new CwException('nothing_ordered', Words::say('BUY_ERROR', 'nothing_ordered', implode('; ', array_map(static fn (array $s): string => "{$s['code']}: {$s['reason']}",
                        array_slice($res['skipped'], 0, 10))) . (count($res['skipped']) > 10 ? '; …' : '')), 422);
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
            return $this->listPage($ctx, $f, $e->httpStatus, self::plain($e));
        }
        return FormOnce::redirect($r);
    }

    /**
     * A refusal of the reorder services in the page's words, by its error code (plan rule 18: the service's message stays the
     * API's). A code without words: the service's message, then "Nothing was saved."
     */
    public static function plain(CwException $e): string
    {
        $code = $e->errorCode;
        return match (true) {
            in_array($code, ['bad_pick', 'nothing_ordered', 'rebuild_on_server', 'refresh_later'], true) => $e->getMessage(), // made in words here
            isset(Words::REORDER_ENGINE_ERROR[$code]) => Words::REORDER_ENGINE_ERROR[$code], // pack G
            $code === 'version_conflict' => Words::BUY_ERROR['version_conflict_settings'],
            isset(Words::ERROR[$code]) && !str_contains(Words::ERROR[$code], '%') => Words::ERROR[$code],
            isset(Words::BUY_ERROR[$code]) && $code !== 'other' => Words::BUY_ERROR[$code],
            default => Words::say('BUY_ERROR', 'other', PurchaseOrdersController::sentence($e->getMessage())),
        };
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
                throw new CwException('rebuild_on_server', Words::say('BUY_ERROR', 'rebuild_on_server', $items), 409);
            }
            $b = ReorderJobs::record($ctx->db, 'demand', static fn (): \DateTimeImmutable => \CW\Clock::now(),
                static fn (): array => (new DemandBuilder($ctx->db, $ctx->settings()))->rebuild());
        } catch (CwException $e) {
            return $this->listPage($ctx, ReorderList::filters($this->formFilters($ctx->req)), $e->httpStatus, self::plain($e));
        }
        Audit::write($ctx->db, $ctx->caller(), 'reorder.recalculate', null, null, null, ['items' => $b['items'], 'listings' => $b['listings'], 'ms' => $b['ms'],
            'channels' => $b['channels']]);
        $notice = 'recalculated';
        try {
            $this->refreshAll($ctx); // pack G: the stored list follows the new demand at once (a long list: at the next scheduled run)
        } catch (CwException $e) {
            if ($e->errorCode !== 'refresh_later') {
                throw $e;
            }
            $notice = 'recalculated_later';
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/reorder', ['notice' => $notice]));
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
                throw new CwException('bad_version', Words::ERROR['bad_version'], 400);
            }
            (new ReorderSettings($ctx->db))->saveItem($ctx->caller(), $id, (int) $version, $fields);
            $this->refresher($ctx)->items([$id]); // pack G: the stored line shows the new settings at once
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->itemPage($ctx, $id, 409, self::plain($e), null, null);
            }
            return $this->itemPage($ctx, $id, $e->httpStatus, $e->errorCode === 'bad_version' ? $e->getMessage() : self::plain($e), $fields, null);
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
                throw new CwException('bad_version', Words::ERROR['bad_version'], 400);
            }
            $row = (new ReorderSettings($ctx->db))->saveBrand($ctx->caller(), $brand, (int) $version, $fields);
            $this->refreshSome($ctx, array_map('intval', $ctx->db->column('SELECT rl.sku_id FROM reorder_line rl WHERE rl.brand = ? AND rl.listed = 1',
                [(string) $row['brand']])));
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->brandsPage($ctx, $brand, 409, self::plain($e), null, null);
            }
            return $this->brandsPage($ctx, $brand === '' ? null : mb_substr($brand, 0, 128), $e->httpStatus, $e->errorCode === 'bad_version' ? $e->getMessage() : self::plain($e),
                $fields, null);
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
            return $this->anomaliesPage($ctx, $e->httpStatus, self::plain($e), $fields, null);
        }
        return FormOnce::redirect($r);
    }

    public function endAnomaly(Context $ctx): HtmlResponse
    {
        try {
            (new ReorderSettings($ctx->db))->endAnomaly($ctx->caller(), $ctx->id());
        } catch (CwException $e) {
            return $this->anomaliesPage($ctx, $e->httpStatus, self::plain($e), null, null);
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
        $page = max(1, (int) (UiRequest::id($ctx->req->param('page') ?? $ctx->req->field('page')) ?? 1));
        if ($f['stock'] === 'cw') {
            // Pack G (PG3): one page of the stored list; the list is worked out by ReorderRefresh, never here.
            $stored = new ReorderLines($ctx->db);
            $res = $stored->page($f, $page, ReorderList::PAGE);
            $pages = max(1, intdiv($res['total'] + ReorderList::PAGE - 1, ReorderList::PAGE));
            if ($page > $pages) {
                $page = $pages;
                $res = $stored->page($f, $page, ReorderList::PAGE);
            }
            $total = $res['total'];
            $lines = $list->explain($res['lines']);
            $brands = $stored->brands();
        } else {
            $all = $list->lines($f);
            $total = count($all);
            $pages = max(1, intdiv($total + ReorderList::PAGE - 1, ReorderList::PAGE));
            $page = min($pages, $page);
            $lines = $list->explain(array_slice($all, ($page - 1) * ReorderList::PAGE, ReorderList::PAGE));
            $brands = $list->brands();
        }
        $drafts = self::draftsOf($ctx, array_values(array_filter(array_map(static fn (array $l): ?int => (int) $l['in_drafts'] > 0 ? (int) $l['sku_id'] : null, $lines))));
        $rows = array_map(static fn (array $l): array => self::screenLine($l, $drafts[(int) $l['sku_id']] ?? null), $lines);
        $me = $ctx->me();
        $canDraft = Documents::mayPost($me->roles, 'PO');
        $notice = $this->notice($ctx);
        $names = [];
        foreach ($ctx->queries()->channels() as $c) {
            $names[(string) $c['code']] = (string) $c['name'];
        }
        return $ctx->page('reorder', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $f,
            'filtered' => $f['brand'] !== null || $f['supplier'] !== null || $f['q'] !== '' || $f['urgent'] || $f['below'],
            'history' => $list->header(),
            'channelNames' => $names,
            'brands' => $brands,
            'figures' => self::figures($ctx),
            'suppliers' => $list->suppliers(),
            'canDraft' => $canDraft,
            'canManage' => $me->can('reorder.manage'),
            'lookOnly' => $canDraft ? null : Words::whoCan('doc.PO.post'),
            'formKey' => $canDraft ? ($error !== null ? ($ctx->req->field(FormOnce::FIELD) ?? FormOnce::newKey()) : FormOnce::newKey()) : null,
            'error' => $error,
            'created' => $notice === 'drafts' ? $this->created($ctx) : null,
            'query' => ['brand' => $f['brand'], 'supplier' => $f['supplier'], 'q' => $f['q'] === '' ? null : $f['q'], 'urgent' => $f['urgent'] ? '1' : null,
                'show' => $f['show'] === 'need' ? null : $f['show'], 'stock' => $f['stock'] === 'cw' ? null : $f['stock'], 'below' => $f['below'] ? '1' : null],
        ], $status, ['title' => Words::MENU['reorder'], 'active' => 'reorder', 'notice' => self::NOTICES[$notice ?? ''] ?? null]);
    }

    /**
     * A line of What to buy as people read it (plan F307-F310, F318, F322): sells a day, have + ordered, the suggestion in packs,
     * its cost in £, days left, the flags in words, the plain "Why" sentences (Ui\ReorderWhy) and the maths (Reorder\Explain), and
     * the draft order it is on already.
     *
     * @param array<string, mixed> $l a ReorderList::explain() line
     * @return array<string, mixed>
     */
    public static function screenLine(array $l, ?int $draft): array
    {
        $flags = [];
        foreach ((array) $l['flags'] as $fl) {
            $rules = match ($fl) {
                // The product card's flags name the rules (I123): "Blocked: … (tank or pod over 2 ml)".
                'card_blocked' => ItemRules::labels((array) $l['card_blocked']),
                'card_warning' => ItemRules::labels((array) $l['card_warnings']),
                default => '',
            };
            $flags[] = ['word' => Words::of('REORDER_FLAG', (string) $fl) . ($rules === '' ? '' : ' (' . $rules . ')'),
                'tone' => in_array($fl, self::FLAG_BLOCKED, true) ? 'blocked' : (in_array($fl, self::FLAG_NEEDS, true) ? 'needs' : 'info')];
        }
        if (!empty($l['below_safety'])) { // pack G (PG5): below the safety stock now
            $flags[] = ['word' => Words::say('REORDER_ENGINE', 'below_chip', (int) $l['safety_stock']), 'tone' => 'needs'];
        }
        $inDrafts = (int) $l['in_drafts'];
        return $l + [
            'per_day' => ReorderList::perDay((int) $l['d_e6']),
            'value' => Html::money(ReorderList::amount($l['value_e4'])),
            'price' => Html::money($l['pack_price']),
            'days_left' => ReorderWhy::daysLeft($l['cover_now_e1']),
            'why' => ReorderWhy::sentences($l),
            'flag_list' => $flags,
            'draft' => $draft,
            'draft_note' => $inDrafts === 0 ? null : ($inDrafts >= (int) $l['need'] ? Words::REORDER['on_draft_covers']
                : Words::say('REORDER', 'on_draft_more', (int) $l['need'] - $inDrafts)),
        ];
    }

    /**
     * The first draft (or waiting) order each of these products is on (plan F309: "Already on a draft – open it").
     *
     * @param list<int> $skus
     * @return array<int, int> sku id => document id
     */
    private static function draftsOf(Context $ctx, array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $out = [];
        foreach ($ctx->db->all("SELECT dl.sku_id, MIN(d.id) AS doc FROM document_line dl JOIN document d ON d.id = dl.document_id WHERE d.doc_type = 'PO' "
            . "AND d.status IN ('draft', 'awaiting_approval') AND dl.sku_id IN (" . implode(', ', array_fill(0, count($skus), '?')) . ') GROUP BY dl.sku_id', $skus) as $r) {
            $out[(int) $r['sku_id']] = (int) $r['doc'];
        }
        return $out;
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
            $n = (int) $d['lines'];
            $d['line'] = Words::say('REORDER', 'made_line', (string) $d['supplier_name'], $n === 1 ? Words::ORDERS['products_one'] : Words::say('ORDERS', 'products_many', $n),
                Html::money($d['net_total']));
        }
        unset($d);
        $skus = self::ids($ctx->req->param('skipped'), self::SKIPPED_IN_URL);
        $skipped = [];
        if ($skus !== []) {
            foreach ($this->list($ctx)->lines(ReorderList::filters(['show' => 'all']), $skus) as $l) {
                $skipped[] = ['sku_id' => $l['sku_id'], 'code' => $l['code'], 'name' => $l['name'], 'reason' => match (true) {
                    $l['never'] !== null && in_array('merged', $l['flags'], true) => Words::REORDER['skip_merged'],
                    in_array('card_blocked', $l['flags'], true) => Words::say('REORDER', 'skip_blocked', ItemRules::labels($l['card_blocked'])),
                    // Pack G (PG8): never suggested, a main supplier not approved yet, no price.
                    in_array('discontinued', $l['flags'], true) => Words::REORDER_ENGINE['skip_discontinued'],
                    in_array('do_not_reorder', $l['flags'], true) => Words::REORDER_ENGINE['skip_never'],
                    $l['supplier_item_id'] === null => Words::REORDER['skip_no_supplier'],
                    $l['supplier_status'] === 'inactive' => Words::say('REORDER', 'skip_supplier_stopped', (string) ($l['supplier_name'] ?? $l['supplier'])),
                    $l['supplier_status'] !== 'active' => Words::say('REORDER_ENGINE', 'skip_supplier_waiting', (string) ($l['supplier_name'] ?? $l['supplier'])),
                    $l['pack_price'] === null => Words::REORDER_ENGINE['skip_no_price'],
                    default => Words::REORDER['skip_other'],
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
            return $ctx->error(404, 'unknown_item', Words::ERROR['unknown_item'], ['/ui/purchasing/reorder', Words::MENU['reorder']]);
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
        $names = [];
        foreach ($ctx->queries()->channels() as $c) {
            $names[(string) $c['code']] = (string) $c['name'];
        }
        $title = Words::say('REORDER_ITEM', 'title', (string) $sku['name'], (string) $sku['code']);
        return $ctx->page('reorder_item', [
            'sku' => $sku,
            'title' => $title,
            'line' => $line === null ? null : self::screenLine($line, null),
            'demand' => $demand,
            'monthly' => $demand === null ? ($live['monthly'] ?? []) : Html::json($demand['monthly']),
            'live' => $live,
            'liveError' => $liveError === null ? null : PurchaseOrdersController::sentence($liveError),
            'channelNames' => $names,
            'settings' => $settings,
            'brand' => $brand,
            'params' => $list->params(),
            'version' => $settings === null ? 0 : (int) $settings['version'],
            'typed' => $typed,
            'canManage' => $ctx->me()->can('reorder.manage'),
            'error' => $error,
            'dayReason' => ['promo' => Words::REORDER_ITEM['r_promo'], 'oos' => Words::REORDER_ITEM['r_oos'], 'before_first' => Words::REORDER_ITEM['before_first'],
                'nodata' => Words::REORDER_ITEM['before_history']],
        ], $status, ['title' => $title, 'active' => 'reorder', 'notice' => $notice]);
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
            'lookOnly' => $ctx->me()->can('reorder.manage') ? null : Words::whoCan('reorder.manage'),
        ], $status, ['title' => Words::title('reorder_brands'), 'active' => 'reorder', 'notice' => $notice]);
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
            'lookOnly' => $canManage ? null : Words::whoCan('reorder.manage'),
            'channelNames' => array_column($ctx->db->all('SELECT code, name FROM channel'), 'name', 'code'),
        ], $status, ['title' => Words::title('reorder_anomalies'), 'active' => 'reorder', 'notice' => $notice]);
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
        return new DraftPos($ctx->db, $pos, new ReorderList($ctx->db, $pos, $ctx->settings()), new ReorderRefresh($ctx->db, $pos, $ctx->settings()), $ctx->settings());
    }

    /** Pack G: the purchase orders (the pack's other pages build their ReorderList with it). */
    public function purchaseOrders(Context $ctx): PurchaseOrders
    {
        return $this->pos($ctx);
    }

    /** Pack G: the stored list's writer. */
    public function refresher(Context $ctx): ReorderRefresh
    {
        return new ReorderRefresh($ctx->db, $this->pos($ctx), $ctx->settings());
    }

    /**
     * Pack G (PG4): every line worked out again now when the list holds at most reorder.refresh_inline_max_items items; above it 409
     * refresh_later (the schedule does it). Another run already working: nothing to do.
     */
    public function refreshAll(Context $ctx): void
    {
        $e = ReorderSettings::engine($ctx->settings());
        $refresh = $this->refresher($ctx);
        $n = count($refresh->base());
        if ($n > $e['inline_max']) {
            throw new CwException('refresh_later', Words::say('REORDER_ENGINE', 'refresh_later', $n, $e['refresh_minutes']), 409);
        }
        try {
            $refresh->all();
        } catch (CwException $x) {
            if ($x->errorCode !== 'refresh_running') {
                throw $x;
            }
        }
    }

    /**
     * Pack G (PG4): some lines worked out again now, when they are at most reorder.refresh_inline_max_items (else the schedule does it).
     *
     * @param list<int> $skus
     */
    public function refreshSome(Context $ctx, array $skus): void
    {
        if ($skus !== [] && count($skus) <= ReorderSettings::engine($ctx->settings())['inline_max']) {
            $this->refresher($ctx)->items($skus);
        }
    }

    /**
     * Pack G: when the stored list was last worked out, how often, and whether the last run failed.
     *
     * @return array{at: ?string, minutes: int, failed: bool}
     */
    public static function figures(Context $ctx): array
    {
        $row = $ctx->db->one("SELECT ok_at, error FROM reorder_job WHERE job = 'lines'");
        return ['at' => $row === null || $row['ok_at'] === null ? null : (string) $row['ok_at'],
            'minutes' => ReorderSettings::engine($ctx->settings())['refresh_minutes'], 'failed' => $row !== null && $row['error'] !== null];
    }

    /** @return array<string, ?string> */
    private function query(UiRequest $req): array
    {
        $out = [];
        foreach (['brand', 'supplier', 'q', 'urgent', 'show', 'stock', 'below'] as $k) {
            $out[$k] = $req->param($k);
        }
        return $out;
    }

    /** The list's filters a POST carries (hidden fields), so a refused form redraws the same list. @return array<string, ?string> */
    private function formFilters(UiRequest $req): array
    {
        $out = [];
        foreach (['brand', 'supplier', 'q', 'urgent', 'show', 'stock', 'below'] as $k) {
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
