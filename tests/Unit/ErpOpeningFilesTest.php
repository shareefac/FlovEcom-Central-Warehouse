<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Ops\ErpOpeningFiles;
use CW\Ops\ErpOpeningRules;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Reading the ERPNext exports and the rules of the opening import (pack I, docs/decisions.md PI2-PI10), on made-up files; and the
 * words of its settings (0039).
 */
final class ErpOpeningFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw_erp_files_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function file(string $name, string $text): string
    {
        file_put_contents($p = $this->dir . '/' . $name, $text);
        return $p;
    }

    private static function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (CwException $e) {
            return $e->errorCode;
        }
        self::fail('expected a refusal');
    }

    public function testNumbersAreWholeOrFlaggedAndRatesKeepSixDecimals(): void
    {
        self::assertSame([12, null], ErpOpeningFiles::wholeNumber('12.0'));
        self::assertSame([-3, null], ErpOpeningFiles::wholeNumber('-3'));
        self::assertSame([0, null], ErpOpeningFiles::wholeNumber('0.000'));
        self::assertSame([null, 'fractional_qty'], ErpOpeningFiles::wholeNumber('1.5'));
        self::assertSame([12, null], ErpOpeningFiles::wholeNumber('11.999999999999998'), 'float noise is not a fraction');
        self::assertSame([-4, null], ErpOpeningFiles::wholeNumber('-4.000000000000001'));
        self::assertSame([null, 'fractional_qty'], ErpOpeningFiles::wholeNumber('2.25'));
        self::assertSame([null, 'bad_qty'], ErpOpeningFiles::wholeNumber('12 units'));
        self::assertSame([null, 'bad_qty'], ErpOpeningFiles::wholeNumber(''));
        self::assertSame([null, 'bad_qty'], ErpOpeningFiles::wholeNumber('99999999'));
        self::assertSame(['2.500000', null], ErpOpeningFiles::rate('2.5'));
        self::assertSame(['0.333333', null], ErpOpeningFiles::rate('0.33333333'));
        self::assertSame(['0.666667', null], ErpOpeningFiles::rate('0.6666666666'), 'half up');
        self::assertSame(['1234.500000', null], ErpOpeningFiles::rate('£1,234.50'));
        self::assertSame(['3.333333', null], ErpOpeningFiles::rate('3.3333333333333335'), 'a float ERPNext computed');
        self::assertSame(['0.000010', null], ErpOpeningFiles::rate('1e-05'));
        self::assertSame([null, 'blank'], ErpOpeningFiles::rate('  '));
        self::assertSame([null, 'bad'], ErpOpeningFiles::rate('-2'));
        self::assertSame([null, 'bad'], ErpOpeningFiles::rate('two'));
    }

    public function testTheStockFileIsFoundUnderItsFiltersAndItsTotalLineIsChecked(): void
    {
        $text = "\xEF\xBB\xBF\"company\",\"VAPE AND GO\"\r\n\"to_date\",\"2026-10-31\"\r\n\r\n"
            . "\"Item\",\"Item Name\",\"Warehouse\",\"Stock UOM\",\"Balance Qty\",\"Balance Value\",\"Valuation Rate\",\"Company\"\r\n"
            . "\"TEST-1\",\"Made-up liquid, 10ml\",\"Stores - VPG\",\"Nos\",4.0,10.0,2.5,\"VAPE AND GO\"\r\n"
            . "\"TEST-2\",\"Made-up coil\",\"Stores - VPG\",\"Nos\",-2.0,-3.0,1.5,\"VAPE AND GO\"\r\n"
            . "\"'Total'\",\"\",\"\",\"\",2.0,7.0,,\"\"\r\n";
        $s = ErpOpeningFiles::stock($this->file('sb.csv', $text));
        self::assertSame(['company' => 'VAPE AND GO', 'to_date' => '2026-10-31'], $s['filters']);
        self::assertSame(4, $s['header_row']);
        self::assertSame('matches', $s['total']['check']);
        self::assertSame(['TEST-1', 'TEST-2'], array_column($s['lines'], 'item_code'));
        self::assertSame([4, -2], array_column($s['lines'], 'qty'));
        self::assertSame(['2.500000', '1.500000'], array_column($s['lines'], 'rate'));
        self::assertSame(['10.0', '-3.0'], array_column($s['lines'], 'value'));
        self::assertSame(['item_code', 'warehouse', 'qty', 'rate', 'company', 'value', 'uom', 'item_name'], $s['columns']);

        self::assertSame('total_mismatch', self::refusal(fn () => ErpOpeningFiles::stock($this->file('cut.csv', str_replace('2.0,7.0', '9.0,7.0', $text)))));
        self::assertSame('bad_file', self::refusal(fn () => ErpOpeningFiles::stock($this->file('after.csv', $text . "\"TEST-3\",\"x\",\"Stores - VPG\",\"Nos\",1.0,1.0,1,\"VAPE AND GO\"\r\n"))));
        self::assertSame('bad_file', self::refusal(fn () => ErpOpeningFiles::stock($this->file('none.csv', "\"Item\",\"Warehouse\",\"Balance Qty\",\"Valuation Rate\"\r\n"))));
    }

    public function testABinExportReadsTooAndTheItemListAndPendingSalesFiles(): void
    {
        $bin = ErpOpeningFiles::stock($this->file('bin.csv', "Item Code,Warehouse,Actual Quantity,Valuation Rate,Stock Value\nTEST-1,Stores - VPG,3,2,6\n"));
        self::assertSame([3], array_column($bin['lines'], 'qty'));
        self::assertNull($bin['lines'][0]['company']);
        self::assertNull($bin['total']);

        $map = ErpOpeningFiles::itemMap($this->file('items.csv', "ID,Item Name,Product ID\nP-1,a,101\nP-2,b,\nP-3,c,103\np-3,c,104\nP-4,d,105\nP-4,d,105\n"));
        self::assertSame(['p-1' => '101', 'p-4' => '105'], $map['map']);
        self::assertSame(['p-3' => ['103', '104']], $map['ambiguous']);
        self::assertSame(1, $map['without_product']);

        $p = ErpOpeningFiles::pendingSales($this->file('p.csv', "Variant ID;Qty\n101;2\n102;0\n;1\n103;1.5\n"));
        self::assertSame('variant', $p['by']);
        self::assertSame([[101 => 2], null, null, null], [[(int) $p['rows'][0]['ref'] => $p['rows'][0]['qty']], $p['rows'][1]['qty'], $p['rows'][2]['qty'], $p['rows'][3]['qty']]);
        self::assertSame([null, 'not_above_zero', 'no_reference', 'fractional_qty'], array_column($p['rows'], 'error'));
        $units = ErpOpeningFiles::pendingSales($this->file('u.csv', "item_code\nTEST-1\nTEST-1\n"));
        self::assertSame(['item_code', [1, 1]], [$units['by'], array_column($units['rows'], 'qty')], 'no Qty column: one unit a row');
        $empty = ErpOpeningFiles::pendingSales($this->file('e.csv', "variant_id,qty\n"));
        self::assertSame([], $empty['rows'], 'an empty list says no sale is on its way');
    }

    public function testTheRulesNeverReadABlockedNameAndCompareNamesAsErpnextDoes(): void
    {
        $r = new ErpOpeningRules(['Stores - VPG'], ['VAPE AND GO'], ['VPG 2'], 'zero', ['1.000000'], 'vapeandgo', 'MAIN', true);
        self::assertTrue($r->allowsWarehouse('stores  -  vpg'));
        self::assertFalse($r->allowsWarehouse('Stores - VPG2'));
        self::assertSame('VPG 2', $r->blockedIn('VPG 2 Main warehouse - VPG2'));
        self::assertSame('VPG 2', $r->blockedIn('Stores - vpg2'));
        self::assertNull($r->blockedIn('Stores - VPG'));
        self::assertTrue($r->allowsCompany('Vape and Go'));
        self::assertFalse($r->allowsCompany('VPG 2'));
        self::assertTrue($r->isPlaceholder('1.000000'));
        self::assertFalse($r->isPlaceholder('1.010000'));
        self::assertTrue((new ErpOpeningRules(['Stores - VPG'], [], [], 'carry', [], '', 'MAIN', false))->allowsCompany('anyone'), 'no company list: not checked');
        foreach ([fn () => new ErpOpeningRules([], [], ['VPG 2'], 'zero', [], '', 'MAIN', false),
            fn () => new ErpOpeningRules(['Stores - VPG', 'VPG 2 Main warehouse - VPG2'], [], ['VPG 2'], 'zero', [], '', 'MAIN', false),
            fn () => new ErpOpeningRules(['Stores - VPG'], ['VPG 2'], ['VPG 2'], 'zero', [], '', 'MAIN', false),
            fn () => new ErpOpeningRules(['Stores - VPG'], [], [], 'half', [], '', 'MAIN', false),
            fn () => new ErpOpeningRules(['Stores - VPG'], [], [], 'zero', [], '', 'main', false)] as $fn) {
            self::assertSame('bad_setting', self::refusal($fn));
        }
        self::assertSame(['Stores - VPG', 'Shop'], ErpOpeningRules::lines("Stores - VPG\r\n\n  stores - vpg \nShop\n"));
        // Item codes keep their inner spaces (review of 14 Oct 2026, PI18); names of warehouses and companies fold them.
        self::assertSame('abc  def', ErpOpeningRules::itemKey('  ABC  DEF '));
        self::assertNotSame(ErpOpeningRules::itemKey('ABC DEF'), ErpOpeningRules::itemKey('ABC  DEF'));
        self::assertSame(ErpOpeningRules::key('Stores  - VPG'), ErpOpeningRules::key('stores - vpg'));
    }

    public function testEverySettingOf0039HasItsWordsAndItsTopic(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0039_erp_opening_import.sql');
        preg_match_all("/^ \\('([a-z_]+\\.[a-z_.]+)','/m", $sql, $m);
        self::assertSame(ErpOpeningRules::KEYS, $m[1]);
        foreach ($m[1] as $key) {
            self::assertArrayHasKey($key, Words::SETTING, $key);
            self::assertArrayHasKey($key, Words::SETTING_HELP, $key);
            self::assertSame('Opening stock from ERPNext', Words::settingTopic($key));
            self::assertDoesNotMatchRegularExpression('/\d/', Words::SETTING[$key] . Words::SETTING_HELP[$key], "{$key}: no number written into a text");
        }
    }
}
