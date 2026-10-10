<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\Db;
use CW\Settings;

/**
 * Every approval rule in one place (the Approval rules page, docs/decisions.md Y9-Y11; the owner's Q8 answer of 7-8 Oct 2026:
 * "leave it for now", so the existing two-person rules stay ON and the extra one is OFF, all switchable):
 *
 *  - records (document_type, Admin\DocumentRules): the reviewer check after a record is final and the blocking approvals (a
 *    purchase order over a net value; stock put back without a supplier document, which also covers a cancellation putting more
 *    than that back, I32);
 *  - settings read here on every call (a switch changed on the screen applies to the next action):
 *      approvals.supplier_activation  a new supplier, one switched back on, an overseas supplier's import route (Suppliers)
 *      suppliers.change_review        changed details of a supplier we order from are checked afterwards (Suppliers)
 *      suppliers.approval_due_days    days a reviewer has to decide a supplier approval
 *      approvals.match_multiple       a match where 1 sale is not 1 product waits for a second matching lead (DecisionService)
 *      approvals.match_counted        a merge or split touching a counted item waits for a second matching lead (DecisionService)
 *      approvals.mapping_bulk_second_ok  a match confirmed, or a new product created, from a ticked list on the matching screens waits for
 *                                     a second matching lead (DecisionService, Mapping\BulkDecisions; OFF by default, M50)
 *      approvals.spot_check_size      how many matches a spot check holds; smaller ones never unlock a bulk confirm (KeySample)
 *      approvals.company_own_change   a reviewer confirming their own change of the company details gets another's check
 *      approvals.staff_grant          giving Admin or Reviewer waits for a reviewer's OK (StaffAdmin; OFF by default)
 *      approvals.staff_reset          a new sign-in code, a new password or a new sign-up sheet for someone holding Admin or Reviewer
 *                                     waits for a reviewer's OK (StaffAdmin, RoleRequests; OFF by default, Y44)
 *
 * A setting missing from an older schema reads as its default (SWITCHES, NUMBERS): the rules as they were before 0019.
 *
 * Loosening a rule (loosens(): a switch from on to off, the spot-check size down; a kind of record's rules: DocumentRules::loosens)
 * needs a person who may say OK to staff access (`staff.approve`, a Reviewer): the admin a rule restrains cannot switch it off
 * (review finding I1, Y45). Tightening stays with settings.manage (an admin or a reviewer). The server's tools (system callers)
 * may do both. The days a reviewer has to decide (suppliers.approval_due_days, a kind's review days) restrain nobody: either way.
 * Switching a rule off never releases work already waiting for an OK.
 */
final class ApprovalRules
{
    /** switch => its default (what the system did before 0019; the new staff rule is off). */
    public const SWITCHES = [
        'approvals.supplier_activation' => true,
        'suppliers.change_review' => true,
        'approvals.match_multiple' => true,
        'approvals.match_counted' => true,
        'approvals.mapping_bulk_second_ok' => false,
        'approvals.company_own_change' => true,
        'approvals.staff_grant' => false,
        'approvals.staff_reset' => false,
    ];
    /** number => its default. */
    public const NUMBERS = [
        'suppliers.approval_due_days' => 3,
        'approvals.spot_check_size' => 20,
    ];
    /** The page: section => its rules, in reading order (`document` a kind of record, `switch` and `number` a setting). */
    public const PAGE = [
        'records' => [['document', 'PO'], ['document', 'GRN'], ['document', 'ADJ'], ['document', 'CNT'], ['document', 'WO'], ['document', 'SINV'],
            ['document', 'DN'], ['document', 'TRD']],
        'suppliers' => [['switch', 'approvals.supplier_activation'], ['switch', 'suppliers.change_review'], ['number', 'suppliers.approval_due_days']],
        'matching' => [['switch', 'approvals.match_multiple'], ['switch', 'approvals.match_counted'], ['switch', 'approvals.mapping_bulk_second_ok'],
            ['number', 'approvals.spot_check_size']],
        'company' => [['switch', 'approvals.company_own_change']],
        'staff' => [['switch', 'approvals.staff_grant'], ['switch', 'approvals.staff_reset']],
    ];
    /** The numbers whose lower value is the looser rule (a smaller spot check proves less). */
    public const LOWER_IS_LOOSER = ['approvals.spot_check_size'];
    /** The permission that loosens a rule (I1): a Reviewer's. */
    public const LOOSEN_PERMISSION = 'staff.approve';
    /** The jobs whose grant waits for a reviewer's OK while approvals.staff_grant is on. */
    public const GUARDED_ROLES = ['admin', 'reviewer'];

    /** Whether a switch is on now (read from the database on every call). */
    public static function on(Db $db, string $key): bool
    {
        $default = self::SWITCHES[$key] ?? throw new \InvalidArgumentException("unknown approval switch {$key}");
        return Settings::flag($db, $key, $default);
    }

    /** A number of the rules now (read from the database on every call). */
    public static function number(Db $db, string $key): int
    {
        $default = self::NUMBERS[$key] ?? throw new \InvalidArgumentException("unknown approval number {$key}");
        return Settings::number($db, $key, $default);
    }

    /** Whether a setting is one of this page's rules (its switches and numbers). */
    public static function isRule(string $key): bool
    {
        return isset(self::SWITCHES[$key]) || isset(self::NUMBERS[$key]);
    }

    /**
     * Whether changing a rule setting from $before to $after makes it looser (a switch on -> off; a number of LOWER_IS_LOOSER going
     * down). The other numbers (days to decide) restrain nobody: never looser. Not a rule: false.
     */
    public static function loosens(string $key, mixed $before, mixed $after): bool
    {
        if (isset(self::SWITCHES[$key])) {
            return $before === true && $after !== true;
        }
        if (in_array($key, self::LOWER_IS_LOOSER, true)) {
            return is_int($before) && (!is_int($after) || $after < $before);
        }
        return false;
    }

    /**
     * 403 loosen_needs_reviewer when a person at a screen who may not say OK to staff access (staff.approve: a Reviewer) loosens a
     * rule (I1). A server tool (system caller) may. Call inside the change's transaction (the roles are re-read there).
     */
    public static function authoriseLoosening(Db $db, \CW\Caller $caller): void
    {
        if ($caller->staffUserId !== null
            && !\CW\Auth\Permissions::can(\CW\Staff\StaffRoles::active($db, $caller->staffUserId), self::LOOSEN_PERMISSION)) {
            throw new \CW\CwException('loosen_needs_reviewer', 'only a reviewer switches an approval rule off or makes it looser: ask one', 403);
        }
    }

    /**
     * A switch read together with its evidence, with the setting's row locked FOR SHARE (review finding M1): a one-person approval
     * decided under the switch and the version it names come from the same read, and nobody can change the switch until the
     * approval's transaction ends (Settings::change locks the row FOR UPDATE first). Call inside the approval's transaction.
     * `evidence` is the id of the version in force (a setting without a history gets its baseline first); null while the switch is
     * on (nothing to record). A setting missing from an older schema reads as its default, with no evidence.
     *
     * @return array{on: bool, evidence: ?int}
     */
    public static function lockedSwitch(Db $db, string $key): array
    {
        $default = self::SWITCHES[$key] ?? throw new \InvalidArgumentException("unknown approval switch {$key}");
        $v = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'bool' FOR SHARE", [$key]);
        $on = $v === null ? $default : trim((string) $v) === 'true';
        return ['on' => $on, 'evidence' => $on || $v === null ? null : self::evidence($db, $key)];
    }

    /**
     * The id of the setting's latest history version: what a one-person approval made while a switch is off records as its
     * evidence (supplier.alone_change_id; the nightly check S2/S3 finds the version and that it said off). A setting without a
     * history gets its baseline first (call inside the transaction of the approval).
     */
    public static function evidence(Db $db, string $key): int
    {
        $latest = ConfigHistory::latest($db, 'setting', $key);
        if ($latest !== null) {
            return $latest['id'];
        }
        $state = ConfigHistory::state($db, 'setting', $key) ?? throw new \LogicException("setting {$key} is missing");
        return $db->insert("INSERT INTO config_change (subject_type, subject_key, version, action, state, actor) VALUES ('setting', ?, 1, 'baseline', CAST(? AS JSON), 'system:history')",
            [$key, \CW\Idempotency::json($state)]);
    }
}
