<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Admin\ConfigHistory;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * The settable list of expense types and regular cost groups (the brief's point 1; docs/decisions.md EX10): Settings > Expense types, changed
 * like CW's other settable lists by an admin or a reviewer (settings.manage, re-read inside the write: ConfigHistory::authorise), each change
 * with its reason and kept in expense_history (who, when, before and after). A type is renamed or switched off, never deleted: the rows that
 * name it keep it. Start list (0079): Stationery and packaging, Software and AI, Developer, Fees, Other; groups: Rent, Running the business,
 * Marketing, website and people, Other.
 */
final class ExpenseTypes
{
    public const KINDS = ['expense', 'regular'];
    public const NAME_MAX = 64;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id: int, kind: string, name: string, is_active: bool, sort_order: int, version: int, used: int}> every type, by kind and order */
    public function all(?string $kind = null): array
    {
        $rows = $this->db->all('SELECT t.id, t.kind, t.name, t.is_active, t.sort_order, t.version, '
            . '(SELECT COUNT(*) FROM expense e WHERE e.type_id = t.id AND e.deleted_at IS NULL) + (SELECT COUNT(*) FROM regular_cost r WHERE r.type_id = t.id '
            . 'AND r.deleted_at IS NULL) AS used FROM expense_type t' . ($kind === null ? '' : ' WHERE t.kind = ?') . ' ORDER BY t.kind, t.sort_order, t.name, t.id',
            $kind === null ? [] : [$kind]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'kind' => (string) $r['kind'], 'name' => (string) $r['name'],
            'is_active' => (int) $r['is_active'] === 1, 'sort_order' => (int) $r['sort_order'], 'version' => (int) $r['version'], 'used' => (int) $r['used']], $rows);
    }

    /** @return list<array{id: int, name: string}> the types of a kind offered for a new row (switched on), in their order */
    public function active(string $kind): array
    {
        return array_values(array_map(static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name']],
            array_filter($this->all($kind), static fn (array $t): bool => $t['is_active'])));
    }

    /** Adds a type (409 type_exists when its kind has the name, whatever its case); last in its list. */
    public function add(Caller $caller, string $kind, string $name, string $reason): int
    {
        return $this->db->transaction(function (Db $db) use ($caller, $kind, $name, $reason): int {
            ConfigHistory::authorise($db, $caller);
            if (!in_array($kind, self::KINDS, true)) {
                throw new CwException('bad_kind', 'a type is for expenses or for regular costs', 422, ['field' => 'kind']);
            }
            $name = self::name($name);
            $why = ExpenseHistory::reason($reason);
            if ($db->value('SELECT id FROM expense_type WHERE kind = ? AND name = ?', [$kind, $name]) !== null) {
                throw new CwException('type_exists', 'that type is on the list already', 409, ['field' => 'name']);
            }
            $order = (int) $db->value('SELECT COALESCE(MAX(sort_order), 0) FROM expense_type WHERE kind = ? AND sort_order < 90', [$kind]) + 10;
            try {
                $id = $db->insert('INSERT INTO expense_type (kind, name, sort_order, created_actor) VALUES (?, ?, ?, ?)', [$kind, $name, min($order, 89), $caller->actor]);
            } catch (\PDOException $e) {
                if (Db::driverCode($e) === 1062) {
                    throw new CwException('type_exists', 'that type is on the list already', 409, ['field' => 'name']);
                }
                throw $e;
            }
            ExpenseHistory::record($db, $caller, 'type', $id, 1, 'add', null, self::state($db, $id), $why);
            return $id;
        });
    }

    /** Renames a type; $seen: the version the page showed. False when the name is the same. */
    public function rename(Caller $caller, int $id, int $seen, string $name, string $reason): bool
    {
        return $this->write($caller, $id, $seen, $reason, static function (Db $db, array $row) use ($name, $id): ?array {
            $name = self::name($name);
            if ($name === (string) $row['name']) {
                return null;
            }
            if ($db->value('SELECT id FROM expense_type WHERE kind = ? AND name = ? AND id <> ?', [$row['kind'], $name, $id]) !== null) {
                throw new CwException('type_exists', 'that type is on the list already', 409, ['field' => 'name']);
            }
            return ['change', ['name' => $name]];
        });
    }

    /** Switches a type off (not offered for a new row; the rows that name it keep it) or on again. False when it already is. */
    public function setActive(Caller $caller, int $id, int $seen, bool $on, string $reason): bool
    {
        return $this->write($caller, $id, $seen, $reason, static fn (Db $db, array $row): ?array => ((int) $row['is_active'] === 1) === $on ? null
            : [$on ? 'switch_on' : 'switch_off', ['is_active' => $on ? 1 : 0]]);
    }

    /** @param \Closure(Db, array<string, mixed>): ?array{0: string, 1: array<string, mixed>} $what */
    private function write(Caller $caller, int $id, int $seen, string $reason, \Closure $what): bool
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $seen, $reason, $what): bool {
            ConfigHistory::authorise($db, $caller);
            $row = $db->one('SELECT id, kind, name, is_active, sort_order, version FROM expense_type WHERE id = ? FOR UPDATE', [$id])
                ?? throw new CwException('not_found', 'there is no such type', 404);
            if ((int) $row['version'] !== $seen) {
                throw Expenses::meanwhile($db, 'type', $id, (int) $row['version'], $seen);
            }
            $why = ExpenseHistory::reason($reason);
            $change = $what($db, $row);
            if ($change === null) {
                return false;
            }
            [$action, $set] = $change;
            $before = self::state($db, $id);
            $version = (int) $row['version'] + 1;
            $cols = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($set)));
            $db->exec("UPDATE expense_type SET {$cols}, version = ? WHERE id = ?", [...array_values($set), $version, $id]);
            ExpenseHistory::record($db, $caller, 'type', $id, $version, $action, $before, self::state($db, $id), $why);
            return true;
        });
    }

    /** @return array{kind: string, name: string, is_active: int, sort_order: int} */
    private static function state(Db $db, int $id): array
    {
        $r = $db->one('SELECT kind, name, is_active, sort_order FROM expense_type WHERE id = ?', [$id]) ?? throw new \LogicException("type {$id} vanished");
        return ['kind' => (string) $r['kind'], 'name' => (string) $r['name'], 'is_active' => (int) $r['is_active'], 'sort_order' => (int) $r['sort_order']];
    }

    private static function name(string $name): string
    {
        $name = Expenses::plain($name);
        if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name) < 2 || mb_strlen($name) > self::NAME_MAX || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new CwException('bad_name', 'a name of 2 to ' . self::NAME_MAX . ' characters', 422, ['field' => 'name']);
        }
        return $name;
    }
}
