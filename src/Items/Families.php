<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Audit;
use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\CwException;
use CW\Db;
use CW\Matching\Gtin;
use CW\Settings;

/**
 * Product families (pack B; owner answer Q4 "yes"; docs/decisions.md PB4-PB6): a family (the parent page of the shops: every flavour or
 * strength of one product) groups items. item_family holds the families, item_family_member which family an item is in (one at most;
 * sku is not altered).
 *
 *  - Who: families.manage (the catalogue team and the buyers; never admin, ItemCards::editor's checks), roles re-read inside each
 *    transaction. Everyone with catalogue.view reads.
 *  - Never deleted: a family is switched off (it keeps its items and takes no new ones) and on again; an item is taken out of its
 *    family (family_id NULL, the row stays). Every write is audited (family.create, family.rename, family.switch, family.assign,
 *    family.bulk_assign, family.bulk_remove).
 *  - A name is unique among the families switched on, ignoring case (the collation): 409 family_exists names the other one.
 *  - Optimistic locks: a family's `version` (rename, switch), and the family an item had when the form was drawn (assign: 409
 *    family_moved when it changed meanwhile).
 *  - Bulk (the owner's rule: skip anything doubtful, finish the rest, list the skipped at the end): a list of CW numbers or barcodes
 *    is put in a family in ONE transaction, at most items.family_bulk_max_rows of them (422 too_many_rows: nothing saved). A line
 *    is skipped, with its reason, when it names no item (`not_found`), an item joined into another (`merged`), an item named on
 *    an earlier line (`repeated`), an item already in this family (`already_here`) or one in ANOTHER family (`in_other_family`:
 *    moving it is a person's choice, made one at a time on the item's page). Taking ticked items out skips an item no longer in
 *    this family (`not_in_family`).
 *  - Lock order: the family row (FOR UPDATE), then the items' member rows in sku id order (FOR UPDATE). No stock, listing or sku
 *    row is locked.
 */
final class Families
{
    public const NAME_MAX = 255;
    public const NOTE_MAX = 500;
    public const PER_PAGE = 50;
    public const MEMBERS_PER_PAGE = 100;
    /** The bulk limit's setting (0031) and the value it has when the setting is missing (a schema before 0031). */
    public const BULK_SETTING = 'items.family_bulk_max_rows';
    public const BULK_DEFAULT = 200;
    /** Why a line of a bulk change was left out (Ui\Words::FAMILY_SKIP). */
    public const SKIPS = ['not_found', 'merged', 'repeated', 'already_here', 'in_other_family', 'not_in_family'];

    public function __construct(private readonly Db $db)
    {
    }

    /** The checks of every change (403 staff_required / staff_not_allowed / admin_cannot_edit / role_not_allowed). @return array{id: int, roles: list<string>} */
    public static function manager(Db $db, Caller $caller): array
    {
        return ItemCards::editor($db, $caller, 'families.manage');
    }

    /** The most items one bulk change takes now (the setting, read on every call). */
    public static function bulkMax(Db $db): int
    {
        return max(1, Settings::number($db, self::BULK_SETTING, self::BULK_DEFAULT));
    }

    // ------------------------------------------------------------------------------------------
    // Families
    // ------------------------------------------------------------------------------------------

    /** A new family, switched on. 422 bad_name / bad_note; 409 family_exists. @return array{id: int, name: string} */
    public function create(Caller $caller, string $name, ?string $note = null): array
    {
        $name = self::name($name);
        $note = self::note($note);
        return $this->db->transaction(function (Db $db) use ($caller, $name, $note): array {
            $me = self::manager($db, $caller);
            $this->refuseTaken($db, $name, null);
            try {
                $id = $db->insert('INSERT INTO item_family (name, note, created_by, created_actor, updated_by, updated_actor) VALUES (?, ?, ?, ?, ?, ?)',
                    [$name, $note, $me['id'], $caller->actor, $me['id'], $caller->actor]);
            } catch (\PDOException $e) {
                throw self::taken($db, $e, $name);
            }
            Audit::write($db, $caller, 'family.create', 'item_family', (string) $id, null, ['name' => $name] + ($note === null ? [] : ['note' => $note]));
            return ['id' => $id, 'name' => $name];
        });
    }

    /** A new name. 404 unknown_family; 409 family_changed (the version the form had) / family_exists. @return array{id: int, version: int, changed: bool} */
    public function rename(Caller $caller, int $id, int $version, string $name): array
    {
        $name = self::name($name);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $name): array {
            self::manager($db, $caller);
            $f = $this->lockFamily($db, $id, $version);
            if ($f['name'] === $name) {
                return ['id' => $id, 'version' => $f['version'], 'changed' => false];
            }
            if ((int) $f['is_active'] === 1) {
                $this->refuseTaken($db, $name, $id);
            }
            try {
                $db->exec('UPDATE item_family SET name = ?, version = version + 1, updated_by = ?, updated_actor = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?',
                    [$name, $caller->staffUserId, $caller->actor, $id]);
            } catch (\PDOException $e) {
                throw self::taken($db, $e, $name);
            }
            Audit::write($db, $caller, 'family.rename', 'item_family', (string) $id, null, ['before' => $f['name'], 'after' => $name, 'version' => $f['version'] + 1]);
            return ['id' => $id, 'version' => $f['version'] + 1, 'changed' => true];
        });
    }

    /**
     * Switches a family off (it keeps its items; it takes no new ones and is left out of the choices) or on again (its name must
     * still be free among the families switched on: 409 family_exists). The same state again writes nothing.
     *
     * @return array{id: int, version: int, changed: bool}
     */
    public function setActive(Caller $caller, int $id, int $version, bool $on): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $on): array {
            self::manager($db, $caller);
            $f = $this->lockFamily($db, $id, $version);
            if (((int) $f['is_active'] === 1) === $on) {
                return ['id' => $id, 'version' => $f['version'], 'changed' => false];
            }
            if ($on) {
                $this->refuseTaken($db, (string) $f['name'], $id);
            }
            try {
                $db->exec('UPDATE item_family SET is_active = ?, version = version + 1, updated_by = ?, updated_actor = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?',
                    [$on ? 1 : 0, $caller->staffUserId, $caller->actor, $id]);
            } catch (\PDOException $e) {
                throw self::taken($db, $e, (string) $f['name']);
            }
            $members = (int) $db->value('SELECT COUNT(*) FROM item_family_member WHERE family_id = ?', [$id]);
            Audit::write($db, $caller, 'family.switch', 'item_family', (string) $id, null, ['on' => $on, 'name' => $f['name'], 'items' => $members,
                'version' => $f['version'] + 1]);
            return ['id' => $id, 'version' => $f['version'] + 1, 'changed' => true];
        });
    }

    // ------------------------------------------------------------------------------------------
    // Items in a family
    // ------------------------------------------------------------------------------------------

    /**
     * Puts one item in a family, moves it from another, or takes it out ($familyId null). $seen: the family the form showed the
     * item in (null: none): 409 family_moved when it is another now, so a person never moves an item they did not see moved.
     * 404 unknown_item / unknown_family; 409 merged_item; 422 family_off (a switched-off family takes no new items).
     *
     * @return array{sku_id: int, from: ?int, to: ?int, changed: bool}
     */
    public function assign(Caller $caller, int $skuId, ?int $familyId, ?int $seen): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $familyId, $seen): array {
            self::manager($db, $caller);
            $target = $familyId === null ? null : $this->family($db, $familyId, true);
            if ($target !== null && (int) $target['is_active'] !== 1) {
                throw new CwException('family_off', "the family {$target['name']} is switched off: switch it on first, or choose another", 422,
                    ['family_id' => $familyId]);
            }
            $sku = self::item($db, $skuId);
            $row = $db->one('SELECT family_id FROM item_family_member WHERE sku_id = ? FOR UPDATE', [$skuId]);
            $from = $row === null || $row['family_id'] === null ? null : (int) $row['family_id'];
            if ($from !== $seen) {
                throw new CwException('family_moved', "{$sku['code']} changed family since the page was drawn: reload it", 409, ['family_id' => $from]);
            }
            if ($from === $familyId) {
                return ['sku_id' => $skuId, 'from' => $from, 'to' => $familyId, 'changed' => false];
            }
            if ($familyId !== null && $sku['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', "{$sku['code']} was joined into another item: use that one", 409, ['merged_into' => (int) $sku['merged_into_sku_id']]);
            }
            if (!$this->write($db, $caller, $skuId, $row !== null, $familyId)) {
                // Another person put it in a family a moment ago (its first row: nothing to lock before): the item changed meanwhile.
                $now = $db->value('SELECT family_id FROM item_family_member WHERE sku_id = ? FOR UPDATE', [$skuId]);
                throw new CwException('family_moved', "{$sku['code']} was put in a family a moment ago: reload the page", 409, ['family_id' => $now === null ? null : (int) $now]);
            }
            Audit::write($db, $caller, $familyId === null ? 'family.unassign' : 'family.assign', 'sku', (string) $skuId, null,
                ['code' => $sku['code'], 'from' => $from, 'to' => $familyId]);
            return ['sku_id' => $skuId, 'from' => $from, 'to' => $familyId, 'changed' => true];
        });
    }

    /**
     * Puts the items a list names (CW numbers, their plain numbers or barcodes, one per line or separated by commas or spaces) in a
     * family, skipping the doubtful ones with their reason (SKIPS); ONE transaction, at most bulkMax() names (422 too_many_rows, 422
     * empty_list: nothing saved). 404 unknown_family; 422 family_off.
     *
     * @return array{family_id: int, added: list<array{sku_id: int, code: string, name: string}>, skipped: list<array{text: string, reason: string, code: ?string, sku_id: ?int, other: ?string}>}
     */
    public function bulkAssign(Caller $caller, int $familyId, string $list): array
    {
        $texts = self::tokens($list);
        if ($texts === []) {
            throw new CwException('empty_list', 'type or paste the CW numbers or barcodes of the items, one per line', 422);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $familyId, $texts): array {
            self::manager($db, $caller);
            $max = self::bulkMax($db);
            if (count($texts) > $max) {
                throw new CwException('too_many_rows', 'at most ' . $max . ' items at a time: split the list', 422, ['max' => $max, 'given' => count($texts)]);
            }
            $fam = $this->family($db, $familyId, true);
            if ((int) $fam['is_active'] !== 1) {
                throw new CwException('family_off', "the family {$fam['name']} is switched off: switch it on first", 422, ['family_id' => $familyId]);
            }
            $found = self::resolve($db, $texts);
            $ids = array_values(array_unique(array_filter(array_column($found, 'sku_id'))));
            sort($ids);
            $members = $this->lockMembers($db, $ids);
            $items = self::items($db, $ids);
            $otherNames = self::names($db, array_values(array_filter(array_map(static fn (?int $f): ?int => $f, $members))));
            $adds = [];
            $skipped = [];
            $seen = [];
            foreach ($found as $f) {
                $id = $f['sku_id'];
                $item = $id === null ? null : ($items[$id] ?? null);
                $skip = static fn (string $reason, ?string $other = null): array => ['text' => $f['text'], 'reason' => $reason, 'code' => $item['code'] ?? null,
                    'sku_id' => $id, 'other' => $other];
                if ($item === null) {
                    $skipped[] = $skip('not_found');
                } elseif ($item['merged_into_sku_id'] !== null) {
                    $skipped[] = $skip('merged');
                } elseif (isset($seen[$id])) {
                    $skipped[] = $skip('repeated');
                } elseif (($members[$id] ?? null) === $familyId) {
                    $skipped[] = $skip('already_here');
                } elseif (($members[$id] ?? null) !== null) {
                    $skipped[] = $skip('in_other_family', $otherNames[$members[$id]] ?? null);
                } else {
                    $adds[$id] = ['text' => $f['text'], 'item' => $item];
                }
                if ($id !== null) {
                    $seen[$id] = true;
                }
            }
            // The writes in sku id order (two lists written at once take their rows in one order: no deadlock). A first assignment that
            // meets another's at the same moment loses on the primary key: its line is skipped with what the item is in now.
            $done = [];
            $ordered = array_keys($adds);
            sort($ordered);
            foreach ($ordered as $id) {
                $done[$id] = $this->write($db, $caller, $id, array_key_exists($id, $members), $familyId);
            }
            $added = [];
            $lost = [];
            foreach ($adds as $id => $a) {
                if ($done[$id]) {
                    $added[] = ['sku_id' => $id, 'code' => (string) $a['item']['code'], 'name' => (string) $a['item']['name']];
                    continue;
                }
                $now = $db->value('SELECT family_id FROM item_family_member WHERE sku_id = ? FOR UPDATE', [$id]);
                $lost[$id] = ['text' => $a['text'], 'code' => (string) $a['item']['code'], 'family' => $now === null ? null : (int) $now];
            }
            $lostNames = self::names($db, array_values(array_filter(array_column($lost, 'family'))));
            foreach ($lost as $id => $l) {
                $skipped[] = ['text' => $l['text'], 'reason' => $l['family'] === $familyId ? 'already_here' : 'in_other_family', 'code' => $l['code'], 'sku_id' => $id,
                    'other' => $l['family'] === $familyId || $l['family'] === null ? null : ($lostNames[$l['family']] ?? null)];
            }
            Audit::write($db, $caller, 'family.bulk_assign', 'item_family', (string) $familyId, null, [
                'added' => array_column($added, 'sku_id'),
                'skipped' => array_map(static fn (array $s): array => ['text' => mb_substr($s['text'], 0, 40), 'reason' => $s['reason']], $skipped),
            ]);
            return ['family_id' => $familyId, 'added' => $added, 'skipped' => $skipped];
        });
    }

    /**
     * Takes the ticked items out of a family (family_id NULL; the rows stay), skipping an item no longer in it (`not_in_family`).
     * At most bulkMax() (422 too_many_rows); 404 unknown_family. A switched-off family's items may be taken out.
     *
     * @param list<int> $skuIds
     * @return array{family_id: int, removed: list<array{sku_id: int, code: string, name: string}>, skipped: list<array{text: string, reason: string, code: ?string, sku_id: ?int, other: ?string}>}
     */
    public function bulkRemove(Caller $caller, int $familyId, array $skuIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $skuIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            throw new CwException('empty_list', 'tick the items to take out', 422);
        }
        sort($ids);
        return $this->db->transaction(function (Db $db) use ($caller, $familyId, $ids): array {
            self::manager($db, $caller);
            $max = self::bulkMax($db);
            if (count($ids) > $max) {
                throw new CwException('too_many_rows', 'at most ' . $max . ' items at a time', 422, ['max' => $max, 'given' => count($ids)]);
            }
            $this->family($db, $familyId, true);
            $members = $this->lockMembers($db, $ids);
            $items = self::items($db, $ids);
            $removed = [];
            $skipped = [];
            foreach ($ids as $id) {
                $item = $items[$id] ?? null;
                if (($members[$id] ?? null) !== $familyId || $item === null) {
                    $skipped[] = ['text' => (string) ($item['code'] ?? $id), 'reason' => 'not_in_family', 'code' => $item['code'] ?? null, 'sku_id' => $id, 'other' => null];
                    continue;
                }
                $this->write($db, $caller, $id, true, null);
                $removed[] = ['sku_id' => $id, 'code' => (string) $item['code'], 'name' => (string) $item['name']];
            }
            Audit::write($db, $caller, 'family.bulk_remove', 'item_family', (string) $familyId, null, [
                'removed' => array_column($removed, 'sku_id'), 'skipped' => array_column($skipped, 'sku_id'),
            ]);
            return ['family_id' => $familyId, 'removed' => $removed, 'skipped' => $skipped];
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null a family with its number of items */
    public function get(int $id): ?array
    {
        $f = $this->db->one('SELECT f.id, f.name, f.is_active, f.version, f.note, f.created_at, f.updated_at, f.created_actor, f.updated_actor, '
            . 'cu.display_name AS created_by_name, uu.display_name AS updated_by_name FROM item_family f '
            . 'LEFT JOIN staff_user cu ON cu.id = f.created_by LEFT JOIN staff_user uu ON uu.id = f.updated_by WHERE f.id = ?', [$id]);
        if ($f === null) {
            return null;
        }
        $f['items'] = (int) $this->db->value('SELECT COUNT(*) FROM item_family_member WHERE family_id = ?', [$id]);
        return $f;
    }

    /**
     * The families, by name: those switched on (and the switched-off ones with $off), whose name holds every word of $q; a page of
     * PER_PAGE with the number of items of each.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function list(string $q, bool $off, int $page): array
    {
        $where = $off ? [] : ['f.is_active = 1'];
        $params = [];
        foreach (array_slice(preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $w) {
            $where[] = 'f.name LIKE ?';
            $params[] = '%' . addcslashes($w, '%_\\') . '%';
        }
        $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM item_family f' . $sql, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->all('SELECT f.id, f.name, f.is_active, f.version, f.updated_at FROM item_family f' . $sql
            . ' ORDER BY f.is_active DESC, f.name, f.id LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        $counts = [];
        if ($rows !== []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            foreach ($this->db->all('SELECT family_id, COUNT(*) AS n FROM item_family_member WHERE family_id IN (' . implode(',', array_fill(0, count($ids), '?'))
                . ') GROUP BY family_id', $ids) as $c) {
                $counts[(int) $c['family_id']] = (int) $c['n'];
            }
        }
        foreach ($rows as $i => $r) {
            $rows[$i]['items'] = $counts[(int) $r['id']] ?? 0;
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The items of a family, by name, a page of MEMBERS_PER_PAGE, each with its stock on hand in our own warehouses.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function members(int $familyId, int $page): array
    {
        $total = (int) $this->db->value('SELECT COUNT(*) FROM item_family_member WHERE family_id = ?', [$familyId]);
        $pages = max(1, (int) ceil($total / self::MEMBERS_PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->all('SELECT s.id, s.code, s.name, s.brand, s.strength_mg, s.flavour, s.volume_ml, s.merged_into_sku_id, m.set_at, m.version '
            . 'FROM item_family_member m JOIN sku s ON s.id = m.sku_id WHERE m.family_id = ? ORDER BY s.name, s.id LIMIT '
            . self::MEMBERS_PER_PAGE . ' OFFSET ' . (($page - 1) * self::MEMBERS_PER_PAGE), [$familyId]);
        $stock = self::onHand($this->db, array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as $i => $r) {
            $rows[$i]['on_hand'] = $stock[(int) $r['id']] ?? 0;
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The family of each item that is in one (id, name, whether it is switched on): the item page, By Item, the matching pages.
     *
     * @param list<int> $skuIds
     * @return array<int, array{id: int, name: string, active: bool}>
     */
    public static function ofItems(Db $db, array $skuIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $skuIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->all('SELECT m.sku_id, f.id, f.name, f.is_active FROM item_family_member m JOIN item_family f ON f.id = m.family_id WHERE m.sku_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out[(int) $r['sku_id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'active' => (int) $r['is_active'] === 1];
            }
        }
        return $out;
    }

    /** The family switched on whose name is $name (the collation ignores case), or null. @return array{id: int, name: string}|null */
    public function activeByName(string $name): ?array
    {
        $r = $this->db->one('SELECT id, name FROM item_family WHERE active_name = ?', [trim((string) preg_replace('/\s+/u', ' ', $name))]);
        return $r === null ? null : ['id' => (int) $r['id'], 'name' => (string) $r['name']];
    }

    /** The names of the families switched on, by name (the choices of a form), at most $limit. @return list<string> */
    public function activeNames(int $limit = 500): array
    {
        return array_map('strval', $this->db->column('SELECT name FROM item_family WHERE is_active = 1 ORDER BY name LIMIT ' . max(1, $limit)));
    }

    // ------------------------------------------------------------------------------------------
    // Inside
    // ------------------------------------------------------------------------------------------

    /**
     * Inserts or updates an item's member row (the caller holds its lock when it exists). False when the item had no row and another
     * transaction made its first one meanwhile (READ COMMITTED: an absent row is not locked; the insert waits for the other's and
     * meets its key once it is committed): the statement is undone, the transaction goes on, the caller re-reads the row.
     */
    private function write(Db $db, Caller $caller, int $skuId, bool $exists, ?int $familyId): bool
    {
        if ($exists) {
            $db->exec('UPDATE item_family_member SET family_id = ?, version = version + 1, set_by = ?, set_actor = ?, set_at = UTC_TIMESTAMP(6) WHERE sku_id = ?',
                [$familyId, $caller->staffUserId, $caller->actor, $skuId]);
            return true;
        }
        try {
            $db->exec('INSERT INTO item_family_member (sku_id, family_id, set_by, set_actor) VALUES (?, ?, ?, ?)', [$skuId, $familyId, $caller->staffUserId, $caller->actor]);
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1062) {
                return false;
            }
            throw $e;
        }
        return true;
    }

    /** @return array<string, mixed> the family row, locked (409 family_changed when its version is not $version; 404 unknown_family) */
    private function lockFamily(Db $db, int $id, int $version): array
    {
        $f = $db->one('SELECT id, name, is_active, version FROM item_family WHERE id = ? FOR UPDATE', [$id])
            ?? throw new CwException('unknown_family', 'there is no such family', 404);
        $f['version'] = (int) $f['version'];
        if ($f['version'] !== $version) {
            throw new CwException('family_changed', "the family {$f['name']} changed since the page was drawn: reload it", 409, ['version' => $f['version']]);
        }
        return $f;
    }

    /** @return array<string, mixed> the family row (FOR SHARE when $share: it cannot be switched off under the change) */
    private function family(Db $db, int $id, bool $share): array
    {
        return $db->one('SELECT id, name, is_active FROM item_family WHERE id = ?' . ($share ? ' FOR SHARE' : ''), [$id])
            ?? throw new CwException('unknown_family', 'there is no such family', 404);
    }

    /** @return array{id: int, code: string, name: string, merged_into_sku_id: ?int} 404 unknown_item */
    private static function item(Db $db, int $skuId): array
    {
        $r = $db->one('SELECT id, code, name, merged_into_sku_id FROM sku WHERE id = ?', [$skuId]) ?? throw new CwException('unknown_item', 'there is no such item', 404);
        return ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
            'merged_into_sku_id' => $r['merged_into_sku_id'] === null ? null : (int) $r['merged_into_sku_id']];
    }

    /**
     * The member rows of these items (sku id order), locked: sku id => family id (null: taken out); an item with no row is absent.
     *
     * @param list<int> $ids sorted
     * @return array<int, ?int>
     */
    private function lockMembers(Db $db, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->all('SELECT sku_id, family_id FROM item_family_member WHERE sku_id IN (' . implode(',', array_fill(0, count($chunk), '?'))
                . ') ORDER BY sku_id FOR UPDATE', $chunk) as $r) {
                $out[(int) $r['sku_id']] = $r['family_id'] === null ? null : (int) $r['family_id'];
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private static function items(Db $db, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->all('SELECT id, code, name, merged_into_sku_id FROM sku WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out[(int) $r['id']] = $r;
            }
        }
        return $out;
    }

    /** @param list<int> $familyIds @return array<int, string> */
    private static function names(Db $db, array $familyIds): array
    {
        $familyIds = array_values(array_unique($familyIds));
        if ($familyIds === []) {
            return [];
        }
        $out = [];
        foreach ($db->all('SELECT id, name FROM item_family WHERE id IN (' . implode(',', array_fill(0, count($familyIds), '?')) . ')', $familyIds) as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /**
     * Stock on hand of each item in our own warehouses (another account's room left out), for the family page.
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    public static function onHand(Db $db, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($db->all("SELECT b.sku_id, SUM(b.on_hand) AS n FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id AND w.stock_owner = 'own' "
            . 'WHERE b.sku_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY b.sku_id', $ids) as $r) {
            $out[(int) $r['sku_id']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Each text of a bulk list with the item it names (null: none): CW-000123, 123 or 000123 (the item's number), or a barcode
     * (8 to 14 digits, sku_barcode). A number of 8 digits or more is read as a barcode first.
     *
     * @param list<string> $texts
     * @return list<array{text: string, sku_id: ?int}>
     */
    public static function resolve(Db $db, array $texts): array
    {
        $out = [];
        foreach ($texts as $t) {
            $out[] = ['text' => $t, 'sku_id' => self::resolveOne($db, $t)];
        }
        return $out;
    }

    /** The item a CW number or a barcode names, or null (Products › Multipacks and Repack use it too). */
    public static function resolveOne(Db $db, string $text): ?int
    {
        $t = strtoupper(trim($text));
        if (preg_match('/^[0-9]{8,14}$/D', $t) === 1) {
            $key = Gtin::key($t);
            $barcode = $key === null ? null : $db->one('SELECT sku_id, is_usable FROM sku_barcode WHERE barcode = ?', [$key]);
            if ($barcode !== null) {
                return (int) $barcode['is_usable'] === 1 ? (int) $barcode['sku_id'] : null;
            }
        }
        if (preg_match('/^(?:CW-?)?0*([1-9][0-9]{0,9})$/D', $t, $m) === 1) {
            $id = $db->value('SELECT id FROM sku WHERE id = ?', [(int) $m[1]]);
            return $id === null ? null : (int) $id;
        }
        return null;
    }

    /** The names of a pasted list: split on line breaks, commas, semicolons, tabs and spaces. @return list<string> */
    public static function tokens(string $list): array
    {
        $parts = preg_split('/[\s,;]+/u', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_map(static fn (string $p): string => mb_substr($p, 0, 64), $parts));
    }

    /** 409 family_exists when another family switched on has this name. */
    private function refuseTaken(Db $db, string $name, ?int $self): void
    {
        $other = $db->one('SELECT id, name FROM item_family WHERE active_name = ? AND id <> ?', [$name, $self ?? 0]);
        if ($other !== null) {
            throw new CwException('family_exists', "a family called {$other['name']} is already switched on", 409, ['family_id' => (int) $other['id'],
                'name' => (string) $other['name']]);
        }
    }

    /** The refusal of a duplicate name that slipped past refuseTaken (two people at once), or the error itself. */
    private static function taken(Db $db, \PDOException $e, string $name): \Throwable
    {
        if (Db::driverCode($e) === 1062) {
            return new CwException('family_exists', "a family called {$name} was switched on a moment ago", 409, ['name' => $name]);
        }
        return $e;
    }

    /** A family's name: one line, 2 to NAME_MAX characters, spaces made single. 422 bad_name. */
    public static function name(string $raw): string
    {
        $n = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if (!mb_check_encoding($n, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $n) === 1 || mb_strlen($n) < 2 || mb_strlen($n) > self::NAME_MAX) {
            throw new CwException('bad_name', 'a family\'s name is 2 to ' . self::NAME_MAX . ' characters on one line', 422, ['field' => 'name']);
        }
        return $n;
    }

    /** An optional note: at most NOTE_MAX characters (422 bad_note). */
    public static function note(?string $raw): ?string
    {
        $n = $raw === null ? '' : trim(str_replace("\r\n", "\n", $raw));
        if ($n === '') {
            return null;
        }
        if (!mb_check_encoding($n, 'UTF-8') || mb_strlen($n) > self::NOTE_MAX) {
            throw new CwException('bad_note', 'a note is at most ' . self::NOTE_MAX . ' characters', 422, ['field' => 'note']);
        }
        return $n;
    }
}
