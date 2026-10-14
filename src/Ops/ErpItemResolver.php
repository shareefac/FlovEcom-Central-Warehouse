<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Db;

/**
 * Which CW item an ERPNext item is, for the opening import (pack I, docs/decisions.md PI3). Never a guess: one route or a reason.
 *
 *  1. CW's ERPNext link (sku_erp_item: item code -> item x units_per_item), the override the plan keeps for items no store lists;
 *  2. else the ERPNext item's Product ID (the item list) names a variant of the store erp_opening.listing_store, whose link must be
 *     `mapped` to an item: that item x the listing's units_per_item (plan §9: ERPNext goods-in resolves through Vape and Go's link).
 *
 * Reasons it does not resolve: no_item_code; ambiguous_erp_link (two CW links read as this code once case and the spaces at the
 * ends are set aside: which one is meant is not guessed, PI18); no_item_list (no item list was given and CW has no ERPNext link for it); no_product_id
 * (the item list has no Product ID for it); ambiguous_product_id (the item list gives it two); no_listing_store (no store is set);
 * unknown_listing (the store has no such variant in CW); unlinked_listing (not matched to an item); quarantined_listing (its match is
 * in doubt); merged_item (the item was merged into another, M42: the listing is relinked to the kept one).
 *
 * ERPNext's item codes are compared as ERPNext compares them: letter case and the spaces at the ends ignored, the spaces inside kept
 * (ErpOpeningRules::itemKey; review of 14 Oct 2026, PI18).
 */
final class ErpItemResolver
{
    /** @var array<string, array{sku_id: int, u: int}|false>|null false: two links read the same (ambiguous) */
    private ?array $erpLinks = null;
    /** @var array<string, array{sku_id: ?int, u: int, status: string}>|null */
    private ?array $listings = null;
    /** @var array<int, true>|null */
    private ?array $merged = null;

    /**
     * @param array<string, string>|null $productIds  ErpOpeningRules::itemKey(item code) => the store's variant number; null = no item list
     * @param array<string, list<string>> $ambiguous   item codes the item list gives two product ids
     */
    public function __construct(private readonly Db $db, private readonly string $store, private readonly ?array $productIds,
        private readonly array $ambiguous = [])
    {
    }

    /** @return array{sku_id: int, u: int, via: string}|array{reason: string, product_id?: string} */
    public function resolve(string $itemCode): array
    {
        $k = ErpOpeningRules::itemKey($itemCode);
        if ($k === '') {
            return ['reason' => 'no_item_code'];
        }
        $link = $this->erpLinks()[$k] ?? null;
        if ($link === false) {
            return ['reason' => 'ambiguous_erp_link'];
        }
        if ($link !== null) {
            return ($this->merged()[$link['sku_id']] ?? false) ? ['reason' => 'merged_item'] : $link + ['via' => 'erp_link'];
        }
        if (isset($this->ambiguous[$k])) {
            return ['reason' => 'ambiguous_product_id'];
        }
        if ($this->productIds === null) {
            return ['reason' => 'no_item_list'];
        }
        $pid = $this->productIds[$k] ?? null;
        if ($pid === null) {
            return ['reason' => 'no_product_id'];
        }
        $r = $this->listing($pid);
        return isset($r['reason']) ? $r + ['product_id' => $pid] : $r;
    }

    /** A variant of the store (the pending-sales file names variants directly). @return array{sku_id: int, u: int, via: string}|array{reason: string} */
    public function listing(string $variant): array
    {
        if ($this->store === '') {
            return ['reason' => 'no_listing_store'];
        }
        $l = $this->listings()[trim($variant)] ?? null;
        if ($l === null) {
            return ['reason' => 'unknown_listing'];
        }
        if ($l['status'] === 'quarantined') {
            return ['reason' => 'quarantined_listing'];
        }
        if ($l['status'] !== 'mapped' || $l['sku_id'] === null) {
            return ['reason' => 'unlinked_listing'];
        }
        if ($this->merged()[$l['sku_id']] ?? false) {
            return ['reason' => 'merged_item'];
        }
        return ['sku_id' => $l['sku_id'], 'u' => $l['u'], 'via' => 'listing'];
    }

    /** @return array<string, array{sku_id: int, u: int}|false> false: two links read as this code with another item or units (never overwritten) */
    private function erpLinks(): array
    {
        if ($this->erpLinks === null) {
            $this->erpLinks = [];
            foreach ($this->db->all('SELECT item_code, sku_id, units_per_item FROM sku_erp_item ORDER BY item_code') as $r) {
                $k = ErpOpeningRules::itemKey((string) $r['item_code']);
                $link = ['sku_id' => (int) $r['sku_id'], 'u' => (int) $r['units_per_item']];
                $seen = $this->erpLinks[$k] ?? null;
                $this->erpLinks[$k] = $seen === null || $seen === $link ? $link : false;
            }
        }
        return $this->erpLinks;
    }

    /** @return array<string, array{sku_id: ?int, u: int, status: string}> */
    private function listings(): array
    {
        if ($this->listings === null) {
            $this->listings = [];
            foreach ($this->db->all('SELECT l.external_variant_id v, l.sku_id, l.units_per_item u, l.status FROM channel_listing l JOIN channel c ON c.id = l.channel_id '
                . 'WHERE c.code = ?', [$this->store]) as $r) {
                $this->listings[(string) $r['v']] = ['sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'], 'u' => (int) $r['u'], 'status' => (string) $r['status']];
            }
        }
        return $this->listings;
    }

    /** @return array<int, true> */
    private function merged(): array
    {
        if ($this->merged === null) {
            $this->merged = [];
            foreach ($this->db->column('SELECT id FROM sku WHERE merged_into_sku_id IS NOT NULL') as $id) {
                $this->merged[(int) $id] = true;
            }
        }
        return $this->merged;
    }
}
