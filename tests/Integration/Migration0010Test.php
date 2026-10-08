<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\IntegrationTestCase;

/**
 * 0010 (I48-I59): the PO document type's rules (review every PO within 7 days, a blocking approval above £10,000 net,
 * a rejected review recorded, not reversed: provisional decision 11), the other types keep `reverse`, the approval_rule
 * enum gains over_value, the three reversal reasons, the po.* settings, the tables and their CHECKs, and the cascade of a
 * draft's po_line with its document_line. No backfill: tested on the slot's schema.
 */
final class Migration0010Test extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;
    private const FK = 1452;

    public function testTheDocumentTypeRules(): void
    {
        $by = array_column(self::$db->all('SELECT code, review_rule, review_limit_units, approval_rule, approval_limit_units, review_due_days, reject_action '
            . 'FROM document_type ORDER BY code'), null, 'code');
        self::assertSame(['all', null, 'over_value', 10000, 7, 'record'], array_values(array_diff_key($by['PO'], ['code' => 1])));
        foreach (['ADJ', 'CNT', 'DN', 'GRN', 'SINV', 'TRD', 'WO'] as $code) {
            self::assertSame('reverse', $by[$code]['reject_action'], "{$code}: a rejected review still reverses (I19)");
        }
        self::assertSame(['positive_without_supplier_doc', 10], [$by['ADJ']['approval_rule'], $by['ADJ']['approval_limit_units']], 'ADJ unchanged');
        $enum = (string) self::$db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_type' "
            . "AND COLUMN_NAME = 'approval_rule'");
        self::assertSame("enum('none','positive_without_supplier_doc','over_value')", $enum);
        self::assertSame("enum('reverse','record')", (string) self::$db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'document_type' AND COLUMN_NAME = 'reject_action'"));
        self::assertSame('review_due_days', self::$db->value("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'document_type' AND ORDINAL_POSITION = (SELECT ORDINAL_POSITION - 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'document_type' AND COLUMN_NAME = 'reject_action')"), 'reject_action follows review_due_days');
    }

    public function testTheReasonsAndSettings(): void
    {
        // 0019 (Y51) adds where the order screens offer them.
        self::assertSame([['po_amended', 'reversal,po_amend', 0, 0], ['supplier_cannot_supply', 'reversal,po_cancel,po_draft_cancel,po_amend', 0, 0],
            ['not_needed', 'reversal,po_cancel,po_draft_cancel', 0, 0]],
            array_map(static fn (array $r): array => [(string) $r['code'], (string) $r['applies_to'], (int) $r['needs_note'], (int) $r['system_only']],
                self::$db->all("SELECT code, applies_to, needs_note, system_only FROM reason_code WHERE code IN ('po_amended', 'supplier_cannot_supply', 'not_needed') "
                    . 'ORDER BY sort_order')));
        self::assertSame(['entered_in_error', 'po_amended', 'supplier_cannot_supply', 'not_needed', 'duplicate', 'review_rejected', 'other'],
            array_map('strval', self::$db->column("SELECT code FROM reason_code WHERE FIND_IN_SET('reversal', applies_to) > 0 ORDER BY sort_order")));
        $s = array_column(self::$db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting "
            . "WHERE setting_key LIKE 'po.%' ORDER BY setting_key"), null, 'setting_key');
        self::assertSame(['po.default_vat_code', 'po.over_delivery_tolerance_pct', 'po.terms'], array_keys($s));
        self::assertSame(['string', '"S"', 1, null], [$s['po.default_vat_code']['value_type'], $s['po.default_vat_code']['v'], (int) $s['po.default_vat_code']['provisional'],
            $s['po.default_vat_code']['decision']]);
        self::assertSame(['int', '10', 1, '11'], [$s['po.over_delivery_tolerance_pct']['value_type'], $s['po.over_delivery_tolerance_pct']['v'],
            (int) $s['po.over_delivery_tolerance_pct']['provisional'], $s['po.over_delivery_tolerance_pct']['decision']]);
        self::assertSame('text', $s['po.terms']['value_type']);
        self::assertStringContainsString('UK duty stamp', $s['po.terms']['v']);
    }

    public function testTheTablesAndChecks(): void
    {
        $tables = array_map('strval', self::$db->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME IN ('purchase_order', 'po_line', 'po_posting') AND ENGINE = 'InnoDB' AND TABLE_COLLATION = 'utf8mb4_0900_ai_ci' ORDER BY TABLE_NAME"));
        self::assertSame(['po_line', 'po_posting', 'purchase_order'], $tables);
        foreach (self::$db->all("SELECT TABLE_NAME, COLUMN_NAME, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME IN ('purchase_order', 'po_line', 'po_posting') AND DATA_TYPE = 'datetime'") as $c) {
            self::assertSame(6, (int) $c['DATETIME_PRECISION'], "{$c['TABLE_NAME']}.{$c['COLUMN_NAME']}");
        }
        self::assertSame('CASCADE', self::$db->value("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() "
            . "AND CONSTRAINT_NAME = 'fk_po_line_line'"));

        $staff = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m10', 'M10', 'm10@test.example', 'x')");
        $supplier = self::$db->insert("INSERT INTO supplier (code, name, created_actor, updated_actor) VALUES ('M10', 'M10 Ltd', 'system:test', 'system:test')");
        $doc = static fn (): int => self::$db->insert("INSERT INTO document (doc_type, created_actor) VALUES ('PO', 'system:test')");
        $po = static fn (int $d, array $over = []): int => self::$db->exec(
            'INSERT INTO purchase_order (document_id, supplier_id, state, currency, net_total, vat_total, gross_total, company_snapshot, supplier_snapshot, sent_at, '
            . 'sent_via, closed_at, close_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d, $supplier, $over['state'] ?? null, $over['currency'] ?? 'GBP', $over['net'] ?? '0', $over['vat'] ?? '0', $over['gross'] ?? '0',
                $over['company'] ?? null, $over['supplier'] ?? null, $over['sent_at'] ?? null, $over['sent_via'] ?? null, $over['closed_at'] ?? null,
                $over['close_reason'] ?? null]);
        $snap = ['company' => '{}', 'supplier' => '{}'];
        foreach ([
            ['currency' => 'EUR'],
            ['net' => '-1', 'gross' => '-1'],
            ['net' => '10', 'vat' => '2', 'gross' => '11'],
            ['state' => 'approved'],
            ['state' => 'approved', 'company' => '{}'],
            ['sent_at' => '2026-10-02 10:00:00'],
            ['sent_via' => 'email'],
            ['state' => 'sent'] + $snap,
            ['state' => 'closed'] + $snap,
            ['state' => 'closed', 'closed_at' => '2026-10-02 10:00:00'] + $snap,
            ['closed_at' => '2026-10-02 10:00:00', 'close_reason' => 'x'],
        ] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $po($doc(), $bad)), json_encode($bad));
        }
        foreach ([
            [],
            ['net' => '10.00', 'vat' => '2.00', 'gross' => '12.00'],
            ['state' => 'approved'] + $snap,
            ['state' => 'sent', 'sent_at' => '2026-10-02 10:00:00', 'sent_via' => 'email'] + $snap,
            ['state' => 'closed', 'closed_at' => '2026-10-02 10:00:00', 'close_reason' => 'rest not expected'] + $snap,
        ] as $good) {
            self::assertSame(1, $po($doc(), $good), json_encode($good));
        }

        // po_line: the extension of a document line, removed with it; charges carry no item, no pack, no receipts.
        $d = $doc();
        $po($d);
        $sku = self::makeSku('M10 item');
        self::$db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty, unit_cost, amount) VALUES (?, 1, ?, 24, 0.5, 12), (?, 2, NULL, NULL, NULL, 5)',
            [$d, $sku, $d]);
        $line = static fn (int $no, array $over = []): int => self::$db->exec(
            'INSERT INTO po_line (document_id, line_no, kind, supplier_item_id, units_per_pack, packs, pack_price, vat_code, vat_rate, received_units) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d, $no, $over['kind'] ?? 'item', $over['supplier_item_id'] ?? null, $over['upp'] ?? 1, $over['packs'] ?? 1, $over['price'] ?? '1.0000',
                $over['vat'] ?? 'S', $over['rate'] ?? '20.00', $over['received'] ?? 0]);
        foreach ([['upp' => 0], ['upp' => 100_001], ['packs' => 0], ['packs' => 1_000_001], ['price' => '-0.0001'], ['received' => -1],
            ['kind' => 'charge', 'upp' => 2], ['kind' => 'charge', 'packs' => 2], ['kind' => 'charge', 'received' => 1]] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $line(1, $bad)), json_encode($bad));
        }
        self::assertSame(self::FK, self::mysqlError(static fn () => $line(1, ['vat' => 'XX'])));
        self::assertSame(self::FK, self::mysqlError(static fn () => $line(3)), 'a po_line needs its document line');
        $line(1, ['upp' => 24, 'packs' => 1, 'price' => '12.0000']);
        $line(2, ['kind' => 'charge', 'price' => '5.0000']);
        self::$db->exec('DELETE FROM document_line WHERE document_id = ?', [$d]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM po_line WHERE document_id = ?', [$d]), 'the cascade removes a draft\'s po_line rows');

        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => self::$db->exec("INSERT INTO po_posting (document_id, content_hash, content) VALUES (?, ?, '{}')",
            [$d, str_repeat('A', 64)])));
        self::assertSame(1, self::$db->exec("INSERT INTO po_posting (document_id, content_hash, content) VALUES (?, ?, '{}')", [$d, hash('sha256', '{}')]));
        self::assertGreaterThan(0, $staff);
    }
}
