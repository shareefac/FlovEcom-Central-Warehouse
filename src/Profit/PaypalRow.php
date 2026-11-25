<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\CwException;
use CW\Reports\ReportRefresh;

/**
 * One transaction_info object of PayPal's Transaction Search, read strictly (docs/decisions.md PF2, PF10): the fields CW keeps, in CW's
 * shapes, or a refusal that names the row and the field, never what it holds (checklist 6: a window with one row not understood is
 * refused whole). Nothing personal is read or kept: no payer, no item text, no note. The invoice and custom fields are kept as written only
 * when they are a reference (letters, digits and . _ : | = / + - only, at most 127: the shops write the order number there, PF21); one with
 * a space, an @ or anything else (it could be a name or an e-mail) is never kept, only that there was one ("dropped").
 */
final class PaypalRow
{
    /** What an invoice or custom field kept as written may hold. */
    public const REF_FORM = '/^[A-Za-z0-9._:|=\/+-]{1,127}$/D';
    /** PayPal's longest invoice_id / custom_field (127 characters); anything much longer is not PayPal's. */
    public const TEXT_MAX = 255;

    /**
     * @param mixed $ti one transaction_info object as decoded from PayPal's JSON
     * @return array{txn_id: string, event_code: string, ref_txn_id: ?string, ref_type: ?string, status: string, initiated_at: string,
     *   pp_updated_at: ?string, day: string, currency: string, amount_pence: int, fee_currency: ?string, fee_pence: int, invoice_state: string,
     *   invoice_ref: ?string, custom_state: string, custom_ref: ?string}
     */
    public static function parse(mixed $ti, int $index): array
    {
        $bad = static fn (string $field, string $why): CwException => new CwException('paypal_bad_row',
            "PayPal row {$index}: {$field} {$why} (nothing of this window was stored)", 422, ['row' => $index, 'field' => $field]);
        if (!is_array($ti) || array_is_list($ti) && $ti !== []) {
            throw $bad('transaction_info', 'is not an object');
        }
        $text = static function (string $field, string $pattern, bool $required) use ($ti, $bad): ?string {
            if (!array_key_exists($field, $ti) || $ti[$field] === null || $ti[$field] === '') {
                if ($required) {
                    throw $bad($field, 'is missing');
                }
                return null;
            }
            if (!is_string($ti[$field]) || preg_match($pattern, $ti[$field]) !== 1) {
                throw $bad($field, 'is not in the form PayPal uses');
            }
            return $ti[$field];
        };
        $txn = (string) $text('transaction_id', '/^[A-Za-z0-9]{1,32}$/D', true);
        $code = (string) $text('transaction_event_code', '/^T[0-9]{4}$/D', true);
        $status = (string) $text('transaction_status', '/^[A-Z]$/D', true);
        // a sale's id (17 letters and digits) or, for a pre-approved payment (type PAP), its agreement's id with a dash (B-...)
        $refId = $text('paypal_reference_id', '/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/D', false);
        $refType = $text('paypal_reference_id_type', '/^[A-Z]{1,8}$/D', false);
        $initiated = self::time($text('transaction_initiation_date', '/^.{1,40}$/D', true), 'transaction_initiation_date', $bad);
        $updated = ($u = $text('transaction_updated_date', '/^.{1,40}$/D', false)) === null ? null : self::time($u, 'transaction_updated_date', $bad);
        [$currency, $amount] = self::money($ti['transaction_amount'] ?? null, 'transaction_amount', true, $bad);
        [$feeCurrency, $fee] = self::money($ti['fee_amount'] ?? null, 'fee_amount', false, $bad);
        $invoice = self::ref($ti, 'invoice_id', $bad);
        $custom = self::ref($ti, 'custom_field', $bad);
        return ['txn_id' => $txn, 'event_code' => $code, 'ref_txn_id' => $refId, 'ref_type' => $refType, 'status' => $status,
            'initiated_at' => $initiated, 'pp_updated_at' => $updated, 'day' => ReportRefresh::ukDay($initiated), 'currency' => (string) $currency,
            'amount_pence' => (int) $amount, 'fee_currency' => $feeCurrency, 'fee_pence' => $feeCurrency === null ? 0 : (int) $fee,
            'invoice_state' => $invoice[0], 'invoice_ref' => $invoice[1], 'custom_state' => $custom[0], 'custom_ref' => $custom[1]];
    }

    /** A PayPal time (2026-11-22T10:00:00Z, +0000 or +00:00) as CW's UTC database time. */
    private static function time(?string $s, string $field, \Closure $bad): string
    {
        if ($s === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})$/D', $s, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59
            || (int) $m[1] < 2000 || (int) $m[1] > 2099) {
            throw $bad($field, 'is not a time in the form PayPal uses');
        }
        $t = new \DateTimeImmutable($s);
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.000000');
    }

    /**
     * A money object {currency_code, value} as [currency, pence]: the value a decimal string with at most 2 decimals (PayPal's form), at most
     * 12 digits of pounds. Absent and not required: [null, 0].
     *
     * @return array{0: ?string, 1: int}
     */
    private static function money(mixed $m, string $field, bool $required, \Closure $bad): array
    {
        if ($m === null) {
            if ($required) {
                throw $bad($field, 'is missing');
            }
            return [null, 0];
        }
        if (!is_array($m) || !isset($m['currency_code'], $m['value']) || !is_string($m['currency_code']) || !is_string($m['value'])
            || preg_match('/^[A-Z]{3}$/D', $m['currency_code']) !== 1 || preg_match('/^(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?$/D', $m['value'], $v) !== 1) {
            throw $bad($field, 'is not an amount in the form PayPal uses');
        }
        $pence = (int) $v[2] * 100 + (int) str_pad($v[3] ?? '0', 2, '0');
        return [$m['currency_code'], $v[1] === '-' ? -$pence : $pence];
    }

    /**
     * The invoice or custom field as kept: ['kept', as written] for a reference (REF_FORM), ['dropped', null] for anything else (it could be
     * personal: never kept), ['none', null] when absent or empty.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function ref(array $ti, string $field, \Closure $bad): array
    {
        $v = $ti[$field] ?? null;
        if ($v === null || $v === '') {
            return ['none', null];
        }
        if (!is_string($v) || strlen($v) > self::TEXT_MAX) {
            throw $bad($field, 'is not a text PayPal sends');
        }
        return preg_match(self::REF_FORM, $v) === 1 ? ['kept', $v] : ['dropped', null];
    }
}
