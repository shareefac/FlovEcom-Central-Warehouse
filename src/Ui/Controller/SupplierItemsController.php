<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\CwException;
use CW\Db;
use CW\OpResult;
use CW\Output\CsvWriter;
use CW\Suppliers\SupplierItems;
use CW\Ui\Context;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;
use CW\Ui\UiRequest;

/**
 * Supplier items (IM4; docs/decisions.md I43-I44): a supplier's items (and their CSV), adding an item found by a search
 * (CW code, barcode or name words: Queries::searchSkus), one supplier item with its price history, its changes (version;
 * the preferred toggle) and a manual price. Every change goes through CW\Suppliers\SupplierItems (suppliers.manage); a new
 * supplier item and a manual price carry a FormOnce key (one effect however often the form is sent).
 */
final class SupplierItemsController
{
    public const NOTICES = [
        'created' => 'Supplier item added.',
        'saved' => 'Saved.',
        'unchanged' => 'Nothing changed.',
        'preferred' => 'This is now the preferred supply of the item (any other supplier item of the item is no longer preferred).',
        'not_preferred' => 'This is no longer the preferred supply of the item.',
        'price' => 'Price recorded.',
        'pack_changed' => 'Saved. The pack size changed: the last price is now the newest one recorded for the new pack (none yet: record it below).',
    ];

    public function index(Context $ctx): HtmlResponse
    {
        $s = $ctx->suppliers()->find($ctx->id());
        if ($s === null) {
            return $ctx->error(404, 'unknown_supplier', 'there is no such supplier');
        }
        return $ctx->page('supplier_items', [
            's' => $s,
            'rows' => array_map(self::row(...), $this->rows($ctx, (int) $s['id'])),
            'canManage' => $ctx->me()->can('suppliers.manage'),
            'notice' => null,
        ], 200, ['title' => $s['code'] . ' items', 'active' => 'suppliers']);
    }

    public function csv(Context $ctx): HtmlResponse
    {
        $s = $ctx->suppliers()->find($ctx->id());
        if ($s === null) {
            return $ctx->error(404, 'unknown_supplier', 'there is no such supplier');
        }
        $csv = new CsvWriter([['supplier', 'text'], ['cw_code', 'text'], ['item_name', 'text'], ['brand', 'text'], ['supplier_code', 'text'],
            ['supplier_description', 'text'], ['purchase_unit', 'text'], ['units_per_pack', 'number'], ['moq_packs', 'number'], ['order_multiple_packs', 'number'],
            ['lead_days', 'number'], ['preferred', 'text'], ['active', 'text'], ['last_pack_price', 'number'], ['last_price_on', 'text'], ['last_price_source', 'text'],
            ['unit_price', 'number'], ['last_po_pack_price', 'number'], ['last_po_on', 'text'], ['merged_into', 'text']]);
        foreach ($this->rows($ctx, (int) $s['id']) as $r) {
            $csv->add([$s['code'], $r['sku_code'], $r['sku_name'], $r['brand'], $r['supplier_code'], $r['supplier_description'], $r['purchase_unit'],
                (int) $r['units_per_pack'], (int) $r['moq_packs'], (int) $r['order_multiple_packs'], $r['lead_days'], (int) $r['is_preferred'] === 1,
                (int) $r['is_active'] === 1, $r['last_pack_price'], $r['last_price_on'], $r['last_price_source'],
                $r['last_pack_price'] === null ? null : SupplierItems::unitPrice((string) $r['last_pack_price'], (int) $r['units_per_pack']),
                $r['last_po_pack_price'], $r['last_po_on'], $r['merged_code']]);
        }
        return FilesController::download($csv->output(), 'text/csv; charset=utf-8', $s['code'] . '-items.csv');
    }

    public function newForm(Context $ctx): HtmlResponse
    {
        return $this->form($ctx, $ctx->id(), [], FormOnce::newKey(), 200, null);
    }

    public function create(Context $ctx): HtmlResponse
    {
        $supplierId = $ctx->id();
        $req = $ctx->req;
        $fields = [];
        foreach (['supplier_code', 'supplier_description', 'purchase_unit', 'units_per_pack', 'moq_packs', 'order_multiple_packs', 'lead_days'] as $k) {
            $fields[$k] = $req->field($k) ?? '';
        }
        $pref = $req->field('is_preferred');
        $fields['is_preferred'] = in_array($pref, ['1', '0'], true) ? $pref : SupplierItems::PREFERRED_AUTO;
        $sku = UiRequest::id($req->field('sku_id'));
        $price = trim($req->field('pack_price') ?? '');
        $priceOn = trim($req->field('effective_on') ?? '');
        $request = $fields + ['sku_id' => $sku, 'pack_price' => $price, 'effective_on' => $priceOn];
        try {
            if ($sku === null) {
                throw new CwException('bad_field', 'choose the item (search for it first)', 422, ['field' => 'sku_id']);
            }
            $r = FormOnce::run($ctx, 'ui.supplier_item.create', $request, static function (Db $db) use ($ctx, $supplierId, $sku, $fields, $price, $priceOn): OpResult {
                $i = $ctx->supplierItems()->create($ctx->caller(), $supplierId, $sku, $fields,
                    $price === '' ? null : ['pack_price' => $price, 'effective_on' => $priceOn === '' ? null : $priceOn]);
                return OpResult::of(303, ['result' => 'created', 'supplier_item_id' => (int) $i['id'],
                    'redirect' => Html::url('/ui/purchasing/supplier-items/' . (int) $i['id'], ['notice' => 'created'])]);
            });
        } catch (CwException $e) {
            return $this->form($ctx, $supplierId, $request + ['q' => $req->field('q') ?? ''], $req->field(FormOnce::FIELD) ?? FormOnce::newKey(), $e->httpStatus, $e);
        }
        return FormOnce::redirect($r);
    }

    public function show(Context $ctx): HtmlResponse
    {
        return $this->page($ctx, $ctx->id(), 200, null, self::NOTICES[$ctx->req->param('notice') ?? ''] ?? null);
    }

    public function update(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $version = UiRequest::id($req->field('version'));
        if ($version === null) {
            return $ctx->error(400, 'bad_version', 'this form has no version: reload the page');
        }
        $svc = $ctx->supplierItems();
        try {
            $before = $svc->get($id);
            $toggle = $req->field('preferred');
            if ($toggle === '1' || $toggle === '0') {
                $after = $svc->setPreferred($ctx->caller(), $id, $toggle === '1', $version);
                $notice = (int) $after['version'] === (int) $before['version'] ? 'unchanged' : ($toggle === '1' ? 'preferred' : 'not_preferred');
            } else {
                $fields = [];
                foreach (['supplier_code', 'supplier_description', 'purchase_unit', 'units_per_pack', 'moq_packs', 'order_multiple_packs', 'lead_days'] as $k) {
                    $v = $req->field($k);
                    if ($v !== null) {
                        $fields[$k] = $v;
                    }
                }
                $fields['is_preferred'] = $req->field('is_preferred') === '1' ? '1' : '0';
                $fields['is_active'] = $req->field('is_active') === '1' ? '1' : '0';
                $after = $svc->update($ctx->caller(), $id, $version, $fields);
                $notice = (int) $after['version'] === (int) $before['version'] ? 'unchanged'
                    : ((int) $after['units_per_pack'] !== (int) $before['units_per_pack'] ? 'pack_changed' : 'saved');
            }
        } catch (CwException $e) {
            if ($e->errorCode === 'version_conflict') {
                return $this->page($ctx, $id, 409, new CwException('version_conflict',
                    'This supplier item was changed since you opened the page: here is the current data. Make your change again.', 409));
            }
            return $this->page($ctx, $id, $e->httpStatus, $e);
        }
        return HtmlResponse::redirect(Html::url('/ui/purchasing/supplier-items/' . $id, ['notice' => $notice]));
    }

    public function price(Context $ctx): HtmlResponse
    {
        $id = $ctx->id();
        $req = $ctx->req;
        $request = ['pack_price' => trim($req->field('pack_price') ?? ''), 'effective_on' => trim($req->field('effective_on') ?? ''),
            'note' => trim($req->field('note') ?? '')];
        try {
            $r = FormOnce::run($ctx, 'ui.supplier_item.price', $request, static function (Db $db) use ($ctx, $id, $request): OpResult {
                $ctx->supplierItems()->recordPrice($ctx->caller(), $id, $request['pack_price'], $request['effective_on'] === '' ? null : $request['effective_on'],
                    $request['note'] === '' ? null : $request['note']);
                return OpResult::of(303, ['result' => 'price', 'redirect' => Html::url('/ui/purchasing/supplier-items/' . $id, ['notice' => 'price'])]);
            });
        } catch (CwException $e) {
            return $this->page($ctx, $id, $e->httpStatus, $e, null, $request, $req->field(FormOnce::FIELD));
        }
        return FormOnce::redirect($r);
    }

    // ------------------------------------------------------------------------------------------

    /**
     * One supplier item: details, the edit form, the preferred toggle, the price form and the history.
     *
     * @param array<string, string>|null $priceValues what the price form shows after a refusal
     */
    private function page(Context $ctx, int $id, int $status, ?CwException $error, ?string $notice = null, ?array $priceValues = null,
        ?string $priceKey = null): HtmlResponse
    {
        $svc = $ctx->supplierItems();
        $i = $svc->find($id);
        if ($i === null) {
            return $ctx->error(404, 'unknown_supplier_item', 'there is no such supplier item');
        }
        $s = $ctx->suppliers()->get((int) $i['supplier_id']);
        $sku = $ctx->db->one('SELECT s.id, s.code, s.name, s.brand, s.merged_into_sku_id, m.code AS merged_code FROM sku s LEFT JOIN sku m ON m.id = s.merged_into_sku_id '
            . 'WHERE s.id = ?', [(int) $i['sku_id']]);
        $others = $ctx->db->all(
            'SELECT i.id, i.units_per_pack, i.purchase_unit, i.is_preferred, i.is_active, i.last_pack_price, s.id AS supplier_id, s.code AS supplier_code, s.name AS supplier_name, s.status '
            . 'FROM supplier_item i JOIN supplier s ON s.id = i.supplier_id WHERE i.sku_id = ? AND i.id <> ? ORDER BY i.is_preferred DESC, s.name',
            [(int) $i['sku_id'], $id],
        );
        $history = array_map(static fn (array $h): array => $h + ['pack_text' => self::gbp($h['pack_price']), 'unit_text' => self::gbp($h['unit_price'])],
            $svc->history($id));
        return $ctx->page('supplier_item', [
            'i' => self::row($i + ['sku_code' => $sku['code'] ?? null, 'sku_name' => $sku['name'] ?? null, 'brand' => $sku['brand'] ?? null,
                'merged_into_sku_id' => $sku['merged_into_sku_id'] ?? null, 'merged_code' => $sku['merged_code'] ?? null]),
            's' => $s,
            'others' => array_map(static fn (array $o): array => $o + ['pack' => self::pack((string) $o['purchase_unit'], (int) $o['units_per_pack']),
                'price_text' => self::gbp($o['last_pack_price'])], $others),
            'history' => $history,
            'canManage' => $ctx->me()->can('suppliers.manage'),
            'priceKey' => $priceKey ?? FormOnce::newKey(),
            'priceValues' => $priceValues ?? ['pack_price' => '', 'effective_on' => '', 'note' => ''],
            'today' => $ctx->suppliers()->today(),
            'error' => $error?->getMessage(),
        ], $status, ['title' => ($sku['code'] ?? '') . ' from ' . $s['code'], 'active' => 'suppliers', 'notice' => $notice]);
    }

    /**
     * The "add an item" form: a search (GET q), the items found, and the fields.
     *
     * @param array<string, mixed> $values
     */
    private function form(Context $ctx, int $supplierId, array $values, string $formKey, int $status, ?CwException $error): HtmlResponse
    {
        $s = $ctx->suppliers()->find($supplierId);
        if ($s === null) {
            return $ctx->error(404, 'unknown_supplier', 'there is no such supplier');
        }
        $q = mb_substr(trim($ctx->req->param('q') ?? (string) ($values['q'] ?? '')), 0, 100);
        $chosen = UiRequest::id($ctx->req->param('sku_id')) ?? (isset($values['sku_id']) && is_int($values['sku_id']) ? $values['sku_id'] : null);
        $found = $q === '' ? [] : $ctx->queries()->searchSkus($q, 20);
        if ($chosen !== null && !in_array($chosen, array_map(static fn (array $r): int => (int) $r['id'], $found), true)) {
            $c = $ctx->queries()->skus([$chosen])[$chosen] ?? null;
            if ($c !== null) {
                array_unshift($found, $c);
            }
        }
        $have = [];
        if ($found !== []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $found);
            foreach ($ctx->db->all('SELECT sku_id, units_per_pack, purchase_unit FROM supplier_item WHERE supplier_id = ? AND sku_id IN ('
                . implode(', ', array_fill(0, count($ids), '?')) . ')', [$supplierId, ...$ids]) as $r) {
                $have[(int) $r['sku_id']][] = self::pack((string) $r['purchase_unit'], (int) $r['units_per_pack']);
            }
        }
        $items = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
            'brand' => $r['brand'], 'merged' => $r['merged_into_sku_id'] !== null, 'have' => implode(', ', $have[(int) $r['id']] ?? [])], $found);
        $v = [];
        foreach (['supplier_code', 'supplier_description', 'purchase_unit', 'units_per_pack', 'moq_packs', 'order_multiple_packs', 'lead_days', 'pack_price',
            'effective_on'] as $k) {
            $v[$k] = isset($values[$k]) ? (string) $values[$k] : '';
        }
        $v['is_preferred'] = in_array($values['is_preferred'] ?? null, ['1', '0'], true) ? (string) $values['is_preferred'] : SupplierItems::PREFERRED_AUTO;
        return $ctx->page('supplier_item_form', [
            's' => $s,
            'q' => $q,
            'items' => $items,
            'chosen' => $chosen ?? (count($items) === 1 ? $items[0]['id'] : null),
            'v' => $v,
            'formKey' => $formKey,
            'today' => $ctx->suppliers()->today(),
            'error' => $error?->getMessage(),
        ], $status, ['title' => 'Add an item to ' . $s['code'], 'active' => 'suppliers']);
    }

    /** @return list<array<string, mixed>> */
    private function rows(Context $ctx, int $supplierId): array
    {
        return $ctx->db->all(
            'SELECT i.*, k.code AS sku_code, k.name AS sku_name, k.brand, k.merged_into_sku_id, m.code AS merged_code FROM supplier_item i '
            . 'JOIN sku k ON k.id = i.sku_id LEFT JOIN sku m ON m.id = k.merged_into_sku_id WHERE i.supplier_id = ? ORDER BY i.is_active DESC, k.name, i.units_per_pack',
            [$supplierId],
        );
    }

    /** @param array<string, mixed> $r @return array<string, mixed> a supplier item row with its display texts */
    private static function row(array $r): array
    {
        return $r + [
            'pack' => self::pack((string) $r['purchase_unit'], (int) $r['units_per_pack']),
            'price_text' => self::gbp($r['last_pack_price']),
            'unit_text' => $r['last_pack_price'] === null ? null : self::gbp(SupplierItems::unitPrice((string) $r['last_pack_price'], (int) $r['units_per_pack'])),
            'po_text' => self::gbp($r['last_po_pack_price']),
        ];
    }

    /** "box ×24", "each" (one central unit), "case ×6". */
    public static function pack(string $unit, int $upp): string
    {
        return $upp === 1 && $unit === 'each' ? 'each' : "{$unit} ×{$upp}";
    }

    /** £1.50, £0.1234 (at least 2 decimals, trailing zeros beyond them dropped); null for null. */
    public static function gbp(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;
        if (str_contains($s, '.')) {
            [$i, $f] = explode('.', $s, 2);
            $f = rtrim($f, '0');
            $s = $i . '.' . str_pad($f, 2, '0');
        } else {
            $s .= '.00';
        }
        return '£' . $s;
    }
}
