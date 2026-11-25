<?php

declare(strict_types=1);

namespace CW\Ai;

use CW\CwException;
use CW\Expenses\ExpenseMoney;
use CW\Expenses\Expenses;

/**
 * An expense's invoice (a PDF or a photo) read by Claude to fill the expense form (CW-1182 phase 1; docs/decisions.md AI5): the supplier's name
 * as printed, the invoice number and date, the currency, the amount without VAT, the VAT and the total with VAT as printed, whether it is a
 * credit note, and the name it is billed to (to see whether a shop's name is printed). CW fills the form's boxes only (fill()); staff see
 * them filled, change anything, and press Save: every check of the expense runs on Save as before (the supplier on the list or ticked new, the
 * amounts adding up to the total, the same invoice twice, ...). CW never fills what does not hold together: amounts that do not add up, or
 * not in pounds, are left empty with the reason said; a supplier is filled only when it is exactly one of CW's own (else staff choose, with
 * the printed name shown); a shop only when its exact name is printed as the one billed. Never a confidence number.
 */
final class ExpenseInvoiceKind implements Kind
{
    public const NAME = 'expense_invoice';
    public const NOT_READABLE = ['not_an_invoice', 'unreadable', 'other'];
    public const DOCUMENTS = ['invoice', 'credit_note', 'receipt'];
    private const TEXT_MAX = 100;

    public function name(): string
    {
        return self::NAME;
    }

    public function maxTokens(): int
    {
        return 8_000;
    }

    public function instructions(): string
    {
        return <<<'TXT'
This document is an invoice, a credit note or a receipt that a supplier sent to a business (a PDF or a photo). Copy what it prints into the JSON form, exactly as printed. Do not work anything out and do not correct anything: when a figure is not printed, give null.

- document_kind: "invoice", "credit_note" (a credit note or a refund) or "receipt".
- supplier_name: the name of the company that issued the document, as printed (without its address).
- invoice_number: the invoice (or credit note, or receipt) number as printed, or null.
- invoice_date: the invoice date (the tax point if that is the only date) as YYYY-MM-DD, or null.
- currency: the three-letter code of the currency the amounts are in (GBP for pounds, USD for dollars, EUR for euros).
- net: the total without VAT; vat: the total VAT; total: the total with VAT (what is to be paid, before any payment already made). Each as printed for the whole document, not a line.
- billed_to: the name of the business the document is addressed or billed to, without its address, or null.

Every amount: digits with a decimal point and exactly two decimals, no currency and no thousands separators (for example 1234.50); give a credit note's amounts without a minus sign.

If the document is not an invoice, credit note or receipt, or the supplier or the total cannot be read with certainty, set readable to false and not_readable_reason to the reason. Otherwise set readable to true and not_readable_reason to null.
TXT;
    }

    public function schema(): array
    {
        $ns = ['anyOf' => [['type' => 'string'], ['type' => 'null']]];
        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['readable', 'not_readable_reason', 'document_kind', 'supplier_name', 'invoice_number', 'invoice_date', 'currency', 'net', 'vat', 'total',
                'billed_to'],
            'properties' => [
                'readable' => ['type' => 'boolean'],
                'not_readable_reason' => ['anyOf' => [['type' => 'string', 'enum' => self::NOT_READABLE], ['type' => 'null']]],
                'document_kind' => ['type' => 'string', 'enum' => self::DOCUMENTS],
                'supplier_name' => $ns, 'invoice_number' => $ns, 'invoice_date' => $ns, 'currency' => $ns, 'net' => $ns, 'vat' => $ns, 'total' => $ns,
                'billed_to' => $ns,
            ]];
    }

    /** The answer's fields in the schema's order, with the words a refusal names each by (never what it holds). */
    private const FIELDS = ['readable' => 'readable', 'not_readable_reason' => 'the reason not readable', 'document_kind' => 'the kind of document',
        'supplier_name' => 'the supplier', 'invoice_number' => 'the invoice number', 'invoice_date' => 'the invoice date', 'currency' => 'the currency',
        'net' => 'the net', 'vat' => 'the VAT', 'total' => 'the total', 'billed_to' => 'the name billed'];

    /**
     * The answer read against its WHOLE schema (H3, the Codex final review of 86aebbd), before anything is filled: exactly the schema's fields
     * (one missing or one more: refused), each of its type: readable a boolean; when readable, not_readable_reason null and document_kind one
     * of DOCUMENTS; every other field a string or an explicit null; a text one line of at most its box, a date a day (YYYY-MM-DD), the currency
     * three letters, an amount "1234.50" (a minus only as a sign). Anything else refuses the WHOLE reading (ai_bad_answer, naming the field),
     * never a part filled from it. Shaped: text squashed to one line, amounts in pence (signed), the currency in capitals.
     *
     * @return array{credit: bool, supplier: ?string, invoice_no: ?string, invoice_date: ?string, currency: ?string, net: ?int, vat: ?int, total: ?int,
     *   billed_to: ?string}
     */
    public function shape(array $answer): array
    {
        $bad = static fn (string $field): CwException => new CwException('ai_bad_answer', $field, 422);
        foreach (self::FIELDS as $k => $words) {
            if (!array_key_exists($k, $answer)) {
                throw $bad($words . ' (missing)');
            }
        }
        if (array_diff_key($answer, self::FIELDS) !== []) {
            throw $bad('a field CW did not ask for');
        }
        if (!is_bool($answer['readable'])) {
            throw $bad(self::FIELDS['readable']);
        }
        if ($answer['readable'] === false) {
            $why = $answer['not_readable_reason'];
            throw new CwException('ai_not_readable', is_string($why) && in_array($why, self::NOT_READABLE, true) ? $why : 'other', 422);
        }
        if ($answer['not_readable_reason'] !== null) {
            throw $bad(self::FIELDS['not_readable_reason']);
        }
        $kind = $answer['document_kind'];
        if (!is_string($kind) || !in_array($kind, self::DOCUMENTS, true)) {
            throw $bad(self::FIELDS['document_kind']);
        }
        $text = static function (string $k, int $max = self::TEXT_MAX) use ($answer, $bad): ?string {
            $v = $answer[$k];
            if ($v === null) {
                return null;
            }
            if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
                throw $bad(self::FIELDS[$k]);
            }
            $v = Expenses::plain((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v));
            if (mb_strlen($v) > $max) {
                throw $bad(self::FIELDS[$k]);
            }
            return $v === '' ? null : $v;
        };
        $date = $text('invoice_date', 10);
        if ($date !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
            if ($d === false || $d->format('Y-m-d') !== $date || $date < '2000-01-01' || $date > '2099-12-31') {
                throw $bad(self::FIELDS['invoice_date']);
            }
        }
        $cur = $text('currency', 3);
        if ($cur !== null && preg_match('/^[A-Za-z]{3}$/D', $cur) !== 1) {
            throw $bad(self::FIELDS['currency']);
        }
        $money = static function (string $k) use ($answer, $bad): ?int {
            $v = $answer[$k];
            if ($v === null) {
                return null;
            }
            if (!is_string($v) || preg_match('/^(-?)(\d{1,8})\.(\d{2})$/D', $v, $m) !== 1) {
                throw $bad(self::FIELDS[$k]);
            }
            return ((int) $m[2] * 100 + (int) $m[3]) * ($m[1] === '-' ? -1 : 1);
        };
        return ['credit' => $kind === 'credit_note', 'supplier' => $text('supplier_name'), 'invoice_no' => $text('invoice_number', 64), 'invoice_date' => $date,
            'currency' => $cur === null ? null : strtoupper($cur), 'net' => $money('net'), 'vat' => $money('vat'), 'total' => $money('total'),
            'billed_to' => $text('billed_to')];
    }

    /**
     * The expense form's boxes from a shaped answer, over what the person typed before (a box Claude read wins; one it did not read keeps what
     * was typed). The amounts only when they hold together (H3): in pounds (GBP read, never assumed), all three read (none worked out from
     * the others), net + VAT = total to the penny, none below 0, not a credit note, each within a box; else none filled and why said; the VAT
     * choice the figures match (ExpensesController's rule: VAT 0 = No VAT, the shops' rate's split of the total = Includes, else Different VAT).
     *
     * @param array<string, mixed> $a shape()
     * @param list<array{id: int, name: string}> $suppliers the active expense suppliers
     * @param list<array{id: int, name: string}> $shops the shops
     * @param array<string, mixed> $typed what the form held
     * @return array{typed: array<string, mixed>, filled: list<string>, notes: list<array{0: string, 1: list<string>}>} notes: [AI word key, its values]
     */
    public static function fill(array $a, array $suppliers, array $shops, int $vatBp, array $typed): array
    {
        $filled = [];
        $notes = [];
        $key = static fn (string $s): string => mb_strtolower(Expenses::plain($s));
        if ($a['supplier'] !== null) {
            $hits = array_values(array_filter($suppliers, static fn (array $s): bool => $key((string) $s['name']) === $key((string) $a['supplier'])));
            if (count($hits) === 1) {
                $typed['supplier'] = (string) $hits[0]['name'];
                $typed['new_supplier'] = false;
                $filled[] = 'supplier';
            } else {
                $notes[] = ['fill_supplier_choose', [(string) $a['supplier']]];
            }
        }
        if ($a['invoice_no'] !== null) {
            $typed['invoice_no'] = (string) $a['invoice_no'];
            $filled[] = 'invoice_no';
        }
        if ($a['invoice_date'] !== null) {
            $typed['invoice_date'] = (string) $a['invoice_date'];
            $filled[] = 'invoice_date';
        }
        if ($a['billed_to'] !== null) {
            $hits = array_values(array_filter($shops, static fn (array $s): bool => $key((string) $s['name']) === $key((string) $a['billed_to'])));
            if (count($hits) === 1) {
                $typed['shop'] = (string) $hits[0]['id'];
                $filled[] = 'shop';
            }
        }
        $typed['credit'] = (bool) $a['credit'];
        if ($a['credit']) {
            $filled[] = 'credit';
        }
        [$net, $vat, $total] = [$a['net'], $a['vat'], $a['total']];
        // H3: the amounts only when they are certainly the expense's: in pounds (said so), all three read (none worked out), added up to the
        // penny, none below 0, and not a credit note (its minus is the person's to set: they type them and tick Credit note); else none
        $why = match (true) {
            $a['credit'] => ['fill_credit_note', []],
            $a['currency'] === null => ['fill_currency_unknown', []],
            $a['currency'] !== 'GBP' => ['fill_not_gbp', [(string) $a['currency']]],
            $net === null || $vat === null || $total === null => ['fill_amounts_missing', []],
            $net < 0 || $vat < 0 || $total < 0 => ['fill_negative', []],
            $net + $vat !== $total => ['fill_amounts_disagree', [ExpenseMoney::gbp($net + $vat), ExpenseMoney::gbp($total)]],
            $net > ExpenseMoney::MAX_PENCE || $vat > ExpenseMoney::MAX_PENCE => ['fill_amounts_disagree', [ExpenseMoney::gbp($net + $vat), ExpenseMoney::gbp($total)]],
            default => null,
        };
        if ($why !== null) {
            $notes[] = $why;
        } else {
            $typed['net'] = ExpenseMoney::plain($net);
            $typed['vat'] = ExpenseMoney::plain($vat);
            $typed['total'] = ExpenseMoney::plain($total);
            $typed['vat_way'] = $vat === 0 ? 'none' : ($net === ExpenseMoney::netOfTotal($total, $vatBp) ? 'inc' : 'other');
            // the total leads the page's script from here (data-expense-anchor), the two boxes as Claude read them
            $typed['hand'] = '';
            $typed['anchor'] = true;
            array_push($filled, 'total', 'net', 'vat');
        }
        return ['typed' => $typed, 'filled' => $filled, 'notes' => $notes];
    }
}
