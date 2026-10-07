<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Audit;
use CW\Caller;
use CW\Catalogue\ItemRules;
use CW\CwException;
use CW\Db;
use CW\PurchaseOrders\PoMath;
use CW\PurchaseOrders\PurchaseOrders;

/**
 * "Create draft PO" from the reorder list (spec §7.4; docs/decisions.md I67): the picked items, grouped by their PREFERRED
 * supplier, become one draft PO per supplier (source 'reorder'), each line the preferred supplier item with the packs the
 * buyer kept or typed and the list's suggestion in `suggested_units`; the price is the supplier item's last price and the
 * VAT code the supplier's (PurchaseOrders::saveDraft defaults). Items without a preferred supplier, with an inactive
 * supplier, merged or blocked by their item card (IM3, I103) are skipped and listed. Everything runs in ONE transaction (the caller's FormOnce transaction: the
 * same form sent twice replays the same drafts). A draft whose net is below the supplier's minimum order is warned about.
 */
final class DraftPos
{
    public const MAX_PICKS = 2000;

    public function __construct(private readonly Db $db, private readonly PurchaseOrders $pos, private readonly ReorderList $list)
    {
    }

    /**
     * @param list<array{sku_id: int, packs: int}> $picks
     * @param string $stock the list's stock source (cw | site): the suggestion stored with each line is the one the buyer saw
     * @return array{drafts: list<array{document_id: int, supplier_id: int, supplier: string, lines: int, units: int, net: string, below_minimum: bool}>,
     *   skipped: list<array{sku_id: int, code: string, reason: string}>, warnings: list<string>}
     */
    public function create(Caller $caller, array $picks, string $stock = 'cw', ?string $formKey = null): array
    {
        if (count($picks) > self::MAX_PICKS) {
            throw new CwException('too_many_lines', 'at most ' . self::MAX_PICKS . ' items at once', 422);
        }
        $want = [];
        foreach ($picks as $p) {
            if (!is_array($p) || !is_int($p['sku_id'] ?? null) || !is_int($p['packs'] ?? null) || $p['sku_id'] < 1 || $p['packs'] < 0 || $p['packs'] > PoMath::MAX_PACKS) {
                throw new CwException('bad_pick', 'each picked item has an item and a number of packs from 0 to ' . number_format(PoMath::MAX_PACKS), 400);
            }
            if (isset($want[$p['sku_id']])) {
                throw new CwException('bad_pick', 'an item is picked twice', 400);
            }
            if ($p['packs'] > 0) {
                $want[$p['sku_id']] = $p['packs'];
            }
        }
        if ($want === []) {
            throw new CwException('nothing_picked', 'tick at least one line with more than 0 packs', 422);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $want, $stock, $formKey): array {
            $f = ReorderList::filters(['show' => 'all', 'stock' => $stock]);
            $lines = [];
            foreach ($this->list->lines($f, array_keys($want)) as $l) {
                $lines[(int) $l['sku_id']] = $l;
            }
            $skipped = [];
            $bySupplier = [];
            foreach ($want as $sku => $packs) {
                $l = $lines[$sku] ?? null;
                $code = $l['code'] ?? (string) ($db->value('SELECT code FROM sku WHERE id = ?', [$sku]) ?? "#{$sku}");
                $reason = match (true) {
                    $l === null => 'not on the reorder list',
                    in_array('merged', $l['flags'], true) => (string) $l['never'] . ': order that item instead',
                    // A confirmed item card that breaks a TRPR or the single-use rule blocks ordering (IM3, I103); a discontinued item may
                    // still be ordered on purpose (the buyer typed the packs).
                    in_array('card_blocked', $l['flags'], true) => 'blocked by its item card: ' . ItemRules::labels($l['card_blocked'])
                        . ': it cannot be ordered (correct the item card and confirm it again if it is wrong)',
                    $l['supplier_item_id'] === null => 'no preferred supplier: mark one of its supplier items as preferred',
                    $l['supplier_status'] === 'inactive' => "its preferred supplier {$l['supplier']} is inactive",
                    default => null,
                };
                if ($reason !== null) {
                    $skipped[] = ['sku_id' => $sku, 'code' => $code, 'reason' => $reason];
                    continue;
                }
                $bySupplier[(int) $l['supplier_id']][] = ['code' => $code, 'line' => ['supplier_item_id' => (int) $l['supplier_item_id'], 'packs' => $packs,
                    'suggested_units' => (int) $l['units']]];
            }
            ksort($bySupplier);
            $drafts = [];
            $warnings = [];
            foreach ($bySupplier as $supplierId => $items) {
                usort($items, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
                $d = $this->pos->createDraft($caller, $supplierId, [], 'reorder');
                $d = $this->pos->saveDraft($caller, $d->id, $d->version, [], array_map(static fn (array $i): array => $i['line'], $items));
                $s = (array) $db->one('SELECT s.code, s.min_order_value, po.net_total FROM purchase_order po JOIN supplier s ON s.id = po.supplier_id WHERE po.document_id = ?',
                    [$d->id]);
                $below = $s['min_order_value'] !== null && PoMath::e2((string) $s['net_total']) < PoMath::e2((string) $s['min_order_value']);
                if ($below) {
                    $warnings[] = "The draft for {$s['code']} is " . PoMath::money(PoMath::e2((string) $s['net_total'])) . ' net, below its minimum order of '
                        . PoMath::money(PoMath::e2((string) $s['min_order_value'])) . '.';
                }
                $drafts[] = ['document_id' => $d->id, 'supplier_id' => $supplierId, 'supplier' => (string) $s['code'], 'lines' => count($items),
                    'units' => (int) $db->value('SELECT COALESCE(SUM(qty), 0) FROM document_line WHERE document_id = ?', [$d->id]), 'net' => (string) $s['net_total'],
                    'below_minimum' => $below];
            }
            Audit::write($db, $caller, 'reorder.draft_pos', 'document', $drafts === [] ? null : (string) $drafts[0]['document_id'], null,
                ['drafts' => array_column($drafts, 'document_id'), 'items' => count($want), 'skipped' => array_column($skipped, 'sku_id'), 'stock' => $stock]
                + ($formKey === null ? [] : ['form_key' => $formKey]));
            return ['drafts' => $drafts, 'skipped' => $skipped, 'warnings' => $warnings];
        });
    }
}
