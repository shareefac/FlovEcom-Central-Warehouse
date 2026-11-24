<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;

/**
 * The trail of the expenses, regular costs, their suppliers and types (expense_history, append-only; docs/decisions.md EX8): one row per
 * version of a row: what it was before and after, the reason when one is asked for, who and when; and the same change in audit_log.
 * Written in the transaction that changed the row, after it was locked; the row's own `version` is the trail's latest.
 */
final class ExpenseHistory
{
    public const SUBJECTS = ['expense', 'regular_cost', 'supplier', 'type'];
    /** The audit_log entity (and action prefix: expense.add, expense_supplier.change, ...) of each subject. */
    private const ENTITY = ['expense' => 'expense', 'regular_cost' => 'regular_cost', 'supplier' => 'expense_supplier', 'type' => 'expense_type'];
    /** A reason (a delete, a type's change) of 3 to 500 characters. */
    public const REASON_MIN = 3;
    public const REASON_MAX = 500;

    /**
     * Records version $version of a subject (1 = its add). The caller holds the row's lock and has written the row.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $after
     */
    public static function record(Db $db, Caller $caller, string $subject, int $id, int $version, string $action, ?array $before, array $after, ?string $reason): void
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('an expense change is recorded in the transaction that made it');
        }
        if (!in_array($subject, self::SUBJECTS, true)) {
            throw new \InvalidArgumentException("unknown subject {$subject}");
        }
        $db->insert('INSERT INTO expense_history (subject, subject_id, version, action, state, before_state, reason, actor, staff_user_id) '
            . 'VALUES (?, ?, ?, ?, CAST(? AS JSON), CAST(? AS JSON), ?, ?, ?)',
            [$subject, $id, $version, $action, Idempotency::json($after), $before === null ? null : Idempotency::json($before), $reason, $caller->actor,
                $caller->staffUserId]);
        // The audit log says who did what to which row and which fields changed, never their values (amounts, names, notes, a reason): it is
        // read by audit.view, which does not see the expenses; the values are in expense_history, shown on the row's own page to expenses.view
        // only (the Codex check r1, H9; docs/decisions.md EX23).
        $entity = self::ENTITY[$subject];
        Audit::write($db, $caller, $entity . '.' . $action, $entity, (string) $id, null,
            ['version' => $version, 'fields' => array_map(static fn (array $d): string => $d[0], self::diff($before, $after))]);
    }

    /** A reason of 3 to 500 characters, trimmed (400 bad_reason). */
    public static function reason(?string $reason): string
    {
        $reason = trim((string) $reason);
        if (!mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason) === 1) {
            throw new CwException('bad_reason', 'say in ' . self::REASON_MIN . ' to ' . self::REASON_MAX . ' characters why (it is kept with the change)', 400,
                ['field' => 'reason']);
        }
        return $reason;
    }

    /**
     * The versions of a subject, newest first: version, action, before and after (decoded), the reason, who (their name) and when.
     *
     * @return list<array{version: int, action: string, before: ?array<string, mixed>, after: array<string, mixed>, reason: ?string, actor: string, who: ?string, at: string}>
     */
    public static function of(Db $db, string $subject, int $id): array
    {
        $out = [];
        foreach ($db->all('SELECT h.version, h.action, CAST(h.state AS CHAR) AS state, CAST(h.before_state AS CHAR) AS before_state, h.reason, h.actor, '
            . 'u.display_name, h.created_at FROM expense_history h LEFT JOIN staff_user u ON u.id = h.staff_user_id WHERE h.subject = ? AND h.subject_id = ? '
            . 'ORDER BY h.version DESC LIMIT 200', [$subject, $id]) as $r) {
            $after = json_decode((string) $r['state'], true);
            $before = $r['before_state'] === null ? null : json_decode((string) $r['before_state'], true);
            $out[] = ['version' => (int) $r['version'], 'action' => (string) $r['action'], 'before' => is_array($before) ? $before : null,
                'after' => is_array($after) ? $after : [], 'reason' => $r['reason'] === null ? null : (string) $r['reason'], 'actor' => (string) $r['actor'],
                'who' => $r['display_name'] === null ? null : (string) $r['display_name'], 'at' => (string) $r['created_at']];
        }
        return $out;
    }

    /**
     * The fields a version changed, as [field, before, after] (an add: every field, before null).
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $after
     * @return list<array{0: string, 1: mixed, 2: mixed}>
     */
    public static function diff(?array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) {
            if ($before === null || !array_key_exists($k, $before) || $before[$k] !== $v) {
                $out[] = [(string) $k, $before[$k] ?? null, $v];
            }
        }
        return $out;
    }
}
