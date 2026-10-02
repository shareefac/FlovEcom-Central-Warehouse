<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Caller;
use CW\Db;
use CW\Schema\Grants;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW\Settings and bin/settings.php (I38-I41): typed values, the company block, an unknown key is a programming error;
 * the CLI changes a value only with the admin login (audited setting.change), refuses without --admin (exit 1) and a
 * bad value (exit 2); the app login cannot write the table (1142). Every test restores what it changed (app_setting
 * is a seed table: TestDb::clean keeps it).
 */
final class SettingsTest extends IntegrationTestCase
{
    private const DENIED = 1142;

    /** @var array<string, string> setting_key => value_json before the test */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional FROM app_setting') as $r) {
            $this->saved[(string) $r['setting_key']] = (string) $r['v'] . "\0" . $r['provisional'];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            [$json, $prov] = explode("\0", $v);
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = 'system:migrate' WHERE setting_key = ?",
                [$json, (int) $prov, $k]);
        }
    }

    /** @return array{code: int, out: string, err: string} */
    private static function cli(string ...$args): array
    {
        $root = dirname(__DIR__, 2);
        $p = proc_open([PHP_BINARY, "{$root}/bin/settings.php", '--db=' . TestDb::name(), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    public function testTypedValuesAndTheCompany(): void
    {
        $s = new Settings(self::$db);
        self::assertSame(3, $s->get('suppliers.approval_due_days'));
        self::assertTrue($s->get('suppliers.change_review'));
        self::assertFalse($s->get('costs.site_writeback'));
        self::assertSame('', $s->get('company.legal_name'));
        self::assertSame(['legal_name' => '', 'trading_name' => '', 'address' => '', 'company_number' => '', 'vat_number' => '', 'phone' => '', 'email' => '',
            'delivery_address' => '', 'confirmed' => false], $s->company(), 'empty placeholders until the owner provides them (decision 9)');
        self::assertCount((int) self::$db->value('SELECT COUNT(*) FROM app_setting'), $s->all(), 'every row (0009: 12; 0010 adds po.*)');
        self::assertSame(['key' => 'company.confirmed', 'type' => 'bool', 'value' => false, 'display' => 'false', 'provisional' => true, 'decision' => '9'],
            array_slice($s->all()[2], 0, 6));
        self::assertTrue($s->has('company.email'));
        self::assertTrue($s->has('po.terms'), 'the pos task (0010) adds it');
        self::assertSame('S', $s->get('po.default_vat_code'));
        self::assertSame(10, $s->get('po.over_delivery_tolerance_pct'));
        self::assertFalse($s->has('company.bank_account'));

        // Rows are read once per instance: a change shows in a new instance.
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"Acme Vapes Ltd\"' AS JSON) WHERE setting_key = 'company.legal_name'");
        self::assertSame('', $s->get('company.legal_name'));
        self::assertSame('Acme Vapes Ltd', (new Settings(self::$db))->company()['legal_name']);
        // A seeded JSON number of a decimal setting reads through its text, never as a float.
        self::$db->exec("INSERT INTO app_setting (setting_key, value_type, value_json, description) VALUES ('test.weight', 'decimal', CAST('0.50' AS JSON), 'x')");
        try {
            self::assertSame('0.5', (new Settings(self::$db))->get('test.weight'));
        } finally {
            self::$db->exec("DELETE FROM app_setting WHERE setting_key = 'test.weight'");
        }
    }

    public function testAnUnknownKeyIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        (new Settings(self::$db))->get('company.bank_account');
    }

    public function testTheRules(): void
    {
        $s = new Settings(self::$db);
        $s->checkRule('suppliers.approval_due_days', 120);
        $s->checkRule('po.default_vat_code', 'RC');
        $s->checkRule('reorder.short_weight', '1.0');
        $s->checkRule('reorder.default_lead_days', 0);
        $s->checkRule('company.email', 'buying@example.co.uk');
        $s->checkRule('company.email', '');
        // The reorder windows against each other (review nit, I82): short 28, long 91, fewest valid days 7 and 21.
        $s->checkRule('reorder.min_valid_days_short', 28);
        $s->checkRule('reorder.short_window_days', 91);
        $s->checkRule('reorder.long_window_days', 28);
        foreach ([['suppliers.approval_due_days', 0], ['suppliers.approval_due_days', 121], ['po.default_vat_code', 'XX'], ['reorder.short_weight', '1.01'],
            ['reorder.anything_days', 121], ['company.email', 'not an address'], ['reorder.min_valid_days_short', -5], ['reorder.min_valid_days_short', 0],
            ['reorder.min_valid_days_short', 29], ['reorder.min_valid_days_long', 92], ['reorder.short_window_days', 0], ['reorder.short_window_days', 6],
            ['reorder.short_window_days', 92], ['reorder.long_window_days', 27], ['reorder.long_window_days', 20]] as [$key, $value]) {
            try {
                $s->checkRule($key, $value);
                self::fail("{$key} accepted " . var_export($value, true));
            } catch (\CW\CwException $e) {
                self::assertSame('bad_value', $e->errorCode, $key);
            }
        }
    }

    public function testTheCliChangesAValueWithTheAdminLoginOnlyAndAuditsIt(): void
    {
        $r = self::cli('--set=suppliers.approval_due_days', '--value=5', '--reason=owner asked for a week');
        self::assertSame(1, $r['code'], $r['err'] . $r['out']);
        self::assertStringContainsString('settings change needs --admin (cw_app has SELECT only)', $r['err']);
        self::assertSame(3, (new Settings(self::$db))->get('suppliers.approval_due_days'), 'unchanged');

        $r = self::cli('--admin', '--set=suppliers.approval_due_days', '--value=5', '--reason=owner asked for five days');
        self::assertSame(0, $r['code'], $r['err'] . $r['out']);
        self::assertStringContainsString('suppliers.approval_due_days changed: 3 -> 5', $r['out']);
        $row = self::$db->one("SELECT CAST(value_json AS CHAR) AS v, updated_actor, provisional FROM app_setting WHERE setting_key = 'suppliers.approval_due_days'");
        self::assertSame(['5', 'system:settings', 1], [$row['v'], $row['updated_actor'], (int) $row['provisional']]);
        $audit = self::$db->one("SELECT actor, entity_type, entity_id, detail FROM audit_log WHERE action = 'setting.change' ORDER BY id DESC LIMIT 1");
        self::assertSame(['system:settings', 'app_setting', 'suppliers.approval_due_days'], [$audit['actor'], $audit['entity_type'], $audit['entity_id']]);
        self::assertEquals(['after' => 5, 'before' => 3, 'key' => 'suppliers.approval_due_days', 'reason' => 'owner asked for five days'],
            json_decode((string) $audit['detail'], true));

        $r = self::cli('--admin', '--set=suppliers.approval_due_days', '--value=5', '--reason=again');
        self::assertSame(0, $r['code']);
        self::assertStringContainsString('suppliers.approval_due_days unchanged', $r['out']);

        // A multi-line text through --value-file; --confirmed clears the provisional mark.
        $file = tempnam(sys_get_temp_dir(), 'cw-set-');
        self::assertIsString($file);
        file_put_contents($file, "Unit 4, Example Park\r\nLondon\r\nE1 1AA\r\n");
        try {
            $r = self::cli('--admin', '--set=company.address', '--value-file=' . $file, '--reason=registered office', '--confirmed');
        } finally {
            unlink($file);
        }
        self::assertSame(0, $r['code'], $r['err'] . $r['out']);
        $s = new Settings(self::$db);
        self::assertSame("Unit 4, Example Park\nLondon\nE1 1AA", $s->company()['address']);
        self::assertSame(0, (int) self::$db->value("SELECT provisional FROM app_setting WHERE setting_key = 'company.address'"));
        self::assertTrue(json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'setting.change' ORDER BY id DESC LIMIT 1"), true)['confirmed']);

        $r = self::cli('--admin', '--set=company.confirmed', '--value=true', '--reason=owner confirmed the details');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertTrue((new Settings(self::$db))->company()['confirmed']);

        $list = self::cli('--admin', '--list');
        self::assertSame(0, $list['code'], $list['err']);
        self::assertStringContainsString("company.address\ttext\tUnit 4, Example Park\\nLondon\\nE1 1AA\tdecision 9", $list['out'], 'confirmed: no provisional mark');
        self::assertStringContainsString("suppliers.change_review\tbool\ttrue\t[provisional]\tdecision 11", $list['out']);
    }

    public function testBadValuesAndUsageExitTwo(): void
    {
        foreach ([
            ['--set=suppliers.approval_due_days', '--value=three', '--reason=bad type'],
            ['--set=suppliers.approval_due_days', '--value=0', '--reason=breaks the rule'],
            ['--set=reorder.min_valid_days_short', '--value=-999', '--reason=broke every reorder list before (I82)'],
            ['--set=company.confirmed', '--value=yes', '--reason=not a bool'],
            ['--set=company.bank_account', '--value=1', '--reason=no such key'],
            ['--set=company.email', '--value=x@example.com', '--reason=no'],
            ['--set=company.email', '--value=x@example.com'],
            ['--set=company.email', '--reason=no value given'],
            ['--set=company.email', '--value=a@example.com', '--value-file=/etc/hostname', '--reason=both given'],
            [],
        ] as $args) {
            $r = self::cli('--admin', ...$args);
            self::assertSame(2, $r['code'], implode(' ', $args) . ': ' . $r['err'] . $r['out']);
        }
        self::assertSame('', (new Settings(self::$db))->get('company.email'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'setting.change'"));
    }

    public function testTheAppLoginCannotWriteSettingsOrVatCodes(): void
    {
        $config = TestDb::config();
        $user = $config->appDbUser();
        self::assertNotNull($user, 'app.env has no db_user');
        Grants::apply(self::$db, TestDb::name(), $user);
        $app = Db::connect($config->dbApp()->withDatabase(TestDb::name()));
        self::assertSame((int) self::$db->value('SELECT COUNT(*) FROM app_setting'), (int) $app->value('SELECT COUNT(*) FROM app_setting'), 'the app login reads them');
        self::assertSame(3, (new Settings($app))->get('suppliers.approval_due_days'));
        foreach ([
            "UPDATE app_setting SET value_json = CAST('5' AS JSON) WHERE setting_key = 'suppliers.approval_due_days'",
            "INSERT INTO app_setting (setting_key, value_type, value_json, description) VALUES ('x.y', 'string', '\"\"', 'x')",
            'DELETE FROM app_setting',
            "UPDATE vat_code SET rate_percent = 0 WHERE code = 'S'",
            "INSERT INTO vat_code (code, label, rate_percent) VALUES ('X', 'x', 0)",
            'DELETE FROM vat_code',
        ] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(static fn () => $app->exec($sql)), $sql);
        }
        try {
            (new Settings($app))->set(Caller::system('test'), 'suppliers.approval_due_days', '5', 'as the app login');
            self::fail('the app login changed a setting');
        } catch (\PDOException $e) {
            self::assertSame(self::DENIED, Db::driverCode($e));
        }
    }
}
