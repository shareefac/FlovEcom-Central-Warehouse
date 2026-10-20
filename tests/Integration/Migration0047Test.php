<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Auth\Sessions;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Ui\Words;

/**
 * 0047 (how long a person stays signed in; docs/decisions.md SL1-SL5) on the migrated test schema: the two settings with their baselines,
 * rules and words, their values the limits of before (Auth\Sessions); no table changes; the configuration checks K1-K4 hold.
 */
final class Migration0047Test extends IntegrationTestCase
{
    public function testTheSettings(): void
    {
        $s = [];
        foreach (self::$db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision, updated_actor FROM app_setting "
            . "WHERE setting_key LIKE 'session.%' ORDER BY setting_key") as $r) {
            $s[(string) $r['setting_key']] = [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision'], $r['updated_actor']];
        }
        self::assertSame([
            'session.absolute_hours' => ['int', '12', 1, 'SL1', 'system:migrate'],
            'session.idle_minutes' => ['int', '30', 1, 'SL1', 'system:migrate'],
        ], $s, 'the limits of before (30 minutes away, 12 hours in all): nothing changes until someone raises them; provisional (put to the owner)');
        self::assertSame([Sessions::IDLE_SECONDS, Sessions::ABSOLUTE_SECONDS], [30 * 60, 12 * 3600]);
        $settings = new Settings(self::$db);
        foreach (array_keys($s) as $key) {
            $settings->checkRule($key, $settings->get($key));
            self::assertArrayHasKey($key, Settings::RULES);
            self::assertArrayHasKey($key, Words::SETTING);
            self::assertArrayHasKey($key, Words::SETTING_HELP);
            self::assertSame('Staff', Words::settingTopic($key));
        }
        self::assertSame(['idle' => Sessions::IDLE_SECONDS, 'absolute' => Sessions::ABSOLUTE_SECONDS], (new Sessions(self::$db))->limits());
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND action = 'baseline' AND version = 1 "
            . "AND actor = 'system:migrate' AND subject_key LIKE 'session.%'"));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: the new rows have their history');
    }

    /** Every key Settings::RULES checks is a setting a migration made, with its baseline (a rule for a row that is not there checks nothing). */
    public function testEveryRuleNamesASettingWithABaseline(): void
    {
        $keys = array_keys(Settings::RULES);
        $known = self::$db->column('SELECT a.setting_key FROM app_setting a JOIN config_change c ON c.subject_type = \'setting\' AND c.subject_key = a.setting_key '
            . "AND c.version = 1 AND c.action = 'baseline' WHERE a.setting_key IN (" . implode(', ', array_fill(0, count($keys), '?')) . ')', $keys);
        self::assertSame([], array_values(array_diff($keys, $known)));
    }
}
