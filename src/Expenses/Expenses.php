<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Files\FileStore;

/**
 * The expenses (CW-1150, the brief's point 1; docs/decisions.md EX1-EX12): the invoices the owner adds, each for ONE shop he picks, with
 * its supplier (their own list), type (Settings), invoice date and number, the amount without VAT and the VAT as printed (CW never works VAT
 * out; total = net + VAT), a note and its PDF or photo. Money in whole pence. A credit note (the owner, 24 Nov 2026: EX37) is ticked as one:
 * its amounts are typed as printed, without a minus, and kept and counted as minus (net and VAT), so it takes that much off its shop's
 * expenses on its date. The same supplier + invoice number + shop as an expense not deleted of the same kind (invoice or credit note) is
 * refused unless the person confirms it; with no number on either of the two, the same supplier, shop, day and total (signed) is the same bill
 * (the final review of 70ab3d2, astra HIGH 4; EX42). A confirmation names the expenses it was asked about and holds for them only (astra
 * MEDIUM; EX43). Only the three shops take an expense (ExpenseViews::SHOP_CODES; EX44). Every file an expense names is kept in expense_file
 * by the version that named it, 7 years from then (EX40). A change rewrites the row
 * (version + 1) and keeps before and after; a delete is a mark with a reason; nothing is ever removed (ExpenseHistory, audit_log).
 * Only the Expenses job writes (ExpenseAccess), checked again inside each write.
 */
final class Expenses
{
    /** The earliest invoice date taken, and how far ahead (a typo guard). */
    public const FIRST_DAY = '2020-01-01';
    public const DAYS_AHEAD = 366;
    public const NAME_MAX = 100;
    public const NO_MAX = 64;
    public const NOTE_MAX = 500;

    /** @param (\Closure(): FileStore)|null $files the document store (built only when a file comes) */
    public function __construct(private readonly Db $db, private readonly ?\Closure $files = null)
    {
    }

    /**
     * Adds an expense. $in: shop (channel id), supplier (its name), new_supplier (add it when it is not on the list), type (expense_type id),
     * invoice_date (YYYY-MM-DD), invoice_no, net and vat (pounds as typed, never minus), credit (true, '1' or 'on': a credit note, kept as
     * minus), note. $file: ['path', 'name'] or null. $same: the confirmation the form carries ("it is another invoice"): the ids of the
     * expenses it was asked about (the 409's detail.confirm); it holds only while exactly those are the same as this one.
     *
     * @param array<string, mixed> $in
     * @param array{path: string, name: string}|null $file
     * @return array{id: int, supplier_id: int, file_id: ?int}
     */
    public function add(Caller $caller, array $in, ?array $file = null, string $same = ''): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $in, $file, $same): array {
            ExpenseAccess::mayEdit($db, $caller);
            $v = $this->fields($db, $in, null);
            $supplier = $this->supplierFor($db, $caller, $v['supplier'], (bool) ($in['new_supplier'] ?? false), null);
            // CW-1174 (0084, docs/decisions.md GPF9): a Global Payments invoice entered on Profit > Card fees is never an expense too
            \CW\Profit\GpInvoices::guardExpense($db, $v['supplier'], $v['shop'], $v['invoice_date'], $v['net'], $v['net'] + $v['vat']);
            $this->checkSame($db, $supplier, $v, null, $same);
            $fileId = $file === null ? null : ExpenseFiles::store($this->files(), $caller, $file['path'], $file['name']);
            $now = Clock::db(Clock::now());
            $id = $db->insert('INSERT INTO expense (channel_id, supplier_id, type_id, invoice_date, invoice_no, net_pence, vat_pence, total_pence, note, file_id, version, '
                . 'created_by, created_actor, created_at, updated_by, updated_actor, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
                [$v['shop'], $supplier, $v['type'], $v['invoice_date'], $v['invoice_no'], $v['net'], $v['vat'], $v['net'] + $v['vat'], $v['note'], $fileId,
                    $caller->staffUserId, $caller->actor, $now, $caller->staffUserId, $caller->actor, $now]);
            if ($fileId !== null) {
                $this->attach($db, $caller, $id, $fileId, 1);
            }
            ExpenseHistory::record($db, $caller, 'expense', $id, 1, 'add', null, self::state($db, $id), null);
            return ['id' => $id, 'supplier_id' => $supplier, 'file_id' => $fileId];
        });
    }

    /**
     * Changes an expense (every field the form has; a new file replaces the one it names, which stays in the store). $seen: the version the
     * page showed (409 changed_meanwhile otherwise). Nothing different: no new version. The form names its supplier by its id too
     * (supplier_id, and supplier_was: the name it showed): a name left as it was keeps that supplier, whatever another supplier is called now
     * (the final review of 70ab3d2, astra MEDIUM; EX45); a name typed over it is looked up.
     *
     * @param array<string, mixed> $in
     * @param array{path: string, name: string}|null $file
     * @return array{changed: bool, version: int}
     */
    public function change(Caller $caller, int $id, int $seen, array $in, ?array $file = null, string $same = ''): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $seen, $in, $file, $same): array {
            ExpenseAccess::mayEdit($db, $caller);
            $row = $this->locked($db, $id, $seen);
            $v = $this->fields($db, $in, $row);
            $kept = is_string($in['supplier_id'] ?? null) || is_int($in['supplier_id'] ?? null) ? (string) $in['supplier_id'] : '';
            if ($kept === (string) $row['supplier_id'] && is_string($in['supplier_was'] ?? null) && self::plain($in['supplier_was']) === $v['supplier']) {
                // The supplier as the form showed it: the same one, locked as a looked-up one is (two changes of its invoices one after the other).
                $supplier = (int) $db->value('SELECT id FROM expense_supplier WHERE id = ? FOR UPDATE', [$row['supplier_id']]);
            } else {
                $supplier = $this->supplierFor($db, $caller, $v['supplier'], (bool) ($in['new_supplier'] ?? false), (int) $row['supplier_id']);
            }
            $fileId = $row['file_id'] === null ? null : (int) $row['file_id'];
            $unchanged = $supplier === (int) $row['supplier_id'] && $v['shop'] === (int) $row['channel_id'] && $v['type'] === (int) $row['type_id']
                && $v['invoice_date'] === (string) $row['invoice_date'] && $v['invoice_no'] === (string) $row['invoice_no'] && $v['net'] === (int) $row['net_pence']
                && $v['vat'] === (int) $row['vat_pence'] && $v['note'] === ($row['note'] === null ? null : (string) $row['note']);
            if ($unchanged && $file === null) {
                return ['changed' => false, 'version' => (int) $row['version']];
            }
            // CW-1174 (0084, docs/decisions.md GPF9): as add() (the supplier as it is now; its name read under the lock the form's supplier took)
            \CW\Profit\GpInvoices::guardExpense($db, (string) $db->value('SELECT name FROM expense_supplier WHERE id = ?', [$supplier]), $v['shop'], $v['invoice_date'],
                $v['net'], $v['net'] + $v['vat']);
            // Checked again when it could meet another: a new supplier, shop, number, day or total, an invoice made a credit note or back (EX37;
            // a day or total too, since a bill of no number meets one of the same day and total: EX42).
            if ($supplier !== (int) $row['supplier_id'] || $v['shop'] !== (int) $row['channel_id'] || $v['invoice_no'] !== (string) $row['invoice_no']
                || ($v['net'] < 0) !== ((int) $row['net_pence'] < 0) || $v['invoice_date'] !== (string) $row['invoice_date']
                || $v['net'] + $v['vat'] !== (int) $row['total_pence']) {
                $this->checkSame($db, $supplier, $v, $id, $same);
            }
            if ($file !== null) {
                $fileId = ExpenseFiles::store($this->files(), $caller, $file['path'], $file['name']);
            }
            $before = self::state($db, $id);
            $version = (int) $row['version'] + 1;
            $db->exec('UPDATE expense SET channel_id = ?, supplier_id = ?, type_id = ?, invoice_date = ?, invoice_no = ?, net_pence = ?, vat_pence = ?, total_pence = ?, '
                . 'note = ?, file_id = ?, version = ?, updated_by = ?, updated_actor = ?, updated_at = ? WHERE id = ?',
                [$v['shop'], $supplier, $v['type'], $v['invoice_date'], $v['invoice_no'], $v['net'], $v['vat'], $v['net'] + $v['vat'], $v['note'], $fileId, $version,
                    $caller->staffUserId, $caller->actor, Clock::db(Clock::now()), $id]);
            if ($file !== null && $fileId !== null) {
                $this->attach($db, $caller, $id, $fileId, $version);
            }
            ExpenseHistory::record($db, $caller, 'expense', $id, $version, 'change', $before, self::state($db, $id), null);
            return ['changed' => true, 'version' => $version];
        });
    }

    /** Deletes an expense by a mark with a reason (3-500 characters): out of every list and of the profit; kept with its history and file. */
    public function delete(Caller $caller, int $id, int $seen, string $reason): void
    {
        $this->db->transaction(function (Db $db) use ($caller, $id, $seen, $reason): void {
            ExpenseAccess::mayEdit($db, $caller);
            $row = $this->locked($db, $id, $seen);
            $why = ExpenseHistory::reason($reason);
            $before = self::state($db, $id);
            $version = (int) $row['version'] + 1;
            $now = Clock::db(Clock::now());
            $db->exec('UPDATE expense SET deleted_at = ?, deleted_by = ?, deleted_actor = ?, deleted_reason = ?, version = ?, updated_by = ?, updated_actor = ?, '
                . 'updated_at = ? WHERE id = ?', [$now, $caller->staffUserId, $caller->actor, $why, $version, $caller->staffUserId, $caller->actor, $now, $id]);
            ExpenseHistory::record($db, $caller, 'expense', $id, $version, 'delete', $before, self::state($db, $id), $why);
        });
    }

    /** Adds a supplier to the expenses' own list (409 supplier_exists when the name is there, whatever its case). */
    public function addSupplier(Caller $caller, string $name): int
    {
        return $this->db->transaction(function (Db $db) use ($caller, $name): int {
            ExpenseAccess::mayEdit($db, $caller);
            $name = self::name($name);
            if ($db->value('SELECT id FROM expense_supplier WHERE name = ?', [$name]) !== null) {
                throw new CwException('supplier_exists', 'that supplier is on the list already', 409, ['field' => 'name']);
            }
            return $this->insertSupplier($db, $caller, $name);
        });
    }

    /**
     * Renames a supplier ($name) or switches it off or on ($active); its expenses keep it. $seen: the version the page showed.
     *
     * @return array{changed: bool, version: int}
     */
    public function changeSupplier(Caller $caller, int $id, int $seen, ?string $name, ?bool $active): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $seen, $name, $active): array {
            ExpenseAccess::mayEdit($db, $caller);
            $row = $db->one('SELECT id, name, is_active, version FROM expense_supplier WHERE id = ? FOR UPDATE', [$id])
                ?? throw new CwException('not_found', 'there is no such supplier', 404);
            if ((int) $row['version'] !== $seen) {
                throw self::meanwhile($db, 'supplier', $id, (int) $row['version'], $seen);
            }
            $newName = $name === null ? (string) $row['name'] : self::name($name);
            $newActive = $active === null ? (int) $row['is_active'] === 1 : $active;
            if ($newName === (string) $row['name'] && $newActive === ((int) $row['is_active'] === 1)) {
                return ['changed' => false, 'version' => (int) $row['version']];
            }
            if ($newName !== (string) $row['name'] && $db->value('SELECT id FROM expense_supplier WHERE name = ? AND id <> ?', [$newName, $id]) !== null) {
                throw new CwException('supplier_exists', 'that supplier is on the list already', 409, ['field' => 'name']);
            }
            $before = ['name' => (string) $row['name'], 'is_active' => (int) $row['is_active']];
            $after = ['name' => $newName, 'is_active' => $newActive ? 1 : 0];
            $version = (int) $row['version'] + 1;
            $db->exec('UPDATE expense_supplier SET name = ?, is_active = ?, version = ? WHERE id = ?', [$newName, $newActive ? 1 : 0, $version, $id]);
            $action = $newName !== (string) $row['name'] ? 'change' : ($newActive ? 'switch_on' : 'switch_off');
            ExpenseHistory::record($db, $caller, 'supplier', $id, $version, $action, $before, $after, null);
            return ['changed' => true, 'version' => $version];
        });
    }

    /**
     * The tracked state of an expense (what its history keeps): its fields, with the shop's, supplier's and type's names beside their ids so
     * the trail reads in words.
     *
     * @return array<string, int|string|null>
     */
    public static function state(Db $db, int $id): array
    {
        $r = $db->one('SELECT e.channel_id, c.name AS shop, e.supplier_id, s.name AS supplier, e.type_id, t.name AS type, e.invoice_date, e.invoice_no, e.net_pence, '
            . 'e.vat_pence, e.total_pence, e.note, e.file_id, e.deleted_reason, e.deleted_at, e.deleted_actor, e.deleted_by FROM expense e JOIN channel c ON c.id = e.channel_id '
            . 'JOIN expense_supplier s ON s.id = e.supplier_id JOIN expense_type t ON t.id = e.type_id WHERE e.id = ?', [$id])
            ?? throw new \LogicException("expense {$id} vanished");
        return ['channel_id' => (int) $r['channel_id'], 'shop' => (string) $r['shop'], 'supplier_id' => (int) $r['supplier_id'], 'supplier' => (string) $r['supplier'],
            'type_id' => (int) $r['type_id'], 'type' => (string) $r['type'], 'invoice_date' => (string) $r['invoice_date'], 'invoice_no' => (string) $r['invoice_no'],
            'net_pence' => (int) $r['net_pence'], 'vat_pence' => (int) $r['vat_pence'], 'total_pence' => (int) $r['total_pence'],
            'note' => $r['note'] === null ? null : (string) $r['note'], 'file_id' => $r['file_id'] === null ? null : (int) $r['file_id'],
            'deleted_reason' => $r['deleted_reason'] === null ? null : (string) $r['deleted_reason'],
            // Who deleted it and when, compared every night (the Codex check r2, M11).
            'deleted_at' => $r['deleted_at'] === null ? null : (string) $r['deleted_at'], 'deleted_actor' => $r['deleted_actor'] === null ? null : (string) $r['deleted_actor'],
            'deleted_by' => $r['deleted_by'] === null ? null : (int) $r['deleted_by']];
    }

    /**
     * The form's fields checked and normalised (422 with the field's code). $row: the expense being changed (its type may stay one switched off
     * since). A credit note's net and VAT come back as minus (typed without one: EX37).
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $row
     * @return array{shop: int, supplier: string, type: int, invoice_date: string, invoice_no: string, net: int, vat: int, note: ?string}
     */
    private function fields(Db $db, array $in, ?array $row): array
    {
        $shop = is_int($in['shop'] ?? null) ? $in['shop'] : (is_string($in['shop'] ?? null) && preg_match('/^[1-9]\d{0,5}$/D', $in['shop']) === 1 ? (int) $in['shop'] : null);
        if ($shop === null || !ExpenseViews::isBusinessShop($db, $shop)) {
            throw new CwException('choose_shop', 'choose the shop this expense is for', 422, ['field' => 'shop']);
        }
        $supplier = self::name((string) ($in['supplier'] ?? ''), 'bad_supplier', 'supplier');
        $type = is_int($in['type'] ?? null) ? $in['type'] : (is_string($in['type'] ?? null) && preg_match('/^[1-9]\d{0,5}$/D', $in['type']) === 1 ? (int) $in['type'] : null);
        $t = $type === null ? null : $db->one("SELECT id, is_active FROM expense_type WHERE id = ? AND kind = 'expense'", [$type]);
        if ($t === null) {
            throw new CwException('choose_type', 'choose the type of the expense', 422, ['field' => 'type']);
        }
        if ((int) $t['is_active'] !== 1 && ($row === null || (int) $row['type_id'] !== $type)) {
            throw new CwException('type_off', 'that type is switched off: choose another', 422, ['field' => 'type']);
        }
        $date = trim((string) ($in['invoice_date'] ?? ''));
        $latest = RegularCostMath::addDays(\CW\Profit\ProfitViews::today(), self::DAYS_AHEAD);
        if (!RegularCostMath::isDay($date) || $date < self::FIRST_DAY || $date > $latest) {
            throw new CwException('bad_date', 'the invoice date is not a day', 422, ['field' => 'invoice_date']);
        }
        $no = self::plain((string) ($in['invoice_no'] ?? ''));
        if (!mb_check_encoding($no, 'UTF-8') || mb_strlen($no) > self::NO_MAX || preg_match('/[\x00-\x1F\x7F]/', $no) === 1) {
            throw new CwException('bad_invoice_no', 'the invoice number is at most ' . self::NO_MAX . ' characters', 422, ['field' => 'invoice_no']);
        }
        $net = ExpenseMoney::pence((string) ($in['net'] ?? ''));
        if ($net === null || $net < 1) {
            throw new CwException('bad_net', 'the amount without VAT is not an amount in pounds and pence above 0', 422, ['field' => 'net']);
        }
        $vat = ExpenseMoney::pence((string) ($in['vat'] ?? ''));
        if ($vat === null) {
            throw new CwException('bad_vat', 'the VAT is not an amount in pounds and pence (0 when the invoice has none)', 422, ['field' => 'vat']);
        }
        // The total with VAT, when the form sends one (EX65): typed as printed (a credit note's without a minus, as its amounts), it must be the
        // amount without VAT + the VAT as typed; CW saves those two boxes and works nothing out (EX3). Empty: not asked (the old way, no script).
        $total = (string) ($in['total'] ?? '');
        if (!ExpenseMoney::blank($total)) {
            // The same money rule as the boxes (nothing trimmed that they are refused for), up to two boxes' worth (Codex's final review of
            // 1f18763, L1 and M2).
            $t = ExpenseMoney::pence($total, ExpenseMoney::MAX_TOTAL_PENCE);
            if ($t === null) {
                throw new CwException('bad_total', 'the total with VAT is not an amount in pounds and pence', 422, ['field' => 'total']);
            }
            if ($t !== $net + $vat) {
                throw new CwException('total_mismatch', 'the amount without VAT and the VAT do not add up to the total with VAT typed', 422,
                    ['field' => 'total', 'sum' => $net + $vat, 'total' => $t]);
            }
        }
        $note = trim((string) ($in['note'] ?? ''));
        if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > self::NOTE_MAX || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note) === 1) {
            throw new CwException('bad_note', 'the note is at most ' . self::NOTE_MAX . ' characters', 422, ['field' => 'note']);
        }
        $sign = self::isCredit($in['credit'] ?? null) ? -1 : 1;
        return ['shop' => $shop, 'supplier' => $supplier, 'type' => $type, 'invoice_date' => $date, 'invoice_no' => $no, 'net' => $sign * $net, 'vat' => $sign * $vat,
            'note' => $note === '' ? null : $note];
    }

    /** Whether the form says "This is a credit note" (true, 1, '1' or 'on'). */
    public static function isCredit(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 'on'], true);
    }

    /**
     * The supplier of this name (whatever its case), locked so two adds of one invoice are checked one after the other; a name not on the list
     * is added when the person said so ($new), else 422 unknown_supplier. A supplier switched off is refused unless the expense names it already.
     */
    private function supplierFor(Db $db, Caller $caller, string $name, bool $new, ?int $current): int
    {
        $row = $db->one('SELECT id, is_active FROM expense_supplier WHERE name = ? FOR UPDATE', [$name]);
        if ($row === null) {
            if (!$new) {
                throw new CwException('unknown_supplier', 'that supplier is not on the list: tick "Add it as a new supplier", or choose one from the list', 422,
                    ['field' => 'supplier', 'name' => $name]);
            }
            return $this->insertSupplier($db, $caller, $name);
        }
        if ((int) $row['is_active'] !== 1 && (int) $row['id'] !== $current) {
            throw new CwException('supplier_off', 'that supplier is switched off: switch it on under Suppliers, or choose another', 422, ['field' => 'supplier']);
        }
        return (int) $row['id'];
    }

    private function insertSupplier(Db $db, Caller $caller, string $name): int
    {
        try {
            $id = $db->insert('INSERT INTO expense_supplier (name, created_by, created_actor, created_at) VALUES (?, ?, ?, ?)',
                [$name, $caller->staffUserId, $caller->actor, Clock::db(Clock::now())]);
        } catch (\PDOException $e) {
            if (Db::driverCode($e) === 1062) {
                throw new CwException('supplier_exists', 'that supplier was added a moment ago: send the form again', 409, ['field' => 'supplier']);
            }
            throw $e;
        }
        ExpenseHistory::record($db, $caller, 'supplier', $id, 1, 'add', null, ['name' => $name, 'is_active' => 1], null);
        return $id;
    }

    /**
     * 409 same_invoice when this expense is the same as others not deleted (other than $self), unless $same confirms exactly those: the
     * confirmation (detail.confirm of the 409 the person answered) is a digest of this bill's supplier, shop, number, day and total and of the
     * WHOLE set of the ones it meets, in order, worked out again here under the supplier's lock, so it holds only while exactly the records the
     * page listed are the ones met (the final review of 70ab3d2, astra MEDIUM; the fix round 2 of 8dbee81, Codex C1: no cap of 20, a bill
     * arriving meanwhile asks again; EX43, EX58). The same: the same supplier and shop and
     *  - with a number: the same number and kind (an invoice, or a credit note: the sign of its amount; EX37), or a bill of NO number of the same
     *    day and total (typed in first without its number);
     *  - with no number: the same day and total (signed: a credit note meets only a credit note), with a number or not (astra HIGH 4; EX42).
     * detail: every one met (matches: id, number, day, total and why: number | day_total), why (number when every one met has this number,
     * else day_total: its own words, the Fable re-check F-B), the first one's id and date, whether this is a credit note, the confirmation.
     *
     * @param array{shop: int, invoice_date: string, invoice_no: string, net: int, vat: int} $v
     */
    private function checkSame(Db $db, int $supplier, array $v, ?int $self, string $same): void
    {
        $total = $v['net'] + $v['vat'];
        $rows = $v['invoice_no'] !== ''
            ? $db->all('SELECT id, invoice_no, invoice_date, total_pence FROM expense WHERE supplier_id = ? AND channel_id = ? AND deleted_at IS NULL AND id <> ? '
                . "AND ((invoice_no = ? AND (net_pence < 0) = ?) OR (invoice_no = '' AND invoice_date = ? AND total_pence = ?)) ORDER BY id",
                [$supplier, $v['shop'], $self ?? 0, $v['invoice_no'], $v['net'] < 0 ? 1 : 0, $v['invoice_date'], $total])
            : $db->all('SELECT id, invoice_no, invoice_date, total_pence FROM expense WHERE supplier_id = ? AND channel_id = ? AND invoice_date = ? AND total_pence = ? '
                . 'AND deleted_at IS NULL AND id <> ? ORDER BY id', [$supplier, $v['shop'], $v['invoice_date'], $total, $self ?? 0]);
        if ($rows === []) {
            return;
        }
        $matches = array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'invoice_no' => (string) $r['invoice_no'], 'date' => (string) $r['invoice_date'],
            'total' => (int) $r['total_pence'], 'why' => $v['invoice_no'] !== '' && (string) $r['invoice_no'] === $v['invoice_no'] ? 'number' : 'day_total'], $rows);
        $confirm = self::confirmation('expense', [$supplier, $v['shop'], $v['invoice_no'], $v['invoice_date'], $total], array_column($matches, 'id'));
        if (hash_equals($confirm, $same)) {
            return;
        }
        $why = in_array('day_total', array_column($matches, 'why'), true) ? 'day_total' : 'number';
        throw new CwException('same_invoice', match (true) {
            $why === 'day_total' => ($v['net'] < 0 ? 'this credit note' : 'this bill') . ' is in already (the same supplier, shop, date and total)',
            $v['net'] < 0 => 'this credit note is in already (the same supplier, number and shop)',
            default => 'this invoice is in already (the same supplier, invoice number and shop)',
        }, 409, ['other' => $matches[0]['id'], 'date' => $matches[0]['date'], 'credit' => $v['net'] < 0, 'why' => $why, 'matches' => $matches, 'confirm' => $confirm]);
    }

    /**
     * The confirmation of a "same one" question: a sha256 of what the record is ($what, $key) and of every record it meets ($ids, in order), so a
     * confirmation holds for that record against exactly that set, whatever its size (Codex C1; EX58).
     *
     * @param list<int|string> $key
     * @param list<int> $ids
     */
    public static function confirmation(string $what, array $key, array $ids): string
    {
        return hash('sha256', (string) json_encode([$what, $key, $ids], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The file $fileId named by version $version of expense $id: kept in expense_file (the file rules ask it by its file_id index; EX24, EX40),
     * 7 years from now, and the storage's retention extended to that day (a file stored years before is kept for this expense too; the final
     * review of 70ab3d2, astra MEDIUM).
     */
    private function attach(Db $db, Caller $caller, int $id, int $fileId, int $version): void
    {
        $until = (string) $db->value('SELECT DATE_ADD(CURRENT_DATE(), INTERVAL ' . FileStore::RETENTION_YEARS . ' YEAR)');
        $db->exec('INSERT INTO expense_file (expense_id, file_id, version, retain_until, attached_actor) VALUES (?, ?, ?, ?, ?)',
            [$id, $fileId, $version, $until, $caller->actor]);
        $this->files()->retain($fileId, $until);
    }

    /** @return array<string, mixed> the expense row, locked, at the version the page showed and not deleted */
    private function locked(Db $db, int $id, int $seen): array
    {
        $row = $db->one('SELECT * FROM expense WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('not_found', 'there is no such expense', 404);
        if ($row['deleted_at'] !== null) {
            throw new CwException('deleted', 'this expense was deleted', 409);
        }
        if ((int) $row['version'] !== $seen) {
            throw self::meanwhile($db, 'expense', $id, (int) $row['version'], $seen);
        }
        return $row;
    }

    /** 409 changed_meanwhile naming who changed it last and when. */
    public static function meanwhile(Db $db, string $subject, int $id, int $now, int $seen): CwException
    {
        $last = $db->one('SELECT h.actor, u.display_name, h.created_at FROM expense_history h LEFT JOIN staff_user u ON u.id = h.staff_user_id '
            . 'WHERE h.subject = ? AND h.subject_id = ? ORDER BY h.version DESC LIMIT 1', [$subject, $id]);
        return new CwException('changed_meanwhile', "this was changed by someone else since the page was shown (version {$now}, the page had {$seen}): reload it", 409,
            ['version' => $now, 'by' => $last === null ? null : (string) ($last['display_name'] ?? $last['actor']), 'at' => $last['created_at'] ?? null]);
    }

    /** A name of 2 to NAME_MAX characters, trimmed, inner spaces made one, no control characters (422 $code). */
    public static function name(string $name, string $code = 'bad_name', string $field = 'name'): string
    {
        $name = self::plain($name);
        if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name) < 2 || mb_strlen($name) > self::NAME_MAX || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new CwException($code, 'a name of 2 to ' . self::NAME_MAX . ' characters', 422, ['field' => $field]);
        }
        return $name;
    }

    /**
     * Typed text as one plain line: every kind of space (a non-breaking or thin space copied from a PDF, a tab) one plain space, invisible
     * format characters (a zero-width space) gone, no space at either end; so "INV-487" copied with a non-breaking space is "INV-487" (the
     * Codex check r3, H2; EX32). Text that is not UTF-8 comes back as it was, for the caller's own check to refuse.
     */
    public static function plain(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        return trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', (string) preg_replace('/\p{Cf}+/u', '', $s)));
    }

    private function files(): FileStore
    {
        return $this->files === null ? throw new CwException('file_store_unconfigured', 'the file store is not set up on this server', 503) : ($this->files)();
    }
}
