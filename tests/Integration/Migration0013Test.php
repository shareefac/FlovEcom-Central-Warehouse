<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Company\CompanyDetails;
use CW\Db;
use CW\Schema\Migrator;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0013 (I91): company_profile is seeded with version 1 from the nine company.* settings as bin/settings.php may have left
 * them on staging (company and VAT numbers tidied when that gives a valid number, otherwise kept as typed; the confirmation
 * kept with its actor and time), the seed is audited, the company.* settings are deleted, review_task takes subject
 * `company`; a run that stopped half way, or a file applied again, doubles nothing and fails nowhere. On scratch schemas
 * migrated up to 0012 (cw_test_<slot>_m13).
 */
final class Migration0013Test extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;
    private const KEYS = ['company.address', 'company.company_number', 'company.confirmed', 'company.delivery_address', 'company.email', 'company.legal_name',
        'company.phone', 'company.trading_name', 'company.vat_number'];

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m13', $this->dir);
            $this->dir = null;
        }
    }

    /** A scratch schema up to 0012, its company.* settings set as bin/settings.php would have (JSON values, actor, time). @param array<string, string> $json */
    private function before(array $json): Db
    {
        [$db, $this->dir] = MigrationFixture::upTo('m13', '0012_key_bulk.sql');
        self::assertSame(self::KEYS, array_map('strval', $db->column("SELECT setting_key FROM app_setting WHERE setting_key LIKE 'company.%' ORDER BY setting_key")),
            'the nine placeholders of 0009');
        self::assertSame(['9'], array_values(array_unique(array_map('strval', $db->column("SELECT decision FROM app_setting WHERE setting_key LIKE 'company.%'")))));
        foreach ($json as $key => $value) {
            $db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON), updated_actor = 'system:settings', updated_at = '2026-10-02 09:30:00' WHERE setting_key = ?",
                [$value, $key]);
        }
        return $db;
    }

    private static function j(string $s): string
    {
        return json_encode($s, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function testTheSeedCopiesTheSettingsAndTheSettingsGo(): void
    {
        // As bin/settings.php may have left them: extra spaces inside names and lines, CR LF, blank lines, a tab.
        $db = $this->before(['company.legal_name' => self::j(' Café  Example Vapes Ltd '), 'company.trading_name' => self::j("Vape\tand Go"),
            'company.company_number' => self::j('sc 123456'), 'company.vat_number' => self::j('123 4567 82'),
            'company.address' => self::j("1  High Street \r\n\r\n Leeds\r\nLS1 1AA\n"), 'company.phone' => self::j('0113  496 0000'),
            'company.email' => self::j(' buying@example.co.uk'), 'company.delivery_address' => self::j("Unit 4,  Example Park\n\nLeeds LS2 2BB  "),
            'company.confirmed' => 'true']);
        $before = (int) $db->value('SELECT COUNT(*) FROM app_setting');
        self::assertSame(['0013_company_profile.sql'], MigrationFixture::migrateRest($db, (string) $this->dir, '0013_company_profile.sql'));

        $rows = $db->all('SELECT * FROM company_profile');
        self::assertCount(1, $rows);
        $p = $rows[0];
        self::assertSame([1, 'seed', 'Café Example Vapes Ltd', 'Vape and Go', 'SC123456', 1, 'GB123456782', "1 High Street\nLeeds\nLS1 1AA", '0113 496 0000',
            'buying@example.co.uk', "Unit 4, Example Park\nLeeds LS2 2BB", 1, null, 'system:settings', '2026-10-02 09:30:00.000000', null, 'copied from the old company settings',
            null, 'system:migrate'],
            [(int) $p['version'], $p['kind'], $p['legal_name'], $p['trading_name'], $p['company_number'], (int) $p['vat_registered'], $p['vat_number'], $p['address'],
                $p['phone'], $p['email'], $p['delivery_address'], (int) $p['confirmed'], $p['confirmed_by'], $p['confirmed_actor'], $p['confirmed_at'], $p['baseline_version'],
                $p['reason'], $p['saved_by'], $p['saved_actor']]);
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'company.%'"), 'one source of truth');
        self::assertSame($before - 9, (int) $db->value('SELECT COUNT(*) FROM app_setting'), 'nothing else removed');

        $audit = $db->all("SELECT actor, entity_type, entity_id, detail FROM audit_log WHERE action = 'company.change'");
        self::assertCount(1, $audit);
        self::assertSame(['system:migrate', 'company_profile', '1'], [$audit[0]['actor'], $audit[0]['entity_type'], $audit[0]['entity_id']]);
        $detail = json_decode((string) $audit[0]['detail'], true);
        self::assertSame(['0013_company_profile.sql', 1, 'seed', true], [$detail['migration'], $detail['version'], $detail['kind'], $detail['confirmed']]);
        self::assertEquals(['legal_name' => 'Café Example Vapes Ltd', 'trading_name' => 'Vape and Go', 'company_number' => 'SC123456', 'vat_registered' => true,
            'vat_number' => 'GB123456782', 'address' => "1 High Street\nLeeds\nLS1 1AA", 'phone' => '0113 496 0000', 'email' => 'buying@example.co.uk',
            'delivery_address' => "Unit 4, Example Park\nLeeds LS2 2BB"], $detail['after']);

        // What a PO prints from now on: the seed, confirmed as it was; it passes today's rules as stored (so it can be confirmed again).
        $c = (new CompanyDetails($db))->company();
        self::assertSame(['Café Example Vapes Ltd', 'SC123456', 'GB123456782', true, true, 1], [$c['legal_name'], $c['company_number'], $c['vat_number'],
            $c['vat_registered'], $c['confirmed'], $c['version']]);
        self::assertSame([], CompanyDetails::problems((new CompanyDetails($db))->current()));
        self::assertFalse(CompanyDetails::unsaved((new CompanyDetails($db))->current()), 'tidied as a save tidies: it can be confirmed as it is');

        // Saving the form untouched (what the page draws, as a browser sends it back) changes nothing: still confirmed, no
        // version, no check (review finding: a seed with extra spaces used to be unconfirmed by an untouched Save).
        $staff = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m13o', 'Owner', 'm13o@test.example', 'x')");
        $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'reviewer')", [$staff]);
        $seed = (new CompanyDetails($db))->current();
        $form = ['vat_registered' => 'yes', 'vat_number' => CompanyDetails::formatVat($seed['vat_number']),
            'address' => str_replace("\n", "\r\n", $seed['address']), 'delivery_address' => str_replace("\n", "\r\n", $seed['delivery_address'])] + CompanyDetails::fields($seed);
        self::assertSame('unchanged', (new CompanyDetails($db))->save(\CW\Caller::staff($staff), 1, $form)['result']);
        self::assertSame([1, true], [(new CompanyDetails($db))->current()['version'], (new CompanyDetails($db))->current()['confirmed']]);

        self::assertSame("enum('document','supplier','company')", (string) $db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'review_task' AND COLUMN_NAME = 'subject_type'"));
        $db->exec("INSERT INTO review_task (subject_type, subject_id, kind, reason, opened_actor, due_at) VALUES ('company', 2, 'review', 'company_changed', 'system:test', NOW())");
        self::assertSame('company:2:review', (string) $db->value("SELECT open_key FROM review_task WHERE subject_type = 'company'"));
    }

    public function testEmptyPlaceholdersAndValuesThatAreNotNumbersAreKeptAsTheyWere(): void
    {
        $db = $this->before(['company.company_number' => self::j(' not a number '), 'company.vat_number' => self::j('GB 12')]);
        MigrationFixture::migrateRest($db, (string) $this->dir, '0013_company_profile.sql');
        $p = (new CompanyDetails($db))->current();
        self::assertSame(['', 'not a number', true, 'GB 12', false, null, 1], [$p['legal_name'], $p['company_number'], $p['vat_registered'], $p['vat_number'],
            $p['confirmed'], $p['confirmed_actor'], $p['version']]);
        self::assertSame(['company_number', 'vat_number'], array_keys(CompanyDetails::problems($p)), 'the page shows them; a save must correct them');
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'company.change'"));
        self::assertFalse(json_decode((string) $db->value("SELECT detail FROM audit_log WHERE action = 'company.change'"), true)['confirmed']);

        // Only a seed may hold them: a saved version must have tidy numbers and agree with its VAT choice, even for admin SQL.
        $staff = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m13', 'M13', 'm13@test.example', 'x')");
        $row = static fn (array $over): array => $over + ['version' => 2, 'kind' => 'change', 'company_number' => '', 'vat_registered' => null, 'vat_number' => '',
            'confirmed' => 0, 'confirmed_actor' => null, 'confirmed_at' => null, 'baseline_version' => null, 'saved_by' => $staff];
        $insert = static fn (array $r) => $db->exec('INSERT INTO company_profile (version, kind, company_number, vat_registered, vat_number, confirmed, confirmed_actor, '
            . "confirmed_at, baseline_version, saved_by, saved_actor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'system:test')", [$r['version'], $r['kind'], $r['company_number'],
                $r['vat_registered'], $r['vat_number'], $r['confirmed'], $r['confirmed_actor'], $r['confirmed_at'], $r['baseline_version'], $r['saved_by']]);
        $confirmed = ['kind' => 'confirm', 'confirmed' => 1, 'confirmed_actor' => 'staff:1', 'confirmed_at' => '2026-10-02 10:00:00'];
        foreach ([['company_number' => 'not a number'], ['company_number' => 'sc123456'], ['company_number' => 'IP1234R'], ['vat_registered' => 1, 'vat_number' => 'GB 12'],
            ['vat_registered' => 1, 'vat_number' => ''], ['vat_registered' => 0, 'vat_number' => 'GB123456782'], ['vat_registered' => null, 'vat_number' => 'GB123456782'],
            ['kind' => 'confirm'], ['confirmed' => 1], ['confirmed' => 1, 'confirmed_actor' => 'staff:1', 'confirmed_at' => '2026-10-02 10:00:00'],
            ['kind' => 'confirm', 'confirmed' => 1, 'confirmed_actor' => 'staff:1'], ['saved_by' => null], ['version' => 0],
            ['baseline_version' => 1], ['baseline_version' => 2] + $confirmed, ['baseline_version' => 5] + $confirmed] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $insert($row($bad))), json_encode($bad, JSON_THROW_ON_ERROR));
        }
        self::assertSame(1062, self::mysqlError(static fn () => $insert($row(['version' => 1]))), 'one row per version');
        $insert($row(['company_number' => 'SC123456', 'vat_registered' => 1, 'vat_number' => 'XI123456782001']));
        $insert($row(['version' => 3, 'baseline_version' => 1] + $confirmed));
        // The rarer company numbers (an old Northern Ireland company, a registered society) pass the CHECK as they pass the form.
        $insert($row(['version' => 4, 'company_number' => 'R0000123']));
        $insert($row(['version' => 5, 'company_number' => 'IP12345R']));
        self::assertSame(5, (new CompanyDetails($db))->current()['version']);
    }

    public function testARunThatStoppedHalfWayAndAFileAppliedAgainDoubleNothing(): void
    {
        $db = $this->before(['company.legal_name' => self::j('Example Vapes Ltd')]);
        $statements = SqlSplitter::split((string) file_get_contents(Migrator::defaultDir() . '/0013_company_profile.sql'));
        self::assertCount(5, $statements, 'table, seed, audit, delete, review_task');

        // Stopped after the seed (the migrator records a file only once every statement succeeded): the next run does the rest.
        foreach (array_slice($statements, 0, 2) as $sql) {
            $db->pdo()->exec($sql);
        }
        self::assertSame(9, (int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'company.%'"));
        self::assertSame(['0013_company_profile.sql'], MigrationFixture::migrateRest($db, (string) $this->dir, '0013_company_profile.sql'));
        $state = static fn (): array => [(int) $db->value('SELECT COUNT(*) FROM company_profile'), (string) $db->value('SELECT legal_name FROM company_profile'),
            (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'company.change'"),
            (int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'company.%'"),
            (string) $db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'review_task' AND COLUMN_NAME = 'subject_type'")];
        $after = [1, 'Example Vapes Ltd', 1, 0, "enum('document','supplier','company')"];
        self::assertSame($after, $state());

        // Every statement once more (applied, but its record lost): no second seed or audit row, no error.
        foreach ($statements as $sql) {
            $db->pdo()->exec($sql);
        }
        self::assertSame($after, $state());
        self::assertSame([], (new Migrator($db, (string) $this->dir))->migrate(), 'recorded once');
    }
}
