<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Handovers;

use CW\Admin\ConfigInvariants;
use CW\CwException;
use CW\Handovers\HandoverInvariants;
use CW\Handovers\HandoverPdf;

/**
 * Hand-overs (pack H, IM11; docs/decisions.md PH1-PH14) through the services, against the real document base: a hand-over is booked once
 * (a second posting of the same form is refused, the ledger has its rows once) and cancelled by a new record that puts the stock back and
 * the amount off the balance; the prices a customer paid are remembered and a new line takes them; the VAT code comes from the hand-over,
 * the customer or the company; a protected item never goes below zero; the balance per customer follows hand-overs, cancellations and
 * payments; the invoice PDF is refused until the company details are confirmed; who may do what; the customer list; the old prices from
 * a file; the migration's rows. The invariants (H1-H5 with the rest) hold after every test.
 */
final class HandoversTest extends HandoversTestCase
{
    public function testAHandoverIsBookedOnceAndItsCancellationPutsEverythingBack(): void
    {
        $sku = $this->item('strict', 20);
        $other = $this->item('legacy', 5);
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Electrofag Ltd', 'sister', ['vat_code' => 'S']);
        $d = $this->draftTo($desk, $cp, [['sku_id' => $sku, 'qty' => 6, 'unit_cost' => '2.50'], ['sku_id' => $other, 'qty' => 3, 'unit_cost' => '1.333']],
            ['external_ref' => 'EF order 77']);
        self::assertSame([0, 0], [(int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$d->id]), count($this->entries($cp))],
            'a draft books nothing');
        $posted = $this->handovers->post($desk, $d->id, $d->version);
        self::assertSame(['posted', 'TRD-000001', 'not_required'], [$posted->status, $posted->number, $posted->reviewState], 'no reviewer check by default');
        // The same form sent again (a double tap, the back button): refused, nothing booked twice.
        $again = self::refusedWith('not_draft', fn () => $this->handovers->post($desk, $d->id, $d->version));
        self::assertSame(409, $again->httpStatus);
        self::assertSame([['MAIN', 'trade_sale', -6, null], ['MAIN', 'trade_sale', -3, null]], $this->ledgerOf($posted->id));
        self::assertSame([14, 2], [$this->onHand($sku), $this->onHand($other)]);
        // 6 x 2.50 = 15.00; 3 x 1.333 = 3.999 -> 4.00; net 19.00, VAT 20% 3.80, gross 22.80.
        self::assertSame(['S', '20.00', 'GBP', '19.00', '3.80', '22.80', 0, 0], array_values(array_map(static fn (mixed $v): mixed => is_int($v) ? $v : (string) $v, (array) self::$db->one(
            'SELECT vat_code, vat_rate, currency, net_amount, vat_amount, gross_amount, company_version, company_confirmed FROM handover WHERE document_id = ?', [$posted->id]))));
        self::assertSame([['handover', '22.80']], $this->entries($cp));
        self::assertSame('22.80', $this->balances->balance($cp));
        self::assertSame([[$sku, '2.500000', 'handover', $posted->id], [$other, '1.333000', 'handover', $posted->id]], array_map(static fn (array $r): array =>
            [(int) $r['sku_id'], (string) $r['unit_price'], (string) $r['source'], (int) $r['document_id']],
            self::$db->all('SELECT sku_id, unit_price, source, document_id FROM counterparty_price WHERE counterparty_id = ? ORDER BY sku_id', [$cp])));
        self::assertSame('Electrofag Ltd', json_decode((string) self::$db->value('SELECT buyer_snapshot FROM handover WHERE document_id = ?', [$posted->id]), true)['name']);

        $rev = $this->handovers->reverse($desk, $posted->id, 'entered_in_error', null);
        self::assertSame(['posted', 'TRD-000002', $posted->id], [$rev->status, $rev->number, $rev->reversesId]);
        self::assertSame('reversed', $this->docs->get($posted->id)->status);
        self::assertSame([['MAIN', 'trade_sale', 6, null], ['MAIN', 'trade_sale', 3, null]], $this->ledgerOf($rev->id));
        self::assertSame([20, 5], [$this->onHand($sku), $this->onHand($other)]);
        self::assertSame([['handover', '22.80'], ['handover_reversal', '-22.80']], $this->entries($cp));
        self::assertSame('0.00', $this->balances->balance($cp));
        self::assertSame(['-19.00', '-3.80', '-22.80', $cp], array_map(static fn (mixed $v): mixed => is_int($v) ? $v : (string) $v, array_values((array) self::$db->one(
            'SELECT net_amount, vat_amount, gross_amount, counterparty_id FROM handover WHERE document_id = ?', [$rev->id]))));
        self::refusedWith('not_reversible', fn () => $this->handovers->reverse($desk, $posted->id, 'entered_in_error', null));
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM counterparty_price WHERE counterparty_id = ?', [$cp]), 'the prices agreed stay');
    }

    public function testPricesAreRememberedPerCustomerAndANewLineTakesThem(): void
    {
        $sku = $this->item('legacy', 50);
        $desk = $this->staffUser('purchasing_desk');
        $a = $this->customer('Unit 6', 'unit', ['vat_code' => 'Z']);
        $b = $this->customer('Ashton Vapes', 'trade', ['vat_code' => 'S']);
        $this->postedTo($desk, $a, [['sku_id' => $sku, 'qty' => 2, 'unit_cost' => '3.10']], ['doc_date' => '2026-10-10']);

        // A new line for the same customer takes its price; for another customer there is none (the person types it).
        $d = $this->draftTo($desk, $a, []);
        $r = $this->handovers->addLine($desk, $d->id, $d->version, (string) $sku, 4, null, $this->settings());
        self::assertSame(['added', '3.100000', 'customer', 1], [$r['status'], $r['price'], $r['price_from'], $r['line_no']]);
        $r = $this->handovers->addItem($desk, $d->id, $r['document']->version, $sku, 1, null, $this->settings());
        self::assertSame(['incremented', 1], [$r['status'], $r['line_no']]);
        self::assertSame(5, (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ? AND line_no = 1', [$d->id]));
        $other = $this->draftTo($desk, $b, []);
        $r = $this->handovers->addItem($desk, $other->id, $other->version, $sku, 1, null, $this->settings());
        self::assertSame([null, 'none'], [$r['price'], $r['price_from']]);
        self::refusedWith('price_required', fn () => $this->handovers->post($desk, $other->id, $r['document']->version));
        // handovers.price_fallback = cost: the average cost so far fills the price.
        $this->posted('in', $this->staffUser('stock_controller'), ['reason_code' => 'found'], [['sku_id' => $sku, 'qty' => 4, 'unit_cost' => '1.50']]);
        $this->setting('handovers.price_fallback', '"cost"');
        $third = $this->draftTo($desk, $b, []);
        $r = $this->handovers->addItem($desk, $third->id, $third->version, $sku, 1, null, $this->settings());
        self::assertSame(['1.500000', 'cost'], [$r['price'], $r['price_from']]);
        // A later hand-over replaces the remembered price; one dated earlier does not.
        $this->postedTo($desk, $a, [['sku_id' => $sku, 'qty' => 1, 'unit_cost' => '3.40']], ['doc_date' => '2026-10-12']);
        $this->postedTo($desk, $a, [['sku_id' => $sku, 'qty' => 1, 'unit_cost' => '2.00']], ['doc_date' => '2026-10-01']);
        self::assertSame(['3.400000', '2026-10-12'], array_values(array_map('strval', (array) self::$db->one(
            'SELECT unit_price, priced_on FROM counterparty_price WHERE counterparty_id = ? AND sku_id = ?', [$a, $sku]))));
        self::assertSame(['3.400000'], array_values($this->customers->lastPrices($a, [$sku])));
    }

    public function testTheVatCodeComesFromTheHandoverTheCustomerOrTheCompany(): void
    {
        $sku = $this->item('legacy', 100);
        $desk = $this->staffUser('purchasing_desk');
        $plain = $this->customer('Plain Trade Ltd');
        $line = [['sku_id' => $sku, 'qty' => 1, 'unit_cost' => '10.00']];
        // No VAT code anywhere and the company may be VAT registered: refused, nothing booked.
        $d = $this->draftTo($desk, $plain, $line);
        self::refusedWith('vat_code_required', fn () => $this->handovers->post($desk, $d->id, $d->version));
        // The company's code (saved, not confirmed: posting does not wait for the confirmation).
        $this->company(false, 'S');
        $p = $this->handovers->post($desk, $d->id, $d->version);
        self::assertSame(['S', '2.00', '12.00'], array_values(array_map('strval', (array) self::$db->one('SELECT vat_code, vat_amount, gross_amount FROM handover WHERE document_id = ?', [$p->id]))));
        // The customer's own code wins over the company's; the hand-over's own code wins over both.
        $zero = $this->customer('Export Customer', 'trade', ['vat_code' => 'Z']);
        $p = $this->postedTo($desk, $zero, $line);
        self::assertSame(['Z', '0.00', '10.00'], array_values(array_map('strval', (array) self::$db->one('SELECT vat_code, vat_amount, gross_amount FROM handover WHERE document_id = ?', [$p->id]))));
        $p = $this->postedTo($desk, $zero, $line, ['vat_code' => 'R']);
        self::assertSame(['R', '0.50', '10.50'], array_values(array_map('strval', (array) self::$db->one('SELECT vat_code, vat_amount, gross_amount FROM handover WHERE document_id = ?', [$p->id]))));
        self::refusedWith('vat_code_off', fn () => $this->draftTo($desk, $zero, [], ['vat_code' => 'QQ']));
        // Not VAT registered (said on the company details): no VAT at all.
        $this->company(false, '', ['vat_registered' => 'no', 'vat_number' => '']);
        $p = $this->postedTo($desk, $zero, $line);
        self::assertSame([null, null, '0.00', '10.00'], array_values(array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, (array) self::$db->one(
            'SELECT vat_code, vat_rate, vat_amount, gross_amount FROM handover WHERE document_id = ?', [$p->id]))));
    }

    public function testAProtectedItemNeverGoesBelowZero(): void
    {
        $strict = $this->item('strict', 3);
        $legacy = $this->item('legacy', 1);
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Big Trade Ltd', 'trade', ['vat_code' => 'S']);
        $d = $this->draftTo($desk, $cp, [['sku_id' => $strict, 'qty' => 5, 'unit_cost' => '1.00']]);
        $e = self::refusedWith('below_zero', fn () => $this->handovers->post($desk, $d->id, $d->version));
        self::assertSame([1, 3, 5], [$e->detail['line'], $e->detail['have'], $e->detail['wanted']]);
        self::assertSame([3, 'draft'], [$this->onHand($strict), $this->docs->get($d->id)->status], 'nothing booked');
        // An unprotected (legacy) item is not held to it, as on a stock out.
        $this->postedTo($desk, $cp, [['sku_id' => $legacy, 'qty' => 2, 'unit_cost' => '1.00']]);
        self::assertSame(-1, $this->onHand($legacy));
        // Another account's room is never the source.
        $this->warehouse('VPGTWO', 'other', 'VPG Two Ltd');
        self::refusedWith('not_own_stock', fn () => $this->draftTo($desk, $cp, [], ['warehouse' => self::warehouseId('VPGTWO')]));
    }

    public function testTheBalancePerCustomerFollowsHandoversCancellationsAndPayments(): void
    {
        $sku = $this->item('legacy', 100);
        $desk = $this->staffUser('purchasing_desk');
        $a = $this->customer('Electrofag Ltd', 'sister', ['vat_code' => 'S']);
        $b = $this->customer('Vape Big Ltd', 'sister', ['vat_code' => 'S']);
        $this->postedTo($desk, $a, [['sku_id' => $sku, 'qty' => 4, 'unit_cost' => '2.50']]);     // 10.00 + 2.00
        $cancel = $this->postedTo($desk, $a, [['sku_id' => $sku, 'qty' => 2, 'unit_cost' => '5.00']]); // 10.00 + 2.00
        $this->postedTo($desk, $b, [['sku_id' => $sku, 'qty' => 1, 'unit_cost' => '5.00']]);     // 5.00 + 1.00
        self::assertSame(['24.00', '6.00'], [$this->balances->balance($a), $this->balances->balance($b)]);
        $this->handovers->reverse($desk, $cancel->id, 'entered_in_error', null);
        self::assertSame('12.00', $this->balances->balance($a));
        $paid = $this->balances->recordPayment($desk, $a, '7.00', '2026-10-01', 'BACS 4411', null, $this->settings());
        self::assertSame('5.00', $paid['balance']);
        $e = self::refusedWith('more_than_owed', fn () => $this->balances->recordPayment($desk, $a, '6.00', '2026-10-01', 'BACS 4412', null, $this->settings()));
        self::assertSame(['5.00', 409], [$e->detail['balance'], $e->httpStatus]);
        self::refusedWith('bad_amount', fn () => $this->balances->recordPayment($desk, $a, '-1', '2026-10-01', 'X', null, $this->settings()));
        self::refusedWith('bad_date', fn () => $this->balances->recordPayment($desk, $a, '1.00', '2999-01-01', 'X', null, $this->settings()));
        // handovers.allow_overpayment on: the balance goes into credit.
        $this->setting('handovers.allow_overpayment', 'true');
        $over = $this->balances->recordPayment($desk, $a, '6.00', '2026-10-02', 'BACS 4412', 'paid twice', $this->settings());
        self::assertSame('-1.00', $over['balance']);
        $back = $this->balances->reversePayment($desk, $over['entry_id'], 'paid twice by the shop');
        self::assertSame(['5.00', $a], [$back['balance'], $back['counterparty_id']]);
        self::refusedWith('already_reversed', fn () => $this->balances->reversePayment($desk, $over['entry_id'], 'again please'));
        self::assertSame([['handover', '12.00'], ['handover', '12.00'], ['handover_reversal', '-12.00'], ['payment', '-7.00'], ['payment', '-6.00'], ['payment_reversal', '6.00']],
            $this->entries($a));
        $rows = array_column($this->balances->balances(), null, 'id');
        self::assertSame(['5.00', 2, '7.00'], [$rows[$a]['balance'], $rows[$a]['handovers'], $rows[$a]['last_payment']['amount']]);
        self::assertSame(['6.00', 1, null], [$rows[$b]['balance'], $rows[$b]['handovers'], $rows[$b]['last_payment']]);
        // Who records payments: the purchasing desk and manager; never admin.
        self::refusedWith('role_not_allowed', fn () => $this->balances->recordPayment($this->staffUser('reviewer'), $b, '1.00', '2026-10-01', 'X', null, $this->settings()));
        self::refusedWith('admin_cannot_post', fn () => $this->balances->recordPayment($this->staffUser('admin'), $b, '1.00', '2026-10-01', 'X', null, $this->settings()));
        self::assertSame('5.00', $this->balances->recordPayment($this->staffUser('purchasing_manager'), $b, '1.00', '2026-10-01', 'X', null, $this->settings())['balance']);
    }

    public function testTheInvoiceIsRefusedUntilTheCompanyDetailsAreConfirmed(): void
    {
        $sku = $this->item('legacy', 100);
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Electrofag Ltd', 'sister');
        $this->company(false, 'S');
        $line = [['sku_id' => $sku, 'qty' => 2, 'unit_cost' => '4.00']];
        $draft = $this->draftTo($desk, $cp, $line);
        $e = self::refusedWith('company_not_confirmed', fn () => $this->handovers->invoice($draft->id, $this->settings()));
        self::assertSame([409, false], [$e->httpStatus, $e->detail['posted']]);
        $early = $this->handovers->post($desk, $draft->id, $draft->version);
        $e = self::refusedWith('company_not_confirmed', fn () => $this->handovers->invoice($early->id, $this->settings()));
        self::assertTrue($e->detail['posted'], 'made final before the confirmation: its invoice keeps the details it was made with');

        $this->company(true, 'S');
        self::refusedWith('company_not_confirmed', fn () => $this->handovers->invoice($early->id, $this->settings()), 'confirming later does not change a final invoice');
        $late = $this->postedTo($desk, $cp, $line);
        $inv = $this->handovers->invoice($late->id, $this->settings());
        self::assertSame([false, 'S', '20.00', '9.60', 'Example Vapes Ltd', 'Electrofag Ltd'], [$inv['banner'], $inv['vat_code'], $inv['vat_rate'], $inv['totals']['gross'],
            $inv['company']['legal_name'], $inv['buyer']['name']]);
        $pdf = HandoverPdf::build($inv);
        self::assertStringStartsWith('%PDF-', $pdf);
        // The early one is made again from a copy (the same customer, lines and prices), then cancelled.
        $copy = $this->handovers->copy($desk, $early->id);
        self::assertSame([['sku_id' => $sku, 'qty' => 2, 'unit_cost' => '4.000000']], array_map(static fn (array $l): array => ['sku_id' => (int) $l['sku_id'],
            'qty' => (int) $l['qty'], 'unit_cost' => (string) $l['unit_cost']], $this->docs->lines($copy->id)));
        self::assertSame(['draft', $cp], [$copy->status, (int) self::$db->value('SELECT counterparty_id FROM handover WHERE document_id = ?', [$copy->id])]);
        // The owner may let it print anyway, with "do not send" on it, as a purchase order does.
        $this->setting('handovers.pdf_needs_confirmed_company', 'false');
        self::assertTrue($this->handovers->invoice($early->id, $this->settings())['banner']);
        self::assertStringStartsWith('%PDF-', HandoverPdf::build($this->handovers->invoice($early->id, $this->settings())));
        // A cancellation record has no invoice of its own.
        $rev = $this->handovers->reverse($desk, $late->id, 'entered_in_error', null);
        self::refusedWith('no_invoice', fn () => $this->handovers->invoice($rev->id, $this->settings()));
        self::assertTrue($this->handovers->invoice($late->id, $this->settings())['record']['reversed_by_number'] !== null, 'the cancelled one says which record cancelled it');
    }

    public function testWhoMayDoWhat(): void
    {
        $cp = $this->customer('Trade One');
        foreach (['buyer' => 'role_not_allowed', 'stock_controller' => 'role_not_allowed', 'reviewer' => 'role_not_allowed', 'admin' => 'admin_cannot_post'] as $role => $code) {
            self::refusedWith($code, fn () => $this->handovers->createDraft($this->staffUser($role), ['counterparty' => $cp]));
            self::refusedWith($code, fn () => $this->customers->add($this->staffUser($role), ['kind' => 'trade', 'name' => 'Nope ' . $role]));
        }
        self::assertSame('draft', $this->handovers->createDraft($this->staffUser('purchasing_manager'), ['counterparty' => $cp])->status);
        $desk = $this->staffUser('purchasing_desk');
        $d = $this->handovers->createDraft($desk, ['counterparty' => $cp]);
        // A draft is changed by its creator only (the document base's rule).
        self::refusedWith('not_creator', fn () => $this->handovers->saveLines($this->staffUser('purchasing_desk'), $d->id, $d->version, []));
        self::refusedWith('staff_required', fn () => $this->customers->add(\CW\Caller::system('test'), ['kind' => 'trade', 'name' => 'System']));
    }

    public function testCustomersAreCheckedSwitchedOffAndNeverDeleted(): void
    {
        $desk = $this->staffUser('purchasing_desk');
        $e = self::refusedWith('customer_invalid', fn () => $this->customers->add($desk, ['kind' => 'friend', 'name' => 'X', 'company_number' => '123',
            'vat_number' => 'GB12', 'email' => 'not an e-mail', 'phone' => 'call me', 'address' => str_repeat("line\n", 9), 'vat_code' => 'QQ']));
        self::assertSame(['kind' => 'kind', 'name' => 'name_short', 'address' => 'lines_many', 'company_number' => 'company_number', 'vat_number' => 'vat_number',
            'email' => 'email', 'phone' => 'phone'], $e->detail['errors']);
        self::assertSame(['vat_code' => 'vat_code'], self::refusedWith('customer_invalid', fn () => $this->customers->add($desk, ['kind' => 'trade', 'name' => 'Some Ltd',
            'vat_code' => 'QQ']))->detail['errors']);
        $id = $this->customers->add($desk, ['kind' => 'trade', 'name' => 'MYV Ltd', 'company_number' => '1234 5678', 'vat_number' => '123 4567 82']);
        $c = $this->customers->get($id);
        self::assertSame(['12345678', 'GB123456782', 1, 1], [$c['company_number'], $c['vat_number'], (int) $c['is_active'], (int) $c['version']]);
        self::refusedWith('customer_exists', fn () => $this->customers->add($desk, ['kind' => 'unit', 'name' => 'myv ltd']));
        $s = $this->customers->save($desk, $id, 1, ['kind' => 'trade', 'name' => 'MYV Ltd', 'company_number' => '12345678', 'vat_number' => 'GB123456782', 'email' => 'shop@myv.example']);
        self::assertSame(['saved', 2, ['email']], [$s['result'], $s['version'], $s['changed']]);
        self::refusedWith('customer_changed', fn () => $this->customers->save($desk, $id, 1, ['kind' => 'trade', 'name' => 'MYV Two']));
        $d = $this->handovers->createDraft($desk, ['counterparty' => $id]);
        self::assertSame('off', $this->customers->setActive($desk, $id, 2, false)['result']);
        self::refusedWith('customer_off', fn () => $this->handovers->createDraft($desk, ['counterparty' => $id]));
        $sku = $this->item('legacy', 5);
        $d = $this->handovers->saveLines($desk, $d->id, $d->version, [['sku_id' => $sku, 'qty' => 1, 'unit_cost' => '1.00']]);
        self::refusedWith('customer_off', fn () => $this->handovers->post($desk, $d->id, $d->version));
        self::assertSame('on', $this->customers->setActive($desk, $id, 3, true)['result']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM counterparty WHERE id = ?', [$id]));
        self::assertSame(['counterparty.add', 'counterparty.change', 'counterparty.switch_off', 'counterparty.switch_on'], array_map('strval', self::$db->column(
            "SELECT action FROM audit_log WHERE entity_type = 'counterparty' AND entity_id = ? ORDER BY id", [(string) $id])));
    }

    public function testOldPricesLoadFromAFileAndDoubtfulRowsAreSkipped(): void
    {
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Exchange Vapes');
        $x = $this->item('legacy', 10);
        $y = $this->item('legacy', 10);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan) VALUES ('5060000000016', ?, 1, 1), ('5060000000023', ?, 1, 6)", [$y, $y]);
        // A price from a hand-over dated later than the file's line for $y: kept.
        $this->postedTo($desk, $cp, [['sku_id' => $y, 'qty' => 1, 'unit_cost' => '9.99']], ['vat_code' => 'S', 'doc_date' => '2026-10-05']);
        $path = sys_get_temp_dir() . '/cw_prices_' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, "Customer,Item Code,Rate,Posting Date,Sales Invoice\n"
            . "Exchange Vapes,CW-" . sprintf('%06d', $x) . ",2.10,2026-08-01,SINV-0001\n"
            . "exchange vapes,CW-" . sprintf('%06d', $x) . ",2.20,14/09/2026,SINV-0002\n"
            . "Nobody Ltd,CW-" . sprintf('%06d', $x) . ",1.00,2026-08-01,SINV-0003\n"
            . "Exchange Vapes,NOT-AN-ITEM,1.00,2026-08-01,SINV-0004\n"
            . "Exchange Vapes,5060000000023,1.00,2026-08-01,SINV-0005\n"
            . "Exchange Vapes,5060000000016,abc,2026-08-01,SINV-0006\n"
            . "Exchange Vapes,5060000000016,3.30,2026-09-01,SINV-0007\n"
            . "Exchange Vapes,CW-" . sprintf('%06d', $x) . ",2.50,31/02/2026,SINV-0008\n");
        try {
            $r = $this->customers->importPrices($desk, $path, $this->settings());
        } finally {
            @unlink($path);
        }
        self::assertSame([8, 1, 1], [$r['rows'], $r['imported'], $r['kept_newer']]);
        self::assertSame([[3, 'unknown_customer'], [4, 'unknown_item'], [5, 'item_doubtful'], [6, 'bad_price'], [8, 'bad_date']],
            array_map(static fn (array $s): array => [$s['row'], $s['why']], $r['skipped']));
        self::assertSame([[$x, '2.200000', '2026-09-14', 'import', 'SINV-0002'], [$y, '9.990000', '2026-10-05', 'handover', null]], array_map(static fn (array $p): array =>
            [(int) $p['sku_id'], (string) $p['unit_price'], (string) $p['priced_on'], (string) $p['source'], $p['source_ref'] === null ? null : (string) $p['source_ref']],
            self::$db->all('SELECT sku_id, unit_price, priced_on, source, source_ref FROM counterparty_price WHERE counterparty_id = ? ORDER BY sku_id', [$cp])));
        $bad = sys_get_temp_dir() . '/cw_prices_' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($bad, "Name,Price\nX,1\n");
        try {
            self::refusedWith('import_columns', fn () => $this->customers->importPrices($desk, $bad, $this->settings()));
        } finally {
            @unlink($bad);
        }
        self::refusedWith('role_not_allowed', fn () => $this->customers->importPrices($this->staffUser('buyer'), __FILE__, $this->settings()));
    }

    /** Review finding 1 (Codex, 14 Oct): another session writes a newer price between the import's read and its write. */
    public function testAnOlderImportNeverOverwritesANewerPriceWrittenMeanwhile(): void
    {
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Race Vapes');
        $x = $this->item('legacy', 5);
        $path = $this->csv("Customer,Item Code,Rate,Posting Date,Sales Invoice\nRace Vapes,CW-" . sprintf('%06d', $x) . ",2.10,2026-08-01,SINV-OLD\n");
        $conn = \CW\Tests\Support\TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        \CW\Tests\Support\HookedStatement::$before = ['INSERT INTO counterparty_price' => static function () use ($cp, $x): void {
            // Another import, committed meanwhile, with a price from a later day.
            self::$db->exec("INSERT INTO counterparty_price (counterparty_id, sku_id, unit_price, priced_on, source, source_ref, updated_actor) "
                . "VALUES (?, ?, 9.99, '2026-10-05', 'import', 'SINV-NEW', 'test')", [$cp, $x]);
        }];
        try {
            $r = (new \CW\Handovers\Counterparties($conn))->importPrices($desk, $path, $this->settings());
        } finally {
            \CW\Tests\Support\HookedStatement::$before = [];
            @unlink($path);
        }
        self::assertSame(['9.990000', '2026-10-05', 'SINV-NEW'], array_values(array_map('strval', (array) self::$db->one(
            'SELECT unit_price, priced_on, source_ref FROM counterparty_price WHERE counterparty_id = ? AND sku_id = ?', [$cp, $x]))), 'the newer price stays');
        self::assertSame([0, 1], [$r['imported'], $r['kept_newer']]);
    }

    /** Review finding 2 (Codex, 14 Oct): a deadlock retried after the file was read must read it again. */
    public function testAnImportIsRetriedWholeAfterADeadlock(): void
    {
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Retry Vapes');
        $x = $this->item('legacy', 5);
        $y = $this->item('legacy', 5);
        $path = $this->csv("customer,item,price\nRetry Vapes,CW-" . sprintf('%06d', $x) . ",1.10\nRetry Vapes,CW-" . sprintf('%06d', $y) . ",1.20\n");
        $conn = \CW\Tests\Support\TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\HookedStatement::class, []]);
        \CW\Tests\Support\HookedStatement::$before = ['INSERT INTO counterparty_price' => static function (): void {
            $e = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            throw $e;
        }];
        try {
            $r = (new \CW\Handovers\Counterparties($conn))->importPrices($desk, $path, $this->settings());
        } finally {
            \CW\Tests\Support\HookedStatement::$before = [];
            @unlink($path);
        }
        self::assertSame([2, 2, 0], [$r['rows'], $r['imported'], $r['kept_newer']]);
        self::assertSame(['1.100000', '1.200000'], array_map('strval', self::$db->column(
            'SELECT unit_price FROM counterparty_price WHERE counterparty_id = ? ORDER BY sku_id', [$cp])));
    }

    /** Review finding 5 (Codex, 14 Oct): an identifier longer than the key it is compared with names no item (never its first part). */
    public function testOnlyAWholeIdentifierNamesAnItem(): void
    {
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Long Codes Ltd');
        $x = $this->item('legacy', 5);
        $y = $this->item('legacy', 5);
        $site = $this->site('vgcodes');
        $variant = str_repeat('A', 64);
        $this->listing($site, $variant, $x);
        $erp = str_repeat('E', 140);
        self::$db->exec('INSERT INTO sku_erp_item (item_code, sku_id, units_per_item) VALUES (?, ?, 1)', [$erp, $y]);
        $path = $this->csv("customer,item,price,date\n"
            . "Long Codes Ltd,{$variant},1.00,2026-09-01\n"
            . "Long Codes Ltd,{$variant}B,2.00,2026-09-01\n"
            . "Long Codes Ltd,{$erp},3.10,2026-09-01\n"
            . "Long Codes Ltd,{$erp}X,3.00,2026-09-01\n");
        try {
            $r = $this->customers->importPrices($desk, $path, $this->settings());
        } finally {
            @unlink($path);
        }
        self::assertSame([[2, 'unknown_item'], [4, 'unknown_item']], array_map(static fn (array $s): array => [$s['row'], $s['why']], $r['skipped']));
        self::assertSame([[$x, '1.000000'], [$y, '3.100000']], array_map(static fn (array $p): array => [(int) $p['sku_id'], (string) $p['unit_price']],
            self::$db->all('SELECT sku_id, unit_price FROM counterparty_price WHERE counterparty_id = ? ORDER BY sku_id', [$cp])));
        self::assertSame('unknown_item', $this->customers->resolveItem($variant . 'B'));
        self::assertSame($x, $this->customers->resolveItem($variant));
    }

    /** A CSV file with these bytes in the temporary directory (the caller removes it). */
    private function csv(string $bytes): string
    {
        $path = sys_get_temp_dir() . '/cw_prices_' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, $bytes);
        return $path;
    }

    public function testTheMigrationSeedsTheSettingsAndSwitchesTheCheckOff(): void
    {
        $want = ['handovers.pdf_needs_confirmed_company' => 'true', 'handovers.invoice_terms' => '""', 'handovers.price_fallback' => '"none"',
            'handovers.allow_overpayment' => 'false', 'handovers.price_import_max_rows' => '5000', 'duty.register_lines' => '"stamp"',
            'duty.unstamped_warehouses' => '"UNSTAMPED"', 'duty.unstamped_zero_from' => '"2027-04-01"', 'duty.page_rows' => '200', 'duty.export_max_rows' => '20000'];
        foreach ($want as $key => $json) {
            self::assertSame($json, (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]), $key);
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ? AND action = 'baseline'", [$key]), $key);
        }
        $t = (array) self::$db->one("SELECT review_rule, approval_rule, size_approval, size_units, size_value FROM document_type WHERE code = 'TRD'");
        self::assertSame(['none', 'none', 0, 500, 2000], [(string) $t['review_rule'], (string) $t['approval_rule'], (int) $t['size_approval'], (int) $t['size_units'],
            (int) $t['size_value']]);
        $last = json_decode((string) self::$db->value("SELECT state FROM config_change WHERE subject_type = 'document_rule' AND subject_key = 'TRD' ORDER BY version DESC LIMIT 1"), true);
        self::assertSame(['none', 500, 2000], [$last['review_rule'], $last['size_units'], $last['size_value']]);
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4 hold with the new rows');
        // The stored map of pack E holds the three new permissions as the code gives them, each with its baseline.
        foreach (['handovers.customers', 'handovers.pay', 'duty.view'] as $perm) {
            self::assertSame(implode(',', \CW\Auth\Permissions::ordered(\CW\Auth\Permissions::MAP[$perm])),
                (string) self::$db->value('SELECT roles FROM role_permission WHERE permission = ?', [$perm]), $perm);
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'permission' AND subject_key = ? AND action = 'baseline'",
                [$perm]), $perm);
        }
        self::assertSame("enum('sister','unit','trade')", (string) self::$db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'counterparty' AND COLUMN_NAME = 'kind'"));
    }

    public function testTheChecksFindAForgedEntry(): void
    {
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Forged Ltd', 'trade', ['vat_code' => 'S']);
        $d = $this->handovers->createDraft($desk, ['counterparty' => $cp]);
        self::assertSame([], HandoverInvariants::check(self::$db));
        $id = self::$db->insert("INSERT INTO counterparty_entry (counterparty_id, kind, amount, document_id, created_actor) VALUES (?, 'handover', 50.00, ?, 'test')", [$cp, $d->id]);
        $found = HandoverInvariants::check(self::$db);
        self::assertNotSame([], $found);
        self::assertStringContainsString('has sums or entries', implode(' | ', $found));
        self::$db->exec('DELETE FROM counterparty_entry WHERE id = ?', [$id]);
        self::assertSame([], HandoverInvariants::check(self::$db));
    }
}
