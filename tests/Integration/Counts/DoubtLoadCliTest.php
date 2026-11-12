<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Counts;

use CW\Schema\Grants;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * bin/doubt_load.php (docs/decisions.md DS1-), run as production runs it (the app login): a dry run by default that prints what it would do and
 * writes nothing; --apply writes the list in one transaction with one audit row; a list naming an item CW does not have is refused whole (exit
 * 1, nothing written); a wrong command line is a usage error (exit 2) before anything is read.
 */
final class DoubtLoadCliTest extends IntegrationTestCase
{
    /** @var list<string> */
    private array $files = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // The job runs as the app login, as on production: its rights on the new table come from the grants (bin/migrate.php applies them).
        $user = TestDb::config()->appDbUser();
        self::assertNotNull($user);
        Grants::apply(self::$db, TestDb::name(), $user);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    /** @return array{code: int, out: string, err: string} */
    private static function cli(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/doubt_load.php", '--db=' . TestDb::name(), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    private function csv(string $text): string
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'doubtcli');
        file_put_contents($f, $text);
        $this->files[] = $f;
        return $f;
    }

    public function testADryRunThenTheLoad(): void
    {
        self::makeChannel('vapeandgo');
        $a = self::makeSku('Xros pods');
        $b = self::makeSku('Cool Mint');
        $codeA = sprintf('CW-%06d', $a);
        $codeB = sprintf('CW-%06d', $b);
        $file = $this->csv("cw_code,shop_qty,cw_qty,reason,sold_30d,note,product\n{$codeA},75,112,differs,182,,\"Xros, pods\"\n{$codeB},,-2,below_zero,42,check it,Mint\n");

        $dry = self::cli('--store=vapeandgo', '--csv=' . $file, '--batch=list_12nov');
        self::assertSame(0, $dry['code'], $dry['err'] . $dry['out']);
        self::assertStringContainsString('DRY RUN (nothing written)', $dry['out']);
        self::assertStringContainsString("row 1 {$codeA} add differs shop=75 cw=112 sold=182", $dry['out']);
        self::assertStringContainsString("row 2 {$codeB} add below_zero shop=- cw=-2 sold=42", $dry['out']);
        self::assertStringContainsString('add=2 refresh=0 same=0 skip=0', $dry['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_doubt'));

        $run = self::cli('--store=vapeandgo', '--csv=' . $file, '--batch=list_12nov', '--apply');
        self::assertSame(0, $run['code'], $run['err'] . $run['out']);
        self::assertStringNotContainsString('DRY RUN', $run['out']);
        self::assertStringContainsString('add=2 refresh=0 same=0 skip=0', $run['out']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM stock_doubt WHERE status = 'open' AND batch = 'list_12nov'"));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'stock_doubt.load' AND actor = 'system:doubt_load'"));
        $again = self::cli('--store=vapeandgo', '--csv=' . $file, '--batch=list_12nov', '--apply');
        self::assertStringContainsString('add=0 refresh=0 same=2 skip=0', $again['out'], 'never doubled');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM stock_doubt'));
    }

    public function testAListThatCannotBeLoadedWritesNothing(): void
    {
        self::makeChannel('vapeandgo');
        $a = sprintf('CW-%06d', self::makeSku('Known pod'));
        $file = $this->csv("cw_code,shop_qty,cw_qty,reason,sold_30d,note\n{$a},1,2,differs,3,\nCW-999999,1,2,differs,3,\n");
        $r = self::cli('--store=vapeandgo', '--csv=' . $file, '--batch=list_12nov', '--apply');
        self::assertSame(1, $r['code'], $r['out']);
        self::assertStringContainsString('row 2', $r['err']);
        self::assertStringContainsString('nothing written', $r['err']);
        $r = self::cli('--store=vapeandgo', '--csv=' . $this->csv("cw_code,cw_qty\n{$a},2\n"), '--batch=list_12nov', '--apply');
        self::assertSame(1, $r['code'], $r['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_doubt'));

        foreach ([
            ['--csv=' . $file, '--batch=list_12nov'],                                   // no store
            ['--store=nowhere', '--csv=' . $file, '--batch=list_12nov'],                // no such store
            ['--store=vapeandgo', '--batch=list_12nov'],                                // no file
            ['--store=vapeandgo', '--csv=/no/such/file.csv', '--batch=list_12nov'],     // a file that is not there
            ['--store=vapeandgo', '--csv=' . $file],                                    // no list name
            ['--store=vapeandgo', '--csv=' . $file, '--batch=list_12nov', '--aply'],     // a misspelt option
            ['--store=vapeandgo', '--csv=' . $file, '--batch=list_12nov', '--apply=no'], // a value on a flag
        ] as $args) {
            $r = self::cli(...$args);
            self::assertSame(2, $r['code'], implode(' ', $args) . ': ' . $r['err'] . $r['out']);
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_doubt'));
    }
}
