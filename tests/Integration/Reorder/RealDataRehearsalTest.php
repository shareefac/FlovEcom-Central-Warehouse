<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\Reorder\DemandBuilder;
use CW\Reorder\DemandMath;
use CW\Reorder\ReorderSettings;
use CW\Reorder\SalesHistoryImport;
use CW\Settings;
use CW\Tests\Support\MigrationFixture;
use CW\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * The real-data rehearsal (spec §11.R, docs/decisions.md I70), OPT-IN: skipped unless CW_REHEARSAL_DIR names a directory
 * holding the live sales-history exports (tools/sales_history/export.php: the manifests and their files, for vapeandgo and
 * electrofag) and the first-match listing exports (*_listings_*.jsonl.gz). On a scratch schema cw_test_<slot>_rh (fully
 * migrated, so the 14-22 Sep 2026 window is seeded):
 *   1. both listing exports imported (bin/import_listings.php), and ONLY the Vape and Go listings of three brands minted and
 *      linked (DecisionService::mintAndLink): Elux (its nic salts), Lost Mary, Bar Juice 5000 (an e-liquid);
 *   2. both sales exports imported (SalesHistoryImport), the demand built (DemandBuilder);
 *   3. asserted: the stockpiling days are excluded, Elux's promotion from 23 Sep is flagged on vapeandgo, and Elux's demand is
 *      below its plain 30-day average;
 *   4. printed on stderr (PHPUnit forbids output on stdout): the import and build seconds, the unlinked units per channel, the
 *      promotion days, and rate against rate_raw_30 per brand;
 *   5. the scratch schema dropped.
 *
 *   scripts/remote.sh <slot> env CW_REHEARSAL_DIR=data/rehearsal vendor/bin/phpunit --filter RealDataRehearsalTest
 *
 * data/ is git-ignored: copy the files in for the run only and delete them afterwards (docs/ops.md).
 */
final class RealDataRehearsalTest extends TestCase
{
    public const BRANDS = ['Elux' => 'Elux%', 'Lost Mary' => 'Lost Mary%', 'Bar Juice 5000' => 'Bar Juice 5000%'];

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('rh', $this->dir);
            $this->dir = null;
        }
    }

    /** @return list<string> files under $root whose name matches $pattern */
    private static function find(string $root, string $pattern): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && preg_match($pattern, $f->getFilename()) === 1) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    private static function note(string $line): void
    {
        fwrite(STDERR, "[rehearsal] {$line}\n");
    }

    public function testRealHistoryForThreeBrands(): void
    {
        $root = getenv('CW_REHEARSAL_DIR');
        if (!is_string($root) || $root === '' || getenv('CW_TEST_DB') === '0') {
            self::markTestSkipped('opt-in: set CW_REHEARSAL_DIR to the directory of the real exports (docs/ops.md, "Sales history: the rehearsal")');
        }
        $root = (string) realpath($root);
        self::assertDirectoryExists($root);
        $listings = ['vapeandgo' => self::find($root, '/^vapeandgo_listings_.*\.jsonl\.gz$/D'), 'electrofag' => self::find($root, '/^electrofag_listings_.*\.jsonl\.gz$/D')];
        $sales = ['vapeandgo' => self::find($root, '/^vapeandgo_sales_.*\.manifest\.json$/D'), 'electrofag' => self::find($root, '/^electrofag_sales_.*\.manifest\.json$/D')];
        foreach ([$listings, $sales] as $set) {
            foreach ($set as $site => $files) {
                self::assertCount(1, $files, "exactly one file of {$site}");
            }
        }
        [$db, $this->dir] = MigrationFixture::upTo('rh', '0011_reorder.sql');
        $schema = MigrationFixture::schema('rh');
        $channels = [];
        foreach (['vapeandgo', 'electrofag'] as $code) {
            $channels[$code] = $db->insert("INSERT INTO channel (code, name, mode) VALUES (?, ?, 'off')", [$code, $code]);
            $db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, 1, 1)', [$channels[$code]]);
        }

        // 1. Listings, then the three brands of Vape and Go minted and linked.
        $t = hrtime(true);
        foreach ($listings as $code => [$file]) {
            $r = self::cli('bin/import_listings.php', '--db=' . $schema, '--admin', "--channel={$code}", "--file={$file}");
            self::assertContains($r['code'], [0, 1], $r['err']);
        }
        $lead = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('rh-lead', 'Rehearsal lead', 'rh-lead@test.invalid', 'x')");
        $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'mapping_lead')", [$lead]);
        $ds = new DecisionService($db);
        $minted = 0;
        $failed = 0;
        foreach (self::BRANDS as $like) {
            foreach ($db->all('SELECT l.id, l.map_version FROM channel_listing l JOIN listing_profile p ON p.listing_id = l.id WHERE l.channel_id = ? AND p.brand LIKE ? '
                . "AND l.status IN ('unmapped', 'suggested') ORDER BY l.id", [$channels['vapeandgo'], $like]) as $l) {
                try {
                    $ds->mintAndLink(Caller::staff($lead), (int) $l['id'], (int) $l['map_version'], $ds->card((int) $l['id']), 'rehearsal', 'I-2 rehearsal');
                    $minted++;
                } catch (CwException) {
                    $failed++;
                }
            }
        }
        self::note(sprintf('listings imported and %d Vape and Go listings of %s minted and linked (%d refused) in %.1f s', $minted, implode(', ', array_keys(self::BRANDS)),
            $failed, (hrtime(true) - $t) / 1e9));
        self::assertGreaterThan(100, $minted);

        // 2. The sales history, then the demand.
        $import = new SalesHistoryImport($db);
        foreach ($sales as $code => [$manifest]) {
            $t = hrtime(true);
            $r = $import->import(Caller::system('rehearsal'), $code, $manifest);
            $c = $r['counts'];
            self::note(sprintf('import %s %s..%s: %d rows, %d units, unknown %d units, unlinked %d units, %d stock days, %d latest, %.1f s', $code, $r['from'], $r['to'],
                $c['rows_read'], $c['units_loaded'], $c['unknown_units'], $c['unlinked_units'], $c['stock_rows'], $c['latest_rows'], (hrtime(true) - $t) / 1e9));
            foreach ($r['anomalies'] as $a) {
                self::note("  {$code} window {$a['from']}..{$a['to']}: {$a['per_day']} units/day vs {$a['baseline_per_day']} in Jul-Aug ({$a['uplift']})");
            }
        }
        $t = hrtime(true);
        $b = (new DemandBuilder($db))->rebuild();
        self::note(sprintf('demand build: %d items, %d listings, %.1f s (%d ms reported)', $b['items'], $b['listings'], (hrtime(true) - $t) / 1e9, $b['ms']));

        // 3. The stockpiling window is excluded.
        $byBrand = [];
        foreach ($db->all('SELECT s.brand, d.rate, d.rate_raw_30, d.units_365, d.excluded_anomaly, d.excluded_promo, d.first_sale_date FROM reorder_demand d '
            . 'JOIN sku s ON s.id = d.sku_id') as $r) {
            $byBrand[(string) $r['brand']][] = $r;
        }
        $units = static fn (array $rows): int => array_sum(array_map(static fn (array $r): int => (int) $r['units_365'], $rows));
        // Several brand strings start with "Elux" on Vape and Go; the promotion is its nic salts' (the biggest by units).
        $elux = array_values(array_filter(array_keys($byBrand), static fn (string $k): bool => str_starts_with($k, 'Elux')));
        usort($elux, static fn (string $a, string $b): int => $units($byBrand[$b]) <=> $units($byBrand[$a]));
        self::assertNotEmpty($elux);
        $eluxRows = array_merge(...array_map(static fn (string $k): array => $byBrand[$k], $elux));
        $old = array_filter($eluxRows, static fn (array $r): bool => $r['first_sale_date'] !== null && $r['first_sale_date'] < '2026-09-14');
        self::assertNotEmpty($old);
        $nine = count(array_filter($old, static fn (array $r): bool => (int) $r['excluded_anomaly'] === 9));
        self::note(sprintf('Elux items sold before 14 Sep: %d, with the 9 stockpiling days excluded from their main listing: %d', count($old), $nine));
        foreach ($eluxRows as $r) {
            self::assertLessThanOrEqual(9, (int) $r['excluded_anomaly']);
        }
        self::assertGreaterThan(0, $nine);
        // Elux's promotion from 23 Sep is flagged on vapeandgo.
        $builder = new DemandBuilder($db);
        $flags = $builder->promotions($builder->channels(), $builder->anomalies(), ReorderSettings::params(new Settings($db)));
        foreach ($elux as $i => $brand) {
            $days = array_map(static fn (int $d): string => DemandMath::date($d), array_keys($flags[$channels['vapeandgo']][DemandBuilder::brandKey($brand)] ?? []));
            sort($days);
            self::note("promotion days flagged on vapeandgo for {$brand}: " . ($days === [] ? 'none' : implode(', ', $days)));
            if ($i === 0) {
                self::assertContains('2026-09-23', $days, 'the 6-for-£10 from 23 Sep');
            }
        }
        foreach (array_diff(array_keys($byBrand), $elux) as $brand) {
            $days = array_map(static fn (int $d): string => DemandMath::date($d), array_keys($flags[$channels['vapeandgo']][DemandBuilder::brandKey($brand)] ?? []));
            sort($days);
            self::note("promotion days flagged on vapeandgo for {$brand}: " . ($days === [] ? 'none' : implode(', ', $days)));
        }
        // Elux's demand is below its plain 30-day average (the stockpiling and the promotion inflate the latter).
        $sum = static function (array $rows, string $col): int {
            $n = 0;
            foreach ($rows as $r) {
                $n += DemandMath::toE4((string) $r[$col]);
            }
            return $n;
        };
        self::assertLessThan($sum($byBrand[$elux[0]], 'rate_raw_30'), $sum($byBrand[$elux[0]], 'rate'));

        // 4. The comparison per brand, and the unlinked units per channel.
        ksort($byBrand);
        foreach ($byBrand as $brand => $rows) {
            $rate = $sum($rows, 'rate');
            $raw = $sum($rows, 'rate_raw_30');
            self::note(sprintf('brand %-45s items %4d  rate %10s  rate_raw_30 %10s  rate/raw %s', $brand, count($rows), DemandMath::e4($rate), DemandMath::e4($raw),
                $raw === 0 ? '-' : number_format(100 * $rate / $raw, 1) . '%'));
        }
        foreach ($db->all('SELECT c.code, b.units_loaded, b.unknown_units, b.unlinked_units FROM sales_import_batch b JOIN channel c ON c.id = b.channel_id ORDER BY c.code') as $r) {
            self::note(sprintf('%s: %d units loaded, %d unknown (no listing), %d unlinked (no item) = %.1f%% not counted', $r['code'], $r['units_loaded'], $r['unknown_units'],
                $r['unlinked_units'], 100 * ((int) $r['unknown_units'] + (int) $r['unlinked_units']) / max(1, (int) $r['units_loaded'])));
        }
        self::assertInstanceOf(Db::class, $db);
        self::assertSame(1, (int) TestDb::server()->value('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$schema]));
    }

    /** @return array{code: int, out: string, err: string} */
    private static function cli(string $script, string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/{$script}", ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
