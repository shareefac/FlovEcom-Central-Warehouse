<?php

declare(strict_types=1);

namespace CW\Tests\Support;

/**
 * Made-up PayPal transaction_info objects in PayPal's shape (docs/decisions.md PPF2): never a real id, payer or amount.
 */
final class PaypalTx
{
    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function row(string $id, string $code, string $at, string $amount, ?string $fee, ?string $orderNo = null, string $status = 'S', array $over = []): array
    {
        $r = ['transaction_id' => $id, 'paypal_reference_id' => 'REF' . $id, 'paypal_reference_id_type' => 'TXN', 'transaction_event_code' => $code,
            'transaction_initiation_date' => $at, 'transaction_updated_date' => $at, 'transaction_amount' => ['currency_code' => 'GBP', 'value' => $amount],
            'transaction_status' => $status, 'transaction_subject' => 'made-up basket text', 'ending_balance' => ['currency_code' => 'GBP', 'value' => '100.00'],
            'available_balance' => ['currency_code' => 'GBP', 'value' => '100.00'], 'protection_eligibility' => '01', 'instrument_type' => 'PayPal'];
        if ($fee !== null) {
            $r['fee_amount'] = ['currency_code' => 'GBP', 'value' => $fee];
        }
        if ($orderNo !== null) {
            $r['invoice_id'] = $orderNo . '-1763700000';
            $r['custom_field'] = $orderNo . '-1763700000';
        }
        return $over + $r;
    }

    /** A sale's payment (T0006) with its fee taken. @return array<string, mixed> */
    public static function sale(string $id, string $at, string $amount, string $fee, ?string $orderNo, string $status = 'S'): array
    {
        return self::row($id, 'T0006', $at, $amount, $fee, $orderNo, $status);
    }

    /** A refund (T1107): money out, part of the fee back. @return array<string, mixed> */
    public static function refund(string $id, string $at, string $amount, ?string $feeBack, ?string $orderNo): array
    {
        return self::row($id, 'T1107', $at, $amount, $feeBack, $orderNo);
    }
}
