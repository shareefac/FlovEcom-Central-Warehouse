<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Queues;

use CW\Admin\ConfigInvariants;
use CW\Queues\Corrections;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;
use CW\Ui\Words;

/**
 * 0054 ("Confirm all ready" for ERPNext corrections; docs/decisions.md CA3) on the migrated test schema: the setting
 * relay.correction_bulk_max_units (100, provisional) with its baseline, rule and words, under Messages from ERPNext with the other relay
 * settings; no table changes; K1-K4 hold. Both inserts are safe to run again (CA19, Codex MEDIUM 7): a run stopped between them, or a
 * second run, neither fails nor duplicates the history nor overwrites a value set since.
 */
final class Migration0054Test extends IntegrationTestCase
{
    private const FILE = '0054_correction_bulk_max_units.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m54', $this->dir);
        }
    }

    public function testTheSetting(): void
    {
        $r = self::$db->one('SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = ?', [Corrections::BIG_SETTING]);
        self::assertSame(['int', '100', 1, 'CA3'], [$r['value_type'] ?? null, $r['v'] ?? null, (int) ($r['provisional'] ?? -1), $r['decision'] ?? null]);
        self::assertSame(Corrections::BIG_DEFAULT, (new Settings(self::$db))->get(Corrections::BIG_SETTING), 'the default the code falls back on is the one seeded');
        self::assertSame(['min' => 0, 'max' => 100_000], Settings::RULES[Corrections::BIG_SETTING]);
        (new Settings(self::$db))->checkRule(Corrections::BIG_SETTING, 0);
        (new Settings(self::$db))->checkRule(Corrections::BIG_SETTING, 250);
        self::assertArrayHasKey(Corrections::BIG_SETTING, Words::SETTING);
        self::assertArrayHasKey(Corrections::BIG_SETTING, Words::SETTING_HELP);
        self::assertSame(Words::SETTING_TOPIC['relay'], Words::settingTopic(Corrections::BIG_SETTING), 'listed with the other relay settings');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ? "
            . "AND action = 'baseline' AND version = 1 AND actor = 'system:migrate'", [Corrections::BIG_SETTING]));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: the new row has its history');
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/migrations/0054_correction_bulk_max_units.sql');
        self::assertDoesNotMatchRegularExpression('/\b(ALTER|CREATE|DROP|DELETE|UPDATE)\b/', (string) preg_replace('/^--.*$/m', '', $sql), 'two rows, nothing else');
    }

    public function testItRunsAgainWithoutFailingDuplicatingOrOverwriting(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 3) . '/migrations/' . self::FILE));
        self::assertCount(2, $statements);
        [$db, $this->dir] = MigrationFixture::upTo('m54', '0053_po_board_recent_days.sql');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $state = static fn (): array => [(string) $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?", [Corrections::BIG_SETTING]),
            (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [Corrections::BIG_SETTING])];
        self::assertSame(['100', 1], $state());
        // Stopped between its two statements: the setting is there, its baseline is not; run again, it completes once.
        $db->exec("DELETE FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [Corrections::BIG_SETTING]);
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame(['100', 1], $state(), 'the baseline written once');
        self::assertSame(1, (int) $db->value("SELECT version FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [Corrections::BIG_SETTING]));
        // Run again after someone set the limit: nothing changes, nothing is duplicated.
        $db->exec("UPDATE app_setting SET value_json = CAST('250' AS JSON) WHERE setting_key = ?", [Corrections::BIG_SETTING]);
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        self::assertSame(['250', 1], $state(), 'a value set since is kept');
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM app_setting WHERE setting_key = ?', [Corrections::BIG_SETTING]));
    }
}
