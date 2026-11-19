<?php

declare(strict_types=1);

namespace CW\Documents;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Movements;
use CW\Staff\StaffRoles;

/**
 * The document base (IM1; docs/decisions.md I17-I21, I27): drafts, posting, the post-first review, the two blocking
 * approvals' infrastructure, reversal and the number series, for every document type (PO, GRN, SINV, DN, CNT, ADJ,
 * WO, TRD). What a type DOES when it is posted is its DocumentHandler's (DocumentHandlers; none is live in I-1).
 *
 * Every public write is ONE Db::transaction (deadlock retry), takes a staff Caller (403 staff_required otherwise) and
 * re-reads the caller's roles inside it (StaffRoles::active: an inactive person is refused). The one exception is SYSTEM_JOBS
 * (CW-917, docs/decisions.md EC4): postBySystem() / reverseBySystem() let the named job keep the named types (the copies of ERPNext
 * purchase invoices, which move no stock) with no person and nothing for Approvals. Drafting, posting and
 * reversing need `doc.<TYPE>.post`; a caller holding admin is refused (403 admin_cannot_post, I12) before anything else.
 * A draft's header and lines are changed by anyone allowed to post its type, not only by the person who created it
 * (docs/decisions.md DA1; the owner, 18 Nov 2026: "anyone who have access should be able to edit it"; until then I19 kept a
 * draft its creator's). created_by stays the person who started it; each save is audited under the person who made it
 * (DraftChanges reads who changed what). Every write locks the document row FOR UPDATE first, checks its status (409
 * not_draft, ...) and the version the form was drawn with (409 version_conflict, naming who saved it last when CW knows:
 * DraftChanges::conflict), and moves `version` and `updated_at`.
 *
 * Posting, in this order (I21: nothing with a foreign key is written after the stock locks):
 *   1. the document row FOR UPDATE; permission, status, version
 *   2. the lines (line_no order); generic checks (reasons still active and applicable, notes, items not merged);
 *      handler->validate()
 *   3. a type with an approval rule whose handler->approvalUnits() exceeds the limit: status awaiting_approval and an
 *      open approval task (blocking, I19); nothing is numbered or booked
 *   4. NumberSeries::next(prefix): the series row X-locked to commit
 *   5. ONE UPDATE of the header: number, status posted, posted_by/actor/at, posted_hash (fingerprint()), review_state
 *      not_required (provisional), version + 1; and the write-once posting record (document_posting: the hash and the
 *      canonical content it covers, I33), which the app login can add but never rewrite
 *   6. audit document.post (with posted_hash)
 *   7. handler->post(): module rows, then stock in one Movements::bookForDocument (stock_balance -> sku -> value clocks
 *      -> feed clock, last)
 *   8. the review the type's rule asks for: review_state pending (the row is already X-locked: no new lock) and an
 *      open review task (review_task has no foreign keys)
 *
 * Reviews (I19): post first, a second person reviews. A task is decided by someone holding documents.review (a review)
 * or documents.approve (an approval) who is not the document's creator, submitter or poster (403 own_document, also a
 * CHECK on review_task) and never by admin. Rejecting a posted document posts its reversal in the same transaction
 * (reason review_rejected, created and posted by the reviewer, not reviewed again); rejecting a REVERSAL's review
 * records the rejection and books nothing (a reversal is never reversed, I31); rejecting an approval cancels the
 * request; the requester may withdraw an open approval (the document goes back to draft; a reversal request is
 * cancelled instead: a reversal is never a draft). An approval's posting is the requester's (posted_by, the ledger's
 * actor) and is audited under the reviewer who performed it (I34).
 *
 * Corrections are reversals (I18): a new document of the same type and series, `reverses_id` = the original (one
 * live reversal per document: UNIQUE live_reverses_id, which a cancelled reversal request leaves free), lines copied
 * with qty and amount negated, stock negated exactly by Movements::reverseDocument; the original becomes `reversed`; a
 * reversal is never reversed. A voluntary reversal that would put more units back on hand than the approval limit
 * of "positive without a supplier document" (the reversal of a write-down, I32) waits for that blocking approval
 * like a positive adjustment: unnumbered and unbooked until a reviewer approves it.
 */
final class Documents
{
    /** Header fields a draft takes (warehouse is a warehouse code). */
    public const HEADER_FIELDS = ['external_ref', 'doc_date', 'warehouse', 'reason_code', 'note'];
    /** Fields of one line (an item by sku_id or sku_code, or none for freight, duty, ...; warehouse is a code). */
    public const LINE_FIELDS = ['sku_id', 'sku_code', 'warehouse', 'qty', 'unit_cost', 'amount', 'reason_code', 'description'];
    /**
     * reason_code.applies_to of the types whose header and lines carry a reason; other types carry none (I22). A stock in and a
     * stock out (pack A1) take the reasons offered for them on the Reasons page.
     */
    public const REASON_USE = ['ADJ' => 'adjustment', 'WO' => 'write_off', 'CNT' => 'count', 'DN' => 'supplier_return', 'SIN' => 'stock_in', 'SOUT' => 'stock_out'];
    public const MAX_LINES = Movements::MAX_LINES;
    /** The largest line id (document_line.uid, INT UNSIGNED; 0070). */
    private const UID_MAX = 4294967295;
    public const MAX_QTY = 10_000_000;
    /** A reviewer's note: optional on an approval, 3-500 characters on a rejection (it is the reason). */
    public const NOTE_MIN = 3;
    public const NOTE_MAX = 500;

    private readonly NumberSeries $series;
    private readonly Movements $moves;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param array<string, DocumentHandler> $handlers type code => handler (DocumentHandlers::all in production)
     * @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (posted_at, due dates; also the reversal's effective_at)
     */
    public function __construct(private readonly Db $db, private readonly array $handlers, ?\Closure $clock = null)
    {
        foreach ($handlers as $type => $h) {
            if (!$h instanceof DocumentHandler || $h->type() !== $type) {
                throw new \InvalidArgumentException("the handler registered for {$type} must be a DocumentHandler of that type");
            }
        }
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->series = new NumberSeries($db);
        $this->moves = new Movements($db, null, $this->clock);
    }

    // ------------------------------------------------------------------------------------------
    // Drafts
    // ------------------------------------------------------------------------------------------

    /**
     * A new draft of $type. 400 unknown_type; 409 type_not_built (no handler yet: detail phase); 403 admin_cannot_post /
     * role_not_allowed (doc.<TYPE>.post).
     *
     * @param array<string, mixed> $header external_ref, doc_date (Y-m-d), warehouse (code), reason_code, note
     */
    public function createDraft(Caller $caller, string $type, array $header): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $type, $header): Document {
            $me = $this->staff($caller);
            $t = $this->typeRow($type);
            $this->handlerFor($t);
            $this->checkPoster($me, $type);
            $h = $this->header($type, $header, null);
            $now = $this->nowDb();
            $id = $db->insert(
                'INSERT INTO document (doc_type, status, version, external_ref, doc_date, warehouse_id, reason_code, note, created_by, created_actor, '
                . "created_at, updated_at) VALUES (?, 'draft', 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$type, $h['external_ref'], $h['doc_date'], $h['warehouse_id'], $h['reason_code'], $h['note'], $me['id'], $caller->actor, $now, $now],
            );
            Audit::write($db, $caller, 'document.create', 'document', (string) $id, null, ['type' => $type] + $h);
            return $this->get($id);
        });
    }

    /**
     * Changes header fields of a draft (the keys given; null clears one). Anyone allowed to post the type (DA1).
     *
     * @param array<string, mixed> $header
     */
    public function updateDraft(Caller $caller, int $id, int $expectedVersion, array $header): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $expectedVersion, $header): Document {
            $me = $this->staff($caller);
            $row = $this->lockDraft($id, $expectedVersion);
            $this->checkPoster($me, (string) $row['doc_type']);
            $h = $this->header((string) $row['doc_type'], $header, $row);
            $db->exec('UPDATE document SET external_ref = ?, doc_date = ?, warehouse_id = ?, reason_code = ?, note = ?, version = version + 1, '
                . 'updated_at = ? WHERE id = ?',
                [$h['external_ref'], $h['doc_date'], $h['warehouse_id'], $h['reason_code'], $h['note'], $this->nowDb(), $id]);
            $changed = [];
            foreach ($h as $k => $v) {
                if ($v !== $row[$k]) {
                    $changed[$k] = $v;
                }
            }
            Audit::write($db, $caller, 'document.update', 'document', (string) $id, null, ['version' => $expectedVersion + 1, 'changed' => $changed]);
            return $this->get($id);
        });
    }

    /**
     * Replaces every line of a draft (line_no 1..N in list order; [] clears them). Anyone allowed to post the type (DA1). Each line is checked:
     * the item exists and is not merged (422 unknown_sku / merged_item), the warehouse is known (422 unknown_warehouse),
     * the reason code is active, applies to the type and is not CW's own (422), qty is an integer (400 bad_lines),
     * unit_cost passes Movements::normaliseCost (400 bad_cost), amount is a decimal (400 bad_amount).
     * A line's own id (document_line.uid; 0070, docs/decisions.md LV8): a line may name the id it had (`uid`, a line of this draft now: 400
     * bad_lines for another, or for one id twice); the line keeps it, wherever it is now. A line without one (absent or null) gets the
     * draft's next free id (document.next_line_uid), never given before in this document. The ids are not in the posting fingerprint.
     * A line's own version (document_line.rev, changed_by, changed_at; 0072, docs/decisions.md LS2): a line that keeps its id keeps its
     * version, who changed it and when while its content is the same as the old line's (its place may move: a line removed above it is not a
     * change of it); otherwise its version moves by one and it is the caller's, now. A new line: version 1, the caller's. $keys / $oldKeys:
     * the module's own part of each line's content (PurchaseOrders: the order's own row, po_line), $keys in the order of $lines, $oldKeys by
     * the old line's id; a line whose key differs is changed too. Neither is in the posting fingerprint.
     *
     * @param list<array<string, mixed>> $lines
     * @param list<string> $keys
     * @param array<int, string> $oldKeys
     */
    public function setLines(Caller $caller, int $id, int $expectedVersion, array $lines, array $keys = [], array $oldKeys = []): Document
    {
        [$lines, $uids] = self::takeUids($lines);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $expectedVersion, $lines, $uids, $keys, $oldKeys): Document {
            $me = $this->staff($caller);
            $row = $this->lockDraft($id, $expectedVersion);
            $type = (string) $row['doc_type'];
            $this->checkPoster($me, $type);
            $norm = $this->normaliseLines($type, $lines);
            $this->writeLines($me['id'], $row, $norm, $uids, $keys, $oldKeys);
            Audit::write($db, $caller, 'document.lines', 'document', (string) $id, null, ['version' => $expectedVersion + 1, 'lines' => count($norm)]);
            return $this->get($id);
        });
    }

    /**
     * Replaces every line of a draft as setLines() does, for a module's own change of one line that renumbers the others (a line removed, a
     * line put back: PurchaseOrders::removeLine, restoreLine; docs/decisions.md LS3), inside the module's transaction: the draft row locked
     * here (409 not_draft), the caller allowed to post its type, NO version check (the module checked the line's own version) and NO audit
     * row (the module records the change: Live\LiveChanges, one audit row per person per draft per hour). Ids, versions and the next free id
     * as setLines(); the document's version moves by one.
     *
     * @param list<array<string, mixed>> $lines
     * @param list<string> $keys
     * @param array<int, string> $oldKeys
     */
    public function replaceLinesLive(Caller $caller, int $id, array $lines, array $keys = [], array $oldKeys = []): Document
    {
        [$lines, $uids] = self::takeUids($lines);
        return $this->db->transaction(function () use ($caller, $id, $lines, $uids, $keys, $oldKeys): Document {
            $me = $this->staff($caller);
            $row = $this->lockLiveDraft($id);
            $type = (string) $row['doc_type'];
            $this->checkPoster($me, $type);
            $this->writeLines($me['id'], $row, $this->normaliseLines($type, $lines), $uids, $keys, $oldKeys);
            return $this->get($id);
        });
    }

    /**
     * Changes ONE line of a draft in place (docs/decisions.md LS3; PurchaseOrders::setLine): the line named by its own id, at the version the
     * page saw (409 line_conflict with the version now; 409 line_gone when it is no longer there), its content as $line (the fields of
     * LINE_FIELDS, checked as setLines checks a line). Its version moves by one and it is the caller's, now; the document's version moves by
     * one. Inside the module's transaction; the document row locked first (409 not_draft); the caller allowed to post its type; no audit row
     * (the module records the change).
     *
     * @param array<string, mixed> $line
     */
    public function changeLine(Caller $caller, int $id, int $uid, int $expectedRev, array $line): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $uid, $expectedRev, $line): Document {
            $me = $this->staff($caller);
            $row = $this->lockLiveDraft($id);
            $type = (string) $row['doc_type'];
            $this->checkPoster($me, $type);
            $old = $db->one('SELECT line_no, rev FROM document_line WHERE document_id = ? AND uid = ? FOR UPDATE', [$id, $uid])
                ?? throw new CwException('line_gone', "there is no line with id {$uid} on this draft", 409, ['uid' => $uid]);
            if ((int) $old['rev'] !== $expectedRev) {
                throw new CwException('line_conflict', "line id {$uid} changed since the page was drawn", 409, ['uid' => $uid, 'rev' => (int) $old['rev'],
                    'line' => (int) $old['line_no']]);
            }
            $n = $this->normaliseLines($type, [$line])[0];
            $now = $this->nowDb();
            $db->exec('UPDATE document_line SET sku_id = ?, warehouse_id = ?, qty = ?, unit_cost = ?, amount = ?, reason_code = ?, description = ?, rev = rev + 1, '
                . 'changed_by = ?, changed_at = ? WHERE document_id = ? AND uid = ?', [$n['sku_id'], $n['warehouse_id'], $n['qty'], $n['unit_cost'], $n['amount'],
                    $n['reason_code'], $n['description'], $me['id'], $now, $id, $uid]);
            $db->exec('UPDATE document SET version = version + 1, updated_at = ? WHERE id = ?', [$now, $id]);
            return $this->get($id);
        });
    }

    /**
     * Adds ONE line after the draft's last (docs/decisions.md LS3; PurchaseOrders::addLineLive): the draft's next free id, version 1, the
     * caller's, now; the document's version and its next free id move by one. At most MAX_LINES (422 too_many_lines). Inside the module's
     * transaction, as changeLine().
     *
     * @param array<string, mixed> $line
     * @return array{document: Document, uid: int, line_no: int}
     */
    public function appendLine(Caller $caller, int $id, array $line): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $line): array {
            $me = $this->staff($caller);
            $row = $this->lockLiveDraft($id);
            $type = (string) $row['doc_type'];
            $this->checkPoster($me, $type);
            $n = $this->normaliseLines($type, [$line])[0];
            $at = $db->one('SELECT COUNT(*) AS n, COALESCE(MAX(line_no), 0) AS last, COALESCE(MAX(uid), 0) AS top FROM document_line WHERE document_id = ?', [$id]);
            if ((int) $at['n'] >= self::MAX_LINES) {
                throw new CwException('too_many_lines', 'a document has at most ' . self::MAX_LINES . ' lines', 422);
            }
            $uid = max((int) ($row['next_line_uid'] ?? 1), (int) $at['top'] + 1, 1);
            $no = (int) $at['last'] + 1;
            $now = $this->nowDb();
            $db->exec('INSERT INTO document_line (document_id, line_no, uid, rev, changed_by, changed_at, sku_id, warehouse_id, qty, unit_cost, amount, reason_code, description) '
                . 'VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, $no, $uid, $me['id'], $now, $n['sku_id'], $n['warehouse_id'], $n['qty'], $n['unit_cost'], $n['amount'],
                    $n['reason_code'], $n['description']]);
            $db->exec('UPDATE document SET version = version + 1, next_line_uid = ?, updated_at = ? WHERE id = ?', [$uid + 1, $now, $id]);
            return ['document' => $this->get($id), 'uid' => $uid, 'line_no' => $no];
        });
    }

    /**
     * Changes header fields of a draft in place for a module's own change of one box (docs/decisions.md LS4; PurchaseOrders::setHeaderField):
     * the keys given (of HEADER_FIELDS; none: only the version moves, a field of the module's own row changed), checked as updateDraft checks
     * them; NO version check (the module checked the value the page saw) and NO audit row (the module records the change); the document's
     * version moves by one. Inside the module's transaction; the document row locked first (409 not_draft).
     *
     * @param array<string, mixed> $header
     */
    public function changeHeaderLive(Caller $caller, int $id, array $header): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $header): Document {
            $me = $this->staff($caller);
            $row = $this->lockLiveDraft($id);
            $this->checkPoster($me, (string) $row['doc_type']);
            $h = $this->header((string) $row['doc_type'], $header, $row);
            $db->exec('UPDATE document SET external_ref = ?, doc_date = ?, warehouse_id = ?, reason_code = ?, note = ?, version = version + 1, updated_at = ? WHERE id = ?',
                [$h['external_ref'], $h['doc_date'], $h['warehouse_id'], $h['reason_code'], $h['note'], $this->nowDb(), $id]);
            return $this->get($id);
        });
    }

    /**
     * Gives an id to every line of a draft that has none (a line written before 0070 by code that gave none), in line order, from the next
     * free one (docs/decisions.md LS3: a page saves a line by its id). Nothing else moves, not the version. Returns how many got one.
     */
    public function ensureUids(int $id): int
    {
        return $this->db->transaction(function (Db $db) use ($id): int {
            $row = $this->lock($id);
            if ($row['status'] !== 'draft') {
                return 0;
            }
            $none = array_map('intval', $db->column('SELECT line_no FROM document_line WHERE document_id = ? AND uid IS NULL ORDER BY line_no', [$id]));
            if ($none === []) {
                return 0;
            }
            $next = max((int) $row['next_line_uid'], (int) $db->value('SELECT COALESCE(MAX(uid), 0) FROM document_line WHERE document_id = ?', [$id]) + 1, 1);
            foreach ($none as $no) {
                $db->exec('UPDATE document_line SET uid = ? WHERE document_id = ? AND line_no = ?', [$next++, $id, $no]);
            }
            $db->exec('UPDATE document SET next_line_uid = ? WHERE id = ?', [$next, $id]);
            return count($none);
        });
    }

    /**
     * The lines of a draft written again (setLines, replaceLinesLive): each line's id (giveUids), its version kept or moved (LS2), then the
     * old lines out and the new in, the document's version + 1 and its next free id. The document row is locked by the caller.
     *
     * @param array<string, mixed> $row the document row, X-locked
     * @param list<array<string, mixed>> $norm normaliseLines()
     * @param list<?int> $uids takeUids()
     * @param list<string> $keys
     * @param array<int, string> $oldKeys
     */
    private function writeLines(int $staffId, array $row, array $norm, array $uids, array $keys, array $oldKeys): void
    {
        $id = (int) $row['id'];
        $db = $this->db;
        [$given, $next] = $this->giveUids($id, (int) ($row['next_line_uid'] ?? 1), $uids);
        $old = [];
        foreach ($db->all('SELECT * FROM document_line WHERE document_id = ? AND uid IS NOT NULL', [$id]) as $o) {
            $old[(int) $o['uid']] = $o;
        }
        $now = $this->nowDb();
        $db->exec('DELETE FROM document_line WHERE document_id = ?', [$id]);
        foreach (array_chunk($norm, 500, true) as $chunk) {
            $params = [];
            foreach ($chunk as $i => $l) {
                $o = $old[$given[$i]] ?? null;
                $same = $o !== null && self::sameContent(self::lineRow($o), $l) && ($keys[$i] ?? null) === ($oldKeys[$given[$i]] ?? null);
                array_push($params, $id, $l['line_no'], $given[$i], $o === null ? 1 : ((int) $o['rev'] + ($same ? 0 : 1)), $same ? $o['changed_by'] : $staffId,
                    $same ? $o['changed_at'] : $now, $l['sku_id'], $l['warehouse_id'], $l['qty'], $l['unit_cost'], $l['amount'], $l['reason_code'], $l['description']);
            }
            $db->exec('INSERT INTO document_line (document_id, line_no, uid, rev, changed_by, changed_at, sku_id, warehouse_id, qty, unit_cost, amount, reason_code, description) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
        }
        $db->exec('UPDATE document SET version = version + 1, next_line_uid = ?, updated_at = ? WHERE id = ?', [$next, $now, $id]);
    }

    /** Whether a line's content (all but its number) is the same: an old row as lineRow() reads it, a new one as normaliseLines() makes it. */
    private static function sameContent(array $a, array $b): bool
    {
        foreach (['sku_id', 'warehouse_id', 'qty', 'unit_cost', 'amount', 'reason_code', 'description'] as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** Cancels a draft (never numbered, nothing booked). $reason 3-500 characters (400 bad_cancel_reason). */
    public function cancelDraft(Caller $caller, int $id, int $expectedVersion, string $reason): Document
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::NOTE_MIN || mb_strlen($reason) > self::NOTE_MAX) {
            throw new CwException('bad_cancel_reason', 'say in ' . self::NOTE_MIN . ' to ' . self::NOTE_MAX . ' characters why the draft is cancelled', 400);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $expectedVersion, $reason): Document {
            $me = $this->staff($caller);
            $row = $this->lockDraft($id, $expectedVersion);
            $this->checkPoster($me, (string) $row['doc_type']);
            $now = $this->nowDb();
            $db->exec("UPDATE document SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, cancel_reason = ?, version = version + 1, "
                . 'updated_at = ? WHERE id = ?', [$me['id'], $now, $reason, $now, $id]);
            Audit::write($db, $caller, 'document.cancel', 'document', (string) $id, null, ['reason' => $reason]);
            $this->cancelled($id, $caller); // Pack C: CancelHook
            return $this->get($id);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Posting
    // ------------------------------------------------------------------------------------------

    /**
     * Posts a draft (the order of the class docblock). Returns the document as it is afterwards: posted (numbered,
     * booked, review_state not_required or pending) or awaiting_approval (blocking approval, nothing booked).
     */
    public function post(Caller $caller, int $id, int $expectedVersion): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $expectedVersion): Document {
            $me = $this->staff($caller);
            $row = $this->lockDraft($id, $expectedVersion);
            $t = $this->typeRow((string) $row['doc_type']);
            $handler = $this->handlerFor($t);
            $this->checkPoster($me, (string) $t['code']);
            $doc = Document::fromRow($row);
            $lines = $this->lines($id);
            $this->validateForPosting($doc, $lines);
            $handler->validate($db, $doc, $lines);
            if ($t['approval_rule'] !== 'none') {
                $units = $handler->approvalUnits($db, $doc, $lines);
                if ($units > (int) $t['approval_limit_units']) {
                    return $this->submit($caller, $me['id'], $doc, $t, $units, (string) $t['approval_rule'], ['limit' => (int) $t['approval_limit_units']]);
                }
            }
            // The OK first for a big record (pack A1, docs/decisions.md SO5; off by default): more units, or more pounds, than the
            // type's sizes.
            if ((int) ($t['size_approval'] ?? 0) === 1 && $handler instanceof SizeApproval) {
                $size = $handler->size($db, $doc, $lines);
                $maxUnits = $t['size_units'] === null ? null : (int) $t['size_units'];
                $maxValue = $t['size_value'] === null ? null : (int) $t['size_value'];
                if (($maxUnits !== null && $size['units'] > $maxUnits) || ($maxValue !== null && $size['value'] > $maxValue)) {
                    return $this->submit($caller, $me['id'], $doc, $t, $size['units'], 'over_size',
                        ['size_units' => $maxUnits, 'size_value' => $maxValue, 'value' => $size['value']]);
                }
            }
            return $this->postNow($caller, $me['id'], $doc, $t, $handler, $lines, null);
        });
    }

    /**
     * Reverses a posted document voluntarily (a correction: I18). Needs doc.<TYPE>.post and a reason that applies to
     * reversals ($use: `reversal`, or the purchase-order screens' po_cancel / po_amend, Y51; not CW's own; a note when the reason
     * needs one). 409 not_reversible for a draft, a reversed document or
     * a reversal; 409 reversal_pending while a reversal request of it waits for approval. A reversal that puts more
     * units back on hand than the "positive without a supplier document" limit waits for a blocking approval (I32:
     * awaiting_approval, nothing numbered or booked); otherwise it is posted now and reviewed under the type's rule (its
     * units: what it moved). Returns the reversal.
     */
    public function reverse(Caller $caller, int $id, string $reasonCode, ?string $note, string $use = 'reversal'): Document
    {
        $note = self::optText($note, 'note', 1000);
        return $this->db->transaction(function (Db $db) use ($caller, $id, $reasonCode, $note, $use): Document {
            $me = $this->staff($caller);
            $row = $this->lock($id);
            $t = $this->typeRow((string) $row['doc_type']);
            $handler = $this->handlerFor($t);
            $this->checkPoster($me, (string) $t['code']);
            $doc = Document::fromRow($row);
            if ($doc->status !== 'posted' || $doc->isReversal()) {
                throw new CwException('not_reversible', match (true) {
                    $doc->status === 'reversed' => "{$doc->label()} is already reversed",
                    $doc->isReversal() => "{$doc->label()} is a reversal: a reversal is never reversed (post a new document instead)",
                    default => "{$doc->label()} is not posted: only a posted document is reversed",
                }, 409, ['status' => $doc->status]);
            }
            $pending = $db->value("SELECT id FROM document WHERE reverses_id = ? AND status = 'awaiting_approval'", [$doc->id]);
            if ($pending !== null) {
                throw new CwException('reversal_pending', "{$doc->label()} already has a reversal waiting for approval: a reviewer decides it, "
                    . 'or its requester withdraws it', 409, ['reversal_id' => (int) $pending]);
            }
            $reason = $this->reason($reasonCode, $use, 'reason_code', true);
            if ((int) $reason['needs_note'] === 1 && $note === null) {
                throw new CwException('note_required', "the reason {$reasonCode} needs a note", 422, ['field' => 'note']);
            }
            $now = $this->nowDb();
            $units = $this->unitsBackOnHand($doc->id);
            $limit = $this->positiveLimit();
            if ($limit !== null && $units > $limit) {
                return $this->submitReversal($caller, $me['id'], $doc, $t, $reasonCode, $note, $units, $limit, $now);
            }
            $revId = $this->insertReversal($doc, $me['id'], $caller->actor, $reasonCode, $note, $now, false);
            return $this->postReversal($caller, $me['id'], $doc, $revId, $t, $handler, 'not_required', true, null);
        });
    }

    // ------------------------------------------------------------------------------------------
    // CW-917: the records a system job keeps (docs/decisions.md EC4)
    // ------------------------------------------------------------------------------------------

    /**
     * The system jobs that make and cancel documents without a person, and the types each may (docs/decisions.md EC4): the copy of an
     * ERPNext purchase invoice (bin/copy_erp_invoices.php, Invoices\ErpInvoiceCopy) keeps supplier invoices and credit notes, which move no
     * stock. Nobody else: a person posts as a person (staff()), and every other system caller is refused (403 system_job_only).
     */
    public const SYSTEM_JOBS = ['erp_copy' => ['SINV', 'SCN']];

    /**
     * A document a system job makes and posts in one go (EC4): no draft a person sees, never an approval nor a review (the job keeps a copy
     * of a record another system already accepted: nothing waits in Approvals), created_by and posted_by NULL, created_actor and posted_actor
     * the job's (system:<job>). $extend writes the type's module rows inside the transaction, before the handler's checks; the handler then
     * validates and records it as for a person's posting (it refuses what a system posting may not do).
     *
     * @param array<string, mixed> $header HEADER_FIELDS
     * @param list<array<string, mixed>> $lines LINE_FIELDS
     * @param \Closure(Db, int): void $extend
     */
    public function postBySystem(Caller $job, string $type, array $header, array $lines, \Closure $extend): Document
    {
        return $this->db->transaction(function (Db $db) use ($job, $type, $header, $lines, $extend): Document {
            $this->checkSystemJob($job, $type);
            $t = $this->typeRow($type);
            $handler = $this->handlerFor($t);
            $h = $this->header($type, $header, null);
            $norm = $this->normaliseLines($type, $lines);
            $now = $this->nowDb();
            $id = $db->insert(
                'INSERT INTO document (doc_type, status, version, external_ref, doc_date, warehouse_id, reason_code, note, created_by, created_actor, '
                . "created_at, updated_at) VALUES (?, 'draft', 1, ?, ?, ?, ?, ?, NULL, ?, ?, ?)",
                [$type, $h['external_ref'], $h['doc_date'], $h['warehouse_id'], $h['reason_code'], $h['note'], $job->actor, $now, $now],
            );
            foreach (array_chunk($norm, 500) as $chunk) {
                $params = [];
                foreach ($chunk as $l) {
                    array_push($params, $id, $l['line_no'], $l['sku_id'], $l['warehouse_id'], $l['qty'], $l['unit_cost'], $l['amount'], $l['reason_code'], $l['description']);
                }
                $db->exec('INSERT INTO document_line (document_id, line_no, sku_id, warehouse_id, qty, unit_cost, amount, reason_code, description) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
            }
            Audit::write($db, $job, 'document.create', 'document', (string) $id, null, ['type' => $type, 'lines' => count($norm)] + $h);
            $extend($db, $id);
            $row = $this->lock($id);
            $doc = Document::fromRow($row);
            $docLines = $this->lines($id);
            $this->validateForPosting($doc, $docLines);
            $handler->validate($db, $doc, $docLines);
            return $this->postNow($job, null, $doc, $t, $handler, $docLines, null, false);
        });
    }

    /**
     * Cancels a document a system job keeps by its reversal (EC4), as that job: the same reversal as reverse() (a new document of the same
     * type and series, lines negated, the handler's module rows, the exact stock negation: none for the job's types), with a reason that
     * applies to reversals (CW's own reasons allowed: the job sets them), never an approval nor a review. Returns the reversal.
     */
    public function reverseBySystem(Caller $job, int $id, string $reasonCode, ?string $note): Document
    {
        $note = self::optText($note, 'note', 1000);
        return $this->db->transaction(function (Db $db) use ($job, $id, $reasonCode, $note): Document {
            $row = $this->lock($id);
            $this->checkSystemJob($job, (string) $row['doc_type']);
            $t = $this->typeRow((string) $row['doc_type']);
            $handler = $this->handlerFor($t);
            $doc = Document::fromRow($row);
            if ($doc->status !== 'posted' || $doc->isReversal()) {
                throw new CwException('not_reversible', "{$doc->label()} is " . str_replace('_', ' ', $doc->status) . ': only a posted document is reversed', 409,
                    ['status' => $doc->status]);
            }
            if ($db->value("SELECT id FROM document WHERE reverses_id = ? AND status = 'awaiting_approval'", [$doc->id]) !== null) {
                throw new CwException('reversal_pending', "{$doc->label()} already has a reversal waiting for approval", 409);
            }
            $this->reason($reasonCode, 'reversal', 'reason_code', false);
            $revId = $this->insertReversal($doc, null, $job->actor, $reasonCode, $note, $this->nowDb(), false);
            return $this->postReversal($job, null, $doc, $revId, $t, $handler, 'not_required', false, null);
        });
    }

    /** Whether $caller is one of SYSTEM_JOBS for a document of $type (a person, a store or another job is not). */
    public static function isSystemJob(Caller $caller, string $type): bool
    {
        $job = str_starts_with($caller->actor, 'system:') ? substr($caller->actor, 7) : '';
        return $caller->staffUserId === null && !$caller->isChannel() && $caller->source === $caller->actor
            && in_array($type, self::SYSTEM_JOBS[$job] ?? [], true);
    }

    private function checkSystemJob(Caller $caller, string $type): void
    {
        if (!self::isSystemJob($caller, $type)) {
            throw new CwException('system_job_only', "only CW's own job keeps {$type} records without a person", 403, ['type' => $type]);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Reviews and approvals
    // ------------------------------------------------------------------------------------------

    /**
     * Approves an open task: a review (the posted document stands, review_state approved) or an approval (the
     * document is posted now, as the requester's posting: posted_by = submitted_by, and review_state approved: the
     * approval was the review; audited under the reviewer, I34). An approved reversal request is posted as a reversal
     * (I32). Returns the document.
     */
    public function approve(Caller $reviewer, int $taskId, ?string $note): Document
    {
        $note = self::optText($note, 'note', self::NOTE_MAX);
        return $this->db->transaction(function (Db $db) use ($reviewer, $taskId, $note): Document {
            $me = $this->staff($reviewer);
            [$row, $task] = $this->lockTask($taskId);
            $doc = Document::fromRow($row);
            $this->checkDecider($me, $doc, (string) $task['kind']);
            $now = $this->nowDb();
            if ($task['kind'] === 'review') {
                $this->checkReviewable($doc);
                $this->decideTask($taskId, 'approved', $me['id'], $note, $now);
                $db->exec("UPDATE document SET review_state = 'approved', version = version + 1, updated_at = ? WHERE id = ?", [$now, $doc->id]);
                Audit::write($db, $reviewer, 'document.approve', 'document', (string) $doc->id, null,
                    ['task_id' => $taskId, 'kind' => 'review', 'number' => $doc->number, 'note' => $note]);
                return $this->get($doc->id);
            }
            if ($doc->status !== 'awaiting_approval') {
                throw new CwException('not_awaiting_approval', "{$doc->label()} is not waiting for an approval", 409, ['status' => $doc->status]);
            }
            $t = $this->typeRow($doc->docType);
            $handler = $this->handlerFor($t);
            $requester = (int) $doc->submittedBy;
            $this->checkRequesterMayPost($requester, $doc->docType);
            if ($doc->isReversal()) {
                // I32: the original was locked first (lockTask) and is still posted (one live reversal per document).
                $orig = Document::fromRow($this->lock((int) $doc->reversesId));
                if ($orig->status !== 'posted') {
                    throw new CwException('not_reversible', "{$orig->label()} is " . str_replace('_', ' ', $orig->status) . ': reject this reversal request instead', 409);
                }
                $this->decideTask($taskId, 'approved', $me['id'], $note, $now);
                Audit::write($db, $reviewer, 'document.approve', 'document', (string) $doc->id, null,
                    ['task_id' => $taskId, 'kind' => 'approval', 'units' => $task['units'], 'requested_by' => $requester, 'reverses' => $orig->id, 'note' => $note]);
                return $this->postReversal(Caller::staff($requester), $requester, $orig, $doc->id, $t, $handler, 'approved', false, $reviewer);
            }
            $lines = $this->lines($doc->id);
            // A record already waiting keeps the reasons it was sent with (M6): a reason switched off (or no longer offered for
            // this kind) since then does not block the reviewer's OK; it stops new records only.
            $this->validateForPosting($doc, $lines, true);
            $handler->validate($db, $doc, $lines);
            $this->decideTask($taskId, 'approved', $me['id'], $note, $now);
            Audit::write($db, $reviewer, 'document.approve', 'document', (string) $doc->id, null,
                ['task_id' => $taskId, 'kind' => 'approval', 'units' => $task['units'], 'requested_by' => $requester, 'note' => $note]);
            return $this->postNow(Caller::staff($requester), $requester, $doc, $t, $handler, $lines, $reviewer);
        });
    }

    /**
     * Rejects an open task ($note 3-500 characters: the reason). A review: review_state rejected and the document's
     * reversal posted in the same transaction (reason review_rejected, created and posted by the reviewer, who needs no
     * doc.<TYPE>.post for this mechanical undo; the reversal is not reviewed again; a voluntary reversal of it that
     * still waits for approval is cancelled first, I32). The review of a REVERSAL: the rejection is recorded
     * (review_state rejected) and nothing is booked, because a reversal is never reversed (I31): if the original was
     * right, it is posted again as a new document. The review of a type whose `reject_action` is 'record' (PO, 0010:
     * the order may already be with the supplier) likewise only records the rejection: review_state rejected, nothing
     * reversed, the document stands until its poster cancels or amends it (I49). An approval: the request is cancelled
     * (cancel_reason = the note). Returns the document (reversed, rejected or cancelled).
     */
    public function reject(Caller $reviewer, int $taskId, string $note): Document
    {
        $note = trim($note);
        if (mb_strlen($note) < self::NOTE_MIN || mb_strlen($note) > self::NOTE_MAX) {
            throw new CwException('note_required', 'a rejection needs a note of ' . self::NOTE_MIN . ' to ' . self::NOTE_MAX . ' characters: it is the reason', 400,
                ['field' => 'note']);
        }
        return $this->db->transaction(function (Db $db) use ($reviewer, $taskId, $note): Document {
            $me = $this->staff($reviewer);
            [$row, $task] = $this->lockTask($taskId);
            $doc = Document::fromRow($row);
            $this->checkDecider($me, $doc, (string) $task['kind']);
            $now = $this->nowDb();
            if ($task['kind'] === 'review') {
                $this->checkReviewable($doc);
                $t = $this->typeRow($doc->docType);
                $record = ($t['reject_action'] ?? 'reverse') === 'record';
                if ($doc->isReversal() || $record) {
                    // I31: a reversal is never reversed. The rejection is recorded on the reversal and nothing is booked; the
                    // original stays reversed until someone posts it again as a new document. A type whose reject_action is
                    // 'record' (PO, I49) is likewise only marked rejected: its poster cancels or amends it.
                    $this->decideTask($taskId, 'rejected', $me['id'], $note, $now);
                    $db->exec("UPDATE document SET review_state = 'rejected', version = version + 1, updated_at = ? WHERE id = ?", [$now, $doc->id]);
                    Audit::write($db, $reviewer, 'document.reject', 'document', (string) $doc->id, null,
                        ['task_id' => $taskId, 'kind' => 'review', 'number' => $doc->number, 'note' => $note]
                        + ($doc->isReversal() ? ['reverses' => $doc->reversesId] : []) + ['booked' => false] + ($record ? ['recorded' => true] : []));
                    return $this->get($doc->id);
                }
                $handler = $this->handlerFor($t);
                $this->decideTask($taskId, 'rejected', $me['id'], $note, $now);
                $db->exec("UPDATE document SET review_state = 'rejected' WHERE id = ?", [$doc->id]);
                Audit::write($db, $reviewer, 'document.reject', 'document', (string) $doc->id, null,
                    ['task_id' => $taskId, 'kind' => 'review', 'number' => $doc->number, 'note' => $note]);
                $this->cancelPendingReversal($doc, $reviewer, $me['id'], $now, "superseded: {$doc->label()} was rejected at review and reversed");
                $revId = $this->insertReversal($doc, $me['id'], $reviewer->actor, 'review_rejected', 'Rejected at review: ' . $note, $now, false);
                $this->postReversal($reviewer, $me['id'], $doc, $revId, $t, $handler, 'not_required', false, null);
                return $this->get($doc->id);
            }
            if ($doc->status !== 'awaiting_approval') {
                throw new CwException('not_awaiting_approval', "{$doc->label()} is not waiting for an approval", 409, ['status' => $doc->status]);
            }
            $this->decideTask($taskId, 'rejected', $me['id'], $note, $now);
            $db->exec("UPDATE document SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, cancel_reason = ?, version = version + 1, "
                . 'updated_at = ? WHERE id = ?', [$me['id'], $now, $note, $now, $doc->id]);
            Audit::write($db, $reviewer, 'document.reject', 'document', (string) $doc->id, null,
                ['task_id' => $taskId, 'kind' => 'approval', 'units' => $task['units'], 'note' => $note]);
            $this->cancelled($doc->id, $reviewer); // Pack C: CancelHook
            return $this->get($doc->id);
        });
    }

    /**
     * The requester withdraws their open approval request: the task is withdrawn, the document is a draft again; a
     * reversal request is cancelled instead (a reversal is never a draft: its lines are the original's, I32).
     */
    public function withdraw(Caller $caller, int $taskId): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $taskId): Document {
            $me = $this->staff($caller);
            [$row, $task] = $this->lockTask($taskId);
            $doc = Document::fromRow($row);
            if ($task['kind'] !== 'approval' || $doc->status !== 'awaiting_approval') {
                throw new CwException('not_withdrawable', 'only an open approval request is withdrawn', 409, ['kind' => $task['kind'], 'status' => $doc->status]);
            }
            if ($doc->submittedBy !== $me['id']) {
                throw new CwException('not_requester', 'only the person who asked for this approval can withdraw it', 403);
            }
            $now = $this->nowDb();
            $this->decideTask($taskId, 'withdrawn', null, 'withdrawn by the requester', $now);
            if ($doc->isReversal()) {
                $db->exec("UPDATE document SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, cancel_reason = 'reversal request withdrawn by the requester', "
                    . 'version = version + 1, updated_at = ? WHERE id = ?', [$me['id'], $now, $now, $doc->id]);
                Audit::write($db, $caller, 'document.withdraw', 'document', (string) $doc->id, null, ['task_id' => $taskId, 'cancelled' => true, 'reverses' => $doc->reversesId]);
                return $this->get($doc->id);
            }
            $db->exec("UPDATE document SET status = 'draft', submitted_by = NULL, submitted_at = NULL, version = version + 1, updated_at = ? WHERE id = ?",
                [$now, $doc->id]);
            Audit::write($db, $caller, 'document.withdraw', 'document', (string) $doc->id, null, ['task_id' => $taskId]);
            return $this->get($doc->id);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    public function find(int $id): ?Document
    {
        $r = $this->db->one('SELECT * FROM document WHERE id = ?', [$id]);
        return $r === null ? null : Document::fromRow($r);
    }

    public function get(int $id): Document
    {
        return $this->find($id) ?? throw new CwException('unknown_document', 'there is no such document', 404);
    }

    /**
     * The lines of a document, line_no order, typed as fingerprint() hashes them.
     *
     * @return list<array{line_no: int, sku_id: ?int, warehouse_id: ?int, qty: ?int, unit_cost: ?string, amount: ?string, reason_code: ?string, description: ?string}>
     */
    public function lines(int $documentId): array
    {
        return array_map(self::lineRow(...), $this->db->all(
            'SELECT line_no, sku_id, warehouse_id, qty, unit_cost, amount, reason_code, description FROM document_line WHERE document_id = ? ORDER BY line_no',
            [$documentId],
        ));
    }

    /**
     * Each line's own id (document_line.uid; 0070, docs/decisions.md LV8), by line number: what a page marks a line with (who is here). null:
     * a line written before 0070, or a copied posted line (a reversal's).
     *
     * @return array<int, ?int> line_no => uid, line order
     */
    public function lineUids(int $documentId): array
    {
        $out = [];
        foreach ($this->db->all('SELECT line_no, uid FROM document_line WHERE document_id = ? ORDER BY line_no', [$documentId]) as $r) {
            $out[(int) $r['line_no']] = $r['uid'] === null ? null : (int) $r['uid'];
        }
        return $out;
    }

    /** The handler of a live type, or null (I-1: none). */
    public function handler(string $type): ?DocumentHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /** @return array<string, mixed> the document_type row (400 unknown_type) */
    public function typeInfo(string $code): array
    {
        return $this->typeRow($code);
    }

    /** Whether these roles may draft, post and reverse documents of $type (never admin; I12). @param list<string> $roles */
    public static function mayPost(array $roles, string $type): bool
    {
        return !in_array('admin', $roles, true) && isset(Permissions::MAP["doc.{$type}.post"]) && Permissions::can($roles, "doc.{$type}.post");
    }

    /**
     * Why this person may NOT decide a task of $kind ('review' | 'approval') on $doc, or null when they may: the screens
     * show the reason instead of the forms, and the service refuses with it (403).
     *
     * @param list<string> $roles
     * @return array{code: string, message: string}|null
     */
    public static function refusal(int $staffId, array $roles, Document $doc, string $kind): ?array
    {
        if (in_array('admin', $roles, true)) {
            return ['code' => 'admin_cannot_review', 'message' => 'admin manages people and roles and never reviews or approves documents (I12).'];
        }
        $perm = $kind === 'approval' ? 'documents.approve' : 'documents.review';
        if (!Permissions::can($roles, $perm)) {
            return ['code' => 'role_not_allowed', 'message' => ucfirst(self::rolesPhrase($roles))
                . ($kind === 'approval' ? ' cannot give approvals.' : ' cannot review documents.')];
        }
        $what = $kind === 'approval' ? 'decide this request' : 'review it';
        return match ($staffId) {
            $doc->postedBy => ['code' => 'own_document', 'message' => "You posted this document: another reviewer must {$what}."],
            $doc->submittedBy => ['code' => 'own_document', 'message' => "You asked for this approval: another reviewer must {$what}."],
            $doc->createdBy => ['code' => 'own_document', 'message' => "You created this document: another reviewer must {$what}."],
            default => null,
        };
    }

    /**
     * refusal() plus the people a type's handler names as having written part of the document (ReviewInvolvement: the
     * goods-in bench check of a receipt, I133): what the screens show instead of the decide forms, and what approve() and
     * reject() refuse with (403).
     *
     * @param list<string> $roles
     * @return array{code: string, message: string}|null
     */
    public function refusalFor(int $staffId, array $roles, Document $doc, string $kind): ?array
    {
        $no = self::refusal($staffId, $roles, $doc, $kind);
        if ($no !== null) {
            return $no;
        }
        $h = $this->handlers[$doc->docType] ?? null;
        if ($h instanceof ReviewInvolvement) {
            $why = $h->involved($this->db, $doc)[$staffId] ?? null;
            if ($why !== null) {
                return ['code' => 'own_document', 'message' => $why];
            }
        }
        return null;
    }

    /**
     * Open tasks this person may decide (the menu badge `reviews_open`): of the kinds their roles decide, not opened by
     * them, not on a document they created, submitted or posted, nor on one a type's handler says they wrote part of
     * (ReviewInvolvement, I133).
     *
     * @param list<string> $roles
     */
    public function decidableCount(int $staffId, array $roles): int
    {
        return array_sum($this->decidableCounts($staffId, $roles));
    }

    /**
     * decidableCount() by kind, in one query (the Home page's cards and the badge come from one call per request).
     *
     * @param list<string> $roles
     * @return array{review: int, approval: int}
     */
    public function decidableCounts(int $staffId, array $roles): array
    {
        $out = ['review' => 0, 'approval' => 0];
        foreach ($this->decidableCountsByType($staffId, $roles) as $byKind) {
            $out['review'] += $byKind['review'];
            $out['approval'] += $byKind['approval'];
        }
        return $out;
    }

    /**
     * decidableCounts() by document type, in the same one query (Home's "Deliveries booked in to check" card is the GRN part of the
     * reviews, U87): document type => [review, approval]; a type with nothing decidable is absent.
     *
     * @param list<string> $roles
     * @return array<string, array{review: int, approval: int}>
     */
    public function decidableCountsByType(int $staffId, array $roles): array
    {
        if (in_array('admin', $roles, true)) {
            return [];
        }
        $kinds = [];
        if (Permissions::can($roles, 'documents.review')) {
            $kinds[] = 'review';
        }
        if (Permissions::can($roles, 'documents.approve')) {
            $kinds[] = 'approval';
        }
        if ($kinds === []) {
            return [];
        }
        // A type whose handler names other people who wrote part of the document (ReviewInvolvement: a receipt's goods-in bench
        // check, I133): its tasks are not offered to them either, so the badge and Home's cards never count what the person
        // would be refused (refusalFor).
        $involved = '';
        $params = [...$kinds, $staffId, $staffId, $staffId, $staffId];
        foreach ($this->handlers as $type => $h) {
            if ($h instanceof ReviewInvolvement) {
                $involved .= ' AND NOT (d.doc_type = ? AND ' . $h->involvedSql() . ')';
                array_push($params, $type, $staffId);
            }
        }
        $out = [];
        foreach ($this->db->all(
            "SELECT d.doc_type, t.kind, COUNT(*) AS n FROM review_task t JOIN document d ON d.id = t.subject_id WHERE t.subject_type = 'document' AND t.state = 'open' "
            . 'AND t.kind IN (' . implode(', ', array_fill(0, count($kinds), '?')) . ') AND NOT (t.opened_by <=> ?) '
            . 'AND NOT (d.created_by <=> ?) AND NOT (d.submitted_by <=> ?) AND NOT (d.posted_by <=> ?)' . $involved . ' GROUP BY d.doc_type, t.kind',
            $params,
        ) as $r) {
            $out[(string) $r['doc_type']] ??= ['review' => 0, 'approval' => 0];
            $out[(string) $r['doc_type']][(string) $r['kind']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * sha256 of the canonical JSON of the header fields and the lines of a document, decimals as the database returns
     * them (posted_hash, I17). $number: the number the posting is about to set.
     *
     * @param list<array<string, mixed>> $lines lines() rows
     */
    public static function fingerprint(Document $doc, array $lines, ?string $number = null): string
    {
        return hash('sha256', self::canonical($doc, $lines, $number));
    }

    /**
     * The canonical JSON fingerprint() hashes: what document_posting.content keeps of a posting (I33), so the posted
     * content can be read back even if the document's rows were changed afterwards.
     *
     * @param list<array<string, mixed>> $lines lines() rows
     */
    public static function canonical(Document $doc, array $lines, ?string $number = null): string
    {
        return Idempotency::canonicalJson(['document' => $doc->fingerprintHeader($number), 'lines' => array_map(self::lineRow(...), $lines)]);
    }

    // ------------------------------------------------------------------------------------------
    // The steps
    // ------------------------------------------------------------------------------------------

    /**
     * Steps 4-8 of a posting (class docblock). $approver: the reviewer whose approval this posting carries: the posting
     * is the requester's ($poster: posted_by and the ledger's actor), review_state approved, no review task, and the
     * document.post audit row names the reviewer who performed it, with on_behalf_of the requester (I34).
     *
     * @param array<string, mixed> $t document_type row
     * @param list<array<string, mixed>> $lines
     */
    private function postNow(Caller $poster, ?int $posterId, Document $doc, array $t, DocumentHandler $handler, array $lines, ?Caller $approver,
        bool $reviewed = true): Document
    {
        $number = $this->series->next((string) $t['prefix']);
        $now = $this->nowDb();
        $hash = $this->markPosted($doc, $lines, $number, $posterId, $poster->actor, $now, $approver === null ? 'not_required' : 'approved');
        $opKey = "doc:{$doc->id}:post";
        Audit::write($this->db, $approver ?? $poster, 'document.post', 'document', (string) $doc->id, $opKey,
            ['type' => $doc->docType, 'number' => $number, 'lines' => count($lines), 'posted_hash' => $hash,
                'rule_version' => isset($t['rule_version']) ? (int) $t['rule_version'] : null]
            + ($approver === null ? [] : ['on_behalf_of' => $posterId, 'approved_by' => $approver->staffUserId]));
        $posted = $this->get($doc->id);
        $units = $handler->post($this->db, $posted, $lines, $poster, $opKey);
        if ($approver === null && $reviewed && $posterId !== null) {
            $this->openReviewIfNeeded($posted, $t, $units, $posterId, $poster->actor, $now);
        }
        return $this->get($doc->id);
    }

    /**
     * The posting UPDATE of a document (number, status posted, poster, posted_hash, review state) and its write-once
     * record in document_posting (the hash and the canonical content it covers, I33). Returns posted_hash.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function markPosted(Document $doc, array $lines, string $number, ?int $posterId, string $actor, string $now, string $reviewState): string
    {
        $content = self::canonical($doc, $lines, $number);
        $hash = hash('sha256', $content);
        $this->db->exec(
            "UPDATE document SET number = ?, status = 'posted', posted_by = ?, posted_actor = ?, posted_at = ?, posted_hash = ?, review_state = ?, "
            . 'version = version + 1, updated_at = ? WHERE id = ?',
            [$number, $posterId, $actor, $now, $hash, $reviewState, $now, $doc->id],
        );
        $this->db->exec('INSERT INTO document_posting (document_id, number, posted_hash, posted_by, posted_actor, posted_at, content) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$doc->id, $number, $hash, $posterId, $actor, $now, $content]);
        return $hash;
    }

    /**
     * Step 3: the blocking approval request. $rule: the task's reason (the type's approval rule, or `over_size` for the OK first for a
     * big record); $limits: what it was compared with (audited).
     *
     * @param array<string, mixed> $t
     * @param array<string, mixed> $limits
     */
    private function submit(Caller $caller, int $staffId, Document $doc, array $t, int $units, string $rule, array $limits): Document
    {
        $now = $this->nowDb();
        $this->db->exec("UPDATE document SET status = 'awaiting_approval', submitted_by = ?, submitted_at = ?, version = version + 1, updated_at = ? "
            . 'WHERE id = ?', [$staffId, $now, $now, $doc->id]);
        $this->db->insert(
            'INSERT INTO review_task (subject_type, subject_id, kind, reason, units, opened_by, opened_actor, opened_at, due_at) '
            . "VALUES ('document', ?, 'approval', ?, ?, ?, ?, ?, ?)",
            [$doc->id, $rule, $units, $staffId, $caller->actor, $now, self::dueAt($now, (int) $t['review_due_days'])],
        );
        Audit::write($this->db, $caller, 'document.submit', 'document', (string) $doc->id, null,
            ['type' => $doc->docType, 'rule' => $rule, 'units' => $units] + $limits
                + ['rule_version' => isset($t['rule_version']) ? (int) $t['rule_version'] : null]);
        return $this->get($doc->id);
    }

    /**
     * Step 8: a review task when the type's rule asks for one (all, or over_limit with more units than the limit).
     *
     * @param array<string, mixed> $t
     */
    private function openReviewIfNeeded(Document $doc, array $t, int $units, int $openedBy, string $actor, string $now): void
    {
        $reason = match ($t['review_rule']) {
            'all' => 'all_documents',
            'over_limit' => $units > (int) $t['review_limit_units'] ? 'over_limit' : null,
            default => null,
        };
        if ($reason === null) {
            return;
        }
        // The document row is X-locked by this transaction already and review_task has no foreign keys: nothing new is
        // locked after the feed clock (I21).
        $this->db->exec("UPDATE document SET review_state = 'pending' WHERE id = ?", [$doc->id]);
        $this->db->insert(
            'INSERT INTO review_task (subject_type, subject_id, kind, reason, units, opened_by, opened_actor, opened_at, due_at) '
            . "VALUES ('document', ?, 'review', ?, ?, ?, ?, ?, ?)",
            [$doc->id, $reason, $units, $openedBy, $actor, $now, self::dueAt($now, (int) $t['review_due_days'])],
        );
    }

    /**
     * A new reversal document of $orig (I18): same type, `reverses_id` = the original, the original's external_ref and
     * warehouse, today's date, the reason, and the original's lines copied with qty and amount negated. A draft about to
     * be posted in this transaction, or ($awaiting) a request waiting for its blocking approval (I32). Returns its id.
     */
    private function insertReversal(Document $orig, ?int $staffId, string $actor, string $reasonCode, ?string $note, string $now, bool $awaiting): int
    {
        $revId = $this->db->insert(
            'INSERT INTO document (doc_type, status, version, external_ref, doc_date, warehouse_id, reason_code, note, created_by, created_actor, '
            . 'created_at, updated_at, reverses_id, submitted_by, submitted_at) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$orig->docType, $awaiting ? 'awaiting_approval' : 'draft', $orig->externalRef, ($this->clock)()->setTimezone(Clock::utc())->format('Y-m-d'),
                $orig->warehouseId, $reasonCode, $note === null ? null : mb_substr($note, 0, 1000), $staffId, $actor, $now, $now, $orig->id,
                $awaiting ? $staffId : null, $awaiting ? $now : null],
        );
        $this->db->exec(
            'INSERT INTO document_line (document_id, line_no, sku_id, warehouse_id, location_id, qty, unit_cost, amount, reason_code, description) '
            . 'SELECT ?, line_no, sku_id, warehouse_id, location_id, -qty, unit_cost, -amount, reason_code, description FROM document_line WHERE document_id = ? ORDER BY line_no',
            [$revId, $orig->id],
        );
        return $revId;
    }

    /**
     * Posts the reversal $revId of $orig (I18): a number from the same series, posted (its own posted_hash and posting
     * record), the original `reversed` and its open review task withdrawn, audit document.reverse, the handler's module
     * rows, then the exact stock negation (Movements::reverseDocument). $poster is the reversal's poster (posted_by and
     * the ledger's actor); $approver, when an approval posts it, is the reviewer the audit row names (I34). $reviewed:
     * a voluntary reversal is reviewed under the type's rule (units = what it moved on hand); a rejection's reversal and
     * an approved one are not.
     *
     * @param array<string, mixed> $t
     */
    private function postReversal(Caller $poster, ?int $posterId, Document $orig, int $revId, array $t, DocumentHandler $handler, string $reviewState,
        bool $reviewed, ?Caller $approver): Document
    {
        if ($orig->isReversal() || $orig->status !== 'posted') {
            throw new \LogicException("{$orig->label()} cannot be reversed: a reversal is never reversed, and only a posted document is (I18, I31)");
        }
        $now = $this->nowDb();
        $number = $this->series->next((string) $t['prefix']);
        $draft = $this->get($revId);
        $lines = $this->lines($revId);
        $hash = $this->markPosted($draft, $lines, $number, $posterId, $poster->actor, $now, $reviewState);
        $this->db->exec("UPDATE document SET status = 'reversed', version = version + 1, updated_at = ? WHERE id = ?", [$now, $orig->id]);
        $this->db->exec(
            "UPDATE review_task SET state = 'withdrawn', decided_at = ?, decision_note = ? WHERE subject_type = 'document' AND subject_id = ? AND state = 'open'",
            [$now, "reversed by {$number}", $orig->id],
        );
        $opKey = "doc:{$revId}:reverse";
        Audit::write($this->db, $approver ?? $poster, 'document.reverse', 'document', (string) $revId, $opKey,
            ['type' => $orig->docType, 'number' => $number, 'reverses' => $orig->id, 'reverses_number' => $orig->number, 'reason' => $draft->reasonCode,
                'note' => $draft->note, 'posted_hash' => $hash, 'rule_version' => isset($t['rule_version']) ? (int) $t['rule_version'] : null]
                + ($approver === null ? [] : ['on_behalf_of' => $posterId, 'approved_by' => $approver->staffUserId]));
        $reversal = $this->get($revId);
        $handler->reverse($this->db, $orig, $reversal, $lines, $poster, $opKey);
        $this->moves->reverseDocument($poster, $orig->id, ['document_id' => $revId, 'doc_ref' => $number], $opKey);
        if ($reviewed && $posterId !== null) {
            $units = (int) $this->db->value("SELECT COALESCE(SUM(ABS(qty_delta)), 0) FROM stock_ledger WHERE document_id = ? AND bucket = 'on_hand'", [$revId]);
            $this->openReviewIfNeeded($reversal, $t, $units, $posterId, $poster->actor, $now);
        }
        return $this->get($revId);
    }

    /**
     * I32: a voluntary reversal that puts more units back on hand than the "positive without a supplier document" limit
     * is a request: the reversal document waits in awaiting_approval (no number, nothing booked) with an open approval
     * task; approve() posts it as the requester's reversal, reject() cancels it, the requester may withdraw it.
     *
     * @param array<string, mixed> $t
     */
    private function submitReversal(Caller $caller, int $staffId, Document $orig, array $t, string $reasonCode, ?string $note, int $units, int $limit,
        string $now): Document
    {
        $revId = $this->insertReversal($orig, $staffId, $caller->actor, $reasonCode, $note, $now, true);
        $this->db->insert(
            'INSERT INTO review_task (subject_type, subject_id, kind, reason, units, opened_by, opened_actor, opened_at, due_at) '
            . "VALUES ('document', ?, 'approval', 'positive_without_supplier_doc', ?, ?, ?, ?, ?)",
            [$revId, $units, $staffId, $caller->actor, $now, self::dueAt($now, (int) $t['review_due_days'])],
        );
        Audit::write($this->db, $caller, 'document.submit', 'document', (string) $revId, null,
            ['type' => $orig->docType, 'rule' => 'positive_without_supplier_doc', 'units' => $units, 'limit' => $limit, 'reverses' => $orig->id,
                'reverses_number' => $orig->number, 'reason' => $reasonCode, 'note' => $note, 'rule_version' => isset($t['rule_version']) ? (int) $t['rule_version'] : null]);
        return $this->get($revId);
    }

    /**
     * Cancels a reversal request of $orig that still waits for approval (its task withdrawn): the rejection of the
     * original's review reverses it instead (I32). The original is X-locked; the request's row is locked after it (id
     * order, I21).
     */
    private function cancelPendingReversal(Document $orig, Caller $caller, int $staffId, string $now, string $why): void
    {
        $r = $this->db->one("SELECT id FROM document WHERE reverses_id = ? AND status = 'awaiting_approval' FOR UPDATE", [$orig->id]);
        if ($r === null) {
            return;
        }
        $rid = (int) $r['id'];
        $why = mb_substr($why, 0, self::NOTE_MAX);
        $this->db->exec("UPDATE review_task SET state = 'withdrawn', decided_at = ?, decision_note = ? WHERE subject_type = 'document' AND subject_id = ? AND state = 'open'",
            [$now, $why, $rid]);
        $this->db->exec("UPDATE document SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, cancel_reason = ?, version = version + 1, updated_at = ? "
            . 'WHERE id = ?', [$staffId, $now, $why, $now, $rid]);
        Audit::write($this->db, $caller, 'document.cancel', 'document', (string) $rid, null, ['reason' => $why, 'reverses' => $orig->id]);
    }

    /**
     * The units a reversal of $documentId would put back on hand, per item net (I32): Σ over items of max(0, −Σ
     * qty_delta of the original's on_hand rows). A transfer nets to 0 per item; a write-down's reversal counts in full.
     */
    private function unitsBackOnHand(int $documentId): int
    {
        return (int) $this->db->value(
            'SELECT COALESCE(SUM(GREATEST(n, 0)), 0) FROM (SELECT -SUM(qty_delta) AS n FROM stock_ledger '
            . "WHERE document_id = ? AND bucket = 'on_hand' GROUP BY sku_id) x",
            [$documentId],
        );
    }

    /**
     * The limit of the owner's blocking approval "positive adjustment without a supplier document" (document_type rows
     * with approval_rule positive_without_supplier_doc: ADJ, 10 units until decision 11), or null when no type has it.
     */
    private function positiveLimit(): ?int
    {
        $v = $this->db->value("SELECT MIN(approval_limit_units) FROM document_type WHERE approval_rule = 'positive_without_supplier_doc'");
        return $v === null ? null : (int) $v;
    }

    /** Settles an open task (it is X-locked by lockTask()). */
    private function decideTask(int $taskId, string $state, ?int $decidedBy, ?string $note, string $now): void
    {
        if ($this->db->exec("UPDATE review_task SET state = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ? AND state = 'open'",
            [$state, $decidedBy, $now, $note, $taskId]) !== 1) {
            throw new CwException('task_closed', 'this review task is no longer open', 409);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Locks and checks
    // ------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the document row, X-locked (404 unknown_document) */
    private function lock(int $id): array
    {
        return $this->db->one('SELECT * FROM document WHERE id = ? FOR UPDATE', [$id])
            ?? throw new CwException('unknown_document', 'there is no such document', 404);
    }

    /** @return array<string, mixed> a draft's row, X-locked, at the version the form was drawn with */
    private function lockDraft(int $id, int $expectedVersion): array
    {
        $row = $this->lock($id);
        if ($row['status'] !== 'draft') {
            throw new CwException('not_draft', 'document #' . $id . ' is ' . str_replace('_', ' ', (string) $row['status'])
                . ': only a draft is changed or posted', 409, ['status' => $row['status']]);
        }
        if ((int) $row['version'] !== $expectedVersion) {
            throw DraftChanges::conflict($this->db, $id, 'document', (int) $row['version'], $expectedVersion);
        }
        return $row;
    }

    /**
     * A draft's row, X-locked, for a change of one line or one box (LS3): 409 not_draft (naming what it became, its number and who made it so)
     * when it is no longer a draft; no version check (the line's own version is the module's).
     *
     * @return array<string, mixed>
     */
    private function lockLiveDraft(int $id): array
    {
        $row = $this->lock($id);
        if ($row['status'] !== 'draft') {
            throw self::notDraft($this->db, $row);
        }
        return $row;
    }

    /**
     * 409 not_draft for a change of a document that is no longer a draft (docs/decisions.md LS9): its status, its number, who made it so
     * (posted, sent for its OK, cancelled) by name, for the page that was typing in it ("Fazil confirmed this order. It is PO-000123 now.").
     *
     * @param array<string, mixed> $row the document row
     */
    public static function notDraft(Db $db, array $row): CwException
    {
        $status = (string) $row['status'];
        $by = match ($status) {
            'cancelled' => $row['cancelled_by'] ?? null,
            'awaiting_approval' => $row['submitted_by'] ?? null,
            default => $row['posted_by'] ?? null,
        };
        $name = $by === null ? null : $db->value('SELECT display_name FROM staff_user WHERE id = ?', [(int) $by]);
        return new CwException('not_draft', 'document #' . $row['id'] . ' is ' . str_replace('_', ' ', $status) . ': only a draft is changed', 409,
            ['status' => $status, 'number' => $row['number'] === null ? null : (string) $row['number'], 'by' => $name === null ? null : (string) $name]);
    }

    /**
     * Locks a task's document (a reversal's original before it), then the task (the order every document write uses:
     * the document rows first, by id), and checks the task is still open.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [document row, task row]
     */
    private function lockTask(int $taskId): array
    {
        $t = $this->db->one('SELECT subject_type, subject_id FROM review_task WHERE id = ?', [$taskId])
            ?? throw new CwException('unknown_task', 'there is no such review task', 404);
        if ($t['subject_type'] === 'company') {
            // Reviews of a change of the company details are CW\Company\CompanyDetails' (I94).
            throw new CwException('company_task', 'reviews of the company details are decided on the Company details page', 409);
        }
        if ($t['subject_type'] !== 'document') {
            // Supplier tasks (activation, import route, change review) are CW\Suppliers\Suppliers' (I-2, I40).
            throw new CwException('supplier_task', "supplier approvals are decided on the supplier's page", 409);
        }
        // A reversal's original first (document rows in id order, I21): approving a reversal request writes it too.
        // reverses_id never changes (column grant), so this unlocked read is stable.
        $orig = $this->db->value('SELECT reverses_id FROM document WHERE id = ?', [(int) $t['subject_id']]);
        if ($orig !== null) {
            $this->lock((int) $orig);
        }
        $row = $this->lock((int) $t['subject_id']);
        $task = $this->db->one('SELECT * FROM review_task WHERE id = ? FOR UPDATE', [$taskId]);
        if ($task === null || $task['state'] !== 'open') {
            throw new CwException('task_closed', 'this review task is no longer open (' . ($task['state'] ?? 'gone') . ')', 409, ['state' => $task['state'] ?? null]);
        }
        return [$row, $task];
    }

    /** A review is decided while its document is posted and waiting for it (409 not_reviewable otherwise). */
    private function checkReviewable(Document $doc): void
    {
        if ($doc->status !== 'posted' || $doc->reviewState !== 'pending') {
            throw new CwException('not_reviewable', "{$doc->label()} is " . str_replace('_', ' ', $doc->status)
                . ($doc->status === 'posted' ? ' and its review is ' . str_replace('_', ' ', (string) $doc->reviewState) : '') . ': nothing to review', 409);
        }
    }

    /** @return array{id: int, roles: list<string>} */
    private function staff(Caller $caller): array
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'documents are drafted, posted and reviewed by staff', 403);
        }
        return ['id' => $caller->staffUserId, 'roles' => StaffRoles::active($this->db, $caller->staffUserId)];
    }

    /** @param array{id: int, roles: list<string>} $me */
    private function checkPoster(array $me, string $type): void
    {
        if (in_array('admin', $me['roles'], true)) {
            throw new CwException('admin_cannot_post', 'admin manages people and roles and never drafts, posts or reverses documents (I12)', 403);
        }
        if (!Permissions::can($me['roles'], "doc.{$type}.post")) {
            throw new CwException('role_not_allowed', self::rolesPhrase($me['roles']) . " cannot post {$type} documents", 403, ['type' => $type]);
        }
    }

    /** @param array{id: int, roles: list<string>} $me */
    private function checkDecider(array $me, Document $doc, string $kind): void
    {
        $no = $this->refusalFor($me['id'], $me['roles'], $doc, $kind);
        if ($no !== null) {
            throw new CwException($no['code'], $no['message'], 403);
        }
    }

    /** An approval posts as the requester: they must still be active and still allowed to post the type. */
    private function checkRequesterMayPost(int $requester, string $type): void
    {
        try {
            $roles = StaffRoles::active($this->db, $requester);
        } catch (CwException) {
            $roles = null;
        }
        if ($roles === null || !self::mayPost($roles, $type)) {
            throw new CwException('requester_cannot_post', "the person who asked for this approval can no longer post {$type} documents: reject the request instead", 409);
        }
    }

    /** @return array<string, mixed> */
    private function typeRow(string $code): array
    {
        // Read on every use, never kept: the rules are changed on the Approval rules page (0019, Y10) and a long-lived service
        // must apply the rules in force now.
        // With the version of the rule in force (one statement: the row and its history agree), for the posting's audit row (M10).
        $r = $this->db->one("SELECT t.*, (SELECT MAX(c.version) FROM config_change c WHERE c.subject_type = 'document_rule' AND c.subject_key = t.code) AS rule_version "
            . 'FROM document_type t WHERE t.code = ?', [$code]);
        if ($r === null) {
            throw new CwException('unknown_type', "there is no document type {$code}", 400, ['type' => mb_substr($code, 0, 16)]);
        }
        return $r;
    }

    /**
     * --- Pack C (review finding 5): a handler with module rows tied to a live record hears that its draft or approval request was
     * cancelled, in the same transaction (CancelHook; a supplier invoice frees the supplier's number).
     */
    private function cancelled(int $id, Caller $caller): void
    {
        $doc = $this->get($id);
        $handler = $this->handlers[$doc->docType] ?? null;
        if ($handler instanceof CancelHook) {
            $handler->cancelled($this->db, $doc, $caller);
        }
    }

    /** @param array<string, mixed> $t */
    private function handlerFor(array $t): DocumentHandler
    {
        return $this->handlers[$t['code']] ?? throw new CwException('type_not_built', "{$t['name']} documents arrive in Phase {$t['phase']}", 409,
            ['type' => $t['code'], 'phase' => $t['phase']]);
    }

    /**
     * The checks every posting makes whatever its type, again at posting time (a draft may be days old): lines exist,
     * reason codes are still active and applicable, notes are there where a reason needs one, items are not merged.
     * $waiting: a reviewer's OK of a record that waited for it (approve()): its reasons were checked when it was sent, and a
     * reason switched off or no longer offered for this kind since then does not block the OK (review finding M6, Y52); the
     * reason must still exist and still needs its note.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function validateForPosting(Document $doc, array $lines, bool $waiting = false): void
    {
        if ($lines === []) {
            throw new CwException('no_lines', "{$doc->label()} has no lines", 422);
        }
        $use = self::REASON_USE[$doc->docType] ?? null;
        if ($doc->reasonCode !== null) {
            $r = $this->reason($doc->reasonCode, $use, 'reason_code', true, $waiting);
            if ((int) $r['needs_note'] === 1 && $doc->note === null) {
                throw new CwException('note_required', "the reason {$doc->reasonCode} needs a note", 422, ['field' => 'note']);
            }
        }
        $skus = [];
        foreach ($lines as $l) {
            if ($l['reason_code'] !== null) {
                $r = $this->reason($l['reason_code'], $use, "lines[{$l['line_no']}].reason_code", true, $waiting);
                if ((int) $r['needs_note'] === 1 && $l['description'] === null && $doc->note === null) {
                    throw new CwException('note_required', "line {$l['line_no']}: the reason {$l['reason_code']} needs a description or a document note", 422,
                        ['line' => $l['line_no']]);
                }
            }
            if ($l['sku_id'] !== null) {
                $skus[$l['sku_id']][] = $l['line_no'];
            }
        }
        foreach (array_chunk(array_keys($skus), 1000) as $chunk) {
            $found = [];
            foreach ($this->db->all('SELECT id, merged_into_sku_id FROM sku WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $s) {
                $found[(int) $s['id']] = $s['merged_into_sku_id'];
            }
            foreach ($chunk as $sku) {
                if (!array_key_exists($sku, $found)) {
                    throw new CwException('unknown_sku', "line {$skus[$sku][0]}: item {$sku} does not exist", 422, ['line' => $skus[$sku][0]]);
                }
                if ($found[$sku] !== null) {
                    throw new CwException('merged_item', "line {$skus[$sku][0]}: item {$sku} was merged into item {$found[$sku]}: use that one", 422,
                        ['line' => $skus[$sku][0], 'merged_into' => (int) $found[$sku]]);
                }
            }
        }
    }

    /**
     * A reason code as a document may use it: known (422 unknown_reason), applying to $use (422 reason_not_applicable;
     * null = the type carries no reasons), active (422 reason_inactive), and when chosen by staff not CW's own (422
     * reason_system_only). $waiting (a record already waiting for its OK, M6): only known and not CW's own.
     *
     * @return array<string, mixed>
     */
    private function reason(string $code, ?string $use, string $field, bool $staffChoice, bool $waiting = false): array
    {
        $r = $this->db->one('SELECT code, applies_to, needs_note, system_only, is_active FROM reason_code WHERE code = ?', [$code]);
        if ($r === null) {
            throw new CwException('unknown_reason', "{$field}: there is no reason code " . mb_substr($code, 0, 40), 422, ['field' => $field]);
        }
        if ($waiting) {
            if ($staffChoice && (int) $r['system_only'] === 1) {
                throw new CwException('reason_system_only', "{$field}: {$code} is set by CW itself, never chosen on a form", 422, ['field' => $field]);
            }
            return $r;
        }
        if ($use === null || !in_array($use, explode(',', (string) $r['applies_to']), true)) {
            throw new CwException('reason_not_applicable', "{$field}: {$code} is not a reason for " . ($use === null ? 'this document type' : str_replace('_', ' ', $use) . 's'), 422,
                ['field' => $field]);
        }
        if ((int) $r['is_active'] !== 1) {
            throw new CwException('reason_inactive', "{$field}: {$code} is no longer used", 422, ['field' => $field]);
        }
        if ($staffChoice && (int) $r['system_only'] === 1) {
            throw new CwException('reason_system_only', "{$field}: {$code} is set by CW itself, never chosen on a form", 422, ['field' => $field]);
        }
        return $r;
    }

    /**
     * Checks a draft header and returns its column values. $current: the row being changed (absent keys keep its
     * values), or null for a new draft.
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $current
     * @return array{external_ref: ?string, doc_date: ?string, warehouse_id: ?int, reason_code: ?string, note: ?string}
     */
    private function header(string $type, array $in, ?array $current): array
    {
        foreach (array_keys($in) as $k) {
            if (!in_array($k, self::HEADER_FIELDS, true)) {
                throw new CwException('bad_field', 'a document header has ' . implode(', ', self::HEADER_FIELDS) . ', not ' . mb_substr((string) $k, 0, 40), 400,
                    ['field' => mb_substr((string) $k, 0, 40)]);
            }
        }
        $out = [
            'external_ref' => $current === null ? null : $current['external_ref'],
            'doc_date' => $current === null ? null : $current['doc_date'],
            'warehouse_id' => $current === null || $current['warehouse_id'] === null ? null : (int) $current['warehouse_id'],
            'reason_code' => $current === null ? null : $current['reason_code'],
            'note' => $current === null ? null : $current['note'],
        ];
        if (array_key_exists('external_ref', $in)) {
            $out['external_ref'] = self::optText($in['external_ref'], 'external_ref', 191);
        }
        if (array_key_exists('doc_date', $in)) {
            $d = $in['doc_date'];
            if ($d !== null && (!is_string($d) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d) !== 1
                || \DateTimeImmutable::createFromFormat('!Y-m-d', $d, Clock::utc())?->format('Y-m-d') !== $d)) {
                throw new CwException('bad_field', 'doc_date must be a date (YYYY-MM-DD)', 400, ['field' => 'doc_date']);
            }
            $out['doc_date'] = $d;
        }
        if (array_key_exists('warehouse', $in)) {
            $code = self::optText($in['warehouse'], 'warehouse', 32);
            $out['warehouse_id'] = $code === null ? null : $this->warehouseId($code, 'warehouse');
        }
        if (array_key_exists('reason_code', $in)) {
            $code = self::optText($in['reason_code'], 'reason_code', 32);
            if ($code !== null) {
                $this->reason($code, self::REASON_USE[$type] ?? null, 'reason_code', true);
            }
            $out['reason_code'] = $code;
        }
        if (array_key_exists('note', $in)) {
            $out['note'] = self::optText($in['note'], 'note', 1000);
        }
        return $out;
    }

    /**
     * @param mixed $lines
     * @return list<array{line_no: int, sku_id: ?int, warehouse_id: ?int, qty: ?int, unit_cost: ?string, amount: ?string, reason_code: ?string, description: ?string}>
     */
    private function normaliseLines(string $type, mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new CwException('bad_lines', 'lines must be a list of at most ' . self::MAX_LINES . ' lines', 400);
        }
        $out = [];
        $ids = [];
        $codes = [];
        foreach ($lines as $i => $line) {
            $no = $i + 1;
            if (!is_array($line)) {
                throw new CwException('bad_lines', "line {$no} must be an object", 400, ['line' => $no]);
            }
            foreach (array_keys($line) as $k) {
                if (!in_array($k, self::LINE_FIELDS, true)) {
                    throw new CwException('bad_lines', "line {$no}: a line has " . implode(', ', self::LINE_FIELDS) . ', not ' . mb_substr((string) $k, 0, 40), 400, ['line' => $no]);
                }
            }
            $skuId = $line['sku_id'] ?? null;
            $skuCode = $line['sku_code'] ?? null;
            if ($skuId !== null && $skuCode !== null) {
                throw new CwException('bad_lines', "line {$no} names its item by sku_id or sku_code, not both", 400, ['line' => $no]);
            }
            if ($skuId !== null && (!is_int($skuId) || $skuId <= 0)) {
                throw new CwException('bad_lines', "line {$no}: sku_id must be a positive integer", 400, ['line' => $no]);
            }
            if ($skuCode !== null && (!is_string($skuCode) || trim($skuCode) === '' || strlen($skuCode) > 16)) {
                throw new CwException('bad_lines', "line {$no}: sku_code must be a CW code", 400, ['line' => $no]);
            }
            $qty = $line['qty'] ?? null;
            if ($qty !== null && (!is_int($qty) || abs($qty) > self::MAX_QTY)) {
                throw new CwException('bad_lines', "line {$no}: qty must be a whole number of central units (at most " . self::MAX_QTY . ')', 400, ['line' => $no]);
            }
            $hasItem = $skuId !== null || $skuCode !== null;
            if ($hasItem && $qty === null) {
                throw new CwException('bad_lines', "line {$no}: a line with an item needs qty", 400, ['line' => $no]);
            }
            $wh = self::optText($line['warehouse'] ?? null, "lines[{$no}].warehouse", 32);
            $reason = self::optText($line['reason_code'] ?? null, "lines[{$no}].reason_code", 32);
            if ($reason !== null) {
                $this->reason($reason, self::REASON_USE[$type] ?? null, "lines[{$no}].reason_code", true);
            }
            $n = [
                'line_no' => $no,
                'sku_id' => $skuId,
                'warehouse_id' => $wh === null ? null : $this->warehouseId($wh, "lines[{$no}].warehouse"),
                'qty' => $qty,
                'unit_cost' => isset($line['unit_cost']) ? Movements::normaliseCost($line['unit_cost'], "lines[{$no}].unit_cost") : null,
                'amount' => isset($line['amount']) ? self::normaliseAmount($line['amount'], "lines[{$no}].amount") : null,
                'reason_code' => $reason,
                'description' => self::optText($line['description'] ?? null, "lines[{$no}].description", 255),
            ];
            if (!$hasItem && $n['amount'] === null && $n['description'] === null) {
                throw new CwException('bad_lines', "line {$no} is empty: it needs an item, an amount or a description", 400, ['line' => $no]);
            }
            if ($skuCode !== null) {
                $codes[trim($skuCode)][] = $i;
            } elseif ($skuId !== null) {
                $ids[$skuId][] = $i;
            }
            $out[] = $n;
        }
        // Items: known and not merged (one query per 1,000).
        foreach (['id' => $ids, 'code' => $codes] as $col => $want) {
            foreach (array_chunk(array_keys($want), 1000) as $chunk) {
                $found = [];
                foreach ($this->db->all("SELECT id, code, merged_into_sku_id FROM sku WHERE `{$col}` IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                    array_map(static fn (int|string $k): int|string => $col === 'id' ? (int) $k : (string) $k, $chunk)) as $s) {
                    $found[$col === 'id' ? (int) $s['id'] : (string) $s['code']] = $s;
                }
                foreach ($chunk as $key) {
                    $first = $want[$key][0] + 1;
                    $s = $found[$key] ?? throw new CwException('unknown_sku', "line {$first}: there is no item " . mb_substr((string) $key, 0, 20), 422, ['line' => $first]);
                    if ($s['merged_into_sku_id'] !== null) {
                        throw new CwException('merged_item', "line {$first}: item {$s['code']} was merged into item {$s['merged_into_sku_id']}: use that one", 422,
                            ['line' => $first, 'merged_into' => (int) $s['merged_into_sku_id']]);
                    }
                    foreach ($want[$key] as $i) {
                        $out[$i]['sku_id'] = (int) $s['id'];
                    }
                }
            }
        }
        return $out;
    }

    private function warehouseId(string $code, string $field): int
    {
        $w = $this->db->one('SELECT id, is_active FROM warehouse WHERE code = ?', [$code]);
        if ($w === null) {
            throw new CwException('unknown_warehouse', "{$field}: there is no warehouse " . mb_substr($code, 0, 32), 422, ['field' => $field]);
        }
        if ((int) $w['is_active'] !== 1) {
            // Switched off on the Warehouses page (0019, Y16): no new record names it.
            throw new CwException('warehouse_inactive', "{$field}: warehouse " . mb_substr($code, 0, 32) . ' is switched off', 422, ['field' => $field]);
        }
        return (int) $w['id'];
    }

    /** A signed GBP amount with at most 12 integer digits and 6 decimals, as its canonical 6-decimal string. */
    private static function normaliseAmount(mixed $v, string $field): string
    {
        $bad = static fn (): CwException => new CwException('bad_amount', "{$field} must be an amount in GBP with at most 6 decimals", 400, ['field' => $field]);
        if (is_int($v)) {
            if (abs($v) >= 1_000_000_000_000) {
                throw $bad();
            }
            return $v . '.000000';
        }
        if (is_float($v)) {
            if (!is_finite($v) || abs($v) >= 1e12 || abs($v * 1e6 - round($v * 1e6)) >= 1e-6) {
                throw $bad();
            }
            $v = sprintf('%.6F', $v);
            return $v === '-0.000000' ? '0.000000' : $v;
        }
        if (!is_string($v) || preg_match('/^(-?)(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?$/D', $v, $m) !== 1) {
            throw $bad();
        }
        $s = $m[2] . '.' . str_pad($m[3] ?? '', 6, '0');
        return $m[1] === '-' && $s !== '0.000000' ? '-' . $s : $s;
    }

    /** A trimmed string of at most $max characters, or null (absent, null or blank). */
    private static function optText(mixed $v, string $field, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            throw new CwException('bad_field', "{$field} must be text", 400, ['field' => $field]);
        }
        $v = trim($v);
        if (mb_strlen($v) > $max) {
            throw new CwException('bad_field', "{$field} is longer than {$max} characters", 400, ['field' => $field]);
        }
        return $v === '' ? null : $v;
    }

    /**
     * setLines()' lines without their `uid`, and the uid each named (null: none; LV8). An id is a whole number from 1 to 4294967295, each
     * once (400 bad_lines, naming the line); whether it is a line of the draft is giveUids()'. A $lines that is not a list is left to
     * normaliseLines(), which refuses it.
     *
     * @param array<mixed> $lines
     * @return array{0: array<mixed>, 1: list<?int>}
     */
    private static function takeUids(array $lines): array
    {
        if (!array_is_list($lines)) {
            return [$lines, []];
        }
        $uids = [];
        $seen = [];
        foreach ($lines as $i => $line) {
            $uid = null;
            if (is_array($line) && array_key_exists('uid', $line)) {
                $uid = $line['uid'];
                $no = $i + 1;
                if ($uid !== null && (!is_int($uid) || $uid < 1 || $uid > self::UID_MAX)) {
                    throw new CwException('bad_lines', "line {$no}: uid must be the id of a line of this draft, or null for a new line", 400, ['line' => $no]);
                }
                if ($uid !== null && isset($seen[$uid])) {
                    throw new CwException('bad_lines', "line {$no}: line id {$uid} is given twice", 400, ['line' => $no, 'uid' => $uid]);
                }
                if ($uid !== null) {
                    $seen[$uid] = true;
                }
                unset($line['uid']);
                $lines[$i] = $line;
            }
            $uids[] = $uid;
        }
        return [$lines, $uids];
    }

    /**
     * The id of each line of a save (LV8), in its order: the id it named, when that is a line of the draft now (400 bad_lines otherwise:
     * another draft's, or one removed since the page was drawn), else the next free one; and the next free id afterwards (never below an id
     * the draft holds). The document row is locked by the caller, so its lines cannot change meanwhile.
     *
     * @param list<?int> $uids takeUids()
     * @return array{0: list<int>, 1: int}
     */
    private function giveUids(int $documentId, int $next, array $uids): array
    {
        $have = array_flip(array_map('intval', $this->db->column('SELECT uid FROM document_line WHERE document_id = ? AND uid IS NOT NULL', [$documentId])));
        if ($have !== []) {
            $next = max($next, max(array_keys($have)) + 1);
        }
        $next = max($next, 1);
        $out = [];
        foreach ($uids as $i => $uid) {
            if ($uid === null) {
                $out[] = $next++;
            } elseif (isset($have[$uid])) {
                $out[] = $uid;
            } else {
                $no = $i + 1;
                throw new CwException('bad_lines', "line {$no}: there is no line with id {$uid} on this draft (removed since the page was drawn?): reload it", 400,
                    ['line' => $no, 'uid' => $uid]);
            }
        }
        return [$out, $next];
    }

    /**
     * A line as fingerprint() hashes it: ints as ints, decimals as the database returns them (6-decimal strings).
     *
     * @param array<string, mixed> $l
     * @return array{line_no: int, sku_id: ?int, warehouse_id: ?int, qty: ?int, unit_cost: ?string, amount: ?string, reason_code: ?string, description: ?string}
     */
    private static function lineRow(array $l): array
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $str = static fn (mixed $v): ?string => $v === null ? null : (string) $v;
        return ['line_no' => (int) $l['line_no'], 'sku_id' => $int($l['sku_id']), 'warehouse_id' => $int($l['warehouse_id']), 'qty' => $int($l['qty']),
            'unit_cost' => $str($l['unit_cost']), 'amount' => $str($l['amount']), 'reason_code' => $str($l['reason_code']), 'description' => $str($l['description'])];
    }

    /** "your role (buyer)" / "your roles (a, b)" (Kernel's 403 wording). @param list<string> $roles */
    private static function rolesPhrase(array $roles): string
    {
        return (count($roles) === 1 ? 'your role (' : 'your roles (') . ($roles === [] ? 'none' : implode(', ', $roles)) . ')';
    }

    private function nowDb(): string
    {
        return Clock::db(($this->clock)());
    }

    /** $days after $now (a DATETIME(6) string): a task is due review_due_days after it was opened. */
    private static function dueAt(string $now, int $days): string
    {
        return Clock::db(Clock::fromDb($now)->modify("+{$days} days"));
    }
}
