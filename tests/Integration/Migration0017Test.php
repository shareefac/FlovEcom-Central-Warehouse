<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;

/**
 * 0017 (IM6; docs/decisions.md I127-I138): the receipt's tables hold their rules in SQL too (the CHECKs refuse what
 * CW\Receiving never writes), one live receipt per supplier and invoice key, the four receiving settings, and the file applies
 * again without an error (each statement finds its work done).
 */
final class Migration0017Test extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;
    private const DUPLICATE = 1062;

    public function testTheSettings(): void
    {
        $rows = [];
        foreach (self::$db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key LIKE 'receiving.%' "
            . 'ORDER BY setting_key') as $r) {
            $rows[(string) $r['setting_key']] = [(string) $r['value_type'], (string) $r['v'], (int) $r['provisional'], $r['decision']];
        }
        self::assertSame([
            'receiving.backdate_max_days' => ['int', '30', 1, '11'],
            'receiving.duty_pence_per_ml' => ['int', '22', 0, null],
            'receiving.mode_after_out_of_stock' => ['string', '"From-Warehouse"', 1, null],
            'receiving.unstamped_refusal_from' => ['date', '"2027-01-01"', 0, '8'],
        ], $rows);
    }

    public function testTheReceiptChecks(): void
    {
        $supplier = self::$db->insert("INSERT INTO supplier (code, name, created_actor, updated_actor) VALUES ('MIG17', 'Migration 17', 'x', 'x')");
        $doc = static fn (): int => self::$db->insert("INSERT INTO document (doc_type, created_actor) VALUES ('GRN', 'system:test')");
        $gr = static fn (array $over): int => self::$db->insert('INSERT INTO goods_receipt (' . implode(', ', array_keys($over)) . ') VALUES ('
            . implode(', ', array_fill(0, count($over), '?')) . ')', array_values($over));
        foreach ([['invoice_key' => 'INV 1'], ['invoice_key' => ''], ['paper_sheet' => 1], ['paperwork_ok' => 2], ['checked_at' => '2026-10-07 10:00:00', 'checked_actor' => 'x'],
            ['checked_by' => null, 'checked_at' => '2026-10-07 10:00:00']] as $bad) {
            $d = $doc();
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $gr($bad + ['document_id' => $d, 'supplier_id' => $supplier, 'received_at' => '2026-10-07 10:00:00'])),
                json_encode($bad));
        }
        $a = $doc();
        $gr(['document_id' => $a, 'supplier_id' => $supplier, 'received_at' => '2026-10-07 10:00:00', 'invoice_key' => 'INV-1']);
        $b = $doc();
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $gr(['document_id' => $b, 'supplier_id' => $supplier, 'received_at' => '2026-10-07 10:00:00',
            'invoice_key' => 'inv-1'])), 'one live receipt per supplier and invoice (any case)');
        $gr(['document_id' => $b, 'supplier_id' => $supplier, 'received_at' => '2026-10-07 10:00:00', 'invoice_key' => null]);
        $c = $doc();
        $gr(['document_id' => $c, 'supplier_id' => $supplier, 'received_at' => '2026-10-07 10:00:00', 'invoice_key' => null]);

        // Lines: units = packs x units per pack hold the exceptions; unstamped units say what happens to them; the posted split is whole.
        $sku = self::makeSku('Migration line');
        self::$db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty) VALUES (?, 1, ?, 10)', [$a, $sku]);
        $line = static fn (array $over): mixed => self::$db->exec('INSERT INTO grn_line (' . implode(', ', array_keys($over)) . ') VALUES ('
            . implode(', ', array_fill(0, count($over), '?')) . ')', array_values($over));
        $base = ['document_id' => $a, 'line_no' => 1, 'packs' => 2, 'units_per_pack' => 5, 'pack_price' => '1.0000'];
        foreach ([['short_units' => 6, 'damaged_units' => 5], ['unstamped_units' => 1], ['unstamped_units' => 1, 'unstamped_action' => 'accept_pre_october'],
            ['unstamped_action' => 'refuse'], ['stamp_type' => 'digital'], ['stamp_on_pack' => 0, 'stamp_type' => 'digital'], ['packs' => 0], ['units_per_pack' => 100001],
            ['pack_price' => '-1.0000'], ['selling_mode' => 'In-Stock'], ['accepted_units' => 10, 'verify_units' => 0, 'quarantine_units' => 0, 'refused_units' => 0,
                'stamp_required' => 0, 'selling_mode' => 'In-Stock', 'mode_source' => 'last', 'po_units' => 11]] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $line($bad + $base)), json_encode($bad));
        }
        $line(['unstamped_units' => 2, 'unstamped_action' => 'accept_pre_october', 'pre_october_evidence' => 'certificate 1', 'over_units' => 3] + $base);
        self::$db->exec('DELETE FROM grn_line');
        self::$db->exec('DELETE FROM document_line');

        // The incident register: closed means a resolution and a time; a booked disposition names its warehouse.
        $inc = static fn (array $over): mixed => self::$db->exec('INSERT INTO incident (' . implode(', ', array_keys($over)) . ') VALUES ('
            . implode(', ', array_fill(0, count($over), '?')) . ')', array_values($over));
        $ok = ['kind' => 'damaged', 'disposition' => 'not_received', 'document_id' => $a, 'line_no' => 1, 'sku_id' => $sku, 'units' => 1, 'opened_actor' => 'x', 'dedupe_key' => 'k'];
        foreach ([['units' => 0], ['status' => 'resolved'], ['status' => 'resolved', 'resolved_at' => '2026-10-07 10:00:00', 'resolved_actor' => 'x'],
            ['disposition' => 'verify'], ['warehouse_id' => self::warehouseId('VERIFY')]] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $inc($bad + $ok)), json_encode($bad));
        }
        $inc($ok);
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $inc($ok)), 'one incident per line and kind (dedupe_key)');

        // The selling mode: a previous mode only while Out-Of-Stock.
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => self::$db->exec(
            "INSERT INTO item_selling_mode (sku_id, mode, previous_mode, updated_actor) VALUES (?, 'In-Stock', 'From-Warehouse', 'x')", [$sku])));
        self::$db->exec("INSERT INTO item_selling_mode (sku_id, mode, previous_mode, updated_actor) VALUES (?, 'Out-Of-Stock', 'In-Stock', 'x')", [$sku]);
        self::$db->exec('DELETE FROM item_selling_mode');
        self::$db->exec('DELETE FROM incident');
        self::$db->exec('DELETE FROM goods_receipt');
        self::$db->exec("DELETE FROM document WHERE doc_type = 'GRN'");
        self::$db->exec('DELETE FROM supplier');
    }

    public function testTheFileAppliesAgain(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0017_receiving.sql');
        foreach (SqlSplitter::split($sql) as $statement) {
            self::$db->pdo()->exec($statement);
        }
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM app_setting WHERE setting_key LIKE 'receiving.%'"));
    }
}
