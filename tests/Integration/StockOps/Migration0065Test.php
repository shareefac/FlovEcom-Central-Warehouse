<?php

declare(strict_types=1);

namespace CW\Tests\Integration\StockOps;

use CW\Admin\ConfigInvariants;
use CW\CwException;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Ui\Words;

/**
 * 0065 (the reason a new adjustment opens on; docs/decisions.md AJ9-AJ13; tracker CW-920 part 2, step 2) on the migrated test schema: the
 * setting stock.adjust_opens_on ("data_correction", as the page opened before; provisional) with its baseline, rule and words; no table
 * changes and no reason touched ("Count difference" is offered on stock corrections by the owner, on the Reasons screen); K1-K4 hold.
 */
final class Migration0065Test extends IntegrationTestCase
{
    private const KEY = 'stock.adjust_opens_on';

    public function testTheSetting(): void
    {
        $r = self::$db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = '" . self::KEY . "'");
        self::assertSame(['string', '"data_correction"', 1, 'AJ9'], [$r['value_type'] ?? null, $r['v'] ?? null, (int) ($r['provisional'] ?? -1), $r['decision'] ?? null]);
        self::assertSame(['reason_use' => 'adjustment'], Settings::RULES[self::KEY]);
        self::assertSame('data_correction', (new Settings(self::$db))->get(self::KEY));
        self::assertArrayHasKey(self::KEY, Words::SETTING);
        self::assertArrayHasKey(self::KEY, Words::SETTING_HELP);
        self::assertSame('Stock records', Words::SETTING_TOPIC['stock'] ?? null);
        self::assertSame(Words::SETTING_TOPIC['stock'], Words::settingTopic(self::KEY), 'listed under Stock records');
        foreach (['reason_use', 'reason_none', 'reason_gone', 'reason_coded'] as $word) {
            self::assertArrayHasKey($word, Words::SETTING_EDIT);
        }
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = '" . self::KEY . "' "
            . "AND action = 'baseline' AND version = 1 AND actor = 'system:migrate'"));
        self::assertSame('count', (string) self::$db->value("SELECT applies_to FROM reason_code WHERE code = 'count_difference'"),
            'no reason was touched: "Count difference" is offered on stock corrections by the owner, not by this file');
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: the new row has its history');
    }

    public function testTheRuleTakesOnlyAReasonOfferedOnStockCorrections(): void
    {
        $s = new Settings(self::$db);
        $s->checkRule(self::KEY, 'data_correction');
        $s->checkRule(self::KEY, ''); // None: the person chooses each time
        self::assertSame('bad_value', self::refusal($s, 'no_such_reason'), 'not a reason');
        self::assertSame('bad_value', self::refusal($s, 'count_difference'), 'a reason, but offered on counts only');
        $was = (array) self::$db->one("SELECT is_active, system_only FROM reason_code WHERE code = 'data_correction'");
        try {
            self::$db->exec("UPDATE reason_code SET is_active = 0 WHERE code = 'data_correction'");
            self::assertSame('bad_value', self::refusal($s, 'data_correction'), 'switched off');
            self::$db->exec("UPDATE reason_code SET is_active = 1, system_only = 1 WHERE code = 'data_correction'");
            self::assertSame('bad_value', self::refusal($s, 'data_correction'), 'one CW sets itself: never offered to a person');
        } finally {
            self::$db->exec("UPDATE reason_code SET is_active = ?, system_only = ? WHERE code = 'data_correction'", [(int) $was['is_active'], (int) $was['system_only']]);
        }
        $s->checkRule(self::KEY, 'data_correction');
        self::assertSame([], ConfigInvariants::check(self::$db));
    }

    private static function refusal(Settings $s, string $value): ?string
    {
        try {
            $s->checkRule(self::KEY, $value);
        } catch (CwException $e) {
            return $e->errorCode;
        }
        return null;
    }
}
