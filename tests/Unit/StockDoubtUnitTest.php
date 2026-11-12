<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Counts\CountSheets;
use CW\Counts\StockDoubt;
use CW\CwException;
use PHPUnit\Framework\TestCase;

/**
 * Doubtful stock's pure rules (docs/decisions.md DS1-): reading the list a load is made from (bin/doubt_load.php: the six columns, every value
 * checked, one bad row refuses the whole file), the list's name, the order the page shows the items in (CW and the shop differ first, the
 * biggest difference first; then CW below zero, the best sellers first; then the rest), and the number typed in "On the shelf".
 */
final class StockDoubtUnitTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function csv(string $text): string
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'doubt');
        file_put_contents($f, $text);
        $this->files[] = $f;
        return $f;
    }

    private function refused(string $text): CwException
    {
        try {
            StockDoubt::readFile($this->csv($text));
        } catch (CwException $e) {
            self::assertSame(400, $e->httpStatus);
            return $e;
        }
        self::fail('the file was read: ' . $text);
    }

    private const HEAD = "cw_code,shop_qty,cw_qty,reason,sold_30d,note\n";

    public function testAFileIsReadRowByRowWithItsSixColumns(): void
    {
        $rows = StockDoubt::readFile($this->csv("group,cw_code,product,shop_qty,cw_qty,reason,sold_30d,note,extra\n"
            . "A,cw-005209 ,\"Xros pods, pack of 4\",75,112,differs,182,\n"
            . "B,CW-003906,Cool Mint,,-2,below_zero,,  seen twice \n"
            . "\n"
            . "C,CW-000017,Mr Blue,-3,0,other,0,a note\n"));
        self::assertSame([
            ['row' => 1, 'cw_code' => 'CW-005209', 'shop_qty' => 75, 'cw_qty' => 112, 'reason' => 'differs', 'sold_30d' => 182, 'note' => null],
            ['row' => 2, 'cw_code' => 'CW-003906', 'shop_qty' => null, 'cw_qty' => -2, 'reason' => 'below_zero', 'sold_30d' => null, 'note' => 'seen twice'],
            ['row' => 4, 'cw_code' => 'CW-000017', 'shop_qty' => -3, 'cw_qty' => 0, 'reason' => 'other', 'sold_30d' => 0, 'note' => 'a note'],
        ], $rows, 'other columns are ignored; a code in small letters or with spaces is the code; a blank line keeps the rows\' numbers');
        self::assertSame(['cw_code', 'shop_qty', 'cw_qty', 'reason', 'sold_30d', 'note'], StockDoubt::COLUMNS);
    }

    public function testOneBadRowRefusesTheWholeFileAndSaysWhere(): void
    {
        self::assertSame('bad_file', $this->refused("cw_code,shop_qty,cw_qty,reason,sold_30d\nCW-000001,1,2,differs,3\n")->errorCode, 'a column missing');
        self::assertSame('bad_file', $this->refused(self::HEAD)->errorCode, 'no row at all');
        foreach ([
            'CW-1,1,2,differs,3,' => 'cw_code',
            ',1,2,differs,3,' => 'cw_code',
            'CW-000001,one,2,differs,3,' => 'shop_qty',
            'CW-000001,1.5,2,differs,3,' => 'shop_qty',
            'CW-000001,1,,differs,3,' => 'cw_qty',
            'CW-000001,1,9999999999,differs,3,' => 'cw_qty',
            'CW-000001,1,2,Differs ,3,' => null,
            'CW-000001,1,2,CW and shop differ,3,' => 'reason',
            'CW-000001,1,2,,3,' => 'reason',
            'CW-000001,1,2,differs,-3,' => 'sold_30d',
            'CW-000001,1,2,differs,3,' . str_repeat('x', 501) => 'note',
        ] as $line => $column) {
            if ($column === null) {
                self::assertSame('differs', StockDoubt::readFile($this->csv(self::HEAD . $line . "\n"))[0]['reason'], 'a reason in other letters or with spaces');
                continue;
            }
            $e = $this->refused(self::HEAD . "CW-000002,1,2,differs,3,\n" . $line . "\n");
            self::assertSame(['bad_file', 2, $column], [$e->errorCode, $e->detail['row'] ?? null, $e->detail['column'] ?? null], $line);
        }
        $e = $this->refused(self::HEAD . "CW-000002,1,2,differs,3,\nCW-000003,1,2,differs,3,\ncw-000002,5,6,below_zero,0,\n");
        self::assertSame(['bad_file', 3, 'cw_code'], [$e->errorCode, $e->detail['row'] ?? null, $e->detail['column'] ?? null], 'an item twice in one list');
        self::assertStringContainsString('row 1', $e->getMessage(), 'names the row it is on already');
    }

    public function testAQuoteLeftOpenNeverSwallowsTheRowsAfterIt(): void
    {
        // Codex round 2, LOW (the CSV reader, OLD): a cell opened with a quote and never closed took the next rows into it, and the list
        // loaded with an item missing. A line break inside a cell is refused, the row named.
        $e = $this->refused(self::HEAD . "CW-000001,1,2,differs,0,\"check\nCW-000002,3,4,differs,0,ok\nCW-000003,5,6,differs,0,fine\n");
        self::assertSame(['bad_file', 1, 'note'], [$e->errorCode, $e->detail['row'] ?? null, $e->detail['column'] ?? null]);
        $e = $this->refused(self::HEAD . "CW-000001,\"1\r\n\",2,differs,0,\n");
        self::assertSame(['bad_file', 1, 'shop_qty'], [$e->errorCode, $e->detail['row'] ?? null, $e->detail['column'] ?? null]);
    }

    public function testTheListsNameIsShortPlainText(): void
    {
        self::assertSame('doubt_12nov', StockDoubt::batch(' doubt_12nov '));
        self::assertSame('Shelf check 12 Nov 2026', StockDoubt::batch('Shelf check 12 Nov 2026'));
        foreach (['', '   ', str_repeat('a', 65), "two\nlines", 'tab	here', '-starts-with-a-dash'] as $bad) {
            try {
                StockDoubt::batch($bad);
                self::fail('a name: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                // refused before anything is read
            }
        }
    }

    public function testTheOrderDiffersFirstByTheSizeOfTheDifferenceThenBelowZeroByTheUnitsSold(): void
    {
        $row = static fn (int $id, string $reason, ?int $shop, int $cw, ?int $sold): array
            => ['id' => $id, 'reason' => $reason, 'shop_qty' => $shop, 'cw_qty' => $cw, 'sold_30d' => $sold];
        $rows = [
            $row(1, 'below_zero', 0, -2, 10),
            $row(2, 'differs', 75, 112, 182),       // 37
            $row(3, 'other', null, 5, 999),
            $row(4, 'differs', 28, -2, 42),         // 30
            $row(5, 'below_zero', 3, -1, null),
            $row(6, 'differs', 25768, 25803, 5423), // 35
            $row(7, 'below_zero', 0, -9, 300),
            $row(8, 'differs', 100, 60, 1),         // 40
            $row(9, 'differs', null, 7, 50),        // not known: after every known difference
            $row(10, 'differs', 90, 50, 2),         // 40, a later row
            $row(11, 'below_zero', 0, -1, 10),
        ];
        self::assertSame([8, 10, 2, 6, 4, 9, 7, 1, 11, 5, 3], array_column(StockDoubt::order($rows), 'id'));
        self::assertSame([], StockDoubt::order([]));
    }

    public function testOnTheShelfIsAWholeNumberOfUnits(): void
    {
        self::assertSame(42, StockDoubt::quantity('42'));
        self::assertSame(0, StockDoubt::quantity(' 0 '));
        self::assertSame(7, StockDoubt::quantity('007'));
        self::assertSame(CountSheets::MAX_QTY, StockDoubt::quantity((string) CountSheets::MAX_QTY));
        foreach (['', '  ', '-1', '4.5', '1,234', '12a', '+3', (string) (CountSheets::MAX_QTY + 1), '99999999999999', '٣'] as $bad) {
            try {
                StockDoubt::quantity($bad);
                self::fail('a quantity: ' . $bad);
            } catch (CwException $e) {
                self::assertSame(['bad_quantity', 422], [$e->errorCode, $e->httpStatus], $bad);
            }
        }
    }
}
