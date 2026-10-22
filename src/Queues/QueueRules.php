<?php

declare(strict_types=1);

namespace CW\Queues;

use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Staff\StaffRoles;

/**
 * What the four queues of the stores share (pack F; docs/decisions.md PF5): who may act (re-read inside the action's transaction:
 * the person's active roles, the action's permission; never admin, whose roles grant none of them, I12), the note every answer
 * carries, the status filter of a list, and the settling of a row (D17: open -> resolved or dismissed, with who, when and why; a
 * row is never removed, Grants::NO_DELETE). A row that is no longer open answers 409 queue_settled: each action settles a row once.
 */
final class QueueRules
{
    public const STATUSES = ['open', 'resolved', 'dismissed'];
    /** The four queue tables (0001; D17), and the website bugs (0050, docs/decisions.md LP8). */
    public const TABLES = ['goods_in_suspense', 'count_review', 'policy_review', 'oversell_event', 'landing_order'];
    public const NOTE_MIN = 3;
    public const NOTE_MAX = 500;
    /** Rows a list page shows (oldest open first). */
    public const PAGE = 50;

    /** @return list<string> the person's active roles, after checking they may $perm */
    public static function authorise(Db $db, Caller $caller, string $perm): array
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'the stores\' queues are worked by staff', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (!Permissions::can($roles, $perm)) {
            throw new CwException('role_not_allowed', (count($roles) === 1 ? 'your role (' : 'your roles (') . implode(', ', $roles) . ") cannot do this ({$perm})", 403);
        }
        return $roles;
    }

    /** A note of 3 to 500 characters, control characters made spaces (400 note_required). */
    public static function note(string $note): string
    {
        $note = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $note));
        if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) < self::NOTE_MIN || mb_strlen($note) > self::NOTE_MAX) {
            throw new CwException('note_required', 'say in ' . self::NOTE_MIN . ' to ' . self::NOTE_MAX . ' characters what was done (or why nothing is needed)', 400,
                ['field' => 'note']);
        }
        return $note;
    }

    /** The status a list shows: one of STATUSES, or null for every row ('all'); anything else is 'open'. */
    public static function status(?string $asked): ?string
    {
        return in_array($asked, self::STATUSES, true) ? $asked : ($asked === 'all' ? null : 'open');
    }

    /**
     * Locks a queue row (FOR UPDATE) and checks it is still open: 404 unknown_queue_row, 409 queue_settled (with who settled it).
     *
     * @param string $where extra condition on the row (its source), with its params
     * @param list<mixed> $params
     * @return array<string, mixed>
     */
    public static function lockOpen(Db $db, string $table, int $id, string $where = '', array $params = []): array
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException("{$table} is not a queue");
        }
        $row = $db->one("SELECT *, CAST(status AS CHAR) AS status FROM {$table} WHERE id = ?" . ($where === '' ? '' : " AND {$where}") . ' FOR UPDATE', [$id, ...$params])
            ?? throw new CwException('unknown_queue_row', 'there is no such row in this queue', 404);
        if ($row['status'] !== 'open') {
            throw new CwException('queue_settled', "this row is already {$row['status']}", 409, ['status' => $row['status'],
                'resolved_by' => $row['resolved_by'] === null ? null : (int) $row['resolved_by']]);
        }
        return $row;
    }

    /** Settles a locked open row: status, resolution (when the table has one), by whom, when, the note. */
    public static function settle(Db $db, Caller $caller, string $table, int $id, string $status, ?string $resolution, string $note): void
    {
        if (!in_array($table, self::TABLES, true) || !in_array($status, ['resolved', 'dismissed'], true)) {
            throw new \InvalidArgumentException("{$table} / {$status}: not a queue answer");
        }
        $now = Clock::db(Clock::now());
        $res = $table === 'goods_in_suspense' ? '' : ', resolution = ?';
        $params = [$status, ...($table === 'goods_in_suspense' ? [] : [$resolution]), $caller->staffUserId, $now, $note, $id];
        $n = $db->exec("UPDATE {$table} SET status = ?{$res}, resolved_by = ?, resolved_at = ?, note = ? WHERE id = ? AND status = 'open'", $params);
        if ($n !== 1) {
            throw new \LogicException("{$table} {$id} was not open under its lock");
        }
    }

    /**
     * A stored answer that is a refusal (the same key sent with another request: 422 idempotency_key_reused) becomes the exception the
     * screens show; a 200 passes.
     */
    public static function refused(\CW\OpResult $r): void
    {
        if ($r->status >= 400) {
            throw new CwException((string) ($r->body['error'] ?? 'refused'), (string) ($r->body['message'] ?? 'the answer was refused'), $r->status);
        }
    }

    /** The staff names of some ids (for the lists). @param list<int|null> $ids @return array<int, string> */
    public static function names(Db $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (mixed $i): int => (int) $i, $ids))));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($db->all('SELECT id, display_name FROM staff_user WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $out[(int) $r['id']] = (string) $r['display_name'];
        }
        return $out;
    }

    /** The store codes and names by id. @return array<int, array{code: string, name: string}> */
    public static function stores(Db $db): array
    {
        $out = [];
        foreach ($db->all('SELECT id, code, name FROM channel ORDER BY id') as $r) {
            $out[(int) $r['id']] = ['code' => (string) $r['code'], 'name' => (string) $r['name']];
        }
        return $out;
    }
}
