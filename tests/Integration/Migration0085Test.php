<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0085 (CW-1160, Parcel2Go; docs/decisions.md PGL1-PGL12), applied to a scratch schema at 0084 holding the three shops' parties, DPD's and Royal
 * Mail's accounts: the carrier family per_charge and the carrier parcel2go, the account CHECK widened, the two Parcel2Go accounts OFF serving
 * the three shops, five new empty tables, four settings with their baselines; no existing row changes; every statement again changes nothing.
 */
final class Migration0085Test extends IntegrationTestCase
{
    private const FILE = '0085_parcel2go.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m85', $this->dir);
        }
    }

    public function testItAddsTheAccountsOffAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\s+(TABLE|COLUMN|INDEX)\b/i', $sql, 'nothing is deleted, changed or dropped');
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m85', $files[$at - 1]);
        foreach (['vapeandgo' => 'Vape and Go', 'vapebig' => 'Vape Big', 'electrofag' => 'Electrofag'] as $code => $name) {
            $ch = $db->insert("INSERT INTO channel (code, name, mode) VALUES (?, ?, 'live')", [$code, $name]);
            $db->exec('INSERT INTO delivery_party (code, name, channel_id) VALUES (?, ?, ?)', [$code, $name, $ch]);
        }
        $vpg = (int) $db->value("SELECT id FROM delivery_party WHERE code = 'vapeandgo'");
        $db->exec("INSERT INTO carrier_account (carrier, account_no, name, bill_format, label_source, main_party_id, state) VALUES ('dpd', '3012002', 'DPD', 'dpd_csv', 'connector', ?, 'active')",
            [$vpg]);
        $before = $db->all('SELECT * FROM carrier_account ORDER BY id');
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame($before[0], $db->one('SELECT * FROM carrier_account WHERE id = ?', [(int) $before[0]['id']]), 'DPD\'s account as it was');
        self::assertSame('per_charge', (string) $db->value("SELECT family FROM carrier WHERE code = 'parcel2go'"));
        $acc = $db->all("SELECT a.account_no, a.key_name, a.state, a.bill_format, a.label_source, p.code AS main FROM carrier_account a JOIN delivery_party p ON p.id = a.main_party_id "
            . "WHERE a.carrier = 'parcel2go' ORDER BY a.account_no");
        self::assertSame([['p2g-vapeandgo', 'p2g_vapeandgo', 'off', 'p2g_api', 'p2g_api', 'vapeandgo'], ['p2g-vapebig', 'p2g_vapebig', 'off', 'p2g_api', 'p2g_api', 'vapebig']],
            array_map(static fn (array $r): array => array_values(array_map('strval', $r)), $acc), 'both accounts OFF as installed');
        self::assertSame(['electrofag', 'vapeandgo', 'vapebig'], array_map('strval', $db->column("SELECT DISTINCT p.code FROM carrier_account_party ap JOIN carrier_account a "
            . "ON a.id = ap.account_id JOIN delivery_party p ON p.id = ap.party_id WHERE a.carrier = 'parcel2go' ORDER BY p.code")));
        foreach (['p2g_run', 'p2g_line', 'p2g_parcel', 'p2g_charge', 'p2g_import'] as $t) {
            self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM {$t}"), "{$t} is new and empty");
        }
        self::assertSame(['Select', 'Insert'], Grants::desired('p2g_charge'), 'a bill row\'s label is written once');
        self::assertSame(['Select', 'Insert', 'Update'], Grants::desired('p2g_line'), 'a label is never deleted');
        $s = new Settings($db);
        self::assertSame(['', '', 3, 6], [$s->get('delivery.p2g_from_vapeandgo'), $s->get('delivery.p2g_from_vapebig'), $s->get('delivery.p2g_tracking_wait_days'),
            $s->get('delivery.p2g_price_wait_hours')]);
        self::assertSame([], ConfigInvariants::check($db), 'the new settings have their history');
        // a priced label must name its bill; a top-up is never priced (the table's own checks)
        $a = (int) $db->value("SELECT id FROM carrier_account WHERE key_name = 'p2g_vapeandgo'");
        self::assertSame(3819, self::mysqlError(fn () => $db->exec("INSERT INTO p2g_line (account_id, order_line_id, source, kind, price_state, first_at, updated_at) "
            . "VALUES (?, 150000001, 'api', 'parcel', 'priced', NOW(6), NOW(6))", [$a])));
        self::assertSame(3819, self::mysqlError(fn () => $db->exec("INSERT INTO p2g_line (account_id, order_line_id, source, kind, paid_at, paid_day, price_state, first_at, "
            . "updated_at) VALUES (?, 150000002, 'api', 'topup', NOW(6), CURDATE(), 'due', NOW(6), NOW(6))", [$a])));
        $cols = $db->column("SELECT CONCAT(table_name, '.', column_name, ':', column_type) FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY 1");
        $rows = [$db->all('SELECT * FROM carrier_account ORDER BY id'), $db->all('SELECT * FROM carrier_account_party ORDER BY account_id, party_id'), $db->all('SELECT * FROM carrier ORDER BY code')];
        foreach (SqlSplitter::split($sql) as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame($cols, $db->column("SELECT CONCAT(table_name, '.', column_name, ':', column_type) FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY 1"));
        self::assertSame($rows, [$db->all('SELECT * FROM carrier_account ORDER BY id'), $db->all('SELECT * FROM carrier_account_party ORDER BY account_id, party_id'),
            $db->all('SELECT * FROM carrier ORDER BY code')], 'every statement again changes nothing');
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'delivery.p2g_price_wait_hours'"));
    }
}
