<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\WorkerPool;

/**
 * §14 hammer: parallel reserves from 3 simulated sites, each worker its own DB connection.
 * The pool is bounded at WorkerPool::MAX_WORKERS connections (the staging cluster allows 76
 * in total, shared by every slot), so "200 parallel reserves" = 200 reserves spread over 12
 * concurrent connections that all start at the same instant.
 */
final class HammerTest extends StockTestCase
{
    private const WORKERS_PER_SITE = 4;

    /** @return list<Caller> */
    private function threeSites(): array
    {
        return [$this->site('vpg'), $this->site('alt'), $this->site('vbig')];
    }

    /** @param array<string, mixed> $extra */
    private static function op(Caller $site, string $op, string $ref, array $extra): array
    {
        return ['op' => $op, 'channel_id' => $site->channelId, 'channel_code' => substr($site->actor, 8), 'order_ref' => $ref,
            'key' => "{$op}-{$site->channelId}-{$ref}"] + $extra;
    }

    /**
     * Groups ops by site and spreads each site's ops over its own workers.
     *
     * @param array<int, list<array<string, mixed>>> $bySite channel id => ops
     * @return list<list<array<string, mixed>>>
     */
    private static function jobs(array $bySite): array
    {
        $jobs = [];
        foreach ($bySite as $ops) {
            array_push($jobs, ...WorkerPool::spread($ops, self::WORKERS_PER_SITE));
        }
        return $jobs;
    }

    public function testTwoHundredParallelReservesFromThreeSitesHoldExactlyTheTenUnitsOnHand(): void
    {
        $sites = $this->threeSites();
        $sku = $this->item('strict', 10);
        $bySite = [];
        foreach ($sites as $site) {
            $this->listing($site, 'LAST10', $sku);
        }
        for ($i = 0; $i < 200; $i++) {
            $site = $sites[$i % 3];
            $bySite[$site->channelId][] = self::op($site, 'reserve', (string) (1000 + $i),
                ['lines' => [self::line('LAST10', "u{$i}")]]);
        }
        $out = WorkerPool::run(self::jobs($bySite));

        self::assertCount(200, $out['results']);
        $byStatus = array_count_values(array_map(static fn (array $r): string => $r['status'] . ':' . ($r['result'] ?? $r['error'] ?? ''), $out['results']));
        ksort($byStatus);
        self::assertSame(['201:held' => 10, '409:refused' => 190], $byStatus, json_encode(array_slice($out['results'], 0, 5)));
        self::assertSame(0, $out['deadlocks'], 'one hot row, one lock order: no deadlocks');
        $this->assertBal(10, 0, 10, $sku);
        self::assertSame(10, self::$db->value("SELECT COUNT(*) FROM reservation WHERE status = 'held'"));
        self::assertSame(10, self::$db->value("SELECT COUNT(*) FROM reservation_unit WHERE state = 'held'"));
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM reservation WHERE status <> 'held'"), 'a refused new order leaves no row');
        foreach ($sites as $site) {
            self::assertSame(0, $this->view($site, 'LAST10')['available']);
            self::assertSame('out_of_stock', $this->view($site, 'LAST10')['state']);
        }
    }

    public function testMultiLineOrdersInRandomOrderAreAllOrNothingWithoutDeadlocks(): void
    {
        $seed = random_int(1, PHP_INT_MAX);
        mt_srand($seed);
        $msg = "seed {$seed}";
        $sites = $this->threeSites();

        // five strict items, a duplicate listing of item 1, a "10 x" listing of item 2, a legacy item
        $onHand = [1 => 9, 2 => 45, 3 => 7, 4 => 12, 5 => 6];
        $skus = [];
        foreach ($onHand as $n => $qty) {
            $skus[$n] = $this->item('strict', $qty);
        }
        $legacy = $this->item('legacy', 0);
        /** @var array<string, array{0: int, 1: int}> $variants variant => [sku, u] */
        $variants = ['I1' => [$skus[1], 1], 'I1DUP' => [$skus[1], 1], 'I2' => [$skus[2], 1], 'I2X10' => [$skus[2], 10],
            'I3' => [$skus[3], 1], 'I4' => [$skus[4], 1], 'I5' => [$skus[5], 1], 'LEG' => [$legacy, 1]];
        foreach ($sites as $site) {
            foreach ($variants as $v => [$sku, $u]) {
                $this->listing($site, $v, $sku, $u);
            }
        }

        $orders = [];
        $bySite = [];
        $unit = 0;
        for ($i = 0; $i < 150; $i++) {
            $site = $sites[$i % 3];
            $names = array_keys($variants);
            shuffle($names);
            $lines = [];
            foreach (array_slice($names, 0, mt_rand(2, 4)) as $v) {
                $units = [];
                for ($q = $v === 'I2X10' ? 1 : mt_rand(1, 3); $q > 0; $q--) {
                    $units[] = 'x' . (++$unit);
                }
                $lines[] = self::line($v, ...$units);
            }
            shuffle($lines); // the request's line order is random; CW must lock in its own order
            $ref = (string) (5000 + $i);
            $orders[$ref] = ['site' => $site, 'lines' => $lines];
            $bySite[$site->channelId][] = self::op($site, 'reserve', $ref, ['lines' => $lines]);
        }

        $out = WorkerPool::run(self::jobs($bySite));
        self::assertSame(0, $out['deadlocks'], "no deadlocks ({$msg})");
        self::assertCount(150, $out['results']);
        $held = [];
        foreach ($out['results'] as $r) {
            self::assertContains($r['status'], [201, 409], $msg . ' ' . json_encode($r));
            $ref = (string) $r['order_ref'];
            $o = $orders[$ref];
            $unitIds = array_merge(...array_column($o['lines'], 'unit_ids'));
            $states = self::$db->column(
                'SELECT state FROM reservation_unit WHERE channel_id = ? AND unit_id IN (' . implode(',', array_fill(0, count($unitIds), '?')) . ')',
                [$o['site']->channelId, ...$unitIds],
            );
            if ($r['status'] === 201) {
                self::assertSame(array_fill(0, count($unitIds), 'held'), $states, "order {$ref}: every unit held ({$msg})");
                $held[$ref] = $o;
            } else {
                self::assertSame([], $states, "order {$ref}: refused means nothing held ({$msg})");
                self::assertNull($this->reservation($o['site'], $ref));
            }
        }
        self::assertNotEmpty($held, $msg);
        self::assertLessThan(150, count($held), "some orders must have been refused ({$msg})");
        foreach ($skus as $n => $sku) {
            $b = $this->bal($sku);
            self::assertLessThanOrEqual($onHand[$n], $b['held'], "strict item {$n} never over-held ({$msg})");
            self::assertSame($onHand[$n], $b['on_hand']);
        }

        // phase 2: pay half of the held orders and abandon the rest, all at once
        $bySite = [];
        $n = 0;
        foreach ($held as $ref => $o) {
            $ref = (string) $ref;
            $bySite[$o['site']->channelId][] = ($n++ % 2 === 0)
                ? self::op($o['site'], 'commit', $ref, ['lines' => $o['lines'], 'origin' => 'reserved'])
                : self::op($o['site'], 'release', $ref, ['attempt' => 1]);
        }
        $out2 = WorkerPool::run(self::jobs($bySite));
        self::assertSame(0, $out2['deadlocks'], "no deadlocks in phase 2 ({$msg})");
        foreach ($out2['results'] as $r) {
            self::assertSame(200, $r['status'], $msg . ' ' . json_encode($r));
        }
        foreach ([...$skus, $legacy] as $sku) {
            self::assertSame(0, $this->bal($sku)['held'], $msg);
        }
        self::assertSame(0, self::$db->value('SELECT COUNT(*) FROM oversell_event'), "held units never oversell ({$msg})");
    }
}
