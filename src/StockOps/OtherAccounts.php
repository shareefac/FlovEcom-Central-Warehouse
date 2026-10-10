<?php

declare(strict_types=1);

namespace CW\StockOps;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Documents;
use CW\Staff\StaffRoles;
use CW\Auth\Permissions;

/**
 * The stock another account keeps in our building and what we owe that account (owner answers Q6/Q7, pack A1; docs/decisions.md
 * SO8): the owner's "VPG 2" room is a warehouse whose stock is another account's (warehouse.stock_owner `other`, its name in
 * owner_entity, set on the Warehouses page; never sellable). It is released to us rarely, by a sales invoice from that account: a
 * release record (REL, StockOpHandler) moves the stock into our warehouse and adds its amount to the running balance owed. A payment
 * to the account lowers it. Pounds only (Q13).
 *
 * The balance is the sum of other_account_entry (append-only): + a release, - its reversal, - a payment, + a payment's reversal. A
 * payment and its reversal are made here; the release's entries by its handler. Per warehouse: an account that keeps stock in two
 * rooms has two balances (the Warehouses page names the account of each).
 *
 * Who: anyone who reads stock records looks (documents.view on the screens); accounts.pay (the purchasing desk and manager, never
 * admin) records and reverses payments. A payment never makes the balance less than nothing (409 more_than_owed): a typo of a zero too
 * many is refused, and money paid in advance waits until the release it pays for.
 */
final class OtherAccounts
{
    public const REF_MAX = 100;
    public const NOTE_MAX = 500;
    public const REASON_MIN = 3;
    public const ENTRY_LIMIT = 100;
    public const PERMISSION = 'accounts.pay';

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock ("this month", "not in the future") */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * Every warehouse that holds another account's stock now, and any other that has a balance history: id, code, name, entity (the
     * account's name), active, own (whose stock it holds now), the stock it holds (units, products, the estimated value at the
     * suggested release price and how many products have no price), what was released this month (UK month: units and pounds, net of
     * cancellations), the balance owed, the last payment.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(): array
    {
        $rows = $this->db->all(
            'SELECT w.id, w.code, w.name, w.owner_entity, w.is_active, CAST(w.stock_owner AS CHAR) AS stock_owner, '
            . '(SELECT COALESCE(SUM(e.amount), 0) FROM other_account_entry e WHERE e.warehouse_id = w.id) AS balance, '
            . '(SELECT e.account_name FROM other_account_entry e WHERE e.warehouse_id = w.id ORDER BY e.id DESC LIMIT 1) AS last_account '
            . "FROM warehouse w WHERE w.stock_owner = 'other' OR EXISTS (SELECT 1 FROM other_account_entry e WHERE e.warehouse_id = w.id) ORDER BY w.sort_order, w.id",
        );
        $monthStart = Clock::db(self::ukMonthStart(($this->clock)()));
        $out = [];
        foreach ($rows as $w) {
            $id = (int) $w['id'];
            $held = $this->db->all('SELECT sku_id, on_hand FROM stock_balance WHERE warehouse_id = ? AND on_hand > 0', [$id]);
            $units = array_sum(array_map(static fn (array $b): int => (int) $b['on_hand'], $held));
            $prices = $held === [] ? [] : (new CostHints($this->db))->suggested(array_map(static fn (array $b): int => (int) $b['sku_id'], $held));
            $value = '0.00';
            $unpriced = 0;
            foreach ($held as $b) {
                $p = $prices[(int) $b['sku_id']]['price'] ?? null;
                if ($p === null) {
                    $unpriced++;
                    continue;
                }
                $value = bcadd($value, CostHints::amount((int) $b['on_hand'], $p), 2);
            }
            $month = $this->db->one(
                "SELECT COALESCE(SUM(e.amount), 0) AS amount FROM other_account_entry e WHERE e.warehouse_id = ? AND e.kind IN ('release', 'release_reversal') AND e.created_at >= ?",
                [$id, $monthStart],
            );
            $monthUnits = (int) $this->db->value(
                "SELECT COALESCE(SUM(l.qty), 0) FROM document d JOIN document_line l ON l.document_id = d.id WHERE d.doc_type = 'REL' AND d.warehouse_id = ? "
                . "AND d.status IN ('posted', 'reversed') AND d.posted_at >= ?",
                [$id, $monthStart],
            );
            $last = $this->db->one("SELECT amount, paid_on, reference FROM other_account_entry e WHERE e.warehouse_id = ? AND e.kind = 'payment' "
                . 'AND NOT EXISTS (SELECT 1 FROM other_account_entry r WHERE r.reverses_id = e.id) ORDER BY e.paid_on DESC, e.id DESC LIMIT 1', [$id]);
            $out[] = ['id' => $id, 'code' => (string) $w['code'], 'name' => (string) $w['name'],
                'entity' => (string) ($w['owner_entity'] ?? $w['last_account'] ?? ''), 'active' => (int) $w['is_active'] === 1, 'own' => $w['stock_owner'] === 'own',
                'holds_units' => $units, 'holds_products' => count($held), 'holds_value' => $value, 'unpriced' => $unpriced,
                'month_units' => $monthUnits, 'month_amount' => self::money((string) ($month['amount'] ?? '0')),
                'balance' => self::money((string) $w['balance']),
                'last_payment' => $last === null ? null : ['amount' => self::money(bcsub('0', (string) $last['amount'], 2)), 'paid_on' => (string) $last['paid_on'],
                    'reference' => (string) $last['reference']]];
        }
        return $out;
    }

    /** The balance owed to the account of $warehouseId (pounds, 2 decimals; 0.00 without entries). */
    public function balance(int $warehouseId): string
    {
        return self::money((string) ($this->db->value('SELECT COALESCE(SUM(amount), 0) FROM other_account_entry WHERE warehouse_id = ?', [$warehouseId]) ?? '0'));
    }

    /**
     * The entries of one account, newest first: id, kind, amount, the record (id, number), paid_on, reference, note, the entry it
     * reverses, whether it was reversed (and by which entry), who and when.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(int $warehouseId, int $limit = self::ENTRY_LIMIT): array
    {
        return array_map(static fn (array $e): array => [
            'id' => (int) $e['id'], 'kind' => (string) $e['kind'], 'amount' => self::money((string) $e['amount']), 'account' => (string) $e['account_name'],
            'document_id' => $e['document_id'] === null ? null : (int) $e['document_id'], 'number' => $e['number'] === null ? null : (string) $e['number'],
            'paid_on' => $e['paid_on'] === null ? null : (string) $e['paid_on'], 'reference' => $e['reference'] === null ? null : (string) $e['reference'],
            'note' => $e['note'] === null ? null : (string) $e['note'], 'reverses_id' => $e['reverses_id'] === null ? null : (int) $e['reverses_id'],
            'reversed_by' => $e['reversed_by'] === null ? null : (int) $e['reversed_by'], 'who' => (string) ($e['who'] ?? ''), 'at' => (string) $e['created_at'],
        ], $this->db->all(
            'SELECT e.*, d.number, s.display_name AS who, (SELECT r.id FROM other_account_entry r WHERE r.reverses_id = e.id) AS reversed_by '
            . 'FROM other_account_entry e LEFT JOIN document d ON d.id = e.document_id LEFT JOIN staff_user s ON s.id = e.created_by '
            . 'WHERE e.warehouse_id = ? ORDER BY e.id DESC LIMIT ' . max(1, min(1000, $limit)),
            [$warehouseId],
        ));
    }

    /**
     * Records a payment to the account of $warehouseId: $amount pounds (above 0, at most 2 decimals: 400 bad_amount), paid on $paidOn
     * (a date, not after today in the UK: 400 bad_date), with the bank or remittance $reference (1-100 characters: 400 bad_reference)
     * and an optional note. 404 unknown_account (a warehouse with no account); 409 nothing_owed / more_than_owed. Audit account.payment.
     *
     * @return array{entry_id: int, balance: string}
     */
    public function recordPayment(Caller $caller, int $warehouseId, string $amount, string $paidOn, string $reference, ?string $note): array
    {
        $amount = self::amount($amount);
        $paidOn = $this->date($paidOn);
        $reference = self::text($reference, self::REF_MAX, 'reference', 'bad_reference') ?? throw new CwException('bad_reference', 'say the payment\'s reference', 400,
            ['field' => 'reference']);
        $note = self::text($note, self::NOTE_MAX, 'note', 'bad_field');
        return $this->db->transaction(function (Db $db) use ($caller, $warehouseId, $amount, $paidOn, $reference, $note): array {
            $this->authorise($db, $caller);
            $w = $db->one('SELECT id, name, owner_entity, CAST(stock_owner AS CHAR) AS stock_owner FROM warehouse WHERE id = ? FOR UPDATE', [$warehouseId]);
            $last = $db->value('SELECT account_name FROM other_account_entry WHERE warehouse_id = ? ORDER BY id DESC LIMIT 1', [$warehouseId]);
            if ($w === null || ($w['stock_owner'] !== 'other' && $last === null)) {
                throw new CwException('unknown_account', 'there is no other account\'s stock in that warehouse', 404);
            }
            $balance = $this->balance($warehouseId);
            if (bccomp($balance, '0', 2) <= 0) {
                throw new CwException('nothing_owed', 'nothing is owed to this account', 409, ['balance' => $balance]);
            }
            if (bccomp($amount, $balance, 2) > 0) {
                throw new CwException('more_than_owed', "the payment is more than the {$balance} owed", 409, ['balance' => $balance]);
            }
            $account = (string) ($w['owner_entity'] ?? $last);
            $id = $db->insert('INSERT INTO other_account_entry (warehouse_id, account_name, kind, amount, paid_on, reference, note, created_by, created_actor) '
                . "VALUES (?, ?, 'payment', ?, ?, ?, ?, ?, ?)", [$warehouseId, $account, '-' . $amount, $paidOn, $reference, $note, $caller->staffUserId, $caller->actor]);
            $after = $this->balance($warehouseId);
            Audit::write($db, $caller, 'account.payment', 'other_account_entry', (string) $id, null,
                ['warehouse' => $warehouseId, 'account' => $account, 'amount' => $amount, 'paid_on' => $paidOn, 'reference' => $reference, 'balance' => $after]);
            return ['entry_id' => $id, 'balance' => $after];
        });
    }

    /**
     * Reverses a payment recorded by mistake (a new entry that puts the amount back on the balance; the payment stays, marked reversed).
     * $reason 3-500 characters (400 bad_reason). 404 unknown_entry; 409 not_a_payment, already_reversed. Audit account.payment_reverse.
     *
     * @return array{entry_id: int, balance: string, warehouse_id: int}
     */
    public function reversePayment(Caller $caller, int $entryId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::NOTE_MAX) {
            throw new CwException('bad_reason', 'say in ' . self::REASON_MIN . ' to ' . self::NOTE_MAX . ' characters why the payment is reversed', 400, ['field' => 'reason']);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $entryId, $reason): array {
            $this->authorise($db, $caller);
            $e = $db->one('SELECT id, warehouse_id, account_name, kind, amount FROM other_account_entry WHERE id = ?', [$entryId])
                ?? throw new CwException('unknown_entry', 'there is no such payment', 404);
            $db->one('SELECT id FROM warehouse WHERE id = ? FOR UPDATE', [(int) $e['warehouse_id']]);
            if ($e['kind'] !== 'payment') {
                throw new CwException('not_a_payment', 'only a payment is reversed here (a release is cancelled on its own page)', 409);
            }
            if ($db->value('SELECT id FROM other_account_entry WHERE reverses_id = ?', [$entryId]) !== null) {
                throw new CwException('already_reversed', 'this payment is reversed already', 409);
            }
            $back = bcsub('0', (string) $e['amount'], 2);
            try {
                $id = $db->insert('INSERT INTO other_account_entry (warehouse_id, account_name, kind, amount, note, reverses_id, created_by, created_actor) '
                    . "VALUES (?, ?, 'payment_reversal', ?, ?, ?, ?, ?)", [(int) $e['warehouse_id'], (string) $e['account_name'], $back, $reason, $entryId,
                        $caller->staffUserId, $caller->actor]);
            } catch (\PDOException $x) {
                if (Db::driverCode($x) === 1062) {
                    throw new CwException('already_reversed', 'this payment is reversed already', 409);
                }
                throw $x;
            }
            $after = $this->balance((int) $e['warehouse_id']);
            Audit::write($db, $caller, 'account.payment_reverse', 'other_account_entry', (string) $id, null,
                ['warehouse' => (int) $e['warehouse_id'], 'payment' => $entryId, 'amount' => $back, 'reason' => $reason, 'balance' => $after]);
            return ['entry_id' => $id, 'balance' => $after, 'warehouse_id' => (int) $e['warehouse_id']];
        });
    }

    /** Whether these roles may record payments (never admin). @param list<string> $roles */
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
            throw new CwException(in_array('admin', $roles, true) ? 'admin_cannot_post' : 'role_not_allowed', 'you cannot record payments to another account', 403);
        }
    }

    /** "12.5" -> "12.50": pounds above 0 with at most 2 decimals and 10 whole digits (400 bad_amount). */
    public static function amount(string $v): string
    {
        $v = trim(str_replace([',', '£', ' '], '', $v));
        if (preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $v, $m) !== 1) {
            throw new CwException('bad_amount', 'the amount is in pounds, like 1250.00', 400, ['field' => 'amount']);
        }
        $s = $m[1] . '.' . str_pad($m[2] ?? '', 2, '0');
        if (bccomp($s, '0', 2) <= 0) {
            throw new CwException('bad_amount', 'the amount is more than nothing', 400, ['field' => 'amount']);
        }
        return $s;
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

    /** A decimal as pounds with 2 decimals ("-0.00" as "0.00"). */
    public static function money(string $v): string
    {
        $s = bcadd($v, '0', 2);
        return $s === '-0.00' ? '0.00' : $s;
    }

    /** The first moment of the UK month $t is in, in UTC. */
    public static function ukMonthStart(\DateTimeImmutable $t): \DateTimeImmutable
    {
        $uk = $t->setTimezone(new \DateTimeZone('Europe/London'));
        return $uk->setDate((int) $uk->format('Y'), (int) $uk->format('n'), 1)->setTime(0, 0)->setTimezone(Clock::utc());
    }

    /** Whether these roles may make a release (the account page's "Release stock" button; the document base checks it again). @param list<string> $roles */
    public static function mayRelease(array $roles): bool
    {
        return Documents::mayPost($roles, 'REL');
    }
}
