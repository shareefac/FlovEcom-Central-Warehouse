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
 * a role name, and the menu is derived from the same map (MENU), so a page a person cannot open is neither
 * shown nor served.
 *
 * Separation of duties (I12): `admin` manages people and roles and nothing else. It may be held together
 * with read-only roles only (ADMIN_COMPATIBLE); checkRoleSet() refuses anything else, so the person who
 * gives roles never posts, reviews or decides. A set that breaks the rule anyway (only possible through
 * admin SQL) is read fail-closed: the conflicting roles grant nothing while admin is held (effective()).
 *
 * The posting permissions (doc.<TYPE>.post) are proposals pending the owner's decisions 3 and 11 (I16); the Phase I-2
 * purchasing permissions (suppliers.*, purchasing.view, reorder.*) likewise (I40), the company details' (company.*, I90), the
 * item card's (catalogue.edit, I109), receiving's (receiving.view, incidents.*, I141) and the selling-mode switch's
 * (modes.set, I158).
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
    ];

    /**
     * The navigation, grouped. An item has either a `path` (a live GET route; `query` is added to its URL) or a
     * `phase` (shown as "<label> · coming in Phase <phase>", never a link: the screen does not exist yet, I14).
     * `key` marks the item as the current page (layout `active`); `badge` names a count of Ui\Context::badges()
     * (linking_pending, linking_duplicates, reviews_open, barcodes_open, incidents_open). Document reviews, Documents and Reference are live since the documents task
     * (0008, I27): real screens, empty until a phase registers a document type.
     */
    public const MENU = [
        ['section' => 'Linking', 'items' => [
            ['label' => 'Dashboard', 'perm' => 'linking.view', 'key' => 'dashboard', 'path' => '/ui/'],
            ['label' => 'Review', 'perm' => 'linking.view', 'key' => 'review', 'path' => '/ui/review', 'query' => ['queue' => 'Key']],
            ['label' => 'Second approval', 'perm' => 'linking.view', 'key' => 'pending', 'path' => '/ui/review', 'query' => ['queue' => 'pending'],
                'badge' => 'linking_pending'],
            ['label' => 'Key spot-check', 'perm' => 'linking.view', 'key' => 'samples', 'path' => '/ui/review/samples'],
            ['label' => 'Duplicates', 'perm' => 'linking.view', 'key' => 'duplicates', 'path' => '/ui/review/duplicates', 'badge' => 'linking_duplicates'],
        ]],
        // IM3 (I109): the item cards list (everyone) and the barcode review queue (the people who decide it).
        ['section' => 'Items', 'items' => [
            ['label' => 'Search', 'perm' => 'catalogue.view', 'key' => 'search', 'path' => '/ui/search'],
            ['label' => 'Item cards', 'perm' => 'catalogue.view', 'key' => 'cards', 'path' => '/ui/items/cards'],
            ['label' => 'Barcode review', 'perm' => 'catalogue.edit', 'key' => 'barcodes', 'path' => '/ui/items/barcodes', 'badge' => 'barcodes_open'],
        ]],
        // Phase I-2: Suppliers (the suppliers task), Purchase orders (the pos task), Reorder list and Sales history (the reorder task).
        ['section' => 'Purchasing', 'items' => [
            ['label' => 'Suppliers', 'perm' => 'suppliers.view', 'key' => 'suppliers', 'path' => '/ui/purchasing/suppliers'],
            ['label' => 'Purchase orders', 'perm' => 'purchasing.view', 'key' => 'orders', 'path' => '/ui/purchasing/orders'],
            ['label' => 'Reorder list', 'perm' => 'reorder.view', 'key' => 'reorder', 'path' => '/ui/purchasing/reorder'],
            ['label' => 'Sales history', 'perm' => 'reorder.view', 'key' => 'sales_history', 'path' => '/ui/purchasing/sales-history'],
        ]],
        // IM6 (I-3, I141): the receipts (keying and the read-only views), the goods-in bench's list of deliveries to check, and the
        // incident register (badge: the open incidents).
        ['section' => 'Receiving', 'items' => [
            ['label' => 'Receive + invoice', 'perm' => 'receiving.view', 'key' => 'receiving', 'path' => '/ui/receiving'],
            ['label' => 'Goods-in bench', 'perm' => 'doc.GRN.post', 'key' => 'bench', 'path' => '/ui/receiving/bench'],
            ['label' => 'Incidents', 'perm' => 'incidents.view', 'key' => 'incidents', 'path' => '/ui/receiving/incidents', 'badge' => 'incidents_open'],
            ['label' => 'Supplier invoices', 'perm' => 'doc.SINV.post', 'phase' => 'I-4'],
            ['label' => 'Supplier returns', 'perm' => 'doc.DN.post', 'phase' => 'I-4'],
        ]],
        ['section' => 'Stock control', 'items' => [
            ['label' => 'Counts', 'perm' => 'doc.CNT.post', 'phase' => 'I-4'],
            ['label' => 'Adjustments and write-offs', 'perm' => 'doc.ADJ.post', 'phase' => 'I-4'],
        ]],
        ['section' => 'Trade', 'items' => [
            ['label' => 'Trade and inter-site issues', 'perm' => 'doc.TRD.post', 'phase' => 'I-6'],
        ]],
        ['section' => 'Document reviews', 'items' => [
            ['label' => 'Review queue', 'perm' => 'documents.review', 'key' => 'reviews', 'path' => '/ui/documents/reviews', 'badge' => 'reviews_open'],
        ]],
        ['section' => 'Documents', 'items' => [
            ['label' => 'All documents', 'perm' => 'documents.view', 'key' => 'documents', 'path' => '/ui/documents'],
        ]],
        ['section' => 'Accounts', 'items' => [
            ['label' => 'Stock valuation and accountant files', 'perm' => 'accounts.view', 'phase' => 'I-5'],
        ]],
        ['section' => 'Reference', 'items' => [
            ['label' => 'Reason codes', 'perm' => 'reference.view', 'key' => 'reasons', 'path' => '/ui/reference/reasons'],
            ['label' => 'Number series', 'perm' => 'reference.view', 'key' => 'series', 'path' => '/ui/reference/series'],
            ['label' => 'Settings', 'perm' => 'reference.view', 'key' => 'settings', 'path' => '/ui/reference/settings'],
            ['label' => 'Company details', 'perm' => 'reference.view', 'key' => 'company', 'path' => '/ui/reference/company'],
        ]],
        ['section' => 'Admin', 'items' => [
            ['label' => 'People and roles', 'perm' => 'staff.view', 'key' => 'people', 'path' => '/ui/people'],
        ]],
    ];

    /**
     * Whether the roles hold $perm. An unknown permission is a programming error (a typo must fail loudly,
     * never read as "no").
     *
     * @param list<string> $roles
     */
    public static function can(array $roles, string $perm): bool
    {
        if (!isset(self::MAP[$perm])) {
            throw new \InvalidArgumentException("unknown permission {$perm}");
        }
        return array_intersect(self::effective($roles), self::MAP[$perm]) !== [];
    }

    /** @param list<string> $roles @return list<string> every permission the roles hold, in MAP order */
    public static function permissionsOf(array $roles): array
    {
        $have = self::effective($roles);
        $out = [];
        foreach (self::MAP as $perm => $holders) {
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

    /**
     * The menu the roles see: only the items they may use, sections without one left out.
     *
     * @param list<string> $roles
     * @return list<array{section: string, items: list<array<string, mixed>>}>
     */
    public static function menu(array $roles): array
    {
        $out = [];
        foreach (self::MENU as $section) {
            $items = array_values(array_filter($section['items'], static fn (array $i): bool => self::can($roles, $i['perm'])));
            if ($items !== []) {
                $out[] = ['section' => $section['section'], 'items' => $items];
            }
        }
        return $out;
    }

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
