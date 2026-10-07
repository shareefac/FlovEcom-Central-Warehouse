<?php

declare(strict_types=1);

namespace CW\Ui;

/**
 * The purchase-order services' warnings in the screens' words (plan F262, F263, F267, F270, F284; rule 18). The services keep
 * their own sentences (PurchaseOrders::warnings() / sendWarnings(), PoLinesFile's row errors): their tests and the API read them.
 * This class recognises each of them and says it again with the glossary's words and the names people know (the supplier's
 * name, the product's name). A sentence it does not know is shown as the service wrote it, so a new warning is never lost.
 *
 * Pure: no database. The caller passes the names.
 */
final class PoWarnings
{
    /**
     * @param list<string> $warnings what PurchaseOrders::warnings() said
     * @param string $supplier the order's supplier by name
     * @param array<string, string> $products CW number => product name (the order's lines)
     * @return list<string>
     */
    public static function plain(array $warnings, string $supplier, array $products): array
    {
        $out = [];
        foreach ($warnings as $w) {
            $out[] = self::one($w, $supplier, $products);
        }
        return $out;
    }

    /** @param array<string, string> $products */
    public static function one(string $w, string $supplier, array $products): string
    {
        $product = static fn (string $code): string => isset($products[$code]) && trim($products[$code]) !== '' ? $products[$code] : $code;
        $m = [];
        return match (true) {
            preg_match('/^Supplier \S+ is inactive: the order can be drafted/', $w) === 1 => Words::say('PO_WARN', 'supplier_stopped', $supplier),
            preg_match('/^Supplier \S+ is .+?: the order can be drafted/', $w) === 1 => Words::say('PO_WARN', 'supplier_not_approved', $supplier),
            preg_match('/^Supplier \S+ is overseas and its import route is not approved/', $w) === 1 => Words::say('PO_WARN', 'route', $supplier),
            preg_match('/^A change of \S+ import route or overseas status waits/', $w) === 1 => Words::say('PO_WARN', 'route_change', $supplier),
            preg_match('/^Due diligence of \S+ is overdue: the next review was due on (\d{4}-\d{2}-\d{2})\.$/', $w, $m) === 1
                => Words::say('PO_WARN', 'dd_due', $supplier, Html::day($m[1])),
            preg_match('/^The net total (£[\d,.]+) is below \S+ minimum order of (£[\d,.]+)\.$/', $w, $m) === 1
                => Words::say('PO_WARN', 'below_minimum', $m[1], $supplier, $m[2]),
            preg_match('/^Line (\d+) \((\S+)\) has a price of £0\.$/', $w, $m) === 1 => Words::say('PO_WARN', 'no_price', $m[1], $product($m[2])),
            preg_match('/^Line (\d+): item (\S+) was merged into (\S+): replace the line/', $w, $m) === 1
                => Words::say('PO_WARN', 'merged', $m[1], $product($m[2]), $m[3]),
            preg_match('/^Line (\d+): the supplier item of (\S+) was switched off/', $w, $m) === 1 => Words::say('PO_WARN', 'switched_off', $m[1], $product($m[2])),
            preg_match('/^Line (\d+): (\S+) is blocked by its item card \((.+)\): the order cannot be approved with it\.$/', $w, $m) === 1
                => Words::say('PO_WARN', 'blocked', $m[1], $product($m[2]), $m[3]),
            preg_match('/^Line (\d+): (\S+)\'s item card, not confirmed since it changed, says (.+): check it before ordering/', $w, $m) === 1
                => Words::say('PO_WARN', 'card_warning_changed', $m[1], $product($m[2]), $m[3]),
            preg_match('/^Line (\d+): (\S+)\'s item card, not confirmed yet, says (.+): check it before ordering/', $w, $m) === 1
                => Words::say('PO_WARN', 'card_warning', $m[1], $product($m[2]), $m[3]),
            preg_match('/^Line (\d+): (\S+) is marked discontinued on its item card\.$/', $w, $m) === 1 => Words::say('PO_WARN', 'discontinued', $m[1], $product($m[2])),
            default => $w,
        };
    }

    /**
     * What PurchaseOrders::sendWarnings() said, in words; `company` is true when one of them is about our company details (the
     * send box then links to them).
     *
     * @param list<string> $warnings
     * @return array{texts: list<string>, company: bool}
     */
    public static function send(array $warnings): array
    {
        $texts = [];
        $company = false;
        foreach ($warnings as $w) {
            if (str_starts_with($w, 'Its review was rejected')) {
                $texts[] = Words::PO_WARN['send_rejected'];
            } elseif (str_starts_with($w, 'The company details it was approved with include a change a reviewer rejected')) {
                $texts[] = Words::PO_WARN['send_company_rejected'];
                $company = true;
            } elseif (str_starts_with($w, 'The company details it was approved with are not confirmed')) {
                $texts[] = Words::PO_WARN['send_company'];
                $company = true;
            } else {
                $texts[] = $w;
            }
        }
        return ['texts' => $texts, 'company' => $company];
    }

    /**
     * A problem of a lines file (PoLinesFile: "row 3, packs: required: …", "header, packs: …") as a sentence: the row, the
     * column (its name stays: the file uses it) and what is wrong.
     */
    public static function fileRow(string $e): string
    {
        $m = [];
        if (preg_match('/^row (\d+), cw_code: give cw_code, supplier_code or barcode$/D', $e, $m) === 1) {
            return Words::say('PO_WARN', 'row_no_product', $m[1], 'cw_code', 'supplier_code', 'barcode');
        }
        if (preg_match('/^row (\d+), ([a-z_]+): (.+)$/sD', $e, $m) === 1) {
            return Words::say('PO_WARN', 'row', $m[1], $m[2], self::sentence($m[3]));
        }
        if (preg_match('/^row (\d+): (.+)$/sD', $e, $m) === 1) {
            return Words::say('PO_WARN', 'row_plain', $m[1], self::sentence($m[2]));
        }
        return self::sentence($e);
    }

    /** "a whole number" -> "A whole number." */
    private static function sentence(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        return preg_match('/[.!?]$/u', $s) === 1 ? $s : $s . '.';
    }
}
