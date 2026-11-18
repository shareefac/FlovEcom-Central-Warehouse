<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Counts;

use CW\Tests\Support\LineUids;

/**
 * A count sheet's document lines, written when the sheet is made final (CountSheets::post -> Documents::setLines; 0070, docs/decisions.md
 * LV8): they get ids like any other save, and the posting's fingerprint covers none. The sheet's own lines (count_sheet_line) keep their
 * numbers as before.
 */
final class CountLineUidTest extends CountsTestCase
{
    use LineUids;

    public function testAPostedSheetsLinesGetIdsAndItsFingerprintCoversNone(): void
    {
        $a = $this->item('strict', 10);
        $b = $this->item('legacy', 5);
        $sc = $this->staffUser('stock_controller');
        $sheet = $this->sheet($sc, [$a, $b]);
        $this->enter($sc, $sheet, $a, 8);
        $this->enter($sc, $sheet, $b, 5);
        $posted = $this->post($sc, $sheet);
        self::assertSame(['posted', 'CNT'], [$posted->status, $posted->docType]);
        self::assertSame([1, 2], self::uidsOf($posted->id));
        self::assertPostingIgnoresUids($this->docs, $posted->id);
    }
}
