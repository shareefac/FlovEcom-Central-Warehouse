<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Ui\Context;
use CW\Ui\Duplicates;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;

/** /ui/items/{id}: who an item is, every listing linked to it (now or before), its stock and (suppliers.view) who supplies it. */
final class ItemController
{
    public function show(Context $ctx): HtmlResponse
    {
        $q = $ctx->queries();
        $id = $ctx->id();
        $sku = $q->sku($id);
        if ($sku === null) {
            return $ctx->error(404, 'not_found', 'no such item');
        }
        $listings = $q->listingsOfSku($id);
        $history = $q->historyOf(array_map(static fn (array $r): int => (int) $r['id'], $listings));
        $rows = [];
        foreach ($listings as $l) {
            $lid = (int) $l['id'];
            $periods = [];
            foreach ($history[$lid] ?? [] as $h) {
                $periods[] = [
                    'sku_id' => (int) $h['sku_id'], 'sku_code' => self::s($h['sku_code']), 'units' => $h['units_per_item'],
                    'from' => self::s($h['valid_from']), 'to' => self::s($h['valid_to']), 'action' => self::s($h['action']),
                    'decider' => self::s($h['decider']), 'decision_id' => (int) $h['decision_id'], 'this_item' => (int) $h['sku_id'] === $id,
                ];
            }
            $rows[] = [
                'id' => $lid, 'channel' => self::s($l['channel_code']), 'variant' => self::s($l['external_variant_id']),
                'title' => self::s($l['product_title']), 'variant_title' => self::s($l['variant_title']), 'status' => self::s($l['status']),
                'units_per_item' => (int) $l['units_per_item'], 'units_30d' => $l['units_30d'], 'units_365d' => $l['units_365d'],
                'linked' => $l['sku_id'] !== null && (int) $l['sku_id'] === $id, 'elsewhere' => $l['sku_id'] !== null && (int) $l['sku_id'] !== $id,
                'periods' => $periods,
            ];
        }
        $stock = [];
        $totals = ['on_hand' => 0, 'allocated' => 0, 'held' => 0, 'available' => 0];
        foreach ($q->stockOf($id) as $b) {
            $stock[] = [
                'code' => self::s($b['code']), 'name' => self::s($b['name']), 'sellable' => (bool) $b['is_sellable'], 'on_hand' => $b['on_hand'],
                'allocated' => $b['allocated'], 'held' => $b['held'], 'available' => $b['available'], 'counted_at' => self::s($b['counted_at']),
            ];
            if ($b['is_sellable']) {
                foreach (array_keys($totals) as $k) {
                    $totals[$k] += (int) $b[$k];
                }
            }
        }
        $ledger = [];
        foreach ($q->ledgerOf($id) as $r) {
            $ledger[] = [
                'id' => (int) $r['id'], 'warehouse' => self::s($r['warehouse']), 'bucket' => self::s($r['bucket']), 'delta' => $r['qty_delta'],
                'after' => $r['balance_after'], 'type' => self::s($r['movement_type']), 'ref' => self::s($r['order_ref'] ?? $r['doc_ref']),
                'actor' => self::s($r['actor']), 'note' => self::s($r['note']), 'at' => self::s($r['effective_at']),
            ];
        }
        $mergedInto = $sku['merged_into_sku_id'] === null ? null : $q->sku((int) $sku['merged_into_sku_id']);
        $barcodes = array_map(static fn (array $b): array => [
            'barcode' => self::s($b['barcode']), 'usable' => (bool) $b['is_usable'], 'units' => $b['units_per_scan'], 'source' => self::s($b['source']),
        ], $q->barcodesOf($id));
        if ($barcodes === []) {
            // Not seeded into sku_barcode yet: the usable barcodes of the listing it was minted from.
            foreach ($q->barcodesOfMany([$id])[$id] ?? [] as $code) {
                $barcodes[] = ['barcode' => $code, 'usable' => true, 'units' => 1, 'source' => 'listing it was minted from'];
            }
        }
        $cwp = $sku['origin'] === 'vpg_mint' ? ($q->skus([$id])[$id]['vpg_variant_id'] ?? null) : null;
        // Who supplies the item (IM4, I-2): shown to the people who may see suppliers.
        $suppliers = null;
        if ($ctx->me()->can('suppliers.view')) {
            $suppliers = array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'supplier_id' => (int) $r['supplier_id'], 'supplier' => (string) $r['supplier_code'], 'name' => (string) $r['supplier_name'],
                'status' => (string) $r['status'], 'code' => self::s($r['supplier_code_item']),
                'pack' => SupplierItemsController::pack((string) $r['purchase_unit'], (int) $r['units_per_pack']),
                'preferred' => (int) $r['is_preferred'] === 1 && (int) $r['is_active'] === 1, 'active' => (int) $r['is_active'] === 1,
                'price' => SupplierItemsController::gbp($r['last_pack_price']), 'price_on' => self::s($r['last_price_on']),
            ], $ctx->db->all(
                'SELECT i.id, i.supplier_id, s.code AS supplier_code, s.name AS supplier_name, s.status, i.supplier_code AS supplier_code_item, i.purchase_unit, '
                . 'i.units_per_pack, i.is_preferred, i.is_active, i.last_pack_price, i.last_price_on FROM supplier_item i JOIN supplier s ON s.id = i.supplier_id '
                . 'WHERE i.sku_id = ? ORDER BY i.is_active DESC, i.is_preferred DESC, s.name, i.units_per_pack',
                [$id],
            ));
        }
        // The duplicate groups of its listings (now or before a merge, M44): the group page holds the decision and its undo.
        $dupGroups = [];
        if ($ctx->me()->can('linking.view')) {
            // Its listings, and the listings named by the merges and splits that moved stock to or from it (a merged item's page).
            $lids = [...array_map(static fn (array $r): int => $r['id'], $rows), ...array_map('intval', array_column($ctx->db->all(
                "(SELECT id, listing_id FROM match_decision WHERE sku_id = ? AND action IN ('merge_skus', 'split') AND state = 'applied') UNION "
                . "(SELECT id, listing_id FROM match_decision WHERE merge_from_sku_id = ? AND action = 'merge_skus' AND state = 'applied') ORDER BY id DESC LIMIT 50",
                [$id, $id]), 'listing_id'))];
            foreach ((new Duplicates($ctx->db))->groupsOfListings($lids) as $gids) {
                foreach ($gids as $gid) {
                    $dupGroups[$gid] = true;
                }
            }
            ksort($dupGroups);
        }
        return $ctx->page('item', [
            'dup_groups' => array_map('intval', array_keys($dupGroups)),
            'sku' => [
                'id' => $id, 'code' => self::s($sku['code']), 'name' => self::s($sku['name']), 'brand' => self::s($sku['brand']),
                'policy' => self::s($sku['sell_policy']), 'strength_mg' => Html::dec($sku['strength_mg']), 'nic_type' => self::s($sku['nic_type']),
                'line' => self::s($sku['line']), 'form' => self::s($sku['form']), 'flavour' => self::s($sku['flavour']),
                'volume_ml' => Html::dec($sku['volume_ml']), 'puffs' => self::s($sku['puffs']), 'pack_units' => self::s($sku['pack_units']),
                'origin' => self::s($sku['origin']), 'origin_listing_id' => $sku['origin_listing_id'] === null ? null : (int) $sku['origin_listing_id'],
                'created_at' => self::s($sku['created_at']), 'counted_at' => self::s($sku['counted_at']),
                'cwp' => $cwp === null ? null : 'CWP-' . $cwp,
            ],
            'merged_into' => $mergedInto === null ? null : ['id' => (int) $mergedInto['id'], 'code' => self::s($mergedInto['code']), 'name' => self::s($mergedInto['name'])],
            'merged_from' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code']],
                $ctx->db->all('SELECT id, code FROM sku WHERE merged_into_sku_id = ? ORDER BY id LIMIT 50', [$id])),
            'barcodes' => $barcodes,
            'listings' => $rows,
            'stock' => $stock,
            'totals' => $totals,
            'ledger' => $ledger,
            'suppliers' => $suppliers,
        ], 200, ['title' => (string) $sku['code'], 'active' => 'search']);
    }

    private static function s(mixed $v): ?string
    {
        return is_string($v) || is_int($v) || is_float($v) ? ($v === '' ? null : (string) $v) : null;
    }
}
