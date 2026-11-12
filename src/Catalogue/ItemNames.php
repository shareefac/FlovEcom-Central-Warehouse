<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * An item's own name in CW (sku.name), changed by a person on the item page (the owner, 12 Nov 2026: "i will change the product name in cw
 * ... just letting you know you dont have to do anything other than giving me an option to change the name"; docs/decisions.md IR1-IR6).
 *
 *  - CW's name only: no website product name changes (CW sends no names to the shops), and the item's code, stock, links, barcodes and
 *    card stay as they are; no stock feed row is written (nothing a website reads changes).
 *  - Who: as ItemCards::editor (catalogue.edit: mapping_lead, stock_controller, purchasing_manager; never admin), read again inside the
 *    transaction.
 *  - ONE transaction: the same name (read without a lock) writes nothing (`unchanged`); else the NEW name's guard row first
 *    (item_name_guard, as an item made by hand or a store's choice takes it before anything: a making of that name at this moment waits
 *    and then sees the rename; HandMade may lock an existing item after its guard, so the guard comes first here too: no cycle), then the
 *    item row FOR UPDATE (a merge meanwhile waits, or is seen), then the person read again after both waits (a job taken away meanwhile
 *    counts), an item joined into another refused (409 merged_item: the item it was joined into is renamed instead), the same name again
 *    (`unchanged`), a name someone else changed since the page was drawn refused (409 name_changed, detail.name: the name it has now; the
 *    page sends the name it showed as the expected one: sku has no version column, and only the spaces of a stored name are forgiven, as
 *    clean() makes them single, and a NUL, which the page's hidden field carries as U+FFFD), then the name and one audit row `sku.rename`
 *    {code, before, after}.
 *  - The history (the item page) and the names an item had (Find) are read from those audit rows (entity sku / id, ix_audit_entity; the
 *    action's index ix_audit_action): no table of its own.
 */
final class ItemNames
{
    /** sku.name is VARCHAR(255) (0001): characters, not bytes. */
    public const NAME_MAX = 255;
    public const ACTION = 'sku.rename';
    public const HISTORY_LIMIT = 20;
    /** The most time one read of the names items had may take (MAX_EXECUTION_TIME, ms): past it Find shows what the names now found. */
    public const FORMER_MS = 2000;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * A name as a person typed it, tidied: trimmed, every run of spaces (a tab or line break at either end, a no-break space, a Unicode
     * line separator) made one space. 422 name_not_plain: not UTF-8, or a control or hidden formatting character inside it (a tab, a
     * zero-width space, a right-to-left override, a soft hyphen); 422 name_empty; 422 name_too_long (detail max, length).
     */
    public static function clean(string $raw): string
    {
        $s = trim($raw);
        if (!mb_check_encoding($s, 'UTF-8') || preg_match('/[\x00-\x1F\x7F\x{80}-\x{9F}]|\p{Cf}/u', $s) === 1) {
            throw new CwException('name_not_plain', 'The name must be plain text on one line (no tabs or hidden characters).', 422);
        }
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if ($s === '') {
            throw new CwException('name_empty', 'The name is empty.', 422);
        }
        $length = mb_strlen($s);
        if ($length > self::NAME_MAX) {
            throw new CwException('name_too_long', 'A name is at most ' . self::NAME_MAX . " characters (this one has {$length}).", 422,
                ['max' => self::NAME_MAX, 'length' => $length]);
        }
        return $s;
    }

    /**
     * Renames one item. $expected: the name the page showed. 404 unknown_item · 409 merged_item / name_changed · 422 as clean() · 403 as
     * ItemCards::editor. Result `renamed`, or `unchanged` when the item already has this name (nothing written).
     *
     * @return array{result: string, name: string, before: string}
     */
    public function rename(Caller $caller, int $skuId, string $expected, string $raw): array
    {
        $name = self::clean($raw);
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $expected, $name): array {
            ItemCards::editor($db, $caller);
            $seen = $db->one('SELECT name, merged_into_sku_id FROM sku WHERE id = ?', [$skuId]);
            if ($seen !== null && $seen['merged_into_sku_id'] === null && (string) $seen['name'] === $name) {
                return ['result' => 'unchanged', 'name' => $name, 'before' => $name];
            }
            self::guard($db, $name);
            $sku = $db->one('SELECT id, code, name, merged_into_sku_id FROM sku WHERE id = ? FOR UPDATE', [$skuId])
                ?? throw new CwException('unknown_item', 'There is no such item.', 404);
            // Again after the waits for the guard and the item (READ COMMITTED: a fresh read): a job taken away meanwhile counts.
            ItemCards::editor($db, $caller);
            if ($sku['merged_into_sku_id'] !== null) {
                $into = (int) $sku['merged_into_sku_id'];
                $code = (string) ($db->value('SELECT code FROM sku WHERE id = ?', [$into]) ?? '');
                throw new CwException('merged_item', "{$sku['code']} was merged into {$code}: rename that item instead.", 409, ['merged_into' => $into, 'code' => $code]);
            }
            $before = (string) $sku['name'];
            if ($name === $before) {
                return ['result' => 'unchanged', 'name' => $before, 'before' => $before];
            }
            if (self::loose($before) !== self::loose($expected)) {
                throw new CwException('name_changed', "Someone renamed {$sku['code']} meanwhile: it is \"{$before}\" now. Look at it again.", 409, ['name' => $before]);
            }
            $db->exec('UPDATE sku SET name = ? WHERE id = ?', [$name, $skuId]);
            Audit::write($db, $caller, self::ACTION, 'sku', (string) $skuId, null, ['code' => (string) $sku['code'], 'before' => $before, 'after' => $name]);
            return ['result' => 'renamed', 'name' => $name, 'before' => $before];
        });
    }

    /**
     * The renames of an item, newest first: the name before and after, who (their name, else the actor) and when.
     *
     * @return list<array{before: string, after: string, who: string, at: string}>
     */
    public function history(int $skuId, int $limit = self::HISTORY_LIMIT): array
    {
        $out = [];
        foreach ($this->db->all('SELECT a.detail, a.actor, a.created_at, u.display_name FROM audit_log a LEFT JOIN staff_user u ON u.id = a.staff_user_id '
            . "WHERE a.entity_type = 'sku' AND a.entity_id = ? AND a.action = ? ORDER BY a.id DESC LIMIT " . max(1, min(500, $limit)),
            [(string) $skuId, self::ACTION]) as $r) {
            $d = json_decode((string) $r['detail'], true);
            if (!is_array($d) || !is_string($d['before'] ?? null) || !is_string($d['after'] ?? null)) {
                continue;
            }
            $out[] = ['before' => $d['before'], 'after' => $d['after'], 'who' => $r['display_name'] !== null ? (string) $r['display_name'] : (string) $r['actor'],
                'at' => (string) $r['created_at']];
        }
        return $out;
    }

    /**
     * The items a name they had matches (Find, docs/decisions.md IR5): every word of the search in a name before a rename, as the search
     * compares words ($where, $params: CW\Ui\SearchText::allWords on formerExpr()). At most $limit ITEMS, each once, not joined into
     * another and not in $skip (the ones the names now found), the most recently renamed first: item id => the newest of its former names
     * that matched. Two reads, each capped at FORMER_MS (MAX_EXECUTION_TIME): the items with their newest matching rename (the action's
     * index), then those rows by their ids; past a cap, none.
     *
     * @param list<string|int> $params
     * @param list<int> $skip
     * @return array<int, string>
     */
    public static function formerMatches(Db $db, string $where, array $params, int $limit, array $skip = []): array
    {
        if ($limit < 1 || $where === '') {
            return [];
        }
        $cap = '/*+ MAX_EXECUTION_TIME(' . self::FORMER_MS . ') */';
        $not = $skip === [] ? '' : ' AND s.id NOT IN (' . implode(',', array_fill(0, count($skip), '?')) . ')';
        try {
            $last = [];
            foreach ($db->all("SELECT {$cap} s.id, MAX(a.id) AS last FROM audit_log a JOIN sku s ON s.id = CAST(a.entity_id AS UNSIGNED) "
                . "WHERE a.action = ? AND a.entity_type = 'sku' AND s.merged_into_sku_id IS NULL{$not} AND {$where} GROUP BY s.id ORDER BY last DESC LIMIT "
                . $limit, [self::ACTION, ...$skip, ...$params]) as $r) {
                $last[(int) $r['id']] = (int) $r['last'];
            }
            if ($last === []) {
                return [];
            }
            $names = [];
            foreach ($db->all("SELECT {$cap} a.id, " . self::formerExpr() . ' AS was FROM audit_log a WHERE a.id IN ('
                . implode(',', array_fill(0, count($last), '?')) . ')', array_values($last)) as $r) {
                $names[(int) $r['id']] = (string) $r['was'];
            }
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 3024) {
                return [];
            }
            throw $e;
        }
        $out = [];
        foreach ($last as $id => $row) {
            if (isset($names[$row])) {
                $out[$id] = $names[$row];
            }
        }
        return $out;
    }

    /** The name before a rename in an audit row (alias `a`), compared as sku.name is (accents and case aside). */
    public static function formerExpr(): string
    {
        return "(JSON_UNQUOTE(JSON_EXTRACT(a.detail, '$.before')) COLLATE utf8mb4_0900_ai_ci)";
    }

    /**
     * The name's guard row (item_name_guard, 0031: keyed by the name as the collation compares it), taken as HandMade::create and
     * StoreChoices take it before they make an item of a name.
     */
    private static function guard(Db $db, string $name): void
    {
        $db->exec('INSERT INTO item_name_guard (name_key, last_at) VALUES (SHA2(WEIGHT_STRING(CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci), 256), '
            . 'UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE last_at = UTC_TIMESTAMP(6)', [$name]);
    }

    /**
     * A name with its spaces made single: how a stored name is compared with the one the page showed (a NUL as U+FFFD: what the HTML parser
     * makes of it in the page's hidden field).
     */
    private static function loose(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\0", "\u{FFFD}", $s)));
    }
}
