<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ApiCredentials;
use CW\Db;
use CW\Invoices\ErpApiReader;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Staff\SecretBox;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0063 (Settings › APIs; docs/decisions.md AK3), applied to a scratch schema: api_credential with its one row (erp_read, empty, version 0,
 * made by system:migrate); a row is whole or empty, its address https://<a name>, its last 4 letters and digits, its sealed values longer
 * than a nonce and a tag, its actors never empty (the CHECKs refuse anything else); the app login may only UPDATE a row's save columns
 * (no INSERT, no DELETE, never its name or who made it); nothing existing is touched; a second run, or one stopped between its two
 * statements, adds nothing twice. Before 0063 the reader and the service read "nothing saved" (no table).
 */
final class Migration0063Test extends IntegrationTestCase
{
    private const FILE = '0063_api_credentials.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m63', $this->dir);
            $this->dir = null;
        }
        parent::tearDown();
    }

    public function testTheTableItsOneRowItsChecksAndASecondRun(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        self::assertCount(2, $statements);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bALTER\s+TABLE\b|\bDROP\b|\bUPDATE\b/i', implode(";\n", $statements),
            'nothing removed or rewritten, no table changed');

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m63', '0062_supplier_details_optional.sql');
        // Before 0063: nothing saved (no table), the reader reads app.env as before.
        self::assertFalse(ErpApiReader::hasSaved($db));
        self::assertNull(ApiCredentials::open($db, 'erp_read', SecretBox::fromBase64(SecretBox::newKeyBase64())));
        $tables = $db->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame(['api_credential'], array_values(array_diff($db->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"), $tables)),
            'one table more');
        self::assertSame([], array_values(array_diff($tables, $db->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"))));

        $row = $db->one("SELECT * FROM api_credential");
        self::assertSame(['erp_read', null, null, null, null, 0, 'system:migrate', null, 'system:migrate'], [$row['name'], $row['url'], $row['key_enc'], $row['key_last4'],
            $row['secret_enc'], (int) $row['version'], $row['created_actor'], $row['updated_by'], $row['updated_actor']]);
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM api_credential'));
        self::assertSame(ApiCredentials::NAMES, $db->column('SELECT name FROM api_credential ORDER BY name'), 'a row for every API the code knows');
        self::assertFalse(ErpApiReader::hasSaved($db));
        $cols = $db->column("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_credential' ORDER BY ORDINAL_POSITION");
        self::assertSame(['name', 'url', 'key_enc', 'key_last4', 'secret_enc', 'version', 'created_actor', 'created_at', 'updated_by', 'updated_actor', 'updated_at'], $cols);
        self::assertSame(['varbinary(255)', 'varbinary(255)'], $db->column("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'api_credential' AND COLUMN_NAME IN ('key_enc', 'secret_enc') ORDER BY COLUMN_NAME"));

        // The CHECKs: whole or empty, a name-only https address, letters and digits, sealed bytes, actors.
        $sealed = str_repeat("\x01", 41);
        $ok = "UPDATE api_credential SET url = 'https://vpg-erp.floverfy.com', key_enc = ?, key_last4 = 'ABCD', secret_enc = ? WHERE name = 'erp_read'";
        $db->exec($ok, [$sealed, $sealed]);
        foreach ([
            "UPDATE api_credential SET secret_enc = NULL WHERE name = 'erp_read'",
            "UPDATE api_credential SET url = NULL WHERE name = 'erp_read'",
            "UPDATE api_credential SET key_last4 = NULL WHERE name = 'erp_read'",
            "UPDATE api_credential SET url = 'http://vpg-erp.floverfy.com' WHERE name = 'erp_read'",
            "UPDATE api_credential SET url = 'https://vpg-erp.floverfy.com:8443' WHERE name = 'erp_read'",
            "UPDATE api_credential SET url = 'https://vpg-erp.floverfy.com/api' WHERE name = 'erp_read'",
            "UPDATE api_credential SET url = 'https://VPG-ERP.floverfy.com' WHERE name = 'erp_read'",
            "UPDATE api_credential SET key_last4 = 'AB:D' WHERE name = 'erp_read'",
            "UPDATE api_credential SET key_enc = 'short' WHERE name = 'erp_read'",
            "UPDATE api_credential SET updated_actor = '' WHERE name = 'erp_read'",
            "INSERT INTO api_credential (name, created_actor, updated_actor) VALUES ('Bad Name', 'x', 'x')",
        ] as $sql) {
            self::assertSame(3819, self::mysqlError(static fn () => $db->exec($sql)), $sql);
        }
        self::assertSame(1452, self::mysqlError(static fn () => $db->exec("UPDATE api_credential SET updated_by = 999999999 WHERE name = 'erp_read'")), 'a staff member');
        $db->exec("UPDATE api_credential SET url = NULL, key_enc = NULL, key_last4 = NULL, secret_enc = NULL WHERE name = 'erp_read'");

        // A second run (or one stopped after the table): nothing added, nothing changed.
        $snapshot = $db->all('SELECT * FROM api_credential');
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        $db->exec(end($statements));
        self::assertSame($snapshot, $db->all('SELECT * FROM api_credential'));
    }

    public function testTheAppLoginOnlyUpdatesARowsSaveColumns(): void
    {
        self::assertSame(['Select'], Grants::desired('api_credential'), 'no INSERT, no DELETE: the rows come by migration');
        self::assertSame(['url', 'key_enc', 'key_last4', 'secret_enc', 'version', 'updated_by', 'updated_actor', 'updated_at'],
            array_keys(Grants::desiredColumns('api_credential')));
        foreach (Grants::desiredColumns('api_credential') as $col => $privs) {
            self::assertSame(['Update'], $privs, $col);
        }
        self::assertNotContains('api_credential', Grants::NO_DELETE);
        self::assertNotContains('api_credential', Grants::APPEND_ONLY);
        self::assertArrayNotHasKey('api_credential', Grants::INSERT_COLUMNS);
    }
}
