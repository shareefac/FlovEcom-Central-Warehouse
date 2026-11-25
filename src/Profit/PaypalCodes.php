<?php

declare(strict_types=1);

namespace CW\Profit;

/**
 * What a PayPal transaction is, by its event code (docs/decisions.md PF3), and what it costs the shop (PF4). The owner's rules (24 Nov 2026
 * 23:58): a sale's PayPal fee is a cost on its order; a refund's fee given back goes back on the same order; a chargeback's money taken back
 * PLUS PayPal's chargeback fee and any dispute fee are a cost on the order; holds and their releases, money moved to the bank and payments sent
 * or received are NOT costs.
 *
 * Kinds:
 *   sale        a payment the shop received (T0005 Direct payment API, T0006 PayPal Checkout): its FEE counts (the money is the sale CW counts
 *               already). Its amount must be above 0, or it is not understood (look).
 *   refund      a refund the shop made (T1107): its FEE counts (above 0 when PayPal gives part of it back: a cost below 0); the money is the
 *               refund CW counts already. Its amount must be below 0.
 *   chargeback  money PayPal or the card company took back, or gave back after a dispute was won (T1106 payment reversal, T1201 chargeback,
 *               T1202 chargeback reversal): the money AND its fee count.
 *   fee         a fee PayPal charged on its own (T0106 chargeback fee, T0114 dispute fee): the money AND its fee count.
 *   ignore      not a cost (holds and releases, money to and from the bank, currency conversion, payments sent with the PayPal card or the
 *               account, authorisations, the card's cash-back bonus): read and kept, never counted. One with a fee is not understood (look).
 *   unknown     every other code, and a payment code (T00nn) of money received other than T0005 / T0006: kept, shown (health alert, "needs a
 *               look"), never guessed into money.
 *
 * What it costs (cost(): pence, a cost above 0, money back below 0): sale and refund: -fee; chargeback and fee: -(amount + fee) (PayPal gives
 * money taken from the balance below 0, so its opposite is the cost).
 */
final class PaypalCodes
{
    public const KINDS = ['sale', 'refund', 'chargeback', 'fee', 'ignore', 'unknown'];
    /** The kinds whose money counts somewhere. */
    public const COSTED = ['sale', 'refund', 'chargeback', 'fee'];

    /**
     * Every code CW knows, with its kind and PayPal's own name (PayPal's "Transaction event codes" list). `sent` = a payment code (T00nn):
     * money the shop SENT (amount below 0) is ignored (owner rule 5); money received with it is not understood (unknown: never guessed).
     * Every code not listed here is unknown.
     */
    public const TABLE = [
        // T00nn PayPal account-to-PayPal account payment
        'T0000' => ['sent', 'General: received payment of a type not belonging to the other T00nn categories'],
        'T0001' => ['sent', 'MassPay payment'],
        'T0002' => ['sent', 'Subscription payment'],
        'T0003' => ['sent', 'Pre-approved payment (BillUser API)'],
        'T0004' => ['sent', 'eBay auction payment'],
        'T0005' => ['sale', 'Direct payment API'],
        'T0006' => ['sale', 'PayPal Checkout APIs'],
        'T0007' => ['sent', 'Website payments standard payment'],
        'T0008' => ['sent', 'Postage payment to carrier'],
        'T0009' => ['sent', 'Gift certificate payment'],
        'T0010' => ['sent', 'Third-party auction payment'],
        'T0011' => ['sent', 'Mobile payment, made through a mobile phone'],
        'T0012' => ['sent', 'Virtual terminal payment'],
        'T0013' => ['sent', 'Donation payment'],
        'T0014' => ['sent', 'Rebate payments'],
        'T0015' => ['sent', 'Third-party payout'],
        'T0016' => ['sent', 'Third-party recoupment'],
        'T0017' => ['sent', 'Store-to-store transfers'],
        'T0018' => ['sent', 'PayPal Here payment'],
        'T0019' => ['sent', 'Generic instrument-funded payment'],
        // T01nn non-payment-related fees
        'T0106' => ['fee', 'Chargeback processing fee'],
        'T0114' => ['fee', 'Dispute fee'],
        // T02nn currency conversion
        'T0200' => ['ignore', 'General currency conversion'],
        'T0201' => ['ignore', 'User-initiated currency conversion'],
        'T0202' => ['ignore', 'Currency conversion required to cover negative balance'],
        // T03nn bank deposit into the PayPal account
        'T0300' => ['ignore', 'General funding of PayPal account'],
        'T0301' => ['ignore', 'PayPal Balance Manager funding of PayPal account'],
        'T0302' => ['ignore', 'ACH funding for funds recovery from account balance'],
        'T0303' => ['ignore', 'Electronic funds transfer (EFT)'],
        // T04nn bank withdrawal from the PayPal account
        'T0400' => ['ignore', 'General withdrawal from PayPal account'],
        'T0401' => ['ignore', 'AutoSweep'],
        'T0403' => ['ignore', 'Withdrawal to the bank'],
        // T05nn PayPal debit card (payments the shop made with its card)
        'T0500' => ['ignore', 'General PayPal debit card transaction'],
        'T0501' => ['ignore', 'Virtual PayPal debit card transaction'],
        'T0502' => ['ignore', 'PayPal debit card withdrawal to ATM'],
        'T0503' => ['ignore', 'Hidden virtual PayPal debit card transaction'],
        'T0504' => ['ignore', 'PayPal debit card cash advance'],
        'T0505' => ['ignore', 'PayPal debit authorization'],
        // T08nn bonus: the card's cash back (an owner question: shown as ignored, PF3)
        'T0801' => ['ignore', 'Debit card cash back bonus'],
        // T11nn reversals
        'T1105' => ['ignore', 'Reversal of general account hold'],
        'T1106' => ['chargeback', 'Payment reversal, initiated by PayPal'],
        'T1107' => ['refund', 'Payment refund, initiated by merchant'],
        'T1110' => ['ignore', 'Hold for dispute investigation'],
        'T1111' => ['ignore', 'Cancellation of hold for dispute resolution'],
        // T12nn adjustments
        'T1201' => ['chargeback', 'Chargeback'],
        'T1202' => ['chargeback', 'Chargeback reversal'],
        // T13nn authorisations (no money moves)
        'T1300' => ['ignore', 'General authorization'],
        'T1301' => ['ignore', 'Reauthorization'],
        'T1302' => ['ignore', 'Void of authorization'],
        // T15nn holds
        'T1500' => ['ignore', 'General temporary hold'],
        'T1501' => ['ignore', 'Account hold for open authorization'],
        'T1502' => ['ignore', 'Account hold for ACH deposit'],
        'T1503' => ['ignore', 'Temporary hold on available balance'],
        // T21nn reserves and releases
        'T2101' => ['ignore', 'General hold'],
        'T2102' => ['ignore', 'General hold release'],
        'T2103' => ['ignore', 'Reserve hold'],
        'T2104' => ['ignore', 'Reserve release'],
        'T2105' => ['ignore', 'Payment review hold'],
        'T2106' => ['ignore', 'Payment review release'],
        'T2107' => ['ignore', 'Payment hold'],
        'T2108' => ['ignore', 'Payment hold release'],
        'T2111' => ['ignore', 'Funds not yet available'],
        'T2112' => ['ignore', 'Funds available'],
    ];

    /** The kind of a code with this amount (pence, as PayPal signs it). */
    public static function kind(string $code, int $amount): string
    {
        $k = self::TABLE[$code][0] ?? 'unknown';
        return match ($k) {
            // a payment code: money sent is a payment sent (owner rule 5); money received with any code but T0005 / T0006 is not guessed
            'sent' => $amount < 0 ? 'ignore' : 'unknown',
            default => $k,
        };
    }

    /** PayPal's own name of a code, or null when CW does not know it. */
    public static function name(string $code): ?string
    {
        return self::TABLE[$code][1] ?? null;
    }

    /**
     * What a row costs the shop, in pence (a cost above 0, money back below 0). 0 for a kind that is not costed.
     */
    public static function cost(string $kind, int $amount, int $fee): int
    {
        return match ($kind) {
            'sale', 'refund' => -$fee,
            'chargeback', 'fee' => -($amount + $fee),
            default => 0,
        };
    }

    /**
     * Whether a costed kind's amount has the sign it must have (PF3): a sale received money (above 0), a refund sent it (below 0). A
     * chargeback or a fee may go either way (a chargeback reversal gives money back). An amount of the wrong sign is not understood (look).
     */
    public static function signOk(string $kind, int $amount): bool
    {
        return match ($kind) {
            'sale' => $amount > 0,
            'refund' => $amount < 0,
            default => true,
        };
    }
}
