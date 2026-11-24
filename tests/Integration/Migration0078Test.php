<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0078 (Royal Mail's bills, phase 1; docs/decisions.md DL43-DL51), applied to a scratch schema at 0077 that holds the three shops, a DPD bill
 * with a line and a day summary: it only ADDS (the new tables, their seed rows: the carriers, one party per shop and Hayati Vapes, the accounts
 * with their parties, channels and rules, Royal Mail's products and bands); the one change to an existing table is carrier_invoice's carrier
 * CHECK swapped for a link to carrier (its rows untouched); the CHECKs of the new tables; the app login's rights; every statement again changes
 * nothing.
 */
final class Migration0078Test extends IntegrationTestCase
{
    private const FILE = '0078_royal_mail_bills.sql';
    private const CHECK_VIOLATED = 3819;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m78', $this->dir);
        }
    }

    public function testItOnlyAddsSeedsTheSettingsAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        $statements = SqlSplitter::split($sql);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\s+(TABLE|COLUMN|INDEX|KEY|FOREIGN)\b/', $sql,
            'nothing deleted, changed or dropped (only the CHECK the link replaces)');
        foreach ($statements as $st) {
            self::assertMatchesRegularExpression('/^(CREATE TABLE IF NOT EXISTS (carrier|delivery_party|carrier_account|carrier_account_party|carrier_channel|carrier_party_rule|'
                . 'carrier_product|carrier_band|carrier_label|carrier_label_pull|carrier_label_pull_day|carrier_pin_due|carrier_bill_row|carrier_allocation|carrier_bill_check) |INSERT INTO (carrier|delivery_party|carrier_account|'
                . 'carrier_account_party|carrier_channel|carrier_party_rule|carrier_product|carrier_band) |SET @cw_0078_carrier = |(PREPARE|EXECUTE|DEALLOCATE PREPARE) '
                . 'cw_0078_carrier)/', $st);
        }
        self::assertSame(['Select'], Grants::desired('carrier'), 'the carriers come by migration');
        self::assertSame(['Select'], Grants::desired('carrier_product'));
        self::assertSame(['Select'], Grants::desired('carrier_band'));
        foreach (['delivery_party', 'carrier_account', 'carrier_account_party', 'carrier_channel', 'carrier_party_rule', 'carrier_label', 'carrier_label_pull'] as $t) {
            self::assertSame(['Select', 'Insert', 'Update'], Grants::desired($t), "{$t}: never deleted");
        }
        self::assertSame(['Select', 'Insert'], Grants::desired('carrier_bill_row'), 'a bill row as printed: never changed');
        self::assertSame(['Select', 'Insert'], Grants::desired('carrier_allocation'));
        self::assertSame(['ended_at'], Grants::UPDATE_COLUMNS['carrier_allocation'], 'an allocation is only ended');
        self::assertSame(['Select', 'Insert', 'Update', 'Delete'], Grants::desired('carrier_bill_check'), 'a check is taken once, and gone a day after it expires (DL79)');
        self::assertSame(['Select', 'Insert'], Grants::desired('carrier_label_pull_day'), 'a day a pull read stays read (DL80)');
        self::assertSame(['Select', 'Insert', 'Update', 'Delete'], Grants::desired('carrier_pin_due'), 'a pin due is done, then gone (DL80)');

        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m78', $files[$at - 1]);
        $vpg = $db->insert("INSERT INTO channel (code, name) VALUES ('vapeandgo', 'Vape and Go')");
        $ef = $db->insert("INSERT INTO channel (code, name) VALUES ('electrofag', 'Electrofag')");
        $vb = $db->insert("INSERT INTO channel (code, name) VALUES ('vapebig', 'Vape Big')");
        $db->exec("INSERT INTO profit_day (channel_id, day, orders, delivery_cost, delivery_other) VALUES (?, '2026-11-19', 3, 420, 0)", [$vpg]);
        $bill = $db->insert("INSERT INTO carrier_invoice (carrier, account, invoice_no, period_from, period_to, channel_id, net_pence, vat_pence, gross_pence, line_count, "
            . "file_sha256, status, uploaded_actor, uploaded_at) VALUES ('dpd', '3012002', '62368793', '2026-11-05', '2026-11-14', ?, 420, 84, 504, 1, ?, 'live', 'system:x', "
            . "'2026-11-20 10:00:00')", [$vpg, str_repeat('a', 64)]);
        $db->exec("INSERT INTO carrier_invoice_line (invoice_id, line_no, line_date, consignment, vat_code, money, cost, match_state) VALUES (?, 1, '2026-11-05', "
            . "'6599195040', 'S', '{\"Revenue\": 420}', 420, 'not_matched')", [$bill]);
        $before = [$db->all('SELECT * FROM carrier_invoice'), $db->all('SELECT * FROM carrier_invoice_line'), $db->all('SELECT * FROM profit_day')];
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $new = array_values(array_diff($db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), $tables));
        sort($new);
        self::assertSame(['carrier', 'carrier_account', 'carrier_account_party', 'carrier_allocation', 'carrier_band', 'carrier_bill_check', 'carrier_bill_row', 'carrier_channel',
            'carrier_label', 'carrier_label_pull', 'carrier_label_pull_day', 'carrier_party_rule', 'carrier_pin_due', 'carrier_product', 'delivery_party'], $new);
        self::assertSame($before, [$db->all('SELECT * FROM carrier_invoice'), $db->all('SELECT * FROM carrier_invoice_line'), $db->all('SELECT * FROM profit_day')],
            'the DPD bill, its line and the day summary as they were');
        $constraints = static fn (): array => array_map('strval', $db->column("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'carrier_invoice' AND CONSTRAINT_NAME IN ('ck_carrier_invoice_carrier', 'fk_carrier_invoice_carrier') ORDER BY CONSTRAINT_NAME"));
        self::assertSame(['fk_carrier_invoice_carrier'], $constraints(), 'the carrier CHECK became a link to carrier');
        self::assertSame(1452, self::err(static fn () => $db->exec("INSERT INTO carrier_invoice (carrier, account, invoice_no, period_from, period_to, channel_id, net_pence, "
            . "vat_pence, gross_pence, line_count, file_sha256, uploaded_actor, uploaded_at) VALUES ('evri', '1', '1', '2026-11-05', '2026-11-05', ?, 0, 0, 0, 0, ?, 'system:x', "
            . "'2026-11-20 10:00:00')", [$vpg, str_repeat('b', 64)])), 'a carrier not in the list');

        // the seeds
        self::assertSame([['dpd', 'DPD', 'per_parcel'], ['royal_mail', 'Royal Mail', 'pooled']], self::rows($db, 'SELECT code, name, family FROM carrier ORDER BY code'));
        // Electrofag's first CW order number, a setting (the coordinator, 24 Nov 2026, D2; DL78): 5457, never worked out from data
        self::assertSame([['electrofag', (string) $ef, '5457'], ['hayativapes', null, null], ['vapeandgo', (string) $vpg, null], ['vapebig', (string) $vb, null]],
            self::rows($db, 'SELECT code, channel_id, first_order_no FROM delivery_party ORDER BY code'));
        self::assertSame([
            ['dpd', '3012002', 'dpd_csv', 'connector', null, 'vapeandgo', 'active'],
            ['royal_mail', '0706703000', 'rm_oba_page', 'click_and_drop', 'click_and_drop_0706703000', 'vapeandgo', 'active'],
            ['royal_mail', 'vapebig-pending', 'rm_oba_page', 'click_and_drop', 'click_and_drop_vapebig', 'vapebig', 'off'],
        ], self::rows($db, 'SELECT a.carrier, a.account_no, a.bill_format, a.label_source, a.key_name, p.code, a.state FROM carrier_account a '
            . 'JOIN delivery_party p ON p.id = a.main_party_id ORDER BY a.carrier, a.account_no'));
        self::assertSame([['0706703000', 'electrofag'], ['0706703000', 'hayativapes'], ['0706703000', 'vapeandgo'], ['3012002', 'vapeandgo'], ['vapebig-pending', 'vapebig']],
            self::rows($db, 'SELECT a.account_no, p.code FROM carrier_account_party ap JOIN carrier_account a ON a.id = ap.account_id JOIN delivery_party p ON p.id = ap.party_id '
                . 'ORDER BY a.account_no, p.code'));
        self::assertSame([['api'], ['manual'], ['woocommerce']], self::rows($db, 'SELECT code FROM carrier_channel ORDER BY code'));
        self::assertSame([['0706703000', '1', 'manual', null, null, '0'], ['0706703000', '2', 'woocommerce', null, 'hayativapes', '0'],
            ['0706703000', '3', null, 'hash_digits', 'hayativapes', '0'], ['0706703000', '4', null, 'digits_7', 'vapeandgo', '0'],
            ['0706703000', '5', 'api', 'digits_4', 'electrofag', '1'], ['vapebig-pending', '1', null, 'digits_5', 'vapebig', '0']],
            self::rows($db, 'SELECT a.account_no, r.priority, r.channel_code, r.ref_form, p.code, r.before_first FROM carrier_party_rule r JOIN carrier_account a ON a.id = r.account_id '
                . 'LEFT JOIN delivery_party p ON p.id = r.party_id ORDER BY a.account_no, r.priority'));
        self::assertSame([['RW4', 'surcharge', null], ['RX1', 'wrong_weight', null], ['RXA', 'wrong_weight', null], ['SD1', 'band', 'SD1'], ['TPL', 'flat', 'TPL48HV'],
            ['TPM', 'flat', 'TPM24HV'], ['TRN', 'flat', 'TRN24'], ['TSS', 'return', null]],
            self::rows($db, "SELECT product_code, kind, service_code FROM carrier_product WHERE carrier = 'royal_mail' ORDER BY product_code"));
        self::assertSame(['100', '500', '1000', '2000', '10000', '20000'], array_map('strval', $db->column("SELECT max_grams FROM carrier_band WHERE product_code = 'SD1' ORDER BY band_no")));

        // the CHECKs of the new tables
        $acct = (int) $db->value("SELECT id FROM carrier_account WHERE account_no = '0706703000'");
        $label = static fn (array $over = []): int => $db->insert('INSERT INTO carrier_label (account_id, tracking_no, source, reference, channel_code, service_code, package, '
            . 'manifest_no, manifest_day, sales_order, first_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_values(array_replace(['a' => $acct,
                't' => 'JV000000001GB', 's' => 'api', 'ref' => '1360001', 'ch' => 'api', 'svc' => 'TPL48HV', 'pkg' => 'parcel', 'm' => 2288, 'd' => '2026-11-09', 'so' => null,
                'f' => '2026-11-20 10:00:00', 'u' => '2026-11-20 10:00:00'], $over)));
        $label();
        foreach ([['t' => 'JV000000002GB', 'ref' => 'Mr Test'], ['t' => 'jv000000003gb'], ['t' => 'JV000000004GB', 'pkg' => 'box'], ['t' => 'JV000000005GB', 'so' => 'uuid-1'],
            ['t' => 'JV000000006GB', 'd' => null], ['t' => 'JV000000007GB', 'ch' => 'Vape Go API']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $label($bad)), json_encode($bad, JSON_THROW_ON_ERROR));
        }
        self::assertSame(1062, self::err(static fn () => $label()), 'one row per account and tracking number');
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $db->exec("INSERT INTO carrier_party_rule (account_id, priority, ref_form) VALUES (?, 9, 'digits')",
            [$acct])));
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $db->exec("INSERT INTO carrier_channel (account_id, code, name) VALUES (?, 'other', 'x')", [$acct])),
            '"other" is what a label keeps for a channel the account does not know');
        // a before-start rule names a party and a number of digits (DL78); a first order number is a shop's
        $efParty = (int) $db->value("SELECT id FROM delivery_party WHERE code = 'electrofag'");
        foreach ([[null, $efParty, 1], ['hash_digits', $efParty, 1], ['digits_4', null, 1], ['digits_4', $efParty, 2]] as [$form, $party, $flag]) {
            self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $db->exec('INSERT INTO carrier_party_rule (account_id, priority, channel_code, ref_form, party_id, '
                . "before_first) VALUES (?, 9, 'api', ?, ?, ?)", [$acct, $form, $party, $flag])), json_encode([$form, $party, $flag], JSON_THROW_ON_ERROR));
        }
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $db->exec("INSERT INTO delivery_party (code, name, first_order_no) VALUES ('noshop', 'No shop', 10)")),
            'a first CW order number is a shop\'s');
        // a bill checked on the screen keeps the bill as read and the sha256s, never the file (DL79)
        $user = $db->insert("INSERT INTO staff_user (username, display_name, password_hash) VALUES ('m78', 'M 78', 'x')");
        $check = static fn (array $over = []): int => $db->insert('INSERT INTO carrier_bill_check (token_sha256, staff_user_id, account_id, file_sha256, bill_sha256, plan_sha256, '
            . 'bill, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', array_values(array_replace(['t' => str_repeat('c', 64), 'u' => $user, 'a' => $acct,
                'f' => str_repeat('d', 64), 'b' => str_repeat('e', 64), 'p' => str_repeat('9', 64), 'j' => '{"rows": []}', 'c' => '2026-11-24 10:00:00',
                'x' => '2026-11-24 10:20:00'], $over)));
        $check();
        foreach ([['t' => str_repeat('C', 64)], ['t' => str_repeat('f', 64), 'j' => 'not json'], ['t' => str_repeat('a', 64), 'x' => '2026-11-24 09:00:00'],
            ['t' => str_repeat('b', 64), 'f' => 'x']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $check($bad)), json_encode($bad, JSON_THROW_ON_ERROR));
        }
        self::assertSame(1062, self::err(static fn () => $check()), 'a token is one check\'s');

        // The Codex check r1, MEDIUM 14: Vape Big's account renumbered (bin/carrier_account.php), then every statement again: no second account.
        $db->exec("UPDATE carrier_account SET account_no = '0706799999' WHERE account_no = 'vapebig-pending'");
        // Every statement again: nothing changes.
        $dump = static fn (): string => (string) json_encode(array_map(static fn (string $t): array => $db->all("SELECT * FROM {$t}"), ['carrier', 'delivery_party',
            'carrier_account', 'carrier_account_party', 'carrier_channel', 'carrier_party_rule', 'carrier_product', 'carrier_band', 'carrier_invoice']));
        $was = $dump();
        foreach ($statements as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame($was, $dump());
        self::assertSame(['fk_carrier_invoice_carrier'], $constraints());
    }

    /** @return list<list<?string>> */
    private static function rows(Db $db, string $sql): array
    {
        return array_map(static fn (array $r): array => array_map(static fn ($v): ?string => $v === null ? null : (string) $v, array_values($r)), $db->all($sql));
    }

    private static function err(callable $fn): int
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            return (int) Db::driverCode($e);
        }
        self::fail('expected a MySQL error');
    }
}
