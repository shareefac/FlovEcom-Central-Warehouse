<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Auth\Permissions;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Staff\StaffRoles;

/**
 * Who may write expenses and regular costs (docs/decisions.md EX12): a person holding the Expenses job (expenses.edit, LOCKED to it like the
 * delivery bills' upload, DL31), re-read inside the write's transaction; never admin (I12: admin gives the job, it never acts); a server tool
 * (system:...: bin/seed_regular_costs.php, the tests). Finance looks only (expenses.view).
 */
final class ExpenseAccess
{
    public const JOB = 'expenses';
    public const PERM_VIEW = 'expenses.view';
    public const PERM_EDIT = 'expenses.edit';

    /**
     * Whether the caller sees the expenses (expenses.view: Finance and the Expenses job, LOCKED), re-read from their jobs now: the reads of
     * expense figures ask it inside their own snapshot (ExpenseViews with a viewer), so a job taken away while a page is drawn shows nothing
     * (the final review of 70ab3d2, astra MEDIUM; docs/decisions.md EX48). A server tool (system:...) may.
     */
    public static function mayView(Db $db, Caller $caller): void
    {
        if ($caller->staffUserId === null) {
            if (!$caller->isChannel() && str_starts_with($caller->actor, 'system:')) {
                return;
            }
            throw new CwException('staff_required', 'expenses are read by staff', 403);
        }
        if (!Permissions::can(StaffRoles::active($db, $caller->staffUserId), self::PERM_VIEW)) {
            throw new CwException('role_not_allowed', 'your jobs do not see the expenses', 403);
        }
    }

    public static function mayEdit(Db $db, Caller $caller): void
    {
        if ($caller->staffUserId === null) {
            if (!$caller->isChannel() && str_starts_with($caller->actor, 'system:')) {
                return;
            }
            throw new CwException('staff_required', 'expenses are written by staff', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (in_array('admin', $roles, true)) {
            throw new CwException('admin_cannot_post', 'admin manages people and roles only', 403);
        }
        if (!in_array(self::JOB, $roles, true) || !Permissions::can($roles, self::PERM_EDIT)) {
            throw new CwException('role_not_allowed', 'your jobs cannot add, change or delete expenses', 403);
        }
    }
}
