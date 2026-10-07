<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\Db;

/**
 * The nightly checks of the item cards and the barcode review (0016; called at the end of CW\Invariants::check, so
 * bin/invariants.php, the hammer and every stock test run them; docs/decisions.md I101, I107). The app login may UPDATE
 * item_card (it is how a card changes) but may only ADD item_card_change rows: a card rewritten outside ItemCards, or a history
 * row added by hand, is found here. Read-only; at most MAX_PER_CHECK violations per check.
 *
 *  IC1. every card has history rows for exactly versions 1..version; no history row without a card.
 *  IC2. every card's values are the `card` snapshot of its latest history row (ItemCards::SNAPSHOT), and a confirm row's
 *       snapshot carries a confirmation.
 *  IC3. a barcode with an open review is unusable on whichever item has it (the rule "a barcode on two items is unusable until
 *       fixed"; BarcodeReviews restores it only when its last open review is decided).
 */
final class CatalogueInvariants
{
    private const MAX_PER_CHECK = 50;
    private const CHUNK = 2000;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $v = [];
        foreach ($db->all('SELECT c.sku_id, c.version, COUNT(h.id) AS n, MIN(h.version) AS lo, MAX(h.version) AS hi FROM item_card c '
            . 'LEFT JOIN item_card_change h ON h.sku_id = c.sku_id GROUP BY c.sku_id, c.version '
            . 'HAVING n <> c.version OR lo <> 1 OR hi <> c.version OR lo IS NULL LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "item card {$r['sku_id']}: version {$r['version']} but its history has {$r['n']} rows, versions {$r['lo']}..{$r['hi']} (IC1)";
        }
        foreach ($db->all('SELECT DISTINCT h.sku_id FROM item_card_change h LEFT JOIN item_card c ON c.sku_id = h.sku_id WHERE c.sku_id IS NULL LIMIT '
            . self::MAX_PER_CHECK) as $r) {
            $v[] = "item card history of item {$r['sku_id']}, which has no card (IC1)";
        }
        $after = 0;
        $found = 0;
        while ($found < self::MAX_PER_CHECK) {
            $rows = $db->all('SELECT c.*, h.kind, h.card FROM item_card c JOIN item_card_change h ON h.sku_id = c.sku_id AND h.version = c.version '
                . 'WHERE c.sku_id > ? ORDER BY c.sku_id LIMIT ' . self::CHUNK, [$after]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $after = (int) $r['sku_id'];
                $snap = json_decode((string) $r['card'], true);
                $now = ItemCards::snapshot($r);
                $diff = [];
                foreach (ItemCards::SNAPSHOT as $k) {
                    if (!is_array($snap) || !array_key_exists($k, $snap) || $snap[$k] !== $now[$k]) {
                        $diff[] = $k;
                    }
                }
                if ($diff !== []) {
                    $v[] = "item card {$r['sku_id']} version {$r['version']}: " . implode(', ', $diff) . ' differ from its history (changed outside CW?) (IC2)';
                    $found++;
                }
                if ($r['kind'] === 'confirm' && (!is_array($snap) || ($snap['confirmed_at'] ?? null) === null)) {
                    $v[] = "item card {$r['sku_id']} version {$r['version']} is a confirmation without a confirmed time (IC2)";
                    $found++;
                }
            }
        }
        foreach ($db->all("SELECT b.barcode, s.code FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.is_usable = 1 AND EXISTS "
            . "(SELECT 1 FROM barcode_review r WHERE r.barcode = b.barcode AND r.status = 'open') ORDER BY b.barcode LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "barcode {$r['barcode']} of {$r['code']} is usable while a barcode review of it is open (IC3)";
        }
        return array_slice($v, 0, 4 * self::MAX_PER_CHECK);
    }
}
