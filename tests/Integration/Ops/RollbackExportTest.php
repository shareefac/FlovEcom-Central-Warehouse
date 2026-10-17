<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ops;

use CW\Caller;
use CW\Clock;
use CW\Ops\ErpOpening;
use CW\Ops\ErpOpeningFiles;
use CW\Ops\RollbackExport;
use CW\Output\CsvWriter;
use CW\Queues\Corrections;
use CW\Queues\Suspense;
use CW\Relay\RelayIntake;
use CW\Tests\Integration\Handovers\HandoversTestCase;
use CW\Tests\Support\StatementHook;
use CW\Tests\Support\TestDb;
use CW\Ui\Words;

/**
 * Pack RB (docs/decisions.md RB1-RB19): the rollback file. What a person booked since the cut-off goes into ERPNext's Data Import files,
 * at the right ERPNext item, in ERPNext units and UK time; what ERPNext or a website already has, what adds up to nothing and what cannot
 * be named is listed with why, never guessed. The script writes a new directory once, its manifest last.
 */
final class RollbackExportTest extends HandoversTestCase
{
    /** UTC 'Y-m-d H:i:s.u': the cut-off of the test (rows booked from it on are read). */
    private string $since = '';

    public function testAGoodsInTypedByAPersonIsAMaterialReceiptAtItsCostInUkTime(): void
    {
        $sku = $this->item('legacy', 4);
        $this->erpLink('VPG Liquid 10ml', $sku);
        $this->cut();
        $this->book('goods_in', $sku, 6, 'MAIN', null, '2.5');

        $x = $this->export();
        $se = self::csv($x['files']['erpnext_stock_entry.csv']);
        self::assertCount(1, $se);
        self::assertMatchesRegularExpression('/^CW rollback entry 1: DOC-[0-9a-f]{6} \(goods_in\)$/D', $se[0]['Remarks']);
        unset($se[0]['Remarks']);
        self::assertSame(['Stock Entry Type' => 'Material Receipt', 'Company' => 'VAPE AND GO', 'Posting Date' => '2026-09-26', 'Posting Time' => '19:00:00',
            'Set Posting Time' => '1', 'Item Code (Items)' => 'VPG Liquid 10ml', 'Qty (Items)' => '6', 'Basic Rate (Items)' => '2.5',
            'Source Warehouse (Items)' => '', 'Target Warehouse (Items)' => 'Stores - VPG'], $se[0], '18:00 UTC is 19:00 in London in September');
        self::assertStringStartsNotWith(CsvWriter::BOM, $x['files']['erpnext_stock_entry.csv'], 'ERPNext reads the file as it is: no byte-order mark');
        self::assertStringStartsWith(CsvWriter::BOM, $x['files']['movements.csv'], 'the lists are for people (Excel)');
        $mv = self::csv($x['files']['movements.csv']);
        self::assertCount(1, $mv, 'the item\'s goods-in before the cut-off is not read');
        self::assertSame(['goods_in', '6', '2.5', 'yes', '1', 'VPG Liquid 10ml', '1', ''], self::pick($mv[0], 'Type', 'Units', 'Cost a unit (GBP)', 'Carried', 'Entry',
            'ERPNext item', 'Units per ERPNext item', 'Why not carried'));
        self::assertSame(['material_receipt' => 1, 'material_issue' => 0, 'sales_invoice' => 0, 'receipt_lines_without_rate' => 0], $x['counts']['entries']);
        self::assertSame([1, 1], [$x['counts']['rows_read'], $x['counts']['rows_carried']]);
        self::assertSame(1, $x['rows']['erpnext_stock_entry.csv']);
    }

    public function testUnitsAreCountedInErpnextItemsAndWhatCannotBeNamedIsListedWithWhy(): void
    {
        $box = $this->item('legacy', 30);
        $this->erpLink('Box of ten', $box, 10);
        $none = $this->item('legacy', 5);
        $two = $this->item('legacy', 5);
        $this->erpLink('Two A', $two);
        $this->erpLink('Two B', $two);
        $single = $this->item('legacy', 5);
        $this->erpLink('Single', $single);
        $this->erpLink('Single six', $single, 6);
        $formula = $this->item('legacy', 5);
        $this->erpLink('=HYPERLINK', $formula);
        $this->cut();
        $this->book('adjustment', $box, 20);
        $this->book('adjustment', $box, -15);
        $this->book('adjustment', $none, -3);
        $this->book('adjustment', $two, 1);
        $this->book('write_off', $single, 2);
        $this->book('adjustment', $formula, 1);

        $x = $this->export();
        $se = self::csv($x['files']['erpnext_stock_entry.csv']);
        self::assertSame([['Material Receipt', 'Box of ten', '2', '', 'Stores - VPG'], ['Material Issue', 'Single', '2', 'Stores - VPG', '']],
            array_map(static fn (array $r): array => self::pick($r, 'Stock Entry Type', 'Item Code (Items)', 'Qty (Items)', 'Source Warehouse (Items)',
                'Target Warehouse (Items)'), $se), 'twenty units are two boxes; of two ERPNext items the one of one unit is meant');
        self::assertSame('', $se[0]['Basic Rate (Items)'], 'no cost known: ERPNext is told none');
        self::assertSame(1, $x['counts']['entries']['receipt_lines_without_rate']);
        $why = [];
        foreach (self::csv($x['files']['movements.csv']) as $r) {
            $why[] = [$r['Units'], $r['Carried'], $r['Why not carried']];
        }
        self::assertSame([['20', 'yes', ''], ['-15', 'no', Words::ROLLBACK_REASON['fractional_qty']], ['-3', 'no', Words::ROLLBACK_REASON['no_erp_item']],
            ['1', 'no', Words::ROLLBACK_REASON['ambiguous_erp_item']], ['-2', 'yes', ''], ['1', 'no', Words::ROLLBACK_REASON['unsafe_text']]], $why);
        self::assertSame(['no_erp_item' => 1, 'ambiguous_erp_item' => 1, 'fractional_qty' => 1, 'unsafe_text' => 1],
            array_filter($x['counts']['not_carried']));
    }

    public function testWhatErpnextOrAWebsiteAlreadyHasIsNotCarried(): void
    {
        $site = $this->site('vapeandgo');
        $this->grant($site, 'goods_in');
        $sku = $this->item('legacy', 10);
        $this->erpLink('Relay item', $sku);
        $this->listing($site, 'V1', $sku);
        $this->cut();
        // ERPNext's own goods-in, relayed by the website: the channel booked it.
        $this->ok($this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-1', 'lines' => [['variant_id' => 'V1', 'qty' => 3]]], $this->key('relay')));
        // One that waited in suspense and a person put on the item: still ERPNext's document.
        $this->ok($this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-2', 'lines' => [['variant_id' => 'NOPE', 'qty' => 2]]], $this->key('relay')));
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
        $desk = $this->staffUser('purchasing_desk');
        (new Suspense(self::$db))->pick($desk, (int) self::$db->value('SELECT id FROM goods_in_suspense'), $code, 1, 'ours');
        // The opening from ERPNext (a system job), and a website order paid and sent.
        $this->ok($this->moves->recordOpening(Caller::system(ErpOpening::ACTOR_JOB), ['type' => 'adjustment', 'doc_ref' => 'erp-opening:t',
            'lines' => [['sku_id' => $sku, 'qty' => 4]]], 'erp-opening:t:1'));
        $this->ok($this->commit($site, 'O-1', [self::line('V1', 'u1')]));
        $this->ok($this->ship($site, 'O-1', ['u1']));

        $x = $this->export();
        self::assertSame([], self::csv($x['files']['erpnext_stock_entry.csv']));
        $why = array_map(static fn (array $r): array => self::pick($r, 'Type', 'Units', 'Booked by', 'Why not carried'), self::csv($x['files']['movements.csv']));
        self::assertSame([['goods_in', '3', 'channel:vapeandgo', Words::ROLLBACK_REASON['in_erpnext']],
            ['goods_in', '2', $desk->actor, Words::ROLLBACK_REASON['in_erpnext']],
            ['adjustment', '4', 'system:' . ErpOpening::ACTOR_JOB, Words::ROLLBACK_REASON['opening']]], $why);
        self::assertSame(['ship' => 1], $x['counts']['website_rows'], 'the website\'s order flow is counted, not read');
        self::assertSame(['in_erpnext' => 2, 'opening' => 1], array_filter($x['counts']['not_carried']));
    }

    public function testADocumentAndItsReversalAddUpToNothingAndSoDoesAMoveBetweenCarriedWarehouses(): void
    {
        $sku = $this->item('legacy', 20);
        $this->erpLink('Doc item', $sku);
        $this->warehouse('SHOP');
        $sc = $this->staffUser('stock_controller');
        $this->cut();
        $in = $this->posted('in', $sc, ['reason_code' => 'found'], [['sku_id' => $sku, 'qty' => 5, 'unit_cost' => '1.25']]);
        $this->ops->reverse($sc, $in->id, 'entered_in_error', null);
        $move = $this->posted('transfer', $sc, ['to_warehouse' => 'SHOP'], [['sku_id' => $sku, 'qty' => 4]]);

        $this->setting('rollback.cw_warehouses', '"MAIN,SHOP"');
        $x = $this->export();
        self::assertSame([], self::csv($x['files']['erpnext_stock_entry.csv']));
        self::assertSame(['netted_out' => 4], array_filter($x['counts']['not_carried']), 'the stock in and its cancelling, the move out and in');

        $this->setting('rollback.cw_warehouses', '"MAIN"');
        $x = $this->export();
        $se = self::csv($x['files']['erpnext_stock_entry.csv']);
        self::assertSame([['Material Issue', 'Doc item', '4', 'Stores - VPG', "CW rollback entry 1: {$move->number} (transfer_out)"]],
            array_map(static fn (array $r): array => self::pick($r, 'Stock Entry Type', 'Item Code (Items)', 'Qty (Items)', 'Source Warehouse (Items)', 'Remarks'), $se));
        self::assertSame(['other_warehouse' => 1, 'netted_out' => 2], array_filter($x['counts']['not_carried']));
    }

    public function testAHandOverIsASalesInvoiceToItsCustomerAtItsPrice(): void
    {
        $sku = $this->item('legacy', 20);
        $this->erpLink('Trade item', $sku, 2);
        $desk = $this->staffUser('purchasing_desk');
        $cp = $this->customer('Ashton Vapes');
        $this->company(true);
        $this->cut();
        $sold = $this->postedTo($desk, $cp, [['sku_id' => $sku, 'qty' => 4, 'unit_cost' => '3.10']]);
        $undone = $this->postedTo($desk, $cp, [['sku_id' => $sku, 'qty' => 2, 'unit_cost' => '2.00']]);
        $this->handovers->reverse($desk, $undone->id, 'entered_in_error', null);

        $x = $this->export();
        $si = self::csv($x['files']['erpnext_sales_invoice.csv']);
        $at = (new \DateTimeImmutable((string) self::$db->value('SELECT COALESCE(effective_at, created_at) FROM stock_ledger WHERE document_id = ?', [$sold->id]),
            Clock::utc()))->setTimezone(new \DateTimeZone('Europe/London'));
        self::assertSame([['Customer' => 'Ashton Vapes', 'Company' => 'VAPE AND GO', 'Posting Date' => $at->format('Y-m-d'), 'Posting Time' => $at->format('H:i:s'),
            'Set Posting Time' => '1', 'Update Stock' => '1', 'Remarks' => "CW rollback entry 1: {$sold->number} (trade_sale)", 'Item Code (Items)' => 'Trade item',
            'Qty (Items)' => '2', 'Rate (Items)' => '6.2', 'Warehouse (Items)' => 'Stores - VPG']], $si, 'four units are two ERPNext items of two, at twice the unit price');
        self::assertSame([], self::csv($x['files']['erpnext_stock_entry.csv']));
        self::assertSame(['netted_out' => 2], array_filter($x['counts']['not_carried']), 'the hand-over undone and its undoing');
        self::assertSame(1, $x['counts']['entries']['sales_invoice']);
    }

    public function testTheItemListNamesItemsThroughTheStoresMatchAndTheModesFileListsEachErpnextItem(): void
    {
        $site = $this->site('vapeandgo');
        $sku = $this->item('legacy', 0);
        $this->listing($site, 'V7', $sku);
        $this->listing($site, 'V8', $sku, 6);
        $lonely = $this->item('legacy', 0);
        $path = $this->dir . '/item_list.csv';
        file_put_contents($path, "\"ID\",\"Item Name\",\"Product ID\"\r\n\"Erp Item 7\",\"Seven\",\"V7\"\r\n\"ERP BOX 7\",\"Box\",\"V8\"\r\n\"Unused\",\"None\",\"V99\"\r\n");
        $map = ErpOpeningFiles::itemMap($path);
        self::assertSame(['erp item 7' => 'Erp Item 7', 'erp box 7' => 'ERP BOX 7', 'unused' => 'Unused'], $map['codes'], 'each code as ERPNext wrote it');
        // The modes CW last wrote to the store, each with its log row (W1).
        foreach ([[$sku, 'In-Stock', null, 5], [$lonely, 'Out-Of-Stock', 'From-Warehouse', null]] as [$id, $m, $before, $low]) {
            self::$db->exec("INSERT INTO item_channel_mode (sku_id, channel_id, mode, previous_mode, low_stock_threshold, source, updated_actor) "
                . "VALUES (?, ?, ?, ?, ?, 'switch', 'staff:1')", [$id, $site->channelId, $m, $before, $low]);
            self::$db->exec("INSERT INTO item_channel_mode_log (sku_id, channel_id, version, mode_after, previous_mode, threshold_after, source, reason, actor) "
                . "VALUES (?, ?, 1, ?, ?, ?, 'switch', 'set for the test', 'staff:1')", [$id, $site->channelId, $m, $before, $low]);
        }
        $this->cut();
        $this->book('goods_in', $sku, 12);

        $x = $this->export($map);
        self::assertSame([['Material Receipt', 'Erp Item 7', '12']], array_map(static fn (array $r): array => self::pick($r, 'Stock Entry Type', 'Item Code (Items)',
            'Qty (Items)'), self::csv($x['files']['erpnext_stock_entry.csv'])), 'of the two ERPNext items of the item, the one of one unit');
        $item = static fn (int $id): string => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$id]);
        self::assertSame([['Erp Item 7', '1', $item($sku), 'In-Stock', '', '5', 'switch', 'V7 | V8', ''], ['ERP BOX 7', '6', $item($sku), 'In-Stock', '', '5', 'switch', 'V7 | V8', ''],
            ['', '', $item($lonely), 'Out-Of-Stock', 'From-Warehouse', '', 'switch', '', Words::ROLLBACK_REASON['no_erp_item']]],
            array_map(static fn (array $r): array => self::pick($r, 'ERPNext item', 'Units per ERPNext item', 'Item', 'Selling mode', 'Mode before Out-Of-Stock',
                'Low-stock figure', 'Set by', 'Website variants', 'Why no ERPNext item'), self::csv($x['files']['selling_modes.csv'])),
            'every ERPNext item of an item takes its mode; an item without one is listed');

        $x = $this->export();
        self::assertSame([], self::csv($x['files']['erpnext_stock_entry.csv']), 'without the item list only CW\'s own ERPNext links name items');
        self::assertSame(['no_erp_item' => 1], array_filter($x['counts']['not_carried']));
    }

    public function testTheErpnextNamesComeFromTheSettingsAndAreRefusedEmptyOrLikeAFormula(): void
    {
        $settings = $this->settings();
        foreach (['rollback.erp_warehouse' => '=cmd', 'rollback.erp_company' => '-1', 'rollback.cw_warehouses' => 'main'] as $key => $bad) {
            self::refusedWith('bad_value', static fn () => $settings->checkRule($key, $bad));
        }
        $settings->checkRule('rollback.erp_warehouse', 'Stores - VPG');
        $settings->checkRule('rollback.cw_warehouses', 'MAIN,VERIFY');
        $this->setting('rollback.erp_company', '""');
        $this->expectException(\InvalidArgumentException::class);
        RollbackExport::fromSettings(self::$db, $this->settings(), null);
    }

    public function testACwWarehouseTheSettingNamesMustExist(): void
    {
        $this->setting('rollback.cw_warehouses', '"MAIN,NOWHERE"');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NOWHERE');
        $this->cut();
        $this->export();
    }

    public function testTheScriptWritesItsFilesOnceIntoANewDirectoryWithTheManifestLast(): void
    {
        $sku = $this->item('legacy', 0);
        $this->erpLink('Script item', $sku);
        $this->cut();
        $this->book('goods_in', $sku, 2, 'MAIN', null, '1.5');
        $since = (string) Clock::iso($this->since);

        self::assertSame(2, self::script('rollback_export', '--out=' . $this->dir . '/none')['code'], 'no opening has finished and no --since');
        self::assertDirectoryDoesNotExist($this->dir . '/none');
        self::assertSame(2, self::script('rollback_export', '--out=' . $this->dir . '/bad', '--since=yesterday')['code']);
        self::assertSame(2, self::script('rollback_export', '--out=' . $this->dir . '/bad', '--since=2026-02-30T00:00:00Z')['code']);
        self::assertDirectoryDoesNotExist($this->dir . '/bad');

        $out = $this->dir . '/rollback';
        $r = self::script('rollback_export', "--out={$out}", "--since={$since}");
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('1 rows read, 1 carried; 1 receipts', $r['out']);
        $names = [...RollbackExport::FILES, 'manifest.json'];
        sort($names);
        self::assertSame($names, array_values(array_diff(scandir($out), ['.', '..'])));
        self::assertSame('700', substr(sprintf('%o', fileperms($out)), -3));
        $m = json_decode((string) file_get_contents("{$out}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
        foreach (RollbackExport::FILES as $name) {
            self::assertSame('600', substr(sprintf('%o', fileperms("{$out}/{$name}")), -3), $name);
            self::assertSame(hash_file('sha256', "{$out}/{$name}"), $m['files'][$name]['sha256'], $name);
            self::assertSame(filesize("{$out}/{$name}"), $m['files'][$name]['bytes'], $name);
        }
        self::assertSame([RollbackExport::FORMAT, TestDb::name(), $since, 'given', 'vapeandgo', ['MAIN', 'VERIFY'], 'Stores - VPG', 'VAPE AND GO', null, 1, 1],
            [$m['format'], $m['schema'], $m['since'], $m['since_from'], $m['store'], $m['cw_warehouses'], $m['erp_warehouse'], $m['erp_company'], $m['item_list'],
                $m['files']['erpnext_stock_entry.csv']['rows'], $m['counts']['entries']['material_receipt']]);

        self::assertIsInt($m['peak_memory_bytes'], 'each run says how much memory it took (RB22)');
        // Nothing is ever overwritten: the same directory again is refused, and what is there stays as it was.
        $before = hash_file('sha256', "{$out}/manifest.json");
        $again = self::script('rollback_export', "--out={$out}", "--since={$since}");
        self::assertSame(2, $again['code']);
        self::assertStringContainsString('exists already', $again['err']);
        self::assertSame($before, hash_file('sha256', "{$out}/manifest.json"));

        // Without --since, the as-of moment of the latest ERPNext opening that finished is the cut-off.
        $run = self::$db->insert("INSERT INTO erp_opening_run (doc_ref, as_of, file_name, file_sha256, status, actor, finished_at) "
            . "VALUES ('erp:t', ?, 'stock.csv', REPEAT('a', 64), 'done', 'system:opening_estimate', NOW(6))", [$this->since]);
        $auto = self::script('rollback_export', '--out=' . $this->dir . '/auto');
        self::assertSame(0, $auto['code'], $auto['err']);
        $m = json_decode((string) file_get_contents($this->dir . '/auto/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$since, "erp_opening_run {$run}", 1], [$m['since'], $m['since_from'], $m['counts']['rows_carried']]);
    }

    // ---- the Codex review of pack RB (runs/rbx_codex_review.txt; docs/decisions.md RB10-RB19) ---------------------------------------

    /** RB10 (finding 1): an ERPNext item is the CW item the opening import makes it, with its units; the item list never overrides a link. */
    public function testAnErpnextLinkKeepsItsUnitsWhenTheItemListAlsoReachesTheItem(): void
    {
        $site = $this->site('vapeandgo');
        $sku = $this->item('legacy', 0);
        $this->erpLink('BOX', $sku, 6);
        $this->listing($site, 'V1', $sku);
        $other = $this->item('legacy', 0);
        $this->listing($site, 'V2', $other);
        $path = $this->dir . '/item_list.csv';
        file_put_contents($path, "\"ID\",\"Product ID\"\r\n\"BOX\",\"V1\"\r\n\"Other single\",\"V2\"\r\n");
        $this->cut();
        $this->book('goods_in', $sku, 12);
        $this->book('goods_in', $other, 3);

        $x = $this->export(ErpOpeningFiles::itemMap($path));
        self::assertSame([['BOX', '2'], ['Other single', '3']], array_map(static fn (array $r): array => self::pick($r, 'Item Code (Items)', 'Qty (Items)'),
            self::csv($x['files']['erpnext_stock_entry.csv'])), 'twelve units are two boxes (the link), never twelve boxes');
    }

    /** RB11 (finding 2): a dispatch the website sends after a count that already took the unit off is not taken off ERPNext twice. */
    public function testADispatchOrResetACountAlreadyTookInIsNotCarriedTwice(): void
    {
        $site = $this->site('vapeandgo');
        $sku = $this->item('legacy', 10);
        $this->erpLink('Counted item', $sku);
        $this->listing($site, 'V1', $sku);
        $back = $this->item('legacy', 10);
        $this->erpLink('Counted back', $back);
        $this->listing($site, 'V2', $back);
        $this->ok($this->commit($site, 'O-1', [self::line('V1', 'u1', 'u2')]));
        $this->ok($this->commit($site, 'O-2', [self::line('V2', 'w1')]));
        $this->ok($this->ship($site, 'O-2', ['w1'], '2026-09-26T10:00:00Z'));
        $this->cut();
        // Two units left at 12:00; the shelf was counted at 13:00 (8 left: the count takes them off); the dispatch arrives after.
        $this->book('count', $sku, 8, 'MAIN', '2026-09-26T13:00:00Z');
        $this->ok($this->ship($site, 'O-1', ['u1', 'u2'], '2026-09-26T12:00:00Z'));
        // The unit sent at 10:00 came back at 11:00 and was counted at 13:00 (the count puts it back); the reset arrives after.
        $this->book('count', $back, 10, 'MAIN', '2026-09-26T13:00:00Z');
        $this->ok($this->res->unship($site, 'O-2', ['w1'], '2026-09-26T11:00:00Z', $this->key('unship')));
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE note = 'pre_count'"), 'all three units arrived after the count');

        $x = $this->export();
        self::assertSame([], self::csv($x['files']['erpnext_stock_entry.csv']), 'the website sends ERPNext the sale and its reset itself');
        self::assertSame(['netted_out' => 5], array_filter($x['counts']['not_carried']), 'each count and the website events it took in add up to nothing');
    }

    /** RB12 (finding 3): ERPNext's "set to this number" makes ERPNext's figure whole at its time: what CW booked before it is not carried. */
    public function testAnErpnextCorrectionTakesInWhatCwBookedBeforeIt(): void
    {
        $site = $this->site('vapeandgo');
        $this->grant($site, 'erp_reconciliation');
        $sku = $this->item('legacy', 10);
        $this->erpLink('Corrected item', $sku);
        $this->listing($site, 'V1', $sku);
        $this->cut();
        $this->book('goods_in', $sku, 5);
        self::$db->exec('DO SLEEP(0.01)');
        $at = Clock::now()->format('Y-m-d\TH:i:s.u\Z');
        self::$db->exec('DO SLEEP(0.01)');
        $this->ok((new RelayIntake(self::$db))->reconciliations($site, ['doc_ref' => 'MAT-RECO-1', 'posted_at' => $at, 'lines' => [['variant_id' => 'V1', 'qty' => 8]]],
            $this->key('reco')));
        $id = (int) self::$db->value("SELECT id FROM count_review WHERE source = 'erp_reconciliation'");
        (new Corrections(self::$db))->confirm($this->staffUser('stock_controller'), $id, 'ERPNext counted it');
        $this->assertBal(8, 0, 0, $sku);
        $this->book('goods_in', $sku, 2);

        $x = $this->export();
        self::assertSame([['Material Receipt', 'Corrected item', '2']], array_map(static fn (array $r): array => self::pick($r, 'Stock Entry Type', 'Item Code (Items)',
            'Qty (Items)'), self::csv($x['files']['erpnext_stock_entry.csv'])), 'ERPNext holds 8 like CW; only the 2 after it are missing');
        self::assertSame(['in_erpnext' => 1, 'before_erp_correction' => 1], array_filter($x['counts']['not_carried']));
    }

    /** RB13 (finding 4): the cut-off is the earliest finished ERPNext opening; every finished run is named in the manifest. */
    public function testTheEarliestFinishedOpeningIsTheCutOff(): void
    {
        $sku = $this->item('legacy', 0);
        $this->erpLink('Opened item', $sku);
        $this->cut();
        $first = self::$db->insert("INSERT INTO erp_opening_run (doc_ref, as_of, file_name, file_sha256, status, actor, finished_at) "
            . "VALUES ('erp:a', ?, 'stock.csv', REPEAT('a', 64), 'done', 'system:opening_estimate', NOW(6))", [$this->since]);
        $this->book('goods_in', $sku, 5);
        self::$db->exec('DO SLEEP(0.01)');
        $second = self::$db->insert("INSERT INTO erp_opening_run (doc_ref, as_of, file_name, file_sha256, status, actor, finished_at) "
            . "VALUES ('erp:b', NOW(6), 'stock.csv', REPEAT('b', 64), 'done', 'system:opening_estimate', NOW(6))");

        $x = RollbackExport::run(self::$db, null, null);
        self::assertSame([$this->since, "erp_opening_run {$first}"], [$x['cut']['since'], $x['cut']['from']]);
        self::assertSame([$first, $second], array_column($x['cut']['runs'], 'id'));
        self::assertSame(1, $x['counts']['entries']['material_receipt'], 'the goods-in between the two runs is carried');
    }

    /**
     * RB14, RB24 (finding 5, and the reviews after it): a join of duplicates is CW's own and is not carried. ERPNext keeps both items, and a
     * later ERPNext correction of the kept one leaves the joined one's units in ERPNext (20 + 8 against CW's 20). Nothing sets ERPNext's
     * figures by itself: both items are listed to be set by hand, with CW's figure as ERPNext counts it.
     */
    public function testAJoinOfDuplicatesIsNotCarriedAndBothItemsAreListedToBeSetByHand(): void
    {
        ['site' => $site, 'keep' => $keep, 'dup' => $dup] = $this->joined();
        $this->assertBal(38, 0, 0, $keep);
        $this->grant($site, 'erp_reconciliation');
        $this->ok((new RelayIntake(self::$db))->reconciliations($site, ['doc_ref' => 'MAT-RECO-7', 'posted_at' => Clock::now()->format('Y-m-d\TH:i:s.u\Z'),
            'lines' => [['variant_id' => '23408', 'qty' => 20]]], $this->key('reco')));
        (new Corrections(self::$db))->confirm($this->staffUser('stock_controller'), (int) self::$db->value("SELECT id FROM count_review WHERE source = 'erp_reconciliation'"),
            'ERPNext counted it');
        $this->assertBal(20, 0, 0, $keep);
        $this->assertBal(0, 0, 0, $dup);

        $x = $this->export();
        self::assertSame([], self::csv($x['files']['erpnext_stock_entry.csv']));
        self::assertSame(RollbackExport::FILES, array_keys($x['files']), 'no file sets a figure in ERPNext by itself');
        self::assertSame(['in_erpnext' => 1, 'cw_join' => 2], array_filter($x['counts']['not_carried']));
        $code = static fn (int $id): string => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$id]);
        self::assertSame([[$code($keep), '20', '20', '0', 'Keep pods', $code($dup), Words::ROLLBACK_SET['cw_join']],
            [$code($dup), '0', '0', '0', 'Dup pods', $code($keep), Words::ROLLBACK_SET['cw_join']]],
            array_map(static fn (array $r): array => self::pick($r, 'Item', 'ERPNext figure', 'Units on hand', 'Paid, not sent', 'ERPNext items', 'Joined with', 'Why'),
                self::csv($x['files']['to_set_by_hand.csv'])));
        self::assertSame(2, $x['counts']['to_set_by_hand']);
    }

    /**
     * RB24, RB29 (the third review's 1, the fourth's 2): units held on the joined item stay its own, and so does its ERPNext item when it
     * reaches CW only through its listing (now the kept item's): both figures, and each code with the item it belonged to before the join.
     */
    public function testAJoinThatLeavesHeldUnitsOnTheJoinedItemListsBothFiguresAndItsOwnErpnextItem(): void
    {
        ['site' => $site, 'keep' => $keep, 'dup' => $dup] = $this->joined('live', static function (self $t, Caller $site): void {
            $t->ok($t->reserve($site, 'R-1', [self::line('25772', 'h1', 'h2')]));
        }, false);
        $this->assertBal(36, 0, 0, $keep);
        $this->assertBal(2, 0, 2, $dup);
        $path = $this->dir . '/item_list.csv';
        file_put_contents($path, "\"ID\",\"Product ID\"\r\n\"Dup listed\",\"25772\"\r\n");
        $code = static fn (int $id): string => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$id]);

        $x = $this->export(ErpOpeningFiles::itemMap($path));
        self::assertSame([['36', 'Keep pods | Dup listed (came with ' . $code($dup) . ' in the join)'], ['2', 'Dup listed (its own before the join)']],
            array_map(static fn (array $r): array => self::pick($r, 'ERPNext figure', 'ERPNext items'), self::csv($x['files']['to_set_by_hand.csv'])),
            'held units are not paid: ERPNext still counts them, under the ERPNext item the joined item had');
    }

    /** RB33 (the fifth review's 1): through a chain of joins (A into B, B into C) each code goes back to the item it belonged to first. */
    public function testAChainOfJoinsGivesEachCodeBackToTheItemItBelongedToFirst(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c, 'list' => $list, 'code' => $code] = $this->chainOfJoins();

        $rows = $this->handRows($this->export($list));
        self::assertSame(['2', 'A listed (its own before the join)'], $rows[$code($a)], 'the held units keep the code A had');
        self::assertSame(['0', 'B listed (its own before the join)'], $rows[$code($b)]);
        self::assertSame(['13', 'C pods | A listed (came with ' . $code($a) . ' in the join) | B listed (came with ' . $code($b) . ' in the join)'], $rows[$code($c)]);
    }

    /** RB34 (the sixth review's 1): a join undone (split back to the former item) is stepped over: the earlier join's code stays A's. */
    public function testAJoinUndoneIsSteppedOverAndTheEarlierJoinsCodeStaysItsOwn(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c, 'lb' => $lb, 'lead' => $lead, 'list' => $list, 'code' => $code] = $this->chainOfJoins();
        $this->decide($lead, 'split', $lb, ['reason' => 'B is another pod after all']);
        $this->assertBal(2, 0, 2, $a);
        $this->assertBal(3, 0, 0, $b);
        $this->assertBal(10, 0, 0, $c);

        $rows = $this->handRows($this->export($list));
        self::assertSame(['2', 'A listed (its own before the join)'], $rows[$code($a)], 'A\'s held units keep A\'s code');
        self::assertSame(['3', 'A listed (came with ' . $code($a) . ' in the join) | B listed'], $rows[$code($b)]);
        self::assertSame(['10', 'C pods'], $rows[$code($c)]);
    }

    /** RB30 (the fourth review's 1): a code two CW links read as one is shown with its other owner: it is settled before anything is set. */
    public function testACodeTwoLinksReadAsOneIsShownWithItsOtherOwner(): void
    {
        ['dup' => $dup] = $this->joined();
        self::$db->exec('INSERT INTO sku_erp_item (item_code, sku_id, units_per_item) VALUES (?, ?, 1)', [' Shared', $dup]);
        $active = $this->item('legacy', 7);
        $this->erpLink('Shared', $active);
        $code = static fn (int $id): string => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$id]);

        $x = $this->export();
        $rows = array_column(self::csv($x['files']['to_set_by_hand.csv']), 'ERPNext items', 'Item');
        self::assertSame('Dup pods | Shared (also linked to ' . $code($active) . ': settle which item it is first)', $rows[$code($dup)]);
    }

    /** RB25 (the third review's 4): of two ERPNext items of one unit, CW's own link is not the item's either: it is listed, never guessed. */
    public function testTwoErpnextItemsOfOneUnitAreNeverChosenBetween(): void
    {
        $site = $this->site('vapeandgo');
        $sku = $this->item('legacy', 10);
        $this->erpLink('Override', $sku);
        $this->listing($site, 'V1', $sku);
        $path = $this->dir . '/item_list.csv';
        file_put_contents($path, "\"ID\",\"Product ID\"\r\n\"Listed\",\"V1\"\r\n");
        $this->cut();
        $this->book('write_off', $sku, 2);

        $x = $this->export(ErpOpeningFiles::itemMap($path));
        self::assertSame([], self::csv($x['files']['erpnext_stock_entry.csv']));
        self::assertSame(['ambiguous_erp_item' => 1], array_filter($x['counts']['not_carried']));
    }

    /** RB28, RB31 (the third review's 9, the fourth's 3): a window bigger than rollback.max_rows stops before it is read, with how to go on; nothing is written. */
    public function testARunThatWouldReadMoreRowsThanTheLimitStopsAndSaysWhy(): void
    {
        $sku = $this->item('legacy', 0);
        $this->erpLink('Limited item', $sku);
        $this->cut();
        foreach ([1, 2, 3] as $q) {
            $this->book('goods_in', $sku, $q);
        }
        $this->setting('rollback.max_rows', '2');
        $read = [];
        self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use (&$read): void {
            if (str_contains($sql, 'LEFT JOIN stock_value_ledger')) {
                $read[] = $sql;
            }
        }]]);
        try {
            $this->export();
            self::fail('a run over the limit stops');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('rollback.max_rows', $e->getMessage());
        } finally {
            self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        self::assertSame([], $read, 'it stops on the count, before a row is read (RB31)');
        $r = self::script('rollback_export', '--out=' . $this->dir . '/big', '--since=' . Clock::iso($this->since));
        self::assertSame(3, $r['code']);
        self::assertStringContainsString('rollback.max_rows', $r['err']);
        self::assertDirectoryDoesNotExist($this->dir . '/big');
        $this->setting('rollback.max_rows', '3');
        self::assertSame(3, $this->export()['counts']['rows_carried']);
    }

    /** RB21 (the second review's 2): a job's movement under a queue row's key is not that queue's booking: it is carried. */
    public function testAJobsMovementUnderAQueueRowsKeyIsCarried(): void
    {
        $site = $this->site('vapeandgo');
        $this->grant($site, 'erp_reconciliation');
        $sku = $this->item('legacy', 10);
        $this->erpLink('Queue key item', $sku);
        $this->listing($site, 'V1', $sku);
        $this->cut();
        $this->ok((new RelayIntake(self::$db))->reconciliations($site, ['doc_ref' => 'MAT-RECO-2', 'posted_at' => Clock::now()->format('Y-m-d\TH:i:s.u\Z'),
            'lines' => [['variant_id' => 'V1', 'qty' => 8]]], $this->key('reco')));
        $id = (int) self::$db->value("SELECT id FROM count_review WHERE source = 'erp_reconciliation'");
        (new Corrections(self::$db))->confirm($this->staffUser('stock_controller'), $id, 'ERPNext counted it');
        self::$db->exec('DO SLEEP(0.01)');
        $this->ok($this->moves->record(Caller::system('test_job'), ['type' => 'goods_in', 'doc_ref' => 'JOB-1', 'lines' => [['sku_id' => $sku, 'qty' => 5]]],
            Corrections::KEY . $id));

        $x = $this->export();
        self::assertSame([['Material Receipt', 'Queue key item', '5']], array_map(static fn (array $r): array => self::pick($r, 'Stock Entry Type', 'Item Code (Items)',
            'Qty (Items)'), self::csv($x['files']['erpnext_stock_entry.csv'])), 'ERPNext holds 8; CW 13');
        self::assertSame(['in_erpnext' => 1], array_filter($x['counts']['not_carried']));
    }

    /** RB16, RB23 (finding 7; the second review's 4): two movements of two scopes under one key stay two families. */
    public function testTwoMovementsUnderOneKeyFromTwoScopesStayApart(): void
    {
        $sku = $this->item('legacy', 12);
        $this->erpLink('Box of six', $sku, 6);
        $this->cut();
        $this->ok($this->moves->record(self::staff(), ['type' => 'adjustment', 'lines' => [['sku_id' => $sku, 'qty' => 6]]], 'same-key'));
        $this->ok($this->moves->record(Caller::system('test_job'), ['type' => 'adjustment', 'lines' => [['sku_id' => $sku, 'qty' => -1]]], 'same-key'));

        $x = $this->export();
        self::assertSame([['Material Receipt', 'Box of six', '1']], array_map(static fn (array $r): array => self::pick($r, 'Stock Entry Type', 'Item Code (Items)',
            'Qty (Items)'), self::csv($x['files']['erpnext_stock_entry.csv'])), 'six units are one box; the job\'s one unit is listed');
        self::assertSame(['fractional_qty' => 1], array_filter($x['counts']['not_carried']));
    }

    /** RB15 (finding 6): only a booking OF an ERPNext queue row is ERPNext's; another movement that shares the document's name is carried. */
    public function testAMovementThatOnlySharesAnErpnextDocumentsNameIsCarried(): void
    {
        $site = $this->site('vapeandgo');
        $this->grant($site, 'goods_in');
        $sku = $this->item('legacy', 0);
        $this->erpLink('Shared name', $sku);
        $this->cut();
        $this->ok($this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-9', 'lines' => [['variant_id' => 'NOPE', 'qty' => 2]]], $this->key('relay')));
        $this->ok($this->moves->record(self::staff(), ['type' => 'goods_in', 'doc_ref' => 'ACC-PINV-9', 'lines' => [['sku_id' => $sku, 'qty' => 5]]], $this->key('own')));

        $x = $this->export();
        self::assertSame([['Material Receipt', 'Shared name', '5']], array_map(static fn (array $r): array => self::pick($r, 'Stock Entry Type', 'Item Code (Items)',
            'Qty (Items)'), self::csv($x['files']['erpnext_stock_entry.csv'])));
    }

    /** RB17 (finding 8): the settings are read inside the snapshot the stock is read in. */
    public function testTheSettingsAreReadInTheSnapshotOfTheStock(): void
    {
        $this->cut();
        $seen = [];
        self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [StatementHook::class, [static function (string $sql) use (&$seen): void {
            if (str_contains($sql, 'app_setting')) {
                $seen[] = self::$db->inTransaction();
            }
        }]]);
        try {
            RollbackExport::run(self::$db, null, $this->since);
        } finally {
            self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        self::assertNotSame([], $seen);
        self::assertSame([true], array_values(array_unique($seen)));
    }

    // ---- helpers --------------------------------------------------------------------------------------------------------------------

    /**
     * Two duplicate items joined after the cut-off (as DuplicateMergeTest's Corex pair): the kept item 30 + 8, each with its ERPNext link.
     *
     * @param ?\Closure(self, Caller): void $before what happens on the store before the join (after the cut-off)
     * @param bool $dupLink whether the joined item has CW's own ERPNext link (else ERPNext reaches it through its listing only)
     * @return array{site: Caller, keep: int, dup: int}
     */
    private function joined(string $mode = 'shadow', ?\Closure $before = null, bool $dupLink = true): array
    {
        $site = $this->site('vapeandgo', $mode);
        $keep = $this->item('legacy', 30, 'Corex 3.0 Pods (Pack of 4) - 0.4 ohm');
        $dup = $this->item('legacy', 8, 'Corex Replacement Pods - 0.4ohm Corex 3.0 Pod - 4 Pack');
        $this->erpLink('Keep pods', $keep);
        if ($dupLink) {
            $this->erpLink('Dup pods', $dup);
        }
        $a = $this->listing($site, '23408', $keep);
        $b = $this->listing($site, '25772', $dup);
        foreach ([[$a, 'Corex 3.0 Pods (Pack of 4)', '0.4 ohm'], [$b, 'Corex Replacement Pods', '0.4ohm Corex 3.0 Pod - 4 Pack']] as [$id, $pt, $vt]) {
            self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, barcodes, features) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $pt, $vt, 'Vaporesso', '[]', json_encode(['form' => 'pod_refill', 'resistance_ohm' => 0.4, 'pack_units' => 4, 'line_numbers' => ['3.0']], JSON_THROW_ON_ERROR)]);
        }
        $run = $this->proposals->run('run2-vpg-duplicates', 'vpg_duplicates');
        $pb = $this->proposals->add(Caller::system('mint_vpg'), $b, $run, ['proposed_sku_id' => $keep, 'band' => 'Manual', 'lane' => 'vpg_duplicate',
            'flags' => ['merge_suggestion', 'identity_key'], 'evidence' => ['group' => 7, 'kind' => 'identity_key', 'keeper' => ['vpg_variant_id' => '23408', 'sku_id' => $keep],
            'members' => [['vpg_variant_id' => '23408', 'sku_id' => $keep, 'units_30d' => 1], ['vpg_variant_id' => '25772', 'sku_id' => $dup, 'units_30d' => 1]]]], false)['proposal_id'];
        $this->cut();
        if ($before !== null) {
            $before($this, $site);
        }
        $r = $this->decide($this->staffUser('mapping_lead'), 'merge_skus', $b, ['sku_id' => $keep, 'merge_from_sku_id' => $dup, 'proposal_id' => $pb]);
        self::assertSame('applied', $r['state']);
        return ['site' => $site, 'keep' => $keep, 'dup' => $dup];
    }

    /**
     * Three duplicates joined in a chain after the cut-off: A (5, two of them held for a checkout) into B (0), then B into C (10); only C has
     * CW's own ERPNext link, A and B reach ERPNext through their listings (the item list given back).
     *
     * @return array{a: int, b: int, c: int, lb: int, lead: Caller, list: array<string, mixed>, code: \Closure(int): string}
     */
    private function chainOfJoins(): array
    {
        $site = $this->site('vapeandgo', 'live');
        $c = $this->item('legacy', 10, 'Pods C 0.4 ohm');
        $b = $this->item('legacy', 0, 'Pods B 0.4 ohm');
        $a = $this->item('legacy', 5, 'Pods A 0.4 ohm');
        $this->erpLink('C pods', $c);
        $lc = $this->listing($site, '100', $c);
        $lb = $this->listing($site, '200', $b);
        $la = $this->listing($site, '300', $a);
        foreach ([$lc, $lb, $la] as $id) {
            self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, barcodes, features) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, 'Corex 3.0 Pods (Pack of 4)', '0.4 ohm', 'Vaporesso', '[]', json_encode(['form' => 'pod_refill', 'resistance_ohm' => 0.4, 'pack_units' => 4,
                    'line_numbers' => ['3.0']], JSON_THROW_ON_ERROR)]);
        }
        $run = $this->proposals->run('run2-vpg-duplicates', 'vpg_duplicates');
        $suggest = fn (int $listing, string $variant, int $from, int $to, string $toVariant): int => $this->proposals->add(Caller::system('mint_vpg'), $listing,
            $run, ['proposed_sku_id' => $to, 'band' => 'Manual', 'lane' => 'vpg_duplicate', 'flags' => ['merge_suggestion', 'identity_key'],
            'evidence' => ['group' => 9, 'kind' => 'identity_key', 'keeper' => ['vpg_variant_id' => $toVariant, 'sku_id' => $to], 'members' => [
                ['vpg_variant_id' => $toVariant, 'sku_id' => $to, 'units_30d' => 1], ['vpg_variant_id' => $variant, 'sku_id' => $from, 'units_30d' => 1]]]], false)['proposal_id'];
        $pa = $suggest($la, '300', $a, $b, '200');
        $this->cut();
        $this->ok($this->reserve($site, 'R-1', [self::line('300', 'h1', 'h2')]));
        $lead = $this->staffUser('mapping_lead');
        self::assertSame('applied', $this->decide($lead, 'merge_skus', $la, ['sku_id' => $b, 'merge_from_sku_id' => $a, 'proposal_id' => $pa])['state']);
        $pb = $suggest($lb, '200', $b, $c, '100');
        self::assertSame('applied', $this->decide($lead, 'merge_skus', $lb, ['sku_id' => $c, 'merge_from_sku_id' => $b, 'proposal_id' => $pb])['state']);
        $this->assertBal(13, 0, 0, $c);
        $this->assertBal(2, 0, 2, $a);
        $path = $this->dir . '/item_list.csv';
        file_put_contents($path, "\"ID\",\"Product ID\"\r\n\"A listed\",\"300\"\r\n\"B listed\",\"200\"\r\n");
        return ['a' => $a, 'b' => $b, 'c' => $c, 'lb' => $lb, 'lead' => $lead, 'list' => ErpOpeningFiles::itemMap($path),
            'code' => static fn (int $id): string => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$id])];
    }

    /** @param array<string, mixed> $x run() @return array<string, array{0: string, 1: string}> the hand list by item: [ERPNext figure, ERPNext items] */
    private function handRows(array $x): array
    {
        $out = [];
        foreach (self::csv($x['files']['to_set_by_hand.csv']) as $r) {
            $out[$r['Item']] = [$r['ERPNext figure'], $r['ERPNext items']];
        }
        return $out;
    }

    /** CW's ERPNext link of an item (sku_erp_item). */
    private function erpLink(string $itemCode, int $sku, int $u = 1): void
    {
        self::$db->exec('INSERT INTO sku_erp_item (item_code, sku_id, units_per_item) VALUES (?, ?, ?)', [$itemCode, $sku, $u]);
    }

    /** The cut-off: from now on rows are read (the database clock, as stock_ledger.created_at). */
    private function cut(): void
    {
        self::$db->exec('DO SLEEP(0.01)');
        $this->since = (string) self::$db->value('SELECT NOW(6)');
        self::$db->exec('DO SLEEP(0.01)');
    }

    /**
     * @param array<string, mixed>|null $itemMap ErpOpeningFiles::itemMap()
     * @return array<string, mixed> RollbackExport::run()
     */
    private function export(?array $itemMap = null): array
    {
        return RollbackExport::run(self::$db, $itemMap, $this->since);
    }

    /** @return list<array<string, string>> a CSV's rows by their header (quoted line breaks kept) */
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

    /** @param array<string, string> $row @return list<string> */
    private static function pick(array $row, string ...$columns): array
    {
        return array_map(static fn (string $c): string => $row[$c], $columns);
    }

    /** @return array{code: int, out: string, err: string} */
    private static function script(string $name, string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
