<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\CwException;
use CW\Db;

/** Replacement ancestry belongs to one booked item and warehouse, including a partially completed import (XA2). */
final class OpeningLineage
{
    /**
     * The ancestors of the named openings, by item. Read afresh under the balance lock for the booking guard.
     *
     * @param list<string> $roots
     * @param ?list<int> $skuIds
     * @return array<int, array<string, true>>
     */
    public static function documents(Db $db, int $warehouseId, array $roots, ?array $skuIds = null): array
    {
        if ($roots === [] || $skuIds === []) {
            return [];
        }
        $sql = 'SELECT sku_id, doc_ref, replaces_doc_ref FROM opening_replacement WHERE warehouse_id = ?';
        $params = [$warehouseId];
        if ($skuIds !== null) {
            $sql .= ' AND sku_id IN (' . implode(',', array_fill(0, count($skuIds), '?')) . ')';
            array_push($params, ...$skuIds);
        }
        $edges = [];
        foreach ($db->all($sql, $params) as $r) {
            $edges[(int) $r['sku_id']][(string) $r['doc_ref']] = (string) $r['replaces_doc_ref'];
        }
        $out = [];
        foreach ($edges as $sku => $links) {
            foreach ($roots as $root) {
                $seen = [];
                $ref = $root;
                while (isset($links[$ref]) && !isset($seen[$ref])) {
                    $seen[$ref] = true;
                    $ref = $links[$ref];
                    if (!in_array($ref, $roots, true)) {
                        $out[$sku][$ref] = true;
                    }
                }
            }
        }
        return $out;
    }

    /** Called after the item's balance is locked, in the transaction that books its replacement. */
    public static function record(Db $db, int $warehouseId, int $skuId, string $docRef, string $replaces): void
    {
        $known = $db->value('SELECT replaces_doc_ref FROM opening_replacement WHERE warehouse_id = ? AND sku_id = ? AND doc_ref = ?',
            [$warehouseId, $skuId, $docRef]);
        if ($known !== null) {
            if ((string) $known !== $replaces) {
                throw new CwException('opening_conflict', 'this opening already replaced another document for this item', 409);
            }
            return;
        }
        $db->exec('INSERT INTO opening_replacement (warehouse_id, sku_id, doc_ref, replaces_doc_ref) VALUES (?, ?, ?, ?)',
            [$warehouseId, $skuId, $docRef, $replaces]);
    }
}
