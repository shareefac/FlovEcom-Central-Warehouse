<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * Warehouses and the optional places inside them (0001, 0019; docs/decisions.md D9, D11, Y14-Y19; the owner's answers Q2, Q6, Q9):
 *
 *  - The list shows every warehouse with the stock it holds now (in the building, sold waiting to ship, reserved), whose stock it
 *    is, whether websites sell from it and which ones.
 *  - An admin or a reviewer (settings.manage) adds a warehouse, renames it, switches it off (only when it is empty: no stock, no
 *    website selling from it, no open record or count naming it) or on again, and says whose stock it holds: our own, or another
 *    account's (the VPG 2 room: never sellable, a CHECK; its stock is released into the main warehouse by a release invoice, a
 *    later pack). Whose stock changes only while the warehouse is empty, with a confirmation tick (Y48): otherwise another
 *    account's stock would become ours, and sellable, with no document. Nothing is ever deleted (Q9).
 *  - Whether websites may sell from a warehouse (`is_sellable`) changes only with a confirmation (`$confirmed`, the page's tick),
 *    never for MAIN, VERIFY and UNSTAMPED (the code names them: D11), never while a website is assigned to it (the composite FK
 *    of D9 would refuse it anyway), and never for another account's stock.
 *  - Places inside a warehouse (a shelf, the overflow room) are OPTIONAL: nothing asks for one (Q2). They are added, renamed and
 *    switched off or on; document_line.location_id is ready for the count and adjustment screens.
 * Every change has a reason, is a config_change version (key: the warehouse code, or CODE/PLACE) and an audit row.
 */
final class Warehouses
{
    public const SYSTEM = ['MAIN', 'VERIFY', 'UNSTAMPED'];
    public const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{1,31}$/D';
    public const PLACE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{0,30}$/D';
    public const OWNERS = ['own', 'other'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Every warehouse, in its order: id, code, name, flags, owner, note, the stock it holds now (on_hand, allocated, held, items
     * with stock), the websites selling from it (sellable assignment) and the other websites assigned to it, its places (active /
     * all), its history version.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $stock = [];
        foreach ($this->db->all('SELECT warehouse_id, SUM(on_hand) AS on_hand, SUM(allocated) AS allocated, SUM(held) AS held, SUM(on_hand <> 0) AS items '
            . 'FROM stock_balance GROUP BY warehouse_id') as $r) {
            $stock[(int) $r['warehouse_id']] = ['on_hand' => (int) $r['on_hand'], 'allocated' => (int) $r['allocated'], 'held' => (int) $r['held'], 'items' => (int) $r['items']];
        }
        $sites = [];
        foreach ($this->db->all('SELECT cw.warehouse_id, cw.is_sellable, c.name FROM channel_warehouse cw JOIN channel c ON c.id = cw.channel_id ORDER BY c.name') as $r) {
            $sites[(int) $r['warehouse_id']][(int) $r['is_sellable'] === 1 ? 'selling' : 'assigned'][] = (string) $r['name'];
        }
        $places = [];
        foreach ($this->db->all('SELECT warehouse_id, COUNT(*) AS n, SUM(is_active) AS active FROM warehouse_location GROUP BY warehouse_id') as $r) {
            $places[(int) $r['warehouse_id']] = ['all' => (int) $r['n'], 'active' => (int) $r['active']];
        }
        $versions = [];
        foreach ($this->db->all("SELECT subject_key, MAX(version) AS v FROM config_change WHERE subject_type = 'warehouse' GROUP BY subject_key") as $r) {
            $versions[(string) $r['subject_key']] = (int) $r['v'];
        }
        $out = [];
        foreach ($this->db->all('SELECT * FROM warehouse ORDER BY is_active DESC, sort_order, code') as $r) {
            $id = (int) $r['id'];
            $out[] = self::row($r) + [
                'stock' => $stock[$id] ?? ['on_hand' => 0, 'allocated' => 0, 'held' => 0, 'items' => 0],
                'selling' => $sites[$id]['selling'] ?? [], 'assigned' => $sites[$id]['assigned'] ?? [],
                'places' => $places[$id] ?? ['all' => 0, 'active' => 0],
                'version' => $versions[(string) $r['code']] ?? 0,
            ];
        }
        return $out;
    }

    /** @return array<string, mixed>|null one warehouse as all() gives it, with its places (list) and why it is not empty (list of codes) */
    public function get(int $id): ?array
    {
        foreach ($this->all() as $w) {
            if ($w['id'] === $id) {
                return $w + ['places_list' => $this->places($id), 'not_empty' => self::notEmpty($this->db, $id)];
            }
        }
        return null;
    }

    /** @return list<array{id: int, code: string, name: string, is_active: bool, note: ?string, key: string, version: int}> */
    public function places(int $warehouseId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT l.id, l.code, l.name, l.is_active, l.note, w.code AS wh FROM warehouse_location l JOIN warehouse w ON w.id = l.warehouse_id '
            . 'WHERE l.warehouse_id = ? ORDER BY l.is_active DESC, l.code', [$warehouseId]) as $r) {
            $key = ConfigHistory::locationKey((string) $r['wh'], (string) $r['code']);
            $out[] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'is_active' => (int) $r['is_active'] === 1,
                'note' => $r['note'] === null ? null : (string) $r['note'], 'key' => $key, 'version' => ConfigHistory::version($this->db, 'location', $key)];
        }
        return $out;
    }

    /**
     * Why a warehouse cannot be switched off: `stock` (in the building, sold or reserved there), `website` (a website is assigned to
     * it), `records` (a draft or a record waiting for an OK names it), `counts` (an open recount there). [] = empty.
     *
     * @return list<string>
     */
    public static function notEmpty(Db $db, int $id): array
    {
        $out = [];
        if ($db->value('SELECT 1 FROM stock_balance WHERE warehouse_id = ? AND (on_hand <> 0 OR allocated <> 0 OR held <> 0) LIMIT 1', [$id]) !== null) {
            $out[] = 'stock';
        }
        if ($db->value('SELECT 1 FROM channel_warehouse WHERE warehouse_id = ? LIMIT 1', [$id]) !== null) {
            $out[] = 'website';
        }
        if ($db->value("SELECT 1 FROM document WHERE warehouse_id = ? AND status IN ('draft', 'awaiting_approval') LIMIT 1", [$id]) !== null
            || $db->value("SELECT 1 FROM document_line l JOIN document d ON d.id = l.document_id WHERE l.warehouse_id = ? AND d.status IN ('draft', 'awaiting_approval') LIMIT 1", [$id]) !== null) {
            $out[] = 'records';
        }
        if ($db->value("SELECT 1 FROM count_review WHERE warehouse_id = ? AND status = 'open' LIMIT 1", [$id]) !== null) {
            $out[] = 'counts';
        }
        return $out;
    }

    /**
     * Adds a warehouse. $code upper case (400 bad_code; 409 warehouse_exists), $name 2-100 characters, $owner own | other (other:
     * $ownerName 2-64 characters, never sellable: 422 other_not_sellable). Sellable only with $confirmed (422 confirm_needed). It
     * is active at once; no website sells from it until one is assigned on the server.
     *
     * @return array<string, mixed> the warehouse
     */
    public function add(Caller $caller, string $code, string $name, bool $sellable, bool $confirmed, string $owner, ?string $ownerName, ?string $note, string $reason): array
    {
        $reason = ConfigHistory::reason($reason);
        $code = strtoupper(trim($code));
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new CwException('bad_code', 'a warehouse code is 2 to 32 capital letters, digits or _, starting with a letter', 400, ['field' => 'code']);
        }
        $name = self::name($name, 'name');
        $note = self::note($note);
        [$owner, $ownerName] = self::owner($owner, $ownerName);
        if ($sellable && $owner === 'other') {
            throw new CwException('other_not_sellable', 'stock owned by another account is never sold from: it is released into our stock first', 422, ['field' => 'sellable']);
        }
        if ($sellable && !$confirmed) {
            throw new CwException('confirm_needed', 'tick the confirmation: websites may then sell from this warehouse', 422, ['field' => 'confirm']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $code, $name, $sellable, $owner, $ownerName, $note, $reason): array {
            ConfigHistory::authorise($db, $caller);
            if ($db->value('SELECT id FROM warehouse WHERE code = ? FOR UPDATE', [$code]) !== null) {
                throw new CwException('warehouse_exists', "there is a warehouse {$code} already", 409, ['field' => 'code']);
            }
            $sort = (int) ($db->value('SELECT MAX(sort_order) FROM warehouse') ?? 0) + 10;
            // is_system is never named: the app login may not (Grants::INSERT_COLUMNS), so it is 0, its default.
            $id = $db->insert('INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, owner_entity, note, sort_order) '
                . 'VALUES (?, ?, ?, 1, ?, ?, ?, ?)', [$code, $name, $sellable ? 1 : 0, $owner, $ownerName, $note, min(65535, $sort)]);
            $after = ConfigHistory::state($db, 'warehouse', $code) ?? throw new \LogicException('the warehouse just added is missing');
            $v = ConfigHistory::record($db, $caller, 'warehouse', $code, 'add', null, $after, $reason);
            Audit::write($db, $caller, 'warehouse.add', 'warehouse', $code, null, ['id' => $id, 'after' => $after, 'reason' => $reason, 'version' => $v['version']]);
            return $this->get($id) ?? throw new \LogicException('the warehouse just added is missing');
        });
    }

    /** Renames a warehouse and/or changes its note (its code stays). @return array{changed: bool} */
    public function rename(Caller $caller, int $id, string $name, ?string $note, string $reason, ?int $seen = null): array
    {
        $name = self::name($name, 'name');
        $note = self::note($note);
        return $this->changeWarehouse($caller, $id, $reason, $seen, 'rename', static fn (array $b): array => ['name' => $name, 'note' => $note] + $b,
            static function (Db $db, array $w) use ($name, $note): void {
                $db->exec('UPDATE warehouse SET name = ?, note = ? WHERE id = ?', [$name, $note, $w['id']]);
            });
    }

    /**
     * Whether websites may sell from the warehouse. Only with $confirmed (422 confirm_needed); never for MAIN, VERIFY, UNSTAMPED
     * (409 system_warehouse), a switched-off one (409 warehouse_off), another account's stock (422 other_not_sellable), or while
     * a website is assigned to it (409 warehouse_in_use: move the website first, on the server).
     *
     * @return array{changed: bool}
     */
    public function setSellable(Caller $caller, int $id, bool $sellable, bool $confirmed, string $reason, ?int $seen = null): array
    {
        return $this->changeWarehouse($caller, $id, $reason, $seen, 'sellable', static fn (array $b): array => ['is_sellable' => $sellable ? 1 : 0] + $b,
            static function (Db $db, array $w) use ($sellable, $confirmed): void {
                if ($w['is_system'] === 1) {
                    throw new CwException('system_warehouse', "{$w['code']} is one of the three warehouses the system works with: whether it is sold from never changes", 409);
                }
                if ($w['is_active'] !== 1) {
                    throw new CwException('warehouse_off', "{$w['code']} is switched off: switch it on first", 409);
                }
                if ($sellable && $w['stock_owner'] === 'other') {
                    throw new CwException('other_not_sellable', 'stock owned by another account is never sold from: it is released into our stock first', 422);
                }
                if (!$confirmed) {
                    throw new CwException('confirm_needed', 'tick the confirmation first: this changes what the websites may sell', 422, ['field' => 'confirm']);
                }
                $sites = $db->column('SELECT c.name FROM channel_warehouse cw JOIN channel c ON c.id = cw.channel_id WHERE cw.warehouse_id = ? ORDER BY c.name', [$w['id']]);
                if ($sites !== []) {
                    throw new CwException('warehouse_in_use', 'websites use this warehouse (' . implode(', ', $sites) . '): move them to another warehouse first', 409,
                        ['websites' => $sites]);
                }
                $db->exec('UPDATE warehouse SET is_sellable = ? WHERE id = ?', [$sellable ? 1 : 0, $w['id']]);
            });
    }

    /**
     * Whose stock the warehouse holds: own, or another account's ($ownerName). Only while the warehouse is EMPTY (notEmpty(): no
     * stock, no website, no record waiting, no open recount; 409 owner_not_empty with detail.why), in either direction: the stock
     * of another account becomes ours only through a release invoice (Y15), never by renaming the room it is in, and ours never
     * becomes someone else's without a document either (review finding I4). Only with $confirmed (the page's tick; 422
     * unconfirmed). Another account's stock is never sellable (409 sellable_warehouse: make it not sellable first); never for
     * MAIN, VERIFY, UNSTAMPED (409 system_warehouse). Renaming the other account (other to other) is a change of whose stock it
     * is too, so it follows the same rules.
     *
     * @return array{changed: bool}
     */
    public function setOwner(Caller $caller, int $id, string $owner, ?string $ownerName, bool $confirmed, string $reason, ?int $seen = null): array
    {
        [$owner, $ownerName] = self::owner($owner, $ownerName);
        return $this->changeWarehouse($caller, $id, $reason, $seen, 'owner',
            static fn (array $b): array => ['stock_owner' => $owner, 'owner_entity' => $ownerName] + $b,
            static function (Db $db, array $w) use ($owner, $ownerName, $confirmed): void {
                if ($w['is_system'] === 1) {
                    throw new CwException('system_warehouse', "{$w['code']} is one of the three warehouses the system works with: its stock is ours", 409);
                }
                if ($owner === 'other' && $w['is_sellable'] === 1) {
                    throw new CwException('sellable_warehouse', "websites may sell from {$w['code']}: make it not sellable first", 409);
                }
                $why = self::notEmpty($db, (int) $w['id']);
                if ($why !== []) {
                    throw new CwException('owner_not_empty', "{$w['code']} is not empty (" . implode(', ', $why) . '): whose stock it holds changes only while it is '
                        . 'empty; another account\'s stock becomes ours only through a release invoice', 409, ['why' => $why]);
                }
                if (!$confirmed) {
                    throw new CwException('unconfirmed', 'tick the confirmation first: this changes whose stock the warehouse holds', 422, ['field' => 'confirm']);
                }
                $db->exec('UPDATE warehouse SET stock_owner = ?, owner_entity = ? WHERE id = ?', [$owner, $ownerName, $w['id']]);
            });
    }

    /**
     * Switches a warehouse off (only when empty: 409 warehouse_not_empty with detail.why, notEmpty()) or on again. Never MAIN,
     * VERIFY, UNSTAMPED (409 system_warehouse). A switched-off warehouse is refused on new records (Documents: 422
     * warehouse_inactive).
     *
     * @return array{changed: bool}
     */
    public function setActive(Caller $caller, int $id, bool $active, string $reason, ?int $seen = null): array
    {
        return $this->changeWarehouse($caller, $id, $reason, $seen, $active ? 'switch_on' : 'switch_off',
            static fn (array $b): array => ['is_active' => $active ? 1 : 0] + $b,
            static function (Db $db, array $w) use ($active): void {
                if ($w['is_system'] === 1) {
                    throw new CwException('system_warehouse', "{$w['code']} is one of the three warehouses the system works with: it is never switched off", 409);
                }
                if (!$active) {
                    $why = self::notEmpty($db, (int) $w['id']);
                    if ($why !== []) {
                        throw new CwException('warehouse_not_empty', "{$w['code']} is not empty (" . implode(', ', $why) . '): it is switched off only when empty', 409,
                            ['why' => $why]);
                    }
                }
                $db->exec('UPDATE warehouse SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $w['id']]);
            });
    }

    /**
     * Adds a place inside a warehouse (optional, Q2): $code upper case (A-01, OVERFLOW; 400 bad_code; 409 place_exists), $name
     * 2-100 characters. Not in a switched-off warehouse (409 warehouse_off).
     *
     * @return array{id: int, key: string}
     */
    public function addPlace(Caller $caller, int $warehouseId, string $code, string $name, ?string $note, string $reason): array
    {
        $reason = ConfigHistory::reason($reason);
        $code = strtoupper(trim($code));
        if (preg_match(self::PLACE_PATTERN, $code) !== 1) {
            throw new CwException('bad_code', 'a place code is 1 to 31 capital letters, digits, _ or -, like A-01 or OVERFLOW', 400, ['field' => 'code']);
        }
        $name = self::name($name, 'name');
        $note = self::note($note);
        return $this->db->transaction(function (Db $db) use ($caller, $warehouseId, $code, $name, $note, $reason): array {
            ConfigHistory::authorise($db, $caller);
            $w = $db->one('SELECT id, code, is_active FROM warehouse WHERE id = ? FOR UPDATE', [$warehouseId])
                ?? throw new CwException('unknown_warehouse', 'there is no such warehouse', 404);
            if ((int) $w['is_active'] !== 1) {
                throw new CwException('warehouse_off', "{$w['code']} is switched off: switch it on first", 409);
            }
            if ($db->value('SELECT id FROM warehouse_location WHERE warehouse_id = ? AND code = ?', [$warehouseId, $code]) !== null) {
                throw new CwException('place_exists', "{$w['code']} has a place {$code} already", 409, ['field' => 'code']);
            }
            $id = $db->insert('INSERT INTO warehouse_location (warehouse_id, code, name, is_active, note) VALUES (?, ?, ?, 1, ?)', [$warehouseId, $code, $name, $note]);
            $key = ConfigHistory::locationKey((string) $w['code'], $code);
            $after = ConfigHistory::state($db, 'location', $key) ?? throw new \LogicException('the place just added is missing');
            $v = ConfigHistory::record($db, $caller, 'location', $key, 'add', null, $after, $reason);
            Audit::write($db, $caller, 'location.add', 'warehouse_location', (string) $id, null, ['key' => $key, 'after' => $after, 'reason' => $reason, 'version' => $v['version']]);
            return ['id' => $id, 'key' => $key];
        });
    }

    /** Renames a place and/or changes its note. @return array{changed: bool} */
    public function renamePlace(Caller $caller, int $placeId, string $name, ?string $note, string $reason, ?int $seen = null): array
    {
        $name = self::name($name, 'name');
        $note = self::note($note);
        return $this->changePlace($caller, $placeId, $reason, $seen, 'rename', static fn (array $b): array => ['name' => $name, 'note' => $note] + $b,
            static function (Db $db) use ($name, $note, $placeId): void {
                $db->exec('UPDATE warehouse_location SET name = ?, note = ? WHERE id = ?', [$name, $note, $placeId]);
            });
    }

    /** Switches a place off (not while a draft names it: 409 place_in_use) or on again. @return array{changed: bool} */
    public function setPlaceActive(Caller $caller, int $placeId, bool $active, string $reason, ?int $seen = null): array
    {
        return $this->changePlace($caller, $placeId, $reason, $seen, $active ? 'switch_on' : 'switch_off', static fn (array $b): array => ['is_active' => $active ? 1 : 0] + $b,
            static function (Db $db) use ($active, $placeId): void {
                if (!$active && $db->value("SELECT 1 FROM document_line l JOIN document d ON d.id = l.document_id WHERE l.location_id = ? "
                    . "AND d.status IN ('draft', 'awaiting_approval') LIMIT 1", [$placeId]) !== null) {
                    throw new CwException('place_in_use', 'a record that is not final yet names this place', 409);
                }
                $db->exec('UPDATE warehouse_location SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $placeId]);
            });
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $next
     * @param \Closure(Db, array<string, mixed>): void $write checks and writes (the warehouse row is locked)
     * @return array{changed: bool}
     */
    private function changeWarehouse(Caller $caller, int $id, string $reason, ?int $seen, string $action, \Closure $next, \Closure $write): array
    {
        $reason = ConfigHistory::reason($reason);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $reason, $seen, $action, $next, $write): array {
            ConfigHistory::authorise($db, $caller);
            $code = $db->value('SELECT code FROM warehouse WHERE id = ?', [$id]) ?? throw new CwException('unknown_warehouse', 'there is no such warehouse', 404);
            $before = ConfigHistory::state($db, 'warehouse', (string) $code, true) ?? throw new CwException('unknown_warehouse', 'there is no such warehouse', 404);
            ConfigHistory::checkSeen($db, 'warehouse', (string) $code, $seen);
            $after = ConfigHistory::normalise('warehouse', $next($before));
            if ($after === $before) {
                return ['changed' => false];
            }
            $write($db, ['id' => $id] + $before);
            $now = ConfigHistory::state($db, 'warehouse', (string) $code) ?? throw new \LogicException('the warehouse vanished');
            $v = ConfigHistory::record($db, $caller, 'warehouse', (string) $code, $action, $before, $now, $reason);
            Audit::write($db, $caller, 'warehouse.' . $action, 'warehouse', (string) $code, null,
                ['id' => $id, 'before' => $before, 'after' => $now, 'reason' => $reason, 'version' => $v['version']]);
            return ['changed' => true];
        });
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $next
     * @param \Closure(Db): void $write
     * @return array{changed: bool}
     */
    private function changePlace(Caller $caller, int $placeId, string $reason, ?int $seen, string $action, \Closure $next, \Closure $write): array
    {
        $reason = ConfigHistory::reason($reason);
        return $this->db->transaction(function (Db $db) use ($caller, $placeId, $reason, $seen, $action, $next, $write): array {
            ConfigHistory::authorise($db, $caller);
            $r = $db->one('SELECT l.code, w.code AS wh FROM warehouse_location l JOIN warehouse w ON w.id = l.warehouse_id WHERE l.id = ?', [$placeId])
                ?? throw new CwException('unknown_place', 'there is no such place', 404);
            $key = ConfigHistory::locationKey((string) $r['wh'], (string) $r['code']);
            $before = ConfigHistory::state($db, 'location', $key, true) ?? throw new CwException('unknown_place', 'there is no such place', 404);
            ConfigHistory::checkSeen($db, 'location', $key, $seen);
            $after = ConfigHistory::normalise('location', $next($before));
            if ($after === $before) {
                return ['changed' => false];
            }
            $write($db);
            $v = ConfigHistory::record($db, $caller, 'location', $key, $action, $before, $after, $reason);
            Audit::write($db, $caller, 'location.' . $action, 'warehouse_location', (string) $placeId, null,
                ['key' => $key, 'before' => $before, 'after' => $after, 'reason' => $reason, 'version' => $v['version']]);
            return ['changed' => true];
        });
    }

    private static function name(string $name, string $field): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || !mb_check_encoding($name, 'UTF-8')) {
            throw new CwException('bad_name', 'a name is 2 to 100 characters', 400, ['field' => $field]);
        }
        return $name;
    }

    private static function note(?string $note): ?string
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', (string) $note));
        if ($note === '') {
            return null;
        }
        if (mb_strlen($note) > 255 || !mb_check_encoding($note, 'UTF-8')) {
            throw new CwException('bad_note', 'a note is at most 255 characters', 400, ['field' => 'note']);
        }
        return $note;
    }

    /** @return array{0: string, 1: ?string} */
    private static function owner(string $owner, ?string $ownerName): array
    {
        if (!in_array($owner, self::OWNERS, true)) {
            throw new CwException('bad_owner', 'the stock is ours (own) or another account\'s (other)', 400, ['field' => 'owner']);
        }
        if ($owner === 'own') {
            return ['own', null];
        }
        $ownerName = trim((string) preg_replace('/\s+/u', ' ', (string) $ownerName));
        if (mb_strlen($ownerName) < 2 || mb_strlen($ownerName) > 64 || !mb_check_encoding($ownerName, 'UTF-8')) {
            throw new CwException('bad_owner', 'name the account that owns the stock (2 to 64 characters)', 400, ['field' => 'owner_name']);
        }
        return ['other', $ownerName];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'is_sellable' => (int) $r['is_sellable'] === 1,
            'is_active' => (int) $r['is_active'] === 1, 'stock_owner' => (string) $r['stock_owner'],
            'owner_entity' => $r['owner_entity'] === null ? null : (string) $r['owner_entity'], 'is_system' => (int) $r['is_system'] === 1,
            'note' => $r['note'] === null ? null : (string) $r['note'], 'sort_order' => (int) $r['sort_order']];
    }
}
