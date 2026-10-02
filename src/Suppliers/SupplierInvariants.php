<?php

declare(strict_types=1);

namespace CW\Suppliers;

use CW\Db;

/**
 * The nightly checks of suppliers and supplier items (0009; called at the end of CW\Invariants::check, so
 * bin/invariants.php, the hammer and every stock test run them). Read-only; at most MAX_PER_CHECK violations per check.
 *
 *  S1. a supplier is pending_approval if and only if it has exactly one open approval task with reason new_supplier or
 *      reactivation; every open supplier task names an existing supplier; an open import_route approval (an overseas
 *      supplier's route, or an overseas supplier made UK: I72) and an open supplier_changed review are on an active
 *      supplier.
 *  S2. every active supplier has approved_by/at, and its latest decided approval task with reason new_supplier or
 *      reactivation is `approved`, by someone other than its opener, and that person is approved_by.
 *  S3. an overseas active supplier whose import route is approved (import_route_approved_at) has an approved task
 *      (new_supplier, reactivation or import_route) decided at that time or earlier.
 *  S4. supplier_item.last_pack_price / last_price_on / last_price_source equal the newest (effective_on, id) history row
 *      whose source is not `po` and whose units_per_pack is the item's current one (I74), or are all NULL when there is none.
 *  S5. every supplier_item.sku_id exists (a merged item is not a violation: the screens tag it "merged into CW-x").
 */
final class SupplierInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::pending($db), ...self::active($db), ...self::routes($db), ...self::lastPrices($db), ...self::items($db)];
    }

    /** @return list<string> S1 */
    private static function pending(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            "SELECT s.id, s.code, s.status, COUNT(t.id) AS n FROM supplier s LEFT JOIN review_task t ON t.subject_type = 'supplier' AND t.subject_id = s.id "
            . "AND t.state = 'open' AND t.kind = 'approval' AND t.reason IN ('new_supplier', 'reactivation') "
            . "GROUP BY s.id HAVING (s.status = 'pending_approval') <> (COUNT(t.id) = 1) ORDER BY s.id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "supplier {$r['id']} ({$r['code']}) is {$r['status']} but has {$r['n']} open activation tasks";
        }
        foreach ($db->all(
            "SELECT t.id, t.subject_id, t.kind, t.reason, s.id AS s_id, s.status, s.is_overseas FROM review_task t LEFT JOIN supplier s ON s.id = t.subject_id "
            . "WHERE t.subject_type = 'supplier' AND t.state = 'open' AND (s.id IS NULL "
            . "  OR (t.kind = 'approval' AND t.reason = 'import_route' AND s.status <> 'active') "
            . "  OR (t.kind = 'review' AND s.status <> 'active') "
            . "  OR (t.kind = 'approval' AND t.reason NOT IN ('new_supplier', 'reactivation', 'import_route')) "
            . "  OR (t.kind = 'review' AND t.reason <> 'supplier_changed')) ORDER BY t.id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = $r['s_id'] === null
                ? "open supplier task {$r['id']} names supplier {$r['subject_id']}, which does not exist"
                : "open supplier task {$r['id']} ({$r['kind']}, {$r['reason']}) on supplier {$r['subject_id']}, which is {$r['status']}"
                    . ((int) $r['is_overseas'] === 1 ? ' (overseas)' : '');
        }
        return $v;
    }

    /** @return list<string> S2 */
    private static function active(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT s.id, s.code, s.approved_by, s.approved_at, t.id AS t_id, t.state, t.opened_by, t.decided_by FROM supplier s '
            . 'LEFT JOIN review_task t ON t.id = (SELECT MAX(x.id) FROM review_task x WHERE x.subject_type = \'supplier\' AND x.subject_id = s.id '
            . "  AND x.kind = 'approval' AND x.reason IN ('new_supplier', 'reactivation') AND x.state IN ('approved', 'rejected')) "
            . "WHERE s.status = 'active' AND (s.approved_by IS NULL OR s.approved_at IS NULL OR t.id IS NULL OR t.state <> 'approved' OR t.decided_by IS NULL "
            . '  OR t.decided_by <=> t.opened_by OR NOT (t.decided_by <=> s.approved_by)) ORDER BY s.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "active supplier {$r['id']} ({$r['code']}) " . match (true) {
                $r['approved_by'] === null || $r['approved_at'] === null => 'has no approved_by/approved_at',
                $r['t_id'] === null => 'has no decided activation task',
                $r['state'] !== 'approved' => "was last {$r['state']} at activation (task {$r['t_id']})",
                default => "was approved by staff " . ($r['decided_by'] ?? 'NULL') . " (task {$r['t_id']}, opened by " . ($r['opened_by'] ?? 'NULL')
                    . ", approved_by {$r['approved_by']}): not a second person or not the approver on the row",
            };
        }
        return $v;
    }

    /** @return list<string> S3 */
    private static function routes(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            "SELECT s.id, s.code, s.import_route_approved_at FROM supplier s WHERE s.status = 'active' AND s.is_overseas = 1 AND s.import_route_approved_at IS NOT NULL "
            . "AND NOT EXISTS (SELECT 1 FROM review_task t WHERE t.subject_type = 'supplier' AND t.subject_id = s.id AND t.kind = 'approval' "
            . "  AND t.reason IN ('new_supplier', 'reactivation', 'import_route') AND t.state = 'approved' AND t.decided_at <= s.import_route_approved_at) "
            . 'ORDER BY s.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "overseas supplier {$r['id']} ({$r['code']}) has its import route approved at {$r['import_route_approved_at']} without an approved task by then";
        }
        return $v;
    }

    /** @return list<string> S4 */
    private static function lastPrices(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT i.id, i.units_per_pack AS upp, i.last_pack_price, i.last_price_on, CAST(i.last_price_source AS CHAR) AS last_source, p.id AS p_id, p.pack_price, p.effective_on, '
            . 'CAST(p.source AS CHAR) AS p_source FROM supplier_item i LEFT JOIN supplier_item_price p ON p.id = ('
            . "  SELECT x.id FROM supplier_item_price x WHERE x.supplier_item_id = i.id AND x.source <> 'po' AND x.units_per_pack = i.units_per_pack "
            . '  ORDER BY x.effective_on DESC, x.id DESC LIMIT 1) '
            . 'WHERE NOT (i.last_pack_price <=> p.pack_price AND i.last_price_on <=> p.effective_on AND CAST(i.last_price_source AS CHAR) <=> CAST(p.source AS CHAR)) '
            . 'ORDER BY i.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "supplier item {$r['id']}: last price " . ($r['last_pack_price'] ?? 'NULL') . ' on ' . ($r['last_price_on'] ?? 'NULL') . ' (' . ($r['last_source'] ?? 'NULL') . ')'
                . ($r['p_id'] === null ? ' but it has no import/manual/invoice price for its pack of ' . $r['upp'] : " but its newest price is {$r['pack_price']} on {$r['effective_on']} ({$r['p_source']}, row {$r['p_id']})");
        }
        return $v;
    }

    /** @return list<string> S5 */
    private static function items(Db $db): array
    {
        $v = [];
        foreach ($db->all('SELECT i.id, i.sku_id FROM supplier_item i LEFT JOIN sku s ON s.id = i.sku_id WHERE s.id IS NULL ORDER BY i.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "supplier item {$r['id']} names item {$r['sku_id']}, which does not exist";
        }
        return $v;
    }
}
