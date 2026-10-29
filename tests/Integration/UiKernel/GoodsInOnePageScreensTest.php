<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Catalogue\ItemCards;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Receiving\GoodsReceipts;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Controller\ReceivingController;
use CW\Ui\Words;

/**
 * Goods in on ONE page (the owner's approved design of 29 Oct 2026; docs/decisions.md GV): the Goods in board (Arriving, Being checked,
 * Ready to book in, Booked in today; Show; one search) and one delivery on one page (the details, the scan box, the lines as a board
 * with "All fine" / "Problem", the problem panel on the row, the prices fold for the buying desk only, photos and papers, the sticky bar
 * with "Book in N items"), through the real /ui kernel as cw_app. Every receiving rule stays the service's (tests/Integration/Receiving
 * unchanged): the creator's lines, the bench's findings and their stale-save stamps, the duty answers, the invoice copy, booking in.
 */
final class GoodsInOnePageScreensTest extends KernelUiTestCase
{
    private string $dir;
    private string|false $prevStore = false;
    private ?string $prevCutoff = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_gi_ui_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        mkdir($this->dir . '/store', 0700);
        $this->prevStore = getenv('CW_FILE_STORE_DIR');
        putenv('CW_FILE_STORE_DIR=' . $this->dir . '/store');
        $this->prevCutoff = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'receiving.unstamped_refusal_from'");
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'",
            [json_encode(gmdate('Y-m-d', time() + 365 * 86400), JSON_THROW_ON_ERROR)]);
    }

    protected function tearDown(): void
    {
        putenv($this->prevStore === false ? 'CW_FILE_STORE_DIR' : 'CW_FILE_STORE_DIR=' . $this->prevStore);
        if ($this->prevCutoff !== null) {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'receiving.unstamped_refusal_from'", [$this->prevCutoff]);
        }
        exec('chmod -R u+w ' . escapeshellarg($this->dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------------------------------

    /** An active supplier created by $buyerId and approved by a fresh reviewer. @return array<string, mixed> */
    private function supplier(int $buyerId, string $name): array
    {
        $sup = new Suppliers(self::$db);
        $s = $sup->create(Caller::staff($buyerId), ['name' => $name, 'address_line1' => '2 Mill Lane', 'postcode' => 'M1 1AA',
            'email' => 'sales@' . strtolower(str_replace(' ', '', $name)) . '.example', 'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 7 * 86400),
            'dd_checked_by' => (string) $buyerId, 'dd_next_review_on' => gmdate('Y-m-d', time() + 365 * 86400)]);
        $s = $sup->requestActivation(Caller::staff($buyerId), (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND subject_id = ? AND state = 'open'", [(int) $s['id']]);
        return $sup->approve(Caller::staff($this->uiUser('reviewer')['id']), $task, null);
    }

    /** A duty-liable e-liquid (10 ml) or a coil (no duty) item card. */
    private function card(int $sku, bool $liquid): void
    {
        $editor = Caller::staff($this->uiUser('stock_controller')['id']);
        (new ItemCards(self::$db))->save($editor, $sku, 0, $liquid ? ['product_type' => 'e-liquid', 'liquid_ml' => '10', 'nicotine_mg' => '6', 'duty_liable' => 'yes']
            : ['product_type' => 'coil', 'duty_liable' => 'no']);
    }

    private function pdf(): string
    {
        $p = new \CW\Output\PdfWriter('Invoice ' . bin2hex(random_bytes(4)), 'test', true);
        $path = $this->dir . '/invoice-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($path, $p->output());
        return $path;
    }

    private function receipts(): GoodsReceipts
    {
        return new GoodsReceipts(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)));
    }

    private static function docVersion(int $id): int
    {
        return (int) self::$db->value('SELECT version FROM document WHERE id = ?', [$id]);
    }

    /**
     * A draft delivery keyed by $creatorId through the service: its lines ({sku_id, packs, units_per_pack?, supplier_item_id?, pack_price?}).
     *
     * @param list<array<string, mixed>> $lines
     */
    private function delivery(int $creatorId, int $supplierId, array $lines, ?string $invoice = null): int
    {
        $svc = $this->receipts();
        $d = $svc->createDraft(Caller::staff($creatorId), $supplierId, $invoice === null ? [] : ['invoice_number' => $invoice]);
        $svc->saveDraft(Caller::staff($creatorId), $d->id, $d->version, [], $lines);
        return $d->id;
    }

    /** The one page's form (its fields as a browser sends them, text areas too). @return array<string, string> */
    private static function onePage(UiResponse $page, int $id): array
    {
        $f = $page->form("/ui/receiving/{$id}/lines", true);
        self::assertNotSame([], $f, 'the one page has its form: ' . $page->describe() . ' ' . implode(' | ', array_slice(self::$log, -2)));
        return $f;
    }

    /** The fields of the form inside the board's row that holds $text (the Arriving group's "Receive"). @return array<string, string> */
    private static function rowForm(UiResponse $page, string $text): array
    {
        $xp = new \DOMXPath($page->dom());
        $forms = $xp->query('//tr[contains(normalize-space(.), ' . self::xq($text) . ')]//form');
        self::assertNotFalse($forms);
        self::assertSame(1, $forms->length, "one form in the row of {$text}");
        $out = [];
        foreach ($xp->query('.//input', $forms->item(0)) ?: [] as $in) {
            /** @var \DOMElement $in */
            if ($in->getAttribute('name') !== '' && !in_array($in->getAttribute('type'), ['submit', 'button'], true)) {
                $out[$in->getAttribute('name')] = $in->getAttribute('value');
            }
        }
        return $out;
    }

    private static function xq(string $s): string
    {
        return str_contains($s, "'") ? 'concat("' . $s . '", "")' : "'" . $s . "'";
    }

    /** The text of the first element matching $xpath ('' when none), spaces collapsed. */
    private static function textOf(UiResponse $page, string $xpath): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) (new \DOMXPath($page->dom()))->evaluate('string(' . $xpath . ')')));
    }

    /** The board's groups as they read: name => the rows' first cells. @return array<string, list<string>> */
    private static function boardGroups(UiResponse $page): array
    {
        $xp = new \DOMXPath($page->dom());
        $out = [];
        foreach ($xp->query('//div[contains(concat(" ", @class, " "), " doc-group ")]') ?: [] as $g) {
            /** @var \DOMElement $g */
            $name = trim((string) $xp->evaluate('string(.//button[contains(@class, "grp-toggle")])', $g));
            $rows = [];
            foreach ($xp->query('.//tbody/tr/th', $g) ?: [] as $th) {
                $rows[] = trim((string) preg_replace('/\s+/u', ' ', $th->textContent));
            }
            $out[$name] = $rows;
        }
        return $out;
    }

    private function onHand(int $sku, string $warehouse): int
    {
        return (int) self::$db->value('SELECT COALESCE(SUM(b.on_hand), 0) FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id WHERE w.code = ? AND b.sku_id = ?',
            [$warehouse, $sku]);
    }

    /** A buyer, an approved order of 3 boxes of 5 of a liquid, the supplier, the item. @return array{buyer: array<string, mixed>, s: array<string, mixed>, sku: int, po: int, number: string} */
    private function orderOfThreeBoxes(string $supplier): array
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier($buyer['id'], $supplier);
        $sku = self::makeSku($supplier . ' liquid 10ml');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES (?, ?, 5)", ['50' . str_pad((string) $sku, 11, '0', STR_PAD_LEFT), $sku]);
        $this->card($sku, true);
        $si = (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $s['id'], $sku, ['units_per_pack' => '5', 'supplier_code' => 'BOX5'], ['pack_price' => '10.00']);
        $docs = new Documents(self::$db, DocumentHandlers::all(self::$db));
        $pos = new PurchaseOrders(self::$db, $docs);
        $po = $pos->createDraft(Caller::staff($buyer['id']), (int) $s['id'], []);
        $po = $pos->saveDraft(Caller::staff($buyer['id']), $po->id, $po->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 3]]);
        $po = $pos->approve(Caller::staff($buyer['id']), $po->id, $po->version);
        return ['buyer' => $buyer, 's' => $s, 'sku' => $sku, 'po' => $po->id, 'number' => (string) $po->number];
    }

    // ------------------------------------------------------------------------------------------
    // The one page
    // ------------------------------------------------------------------------------------------

    /**
     * The owner's flow on one page: the board's Arriving row "Receive" starts the delivery with the order's lines; the page shows its
     * details, the progress strip, the scan box and the lines as a board; one save (no script: the row's "All fine" sends the form) records
     * the paperwork, the invoice number, the duty stamp, the line checked and the invoice copy; "Book in 15 items" books it in.
     */
    public function testOnePageEditsChecksAndBooksIn(): void
    {
        $o = $this->orderOfThreeBoxes('One Page Supplies');
        $desk = $this->signIn($this->uiUser('purchasing_desk'));
        $board = $desk->get('/ui/receiving');
        self::assertSame(200, $board->status, $board->describe() . ' ' . implode(' | ', array_slice(self::$log, -2)));
        self::assertContains($o['number'], array_map(static fn (string $r): string => explode(' ', $r)[0], self::boardGroups($board)[Words::GI['group_arriving']] ?? []),
            'the approved order waits in Arriving');
        $r = $desk->post('/ui/receiving', self::rowForm($board, $o['number']));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, preg_match('#^/ui/receiving/(\d+)\?notice=copied$#', (string) $r->location(), $m), (string) $r->location());
        $id = (int) $m[1];
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM grn_line WHERE document_id = ?', [$id]), 'the order\'s line copied');
        self::assertNotContains($o['number'], array_map(static fn (string $r): string => explode(' ', $r)[0], self::boardGroups($desk->get('/ui/receiving'))[Words::GI['group_arriving']] ?? []),
            'an order with a delivery being checked leaves Arriving');

        $page = $desk->follow($r);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString(Words::RECEIPT_NOTICE['copied'], $page->text());
        self::assertSame(Words::say('RECEIPT', 'title_draft', 'One Page Supplies'), self::textOf($page, '//main//h1'));
        self::assertSame(Words::of('GI_STATE', 'todo'), self::textOf($page, '//div[contains(@class, "doc-head")]//span[contains(@class, "chip")]'));
        self::assertSame(['1', '0'], [self::textOf($page, '//section[contains(@class, "doc-progress")]/div[1]/p[2]'),
            self::textOf($page, '//section[contains(@class, "doc-progress")]/div[2]/p[2]/text()[1]')], 'Lines 1, checked 0 of 1');
        $f = self::onePage($page, $id);
        self::assertSame(['csrf', 'version', 'page'], array_slice(array_keys($f), 0, 3), 'the version comes first');
        self::assertSame(['one', '1', '3', ''], [$f['page'], $f['line_count'], $f['line_1_packs'], $f['b_1_duty']]);
        self::assertSame('One Page Supplies', self::textOf($page, '(//div[contains(@class, "gi-details")]//*[contains(@class, "gi-fixed")])[1]'));
        self::assertStringContainsString($o['number'], self::textOf($page, '//div[contains(@class, "gi-details")]'), 'From order');
        self::assertSame(Words::of('GI_CHECK', 'todo'), self::textOf($page, '//tr[@id="line-1"]/td[contains(@class, "gi-check")]'));
        self::assertSame(Words::GI['left_one'], self::textOf($page, '//div[contains(@class, "doc-bar")]//*[@data-gi-left]'));
        self::assertSame(Words::say('GI', 'book', 15), self::textOf($page, '//div[contains(@class, "doc-bar")]//button[contains(@class, "primary")]'));

        // No script: the row's "All fine" is a submit button; the same form carries the paperwork, the invoice number, the stamp and the file.
        $saved = $desk->postMultipart("/ui/receiving/{$id}/lines", ['paperwork_ok' => '1', 'invoice_number' => 'ONE-1', 'b_1_duty' => 'digital', 'fine' => '1',
            'file_role' => 'supplier_invoice'] + $f, ['file' => ['path' => $this->pdf(), 'name' => 'ONE-1.pdf']]);
        self::assertSame(303, $saved->status, $saved->describe());
        self::assertStringStartsWith("/ui/receiving/{$id}?notice=saved", (string) $saved->location());
        $line = self::$db->one('SELECT stamp_on_pack, stamp_type, checked_at, damaged_units FROM grn_line WHERE document_id = ?', [$id]);
        self::assertSame([1, 'digital', 0], [(int) $line['stamp_on_pack'], $line['stamp_type'], (int) $line['damaged_units']]);
        self::assertNotNull($line['checked_at'], 'All fine counts the line');
        self::assertSame(['ONE-1', 1], [self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$id]),
            (int) self::$db->value("SELECT COUNT(*) FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$id])]);
        $page = $desk->follow($saved);
        self::assertSame(Words::of('GI_CHECK', 'done'), self::textOf($page, '//tr[@id="line-1"]/td[contains(@class, "gi-check")]'));
        self::assertSame(Words::GI['left_none'], self::textOf($page, '//div[contains(@class, "doc-bar")]//*[@data-gi-left]'));
        self::assertSame(Words::of('GI_STATE', 'ready'), self::textOf($page, '//div[contains(@class, "doc-head")]//span[contains(@class, "chip")]'));

        $r = $desk->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + self::onePage($page, $id));
        self::assertSame("/ui/receiving/{$id}?notice=posted", $r->location(), $r->describe());
        self::assertSame('posted', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        self::assertSame(15, $this->onHand($o['sku'], 'MAIN'));
        self::assertFalse($desk->follow($r)->hasForm("/ui/receiving/{$id}/lines"), 'a booked-in delivery is read only');
        self::assertContains('GRN-', array_map(static fn (string $r): string => substr($r, 0, 4), self::boardGroups($desk->get('/ui/receiving'))[Words::GI['group_today']] ?? []));
    }

    /** With the script, "All fine" ticks the line in the page and "Book in" sends it: one press saves, checks and books in. */
    public function testWithTheScriptAllFineAndBookInAreOnePress(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Script Press Ltd');
        $coil = self::makeSku('Script coil');
        $this->card($coil, false);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $coil, 'packs' => 4, 'units_per_pack' => 1, 'pack_price' => '1.50']], 'SCR-9');
        $desk = $this->signIn($deskUser);
        $page = $desk->get("/ui/receiving/{$id}");
        $f = self::onePage($page, $id);
        self::assertArrayNotHasKey('b_1_duty', $f, 'a coil needs no duty stamp: no stamp answer');
        $up = $desk->postMultipart("/ui/receiving/{$id}/lines", ['paperwork_ok' => '1', 'b_1_ok' => '1', 'b_1_fine' => '1', 'action' => 'post', 'file_role' => 'supplier_invoice'] + $f,
            ['file' => ['path' => $this->pdf(), 'name' => 'SCR-9.pdf']]);
        self::assertSame("/ui/receiving/{$id}?notice=posted", $up->location(), $up->describe());
        self::assertSame(4, $this->onHand($coil, 'MAIN'));
    }

    /**
     * "All fine" (the bench's counted tick with no problems) needs the duty answer of a duty line and is refused, in words and with nothing
     * saved, when the answer is missing or is "No stamp"; it clears the problems typed before; a line without duty takes it at once.
     */
    public function testAllFineNeedsTheDutyAnswerAndClearsTheProblems(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'All Fine Ltd');
        $liquid = self::makeSku('All fine liquid');
        $this->card($liquid, true);
        $coil = self::makeSku('All fine coil');
        $this->card($coil, false);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $liquid, 'packs' => 2, 'units_per_pack' => 5], ['sku_id' => $coil, 'packs' => 3]]);
        $desk = $this->signIn($deskUser);
        $f = self::onePage($desk->get("/ui/receiving/{$id}"), $id);
        $v = self::docVersion($id);

        $no = $desk->post("/ui/receiving/{$id}/lines", ['fine' => '1'] + $f);
        self::assertSame(422, $no->status, $no->describe());
        self::assertStringContainsString(Words::say('RECEIPT_ERROR', 'fine_needs_stamp', 1), $no->text());
        self::assertSame($v, self::docVersion($id), 'nothing saved');
        $none = $desk->post("/ui/receiving/{$id}/lines", ['fine' => '1', 'b_1_duty' => 'none'] + $f);
        self::assertSame(422, $none->status, $none->describe());
        self::assertStringContainsString(Words::say('RECEIPT_ERROR', 'fine_no_stamp', 1), $none->text());
        self::assertSame('none', self::onePage($none, $id)['b_1_duty'], 'what was typed is shown again');
        self::assertSame($v, self::docVersion($id));

        $two = $desk->post("/ui/receiving/{$id}/lines", ['fine' => '2'] + $f);
        self::assertSame(303, $two->status, $two->describe());
        self::assertNotNull(self::$db->value('SELECT checked_at FROM grn_line WHERE document_id = ? AND line_no = 2', [$id]), 'the coil: counted');
        self::assertNull(self::$db->value('SELECT checked_at FROM grn_line WHERE document_id = ? AND line_no = 1', [$id]), 'line 1 untouched');

        // A problem typed in the panel (and saved: a change counts the line), then "All fine": no problem left.
        $f = self::onePage($desk->follow($two), $id);
        $p = $desk->post("/ui/receiving/{$id}/lines", ['b_1_duty' => 'digital', 'b_1_damaged' => '2', 'action' => 'save'] + $f);
        self::assertSame(303, $p->status, $p->describe());
        $page = $desk->follow($p);
        self::assertSame(Words::of('GI_CHECK', 'problem'), self::textOf($page, '//tr[@id="line-1"]/td[contains(@class, "gi-check")]'));
        self::assertSame('2', self::onePage($page, $id)['b_1_damaged']);
        $ok = $desk->post("/ui/receiving/{$id}/lines", ['fine' => '1'] + self::onePage($page, $id));
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame([0, 1, 'digital'], array_map(static fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v,
            array_values((array) self::$db->one('SELECT damaged_units, stamp_on_pack, stamp_type FROM grn_line WHERE document_id = ? AND line_no = 1', [$id]))));
        self::assertSame(Words::of('GI_CHECK', 'done'), self::textOf($desk->follow($ok), '//tr[@id="line-1"]/td[contains(@class, "gi-check")]'));
    }

    /** The problem panel on the row: short, extra (with its confirmation), damaged, wrong, no stamp and what to do, the code, the proof, the note. */
    public function testTheProblemPanelSavesItsAnswers(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Panel Answers Ltd');
        $liquid = self::makeSku('Panel liquid');
        $this->card($liquid, true);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $liquid, 'packs' => 2, 'units_per_pack' => 5]]);
        $desk = $this->signIn($deskUser);
        $page = $desk->get("/ui/receiving/{$id}");
        self::assertSame(1, (new \DOMXPath($page->dom()))->query('//tr[@id="gi-p-1" and contains(@class, "gi-panel")]')->length, 'the panel is a row of its own');
        $f = self::onePage($page, $id);
        foreach (['b_1_short', 'b_1_over', 'b_1_damaged', 'b_1_wrong', 'b_1_unstamped', 'b_1_action', 'b_1_code', 'b_1_evidence', 'line_1_note'] as $k) {
            self::assertArrayHasKey($k, $f, $k);
        }
        $r = $desk->post("/ui/receiving/{$id}/lines", ['b_1_duty' => 'digital', 'b_1_code' => 'UKDS-123', 'b_1_short' => '1', 'b_1_damaged' => '2', 'b_1_unstamped' => '3',
            'b_1_action' => 'quarantine', 'line_1_note' => 'outer carton crushed', 'action' => 'save'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        $l = self::$db->one('SELECT g.*, dl.description FROM grn_line g JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no WHERE g.document_id = ?', [$id]);
        self::assertSame([1, 'digital', 'UKDS-123', 1, 2, 3, 'quarantine', 'outer carton crushed'], [(int) $l['stamp_on_pack'], $l['stamp_type'], $l['stamp_code'],
            (int) $l['short_units'], (int) $l['damaged_units'], (int) $l['unstamped_units'], $l['unstamped_action'], $l['description']]);
        self::assertNotNull($l['checked_at'], 'a problem written counts the line (I168)');
        $page = $desk->follow($r);
        self::assertSame(Words::of('GI_CHECK', 'problem'), self::textOf($page, '//tr[@id="line-1"]/td[contains(@class, "gi-check")]'));
        self::assertStringContainsString(Words::say('RECEIPT', 'finding', Words::RECEIPT['finding_damaged'], 2), self::textOf($page, '//tr[@id="line-1"]/th'));
        self::assertSame('1', self::textOf($page, '//section[contains(@class, "doc-progress")]/div[3]/p[2]'), 'Problems: 1 line');

        // Taken in on the supplier's proof (before the refusal date), and more extra than the line: confirmed in a second step.
        $f = self::onePage($page, $id);
        $proof = $desk->post("/ui/receiving/{$id}/lines", ['b_1_action' => 'accept_pre_october', 'b_1_evidence' => 'Certificate of manufacture 2026-09-12', 'action' => 'save'] + $f);
        self::assertSame(303, $proof->status, $proof->describe());
        self::assertSame('Certificate of manufacture 2026-09-12', self::$db->value('SELECT pre_october_evidence FROM grn_line WHERE document_id = ?', [$id]));
        $f = self::onePage($desk->follow($proof), $id);
        $over = $desk->post("/ui/receiving/{$id}/lines", ['b_1_over' => '20', 'action' => 'save'] + $f);
        self::assertSame(422, $over->status, $over->describe());
        $again = self::onePage($over, $id);
        self::assertSame('20', $again['b_1_over']);
        self::assertArrayNotHasKey('b_1_overok', $again, 'the confirmation is a box to tick, not ticked for the person');
        $ok = $desk->post("/ui/receiving/{$id}/lines", ['b_1_overok' => '1', 'action' => 'save'] + $again);
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame(20, (int) self::$db->value('SELECT over_units FROM grn_line WHERE document_id = ?', [$id]));
    }

    /**
     * Two people on one delivery (I175 kept, line by line): a line someone else checked since the page was drawn and that this person
     * changed too is refused (409, what was typed kept); a line this person did not touch is never written back with what their page
     * showed; the desk's price change after a bench check keeps the check.
     */
    public function testAStaleCheckIsRefusedAndAnUntouchedLineIsKept(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Two People Ltd');
        $a = self::makeSku('Two people liquid A');
        $b = self::makeSku('Two people liquid B');
        $this->card($a, true);
        $this->card($b, true);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $a, 'packs' => 2, 'units_per_pack' => 5, 'pack_price' => '9.00'],
            ['sku_id' => $b, 'packs' => 1, 'units_per_pack' => 5, 'pack_price' => '9.00']]);
        $desk = $this->signIn($deskUser, $this->browser('198.51.100.81'));
        $one = $this->signIn($this->uiUser('goods_in'), $this->browser('198.51.100.82'));
        $two = $this->signIn($this->uiUser('goods_in'), $this->browser('198.51.100.83'));
        $deskForm = self::onePage($desk->get("/ui/receiving/{$id}"), $id);
        $f1 = self::onePage($one->get("/ui/receiving/{$id}"), $id);
        $f2 = self::onePage($two->get("/ui/receiving/{$id}"), $id);

        $r = $one->post("/ui/receiving/{$id}/lines", ['b_1_duty' => 'digital', 'b_1_damaged' => '1', 'action' => 'save'] + $f1);
        self::assertSame(303, $r->status, $r->describe());
        $stale = $two->post("/ui/receiving/{$id}/lines", ['b_1_duty' => 'transitional', 'fine' => '1'] + $f2);
        self::assertSame(409, $stale->status, $stale->describe());
        self::assertSame('version_conflict', $stale->errorCode());
        self::assertSame('transitional', self::onePage($stale, $id)['b_1_duty'], 'what was typed stays');
        self::assertSame([1, 'digital'], [(int) self::$db->value('SELECT damaged_units FROM grn_line WHERE document_id = ? AND line_no = 1', [$id]),
            self::$db->value('SELECT stamp_type FROM grn_line WHERE document_id = ? AND line_no = 1', [$id])], 'the first check stands');

        // The second person's page, drawn before, changes only line 2: saved; line 1 is not written back as it was drawn.
        $r = $two->post("/ui/receiving/{$id}/lines", ['b_2_duty' => 'digital', 'fine' => '2'] + $f2);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([1, 'digital'], [(int) self::$db->value('SELECT damaged_units FROM grn_line WHERE document_id = ? AND line_no = 1', [$id]),
            self::$db->value('SELECT stamp_type FROM grn_line WHERE document_id = ? AND line_no = 1', [$id])]);
        self::assertNotNull(self::$db->value('SELECT checked_at FROM grn_line WHERE document_id = ? AND line_no = 2', [$id]));

        // The desk's page, drawn before both checks, changes a price: saved, both checks kept.
        $r = $desk->post("/ui/receiving/{$id}/lines", ['line_1_price' => '9.50', 'action' => 'save'] + $deskForm);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['9.5000', 2], [self::$db->value('SELECT pack_price FROM grn_line WHERE document_id = ? AND line_no = 1', [$id]),
            (int) self::$db->value('SELECT COUNT(*) FROM grn_line WHERE document_id = ? AND checked_at IS NOT NULL', [$id])]);
        // The desk changes line 1's quantity: its check is cleared and said; a bench page drawn before and touching line 1 is refused, and one
        // drawn before that presses "Book in": nothing it saw is booked in (it looks again).
        $f1 = self::onePage($one->get("/ui/receiving/{$id}"), $id);
        $f3 = self::onePage($two->get("/ui/receiving/{$id}"), $id);
        $q = $desk->post("/ui/receiving/{$id}/lines", ['line_1_packs' => '3', 'action' => 'save'] + self::onePage($desk->get("/ui/receiving/{$id}"), $id));
        self::assertSame(303, $q->status, $q->describe());
        self::assertStringContainsString('rc=1', (string) $q->location());
        self::assertStringContainsString(Words::say('RECEIPT_ADDED', 'cleared_one', 'line 1'), $desk->follow($q)->text());
        $moved = $one->post("/ui/receiving/{$id}/lines", ['b_1_short' => '1', 'action' => 'save'] + $f1);
        self::assertSame(409, $moved->status, $moved->describe());
        self::assertStringContainsString(Words::say('RECEIPT_ERROR', 'bench_except', 'line 1'), $moved->text());
        $late = $two->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + $f3);
        self::assertSame(409, $late->status, $late->describe());
        self::assertStringContainsString(Words::RECEIPT_ERROR['saved_not_booked_changed'], $late->text());
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
    }

    /**
     * The Codex review of d18e699, finding 1 (HIGH; GV16): a refused form drawn again shows the lines this person did not touch as they are
     * now (never their own page's older answers, which a later save would write back over someone else's check), and keeps, on a line they
     * did change, what they typed with the stamps of the page they typed it on (so someone else's check since is still a conflict).
     */
    public function testARefusedFormDrawnAgainNeverWritesBackAnotherPersonsCheck(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Drawn Again Ltd');
        $a = self::makeSku('Drawn again liquid A');
        $b = self::makeSku('Drawn again liquid B');
        $c = self::makeSku('Drawn again liquid C');
        foreach ([$a, $b, $c] as $sku) {
            $this->card($sku, true);
        }
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $a, 'packs' => 2, 'units_per_pack' => 5], ['sku_id' => $b, 'packs' => 1, 'units_per_pack' => 5],
            ['sku_id' => $c, 'packs' => 1, 'units_per_pack' => 5]]);
        $other = $this->uiUser('goods_in');
        $this->receipts()->bench(Caller::staff($other['id']), $id, self::docVersion($id), [], [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital'],
            3 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital']]);
        $web = $this->signIn($this->uiUser('goods_in'));
        $f = self::onePage($web->get("/ui/receiving/{$id}"), $id);
        // Someone else records 2 damaged on line 1 and 1 short on line 3 meanwhile.
        $this->receipts()->bench(Caller::staff($other['id']), $id, self::docVersion($id), [], [1 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital', 'damaged_units' => '2'],
            3 => ['stamp_on_pack' => '1', 'stamp_type' => 'digital', 'short_units' => '1']]);
        // This person typed 1 extra on line 3 and pressed All fine on line 2 without its stamp: refused, nothing saved.
        $no = $web->post("/ui/receiving/{$id}/lines", ['b_3_over' => '1', 'fine' => '2'] + $f);
        self::assertSame(422, $no->status, $no->describe());
        $again = self::onePage($no, $id);
        self::assertSame('2', $again['b_1_damaged'], 'a line not touched shows what is there now');
        self::assertSame('1', $again['b_3_over'], 'a line touched keeps what was typed');
        self::assertSame($f['b_3_was'], $again['b_3_was'], '... with the stamps of the page it was typed on');
        $ok = $web->post("/ui/receiving/{$id}/lines", ['b_2_duty' => 'digital', 'fine' => '2', 'b_3_over' => '0', 'b_3_short' => '1'] + $again);
        self::assertSame(409, $ok->status, 'line 3 was checked by someone else since: a conflict, as on the first page');
        $ok = $web->post("/ui/receiving/{$id}/lines", ['b_2_duty' => 'digital', 'fine' => '2'] + self::onePage($web->get("/ui/receiving/{$id}"), $id));
        self::assertSame(303, $ok->status, $ok->describe());
        self::assertSame([2, 1], [(int) self::$db->value('SELECT damaged_units FROM grn_line WHERE document_id = ? AND line_no = 1', [$id]),
            (int) self::$db->value('SELECT short_units FROM grn_line WHERE document_id = ? AND line_no = 3', [$id])], 'the other person\'s check stands');
    }

    /**
     * The Codex review of d18e699, findings 3, 4 and 6 (GV16): "All fine" on a duty line needs its stamp answer whatever the form sends (the
     * line's own facts, not the field's presence); a photo taken from a line's panel is a photo of that line, never the supplier invoice,
     * without the script; a problem answer changed with the quantity in one form counts the line.
     */
    public function testAllFineAPanelPhotoAndAQuantityWithItsAnswer(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Codex Finds Ltd');
        $liquid = self::makeSku('Codex liquid');
        $this->card($liquid, true);
        $coil = self::makeSku('Codex coil');
        $this->card($coil, false);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $liquid, 'packs' => 1, 'units_per_pack' => 5], ['sku_id' => $coil, 'packs' => 2]]);
        $this->receipts()->bench(Caller::staff($this->uiUser('goods_in')['id']), $id, self::docVersion($id), [], [2 => ['short_units' => '1', 'checked' => false]]);
        $desk = $this->signIn($deskUser);
        $f = self::onePage($desk->get("/ui/receiving/{$id}"), $id);
        $v = self::docVersion($id);
        unset($f['b_1_duty']);
        $no = $desk->post("/ui/receiving/{$id}/lines", ['fine' => '1'] + $f);
        self::assertSame(422, $no->status, 'the duty answer left out of the form: still refused');
        self::assertStringContainsString(Words::say('RECEIPT_ERROR', 'fine_needs_stamp', 1), $no->text());
        self::assertSame($v, self::docVersion($id), 'nothing saved');

        $page = $desk->get("/ui/receiving/{$id}");
        self::assertSame(1, (new \DOMXPath($page->dom()))->query('//tr[@id="gi-p-1"]//input[@type="file" and @name="photo_1"]')->length, 'the panel\'s own photo field');
        self::assertSame('supplier_invoice', self::onePage($page, $id)['file_role'], 'the papers\' field still starts as the invoice');
        $r = $desk->postMultipart("/ui/receiving/{$id}/lines", ['action' => 'save'] + self::onePage($page, $id), ['photo_1' => ['path' => $this->pdf(), 'name' => 'damage.pdf']]);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([['photo', 0]], array_map(static fn (array $x): array => [(string) $x['role'], (int) $x['n']],
            self::$db->all("SELECT role, (role = 'supplier_invoice') AS n FROM document_file WHERE document_id = ?", [$id])), 'a photo, never the invoice copy');

        // Line 2 (1 short, not counted): its quantity and its answer changed in one form: counted, at the new quantity, with no short.
        $r = $desk->post("/ui/receiving/{$id}/lines", ['line_2_packs' => '3', 'b_2_short' => '0', 'action' => 'save'] + self::onePage($desk->follow($r), $id));
        self::assertSame(303, $r->status, $r->describe());
        $l = self::$db->one('SELECT packs, short_units, checked_at FROM grn_line WHERE document_id = ? AND line_no = 2', [$id]);
        self::assertSame([3, 0], [(int) $l['packs'], (int) $l['short_units']]);
        self::assertNotNull($l['checked_at'], 'an answer changed with the quantity counts the line');
    }

    /** The Codex review of d18e699, finding 2 (GV16): the supplier invoice copy shows its prices, so goods in has no link to it and cannot open it. */
    public function testTheSupplierInvoiceCopyIsTheBuyingDesks(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Invoice Copy Ltd');
        $coil = self::makeSku('Invoice copy coil');
        $this->card($coil, false);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $coil, 'packs' => 1]], 'IC-1');
        $desk = $this->signIn($deskUser);
        $r = $desk->postMultipart("/ui/receiving/{$id}/lines", ['file_role' => 'supplier_invoice', 'action' => 'save'] + self::onePage($desk->get("/ui/receiving/{$id}"), $id),
            ['file' => ['path' => $this->pdf(), 'name' => 'IC-1.pdf']]);
        self::assertSame(303, $r->status, $r->describe());
        $file = (int) self::$db->value("SELECT file_id FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$id]);
        self::assertContains('/ui/files/' . $file, $desk->get("/ui/receiving/{$id}")->hrefs());
        self::assertSame(200, $desk->get('/ui/files/' . $file)->status);
        $gi = $this->signIn($this->uiUser('goods_in'));
        $page = $gi->get("/ui/receiving/{$id}");
        self::assertNotContains('/ui/files/' . $file, $page->hrefs(), 'shown as there, not as a link');
        self::assertStringContainsString('IC-1.pdf', $page->text());
        self::assertSame(403, $gi->get('/ui/files/' . $file)->status);
    }

    /**
     * Two older gaps the Codex review of d18e699 named, closed on the owner's word (GV17): only the person who keyed a delivery cancels it (the
     * screens offered it to them only; the service now refuses anyone else), and a booked-in delivery's page shows no price or value to
     * someone who does not read money (goods in).
     */
    public function testOnlyItsKeyerCancelsADeliveryAndABookedInOneShowsGoodsInNoPrice(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Keyer Cancels Ltd');
        $coil = self::makeSku('Keyer cancels coil');
        $this->card($coil, false);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $coil, 'packs' => 2, 'pack_price' => '3.40']], 'KC-1');
        $gi = $this->signIn($this->uiUser('goods_in'));
        $other = $gi->post("/ui/receiving/{$id}/cancel", ['csrf' => $this->token($gi), 'version' => (string) self::docVersion($id), 'reason' => 'not ours']);
        self::assertSame(403, $other->status, $other->describe());
        self::assertStringContainsString(Words::RECEIPT_ERROR['not_keyer_cancel'], $other->text());
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        $desk = $this->signIn($deskUser);
        $page = $desk->get("/ui/receiving/{$id}");
        $r = $desk->postMultipart("/ui/receiving/{$id}/lines", ['paperwork_ok' => '1', 'fine' => '1', 'file_role' => 'supplier_invoice'] + self::onePage($page, $id),
            ['file' => ['path' => $this->pdf(), 'name' => 'KC-1.pdf']]);
        self::assertSame(303, $r->status, $r->describe());
        $r = $desk->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + self::onePage($desk->follow($r), $id));
        self::assertSame("/ui/receiving/{$id}?notice=posted", $r->location(), $r->describe());
        self::assertStringContainsString('£3.40', $desk->get("/ui/receiving/{$id}")->text(), 'the buying desk reads the prices');
        $look = $gi->get("/ui/receiving/{$id}");
        self::assertSame(200, $look->status, $look->describe());
        self::assertStringNotContainsString('£', $look->text(), 'goods in reads no price or value');
        self::assertStringNotContainsString(Words::RECEIPT['per_pack'], $look->text());
    }

    /** The Codex review of d18e699, finding 5 (GV16): "Save and show the next lines" after a line was removed shows the line that came next. */
    public function testSaveAndNextAfterARemovedLineSkipsNothing(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Next After Ltd');
        $size = ReceivingController::pageSize(true);
        $lines = [];
        for ($i = 1; $i <= $size + 2; $i++) {
            $lines[] = ['sku_id' => self::makeSku("Next after coil {$i}"), 'packs' => 1];
        }
        $id = $this->delivery($deskUser['id'], (int) $s['id'], $lines);
        $firstOfNext = (int) self::$db->value('SELECT sku_id FROM document_line WHERE document_id = ? AND line_no = ?', [$id, $size + 1]);
        $desk = $this->signIn($deskUser);
        $r = $desk->post("/ui/receiving/{$id}/lines", ['line_1_packs' => '0', 'next' => '1'] + self::onePage($desk->get("/ui/receiving/{$id}"), $id));
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('from=' . $size, (string) $r->location(), 'line 1 gone: the next page starts one line earlier');
        $next = $desk->follow($r);
        self::assertStringContainsString('/ui/items/' . $firstOfNext, (string) (new \DOMXPath($next->dom()))->evaluate('string(//tr[contains(@class, "gi-row")][1]//a/@href)'));
    }

    /** "Book in" before every line is checked: the save is kept (the draft has everything typed), the booking refused in plain words. */
    public function testBookingInIsRefusedUntilEveryLineIsCheckedAndTheDraftKeepsTheEdits(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Not Yet Ltd');
        $x = self::makeSku('Not yet coil X');
        $y = self::makeSku('Not yet coil Y');
        $this->card($x, false);
        $this->card($y, false);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $x, 'packs' => 2], ['sku_id' => $y, 'packs' => 2]], 'NY-1');
        $desk = $this->signIn($deskUser);
        $f = self::onePage($desk->get("/ui/receiving/{$id}"), $id);
        $r = $desk->postMultipart("/ui/receiving/{$id}/lines", ['paperwork_ok' => '1', 'b_1_ok' => '1', 'b_1_fine' => '1', 'line_2_note' => 'box 2 on the next pallet',
            'action' => 'post', 'file_role' => 'supplier_invoice'] + $f, ['file' => ['path' => $this->pdf(), 'name' => 'NY-1.pdf']]);
        self::assertSame(422, $r->status, $r->describe());
        self::assertStringContainsString(Words::RECEIPT_ERROR['saved_not_booked_one'], $r->text());
        self::assertStringContainsString(Words::say('RECEIPT_PLAN', 'line_not_checked', 'line 2'), $r->text(), 'what stops it, in words');
        self::assertSame('draft', self::$db->value('SELECT status FROM document WHERE id = ?', [$id]));
        self::assertSame('box 2 on the next pallet', self::$db->value('SELECT description FROM document_line WHERE document_id = ? AND line_no = 2', [$id]), 'the draft keeps the edits');
        self::assertNotNull(self::$db->value('SELECT checked_at FROM grn_line WHERE document_id = ? AND line_no = 1', [$id]));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$id]), 'and the file');
        self::assertTrue((new \DOMXPath($r->dom()))->query('//details[@id="ready" and @open]')->length === 1, '"Before it can be booked in" is open');
        // The refusal box lists what to settle, each a line to its field (GV14): here the line not checked yet.
        self::assertSame(Words::say('GI', 'fix_lines', 'line 2'), self::textOf($r, '//div[contains(@class, "gi-refused")]//ul[@class="gi-fixes"]/li/a[@href="#line-2"]'));
        // The second line checked: booked in.
        $r = $desk->post("/ui/receiving/{$id}/lines", ['fine' => '2'] + self::onePage($r, $id));
        self::assertSame(303, $r->status, $r->describe());
        $r = $desk->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + self::onePage($desk->follow($r), $id));
        self::assertSame("/ui/receiving/{$id}?notice=posted", $r->location(), $r->describe());
    }

    /**
     * A refused "Book in" says what to settle in the refusal box itself, each a short line to its field (the coordinator's Chrome check of
     * d18e699; GV14): the supplier's invoice number and Paperwork (a press focuses the field), the invoice copy (to the file field); the
     * draft keeps what was saved and the head says it is being checked.
     */
    public function testARefusedBookingListsWhatToSettleWithLinksToTheFields(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Settle First Ltd');
        $coil = self::makeSku('Settle first coil');
        $this->card($coil, false);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $coil, 'packs' => 2]]);
        $desk = $this->signIn($deskUser);
        $r = $desk->post("/ui/receiving/{$id}/lines", ['b_1_ok' => '1', 'b_1_fine' => '1', 'action' => 'post'] + self::onePage($desk->get("/ui/receiving/{$id}"), $id));
        self::assertSame(422, $r->status, $r->describe());
        $xp = new \DOMXPath($r->dom());
        self::assertSame(Words::say('RECEIPT_ERROR', 'saved_not_booked', 3), self::textOf($r, '//div[contains(@class, "gi-refused")]/p'));
        $items = [];
        foreach ($xp->query('//div[contains(@class, "gi-refused")]//ul[@class="gi-fixes"]/li/*') ?: [] as $el) {
            /** @var \DOMElement $el */
            $items[] = [$el->nodeName, $el->getAttribute('for') . $el->getAttribute('href'), trim($el->textContent)];
        }
        self::assertSame([['label', 'gi-invoice', Words::GI['fix_invoice_number']], ['a', '#gi-file', Words::GI['fix_invoice_file']],
            ['label', 'gi-paperwork', Words::GI['fix_paperwork']]], $items, 'the box lists them itself, each to its field');
        self::assertSame(1, $xp->query('//input[@id="gi-invoice" and @name="invoice_number"]')->length);
        self::assertSame(1, $xp->query('//input[@id="gi-file" and @type="file"]')->length);
        self::assertSame(1, $xp->query('//select[@id="gi-paperwork" and @name="paperwork_ok"]')->length);
        self::assertSame(Words::of('GI_STATE', 'checking'), self::textOf($r, '//div[contains(@class, "doc-head")]//span[contains(@class, "chip")]'));
        self::assertNotNull(self::$db->value('SELECT checked_at FROM grn_line WHERE document_id = ?', [$id]), 'the save was kept');
        self::assertStringNotContainsString('bench', strtolower($r->text()), 'no word names the bench');
    }

    /** Goods in never sees a price: no fold, no price field, no pound sign; the buying desk has the fold; a save by goods in keeps the prices. */
    public function testPricesAreHiddenFromGoodsInStaff(): void
    {
        $buyer = $this->uiUser('buyer');
        $s = $this->supplier($buyer['id'], 'Hidden Prices Ltd');
        $coil = self::makeSku('Hidden price coil');
        $this->card($coil, false);
        (new SupplierItems(self::$db))->create(Caller::staff($buyer['id']), (int) $s['id'], $coil, ['units_per_pack' => '10', 'supplier_code' => 'HP10'], ['pack_price' => '7.25']);
        $goodsInUser = $this->uiUser('goods_in');
        $gi = $this->signIn($goodsInUser);
        $r = $gi->post('/ui/receiving', ['supplier_id' => (string) $s['id']] + $gi->get('/ui/receiving/new')->form('/ui/receiving'));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(1, preg_match('#^/ui/receiving/(\d+)#', (string) $r->location(), $m));
        $id = (int) $m[1];
        $page = $gi->follow($r);
        self::assertArrayNotHasKey('price', self::onePage($page, $id), 'no price beside the scan box');
        $r = $gi->post("/ui/receiving/{$id}/lines", ['q' => 'HP10', 'action' => 'add'] + self::onePage($page, $id));
        self::assertSame(303, $r->status, $r->describe());
        $page = $gi->follow($r);
        $f = self::onePage($page, $id);
        self::assertArrayHasKey('line_1_packs', $f);
        foreach (['line_1_price', 'line_1_po', 'line_1_mode'] as $k) {
            self::assertArrayNotHasKey($k, $f, $k);
        }
        self::assertStringNotContainsString(Words::GI['prices'], $page->text());
        self::assertStringNotContainsString('£', $page->text(), (string) substr($page->text(), max(0, (int) strpos($page->text(), '£') - 300), 400));
        $r = $gi->post("/ui/receiving/{$id}/lines", ['line_1_packs' => '2', 'action' => 'save'] + $f);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['7.2500', 2], [self::$db->value('SELECT pack_price FROM grn_line WHERE document_id = ?', [$id]),
            (int) self::$db->value('SELECT packs FROM grn_line WHERE document_id = ?', [$id])]);

        // The buying desk (another person) sees the fold with the price, read only; its own delivery's prices are fields.
        $deskUser = $this->uiUser('purchasing_desk');
        $desk = $this->signIn($deskUser);
        $other = $desk->get("/ui/receiving/{$id}");
        self::assertStringContainsString(Words::GI['prices'], $other->text());
        self::assertStringContainsString('£7.25', $other->text());
        self::assertArrayNotHasKey('line_1_price', self::onePage($other, $id));
        $own = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $coil, 'packs' => 1, 'units_per_pack' => 10]]);
        self::assertSame('7.25', self::onePage($desk->get("/ui/receiving/{$own}"), $own)['line_1_price']);
    }

    /**
     * Someone who did not key the delivery (the bench, or the desk on goods in's delivery) gets the same page: the lines' quantities are
     * the keyer's (shown, not fields; a forged one is refused by the service), the check and the supplier invoice number are theirs.
     */
    public function testSomeoneWhoDidNotKeyItChecksSetsTheInvoiceAndBooksIn(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Other Hands Ltd');
        $liquid = self::makeSku('Other hands liquid');
        $this->card($liquid, true);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $liquid, 'packs' => 2, 'units_per_pack' => 5]]);
        $benchUser = $this->uiUser('goods_in');
        $bench = $this->signIn($benchUser);
        $page = $bench->get("/ui/receiving/{$id}");
        self::assertSame(200, $page->status, $page->describe());
        $f = self::onePage($page, $id);
        self::assertSame('0', $f['lines_editable']);
        foreach (['line_1_packs', 'header_present', 'supplier_id', 'po_id', 'received_at', 'q'] as $k) {
            self::assertArrayNotHasKey($k, $f, "{$k}: the keyer's");
        }
        foreach (['invoice_number', 'delivery_note', 'paperwork_ok', 'b_1_duty', 'bench_note'] as $k) {
            self::assertArrayHasKey($k, $f, $k);
        }
        $r = $bench->postMultipart("/ui/receiving/{$id}/lines", ['invoice_number' => 'OH-77', 'delivery_note' => 'DN-5', 'paperwork_ok' => '1', 'b_1_duty' => 'digital',
            'fine' => '1', 'bench_note' => 'pallet wrapped', 'file_role' => 'supplier_invoice'] + $f, ['file' => ['path' => $this->pdf(), 'name' => 'OH-77.pdf']]);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(['OH-77', 'DN-5', 'pallet wrapped', $benchUser['id']], [self::$db->value('SELECT external_ref FROM document WHERE id = ?', [$id]),
            self::$db->value('SELECT delivery_note FROM goods_receipt WHERE document_id = ?', [$id]), self::$db->value('SELECT bench_note FROM goods_receipt WHERE document_id = ?', [$id]),
            (int) self::$db->value('SELECT checked_by FROM goods_receipt WHERE document_id = ?', [$id])]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'grn.invoice' AND entity_id = ?", [(string) $id]), 'the invoice set by its service (I172)');
        $forged = $bench->post("/ui/receiving/{$id}/lines", ['lines_editable' => '1', 'line_1_packs' => '9', 'page_count' => '1', 'action' => 'save'] + self::onePage($bench->follow($r), $id));
        self::assertSame(403, $forged->status, $forged->describe());
        self::assertSame(2, (int) self::$db->value('SELECT packs FROM grn_line WHERE document_id = ?', [$id]));
        $r = $bench->post("/ui/receiving/{$id}/lines", ['action' => 'post'] + self::onePage($bench->get("/ui/receiving/{$id}"), $id));
        self::assertSame("/ui/receiving/{$id}?notice=posted", $r->location(), $r->describe());
        // Someone who only looks keeps the read-only page.
        $look = $this->signIn($this->uiUser('stock_controller'))->get("/ui/receiving/{$id}");
        self::assertFalse($look->hasForm("/ui/receiving/{$id}/lines"));
    }

    /**
     * Without the script the page is plain forms: Enter in the scan box adds (the hidden first button), "All fine" sends the form, "Problem"
     * is a link to the panel row (opened by CSS :target), the duty answer is a select, the photo is a file field of the same form.
     */
    public function testWithoutTheScriptThePageIsPlainFormsAndSubmits(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Plain Forms Ltd');
        $liquid = self::makeSku('Plain forms liquid');
        $this->card($liquid, true);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $liquid, 'packs' => 1, 'units_per_pack' => 5]]);
        $desk = $this->signIn($deskUser);
        $page = $desk->get("/ui/receiving/{$id}");
        $body = $page->body;
        self::assertMatchesRegularExpression('#<form class="po-editor gi-form" method="post" enctype="multipart/form-data" action="/ui/receiving/' . $id . '/lines"#', $body);
        $xp = new \DOMXPath($page->dom());
        $first = $xp->query('//form[contains(@class, "gi-form")]//button[@type="submit"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $first);
        self::assertSame(['action', 'add', 'po-default'], [$first->getAttribute('name'), $first->getAttribute('value'), $first->getAttribute('class')], 'Enter adds what was scanned');
        self::assertSame(1, $xp->query('//tr[@id="line-1"]//button[@type="submit" and @name="fine" and @value="1" and contains(@class, "ok")]')->length);
        self::assertSame(1, $xp->query('//tr[@id="line-1"]//a[@href="#gi-p-1"]')->length, 'Problem opens the panel without the script');
        self::assertSame(1, $xp->query('//tr[@id="line-1"]//select[@name="b_1_duty"]')->length);
        // The row's place for what "All fine" has to say (GV11: never a browser dialog), next to the duty stamp cell.
        self::assertSame(1, $xp->query('//tr[@id="line-1"]/td[contains(@class, "gi-duty")]/following-sibling::td[1]//*[@data-gi-why and @role="alert"]')->length);
        self::assertSame(1, $xp->query('//form[contains(@class, "gi-form")]//input[@type="file" and @name="file" and @id="gi-file"]')->length);
        self::assertSame(1, $xp->query('//tr[@id="gi-p-1"]//input[@type="file" and @name="photo_1" and @capture="environment"]')->length, '"Take a photo": the line\'s own photo field (GV16)');
        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString(' style="', $body);
        $sku2 = self::makeSku('Plain forms second');
        $r = $desk->post("/ui/receiving/{$id}/lines", ['q' => 'CW-' . sprintf('%06d', $sku2), 'action' => 'add'] + self::onePage($page, $id));
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM grn_line WHERE document_id = ?', [$id]));
        // For someone who did not key it, Enter saves (they add no products).
        $bench = $this->signIn($this->uiUser('goods_in'));
        $first = (new \DOMXPath($bench->get("/ui/receiving/{$id}")->dom()))->query('//form[contains(@class, "gi-form")]//button[@type="submit"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $first);
        self::assertSame('save', $first->getAttribute('value'));
        // The old bench address leads to the one page.
        $old = $bench->get("/ui/receiving/{$id}/bench");
        self::assertSame(303, $old->status, $old->describe());
        self::assertStringStartsWith("/ui/receiving/{$id}", (string) $old->location());
    }

    /** Phone and tablet: the lines are cards with a coloured left edge by their check, the bar holds Save and Book in. */
    public function testThePhoneMarkup(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Phone Cards Ltd');
        $coil = self::makeSku('Phone coil');
        $this->card($coil, false);
        $id = $this->delivery($deskUser['id'], (int) $s['id'], [['sku_id' => $coil, 'packs' => 2], ['sku_id' => $coil, 'packs' => 1, 'units_per_pack' => 3]]);
        $this->receipts()->bench(Caller::staff($this->uiUser('goods_in')['id']), $id, self::docVersion($id), [], [1 => ['damaged_units' => '1']]);
        $desk = $this->signIn($deskUser);
        $body = $desk->get("/ui/receiving/{$id}")->body;
        self::assertStringContainsString('<table class="stack list board doc-lines gi-lines">', $body);
        self::assertStringContainsString('<tr class="gi-row needs" id="line-1"', $body, 'a line with a problem: amber edge');
        self::assertStringContainsString('<tr class="gi-row waiting" id="line-2"', $body, 'not checked: grey edge');
        self::assertStringContainsString('<td class="c-status doc-st needs gi-check"', $body);
        self::assertStringContainsString('<div class="doc-bar">', $body);
        self::assertStringContainsString('inputmode="numeric"', $body, 'the counts open the number keypad');
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">', $body);
    }

    /**
     * A delivery with more lines than one form carries (max_input_vars, read on the server): the lines are shown a page at a time, each page
     * with its quantities and its check; "Save and show the next" moves on; a line of another page is never written.
     */
    public function testABigDeliveryIsPagedWithinTheFieldBudget(): void
    {
        $buyer = $this->uiUser('buyer');
        $deskUser = $this->uiUser('purchasing_desk');
        $s = $this->supplier($buyer['id'], 'Big Delivery Ltd');
        $size = ReceivingController::pageSize(true);
        self::assertGreaterThan(10, $size);
        self::assertSame($size, intdiv(\CW\Ui\UiRequest::maxInputVars() - 1 - ReceivingController::ONE_FIXED_FIELDS,
            ReceivingController::ONE_DESK_FIELDS + ReceivingController::ONE_BENCH_FIELDS), 'computed from max_input_vars');
        $lines = [];
        for ($i = 1; $i <= $size + 2; $i++) {
            $sku = self::makeSku("Big delivery coil {$i}");
            $lines[] = ['sku_id' => $sku, 'packs' => 1];
        }
        $id = $this->delivery($deskUser['id'], (int) $s['id'], $lines);
        $desk = $this->signIn($deskUser);
        $page = $desk->get("/ui/receiving/{$id}");
        $f = self::onePage($page, $id);
        self::assertSame([(string) $size, '1'], [$f['page_count'], $f['from']]);
        self::assertArrayHasKey("line_{$size}_packs", $f);
        self::assertArrayNotHasKey('line_' . ($size + 1) . '_packs', $f);
        self::assertStringContainsString(Words::say('GI', 'next', $size), $page->text());
        $next = $desk->post("/ui/receiving/{$id}/lines", ['next' => '1'] + $f);
        self::assertSame(303, $next->status, $next->describe());
        self::assertStringContainsString('from=' . ($size + 1), (string) $next->location());
        $p2 = $desk->follow($next);
        $f2 = self::onePage($p2, $id);
        self::assertArrayHasKey('line_' . ($size + 2) . '_packs', $f2);
        self::assertArrayNotHasKey('line_1_packs', $f2);
        // (The coils have no item card: treated as duty-liable, their stamp is answered.)
        $r = $desk->post("/ui/receiving/{$id}/lines", ['line_' . ($size + 2) . '_packs' => '4', 'b_' . ($size + 2) . '_duty' => 'digital', 'fine' => (string) ($size + 2)] + $f2);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame([4, 1], [(int) self::$db->value('SELECT packs FROM grn_line WHERE document_id = ? AND line_no = ?', [$id, $size + 2]),
            (int) self::$db->value('SELECT packs FROM grn_line WHERE document_id = ? AND line_no = 1', [$id])]);
        self::assertNotNull(self::$db->value('SELECT checked_at FROM grn_line WHERE document_id = ? AND line_no = ?', [$id, $size + 2]),
            'a quantity changed and "All fine" pressed with it: counted at the new quantity');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM grn_line WHERE document_id = ? AND checked_at IS NOT NULL', [$id]));
    }

    // ------------------------------------------------------------------------------------------
    // The board
    // ------------------------------------------------------------------------------------------

    /** The board: its groups and their order, the status cells, the checked bar, one search (a delivery note too), Show, the old filters. */
    public function testTheBoardGroupsSearchAndShow(): void
    {
        $o = $this->orderOfThreeBoxes('Board Groups Ltd');
        $deskUser = $this->uiUser('purchasing_desk');
        $benchUser = $this->uiUser('goods_in');
        $coil = self::makeSku('Board coil');
        $this->card($coil, false);
        $sid = (int) $o['s']['id'];
        $svc = $this->receipts();
        $todo = $this->delivery($deskUser['id'], $sid, [['sku_id' => $coil, 'packs' => 1]], 'BG-TODO');
        $part = $this->delivery($deskUser['id'], $sid, [['sku_id' => $coil, 'packs' => 1], ['sku_id' => $coil, 'packs' => 2, 'units_per_pack' => 2]], 'BG-PART');
        self::$db->exec("UPDATE goods_receipt SET delivery_note = 'DN-SCAN-42' WHERE document_id = ?", [$part]);
        $svc->bench(Caller::staff($benchUser['id']), $part, self::docVersion($part), ['paperwork_ok' => '1'], [1 => []]);
        $ready = $this->delivery($deskUser['id'], $sid, [['sku_id' => $coil, 'packs' => 1]], 'BG-READY');
        $svc->bench(Caller::staff($benchUser['id']), $ready, self::docVersion($ready), ['paperwork_ok' => '1'], [1 => []]);
        $gone = $this->delivery($deskUser['id'], $sid, [['sku_id' => $coil, 'packs' => 1]], 'BG-GONE');
        $svc->cancel(Caller::staff($deskUser['id']), $gone, self::docVersion($gone), 'keyed twice');

        $desk = $this->signIn($deskUser);
        $board = $desk->get('/ui/receiving');
        self::assertSame(200, $board->status, $board->describe());
        $groups = self::boardGroups($board);
        self::assertSame([Words::GI['group_arriving'], Words::GI['group_checking'], Words::GI['group_ready']], array_keys($groups), 'in this order; no cancelled');
        self::assertCount(2, $groups[Words::GI['group_checking']]);
        self::assertCount(1, $groups[Words::GI['group_ready']]);
        $xp = new \DOMXPath($board->dom());
        self::assertSame(Words::of('GI_STATE', 'todo'), trim((string) $xp->evaluate('string(//tr[.//a[contains(@href, "/ui/receiving/' . $todo . '")]]/td[contains(@class, "doc-st")])')));
        self::assertSame(Words::of('GI_STATE', 'checking'), trim((string) $xp->evaluate('string(//tr[.//a[contains(@href, "/ui/receiving/' . $part . '")]]/td[contains(@class, "doc-st")])')));
        self::assertStringContainsString(Words::say('GI', 'lines_of', 1, 2), (string) $xp->evaluate('string(//tr[.//a[contains(@href, "/ui/receiving/' . $part . '")]])'));
        self::assertSame(1, $xp->query('//tr[.//a[contains(@href, "/ui/receiving/' . $part . '")]]//span[contains(@class, "doc-meter")]/progress[@max="2" and @value="1"]')->length);
        self::assertSame(Words::say('GI', 'orders_one'), trim((string) $xp->evaluate('string(//div[contains(@class, "doc-group") and contains(@class, "info")]//span[contains(@class, "grp-count")])')));
        self::assertSame(1, $xp->query('//select[@name="show"]')->length, 'one Show menu');
        self::assertSame(1, $xp->query('//input[@type="search" and @name="q"]')->length, 'one search');
        // One way in (GV13): the "+ New delivery" button opens the start page; no fold of its own on the board.
        self::assertSame(0, $xp->query('//details[@id="new"]')->length, 'no second way to start a delivery on the board');
        self::assertSame(0, $xp->query('//form[@action="/ui/receiving"]//select[@name="supplier_id"]')->length);
        self::assertSame(Words::NEW['receiving'], trim((string) $xp->evaluate('string(//a[contains(@class, "primary") and @href="/ui/receiving/new"])')));
        $start = $desk->get('/ui/receiving/new', ['po' => (string) $o['po']]);
        self::assertSame(200, $start->status, $start->describe());
        self::assertSame(Words::NEW['receiving'], self::textOf($start, '//main//h1'));
        $sf = $start->form('/ui/receiving');
        self::assertSame([(string) $o['po'], '1'], [$sf['po_id'] ?? null, $sf['copy'] ?? null], 'the order\'s "Receive the goods" chooses it');
        $old = $desk->get('/ui/receiving', ['po' => (string) $o['po']]);
        self::assertSame('/ui/receiving/new?po=' . $o['po'], $old->location(), 'an older link to the board\'s form');
        $none = $desk->post('/ui/receiving', ['po_id' => '', 'supplier_id' => ''] + $sf);
        self::assertSame(400, $none->status, $none->describe());
        self::assertStringContainsString(Words::RECEIPT_ERROR['choose_supplier'], $none->text());
        self::assertTrue($none->hasForm('/ui/receiving'), 'refused: the start page again, with its form');
        self::assertSame(Words::NEW['receiving'], self::textOf($none, '//main//h1'));

        $found = self::boardGroups($desk->get('/ui/receiving', ['q' => 'DN-SCAN-42']));
        self::assertSame([Words::GI['group_checking']], array_keys($found), 'a scanned delivery note finds its delivery');
        self::assertCount(1, $found[Words::GI['group_checking']]);
        self::assertSame([Words::GI['group_cancelled']], array_keys(self::boardGroups($desk->get('/ui/receiving', ['show' => 'cancelled']))));
        self::assertContains(Words::GI['group_cancelled'], array_keys(self::boardGroups($desk->get('/ui/receiving', ['show' => 'all']))));
        self::assertSame([Words::GI['group_ready']], array_keys(self::boardGroups($desk->get('/ui/receiving', ['state' => 'checked']))), 'Home\'s "to book in" link');
        self::assertNotContains(Words::GI['group_arriving'], array_keys(self::boardGroups($this->signIn($this->uiUser('reviewer'))->get('/ui/receiving', ['show' => 'cancelled']))));
    }
}
