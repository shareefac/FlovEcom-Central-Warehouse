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
 *      approvals.spot_check_size      how many matches a spot check holds; smaller ones never unlock a bulk confirm (KeySample)
 *      approvals.company_own_change   a reviewer confirming their own change of the company details gets another's check
 *      approvals.staff_grant          giving Admin or Reviewer waits for a reviewer's OK (StaffAdmin; OFF by default)
 *
 * A setting missing from an older schema reads as its default (SWITCHES, NUMBERS): the rules as they were before 0019.
 */
final class ApprovalRules
{
    /** switch => its default (what the system did before 0019; the new staff rule is off). */
    public const SWITCHES = [
        'approvals.supplier_activation' => true,
        'suppliers.change_review' => true,
        'approvals.match_multiple' => true,
        'approvals.match_counted' => true,
        'approvals.company_own_change' => true,
        'approvals.staff_grant' => false,
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
        'matching' => [['switch', 'approvals.match_multiple'], ['switch', 'approvals.match_counted'], ['number', 'approvals.spot_check_size']],
        'company' => [['switch', 'approvals.company_own_change']],
        'staff' => [['switch', 'approvals.staff_grant']],
    ];
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
