<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Handovers;

use CW\Company\CompanyDetails;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Handovers\Handovers;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\TestDb;

final class XhighFixesBTest extends HandoversTestCase
{
    public function testPriceImportsRejectAmbiguousSeparatorsBeforeRemovingThem(): void
    {
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Price Format Ltd');
        $cases = [['1,25', null], ['12,34.56', null], ['1 25', null], ['1.234,56', null],
            ['£1,234.56', '1234.560000'], ['1,234,567.123456', '1234567.123456'], ['1.25', '1.250000'], ['0', '0.000000'],
            ['100,000,000', null]];
        $bytes = "customer,item,price\n";
        $expected = [];
        $skipped = [];
        foreach ($cases as $i => [$typed, $price]) {
            $sku = self::makeSku('Price format ' . $i);
            $bytes .= 'Price Format Ltd,CW-' . sprintf('%06d', $sku) . ',"' . $typed . '"' . "\n";
            if ($price === null) {
                $skipped[] = [$i + 1, 'bad_price'];
            } else {
                $expected[$sku] = $price;
            }
        }
        $path = sys_get_temp_dir() . '/cw_prices_' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, $bytes);
        try {
            $r = $this->customers->importPrices($desk, $path, $this->settings());
        } finally {
            @unlink($path);
        }
        self::assertSame([count($cases), count($expected), 0], [$r['rows'], $r['imported'], $r['kept_newer']]);
        self::assertSame($skipped, array_map(static fn (array $s): array => [$s['row'], $s['why']], $r['skipped']));
        $actual = [];
        foreach (self::$db->all('SELECT sku_id, unit_price FROM counterparty_price WHERE counterparty_id = ? ORDER BY sku_id', [$cp]) as $p) {
            $actual[(int) $p['sku_id']] = (string) $p['unit_price'];
        }
        self::assertSame($expected, $actual, 'doubtful rows create no remembered price');
    }

    public function testPostingUsesOneCompanyProfileForVatAndItsSnapshot(): void
    {
        $this->company(false);
        $before = (new CompanyDetails(self::$db))->company();
        $sku = $this->item('strict', 2);
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Concurrent Company Ltd');
        $draft = $this->draftTo($desk, $cp, [['sku_id' => $sku, 'qty' => 1, 'unit_cost' => '100.00']]);
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        $svc = new Handovers($conn, new Documents($conn, DocumentHandlers::all($conn)));
        $changed = false;
        // Arm the change only in post(), after validate() has already checked the VAT code.
        HookedStatement::$before = ['SELECT * FROM counterparty WHERE id = ? FOR SHARE' => function () use (&$changed): void {
            HookedStatement::$before['SELECT rate_percent, is_active FROM vat_code WHERE code = ?'] = function () use (&$changed): void {
                $this->company(false, '', ['vat_registered' => 'no', 'vat_number' => '']);
                $changed = true;
            };
        }];
        try {
            $posted = $svc->post($desk, $draft->id, $draft->version);
        } finally {
            HookedStatement::$before = [];
        }
        self::assertTrue($changed, 'the other session changed the company after the posting read its profile');
        self::assertSame('posted', $posted->status);
        self::assertFalse((new CompanyDetails(self::$db))->company()['vat_registered']);
        $h = (array) self::$db->one('SELECT company_version, company_snapshot, vat_code, net_amount, vat_amount, gross_amount FROM handover WHERE document_id = ?', [$posted->id]);
        $snapshot = json_decode((string) $h['company_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($before['version'], (int) $h['company_version']);
        self::assertSame($before['version'], $snapshot['version']);
        self::assertTrue($snapshot['vat_registered']);
        self::assertSame(['S', '100.00', '20.00', '120.00'], [$h['vat_code'], $h['net_amount'], $h['vat_amount'], $h['gross_amount']]);
        self::assertSame('120.00', $this->balances->balance($cp));
    }
}
