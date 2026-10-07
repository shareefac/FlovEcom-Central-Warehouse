<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Catalogue\BarcodeReviews;
use CW\Catalogue\CardProposals;
use CW\Catalogue\ItemCards;
use CW\Catalogue\ItemRules;
use CW\CwException;
use CW\Ui\Context;
use CW\Ui\Duplicates;
use CW\Ui\FormOnce;
use CW\Ui\Html;
use CW\Ui\HtmlResponse;

/**
 * /ui/items/{id}: who an item is, its item card (IM3: the legal and buying fields, the rules they break, suggestions to accept,
 * the confirmation, the history), its barcodes (with the forms to add, remove and set units per scan for catalogue.edit),
 * every listing linked to it (now or before), the selling mode CW writes on each website with the switch (IM10, SellingModeController),
 * its stock and (suppliers.view) who supplies it. ItemCardsController's actions
 * redraw this page with their error (page()).
 */
final class ItemController
{
    public const NOTICES = [
        'card_saved' => 'Item card saved.',
        'card_saved_unconfirmed' => 'Item card saved. A legal field changed, so the card is no longer confirmed: check it and confirm it again.',
        'card_unchanged' => 'Nothing changed.',
        'accepted' => 'Suggestion used: the item card now has it.',
        'confirmed' => 'Item card confirmed. If a person confirms fields that break a rule, the item is blocked instead of warned about.',
        'confirmed_blocked' => 'Item card confirmed. The item breaks a rule, so it is now BLOCKED: ' . ItemCards::BLOCK_EFFECT
            . ' It stays blocked until someone corrects the card and confirms it again.',
        'confirmed_lifted' => 'Item card confirmed. The fields no longer break the rules the last confirmation blocked, so the item is no longer blocked.',
        'already_confirmed' => 'The item card was already confirmed.',
        'barcode_added' => 'Barcode added.',
        'barcode_added_unusable' => 'Barcode added, but unusable for now: a barcode review of it is open (Items > Barcode review).',
        'barcode_removed' => 'Barcode removed. The barcode sync will not add it back to this item.',
        'units_saved' => 'Units per scan saved.',
        'units_unchanged' => 'Nothing changed.',
    ];

    public function show(Context $ctx): HtmlResponse
    {
        $notice = $ctx->req->param('notice') ?? '';
        return $this->page($ctx, 200, null, self::NOTICES[$notice] ?? SellingModeController::NOTICES[$notice] ?? null);
    }

    /**
     * The item page, also after a refused card or barcode action ($error shown on top, answered under $status; $typed: what
     * the barcode forms had, so a refused add keeps it).
     *
     * @param array<string, mixed> $typed ('selling_mode': what a refused selling-mode form had)
     */
    public function page(Context $ctx, int $status, ?CwException $error, ?string $notice = null, array $typed = []): HtmlResponse
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
        $canEdit = $ctx->me()->can('catalogue.edit') && $sku['merged_into_sku_id'] === null;
        $barcodes = array_map(static fn (array $b): array => [
            'barcode' => self::s($b['barcode']), 'usable' => (bool) $b['is_usable'], 'units' => (int) $b['units_per_scan'], 'source' => self::s($b['source']),
            'note' => self::s($b['note'] ?? null), 'stored' => true, 'removeKey' => $canEdit ? FormOnce::newKey() : null, 'unitsKey' => $canEdit ? FormOnce::newKey() : null,
        ], $ctx->db->all('SELECT barcode, is_usable, units_per_scan, source, note FROM sku_barcode WHERE sku_id = ? ORDER BY units_per_scan, barcode', [$id]));
        if ($barcodes === []) {
            // Not seeded into sku_barcode yet: the usable barcodes of the listing it was minted from.
            foreach ($q->barcodesOfMany([$id])[$id] ?? [] as $code) {
                $barcodes[] = ['barcode' => $code, 'usable' => true, 'units' => 1, 'source' => 'listing it was minted from', 'note' => null, 'stored' => false,
                    'removeKey' => null, 'unitsKey' => null];
            }
        }
        $reviews = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'barcode' => (string) $r['barcode'],
            'reason' => BarcodeReviews::REASONS[(string) $r['reason']] ?? (string) $r['reason']],
            $ctx->db->all("SELECT id, barcode, reason FROM barcode_review WHERE status = 'open' AND (claimant_sku_id = ? OR holder_sku_id = ?) ORDER BY id LIMIT 50", [$id, $id]));
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
        return $ctx->page('item', self::cardVars($ctx, $id, $canEdit) + [
            'error' => $error?->getMessage(),
            'errorCode' => $error?->errorCode,
            'errorDetail' => $error?->detail ?? [],
            'canEditBarcodes' => $canEdit,
            'barcodeAddKey' => $canEdit ? ($typed['form_key'] ?? FormOnce::newKey()) : null,
            'typedBarcode' => $typed['barcode'] ?? '',
            'typedUnits' => $typed['units'] ?? '1',
            'barcodeReviews' => $reviews,
            'canSeeReviews' => $ctx->me()->can('catalogue.edit'),
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
            // IM10 (I158): what CW writes on each website, and the selling-mode switch.
            'sellingMode' => SellingModeController::vars($ctx, $id, $sku, is_array($typed['selling_mode'] ?? null) ? $typed['selling_mode'] : []),
        ], $status, ['title' => (string) $sku['code'], 'active' => 'search', 'notice' => $notice]);
    }

    /**
     * The item card part of the page: the values as people read them, the state, the rules (breaches with their level, what a
     * confirmation still needs, advice), the suggestions with their accept forms (catalogue.edit), the confirm form and the
     * history.
     *
     * @return array<string, mixed>
     */
    public static function cardVars(Context $ctx, int $id, bool $canEdit): array
    {
        $svc = $ctx->itemCards();
        $card = $svc->card($id);
        $exists = $card['version'] > 0;
        $state = !$exists ? 'none' : ($card['confirmed_at'] !== null ? 'confirmed' : ($card['first_confirmed_at'] !== null ? 'changed' : 'unconfirmed'));
        $values = [];
        foreach (ItemCards::FIELDS as $k => $label) {
            $v = $card[$k];
            $values[] = ['field' => $k, 'label' => $label, 'shown' => $v === null ? null : CardProposals::shown($k, $v)
                . ($k === 'flavour' && $card['flavour_status'] === 'proposed' ? ' (proposed by a file, not confirmed)' : '')];
        }
        $st = ItemRules::status($card);
        $now = ItemRules::breaches($card);
        $proposals = null;
        $disposable = [];
        $disagree = [];
        $fileFlavour = null;
        if ($canEdit) {
            $p = (new CardProposals($ctx->db))->of($id, $card);
            $proposals = [];
            foreach ($p['fields'] as $field => $list) {
                foreach ($list as $one) {
                    $proposals[] = ['field' => $field, 'label' => ItemCards::FIELDS[$field], 'value' => self::formValue($one['value']), 'shown' => $one['shown'],
                        'sources' => $one['sources'], 'formKey' => FormOnce::newKey()];
                }
            }
            $disposable = $p['disposable_sources'];
            $disagree = array_map(static fn (string $f): string => ItemCards::FIELDS[$f], $p['disagree']);
            if ($card['flavour'] !== null && $card['flavour_status'] === 'proposed') {
                $fileFlavour = ['value' => $card['flavour'], 'formKey' => FormOnce::newKey()];
            }
        }
        return [
            'card' => [
                'exists' => $exists, 'version' => $card['version'], 'state' => $state, 'values' => $values,
                'confirmed_by' => $card['confirmed_by_name'] ?? ($card['confirmed_actor'] ?? null), 'confirmed_at' => $card['confirmed_at'],
                'first_confirmed_at' => $card['first_confirmed_at'], 'updated_by' => $card['updated_by_name'] ?? $card['updated_actor'], 'updated_at' => $card['updated_at'],
            ],
            'rules' => [
                'level' => $st['level'],
                // A blocked rule the fields no longer break (corrected, emptied or retyped since the last confirmation) still blocks
                // until a person confirms the card again (I113): `still` says which.
                'blocked' => array_map(static fn (string $r): array => ['code' => $r, 'label' => ItemRules::RULES[$r], 'why' => ItemRules::WHY[$r],
                    'still' => in_array($r, $now, true)], $st['blocked']),
                'warnings' => array_map(static fn (string $r): array => ['code' => $r, 'label' => ItemRules::RULES[$r], 'why' => ItemRules::WHY[$r]], $st['warnings']),
                'blockEffect' => ItemCards::BLOCK_EFFECT,
                'missing' => array_map(static fn (string $f): string => ItemRules::neededLabel($f, $card['product_type']), ItemRules::missing($card)),
                'advice' => ItemRules::advice($card),
            ],
            'canEditCard' => $canEdit,
            'proposals' => $proposals,
            'fileFlavour' => $fileFlavour,
            'disposableSources' => $disposable,
            'disagree' => $disagree,
            'confirmKey' => $canEdit && $exists && $state !== 'confirmed' ? FormOnce::newKey() : null,
            'cardHistory' => array_map(static function (array $h): array {
                $parts = [];
                foreach ($h['changes'] as $f => $c) {
                    $label = ItemCards::FIELDS[$f] ?? ($f === 'flavour_status' ? 'flavour status' : $f);
                    $parts[] = $label . ': ' . self::historyValue($f, $c['before'] ?? null) . ' → ' . self::historyValue($f, $c['after'] ?? null);
                }
                $what = match ($h['kind']) {
                    'confirm' => 'confirmed' . (($h['detail']['breaches'] ?? []) !== [] ? ' (blocked: ' . ItemRules::labels($h['detail']['breaches']) . ')' : '')
                        . (($h['detail']['lifted'] ?? []) !== [] ? ' (block lifted: ' . ItemRules::labels($h['detail']['lifted']) . ')' : ''),
                    'accept' => 'used a suggestion: ' . implode('; ', $parts),
                    'import' => 'imported from a file (run ' . ($h['detail']['import_run'] ?? '?') . '): ' . implode('; ', $parts),
                    default => implode('; ', $parts),
                };
                return ['version' => $h['version'], 'what' => $what, 'who' => $h['who'], 'at' => $h['at']];
            }, $svc->history($id, 20)),
        ];
    }

    /** A card value as the accept form sends it (ItemCards::check reads it back to the same value). */
    private static function formValue(mixed $v): string
    {
        return is_int($v) ? (string) $v : (string) $v;
    }

    private static function historyValue(string $field, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '(empty)';
        }
        return $field === 'flavour_status' ? (string) $v : CardProposals::shown($field, $v);
    }

    private static function s(mixed $v): ?string
    {
        return is_string($v) || is_int($v) || is_float($v) ? ($v === '' ? null : (string) $v) : null;
    }
}
