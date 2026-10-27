<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Ops\ErpOpening;
use CW\Ops\ErpOpeningFiles;
use CW\Ops\ErpOpeningRules;
use CW\Output\XlsxWriter;
use CW\StockOps\CostHints;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use DateTimeImmutable;

/**
 * CW's opening stock and opening cost migrated from ERPNext (pack I, docs/decisions.md PI1-PI16): bin/import_erp_opening.php and
 * CW\Ops\ErpOpening, on MADE-UP export files shaped like ERPNext's Stock Balance report (never real ERPNext data).
 */
final class ErpOpeningTest extends StockTestCase
{
    private const DOC = 'erp-opening:vpg:2026-10-31';
    private const MAIN = 'Stores - VPG';
    private const VPG2 = 'VPG 2 Main warehouse - VPG2';
    private const CO = 'VAPE AND GO';
    /** The Stock Balance report's columns, as ERPNext v15/v16 exports them. */
    private const HEADER = ['Item', 'Item Name', 'Item Group', 'Warehouse', 'Stock UOM', 'Balance Qty', 'Balance Value', 'Opening Qty', 'Opening Value',
        'In Qty', 'In Value', 'Out Qty', 'Out Value', 'Valuation Rate', 'Company'];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_erp_opening_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------------------

    private static function rules(string $belowZero = 'zero', ?array $warehouses = null, bool $requirePending = false): ErpOpeningRules
    {
        return new ErpOpeningRules($warehouses ?? [self::MAIN], [self::CO], ['VPG 2'], $belowZero, ['0.000000', '1.000000'], 'vpg', 'MAIN', $requirePending);
    }

    private function op(?ErpOpeningRules $rules = null): ErpOpening
    {
        return new ErpOpening(self::$db, $this->moves, $rules ?? self::rules());
    }

    private static function sys(): Caller
    {
        return Caller::system(ErpOpening::ACTOR_JOB);
    }

    /** An as-of moment before every fixture row (ledger created_at is the database's real clock). */
    private static function longAgo(): DateTimeImmutable
    {
        return new DateTimeImmutable('2000-01-01T00:00:00Z');
    }

    /**
     * A Stock Balance export: one row per [item, warehouse, qty, rate, company?]; the value column is qty x rate; optional filter lines
     * on top (ERPNext's "with filters") and its Total line at the bottom.
     *
     * @param list<array{0: string, 1: string, 2: int|string, 3: string, 4?: string}> $rows
     * @param array<string, string> $filters
     */
    private function stockCsv(array $rows, array $filters = [], bool $total = true, ?int $totalQty = null, string $name = 'stock_balance.csv'): string
    {
        $q = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';
        $out = [];
        foreach ($filters as $k => $v) {
            $out[] = $q($k) . ',' . $q($v);
        }
        if ($filters !== []) {
            $out[] = '';
        }
        $out[] = implode(',', array_map($q, self::HEADER));
        $sum = 0.0;
        foreach ($rows as $r) {
            $qty = is_int($r[2]) ? $r[2] . '.0' : $r[2];
            $sum += (float) $r[2];
            $value = is_numeric($r[3]) && is_int($r[2]) ? sprintf('%.2f', $r[2] * (float) $r[3]) : '';
            $out[] = implode(',', [$q($r[0]), $q('Made-up ' . $r[0]), $q('E-Liquids'), $q($r[1]), $q('Nos'), $qty, $value, '0.0', '0.0', '0.0', '0.0', '0.0', '0.0',
                $r[3], $q($r[4] ?? self::CO)]);
        }
        if ($total) {
            $out[] = implode(',', [$q("'Total'"), '""', '""', '""', '""', sprintf('%.1f', $totalQty ?? $sum), '', '', '', '', '', '', '', '', '""']);
        }
        $path = $this->dir . '/' . $name;
        file_put_contents($path, implode("\r\n", $out) . "\r\n");
        return $path;
    }

    /** @param array<string, string> $map item code => product id ('' = none) */
    private function itemList(array $map, string $name = 'items.csv'): string
    {
        $lines = ['"ID","Item Name","Product ID"'];
        foreach ($map as $code => $pid) {
            $lines[] = '"' . $code . '","Made-up ' . $code . '","' . $pid . '"';
        }
        $path = $this->dir . '/' . $name;
        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    /** @param list<array{0: string, 1: int|string}> $rows variant id, qty */
    private function pendingCsv(array $rows, string $name = 'pending.csv'): string
    {
        $lines = ['variant_id,qty'];
        foreach ($rows as [$v, $q]) {
            $lines[] = "{$v},{$q}";
        }
        $path = $this->dir . '/' . $name;
        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    /** @return array<string, mixed> */
    private function plan(string $stock, ?string $items, ?string $pending = null, ?ErpOpeningRules $rules = null, ?string $replaces = null,
        ?DateTimeImmutable $asOf = null, string $doc = self::DOC): array
    {
        return $this->op($rules)->plan(ErpOpeningFiles::stock($stock), $items === null ? null : ErpOpeningFiles::itemMap($items),
            $pending === null ? null : ErpOpeningFiles::pendingSales($pending), $doc, $asOf ?? self::longAgo(), $replaces);
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

    /** @return array<string, int> table => rows, for every table of the schema */
    private static function tableCounts(): array
    {
        $out = [];
        foreach (self::$db->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $t) {
            $out[(string) $t] = (int) self::$db->value('SELECT COUNT(*) FROM `' . $t . '`');
        }
        return $out;
    }

    /** bin/import_erp_opening.php against this slot's schema (admin login, like the other job tests). @return array{code: int, out: string, err: string} */
    /** @param list<string> $args */
    private static function cli(array $args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/import_erp_opening.php", '--db=' . TestDb::name(), '--admin', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /** The erp_opening.* settings set for the CLI (the seed values are put back by the caller's restore()). @param array<string, string> $values */
    private static function settings(array $values): \Closure
    {
        $before = [];
        foreach ($values as $k => $v) {
            $before[$k] = (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$k]);
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$v, $k]);
        }
        return static function () use ($before): void {
            foreach ($before as $k => $v) {
                self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$v, $k]);
            }
        };
    }

    // ---- the warehouse and company filter --------------------------------------------------------------------------------------

    public function testOnlyTheMainWarehouseIsReadAndVpg2IsNeverRead(): void
    {
        $site = $this->site();
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $this->listing($site, '101', $a);
        $this->listing($site, '102', $b);
        $items = $this->itemList(['TEST-A' => '101', 'TEST-B' => '102']);
        $stock = $this->stockCsv([
            ['TEST-A', self::MAIN, 10, '2.5'],
            ['TEST-A', self::VPG2, 5000, '1.2', 'VPG 2'],           // another account's room: never read
            ['TEST-B', 'Stores - VPG2', 300, '1.1', 'VPG 2'],       // a VPG 2 warehouse by its suffix alone
            ['TEST-B', 'Returns - VPG', 7, '3'],                    // ours, but not on the list
            ['TEST-B', self::MAIN, 4, '3'],
        ]);
        $plan = $this->plan($stock, $items);
        $r = $plan['report'];
        self::assertSame([$a => ['target' => 10, 'earlier' => 0, 'unit_cost' => '2.500000'], $b => ['target' => 4, 'earlier' => 0, 'unit_cost' => '3.000000']],
            $plan['items']);
        self::assertSame(['rows' => 2, 'units' => 5300, 'names' => [self::VPG2 => 1, 'Stores - VPG2' => 1]], $r['excluded']['blocked']);
        self::assertSame(['rows' => 1, 'units' => 7, 'names' => ['Returns - VPG' => 1]], $r['excluded']['other_warehouse']);
        self::assertSame(2, $r['included']['rows']);
        self::assertSame('matches', $r['file']['total_line']);

        // Even a rule that lists the VPG 2 room is refused: the blocked names win.
        self::assertSame('bad_setting', $this->code(fn () => self::rules('zero', [self::MAIN, self::VPG2])));
        // Another company's rows are left out when the file says the company.
        $other = new ErpOpeningRules([self::MAIN], ['SOMEONE ELSE'], ['VPG 2'], 'zero', [], 'vpg', 'MAIN', false);
        self::assertSame(3, $this->plan($stock, $items, null, $other)['report']['excluded']['other_company']['rows'], 'blocked rows stay blocked');
        // A file exported for the VPG 2 company (its filter line) is refused whole.
        $vpg2File = $this->stockCsv([['TEST-A', self::MAIN, 10, '2.5']], ['company' => 'VPG 2'], true, null, 'vpg2.csv');
        self::assertSame('wrong_company', $this->code(fn () => $this->plan($vpg2File, $items)));

        $done = $this->op()->apply(self::sys(), $plan, self::DOC, 'opening stock from ERPNext: test');
        self::assertSame(['booked_items' => 2, 'booked_units' => 14, 'replayed_items' => 0], $done);
        $this->assertBal(10, 0, 0, $a);
        $this->assertBal(4, 0, 0, $b);
        $rows = self::$db->all('SELECT sku_id, qty_delta, movement_type, actor, unit_cost, cost_source FROM stock_ledger WHERE doc_ref = ? ORDER BY sku_id', [self::DOC]);
        self::assertSame([[$a, 10, 'adjustment', 'system:opening_estimate', '2.500000', 'estimate'], [$b, 4, 'adjustment', 'system:opening_estimate', '3.000000', 'estimate']],
            array_map(static fn (array $x): array => [(int) $x['sku_id'], (int) $x['qty_delta'], $x['movement_type'], $x['actor'], $x['unit_cost'], $x['cost_source']], $rows));
        // The screens' "average cost so far" reads the opening cost (until IM8).
        self::assertSame([$a => '2.500000', $b => '3.000000'], (new CostHints(self::$db))->average([$a, $b]));
    }

    /**
     * ERPNext's item list (Stock > Item, Report view) carries the Vape and Go variant number in its "Variant ID" column
     * (custom_variant_id); its "Product ID" column (custom_product_id) is empty on every item (read on the live ERPNext, 27 Oct 2026).
     * The item list is read by Variant ID, and by it first when a file has both columns; a file with Product ID alone still reads.
     */
    public function testTheItemListReadsErpnextsVariantIdColumn(): void
    {
        $site = $this->site();
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $this->listing($site, '201', $a);
        $this->listing($site, '202', $b);
        $stock = $this->stockCsv([['P201', self::MAIN, 6, '2'], ['P202', self::MAIN, 3, '4']]);
        $both = $this->dir . '/items_both.csv';
        file_put_contents($both, "\"ID\",\"Item Name\",\"Product ID\",\"Variant ID\"\n\"P201\",\"Made-up A\",\"\",\"201\"\n\"P202\",\"Made-up B\",\"\",\"202\"\n");
        $plan = $this->plan($stock, $both);
        self::assertSame([$a => ['target' => 6, 'earlier' => 0, 'unit_cost' => '2.000000'], $b => ['target' => 3, 'earlier' => 0, 'unit_cost' => '4.000000']],
            $plan['items'], 'Variant ID is read, the empty Product ID is not');
        $only = $this->dir . '/items_vid.csv';
        file_put_contents($only, "\"ID\",\"Variant ID\"\n\"P201\",\"201\"\n");
        self::assertSame([$a], array_keys($this->plan($this->stockCsv([['P201', self::MAIN, 6, '2']], [], true, null, 'one.csv'), $only)['items']));
        self::assertSame([$b], array_keys($this->plan($this->stockCsv([['P202', self::MAIN, 3, '4']], [], true, null, 'two.csv'),
            $this->itemList(['P202' => '202'], 'items_pid.csv'))['items']), 'a Product ID column alone still reads');
    }

    // ---- unresolved lines --------------------------------------------------------------------------------------------------------

    public function testUnresolvedLinesAreListedAndNeverGuessed(): void
    {
        $site = $this->site();
        $a = $this->item('legacy');
        $c = $this->item('legacy');
        $kept = $this->item('legacy');
        $merged = $this->item('legacy');
        $this->listing($site, '101', $a);
        $this->listing($site, '103', null);                       // not matched
        $this->listing($site, '104', $a, 1, 'quarantined');       // its match is in doubt
        $this->listing($site, '105', $merged);
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$kept, $merged]);
        self::$db->exec('INSERT INTO sku_erp_item (item_code, sku_id, units_per_item) VALUES (?, ?, 2)', ['ERP-LINK', $c]);
        $items = $this->itemList(['TEST-A' => '101', 'NO-PID' => '', 'GHOST' => '999', 'UNLINKED' => '103', 'QUAR' => '104', 'MERGED' => '105',
            'P47074' => '101', 'TWICE' => '101']);
        file_put_contents($items, '"TWICE","Made-up","102"' . "\n", FILE_APPEND); // the item list gives TWICE two product ids
        $stock = $this->stockCsv([
            ['TEST-A', self::MAIN, 3, '2'],
            ['erp-link', self::MAIN, 5, '8'],          // CW's own ERPNext link (letter case as ERPNext ignores it): 5 x 2 units
            ['NO-PID', self::MAIN, 1, '2'],
            ['NOT-LISTED', self::MAIN, 2, '2'],
            ['GHOST', self::MAIN, 3, '2'],
            ['UNLINKED', self::MAIN, 4, '2'],
            ['QUAR', self::MAIN, 5, '2'],
            ['MERGED', self::MAIN, 6, '2'],
            ['TWICE', self::MAIN, 7, '2'],
            ['P47074', self::MAIN, 2, '2'],            // a second ERPNext item of the same variant: summed, and reported
            ['HALF', self::MAIN, '1.5', '2'],
            ['', self::MAIN, 1, '2'],
        ]);
        $plan = $this->plan($stock, $items);
        $r = $plan['report'];
        self::assertSame([$a => 5, $c => 10], array_map(static fn (array $i): int => $i['target'], $plan['items']));
        self::assertSame('4.000000', $plan['items'][$c]['unit_cost'], 'a rate of £8 for a 2-unit ERPNext item is £4 a unit');
        self::assertSame(1, $r['items']['fed_by_several_erp_items']);
        self::assertSame(['rows' => 3, 'units' => 10, 'by_erp_link' => 1, 'by_listing' => 2], $r['resolved'], 'ERPNext units: 3 + 5 + 2');
        $why = array_map(static fn (array $x): int => $x['rows'], $r['unresolved']['by_reason']);
        ksort($why);
        self::assertSame(['ambiguous_product_id' => 1, 'fractional_qty' => 1, 'merged_item' => 1, 'no_item_code' => 1, 'no_product_id' => 2,
            'quarantined_listing' => 1, 'unknown_listing' => 1, 'unlinked_listing' => 1], $why);
        self::assertSame(9, $r['unresolved']['rows']);
        $listed = array_column($r['unresolved']['details'], 'reason', 'item_code');
        self::assertSame('unknown_listing', $listed['GHOST']);
        self::assertSame('no_product_id', $listed['NOT-LISTED']);
        self::assertSame('999', array_column($r['unresolved']['details'], 'product_id', 'item_code')['GHOST']);
        $byLine = array_column($plan['lines'], 'status', 'item_code');
        self::assertSame('unresolved', $byLine['QUAR']);

        // Without the item list only CW's own ERPNext links resolve; the rest say why.
        $bare = $this->plan($stock, null);
        self::assertSame([$c], array_keys($bare['items']));
        self::assertSame(9, $bare['report']['unresolved']['by_reason']['no_item_list']['rows']);
    }

    // ---- below zero ----------------------------------------------------------------------------------------------------------------

    public function testBelowZeroOpensAtZeroOrCarriesTheMinusAsTheSettingSays(): void
    {
        $site = $this->site();
        $neg = $this->item('legacy');
        $pos = $this->item('legacy');
        $this->listing($site, '201', $neg);
        $this->listing($site, '202', $pos);
        $items = $this->itemList(['NEG' => '201', 'POS' => '202']);
        $stock = $this->stockCsv([['NEG', self::MAIN, -5, '2'], ['POS', self::MAIN, 6, '3']]);

        $zero = $this->plan($stock, $items, null, self::rules('zero'));
        self::assertSame([$pos], array_keys($zero['items']), 'opened at zero: nothing to book for it');
        $b = $zero['report']['below_zero'];
        self::assertSame(['items' => 1, 'units' => -5], $b['items']);
        self::assertSame([5, 0, 'zero'], [$b['wiped_units'], $b['carried_units'], $b['policy']]);
        self::assertSame(['rows' => 1, 'units' => -5], $b['lines']);
        self::assertSame(1, $zero['report']['no_change']);

        $carry = $this->plan($stock, $items, null, self::rules('carry'));
        self::assertSame(['target' => -5, 'earlier' => 0, 'unit_cost' => '2.000000'], $carry['items'][$neg]);
        self::assertSame([0, -5, 'carry'], [$carry['report']['below_zero']['wiped_units'], $carry['report']['below_zero']['carried_units'],
            $carry['report']['below_zero']['policy']]);
        $this->op(self::rules('carry'))->apply(self::sys(), $carry, self::DOC, 'n');
        $this->assertBal(-5, 0, 0, $neg);
        $this->assertBal(6, 0, 0, $pos);
        self::assertSame('8.00', $carry['report']['to_book']['value'], '-5 x £2 + 6 x £3');
    }

    // ---- placeholder costs -----------------------------------------------------------------------------------------------------------

    public function testBlankAndPlaceholderCostsAreFlaggedAndNeverUsed(): void
    {
        $site = $this->site();
        $blank = $this->item('legacy');
        $pound = $this->item('legacy');
        $mixed = $this->item('legacy');
        $pack = $this->item('legacy');
        foreach (['301' => [$blank, 1], '302' => [$pound, 1], '303' => [$mixed, 1], '304' => [$mixed, 1], '305' => [$pack, 1], '306' => [$pack, 10]] as $v => [$sku, $u]) {
            $this->listing($site, (string) $v, $sku, $u);
        }
        $items = $this->itemList(['BLANK' => '301', 'POUND' => '302', 'MIX-REAL' => '303', 'MIX-ONE' => '304', 'SINGLE' => '305', 'TENPACK' => '306']);
        $stock = $this->stockCsv([
            ['BLANK', self::MAIN, 4, ''],
            ['POUND', self::MAIN, 3, '1.00'],
            ['MIX-REAL', self::MAIN, 2, '2.40'],
            ['MIX-ONE', self::MAIN, 8, '1'],
            ['SINGLE', self::MAIN, 5, '1.10'],          // 5 singles at £1.10
            ['TENPACK', self::MAIN, 2, '9.00'],         // 2 packs of 10 at £9 a pack = 20 singles at £0.90
        ]);
        $plan = $this->plan($stock, $items);
        $c = $plan['report']['costs'];
        self::assertSame([1, 2, 0], [$c['lines_blank'], $c['lines_placeholder'], $c['lines_bad']]);
        self::assertSame(['items' => 2, 'units' => 7], ['items' => $c['recost']['items'], 'units' => $c['recost']['units']]);
        self::assertEqualsCanonicalizing([$blank, $pound], array_column($c['recost']['details'], 'sku_id'));
        self::assertSame(1, $c['mixed_items']);
        self::assertNull($plan['items'][$blank]['unit_cost']);
        self::assertNull($plan['items'][$pound]['unit_cost']);
        self::assertSame(['target' => 10, 'earlier' => 0, 'unit_cost' => '2.400000'], $plan['items'][$mixed], 'the £1 line is passed over');
        self::assertSame(['target' => 25, 'earlier' => 0, 'unit_cost' => '0.940000'], $plan['items'][$pack], '(5 x 1.10 + 2 x 9.00) / 25 singles');
        self::assertSame(2, $plan['report']['to_book']['items_without_cost']);

        $this->op()->apply(self::sys(), $plan, self::DOC, 'n');
        $rows = self::$db->all('SELECT sku_id, qty_delta, unit_cost, cost_source FROM stock_ledger WHERE doc_ref = ? ORDER BY sku_id', [self::DOC]);
        $bySku = array_column($rows, null, 'sku_id');
        self::assertNull($bySku[$blank]['unit_cost']);
        self::assertNull($bySku[$blank]['cost_source']);
        self::assertSame(['0.940000', 'estimate'], [$bySku[$pack]['unit_cost'], $bySku[$pack]['cost_source']]);
    }

    // ---- idempotency -----------------------------------------------------------------------------------------------------------------

    public function testApplyingTheSameFileAgainChangesNothingAndAnotherFileUnderTheSameNameIsRefused(): void
    {
        $site = $this->site();
        $skus = [];
        $map = [];
        $rows = [];
        for ($i = 1; $i <= 4; $i++) {
            $skus[$i] = $this->item('legacy');
            $this->listing($site, (string) (400 + $i), $skus[$i]);
            $map["ITEM-{$i}"] = (string) (400 + $i);
            $rows[] = ["ITEM-{$i}", self::MAIN, $i * 3, '1.75'];
        }
        $items = $this->itemList($map);
        $stock = $this->stockCsv($rows);
        $plan = $this->plan($stock, $items);

        // A run that stopped after two items, then the same command again.
        $partial = ['items' => array_slice($plan['items'], 0, 2, true)];
        $this->op()->apply(self::sys(), $partial, self::DOC, 'n');
        $resume = $this->plan($stock, $items);
        self::assertSame(['items' => 2, 'units' => 9, 'value' => '15.75'], ['items' => $resume['report']['already_booked']['items'],
            'units' => $resume['report']['already_booked']['units'], 'value' => $resume['report']['already_booked']['value']]);
        self::assertSame([$skus[3], $skus[4]], array_keys($resume['items']));
        $this->op()->apply(self::sys(), $resume, self::DOC, 'n');
        $ledger = $this->ledgerCount();
        $balances = self::$db->all('SELECT sku_id, on_hand FROM stock_balance ORDER BY sku_id');

        // The same file again: nothing left to book; and booking the old plan again only replays.
        $again = $this->plan($stock, $items);
        self::assertSame([], $again['items']);
        self::assertSame(4, $again['report']['already_booked']['items']);
        self::assertSame(30, $again['report']['opening']['units']);
        self::assertSame(['booked_items' => 0, 'booked_units' => 0, 'replayed_items' => 4], $this->op()->apply(self::sys(), $plan, self::DOC, 'n'));
        self::assertSame($ledger, $this->ledgerCount());
        self::assertSame($balances, self::$db->all('SELECT sku_id, on_hand FROM stock_balance ORDER BY sku_id'));

        // Other figures, or another cost, under the same doc_ref: refused before anything is booked.
        $rows[0][2] = 99;
        self::assertSame('opening_conflict', $this->code(fn () => $this->plan($this->stockCsv($rows, [], true, null, 'b.csv'), $items)));
        $rows[0][2] = 3;
        $rows[1][3] = '1.80';
        self::assertSame('opening_conflict', $this->code(fn () => $this->plan($this->stockCsv($rows, [], true, null, 'c.csv'), $items)));
        // The same file under ANOTHER doc_ref: every item already carries an opening, so nothing is booked twice.
        $other = $this->plan($stock, $items, null, null, null, null, 'erp-opening:vpg:again');
        self::assertSame([], $other['items']);
        self::assertSame(['earlier_opening' => 4], $other['report']['skipped']['why']);
        self::assertSame($ledger, $this->ledgerCount());
    }

    // ---- the sales not yet in ERPNext -----------------------------------------------------------------------------------------------

    public function testTheSalesNotYetInErpnextAreTakenOffOrTheCorrectionIsReportedOpen(): void
    {
        $site = $this->site();
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $this->listing($site, '501', $a);
        $this->listing($site, '502', $b, 10);   // a 10-pack listing of b
        $this->listing($site, '503', $b);       // b's single
        $items = $this->itemList(['A' => '501', 'B-SINGLE' => '503']);
        $stock = $this->stockCsv([['A', self::MAIN, 10, '2'], ['B-SINGLE', self::MAIN, 25, '1.5']]);

        $open = $this->plan($stock, $items);
        self::assertSame(['given' => false, 'open' => true], $open['report']['pending_sales']);
        self::assertSame([10, 25], [$open['items'][$a]['target'], $open['items'][$b]['target']]);

        $pending = $this->pendingCsv([['501', 3], ['502', 2], ['503', 1], ['999', 4], ['501', 'x']]);
        $plan = $this->plan($stock, $items, $pending);
        $p = $plan['report']['pending_sales'];
        self::assertTrue($p['given']);
        self::assertSame(['rows' => 3, 'units' => 6, 'central_units' => 24, 'items' => 2], $p['subtracted']);
        $why = array_map(static fn (array $x): int => $x['rows'], $p['unresolved']['by_reason']);
        ksort($why);
        self::assertSame(['bad_qty' => 1, 'unknown_listing' => 1], $why);
        self::assertSame([7, 4], [$plan['items'][$a]['target'], $plan['items'][$b]['target']], 'a: 10 - 3; b: 25 - 2 x 10 - 1');

        // Taking off more than ERPNext holds makes the item below zero: the setting decides.
        $more = $this->pendingCsv([['501', 12]], 'more.csv');
        self::assertArrayNotHasKey($a, $this->plan($stock, $items, $more)['items']);
        self::assertSame(-2, $this->plan($stock, $items, $more, self::rules('carry'))['items'][$a]['target']);

        // An item ERPNext does not list at all but that has sales on their way.
        $c = $this->item('legacy');
        $this->listing($site, '504', $c);
        $onlyPending = $this->plan($stock, $items, $this->pendingCsv([['504', 2]], 'c.csv'), self::rules('carry'));
        self::assertSame(-2, $onlyPending['items'][$c]['target']);
    }

    // ---- the dry run writes nothing; the CLI end to end -------------------------------------------------------------------------------

    public function testTheDryRunWritesNothingAndApplyBooksOnceThroughTheCommandLine(): void
    {
        $site = $this->site('vapeandgo');
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $this->listing($site, '601', $a);
        $this->listing($site, '602', $b);
        $items = $this->itemList(['A' => '601', 'B' => '602']);
        $asOf = new DateTimeImmutable('now', new \DateTimeZone('Europe/London'));
        $stock = $this->stockCsv([['A', self::MAIN, 12, '2.5'], ['B', self::MAIN, -4, '1'], ['A', self::VPG2, 900, '1', 'VPG 2']],
            ['company' => self::CO, 'from_date' => '2026-01-01', 'to_date' => $asOf->format('Y-m-d')]);
        $pending = $this->pendingCsv([['601', 2]]);
        $sha = (string) hash_file('sha256', $stock);
        $base = ['--file=' . $stock, '--item-list=' . $items, '--as-of=' . $asOf->format('Y-m-d\TH:i:sP'), '--doc-ref=' . self::DOC,
            '--source=made-up Stock Balance for the test'];
        $restore = self::settings(['erp_opening.require_pending_sales' => 'true']);
        try {
            $before = self::tableCounts();
            $dry = self::cli([...$base, '--pending-sales=' . $pending]);
            self::assertSame(0, $dry['code'], $dry['err'] . $dry['out']);
            self::assertStringContainsString('DRY RUN', $dry['out']);
            self::assertStringContainsString('left out (blocked): 1 lines, 900 units', $dry['out']);
            self::assertStringContainsString('dry run: nothing booked', $dry['out']);
            self::assertSame($before, self::tableCounts(), 'a dry run writes nothing at all');

            // --apply without the hash, or without the pending-sales file while the setting asks for it: refused, nothing written.
            self::assertSame(2, self::cli([...$base, '--pending-sales=' . $pending, '--apply'])['code']);
            $noPending = self::cli([...$base, '--apply', '--sha256=' . $sha]);
            self::assertSame(1, $noPending['code']);
            self::assertStringContainsString('--pending-sales', $noPending['err']);
            self::assertSame(1, self::cli([...$base, '--pending-sales=' . $pending, '--apply', '--sha256=' . str_repeat('0', 64)])['code']);
            self::assertSame($before, self::tableCounts());

            $run = self::cli([...$base, '--pending-sales=' . $pending, '--apply', '--sha256=' . $sha, '--approved-by=the owner (test)']);
            self::assertSame(0, $run['code'], $run['err'] . $run['out']);
            self::assertStringContainsString('booked 1 items, net +10 units', $run['out']);
            $this->assertBal(10, 0, 0, $a);
            $this->assertBal(0, 0, 0, $b);
            $last = ErpOpening::lastRun(self::$db);
            self::assertNotNull($last);
            self::assertSame(['done', self::DOC, basename($stock)], [$last['status'], $last['doc_ref'], $last['file_name']]);
            self::assertSame(1, $last['summary']['booked']['booked_items']);
            self::assertSame(1, $last['summary']['report']['below_zero']['items']['items']);
            self::assertTrue($last['summary']['report']['pending_sales']['given']);
            $note = (string) self::$db->value('SELECT note FROM stock_ledger WHERE doc_ref = ? AND sku_id = ?', [self::DOC, $a]);
            self::assertStringContainsString('approved by the owner (test)', $note);
            self::assertStringContainsString('taken off', $note);

            // The same command again books nothing (its own run row says so).
            $ledger = $this->ledgerCount();
            $again = self::cli([...$base, '--pending-sales=' . $pending, '--apply', '--sha256=' . $sha]);
            self::assertSame(0, $again['code'], $again['err'] . $again['out']);
            self::assertStringContainsString('booked 0 items', $again['out']);
            self::assertSame($ledger, $this->ledgerCount());
            self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM erp_opening_run WHERE status = 'done'"));
        } finally {
            $restore();
        }
    }

    // ---- replacing the stores' estimate (D40a) ---------------------------------------------------------------------------------------

    public function testTheErpnextFigureReplacesTheStoresEstimateItNames(): void
    {
        $site = $this->site();
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $gone = $this->item('legacy');
        $this->listing($site, '701', $a);
        $this->listing($site, '702', $b);
        $this->listing($site, '703', $gone);
        // The stores' estimate of D40a, booked earlier (the tool's own actor and doc_ref).
        $estimate = 'opening:vpg:2026-10-01T00:00+01:00';
        (new \CW\Ops\OpeningEstimate(self::$db, $this->moves))->apply(self::sys(), [$a => 8, $b => 3, $gone => 2], $estimate, 'estimate (test)');
        $items = $this->itemList(['A' => '701', 'B' => '702']);
        $stock = $this->stockCsv([['A', self::MAIN, 11, '2'], ['B', self::MAIN, 3, '4']]);

        // Without --replaces every estimated item is an earlier opening: skipped, never booked twice.
        self::assertSame(['earlier_opening' => 2], $this->plan($stock, $items)['report']['skipped']['why']);

        $plan = $this->plan($stock, $items, null, null, $estimate);
        self::assertSame(['target' => 11, 'earlier' => 8, 'unit_cost' => '2.000000'], $plan['items'][$a]);
        self::assertSame(['target' => 3, 'earlier' => 3, 'unit_cost' => '4.000000'], $plan['items'][$b], 'same figure: still re-booked with its cost');
        self::assertSame(['not_in_erp_file' => 1], $plan['report']['skipped']['why']);
        self::assertSame($gone, $plan['report']['skipped']['details'][0]['sku_id']);
        $this->op()->apply(self::sys(), $plan, self::DOC, 'n');
        $this->assertBal(11, 0, 0, $a);
        $this->assertBal(3, 0, 0, $b);
        $this->assertBal(2, 0, 0, $gone);
        self::assertSame([[$a, -8, null], [$a, 11, '2.000000']], array_map(static fn (array $x): array => [(int) $x['sku_id'], (int) $x['qty_delta'], $x['unit_cost']],
            self::$db->all('SELECT sku_id, qty_delta, unit_cost FROM stock_ledger WHERE doc_ref = ? AND sku_id = ? ORDER BY qty_delta', [self::DOC, $a])));
        self::assertSame([], $this->plan($stock, $items, null, null, $estimate)['items'], 'a re-run books nothing');
        self::assertSame('bad_replaces', $this->code(fn () => $this->plan($stock, $items, null, null, self::DOC)));
    }

    // ---- what CW already knows -------------------------------------------------------------------------------------------------------

    public function testCountedItemsAndItemsThatMovedBeforeTheAsOfMomentAreSkipped(): void
    {
        $site = $this->site();
        $counted = $this->item('legacy');
        $moved = $this->item('legacy', 7);     // a delivery already in CW
        $fresh = $this->item('legacy');
        self::$db->exec('UPDATE sku SET counted_at = ? WHERE id = ?', ['2026-09-25 10:00:00.000000', $counted]);
        foreach (['801' => $counted, '802' => $moved, '803' => $fresh] as $v => $sku) {
            $this->listing($site, (string) $v, $sku);
        }
        $items = $this->itemList(['K' => '801', 'M' => '802', 'F' => '803']);
        $stock = $this->stockCsv([['K', self::MAIN, 50, '2'], ['M', self::MAIN, 100, '2'], ['F', self::MAIN, 3, '2']]);
        $now = new DateTimeImmutable('now', Clock::utc());
        $plan = $this->plan($stock, $items, null, null, null, $now);
        self::assertSame([$fresh], array_keys($plan['items']));
        $why = $plan['report']['skipped']['why'];
        ksort($why);
        self::assertSame(['counted' => 1, 'moved_before_as_of' => 1], $why);
        // As of long ago the delivery came after it: the ERPNext figure sits under it.
        self::assertSame([$moved, $fresh], array_keys($this->plan($stock, $items)['items']));
    }

    // ---- the file --------------------------------------------------------------------------------------------------------------------

    public function testAnXlsxExportReadsLikeTheCsvAndAnEditedFileIsRefused(): void
    {
        $site = $this->site();
        $a = $this->item('legacy');
        $this->listing($site, '901', $a);
        $items = $this->itemList(['A' => '901']);
        $x = new XlsxWriter(array_map(static fn (string $h): array => [$h, in_array($h, ['Balance Qty', 'Valuation Rate', 'Balance Value'], true) ? 'number' : 'text'],
            ['Item', 'Item Name', 'Warehouse', 'Stock UOM', 'Balance Qty', 'Balance Value', 'Valuation Rate', 'Company']));
        $x->add(['A', 'Made-up A', self::MAIN, 'Nos', '6', '15.00', '2.5', self::CO]);
        $x->add(['A', 'Made-up A', self::VPG2, 'Nos', '60', '60.00', '1', 'VPG 2']);
        file_put_contents($xlsx = $this->dir . '/stock.xlsx', $x->output());
        self::assertSame([$a => ['target' => 6, 'earlier' => 0, 'unit_cost' => '2.500000']], $this->plan($xlsx, $items)['items']);

        // The Total line no longer matches the lines: the file was edited or cut short.
        self::assertSame('total_mismatch', $this->code(fn () => $this->plan($this->stockCsv([['A', self::MAIN, 6, '2.5']], [], true, 7), $items)));
        // The same item and warehouse twice.
        self::assertSame('bad_file', $this->code(fn () => $this->plan($this->stockCsv([['A', self::MAIN, 6, '2.5'], ['a', self::MAIN, 1, '2.5']], [], false,
            null, 'twice.csv'), $items)));
        // No Valuation Rate column: the cost half cannot be done, so nothing is.
        file_put_contents($noRate = $this->dir . '/norate.csv', "\"Item\",\"Warehouse\",\"Balance Qty\"\n\"A\",\"" . self::MAIN . "\",6.0\n");
        self::assertSame('bad_file', $this->code(fn () => ErpOpeningFiles::stock($noRate)));
        // The report's To Date must be the as-of moment's UK day.
        $dated = $this->stockCsv([['A', self::MAIN, 6, '2.5']], ['company' => self::CO, 'to_date' => '2026-10-31'], true, null, 'dated.csv');
        self::assertSame('as_of_mismatch', $this->code(fn () => $this->plan($dated, $items)));
        self::assertSame([$a], array_keys($this->plan($dated, $items, null, null, null, new DateTimeImmutable('2026-10-31T23:30:00+00:00'))['items']));
    }

    // ---- the opening's own writer ---------------------------------------------------------------------------------------------------

    public function testRecordOpeningStoresAnEstimateCostAndIsAJobsEntryPointOnly(): void
    {
        $sku = $this->item('legacy');
        $req = ['type' => 'adjustment', 'doc_ref' => 'erp-opening:t', 'lines' => [['sku_id' => $sku, 'qty' => 2, 'unit_cost' => '1.25']]];
        $r = $this->moves->recordOpening(self::sys(), $req, 'erp-opening:t:1');
        self::assertSame(200, $r->status, json_encode($r->body));
        self::assertSame('estimate', self::$db->value('SELECT cost_source FROM stock_ledger WHERE doc_ref = ?', ['erp-opening:t']));
        // The same key through record() is another request (its cost would be `manual`): refused, never replayed as if the same.
        self::assertSame(422, $this->moves->record(self::sys(), $req, 'erp-opening:t:1')->status);
        foreach ([fn () => $this->moves->recordOpening(self::staff(), $req, 'k1'), fn () => $this->moves->recordOpening(self::sys(), ['type' => 'goods_in'] + $req, 'k2')] as $fn) {
            try {
                $fn();
                self::fail('expected a LogicException');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
        $this->assertBal(2, 0, 0, $sku);
    }

    // ---- review of 14 Oct 2026 (Codex, on 6de7724) ----------------------------------------------------------------------------------

    /** Finding 1: an opening booked by the other tool between a run's plan and its apply is never added to; nor is a count. */
    public function testAnOpeningOrCountMadeBetweenThePlanAndTheApplyStopsTheItem(): void
    {
        $site = $this->site();
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $c = $this->item('legacy');
        $this->listing($site, '1001', $a);
        $this->listing($site, '1002', $b);
        $this->listing($site, '1003', $c);
        $items = $this->itemList(['A' => '1001', 'B' => '1002', 'C' => '1003']);
        $stock = $this->stockCsv([['A', self::MAIN, 11, '2'], ['B', self::MAIN, 6, '2'], ['C', self::MAIN, 4, '2']]);
        $estimate = new \CW\Ops\OpeningEstimate(self::$db, $this->moves);

        // The ERPNext run plans; the stores' estimate books A in between; the ERPNext apply must not add 11 to its 8.
        $plan = $this->plan($stock, $items);
        $estimate->apply(self::sys(), [$a => 8], 'opening:vpg:estimate', 'estimate (test)');
        self::assertSame('opening_changed', $this->code(fn () => $this->op()->apply(self::sys(), $plan, self::DOC, 'n')));
        $this->assertBal(8, 0, 0, $a);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE doc_ref = ?', [self::DOC]), 'whole items only: A was first');

        // The other way round: the estimate planned, the ERPNext opening booked B, the estimate's apply is refused.
        $plan = $this->plan($stock, $items);
        self::assertSame([$b, $c], array_keys($plan['items']));
        $this->op()->apply(self::sys(), ['items' => [$b => $plan['items'][$b]]] + $plan, self::DOC, 'n');
        self::assertSame('opening_changed', $this->code(fn () => $estimate->apply(self::sys(), [$b => 5], 'opening:vpg:estimate', 'estimate (test)')));
        $this->assertBal(6, 0, 0, $b);

        // A count between the plan and the apply: the opening would land on top of what was counted.
        self::$db->exec('UPDATE sku SET counted_at = UTC_TIMESTAMP(6) WHERE id = ?', [$c]);
        self::assertSame('opening_changed', $this->code(fn () => $this->op()->apply(self::sys(), ['items' => [$c => $plan['items'][$c]]] + $plan, self::DOC, 'n')));
        $this->assertBal(0, 0, 0, $c);
        self::$db->exec('UPDATE sku SET counted_at = NULL WHERE id = ?', [$c]);
    }

    /** Finding 1: the two opening tools take one shared lock for a real run; a dry run does not need it. */
    public function testTheOpeningToolsShareOneLock(): void
    {
        $site = $this->site('vapeandgo');
        $a = $this->item('legacy');
        $this->listing($site, '1101', $a);
        $items = $this->itemList(['A' => '1101']);
        $stock = $this->stockCsv([['A', self::MAIN, 5, '2']]);
        $pending = $this->pendingCsv([]);
        $asOf = (new DateTimeImmutable('now', Clock::utc()))->format('Y-m-d\TH:i:s\Z');
        $erp = ['--file=' . $stock, '--item-list=' . $items, '--pending-sales=' . $pending, '--as-of=' . $asOf, '--doc-ref=' . self::DOC, '--source=made-up'];
        file_put_contents($csv = $this->dir . '/site.csv', "variant_id,prodt_stock\n1101,5\n");
        $est = ['--csv=' . $csv, '--as-of=' . $asOf, '--doc-ref=opening:vapeandgo:test', '--source=made-up', '--channel=vapeandgo', '--sha256=' . hash_file('sha256', $csv)];
        $lock = \CW\Ops\OpeningLock::name(TestDb::name());
        self::assertSame(1, (int) self::$db->value('SELECT GET_LOCK(?, 0)', [$lock]), 'this test holds the opening lock');
        try {
            $r = self::cli([...$erp, '--apply', '--sha256=' . hash_file('sha256', $stock)]);
            self::assertSame(1, $r['code'], $r['out'] . $r['err']);
            self::assertStringContainsString('another opening run', $r['err']);
            $r = self::estimateCli($est);
            self::assertSame(1, $r['code'], $r['out'] . $r['err']);
            self::assertStringContainsString('another opening run', $r['err']);
            self::assertSame(0, self::cli($erp)['code'], 'a dry run reads only and needs no lock');
            self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE actor = 'system:opening_estimate'"), 'nothing booked');
        } finally {
            self::$db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        $r = self::cli([...$erp, '--apply', '--sha256=' . hash_file('sha256', $stock)]);
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        $r = self::estimateCli($est);
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('earlier_opening', $r['out'], 'the estimate sees the ERPNext opening and books nothing');
        $this->assertBal(5, 0, 0, $a);
    }

    /** Finding 2: item codes keep their inner spaces; two CW links that read the same are refused, never one picked. */
    public function testItemCodesKeepTheirInnerSpacesAndAnAmbiguousLinkIsRefused(): void
    {
        $site = $this->site();
        $one = $this->item('legacy');
        $two = $this->item('legacy');
        $three = $this->item('legacy');
        $four = $this->item('legacy');
        $this->listing($site, '1201', $three);
        $this->listing($site, '1202', $four);
        $link = 'INSERT INTO sku_erp_item (item_code, sku_id, units_per_item) VALUES (?, ?, ?)';
        self::$db->exec($link, ['ABC DEF', $one, 1]);
        self::$db->exec($link, ['ABC  DEF', $two, 3]);
        self::$db->exec($link, ['TRAIL', $one, 1]);
        self::$db->exec($link, ['TRAIL ', $two, 3]);             // reads the same as TRAIL once trimmed: which one is meant?
        $items = $this->itemList(['X Y' => '1201', 'X  Y' => '1202']);
        $stock = $this->stockCsv([['ABC DEF', self::MAIN, 2, '1.5'], ['ABC  DEF', self::MAIN, 4, '6'], ['TRAIL', self::MAIN, 9, '2'],
            ['X Y', self::MAIN, 5, '2'], ['X  Y', self::MAIN, 7, '2']]);
        $plan = $this->plan($stock, $items);
        self::assertSame([$one => 2, $two => 12, $three => 5, $four => 7], array_map(static fn (array $i): int => $i['target'], $plan['items']));
        self::assertSame('2.000000', $plan['items'][$two]['unit_cost'], '£6 for a 3-unit ERPNext item');
        self::assertSame(['ambiguous_erp_link' => ['rows' => 1, 'units' => 9]], $plan['report']['unresolved']['by_reason']);
        self::assertSame('TRAIL', $plan['report']['unresolved']['details'][0]['item_code']);
    }

    /** Finding 3: a re-run under the same doc_ref that leaves out an item booked earlier is refused, not silently passed. */
    public function testARerunThatLeavesOutAnItemBookedEarlierIsRefused(): void
    {
        $site = $this->site();
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $this->listing($site, '1301', $a);
        $this->listing($site, '1302', $b);
        $items = $this->itemList(['A' => '1301', 'B' => '1302']);
        $this->op()->apply(self::sys(), $this->plan($this->stockCsv([['A', self::MAIN, 3, '2'], ['B', self::MAIN, 4, '2']]), $items), self::DOC, 'n');
        $onlyB = $this->stockCsv([['B', self::MAIN, 4, '2']], [], true, null, 'only_b.csv');
        try {
            $this->plan($onlyB, $items);
            self::fail('expected opening_conflict');
        } catch (CwException $e) {
            self::assertSame('opening_conflict', $e->errorCode);
            self::assertSame([$a], array_column($e->detail['items'], 'sku_id'));
        }
        // An item left out because it was taken out of the item list is caught the same way.
        self::assertSame('opening_conflict', $this->code(fn () => $this->plan($this->stockCsv([['A', self::MAIN, 3, '2'], ['B', self::MAIN, 4, '2']], [], true,
            null, 'ab.csv'), $this->itemList(['B' => '1302'], 'only_b_items.csv'))));
    }

    /** bin/import_opening_estimate.php against this slot's schema. @param list<string> $args @return array{code: int, out: string, err: string} */
    private static function estimateCli(array $args): array
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
