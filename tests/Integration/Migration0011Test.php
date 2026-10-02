<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Reorder\ReorderSettings;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0011 (I60-I71): the sales-history and reorder tables, their CHECKs and keys, the seeded 14-22 Sep 2026 stockpiling window
 * (on a scratch schema: TestDb::clean empties demand_anomaly, which is not a seed table) and the reorder.* settings, all
 * provisional but the stale-history warning.
 */
final class Migration0011Test extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;
    private const FK = 1452;
    public const TABLES = ['channel_snapshot_day', 'demand_anomaly', 'item_reorder', 'listing_stock_day', 'listing_stock_latest', 'reorder_brand', 'reorder_demand',
        'sales_history_day', 'sales_import_batch'];

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m11', $this->dir);
            $this->dir = null;
        }
    }

    public function testTheSeedsOnAFreshSchema(): void
    {
        [$db, $this->dir] = MigrationFixture::upTo('m11', '0011_reorder.sql');
        self::assertInstanceOf(Db::class, $db);
        $a = $db->all('SELECT date_from, date_to, channel_id, brand, label, is_active, created_by, created_actor, ended_at FROM demand_anomaly');
        self::assertCount(1, $a);
        self::assertSame(['2026-09-14', '2026-09-22', null, null, 1, null, 'system:migrate', null], [$a[0]['date_from'], $a[0]['date_to'], $a[0]['channel_id'],
            $a[0]['brand'], (int) $a[0]['is_active'], $a[0]['created_by'], $a[0]['created_actor'], $a[0]['ended_at']]);
        self::assertStringStartsWith('Pre-duty stockpiling before the 1 Oct 2026 vaping products duty (+43% units/day', (string) $a[0]['label']);
        self::assertSame(14, (int) $db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'reorder.%'"));
    }

    public function testTheSettings(): void
    {
        $rows = array_column(self::$db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting "
            . "WHERE setting_key LIKE 'reorder.%' ORDER BY setting_key"), null, 'setting_key');
        self::assertSame(['reorder.default_lead_days', 'reorder.default_review_days', 'reorder.default_safety_days', 'reorder.long_window_days',
            'reorder.min_valid_days_long', 'reorder.min_valid_days_short', 'reorder.promo_min_units', 'reorder.promo_price_drop', 'reorder.promo_units_uplift',
            'reorder.short_weight', 'reorder.short_window_days', 'reorder.spike_cap_floor', 'reorder.spike_cap_multiple', 'reorder.stale_history_days'], array_keys($rows));
        foreach ($rows as $k => $r) {
            self::assertSame($k === 'reorder.stale_history_days' ? 0 : 1, (int) $r['provisional'], "{$k}: provisional, owner to confirm");
            self::assertNull($r['decision']);
        }
        self::assertSame(['decimal', 'decimal', 'decimal'], [$rows['reorder.short_weight']['value_type'], $rows['reorder.promo_price_drop']['value_type'],
            $rows['reorder.promo_units_uplift']['value_type']]);
        $s = new Settings(self::$db);
        self::assertSame([2, 7, 5, 28, 91, 7, 21, 4, 5, 20, 3], [$s->get('reorder.default_lead_days'), $s->get('reorder.default_review_days'),
            $s->get('reorder.default_safety_days'), $s->get('reorder.short_window_days'), $s->get('reorder.long_window_days'), $s->get('reorder.min_valid_days_short'),
            $s->get('reorder.min_valid_days_long'), $s->get('reorder.spike_cap_multiple'), $s->get('reorder.spike_cap_floor'), $s->get('reorder.promo_min_units'),
            $s->get('reorder.stale_history_days')]);
        // The seeded JSON numbers (0.50, 0.10, 1.50) read as decimal strings, never floats.
        self::assertSame(0, Settings::cmpDecimal((string) $s->get('reorder.short_weight'), '0.50'));
        self::assertSame(0, Settings::cmpDecimal((string) $s->get('reorder.promo_price_drop'), '0.10'));
        self::assertSame(0, Settings::cmpDecimal((string) $s->get('reorder.promo_units_uplift'), '1.50'));
        self::assertSame(['lead' => 2, 'review' => 7, 'safety' => 5, 'short_window' => 28, 'long_window' => 91, 'weight_e6' => 500_000, 'min_short' => 7, 'min_long' => 21,
            'cap_multiple' => 4, 'cap_floor' => 5, 'promo_min_units' => 20, 'stale_days' => 3],
            array_diff_key(ReorderSettings::params($s), ['promo_drop' => 1, 'promo_uplift' => 1]));
    }

    public function testTheTablesAndChecks(): void
    {
        self::assertSame(self::TABLES, array_map('strval', self::$db->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME IN ('" . implode("', '", self::TABLES) . "') AND ENGINE = 'InnoDB' AND TABLE_COLLATION = 'utf8mb4_0900_ai_ci' ORDER BY TABLE_NAME")));
        foreach (self::$db->all("SELECT TABLE_NAME, COLUMN_NAME, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME IN ('" . implode("', '", self::TABLES) . "') AND DATA_TYPE = 'datetime'") as $c) {
            self::assertSame(6, (int) $c['DATETIME_PRECISION'], "{$c['TABLE_NAME']}.{$c['COLUMN_NAME']}");
        }
        self::assertSame(['channel_id', 'external_variant_id', 'sale_date'], array_map('strval', self::$db->column("SELECT COLUMN_NAME FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_history_day' AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX")));
        $channel = self::makeChannel('vapeandgo');
        $batch = static fn (array $over = []): int => self::$db->insert('INSERT INTO sales_import_batch (channel_id, source, date_from, date_to, sales_file, sales_sha256, '
            . "manifest, exported_at, actor) VALUES (?, 'cps', ?, ?, 'f.csv.gz', ?, '{}', '2026-10-02 05:00:00', 'system:test')",
            [$channel, $over['from'] ?? '2026-09-01', $over['to'] ?? '2026-09-30', $over['sha'] ?? str_repeat('a', 64)]);
        $b = $batch();
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $batch(['from' => '2026-10-01', 'sha' => str_repeat('b', 64)])), 'from after to');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $batch(['sha' => str_repeat('A', 64)])), 'sha256 lower-case hex');
        self::assertSame(1062, self::mysqlError(fn () => $batch()), 'one batch per (channel, sales file)');
        $day = static fn (int $online, int $office, int $batchId) => self::$db->exec('INSERT INTO sales_history_day (channel_id, external_variant_id, sale_date, '
            . "units_online, units_office, batch_id) VALUES (?, '101', '2026-09-01', ?, ?, ?)", [$channel, $online, $office, $batchId]);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $day(0, 0, $b)), 'a row sells at least one unit');
        self::assertSame(self::FK, self::mysqlError(fn () => $day(1, 0, $b + 100)));
        $day(0, 2, $b);

        $anomaly = static fn (string $from, string $to, int $active = 1, ?string $ended = null) => self::$db->insert('INSERT INTO demand_anomaly (date_from, date_to, label, '
            . "is_active, ended_at, created_actor) VALUES (?, ?, 'test', ?, ?, 'system:test')", [$from, $to, $active, $ended]);
        $anomaly('2026-07-01', '2026-10-01');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $anomaly('2026-07-01', '2026-10-02')), 'at most 92 days apart');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $anomaly('2026-09-02', '2026-09-01')));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $anomaly('2026-09-01', '2026-09-02', 0)), 'ended needs ended_at');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $anomaly('2026-09-01', '2026-09-02', 1, '2026-10-01 00:00:00')));

        $sku = self::makeSku();
        $item = static fn (array $f) => self::$db->exec('INSERT INTO item_reorder (sku_id, safety_days, lead_days_override, min_stock, max_stock, demand_factor, '
            . "updated_actor) VALUES (?, ?, ?, ?, ?, ?, 'system:test')", [$sku, $f['safety'] ?? null, $f['lead'] ?? null, $f['min'] ?? null, $f['max'] ?? null,
                $f['factor'] ?? null]);
        foreach ([['safety' => 91], ['lead' => 121], ['min' => 10, 'max' => 9], ['factor' => '5.01']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $item($bad)), json_encode($bad, JSON_THROW_ON_ERROR));
        }
        $item(['safety' => 90, 'lead' => 120, 'min' => 10, 'max' => 10, 'factor' => '5.00']);
        $brand = static fn (?string $factor, ?int $safety) => self::$db->exec("INSERT INTO reorder_brand (brand, demand_factor, safety_days, updated_actor) VALUES "
            . "(?, ?, ?, 'system:test')", ['B' . bin2hex(random_bytes(3)), $factor, $safety]);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $brand('5.50', null)));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $brand(null, 91)));
        $brand('0.85', 7);
        self::assertSame(self::FK, self::mysqlError(fn () => self::$db->exec("INSERT INTO reorder_demand (sku_id, computed_at, rate, rate_raw_30, valid_days_short, "
            . "valid_days_long, excluded_anomaly, excluded_promo, excluded_oos, excluded_other, capped_days, units_365, monthly, detail) VALUES "
            . "(?, NOW(6), 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, '{}', '{}')", [$sku + 1000])));
    }
}
