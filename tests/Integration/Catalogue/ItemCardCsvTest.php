<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Catalogue;

use CW\Catalogue\ItemCardCsv;
use CW\Catalogue\ItemCardList;
use CW\CwException;

/**
 * The item cards as CSV (IM3 "Bulk: CSV export and import"; docs/decisions.md I110, I120): the export in list order (most stock
 * first) and formula-safe; the import's dry run (the default) and real run; a file with any refused row changes nothing; the card
 * version guard; unknown columns refused, the export's own columns read past; an empty cell changes nothing; a flavour from a file
 * is proposed and a file never confirms; the round trip of an untouched export changes nothing; the cap counts the rows that CHANGE
 * a card, not the file's rows; the CLI and where a run came from.
 */
final class ItemCardCsvTest extends CatalogueTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw-cards-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function file(string $csv, string $name = 'cards.csv'): string
    {
        $path = "{$this->dir}/{$name}";
        file_put_contents($path, $csv);
        return $path;
    }

    private static function code(int $sku): string
    {
        return sprintf('CW-%06d', $sku);
    }

    /** @return array<string, mixed> */
    private function import(string $csv, bool $apply, ?\CW\Caller $who = null): array
    {
        return (new ItemCardCsv(self::$db))->import($who ?? $this->editor(), $this->file($csv), 'cards.csv', $apply);
    }

    public function testTheExportIsInStockOrderAndFormulaSafe(): void
    {
        $who = $this->editor();
        $low = $this->item('legacy', 5, 'Low stock');
        $high = $this->item('legacy', 50, 'High stock');
        $none = $this->item('legacy', 0, '=HYPERLINK("http://x")');
        $this->cards->save($who, $high, 0, ['product_type' => 'tank', 'liquid_ml' => '5', 'duty_liable' => 'no', 'flavour' => '-Mint', 'ecid' => '12345-16-12345']);
        $this->cards->confirm($who, $high, 1, true);
        $csv = ItemCardCsv::export((new ItemCardList(self::$db))->rows(ItemCardList::filters([]), null));
        self::assertStringStartsWith("\xEF\xBB\xBF\"code\",\"name\",\"catalogue_brand\",\"stock_held\",\"product_type\",\"liquid_ml\",\"nicotine_mg\",\"duty_liable\",\"single_use\","
            . "\"ecid\",\"manufacturer\",\"brand\",\"flavour\",\"flavour_status\",\"discontinued\",\"card_version\",\"confirmed\",\"confirmed_by\",\"confirmed_at\",\"warnings\"\r\n", $csv);
        $lines = explode("\r\n", trim($csv));
        self::assertStringStartsWith('"' . self::code($high) . '","High stock","",50,"tank",5.0,,"no","","12345-16-12345","","","\'-Mint","confirmed","no",2,"yes"', $lines[1]);
        self::assertStringEndsWith('"BLOCKED: tank or pod over 2 ml"', $lines[1]);
        self::assertStringStartsWith('"' . self::code($low) . '","Low stock","",5,', $lines[2]);
        self::assertStringStartsWith('"' . self::code($none) . '","\'=HYPERLINK(""http://x"")","",0,', $lines[3], 'a name that would run as a formula is text');
        self::assertStringContainsString(',"no",0,"no card",', $lines[3]);
        self::assertCount(4, $lines);
    }

    public function testDryRunThenApplyAndTheRules(): void
    {
        $who = $this->editor();
        [$a, $b, $c] = [$this->item('legacy', 0, 'A'), $this->item('legacy', 0, 'B'), $this->item('legacy', 0, 'C')];
        $this->cards->save($who, $c, 0, ['brand' => 'Old']);
        $csv = "code;product_type;liquid_ml;nicotine_mg;duty_liable;single_use;flavour;brand;discontinued;card_version;name;warnings\r\n"
            . self::code($a) . ";E-liquid;10;1.7%;yes;;Blue Razz;Elux;;;whatever;ignored\r\n"
            . "{$b};;;;;;;;yes;0;;\r\n"
            . self::code($c) . ";;;;;;;;;;;\r\n";
        $dry = $this->import($csv, false, $who);
        self::assertSame([false, false, 3, 2, 1, 0, ['name', 'warnings']], [$dry['apply'], $dry['applied'], $dry['rows'], $dry['changed'], $dry['unchanged'],
            $dry['errors_total'], $dry['ignored_columns']]);
        self::assertSame([[1, self::code($a), ['product_type', 'liquid_ml', 'nicotine_mg', 'duty_liable', 'brand', 'flavour', 'flavour_status'], false],
            [2, self::code($b), ['discontinued'], false]], array_map(static fn (array $x): array => [$x['row'], $x['code'], $x['fields'], $x['unconfirmed']], $dry['changes']));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_card WHERE sku_id IN (?, ?)', [$a, $b]), 'a dry run writes no card');
        self::assertSame(['item_cards', 1, 'done', 3], array_values(array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, (array) self::$db->one(
            'SELECT kind, dry_run, status, rows_read FROM import_run WHERE id = ?', [$dry['run_id']]))), 'every run is recorded');

        $run = $this->import($csv, true, $who);
        self::assertSame([true, true, 2, 0], [$run['apply'], $run['applied'], $run['changed'], $run['errors_total']]);
        $ra = $this->row($a);
        self::assertSame(['e_liquid', '10.0', '17.00', 1, null, 'Blue Razz', 'proposed', 'Elux', 0, null], [$ra['product_type'], $ra['liquid_ml'], $ra['nicotine_mg'],
            (int) $ra['duty_liable'], $ra['single_use'], $ra['flavour'], $ra['flavour_status'], $ra['brand'], (int) $ra['discontinued'], $ra['confirmed_at']],
            'a flavour from a file is proposed; a file never confirms');
        self::assertSame(1, (int) $this->row($b)['discontinued']);
        self::assertSame('Old', $this->row($c)['brand'], 'an empty cell changes nothing');
        self::assertSame('import', $this->cards->history($a)[0]['kind']);
        self::assertSame($run['run_id'], $this->cards->history($a)[0]['detail']['import_run']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'item_card.import'"));
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'item_card.change' AND JSON_UNQUOTE(JSON_EXTRACT(detail, '$.kind')) = 'import'"));

        // The same file again: card_version 0 on B's row is stale now; nothing at all is changed.
        $again = $this->import($csv, true, $who);
        self::assertSame([false, 1], [$again['applied'], $again['errors_total']]);
        self::assertSame([2, self::code($b), 'card_version'], [$again['errors'][0]['row'], $again['errors'][0]['code'], $again['errors'][0]['column']]);
        self::assertStringContainsString('the card changed since the export (version 1 now, the file has 0)', $again['errors'][0]['message']);
        self::assertSame('failed', self::$db->value('SELECT status FROM import_run WHERE id = ?', [$again['run_id']]));
    }

    public function testAFileWithAnyProblemChangesNothing(): void
    {
        $who = $this->editor();
        $a = $this->item('legacy', 0, 'A');
        $b = $this->item('legacy', 0, 'B');
        $merged = $this->item('legacy', 0, 'Merged');
        $csv = "code,product_type,liquid_ml,nicotine_mg,single_use\n"
            . self::code($a) . ",tank,5,,\n"
            . self::code($b) . ",disposable,2.55,200,maybe\n"
            . "CW-999999,,,,\n"
            . "nope,,,,\n"
            . self::code($a) . ",coil,,,\n"
            . self::code($merged) . ",single-use vape,,,\n";
        $r = $this->import($csv, true, $who);
        self::assertFalse($r['applied']);
        self::assertSame([
            [2, self::code($b), 'product_type'], [2, self::code($b), 'liquid_ml'], [2, self::code($b), 'nicotine_mg'], [2, self::code($b), 'single_use'],
            [3, 'CW-999999', 'code'], [4, 'nope', 'code'], [5, self::code($a), 'code'], [6, self::code($merged), 'single_use'],
        ], array_map(static fn (array $e): array => [$e['row'], $e['code'], $e['column']], $r['errors']));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_card'), 'the good row was not kept either');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_card_change'));

        $bad = static fn (string $csv): CwException => self::refused(400, 'bad_file', fn () => (new ItemCardCsv(self::$db))->import($who, self::fileOf($csv), 'x.csv', false));
        self::assertSame(['nicotine', 'colour'], $bad("code,nicotine,colour\nCW-000001,20,red\n")->detail['columns'], 'a typo is refused, not dropped');
        self::assertStringContainsString('needs a "code" column', $bad("sku,brand\n1,X\n")->getMessage());
        self::refused(403, 'role_not_allowed', fn () => $this->import("code\n" . self::code($a) . "\n", false, $this->staffUser('buyer')));
        self::refused(403, 'admin_cannot_edit', fn () => $this->import("code\n" . self::code($a) . "\n", false, $this->staffUser('admin')));
    }

    public function testTheCapCountsChangedRowsOnly(): void
    {
        $who = $this->editor();
        $items = [];
        foreach (range(1, 6) as $i) {
            $items[] = $this->item('legacy', $i, "Item {$i}");
        }
        $merged = $this->item('legacy', 0, 'Merged away');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$items[0], $merged]);
        // Six rows, two of which change a card: a cap of 2 changed rows takes the file; a cap of 1 refuses it whole.
        $csv = "code,product_type,nicotine_mg,discontinued\n";
        foreach ($items as $i => $sku) {
            $csv .= self::code($sku) . ($i < 2 ? ',coil,,yes' : ',,,') . "\n";
        }
        $csv .= self::code($merged) . ",,,\n";
        $e = self::refused(413, 'too_many_changes', fn () => (new ItemCardCsv(self::$db))->import($who, $this->file($csv), 'cards.csv', true, maxChanges: 1));
        self::assertStringContainsString('This file would change 2 item cards; at most 1 are changed in one import. Nothing was imported', $e->getMessage());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_card'));
        $run = (array) self::$db->one("SELECT status, summary FROM import_run WHERE kind = 'item_cards' ORDER BY id DESC LIMIT 1");
        self::assertSame('failed', $run['status'], 'the refused run is recorded');
        self::assertStringContainsString('This file would change 2 item cards', (string) json_decode((string) $run['summary'], true)['refused']);
        $r = (new ItemCardCsv(self::$db))->import($who, $this->file($csv), 'cards.csv', true, maxChanges: 2);
        self::assertSame([true, 7, 2, 5], [$r['applied'], $r['rows'], $r['changed'], $r['unchanged']], 'a merged item with empty cells changes nothing');
        self::assertSame(['screen'], [json_decode((string) self::$db->value('SELECT summary FROM import_run WHERE id = ?', [$r['run_id']]), true)['origin']['via']]);

        // A merged item with a value, and a bare "pod": refused rows; nothing changes.
        $bad = (new ItemCardCsv(self::$db))->import($who, $this->file("code,product_type\n" . self::code($merged) . ",coil\n" . self::code($items[3]) . ",pods\n"), 'b.csv', true);
        self::assertSame([false, 2], [$bad['applied'], $bad['errors_total']]);
        self::assertStringContainsString(sprintf('was merged into %s: change that item\'s card instead', self::code($items[0])), $bad['errors'][0]['message']);
        self::assertSame('product_type', $bad['errors'][1]['column']);
        self::assertNull(self::$db->value('SELECT product_type FROM item_card WHERE sku_id = ?', [$items[3]]));
    }

    /** A card changed by someone between the check and the save refuses the whole file (the second step re-checks each version under its lock). */
    public function testACardChangedDuringTheImportRefusesTheFile(): void
    {
        $who = $this->editor();
        [$a, $b] = [$this->item('legacy', 0, 'A'), $this->item('legacy', 0, 'B')];
        $csv = new ItemCardCsv(self::$db);
        $work = [['row' => 1, 'sku' => $a, 'code' => self::code($a), 'version' => 0, 'input' => ['brand' => 'X']],
            ['row' => 2, 'sku' => $b, 'code' => self::code($b), 'version' => 0, 'input' => ['brand' => 'Y']]];
        $this->cards->save($who, $b, 0, ['brand' => 'Typed on the screen']);
        $report = ['file' => 'x.csv', 'sha256' => str_repeat('0', 64), 'rows' => 2, 'changed' => 2, 'unchanged' => 0, 'errors' => [], 'errors_total' => 0,
            'origin' => ['via' => 'screen'], 'applied' => false];
        $write = new \ReflectionMethod(ItemCardCsv::class, 'write');
        $args = [$who, $work, 1, &$report];
        $write->invokeArgs($csv, $args);
        self::assertSame([false, 1, 2], [$report['applied'], $report['errors_total'], $report['errors'][0]['row']]);
        self::assertStringContainsString('the card changed while the file was being imported', $report['errors'][0]['message']);
        self::assertNull(self::$db->value('SELECT brand FROM item_card WHERE sku_id = ?', [$a]), 'row 1 was rolled back with the rest');
        self::assertSame('Typed on the screen', self::$db->value('SELECT brand FROM item_card WHERE sku_id = ?', [$b]));
    }

    public function testTheRoundTripOfAnUntouchedExportChangesNothing(): void
    {
        $who = $this->editor();
        $site = $this->site('vpg');
        $skus = [];
        foreach ([['e_liquid', '10', '20', 'yes', null, 'Elux', 'Cola Ice'], ['shortfill', '50', '0', 'yes', null, 'Bar Juice', '=1+1'], ['device_kit', '2', null, 'no', 'no', null, null],
            ['single_use', '2', '20', 'yes', 'yes', 'Old Disposables', '@risk']] as $i => [$type, $ml, $mg, $duty, $single, $brand, $flavour]) {
            $sku = $this->item('legacy', 10 * ($i + 1), "Item {$i}");
            $this->cards->save($who, $sku, 0, array_filter(['product_type' => $type, 'liquid_ml' => $ml, 'nicotine_mg' => $mg, 'duty_liable' => $duty,
                'single_use' => $single, 'brand' => $brand, 'flavour' => $flavour, 'ecid' => '12345-16-1234' . $i], static fn ($v): bool => $v !== null));
            $skus[] = $sku;
        }
        $this->cards->confirm($who, $skus[0], 1);
        $this->listing($site, 'L1', $skus[0]);
        $before = self::$db->value('SELECT COUNT(*) FROM item_card_change');
        $export = ItemCardCsv::export((new ItemCardList(self::$db))->rows(ItemCardList::filters([]), null));
        $r = $this->import($export, true, $who);
        self::assertSame([true, 4, 0, 4, 0], [$r['applied'], $r['rows'], $r['changed'], $r['unchanged'], $r['errors_total']],
            'every value reads back as it was stored, the formula-protected ones included');
        self::assertSame($before, self::$db->value('SELECT COUNT(*) FROM item_card_change'));
        self::assertNotNull($this->row($skus[0])['confirmed_at'], 'still confirmed');
        self::assertSame(['=1+1', '@risk'], [$this->row($skus[1])['flavour'], $this->row($skus[3])['flavour']]);
        // Edited in "Excel": the header and one row kept, its strength changed; the confirmed card becomes unconfirmed.
        $lines = explode("\r\n", trim(substr($export, 3)));
        self::assertStringStartsWith('"' . self::code($skus[0]) . '"', $lines[4], 'the least stock comes last');
        $r = $this->import($lines[0] . "\r\n" . str_replace(',20.00,', ',18.00,', $lines[4]), true, $who);
        self::assertSame([1, ['nicotine_mg'], true], [$r['changed'], $r['changes'][0]['fields'], $r['changes'][0]['unconfirmed']]);
        self::assertSame('18.00', $this->row($skus[0])['nicotine_mg']);
    }

    public function testTheCli(): void
    {
        $who = $this->editor();
        $email = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$who->staffUserId]);
        $a = $this->item('legacy', 0, 'A');
        $file = $this->file("code,nicotine_mg\n" . self::code($a) . ",20\n");
        $dry = self::tool('import_item_cards', "--file={$file}", "--staff={$email}");
        self::assertSame(0, $dry['code'], $dry['err']);
        self::assertStringContainsString('DRY RUN (nothing written) cards.csv run ', $dry['out']);
        self::assertStringContainsString('rows=1 would_change=1 unchanged=0 refused=0', $dry['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_card'));
        $report = "{$this->dir}/report.csv";
        $apply = self::tool('import_item_cards', "--file={$file}", "--staff={$email}", '--apply', "--report={$report}");
        self::assertSame(0, $apply['code'], $apply['err']);
        self::assertStringContainsString('rows=1 changed=1', $apply['out']);
        self::assertSame('20.00', $this->row($a)['nicotine_mg']);
        self::assertStringContainsString('"changed","nicotine_mg"', (string) file_get_contents($report));
        $origin = json_decode((string) self::$db->value("SELECT summary FROM import_run WHERE kind = 'item_cards' AND dry_run = 0 ORDER BY id DESC LIMIT 1"), true)['origin'];
        self::assertSame('cli', $origin['via'], 'a server import is told apart from a screen import');
        self::assertNotSame('', $origin['os_user']);
        self::assertSame('cli', json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'item_card.import' ORDER BY id DESC LIMIT 1"), true)['origin']['via']);
        $capped = self::tool('import_item_cards', '--file=' . $this->file("code,brand\n" . self::code($a) . ",X\n" . self::code($this->item('legacy', 0, 'B')) . ",Y\n", 'two.csv'),
            "--staff={$email}", '--max-changes=1');
        self::assertSame(1, $capped['code']);
        self::assertStringContainsString('too_many_changes', $capped['err']);
        $bad = self::tool('import_item_cards', '--file=' . $this->file("code,nicotine_mg\n" . self::code($a) . ",abc\n", 'bad.csv'), "--staff={$email}", '--apply');
        self::assertSame(1, $bad['code']);
        self::assertStringContainsString('NOTHING SAVED', $bad['out']);
        self::assertStringContainsString('row 1 (' . self::code($a) . '), nicotine_mg: The nicotine is a number of mg/ml', $bad['out']);
        $buyer = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$this->staffUser('buyer')->staffUserId]);
        self::assertSame(2, self::tool('import_item_cards', "--file={$file}", "--staff={$buyer}")['code'], 'a buyer cannot change item cards');
        self::assertSame(2, self::tool('import_item_cards', "--staff={$email}")['code']);
    }

    private static function fileOf(string $csv): string
    {
        $p = tempnam(sys_get_temp_dir(), 'cwic');
        file_put_contents($p, $csv);
        return $p;
    }
}
