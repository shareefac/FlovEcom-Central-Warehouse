<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Suppliers;

use CW\Caller;
use CW\Suppliers\ErpSeedImport;
use CW\Tests\Support\TestDb;

/**
 * The ERPNext seed import (spec §5.7, I45) end to end through bin/import_erp_suppliers.php, on SYNTHETIC files written to a
 * temporary directory by the test (*.csv is git-ignored; nothing here ever connects to ERPNext): draft suppliers created
 * by --staff, a file imported once, --update-blank, --request-activation for complete suppliers only, supplier items by
 * every reference type with the central pack, failed rows in the report, prices as import history, two preferred rows
 * failing together, and --dry-run writing only its import_run row.
 */
final class ErpSupplierImportTest extends SupplierTestCase
{
    private string $dir;
    private Caller $buyer;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_erp_import_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->buyer = $this->staffUser('buyer');
        $this->email = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$this->buyer->staffUserId]);
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
        $p = proc_open([PHP_BINARY, "{$root}/bin/import_erp_suppliers.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /** @return array<string, array{row: string, status: string, reason: string}> key => the report's row */
    private static function report(string $path): array
    {
        $out = [];
        $h = fopen($path, 'rb');
        self::assertIsResource($h);
        self::assertSame("\xEF\xBB\xBF", fread($h, 3), 'the report is a CsvWriter file');
        self::assertSame(['row', 'key', 'status', 'reason'], fgetcsv($h, null, ',', '"', ''));
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $out[$r[1] . '#' . $r[0]] = ['row' => $r[0], 'status' => $r[2], 'reason' => $r[3]];
        }
        fclose($h);
        return $out;
    }

    public function testSuppliersAreCreatedAsDraftsOnceAndBadRowsFail(): void
    {
        $file = $this->csv('suppliers.csv', [
            ['ERP_Name', 'Name', 'Code', 'Address Line1', 'Postcode', 'Email', 'Payment Terms', 'Is Overseas', 'Default VAT Code', 'Bank Account'],
            ['Acme Ltd', '', '', '1 Road', 'LS1 1AA', 'orders@acme.example', '30 days', '0', 'S', '12345678'],
            ['Beta Imports', 'Beta Imports Co', 'beta', '', '', '', '', '1', '', ''],
            ['Gamma', '', '', '', '', '', '', '', 'ZZ', ''],
            ['acme ltd', '', '', '', '', '', '', '', '', ''],
            ['', 'No name', '', '', '', '', '', '', '', ''],
            ['Delta', '', 'BETA', '', '', '', '', '', '', ''],
        ]);
        $report = "{$this->dir}/report.csv";
        $r = self::cli('--suppliers=' . $file, '--staff=' . $this->email, '--report=' . $report);
        self::assertSame(1, $r['code'], 'a row failed: ' . $r['err'] . $r['out']);
        self::assertMatchesRegularExpression('/suppliers: suppliers\.csv run \d+: rows=6 created=2 updated=0 skipped=0 failed=4/', $r['out']);
        $rows = self::$db->all('SELECT code, name, erp_name, status, created_by, created_actor, is_overseas, address_line1 FROM supplier ORDER BY id');
        self::assertSame([['ACMELTD', 'Acme Ltd', 'Acme Ltd', 'draft', $this->buyer->staffUserId, $this->buyer->actor, 0, '1 Road'],
            ['BETA', 'Beta Imports Co', 'Beta Imports', 'draft', $this->buyer->staffUserId, $this->buyer->actor, 1, null]],
            array_map(static fn (array $x): array => [$x['code'], $x['name'], $x['erp_name'], $x['status'], (int) $x['created_by'], $x['created_actor'],
                (int) $x['is_overseas'], $x['address_line1']], $rows));
        $rep = self::report($report);
        self::assertSame(['created', 'created', 'failed', 'failed', 'failed', 'failed'], array_column($rep, 'status'));
        self::assertStringContainsString('VAT code: there is no active VAT code ZZ', $rep['Gamma#3']['reason']);
        self::assertSame('the file names this supplier twice (row 1)', $rep['acme ltd#4']['reason'], 'ERPNext names compare without case');
        self::assertSame('erp_name is empty', $rep['#5']['reason']);
        self::assertSame('another supplier has the code BETA', $rep['Delta#6']['reason']);
        $run = self::$db->one("SELECT * FROM import_run WHERE kind = 'erp_suppliers'");
        self::assertSame(['suppliers.csv', hash_file('sha256', $file), 0, 'done', 6, 2, 0, 0, 4, ErpSeedImport::ACTOR, $this->buyer->staffUserId],
            [$run['file_name'], $run['file_sha256'], (int) $run['dry_run'], $run['status'], (int) $run['rows_read'], (int) $run['created'], (int) $run['updated'],
                (int) $run['skipped'], (int) $run['failed'], $run['actor'], (int) $run['staff_user_id']]);
        self::assertSame(['bank_account'], json_decode((string) $run['summary'], true)['ignored_columns'], 'decision 25: a bank column is ignored, never stored');

        // The same file again: already imported.
        $again = self::cli('--suppliers=' . $file, '--staff=' . $this->email);
        self::assertSame(0, $again['code'], $again['err']);
        self::assertStringContainsString("suppliers: suppliers.csv already imported (run {$run['id']})", $again['out']);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));
        // The buyer who imported them never approves them.
        $acme = $this->sup->findByCodeOrErpName('Acme Ltd');
        self::assertNotNull($acme);
        self::assertSame('own_supplier', \CW\Suppliers\Suppliers::refusal($this->buyer->staffUserId, ['buyer', 'reviewer'], $acme,
            ['opened_by' => null, 'kind' => 'approval'])['code'] ?? null);
    }

    public function testUpdateBlankRequestActivationAndDryRun(): void
    {
        $delta = $this->sup->create($this->buyer, ['name' => 'Delta Vapes', 'dd_checked_on' => self::day('-3 days'), 'dd_checked_by' => (string) $this->buyer->staffUserId,
            'dd_next_review_on' => self::day('+1 year'), 'email' => 'old@delta.example']);
        self::$db->exec("UPDATE supplier SET erp_name = 'Delta' WHERE id = ?", [(int) $delta['id']]);
        $echo = $this->activeSupplier($this->buyer, ['name' => 'Echo Ltd']);
        self::$db->exec("UPDATE supplier SET erp_name = 'Echo' WHERE id = ?", [(int) $echo['id']]);
        $file = $this->csv('suppliers2.csv', [
            ['erp_name', 'address_line1', 'postcode', 'email', 'payment_terms', 'phone'],
            ['Delta', '9 Lane', 'M1 1AA', 'new@delta.example', '14 days', ''],
            ['Echo', '1 Trading Estate', 'LS1 1AA', 'changed@echo.example', '', ''],
            ['Foxtrot', '', '', 'f@fox.example', '', ''],
        ]);
        // A dry run first: nothing but its import_run row.
        $dry = self::cli('--suppliers=' . $file, '--staff=' . $this->email, '--update-blank', '--request-activation', '--dry-run');
        self::assertSame(0, $dry['code'], $dry['err'] . $dry['out']);
        self::assertStringContainsString('(dry run, nothing kept): rows=3 created=1 updated=1 skipped=1 failed=0', $dry['out']);
        self::assertSame([2, 'draft', null], [(int) self::$db->value('SELECT COUNT(*) FROM supplier'), self::$db->value('SELECT status FROM supplier WHERE id = ?', [(int) $delta['id']]),
            self::$db->value('SELECT address_line1 FROM supplier WHERE id = ?', [(int) $delta['id']])]);
        self::assertSame([[1, 'done', 1, 1, 1]], array_map(static fn (array $x): array => [(int) $x['dry_run'], $x['status'], (int) $x['created'], (int) $x['updated'],
            (int) $x['skipped']], self::$db->all('SELECT * FROM import_run')));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM review_task WHERE subject_type = 'supplier' AND state = 'open'"));

        // The real run (a dry run does not count as imported).
        $report = "{$this->dir}/r2.csv";
        $r = self::cli('--suppliers=' . $file, '--staff=' . $this->email, '--update-blank', '--request-activation', '--report=' . $report);
        self::assertSame(0, $r['code'], $r['err'] . $r['out']);
        $d = $this->sup->get((int) $delta['id']);
        self::assertSame(['pending_approval', '9 Lane', 'M1 1AA', '14 days', 'old@delta.example'], [$d['status'], $d['address_line1'], $d['postcode'],
            $d['payment_terms'], $d['email']], 'only the blank fields are filled; the e-mail it had stays');
        $task = $this->taskRow($this->openTask((int) $delta['id']));
        self::assertSame(['new_supplier', $this->buyer->staffUserId], [$task['reason'], (int) $task['opened_by']]);
        $e = $this->sup->get((int) $echo['id']);
        self::assertSame(['active', 'orders@acme.example'], [$e['status'], $e['email']], 'an active supplier is never changed by an import');
        $rep = self::report($report);
        self::assertSame('updated', $rep['Delta#1']['status']);
        self::assertStringContainsString('filled address_line1, postcode, payment_terms (differs in CW: email); activation requested', $rep['Delta#1']['reason']);
        self::assertSame('skipped', $rep['Echo#2']['status']);
        self::assertStringContainsString('is active: an import never changes it (differs in CW: email)', $rep['Echo#2']['reason']);
        self::assertSame('created', $rep['Foxtrot#3']['status']);
        self::assertStringContainsString('not complete for activation (missing: address line 1, postcode, payment terms, due diligence checked on', $rep['Foxtrot#3']['reason']);
        self::assertSame('draft', $this->sup->findByCodeOrErpName('Foxtrot')['status'] ?? null);
    }

    public function testSupplierItemsByEveryReferenceType(): void
    {
        $acme = $this->sup->create($this->buyer, ['name' => 'Acme Ltd', 'code' => 'ACME']);
        self::$db->exec("UPDATE supplier SET erp_name = 'Acme Ltd' WHERE id = ?", [(int) $acme['id']]);
        $beta = $this->sup->create($this->buyer, ['name' => 'Beta', 'code' => 'BETA']);
        $vpg = $this->site('vapeandgo');
        [$a, $b, $c, $d, $x, $m, $into] = [self::makeSku('Elux Blue Razz'), self::makeSku('Case item'), self::makeSku('ERP item'), self::makeSku('By CW code'),
            self::makeSku('Preferred twice'), self::makeSku('Merged away'), self::makeSku('Survivor')];
        $this->listing($vpg, '100', $a, 10);
        $this->listing($vpg, '200', null);
        $this->listing($vpg, '300', $m);
        $this->listing($vpg, '101', $a, 1);
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$into, $m]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan) VALUES ('5012345678900', ?, 1, 6)", [$b]);
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan) VALUES ('5099999999990', ?, 0, 1)", [$b]);
        self::$db->exec("INSERT INTO sku_erp_item (item_code, sku_id, units_per_item) VALUES ('ERP-ITEM-1', ?, 2)", [$c]);
        $codes = "{$this->dir}/vapeandgo_listings.jsonl.gz";
        file_put_contents($codes, gzencode(implode("\n", [
            json_encode(['site' => 'vapeandgo', 'variant_id' => 101, 'code' => 'ELX-BR ']),
            json_encode(['site' => 'vapeandgo', 'variant_id' => 102, 'code' => 'AMBIG']),
            json_encode(['site' => 'vapeandgo', 'variant_id' => 103, 'code' => 'ambig']),
            '{not json',
        ]) . "\n"));
        $cw = static fn (int $id): string => sprintf('CW-%06d', $id);
        $file = $this->csv('supplier_items.csv', [
            ['supplier', 'item_ref_type', 'item_ref', 'supplier_code', 'purchase_unit', 'units_per_pack', 'moq_packs', 'is_preferred', 'last_pack_price', 'last_price_date', 'last_price_ref'],
            ['ACME', 'cw_code', $cw($d), 'D-12', 'box', '12', '2', '', '6.00', self::day('-10 days'), 'PINV-0001'],
            ['Acme Ltd', 'vpg_variant', '100', 'ELX-2', 'pack', '2', '', '', '', '', ''],
            ['Acme Ltd', 'vpg_code', 'elx-br', '', '', '', '', '', '1.65', '', ''],
            ['Acme Ltd', 'barcode', '05012345678900', 'CASE-4', 'outer', '4', '', '', '', '', ''],
            ['Acme Ltd', 'erp_item', 'ERP-ITEM-1', '', '', '5', '', '1', '', '', ''],
            ['Acme Ltd', 'vpg_variant', '200', '', '', '', '', '', '', '', ''],
            ['Acme Ltd', 'vpg_variant', '300', '', '', '', '', '', '', '', ''],
            ['Acme Ltd', 'vpg_variant', '999', '', '', '', '', '', '', '', ''],
            ['Nobody', 'cw_code', $cw($d), '', '', '', '', '', '', '', ''],
            ['Acme Ltd', 'cw_code', $cw($x), '', '', '', '', '1', '', '', ''],
            ['BETA', 'cw_code', $cw($x), '', '', '', '', '1', '', '', ''],
            ['Acme Ltd', 'vpg_code', 'AMBIG', '', '', '', '', '', '', '', ''],
            ['Acme Ltd', 'barcode', '5099999999990', '', '', '', '', '', '', '', ''],
            ['Acme Ltd', 'erp_item', 'NOPE', '', '', '', '', '', '', '', ''],
            ['Acme Ltd', 'cw_code', $cw($d), '', '', '1', '', '', '1.00', self::day('+5 days'), ''],
        ]);
        $report = "{$this->dir}/items-report.csv";
        $r = self::cli('--items=' . $file, '--staff=' . $this->email, '--vpg-codes=' . $codes, '--report=' . $report);
        self::assertSame(1, $r['code'], $r['err'] . $r['out']);
        self::assertMatchesRegularExpression('/items: supplier_items\.csv run (\d+): rows=15 created=5 updated=0 skipped=0 failed=10/', $r['out']);
        preg_match('/run (\d+)/', $r['out'], $mm);
        $runId = (int) $mm[1];

        $items = [];
        foreach (self::$db->all('SELECT supplier_id, sku_id, units_per_pack, supplier_code, purchase_unit, moq_packs, is_preferred, last_pack_price, last_price_source '
            . 'FROM supplier_item ORDER BY id') as $i) {
            $items[] = [(int) $i['supplier_id'], (int) $i['sku_id'], (int) $i['units_per_pack'], $i['supplier_code'], $i['purchase_unit'], (int) $i['moq_packs'],
                (int) $i['is_preferred'], $i['last_pack_price'], $i['last_price_source']];
        }
        self::assertSame([
            [(int) $acme['id'], $d, 12, 'D-12', 'box', 2, 1, '6.0000', 'import'],
            [(int) $acme['id'], $a, 20, 'ELX-2', 'pack', 1, 1, null, null],
            [(int) $acme['id'], $a, 1, null, 'each', 1, 0, '1.6500', 'import'],
            [(int) $acme['id'], $b, 24, 'CASE-4', 'outer', 1, 1, null, null],
            [(int) $acme['id'], $c, 10, null, 'each', 1, 1, null, null],
        ], $items, 'central packs: vpg 2 x u 10 = 20; vpg_code -> variant 101 (u 1); barcode 4 x 6 = 24; ERP item 5 x 2 = 10. An empty is_preferred makes the '
            . 'first supply of an item preferred (I75): item a\'s second pack stays an alternative');
        $prices = self::$db->all('SELECT pack_price, units_per_pack, unit_price, source, source_ref, effective_on, recorded_actor FROM supplier_item_price ORDER BY id');
        self::assertSame([['6.0000', 12, '0.500000', 'import', 'PINV-0001', self::day('-10 days'), $this->buyer->actor],
            ['1.6500', 1, '1.650000', 'import', "import_run:{$runId}", self::day('today'), $this->buyer->actor]],
            array_map(static fn (array $p): array => [$p['pack_price'], (int) $p['units_per_pack'], $p['unit_price'], $p['source'], $p['source_ref'],
                $p['effective_on'], $p['recorded_actor']], $prices));

        $rep = array_values(self::report($report));
        self::assertSame(['created', 'created', 'created', 'created', 'created', 'failed', 'failed', 'failed', 'failed', 'failed', 'failed', 'failed', 'failed', 'failed', 'failed'],
            array_column($rep, 'status'));
        $reasons = array_column($rep, 'reason');
        self::assertStringContainsString('in packs of 12; price 6.0000 on ' . self::day('-10 days') . ' (CW code ' . $cw($d) . ')', $reasons[0]);
        self::assertStringContainsString('Vape and Go variant 200: the listing is not linked to an item (status unmapped)', $reasons[5]);
        self::assertStringContainsString('Vape and Go variant 300 is item ' . $cw($m) . ', which was merged into ' . $cw($into), $reasons[6]);
        self::assertStringContainsString('Vape and Go variant 999: CW has no such Vape and Go listing', $reasons[7]);
        self::assertSame('no supplier has the ERPNext name or CW code Nobody', $reasons[8]);
        self::assertSame('rows 10, 11 all make item ' . $cw($x) . ' preferred: the file must name one', $reasons[9]);
        self::assertSame($reasons[9], $reasons[10], 'both rows fail');
        self::assertStringContainsString('the code AMBIG names 2 Vape and Go variants (102, 103)', $reasons[11]);
        self::assertSame('no item has the usable barcode 5099999999990', $reasons[12]);
        self::assertStringContainsString('the ERPNext item NOPE is not linked to a CW item', $reasons[13]);
        self::assertSame('effective on: a price cannot take effect after today', $reasons[14]);

        // Without --vpg-codes a vpg_code row fails; the same file again is already imported; a later file upserts by pack.
        self::assertSame(0, self::cli('--items=' . $file, '--staff=' . $this->email)['code']);
        $file2 = $this->csv('supplier_items_2.csv', [
            ['supplier', 'item_ref_type', 'item_ref', 'units_per_pack', 'moq_packs', 'last_pack_price', 'last_price_date'],
            ['ACME', 'cw_code', $cw($d), '12', '3', '5.50', self::day('-1 day')],
            ['ACME', 'vpg_code', 'ELX-BR', '', '', '', ''],
        ]);
        $r2 = self::cli('--items=' . $file2, '--staff=' . $this->email, '--report=' . $report);
        self::assertSame(1, $r2['code'], $r2['out']);
        self::assertStringContainsString('rows=2 created=0 updated=1 skipped=0 failed=1', $r2['out']);
        self::assertStringContainsString('vpg_code rows need --vpg-codes', $r2['out']);
        $i = self::$db->one('SELECT moq_packs, last_pack_price, last_price_on FROM supplier_item WHERE supplier_id = ? AND sku_id = ? AND units_per_pack = 12', [(int) $acme['id'], $d]);
        self::assertSame([3, '5.5000', self::day('-1 day')], [(int) $i['moq_packs'], $i['last_pack_price'], $i['last_price_on']]);
    }

    public function testUsage(): void
    {
        $file = $this->csv('s.csv', [['erp_name'], ['X']]);
        self::assertSame(2, self::cli('--suppliers=' . $file)['code'], 'no --staff');
        self::assertSame(2, self::cli('--staff=' . $this->email)['code'], 'no file');
        self::assertSame(2, self::cli('--suppliers=' . $file, '--staff=nobody@test.example')['code']);
        $reviewer = $this->staffUser('reviewer');
        $r = self::cli('--suppliers=' . $file, '--staff=' . self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$reviewer->staffUserId]));
        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--staff must name an active person who may manage suppliers', $r['err']);
        self::assertSame(2, self::cli('--suppliers=' . $this->dir . '/missing.csv', '--staff=' . $this->email)['code']);
        $bad = $this->csv('bad.csv', [['name', 'email'], ['X', 'x@x.example']]);
        $r = self::cli('--suppliers=' . $bad, '--staff=' . $this->email);
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('the file has no column erp_name', $r['err']);
        self::assertSame('failed', self::$db->value("SELECT status FROM import_run WHERE file_name = 'bad.csv'"));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));
    }
}
