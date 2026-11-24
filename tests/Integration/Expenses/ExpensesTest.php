<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Expenses;

use CW\Caller;
use CW\Expenses\Expenses;
use CW\Expenses\ExpenseTypes;
use CW\Expenses\ExpenseViews;
use CW\Expenses\RegularCosts;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Tests\Support\MappingTestCase;

/**
 * CW-1150 (docs/decisions.md EX1-): the expenses, their suppliers and types, and the regular costs, through their services as the screens
 * call them. Each expense is for ONE shop the person picks (no default), money in whole pence as typed (the VAT as printed, never worked out),
 * the same supplier + invoice number + shop twice refused unless confirmed, every change and delete kept with who, when, before and after (a
 * delete is a mark with a reason, never a removal), the invoice file a PDF, JPEG or PNG by its first bytes, at most 10 MB, kept in the document
 * store; only the Expenses job writes (Finance looks). A regular cost's new amount ends the old line and starts a new one; its per-day share.
 * Every file here is made up.
 */
final class ExpensesTest extends MappingTestCase
{
    private string $dir = '';
    private int $vpg = 0;
    private int $ef = 0;
    private int $vb = 0;

    private const PDF = "%PDF-1.4\n% made-up test invoice\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /** A made-up 1 x 1 PNG (a whole, valid image, so the content sniffer reads it as one). */
    private static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_expenses_' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/store', 0700, true);
        $this->vpg = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapeandgo', 'Vape and Go', 'off')");
        $this->ef = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('electrofag', 'Electrofag', 'off')");
        $this->vb = self::$db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapebig', 'Vape Big', 'off')");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function service(): Expenses
    {
        $store = $this->dir . '/store';
        return new Expenses(self::$db, static fn (): FileStore => new FileStore(self::$db, new LocalFileStorage($store)));
    }

    private function file(string $name, string $bytes): array
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(4)) . '-' . $name;
        file_put_contents($path, $bytes);
        return ['path' => $path, 'name' => $name];
    }

    private static function type(string $name, string $kind = 'expense'): int
    {
        return (int) self::$db->value('SELECT id FROM expense_type WHERE kind = ? AND name = ?', [$kind, $name]);
    }

    /** @return array<string, mixed> a filled-in expense form */
    private function form(array $over = []): array
    {
        return $over + ['shop' => $this->vpg, 'supplier' => 'Viking Direct', 'new_supplier' => true, 'type' => self::type('Stationery and packaging'),
            'invoice_date' => '2026-11-12', 'invoice_no' => '58812290', 'net' => '64.20', 'vat' => '12.84', 'note' => 'labels and tape'];
    }

    public function testTheStartListsAreThere(): void
    {
        self::assertSame(['Stationery and packaging', 'Software and AI', 'Developer', 'Fees', 'Other'],
            array_column((new ExpenseTypes(self::$db))->active('expense'), 'name'));
        self::assertSame(['Rent', 'Running the business', 'Marketing, website and people', 'Other'],
            array_column((new ExpenseTypes(self::$db))->active('regular'), 'name'));
    }

    public function testAnExpenseIsAddedForOneShopInPenceWithItsFileAndHistory(): void
    {
        $job = $this->staffUser('expenses');
        $r = $this->service()->add($job, $this->form(), $this->file('viking.pdf', self::PDF));
        $e = self::$db->one('SELECT * FROM expense WHERE id = ?', [$r['id']]);
        self::assertSame([$this->vpg, '2026-11-12', '58812290', 6420, 1284, 7704, 'labels and tape', 1],
            [(int) $e['channel_id'], $e['invoice_date'], $e['invoice_no'], (int) $e['net_pence'], (int) $e['vat_pence'], (int) $e['total_pence'], $e['note'], (int) $e['version']]);
        self::assertSame('Viking Direct', self::$db->value('SELECT name FROM expense_supplier WHERE id = ?', [$e['supplier_id']]));
        $f = self::$db->one('SELECT * FROM stored_file WHERE id = ?', [$e['file_id']]);
        self::assertSame(['application/pdf', 'expense_invoice', hash('sha256', self::PDF)], [$f['mime'], $f['kind'], $f['sha256']]);
        self::assertFileExists($this->dir . '/store/' . substr(hash('sha256', self::PDF), 0, 2) . '/' . hash('sha256', self::PDF), 'stored under its sha256');
        $h = self::$db->all("SELECT version, action, before_state, actor FROM expense_history WHERE subject = 'expense' AND subject_id = ?", [$r['id']]);
        self::assertSame([['version' => 1, 'action' => 'add', 'before_state' => null, 'actor' => $job->actor]],
            array_map(static fn (array $x): array => ['version' => (int) $x['version']] + $x, $h));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'expense.add' AND entity_id = ?", [(string) $r['id']]));
        // No file is fine too (it can be added later); a VAT of 0 is fine; a dollar bill is typed as the pounds the card was charged.
        $r2 = $this->service()->add($job, $this->form(['supplier' => 'Anthropic', 'invoice_no' => '2A7F-0019', 'net' => '200', 'vat' => '0', 'shop' => $this->vb,
            'type' => self::type('Software and AI')]));
        self::assertSame([20000, 0, 20000, null], array_values(self::$db->one('SELECT net_pence, vat_pence, total_pence, file_id FROM expense WHERE id = ?', [$r2['id']])));
    }

    public function testTheFormIsRefusedFieldByField(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        self::refused(422, 'choose_shop', fn () => $s->add($job, $this->form(['shop' => null])));
        self::refused(422, 'choose_shop', fn () => $s->add($job, $this->form(['shop' => 999])));
        self::refused(422, 'bad_supplier', fn () => $s->add($job, $this->form(['supplier' => ' '])));
        self::refused(422, 'unknown_supplier', fn () => $s->add($job, $this->form(['supplier' => 'Viking Direkt', 'new_supplier' => false])));
        self::refused(422, 'choose_type', fn () => $s->add($job, $this->form(['type' => null])));
        self::refused(422, 'choose_type', fn () => $s->add($job, $this->form(['type' => self::type('Rent', 'regular')])), 'a regular cost group is no expense type');
        self::refused(422, 'bad_date', fn () => $s->add($job, $this->form(['invoice_date' => '2026-02-30'])));
        self::refused(422, 'bad_date', fn () => $s->add($job, $this->form(['invoice_date' => '12/11/2026'])));
        self::refused(422, 'bad_net', fn () => $s->add($job, $this->form(['net' => '0'])), 'an expense of nothing');
        self::refused(422, 'bad_net', fn () => $s->add($job, $this->form(['net' => '-5.00'])));
        self::refused(422, 'bad_net', fn () => $s->add($job, $this->form(['net' => '64.205'])));
        self::refused(422, 'bad_vat', fn () => $s->add($job, $this->form(['vat' => ''])), 'the VAT as printed, 0 when none: never left empty');
        self::refused(422, 'bad_vat', fn () => $s->add($job, $this->form(['vat' => '12.8x'])));
        self::refused(422, 'bad_invoice_no', fn () => $s->add($job, $this->form(['invoice_no' => str_repeat('9', 65)])));
        self::refused(422, 'bad_note', fn () => $s->add($job, $this->form(['note' => str_repeat('n', 501)])));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM expense_supplier'), 'a refused form adds no supplier');
    }

    public function testOnlyTheExpensesJobWrites(): void
    {
        foreach (['finance', 'reviewer', 'accountant', 'delivery_bills', 'auditor'] as $role) {
            self::refused(403, 'role_not_allowed', fn () => $this->service()->add($this->staffUser($role), $this->form()));
        }
        self::refused(403, 'admin_cannot_post', fn () => $this->service()->add($this->staffUser('admin'), $this->form()));
        self::refused(403, 'staff_not_allowed', fn () => $this->service()->add($this->staffUser('expenses', false), $this->form()), 'switched off');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        // A job given expenses.edit on the Roles page does not get it (the permission is locked to the Expenses job, as the delivery bills').
        self::$db->exec("UPDATE role_permission SET roles = 'expenses,buyer' WHERE permission = 'expenses.edit'");
        try {
            \CW\Auth\RoleMap::load(self::$db);
            self::refused(403, 'role_not_allowed', fn () => $this->service()->add($this->staffUser('buyer'), $this->form()));
        } finally {
            self::$db->exec("UPDATE role_permission SET roles = 'expenses' WHERE permission = 'expenses.edit'");
            \CW\Auth\Permissions::useStored(null);
        }
    }

    public function testTheSameInvoiceTwiceIsRefusedUnlessConfirmed(): void
    {
        $job = $this->staffUser('expenses');
        $first = $this->service()->add($job, $this->form());
        $e = self::refused(409, 'same_invoice', fn () => $this->service()->add($job, $this->form(['supplier' => 'viking direct', 'invoice_no' => ' 58812290 ', 'new_supplier' => false])));
        self::assertSame($first['id'], $e->detail['other'] ?? null);
        // Another shop, another number or another supplier: not the same.
        $this->service()->add($job, $this->form(['shop' => $this->ef]));
        $this->service()->add($job, $this->form(['invoice_no' => '58812291']));
        $this->service()->add($job, $this->form(['supplier' => 'Amazon']));
        // Confirmed (for the one it was asked about: EX43): kept as a second one.
        $second = $this->service()->add($job, $this->form(), null, $e->detail['confirm']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM expense WHERE invoice_no = '58812290' AND channel_id = ? AND supplier_id = ?",
            [$this->vpg, $first['supplier_id']]));
        // A bill with no number: the same supplier, shop, day and total is the same one.
        $this->service()->add($job, $this->form(['invoice_no' => '', 'supplier' => 'Hub Box']));
        self::refused(409, 'same_invoice', fn () => $this->service()->add($job, $this->form(['invoice_no' => '', 'supplier' => 'Hub Box'])));
        $this->service()->add($job, $this->form(['invoice_no' => '', 'supplier' => 'Hub Box', 'net' => '64.21']));
        // A deleted one is not in the way.
        $v = (int) self::$db->value('SELECT version FROM expense WHERE id = ?', [$first['id']]);
        $this->service()->delete($job, $first['id'], $v, 'entered twice by mistake');
        $this->service()->add($job, $this->form(['shop' => $this->vb]));
        $this->service()->add($job, $this->form(), null, self::refused(409, 'same_invoice', fn () => $this->service()->add($job, $this->form()))->detail['confirm']);
    }

    public function testAChangeAndADeleteKeepWhoWhenBeforeAndAfter(): void
    {
        $job = $this->staffUser('expenses');
        $other = $this->staffUser('expenses');
        $r = $this->service()->add($job, $this->form());
        $c = $this->service()->change($other, $r['id'], 1, $this->form(['net' => '65.00', 'vat' => '13.00', 'shop' => $this->ef, 'new_supplier' => false]));
        self::assertSame([true, 2], [$c['changed'], $c['version']]);
        $row = self::$db->one('SELECT * FROM expense WHERE id = ?', [$r['id']]);
        self::assertSame([6500, 1300, 7800, $this->ef, 2, $other->actor], [(int) $row['net_pence'], (int) $row['vat_pence'], (int) $row['total_pence'],
            (int) $row['channel_id'], (int) $row['version'], $row['updated_actor']]);
        $h = self::$db->one("SELECT action, CAST(before_state AS CHAR) AS b, CAST(state AS CHAR) AS a, actor FROM expense_history WHERE subject = 'expense' AND subject_id = ? "
            . 'AND version = 2', [$r['id']]);
        $before = json_decode((string) $h['b'], true);
        $after = json_decode((string) $h['a'], true);
        self::assertSame(['change', $other->actor], [$h['action'], $h['actor']]);
        self::assertSame([6420, 1284, $this->vpg], [$before['net_pence'], $before['vat_pence'], $before['channel_id']]);
        self::assertSame([6500, 1300, $this->ef], [$after['net_pence'], $after['vat_pence'], $after['channel_id']]);
        // Nothing changed: no version. A page drawn before the change: refused.
        self::assertSame([false, 2], array_values($this->service()->change($job, $r['id'], 2, $this->form(['net' => '65.00', 'vat' => '13.00', 'shop' => $this->ef,
            'new_supplier' => false]))));
        self::refused(409, 'changed_meanwhile', fn () => $this->service()->change($job, $r['id'], 1, $this->form(['net' => '1.00'])));
        // A file added later.
        $this->service()->change($job, $r['id'], 2, $this->form(['net' => '65.00', 'vat' => '13.00', 'shop' => $this->ef, 'new_supplier' => false]),
            $this->file('scan.png', self::png()));
        self::assertSame('image/png', self::$db->value('SELECT f.mime FROM expense e JOIN stored_file f ON f.id = e.file_id WHERE e.id = ?', [$r['id']]));
        // A delete needs a reason; it is a mark, never a removal.
        self::refused(400, 'bad_reason', fn () => $this->service()->delete($job, $r['id'], 3, 'x'));
        $this->service()->delete($job, $r['id'], 3, 'it was Electrofag\'s own card');
        $row = self::$db->one('SELECT deleted_at, deleted_actor, deleted_reason, version FROM expense WHERE id = ?', [$r['id']]);
        self::assertNotNull($row['deleted_at']);
        self::assertSame([$job->actor, 'it was Electrofag\'s own card', 4], [$row['deleted_actor'], $row['deleted_reason'], (int) $row['version']]);
        self::assertSame(['add', 'change', 'change', 'delete'], self::$db->column("SELECT action FROM expense_history WHERE subject = 'expense' AND subject_id = ? ORDER BY version",
            [$r['id']]));
        self::refused(409, 'deleted', fn () => $this->service()->change($job, $r['id'], 4, $this->form()));
        self::refused(409, 'deleted', fn () => $this->service()->delete($job, $r['id'], 4, 'again'));
        // The app login can never remove a row (Grants: no DELETE on the money rows; the history is append-only).
        self::assertSame(['Select', 'Insert'], \CW\Schema\Grants::desired('expense'));
        self::assertSame(['Select', 'Insert'], \CW\Schema\Grants::desired('expense_history'));
        self::assertArrayNotHasKey('created_at', \CW\Schema\Grants::desiredColumns('expense'), 'who added it and when are frozen');
    }

    public function testTheFileIsCheckedByItsFirstBytes(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . str_repeat("\0", 64);
        $s->add($job, $this->form(['invoice_no' => 'J1']), $this->file('photo.jpg', $jpeg));
        self::assertSame('image/jpeg', self::$db->value("SELECT f.mime FROM expense e JOIN stored_file f ON f.id = e.file_id WHERE e.invoice_no = 'J1'"));
        // Named .pdf, but text: refused; HTML, SVG, GIF, a ZIP named .pdf: refused; an empty file: refused; nothing stored.
        foreach ([['fake.pdf', "This is not a PDF\n"], ['page.pdf', "<html><script>alert(1)</script></html>"], ['pic.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
            ['anim.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;"], ['zip.pdf', "PK\x03\x04" . str_repeat("\0", 40)], ['x.pdf', " %PDF-1.4 late magic"]] as [$name, $bytes]) {
            self::refused(415, 'file_type', fn () => $s->add($job, $this->form(['invoice_no' => 'T-' . $name]), $this->file($name, $bytes)));
        }
        self::refused(400, 'empty_file', fn () => $s->add($job, $this->form(['invoice_no' => 'E']), $this->file('empty.pdf', '')));
        // Over 10 MB: refused (a PDF's first bytes and 10 MB + 1 byte).
        $big = $this->file('big.pdf', '');
        $h = fopen($big['path'], 'wb');
        fwrite($h, '%PDF-1.4' . "\n");
        ftruncate($h, 10 * 1024 * 1024 + 1);
        fclose($h);
        self::refused(413, 'file_too_large', fn () => $s->add($job, $this->form(['invoice_no' => 'B']), $big));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM expense'));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM stored_file'));
    }

    public function testSuppliersAreTheirOwnListAndTypesASettableOne(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $id = $s->addSupplier($job, 'Tabsyst');
        self::refused(409, 'supplier_exists', fn () => $s->addSupplier($job, ' tabsyst '));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM supplier WHERE name = 'Tabsyst'"), 'not the stock suppliers');
        $s->changeSupplier($job, $id, 1, 'Tabsyst Ltd', null);
        $s->changeSupplier($job, $id, 2, null, false);
        self::refused(422, 'supplier_off', fn () => $s->add($job, $this->form(['supplier' => 'Tabsyst Ltd', 'new_supplier' => false])));
        self::assertSame(['add', 'change', 'switch_off'], self::$db->column("SELECT action FROM expense_history WHERE subject = 'supplier' AND subject_id = ? ORDER BY version", [$id]));
        self::refused(403, 'role_not_allowed', fn () => $s->addSupplier($this->staffUser('finance'), 'Nope'));

        // The types: settings.manage (an admin or a reviewer), each change with its reason; never deleted.
        $types = new ExpenseTypes(self::$db);
        self::refused(403, 'role_not_allowed', fn () => $types->add($job, 'expense', 'Postage', 'we post things'));
        $reviewer = $this->staffUser('reviewer');
        $t = $types->add($reviewer, 'expense', 'Postage', 'stamps and parcels we post by hand');
        self::refused(409, 'type_exists', fn () => $types->add($reviewer, 'expense', 'postage', 'twice'));
        self::refused(400, 'bad_reason', fn () => $types->rename($reviewer, $t, 1, 'Postage and stamps', ''));
        $types->rename($reviewer, $t, 1, 'Postage and stamps', 'clearer');
        $types->setActive($reviewer, $t, 2, false, 'not used any more');
        self::assertNotContains('Postage and stamps', array_column($types->active('expense'), 'name'));
        self::assertSame(['add', 'change', 'switch_off'], self::$db->column("SELECT action FROM expense_history WHERE subject = 'type' AND subject_id = ? ORDER BY version", [$t]));
        self::refused(422, 'type_off', fn () => $s->add($job, $this->form(['type' => $t])));
        self::assertSame(['Select', 'Insert'], \CW\Schema\Grants::desired('expense_type'), 'a type is never deleted');
    }

    public function testRegularCostsANewAmountEndsTheOldLineAndADeleteIsAMark(): void
    {
        $job = $this->staffUser('expenses');
        $rc = new RegularCosts(self::$db);
        $rent = self::type('Rent', 'regular');
        $id = $rc->add($job, ['name' => 'Unit 1', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '207.69', 'from' => '2026-11-01',
            'until' => '', 'note' => '']);
        self::refused(422, 'choose_shop', fn () => $rc->add($job, ['name' => 'X1', 'shop' => null, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '1', 'from' => '2026-11-01']));
        self::refused(422, 'bad_every', fn () => $rc->add($job, ['name' => 'X2', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'day', 'amount' => '1', 'from' => '2026-11-01']));
        self::refused(422, 'bad_amount', fn () => $rc->add($job, ['name' => 'X3', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '0', 'from' => '2026-11-01']));
        self::refused(422, 'bad_until', fn () => $rc->add($job, ['name' => 'X4', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week', 'amount' => '1', 'from' => '2026-11-01',
            'until' => '2026-10-31']));
        self::refused(422, 'choose_type', fn () => $rc->add($job, ['name' => 'X5', 'shop' => $this->vpg, 'type' => self::type('Fees'), 'vat' => 'none', 'every' => 'week', 'amount' => '1',
            'from' => '2026-11-01']));
        self::refused(403, 'role_not_allowed', fn () => $rc->add($this->staffUser('finance'), ['name' => 'X6', 'shop' => $this->vpg, 'type' => $rent, 'vat' => 'none', 'every' => 'week',
            'amount' => '1', 'from' => '2026-11-01']));

        $views = new ExpenseViews(self::$db);
        self::assertSame(89010, $views->regularBy('2026-11-01', '2026-11-30', null)[$this->vpg] ?? null, 'November GBP 890.10');
        // The rent goes up to GBP 210.00 a week from 11 Nov: the old line ends on 10 Nov (its days keep GBP 207.69), the new one starts.
        $new = $rc->newAmount($job, $id, 1, '210.00', '2026-11-11');
        $old = self::$db->one('SELECT until_day, version, amount_pence FROM regular_cost WHERE id = ?', [$id]);
        self::assertSame(['2026-11-10', 2, 20769], [$old['until_day'], (int) $old['version'], (int) $old['amount_pence']]);
        $n = self::$db->one('SELECT from_day, until_day, amount_pence, replaces_id, name, channel_id FROM regular_cost WHERE id = ?', [$new]);
        self::assertSame(['2026-11-11', null, 21000, $id, 'Unit 1', $this->vpg], [$n['from_day'], $n['until_day'], (int) $n['amount_pence'], (int) $n['replaces_id'],
            $n['name'], (int) $n['channel_id']]);
        self::assertSame(29670 + 60000, $views->regularBy('2026-11-01', '2026-11-30', null)[$this->vpg], '10 days at 207.69/7 + 20 days at 210/7');
        self::refused(422, 'bad_from', fn () => $rc->newAmount($job, $new, 1, '220.00', '2026-11-11'), 'a new amount starts after the line\'s first day');
        self::refused(409, 'changed_meanwhile', fn () => $rc->newAmount($job, $id, 1, '220.00', '2026-11-20'));
        self::refused(409, 'ended', fn () => $rc->newAmount($job, $id, 2, '220.00', '2026-11-20'), 'an ended line takes no new amount');
        // The old line's end cannot be moved onto the new amount's days (two amounts on one day), nor taken off.
        self::refused(422, 'overlaps', fn () => $rc->change($job, $id, 2, ['name' => 'Unit 1 rent', 'type' => $rent, 'until' => '2026-11-11', 'note' => '']));
        self::refused(422, 'overlaps', fn () => $rc->change($job, $id, 2, ['name' => 'Unit 1 rent', 'type' => $rent, 'until' => '', 'note' => '']));
        // Its name, group, note and end change in place, with the history.
        self::assertTrue($rc->change($job, $new, 1, ['name' => 'Unit 1 rent', 'type' => $rent, 'until' => '2027-03-31', 'note' => 'lease to March']));
        self::assertSame('2027-03-31', self::$db->value('SELECT until_day FROM regular_cost WHERE id = ?', [$new]));
        // A mistake is deleted with a reason: out of the profit on every day.
        self::refused(400, 'bad_reason', fn () => $rc->delete($job, $new, 2, ''));
        $rc->delete($job, $new, 2, 'typed in the wrong shop');
        self::assertSame(29670, $views->regularBy('2026-11-01', '2026-11-30', null)[$this->vpg]);
        self::assertSame(['add', 'change', 'delete'], self::$db->column("SELECT action FROM expense_history WHERE subject = 'regular_cost' AND subject_id = ? ORDER BY version", [$new]));
        self::assertSame(['add', 'end'], self::$db->column("SELECT action FROM expense_history WHERE subject = 'regular_cost' AND subject_id = ? ORDER BY version", [$id]));
        self::assertSame(['Select', 'Insert'], \CW\Schema\Grants::desired('regular_cost'));
        self::assertNotContains('amount_pence', \CW\Schema\Grants::UPDATE_COLUMNS['regular_cost'], 'an amount is never rewritten: a new line');
    }

    /** EX18: the nightly checks find a row changed around the services (the app login may UPDATE these rows; the history is the record). */
    public function testTheNightlyChecksFindARowChangedAroundCw(): void
    {
        $job = $this->staffUser('expenses');
        $r = $this->service()->add($job, $this->form(), $this->file('v.pdf', self::PDF));
        $rc = (new RegularCosts(self::$db))->add($job, ['name' => 'Unit 1 rent', 'shop' => $this->vpg, 'type' => self::type('Rent', 'regular'), 'vat' => 'none', 'every' => 'week',
            'amount' => '207.69', 'from' => '2026-11-01']);
        self::assertSame([], \CW\Expenses\ExpenseInvariants::check(self::$db));
        self::$db->exec('UPDATE expense SET net_pence = 100, total_pence = 1384 WHERE id = ?', [$r['id']]);
        self::assertSame(["EX-I2 expense {$r['id']}: the row is not what its latest history version says (changed around CW)"], \CW\Expenses\ExpenseInvariants::check(self::$db));
        self::$db->exec('UPDATE expense SET net_pence = 6420, total_pence = 7704, version = 2 WHERE id = ?', [$r['id']]);
        self::assertSame(["EX-I1 expense {$r['id']}: version 2, its history has 1 versions up to 1"], \CW\Expenses\ExpenseInvariants::check(self::$db));
        self::$db->exec('UPDATE expense SET version = 1, deleted_at = NOW(6), deleted_actor = \'x\', deleted_reason = \'around\' WHERE id = ?', [$r['id']]);
        self::assertCount(1, \CW\Expenses\ExpenseInvariants::check(self::$db), 'deleted around CW');
        self::$db->exec('UPDATE expense SET deleted_at = NULL, deleted_actor = NULL, deleted_reason = NULL WHERE id = ?', [$r['id']]);
        self::$db->exec("UPDATE regular_cost SET until_day = '2026-12-31' WHERE id = ?", [$rc]);
        self::assertSame(["EX-I2 regular_cost {$rc}: the row is not what its latest history version says (changed around CW)"], \CW\Expenses\ExpenseInvariants::check(self::$db));
        self::$db->exec('UPDATE regular_cost SET until_day = NULL WHERE id = ?', [$rc]);
        self::assertSame([], \CW\Expenses\ExpenseInvariants::check(self::$db));
    }

    public function testTheMonthsExpensesAndRegularCostsPerShop(): void
    {
        $job = $this->staffUser('expenses');
        $s = $this->service();
        $s->add($job, $this->form(['net' => '750.00', 'vat' => '150.00', 'supplier' => 'Tabsyst', 'invoice_no' => 'INV-2041', 'invoice_date' => '2026-11-20']));
        $s->add($job, $this->form(['net' => '166.67', 'vat' => '33.33', 'supplier' => 'Anthropic', 'invoice_no' => '2A7F-0019', 'invoice_date' => '2026-11-18']));
        $s->add($job, $this->form(['net' => '96.00', 'vat' => '0', 'shop' => $this->ef, 'invoice_no' => 'EF1', 'invoice_date' => '2026-11-30']));
        $s->add($job, $this->form(['net' => '39.00', 'vat' => '7.80', 'shop' => $this->vb, 'invoice_no' => '026-4471', 'invoice_date' => '2026-11-15']));
        $s->add($job, $this->form(['net' => '10.00', 'vat' => '2.00', 'invoice_no' => 'OCT', 'invoice_date' => '2026-10-31']));
        $gone = $s->add($job, $this->form(['net' => '99.00', 'vat' => '0', 'invoice_no' => 'GONE', 'invoice_date' => '2026-11-02']));
        $s->delete($job, $gone['id'], 1, 'a test entry');
        $views = new ExpenseViews(self::$db);
        $by = $views->expensesBy('2026-11-01', '2026-11-30', null);
        self::assertSame(['net' => 91667, 'vat' => 18333, 'total' => 110000, 'count' => 2, 'credits' => 0], $by[$this->vpg], 'by invoice date, deleted ones out');
        self::assertSame(['net' => 9600, 'vat' => 0, 'total' => 9600, 'count' => 1, 'credits' => 0], $by[$this->ef]);
        self::assertSame(['net' => 3900, 'vat' => 780, 'total' => 4680, 'count' => 1, 'credits' => 0], $by[$this->vb]);
        self::assertSame([$this->ef], array_keys($views->expensesBy('2026-11-01', '2026-11-30', $this->ef)));
        $list = $views->month('2026-11', null, '', false);
        self::assertSame(['Vape and Go', 'Electrofag', 'Vape Big'], array_column($list['groups'], 'shop'), 'grouped by shop in the shops\' order');
        self::assertSame(['INV-2041', '2A7F-0019'], array_column($list['groups'][0]['rows'], 'invoice_no'), 'newest first');
        self::assertSame(['2A7F-0019'], array_column($views->month('2026-11', null, 'anthrop', false)['groups'][0]['rows'], 'invoice_no'), 'by supplier');
        self::assertSame(['INV-2041'], array_column($views->month('2026-11', null, 'inv-20', false)['groups'][0]['rows'], 'invoice_no'), 'by invoice number');
        self::assertSame(['GONE'], array_column($views->month('2026-11', $this->vpg, 'gone', true)['groups'][0]['rows'], 'invoice_no'), 'deleted ones when asked');
    }
}
