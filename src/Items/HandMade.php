<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Admin\ApprovalRules;
use CW\Audit;
use CW\Caller;
use CW\Catalogue\ItemBarcodes;
use CW\Catalogue\ItemCards;
use CW\CwException;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\Matching\Gtin;

/**
 * Items made by hand (pack B; owner answer Q10 "yes": stock that arrives before its website page, trade-only or sister-only stock;
 * docs/decisions.md PB1-PB3). Products › Add item.
 *
 * create() makes, in ONE transaction:
 *   1. the checks first, before anything is written: the barcode (ItemBarcodes::parse; refused, 409 barcode_on_other_item, when it is
 *      already a barcode of another item, or when a variant matched to another item carries it: that item's barcode not yet copied
 *      to the item, the item page shows it), the same name AND the same identity (sameItems(): name, brand, strength, nicotine type,
 *      line, form, flavour, ml, puffs, pack count all equal; 409 same_item_exists with the other items, unless the person ticked "it is
 *      a different item": `anyway`), the item card's values (ItemCards::check, 422 card_invalid), the family (switched on);
 *   2. the item, through DecisionService::mintByHand (the only code that makes items): origin `manual`, no listing, its code
 *      CW-%06d of its id, the existing number series of items;
 *   3. its row in item_by_hand: who made it and, while approvals.item_by_hand_check is on (OFF by default: extra approvals start off),
 *      `waiting` for a second person's check afterwards (it is usable at once: a check after, never a block, so goods-in never waits);
 *   4. the barcode (ItemBarcodes::add, units per scan as typed), the family (Families::assign), then the item card (ItemCards::save,
 *      kind `change`): the card last, because a card whose block changes writes the stock feed, the last lock of a transaction.
 * Audit item.create (with the card, the barcode, the family, whether `anyway` was ticked and the other items it named).
 *
 * Who: items.create (the catalogue team: mapping_lead, stock_controller, purchasing_manager), never admin; the barcode and the card
 * are their own permission's (catalogue.edit) and are checked again by ItemBarcodes and ItemCards. check(): a second person holding
 * items.create who did not make it (403 own_item), once (409 not_waiting).
 */
final class HandMade
{
    public const PER_PAGE = 50;
    public const NOTE_MAX = 500;
    /** The items of one name read at a time by sameItems(), and the most of the same it names (the warning lists them). */
    public const SAME_PAGE = 500;
    public const SAME_MAX = 50;
    /** The approval switch (Admin\ApprovalRules): a second person checks the item afterwards. */
    public const CHECK_SWITCH = 'approvals.item_by_hand_check';
    public const STATES = ['not_asked', 'waiting', 'checked'];
    /** The identity fields compared by "the same item" (DecisionService::CARD_FIELDS but the name, compared on its own). */
    public const IDENTITY = ['brand', 'strength_mg', 'nic_type', 'line', 'form', 'flavour', 'volume_ml', 'puffs', 'pack_units'];

    private readonly DecisionService $ds;

    public function __construct(private readonly Db $db, ?DecisionService $ds = null)
    {
        $this->ds = $ds ?? new DecisionService($db);
    }

    /**
     * Makes an item by hand. $in:
     *   card          identity fields (DecisionService::CARD_FIELDS; name required)
     *   item_card     the item card's fields (ItemCards::FIELDS; empty ones are "not known"), optional
     *   barcode       optional; barcode_units: its units per scan (default 1)
     *   family_id     optional (a family switched on)
     *   anyway        true: the person saw the items of the same name and identity and says this is a different one
     *
     * @param array<string, mixed> $in
     * @return array{sku_id: int, code: string, check_state: string, same: list<array<string, mixed>>}
     */
    public function create(Caller $caller, array $in): array
    {
        // A field left blank is "not known" (a name of spaces is no name: 422 name_required).
        $card = DecisionService::cardFrom([], [], array_map(static fn (mixed $v): mixed => is_string($v) && trim($v) === '' ? null : $v,
            is_array($in['card'] ?? null) ? $in['card'] : []));
        $itemCard = is_array($in['item_card'] ?? null) ? array_filter($in['item_card'], static fn (mixed $v): bool => $v !== null && $v !== '') : [];
        if ($itemCard !== []) {
            ItemCards::check($itemCard);
        }
        $rawBarcode = trim((string) ($in['barcode'] ?? ''));
        $barcode = $rawBarcode === '' ? null : ItemBarcodes::parse($rawBarcode);
        $units = (int) ($in['barcode_units'] ?? 1);
        if ($barcode !== null && ($units < 1 || $units > ItemBarcodes::UNITS_MAX)) {
            throw new CwException('bad_units', 'a barcode stands for 1 to ' . ItemBarcodes::UNITS_MAX . ' units', 422, ['field' => 'barcode_units']);
        }
        $familyId = isset($in['family_id']) && $in['family_id'] !== null ? (int) $in['family_id'] : null;
        $anyway = ($in['anyway'] ?? false) === true;
        return $this->db->transaction(function (Db $db) use ($caller, $card, $itemCard, $barcode, $units, $familyId, $anyway): array {
            $me = ItemCards::editor($db, $caller, 'items.create');
            // The name's guard row first (insert, or update when it exists): two makings of one name at the same moment wait for
            // each other here, so the second reads the first below (READ COMMITTED: a fresh read) and is told the same item exists.
            $db->exec('INSERT INTO item_name_guard (name_key, last_at) VALUES (SHA2(WEIGHT_STRING(CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_0900_ai_ci), 256), '
                . 'UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE last_at = UTC_TIMESTAMP(6)', [(string) $card['name']]);
            if ($barcode !== null) {
                $this->refuseBarcode($db, $barcode);
            }
            $same = $this->sameItems($card);
            if ($same !== [] && !$anyway) {
                throw new CwException('same_item_exists', 'an item of this name and identity exists: open it, or say this is a different item', 409,
                    ['items' => array_map(static fn (array $s): array => ['id' => $s['id'], 'code' => $s['code'], 'name' => $s['name']], $same)]);
            }
            if ($familyId !== null) {
                $f = $db->one('SELECT name, is_active FROM item_family WHERE id = ? FOR SHARE', [$familyId])
                    ?? throw new CwException('unknown_family', 'there is no such family', 404, ['field' => 'family']);
                if ((int) $f['is_active'] !== 1) {
                    throw new CwException('family_off', "the family {$f['name']} is switched off", 422, ['field' => 'family']);
                }
            }
            $sku = $this->ds->mintByHand($caller, $card);
            $check = ApprovalRules::on($db, self::CHECK_SWITCH) ? 'waiting' : 'not_asked';
            $db->exec('INSERT INTO item_by_hand (sku_id, check_state, created_by, created_actor) VALUES (?, ?, ?, ?)', [$sku['id'], $check, $me['id'], $caller->actor]);
            if ($barcode !== null) {
                (new ItemBarcodes($db))->add($caller, $sku['id'], $barcode, $units);
            }
            if ($familyId !== null) {
                (new Families($db))->assign($caller, $sku['id'], $familyId, null);
            }
            if ($itemCard !== []) {
                (new ItemCards($db))->save($caller, $sku['id'], 0, $itemCard);
            }
            Audit::write($db, $caller, 'item.create', 'sku', (string) $sku['id'], null, ['code' => $sku['code'], 'card' => $sku['card'],
                'item_card' => $itemCard === [] ? null : array_keys($itemCard), 'barcode' => $barcode, 'barcode_units' => $barcode === null ? null : $units,
                'family_id' => $familyId, 'check' => $check, 'anyway' => $anyway, 'same' => array_map(static fn (array $s): string => (string) $s['code'], $same)]);
            return ['sku_id' => $sku['id'], 'code' => $sku['code'], 'check_state' => $check, 'same' => $same];
        });
    }

    /**
     * A second person's check of an item made by hand: "it is right" (with an optional note). The person holds items.create and did
     * not make it (403 own_item); the item waits for it (409 not_waiting; 404 unknown_item). Audit item.check.
     *
     * @return array{sku_id: int, check_state: string}
     */
    public function check(Caller $caller, int $skuId, ?string $note): array
    {
        $note = Families::note($note);
        return $this->db->transaction(function (Db $db) use ($caller, $skuId, $note): array {
            $me = ItemCards::editor($db, $caller, 'items.create');
            $r = $db->one('SELECT sku_id, check_state, created_by FROM item_by_hand WHERE sku_id = ? FOR UPDATE', [$skuId])
                ?? throw new CwException('unknown_item', 'this item was not made by hand', 404);
            if ((int) $r['created_by'] === $me['id']) {
                throw new CwException('own_item', 'a second person checks an item made by hand: not the person who made it', 403);
            }
            if ($r['check_state'] !== 'waiting') {
                throw new CwException('not_waiting', 'this item is not waiting for a check', 409, ['check_state' => $r['check_state']]);
            }
            $db->exec("UPDATE item_by_hand SET check_state = 'checked', checked_by = ?, checked_actor = ?, checked_at = UTC_TIMESTAMP(6), check_note = ? WHERE sku_id = ?",
                [$me['id'], $caller->actor, $note, $skuId]);
            Audit::write($db, $caller, 'item.check', 'sku', (string) $skuId, null, ['note' => $note]);
            return ['sku_id' => $skuId, 'check_state' => 'checked'];
        });
    }

    /**
     * The items of the same name AND the same identity (every IDENTITY field equal, an unknown equal only to an unknown), not
     * joined into another: what "Add item" warns about. The name's index (ix_sku_name, 0026) finds the candidates.
     *
     * @param array<string, mixed> $card cardFrom()'s card
     * @return list<array{id: int, code: string, name: string, brand: ?string}>
     */
    public function sameItems(array $card): array
    {
        $name = (string) ($card['name'] ?? '');
        if ($name === '') {
            return [];
        }
        // Every item of the name, a page of SAME_PAGE at a time on the name's index (ix_sku_name, then the id): the identity is compared
        // on all of them before anything is cut (the review of b62c69f, finding 2); at most SAME_MAX of the same are named.
        $out = [];
        $after = 0;
        do {
            // (an item joined into another is passed over here, not in the WHERE: the read stays on the name's index, in id order)
            $rows = $this->db->all('SELECT id, code, name, brand, strength_mg, nic_type, line, form, flavour, volume_ml, puffs, pack_units, merged_into_sku_id '
                . 'FROM sku WHERE name = ? AND id > ? ORDER BY id LIMIT ' . self::SAME_PAGE, [$name, $after]);
            foreach ($rows as $r) {
                $after = (int) $r['id'];
                $same = $r['merged_into_sku_id'] === null;
                foreach (self::IDENTITY as $f) {
                    if (!self::equal($f, $card[$f] ?? null, $r[$f])) {
                        $same = false;
                        break;
                    }
                }
                if ($same && count($out) < self::SAME_MAX) {
                    $out[] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'brand' => $r['brand'] === null ? null : (string) $r['brand']];
                }
            }
        } while (count($rows) === self::SAME_PAGE);
        return $out;
    }

    /**
     * The items made by hand, newest first (a page of PER_PAGE), with who made them and their check; `waiting` only: the ones a
     * second person should check.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function recent(bool $waiting, int $page): array
    {
        $where = $waiting ? "WHERE h.check_state = 'waiting'" : '';
        $total = (int) $this->db->value("SELECT COUNT(*) FROM item_by_hand h {$where}");
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        // The page's ids first, on the index alone (ix_item_by_hand_created or ix_item_by_hand_check, newest first), then their columns.
        $ids = array_map('intval', $this->db->column('SELECT h.sku_id FROM item_by_hand h ' . $where . ' ORDER BY h.created_at DESC, h.sku_id DESC LIMIT '
            . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE)));
        $rows = $ids === [] ? [] : $this->db->all('SELECT h.sku_id, h.check_state, h.created_by, h.created_at, h.checked_at, h.check_note, s.code, s.name, s.brand, '
            . 'cu.display_name AS created_by_name, xu.display_name AS checked_by_name FROM item_by_hand h JOIN sku s ON s.id = h.sku_id '
            . 'LEFT JOIN staff_user cu ON cu.id = h.created_by LEFT JOIN staff_user xu ON xu.id = h.checked_by '
            . 'WHERE h.sku_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY h.created_at DESC, h.sku_id DESC', $ids);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** How many items made by hand wait for a check. */
    public function waitingCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM item_by_hand WHERE check_state = 'waiting'");
    }

    /**
     * 409 barcode_on_other_item when the barcode is an item's already (sku_barcode, in any of its stored forms), or carried by a variant
     * matched to an item (that item's barcode not yet copied to it: listing_profile.barcodes, the multi-valued index of 0005).
     */
    private function refuseBarcode(Db $db, string $key): void
    {
        $forms = Gtin::forms([$key]);
        $in = implode(',', array_fill(0, count($forms), '?'));
        $item = $db->one("SELECT s.id, s.code, s.name FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode IN ({$in}) ORDER BY s.id LIMIT 1", $forms);
        if ($item === null) {
            $item = $db->one('SELECT s.id, s.code, s.name FROM listing_profile lp JOIN channel_listing cl ON cl.id = lp.listing_id JOIN sku s ON s.id = cl.sku_id '
                . "WHERE JSON_OVERLAPS(lp.barcodes, CAST(? AS JSON)) AND cl.status IN ('mapped', 'quarantined') ORDER BY s.id LIMIT 1",
                [json_encode($forms, JSON_THROW_ON_ERROR)]);
        }
        if ($item !== null) {
            throw new CwException('barcode_on_other_item', "{$key} is a barcode of {$item['code']} ({$item['name']}): a barcode belongs to one item", 409,
                ['sku_id' => (int) $item['id'], 'code' => (string) $item['code'], 'field' => 'barcode']);
        }
    }

    /** One identity field equal on both sides (numbers as numbers, words ignoring case and spaces; an unknown equal only to an unknown). */
    private static function equal(string $field, mixed $a, mixed $b): bool
    {
        if ($a === null || $a === '' || $b === null || $b === '') {
            return ($a === null || $a === '') && ($b === null || $b === '');
        }
        if (in_array($field, ['strength_mg', 'volume_ml', 'puffs', 'pack_units'], true)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }
        $norm = static fn (mixed $v): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $v)));
        return $norm($a) === $norm($b);
    }
}
