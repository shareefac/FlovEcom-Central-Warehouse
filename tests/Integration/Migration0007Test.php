<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Tests\Support\MigrationFixture;
use PHPUnit\Framework\TestCase;

/**
 * I10: 0007 gives every existing person the role they had as their first staff_role grant (granted_by NULL,
 * granted at their creation), writes one `staff.roles` audit row each, and drops staff_user.role. Runs on a scratch
 * schema migrated up to 0006.
 */
final class Migration0007Test extends TestCase
{
    private const SUFFIX = 'm7';

    private ?string $dir = null;

    protected function setUp(): void
    {
        if (getenv('CW_TEST_DB') === '0') {
            self::markTestSkipped('CW_TEST_DB=0: database tests disabled');
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop(self::SUFFIX, $this->dir);
        }
    }

    public function testEveryPersonKeepsTheirRoleAsAGrantAndTheColumnGoes(): void
    {
        [$db, $this->dir] = MigrationFixture::upTo(self::SUFFIX, '0006_value_core.sql');
        self::assertNull($db->value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_role'"));
        $people = ['viewer' => '2026-09-30 10:00:00.000001', 'admin' => '2026-09-30 11:00:00.000002', 'mapping_lead' => '2026-10-01 09:30:00.000003'];
        $ids = [];
        foreach ($people as $role => $created) {
            $ids[$role] = $db->insert(
                "INSERT INTO staff_user (username, display_name, email, role, password_hash, is_active, created_at) VALUES (?, ?, ?, ?, 'x', ?, ?)",
                ["m7-{$role}", "M7 {$role}", "m7-{$role}@test.invalid", $role, $role === 'viewer' ? 0 : 1, $created],
            );
        }

        self::assertSame(['0007_staff_roles.sql'], MigrationFixture::migrateRest($db, $this->dir, '0007_staff_roles.sql'));

        $rows = $db->all('SELECT staff_user_id, CAST(role AS CHAR) AS role, granted_by, granted_at, revoked_at, revoked_by, active_staff_user_id FROM staff_role ORDER BY id');
        self::assertCount(3, $rows);
        foreach ($rows as $i => $r) {
            $role = array_keys($people)[$i];
            self::assertSame([$ids[$role], $role, null, $people[$role], null, null, $ids[$role]],
                [(int) $r['staff_user_id'], $r['role'], $r['granted_by'], (string) $r['granted_at'], $r['revoked_at'], $r['revoked_by'], (int) $r['active_staff_user_id']],
                "{$role}: one live grant, given by the migration at the person's creation (an inactive person keeps theirs too)");
        }
        $audit = $db->all("SELECT actor, entity_type, entity_id, detail FROM audit_log WHERE action = 'staff.roles' ORDER BY id");
        self::assertCount(3, $audit, 'one audit row each');
        foreach ($audit as $i => $a) {
            $role = array_keys($people)[$i];
            self::assertSame(['system:migrate', 'staff_user', (string) $ids[$role]], [$a['actor'], $a['entity_type'], $a['entity_id']]);
            self::assertEquals(['migration' => '0007_staff_roles.sql', 'roles' => [$role]], json_decode((string) $a['detail'], true));
        }
        self::assertNull($db->value("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_user' AND COLUMN_NAME = 'role'"),
            'staff_user.role is gone: code still reading it fails loudly (I10)');
        self::assertSame(1054, self::mysqlError(static fn () => $db->value('SELECT role FROM staff_user LIMIT 1')));
        // The roles the old ENUM did not have can be granted now.
        $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'reviewer')", [$ids['admin']]);
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM staff_role WHERE staff_user_id = ?', [$ids['admin']]));
    }

    private static function mysqlError(callable $fn): int
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            return (int) Db::driverCode($e);
        }
        self::fail('expected a MySQL error');
    }
}
