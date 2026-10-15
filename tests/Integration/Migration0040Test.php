<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;

/**
 * 0040 (pack C follow-up; docs/decisions.md PC19, the owner's decision of 15 Oct 2026: "the invoice price is the right price") on the
 * migrated test schema: the switch invoices.check_price, off, agreed, with its baseline; the configuration checks K1-K4 hold.
 */
final class Migration0040Test extends IntegrationTestCase
{
    public function testTheSwitchIsOffAndAgreed(): void
    {
        $r = self::$db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = 'invoices.check_price'");
        self::assertSame(['bool', 'false', 0, 'PC19'], [$r['value_type'] ?? null, $r['v'] ?? null, (int) ($r['provisional'] ?? -1), $r['decision'] ?? null],
            'off: the price on the supplier\'s invoice is the right price; the owner\'s own decision, so not provisional');
        self::assertFalse((new Settings(self::$db))->get('invoices.check_price'));
        $b = self::$db->one("SELECT version, action, actor, CAST(state AS CHAR) AS s FROM config_change WHERE subject_type = 'setting' AND subject_key = 'invoices.check_price'");
        self::assertSame([1, 'baseline', 'system:migrate'], [(int) ($b['version'] ?? 0), $b['action'] ?? null, $b['actor'] ?? null]);
        self::assertSame(['value' => 'false', 'provisional' => 0], json_decode((string) $b['s'], true));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: the new row has its history');
    }
}
