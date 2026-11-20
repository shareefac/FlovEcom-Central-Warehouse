<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Staff;

use CW\Auth\Permissions;
use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0069 (the People manager job; docs/decisions.md PM1-PM7), applied to a scratch schema migrated to 0068: one value added at the END of
 * staff_role.role, so every grant keeps its job (the stored numbers do not move), the new job can be granted, nothing else changes (no
 * row written, the permission rows of 0034 left as they are) and a second run changes nothing.
 */
final class Migration0069Test extends IntegrationTestCase
{
    private const FILE = '0069_people_manager.sql';
    /** The jobs later migrations add after people_manager, each at the end again: 0074's finance (docs/decisions.md PR23, PR64). */
    private const LATER = ['finance'];
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m69', $this->dir);
        }
    }

    public function testThePeopleManagerJobIsAddedAtTheEndAndNothingElseMoves(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 3) . '/migrations/' . self::FILE));
        self::assertCount(1, $statements);
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+staff_role\s+MODIFY\s+COLUMN\s+role\s+ENUM\(/i', $statements[0]);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\b|\bDROP\b|\bUPDATE\b|\bINSERT\b/i', $statements[0]);

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m69', '0068_listing_selling.sql');
        $a = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m69a', 'M67 A', 'm69a@test.invalid', 'x')");
        $b = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m69b', 'M67 B', 'm69b@test.invalid', 'x')");
        foreach (array_slice(Permissions::ROLES, 0, 14) as $role) {
            $db->exec('INSERT INTO staff_role (staff_user_id, role, granted_by) VALUES (?, ?, ?)', [$a, $role, $b]);
        }
        $db->exec("UPDATE staff_role SET revoked_at = NOW(6), revoked_by = ? WHERE staff_user_id = ? AND role IN ('admin', 'auditor')", [$b, $a]);
        self::assertSame(1265, self::mysqlError(static fn () => $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'people_manager')", [$b])),
            'not a job before 0069');
        $grants = $db->all('SELECT id, staff_user_id, CAST(role AS CHAR) AS role, role + 0 AS n, granted_by, granted_at, revoked_by, revoked_at, active_staff_user_id '
            . 'FROM staff_role ORDER BY id');
        $rows = $db->all('SELECT permission, roles FROM role_permission ORDER BY permission');

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame(array_slice(Permissions::ROLES, 0, 15), self::enum($db), 'the jobs of the code up to people_manager, in its order: people_manager last');
        self::assertSame(self::LATER, array_slice(Permissions::ROLES, 15), 'after it in the code only the jobs later migrations append at the end');
        self::assertSame('NO', $db->value("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_role' "
            . "AND COLUMN_NAME = 'role'"));
        self::assertSame($grants, $db->all('SELECT id, staff_user_id, CAST(role AS CHAR) AS role, role + 0 AS n, granted_by, granted_at, revoked_by, revoked_at, '
            . 'active_staff_user_id FROM staff_role ORDER BY id'), 'every grant keeps its job and its stored number');
        self::assertSame($rows, $db->all('SELECT permission, roles FROM role_permission ORDER BY permission'), 'the permission rows are left as they are');
        $db->exec("INSERT INTO staff_role (staff_user_id, role, granted_by) VALUES (?, 'people_manager', ?)", [$b, $a]);
        self::assertSame(15, (int) $db->value("SELECT role + 0 FROM staff_role WHERE role = 'people_manager'"));
        self::assertSame(1062, self::mysqlError(static fn () => $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'people_manager')", [$b])),
            'one live grant per person and job, as for every job');
        self::assertSame(1265, self::mysqlError(static fn () => $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'boss')", [$b])));

        $snapshot = $db->all('SELECT id, CAST(role AS CHAR) AS role, role + 0 AS n FROM staff_role ORDER BY id');
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame(array_slice(Permissions::ROLES, 0, 15), self::enum($db));
        self::assertSame($snapshot, $db->all('SELECT id, CAST(role AS CHAR) AS role, role + 0 AS n FROM staff_role ORDER BY id'), 'a second run changes nothing');
    }

    /** @return list<string> */
    private static function enum(Db $db): array
    {
        $type = (string) $db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_role' AND COLUMN_NAME = 'role'");
        preg_match_all("/'([^']*)'/", $type, $m);
        return $m[1];
    }
}
