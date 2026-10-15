<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\PurchaseOrders\PoLinesFile;
use CW\PurchaseOrders\PurchaseOrderHandler;
use CW\PurchaseOrders\PurchaseOrders;

/**
 * The service side of the Codex review of the PO module (pack PO, docs/decisions.md PO7-PO11; runs/po_module_codex_review.txt),
 * each written first against 552851a and seen failing:
 *   finding 2  a closed order is opened again on purpose ("Reopen order": a reason, audited);
 *   finding 3  an order's own lines file imports again when its item is sold in several packs (the supplier code decides the pack);
 *   finding 7  an approved order prints the terms it was approved with, whatever the setting says later.
 */
final class PurchaseOrderReviewFixesTest extends PurchaseOrderTestCase
{
    private ?string $terms = null;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->terms !== null) {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'po.terms'", [$this->terms]);
            $this->terms = null;
        }
        if ($this->dir !== null && str_starts_with(basename($this->dir), 'cw_po_fix_')) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    // ---- finding 2: "Reopen order" ------------------------------------------------------------------------------------

    public function testAClosedOrderIsReopenedWithAReasonAndTheReopenIsAudited(): void
    {
        $buyer = $this->staffUser('buyer');
        ['doc' => $po] = $this->approvedPo($buyer, 2, '12.0000', 6);
        $id = $po->id;
        self::$db->transaction(fn () => $this->pos->applyReceipt($id, [1 => 5], 'GRN-T1'));
        self::refused(409, 'not_reopenable', fn () => $this->pos->reopen($buyer, $id, $this->docs->get($id)->version, 'it is not closed'));
        $closed = $this->pos->close($buyer, $id, $this->docs->get($id)->version, 'supplier cannot send the rest');
        self::assertSame([], $this->pos->openLines($id), 'closed: nothing expected');

        self::refused(400, 'reopen_reason_required', fn () => $this->pos->reopen($buyer, $id, $closed->version, 'x'));
        self::refused(403, 'role_not_allowed', fn () => $this->pos->reopen($this->staffUser('purchasing_desk'), $id, $closed->version, 'the rest is coming'));
        self::refused(409, 'version_conflict', fn () => $this->pos->reopen($buyer, $id, $closed->version - 1, 'the rest is coming'));

        $open = $this->pos->reopen($buyer, $id, $closed->version, 'the supplier found the rest');
        self::assertSame($closed->version + 1, $open->version);
        $row = $this->poRow($id);
        self::assertSame(['part_received', null, null, null], [$row['state'], $row['closed_at'], $row['closed_by'], $row['close_reason']]);
        self::assertSame(7, $this->pos->openLines($id)[0]['units_outstanding'], 'the rest is expected again');
        $audit = self::$db->one("SELECT detail FROM audit_log WHERE action = 'po.reopen' AND entity_id = ?", [(string) $id]);
        self::assertNotNull($audit, 'the reopen is audited');
        $detail = json_decode((string) $audit['detail'], true);
        self::assertSame(['the supplier found the rest', 'supplier cannot send the rest', 'part_received'], [$detail['reason'], $detail['close_reason'], $detail['state']]);
        self::refused(409, 'not_reopenable', fn () => $this->pos->reopen($buyer, $id, $open->version, 'again'));
        // It can be closed again later.
        $this->pos->close($buyer, $id, $open->version, 'really not coming now');
        self::assertSame('closed', $this->poRow($id)['state']);
    }

    // ---- finding 3: the order's own lines file, an item in several packs ------------------------------------------------

    public function testAnOrdersOwnLinesFileImportsAgainWhenItsItemIsSoldInSeveralPacks(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Packs Of Pods']);
        $sid = (int) $s['id'];
        $sku = $this->itemWithBarcode('Pod in two packs', '5012345678900');
        $single = $this->supplierItem($buyer, $sid, $sku, 1, '2.0000', ['supplier_code' => 'POD-1', 'is_preferred' => '1']);
        $box = $this->supplierItem($buyer, $sid, $sku, 6, '11.0000', ['supplier_code' => 'POD-6']);
        self::assertSame([1, 0], [(int) $this->items->get((int) $single['id'])['is_preferred'], (int) $this->items->get((int) $box['id'])['is_preferred']],
            'the single is the preferred pack, the box an alternative');
        $d = $this->draftPo($buyer, $sid, [['supplier_item_id' => (int) $box['id'], 'packs' => 3], ['supplier_item_id' => (int) $single['id'], 'packs' => 2]]);
        $want = [[(int) $box['id'], 3, 18], [(int) $single['id'], 2, 2]];
        self::assertSame($want, $this->siLines($d->id));

        $this->dir = sys_get_temp_dir() . '/cw_po_fix_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        foreach (['csv' => PoLinesFile::csv($this->pos->exportRows($d->id)), 'xlsx' => PoLinesFile::xlsx($this->pos->exportRows($d->id))] as $ext => $bytes) {
            $path = "{$this->dir}/lines.{$ext}";
            file_put_contents($path, $bytes);
            $r = $this->pos->importLines($buyer, $d->id, $this->docs->get($d->id)->version, $path, "lines.{$ext}", 'replace');
            self::assertSame([], $r['errors'], "the order's own {$ext} file imports again unchanged");
            self::assertSame($want, $this->siLines($d->id), "{$ext}: the box line stays a box (the supplier code decides the pack)");
        }

        // The other identifiers are still checked: a CW number of another item is refused, as is a case barcode of another pack size.
        $other = self::makeSku('Another item');
        $bad = "{$this->dir}/bad.csv";
        file_put_contents($bad, "cw_code,supplier_code,packs\r\n" . sprintf('CW-%06d', $other) . ",POD-6,1\r\n");
        $r = $this->pos->importLines($buyer, $d->id, $this->docs->get($d->id)->version, $bad, 'bad.csv', 'replace');
        self::assertCount(1, $r['errors']);
        self::assertStringStartsWith('row 1, cw_code: names a different item than supplier_code', $r['errors'][0]);
        self::$db->exec('INSERT INTO sku_barcode (barcode, sku_id, units_per_scan) VALUES (?, ?, 12)', ['5012345678917', $sku]);
        file_put_contents($bad, "supplier_code,barcode,packs\r\nPOD-6,5012345678917,1\r\n");
        $r = $this->pos->importLines($buyer, $d->id, $this->docs->get($d->id)->version, $bad, 'bad.csv', 'replace');
        self::assertCount(1, $r['errors']);
        self::assertSame('row 1, barcode: is a case of 12, but supplier_code names a pack of 6', $r['errors'][0]);
        self::assertSame($want, $this->siLines($d->id), 'nothing changed');
        // Without a supplier code the CW number still names the preferred pack (as before).
        file_put_contents($bad, "cw_code,packs\r\n" . sprintf('CW-%06d', $sku) . ",4\r\n");
        $r = $this->pos->importLines($buyer, $d->id, $this->docs->get($d->id)->version, $bad, 'bad.csv', 'replace');
        self::assertSame([], $r['errors']);
        self::assertSame([[(int) $single['id'], 4, 4]], $this->siLines($d->id));
    }

    /** @return list<array{int, int, int}> supplier item, packs, units of each line */
    private function siLines(int $id): array
    {
        return array_map(static fn (array $l): array => [(int) $l['supplier_item_id'], (int) $l['packs'], (int) $l['qty']], self::$db->all(
            'SELECT pl.supplier_item_id, pl.packs, dl.qty FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
            . 'WHERE pl.document_id = ? ORDER BY pl.line_no', [$id]));
    }

    // ---- finding 7: the terms as approved -------------------------------------------------------------------------------

    public function testAnApprovedOrderPrintsTheTermsItWasApprovedWith(): void
    {
        $this->terms = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'po.terms'");
        $before = (string) json_decode($this->terms, true);
        $buyer = $this->staffUser('buyer');
        ['doc' => $po, 'supplier' => $s, 'si' => $si] = $this->approvedPo($buyer);
        self::assertSame($before, $this->pos->pdfData($po->id)['terms']);
        self::assertSame($before, self::$db->value('SELECT terms_snapshot FROM purchase_order WHERE document_id = ?', [$po->id]), 'kept at approval');
        $draft = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 1]]);
        self::assertNull(self::$db->value('SELECT terms_snapshot FROM purchase_order WHERE document_id = ?', [$draft->id]), 'a draft keeps none');

        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'po.terms'", [json_encode('Terms changed after the approval.')]);
        $pos = new PurchaseOrders(self::$db, $this->docs);
        self::assertSame($before, $pos->pdfData($po->id)['terms'], 'the approved order prints what it was approved with');
        self::assertSame('Terms changed after the approval.', $pos->pdfData($draft->id)['terms'], 'a draft prints the terms in force');

        // The snapshot is part of the order's write-once anchor: changing it is found (P2).
        self::assertStringContainsString('"terms_snapshot"', (string) self::$db->value('SELECT content FROM po_posting WHERE document_id = ?', [$po->id]));
    }

    public function testAnOrderApprovedBeforeTheSnapshotPrintsTheTermsInForceAtItsApproval(): void
    {
        $this->terms = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'po.terms'");
        $buyer = $this->staffUser('buyer');
        ['doc' => $po] = $this->approvedPo($buyer);
        // As an order approved before 0042: no snapshot, an anchor without it (written again as it was then, admin login).
        self::$db->exec('UPDATE purchase_order SET terms_snapshot = NULL WHERE document_id = ?', [$po->id]);
        $content = PurchaseOrderHandler::content((array) self::$db->one('SELECT * FROM purchase_order WHERE document_id = ?', [$po->id]),
            array_values(PurchaseOrderHandler::poLines(self::$db, $po->id)));
        self::assertStringNotContainsString('terms_snapshot', $content);
        self::$db->exec('UPDATE po_posting SET content = ?, content_hash = ? WHERE document_id = ?', [$content, hash('sha256', $content), $po->id]);
        $posted = (string) self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$po->id]);
        // The setting's history: one version in force at the approval, a later one after it.
        $v = (int) self::$db->value("SELECT COALESCE(MAX(version), 0) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'po.terms'");
        self::$db->exec("INSERT INTO config_change (subject_type, subject_key, version, action, state, before_state, reason, actor, created_at) VALUES "
            . "('setting', 'po.terms', ?, 'change', JSON_OBJECT('value', ?, 'provisional', 1), JSON_OBJECT('value', '\"x\"', 'provisional', 1), 'test: in force at the approval', "
            . "'system:test', DATE_SUB(?, INTERVAL 1 MINUTE)), ('setting', 'po.terms', ?, 'change', JSON_OBJECT('value', ?, 'provisional', 1), "
            . "JSON_OBJECT('value', '\"x\"', 'provisional', 1), 'test: after the approval', 'system:test', DATE_ADD(?, INTERVAL 1 MINUTE))",
            [$v + 1, json_encode('Terms at the approval.'), $posted, $v + 2, json_encode('Terms later.'), $posted]);
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'po.terms'", [json_encode('Terms later.')]);
        self::assertSame('Terms at the approval.', (new PurchaseOrders(self::$db, $this->docs))->pdfData($po->id)['terms']);
        self::$db->exec("DELETE FROM config_change WHERE subject_type = 'setting' AND subject_key = 'po.terms' AND actor = 'system:test'");
    }
}
