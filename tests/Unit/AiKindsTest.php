<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ai\ExpenseInvoiceKind;
use CW\Ai\RoyalMailBillKind;
use CW\CwException;
use CW\Profit\RoyalMailBillPage;
use CW\Tests\Support\AiAnswers;
use CW\Tests\Support\RmBill;
use PHPUnit\Framework\TestCase;

/**
 * CW-1182 phase 1 (docs/decisions.md AI4, AI5): Claude's answers in the kinds' fixed shapes, read strictly by CW, from MADE-UP answers.
 * A Royal Mail bill's reading written out as the page reader's text reads, through the EXISTING RoyalMailBillPage::parse, exactly as the
 * bill's saved page (rows, totals, the fingerprint: the same bill as a page and as a PDF is "the same file"); every check of the reader still
 * applies (a reading that does not tie is refused, never corrected); a field out of its form refuses the whole answer, naming the field, never
 * what it holds. An expense's reading fills only what holds together.
 */
final class AiKindsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function week(): array
    {
        return [
            RmBill::line('2026-11-05', 'SD1', 5, 5308, 'CLICK AND DROP M2281', '5155465848'),
            RmBill::line('2026-11-05', 'SD1', 2, 2007, 'CLICK AND DROP M2281', '5155465848'),
            RmBill::line('2026-11-09', 'TPL', 431, 109612, '943'),
            RmBill::line('2026-11-09', 'TPM', 311, 105081, '943'),
            RmBill::line('2026-11-02', 'TRN', 1, 250, '4239'),
            RmBill::line('2026-11-06', 'RX1', 3, 925, 'ADJ5155465848RCN Surcharge'),
            RmBill::line('2026-11-08', 'RW4', 31, 3255, 'Rectification Week 32'),
            RmBill::line('2026-11-08', 'RW4', 1, 105, 'IPROL Week 32'),
            RmBill::line('2026-11-04', 'TSS', 4, 1078, ''),
            RmBill::line('2026-11-12', 'TSS', 2, 539, '', '7181703660'),
            RmBill::line('2026-11-10', 'TPL', 1, -254, '5672'),
            RmBill::line('2026-11-09', 'TSS', 2, 539, '', null, 'Z', 539),
        ];
    }

    private static function read(array $answer): array
    {
        $k = new RoyalMailBillKind();
        return RoyalMailBillPage::parse(RoyalMailBillKind::render($k->shape($answer)), RmBill::PRODUCTS);
    }

    public function testABillsReadingReadsAsItsSavedPage(): void
    {
        $lines = self::week();
        $page = RoyalMailBillPage::parse(RmBill::page($lines, ['invoice' => '9077300608']), RmBill::PRODUCTS);
        // M1 (Codex final of 86aebbd): a reference cell is written as Claude read it, so a bare sales order ("7181703660", no slash) keeps its order
        foreach (['slash', 'spaced', 'bare'] as $form) {
            self::assertSame($page, self::read(AiAnswers::royalMail($lines, ['invoice' => '9077300608', 'sales_form' => $form])),
                "the rows, totals and fingerprint of the page (a sales order printed {$form})");
        }
        $text = RoyalMailBillKind::render((new RoyalMailBillKind())->shape(AiAnswers::royalMail($lines)));
        self::assertStringNotContainsString("\t", $text, 'the page reader\'s text: never a tab');
        self::assertStringContainsString("\n12:11:2026 TSS 2.000 A DESCRIPTION OF THE PRODUCT\n7181703660/ GBP 5.39 GBP 6.47 T\n", $text,
            'a line whose cell prints only its sales order (the real bill of 23 Nov 2026)');
        self::assertSame('7181703660', $page['rows'][9]['sales_order']);
        // no surcharge note, and a thousands separator Claude was told not to write: read the same
        $a = AiAnswers::royalMail($lines, ['surcharge' => null]);
        $a['lines'][2]['net'] = '1,096.12';
        self::assertSame($page['rows'], self::read($a)['rows']);
    }

    public function testEveryCheckOfThePastedBillStillDecides(): void
    {
        $lines = self::week();
        $cases = [
            'a line read a penny out: the lines do not add up to the net' => static function (array $a): array {
                $a['lines'][3]['net'] = '1050.82';
                return $a;
            },
            'a line left out (its money): the lines do not add up' => static function (array $a): array {
                array_splice($a['lines'], 4, 1);
                $a['line_count']--;
                return $a;
            },
            'an unknown product' => static function (array $a): array {
                $a['lines'][0]['product_code'] = 'ZZ9';
                return $a;
            },
            'the VAT not following from the codes' => static function (array $a): array {
                $a['lines'][11]['vat_code'] = 'T';
                return $a;
            },
            'a gross read wrong' => static function (array $a): array {
                $a['lines'][2]['gross'] = '1315.35';
                return $a;
            },
        ];
        foreach ($cases as $why => $change) {
            try {
                self::read($change(AiAnswers::royalMail($lines)));
                self::fail('refused: ' . $why);
            } catch (CwException $e) {
                self::assertSame('bad_file', $e->errorCode, $why);
            }
        }
    }

    public function testAnAnswerOutOfItsFormIsRefusedWholeNamingTheField(): void
    {
        $k = new RoyalMailBillKind();
        $lines = self::week();
        $cases = [
            ['ai_not_readable', 'pages_missing', ['readable' => false, 'not_readable_reason' => 'pages_missing', 'lines' => []]],
            ['ai_not_readable', 'other', ['readable' => false, 'not_readable_reason' => 'whatever Claude wrote']],
            ['ai_not_gbp', 'the bill is not in pounds (GBP)', ['currency' => 'EUR']],
            ['ai_bad_answer', 'the bill\'s date', ['date' => '2026-02-31']],
            ['ai_bad_answer', 'the invoice number', ['invoice' => 'INV 1']],
            ['ai_bad_answer', 'the customer account number', ['account' => '0706 703000']],
            ['ai_count', '12', ['line_count' => 11]],
            ['ai_bad_answer', 'the lines (none)', ['lines' => [], 'line_count' => 0]],
        ];
        foreach ($cases as [$code, $words, $over]) {
            try {
                $k->shape(AiAnswers::royalMail($lines, $over));
                self::fail("refused: {$code} {$words}");
            } catch (CwException $e) {
                self::assertSame([$code, $words], [$e->errorCode, $e->getMessage()]);
            }
        }
        foreach (['net' => ['18859.7', 'the net total'], 'vat' => ['GBP 3,771.95', 'the VAT'], 'gross' => [22631.69, 'the total with VAT'],
            'surcharge_included' => ['about 2k', 'the surcharge included']] as $field => [$v, $words]) {
            $a = AiAnswers::royalMail($lines);
            $a[$field] = $v;
            try {
                $k->shape($a);
                self::fail($words);
            } catch (CwException $e) {
                self::assertSame(['ai_bad_answer', $words], [$e->errorCode, $e->getMessage()]);
            }
        }
        $line = static function (int $i, string $field, mixed $v) use ($lines): array {
            $a = AiAnswers::royalMail($lines);
            $a['lines'][$i][$field] = $v;
            return $a;
        };
        foreach ([[$line(2, 'service_date', '31:02:2026'), 'line 3: its date'], [$line(0, 'product_code', 'SD 1'), 'line 1: its product code'],
            [$line(4, 'quantity', 'one'), 'line 5: its quantity'], [$line(1, 'net', 'GBP 20.07'), 'line 2: its net'], [$line(1, 'gross', '24.1'), 'line 2: its gross'],
            [$line(6, 'vat_code', 'TT'), 'line 7: its VAT code'], [$line(3, 'description', 'TRACKED GBP 5.00 EXTRA'), 'line 4: its description'],
            [$line(3, 'reference', str_repeat('X', 201)), 'line 4: its reference'], [$line(3, 'reference', 42), 'line 4: its reference'],
            [$line(3, 'reference', '12:11:2026 TPL'), 'line 4: its reference']] as [$a, $words]) {
            try {
                $k->shape($a);
                self::fail($words);
            } catch (CwException $e) {
                self::assertSame(['ai_bad_answer', $words], [$e->errorCode, $e->getMessage()], 'the field named, never what it holds');
            }
        }
        // a line break inside a description never makes a line of its own: squashed to one line
        $a = $line(3, 'description', "ROYAL MAIL TRACKED 48\n(HV)");
        self::assertSame(RoyalMailBillPage::parse(RmBill::page($lines), RmBill::PRODUCTS)['rows'], self::read($a)['rows']);
        self::assertSame('ROYAL MAIL TRACKED 48 (HV)', $k->shape($a)['lines'][3]['description']);
    }

    public function testTheSchemaIsClosedAndEveryFieldRequired(): void
    {
        foreach ([new RoyalMailBillKind(), new ExpenseInvoiceKind()] as $k) {
            $walk = static function (array $s) use (&$walk): void {
                if (($s['type'] ?? null) === 'object') {
                    self::assertFalse($s['additionalProperties']);
                    self::assertSame(array_keys($s['properties']), $s['required'], 'every field required, in order');
                    foreach ($s['properties'] as $p) {
                        $walk($p);
                    }
                }
                if (($s['type'] ?? null) === 'array') {
                    $walk($s['items']);
                }
                foreach ($s['anyOf'] ?? [] as $p) {
                    $walk($p);
                }
                foreach (['minLength', 'maxLength', 'minimum', 'maximum', 'pattern', 'minItems', 'maxItems'] as $no) {
                    self::assertArrayNotHasKey($no, $s, 'only what structured outputs take: CW checks the rest');
                }
            };
            $walk($k->schema());
            self::assertGreaterThan(1000, $k->maxTokens());
            self::assertStringContainsString('exactly as printed', $k->instructions());
        }
    }

    /** The cost estimate of a week (docs/decisions.md AI8): a 160-line answer is ~25 KB of JSON. */
    public function testAWeeksAnswerSize(): void
    {
        $lines = [];
        for ($i = 0; $i < 160; $i++) {
            $lines[] = RmBill::line('2026-11-' . str_pad((string) (1 + $i % 9), 2, '0', STR_PAD_LEFT), ['TPL', 'TPM', 'SD1', 'TSS'][$i % 4], 1 + $i, 250 * (1 + $i),
                $i % 4 === 2 ? 'CLICK AND DROP M' . (2000 + $i) : (string) (900 + $i), $i % 4 === 2 ? '51554658' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) : null);
        }
        $json = json_encode(AiAnswers::royalMail($lines), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertGreaterThan(20_000, strlen($json));
        self::assertLessThan(40_000, strlen($json));
        self::assertCount(160, self::read(AiAnswers::royalMail($lines))['rows']);
    }

    /**
     * H3 (Codex final of 86aebbd): an invoice's reading is read against its WHOLE schema before anything is filled: every field present and of
     * its type (an explicit null where the schema allows it), no other field, each amount "1234.50" (a minus only as a sign), a date a day, the
     * currency three letters; anything else refuses the whole reading, naming the field. The amounts are filled ONLY when the currency is
     * GBP, all three are read, net + VAT = total exactly and none is below 0, and it is not a credit note; else none is filled and the
     * person is told why (they type them).
     */
    public function testAnInvoicesReadingFillsOnlyWhatHoldsTogether(): void
    {
        $k = new ExpenseInvoiceKind();
        $sup = [['id' => 4, 'name' => 'Made Up Paper Ltd'], ['id' => 5, 'name' => 'Other Supplies']];
        $shops = [['id' => 1, 'name' => 'Vape and Go'], ['id' => 2, 'name' => 'Electrofag'], ['id' => 3, 'name' => 'Vape Big']];
        $typed = ['shop' => '', 'supplier' => '', 'new_supplier' => false, 'type' => '7', 'invoice_date' => '', 'invoice_no' => '', 'credit' => false, 'net' => '',
            'vat' => '', 'total' => '', 'vat_way' => 'inc', 'hand' => '', 'anchor' => false, 'note' => 'typed before'];
        $f = ExpenseInvoiceKind::fill($k->shape(AiAnswers::invoice(['supplier_name' => " made  up PAPER ltd "])), $sup, $shops, 2000, $typed);
        self::assertSame(['Made Up Paper Ltd', 'INV-20417', '2026-11-20', '84.72', '70.60', '14.12', 'inc', '', true, '7', 'typed before', ''],
            [$f['typed']['supplier'], $f['typed']['invoice_no'], $f['typed']['invoice_date'], $f['typed']['total'], $f['typed']['net'], $f['typed']['vat'],
                $f['typed']['vat_way'], $f['typed']['hand'], $f['typed']['anchor'], $f['typed']['type'], $f['typed']['note'], $f['typed']['shop']]);
        self::assertSame(['supplier', 'invoice_no', 'invoice_date', 'total', 'net', 'vat'], $f['filled']);
        self::assertSame([], $f['notes']);
        // a supplier CW does not have: left to staff, its printed name said; a shop printed as billed: picked; no VAT: "No VAT"
        $f = ExpenseInvoiceKind::fill($k->shape(AiAnswers::invoice(['supplier_name' => 'New Co', 'billed_to' => 'ELECTROFAG', 'net' => '12.00', 'vat' => '0.00',
            'total' => '12.00'])), $sup, $shops, 2000, $typed);
        self::assertSame(['', '2', 'none', '12.00'], [$f['typed']['supplier'], $f['typed']['shop'], $f['typed']['vat_way'], $f['typed']['total']]);
        self::assertSame([['fill_supplier_choose', ['New Co']]], $f['notes']);
        // never filled, said: not added up, another or an unknown currency, one missing (never worked out from the others), below 0, a credit note
        foreach ([[['total' => '90.00'], 'fill_amounts_disagree'], [['currency' => 'USD'], 'fill_not_gbp'], [['currency' => null], 'fill_currency_unknown'],
            [['net' => null], 'fill_amounts_missing'], [['net' => null, 'vat' => null], 'fill_amounts_missing'],
            [['net' => '-70.60', 'vat' => '-14.12', 'total' => '-84.72'], 'fill_negative'], [['net' => '-70.60', 'vat' => '14.12', 'total' => '-56.48'], 'fill_negative'],
            [['document_kind' => 'credit_note'], 'fill_credit_note']] as [$over, $note]) {
            $f = ExpenseInvoiceKind::fill($k->shape(AiAnswers::invoice($over)), $sup, $shops, 2000, $typed);
            self::assertSame(['', '', ''], [$f['typed']['net'], $f['typed']['vat'], $f['typed']['total']], $note);
            self::assertSame($note, $f['notes'][array_key_last($f['notes'])][0], $note);
            self::assertTrue(\CW\Ui\Words::has('AIREAD', $note), "{$note} is said in words");
        }
        $f = ExpenseInvoiceKind::fill($k->shape(AiAnswers::invoice(['document_kind' => 'credit_note'])), $sup, $shops, 2000, $typed);
        self::assertSame([true, ['supplier', 'invoice_no', 'invoice_date', 'credit']], [$f['typed']['credit'], $f['filled']], 'a credit note: its tick, no amounts');
        // the whole reading refused, naming the field: a malformed amount (the Codex case: a total of "84.7x" with net and VAT that add up), a
        // malformed date or currency, a field missing, one more, a wrong type, a text too long
        $without = AiAnswers::invoice();
        unset($without['total']);
        foreach ([[AiAnswers::invoice(['total' => '84.7x']), 'the total'], [AiAnswers::invoice(['net' => '1,234.56']), 'the net'],
            [AiAnswers::invoice(['vat' => 'fourteen']), 'the VAT'], [AiAnswers::invoice(['total' => 84.72]), 'the total'],
            [AiAnswers::invoice(['invoice_date' => '2026-13-01']), 'the invoice date'], [AiAnswers::invoice(['currency' => 'pounds']), 'the currency'],
            [AiAnswers::invoice(['invoice_number' => str_repeat('9', 65)]), 'the invoice number'], [AiAnswers::invoice(['supplier_name' => str_repeat('S', 101)]), 'the supplier'],
            [AiAnswers::invoice(['billed_to' => 7]), 'the name billed'], [$without, 'the total (missing)'], [AiAnswers::invoice(['tip' => 'x']), 'a field CW did not ask for'],
            [AiAnswers::invoice(['readable' => 'yes']), 'readable'], [AiAnswers::invoice(['not_readable_reason' => 'not_an_invoice']), 'the reason not readable'],
            [AiAnswers::invoice(['document_kind' => 'statement']), 'the kind of document']] as [$answer, $words]) {
            try {
                $k->shape($answer);
                self::fail("refused: {$words}");
            } catch (CwException $e) {
                self::assertSame(['ai_bad_answer', $words], [$e->errorCode, $e->getMessage()]);
            }
        }
        // not an invoice: refused with Claude's reason (a code), nothing filled
        try {
            $k->shape(AiAnswers::invoice(['readable' => false, 'not_readable_reason' => 'not_an_invoice']));
            self::fail('refused');
        } catch (CwException $e) {
            self::assertSame(['ai_not_readable', 'not_an_invoice'], [$e->errorCode, $e->getMessage()]);
        }
    }
}
