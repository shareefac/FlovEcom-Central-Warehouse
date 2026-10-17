<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\CwException;
use CW\Db;

/**
 * The re-check an opening tool makes INSIDE the transaction that books an item, after its balance is locked (pack I, review of 14 Oct
 * 2026, docs/decisions.md PI17): since the run planned the item, has another opening (any doc_ref but the run's own and the one it
 * builds on) or a count reached it? Then the item is refused (409 opening_changed) and nothing of it is booked; the run stops there,
 * whole items only, and the same command run again lists the item as skipped. A booking of the same item at the same warehouse by
 * another tool waits for this transaction's balance lock or committed before it, so what it booked is seen here (READ COMMITTED);
 * the shared OpeningLock keeps the tools from planning at the same time at all.
 */
final class OpeningGuard
{
    /**
     * @param list<string> $ownDocRefs the run's own doc_ref and the opening it builds on (the estimate a rebase rebases, the opening
     *                                 --replaces takes back), compared byte for byte
     * @return \Closure(list<int>): void  for Movements::record()/recordOpening()
     */
    public static function closure(Db $db, array $ownDocRefs, int $warehouseId): \Closure
    {
        $own = array_values(array_unique(array_filter($ownDocRefs, static fn (?string $d): bool => $d !== null && $d !== '')));
        return static function (array $skuIds) use ($db, $own, $warehouseId): void {
            if ($skuIds === []) {
                return;
            }
            $in = implode(',', array_fill(0, count($skuIds), '?'));
            $not = $own === [] ? '' : ' AND doc_ref COLLATE utf8mb4_0900_bin NOT IN (' . implode(',', array_fill(0, count($own), '?')) . ')';
            $params = $own;
            foreach (OpeningLineage::documents($db, $warehouseId, $own, $skuIds) as $sku => $refs) {
                $not .= ' AND NOT (warehouse_id = ? AND sku_id = ? AND doc_ref COLLATE utf8mb4_0900_bin IN ('
                    . implode(',', array_fill(0, count($refs), '?')) . '))';
                array_push($params, $warehouseId, $sku, ...array_map('strval', array_keys($refs)));
            }
            $hit = $db->one(
                "(SELECT id AS sku_id, 'counted' AS why, NULL AS doc_ref FROM sku WHERE id IN ({$in}) AND counted_at IS NOT NULL LIMIT 1)
                 UNION ALL (SELECT sku_id, 'counted', NULL FROM stock_balance WHERE sku_id IN ({$in}) AND counted_at IS NOT NULL LIMIT 1)
                 UNION ALL (SELECT sku_id, 'counted', NULL FROM stock_ledger WHERE sku_id IN ({$in}) AND movement_type = 'count' LIMIT 1)
                 UNION ALL (SELECT sku_id, 'another opening', doc_ref FROM stock_ledger WHERE sku_id IN ({$in}) AND actor = ? AND bucket = 'on_hand'{$not} LIMIT 1)
                 LIMIT 1",
                [...$skuIds, ...$skuIds, ...$skuIds, ...$skuIds, 'system:' . OpeningEstimate::ACTOR_JOB, ...$params],
            );
            if ($hit !== null) {
                throw new CwException('opening_changed', sprintf('item %d was %s since this run was planned%s: nothing booked for it (run the same '
                    . 'command again: it is then listed as skipped)', (int) $hit['sku_id'], $hit['why'] === 'counted' ? 'counted' : 'given another opening',
                    $hit['doc_ref'] === null ? '' : ' (' . $hit['doc_ref'] . ')'), 409, ['sku_id' => (int) $hit['sku_id'], 'why' => $hit['why']]);
            }
        };
    }
}
