<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The final review of 6d59eda (build/A/codex_final_astra_{a,b,c}.txt), the tools, each shown failing first: the start refuses a broken Bin
 * file unless the rows are named (b-HIGH3); an import that finds another import holding the job's lock reads nothing and says so with its
 * own exit code (a-M4); the night's full read of the cost job waits for a run that holds the lock instead of being skipped (a-M4); the
 * day summaries' go-live check compares and writes nothing (c-LOW4, a-M8); the money job works out what the API stored (a-HIGH2).
 */
final class FinalReviewToolsTest extends StockTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_finaltools_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return list<string> */
    private static function command(string $name, string ...$args): array
    {
        return [PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . "/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args];
    }

    /** @return array{code: int, out: string} */
    private static function script(string $name, string ...$args): array
    {
        $p = proc_open(self::command($name, ...$args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    public function testTheStartRefusesABrokenBinFileUnlessItsRowsAreNamed(): void
    {
        $bin = $this->dir . '/erp_bin.tsv';
        file_put_contents($bin, "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\nP500\tStores - VPG\t30.000000000\n");
        $r = self::script('seed_item_cost', '--bin=' . $bin, '--apply');
        self::assertSame(1, $r['code'], 'a broken Bin row: a problem, nothing written; ' . $r['out']);
        self::assertStringContainsString('Bin row 2 (P500) is broken', $r['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_cost'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM profit_job'));
        $r = self::script('seed_item_cost', '--bin=' . $bin, '--allow-skips=P500', '--apply');
        self::assertSame(0, $r['code'], $r['out']);
        self::assertStringContainsString('left out as --allow-skips says', $r['out']);
    }

    public function testAnImportThatFindsTheLockHeldSaysSo(): void
    {
        $this->site('vapeandgo', 'live');
        $file = $this->dir . '/snapshots_vapeandgo_2026-11-19.jsonl';
        file_put_contents($file, '');
        file_put_contents($this->dir . '/snapshots_vapeandgo_2026-11-19.sum', json_encode(['channel' => 'vapeandgo', 'day' => '2026-11-19', 'orders' => 0, 'lines' => 0,
            'goods_inc' => 0, 'delivery_inc' => 0, 'file_sha256' => hash_file('sha256', $file)]));
        $other = TestDb::connect();
        $name = 'cw_job:import_order_snapshots:' . TestDb::name();
        $name = strlen($name) > 64 ? 'cw_job:' . sha1("import_order_snapshots\0" . TestDb::name()) : $name;
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [$name]));
        try {
            $r = self::script('import_order_snapshots', '--channel=vapeandgo', '--file=' . $file);
            self::assertSame(4, $r['code'], 'another import holds the lock: nothing read, never "done" (exit 0); ' . $r['out']);
            self::assertStringContainsString('not run', $r['out']);
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [$name]);
        }
        self::assertSame(0, self::script('import_order_snapshots', '--channel=vapeandgo', '--file=' . $file)['code']);
    }

    public function testTheNightsFullReadWaitsForTheLock(): void
    {
        $other = TestDb::connect();
        $name = 'cw_job:item_cost:' . TestDb::name();
        $name = strlen($name) > 64 ? 'cw_job:' . sha1("item_cost\0" . TestDb::name()) : $name;
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [$name]));
        $p = proc_open(self::command('refresh_item_cost', '--reconcile'), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        sleep(2);
        $other->value('SELECT RELEASE_LOCK(?)', [$name]);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($p), $out);
        self::assertStringNotContainsString('skipped', $out, 'the night\'s full read is never skipped because a run of every ten minutes held the lock');
        self::assertStringContainsString('not started yet', $out, 'it ran once the lock was free');
    }

    public function testTheGoLiveCheckOfTheDaySummariesWritesNothing(): void
    {
        $site = $this->site('vapeandgo', 'live');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, 'C', ['v' => 1, 'version' => 1, 'order_no' => '1', 'status' => 'completed',
            'placed_at' => '2026-11-19T09:00:00Z', 'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'pp', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => 'C1', 'variant_id' => 'NONE', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
                'free' => false]], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 1200, 'parcels' => []], 'bulk');
        $days = ['--from=2026-11-19', '--to=2026-11-19'];
        self::assertSame(0, self::script('rebuild_profit_days', ...$days)['code'], 'the summaries are what the orders add up to');
        self::$db->exec("UPDATE profit_day SET cost = 900 WHERE channel_id = ? AND day = '2026-11-19'", [$site->channelId]);
        $r = self::script('rebuild_profit_days', ...[...$days, '--check']);
        self::assertSame(1, $r['code'], 'a day differs: a problem; ' . $r['out']);
        self::assertStringContainsString('cost kept 900, worked out 0 (-900)', $r['out']);
        self::assertSame('900', (string) self::$db->value("SELECT cost FROM profit_day WHERE channel_id = ? AND day = '2026-11-19'", [$site->channelId]),
            'the check writes nothing');
        self::assertSame(0, self::script('rebuild_profit_days', ...[...$days, '--apply'])['code']);
        self::assertSame('0', (string) self::$db->value("SELECT cost FROM profit_day WHERE channel_id = ? AND day = '2026-11-19'", [$site->channelId]));
        self::assertSame(0, self::script('rebuild_profit_days', ...$days)['code']);
    }

    public function testTheMoneyJobWorksOutWhatTheApiStored(): void
    {
        $site = $this->site('vapeandgo', 'live');
        (new OrderSnapshots(self::$db))->accept((int) $site->channelId, 'Q', ['v' => 1, 'version' => 1, 'order_no' => '1', 'status' => 'completed',
            'placed_at' => '2026-11-19T09:00:00Z', 'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'pp', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => 'Q1', 'variant_id' => 'NONE', 'qty' => 1, 'price_inc' => 1200, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
                'free' => false]], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 1200, 'parcels' => []], 'api');
        self::assertStringContainsString('waiting 1', self::script('apply_order_snapshots', '--status')['out']);
        $r = self::script('apply_order_snapshots');
        self::assertSame(0, $r['code'], $r['out']);
        self::assertStringContainsString('applied 1', $r['out']);
        self::assertSame('1000', (string) self::$db->value("SELECT sale_ex FROM sale_order WHERE order_ref = 'Q'"));
    }
}
