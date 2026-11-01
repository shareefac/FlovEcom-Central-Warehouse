<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Queues;

use CW\Admin\ConfigInvariants;
use CW\Queues\NightlyConfirm;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;
use CW\Ui\Words;

/**
 * 0055 (the nightly Confirm all; docs/decisions.md NC2) on the migrated test schema: the three settings (the switch OFF, the time 04:30,
 * at most 500 a night; provisional, as every new setting) with their baselines, rules and words, under Messages from ERPNext; no table
 * changes; K1-K4 hold. Both inserts are safe to run again (as 0054): a run stopped between them, or a second run, neither fails nor
 * duplicates the history nor overwrites a value set since.
 */
final class Migration0055Test extends IntegrationTestCase
{
    private const FILE = '0055_nightly_confirm.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m55', $this->dir);
        }
    }

    public function testTheSettings(): void
    {
        $rows = [];
        foreach (self::$db->all('SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key IN (?, ?, ?) ORDER BY setting_key',
            [NightlyConfirm::SWITCH, NightlyConfirm::AT, NightlyConfirm::MAX]) as $r) {
            $rows[$r['setting_key']] = [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision']];
        }
        self::assertSame([NightlyConfirm::SWITCH => ['bool', 'false', 1, 'NC2'], NightlyConfirm::AT => ['string', '"04:30"', 1, 'NC2'],
            NightlyConfirm::MAX => ['int', '500', 1, 'NC2']], $rows);
        $s = new Settings(self::$db);
        self::assertSame([false, '04:30', 500], [$s->get(NightlyConfirm::SWITCH), $s->get(NightlyConfirm::AT), $s->get(NightlyConfirm::MAX)]);
        $s->checkRule(NightlyConfirm::AT, '23:59');
        $s->checkRule(NightlyConfirm::MAX, 5000);
        foreach ([[NightlyConfirm::AT, '24:00'], [NightlyConfirm::AT, '4:30'], [NightlyConfirm::MAX, 0], [NightlyConfirm::MAX, 5001]] as [$key, $bad]) {
            try {
                $s->checkRule($key, $bad);
                self::fail("{$key} = {$bad} should be refused");
            } catch (\CW\CwException $e) {
                self::assertSame('bad_value', $e->errorCode, $e->getMessage());
            }
        }
        foreach ([NightlyConfirm::SWITCH, NightlyConfirm::AT, NightlyConfirm::MAX] as $key) {
            self::assertArrayHasKey($key, Words::SETTING);
            self::assertArrayHasKey($key, Words::SETTING_HELP);
            self::assertSame(Words::SETTING_TOPIC['relay'], Words::settingTopic($key), 'with the other relay settings');
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ? AND action = 'baseline' "
                . "AND version = 1 AND actor = 'system:migrate'", [$key]));
        }
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: the new rows have their history');
        $sql = (string) preg_replace('/^--.*$/m', '', (string) file_get_contents(dirname(__DIR__, 3) . '/migrations/' . self::FILE));
        self::assertDoesNotMatchRegularExpression('/\b(ALTER|CREATE|DROP|DELETE|UPDATE)\b/', $sql, 'rows only, nothing else');
    }

    public function testItRunsAgainWithoutFailingDuplicatingOrOverwriting(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 3) . '/migrations/' . self::FILE));
        self::assertCount(2, $statements);
        [$db, $this->dir] = MigrationFixture::upTo('m55', '0054_correction_bulk_max_units.sql');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $keys = [NightlyConfirm::SWITCH, NightlyConfirm::AT, NightlyConfirm::MAX];
        $state = static fn (): array => [array_column($db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v FROM app_setting WHERE setting_key IN (?, ?, ?) ORDER BY setting_key',
            $keys), 'v', 'setting_key'), (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key IN (?, ?, ?)", $keys)];
        $fresh = [[NightlyConfirm::SWITCH => 'false', NightlyConfirm::AT => '"04:30"', NightlyConfirm::MAX => '500'], 3];
        self::assertSame($fresh, $state());
        // Stopped between its statements: the settings there, their baselines not; run again, each baseline once.
        $db->exec("DELETE FROM config_change WHERE subject_type = 'setting' AND subject_key IN (?, ?, ?)", $keys);
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame($fresh, $state());
        // Run again after someone switched it on and moved the time: nothing changes, nothing is duplicated.
        $db->exec("UPDATE app_setting SET value_json = CAST('true' AS JSON) WHERE setting_key = ?", [NightlyConfirm::SWITCH]);
        $db->exec("UPDATE app_setting SET value_json = CAST('\"03:15\"' AS JSON) WHERE setting_key = ?", [NightlyConfirm::AT]);
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame([[NightlyConfirm::SWITCH => 'true', NightlyConfirm::AT => '"03:15"', NightlyConfirm::MAX => '500'], 3], $state(), 'values set since are kept');
    }
}
