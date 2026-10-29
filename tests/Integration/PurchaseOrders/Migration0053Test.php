<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Admin\ConfigInvariants;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Ui\Words;

/**
 * 0053 (the purchase orders board; docs/decisions.md PV18) on the migrated test schema: the setting po.board_recent_days (14, provisional)
 * with its baseline, rule and words; no table changes; K1-K4 hold.
 */
final class Migration0053Test extends IntegrationTestCase
{
    public function testTheSetting(): void
    {
        $r = self::$db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = 'po.board_recent_days'");
        self::assertSame(['int', '14', 1, 'PV18'], [$r['value_type'] ?? null, $r['v'] ?? null, (int) ($r['provisional'] ?? -1), $r['decision'] ?? null]);
        self::assertSame(['min' => 1, 'max' => 365], Settings::RULES['po.board_recent_days']);
        (new Settings(self::$db))->checkRule('po.board_recent_days', 30);
        self::assertArrayHasKey('po.board_recent_days', Words::SETTING);
        self::assertArrayHasKey('po.board_recent_days', Words::SETTING_HELP);
        self::assertSame(Words::SETTING_TOPIC['po'], Words::settingTopic('po.board_recent_days'), 'listed under Purchase orders');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'po.board_recent_days' "
            . "AND action = 'baseline' AND version = 1 AND actor = 'system:migrate'"));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: the new row has its history');
    }
}
