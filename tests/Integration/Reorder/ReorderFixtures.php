<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Caller;
use CW\Reorder\DemandBuilder;
use CW\Reorder\DemandMath;
use CW\Reorder\ReorderList;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;

/**
 * Fixtures of the reorder tests (docs/decisions.md I60-I71), shared by ReorderTestCase (service tests) and the screen tests
 * (KernelUiTestCase): sites, listings, loaded sales history written straight into the tables (or as export files in a
 * temporary directory, as tools/sales_history/export.php writes them), anomaly windows, settings changed with the admin
 * connection and restored (call cleanReorderFixtures() in tearDown), suppliers made active by a second person, and the
 * reorder-list scenario. The using class extends MappingTestCase (self::$db, staffUser(), site(), item(), book()).
 */
trait ReorderFixtures
{
    /** @var array<string, string> setting_key => value_json to restore */
    private array $settingsSaved = [];
    /** @var list<string> temporary directories to remove */
    private array $tmp = [];

    /** Restores the settings changed and removes the temporary directories (the using class's tearDown calls it). */
    protected function cleanReorderFixtures(): void
    {
        foreach ($this->settingsSaved as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->settingsSaved = [];
        foreach ($this->tmp as $dir) {
            if (is_dir($dir) && str_starts_with($dir, sys_get_temp_dir() . '/cw_reorder_')) {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
        $this->tmp = [];
    }

    /** Changes a setting for this test (admin connection; restored in tearDown). */
    protected function setting(string $key, string $json): void
    {
        $this->settingsSaved[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/cw_reorder_' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $this->tmp[] = $dir;
        return $dir;
    }

    /** A site (channel selling from MAIN) by code: vapeandgo, electrofag. */
    protected function channelOf(string $code): int
    {
        $id = self::$db->value('SELECT id FROM channel WHERE code = ?', [$code]);
        return $id !== null ? (int) $id : (int) $this->site($code, 'off')->channelId;
    }

    /** A listing of a channel, mapped to $sku when given (u = $u). */
    protected function listingOf(int $channelId, string $variant, ?int $sku, int $u = 1, ?string $status = null): int
    {
        return self::$db->insert('INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, ?, ?, ?, ?)',
            [$channelId, $variant, $sku, $u, $status ?? ($sku === null ? 'unmapped' : 'mapped')]);
    }

    /** An item with a brand. */
    protected function brandItem(string $name, ?string $brand): int
    {
        $id = self::makeSku($name);
        self::$db->exec('UPDATE sku SET brand = ? WHERE id = ?', [$brand, $id]);
        return $id;
    }

    /**
     * Loaded sales history written straight into the tables: one loaded batch covering [$from, $to] and its rows.
     *
     * @param list<array{0: string, 1: string, 2: int, 3?: string, 4?: int}> $rows [variant, date, units_online, net_online, units_office]
     * @param list<string> $snapshotDays days the site's snapshot covered
     * @param list<array{0: string, 1: string}> $unsellable [variant, date]
     */
    protected function history(int $channelId, string $from, string $to, array $rows, array $snapshotDays = [], array $unsellable = []): int
    {
        $batch = self::$db->insert('INSERT INTO sales_import_batch (channel_id, source, date_from, date_to, sales_file, sales_sha256, manifest, exported_at, status, '
            . "actor, finished_at) VALUES (?, 'cps', ?, ?, 'test.csv.gz', ?, '{}', NOW(6), 'loaded', 'system:test', NOW(6))",
            [$channelId, $from, $to, hash('sha256', random_bytes(16))]);
        foreach (array_chunk($rows, 500) as $chunk) {
            $params = [];
            foreach ($chunk as $r) {
                array_push($params, $channelId, $r[0], $r[1], $r[2], $r[3] ?? '0.00', $r[3] ?? '0.00', $r[4] ?? 0, $batch);
            }
            self::$db->exec('INSERT INTO sales_history_day (channel_id, external_variant_id, sale_date, units_online, net_online, gross_online, units_office, batch_id) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')), $params);
        }
        foreach ($snapshotDays as $d) {
            self::$db->exec('INSERT INTO channel_snapshot_day (channel_id, snapshot_date, variants, unsellable, batch_id) VALUES (?, ?, 100, 10, ?)', [$channelId, $d, $batch]);
        }
        foreach ($unsellable as [$v, $d]) {
            self::$db->exec("INSERT INTO listing_stock_day (channel_id, external_variant_id, stock_date, stock, stock_mode, allow_backorders, batch_id) VALUES "
                . "(?, ?, ?, 0, 'Out-Of-Stock', 0, ?)", [$channelId, $v, $d, $batch]);
        }
        return $batch;
    }

    /**
     * Rows of $variant at $perDay units on every day of [$from, $to], at $pence a unit.
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string}>
     */
    protected static function daily(string $variant, string $from, string $to, int $perDay, int $pence = 195): array
    {
        $out = [];
        for ($d = DemandMath::day($from); $d <= DemandMath::day($to); $d++) {
            $out[] = [$variant, DemandMath::date($d), $perDay, self::money($perDay * $pence)];
        }
        return $out;
    }

    /** Pence as a DECIMAL string. */
    protected static function money(int $pence): string
    {
        return intdiv($pence, 100) . '.' . str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The pre-duty stockpiling window (not a seed table: tests that need it insert it). */
    protected function stockpiling(string $label = 'Pre-duty stockpiling (+43% units/day)', ?int $channelId = null, ?string $brand = null,
        string $from = '2026-09-14', string $to = '2026-09-22'): int
    {
        return self::$db->insert("INSERT INTO demand_anomaly (date_from, date_to, channel_id, brand, label, created_actor) VALUES (?, ?, ?, ?, ?, 'system:test')",
            [$from, $to, $channelId, $brand, $label]);
    }

    protected function builder(): DemandBuilder
    {
        return new DemandBuilder(self::$db);
    }

    /** @return array<string, mixed> the reorder_demand row of an item (detail and monthly decoded) */
    protected function demandOf(int $sku): array
    {
        $r = self::$db->one('SELECT *, CAST(detail AS CHAR) AS detail_json, CAST(monthly AS CHAR) AS monthly_json FROM reorder_demand WHERE sku_id = ?', [$sku]);
        self::assertNotNull($r, "no demand row for item {$sku}");
        $r['detail'] = json_decode((string) $r['detail_json'], true);
        $r['monthly'] = json_decode((string) $r['monthly_json'], true);
        return $r;
    }

    /**
     * An export as tools/sales_history/export.php writes it: gzip CSV files and the manifest, in $dir. Returns the manifest's path.
     *
     * @param list<array<int, mixed>> $sales site,variant_id,sale_date,units_online,orders_online,net_online,gross_online,units_office,orders_office (without site)
     * @param list<array<int, mixed>>|null $stock variant_id,stock_date,stock,stock_mode,allow_backorders (without site)
     * @param list<array<int, mixed>>|null $latest variant_id,snapshot_date,stock,stock_mode,sellable (without site)
     * @param list<array{date: string, variants: int, unsellable: int}> $snapshotDays
     */
    protected static function exportFiles(string $dir, string $site, string $from, string $to, array $sales, ?array $stock = null, ?array $latest = null,
        array $snapshotDays = [], string $ts = '20261002T050000Z'): string
    {
        $files = [];
        $write = static function (string $name, string $kind, array $header, array $rows) use ($dir, $site, &$files): void {
            $fh = fopen('compress.zlib://' . $dir . '/' . $name, 'wb');
            fputcsv($fh, $header, ',', '"', '');
            foreach ($rows as $r) {
                fputcsv($fh, [$site, ...$r], ',', '"', '');
            }
            fclose($fh);
            $files[] = ['name' => $name, 'kind' => $kind, 'sha256' => hash_file('sha256', $dir . '/' . $name), 'rows' => count($rows)];
        };
        $write("{$site}_sales_{$from}_{$to}_{$ts}.csv.gz", 'sales', ['site', 'variant_id', 'sale_date', 'units_online', 'orders_online', 'net_online', 'gross_online',
            'units_office', 'orders_office'], $sales);
        if ($stock !== null) {
            $write("{$site}_stockdays_{$from}_{$to}_{$ts}.csv.gz", 'stockdays', ['site', 'variant_id', 'stock_date', 'stock', 'stock_mode', 'allow_backorders'], $stock);
        }
        if ($latest !== null) {
            $write("{$site}_stocklatest_{$to}_{$ts}.csv.gz", 'latest', ['site', 'variant_id', 'snapshot_date', 'stock', 'stock_mode', 'sellable'], $latest);
        }
        $manifest = "{$dir}/{$site}_sales_{$from}_{$to}_{$ts}.manifest.json";
        file_put_contents($manifest, json_encode(['site' => $site, 'database' => 'test', 'source' => 'cps', 'rule' => 'test', 'from' => $from, 'to' => $to,
            'exported_at' => '2026-10-02T05:00:00Z', 'tool_version' => 'test', 'seconds' => 0.1, 'slices' => 1, 'months' => new \stdClass(),
            'snapshot_days' => $snapshotDays, 'files' => $files, 'query_sha256' => str_repeat('0', 64), 'explain' => new \stdClass(),
            'threads_running' => ['before' => 1, 'max' => 1, 'after' => 1]], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return $manifest;
    }

    /**
     * A complete supplier made and asked for by $buyer and approved by a fresh reviewer.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    protected function reorderSupplier(Caller $buyer, string $name, array $over = []): array
    {
        $sup = new Suppliers(self::$db);
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/London'));
        $s = $sup->create($buyer, $over + ['name' => $name, 'address_line1' => '1 Trading Estate', 'postcode' => 'LS1 1AA', 'country' => 'GB',
            'email' => 'orders@' . strtolower(preg_replace('/[^a-z]/i', '', $name)) . '.example', 'payment_terms' => '30 days',
            'dd_checked_on' => $today->modify('-10 days')->format('Y-m-d'), 'dd_checked_by' => (string) $buyer->staffUserId,
            'dd_next_review_on' => $today->modify('+1 year')->format('Y-m-d')]);
        $s = $sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve($this->staffUser('reviewer'), $task, null);
    }

    /**
     * A supplier item (made by $buyer) with a manual price.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    protected function reorderSupplierItem(Caller $buyer, int $supplierId, int $sku, int $upp, ?string $price, array $fields = []): array
    {
        return (new SupplierItems(self::$db))->create($buyer, $supplierId, $sku, $fields + ['units_per_pack' => (string) $upp], $price === null ? null : ['pack_price' => $price]);
    }

    /**
     * The reorder-list scenario (Vape and Go history to 1 Oct 2026, 91 days at a steady rate, no anomaly):
     *   A Elux 12 a day, stock 60, S1's box of 24 (preferred, MOQ 2, lead 3, £45.00)    -> target 180
     *   B Elux 2 a day, S1's pack of 10 (preferred, £8.00)                               -> target 28
     *   C Lost Mary 5 a day, S2's pack of 6 (preferred, £12.00; S2: lead 4, review 14) -> target 115, urgent
     *   D Elux 1 a day, no supplier item                                                  -> no_supplier
     *   E Elux 3 a day, do not reorder                                                     -> never suggested
     * S2 has a minimum order of £1,000.00.
     *
     * @return array{buyer: Caller, s1: array<string, mixed>, s2: array<string, mixed>, a: int, b: int, c: int, d: int, e: int, vpg: int,
     *   si: array<string, array<string, mixed>>}
     */
    protected function listScenario(): array
    {
        $vpg = $this->channelOf('vapeandgo');
        $buyer = $this->staffUser('buyer');
        $s1 = $this->reorderSupplier($buyer, 'Elux Wholesale');
        $s2 = $this->reorderSupplier($buyer, 'Mary Distribution', ['default_lead_days' => '4', 'review_days' => '14', 'min_order_value' => '1000.00']);
        $a = $this->item('legacy', 60, 'Elux Legend Blue Razz');
        $b = $this->brandItem('Elux Legend Cola', 'Elux');
        $c = $this->brandItem('Lost Mary BM600 Grape', 'Lost Mary');
        $d = $this->brandItem('Elux Legend Mint', 'Elux');
        $e = $this->brandItem('Elux old flavour', 'Elux');
        self::$db->exec("UPDATE sku SET brand = 'Elux' WHERE id = ?", [$a]);
        self::$db->exec("INSERT INTO item_reorder (sku_id, do_not_reorder, updated_actor) VALUES (?, 1, 'system:test')", [$e]);
        $si = [
            'a' => $this->reorderSupplierItem($buyer, (int) $s1['id'], $a, 24, '45.00', ['supplier_code' => 'ELX-A', 'purchase_unit' => 'box', 'moq_packs' => '2',
                'lead_days' => '3', 'is_preferred' => '1']),
            'b' => $this->reorderSupplierItem($buyer, (int) $s1['id'], $b, 10, '8.00', ['supplier_code' => 'ELX-B', 'is_preferred' => '1']),
            'c' => $this->reorderSupplierItem($buyer, (int) $s2['id'], $c, 6, '12.00', ['supplier_code' => 'MARY-C', 'is_preferred' => '1']),
        ];
        $rows = [];
        foreach (['601' => [$a, 12], '602' => [$b, 2], '603' => [$c, 5], '604' => [$d, 1], '605' => [$e, 3]] as $v => [$sku, $perDay]) {
            $this->listingOf($vpg, (string) $v, $sku);
            array_push($rows, ...self::daily((string) $v, '2026-06-01', '2026-10-01', $perDay));
        }
        $this->history($vpg, '2026-06-01', '2026-10-01', $rows);
        (new DemandBuilder(self::$db))->rebuild();
        return ['buyer' => $buyer, 's1' => $s1, 's2' => $s2, 'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'e' => $e, 'vpg' => $vpg, 'si' => $si];
    }
}
