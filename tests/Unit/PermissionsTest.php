<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\CwException;
use CW\Mapping\DecisionService;
use CW\Ui\Kernel;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The one permission map (I11, I12, I14, I16): every role and permission it names exists, the screens not built yet are
 * no routes (the Dashboard names them), and admin never posts, reviews or decides. The navigation built from it (every page a
 * real route guarded by the same permission, the owner's acceptance test of three different menus) is SectionsTest.
 */
final class PermissionsTest extends TestCase
{
    private const ROLES = ['viewer', 'mapper', 'mapping_lead', 'warehouse', 'manager', 'admin', 'buyer', 'purchasing_manager',
        'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'accountant', 'auditor'];

    public function testTheFourteenRolesAndTheirDescriptionsAndGroups(): void
    {
        self::assertSame(self::ROLES, Permissions::ROLES);
        self::assertSame(Permissions::ROLES, DecisionService::ROLES, 'kept as an alias');
        self::assertSame(Permissions::ROLES, array_keys(Permissions::DESCRIPTIONS));
        foreach (Permissions::DESCRIPTIONS as $role => $text) {
            self::assertNotSame('', trim($text), $role);
        }
        $grouped = array_merge(...array_values(Permissions::ROLE_GROUPS));
        sort($grouped);
        $all = Permissions::ROLES;
        sort($all);
        self::assertSame($all, $grouped, 'every role in exactly one group of the People form');
        self::assertSame(['Linking', 'Purchasing and receiving', 'Stock', 'Review and finance', 'Admin'], array_keys(Permissions::ROLE_GROUPS));

        // The ENUM of staff_role (0007) is exactly this list, in this order.
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0007_staff_roles.sql');
        self::assertSame(1, preg_match("/\\brole\\s+ENUM\\(([^)]*)\\)/", $sql, $m));
        self::assertSame(Permissions::ROLES, array_map(static fn (string $v): string => trim($v, " '\n"), explode(',', $m[1])));
    }

    public function testEveryRoleOfTheMapIsKnownAndTheMappingRolesAreUnchanged(): void
    {
        foreach (Permissions::MAP as $perm => $roles) {
            self::assertMatchesRegularExpression('/^[a-z]+\.[a-z]+$|^doc\.(PO|GRN|SINV|DN|CNT|ADJ|WO|TRD)\.post$/D', $perm);
            self::assertNotSame([], $roles, $perm);
            self::assertSame([], array_values(array_diff($roles, Permissions::ROLES)), "{$perm}: unknown role");
            self::assertSame(array_values(array_unique($roles)), $roles, $perm);
        }
        foreach (['PO', 'GRN', 'SINV', 'DN', 'CNT', 'ADJ', 'WO', 'TRD'] as $type) {
            self::assertArrayHasKey("doc.{$type}.post", Permissions::MAP);
        }
        // The linking rules keep their meaning (DecisionService checks these permissions).
        self::assertSame(DecisionService::DECIDERS, Permissions::MAP['mapping.decide']);
        self::assertSame([DecisionService::LEAD], Permissions::MAP['mapping.approve']);
        self::assertSame(Permissions::ROLES, Permissions::MAP['catalogue.view']);
        self::assertSame(Permissions::ROLES, Permissions::MAP['reference.view']);
        foreach (['buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'accountant'] as $new) {
            self::assertFalse(Permissions::can([$new], 'linking.view'), "{$new} never had the linking screens");
        }
    }

    /** The screens not built yet are named on the Dashboard (Words::COMING_LATER) and are no routes yet. The navigation: SectionsTest. */
    public function testTheScreensNotBuiltYetAreNoRoutes(): void
    {
        $kernel = new Kernel(static fn (): never => throw new \RuntimeException('no database'), static fn (): ?string => null, static function (): void {
        });
        $gets = [];
        foreach ($kernel->router()->routes() as $route) {
            if ($route->method === 'GET') {
                $gets[$route->pattern] = $route->access;
            }
        }
        foreach (Permissions::COMING_LATER as $later) {
            self::assertArrayHasKey($later['perm'], Permissions::MAP);
            self::assertArrayHasKey($later['key'], Words::COMING_LATER);
            self::assertNotContains($later['perm'], array_values($gets), "{$later['key']}: no screen yet");
        }
    }

    /** Phase I-2 (I40): suppliers, purchase orders, the reorder list. */
    public function testThePurchasingPermissionsOfPhaseI2(): void
    {
        self::assertSame(['buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'stock_controller', 'reviewer', 'accountant', 'auditor', 'manager'],
            Permissions::MAP['suppliers.view']);
        self::assertSame(['buyer', 'purchasing_manager'], Permissions::MAP['suppliers.manage']);
        self::assertSame(['reviewer'], Permissions::MAP['suppliers.approve']);
        self::assertSame(['buyer', 'purchasing_manager', 'goods_in', 'purchasing_desk', 'reviewer', 'accountant', 'auditor', 'manager'],
            Permissions::MAP['purchasing.view']);
        self::assertSame(['buyer', 'purchasing_manager', 'reviewer', 'auditor', 'manager'], Permissions::MAP['reorder.view']);
        self::assertSame(['buyer', 'purchasing_manager'], Permissions::MAP['reorder.manage']);
        self::assertSame(['buyer', 'purchasing_manager'], Permissions::MAP['doc.PO.post'], 'unchanged: drafting, approving, sending ... a PO');
        // The person who changes a supplier never approves one, unless they also hold reviewer (then the supplier's own rule decides).
        self::assertSame([], array_intersect(Permissions::MAP['suppliers.manage'], Permissions::MAP['suppliers.approve']));
        self::assertTrue(Permissions::can(['buyer', 'reviewer'], 'suppliers.approve'));
    }

    /** The company details printed on POs (I90, provisional): everyone reads, a reviewer changes and confirms, never admin. */
    public function testTheCompanyDetailsPermissions(): void
    {
        self::assertSame(['reviewer'], Permissions::MAP['company.edit']);
        self::assertSame(['reviewer'], Permissions::MAP['company.confirm']);
        foreach (Permissions::ROLES as $role) {
            self::assertSame($role === 'reviewer', Permissions::can([$role], 'company.edit'), $role);
            self::assertSame($role === 'reviewer', Permissions::can([$role], 'company.confirm'), $role);
            self::assertTrue(Permissions::can([$role], 'reference.view'), "{$role} reads the company details");
        }
        self::assertTrue(Permissions::can(['reviewer', 'mapping_lead'], 'company.edit'), "the owner's roles on staging");
    }

    /** The item card (IM3, I109, provisional): everyone reads cards and barcodes; the catalogue team changes them; never admin. */
    public function testTheItemCardPermissions(): void
    {
        self::assertSame(['mapping_lead', 'stock_controller', 'purchasing_manager'], Permissions::MAP['catalogue.edit']);
        foreach (Permissions::ROLES as $role) {
            self::assertSame(in_array($role, ['mapping_lead', 'stock_controller', 'purchasing_manager'], true), Permissions::can([$role], 'catalogue.edit'), $role);
            self::assertTrue(Permissions::can([$role], 'catalogue.view'), "{$role} reads item cards");
        }
        self::assertFalse(Permissions::can(['admin', 'stock_controller'], 'catalogue.edit'), 'a set breaking the admin rule is read fail-closed');
    }

    public function testAdminNeverPostsReviewsOrDecides(): void
    {
        $sets = [['admin'], ['admin', 'viewer'], ['admin', 'accountant'], ['admin', 'auditor'], ['admin', 'viewer', 'accountant', 'auditor']];
        foreach ($sets as $set) {
            foreach (Permissions::permissionsOf($set) as $perm) {
                self::assertDoesNotMatchRegularExpression('/^(doc\.|mapping\.|documents\.(review|approve)$|suppliers\.(manage|approve)$|reorder\.manage$|company\.|catalogue\.edit$)/',
                    $perm, implode('+', $set));
            }
            self::assertTrue(Permissions::can($set, 'staff.manage'));
            foreach (['suppliers.manage', 'suppliers.approve', 'reorder.manage', 'doc.PO.post', 'company.edit', 'company.confirm', 'catalogue.edit'] as $perm) {
                self::assertFalse(Permissions::can($set, $perm), implode('+', $set) . " never holds {$perm}");
            }
        }
        // The owner's decision of 8 Oct 2026 (Y1): admin also changes settings, rules and lists, reads the audit log and looks at the
        // websites and the safety checks. It overrides I12 for those only: still no doc.*, mapping.*, review or approval.
        self::assertSame(['catalogue.view', 'linking.view', 'staff.view', 'staff.manage', 'reference.view', 'settings.manage', 'audit.view', 'system.view'],
            Permissions::permissionsOf(['admin']));
        self::assertFalse(Permissions::can(['admin'], 'staff.approve'), 'admin never gives the OK of a grant of Admin or Reviewer');
        // A set that breaks the rule (only admin SQL can write one) is read fail-closed: the conflicting roles count for nothing.
        self::assertFalse(Permissions::can(['admin', 'mapper'], 'mapping.decide'));
        self::assertFalse(Permissions::can(['admin', 'reviewer'], 'documents.review'));
        self::assertFalse(Permissions::can(['admin', 'buyer'], 'doc.PO.post'));
        self::assertFalse(Permissions::can(['admin', 'buyer'], 'suppliers.manage'));
        self::assertFalse(Permissions::can(['admin', 'reviewer'], 'suppliers.approve'));
        self::assertFalse(Permissions::can(['admin', 'reviewer'], 'company.edit'), 'admin never changes the company details (I90, I12)');
        self::assertFalse(Permissions::can(['admin', 'reviewer'], 'company.confirm'));
        self::assertTrue(Permissions::can(['admin', 'buyer'], 'staff.manage'));
        self::assertTrue(Permissions::can(['admin', 'auditor', 'buyer'], 'accounts.view'), 'the compatible roles still count');
        self::assertTrue(Permissions::can(['mapper', 'reviewer'], 'mapping.decide'), 'without admin, every role counts');
    }

    /**
     * The set-it-yourself pack (0019; docs/decisions.md Y1): settings, rules and lists are changed by an admin AND a reviewer (the
     * owner and Fazil); the audit log is read by those who check and the admin; the websites and the safety checks are looked at; a
     * grant of Admin or Reviewer is given its OK by a reviewer. The owner's account (Admin + Reviewer + Matching lead) changes
     * settings through its Admin job.
     */
    public function testTheSetItYourselfPermissions(): void
    {
        self::assertSame(['admin', 'reviewer'], Permissions::MAP['settings.manage']);
        self::assertSame(['admin', 'reviewer', 'auditor', 'accountant'], Permissions::MAP['audit.view']);
        self::assertSame(['admin', 'reviewer', 'auditor', 'manager'], Permissions::MAP['system.view']);
        self::assertSame(['reviewer'], Permissions::MAP['staff.approve']);
        self::assertTrue(Permissions::can(['admin', 'mapping_lead', 'reviewer'], 'settings.manage'), 'the owner\'s account, through Admin');
        self::assertTrue(Permissions::can(['admin', 'mapping_lead', 'reviewer'], 'audit.view'));
        self::assertFalse(Permissions::can(['admin', 'mapping_lead', 'reviewer'], 'staff.approve'), 'its Reviewer job is off while it has Admin');
        self::assertTrue(Permissions::can(['mapping_lead', 'reviewer'], 'staff.approve'));
        foreach (['buyer', 'mapper', 'mapping_lead', 'stock_controller', 'auditor', 'accountant', 'viewer', 'manager'] as $role) {
            self::assertFalse(Permissions::can([$role], 'settings.manage'), "{$role} never changes settings");
        }
        self::assertTrue(Permissions::can(['auditor'], 'audit.view'));
        self::assertFalse(Permissions::can(['buyer'], 'audit.view'));
    }

    public function testCheckRoleSet(): void
    {
        foreach ([['admin', 'buyer'], ['admin', 'reviewer'], ['admin', 'mapping_lead'], ['auditor', 'admin', 'stock_controller', 'viewer']] as $set) {
            $e = self::refused(422, 'role_conflict', static fn () => Permissions::checkRoleSet($set));
            self::assertStringStartsWith('admin cannot be combined with ', $e->getMessage());
            self::assertStringContainsString('never posts, reviews or decides', $e->getMessage());
        }
        $e = self::refused(422, 'role_conflict', static fn () => Permissions::checkRoleSet(['admin', 'reviewer', 'buyer', 'viewer']));
        self::assertSame('admin cannot be combined with buyer, reviewer: the person who manages people and roles never posts, reviews or decides', $e->getMessage());
        self::assertSame(['buyer', 'reviewer'], $e->detail['conflict']);
        self::refused(422, 'no_roles', static fn () => Permissions::checkRoleSet([]));
        self::refused(400, 'bad_role', static fn () => Permissions::checkRoleSet(['boss']));
        self::refused(400, 'bad_role', static fn () => Permissions::checkRoleSet(['buyer', 'Buyer']));
        self::refused(400, 'bad_role', static fn () => Permissions::checkRoleSet([1]));
        self::refused(400, 'bad_role', static fn () => Permissions::checkRoleSet(['']));
        self::assertSame(['accountant', 'admin', 'auditor', 'viewer'], Permissions::checkRoleSet(['viewer', 'admin', 'auditor', 'accountant', 'admin']));
        self::assertSame(['buyer', 'reviewer'], Permissions::checkRoleSet(['reviewer', 'buyer', 'reviewer']), 'deduped and sorted');
    }

    /** IM10 (I158): the selling-mode switch, provisional: the desk, the purchasing manager, the stock controller, the manager; never admin. */
    public function testTheSellingModeSwitch(): void
    {
        self::assertSame(['purchasing_desk', 'purchasing_manager', 'stock_controller', 'manager'], Permissions::MAP['modes.set']);
        self::assertFalse(Permissions::can(['admin'], 'modes.set'));
        self::assertFalse(Permissions::can(['buyer'], 'modes.set'));
        self::assertTrue(Permissions::can(['manager'], 'modes.set'));
    }

    public function testAnUnknownPermissionFailsLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permissions::can(['admin'], 'staff.mange');
    }

    /** What the Dashboard's "Coming later" names for each job (the screens not built yet). */
    public function testComingLaterNamesTheScreensNotBuiltYet(): void
    {
        self::assertSame(['invoices', 'returns', 'trade'], Permissions::comingLater(['purchasing_desk']), 'named on the Dashboard; receiving is built');
        self::assertSame([], Permissions::comingLater(['goods_in']));
        self::assertSame([], Permissions::comingLater(['buyer']));
        self::assertSame(['counts', 'adjustments'], Permissions::comingLater(['stock_controller']));
    }

    /** Admin switches the working roles off (I12, read fail-closed): the screens say which, and which page Admin alone keeps from them. */
    public function testSwitchedOffRoles(): void
    {
        self::assertSame(['mapping_lead', 'reviewer'], Permissions::switchedOff(['admin', 'mapping_lead', 'reviewer']), "the owner's staging account");
        self::assertSame([], Permissions::switchedOff(['admin', 'viewer', 'accountant', 'auditor']), 'the compatible roles still work');
        self::assertSame([], Permissions::switchedOff(['mapping_lead', 'reviewer']), 'without admin nothing is off');
        self::assertSame([], Permissions::switchedOff([]));
        self::assertSame(['buyer'], Permissions::switchedOff(['admin', 'auditor', 'buyer']));
        foreach (Permissions::switchedOff(['admin', 'mapping_lead', 'reviewer']) as $off) {
            foreach (Permissions::permissionsOf([$off]) as $perm) {
                if (!Permissions::can(['admin'], $perm)) {
                    self::assertFalse(Permissions::can(['admin', 'mapping_lead', 'reviewer'], $perm), "{$off}: {$perm} is really off");
                }
            }
        }
        self::assertTrue(Permissions::blockedByAdmin(['admin', 'mapping_lead', 'reviewer'], 'documents.review'));
        self::assertTrue(Permissions::blockedByAdmin(['admin', 'mapping_lead', 'reviewer'], 'mapping.approve'));
        self::assertFalse(Permissions::blockedByAdmin(['admin', 'mapping_lead', 'reviewer'], 'linking.view'), 'admin opens it anyway');
        self::assertFalse(Permissions::blockedByAdmin(['admin', 'mapping_lead', 'reviewer'], 'doc.PO.post'), 'none of the roles would hold it');
        self::assertFalse(Permissions::blockedByAdmin(['buyer'], 'documents.review'));
    }

    private static function refused(int $status, string $code, callable $fn): CwException
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame([$status, $code], [$e->httpStatus, $e->errorCode], $e->getMessage());
            return $e;
        }
        self::fail("expected {$status} {$code}");
    }
}
