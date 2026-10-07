<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\IntegrationTestCase;

/**
 * 0018 (IM10; docs/decisions.md I149, I151, I152): the site writer switch is off on every site unless someone turns it on, the
 * per-site mode tables hold their rules in SQL too (the CHECKs refuse what CW\SiteWriter\SiteModes never writes), and the setting.
 */
final class Migration0018Test extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;
    private const DUPLICATE = 1062;

    public function testTheSwitchIsOffByDefaultAndTheSetting(): void
    {
        $id = self::makeChannel('mig18');
        self::assertSame(0, (int) self::$db->value('SELECT site_writer FROM channel WHERE id = ?', [$id]));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => self::$db->exec('UPDATE channel SET site_writer = 2 WHERE id = ?', [$id])));
        $r = self::$db->one("SELECT value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key = 'site_writer.receipt_mode_sites'");
        self::assertSame(['string', '"vapeandgo"', 1, null], [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision']]);
    }

    public function testThePerSiteModeChecks(): void
    {
        $ch = self::makeChannel('mig18b');
        $sku = self::makeSku('Migration 18 item');
        $row = static fn (array $over): mixed => self::$db->exec('INSERT INTO item_channel_mode (' . implode(', ', array_keys($over)) . ') VALUES ('
            . implode(', ', array_fill(0, count($over), '?')) . ')', array_values($over));
        $ok = ['sku_id' => $sku, 'channel_id' => $ch, 'mode' => 'In-Stock', 'source' => 'switch', 'updated_actor' => 'x'];
        foreach ([['previous_mode' => 'In-Stock'], ['version' => 0], ['low_stock_threshold' => 100001], ['source' => 'receipt']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $row($bad + $ok)), json_encode($bad));
        }
        $row(['mode' => 'Out-Of-Stock', 'previous_mode' => 'From-Warehouse', 'low_stock_threshold' => 5] + $ok);
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $row($ok)), 'one row per item and site');

        $log = static fn (array $over): mixed => self::$db->exec('INSERT INTO item_channel_mode_log (' . implode(', ', array_keys($over)) . ') VALUES ('
            . implode(', ', array_fill(0, count($over), '?')) . ')', array_values($over));
        $okLog = ['sku_id' => $sku, 'channel_id' => $ch, 'version' => 1, 'mode_after' => 'In-Stock', 'source' => 'switch', 'reason' => 'why', 'actor' => 'x'];
        foreach ([['previous_mode' => 'In-Stock'], ['reason' => null], ['source' => 'receipt']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $log($bad + $okLog)), json_encode($bad));
        }
        $log($okLog);
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $log($okLog)), 'one log row per version');
        self::$db->exec('DELETE FROM item_channel_mode_log');
        self::$db->exec('DELETE FROM item_channel_mode');
    }
}
