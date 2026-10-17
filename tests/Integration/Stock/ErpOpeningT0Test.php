<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\CwException;
use CW\Ops\ErpOpening;
use CW\Ops\ErpOpeningFiles;
use CW\Ops\ErpOpeningRules;
use CW\Ops\OpeningEstimate;
use CW\Ops\OpeningRebase;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use DateTimeImmutable;

/**
 * A store's T0 after the opening came FROM ERPNext (pack I, docs/decisions.md PI14): the rebase's units-only mode keeps the ERPNext
 * figure (free stock: every sale is out of it) and adds only the open paid units the store's opening_orders put into `allocated`, so
 * available equals the ERPNext figure and, once those units have shipped, on-hand does too. Made-up files only.
 */
final class ErpOpeningT0Test extends StockTestCase
{
    private const ERP = 'erp-opening:vpg:2026-09-26';
    private const T0 = ['at' => '2026-09-26T17:00:00Z', 'last_order_id' => 900, 'last_stock_log_id' => 5501];
    private const REBASE = 'opening-rebase:vpg:20260926T170000Z';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_erp_t0_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private static function sys(): Caller
    {
        return Caller::system(OpeningEstimate::ACTOR_JOB);
    }

    private function available(int $sku): int
    {
        $b = $this->bal($sku);
        return $b['on_hand'] - $b['allocated'] - $b['held'];
    }

    /** The ERPNext opening of made-up figures: item code => [variant, qty, rate]. @param array<string, array{0: string, 1: int, 2: string}> $lines */
    private function erpOpening(array $lines, ?string $replaces = null): void
    {
        $stock = "\"Item\",\"Warehouse\",\"Balance Qty\",\"Valuation Rate\",\"Company\"\n";
        $items = "\"ID\",\"Product ID\"\n";
        foreach ($lines as $code => [$variant, $qty, $rate]) {
            $stock .= "\"{$code}\",\"Stores - VPG\",{$qty}.0,{$rate},\"VAPE AND GO\"\n";
            $items .= "\"{$code}\",\"{$variant}\"\n";
        }
        file_put_contents($s = $this->dir . '/sb.csv', $stock);
        file_put_contents($i = $this->dir . '/items.csv', $items);
        $op = new ErpOpening(self::$db, $this->moves, new ErpOpeningRules(['Stores - VPG'], ['VAPE AND GO'], ['VPG 2'], 'zero', ['1.000000'], 'vpg', 'MAIN', false));
        $plan = $op->plan(ErpOpeningFiles::stock($s), ErpOpeningFiles::itemMap($i), null, self::ERP, new DateTimeImmutable('2000-01-01T00:00:00Z'), $replaces);
        $op->apply(self::sys(), $plan, self::ERP, 'opening stock from ERPNext: test');
    }

    private function rebase(string $doc = self::REBASE, ?string $opening = self::ERP): array
    {
        return (new OpeningRebase(self::$db))->plan('vpg', [], $doc, $opening, null, true);
    }

    private function bookRebase(array $lines): array
    {
        return (new OpeningEstimate(self::$db, $this->moves))->apply(self::sys(), $lines, self::REBASE, 'opening rebase, units only (test)', 'MAIN', null,
            OpeningRebase::DOC_TYPE, [self::ERP]);
    }

    public function testTheUnitsOnlyRebaseKeepsTheErpnextFigureAndAddsTheOpenPaidUnits(): void
    {
        $site = $this->site('vpg', 'shadow');
        [$a, $b, $c, $d] = [$this->item('legacy'), $this->item('legacy'), $this->item('legacy'), $this->item('legacy')];
        $this->listing($site, 'A', $a);
        $this->listing($site, 'B10', $b, 10);     // a 10-pack listing: ERPNext's item is the pack
        $this->listing($site, 'C', $c);
        $this->listing($site, 'D', $d);
        // ERPNext's figures, every sale already out of them; C is below zero and opens at zero; D has no open order.
        $this->erpOpening(['A' => ['A', 10, '2'], 'B' => ['B10', 2, '9'], 'C' => ['C', -3, '1.5'], 'D' => ['D', 4, '3']]);
        $this->assertBal(10, 0, 0, $a);
        $this->assertBal(20, 0, 0, $b);

        // The store's open paid orders at T0 go to `allocated` only: without the rebase available reads low by them.
        $this->ok($this->res->openingOrders($site, [
            ['order_ref' => '901', 'lines' => [self::line('A', 'a1', 'a2', 'a3'), self::line('B10', 'b1')]],
            ['order_ref' => '902', 'lines' => [self::line('C', 'c1', 'c2')]],
        ], true, $this->key('opening'), self::T0));
        self::assertSame([7, 10, -2, 4], [$this->available($a), $this->available($b), $this->available($c), $this->available($d)]);
        $this->ship($site, '901', ['a1']);        // one ships before the rebase runs

        $plan = $this->rebase();
        self::assertSame([$a => 3, $b => 10, $c => 2], $plan['lines'], 'only the open paid units, x u');
        $r = $plan['report'];
        self::assertTrue($r['units_only']);
        self::assertSame(['items' => 3, 'up' => 15, 'down' => 0, 'net' => 15], $r['to_book']);
        self::assertSame(3, $r['opening_items'], 'items carrying the named opening (A, B, D)');
        self::assertSame(['booked_items' => 3, 'booked_units' => 15, 'replayed_items' => 0], $this->bookRebase($plan['lines']));

        // available = the ERPNext figure (C: zero), whatever shipped before the rebase.
        self::assertSame([10, 20, 0, 4], [$this->available($a), $this->available($b), $this->available($c), $this->available($d)]);
        self::assertSame(2, $this->view($site, 'B10')['available'], 'two packs');
        $this->ship($site, '901', ['a2', 'a3', 'b1']);
        $this->ship($site, '902', ['c1', 'c2']);
        $this->assertBal(10, 0, 0, $a);
        $this->assertBal(20, 0, 0, $b);
        $this->assertBal(0, 0, 0, $c);
        $this->assertBal(4, 0, 0, $d);

        // A re-run books nothing; the ERPNext opening keeps its cost rows untouched.
        $again = $this->rebase();
        self::assertSame([], $again['lines']);
        self::assertSame(['items' => 3, 'units' => 15], $again['report']['already_rebased']);
        self::assertSame(['2.000000'], array_map('strval', self::$db->column('SELECT unit_cost FROM stock_ledger WHERE doc_ref = ? AND sku_id = ?', [self::ERP, $a])));
        // The normal rebase with the ERPNext opening named would put the store's own figure back: units-only is the one to use.
        self::assertSame('bad_mode', $this->code(fn () => (new OpeningRebase(self::$db))->plan('vpg', [['A', 1]], 'opening-rebase:vpg:x', self::ERP, null, true)));
        self::assertSame('bad_mode', $this->code(fn () => (new OpeningRebase(self::$db))->plan('vpg', [], 'opening-rebase:vpg:x', null, null, true)));
    }

    public function testUnitsOnlyRebaseIncludesTheOpeningReplacedByErpnext(): void
    {
        $site = $this->site('vpg', 'shadow');
        $sku = $this->item('legacy');
        $this->listing($site, 'A', $sku);
        $estimate = 'opening:vpg:estimate';
        (new OpeningEstimate(self::$db, $this->moves))->apply(self::sys(), [$sku => 8], $estimate, 'earlier estimate (test)');
        $this->erpOpening(['A' => ['A', 11, '2']], $estimate);
        $this->assertBal(11, 0, 0, $sku);
        self::assertSame(3, (int) self::$db->value("SELECT SUM(qty_delta) FROM stock_ledger WHERE doc_ref = ? AND bucket = 'on_hand'", [self::ERP]));
        $this->ok($this->res->openingOrders($site, [['order_ref' => '901', 'lines' => [self::line('A', 'a1', 'a2', 'a3')]]],
            true, $this->key('opening'), self::T0));

        $plan = $this->rebase();
        self::assertSame([$sku => 3], $plan['lines']);
        self::assertSame(11, $plan['report']['earlier'], 'eight under the estimate plus net three under ERPNext');
        self::assertSame(0, $plan['report']['skipped']['items']);
        self::assertSame(3, $this->bookRebase($plan['lines'])['booked_units'], 'the in-transaction guard accepts that same ancestry');
        $this->ship($site, '901', ['a1', 'a2', 'a3']);
        $this->assertBal(11, 0, 0, $sku);
        self::assertSame([], $this->rebase()['lines']);
    }

    public function testItemsWithAnotherOpeningOrHistoryAreSkippedAndTheCliRunsWithoutAFile(): void
    {
        $site = $this->site('vpg', 'shadow');
        [$a, $b, $c] = [$this->item('legacy'), $this->item('legacy'), $this->item('legacy')];
        $this->listing($site, '101', $a);
        $this->listing($site, '102', $b);
        $this->listing($site, '103', $c);
        $this->erpOpening(['A' => ['101', 5, '2'], 'C' => ['103', 6, '2']]);
        (new OpeningEstimate(self::$db, $this->moves))->apply(self::sys(), [$b => 4], 'opening:vpg:estimate', 'estimate (test)');
        $this->book('goods_in', $c, 2);          // a delivery after the opening: `moved`, a person settles it
        $this->ok($this->res->openingOrders($site, [['order_ref' => '901', 'lines' => [self::line('101', 'a1'), self::line('102', 'b1'), self::line('103', 'c1')]]],
            true, $this->key('opening'), self::T0));

        $plan = $this->rebase();
        self::assertSame([$a => 1], $plan['lines']);
        self::assertSame(['moved' => 1, 'other_opening' => 1], $plan['report']['skipped']['why']);

        $args = ['--rebase', '--units-only', '--estimate-doc-ref=' . self::ERP, '--channel=vpg', '--source=made-up T0 (test)'];
        self::assertSame(2, self::script([...$args, '--csv=' . __FILE__, '--dry-run'])['code'], 'units-only reads no file');
        self::assertSame(2, self::script(['--rebase', '--units-only', '--channel=vpg', '--source=x', '--dry-run'])['code'], 'it names the ERPNext opening');
        $dry = self::script([...$args, '--dry-run']);
        self::assertSame(0, $dry['code'], $dry['out'] . $dry['err']);
        self::assertStringContainsString('units only', $dry['out']);
        self::assertStringContainsString('to book: 1 items, +1 / -0 units (net +1)', $dry['out']);
        self::assertSame(1, self::script($args)['code'], 'a real run names its T0');
        $run = self::script([...$args, '--as-of=2026-09-26T17:00:00Z', '--approved-by=tester']);
        self::assertSame(0, $run['code'], $run['out'] . $run['err']);
        self::assertStringContainsString('booked 1 items, net +1 units', $run['out']);
        self::assertSame(5, $this->available($a));
        self::assertStringContainsString('units only', (string) self::$db->value('SELECT note FROM stock_ledger WHERE doc_ref = ? AND sku_id = ?', [self::REBASE, $a]));
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (CwException $e) {
            return $e->errorCode;
        }
        self::fail('expected a refusal');
    }

    /** @param list<string> $args @return array{code: int, out: string, err: string} */
    private static function script(array $args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/import_opening_estimate.php", '--db=' . TestDb::name(), '--admin', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
