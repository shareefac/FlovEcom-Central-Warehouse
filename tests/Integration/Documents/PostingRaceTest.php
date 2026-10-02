<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Documents;

use CW\Tests\Support\Documents\DocWorkerPool;

/**
 * I21 under real concurrency: 8 doc_worker processes post fixture ADJ documents (1-4 lines over 6 items, MAIN and
 * VERIFY, random line order) while 4 others make staff stock movements on the same items. The posting's lock order
 * (document row -> number series -> stock_balance -> sku -> value clocks -> feed clock; nothing with a foreign key
 * after the stock locks) meets the stock core's without a cycle: no deadlock surfaces, the numbers are gapless, every
 * document's ledger rows carry its id and number, and every invariant holds (StockTestCase, DocumentInvariants included).
 */
final class PostingRaceTest extends DocumentTestCase
{
    private const POSTERS = 8;
    private const MOVERS = 4;
    private const DOCS_EACH = 8;
    private const MOVES_EACH = 15;

    public function testPostingsMixedWithStockMovesNeverDeadlockAndStayGapless(): void
    {
        mt_srand(20261002);
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->item('legacy', 1000);
        }
        $poster = (int) $this->staffUser('stock_controller')->staffUserId;
        $mover = (int) $this->staffUser('stock_controller')->staffUserId;
        $jobs = [];
        for ($w = 0; $w < self::POSTERS; $w++) {
            $ops = [];
            for ($d = 0; $d < self::DOCS_EACH; $d++) {
                $pick = $items;
                shuffle($pick);
                $lines = [];
                foreach (array_slice($pick, 0, mt_rand(1, 4)) as $sku) {
                    $lines[] = ['sku_id' => $sku, 'qty' => (mt_rand(0, 1) === 0 ? -1 : 1) * mt_rand(1, 5), 'warehouse' => mt_rand(0, 1) === 0 ? 'MAIN' : 'VERIFY'];
                }
                $ops[] = ['op' => 'post', 'staff_id' => $poster, 'header' => ['external_ref' => "RACE-{$w}-{$d}"], 'lines' => $lines];
            }
            $jobs[] = $ops;
        }
        for ($w = 0; $w < self::MOVERS; $w++) {
            $ops = [];
            for ($m = 0; $m < self::MOVES_EACH; $m++) {
                $pick = $items;
                shuffle($pick);
                $lines = [];
                foreach (array_slice($pick, 0, mt_rand(1, 3)) as $i => $sku) {
                    $lines[] = ['sku_id' => $sku, 'qty' => (mt_rand(0, 1) === 0 ? -1 : 1) * mt_rand(1, 3), 'line_index' => $i,
                        'warehouse' => mt_rand(0, 1) === 0 ? 'MAIN' : 'VERIFY'];
                }
                $ops[] = ['op' => 'move', 'staff_id' => $mover, 'key' => "race-move-{$w}-{$m}", 'request' => ['type' => 'adjustment', 'lines' => $lines]];
            }
            $jobs[] = $ops;
        }
        $started = hrtime(true);
        $run = DocWorkerPool::run($jobs);
        $ms = intdiv(hrtime(true) - $started, 1_000_000);

        $posted = [];
        foreach ($run['results'] as $i => $results) {
            foreach ($results as $r) {
                self::assertSame(200, $r['status'], "worker {$i}: " . json_encode($r));
                if ($r['op'] === 'post') {
                    self::assertSame('posted', $r['state']);
                    $posted[$r['id']] = $r['number'];
                } else {
                    self::assertSame('recorded', $r['result']);
                }
            }
        }
        self::assertCount(self::POSTERS * self::DOCS_EACH, $posted);
        $numbers = array_values($posted);
        sort($numbers);
        self::assertSame(array_map(static fn (int $n): string => sprintf('ADJ-%06d', $n), range(1, count($posted))), $numbers, 'gapless');
        self::assertSame(count($posted), (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'ADJ'"));
        foreach ($posted as $id => $number) {
            $lines = count($this->docs->lines($id));
            $rows = self::$db->all('SELECT DISTINCT doc_ref, idem_key FROM stock_ledger WHERE document_id = ?', [$id]);
            self::assertSame([['doc_ref' => $number, 'idem_key' => "doc:{$id}:post"]], $rows, "document {$id}");
            self::assertSame($lines, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$id]), "document {$id}: one row per line");
            self::assertSame('pending', $this->docs->get($id)->reviewState);
        }
        // Deadlocks a posting or a move retried (Db::transaction) are allowed; none may surface as an error (all 200 above).
        self::assertLessThan(10, $run['deadlocks'], 'retried deadlocks stay rare');
        fwrite(STDERR, sprintf("\n[doc-race] %d postings + %d staff moves in %d ms (incl. 1.5 s start delay); deadlocks retried %d, surfaced 0\n",
            count($posted), self::MOVERS * self::MOVES_EACH, $ms, $run['deadlocks']));
    }
}
