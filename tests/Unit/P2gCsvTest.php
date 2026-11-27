<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\P2gCsv;
use PHPUnit\Framework\TestCase;

/**
 * CW-1160 (docs/decisions.md PGL8): Parcel2Go's "My Orders" export read strictly by its column names (the columns seen on 24 Nov 2026, made-up
 * rows): only Reference, Created, Country, Service and TrackingNumber are kept; a row whose Reference is no P2G number, or whose Created is no
 * date and time, refuses the whole file; several rows of one label are its parcels.
 */
final class P2gCsvTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw_p2g_csv_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param list<list<string>> $rows */
    private function file(array $rows, ?array $head = null): string
    {
        $head ??= ['Reference', 'MarketplaceStatus', 'Created', 'Source', 'Customer', 'Postcode', 'Country', 'Product', 'Value', 'Service', 'Sku', 'Weight', 'L/W/H', 'Tags',
            'TrackingStatus', 'TrackingNumber', 'CustomerReference'];
        $f = $this->dir . '/export.csv';
        $h = fopen($f, 'wb');
        fputcsv($h, $head, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($h, $r, ',', '"', '');
        }
        fclose($h);
        return $f;
    }

    /** @return list<string> */
    private static function row(string $ref, string $created, string $tracking, string $country = 'DEU'): array
    {
        return [$ref, 'Shipped', $created, 'Manual', 'Test Person', 'TT1 1TT', $country, 'vape liquid', '41.25', 'Evri Europe Standard Parcelshop', 'SKU1', '0.5', '20x15x5',
            'tag', 'Delivered', $tracking, 'Ring Test Person on 07700 900123'];
    }

    public function testOnlyTheListedColumnsAreKept(): void
    {
        $r = P2gCsv::read($this->file([self::row('P2G150169740', '24/11/2026 13:05', 'H00TST0000000001'), self::row('P2G150169741', '2026-11-24 23:30:00', 'cc-w307-152190428', 'IRL'),
            self::row('P2G150169741', '2026-11-24 23:30:00', 'H00TST0000000003', 'IRL')]));
        self::assertSame(3, $r['rows']);
        self::assertSame([150169740, 150169741], array_keys($r['lines']));
        self::assertSame(['2026-11-24 13:05:00.000000', 'DEU', 'evri-europe-standard-parcelshop', ['H00TST0000000001']],
            [$r['lines'][150169740]['paid_at'], $r['lines'][150169740]['country'], $r['lines'][150169740]['service_slug'], $r['lines'][150169740]['tracking']]);
        self::assertSame(['CCW307152190428', 'H00TST0000000003'], $r['lines'][150169741]['tracking'], 'two rows of one label: its two parcels');
        self::assertSame('2026-11-24 23:30:00.000000', $r['lines'][150169741]['paid_at'], 'UK time in November is UTC');
        $json = json_encode($r);
        foreach (['Test Person', 'TT1 1TT', '07700', 'vape liquid', 'SKU1'] as $personal) {
            self::assertStringNotContainsString($personal, (string) $json, "{$personal} is never kept");
        }
    }

    public function testTheExportPagesUsLayoutWithAmPmIsRead(): void
    {
        // 27 Nov 2026 (the switch on production): My Orders > This Month > Export CSV writes Created month first with AM/PM ("11/26/2026 9:12:34 PM",
        // every row of a real 374-row export). AM/PM marks this layout, so it is never taken for the day-first one; UK time, as before.
        $r = P2gCsv::read($this->file([self::row('P2G150000001', '11/26/2026 9:12:34 PM', 'AA000000001GB'), self::row('P2G150000002', '11/5/2026 10:03:07 AM', 'AA000000002GB'),
            self::row('P2G150000003', '11/26/2026 12:00:01 AM', 'AA000000003GB'), self::row('P2G150000004', '11/26/2026 12:30:00 PM', 'AA000000004GB'),
            self::row('P2G150000005', '6/15/2026 1:00:00 PM', 'AA000000005GB')]));
        self::assertSame(['2026-11-26 21:12:34.000000', '2026-11-05 10:03:07.000000', '2026-11-26 00:00:01.000000', '2026-11-26 12:30:00.000000', '2026-06-15 12:00:00.000000'],
            array_column(array_values($r['lines']), 'paid_at'), 'month first; 12 AM is midnight; summer time is UTC+1');
        foreach (['26/11/2026 9:12:34 PM', '11/26/2026 21:12:34 PM', '11/26/2026 09:12:34 PM', '11/26/2026 9:12 PM', '13/5/2026 9:12:34 AM'] as $bad) {
            try {
                P2gCsv::read($this->file([self::row('P2G150000001', $bad, 'AA000000001GB')]));
                self::fail("{$bad}: refused");
            } catch (CwException $e) {
                self::assertSame('p2g_csv', $e->errorCode, $bad);
            }
        }
    }

    public function testABadRowRefusesTheWholeFile(): void
    {
        foreach ([[self::row('150169740', '24/11/2026 13:05', 'H00TST0000000001')], [self::row('P2G150169740', 'yesterday', 'H00TST0000000001')]] as $i => $rows) {
            try {
                P2gCsv::read($this->file($rows));
                self::fail("case {$i}: refused");
            } catch (CwException $e) {
                self::assertSame('p2g_csv', $e->errorCode);
                self::assertStringContainsString('row 1', $e->getMessage());
                self::assertStringNotContainsString('yesterday', $e->getMessage(), 'a refusal names the row, never its content');
            }
        }
        try {
            P2gCsv::read($this->file([['P2G1', 'x']], ['Reference', 'Postcode']));
            self::fail('a file with no Created or TrackingNumber');
        } catch (CwException $e) {
            self::assertSame('p2g_csv', $e->errorCode);
        }
    }
}
