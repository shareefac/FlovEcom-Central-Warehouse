<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\CostEngine;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The Codex re-check of e5d2e5a (build/A/codex_r5c.txt), the tools, each shown failing first: an ERPNext history import that refuses a
 * broken day file ends with a problem (exit 1), never "done" (exit 0); the start of the buying prices stopped after its mark goes on with
 * the replay when it is run again the same way.
 */
final class ProfitToolsReviewR5Test extends StockTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_r5tools_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return array{code: int, out: string, err: string} */
    private static function script(string $name, string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', "{$root}/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    public function testARefusedErpNextImportEndsWithAProblem(): void
    {
        $this->site('vapeandgo', 'live');
        $head = "name\tposting_date\tcreation\tcustom_order_id\tis_return\treturn_against\tdocstatus\tnet_total\ttotal_taxes_and_charges\tgrand_total\t"
            . "discount_amount\tidx\titem_code\tqty\trate\tnet_rate\tnet_amount\tincoming_rate\twarehouse\n";
        file_put_contents($this->dir . '/si_2026-04-12.tsv', $head . "SI-5\t2026-04-12\tcut short\n");
        $r = self::script('import_erp_sales_cost', '--dir=' . $this->dir);
        self::assertSame(1, $r['code'], 'a day refused: a problem, so a go-live step that checks the exit code stops; ' . $r['out'] . $r['err']);
        self::assertStringContainsString('refused', $r['out'] . $r['err']);
        // A whole day: done.
        file_put_contents($this->dir . '/si_2026-04-12.tsv', $head);
        self::assertSame(0, self::script('import_erp_sales_cost', '--dir=' . $this->dir)['code']);
    }

    public function testTheStartStoppedAfterItsMarkGoesOnWithTheReplay(): void
    {
        $bin = $this->dir . '/erp_bin.tsv';
        file_put_contents($bin, "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n");
        $first = self::script('seed_item_cost', '--bin=' . $bin, '--apply');
        self::assertSame(0, $first['code'], $first['out'] . $first['err']);
        // Stopped after the start was written (its mark), before the replay ran: the same command again goes on with the replay.
        self::$db->exec('UPDATE profit_job SET ran_at = NULL WHERE job = ?', [CostEngine::JOB]);
        $again = self::script('seed_item_cost', '--bin=' . $bin, '--apply');
        self::assertSame(0, $again['code'], 'never "started already": ' . $again['out'] . $again['err']);
        self::assertStringContainsString('replay', $again['out'] . $again['err']);
    }
}
