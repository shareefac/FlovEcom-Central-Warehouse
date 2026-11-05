<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0062 (a supplier's details are not needed unless switched on; docs/decisions.md SD1), applied to a scratch schema: the four switches
 * are added off and agreed with their baselines, no supplier row changes, and a second run changes nothing.
 */
final class Migration0062Test extends IntegrationTestCase
{
    private const FILE = '0062_supplier_details_optional.sql';
    private const KEYS = ['suppliers.need_address', 'suppliers.need_contact', 'suppliers.need_due_diligence', 'suppliers.need_payment_terms'];
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m62', $this->dir);
        }
    }

    public function testTheSwitchesAreAddedOffOnceAndNothingElseMoves(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bALTER\s+TABLE\b|\bDROP\b|\bUPDATE\b/i', implode(";\n", $statements),
            'nothing removed or rewritten, no table changed');

        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m62', '0061_reorder_buy_for.sql');
        $before = $db->all('SELECT * FROM supplier ORDER BY id');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $rows = $db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting "
            . "WHERE setting_key LIKE 'suppliers.need\\_%' ORDER BY setting_key");
        self::assertSame(self::KEYS, array_column($rows, 'setting_key'));
        foreach ($rows as $r) {
            self::assertSame(['bool', 'false', 0, 'SD1'], [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision']]);
        }
        self::assertSame(4, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key LIKE 'suppliers.need\\_%' "
            . "AND version = 1 AND action = 'baseline' AND actor = 'system:migrate'"));
        self::assertSame($before, $db->all('SELECT * FROM supplier ORDER BY id'), 'no supplier changed');

        $snapshot = [$db->all("SELECT * FROM app_setting WHERE setting_key LIKE 'suppliers.need\\_%' ORDER BY setting_key"), $db->all('SELECT * FROM config_change ORDER BY id')];
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame($snapshot, [$db->all("SELECT * FROM app_setting WHERE setting_key LIKE 'suppliers.need\\_%' ORDER BY setting_key"),
            $db->all('SELECT * FROM config_change ORDER BY id')], 'a second run adds nothing');
    }
}
