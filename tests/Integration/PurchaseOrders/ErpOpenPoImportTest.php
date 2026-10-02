<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Tests\Support\TestDb;

/**
 * The ERPNext open-PO import (spec §6.8, I56) end to end through bin/import_erp_open_pos.php, on SYNTHETIC files written to a
 * temporary directory (*.csv is git-ignored; nothing here connects to ERPNext): one CW PO per erp_po with the outstanding
 * packs only, approved and marked sent (imported); one above the value limit waits for a reviewer; a PO of an inactive
 * supplier and a PO with an unresolved line are refused whole; nothing outstanding is skipped; a re-run is safe (the same
 * file: "already imported"; another file naming the same POs: skipped by external_ref); --dry-run writes only its run row.
 */
final class ErpOpenPoImportTest extends PurchaseOrderTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_erp_pos_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @param list<list<string>> $rows */
    private function csv(string $name, array $rows): string
    {
        $path = "{$this->dir}/{$name}";
        $h = fopen($path, 'wb');
        self::assertIsResource($h);
        foreach ($rows as $r) {
            fputcsv($h, $r, ',', '"', '');
        }
        fclose($h);
        return $path;
    }

    /** @return array{code: int, out: string, err: string} */
    private static function cli(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/import_erp_open_pos.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /** @return array<string, array{status: string, reason: string}> erp_po => its report row */
    private static function report(string $path): array
    {
        $out = [];
        $h = fopen($path, 'rb');
        self::assertIsResource($h);
        self::assertSame("\xEF\xBB\xBF", fread($h, 3));
        self::assertSame(['row', 'key', 'status', 'reason'], fgetcsv($h, null, ',', '"', ''));
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $out[$r[1]] = ['status' => $r[2], 'reason' => $r[3]];
        }
        fclose($h);
        return $out;
    }

    public function testFourOpenOrders(): void
    {
        $buyer = $this->staffUser('buyer');
        $email = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$buyer->staffUserId]);
        $acme = $this->activeSupplier($buyer, ['name' => 'Acme Open Orders']);
        $gone = $this->activeSupplier($buyer, ['name' => 'Gone Supplies']);
        $this->sup->deactivate($buyer, (int) $gone['id'], (int) $gone['version'], 'ceased trading');
        $a = self::makeSku('Open A');
        $b = $this->itemWithBarcode('Open B', '5099999000017');
        $c = self::makeSku('Open C');
        $si = $this->supplierItem($buyer, (int) $acme['id'], $a, 10, '25.0000', ['supplier_code' => 'A-10']);
        $head = ['erp_po', 'supplier', 'order_date', 'expected_date', 'line_no', 'item_ref_type', 'item_ref', 'supplier_code', 'purchase_unit', 'units_per_pack',
            'packs_ordered', 'packs_received', 'pack_price', 'vat_code'];
        $rows = [$head,
            ['PUR-ORD-0001', (string) $acme['code'], '2026-09-20', '2026-09-27', '1', 'cw_code', sprintf('CW-%06d', $a), 'A-10', 'box', '10', '10', '4', '25.00', 'S'],
            ['PUR-ORD-0001', (string) $acme['code'], '2026-09-20', '2026-09-27', '2', 'barcode', '5099999000017', 'B-1', 'each', '1', '5', '5', '1.20', ''],
            ['PUR-ORD-0001', (string) $acme['code'], '2026-09-20', '2026-09-27', '3', 'cw_code', sprintf('CW-%06d', $c), 'C-6', 'case', '6', '3', '', '9.00', 'Z'],
            ['PUR-ORD-0002', (string) $acme['code'], '2026-09-21', '', '1', 'cw_code', sprintf('CW-%06d', $c), '', '', '1', '2', '0', '6000.00', ''],
            ['PUR-ORD-0003', (string) $gone['code'], '2026-09-22', '', '1', 'cw_code', sprintf('CW-%06d', $a), '', '', '1', '1', '0', '1.00', ''],
            ['PUR-ORD-0004', (string) $acme['code'], '2026-09-23', '', '1', 'cw_code', sprintf('CW-%06d', $a), '', '', '1', '1', '0', '1.00', ''],
            ['PUR-ORD-0004', (string) $acme['code'], '2026-09-23', '', '2', 'cw_code', 'CW-999999', '', '', '1', '1', '0', '1.00', ''],
            ['PUR-ORD-0005', (string) $acme['code'], '2026-09-24', '', '1', 'cw_code', sprintf('CW-%06d', $a), '', '', '1', '4', '4', '1.00', ''],
        ];
        $file = $this->csv('open_pos.csv', $rows);

        // --dry-run: everything checked, only the import_run row kept.
        $dry = self::cli('--file=' . $file, '--staff=' . $email, '--dry-run', '--report=' . $this->dir . '/dry.csv');
        self::assertSame(1, $dry['code'], $dry['err'] . $dry['out']);
        self::assertStringContainsString('(dry run, nothing kept)', $dry['out']);
        self::assertStringContainsString('would be PO-000001 approved and marked sent', $dry['out']);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'PO'"));
        self::assertSame(['erp_open_pos', 1, 'done'], array_values((array) self::$db->one('SELECT kind, dry_run, status FROM import_run')));

        $r = self::cli('--file=' . $file, '--staff=' . $email, '--report=' . $this->dir . '/report.csv');
        self::assertSame(1, $r['code'], 'two orders were refused whole: ' . $r['err'] . $r['out']);
        self::assertStringContainsString('rows=8 orders=5 created=2 skipped=1 failed=2', $r['out']);
        $rep = self::report($this->dir . '/report.csv');
        self::assertSame(['created', 'created', 'failed', 'failed', 'skipped'], array_column(array_values($rep), 'status'));
        self::assertStringStartsWith('PO-000001 approved and marked sent (imported), net £177.00, 2 lines', $rep['PUR-ORD-0001']['reason']);
        self::assertStringContainsString("waits for a reviewer's approval (net £12000.00 is above the limit)", $rep['PUR-ORD-0002']['reason']);
        self::assertStringContainsString('whole PO skipped: supplier ' . $gone['code'] . ' is inactive', $rep['PUR-ORD-0003']['reason']);
        self::assertStringContainsString('whole PO skipped: row 7: no item has the CW code CW-999999', $rep['PUR-ORD-0004']['reason']);
        self::assertSame('nothing outstanding (every line received)', $rep['PUR-ORD-0005']['reason']);

        $p = $this->docs->get((int) self::$db->value("SELECT id FROM document WHERE external_ref = 'ERPNext PUR-ORD-0001'"));
        self::assertSame(['PO-000001', 'posted', '2026-09-20', $buyer->staffUserId, 'pending'], [$p->number, $p->status, $p->docDate, $p->postedBy, $p->reviewState]);
        $po = $this->poRow($p->id);
        self::assertSame(['sent', 'imported', 'ERPNext', 'erp_seed', '2026-09-27'], [$po['state'], $po['sent_via'], $po['sent_to'], $po['source'], $po['expected_date']]);
        self::assertSame([[(int) $si['id'], 6, 10, '25.0000', 'S'], [null, 3, 6, '9.0000', 'Z']],
            array_map(static fn (array $l): array => [$l['supplier_item_id'], $l['packs'], $l['units_per_pack'], $l['pack_price'], $l['vat_code']], $this->pos->lines($p->id)),
            'only the outstanding packs; the received line left out; the supplier item linked when it matches the pack');
        self::assertSame(['C-6', 'case'], [$this->pos->lines($p->id)[1]['supplier_code'], $this->pos->lines($p->id)[1]['purchase_unit']]);
        $w = $this->docs->get((int) self::$db->value("SELECT id FROM document WHERE external_ref = 'ERPNext PUR-ORD-0002'"));
        self::assertSame(['awaiting_approval', 12000], [$w->status, (int) self::$db->value("SELECT units FROM review_task WHERE subject_id = ? AND kind = 'approval'", [$w->id])]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'PO'"), 'no partial PO');

        // The same file again: already imported; another file naming the same orders: skipped by their reference.
        $again = self::cli('--file=' . $file, '--staff=' . $email);
        self::assertSame(0, $again['code']);
        self::assertStringContainsString('already imported (run', $again['out']);
        $rows2 = array_slice($rows, 0, 5);
        $rows2[] = ['PUR-ORD-0006', (string) $acme['code'], '2026-09-25', '', '1', 'cw_code', sprintf('CW-%06d', $a), '', '', '10', '1', '', '25.00', ''];
        $second = self::cli('--file=' . $this->csv('open_pos_2.csv', $rows2), '--staff=' . $email, '--report=' . $this->dir . '/r2.csv');
        self::assertSame(0, $second['code'], $second['err'] . $second['out']);
        $rep2 = self::report($this->dir . '/r2.csv');
        self::assertSame(['skipped', 'skipped', 'created'], array_column(array_values($rep2), 'status'));
        self::assertSame('already in CW as PO-000001', $rep2['PUR-ORD-0001']['reason']);
        self::assertStringStartsWith('already in CW as awaiting approval #', $rep2['PUR-ORD-0002']['reason']);
        self::assertStringStartsWith('PO-000002 approved and marked sent', $rep2['PUR-ORD-0006']['reason']);

        // Usage: --staff must be able to post POs.
        $reviewerEmail = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$this->staffUser('reviewer')->staffUserId]);
        self::assertSame(2, self::cli('--file=' . $file, '--staff=' . $reviewerEmail)['code']);
        self::assertSame(2, self::cli('--staff=' . $email)['code']);
    }
}
