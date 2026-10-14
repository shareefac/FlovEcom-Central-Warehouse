<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Audit;
use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\CwException;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\Settings;

/**
 * Multipacks (pack B; owner answer Q3 "yes: a 10 x 10ml listing is 10 single nic shots ... suggest more than 1 unit only when the pack
 * is made of a single product that is ALSO sold separately; otherwise its own product, 1 unit; a person decides"; docs/decisions.md
 * PB6-PB8). Two ways of selling a pack exist and this class makes them visible side by side, changing neither:
 *
 *  - a pack LINK (since 0001): a variant matched to an item with N units a sale (channel_listing.units_per_item = N): one sale takes N
 *    singles' stock. Only CW\Mapping\DecisionService sets it, with a second matching lead's OK (approvals.match_multiple);
 *  - a pack ITEM (item_pack, 0031): a pre-packed multipack item with its own stock, counted in packs, defined as N x a single item.
 *    A Repack (RPK, CW\Items\RepackHandler) breaks packs into singles or makes packs from singles with that N.
 *
 * Who defines a pack item: catalogue.edit (like a barcode's units per scan), never admin. A definition is never changed or deleted:
 * it is switched off (and on), so the records that name it keep their meaning. Rules (409/422): the two items differ and are not
 * joined into others; N is 2 to DecisionService::MAX_UNITS; a pack item has one definition switched on (pack_exists); no pack of
 * packs (pack_nested: the single is itself a pack item switched on, or the pack item is the single of one). The two item rows are
 * locked FOR UPDATE in id order (after the definition's own row when one is switched), so two definitions of the same items made
 * at once are decided one after the other.
 *
 * What the screens show for each definition, computed for a page at a time (flags(), never a scan of every variant):
 *   single_sold      the single is sold separately: a variant is matched to it with 1 unit a sale
 *   sold_both_ways   a variant is matched to the single with N units a sale while the pack item exists: one pack's stock in two places
 *   pack_of_packs    a variant is matched to the pack item with more than 1 unit a sale
 *   count_differs    the identity cards' pack counts disagree: pack item's pack_units is not N x the single's (both known), the same
 *                    arithmetic as the matching's pack rule (CW\Matching\Veto)
 *   suggest          the pack item's variants (matched with 1 unit) could take N singles a sale instead, ONLY while the single is sold
 *                    separately (items.pack_suggest_single_sold, on by default; off: for every definition). It is a link to each
 *                    variant's page, where a person decides and a second matching lead OKs, as today. Nothing is relinked here.
 */
final class Packs
{
    public const PER_PAGE = 50;
    public const NOTE_MAX = 500;
    public const MIN_UNITS = 2;
    /** The Q3 rule's setting (0031) and its value when the setting is missing. */
    public const SUGGEST_SETTING = 'items.pack_suggest_single_sold';
    public const SUGGEST_DEFAULT = true;
    public const LINKED = ['mapped', 'quarantined'];
    public const FLAGS = ['single_sold', 'sold_both_ways', 'pack_of_packs', 'count_differs', 'suggest'];
    /** What a pack link (a variant with more than 1 unit a sale) is told: a pack item exists for it, or its item is itself a pack item. */
    public const LINK_FLAGS = ['pack_item_exists', 'item_is_pack'];

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------------------------------
    // Definitions
    // ------------------------------------------------------------------------------------------

    /**
     * Defines $packSkuId as $units x $singleSkuId, switched on. 422 same_item / bad_units / bad_note; 404 unknown_item; 409
     * merged_item / pack_exists / pack_nested; 403 as ItemCards::editor (catalogue.edit). Audit pack.create.
     *
     * @return array{id: int}
     */
    public function create(Caller $caller, int $packSkuId, int $singleSkuId, int $units, ?string $note = null): array
    {
        if ($packSkuId === $singleSkuId) {
            throw new CwException('same_item', 'a pack item and its single are two different items', 422, ['field' => 'single']);
        }
        if ($units < self::MIN_UNITS || $units > DecisionService::MAX_UNITS) {
            throw new CwException('bad_units', 'a pack holds ' . self::MIN_UNITS . ' to ' . DecisionService::MAX_UNITS . ' singles', 422, ['field' => 'units']);
        }
        $note = Families::note($note);
        return $this->db->transaction(function (Db $db) use ($caller, $packSkuId, $singleSkuId, $units, $note): array {
            $me = ItemCards::editor($db, $caller);
            $items = $this->lockItems($db, $packSkuId, $singleSkuId);
            $this->checkRules($db, $packSkuId, $singleSkuId, $items, null);
            $id = $db->insert('INSERT INTO item_pack (pack_sku_id, single_sku_id, units, note, created_by, created_actor) VALUES (?, ?, ?, ?, ?, ?)',
                [$packSkuId, $singleSkuId, $units, $note, $me['id'], $caller->actor]);
            Audit::write($db, $caller, 'pack.create', 'item_pack', (string) $id, null, ['pack' => $items[$packSkuId]['code'], 'single' => $items[$singleSkuId]['code'],
                'units' => $units] + ($note === null ? [] : ['note' => $note]));
            return ['id' => $id];
        });
    }

    /**
     * Switches a definition off (it stays for the records that name it; a Repack can no longer use it) or on again (the rules are
     * checked again: 409 pack_exists / pack_nested / merged_item). The same state again writes nothing. 404 unknown_pack.
     *
     * @return array{id: int, changed: bool}
     */
    public function setActive(Caller $caller, int $id, bool $on): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $on): array {
            $me = ItemCards::editor($db, $caller);
            // The definition first (a repack being booked holds it FOR SHARE until it commits), then its two items.
            $p = $db->one('SELECT id, pack_sku_id, single_sku_id, units, is_active FROM item_pack WHERE id = ? FOR UPDATE', [$id])
                ?? throw new CwException('unknown_pack', 'there is no such pack item', 404);
            $items = $this->lockItems($db, (int) $p['pack_sku_id'], (int) $p['single_sku_id']);
            if (((int) $p['is_active'] === 1) === $on) {
                return ['id' => $id, 'changed' => false];
            }
            if ($on) {
                $this->checkRules($db, (int) $p['pack_sku_id'], (int) $p['single_sku_id'], $items, $id);
            }
            $db->exec('UPDATE item_pack SET is_active = ?, switched_by = ?, switched_actor = ?, switched_at = UTC_TIMESTAMP(6) WHERE id = ?',
                [$on ? 1 : 0, $me['id'], $caller->actor, $id]);
            Audit::write($db, $caller, 'pack.switch', 'item_pack', (string) $id, null, ['on' => $on, 'pack' => $items[(int) $p['pack_sku_id']]['code'],
                'single' => $items[(int) $p['single_sku_id']]['code'], 'units' => (int) $p['units']]);
            return ['id' => $id, 'changed' => true];
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed>|null one definition with its two items' codes and names */
    public function get(int $id): ?array
    {
        $rows = $this->rows('WHERE p.id = ?', [$id]);
        return $rows[0] ?? null;
    }

    /** The definition switched on whose pack item is $packSkuId, or null. @return array<string, mixed>|null */
    public function activeOf(int $packSkuId): ?array
    {
        $rows = $this->rows('WHERE p.active_pack = ?', [$packSkuId]);
        return $rows[0] ?? null;
    }

    /**
     * The definitions (switched on; with $off also the switched-off ones), newest first, a page of PER_PAGE, each with its flags,
     * its stock and the variants a suggestion names. $q: a CW number or words of either item's name.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function list(bool $off, string $q, int $page): array
    {
        $where = $off ? [] : ['p.is_active = 1'];
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $item = Families::resolveOne($this->db, $q);
            if ($item !== null) {
                $where[] = '(p.pack_sku_id = ? OR p.single_sku_id = ?)';
                array_push($params, $item, $item);
            } else {
                foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $w) {
                    $where[] = '(ps.name LIKE ? OR ss.name LIKE ?)';
                    $like = '%' . addcslashes($w, '%_\\') . '%';
                    array_push($params, $like, $like);
                }
            }
        }
        $sql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*) FROM item_pack p JOIN sku ps ON ps.id = p.pack_sku_id JOIN sku ss ON ss.id = p.single_sku_id ' . $sql, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->rows($sql . ' ORDER BY p.is_active DESC, p.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        return ['rows' => $this->flags($rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * The pack definitions an item takes part in, switched on: `pack` (it is a pack item: N x a single) and `packs` (pack items made
     * of it), each with its flags (the item page).
     *
     * @return array{pack: ?array<string, mixed>, packs: list<array<string, mixed>>}
     */
    public function ofItem(int $skuId): array
    {
        $own = $this->flags($this->rows('WHERE p.active_pack = ?', [$skuId]));
        $made = $this->flags($this->rows('WHERE p.single_sku_id = ? AND p.is_active = 1 ORDER BY p.id LIMIT 20', [$skuId]));
        return ['pack' => $own[0] ?? null, 'packs' => $made];
    }

    /**
     * The pack links: every variant matched with more than 1 unit a sale (range of ix_channel_listing_units, 0031), by units then id,
     * a page of PER_PAGE, each with its store, titles, item, and what the pack items say of it (LINK_FLAGS): an active definition N x
     * its item exists (`pack_item_exists`, the pack item named), or its item is itself a pack item (`item_is_pack`).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function links(int $page): array
    {
        $in = implode(', ', array_fill(0, count(self::LINKED), '?'));
        $total = (int) $this->db->value("SELECT COUNT(*) FROM channel_listing cl WHERE cl.units_per_item > 1 AND cl.status IN ({$in})", self::LINKED);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $this->db->all('SELECT cl.id, cl.channel_id, ch.name AS channel_name, cl.external_variant_id, cl.sku_id, cl.units_per_item, cl.status, '
            . 's.code AS sku_code, s.name AS sku_name, s.pack_units AS sku_pack_units, lp.product_title, lp.variant_title, lp.units_365d '
            . 'FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id JOIN sku s ON s.id = cl.sku_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . "WHERE cl.units_per_item > 1 AND cl.status IN ({$in}) ORDER BY cl.units_per_item, cl.id LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            self::LINKED);
        $skus = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['sku_id'], $rows)));
        $bySingle = [];
        $asPack = [];
        if ($skus !== []) {
            $ph = implode(',', array_fill(0, count($skus), '?'));
            foreach ($this->db->all("SELECT p.id, p.pack_sku_id, p.single_sku_id, p.units, s.code AS pack_code, s.name AS pack_name FROM item_pack p "
                . "JOIN sku s ON s.id = p.pack_sku_id WHERE p.single_sku_id IN ({$ph}) AND p.is_active = 1", $skus) as $p) {
                $bySingle[(int) $p['single_sku_id']][(int) $p['units']] = $p;
            }
            foreach ($this->db->all("SELECT id, pack_sku_id, single_sku_id, units FROM item_pack WHERE active_pack IN ({$ph})", $skus) as $p) {
                $asPack[(int) $p['pack_sku_id']] = $p;
            }
        }
        foreach ($rows as $i => $r) {
            $sku = (int) $r['sku_id'];
            $u = (int) $r['units_per_item'];
            $def = $bySingle[$sku][$u] ?? null;
            $rows[$i]['pack_item'] = $def === null ? null : ['id' => (int) $def['pack_sku_id'], 'code' => (string) $def['pack_code'], 'name' => (string) $def['pack_name'],
                'pack_id' => (int) $def['id']];
            $rows[$i]['flags'] = array_values(array_filter([$def !== null ? 'pack_item_exists' : null, isset($asPack[$sku]) ? 'item_is_pack' : null]));
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Whether the Q3 rule holds now: a pack is suggested as N singles only when the single is sold separately (the setting). */
    public static function onlyWhenSingleSold(Db $db): bool
    {
        return Settings::flag($db, self::SUGGEST_SETTING, self::SUGGEST_DEFAULT);
    }

    // ------------------------------------------------------------------------------------------
    // Inside
    // ------------------------------------------------------------------------------------------

    /**
     * Definitions with their items. $tail: the WHERE (and ORDER / LIMIT) on item_pack p, sku ps (pack) and ss (single).
     *
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $tail, array $params): array
    {
        return $this->db->all('SELECT p.id, p.pack_sku_id, p.single_sku_id, p.units, p.is_active, p.note, p.created_at, p.created_actor, p.switched_at, '
            . 'ps.code AS pack_code, ps.name AS pack_name, ps.pack_units AS pack_pack_units, ps.merged_into_sku_id AS pack_merged, '
            . 'ss.code AS single_code, ss.name AS single_name, ss.pack_units AS single_pack_units, ss.merged_into_sku_id AS single_merged, '
            . 'cu.display_name AS created_by_name '
            . 'FROM item_pack p JOIN sku ps ON ps.id = p.pack_sku_id JOIN sku ss ON ss.id = p.single_sku_id LEFT JOIN staff_user cu ON cu.id = p.created_by ' . $tail,
            $params);
    }

    /**
     * Adds to each definition: `links` (pack item's and single's matched variants by units: n_pack_u1, n_pack_multi, n_single_u1,
     * n_single_un), its FLAGS that hold, `suggest_listings` (the pack item's variants with 1 unit, at most 20, when `suggest` holds) and
     * the stock on hand of both items in our own warehouses. Three reads for the whole page.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function flags(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['pack_sku_id'];
            $ids[] = (int) $r['single_sku_id'];
        }
        $ids = array_values(array_unique($ids));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $in = implode(', ', array_fill(0, count(self::LINKED), '?'));
        $byUnits = [];
        foreach ($this->db->all("SELECT sku_id, units_per_item, COUNT(*) AS n FROM channel_listing WHERE sku_id IN ({$ph}) AND status IN ({$in}) "
            . 'GROUP BY sku_id, units_per_item', [...$ids, ...self::LINKED]) as $c) {
            $byUnits[(int) $c['sku_id']][(int) $c['units_per_item']] = (int) $c['n'];
        }
        $stock = Families::onHand($this->db, $ids);
        $rule = self::onlyWhenSingleSold($this->db);
        $packIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['pack_sku_id'], $rows)));
        $listings = [];
        foreach ($this->db->all('SELECT cl.id, cl.sku_id, ch.name AS channel_name, cl.external_variant_id, lp.product_title, lp.variant_title FROM channel_listing cl '
            . 'JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.sku_id IN ('
            . implode(',', array_fill(0, count($packIds), '?')) . ") AND cl.units_per_item = 1 AND cl.status IN ({$in}) ORDER BY cl.sku_id, cl.id", [...$packIds, ...self::LINKED]) as $l) {
            if (count($listings[(int) $l['sku_id']] ?? []) < 20) {
                $listings[(int) $l['sku_id']][] = $l;
            }
        }
        foreach ($rows as $i => $r) {
            $pack = (int) $r['pack_sku_id'];
            $single = (int) $r['single_sku_id'];
            $u = (int) $r['units'];
            $packLinks = $byUnits[$pack] ?? [];
            $singleLinks = $byUnits[$single] ?? [];
            $packU1 = $packLinks[1] ?? 0;
            $packMulti = array_sum($packLinks) - $packU1;
            $singleU1 = $singleLinks[1] ?? 0;
            $singleUn = $singleLinks[$u] ?? 0;
            $countDiffers = $r['pack_pack_units'] !== null && $r['single_pack_units'] !== null
                && (int) $r['pack_pack_units'] !== $u * (int) $r['single_pack_units'];
            $sold = $singleU1 > 0;
            $suggest = (int) $r['is_active'] === 1 && $packU1 > 0 && (!$rule || $sold);
            $rows[$i]['links'] = ['pack_u1' => $packU1, 'pack_multi' => $packMulti, 'single_u1' => $singleU1, 'single_un' => $singleUn];
            $rows[$i]['flags'] = array_values(array_filter([
                $sold ? 'single_sold' : null, $singleUn > 0 ? 'sold_both_ways' : null, $packMulti > 0 ? 'pack_of_packs' : null,
                $countDiffers ? 'count_differs' : null, $suggest ? 'suggest' : null,
            ]));
            $rows[$i]['suggest_listings'] = $suggest ? ($listings[$pack] ?? []) : [];
            $rows[$i]['on_hand'] = ['pack' => $stock[$pack] ?? 0, 'single' => $stock[$single] ?? 0];
        }
        return $rows;
    }

    /**
     * Locks the two items FOR UPDATE in id order (two definitions of the same items made at once wait for each other). 404
     * unknown_item; 409 merged_item.
     *
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function lockItems(Db $db, int $packSkuId, int $singleSkuId): array
    {
        $ids = [$packSkuId, $singleSkuId];
        sort($ids);
        $out = [];
        foreach ($db->all('SELECT id, code, name, merged_into_sku_id FROM sku WHERE id IN (?, ?) ORDER BY id FOR UPDATE', $ids) as $r) {
            $out[(int) $r['id']] = $r;
        }
        foreach ([$packSkuId => 'pack', $singleSkuId => 'single'] as $id => $field) {
            $r = $out[$id] ?? throw new CwException('unknown_item', 'there is no such item', 404, ['field' => $field]);
            if ($r['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', "{$r['code']} was joined into another item: use that one", 409, ['field' => $field,
                    'merged_into' => (int) $r['merged_into_sku_id']]);
            }
            $out[$id] = ['id' => $id, 'code' => (string) $r['code'], 'name' => (string) $r['name']];
        }
        return $out;
    }

    /**
     * 409 pack_exists (the pack item has another definition switched on) / pack_nested (a pack of packs either way); $self: the
     * definition being switched on again (left out).
     *
     * @param array<int, array{id: int, code: string, name: string}> $items
     */
    private function checkRules(Db $db, int $pack, int $single, array $items, ?int $self): void
    {
        $other = $db->value('SELECT id FROM item_pack WHERE active_pack = ? AND id <> ?', [$pack, $self ?? 0]);
        if ($other !== null) {
            throw new CwException('pack_exists', "{$items[$pack]['code']} is already a pack item: switch that one off first", 409, ['pack_id' => (int) $other]);
        }
        if ($db->value('SELECT id FROM item_pack WHERE active_pack = ? LIMIT 1', [$single]) !== null) {
            throw new CwException('pack_nested', "{$items[$single]['code']} is itself a pack item: a pack is made of single items", 409, ['field' => 'single']);
        }
        if ($db->value('SELECT id FROM item_pack WHERE single_sku_id = ? AND is_active = 1 AND id <> ? LIMIT 1', [$pack, $self ?? 0]) !== null) {
            throw new CwException('pack_nested', "{$items[$pack]['code']} is the single of another pack item: a pack is not made of packs", 409, ['field' => 'pack']);
        }
    }
}
