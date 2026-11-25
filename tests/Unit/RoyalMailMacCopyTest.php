<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\CwException;
use CW\Profit\RoyalMailBillPage;
use CW\Tests\Support\RmBill;
use PHPUnit\Framework\TestCase;

/**
 * CW-1181 (the bill of 23 Nov 2026 could not be pasted: "row 1 of the lines' table has 4 cells"): a tabbed copy from Chrome on a Mac whose
 * bill lines each come on TWO lines (date, product, quantity, description / reference, net, gross, VAT code) is read as the one-line copy of
 * the same bill (docs/decisions.md AI13), SYNTHETIC bills; a half line that is not completed (a bill line next, money between, the end) is
 * still refused whole, never read as something else.
 */
final class RoyalMailMacCopyTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function week(): array
    {
        return [
            RmBill::line('2026-11-05', 'SD1', 5, 5308, 'CLICK AND DROP M2281', '5155465848'),
            RmBill::line('2026-11-09', 'TPL', 431, 109612, '943'),
            RmBill::line('2026-11-09', 'TPM', 311, 105081, '943'),
            RmBill::line('2026-11-02', 'TRN', 1, 250, '4239'),
            RmBill::line('2026-11-06', 'RX1', 3, 925, 'ADJ5155465848RCN Surcharge'),
            RmBill::line('2026-11-08', 'RW4', 31, 3255, 'Rectification Week 32'),
            RmBill::line('2026-11-04', 'TSS', 4, 1078, ''),
            RmBill::line('2026-11-10', 'TPL', 1, -254, '5672'),
            RmBill::line('2026-11-09', 'TSS', 2, 539, '', null, 'Z', 539),
        ];
    }

    public function testATwoLineMacCopyReadsAsTheOneLineCopy(): void
    {
        $lines = self::week();
        $one = RoyalMailBillPage::parse(RmBill::text($lines), RmBill::PRODUCTS);
        $mac = RmBill::macText($lines, [], 2);
        self::assertStringContainsString("A DESCRIPTION OF THE PRODUCT\t\n", $mac, 'the shape of the real copy: a line ends after its description');
        self::assertSame($one, RoyalMailBillPage::parse($mac, RmBill::PRODUCTS), 'the same rows, totals and fingerprint');
        self::assertCount(9, $one['rows']);
    }

    /**
     * M1 (Codex final of 86aebbd): a copy that breaks AFTER the reference keeps the reference (a £12 line's manifest M42, a sales order and
     * its manifest): the same rows as the one-line copy, references and fingerprint included; a break after the reference with more words
     * before the amounts (whose words are they: the description's or the reference's?) is refused whole, never guessed.
     */
    public function testABreakAfterTheReferenceKeepsTheReference(): void
    {
        $lines = [RmBill::line('2026-11-05', 'SD1', 1, 1000, 'CLICK AND DROP M42'), RmBill::line('2026-11-05', 'SD1', 5, 5308, 'CLICK AND DROP M2281', '5155465848'),
            RmBill::line('2026-11-09', 'TPL', 431, 109612, '943'), RmBill::line('2026-11-12', 'TSS', 2, 539, '', '7181703660')];
        $one = RoyalMailBillPage::parse(RmBill::text($lines), RmBill::PRODUCTS);
        $mac = RoyalMailBillPage::parse(RmBill::macText($lines, ['break_after_ref' => [0, 1, 3]]), RmBill::PRODUCTS);
        self::assertSame($one, $mac, 'the same rows (references and sales orders), totals and fingerprint');
        self::assertSame([['manifest', '42', null], ['manifest', '2281', '5155465848'], ['preadvice', '943', null], ['none', null, '7181703660']],
            array_map(static fn (array $r): array => [$r['ref_kind'], $r['ref_no'], $r['sales_order']], $mac['rows']));
        $text = RmBill::macText($lines, ['break_after_ref' => [0]], 0);
        self::assertStringContainsString("CLICK AND DROP M42\n(HV)\nGBP 10.00", $text);
        try {
            RoyalMailBillPage::parse($text, RmBill::PRODUCTS);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('bad_file', $e->errorCode);
        }
    }

    /** @return iterable<string, array{0: \Closure(string): string}> */
    public static function broken(): iterable
    {
        yield 'a half line with its second half missing (a bill line next)' => [static fn (string $t): string => str_replace("\tGBP 1,096.12\tGBP 1,315.34\tT\n", "\n", $t)];
        yield 'money between the halves' => [static fn (string $t): string => preg_replace('/(TPM\t311\.000\tA DESCRIPTION OF THE PRODUCT\t?\n)/', "$1GBP 1.00\n", $t, 1)];
        yield 'the last line cut after its first half' => [static fn (string $t): string => preg_replace('/\n[^\n]*GBP 5\.39\tGBP 5\.39\tZ\n/', "\n", $t, 1)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broken')]
    public function testAHalfLineNotCompletedIsStillRefusedWhole(\Closure $cut): void
    {
        $text = $cut(RmBill::macText(self::week()));
        self::assertNotSame(RmBill::macText(self::week()), $text, 'the case changes the copy');
        try {
            RoyalMailBillPage::parse($text, RmBill::PRODUCTS);
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame('bad_file', $e->errorCode);
        }
    }
}
