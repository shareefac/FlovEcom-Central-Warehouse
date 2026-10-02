<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\CwException;
use CW\Mapping\DecisionService;
use CW\Ui\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The one permission map (I11, I12, I14, I16): every role and permission it names exists, every menu item points
 * at a real route or a phase, admin never posts, reviews or decides, and the three people of the owner's
 * acceptance test (buyer, purchasing desk, reviewer) see three different menus.
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

    public function testEveryMenuItemIsAPermittedLiveRouteOrAPhase(): void
    {
        $kernel = new Kernel(static fn (): never => throw new \RuntimeException('no database'), static fn (): ?string => null, static function (): void {
        });
        $gets = [];
        foreach ($kernel->router()->routes() as $route) {
            if ($route->method === 'GET') {
                $gets[] = $route->pattern;
            }
        }
        $keys = [];
        foreach (Permissions::MENU as $section) {
            self::assertNotSame('', $section['section']);
            self::assertNotSame([], $section['items'], $section['section']);
            foreach ($section['items'] as $item) {
                $what = "{$section['section']} / {$item['label']}";
                self::assertArrayHasKey($item['perm'], Permissions::MAP, $what);
                self::assertSame([], array_diff(array_keys($item), ['label', 'perm', 'key', 'path', 'query', 'phase', 'badge']), $what);
                self::assertTrue(isset($item['path']) xor isset($item['phase']), "{$what}: a path or a phase, never both");
                if (isset($item['path'])) {
                    self::assertContains($item['path'], $gets, "{$what}: a real GET route");
                    self::assertArrayHasKey('key', $item, $what);
                    $keys[] = $item['key'];
                } else {
                    self::assertMatchesRegularExpression('/^I-\d$/D', $item['phase'], $what);
                    self::assertArrayNotHasKey('query', $item, $what);
                    self::assertArrayNotHasKey('badge', $item, $what);
                }
                if (isset($item['badge'])) {
                    self::assertContains($item['badge'], ['linking_pending', 'reviews_open'], "{$what}: a count Ui\\Context::badges() computes");
                }
            }
        }
        self::assertSame(array_values(array_unique($keys)), $keys, 'a key marks one item');
        self::assertSame(['Linking', 'Items', 'Purchasing', 'Receiving', 'Stock control', 'Trade', 'Document reviews', 'Documents', 'Accounts',
            'Reference', 'Admin'], array_column(Permissions::MENU, 'section'));
    }

    public function testAdminNeverPostsReviewsOrDecides(): void
    {
        $sets = [['admin'], ['admin', 'viewer'], ['admin', 'accountant'], ['admin', 'auditor'], ['admin', 'viewer', 'accountant', 'auditor']];
        foreach ($sets as $set) {
            foreach (Permissions::permissionsOf($set) as $perm) {
                self::assertDoesNotMatchRegularExpression('/^(doc\.|mapping\.|documents\.(review|approve)$)/', $perm, implode('+', $set));
            }
            self::assertTrue(Permissions::can($set, 'staff.manage'));
        }
        self::assertSame(['catalogue.view', 'linking.view', 'staff.view', 'staff.manage', 'reference.view'], Permissions::permissionsOf(['admin']));
        // A set that breaks the rule (only admin SQL can write one) is read fail-closed: the conflicting roles count for nothing.
        self::assertFalse(Permissions::can(['admin', 'mapper'], 'mapping.decide'));
        self::assertFalse(Permissions::can(['admin', 'reviewer'], 'documents.review'));
        self::assertFalse(Permissions::can(['admin', 'buyer'], 'doc.PO.post'));
        self::assertTrue(Permissions::can(['admin', 'buyer'], 'staff.manage'));
        self::assertTrue(Permissions::can(['admin', 'auditor', 'buyer'], 'accounts.view'), 'the compatible roles still count');
        self::assertTrue(Permissions::can(['mapper', 'reviewer'], 'mapping.decide'), 'without admin, every role counts');
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

    public function testAnUnknownPermissionFailsLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permissions::can(['admin'], 'staff.mange');
    }

    /** The owner's acceptance test (I-1 §4.4), on the map: buyer, desk and reviewer see three different menus. */
    public function testBuyerDeskAndReviewerSeeDifferentMenus(): void
    {
        $sections = static fn (array $roles): array => array_column(Permissions::menu($roles), 'section');
        self::assertSame(['Items', 'Purchasing', 'Documents', 'Reference'], $sections(['buyer']));
        self::assertSame(['Items', 'Receiving', 'Trade', 'Documents', 'Reference'], $sections(['purchasing_desk']));
        self::assertSame(['Items', 'Document reviews', 'Documents', 'Reference'], $sections(['reviewer']));
        self::assertSame(['Linking', 'Items', 'Reference', 'Admin'], $sections(['admin']));
        self::assertSame(['Linking', 'Items', 'Documents', 'Accounts', 'Reference', 'Admin'], $sections(['auditor']));
        self::assertSame(['Linking', 'Items', 'Purchasing', 'Receiving', 'Trade', 'Document reviews', 'Documents', 'Reference'],
            $sections(['mapper', 'purchasing_manager', 'reviewer']), 'several roles: the union, in menu order');
        self::assertSame([], Permissions::menu([]), 'no roles, no menu');

        $buyer = Permissions::menu(['buyer']);
        self::assertSame(['Suppliers', 'Purchase orders', 'Reorder list'], array_column($buyer[1]['items'], 'label'));
        self::assertSame(['I-2', 'I-2', 'I-2'], array_column($buyer[1]['items'], 'phase'));
        $desk = Permissions::menu(['purchasing_desk']);
        self::assertSame(['Receive + invoice', 'Supplier invoices', 'Supplier returns'], array_column($desk[1]['items'], 'label'));
        self::assertSame(['I-3', 'I-4', 'I-4'], array_column($desk[1]['items'], 'phase'));
        $mapper = Permissions::menu(['mapper']);
        self::assertSame(['/ui/', '/ui/review', '/ui/review'], array_column($mapper[0]['items'], 'path'));
        self::assertSame([['queue' => 'Key'], ['queue' => 'pending']], array_column($mapper[0]['items'], 'query'));
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
