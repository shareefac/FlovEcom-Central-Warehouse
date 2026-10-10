<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * The reasons for stock changes and for cancelling or correcting orders (reason_code, 0008; docs/decisions.md I22, Y12, Y51): an
 * admin or a reviewer (settings.manage) adds a reason, renames one, says where it is offered (setUses: the stock records, the
 * cancellations, and the order screens' three lists, review finding I6), or switches one off or on again, with a reason for the
 * change. A reason is never deleted (documents name it: Q9); its code, which way it moves stock and whether it needs a note or is a
 * free gift are fixed once it is added. The reasons set by CW itself (system_only: opening_rebase, review_rejected) are locked. A
 * switched-off reason is not offered and is refused when a NEW record is made final (Documents: 422 reason_inactive); records that
 * used it keep it, and a record already waiting for a reviewer's OK still gets its OK (M6, Y52). Every change is a config_change
 * version and an audit row.
 */
final class ReasonCodes
{
    /** Where a reason can be offered, in the SET's order (0019; stock_in and stock_out since 0022, pack A1). */
    public const USES = ['adjustment', 'write_off', 'count', 'return', 'supplier_return', 'reversal', 'po_cancel', 'po_draft_cancel', 'po_amend', 'stock_in',
        'stock_out'];
    public const DIRECTIONS = ['increase', 'decrease', 'either'];
    public const LABEL_MAX = 100;
    /** New reasons go before `other` (999), which stays last. */
    private const SORT_LAST = 998;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Every reason in the order people see them: code, label, uses (list), direction, needs_note, is_gift, system_only, is_active,
     * sort_order, version (of its history).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $versions = [];
        foreach ($this->db->all("SELECT subject_key, MAX(version) AS v FROM config_change WHERE subject_type = 'reason' GROUP BY subject_key") as $r) {
            $versions[(string) $r['subject_key']] = (int) $r['v'];
        }
        $out = [];
        foreach ($this->db->all('SELECT code, label, CAST(applies_to AS CHAR) AS applies_to, CAST(direction AS CHAR) AS direction, needs_note, is_gift, '
            . 'needs_given_to, below_zero, system_only, is_active, sort_order FROM reason_code ORDER BY sort_order, code') as $r) {
            $out[] = self::row($r) + ['version' => $versions[(string) $r['code']] ?? 0];
        }
        return $out;
    }

    /** @return array<string, mixed>|null one reason (all()'s fields) */
    public function get(string $code): ?array
    {
        $r = $this->db->one('SELECT code, label, CAST(applies_to AS CHAR) AS applies_to, CAST(direction AS CHAR) AS direction, needs_note, is_gift, '
            . 'needs_given_to, below_zero, system_only, is_active, sort_order FROM reason_code WHERE code = ?', [$code]);
        return $r === null ? null : self::row($r) + ['version' => ConfigHistory::version($this->db, 'reason', $code)];
    }

    /**
     * Adds a reason: $code lower case (a-z, 0-9, _; 2-32 characters, starting with a letter: 400 bad_code; 409 reason_exists),
     * $label 2-100 characters (400 bad_label), $uses a non-empty subset of USES (400 bad_uses), $direction one of DIRECTIONS
     * (400 bad_direction). It is active at once and sorted before `other`. $needsGivenTo / $belowZero: the stock-out rules
     * (setRules()).
     *
     * @param list<string> $uses
     * @return array<string, mixed> the reason
     */
    public function add(Caller $caller, string $code, string $label, array $uses, string $direction, bool $needsNote, bool $isGift, string $reason,
        bool $needsGivenTo = false, bool $belowZero = false): array
    {
        $reason = ConfigHistory::reason($reason);
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $code) !== 1) {
            throw new CwException('bad_code', 'a reason code is 2 to 32 lower-case letters, digits or _, starting with a letter', 400, ['field' => 'code']);
        }
        $label = self::label($label);
        $uses = self::uses($uses);
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new CwException('bad_direction', 'the direction is increase, decrease or either', 400, ['field' => 'direction']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $code, $label, $uses, $direction, $needsNote, $isGift, $reason, $needsGivenTo, $belowZero): array {
            ConfigHistory::authorise($db, $caller);
            if ($db->value('SELECT 1 FROM reason_code WHERE code = ? FOR UPDATE', [$code]) !== null) {
                throw new CwException('reason_exists', "there is a reason {$code} already: rename it or switch it on again instead", 409, ['field' => 'code']);
            }
            $sort = min(self::SORT_LAST, (int) ($db->value('SELECT MAX(sort_order) FROM reason_code WHERE sort_order < 999') ?? 0) + 10);
            $db->exec('INSERT INTO reason_code (code, label, applies_to, direction, needs_note, is_gift, needs_given_to, below_zero, system_only, is_active, sort_order) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?)', [$code, $label, implode(',', $uses), $direction, $needsNote ? 1 : 0, $isGift ? 1 : 0,
                    $needsGivenTo ? 1 : 0, $belowZero ? 1 : 0, $sort]);
            $after = ConfigHistory::state($db, 'reason', $code) ?? throw new \LogicException('the reason just added is missing');
            $v = ConfigHistory::record($db, $caller, 'reason', $code, 'add', null, $after, $reason);
            Audit::write($db, $caller, 'reason.add', 'reason_code', $code, null, ['code' => $code, 'after' => $after, 'reason' => $reason, 'version' => $v['version']]);
            return $this->get($code) ?? throw new \LogicException('the reason just added is missing');
        });
    }

    /**
     * Renames a reason (the words people read; its code stays). 409 reason_locked for a reason CW sets itself, 404
     * unknown_reason, 409 changed_meanwhile ($seen). The same name again changes nothing (`changed` false).
     *
     * @return array{changed: bool, reason: array<string, mixed>}
     */
    public function rename(Caller $caller, string $code, string $label, string $reason, ?int $seen = null): array
    {
        $reason = ConfigHistory::reason($reason);
        $label = self::label($label);
        return $this->change($caller, $code, $reason, $seen, 'rename', static fn (array $before): array => ['label' => $label] + $before,
            static fn (Db $db) => $db->exec('UPDATE reason_code SET label = ? WHERE code = ?', [$label, $code]));
    }

    /**
     * Where a reason is offered (Y51): a non-empty subset of USES (400 bad_uses). Records that used it keep it; a record already
     * waiting for an OK still gets it (M6). 409 reason_locked for a reason CW sets itself, 404 unknown_reason, 409 changed_meanwhile.
     *
     * @param list<string> $uses
     * @return array{changed: bool, reason: array<string, mixed>}
     */
    public function setUses(Caller $caller, string $code, array $uses, string $reason, ?int $seen = null): array
    {
        $reason = ConfigHistory::reason($reason);
        $uses = self::uses($uses);
        $set = implode(',', $uses);
        return $this->change($caller, $code, $reason, $seen, 'uses', static fn (array $before): array => ['applies_to' => $set] + $before,
            static fn (Db $db) => $db->exec('UPDATE reason_code SET applies_to = ? WHERE code = ?', [$set, $code]));
    }

    /**
     * The stock-out rules of a reason (pack A1; owner answer Q5): $needsGivenTo, a stock out with it names the person it was given to
     * (samples, staff use); $belowZero, a stock out or a write-down with it may take a protected product (one whose stock the websites
     * must not oversell) below zero. Records already final keep what they were made with. 409 reason_locked for a reason CW sets
     * itself, 404 unknown_reason, 409 changed_meanwhile.
     *
     * @return array{changed: bool, reason: array<string, mixed>}
     */
    public function setRules(Caller $caller, string $code, bool $needsGivenTo, bool $belowZero, string $reason, ?int $seen = null): array
    {
        $reason = ConfigHistory::reason($reason);
        return $this->change($caller, $code, $reason, $seen, 'rules',
            static fn (array $before): array => ['needs_given_to' => $needsGivenTo ? 1 : 0, 'below_zero' => $belowZero ? 1 : 0] + $before,
            static fn (Db $db) => $db->exec('UPDATE reason_code SET needs_given_to = ?, below_zero = ? WHERE code = ?', [$needsGivenTo ? 1 : 0, $belowZero ? 1 : 0, $code]));
    }

    /**
     * Switches a reason off (no longer offered; posting a record with it is refused) or on again. 409 reason_locked for a reason CW
     * sets itself, 404 unknown_reason, 409 changed_meanwhile.
     *
     * @return array{changed: bool, reason: array<string, mixed>}
     */
    public function setActive(Caller $caller, string $code, bool $active, string $reason, ?int $seen = null): array
    {
        $reason = ConfigHistory::reason($reason);
        return $this->change($caller, $code, $reason, $seen, $active ? 'switch_on' : 'switch_off',
            static fn (array $before): array => ['is_active' => $active ? 1 : 0] + $before,
            static fn (Db $db) => $db->exec('UPDATE reason_code SET is_active = ? WHERE code = ?', [$active ? 1 : 0, $code]));
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $next the tracked row after the change
     * @param \Closure(Db): mixed $write
     * @return array{changed: bool, reason: array<string, mixed>}
     */
    private function change(Caller $caller, string $code, string $reason, ?int $seen, string $action, \Closure $next, \Closure $write): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $code, $reason, $seen, $action, $next, $write): array {
            ConfigHistory::authorise($db, $caller);
            $before = ConfigHistory::state($db, 'reason', $code, true) ?? throw new CwException('unknown_reason', "there is no reason {$code}", 404);
            if ($before['system_only'] === 1) {
                throw new CwException('reason_locked', "{$code} is set by CW itself: it is never changed", 409);
            }
            ConfigHistory::checkSeen($db, 'reason', $code, $seen);
            $after = ConfigHistory::normalise('reason', $next($before));
            if ($after === $before) {
                return ['changed' => false, 'reason' => $this->get($code) ?? []];
            }
            $write($db);
            $v = ConfigHistory::record($db, $caller, 'reason', $code, $action, $before, $after, $reason);
            Audit::write($db, $caller, 'reason.' . $action, 'reason_code', $code, null,
                ['code' => $code, 'before' => $before, 'after' => $after, 'reason' => $reason, 'version' => $v['version']]);
            return ['changed' => true, 'reason' => $this->get($code) ?? []];
        });
    }

    /**
     * A non-empty subset of USES, in USES's order (the SET's order: the stored text and the history compare equal). 400 bad_uses.
     *
     * @param array<mixed> $uses
     * @return list<string>
     */
    private static function uses(array $uses): array
    {
        $uses = array_values(array_unique(array_map('strval', $uses)));
        if ($uses === [] || array_diff($uses, self::USES) !== []) {
            throw new CwException('bad_uses', 'choose where the reason is used: ' . implode(', ', self::USES), 400, ['field' => 'uses']);
        }
        usort($uses, static fn (string $a, string $b): int => array_search($a, self::USES, true) <=> array_search($b, self::USES, true));
        return $uses;
    }

    private static function label(string $label): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        if (mb_strlen($label) < 2 || mb_strlen($label) > self::LABEL_MAX || !mb_check_encoding($label, 'UTF-8')) {
            throw new CwException('bad_label', 'a reason\'s name is 2 to ' . self::LABEL_MAX . ' characters', 400, ['field' => 'label']);
        }
        return $label;
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function row(array $r): array
    {
        return ['code' => (string) $r['code'], 'label' => (string) $r['label'],
            'uses' => array_values(array_filter(explode(',', (string) $r['applies_to']), static fn (string $u): bool => $u !== '')),
            'direction' => (string) $r['direction'], 'needs_note' => (int) $r['needs_note'] === 1, 'is_gift' => (int) $r['is_gift'] === 1,
            'needs_given_to' => (int) ($r['needs_given_to'] ?? 0) === 1, 'below_zero' => (int) ($r['below_zero'] ?? 0) === 1,
            'system_only' => (int) $r['system_only'] === 1, 'is_active' => (int) $r['is_active'] === 1, 'sort_order' => (int) $r['sort_order']];
    }
}
