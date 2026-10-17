<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ops;

use CW\Ops\RollbackExport;
use CW\Output\CsvWriter;
use CW\Tests\Integration\PurchaseOrders\PurchaseOrderTestCase;

/**
 * Pack RB (docs/decisions.md RB7): the lists the ERP way back needs besides the stock: the purchase orders made since the cut-off with
 * what is still to come, and the suppliers, customers and items made since, so ERPNext can be given them before the entries that name them.
 */
final class RollbackListsTest extends PurchaseOrderTestCase
{
    public function testTheOrdersSuppliersCustomersAndItemsMadeSinceTheCutOffAreListed(): void
    {
        $buyer = $this->staffUser('buyer');
        $this->approvedPo($buyer, 1, '4.0000', 1);
        self::$db->exec("INSERT INTO counterparty (kind, name, address, created_actor) VALUES ('trade', 'Before Vapes', '', 'staff:1')");
        self::$db->exec('DO SLEEP(0.01)');
        $since = (string) self::$db->value('SELECT NOW(6)');
        self::$db->exec('DO SLEEP(0.01)');
        ['doc' => $po, 'supplier' => $s, 'sku' => $sku] = $this->approvedPo($buyer, 2, '12.5000', 6);
        self::$db->exec('INSERT INTO sku_erp_item (item_code, sku_id, units_per_item) VALUES (?, ?, 6)', ['Case of six', $sku]);
        self::$db->exec("INSERT INTO counterparty (kind, name, address, created_actor) VALUES ('trade', 'Leeds Vapes', ?, 'staff:1')", ["2 Trade Street\nLeeds"]);

        $files = RollbackExport::run(self::$db, null, $since)['files'];
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $vat = (string) self::$db->value('SELECT vat_code FROM po_line WHERE document_id = ?', [$po->id]);
        self::assertSame([[$po->number, 'approved', (string) $s['code'], (string) $s['name'], '1', $code, 'Case of six', '6', '12', '0', '12', '12.5', $vat]],
            array_map(static fn (array $r): array => [$r['Order'], $r['State'], $r['Supplier'], $r['Supplier name'], $r['Line'], $r['Item'], $r['ERPNext item'],
                $r['Units per ERPNext item'], $r['Ordered units'], $r['Received units'], $r['Still to come'], $r['Pack price (GBP)'], $r['VAT code']],
                self::csv($files['purchase_orders.csv'])), 'two packs of six still to come; the order made before the cut-off is ERPNext\'s');
        self::assertSame([(string) $s['code']], array_column(self::csv($files['suppliers.csv']), 'Supplier'));
        self::assertSame([['Leeds Vapes', 'trade', "2 Trade Street\nLeeds"]],
            array_map(static fn (array $r): array => [$r['Name'], $r['Kind'], $r['Address']], self::csv($files['customers.csv'])));
        self::assertSame([[$code, 'Case of six (6)']], array_map(static fn (array $r): array => [$r['Item'], $r['ERPNext items']], self::csv($files['items.csv'])));
    }

    /** RB18 (the Codex review, finding 9): an order drafted before the cut-off and approved after it is listed. */
    public function testAnOrderDraftedBeforeTheCutOffAndApprovedAfterIsListed(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Early supplier']);
        $sku = self::makeSku('Drafted item');
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, 1, '2.0000', ['supplier_code' => 'SC-EARLY']);
        $d = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 3]]);
        self::$db->exec('DO SLEEP(0.01)');
        $since = (string) self::$db->value('SELECT NOW(6)');
        self::$db->exec('DO SLEEP(0.01)');
        $po = $this->pos->approve($buyer, $d->id, $d->version);

        $files = RollbackExport::run(self::$db, null, $since)['files'];
        self::assertSame([[(string) $po->number, '3', '3']], array_map(static fn (array $r): array => [$r['Order'], $r['Ordered units'], $r['Still to come']],
            self::csv($files['purchase_orders.csv'])), 'approved after the cut-off: ERPNext does not have it');
    }

    /** @return list<array<string, string>> */
    private static function csv(string $text): array
    {
        $fh = fopen('php://memory', 'r+');
        self::assertIsResource($fh);
        fwrite($fh, str_starts_with($text, CsvWriter::BOM) ? substr($text, strlen(CsvWriter::BOM)) : $text);
        rewind($fh);
        $head = fgetcsv($fh, null, ',', '"', '');
        self::assertIsArray($head);
        $rows = [];
        while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($head, array_map('strval', $r));
        }
        fclose($fh);
        return $rows;
    }
}
