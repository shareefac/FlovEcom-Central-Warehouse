<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Documents;

use CW\Tests\Support\Documents\DocWorkerPool;
use CW\Tests\Support\IntegrationTestCase;

/**
 * I20 under real concurrency: 12 doc_worker processes take 100 ADJ numbers each, each in its own transaction, and
 * roll every 10th back after taking it. The committed numbers are unique and exactly 1..N (N = 1,080), the series says
 * N, and no deadlock surfaced (a series row is the only lock an allocation takes).
 */
final class NumberSeriesRaceTest extends IntegrationTestCase
{
    public function testTwelveWorkersGetUniqueGaplessNumbers(): void
    {
        $jobs = array_fill(0, 12, [['op' => 'series', 'prefix' => 'ADJ', 'count' => 100, 'rollback_every' => 10]]);
        $started = hrtime(true);
        $run = DocWorkerPool::run($jobs);
        $ms = intdiv(hrtime(true) - $started, 1_000_000);
        $numbers = [];
        foreach ($run['results'] as $i => $results) {
            self::assertCount(1, $results);
            self::assertSame(200, $results[0]['status'], "worker {$i}: " . json_encode($results[0]));
            self::assertSame(10, $results[0]['rolled_back']);
            self::assertCount(90, $results[0]['numbers']);
            $mine = array_map(static fn (string $n): int => (int) substr($n, 4), $results[0]['numbers']);
            self::assertSame($mine, array_values(array_unique($mine)));
            $sorted = $mine;
            sort($sorted);
            self::assertSame($sorted, $mine, "worker {$i}: its own numbers go up");
            array_push($numbers, ...$results[0]['numbers']);
        }
        self::assertSame(0, $run['deadlocks'], 'no deadlock retried');
        sort($numbers);
        self::assertSame(array_map(static fn (int $n): string => sprintf('ADJ-%06d', $n), range(1, 1080)), $numbers, 'unique and exactly 1..N');
        self::assertSame(1080, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'ADJ'"));
        fwrite(STDERR, "\n[series-race] 1,200 allocations (120 rolled back) by 12 workers in {$ms} ms (incl. 1.5 s start delay); 0 deadlocks\n");
    }
}
