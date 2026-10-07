<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * The goods-in plan's problems and warnings in the screens' words (the plain-words pass of the delivery screens, U86). The
 * service keeps its own sentences (Receiving\ReceiptPlan: its tests, the posting's refusal and the API read them); this class
 * recognises each of them and says it again with the glossary's words (delivery, book in, items, product card, set aside to
 * check, the unstamped quarantine) and the product's name instead of its CW number. A sentence it does not know is shown as
 * the service wrote it, so a new problem is never lost (the pattern of Ui\PoWarnings).
 *
 * Pure: no database. The caller passes the names (CW number => product name; the supplier's name).
 */
final class ReceiptWords
{
    /**
     * @param list<string> $messages what ReceiptPlan said (a problem's `message`, a warning, a line's problem)
     * @param array<string, string> $products CW number => product name
     * @return list<string>
     */
    public static function plain(array $messages, array $products = [], string $supplier = ''): array
    {
        return array_map(static fn (string $m): string => self::one($m, $products, $supplier), $messages);
    }

    /** @param array<string, string> $products */
    public static function one(string $m, array $products = [], string $supplier = ''): string
    {
        $p = static fn (string $code): string => isset($products[$code]) && trim($products[$code]) !== '' ? $products[$code] : $code;
        $s = static fn (string $code): string => $supplier !== '' ? $supplier : 'Supplier ' . $code;
        $w = static fn (string $key, string|int ...$args): string => Words::say('RECEIPT_PLAN', $key, ...$args);
        $x = [];
        return match (true) {
            // ---- the header
            $m === 'The receipt has no lines yet.' => $w('no_lines'),
            str_starts_with($m, 'Type the supplier\'s invoice number:') => $w('invoice_number_required'),
            str_starts_with($m, 'Attach the supplier\'s invoice (a PDF, or a photo of a paper invoice)') => $w('invoice_file_required'),
            preg_match('/^The attached supplier invoice \((.+)\) is also the invoice of (.+?): a supplier\'s invoice is received once\./s', $m, $x) === 1
                => $w('invoice_copy_elsewhere', $x[1], self::receiptLabel($x[2])),
            preg_match('/^Supplier (\S+) is [a-z ]+: goods are received only from an active supplier/', $m, $x) === 1 => $w('supplier_not_active', $s($x[1])),
            preg_match('/^Supplier (\S+) is overseas and its import route/', $m, $x) === 1 => $w('import_route', $s($x[1])),
            preg_match('/^Supplier (\S+): a change of its import route or of its overseas status/', $m, $x) === 1 => $w('import_route_change', $s($x[1])),
            preg_match('/^(\S+) is not a purchase order of supplier /', $m, $x) === 1 => $w('po_other_supplier', $x[1]),
            preg_match('/^(\S+) is ([a-z ]+): goods are received only against an approved, sent or part-received order/', $m, $x) === 1
                => $w('po_not_receivable', $x[1], self::poState($x[2])),
            preg_match('/^(\S+) is ([a-z ]+): no line is received against it\.$/', $m, $x) === 1 => $w('po_reference', $x[1], self::poState($x[2])),
            str_starts_with($m, 'The goods cannot have arrived after now') => $w('received_in_future'),
            preg_match('/^The goods arrived on (.+?), more than (\d+) days before the receipt was keyed/', $m, $x) === 1
                => $w('received_too_early', $x[1], (int) $x[2], (int) $x[2]),
            preg_match('/^The goods arrived on (.+?), before the receipt was keyed \((.+?)\): say why/', $m, $x) === 1 => $w('backdate_reason_required', $x[1], $x[2]),
            preg_match('/^Waiting for the goods-in bench: it has not said yet whether the supplier and the paperwork are credible, nor counted (.+)\.$/', $m, $x) === 1
                => $w('bench_check_required_lines', $x[1]),
            str_starts_with($m, 'Waiting for the goods-in bench: it has not said yet whether the supplier and the paperwork are credible') => $w('bench_check_required'),
            str_starts_with($m, 'The bench found the supplier or the paperwork not credible') => $w('paperwork_not_credible'),
            preg_match('/^Waiting for the goods-in bench: (lines? [0-9, and-]+) not counted yet/', $m, $x) === 1 => $w('line_not_checked', $x[1]),
            preg_match('/^The bench checked this delivery at (.+?), before it arrived \((.+?)\)/', $m, $x) === 1 => $w('checked_before_arrival', self::time($x[1]), self::time($x[2])),

            // ---- a line's problems
            preg_match('/^Line (\d+): (\S+) has no item line (\d+)\.$/', $m, $x) === 1 => $w('po_line_unknown', (int) $x[1], $x[2], (int) $x[3]),
            preg_match('/^Line (\d+): (\S+) line (\d+) is another item than (\S+)\.$/', $m, $x) === 1 => $w('po_line_item', (int) $x[1], (int) $x[3], $x[2], $p($x[4])),
            preg_match('/^Line (\d+): (\S+) is blocked by its item card \((.+)\), so it cannot be received\./s', $m, $x) === 1
                => $w('item_blocked', (int) $x[1], $p($x[2]), $x[3]),
            preg_match('/^Line (\d+): (\S+) gets its goods-in from the ERPNext relay of (.+?) \(/', $m, $x) === 1 => $w('relay_route', (int) $x[1], $p($x[2]), self::sites($x[3])),
            preg_match('/^Line (\d+) \((\S+)\): the bench has not recorded the duty stamp check/', $m, $x) === 1 => $w('stamp_check_required', (int) $x[1], $p($x[2])),
            preg_match('/^Line (\d+) \((\S+)\): the stamp is not on the outer retail pack, so the (\d+) units that arrived are unstamped/', $m, $x) === 1
                => $w('stamp_not_on_pack', (int) $x[1], $p($x[2]), (int) $x[3]),
            preg_match('/^Line (\d+) \((\S+)\): say which stamp it carries/', $m, $x) === 1 => $w('stamp_type_required', (int) $x[1], $p($x[2])),
            preg_match('/^Line (\d+): (\S+) needs no duty stamp \(/', $m, $x) === 1 => $w('unstamped_not_duty', (int) $x[1], $p($x[2])),
            preg_match('/^Line (\d+) \((\S+)\): from (.+?) an unstamped duty-liable delivery is refused/', $m, $x) === 1
                => $w('unstamped_refused_now', (int) $x[1], $p($x[2]), $x[3]),
            preg_match('/^Line (\d+) \((\S+)\): accepting unstamped stock needs the supplier\'s evidence .* in at least (\d+) characters\)\.$/s', $m, $x) === 1
                => $w('evidence_required', (int) $x[1], $p($x[2]), (int) $x[3]),
            preg_match('/^(\S+): lines (\d+) and (\d+) give it different selling modes \((.+?), (.+?)\): choose one\.$/', $m, $x) === 1
                => $w('mode_conflict', $p($x[1]), (int) $x[2], (int) $x[3], $x[4], $x[5]),
            preg_match('/^(Lines? [0-9, ]+): (\d+) units of (\S+) line (\d+) would be received against (\d+) ordered \((\d+) before this receipt\): more than the (\d+)% /',
                $m, $x) === 1 => $w('over_tolerance', $x[1], (int) $x[2], $x[3], (int) $x[4], (int) $x[5], (int) $x[6], (int) $x[7]),

            // ---- warnings
            preg_match('/^Line (\d+) \((\S+)\) is not received against (\S+)\.$/', $m, $x) === 1 => $w('not_against', (int) $x[1], $p($x[2]), $x[3]),
            preg_match('/^Line (\d+) \((\S+)\): its item card breaks a rule no confirmation stands behind \((.+)\): a warning/s', $m, $x) === 1
                => $w('card_warning', (int) $x[1], $p($x[2]), $x[3]),
            preg_match('/^Line (\d+) \((\S+)\) is marked discontinued on its item card\.$/', $m, $x) === 1 => $w('discontinued', (int) $x[1], $p($x[2])),
            preg_match('/^Line (\d+) \((\S+)\): no item card says whether it is duty-liable/', $m, $x) === 1 => $w('no_card', (int) $x[1], $p($x[2])),
            preg_match('/^Line (\d+) \((\S+)\): its item card does not say whether it is duty-liable/', $m, $x) === 1 => $w('duty_unknown', (int) $x[1], $p($x[2])),
            preg_match('/^Line (\d+) \((\S+)\): (\d+) unstamped units accepted on the supplier\'s evidence/', $m, $x) === 1
                => $w('pre_october', (int) $x[1], $p($x[2]), (int) $x[3]),
            preg_match('/^Line (\d+) \((\S+)\): its (.+?) units arrived unstamped too .*so they are refused at the door, not put in VERIFY\.$/s', $m, $x) === 1
                => $w('extras_refused', (int) $x[1], $p($x[2]), self::extras($x[3])),
            preg_match('/^Line (\d+) \((\S+)\): its (.+?) units arrived unstamped too .*so they are quarantined in UNSTAMPED, not put in VERIFY\.$/s', $m, $x) === 1
                => $w('extras_quarantined', (int) $x[1], $p($x[2]), self::extras($x[3])),
            preg_match('/^Line (\d+) \((\S+)\): (\d+) units over on a line of (\d+) \(the bench confirmed the count\)/', $m, $x) === 1
                => $w('over_confirmed', (int) $x[1], $p($x[2]), (int) $x[3], (int) $x[4]),
            preg_match('/^Line (\d+) \((\S+)\): (.+) \(an incident each when posted\)\.$/', $m, $x) === 1 => $w('findings', (int) $x[1], $p($x[2]), self::findings($x[3])),
            preg_match('/^Line (\d+) \((\S+)\) costs £0: a free item\?/u', $m, $x) === 1 => $w('free', (int) $x[1], $p($x[2])),
            preg_match('/^(\S+): nothing of it is accepted into MAIN, so its selling mode stays as it is /', $m, $x) === 1 => $w('mode_kept_none', $p($x[1])),
            preg_match('/^(\S+): nothing of it is accepted into MAIN, so its selling mode stays (\S+) \(/', $m, $x) === 1 => $w('mode_kept', $p($x[1]), $x[2]),
            preg_match('/^(\S+) line (\d+): (\d+) units received against (\d+) ordered \(within the (\d+)% tolerance\)\.$/', $m, $x) === 1
                => $w('within_tolerance', $x[1], (int) $x[2], (int) $x[3], (int) $x[4], (int) $x[5]),
            preg_match('/^(\S+): selling mode (.+?) → (\S+) \((.+)\)\.$/u', $m, $x) === 1
                => $w('mode_change', $p($x[1]), $x[2] === 'not known' ? Words::RECEIPT_PLAN['mode_unknown'] : $x[2], $x[3], self::source($x[4])),
            // ---- a scan that needs a choice (GoodsReceipts::scanned)
            preg_match('/^That barcode is one unit of (\S+), but this supplier sells it in (.+): add one of its packs, or single units\?$/', $m, $x) === 1
                => $w('one_unit', $p($x[1]), $x[2]),
            default => $m,
        };
    }

    /** "vapeandgo, electrofag" -> "Vape and Go, Electrofag" (Words::SITE; a code without a name stays). */
    private static function sites(string $codes): string
    {
        return implode(', ', array_map(static fn (string $c): string => Words::SITE[trim($c)] ?? trim($c), explode(',', $codes)));
    }

    /** "7 Oct 2026 14:05" (ReceiptPlan::time) -> "7 Oct 2026, 14:05", the one UK-time form of the screens (Html::when). */
    private static function time(string $t): string
    {
        return (string) preg_replace('/^(\d{1,2} [A-Z][a-z]{2} \d{4}) (\d{2}:\d{2})$/', '$1, $2', $t);
    }

    /** "draft receipt #12" -> "delivery #12 (not booked in yet)", keeping what follows ("(Sam)"); a number (GRN-000003) stays. */
    public static function receiptLabel(string $label): string
    {
        return preg_match('/^[a-z ]+ receipt #(\d+)(.*)$/s', $label, $x) === 1 ? Words::say('RECEIPT', 'draft_ref', $x[1]) . $x[2] : $label;
    }

    /** A PO's state as the service wrote it ("part received") in the screens' words, lower case ("partly delivered"). */
    private static function poState(string $state): string
    {
        $code = str_replace(' ', '_', trim($state));
        return Words::has('PO_STATE', $code) ? mb_strtolower(Words::of('PO_STATE', $code)) : $state;
    }

    /** "2 damaged and 1 over" -> "2 damaged and 1 extra items". */
    private static function extras(string $s): string
    {
        $parts = [];
        foreach (explode(' and ', $s) as $part) {
            if (preg_match('/^(\d+) (damaged|over)$/', trim($part), $x) === 1) {
                $parts[] = Words::say('RECEIPT_PLAN', $x[2] === 'over' ? 'over_n' : 'damaged_n', (int) $x[1]);
            }
        }
        return ($parts === [] ? $s : implode(' ' . Words::RECEIPT_PLAN['and'] . ' ', $parts)) . ' ' . Words::RECEIPT_PLAN['items_word'];
    }

    /** "2 short, 1 over, 3 wrong item" -> "2 short, 1 extra, 3 wrong product". */
    private static function findings(string $s): string
    {
        $out = [];
        foreach (explode(', ', $s) as $part) {
            if (preg_match('/^(\d+) (short|over|damaged|wrong item|unstamped)$/', trim($part), $x) === 1) {
                $key = ['short' => 'short_n', 'over' => 'over_n', 'damaged' => 'damaged_n', 'wrong item' => 'wrong_n', 'unstamped' => 'unstamped_n'][$x[2]];
                $out[] = Words::say('RECEIPT_PLAN', $key, (int) $x[1]);
            } else {
                $out[] = $part;
            }
        }
        return implode(', ', $out);
    }

    /** The reason of a mode change as the plan wrote it, in the screens' words (MODE_SOURCE). */
    private static function source(string $s): string
    {
        return match (true) {
            $s === 'chosen on the receipt' => Words::MODE_SOURCE['chosen'],
            $s === 'its mode before it went Out-Of-Stock' => Words::MODE_SOURCE['previous'],
            str_starts_with($s, 'no earlier mode known') => Words::MODE_SOURCE['fallback'],
            $s === 'its last mode' => Words::MODE_SOURCE['last'],
            default => $s,
        };
    }
}
