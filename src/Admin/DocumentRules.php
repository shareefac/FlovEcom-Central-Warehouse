<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * The review and approval rules of each kind of record (document_type, 0008/0010; docs/decisions.md I19, I57, Y10): who checks
 * what after it is final (every one, only those over a limit, none) and within how many days; whether a reviewer's OK is needed
 * first (purchase orders over a net value; stock put back without a supplier document) and its limit; what a reviewer's Not OK
 * does (cancel the record, or only record it). The Approval rules page and bin/document_rules.php change them through set(): an
 * admin or a reviewer (settings.manage) with a reason, or the CLI; every change is a config_change version and an audit row
 * `document_type.change`. Postings read the rules per request, so the next one uses the new rule (open checks keep their due date).
 *
 * The kind of a type's blocking approval is fixed (APPROVAL_KIND): switching it off sets approval_rule 'none' and keeps the
 * limit; switching it on puts the type's kind back. The "stock put back" approval also covers a cancellation that puts more
 * than its limit back on hand (I32: Documents::positiveLimit reads the types with that kind).
 */
final class DocumentRules
{
    public const REVIEW_RULES = ['all', 'over_limit', 'none'];
    public const REJECT_ACTIONS = ['reverse', 'record'];
    /** type => the kind of its blocking approval (the only types that can have one). */
    public const APPROVAL_KIND = ['PO' => 'over_value', 'ADJ' => 'positive_without_supplier_doc'];
    public const LIMIT_MAX = 2_000_000_000;
    public const DAYS_MIN = 1;
    public const DAYS_MAX = 120;
    /** The fields of a rule, in the order the CLI prints a change. */
    public const FIELDS = ['review_rule', 'review_limit_units', 'review_due_days', 'approval_rule', 'approval_limit_units', 'reject_action'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Every type's rules, in the order of ReferenceController::TYPE_ORDER where known: code, name, the six fields, the history
     * version.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT code, name, CAST(review_rule AS CHAR) AS review_rule, review_limit_units, review_due_days, '
            . 'CAST(approval_rule AS CHAR) AS approval_rule, approval_limit_units, CAST(reject_action AS CHAR) AS reject_action FROM document_type ORDER BY code') as $r) {
            $out[] = ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'review_rule' => (string) $r['review_rule'],
                'review_limit_units' => $r['review_limit_units'] === null ? null : (int) $r['review_limit_units'], 'review_due_days' => (int) $r['review_due_days'],
                'approval_rule' => (string) $r['approval_rule'], 'approval_limit_units' => $r['approval_limit_units'] === null ? null : (int) $r['approval_limit_units'],
                'reject_action' => (string) $r['reject_action'], 'approval_kind' => self::APPROVAL_KIND[(string) $r['code']] ?? null,
                'version' => ConfigHistory::version($this->db, 'document_rule', (string) $r['code'])];
        }
        return $out;
    }

    /**
     * Changes one type's rules. $change may hold: review_rule (all | over_limit | none), review_limit_units (0..2,000,000,000; only
     * with over_limit, which needs one), review_due_days (1..120), approval (bool: the type's blocking approval on or off; only the
     * types of APPROVAL_KIND, and on needs a limit), approval_limit_units (0..2,000,000,000; only those types), reject_action
     * (reverse | record). Refusals are 400 with a code (bad_rule, bad_limit, bad_days, no_approval_rule, limit_required,
     * bad_reject_action, nothing_to_change), 404 unknown_type, 409 changed_meanwhile ($seen: the history version the form had).
     * The same values again write nothing (`changed` false).
     *
     * @param array<string, mixed> $change
     * @return array{changed: bool, before: array<string, mixed>, after: array<string, mixed>, version: int}
     */
    public function set(Caller $caller, string $type, array $change, string $reason, ?int $seen = null): array
    {
        $reason = ConfigHistory::reason($reason);
        $unknown = array_diff(array_keys($change), ['review_rule', 'review_limit_units', 'review_due_days', 'approval', 'approval_limit_units', 'reject_action']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('unknown rule field ' . implode(', ', $unknown));
        }
        if ($change === []) {
            throw new CwException('nothing_to_change', 'say what to change: a review rule, a limit, the days, the approval or what Not OK does', 400);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $type, $change, $reason, $seen): array {
            ConfigHistory::authorise($db, $caller);
            $before = ConfigHistory::state($db, 'document_rule', $type, true)
                ?? throw new CwException('unknown_type', "there is no kind of record {$type}", 404);
            ConfigHistory::checkSeen($db, 'document_rule', $type, $seen);
            $after = self::apply($type, $before, $change);
            $version = ConfigHistory::version($db, 'document_rule', $type);
            if ($after === $before) {
                return ['changed' => false, 'before' => $before, 'after' => $after, 'version' => $version];
            }
            $db->exec('UPDATE document_type SET review_rule = ?, review_limit_units = ?, review_due_days = ?, approval_rule = ?, approval_limit_units = ?, '
                . 'reject_action = ? WHERE code = ?', [$after['review_rule'], $after['review_limit_units'], $after['review_due_days'], $after['approval_rule'],
                    $after['approval_limit_units'], $after['reject_action'], $type]);
            $v = ConfigHistory::record($db, $caller, 'document_rule', $type, 'change', $before, $after, $reason);
            Audit::write($db, $caller, 'document_type.change', 'document_type', $type, null,
                ['type' => $type, 'before' => $before, 'after' => $after, 'reason' => $reason, 'version' => $v['version']]);
            return ['changed' => true, 'before' => $before, 'after' => $after, 'version' => $v['version']];
        });
    }

    /**
     * The rules after $change (pure: the table's CHECKs and the rules above as refusals).
     *
     * @param array<string, int|string|null> $before
     * @param array<string, mixed> $change
     * @return array<string, int|string|null>
     */
    public static function apply(string $type, array $before, array $change): array
    {
        $after = $before;
        $limit = static function (mixed $v, string $field): int {
            if (is_string($v) && preg_match('/^\d{1,10}$/D', trim($v)) === 1) {
                $v = (int) trim($v);
            }
            if (!is_int($v) || $v < 0 || $v > self::LIMIT_MAX) {
                throw new CwException('bad_limit', "{$field} must be a whole number from 0 to " . number_format(self::LIMIT_MAX), 400, ['field' => $field]);
            }
            return $v;
        };
        if (array_key_exists('review_rule', $change)) {
            if (!in_array($change['review_rule'], self::REVIEW_RULES, true)) {
                throw new CwException('bad_rule', 'the review rule is all, over_limit or none', 400, ['field' => 'review_rule']);
            }
            $after['review_rule'] = $change['review_rule'];
        }
        if (array_key_exists('review_limit_units', $change)) {
            if ($after['review_rule'] !== 'over_limit') {
                throw new CwException('bad_rule', "{$type}'s review rule is {$after['review_rule']}: a review limit applies only to over_limit", 400,
                    ['field' => 'review_limit_units']);
            }
            $after['review_limit_units'] = $limit($change['review_limit_units'], 'review_limit_units');
        }
        if ($after['review_rule'] === 'over_limit' && $after['review_limit_units'] === null) {
            throw new CwException('limit_required', 'the over_limit review rule needs a limit', 400, ['field' => 'review_limit_units']);
        }
        if (array_key_exists('review_due_days', $change)) {
            $d = $change['review_due_days'];
            if (is_string($d) && preg_match('/^\d{1,3}$/D', trim($d)) === 1) {
                $d = (int) trim($d);
            }
            if (!is_int($d) || $d < self::DAYS_MIN || $d > self::DAYS_MAX) {
                throw new CwException('bad_days', 'the days to check are ' . self::DAYS_MIN . ' to ' . self::DAYS_MAX, 400, ['field' => 'review_due_days']);
            }
            $after['review_due_days'] = $d;
        }
        $kind = self::APPROVAL_KIND[$type] ?? null;
        if (array_key_exists('approval_limit_units', $change)) {
            if ($kind === null) {
                throw new CwException('no_approval_rule', "{$type} has no approval rule: an approval limit does not apply to it", 400, ['field' => 'approval_limit_units']);
            }
            $after['approval_limit_units'] = $limit($change['approval_limit_units'], 'approval_limit_units');
        }
        if (array_key_exists('approval', $change)) {
            if ($kind === null) {
                throw new CwException('no_approval_rule', "{$type} has no approval rule to switch on or off", 400, ['field' => 'approval']);
            }
            $after['approval_rule'] = $change['approval'] === true ? $kind : 'none';
        }
        if ($after['approval_rule'] !== 'none' && $after['approval_limit_units'] === null) {
            throw new CwException('limit_required', 'a blocking approval needs its limit', 400, ['field' => 'approval_limit_units']);
        }
        if (array_key_exists('reject_action', $change)) {
            if (!in_array($change['reject_action'], self::REJECT_ACTIONS, true)) {
                throw new CwException('bad_reject_action', 'what Not OK does is reverse or record', 400, ['field' => 'reject_action']);
            }
            $after['reject_action'] = $change['reject_action'];
        }
        return $after;
    }
}
