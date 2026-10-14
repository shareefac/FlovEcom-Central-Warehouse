<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ApprovalRules;
use CW\Admin\ConfigInvariants;
use CW\Admin\DocumentRules;
use CW\Schema\Grants;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;

/**
 * 0032 (pack C, plan IM7; docs/decisions.md PC1-PC18) on the migrated test schema: the supplier's credit note as a kind of record with its
 * number series and history; the invoice and the return without a reviewer check (version 2 of each, with the row before it); the new
 * OK first for an invoice that does not match (an approval rule of its own, off); the seven settings with their baselines and rules;
 * the tables, their checks and their grants; the configuration checks K1-K4 hold.
 */
final class Migration0032Test extends IntegrationTestCase
{
    private const CHECK = 3819;

    public function testTheKindsOfRecordAndTheirHistory(): void
    {
        $t = array_column(self::$db->all('SELECT code, prefix, name, review_rule, review_limit_units, approval_rule, approval_limit_units, size_approval, size_units, size_value, '
            . "reject_action FROM document_type WHERE code IN ('SINV', 'DN', 'SCN') ORDER BY code"), null, 'code');
        self::assertSame(['DN', 'none', 'none', 0, 100, 500], [$t['DN']['code'], $t['DN']['review_rule'], $t['DN']['approval_rule'], (int) $t['DN']['size_approval'],
            (int) $t['DN']['size_units'], (int) $t['DN']['size_value']]);
        self::assertSame(['SCN', 'Supplier credit note', 'none', 'none', 0, null, 1000, 'reverse'], [$t['SCN']['prefix'], $t['SCN']['name'], $t['SCN']['review_rule'],
            $t['SCN']['approval_rule'], (int) $t['SCN']['size_approval'], $t['SCN']['size_units'], (int) $t['SCN']['size_value'], $t['SCN']['reject_action']]);
        self::assertSame(['none', 'none', 0, 0, null, 5000], [$t['SINV']['review_rule'], $t['SINV']['approval_rule'], (int) $t['SINV']['approval_limit_units'],
            (int) $t['SINV']['size_approval'], $t['SINV']['size_units'], (int) $t['SINV']['size_value']], 'every rule off by default (the owner\'s rule)');
        self::assertSame(['last_no' => 0, 'pad' => 6], array_map('intval', (array) self::$db->one("SELECT last_no, pad FROM number_series WHERE prefix = 'SCN'")));
        self::assertSame("enum('none','positive_without_supplier_doc','over_value','over_difference')", (string) self::$db->value('SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_type' AND COLUMN_NAME = 'approval_rule'"));
        // History: SCN's baseline; SINV and DN their version 2, the row before it kept.
        self::assertSame([['SCN', 1, 'baseline']], array_map(static fn (array $r): array => [$r['subject_key'], (int) $r['version'], $r['action']],
            self::$db->all("SELECT subject_key, version, action FROM config_change WHERE subject_type = 'document_rule' AND subject_key = 'SCN'")));
        foreach (['SINV', 'DN'] as $code) {
            $v2 = self::$db->one("SELECT action, actor, reason, CAST(state AS CHAR) AS s, CAST(before_state AS CHAR) AS b FROM config_change WHERE subject_type = 'document_rule' "
                . 'AND subject_key = ? AND version = 2', [$code]);
            self::assertNotNull($v2, $code);
            self::assertSame(['change', 'system:migrate'], [$v2['action'], $v2['actor']]);
            self::assertSame(['all', 'none'], [json_decode((string) $v2['b'], true)['review_rule'], json_decode((string) $v2['s'], true)['review_rule']], $code);
            self::assertGreaterThan(3, mb_strlen((string) $v2['reason']));
        }
        self::assertSame('over_difference', DocumentRules::APPROVAL_KIND['SINV']);
        self::assertContains('SCN', DocumentRules::SIZE_TYPES);
        self::assertContains(['document', 'SCN'], ApprovalRules::PAGE['records']);
        self::assertSame([['switch', 'approvals.invoice_accept_other']], ApprovalRules::PAGE['invoices']);
    }

    public function testTheSettings(): void
    {
        $s = [];
        foreach (self::$db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key LIKE 'invoices.%' "
            . "OR setting_key = 'approvals.invoice_accept_other' ORDER BY setting_key") as $r) {
            $s[(string) $r['setting_key']] = [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision']];
        }
        self::assertSame([
            'approvals.invoice_accept_other' => ['bool', 'false', 1, 'PC6'],
            'invoices.duty_spread' => ['string', '"ml"', 1, 'PC9'],
            'invoices.freight_spread' => ['string', '"value"', 1, 'PC9'],
            'invoices.price_tolerance_gbp' => ['decimal', '"5.00"', 1, 'PC3'],
            'invoices.price_tolerance_pct' => ['decimal', '"2"', 1, 'PC3'],
            'invoices.qty_tolerance_pct' => ['decimal', '"0"', 1, 'PC3'],
            'invoices.total_tolerance_per_line' => ['decimal', '"0.05"', 1, 'PC3'],
        ], $s, 'the plan\'s IM7 numbers: price 2 % or £5 a line, the total 5p a line; the quantity exact; every one provisional');
        $settings = new Settings(self::$db);
        self::assertSame(['2', '5.00', '0', '0.05', 'value', 'ml', false], [$settings->get('invoices.price_tolerance_pct'), $settings->get('invoices.price_tolerance_gbp'),
            $settings->get('invoices.qty_tolerance_pct'), $settings->get('invoices.total_tolerance_per_line'), $settings->get('invoices.freight_spread'),
            $settings->get('invoices.duty_spread'), $settings->get('approvals.invoice_accept_other')]);
        self::assertSame(['in' => ['value', 'ml']], Settings::RULES['invoices.freight_spread']);
        self::assertFalse(ApprovalRules::on(self::$db, 'approvals.invoice_accept_other'));
        self::assertSame(7, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND action = 'baseline' AND version = 1 AND "
            . "(subject_key LIKE 'invoices.%' OR subject_key = 'approvals.invoice_accept_other')"));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: every new configuration row has its history');
    }

    public function testTheTablesTheirChecksAndGrants(): void
    {
        foreach (['supplier_invoice', 'supplier_return', 'grn_invoice_left'] as $t) {
            self::assertSame(['Select', 'Insert', 'Update'], Grants::desired($t), "{$t}: never deleted");
        }
        foreach (['sinv_line', 'dn_line'] as $t) {
            self::assertSame(Grants::FULL, Grants::desired($t), "{$t}: a draft's lines are replaced");
        }
        foreach (['purchase_doc_posting', 'invoice_value_event'] as $t) {
            self::assertSame(['Select', 'Insert'], Grants::desired($t), "{$t}: append-only");
            self::assertSame([], Grants::desiredColumns($t), $t);
        }
        $sup = self::$db->insert("INSERT INTO supplier (code, name, created_actor, updated_actor) VALUES ('M32', 'Migration 32 Ltd', 'system:test', 'system:test')");
        $doc = self::$db->insert("INSERT INTO document (doc_type, created_actor) VALUES ('SINV', 'test')");
        $err = static function (string $sql, array $params): ?int {
            try {
                self::$db->exec($sql, $params);
            } catch (\PDOException $e) {
                return \CW\Db::driverCode($e);
            }
            return null;
        };
        $insert = 'INSERT INTO supplier_invoice (document_id, kind, supplier_id, invoice_key, net_total, vat_total, gross_total) VALUES (?, ?, ?, ?, ?, ?, ?)';
        self::assertSame(self::CHECK, $err($insert, [$doc, 'invoice', $sup, 'INV-1', '10.00', '2.00', '12.01']), 'the total to pay is net + VAT');
        self::assertSame(self::CHECK, $err($insert, [$doc, 'invoice', $sup, 'INV 1', null, null, null]), 'a key has no spaces');
        self::assertNull($err($insert, [$doc, 'invoice', $sup, 'INV-1', '10.00', '2.00', '12.00']));
        self::assertSame(self::CHECK, $err("UPDATE supplier_invoice SET match_state = 'accepted', match_lines_off = 1, match_difference = 1, match_rules = '{}', "
            . 'match_checked_at = NOW(6) WHERE document_id = ?', [$doc]), 'accepted needs the acceptance');
        self::$db->exec("INSERT INTO document_line (document_id, line_no, sku_id, qty, amount) VALUES (?, 1, NULL, NULL, '5.00')", [$doc]);
        self::assertSame(self::CHECK, $err("INSERT INTO sinv_line (document_id, line_no, kind, packs, pack_price) VALUES (?, 1, 'freight', 1, '5.0000')", [$doc]),
            'a charge has no packs');
        self::assertNull($err("INSERT INTO sinv_line (document_id, line_no, kind) VALUES (?, 1, 'freight')", [$doc]));
        self::$db->exec('DELETE FROM document_line WHERE document_id = ?', [$doc]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sinv_line WHERE document_id = ?', [$doc]), 'a draft line takes its extension with it');
    }
}
