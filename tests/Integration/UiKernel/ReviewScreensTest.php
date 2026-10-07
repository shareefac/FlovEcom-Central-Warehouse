<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Caller;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Tests\Support\Documents\FixtureAdjustmentHandler;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Ui\Controller\DocumentsController;
use CW\Ui\Kernel;
use CW\Ui\Words;

/**
 * The document screens through the real /ui kernel as cw_app (I17-I19, I27), with the test-only ADJ type: the review
 * queue (empty state, the two tables, the badge), approving and rejecting through the forms (CSRF), the poster who sees
 * why they cannot decide and no forms, the reversal form, the documents list, a type without screens, and the PDF
 * download's hardened headers.
 */
final class ReviewScreensTest extends KernelUiTestCase
{
    private function docs(): Documents
    {
        return new Documents(self::$db, ['ADJ' => new FixtureAdjustmentHandler(self::$db)]);
    }

    /** @param array<string, mixed> $user a uiUser() @param list<array<string, mixed>> $lines @param array<string, mixed> $header */
    private function post(array $user, array $lines, array $header = ['external_ref' => 'SUP-UI']): Document
    {
        $who = Caller::staff($user['id']);
        $d = $this->docs()->createDraft($who, 'ADJ', $header);
        $d = $this->docs()->setLines($who, $d->id, $d->version, $lines);
        return $this->docs()->post($who, $d->id, $d->version);
    }

    private function task(int $documentId): int
    {
        return (int) self::$db->value("SELECT id FROM review_task WHERE subject_id = ? AND state = 'open'", [$documentId]);
    }

    public function testAReviewerApprovesAndRejectsThroughTheForms(): void
    {
        $reviewer = $this->uiUser('reviewer');
        $web = $this->signIn($reviewer);
        $queue = $web->get('/ui/documents/reviews');
        self::assertSame(200, $queue->status, $queue->describe());
        self::assertStringContainsString(Words::CHECKS['none'], $queue->text());
        self::assertSame('Things to check', trim((string) (new \DOMXPath($queue->dom()))->evaluate('string(//main//h1)')), 'the title the plan names (F096)');
        self::assertSame([['label' => 'Waiting for me', 'href' => '/ui/documents/reviews']], self::nav($queue)['To check']);

        $poster = $this->uiUser(['stock_controller', 'reviewer']);
        $sku = self::makeSku('Screen item');
        $a = $this->post($poster, [['sku_id' => $sku, 'qty' => 3, 'unit_cost' => '1.5']]);
        $b = $this->post($poster, [['sku_id' => $sku, 'qty' => -1]]);
        $held = $this->post($poster, [['sku_id' => $sku, 'qty' => 25]], []);
        $queue = $web->get('/ui/documents/reviews');
        self::assertStringNotContainsString(Words::CHECKS['none'], $queue->text());
        self::assertStringContainsString(Words::CHECK_KIND['approval'], $queue->text());
        self::assertStringContainsString(Words::CHECK_KIND['review'], $queue->text());
        $xp = new \DOMXPath($queue->dom());
        self::assertSame(['Stock correction (no number yet)'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//section[@aria-labelledby="approvals-h"]//tbody/tr/th'))), 'a record without a number says so (F099)');
        self::assertSame(['Stock correction ADJ-000001', 'Stock correction ADJ-000002'], array_map(static fn (\DOMNode $n): string => trim((string) $n->textContent),
            iterator_to_array($xp->query('//section[@aria-labelledby="reviews-h"]//tbody/tr/th'))), 'oldest first');
        // Phone first (F100): one card per check, each cell says what it is; the reasons in words (F098), the items counted (F095).
        self::assertSame(2, $xp->query('//table[contains(@class, "stack")]')->length);
        self::assertSame(['Why', 'Value (no VAT)', 'Asked by', 'Asked on', 'Check by'], array_map(static fn (\DOMElement $td): string => $td->getAttribute('data-label'),
            iterator_to_array($xp->query('//section[@aria-labelledby="approvals-h"]//tbody/tr[1]/td[@data-label]'))));
        self::assertSame(Words::CHECK_REASON['positive_without_supplier_doc'], trim((string) $xp->evaluate('string(//section[@aria-labelledby="approvals-h"]//tbody/tr[1]/td[1])')));
        self::assertSame('25 items', trim((string) $xp->evaluate('string(//section[@aria-labelledby="approvals-h"]//tbody/tr[1]/td[2])')));
        self::assertStringNotContainsString('UTC', $queue->text());
        self::assertSame(['Everything', 'Purchase orders', 'Deliveries', 'Stock corrections', 'Suppliers', 'Company details'],
            array_map(static fn (\DOMNode $o): string => trim((string) $o->textContent), iterator_to_array($xp->query('//select[@name="type"]/option'))),
            'the filter offers the kinds in use (F102): PO, GRN (IM6) and the fixture ADJ here, never the kinds still to come');
        self::assertSame('Waiting for me 3', self::nav($queue)['To check'][0]['label'], 'the badge counts what this reviewer may decide');

        // Approve through the form.
        $page = $web->get('/ui/documents/' . $a->id);
        self::assertSame(200, $page->status, $page->describe());
        self::assertStringContainsString('Stock correction ADJ-000001', $page->text());
        $pxp = new \DOMXPath($page->dom());
        self::assertSame(Words::RECORD['review_title'], trim((string) $pxp->evaluate('string(//section[@aria-labelledby="decide-h"]//h2)')));
        self::assertStringContainsString(Words::UI['what_each_answer_does'], $page->text(), 'design B: what each answer does');
        self::assertStringContainsString(Words::RECORD['does_not_ok_review'], $page->text());
        self::assertLessThan((int) $pxp->evaluate('count(//section[@aria-labelledby="lines-h"]/preceding::*)'),
            (int) $pxp->evaluate('count(//section[@aria-labelledby="decide-h"]/preceding::*)'), 'the decision comes before the lines');
        $form = $page->form('/ui/documents/reviews/' . $this->task($a->id) . '/approve');
        self::assertArrayHasKey('csrf', $form);
        $r = $web->post('/ui/documents/reviews/' . $this->task($a->id) . '/approve', ['note' => 'counted again: right'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        self::assertSame('/ui/documents/' . $a->id . '?notice=approved', $r->location());
        $after = $web->follow($r);
        self::assertStringContainsString(DocumentsController::NOTICES['approved'], $after->text());
        self::assertSame('approved', self::$db->value('SELECT review_state FROM document WHERE id = ?', [$a->id]));
        self::assertSame([$reviewer['id'], 'counted again: right'],
            array_values((array) self::$db->one("SELECT decided_by, decision_note FROM review_task WHERE subject_id = ? AND kind = 'review'", [$a->id])));
        self::assertFalse($after->hasForm('/approve'), 'nothing left to decide');

        // A form without the token is refused.
        $taskB = $this->task($b->id);
        $r = $web->post("/ui/documents/reviews/{$taskB}/approve", ['note' => '']);
        self::assertSame(403, $r->status);
        self::assertSame('csrf', $r->errorCode());

        // Reject needs a note; with one, the document is reversed.
        $page = $web->get('/ui/documents/' . $b->id);
        $form = $page->form("/ui/documents/reviews/{$taskB}/reject");
        $r = $web->post("/ui/documents/reviews/{$taskB}/reject", ['note' => ''] + $form);
        self::assertSame(400, $r->status);
        self::assertStringContainsString(Words::ERROR['note_required'], $r->text());
        self::assertTrue($r->hasForm("/ui/documents/reviews/{$taskB}/reject"), 'the page is drawn again with its forms');
        $r = $web->post("/ui/documents/reviews/{$taskB}/reject", ['note' => 'wrong item scanned'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        $after = $web->follow($r);
        self::assertStringContainsString(DocumentsController::NOTICES['rejected_review'], $after->text());
        $reversal = (int) self::$db->value('SELECT id FROM document WHERE reverses_id = ?', [$b->id]);
        self::assertContains('/ui/documents/' . $reversal, $after->hrefs(), 'reversed by: linked');
        $revPage = $web->get('/ui/documents/' . $reversal);
        self::assertContains('/ui/documents/' . $b->id, $revPage->hrefs(), 'reverses: linked back');
        self::assertStringContainsString('Rejected at review', $revPage->text(), 'the reason by its name');
        self::assertStringNotContainsString('review_rejected', $revPage->text());

        // The blocking approval, approved: posted now.
        $r = $web->post('/ui/documents/reviews/' . $this->task($held->id) . '/approve', $web->get('/ui/documents/' . $held->id)
            ->form('/ui/documents/reviews/' . $this->task($held->id) . '/approve'));
        self::assertSame('/ui/documents/' . $held->id . '?notice=approved_posted', $r->location());
        self::assertSame(['posted', 'ADJ-000004', $poster['id']], array_values((array) self::$db->one('SELECT status, number, posted_by FROM document WHERE id = ?', [$held->id])));
        self::assertStringContainsString(Words::CHECKS['none'], $web->get('/ui/documents/reviews')->text());
    }

    /**
     * I31, I32 on the screens: a reversal that puts more than the limit back on hand is a request (notice, no number), a
     * reviewer approves it through the form; the review of a posted reversal offers "Reject" (not "Reject and reverse"),
     * says why, and rejecting it books nothing.
     */
    public function testReversalRequestsAndTheReviewOfAReversal(): void
    {
        $sc = $this->uiUser('stock_controller');
        $sc2 = $this->uiUser('stock_controller');
        $reviewer = $this->uiUser('reviewer');
        $sku = self::makeSku('Reversal screen item');
        $p = $this->post($sc, [['sku_id' => $sku, 'qty' => -30]]);
        $web = $this->signIn($sc2);
        $form = $web->get('/ui/documents/' . $p->id)->form('/ui/documents/' . $p->id . '/reverse');
        $r = $web->post('/ui/documents/' . $p->id . '/reverse', ['reason_code' => 'entered_in_error'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        $reqId = (int) self::$db->value("SELECT id FROM document WHERE reverses_id = ? AND status = 'awaiting_approval'", [$p->id]);
        self::assertSame('/ui/documents/' . $reqId . '?notice=reversal_submitted', $r->location());
        self::assertStringContainsString(DocumentsController::NOTICES['reversal_submitted'], $web->follow($r)->text());
        $orig = $web->get('/ui/documents/' . $p->id);
        self::assertStringContainsString(Words::RECORD['cancel_asked'], $orig->text());
        self::assertFalse($orig->hasForm('/ui/documents/' . $p->id . '/reverse'), 'no second reversal while one waits');

        $rv = $this->signIn($reviewer);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_id = ? AND kind = 'approval' AND state = 'open'", [$reqId]);
        $page = $rv->get('/ui/documents/' . $reqId);
        self::assertStringContainsString(Words::RECORD['does_ok_cancel'], $page->text());
        self::assertSame(Words::UI['safer'], trim((string) (new \DOMXPath($page->dom()))->evaluate('string(//span[@class="safe-tag"])')),
            'refusing a request books nothing: the safer answer (design B)');
        $ok = $rv->post("/ui/documents/reviews/{$task}/approve", $page->form("/ui/documents/reviews/{$task}/approve"));
        self::assertSame('/ui/documents/' . $reqId . '?notice=approved_posted', $ok->location());
        self::assertSame(['posted', 'reversed'], [self::$db->value('SELECT status FROM document WHERE id = ?', [$reqId]),
            self::$db->value('SELECT status FROM document WHERE id = ?', [$p->id])]);

        // A small reversal is posted and reviewed; its review offers Reject without "and reverse", and books nothing.
        $q = $this->post($sc, [['sku_id' => $sku, 'qty' => -2]]);
        $small = $this->docs()->reverse(Caller::staff($sc2['id']), $q->id, 'duplicate', null);
        $task = $this->task($small->id);
        $page = $rv->get('/ui/documents/' . $small->id);
        self::assertStringContainsString(Words::RECORD['does_not_ok_cancel'], $page->text());
        self::assertStringNotContainsString(Words::RECORD['does_not_ok_review'], $page->text());
        $rows = (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger');
        $r = $rv->post("/ui/documents/reviews/{$task}/reject", ['note' => 'the original was right'] + $page->form("/ui/documents/reviews/{$task}/reject"));
        self::assertSame('/ui/documents/' . $small->id . '?notice=rejected_reversal', $r->location());
        self::assertStringContainsString(DocumentsController::NOTICES['rejected_reversal'], $rv->follow($r)->text());
        self::assertSame($rows, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger'));
        self::assertSame([], \CW\Invariants::check(self::$db));
    }

    public function testThePosterSeesWhyAndNoFormsAndOthersAreRefused(): void
    {
        $poster = $this->uiUser(['stock_controller', 'reviewer']);
        $sku = self::makeSku('Own item');
        $doc = $this->post($poster, [['sku_id' => $sku, 'qty' => 2]]);
        $web = $this->signIn($poster);
        $page = $web->get('/ui/documents/' . $doc->id);
        self::assertSame(200, $page->status);
        self::assertStringContainsString(Words::REFUSAL['own_document'], $page->text());
        self::assertFalse($page->hasForm('/approve'));
        self::assertFalse($page->hasForm('/reject'));
        self::assertStringNotContainsString('class="badge"', $page->body, 'their own document is not in their count');
        $queue = $web->get('/ui/documents/reviews');
        self::assertStringContainsString(Words::REFUSAL['own_document'], $queue->text());
        self::assertNotContains('/ui/documents/reviews/' . $this->task($doc->id) . '/approve', $queue->hrefs());
        // A forged POST is refused by the service with the same reason.
        $r = $web->post('/ui/documents/reviews/' . $this->task($doc->id) . '/approve', ['csrf' => $this->token($web)]);
        self::assertSame(403, $r->status);
        self::assertStringContainsString(Words::REFUSAL['own_document'], $r->text(), 'the forged POST: the same reason, by its code');
        self::assertSame('pending', self::$db->value('SELECT review_state FROM document WHERE id = ?', [$doc->id]));

        // The poster may reverse it (a correction is a reversal, I18): the form, the reason list, the result.
        $form = $page->form('/ui/documents/' . $doc->id . '/reverse');
        self::assertArrayHasKey('reason_code', $form);
        self::assertSame(['', 'entered_in_error', 'duplicate', 'other'], array_map(static fn (\DOMElement $o): string => $o->getAttribute('value'),
            iterator_to_array((new \DOMXPath($page->dom()))->query('//select[@name="reason_code"]/option'))), 'reversal reasons, never CW\'s own');
        $r = $web->post('/ui/documents/' . $doc->id . '/reverse', ['reason_code' => 'duplicate'] + $form);
        self::assertSame(303, $r->status, $r->describe());
        $rev = $web->follow($r);
        self::assertStringContainsString(DocumentsController::NOTICES['reversed'], $rev->text());
        self::assertStringContainsString('ADJ-000002', $rev->text());
        self::assertFalse($rev->hasForm('/reverse'), 'a reversal is never reversed');
        $r = $web->post('/ui/documents/' . $doc->id . '/reverse', ['reason_code' => 'duplicate'] + $form);
        self::assertSame(409, $r->status);
        self::assertStringContainsString(Words::RECORD['e_not_reversible'], $r->text(), 'the service\'s refusal in the page\'s words, by code');

        // A buyer reads documents but has no review queue and cannot reverse an ADJ.
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->get('/ui/documents/reviews')->status);
        $page = $buyer->get('/ui/documents/' . $doc->id);
        self::assertSame(200, $page->status);
        self::assertFalse($page->hasForm('/reverse'));
        self::assertStringNotContainsString('another reviewer', $page->text());
        self::assertStringNotContainsString(Words::RECORD['waiting'], $page->text(), 'its review task was withdrawn by the reversal');
        self::assertStringContainsString(Words::say('RECORD', 'closed_cancelled', 'ADJ-000002'), $page->text(), 'the history says why the check closed (F416)');
        $r = $buyer->post('/ui/documents/' . $doc->id . '/reverse', ['csrf' => $this->token($buyer), 'reason_code' => 'duplicate']);
        self::assertSame(403, $r->status);
        self::assertStringContainsString(Words::RECORD['cancel_not_allowed'], $r->text());
        // A viewer (linking only) has no documents at all.
        self::assertSame(403, $this->signIn($this->uiUser('viewer'))->get('/ui/documents')->status);
    }

    public function testTheListATypeWithoutScreensAndThePdf(): void
    {
        $poster = $this->uiUser('stock_controller');
        $sku = self::makeSku('PDF item');
        $doc = $this->post($poster, [['sku_id' => $sku, 'qty' => 4, 'unit_cost' => '2.25'], ['sku_id' => $sku, 'qty' => -1, 'warehouse' => 'VERIFY']],
            ['external_ref' => 'SUP-PDF', 'note' => 'Café delivery £12.50']);
        // A type without screens yet (PO is live since the I-2 pos task, GRN since IM6 in I-3: SINV arrives in I-4).
        $draftGrn = self::$db->insert("INSERT INTO document (doc_type, created_actor, external_ref) VALUES ('SINV', 'staff:1', 'SINV-DRAFT-REF')");
        $web = $this->signIn($this->uiUser('auditor'));

        $list = $web->get('/ui/documents');
        self::assertSame(200, $list->status, $list->describe());
        self::assertStringContainsString('2 records, newest first.', $list->text());
        self::assertContains('/ui/documents/' . $doc->id, $list->hrefs());
        self::assertStringContainsString('Today this list holds purchase orders, deliveries and stock corrections, and their cancellations.', $list->text(),
            'the fixture ADJ is live in tests (and GRN since IM6)');
        self::assertSame('All records', trim((string) (new \DOMXPath($list->dom()))->evaluate('string(//main//h1)')));
        $lxp = new \DOMXPath($list->dom());
        self::assertSame(1, $lxp->query('//table[contains(@class, "stack")]')->length, 'one card per record on a phone');
        self::assertSame(['Final', 'Draft'], [trim((string) $lxp->evaluate('string(//tbody/tr[2]/td[contains(@class, "c-status")])')),
            trim((string) $lxp->evaluate('string(//tbody/tr[1]/td[contains(@class, "c-status")])'))], 'status words (F407)');
        self::assertStringNotContainsString('posted', $list->text());
        self::assertStringContainsString(Words::RECORDS['total_one'], $web->get('/ui/documents', ['type' => 'SINV'])->text());
        self::assertStringContainsString(Words::RECORDS['total_one'], $web->get('/ui/documents', ['q' => 'SUP-PDF'])->text());
        self::assertStringContainsString(Words::RECORDS['none_filter'], $web->get('/ui/documents', ['status' => 'reversed'])->text());
        self::assertSame(200, $web->get('/ui/documents', ['type' => '<script>', 'status' => 'nope', 'page' => '999'])->status, 'unknown filters are ignored');

        $grn = $web->get('/ui/documents/' . $draftGrn);
        self::assertStringContainsString(Words::say('RECORD', 'later', 'supplier invoices'), $grn->text());
        self::assertSame(404, $web->get('/ui/documents/999999')->status);

        $pdf = $web->get('/ui/documents/' . $doc->id . '/pdf');
        self::assertSame(200, $pdf->status);
        self::assertSame('application/pdf', $pdf->header('content-type'));
        self::assertSame('attachment; filename="ADJ-000001.pdf"; filename*=UTF-8\'\'ADJ-000001.pdf', $pdf->header('content-disposition'));
        self::assertSame(['sandbox', Kernel::CSP], $pdf->headerValues('content-security-policy'), 'the sandbox and the kernel policy, both enforced');
        self::assertSame('nosniff', $pdf->header('x-content-type-options'));
        self::assertSame('no-store', $pdf->header('cache-control'));
        self::assertStringStartsWith('%PDF-1.', $pdf->body);
        self::assertSame('%%EOF', substr(rtrim($pdf->body), -5));

        // The production kernel (DocumentHandlers::all): PO is live since the I-2 pos task, the rest arrive with their phases.
        $db = self::$appDb;
        $key = self::$uiKey;
        $plain = new KernelBrowser(new Kernel(static fn (): Db => $db, static fn (): string => $key, static function (): void {
        }));
        $plain->cookies = $web->cookies;
        $live = $plain->get('/ui/documents');
        self::assertStringContainsString('Today this list holds purchase orders and deliveries, and their cancellations. Supplier invoices, returns to suppliers, '
            . 'stock counts, stock corrections, write-offs and trade sales will appear here when those screens are added.', $live->text(), 'F406: no phase codes');
        self::assertStringNotContainsString('Phase', $live->text());
        self::assertStringContainsString(Words::say('RECORD', 'later', 'stock corrections'), $plain->get('/ui/documents/' . $doc->id)->text());
    }
}
