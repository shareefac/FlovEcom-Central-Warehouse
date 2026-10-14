<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Pack E (docs/decisions.md PE2-PE8): the permission map kept in the database (role_permission, 0034) and the rules that hold
 * whatever the table says. No database here: the stored map is handed to Permissions::useStored as Auth\RoleMap would hand it.
 * The service that changes it on the screen and its guards against a real database: Integration\Admin\RolePermissionsTest.
 */
final class RoleMapTest extends TestCase
{
    protected function tearDown(): void
    {
        Permissions::useStored(null);
        parent::tearDown();
    }

    /** @return array<string, list<string>> the rows 0034 seeds: permission => jobs */
    private static function seed(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0034_reports_roles.sql');
        self::assertSame(1, preg_match('/INSERT INTO role_permission \(permission, roles, updated_actor\) VALUES\s*(.*?);/s', $sql, $m));
        self::assertGreaterThan(0, preg_match_all("/\\('([A-Za-z.]+)','([a-z_,]*)','system:migrate'\\)/", $m[1], $rows, PREG_SET_ORDER));
        $out = [];
        foreach ($rows as $r) {
            self::assertArrayNotHasKey($r[1], $out, "{$r[1]} seeded twice");
            $out[$r[1]] = $r[2] === '' ? [] : explode(',', $r[2]);
        }
        return $out;
    }

    /**
     * Nothing changes on deploy: 0034 copies the code's map exactly (every permission it seeds holds the jobs MAP gives it, in the
     * canonical order), and every permission of this commit is seeded. A permission the code adds later has no row and reads as MAP.
     */
    public function testTheSeedIsTheCodeMapExactly(): void
    {
        $seed = self::seed();
        foreach ($seed as $perm => $jobs) {
            self::assertArrayHasKey($perm, Permissions::MAP, "{$perm}: seeded, but the code has no such permission");
            self::assertSame(Permissions::ordered(Permissions::MAP[$perm]), $jobs, "{$perm}: the seed must be the code's map, in ROLES order");
            self::assertEqualsCanonicalizing(Permissions::MAP[$perm], $jobs, $perm);
        }
        foreach (['catalogue.view', 'staff.manage', 'settings.manage', 'staff.approve', 'documents.review', 'doc.PO.post', 'reports.view', 'values.view'] as $perm) {
            self::assertArrayHasKey($perm, $seed, "{$perm} is seeded");
        }
        self::assertCount(56, $seed, 'every permission of the code when 0034 was written (packs A2a, B, C, D, F and E)');
        // The seeded map, put in force, is the code's map: can() answers exactly as before for every job and permission.
        Permissions::useStored($seed);
        foreach (Permissions::MAP as $perm => $holders) {
            foreach (Permissions::ROLES as $role) {
                self::assertSame(in_array($role, $holders, true), Permissions::can([$role], $perm), "{$role} {$perm}");
            }
        }
    }

    /** The code's own map obeys the rules the screen and the read enforce (a permission a later change adds must too). */
    public function testTheCodeMapObeysTheRules(): void
    {
        foreach (Permissions::MAP as $perm => $holders) {
            self::assertSame(Permissions::ordered($holders), Permissions::sanitise($perm, $holders), "{$perm}: the code's holders pass the read unchanged");
            $p = Permissions::problems($perm, $holders);
            self::assertTrue($p === [] || $p === ['locked'], "{$perm}: " . implode(', ', $p));
            if (!Permissions::isReadOnly($perm) && !in_array($perm, Permissions::LOCKED, true)) {
                self::assertSame([], array_values(array_intersect($holders, ['admin', ...Permissions::ADMIN_COMPATIBLE])), "{$perm}: admin and the look-only jobs never act");
            }
            self::assertArrayHasKey($perm, Words::PERMISSION, "{$perm} has words on the screen");
        }
        foreach (Permissions::LOCKED as $perm) {
            self::assertArrayHasKey($perm, Permissions::MAP, $perm);
        }
        foreach (Permissions::REQUIRED as $perm => $jobs) {
            self::assertArrayHasKey($perm, Permissions::MAP, $perm);
            self::assertSame([], array_values(array_diff($jobs, Permissions::MAP[$perm])), "{$perm}: the code already gives it to them");
            self::assertNotContains($perm, Permissions::LOCKED, 'a required permission is one that can be edited around its guarded jobs');
        }
        // The security core is locked; the acting permissions admin holds are exactly the locked ones.
        foreach (['staff.manage', 'settings.manage', 'staff.approve', 'catalogue.view', 'reference.view'] as $perm) {
            self::assertContains($perm, Permissions::LOCKED, $perm);
        }
        foreach (Permissions::permissionsOf(['admin']) as $perm) {
            self::assertTrue(Permissions::isReadOnly($perm) || in_array($perm, Permissions::LOCKED, true), "admin acts only through a locked permission: {$perm}");
        }
        foreach (Permissions::ADMIN_COMPATIBLE as $role) {
            foreach (Permissions::permissionsOf([$role]) as $perm) {
                self::assertTrue(Permissions::isReadOnly($perm), "{$role} only looks: {$perm}");
            }
        }
    }

    /** What the table says is never more than the rules allow (read fail-closed), and a missing row reads as the code. */
    public function testTheStoredMapIsReadFailClosed(): void
    {
        $stored = [];
        foreach (Permissions::MAP as $perm => $holders) {
            $stored[$perm] = $holders;
        }
        unset($stored['incidents.resolve']); // a permission without a row (the code added it later): MAP's holders
        $stored['made.up'] = ['buyer']; // a row for a permission the code does not have: ignored
        $stored['staff.manage'] = ['admin', 'buyer']; // a locked one: the code wins
        $stored['settings.manage'] = ['buyer'];
        $stored['staff.approve'] = ['admin', 'reviewer', 'buyer'];
        $stored['doc.ADJ.post'] = ['stock_controller', 'admin', 'auditor', 'viewer', 'accountant']; // admin and look-only jobs never act
        $stored['documents.review'] = []; // a guarded job's own: always there
        $stored['staff.view'] = ['auditor'];
        $stored['reorder.view'] = ['buyer', 'boss', '', 'buyer']; // an unknown job is dropped, a doubled one counted once
        $stored['audit.view'] = ['admin', 'auditor', 'viewer']; // read-only: admin and the look-only jobs may look
        $stored['doc.WO.post'] = []; // nobody (only possible by SQL): fail closed
        Permissions::useStored($stored);

        self::assertSame(array_keys(Permissions::MAP), array_keys(Permissions::map()), 'every permission of the code, in its order, and nothing else');
        self::assertSame(Permissions::ordered(Permissions::MAP['incidents.resolve']), Permissions::holders('incidents.resolve'));
        self::assertSame(['admin'], Permissions::holders('staff.manage'));
        self::assertSame(['admin', 'reviewer'], Permissions::holders('settings.manage'));
        self::assertSame(['reviewer'], Permissions::holders('staff.approve'));
        self::assertSame(['stock_controller'], Permissions::holders('doc.ADJ.post'));
        self::assertSame(['reviewer'], Permissions::holders('documents.review'));
        self::assertSame(['admin', 'auditor'], Permissions::holders('staff.view'));
        self::assertSame(['buyer'], Permissions::holders('reorder.view'));
        self::assertSame(['viewer', 'admin', 'auditor'], Permissions::holders('audit.view'));
        self::assertSame([], Permissions::holders('doc.WO.post'));
        self::assertFalse(Permissions::can(['admin'], 'doc.ADJ.post'));
        self::assertFalse(Permissions::can(['auditor'], 'doc.ADJ.post'));
        self::assertFalse(Permissions::can(['buyer'], 'staff.manage'));
        self::assertFalse(Permissions::can(['buyer'], 'settings.manage'));
        self::assertFalse(Permissions::can(['stock_controller'], 'doc.WO.post'));
        self::assertFalse(Permissions::can(['purchasing_manager'], 'reorder.view'), 'taken away by the stored map');
        self::assertTrue(Permissions::can(['reviewer'], 'documents.review'));
        self::assertTrue(Permissions::can(['viewer'], 'audit.view'));
        try {
            Permissions::can(['buyer'], 'made.up');
            self::fail('a permission the code does not have is still unknown');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame('Buyers', Words::whoCan('reorder.view'), 'the words follow the map in force');

        Permissions::useStored(null);
        self::assertSame(Permissions::defaults(), Permissions::map());
        self::assertTrue(Permissions::can(['purchasing_manager'], 'reorder.view'), 'back to the code');
        self::assertSame('Buyers, Purchasing managers, Reviewers, Auditors and Stock managers', Words::whoCan('reorder.view'), 'the code\'s order, as before');
        self::assertSame('Goods in staff, Purchasing desk staff and Purchasing managers', Words::whoCan('doc.GRN.post'));
        Permissions::useStored(['doc.GRN.post' => ['purchasing_manager', 'stock_controller', 'goods_in']] + Permissions::MAP);
        self::assertSame('Goods in staff, Purchasing managers and Stock controllers', Words::whoCan('doc.GRN.post'), 'the code\'s order, then the added job');
    }

    /** The refusals of the screen, in order (Admin\RolePermissions turns each into its error). */
    public function testWhatTheScreenRefuses(): void
    {
        self::assertSame(['unknown_permission'], Permissions::problems('made.up', ['buyer']));
        foreach (Permissions::LOCKED as $perm) {
            self::assertSame(['locked'], Permissions::problems($perm, Permissions::MAP[$perm]), $perm);
        }
        self::assertSame(['bad_role'], Permissions::problems('reorder.view', ['buyer', 'boss']));
        self::assertSame(['bad_role'], Permissions::problems('reorder.view', [1]));
        self::assertSame(['no_holders'], Permissions::problems('reorder.view', []));
        self::assertSame(['no_holders', 'required'], Permissions::problems('documents.review', []));
        foreach (['admin', 'viewer', 'accountant', 'auditor'] as $job) {
            self::assertSame(['acting_job'], Permissions::problems('doc.PO.post', ['buyer', $job]), $job);
            self::assertSame([], Permissions::problems('reorder.view', ['buyer', $job]), "{$job} may look");
        }
        self::assertSame(['required'], Permissions::problems('staff.view', ['auditor']));
        self::assertSame(['required'], Permissions::problems('documents.approve', ['buyer']));
        self::assertSame(['acting_job', 'required'], Permissions::problems('suppliers.approve', ['admin']));
        self::assertSame([], Permissions::problems('suppliers.approve', ['reviewer', 'purchasing_manager']));
        self::assertTrue(Permissions::isReadOnly('reports.view'));
        self::assertFalse(Permissions::isReadOnly('reports.manage'));
        self::assertFalse(Permissions::isReadOnly('doc.PO.post'));
        self::assertSame(['viewer', 'admin', 'reviewer'], Permissions::ordered(['reviewer', 'admin', 'viewer', 'admin', 'boss']));
    }

    /** The two report permissions (PE1): read only, never admin, the money one narrower. */
    public function testTheReportPermissions(): void
    {
        self::assertFalse(Permissions::can(['admin'], 'reports.view'));
        self::assertFalse(Permissions::can(['admin'], 'values.view'));
        self::assertFalse(Permissions::can(['viewer'], 'reports.view'));
        self::assertTrue(Permissions::can(['reviewer'], 'values.view'));
        self::assertTrue(Permissions::can(['accountant'], 'values.view'));
        self::assertFalse(Permissions::can(['buyer'], 'values.view'), 'money: fewer jobs');
        self::assertTrue(Permissions::can(['buyer'], 'reports.view'));
        self::assertSame([], array_values(array_diff(Permissions::MAP['values.view'], Permissions::MAP['reports.view'])), 'who sees values sees the other reports');
    }
}
