<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Auth\Permissions;
use CW\Duty\DutyRegister;
use CW\Handovers\Counterparties;
use CW\Handovers\HandoverMath;
use CW\Ui\Sections;
use CW\Ui\View;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Pack H without a database (docs/decisions.md PH1-PH20): the invoice's sums (to the penny, half up, VAT once on the net, a reversal's
 * the negation), the customer form's checks, the duty register's filters, every code of the pack with its words, who may do what, the
 * menu, and the pack's templates held to the screens' rules (cards on a phone, every word from Words, the CSRF field on every POST).
 */
final class HandoverUnitTest extends TestCase
{
    private const TEMPLATES = ['handovers', 'handover', 'handover_fields', 'handover_customers', 'handover_customer', 'handover_customer_fields', 'handover_balances',
        'duty_register'];

    public function testTheSumsOfAnInvoice(): void
    {
        $lines = [['sku_id' => 1, 'qty' => 6, 'unit_cost' => '2.500000'], ['sku_id' => 2, 'qty' => 3, 'unit_cost' => '1.333000'], ['sku_id' => 3, 'qty' => 1, 'unit_cost' => null],
            ['sku_id' => null, 'qty' => 9, 'unit_cost' => '9.00']];
        self::assertSame('19.00', HandoverMath::net($lines), '15.00 + 3.999 -> 4.00; a line without a price or an item adds nothing');
        self::assertSame(['net' => '19.00', 'vat' => '3.80', 'gross' => '22.80'], HandoverMath::totals($lines, '20.00'));
        self::assertSame(['net' => '19.00', 'vat' => '0.00', 'gross' => '19.00'], HandoverMath::totals($lines, null));
        self::assertSame('0.00', HandoverMath::vat('19.00', '0.00'));
        self::assertSame('0.03', HandoverMath::vat('0.13', '20.00'), '0.026 -> 0.03 (half up)');
        self::assertSame('0.02', HandoverMath::vat('0.12', '20.00'), '0.024 -> 0.02');
        self::assertSame('0.01', HandoverMath::vat('0.10', '5.00'), '0.005 -> 0.01 (half up, not to even)');
        self::assertSame('-0.01', HandoverMath::vat('-0.10', '5.00'), 'a negative net rounds away from zero, the mirror of its positive');
        self::assertSame(['-22.80', '0.00', '0.00'], [HandoverMath::negate('22.80'), HandoverMath::negate('0.00'), HandoverMath::money('-0.00')]);
        self::assertSame('1234567.90', HandoverMath::net([['sku_id' => 1, 'qty' => 1, 'unit_cost' => '1234567.895']]));
    }

    public function testTheCustomerFormIsCheckedAndTidied(): void
    {
        $v = Counterparties::check(['kind' => 'sister', 'name' => "  Electrofag \t Ltd ", 'address' => "1 High St\r\n\r\n  Leeds   LS1 1AA ", 'company_number' => 'sc 123-456',
            'vat_number' => '123.4567.82', 'vat_code' => 'z', 'email' => ' shop@example.co.uk ', 'phone' => '0113  496 0000', 'note' => '']);
        self::assertSame(['kind' => 'sister', 'name' => 'Electrofag Ltd', 'address' => "1 High St\nLeeds LS1 1AA", 'company_number' => 'SC123456',
            'vat_number' => 'GB123456782', 'vat_code' => 'Z', 'email' => 'shop@example.co.uk', 'phone' => '0113 496 0000', 'note' => null], $v);
        try {
            Counterparties::check(['kind' => 'trade', 'name' => 'Łódź Vapes', 'note' => str_repeat('x', 501), 'vat_code' => 'S1']);
            self::fail('refused');
        } catch (\CW\CwException $e) {
            self::assertSame(['customer_invalid', ['name' => 'unprintable', 'vat_code' => 'vat_code', 'note' => 'note_long']], [$e->errorCode, $e->detail['errors']]);
        }
        self::assertSame(['2026-10-14', '2026-10-14', null, null, null], [Counterparties::day('2026-10-14'), Counterparties::day('14/10/2026'), Counterparties::day('31/02/2026'),
            Counterparties::day('2026-13-01'), Counterparties::day('1999-12-31')]);
    }

    public function testTheCompanyDetailsTakeAVatCodeAndPoundsOnly(): void
    {
        $base = ['legal_name' => 'Example Vapes Ltd', 'vat_registered' => 'yes', 'vat_number' => 'GB123456782'];
        $v = \CW\Company\CompanyDetails::check($base + ['vat_code' => ' s '])['values'];
        self::assertSame(['S', 'GBP'], [$v['vat_code'], $v['currency']]);
        self::assertSame(['', 'GBP'], array_values(array_intersect_key(\CW\Company\CompanyDetails::check($base)['values'], ['vat_code' => 1, 'currency' => 1])));
        try {
            \CW\Company\CompanyDetails::check($base + ['vat_code' => 'S1', 'currency' => 'EUR']);
            self::fail('refused');
        } catch (\CW\CwException $e) {
            self::assertSame(['vat_code', 'currency'], array_keys($e->detail['errors']));
        }
        self::assertSame(['VAT code of our invoices', 'currency'], [\CW\Company\CompanyDetails::FIELDS['vat_code'], \CW\Company\CompanyDetails::FIELDS['currency']]);
        self::assertSame('pounds (GBP)', \CW\Company\CompanyDetails::shown('currency', 'GBP'));
    }

    public function testEveryCodeOfThePackHasItsWords(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0038_handovers_company_duty.sql');
        $enum = static function (string $table, string $column) use ($sql): array {
            $at = strpos($sql, "CREATE TABLE {$table} (");
            self::assertNotFalse($at, $table);
            self::assertSame(1, preg_match('/\b' . $column . '\s+ENUM\(([^)]*)\)/', substr($sql, (int) $at), $m), "{$table}.{$column}");
            return array_map(static fn (string $v): string => trim($v, " '"), explode(',', $m[1]));
        };
        self::assertSame(Counterparties::KINDS, $enum('counterparty', 'kind'));
        foreach ([
            'CUSTOMER_KIND' => Counterparties::KINDS,
            'BALANCE_KIND' => $enum('counterparty_entry', 'kind'),
            'DUTY_STAMP' => DutyRegister::STAMP_STATES,
            'PRICE_SKIP' => ['unknown_customer', 'unknown_item', 'item_doubtful', 'bad_price', 'bad_date', 'bad_reference'],
            'CUSTOMER_FIELD_ERROR' => ['kind', 'name_short', 'name_long', 'not_text', 'control', 'unprintable', 'lines_many', 'line_long', 'company_number', 'vat_number',
                'vat_code', 'email', 'phone', 'note_long', 'other'],
            'HANDOVER_ERROR' => ['customer_required', 'unknown_customer', 'customer_off', 'warehouse_required', 'warehouse_inactive', 'not_own_stock', 'bad_line', 'price_required',
                'vat_code_required', 'vat_code_off', 'below_zero', 'qty_positive', 'too_many_lines', 'company_not_confirmed', 'company_not_confirmed_posted', 'vat_code_missing',
                'no_invoice'],
            'BALANCE_ERROR' => ['more_than_owed', 'nothing_owed', 'bad_amount', 'bad_date', 'bad_reference', 'bad_reason', 'already_reversed', 'not_a_payment'],
            'CUSTOMER_ERROR' => ['customer_invalid', 'customer_exists', 'customer_changed', 'import_columns'],
            'HANDOVER_NOTICE' => ['created', 'header', 'lines', 'added', 'incremented', 'posted', 'submitted', 'cancelled', 'reversed', 'reversal_submitted', 'copied'],
            'PERMISSION' => ['handovers.customers', 'handovers.pay', 'duty.view'],
            'SETTING' => ['handovers.pdf_needs_confirmed_company', 'handovers.invoice_terms', 'handovers.price_fallback', 'handovers.allow_overpayment',
                'handovers.price_import_max_rows', 'duty.register_lines', 'duty.unstamped_warehouses', 'duty.unstamped_zero_from', 'duty.page_rows', 'duty.export_max_rows'],
            'AUDIT_ACTION' => ['handover.create', 'handover.header', 'handover.copy', 'counterparty.add', 'counterparty.change', 'counterparty.switch_off', 'counterparty.switch_on',
                'counterparty.price_import', 'counterparty.payment', 'counterparty.payment_reverse'],
        ] as $group => $codes) {
            foreach ($codes as $code) {
                self::assertTrue(Words::has($group, $code), "{$group}: no word for {$code}");
            }
        }
        foreach (array_keys(Words::SETTING) as $key) {
            if (str_starts_with($key, 'handovers.') || str_starts_with($key, 'duty.')) {
                self::assertArrayHasKey($key, Words::SETTING_HELP, $key);
                self::assertNotSame('Other', Words::settingTopic($key), $key);
            }
        }
        foreach ([Words::HANDOVER_NOTICE, Words::HANDOVER_ERROR, Words::CUSTOMER_ERROR, Words::CUSTOMER_NOTICE, Words::BALANCE_ERROR, Words::CUSTOMER_FIELD_ERROR,
            Words::PRICE_SKIP] as $texts) {
            foreach ($texts as $code => $text) {
                self::assertMatchesRegularExpression('/^[A-Z%"].*[.:]$/s', $text, "{$code}: a full sentence (writing rule 7)");
            }
        }
        self::assertSame(['Hand-over', 'Hand-overs'], [Words::DOC_TYPE['TRD'], Words::DOC_TYPES['TRD']]);
        // The invoice prints no legal wording of its own (PH9): no "VAT invoice", no "tax point", no payment terms.
        foreach (Words::HANDOVER_PDF as $code => $text) {
            self::assertDoesNotMatchRegularExpression('/VAT invoice|tax point|payment terms|days net/i', $text, $code);
        }
    }

    public function testWhoMayDoWhatAndWhereThePagesAre(): void
    {
        self::assertSame(['purchasing_desk', 'purchasing_manager'], Permissions::MAP['doc.TRD.post']);
        self::assertSame(['purchasing_desk', 'purchasing_manager'], Permissions::MAP['handovers.customers']);
        self::assertSame(['purchasing_desk', 'purchasing_manager'], Permissions::MAP['handovers.pay']);
        self::assertSame(['goods_in', 'purchasing_desk', 'purchasing_manager', 'stock_controller', 'reviewer', 'accountant', 'auditor', 'manager'], Permissions::MAP['duty.view']);
        foreach (['handovers.customers', 'handovers.pay', 'duty.view', 'doc.TRD.post'] as $perm) {
            self::assertFalse(Permissions::can(['admin'], $perm), "{$perm}: never admin");
        }
        self::assertNotContains('trade', array_column(Permissions::COMING_LATER, 'key'), 'hand-overs are built');
        // What the app login may do to the pack's tables: a customer, a price and a hand-over's header are never deleted; what a customer
        // owes is append-only.
        self::assertSame(['Select', 'Insert'], \CW\Schema\Grants::desired('counterparty_entry'));
        foreach (['counterparty', 'counterparty_price', 'handover'] as $t) {
            self::assertSame(['Select', 'Insert', 'Update'], \CW\Schema\Grants::desired($t), $t);
        }
        // The redesign (docs/decisions.md RD1): the hand-overs are Sales › Trade sales, their customers and balances Sales › Customers.
        self::assertSame(['section' => 'sales', 'tab' => 'handovers', 'page' => 'handovers'], Sections::locate('/ui/stock/handovers/12/pdf'));
        self::assertSame(['section' => 'sales', 'tab' => 'customers', 'page' => 'handover_customers'], Sections::locate('/ui/stock/handovers/customers/12/active'));
        self::assertSame(['section' => 'sales', 'tab' => 'customers', 'page' => 'handover_balances'], Sections::locate('/ui/stock/handovers/balances/payments/12/reverse'));
        self::assertSame(['section' => 'purchases', 'tab' => 'goods_in', 'page' => 'duty'], Sections::locate('/ui/receiving/duty.csv'));
    }

    public function testTheTemplatesFollowTheScreensRules(): void
    {
        foreach (self::TEMPLATES as $name) {
            $src = (string) file_get_contents(View::defaultDir() . '/' . $name . '.php');
            preg_match_all('/<table\b([^>]*)>/i', $src, $tables, PREG_SET_ORDER);
            foreach ($tables as $t) {
                self::assertMatchesRegularExpression('/class="[^"]*\bstack\b/', $t[1], "{$name}: {$t[0]} is cards on a phone");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td} says what it is on a phone");
            }
            foreach (['(UTC)', '$dt(', '<svg', '<img', 'data:', 'style=', '<script', ' onclick'] as $old) {
                self::assertStringNotContainsString($old, $src, "{$name}: {$old}");
            }
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/', '/&#[0-9]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $m) > 0 ? $m[0] : [], "{$name}: a word typed in the template instead of taken from Words");
            preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $src, $forms, PREG_SET_ORDER);
            foreach ($forms as $f) {
                if (preg_match('/method="post"/i', $f[1]) === 1) {
                    self::assertMatchesRegularExpression('#<input type="hidden" name="csrf" value="<\?= \$e\(\$csrf\) \?>">#', $f[2], "{$name}: a POST form without the csrf field");
                }
                if (preg_match('/type="file"/i', $f[2]) === 1) {
                    self::assertMatchesRegularExpression('#enctype="multipart/form-data"#i', $f[1], "{$name}: an upload is multipart");
                }
            }
        }
    }
}
