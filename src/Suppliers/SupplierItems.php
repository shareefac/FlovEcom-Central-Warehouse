<?php

declare(strict_types=1);

namespace CW\Suppliers;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Staff\StaffRoles;

/**
 * Supplier items and their prices (IM4; docs/decisions.md I43-I44): what a supplier sells us, under their code, in which
 * purchase unit (a box of 24 = units_per_pack central units), the minimum order and the order multiple in packs, the
 * lead time, whether it is the item's PREFERRED supply (at most one active preferred supplier item per item: the
 * generated preferred_sku_id + UNIQUE), and its price history.
 *
 * Prices are GBP excluding VAT per purchase unit with at most 4 decimals (DECIMAL(14,4)); the unit price is pack price /
 * units per pack, half-up to 6 decimals (micro-GBP, as the value core's unit costs, I1), computed with integers only.
 * The LAST PRICE (last_pack_price / last_price_on / last_price_source) mirrors the newest (effective_on, id) history row
 * whose source is import, manual or invoice AND whose pack size is the supplier item's current units_per_pack (a price
 * is the price of a pack: a changed pack size drops the old pack's price, I74); a PO price goes to the history as source
 * `po` and only sets last_po_* (the pos task), so a PO never pre-fills the next PO with its own price (invariant S4).
 *
 * Writes need suppliers.manage (staff caller, roles re-read inside the transaction), are ONE transaction each and lock the
 * supplier_item row FOR UPDATE (after the supplier row when one is locked: §6.9); updates carry the version.
 */
final class SupplierItems
{
    public const PURCHASE_UNIT_MAX = 32;
    public const MAX_UNITS_PER_PACK = 100_000;
    public const MAX_MOQ = 100_000;
    public const MAX_MULTIPLE = 10_000;
    /** create()'s is_preferred value "auto": preferred when the item has no active preferred supply yet (I75). */
    public const PREFERRED_AUTO = 'auto';
    /** Fields of create() / update(). */
    public const FIELDS = ['supplier_code', 'supplier_description', 'purchase_unit', 'units_per_pack', 'moq_packs', 'order_multiple_packs', 'lead_days',
        'is_preferred', 'is_active'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM supplier_item WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed> (404 unknown_supplier_item) */
    public function get(int $id): array
    {
        return $this->find($id) ?? throw new CwException('unknown_supplier_item', 'there is no such supplier item', 404);
    }

    /**
     * A new supplier item (suppliers.manage). The supplier may be in any status (a buyer prepares the items while the
     * activation waits). The item must exist and not be merged (422 unknown_sku / merged_item). 409 duplicate_pack for a
     * second row of the same supplier, item and pack; 409 duplicate_supplier_code for a code the supplier already uses.
     * is_preferred: 1 makes this the item's preferred supply (any other preferred row of the item is unset); 'auto' makes it
     * preferred only when the item has no active preferred supply yet (I75); absent or 0: an alternative. An optional
     * first price ($price: pack_price, effective_on, note) is recorded as a manual price in the same transaction.
     *
     * @param array<string, mixed> $fields FIELDS
     * @param array{pack_price: string, effective_on?: ?string, note?: ?string, source?: string, source_ref?: ?string}|null $price
     * @return array<string, mixed> the new row
     */
    public function create(Caller $caller, int $supplierId, int $skuId, array $fields, ?array $price = null): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $supplierId, $skuId, $fields, $price): array {
            $me = $this->staff($caller);
            if ($db->value('SELECT 1 FROM supplier WHERE id = ? FOR SHARE', [$supplierId]) === null) {
                throw new CwException('unknown_supplier', 'there is no such supplier', 404);
            }
            $this->checkSku($skuId);
            $auto = ($fields['is_preferred'] ?? null) === self::PREFERRED_AUTO;
            if ($auto) {
                unset($fields['is_preferred']);
            }
            $v = self::normalise($fields, null);
            $v += ['purchase_unit' => 'each', 'units_per_pack' => 1, 'moq_packs' => 1, 'order_multiple_packs' => 1, 'is_preferred' => 0, 'is_active' => 1];
            $preferred = (int) $v['is_preferred'] === 1 && (int) $v['is_active'] === 1;
            $v['is_preferred'] = 0;
            $this->checkDuplicates($supplierId, $skuId, $v, null);
            $now = $this->nowDb();
            $cols = ['supplier_id', 'sku_id', ...array_keys($v), 'version', 'created_by', 'created_actor', 'created_at', 'updated_by', 'updated_actor', 'updated_at'];
            $params = [$supplierId, $skuId, ...array_values($v), 1, $me['id'], $caller->actor, $now, $me['id'], $caller->actor, $now];
            try {
                $id = $db->insert('INSERT INTO supplier_item (' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $cols)) . ') VALUES ('
                    . implode(', ', array_fill(0, count($cols), '?')) . ')', $params);
            } catch (\PDOException $e) {
                throw self::duplicate($e) ?? $e;
            }
            if ($preferred) {
                $this->prefer($id, $skuId);
            } elseif ($auto && (int) $v['is_active'] === 1) {
                // "auto" (the screen's default, the import's empty column, the PO editor's "save as this supplier's item"; I75):
                // the item's preferred supply when it has none yet. The UNIQUE preferred_sku_id decides: a duplicate key
                // means another supply is preferred (or became so at this moment), and this one stays an alternative.
                try {
                    $db->exec('UPDATE supplier_item SET is_preferred = 1 WHERE id = ?', [$id]);
                    $preferred = true;
                } catch (\PDOException $e) {
                    if (Db::driverCode($e) !== 1062) {
                        throw $e;
                    }
                }
            }
            Audit::write($db, $caller, 'supplier_item.create', 'supplier_item', (string) $id, null,
                ['supplier_id' => $supplierId, 'sku_id' => $skuId, 'fields' => $v, 'preferred' => $preferred] + ($auto ? ['preferred_auto' => true] : []));
            if ($price !== null) {
                $this->addPrice($caller, $me['id'], $id, $price['pack_price'], $price['effective_on'] ?? null, $price['note'] ?? null,
                    $price['source'] ?? 'manual', $price['source_ref'] ?? null);
            }
            return $this->get($id);
        });
    }

    /**
     * Changes a supplier item at $expectedVersion (suppliers.manage): every field. is_preferred 1 makes it the item's
     * preferred supply (the others are unset); is_active 0 also unsets its preferred flag. A changed pack size is refused
     * once a PO line uses the item (409 pack_in_use: add a new supplier item for the new pack; checkPackChange()); allowed,
     * it takes the newest price recorded for the new size, or none (I74). Nothing changed: nothing is written.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function update(Caller $caller, int $id, int $expectedVersion, array $fields): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $expectedVersion, $fields): array {
            $me = $this->staff($caller);
            $row = $this->lock($id);
            if ((int) $row['version'] !== $expectedVersion) {
                throw new CwException('version_conflict', 'the supplier item changed since this page was drawn: reload it and try again', 409,
                    ['version' => (int) $row['version'], 'expected_version' => $expectedVersion]);
            }
            $v = self::normalise($fields, $row);
            $after = array_merge($row, $v);
            // The preferred flag is set only through prefer() (after the item's other preferred rows are unset); it is
            // dropped when the row is no longer wanted as preferred or is switched off.
            $wasPreferred = (int) $row['is_preferred'] === 1;
            $wantPreferred = (int) $after['is_active'] === 1 && (int) $after['is_preferred'] === 1;
            unset($v['is_preferred']);
            if ($wasPreferred && !$wantPreferred) {
                $v['is_preferred'] = 0;
            }
            $becomesPreferred = $wantPreferred && !($wasPreferred && (int) $row['is_active'] === 1);
            $changed = [];
            foreach ($v as $c => $val) {
                if (Suppliers::str($row[$c]) !== Suppliers::str($val)) {
                    $changed[$c] = [$row[$c], $val];
                }
            }
            if ($changed === [] && !$becomesPreferred) {
                return $row;
            }
            if (isset($changed['units_per_pack'])) {
                $this->checkPackChange($id);
            }
            $this->checkDuplicates((int) $row['supplier_id'], (int) $row['sku_id'], $after, $id);
            $set = [];
            $params = [];
            foreach ($v as $c => $val) {
                $set[] = "`{$c}` = ?";
                $params[] = $val;
            }
            $now = $this->nowDb();
            array_push($params, $me['id'], $caller->actor, $now, $id);
            try {
                $db->exec('UPDATE supplier_item SET ' . ($set === [] ? '' : implode(', ', $set) . ', ')
                    . 'version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?', $params);
            } catch (\PDOException $e) {
                throw self::duplicate($e) ?? $e;
            }
            if ($becomesPreferred) {
                $this->prefer($id, (int) $row['sku_id']);
                $changed['is_preferred'] = [$row['is_preferred'], 1];
            }
            if (isset($changed['units_per_pack'])) {
                // The last price is the price OF A PACK (review finding, I74): a new pack size takes the newest price
                // recorded for that size, or none ("enter the new pack's price"), never the old pack's.
                $last = $this->refreshLastPrice($id, (int) $after['units_per_pack']);
                if (Suppliers::str($last['last_pack_price']) !== Suppliers::str($row['last_pack_price'])) {
                    $changed['last_pack_price'] = [$row['last_pack_price'], $last['last_pack_price']];
                }
            }
            Audit::write($db, $caller, 'supplier_item.update', 'supplier_item', (string) $id, null, ['version' => $expectedVersion + 1, 'changed' => $changed]);
            return $this->get($id);
        });
    }

    /**
     * Makes this supplier item the preferred supply of its item ($preferred) or not. In one transaction the item's other
     * preferred rows are unset, then this one is set; two people preferring two supplier items of one item at the same
     * moment meet on the UNIQUE preferred_sku_id: the loser is retried once (it then unsets the winner), then 409
     * preferred_busy. $expectedVersion, when given, must match.
     *
     * @return array<string, mixed>
     */
    public function setPreferred(Caller $caller, int $id, bool $preferred = true, ?int $expectedVersion = null): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->db->transaction(function (Db $db) use ($caller, $id, $preferred, $expectedVersion): array {
                    $me = $this->staff($caller);
                    $row = $this->lock($id);
                    if ($expectedVersion !== null && (int) $row['version'] !== $expectedVersion) {
                        throw new CwException('version_conflict', 'the supplier item changed since this page was drawn: reload it and try again', 409);
                    }
                    $is = (int) $row['is_preferred'] === 1 && (int) $row['is_active'] === 1;
                    if ($is === $preferred) {
                        return $row;
                    }
                    if ($preferred && (int) $row['is_active'] !== 1) {
                        throw new CwException('supplier_item_inactive', 'an inactive supplier item cannot be the preferred supply', 422);
                    }
                    $now = $this->nowDb();
                    if ($preferred) {
                        $this->prefer($id, (int) $row['sku_id']);
                    } else {
                        $db->exec('UPDATE supplier_item SET is_preferred = 0 WHERE id = ?', [$id]);
                    }
                    $db->exec('UPDATE supplier_item SET version = version + 1, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?',
                        [$me['id'], $caller->actor, $now, $id]);
                    Audit::write($db, $caller, 'supplier_item.update', 'supplier_item', (string) $id, null,
                        ['version' => (int) $row['version'] + 1, 'changed' => ['is_preferred' => [(int) $row['is_preferred'], $preferred ? 1 : 0]]]);
                    return $this->get($id);
                });
            } catch (\PDOException $e) {
                if (Db::driverCode($e) !== 1062) {
                    throw $e;
                }
                if ($attempt >= 1) {
                    throw new CwException('preferred_busy', 'another supplier item of this item was made preferred at the same moment: reload and try again', 409);
                }
            }
        }
    }

    /**
     * Records a manual price (suppliers.manage): $packPrice GBP excl. VAT per purchase unit (≥ 0, at most 4 decimals;
     * £ and thousands commas are ignored), $effectiveOn on or before today (default today, UK), an optional note. A
     * history row (source manual, unit_price = pack price / units per pack half-up to 6 decimals); the last price moves
     * when $effectiveOn is on or after the current last price's date (an older price goes to the history only).
     *
     * @return array<string, mixed> the supplier item afterwards
     */
    public function recordPrice(Caller $caller, int $id, string $packPrice, ?string $effectiveOn, ?string $note): array
    {
        return $this->db->transaction(function () use ($caller, $id, $packPrice, $effectiveOn, $note): array {
            $me = $this->staff($caller);
            $this->addPrice($caller, $me['id'], $id, $packPrice, $effectiveOn, $note, 'manual', null);
            return $this->get($id);
        });
    }

    /**
     * A price of the ERPNext seed import (source import): as recordPrice(), inside the import's transaction.
     *
     * @internal ErpSeedImport
     */
    public function importPrice(Caller $caller, int $id, string $packPrice, ?string $effectiveOn, ?string $sourceRef): void
    {
        $this->db->transaction(function () use ($caller, $id, $packPrice, $effectiveOn, $sourceRef): void {
            $me = $this->staff($caller);
            $this->addPrice($caller, $me['id'], $id, $packPrice, $effectiveOn, null, 'import', $sourceRef);
        });
    }

    /**
     * The price history of a supplier item, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $id, int $limit = 200): array
    {
        return $this->db->all(
            'SELECT p.*, u.display_name AS recorded_by_name, d.number AS document_number FROM supplier_item_price p '
            . 'LEFT JOIN staff_user u ON u.id = p.recorded_by LEFT JOIN document d ON d.id = p.document_id '
            . 'WHERE p.supplier_item_id = ? ORDER BY p.effective_on DESC, p.id DESC LIMIT ' . max(1, min(1000, $limit)),
            [$id],
        );
    }

    /** pack price / units per pack, half-up to 6 decimals, from a 4-decimal pack price (integers only). */
    public static function unitPrice(string $packPrice, int $unitsPerPack): string
    {
        if ($unitsPerPack < 1 || preg_match('/^(\d{1,10})(?:\.(\d{1,4}))?$/D', $packPrice, $m) !== 1) {
            throw new \InvalidArgumentException('a pack price with at most 4 decimals and a positive pack size');
        }
        $e4 = (int) ($m[1] . str_pad($m[2] ?? '', 4, '0'));
        $e6 = intdiv($e4 * 200 + $unitsPerPack, 2 * $unitsPerPack); // round(e4 * 100 / upp), half-up
        return intdiv($e6, 1_000_000) . '.' . str_pad((string) ($e6 % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * A pack price as typed (£, spaces and thousands commas ignored): ≥ 0, at most 10 digits and 4 decimals, returned
     * with 4 decimals; 422 bad_price otherwise.
     */
    public static function packPrice(string $raw, string $field = 'pack_price'): string
    {
        $s = str_replace([',', '£', ' ', "\u{00A0}"], '', trim($raw));
        if (preg_match('/^(\d{1,10})(?:\.(\d{1,4}))?$/D', $s, $m) !== 1 && preg_match('/^()\.(\d{1,4})$/D', $s, $m) !== 1) {
            throw new CwException('bad_price', 'a pack price is an amount in GBP of at least 0 with at most 4 decimals', 422, ['field' => $field]);
        }
        $int = ltrim($m[1], '0');
        return ($int === '' ? '0' : $int) . '.' . str_pad($m[2] ?? '', 4, '0');
    }

    // ------------------------------------------------------------------------------------------

    private function addPrice(Caller $caller, int $by, int $id, string $packPrice, ?string $effectiveOn, ?string $note, string $source, ?string $sourceRef): void
    {
        $row = $this->lock($id);
        $price = self::packPrice($packPrice);
        $today = $this->today();
        if ($effectiveOn === null || trim($effectiveOn) === '') {
            $on = $today;
        } else {
            $on = Suppliers::date($effectiveOn) ?? throw new CwException('bad_field', 'effective on: a date, YYYY-MM-DD', 422, ['field' => 'effective_on']);
        }
        if ($on > $today) {
            throw new CwException('bad_field', 'effective on: a price cannot take effect after today', 422, ['field' => 'effective_on']);
        }
        if ($note !== null) {
            $note = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $note));
            if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > 255) {
                throw new CwException('bad_field', 'note: at most 255 characters', 422, ['field' => 'note']);
            }
            $note = $note === '' ? null : $note;
        }
        if ($sourceRef !== null) {
            $sourceRef = mb_substr(trim($sourceRef), 0, 191);
            $sourceRef = $sourceRef === '' ? null : $sourceRef;
        }
        $upp = (int) $row['units_per_pack'];
        $unit = self::unitPrice($price, $upp);
        $priceId = $this->db->insert(
            'INSERT INTO supplier_item_price (supplier_item_id, pack_price, units_per_pack, unit_price, source, source_ref, effective_on, note, recorded_by, recorded_actor, recorded_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $price, $upp, $unit, $source, $sourceRef, $on, $note, $by, $caller->actor, $this->nowDb()],
        );
        $last = $row['last_price_on'] === null || $on >= (string) $row['last_price_on'];
        if ($last) {
            $this->db->exec('UPDATE supplier_item SET last_pack_price = ?, last_price_on = ?, last_price_source = ?, version = version + 1, '
                . 'updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?', [$price, $on, $source, $by, $caller->actor, $this->nowDb(), $id]);
        }
        Audit::write($this->db, $caller, 'supplier_item.price', 'supplier_item', (string) $id, null,
            ['price_id' => $priceId, 'pack_price' => $price, 'unit_price' => $unit, 'effective_on' => $on, 'source' => $source, 'source_ref' => $sourceRef,
                'last_price' => $last]);
    }

    /**
     * Sets last_pack_price / last_price_on / last_price_source from the newest (effective_on, id) import, manual or invoice
     * price recorded for pack size $upp (all NULL when none): the last-price rule of a changed pack (I74, S4).
     *
     * @return array{last_pack_price: ?string, last_price_on: ?string, last_price_source: ?string}
     */
    private function refreshLastPrice(int $id, int $upp): array
    {
        $p = $this->db->one("SELECT pack_price, effective_on, CAST(source AS CHAR) AS source FROM supplier_item_price WHERE supplier_item_id = ? AND source <> 'po' "
            . 'AND units_per_pack = ? ORDER BY effective_on DESC, id DESC LIMIT 1', [$id, $upp]);
        $last = ['last_pack_price' => $p['pack_price'] ?? null, 'last_price_on' => $p['effective_on'] ?? null, 'last_price_source' => $p['source'] ?? null];
        $this->db->exec('UPDATE supplier_item SET last_pack_price = ?, last_price_on = ?, last_price_source = ? WHERE id = ?',
            [$last['last_pack_price'], $last['last_price_on'], $last['last_price_source'], $id]);
        return $last;
    }

    /**
     * Unsets the item's other preferred rows (their version moves: a form drawn before cannot set them back unseen), then
     * sets this one (the UNIQUE preferred_sku_id decides a race).
     */
    private function prefer(int $id, int $skuId): void
    {
        $this->db->exec('UPDATE supplier_item SET is_preferred = 0, version = version + 1 WHERE sku_id = ? AND id <> ? AND is_preferred = 1', [$skuId, $id]);
        $this->db->exec('UPDATE supplier_item SET is_preferred = 1 WHERE id = ?', [$id]);
    }

    /**
     * 409 pack_in_use once a PO line references the supplier item (po_line, 0010; spec §5.4): its pack is part of what was
     * ordered (and of the PO's posting anchor). Stricter than the spec's "posted": a draft or an approval request that uses
     * it counts too, because the line keeps the old pack and the next save would refuse it (I55). Only a cancelled draft
     * does not count. Add a new supplier item for the new pack size instead.
     */
    private function checkPackChange(int $id): void
    {
        $doc = $this->db->value(
            "SELECT COALESCE(d.number, CONCAT(REPLACE(d.status, '_', ' '), ' #', d.id)) FROM po_line pl JOIN document d ON d.id = pl.document_id "
            . "WHERE pl.supplier_item_id = ? AND d.status <> 'cancelled' ORDER BY d.id LIMIT 1",
            [$id],
        );
        if ($doc !== null) {
            throw new CwException('pack_in_use', "purchase order {$doc} uses this supplier item in its current pack: add a new supplier item for the new pack size", 409,
                ['field' => 'units_per_pack']);
        }
    }

    private function checkSku(int $skuId): void
    {
        $s = $this->db->one('SELECT id, code, merged_into_sku_id FROM sku WHERE id = ?', [$skuId]);
        if ($s === null) {
            throw new CwException('unknown_sku', 'there is no such item', 422, ['field' => 'sku_id']);
        }
        if ($s['merged_into_sku_id'] !== null) {
            $into = $this->db->value('SELECT code FROM sku WHERE id = ?', [(int) $s['merged_into_sku_id']]);
            throw new CwException('merged_item', "item {$s['code']} was merged into {$into}: use that one", 422,
                ['field' => 'sku_id', 'merged_into' => (int) $s['merged_into_sku_id']]);
        }
    }

    /** @param array<string, mixed> $v */
    private function checkDuplicates(int $supplierId, int $skuId, array $v, ?int $id): void
    {
        if ($this->db->value('SELECT 1 FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = ? AND id <> ?',
            [$supplierId, $skuId, (int) $v['units_per_pack'], $id ?? 0]) !== null) {
            throw new CwException('duplicate_pack', "this supplier already has this item in packs of {$v['units_per_pack']}", 409, ['field' => 'units_per_pack']);
        }
        if (($v['supplier_code'] ?? null) !== null && $this->db->value('SELECT 1 FROM supplier_item WHERE supplier_id = ? AND supplier_code = ? AND id <> ?',
            [$supplierId, $v['supplier_code'], $id ?? 0]) !== null) {
            throw new CwException('duplicate_supplier_code', "this supplier already uses the code {$v['supplier_code']} for another item", 409,
                ['field' => 'supplier_code']);
        }
    }

    private static function duplicate(\PDOException $e): ?CwException
    {
        if (Db::driverCode($e) !== 1062) {
            return null;
        }
        $m = $e->getMessage();
        return match (true) {
            str_contains($m, 'uq_supplier_item_code') => new CwException('duplicate_supplier_code', 'this supplier already uses this code for another item', 409,
                ['field' => 'supplier_code']),
            str_contains($m, 'uq_supplier_item_pack') => new CwException('duplicate_pack', 'this supplier already has this item in this pack size', 409,
                ['field' => 'units_per_pack']),
            default => null,
        };
    }

    /**
     * The given fields as column values (only the keys given). 400 bad_field for an unknown key, 422 bad_field for a bad
     * value.
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    public static function normalise(array $in, ?array $current): array
    {
        $out = [];
        foreach ($in as $k => $raw) {
            $k = (string) $k;
            if (!in_array($k, self::FIELDS, true)) {
                throw new CwException('bad_field', 'a supplier item has no field ' . mb_substr($k, 0, 40), 400, ['field' => mb_substr($k, 0, 40)]);
            }
            $bad = static fn (string $why): CwException => new CwException('bad_field', str_replace('_', ' ', $k) . ": {$why}", 422, ['field' => $k]);
            if (is_bool($raw)) {
                $raw = $raw ? '1' : '0';
            } elseif (is_int($raw)) {
                $raw = (string) $raw;
            } elseif ($raw !== null && (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8'))) {
                throw $bad('must be text');
            }
            $s = $raw === null ? '' : trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw));
            $int = static function (int $min, int $max, ?int $default) use ($s, $bad): ?int {
                if ($s === '') {
                    return $default;
                }
                if (preg_match('/^\d{1,7}$/D', $s) !== 1 || (int) $s < $min || (int) $s > $max) {
                    throw $bad("a whole number from {$min} to {$max}");
                }
                return (int) $s;
            };
            $out[$k] = match ($k) {
                'supplier_code' => mb_strlen($s) > 64 ? throw $bad('at most 64 characters') : ($s === '' ? null : $s),
                'supplier_description' => mb_strlen($s) > 255 ? throw $bad('at most 255 characters') : ($s === '' ? null : $s),
                'purchase_unit' => mb_strlen($s) > self::PURCHASE_UNIT_MAX ? throw $bad('at most ' . self::PURCHASE_UNIT_MAX . ' characters') : ($s === '' ? 'each' : $s),
                'units_per_pack' => $int(1, self::MAX_UNITS_PER_PACK, 1),
                'moq_packs' => $int(1, self::MAX_MOQ, 1),
                'order_multiple_packs' => $int(1, self::MAX_MULTIPLE, 1),
                'lead_days' => $int(0, 120, null),
                'is_preferred', 'is_active' => match (strtolower($s)) {
                    '1', 'true', 'yes', 'y', 'on' => 1,
                    '', '0', 'false', 'no', 'n', 'off' => 0,
                    default => throw $bad('yes or no (1 or 0)'),
                },
            };
        }
        return $out;
    }

    /** @return array<string, mixed> the supplier item row, X-locked (404) */
    private function lock(int $id): array
    {
        return $this->db->one('SELECT * FROM supplier_item WHERE id = ? FOR UPDATE', [$id])
            ?? throw new CwException('unknown_supplier_item', 'there is no such supplier item', 404);
    }

    /** @return array{id: int, roles: list<string>} */
    private function staff(Caller $caller): array
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'supplier items are changed by staff', 403);
        }
        $roles = StaffRoles::active($this->db, $caller->staffUserId);
        if (!Permissions::can($roles, 'suppliers.manage')) {
            throw new CwException('role_not_allowed', ucfirst(Suppliers::rolesPhrase($roles)) . ' cannot change supplier items', 403);
        }
        return ['id' => $caller->staffUserId, 'roles' => $roles];
    }

    private function today(): string
    {
        return ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
    }

    private function nowDb(): string
    {
        return Clock::db(($this->clock)());
    }
}
