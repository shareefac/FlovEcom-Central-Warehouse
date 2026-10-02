<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Documents;

use CW\Caller;
use CW\Documents\Document;
use CW\Documents\DocumentInvariants;
use CW\Documents\Documents;
use CW\Invariants;
use CW\Tests\Support\Documents\FixtureAdjustmentHandler;
use CW\Tests\Support\IntegrationTestCase;

/**
 * DocumentInvariants (D1-D7) report each kind of corruption admin SQL could leave: a gap in a number series, a ledger
 * row naming a document that is not posted, a broken reversal pair, a task decided by the document's own people, a
 * posted line edited afterwards (posted_hash), an open task on the wrong document, a line naming no item.
 * IntegrationTestCase: no invariant post-condition (the tests corrupt on purpose).
 */
final class DocumentInvariantsTest extends IntegrationTestCase
{
    private Documents $docs;
    private Caller $creator;
    private Caller $poster;
    private Caller $reviewer;
    private int $sku;
    /** @var array{reviewed: Document, pending: Document, reversed: Document, reversal: Document} */
    private array $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docs = new Documents(self::$db, ['ADJ' => new FixtureAdjustmentHandler(self::$db)]);
        $this->creator = $this->staff(['stock_controller']);
        $this->poster = $this->staff(['stock_controller']);
        $this->reviewer = $this->staff(['reviewer']);
        $this->sku = self::makeSku('Invariant item');
        $reviewed = $this->post($this->creator, $this->creator, [['sku_id' => $this->sku, 'qty' => 5, 'unit_cost' => '1.5']]);
        $this->docs->approve($this->reviewer, $this->task($reviewed->id), 'ok');
        $pending = $this->post($this->creator, $this->poster, [['sku_id' => $this->sku, 'qty' => -2], ['sku_id' => $this->sku, 'qty' => 1, 'warehouse' => 'VERIFY']]);
        $reversed = $this->post($this->poster, $this->poster, [['sku_id' => $this->sku, 'qty' => 3]]);
        $reversal = $this->docs->reverse($this->poster, $reversed->id, 'entered_in_error', null);
        $this->d = ['reviewed' => $this->docs->get($reviewed->id), 'pending' => $pending, 'reversed' => $this->docs->get($reversed->id), 'reversal' => $reversal];
        self::assertSame([], DocumentInvariants::check(self::$db), 'clean before the corruption');
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testANumberGap(): void
    {
        self::$db->exec("UPDATE number_series SET last_no = last_no + 1 WHERE prefix = 'ADJ'");
        self::assertSame(['number series ADJ: last_no 5 but its posted documents carry 4 numbers from 1 to 4 (expected exactly 1..5)'],
            DocumentInvariants::check(self::$db));
        self::$db->exec("UPDATE number_series SET last_no = last_no - 1 WHERE prefix = 'ADJ'");
        self::$db->exec("UPDATE document SET number = 'ADJ-0000004' WHERE id = ?", [$this->d['reversal']->id]);
        $v = DocumentInvariants::check(self::$db);
        self::assertContains("document {$this->d['reversal']->id}: number ADJ-0000004 is not a ADJ number in its canonical form", $v);
    }

    public function testALedgerRowNamingADocumentThatIsNotPosted(): void
    {
        self::$db->exec('UPDATE stock_ledger SET document_id = 999999 WHERE document_id = ?', [$this->d['pending']->id]);
        self::assertContains('stock_ledger rows of document 999999 (doc_ref ADJ-000002) name a document that does not exist', DocumentInvariants::check(self::$db));
        self::$db->exec('UPDATE stock_ledger SET document_id = ? WHERE document_id = 999999', [$this->d['pending']->id]);
        self::$db->exec("UPDATE stock_ledger SET doc_ref = 'ADJ-000099' WHERE document_id = ? LIMIT 1", [$this->d['reviewed']->id]);
        self::assertContains("stock_ledger rows of document {$this->d['reviewed']->id} (doc_ref ADJ-000099) name a posted document numbered ADJ-000001",
            DocumentInvariants::check(self::$db));
    }

    public function testABrokenReversalPair(): void
    {
        $o = $this->d['reversed']->id;
        $r = $this->d['reversal']->id;
        self::$db->exec("UPDATE document SET status = 'posted' WHERE id = ?", [$o]);
        self::assertContains("reversal {$r} (ADJ-000004, posted ADJ) of document {$o} (posted ADJ)", DocumentInvariants::check(self::$db));
        self::$db->exec("UPDATE document SET status = 'reversed' WHERE id = ?", [$o]);
        self::$db->exec('UPDATE document SET reverses_id = NULL WHERE id = ?', [$r]);
        $v = DocumentInvariants::check(self::$db);
        self::assertContains("document {$o} (ADJ-000003) is reversed but has no reversal", $v);
        self::$db->exec('UPDATE document SET reverses_id = ? WHERE id = ?', [$o, $r]);
        self::$db->exec('UPDATE stock_ledger SET qty_delta = qty_delta + 1 WHERE document_id = ?', [$r]);
        self::assertContains("reversal {$r} of document {$o}: on_hand of " . self::warehouseId('MAIN') . ":{$this->sku} nets to 1, not 0", DocumentInvariants::check(self::$db));
    }

    public function testATaskDecidedByTheDocumentsOwnPeople(): void
    {
        $task = $this->task($this->d['pending']->id);
        // The poster opened it (the CHECK refuses them as decider); the creator is someone else and passes the CHECK.
        self::$db->exec("UPDATE review_task SET state = 'approved', decided_by = ?, decided_at = NOW(6) WHERE id = ?", [$this->creator->staffUserId, $task]);
        self::$db->exec("UPDATE document SET review_state = 'approved' WHERE id = ?", [$this->d['pending']->id]);
        self::assertSame(["review task {$task} was decided by staff {$this->creator->staffUserId}, who created, submitted or posted document {$this->d['pending']->id}"],
            DocumentInvariants::check(self::$db));
        self::$db->exec('UPDATE review_task SET decided_by = 987654 WHERE id = ?', [$task]);
        self::assertSame(["review task {$task} names staff 987654, who does not exist"], DocumentInvariants::check(self::$db));
    }

    public function testAnOpenTaskOnTheWrongDocument(): void
    {
        $p = $this->d['pending']->id;
        self::$db->exec("UPDATE document SET review_state = 'approved' WHERE id = ?", [$p]);
        self::assertSame(["open review task {$this->task($p)} on document {$p}, which is posted (review approved)"], DocumentInvariants::check(self::$db));
        self::$db->exec("UPDATE document SET review_state = 'pending' WHERE id = ?", [$this->d['reviewed']->id]);
        self::assertContains("document {$this->d['reviewed']->id} is waiting for its review but has 0 open tasks for it", DocumentInvariants::check(self::$db));
    }

    public function testAPostedLineEditedAfterwardsAndALineWithoutItsItem(): void
    {
        $id = $this->d['reviewed']->id;
        self::$db->exec('UPDATE document_line SET qty = qty + 1 WHERE document_id = ?', [$id]);
        self::assertSame(["document {$id} (ADJ-000001): its header or lines changed after posting (they no longer hash to its posting record)"], DocumentInvariants::check(self::$db));
        self::$db->exec('UPDATE document_line SET qty = qty - 1 WHERE document_id = ?', [$id]);
        self::$db->exec("UPDATE document SET note = 'edited' WHERE id = ?", [$id]);
        self::assertSame(["document {$id} (ADJ-000001): its header or lines changed after posting (they no longer hash to its posting record)"], DocumentInvariants::check(self::$db));
        self::$db->exec('UPDATE document SET note = NULL WHERE id = ?', [$id]);
        self::assertSame([], DocumentInvariants::check(self::$db));
        self::$db->exec('INSERT INTO document_line (document_id, line_no, sku_id, qty) VALUES (?, 9, 999999, 1)', [$this->d['pending']->id]);
        $v = DocumentInvariants::check(self::$db);
        self::assertContains("document {$this->d['pending']->id} line 9 names item 999999, which does not exist", $v);
        self::assertContains("document {$this->d['pending']->id} (ADJ-000002): its header or lines changed after posting (they no longer hash to its posting record)", $v);
    }

    /**
     * I33 (review finding): the app login may update a posted document's state columns and its lines, so it could rewrite a
     * line AND recompute posted_hash, or move posted_at out of a recent-days window. The write-once posting record
     * (document_posting) is what D7 compares with, for every posted document whatever its posted_at.
     */
    public function testRewritingTheHashOrThePostingTimeIsFoundAgainstThePostingRecord(): void
    {
        $id = $this->d['reviewed']->id;
        self::$db->exec('UPDATE document_line SET qty = 400, unit_cost = 0.01 WHERE document_id = ?', [$id]);
        $doc = $this->docs->get($id);
        self::$db->exec('UPDATE document SET posted_hash = ? WHERE id = ?', [Documents::fingerprint($doc, $this->docs->lines($id)), $id]);
        self::assertSame([
            "document {$id} (ADJ-000001): posted_hash changed after posting (they differ from its write-once posting record)",
            "document {$id} (ADJ-000001): its header or lines changed after posting (they no longer hash to its posting record)",
        ], DocumentInvariants::check(self::$db));
        // The posted content is kept: the original line can be read back from the record.
        $kept = json_decode((string) self::$db->value('SELECT content FROM document_posting WHERE document_id = ?', [$id]), true);
        self::assertSame([5, '1.500000'], [$kept['lines'][0]['qty'], $kept['lines'][0]['unit_cost']]);
        self::$db->exec('UPDATE document_line SET qty = 5, unit_cost = 1.5 WHERE document_id = ?', [$id]);
        self::$db->exec('UPDATE document SET posted_hash = (SELECT posted_hash FROM document_posting WHERE document_id = ?) WHERE id = ?', [$id, $id]);
        self::assertSame([], DocumentInvariants::check(self::$db));

        self::$db->exec("UPDATE document SET external_ref = 'FORGED', note = 'forged', posted_at = posted_at - INTERVAL 401 DAY WHERE id = ?", [$id]);
        self::assertSame([
            "document {$id} (ADJ-000001): posted_at changed after posting (they differ from its write-once posting record)",
            "document {$id} (ADJ-000001): its header or lines changed after posting (they no longer hash to its posting record)",
        ], DocumentInvariants::check(self::$db), 'an old posting time does not hide it');
    }

    public function testAMissingOrForgedPostingRecord(): void
    {
        $id = $this->d['pending']->id;
        self::$db->exec("UPDATE document_posting SET content = REPLACE(content, '\"qty\":-2', '\"qty\":-20') WHERE document_id = ?", [$id]);
        self::assertSame(["posting record of document {$id} (ADJ-000002): its content does not hash to its posted_hash"], DocumentInvariants::check(self::$db));
        self::$db->exec('DELETE FROM document_posting WHERE document_id = ?', [$id]);
        self::assertSame(["document {$id} (ADJ-000002) is posted but has no posting record (document_posting)"], DocumentInvariants::check(self::$db));
        // A record for a document that was never posted.
        $draft = $this->docs->createDraft($this->poster, 'ADJ', []);
        self::$db->exec("INSERT INTO document_posting (document_id, number, posted_hash, posted_actor, posted_at, content) VALUES (?, 'ADJ-000099', REPEAT('a', 64), 'x', NOW(6), 'x')",
            [$draft->id]);
        self::assertContains("document {$draft->id} (draft) is draft but has a posting record", DocumentInvariants::check(self::$db));
    }

    /** I32: a reversal request waits only on a posted original, a cancelled one is history, a reversal is never a draft. */
    public function testAReversalRequestOnTheWrongOriginal(): void
    {
        $o = $this->d['reversed']->id;
        $c = self::$db->insert("INSERT INTO document (doc_type, status, created_actor, reverses_id, submitted_by, submitted_at, cancelled_at) "
            . "VALUES ('ADJ', 'cancelled', 'x', ?, ?, NOW(6), NOW(6))", [$o, $this->poster->staffUserId]);
        self::assertSame([], DocumentInvariants::check(self::$db), 'a cancelled request is history (and frees the one live reversal)');
        $p = $this->d['pending']->id;
        $r = self::$db->insert("INSERT INTO document (doc_type, status, created_actor, reverses_id) VALUES ('ADJ', 'draft', 'x', ?)", [$p]);
        self::assertContains("reversal {$r} (unnumbered, draft ADJ) of document {$p} (posted ADJ)", DocumentInvariants::check(self::$db));
        self::$db->exec("UPDATE document SET status = 'awaiting_approval', submitted_at = NOW(6) WHERE id = ?", [$r]);
        self::$db->exec("UPDATE document SET status = 'reversed' WHERE id = ?", [$p]);
        self::assertContains("reversal {$r} (unnumbered, awaiting_approval ADJ) of document {$p} (reversed ADJ)", DocumentInvariants::check(self::$db));
        self::assertSame(1062, self::mysqlError(fn () => self::$db->exec("INSERT INTO document (doc_type, status, created_actor, reverses_id) VALUES ('ADJ', 'draft', 'x', ?)", [$p])),
            'one live reversal per document');
        self::assertGreaterThan(0, $c);
    }

    /** @param list<string> $roles */
    private function staff(array $roles): Caller
    {
        $n = bin2hex(random_bytes(3));
        $id = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES (?, ?, ?, 'x')", ["inv-{$n}", "inv {$n}", "inv-{$n}@test.invalid"]);
        foreach ($roles as $r) {
            self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$id, $r]);
        }
        return Caller::staff($id);
    }

    /** @param list<array<string, mixed>> $lines */
    private function post(Caller $creator, Caller $poster, array $lines): Document
    {
        $d = $this->docs->createDraft($creator, 'ADJ', ['external_ref' => 'SUP-INV']);
        $d = $this->docs->setLines($creator, $d->id, $d->version, $lines);
        return $this->docs->post($poster, $d->id, $d->version);
    }

    private function task(int $documentId): int
    {
        return (int) self::$db->value("SELECT id FROM review_task WHERE subject_id = ? AND state = 'open'", [$documentId]);
    }
}
