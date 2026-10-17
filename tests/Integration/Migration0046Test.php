<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Ui\Words;

/**
 * 0046 (pack RB, the rollback file; docs/decisions.md RB2, RB4, RB28) on the migrated test schema: the four settings with their baselines,
 * rules and words; no table changes; the configuration checks K1-K4 hold.
 */
final class Migration0046Test extends IntegrationTestCase
{
    public function testTheSettings(): void
    {
        $s = [];
        foreach (self::$db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key LIKE 'rollback.%' "
            . 'ORDER BY setting_key') as $r) {
            $s[(string) $r['setting_key']] = [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision']];
        }
        self::assertSame([
            'rollback.cw_warehouses' => ['string', '"MAIN,VERIFY"', 1, 'RB2'],
            'rollback.erp_company' => ['string', '"VAPE AND GO"', 1, 'RB4'],
            'rollback.erp_warehouse' => ['string', '"Stores - VPG"', 1, 'RB4'],
            'rollback.max_rows' => ['int', '200000', 1, 'RB28'],
        ], $s, 'the ERPNext names the opening import reads; every one provisional (a build decision put to the owner)');
        $settings = new Settings(self::$db);
        foreach (array_keys($s) as $key) {
            $settings->checkRule($key, $settings->get($key));
            self::assertArrayHasKey($key, Settings::RULES);
            self::assertArrayHasKey($key, Words::SETTING);
            self::assertArrayHasKey($key, Words::SETTING_HELP);
            self::assertSame('Way back to ERPNext', Words::settingTopic($key));
        }
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND action = 'baseline' AND version = 1 "
            . "AND actor = 'system:migrate' AND subject_key LIKE 'rollback.%'"));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: the new rows have their history');
    }
}
