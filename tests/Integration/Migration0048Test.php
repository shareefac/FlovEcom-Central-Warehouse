<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Auth\SecondStep;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Ui\Words;

/**
 * 0048 (2-step verification switched off for a while; docs/decisions.md TF1-TF6) on the migrated test schema: the setting
 * auth.totp_off_until, empty (the code is asked for, as before), with its baseline, rule and words; no table changes; K1-K4 hold.
 */
final class Migration0048Test extends IntegrationTestCase
{
    public function testTheSetting(): void
    {
        $r = self::$db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision, updated_actor FROM app_setting WHERE setting_key = 'auth.totp_off_until'");
        self::assertSame(['string', '""', 1, 'TF1', 'system:migrate'], [$r['value_type'] ?? null, $r['v'] ?? null, (int) ($r['provisional'] ?? -1), $r['decision'] ?? null,
            $r['updated_actor'] ?? null], 'empty: nothing changes until someone sets a time');
        self::assertSame(SecondStep::SETTING, 'auth.totp_off_until');
        self::assertNull(SecondStep::offUntil(self::$db));
        (new Settings(self::$db))->checkRule(SecondStep::SETTING, '');
        self::assertSame(90, Settings::RULES[SecondStep::SETTING]['ahead_max_days'] ?? null, 'at most 90 days ahead');
        self::assertSame(SecondStep::MAX_DAYS, Settings::RULES[SecondStep::SETTING]['ahead_max_days']);
        self::assertArrayHasKey(SecondStep::SETTING, Words::SETTING);
        self::assertArrayHasKey(SecondStep::SETTING, Words::SETTING_HELP);
        self::assertSame('Staff', Words::settingTopic(SecondStep::SETTING));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'auth.totp_off_until' "
            . "AND action = 'baseline' AND version = 1 AND actor = 'system:migrate'"));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: the new row has its history');
    }
}
