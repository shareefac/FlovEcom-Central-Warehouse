<?php

declare(strict_types=1);

namespace CW\Auth;

use CW\CwException;

/**
 * THE single place that says what a role may do (docs/decisions.md I11). Pure: no database. A person holds
 * several roles (staff_role, 0007); what they may do is the union of the permissions of their roles, read
 * on every request (Sessions::resolve) and re-read by the services inside their write transactions.
 *
 * Routes are guarded by a permission (Ui\Router validates the name, Ui\Kernel::guarded checks it), never by
 * a role name, and the navigation (Ui\Sections) names each page with the same permission as its route, so a page a
 * person cannot open is neither shown nor served.
 *
 * Separation of duties (I12): `admin` manages people and roles and nothing else. It may be held together
 * with read-only roles only (ADMIN_COMPATIBLE); checkRoleSet() refuses anything else, so the person who
 * gives roles never posts, reviews or decides. A set that breaks the rule anyway (only possible through
 * admin SQL) is read fail-closed: the conflicting roles grant nothing while admin is held (effective()).
 *
 * The posting permissions (doc.<TYPE>.post) are proposals pending the owner's decisions 3 and 11 (I16); the Phase I-2
 * purchasing permissions (suppliers.*, purchasing.view, reorder.*) likewise (I40), the company details' (company.*, I90), the
 * item card's (catalogue.edit, I109), receiving's (receiving.view, incidents.*, I141) and the selling-mode switch's
 * (modes.set, I158). settings.manage (admin AND reviewer) is the owner's decision of 8 Oct 2026 (Y1): it overrides I12 for the
 * settings, rules and lists only.
 */
final class Permissions
{
    /** Every role, in the order of the staff_role ENUM (0007). */
    public const ROLES = ['viewer', 'mapper', 'mapping_lead', 'warehouse', 'manager', 'admin', 'buyer', 'purchasing_manager',
        'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'accountant', 'auditor'];

    /** Shown on the People and roles screen. */
    public const DESCRIPTIONS = [
        'viewer' => 'reads the linking screens',
        'mapper' => 'links listings, new items, ignore, reject',
        'mapping_lead' => 'mapper + second approvals, Conflict band, bulk',
        'warehouse' => 'counts and the count gate (I-4)',
        'manager' => 'sell policies',
        'admin' => 'people and roles only; never posts, reviews or decides',
        'buyer' => 'suppliers, purchase orders, reorder list (I-2)',
        'purchasing_manager' => 'buyer + purchasing documents',
        'goods_in' => 'goods-in bench, receive deliveries (I-3)',
        'purchasing_desk' => 'receive + invoice, supplier invoices, returns, trade issues',
        'stock_controller' => 'counts, adjustments, write-offs (I-4)',
        'reviewer' => 'reviews documents posted by others, gives the blocking approvals',
        'accountant' => 'reads stock values, exports (I-5)',
        'auditor' => 'read-only everywhere, people and roles list',
    ];

    /** The roles' groups on the People and roles form (every role in exactly one). */
    public const ROLE_GROUPS = [
        'Linking' => ['viewer', 'mapper', 'mapping_lead'],
        'Purchasing and receiving' => ['buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk'],
        'Stock' => ['warehouse', 'manager', 'stock_controller'],
        'Review and finance' => ['reviewer', 'accountant', 'auditor'],
        'Admin' => ['admin'],
    ];

    /** The only roles admin may be combined with: they read, they never act (I12). */
    public const ADMIN_COMPATIBLE = ['viewer', 'accountant', 'auditor'];

    /** permission => the roles that hold it. */
    public const MAP = [
        'catalogue.view' => self::ROLES,
        'linking.view' => ['viewer', 'mapper', 'mapping_lead', 'warehouse', 'manager', 'admin', 'auditor'],
        'mapping.decide' => ['mapper', 'mapping_lead'],
        'mapping.approve' => ['mapping_lead'],
        'staff.view' => ['admin', 'auditor'],
        'staff.manage' => ['admin'],
        'reference.view' => self::ROLES,
        'documents.view' => ['buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'accountant',
            'auditor', 'manager', 'warehouse'],
        'documents.review' => ['reviewer'],
        'documents.approve' => ['reviewer'],
        'accounts.view' => ['accountant', 'auditor'],
        'doc.PO.post' => ['buyer', 'purchasing_manager'],
        'doc.GRN.post' => ['goods_in', 'purchasing_desk', 'purchasing_manager'],
        'doc.SINV.post' => ['purchasing_desk', 'purchasing_manager'],
        'doc.DN.post' => ['purchasing_desk', 'purchasing_manager'],
        'doc.CNT.post' => ['stock_controller', 'warehouse'],
        'doc.ADJ.post' => ['stock_controller'],
        'doc.WO.post' => ['stock_controller'],
        'doc.TRD.post' => ['purchasing_desk', 'purchasing_manager'],
        // Pack A1 (docs/decisions.md SO9; provisional, owner to confirm): a stock in (opening stock, found stock, a trade customer's
        // return) and a stock out (samples, staff use, trade sales, goods sent for repair) are the stock controller's and the purchasing
        // desk's (the desk keys trade sales and returns today); an adjustment stays the stock controller's (doc.ADJ.post, I16); a
        // transfer between warehouses or places is the stock controller's and the warehouse's (who moves the stock); a release from
        // another account's warehouse is a purchase from that account: the purchasing desk and manager, and the stock controller.
        // accounts.pay records (and reverses) a payment to that account: the purchasing desk and manager. Never admin (I12).
        'doc.SIN.post' => ['stock_controller', 'purchasing_desk'],
        'doc.SOUT.post' => ['stock_controller', 'purchasing_desk'],
        'doc.TRF.post' => ['stock_controller', 'warehouse'],
        'doc.REL.post' => ['purchasing_desk', 'purchasing_manager', 'stock_controller'],
        'accounts.pay' => ['purchasing_desk', 'purchasing_manager'],
        // Phase I-2 (docs/decisions.md I40): suppliers (IM4), purchase orders (IM5), the reorder list and its sales history (IM9).
        // doc.PO.post (buyer, purchasing_manager) covers drafting, approving (posting), sending, cancelling, amending, closing
        // and importing PO lines. A supplier is approved by a reviewer who did not create, ask for or last change it.
        'suppliers.view' => ['buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'accountant', 'auditor',
            'manager'],
        'suppliers.manage' => ['buyer', 'purchasing_manager'],
        'suppliers.approve' => ['reviewer'],
        'purchasing.view' => ['buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'reviewer', 'accountant', 'auditor', 'manager'],
        'reorder.view' => ['buyer', 'purchasing_manager', 'reviewer', 'auditor', 'manager'],
        'reorder.manage' => ['buyer', 'purchasing_manager'],
        // The company details printed on every PO (I90, provisional: owner to confirm): everyone reads them (reference.view); a
        // reviewer (the owner's role) adds, changes and confirms them and reviews another reviewer's change; never admin (I12).
        'company.edit' => ['reviewer'],
        'company.confirm' => ['reviewer'],
        // The item card (IM3, I100-I112; provisional, owner to confirm): everyone reads cards and barcodes (catalogue.view); the
        // catalogue work (the legal fields, accepting proposals, confirming a card, the barcodes, the barcode review, the CSV
        // import) is mapping_lead, stock_controller and purchasing_manager; never admin (I12).
        'catalogue.edit' => ['mapping_lead', 'stock_controller', 'purchasing_manager'],
        // Receiving (IM6, I-3; docs/decisions.md I141; provisional, owner to confirm). doc.GRN.post (goods_in, purchasing_desk,
        // purchasing_manager: I16) keys a receipt (its creator), does the goods-in bench check (anyone holding it) and posts;
        // receiving.view reads the receipts; incidents.view reads the incident register, incidents.resolve closes an incident
        // with a note (the units themselves leave VERIFY / UNSTAMPED through IM2's documents). Never admin (I12).
        'receiving.view' => ['goods_in', 'purchasing_desk', 'purchasing_manager', 'stock_controller', 'reviewer', 'accountant', 'auditor', 'manager'],
        'incidents.view' => ['goods_in', 'purchasing_desk', 'purchasing_manager', 'stock_controller', 'reviewer', 'auditor', 'manager'],
        'incidents.resolve' => ['purchasing_desk', 'purchasing_manager', 'stock_controller'],
        // The selling-mode switch (IM10, I-3; docs/decisions.md I158; provisional, owner to confirm): the mode CW writes on each
        // website for a legacy item (a receipt's line sets it too, doc.GRN.post). The people who set modes on ERPNext invoice lines
        // and item saves today (the purchasing desk and managers), the stock controller, and the manager (sell policies). Never admin.
        'modes.set' => ['purchasing_desk', 'purchasing_manager', 'stock_controller', 'manager'],
        // The set-it-yourself pack (0019; docs/decisions.md Y1, the owner's decision of 8 Oct 2026): settings, approval rules, reason
        // codes, warehouses and their places are changed on the screens by an admin AND a reviewer (the owner and Fazil). This
        // overrides I12 for settings only: Admin still never posts, reviews or decides stock or matching. Everyone reads them
        // (reference.view).
        'settings.manage' => ['admin', 'reviewer'],
        // The audit log (G07): read only, for the people who check and the admin.
        'audit.view' => ['admin', 'reviewer', 'auditor', 'accountant'],
        // The websites' status and the nightly safety checks (G05, G36): read only.
        'system.view' => ['admin', 'reviewer', 'auditor', 'manager'],
        // A reviewer's OK of a grant of Admin or Reviewer, while the owner has that rule on (approvals.staff_grant, Y25).
        'staff.approve' => ['reviewer'],
        // ---- Pack D (docs/decisions.md PD2; provisional, owner to confirm): Stock › Sales, read only. Each store's orders, what
        // waits to be sent and what came back or was cancelled: the warehouse and stock jobs, buying and the desk (returns), the
        // reviewer, the accountant and the auditor, and the viewer (a read-only job). Not the matching jobs (their work is the
        // links) and not admin (people and roles only, I12). Its own permission, so that the roles screen can change it alone. ----
        'sales.view' => ['viewer', 'warehouse', 'manager', 'buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer',
            'accountant', 'auditor'],
        // ---- end of pack D ----
        // ---- Pack B, Item Management (docs/decisions.md PB2, PB4, PB9; provisional, owner to confirm). Never admin (I12).
        // items.create: an item made by hand (Q10), by the catalogue team (the holders of catalogue.edit), who also fill in its card
        // and barcodes. families.manage: product families (Q4), by the catalogue team and the buyers (buyers think in products).
        // doc.RPK.post: a Repack (Q3) is a stock move in the warehouse, by the people who move stock (as a transfer, doc.TRF.post).
        // A pack item's definition (Products > Multipacks) is catalogue.edit's, like a barcode's units per scan.
        'items.create' => ['mapping_lead', 'stock_controller', 'purchasing_manager'],
        'families.manage' => ['mapping_lead', 'stock_controller', 'purchasing_manager', 'buyer'],
        'doc.RPK.post' => ['stock_controller', 'warehouse'],
        // ---- end of pack B
        // --- Pack C (IM7; docs/decisions.md PC7; provisional, owner to confirm). Supplier invoices and credit notes are the purchasing
        // desk's and manager's (doc.SINV.post since I16; doc.SCN.post the same people); invoices.view reads them, the "To match" list and
        // the deliveries not invoiced yet (buyers, the desk, managers, reviewers, the accountant, the auditor; not goods in: money);
        // invoices.accept accepts the differences of an invoice that does not match (the desk, the purchasing manager, a reviewer; a
        // second person only while approvals.invoice_accept_other is on). Returns to the supplier stay doc.DN.post (the desk and the
        // purchasing manager, I16); returns.view reads them, goods in and the stock controller too (they pack the goods). Never admin.
        'doc.SCN.post' => ['purchasing_desk', 'purchasing_manager'],
        'invoices.view' => ['buyer', 'purchasing_manager', 'purchasing_desk', 'reviewer', 'accountant', 'auditor', 'manager'],
        'invoices.accept' => ['purchasing_desk', 'purchasing_manager', 'reviewer'],
        'returns.view' => ['buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'accountant', 'auditor', 'manager'],
        // --- end of pack C
        // ---- Pack F (go-live; docs/decisions.md PF5; provisional, owner to confirm) -------------------------------------------------
        // The stores' queues on Approvals › From the stores: what the ERPNext relay could not place, ERPNext's corrections and mode
        // changes, and the oversold orders. Everyone who reads records looks (queues.view); the go-live plan names the purchasing desk
        // with a stand-in, the stock controller and the reviewer for them (part 9, list A16). Each action is re-checked by its service
        // (CW\Queues\*) inside its transaction; never admin (I12).
        'queues.view' => ['buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'auditor', 'manager', 'accountant'],
        // A line to place: book it on the item a person picks (the people who book deliveries and stock today).
        'queues.suspense' => ['purchasing_desk', 'purchasing_manager', 'stock_controller'],
        // An ERPNext correction changes the stock figure without a count: the stock controller's.
        'queues.corrections' => ['stock_controller'],
        // A selling-mode change: the people who set modes (modes.set, I158).
        'queues.modes' => ['purchasing_desk', 'purchasing_manager', 'stock_controller', 'manager'],
        // An oversold order: the people who sort it out with the customer and the stock.
        'queues.oversell' => ['purchasing_desk', 'purchasing_manager', 'stock_controller', 'manager'],
        // ---- end of pack F -----------------------------------------------------------------------------------------------------------
        // ---- Pack E (docs/decisions.md PE1; provisional, owner to confirm): the Reports tabs. reports.view reads Stock by Store, Sales by
        // Store, Low Stock and Slow stock (figures of stock and sales, no money); values.view reads Stock Value (pounds). Both read only.
        'reports.view' => ['warehouse', 'manager', 'buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer',
            'accountant', 'auditor'],
        'values.view' => ['purchasing_manager', 'reviewer', 'accountant', 'auditor'],
        // ---- end Pack E
    ];

    /** The screens not built yet (Phase I-4 to I-6; receiving is built, IM6; stock counts since pack A2a; supplier invoices and returns since pack C): Home names the ones the person's jobs will use (Ui\Words::COMING_LATER). */
    public const COMING_LATER = [
        ['key' => 'trade', 'perm' => 'doc.TRD.post'],
        ['key' => 'values', 'perm' => 'accounts.view'],
    ];

    /**
     * Whether the roles hold $perm. An unknown permission is a programming error (a typo must fail loudly,
     * never read as "no").
     *
     * @param list<string> $roles
     */
    public static function can(array $roles, string $perm): bool
    {
        $holders = self::map()[$perm] ?? throw new \InvalidArgumentException("unknown permission {$perm}");
        return array_intersect(self::effective($roles), $holders) !== [];
    }

    /** @param list<string> $roles @return list<string> every permission the roles hold, in MAP order */
    public static function permissionsOf(array $roles): array
    {
        $have = self::effective($roles);
        $out = [];
        foreach (self::map() as $perm => $holders) {
            if (array_intersect($have, $holders) !== []) {
                $out[] = $perm;
            }
        }
        return $out;
    }

    /**
     * Validates a role set someone is about to give a person, and returns it sorted and without duplicates.
     * 400 bad_role (unknown), 422 no_roles (empty: deactivate the person instead), 422 role_conflict (admin
     * with a role outside ADMIN_COMPATIBLE, I12).
     *
     * @param array<mixed> $roles
     * @return list<string>
     */
    public static function checkRoleSet(array $roles): array
    {
        $set = [];
        foreach ($roles as $r) {
            if (!is_string($r) || !in_array($r, self::ROLES, true)) {
                throw new CwException('bad_role', 'a role must be one of ' . implode(', ', self::ROLES), 400,
                    ['role' => is_scalar($r) ? mb_substr((string) $r, 0, 40) : gettype($r)]);
            }
            $set[$r] = true;
        }
        $set = array_keys($set);
        sort($set);
        if ($set === []) {
            throw new CwException('no_roles', 'a person needs at least one role (to take every role away, deactivate the person instead)', 422);
        }
        if (in_array('admin', $set, true)) {
            $conflict = array_values(array_diff($set, ['admin'], self::ADMIN_COMPATIBLE));
            if ($conflict !== []) {
                throw new CwException('role_conflict', 'admin cannot be combined with ' . implode(', ', $conflict)
                    . ': the person who manages people and roles never posts, reviews or decides', 422, ['conflict' => $conflict]);
            }
        }
        return $set;
    }

    /** @param list<string> $roles @return list<string> the COMING_LATER keys the roles will use */
    public static function comingLater(array $roles): array
    {
        $out = [];
        foreach (self::COMING_LATER as $i) {
            if (self::can($roles, $i['perm'])) {
                $out[] = $i['key'];
            }
        }
        return $out;
    }

    /**
     * The roles Admin switches off (I12): a set holding admin with a role outside ADMIN_COMPATIBLE reads fail-closed, so those
     * roles grant nothing (effective()). Pure; the screens say so in words (Ui\Words::switchedOffNote, the "(off)" marks).
     *
     * @param list<string> $roles
     * @return list<string> in the order given
     */
    public static function switchedOff(array $roles): array
    {
        return array_values(array_diff($roles, self::effective($roles)));
    }

    /**
     * Whether the roles would hold $perm without the Admin rule: true when it is Admin alone that keeps it from them (the
     * 403 then names the fix instead of the jobs that hold it).
     *
     * @param list<string> $roles
     */
    public static function blockedByAdmin(array $roles, string $perm): bool
    {
        $off = self::switchedOff($roles);
        return $off !== [] && !self::can($roles, $perm) && self::can($off, $perm);
    }

    // ---- Pack E: the map in the database (role_permission, 0034; docs/decisions.md PE2-PE8) ---------------------------------------------
    //
    // Who holds what is kept in role_permission (one row per permission), changed on Settings › Users › Roles and permissions
    // (Admin\RolePermissions) and read on every request (Auth\RoleMap::load, from Ui\Kernel and Staff\StaffRoles::active). MAP above is
    // what 0034 seeded, and the DEFAULT of a permission that has no row yet: a permission another change adds to MAP later works at once
    // with the holders written there, and gets its row the first time someone changes it on the screen (PE3). A permission's row, once
    // there, wins over MAP. The rules below hold whatever the table says (sanitise(): read fail-closed) and are what the screen refuses
    // (problems()). A permission is read-only when its key ends in ".view" (isReadOnly(): a key that changes anything never does).

    /**
     * Never changed on the screen (MAP wins over any row): what every job needs (Home, the settings pages), the security core (who
     * manages people, settings and the reviewer's OK), and the permissions other code reads by job name (the matching leads of
     * DecisionService and KeySample; the company checks of CompanyDetails and CompanyInvariants).
     */
    public const LOCKED = ['catalogue.view', 'reference.view', 'staff.manage', 'settings.manage', 'staff.approve', 'mapping.decide', 'mapping.approve',
        'company.edit', 'company.confirm'];
    /** The guarded jobs keep these whatever the screen says: an admin sees the people; a reviewer can give the checks and the OKs. */
    public const REQUIRED = ['staff.view' => ['admin'], 'documents.review' => ['reviewer'], 'documents.approve' => ['reviewer'],
        'suppliers.approve' => ['reviewer']];

    /** @var array<string, list<string>>|null the map read from the database (null: MAP) */
    private static ?array $live = null;
    /** @var array<string, list<string>>|null MAP, each permission's holders in ROLES order */
    private static ?array $defaults = null;

    /** Whether a permission only lets a person look (its key ends in ".view"). */
    public static function isReadOnly(string $perm): bool
    {
        return str_ends_with($perm, '.view');
    }

    /** @return array<string, list<string>> the map in force: permission => its holders in ROLES order, in MAP's order */
    public static function map(): array
    {
        return self::$live ?? self::defaults();
    }

    /** @return array<string, list<string>> MAP (what 0034 seeded, and the default of a permission with no row), holders in ROLES order */
    public static function defaults(): array
    {
        if (self::$defaults === null) {
            self::$defaults = [];
            foreach (self::MAP as $perm => $holders) {
                self::$defaults[$perm] = self::ordered($holders);
            }
        }
        return self::$defaults;
    }

    /** @return list<string> the jobs that hold $perm now, in ROLES order; an unknown permission throws */
    public static function holders(string $perm): array
    {
        return self::map()[$perm] ?? throw new \InvalidArgumentException("unknown permission {$perm}");
    }

    /**
     * Puts the map read from role_permission in force for this process (Auth\RoleMap::load); null puts MAP back (tests). Every
     * permission of MAP is kept, in MAP's order: one without a row reads as MAP says, a row for a permission MAP does not have is
     * ignored, and every permission's holders are sanitised.
     *
     * @param array<string, list<string>>|null $stored permission => the jobs its row names
     */
    public static function useStored(?array $stored): void
    {
        if ($stored === null) {
            self::$live = null;
            return;
        }
        $map = [];
        foreach (self::MAP as $perm => $default) {
            $map[$perm] = self::sanitise($perm, $stored[$perm] ?? $default);
        }
        self::$live = $map;
    }

    /**
     * Nothing allowed: every permission of MAP held by no job (Auth\RoleMap after a failed read, review finding 2, PE17). Even a caller
     * that went on after the failure gets no permission from the code's defaults.
     */
    public static function denyAll(): void
    {
        self::$live = array_fill_keys(array_keys(self::MAP), []);
    }

    /**
     * The powers the code keeps for the guarded jobs (Admin and Reviewer, Admin\ApprovalRules::GUARDED_ROLES): every permission whose
     * holders in MAP are among them only (managing people and settings, the reviewer's OKs and checks). A job given one of these on
     * the screen is guarded like them (ApprovalRules::guardedRoles, review finding 1, PE16).
     *
     * @return list<string>
     */
    public static function guardedPowers(): array
    {
        return array_keys(array_filter(self::MAP, static fn (array $holders): bool => array_diff($holders, ['admin', 'reviewer']) === []));
    }

    /**
     * The holders a permission may have, fail-closed (what the table says is never more than the screen allows): unknown jobs are
     * dropped; a LOCKED permission is held as MAP says; a permission that acts is never held by admin or by the look-only jobs admin
     * may be combined with (I12); the REQUIRED holders are always there.
     *
     * @param list<mixed> $holders
     * @return list<string> in ROLES order
     */
    public static function sanitise(string $perm, array $holders): array
    {
        if (in_array($perm, self::LOCKED, true)) {
            return self::ordered(self::MAP[$perm] ?? []);
        }
        $set = array_values(array_filter($holders, static fn (mixed $r): bool => is_string($r) && in_array($r, self::ROLES, true)));
        if (!self::isReadOnly($perm)) {
            $set = array_values(array_diff($set, ['admin'], self::ADMIN_COMPATIBLE));
        }
        return self::ordered([...$set, ...(self::REQUIRED[$perm] ?? [])]);
    }

    /**
     * What is wrong with giving $perm to exactly $holders (the screen's refusals, Admin\RolePermissions), as codes in this order:
     * `unknown_permission`, `locked` (never changed on the screen), `bad_role` (not a job), `no_holders` (every task needs a job that
     * does it), `acting_job` (admin or a look-only job given a permission that changes something: I12), `required` (a guarded job's
     * own permission taken away). [] when it is allowed.
     *
     * @param list<mixed> $holders
     * @return list<string>
     */
    public static function problems(string $perm, array $holders): array
    {
        if (!isset(self::MAP[$perm])) {
            return ['unknown_permission'];
        }
        if (in_array($perm, self::LOCKED, true)) {
            return ['locked'];
        }
        $out = [];
        foreach ($holders as $r) {
            if (!is_string($r) || !in_array($r, self::ROLES, true)) {
                return ['bad_role'];
            }
        }
        if ($holders === []) {
            $out[] = 'no_holders';
        }
        if (!self::isReadOnly($perm) && array_intersect($holders, ['admin', ...self::ADMIN_COMPATIBLE]) !== []) {
            $out[] = 'acting_job';
        }
        if (array_diff(self::REQUIRED[$perm] ?? [], $holders) !== []) {
            $out[] = 'required';
        }
        return $out;
    }

    /**
     * $holders of $perm in the order the code lists them (MAP), then any other job in ROLES order: the sentences that name the jobs
     * (Ui\Words::whoCan) read as they always did while the map is unchanged.
     *
     * @param list<string> $holders
     * @return list<string>
     */
    public static function inCodeOrder(string $perm, array $holders): array
    {
        $code = self::MAP[$perm] ?? [];
        $rank = static fn (string $r): int => ($i = array_search($r, $code, true)) !== false ? (int) $i : 100 + (int) array_search($r, self::ROLES, true);
        $out = self::ordered($holders);
        usort($out, static fn (string $a, string $b): int => $rank($a) <=> $rank($b));
        return $out;
    }

    /** @param list<mixed> $roles @return list<string> the known jobs among $roles, once each, in ROLES order */
    public static function ordered(array $roles): array
    {
        return array_values(array_filter(self::ROLES, static fn (string $r): bool => in_array($r, $roles, true)));
    }
    // ---- end Pack E

    /**
     * The roles that count: all of them, except that a set holding admin together with a role outside
     * ADMIN_COMPATIBLE (never written by CW: checkRoleSet refuses it) counts only admin and the compatible ones.
     *
     * @param list<string> $roles
     * @return list<string>
     */
    private static function effective(array $roles): array
    {
        if (!in_array('admin', $roles, true)) {
            return $roles;
        }
        return array_values(array_intersect($roles, ['admin', ...self::ADMIN_COMPATIBLE]));
    }
}
