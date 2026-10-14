<?php

declare(strict_types=1);

namespace CW\Handovers;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Settings;
use CW\StockOps\OtherAccounts;
use CW\Staff\StaffRoles;

/**
 * What each customer owes us for the stock handed to it (pack H, IM11; docs/decisions.md PH10-PH12): the pattern of the VPG 2 release's
 * balance owed (SO8, CW\StockOps\OtherAccounts), the other way round. The balance is the sum of counterparty_entry (append-only): + a
 * hand-over made final (its gross: net + VAT), - its cancellation, - a payment received, + a payment's reversal. Pounds only (Q13).
 *
 * The hand-overs' entries are written by HandoverHandler; a payment and its reversal here. Who: anyone who reads records looks
 * (documents.view on the screens); handovers.pay (the purchasing desk and manager, never admin) records and reverses payments. A payment
 * more than the balance is refused (409 more_than_owed) unless handovers.allow_overpayment is on (then the balance goes into credit).
 * Receivables stay with the accountant (IM11): this is CW's record of the hand-overs and the payments people tell it about.
 */
final class CounterpartyAccounts
{
    public const REF_MAX = 100;
    public const NOTE_MAX = 500;
    public const REASON_MIN = 3;
    public const ENTRY_LIMIT = 100;
    public const PERMISSION = 'handovers.pay';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock ("this month", "not in the future") */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * Every customer with an entry, and every switched-on one: id, kind, name, active, the balance (what it owes us; negative: a credit),
     * what was handed over this month (UK month, gross, net of cancellations), the last payment, how many hand-overs are final.
     *
     * @return list<array<string, mixed>>
     */
    public function balances(): array
    {
        $monthStart = Clock::db(OtherAccounts::ukMonthStart(($this->clock)()));
        // Each customer's last payment not reversed, in one read (EXPLAIN, PH17: one read per customer was 300 reads for 300 customers).
        $lastPaid = [];
        foreach ($this->db->all('SELECT counterparty_id, amount, paid_on, reference FROM (SELECT e.counterparty_id, e.amount, e.paid_on, e.reference, '
            . 'ROW_NUMBER() OVER (PARTITION BY e.counterparty_id ORDER BY e.paid_on DESC, e.id DESC) AS rn FROM counterparty_entry e '
            . "WHERE e.kind = 'payment' AND NOT EXISTS (SELECT 1 FROM counterparty_entry r WHERE r.reverses_id = e.id)) x WHERE x.rn = 1") as $p) {
            $lastPaid[(int) $p['counterparty_id']] = $p;
        }
        $out = [];
        foreach ($this->db->all(
            'SELECT c.id, c.kind, c.name, c.is_active, (SELECT COALESCE(SUM(e.amount), 0) FROM counterparty_entry e WHERE e.counterparty_id = c.id) AS balance, '
            . "(SELECT COALESCE(SUM(e.amount), 0) FROM counterparty_entry e WHERE e.counterparty_id = c.id AND e.kind IN ('handover', 'handover_reversal') "
            . 'AND e.created_at >= ?) AS month_amount, '
            . "(SELECT COUNT(*) FROM counterparty_entry e WHERE e.counterparty_id = c.id AND e.kind = 'handover') AS handovers "
            . 'FROM counterparty c WHERE c.is_active = 1 OR EXISTS (SELECT 1 FROM counterparty_entry e WHERE e.counterparty_id = c.id) ORDER BY c.name',
            [$monthStart],
        ) as $c) {
            $id = (int) $c['id'];
            $last = $lastPaid[$id] ?? null;
            $out[] = ['id' => $id, 'kind' => (string) $c['kind'], 'name' => (string) $c['name'], 'active' => (int) $c['is_active'] === 1,
                'balance' => HandoverMath::money((string) $c['balance']), 'month_amount' => HandoverMath::money((string) $c['month_amount']),
                'handovers' => (int) $c['handovers'],
                'last_payment' => $last === null ? null : ['amount' => HandoverMath::negate((string) $last['amount']), 'paid_on' => (string) $last['paid_on'],
                    'reference' => (string) $last['reference']]];
        }
        return $out;
    }

    /** What the customer owes us (pounds, 2 decimals; 0.00 without entries; negative: a credit). */
    public function balance(int $counterpartyId): string
    {
        return HandoverMath::money((string) ($this->db->value('SELECT COALESCE(SUM(amount), 0) FROM counterparty_entry WHERE counterparty_id = ?', [$counterpartyId]) ?? '0'));
    }

    /**
     * The entries of one customer, newest first: id, kind, amount, the hand-over (id, number), paid_on, reference, note, the entry it
     * reverses, whether it was reversed (and by which entry), who and when.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(int $counterpartyId, int $limit = self::ENTRY_LIMIT): array
    {
        return array_map(static fn (array $e): array => [
            'id' => (int) $e['id'], 'kind' => (string) $e['kind'], 'amount' => HandoverMath::money((string) $e['amount']),
            'document_id' => $e['document_id'] === null ? null : (int) $e['document_id'], 'number' => $e['number'] === null ? null : (string) $e['number'],
            'paid_on' => $e['paid_on'] === null ? null : (string) $e['paid_on'], 'reference' => $e['reference'] === null ? null : (string) $e['reference'],
            'note' => $e['note'] === null ? null : (string) $e['note'], 'reverses_id' => $e['reverses_id'] === null ? null : (int) $e['reverses_id'],
            'reversed_by' => $e['reversed_by'] === null ? null : (int) $e['reversed_by'], 'who' => (string) ($e['who'] ?? ''), 'at' => (string) $e['created_at'],
        ], $this->db->all(
            'SELECT e.*, d.number, s.display_name AS who, (SELECT r.id FROM counterparty_entry r WHERE r.reverses_id = e.id) AS reversed_by '
            . 'FROM counterparty_entry e LEFT JOIN document d ON d.id = e.document_id LEFT JOIN staff_user s ON s.id = e.created_by '
            . 'WHERE e.counterparty_id = ? ORDER BY e.id DESC LIMIT ' . max(1, min(1000, $limit)),
            [$counterpartyId],
        ));
    }

    /**
     * Records a payment received from the customer: $amount pounds (above 0, at most 2 decimals: 400 bad_amount), received on $paidOn (a
     * date up to today in the UK: 400 bad_date), with the bank or remittance $reference (1-100 characters: 400 bad_reference) and an
     * optional note. 404 unknown_customer; 409 nothing_owed / more_than_owed unless handovers.allow_overpayment is on. Audit
     * counterparty.payment.
     *
     * @return array{entry_id: int, balance: string}
     */
    public function recordPayment(Caller $caller, int $counterpartyId, string $amount, string $paidOn, string $reference, ?string $note, Settings $settings): array
    {
        $amount = OtherAccounts::amount($amount);
        $paidOn = $this->date($paidOn);
        $reference = self::text($reference, self::REF_MAX, 'reference', 'bad_reference')
            ?? throw new CwException('bad_reference', 'say the payment\'s reference', 400, ['field' => 'reference']);
        $note = self::text($note, self::NOTE_MAX, 'note', 'bad_field');
        $over = $settings->has('handovers.allow_overpayment') && (bool) $settings->get('handovers.allow_overpayment');
        return $this->db->transaction(function (Db $db) use ($caller, $counterpartyId, $amount, $paidOn, $reference, $note, $over): array {
            $this->authorise($db, $caller);
            $c = $db->one('SELECT id, name FROM counterparty WHERE id = ? FOR UPDATE', [$counterpartyId])
                ?? throw new CwException('unknown_customer', 'there is no such customer', 404);
            $balance = $this->balance($counterpartyId);
            if (!$over && bccomp($balance, '0', 2) <= 0) {
                throw new CwException('nothing_owed', 'this customer owes nothing', 409, ['balance' => $balance]);
            }
            if (!$over && bccomp($amount, $balance, 2) > 0) {
                throw new CwException('more_than_owed', "the payment is more than the {$balance} owed", 409, ['balance' => $balance]);
            }
            $id = $db->insert('INSERT INTO counterparty_entry (counterparty_id, kind, amount, paid_on, reference, note, created_by, created_actor) '
                . "VALUES (?, 'payment', ?, ?, ?, ?, ?, ?)", [$counterpartyId, '-' . $amount, $paidOn, $reference, $note, $caller->staffUserId, $caller->actor]);
            $after = $this->balance($counterpartyId);
            Audit::write($db, $caller, 'counterparty.payment', 'counterparty_entry', (string) $id, null,
                ['counterparty' => $counterpartyId, 'name' => (string) $c['name'], 'amount' => $amount, 'paid_on' => $paidOn, 'reference' => $reference, 'balance' => $after]);
            return ['entry_id' => $id, 'balance' => $after];
        });
    }

    /**
     * Reverses a payment recorded by mistake (a new entry that puts the amount back; the payment stays, marked reversed). $reason 3-500
     * characters (400 bad_reason). 404 unknown_entry; 409 not_a_payment / already_reversed. Audit counterparty.payment_reverse.
     *
     * @return array{entry_id: int, balance: string, counterparty_id: int}
     */
    public function reversePayment(Caller $caller, int $entryId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::NOTE_MAX) {
            throw new CwException('bad_reason', 'say in ' . self::REASON_MIN . ' to ' . self::NOTE_MAX . ' characters why the payment is reversed', 400, ['field' => 'reason']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $entryId, $reason): array {
            $this->authorise($db, $caller);
            $e = $db->one('SELECT id, counterparty_id, kind, amount FROM counterparty_entry WHERE id = ?', [$entryId])
                ?? throw new CwException('unknown_entry', 'there is no such payment', 404);
            $db->one('SELECT id FROM counterparty WHERE id = ? FOR UPDATE', [(int) $e['counterparty_id']]);
            if ($e['kind'] !== 'payment') {
                throw new CwException('not_a_payment', 'only a payment is reversed here (a hand-over is cancelled on its own page)', 409);
            }
            if ($db->value('SELECT id FROM counterparty_entry WHERE reverses_id = ?', [$entryId]) !== null) {
                throw new CwException('already_reversed', 'this payment is reversed already', 409);
            }
            $back = HandoverMath::negate((string) $e['amount']);
            try {
                $id = $db->insert('INSERT INTO counterparty_entry (counterparty_id, kind, amount, note, reverses_id, created_by, created_actor) '
                    . "VALUES (?, 'payment_reversal', ?, ?, ?, ?, ?)", [(int) $e['counterparty_id'], $back, $reason, $entryId, $caller->staffUserId, $caller->actor]);
            } catch (\PDOException $x) {
                if (Db::driverCode($x) === 1062) {
                    throw new CwException('already_reversed', 'this payment is reversed already', 409);
                }
                throw $x;
            }
            $after = $this->balance((int) $e['counterparty_id']);
            Audit::write($db, $caller, 'counterparty.payment_reverse', 'counterparty_entry', (string) $id, null,
                ['counterparty' => (int) $e['counterparty_id'], 'payment' => $entryId, 'amount' => $back, 'reason' => $reason, 'balance' => $after]);
            return ['entry_id' => $id, 'balance' => $after, 'counterparty_id' => (int) $e['counterparty_id']];
        });
    }

    /** Whether these roles may record payments received (never admin). @param list<string> $roles */
    public static function mayPay(array $roles): bool
    {
        return !in_array('admin', $roles, true) && Permissions::can($roles, self::PERMISSION);
    }

    private function authorise(Db $db, Caller $caller): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'payments are recorded by staff', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (!self::mayPay($roles)) {
            throw new CwException(in_array('admin', $roles, true) ? 'admin_cannot_post' : 'role_not_allowed', 'you cannot record payments received', 403);
        }
    }

    private function date(string $v): string
    {
        $v = trim($v);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, Clock::utc());
        $today = ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        if ($d === false || $d->format('Y-m-d') !== $v || $v > $today || $v < '2000-01-01') {
            throw new CwException('bad_date', 'the payment\'s date is a day up to today', 400, ['field' => 'paid_on']);
        }
        return $v;
    }

    private static function text(?string $v, int $max, string $field, string $code): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));
        if ($v === '') {
            return null;
        }
        if (!mb_check_encoding($v, 'UTF-8') || mb_strlen($v) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1) {
            throw new CwException($code, "{$field} is at most {$max} characters", 400, ['field' => $field]);
        }
        return $v;
    }
}
