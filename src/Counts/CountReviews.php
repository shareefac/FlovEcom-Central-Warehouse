<?php

declare(strict_types=1);

namespace CW\Counts;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Staff\StaffRoles;
use DateTimeImmutable;

/**
 * "Count differences to check" (pack A2a; docs/decisions.md CT6-CT9): the list and its two answers.
 *
 * Two kinds of row wait here:
 *   a LINE of a posted count sheet whose difference was above the tolerance when it was posted (count_sheet_line.review = open;
 *   CountTolerance, the settings counts.review_over_units and counts.review_over_percent);
 *   a review the count ENGINE opened by itself (count_review, two sources): `count_after_movements` (stock was booked after the
 *   count's time and the count overwrote it) and `count_near_ship` (a parcel left within ten minutes of the count). They had no
 *   screen until this pack.
 *   a flag about the TIME of a count (count_review, two sources Reservations opens): `ship_near_count` and `unship_near_count`, a
 *   dispatch or a reset reported within ten minutes of an item's count time. A sheet line booked under a shared count time (CT3) is
 *   judged by them (review I-1, 14 Oct 2026), so they are listed too: for accepting only (they are about no sheet line, and a
 *   person who finds the figure wrong counts the item again).
 *   Every other source of count_review (stock below zero, a recount after a merge, ...) is not a count difference and is not listed.
 *
 * The answers, each with a note of 3 to 500 characters:
 *   accept   the count stays. The row says who accepted it and why.
 *   reject   the count of THAT LINE is put back by a new record (never a delete): a CNT record of the kind `line_reversal` with one
 *            line, posted in the same transaction, which books an adjustment of minus what the count changed (CountSheetHandler). The
 *            sheet and its other lines stand. The balance's count time stays, as when the engine reverses a count: the item is
 *            counted again on a new sheet. A line is put back once: rejecting the engine's review of a line that was already put back
 *            only settles the review. An engine review of a count that was not made on a sheet can be accepted, not rejected.
 *            Putting a line back also settles everything else that waited about that line's count. It is refused (409) when the item
 *            was counted again after that line (counted_again: the newer count already set the figure, review C-1 of 14 Oct 2026;
 *            the list says so on the row), and while a cancellation of the whole sheet waits for a reviewer's OK (cancel_waiting,
 *            review M-1).
 *
 * Who: someone who may post stock counts (doc.CNT.post; never admin), re-read inside the transaction. Whether it must be a SECOND
 * person is the rule of the kind of record "Stock count" on Settings > Approval Rules, read when the answer is given
 * (CountTolerance::needsSecondPerson): none (as 0029 leaves it) = the person who counted may clear their own difference; only over a
 * limit = a difference of more than that many units needs someone who did not count the line and neither opened nor posted its sheet;
 * every one = always. refusal() gives the screen the same answer before the form is drawn.
 *
 * Locks: the sheet's document row first (as every write of a record), then the line or the review row.
 */
final class CountReviews
{
    /** The sources of count_review about a count of a sheet line: the two the count engine opens (Movements::count). */
    public const ENGINE_SOURCES = ['count_after_movements', 'count_near_ship'];
    /** The sources about the time of a count: a dispatch or a reset near an item's count time (Reservations::ship/unship). Accept only. */
    public const NEAR_SOURCES = ['ship_near_count', 'unship_near_count'];
    /** Every source this list shows, in its order. */
    public const LISTED_SOURCES = [...self::ENGINE_SOURCES, ...self::NEAR_SOURCES];
    public const NOTE_MIN = 3;
    public const NOTE_MAX = 500;
    public const PAGE = 50;

    /** @var \Closure(): DateTimeImmutable */
    private readonly \Closure $clock;

    public function __construct(private readonly Db $db, private readonly Documents $docs, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => Clock::now();
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /**
     * The open rows, the newest first, at most $size of each kind (with `more` when there are more).
     *   lines:  document_id, line_no, number (the sheet's), warehouse, sku_id, code, name, held (what the system held), counted, delta
     *           (the difference booked), counted_at, counted_by, counted_by_name, created_by, posted_by, posted_at, review_units,
     *           review_percent, over (the limits it is above, by today's arithmetic of what it was opened under), counted_again (the
     *           item was counted again since: accept only), canReject
     *   engine: id, source, warehouse, sku_id, code, name, proposed_qty (the quantity counted), ref (the record's number), document_id
     *           and line_no of the sheet line it is about (null: the count was not made on a sheet), delta, counted_by, created_by,
     *           posted_by, put_back (the line was already put back), counted_again, canReject, order (the order a flag about a count's
     *           time names), detail (the engine's own: what it overwrote, which parcels), created_at
     *
     * @param array{warehouse?: ?int} $f
     * @return array{lines: list<array<string, mixed>>, engine: list<array<string, mixed>>, more: bool}
     */
    public function open(array $f = [], int $size = self::PAGE): array
    {
        $wh = $f['warehouse'] ?? null;
        $lines = [];
        foreach ($this->db->all(
            'SELECT l.document_id, l.line_no, d.number, d.warehouse_id, w.name AS warehouse, l.sku_id, s.code, s.name, l.system_qty, l.counted_qty, l.booked_delta, l.counted_at, '
            . 'l.counted_by, cu.display_name AS counted_by_name, d.created_by, d.posted_by, d.posted_at, l.review_units, l.review_percent '
            . 'FROM count_sheet_line l FORCE INDEX (ix_count_sheet_line_review) JOIN document d ON d.id = l.document_id JOIN sku s ON s.id = l.sku_id '
            . 'LEFT JOIN warehouse w ON w.id = d.warehouse_id LEFT JOIN staff_user cu ON cu.id = l.counted_by '
            . "WHERE l.review = 'open'" . ($wh === null ? '' : ' AND d.warehouse_id = ?') . ' ORDER BY l.document_id DESC, l.line_no DESC LIMIT ' . ($size + 1),
            $wh === null ? [] : [(int) $wh],
        ) as $r) {
            // Counted again since (review C-1): the newer count set the figure, so this one is for accepting only.
            $again = CountSheetHandler::countedAgain($this->db, (int) $r['document_id'], (int) $r['warehouse_id'], (int) $r['sku_id']);
            $lines[] = self::lineRow($r) + ['counted_again' => $again, 'canReject' => !$again];
        }
        $engine = [];
        foreach (self::LISTED_SOURCES as $source) {
            // One range of (status, source, id) per source, newest first; merged below.
            foreach ($this->db->all(
                'SELECT r.id, r.source, r.warehouse_id, w.name AS warehouse, r.sku_id, s.code, s.name, r.proposed_qty, r.ref, CAST(r.detail AS CHAR) AS detail, r.created_at, '
                . 'd.id AS document_id, d.created_by, d.posted_by, l.line_no, l.booked_delta, l.counted_by, l.reversal_document_id '
                . 'FROM count_review r FORCE INDEX (ix_count_review_open) JOIN sku s ON s.id = r.sku_id JOIN warehouse w ON w.id = r.warehouse_id '
                . "LEFT JOIN document d ON d.number = r.ref AND d.doc_type = 'CNT' LEFT JOIN count_sheet_line l ON l.document_id = d.id AND l.sku_id = r.sku_id "
                . "WHERE r.status = 'open' AND r.source = ?" . ($wh === null ? '' : ' AND r.warehouse_id = ?') . ' ORDER BY r.id DESC LIMIT ' . ($size + 1),
                $wh === null ? [$source] : [$source, (int) $wh],
            ) as $r) {
                $row = self::engineRow($r);
                $again = $row['document_id'] !== null && CountSheetHandler::countedAgain($this->db, (int) $row['document_id'], (int) $r['warehouse_id'], $row['sku_id']);
                $engine[] = $row + ['counted_again' => $again, 'canReject' => $row['document_id'] !== null && !$row['put_back'] && (int) $row['delta'] !== 0 && !$again];
            }
        }
        usort($engine, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        $more = count($lines) > $size || count($engine) > $size;
        return ['lines' => array_slice($lines, 0, $size), 'engine' => array_slice($engine, 0, $size), 'more' => $more];
    }

    /**
     * How many open rows this person may clear (the tab's badge: only what they can act on): none without the job; with the
     * second-person rule on, not the differences of their own counts that need someone else.
     *
     * @param list<string> $roles
     */
    public function openCount(int $staffId, array $roles): int
    {
        if (!Documents::mayPost($roles, CountSheets::TYPE)) {
            return 0;
        }
        $rule = $this->rule();
        $own = '(l.counted_by <=> ? OR d.created_by <=> ? OR d.posted_by <=> ?)';
        $second = match ($rule['review_rule']) {
            'all' => " AND NOT {$own}",
            'over_limit' => " AND NOT (ABS(l.booked_delta) > ? AND {$own})",
            default => '',
        };
        $params = match ($rule['review_rule']) {
            'all' => [$staffId, $staffId, $staffId],
            'over_limit' => [(int) $rule['review_limit_units'], $staffId, $staffId, $staffId],
            default => [],
        };
        $n = (int) $this->db->value("SELECT COUNT(*) FROM count_sheet_line l FORCE INDEX (ix_count_sheet_line_review) JOIN document d ON d.id = l.document_id "
            . "WHERE l.review = 'open'" . $second, $params);
        foreach (self::LISTED_SOURCES as $source) {
            $n += (int) $this->db->value("SELECT COUNT(*) FROM count_review r FORCE INDEX (ix_count_review_open) WHERE r.status = 'open' AND r.source = ?", [$source]);
        }
        return $n;
    }

    /**
     * Why this person may NOT clear a row of open() (null: they may): the screen shows the reason instead of the buttons, and the
     * answers refuse with it (403).
     *
     * @param list<string> $roles
     * @param array<string, mixed> $row a row of open()
     * @return array{code: string, message: string}|null
     */
    public function refusal(int $staffId, array $roles, array $row): ?array
    {
        if (in_array('admin', $roles, true)) {
            return ['code' => 'admin_cannot_post', 'message' => 'admin manages people and roles and never clears count differences (I12)'];
        }
        if (!Permissions::can($roles, 'doc.CNT.post')) {
            return ['code' => 'role_not_allowed', 'message' => 'you cannot count stock or clear count differences'];
        }
        $rule = $this->rule();
        if (($row['delta'] ?? null) !== null && CountTolerance::needsSecondPerson($rule['review_rule'], $rule['review_limit_units'], (int) $row['delta'])
            && in_array($staffId, [$row['counted_by'] ?? null, $row['created_by'] ?? null, $row['posted_by'] ?? null], true)) {
            return ['code' => 'own_count', 'message' => 'you counted this line, or opened or posted its sheet: someone else clears this difference'];
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------
    // The answers
    // ------------------------------------------------------------------------------------------

    /** Accepts the open check of a sheet line: the count stays. 409 not_open. Audit count.accept. */
    public function acceptLine(Caller $caller, int $documentId, int $lineNo, string $note): void
    {
        $note = self::note($note);
        $this->db->transaction(function (Db $db) use ($caller, $documentId, $lineNo, $note): void {
            $me = $this->me($caller);
            $doc = $this->lockSheet($documentId);
            $line = $this->lockLine($documentId, $lineNo);
            if ($line['review'] !== 'open') {
                throw new CwException('not_open', 'this difference is not waiting any more', 409, ['review' => $line['review']]);
            }
            $this->checkMay($me, $doc, $line);
            $db->exec("UPDATE count_sheet_line SET review = 'accepted', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE document_id = ? AND line_no = ?",
                [$me['id'], Clock::db(($this->clock)()), $note, $documentId, $lineNo]);
            Audit::write($db, $caller, 'count.accept', 'document', (string) $documentId, null, ['line' => $lineNo, 'number' => $doc['number'], 'difference' => (int) $line['booked_delta'],
                'note' => $note]);
        });
    }

    /**
     * Rejects the open check of a sheet line: that line's count is put back by a new record, which is returned. 409 not_open; 409
     * nothing_to_put_back when the sheet was cancelled meanwhile. Audit count.reject.
     */
    public function rejectLine(Caller $caller, int $documentId, int $lineNo, string $note): Document
    {
        $note = self::note($note);
        return $this->db->transaction(function () use ($caller, $documentId, $lineNo, $note): Document {
            $me = $this->me($caller);
            $doc = $this->lockSheet($documentId);
            $line = $this->lockLine($documentId, $lineNo);
            if ($line['review'] !== 'open') {
                throw new CwException('not_open', 'this difference is not waiting any more', 409, ['review' => $line['review']]);
            }
            $this->checkMay($me, $doc, $line);
            return $this->putBack($caller, $me['id'], $doc, $line, $note);
        });
    }

    /** Accepts an open review of the count engine: it is resolved, the count stays. 404 unknown_review, 409 not_open. Audit count.accept. */
    public function acceptEngine(Caller $caller, int $reviewId, string $note): void
    {
        $note = self::note($note);
        $this->db->transaction(function (Db $db) use ($caller, $reviewId, $note): void {
            $me = $this->me($caller);
            [$review, $doc, $line] = $this->lockEngine($reviewId);
            if ($doc !== null && $line !== null) {
                $this->checkMay($me, $doc, $line);
            }
            $this->settle((int) $review['id'], 'accepted', $me['id'], $note);
            Audit::write($db, $caller, 'count.accept', 'count_review', (string) $reviewId, null, ['source' => $review['source'], 'ref' => $review['ref'], 'note' => $note]);
        });
    }

    /**
     * Rejects an open review of the count engine: the count of the sheet line it is about is put back by a new record (returned), and
     * the review is resolved. When that line was already put back, only the review is resolved (null). 409 not_from_sheet for a count
     * that was not made on a count sheet; 409 nothing_to_put_back when the count changed nothing; 409 accept_only for a flag about a
     * count's time (NEAR_SOURCES); 409 counted_again and cancel_waiting as rejectLine().
     */
    public function rejectEngine(Caller $caller, int $reviewId, string $note): ?Document
    {
        $note = self::note($note);
        return $this->db->transaction(function (Db $db) use ($caller, $reviewId, $note): ?Document {
            $me = $this->me($caller);
            [$review, $doc, $line] = $this->lockEngine($reviewId);
            if (in_array($review['source'], self::NEAR_SOURCES, true)) {
                throw new CwException('accept_only', 'this is about the time of a count, not a count sheet line: it is accepted with a note (count the item again '
                    . 'if its figure is wrong)', 409);
            }
            if ($doc === null || $line === null) {
                throw new CwException('not_from_sheet', 'this count was not made on a count sheet: it can be accepted with a note, not put back', 409);
            }
            $this->checkMay($me, $doc, $line);
            if ($line['reversal_document_id'] !== null) {
                $this->settle((int) $review['id'], 'rejected', $me['id'], $note);
                Audit::write($db, $caller, 'count.reject', 'count_review', (string) $reviewId, null, ['source' => $review['source'], 'ref' => $review['ref'], 'note' => $note,
                    'already_put_back_by' => (int) $line['reversal_document_id']]);
                return null;
            }
            return $this->putBack($caller, $me['id'], $doc, $line, $note);
        });
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * Puts one line's count back: the record (a CNT of the kind line_reversal, one line: the item and minus what the count changed),
     * the line's check settled as rejected and naming the record, every open review of the engine about that count settled, then the
     * posting (CountSheetHandler books the adjustment last). The sheet's document row and the line are locked by the caller.
     *
     * @param array<string, mixed> $doc the sheet's document row
     * @param array<string, mixed> $line the sheet line
     */
    private function putBack(Caller $caller, int $staffId, array $doc, array $line, string $note): Document
    {
        if ($doc['status'] !== 'posted' || $line['outcome'] !== 'counted' || (int) $line['booked_delta'] === 0 || $line['reversal_document_id'] !== null) {
            throw new CwException('nothing_to_put_back', 'that count changed nothing that can be put back (its sheet was cancelled, it booked no difference, or it was put back already)', 409);
        }
        // A cancellation of the whole sheet waits for a reviewer's OK (I32): putting one line back now would make that OK impossible
        // (line_put_back). The sheet's row is locked, as Documents::reverse locks it to ask: this cannot race (review M-1).
        if ($this->db->value("SELECT 1 FROM document WHERE reverses_id = ? AND status = 'awaiting_approval'", [(int) $doc['id']]) !== null) {
            throw new CwException('cancel_waiting', 'a cancellation of the whole sheet waits for a reviewer\'s OK: no line of it is put back on its own meanwhile', 409);
        }
        // Said at once (CountSheetHandler checks it again under the stock lock, at the booking).
        if (CountSheetHandler::countedAgain($this->db, (int) $doc['id'], (int) $doc['warehouse_id'], (int) $line['sku_id'])) {
            throw new CwException('counted_again', 'this item was counted again after this count: the newer count set the shelf figure, so this one cannot be put back '
                . '(accept it instead)', 409);
        }
        $now = ($this->clock)();
        $at = Clock::db($now);
        $lineNo = (int) $line['line_no'];
        $back = $this->docs->createDraft($caller, CountSheets::TYPE, [
            'warehouse' => (string) $this->db->value('SELECT code FROM warehouse WHERE id = ?', [(int) $doc['warehouse_id']]),
            'doc_date' => $now->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d'),
            'note' => mb_substr("Puts back line {$lineNo} of {$doc['number']}: {$note}", 0, 1000),
        ]);
        $place = $this->db->value('SELECT location_id FROM count_sheet WHERE document_id = ?', [(int) $doc['id']]);
        $this->db->exec("INSERT INTO count_sheet (document_id, kind, location_id, reverses_document_id, reverses_line_no, created_at, updated_at) "
            . "VALUES (?, 'line_reversal', ?, ?, ?, ?, ?)", [$back->id, $place === null ? null : (int) $place, (int) $doc['id'], $lineNo, $at, $at]);
        if ($line['review'] === 'open') {
            // Its check is settled by this answer: rejected, by whom, why, and the record that put the count back.
            $this->db->exec("UPDATE count_sheet_line SET review = 'rejected', reviewed_by = ?, reviewed_at = ?, review_note = ?, reversal_document_id = ? "
                . 'WHERE document_id = ? AND line_no = ?', [$staffId, $at, $note, $back->id, (int) $doc['id'], $lineNo]);
        } else {
            // No check waited (the engine's review was rejected), or it was accepted before: the line names the record all the same.
            $this->db->exec('UPDATE count_sheet_line SET reversal_document_id = ? WHERE document_id = ? AND line_no = ?', [$back->id, (int) $doc['id'], $lineNo]);
        }
        foreach (self::ENGINE_SOURCES as $source) {
            $this->db->exec("UPDATE count_review SET status = 'resolved', resolution = 'rejected', resolved_by = ?, resolved_at = ?, note = ? "
                . "WHERE status = 'open' AND source = ? AND warehouse_id = ? AND sku_id = ? AND ref = ?", [$staffId, $at, $note, $source, (int) $doc['warehouse_id'], (int) $line['sku_id'], (string) $doc['number']]);
        }
        Audit::write($this->db, $caller, 'count.reject', 'document', (string) $doc['id'], null, ['line' => $lineNo, 'number' => $doc['number'],
            'difference' => (int) $line['booked_delta'], 'put_back_by' => $back->id, 'note' => $note]);
        $back = $this->docs->setLines($caller, $back->id, $back->version, [['sku_id' => (int) $line['sku_id'], 'qty' => -(int) $line['booked_delta']]]);
        return $this->docs->post($caller, $back->id, $back->version);
    }

    /** @return array{id: int, roles: list<string>} the caller as staff who may clear count differences (403 otherwise) */
    private function me(Caller $caller): array
    {
        CountSheets::mayCount($this->db, $caller);
        return ['id' => (int) $caller->staffUserId, 'roles' => StaffRoles::active($this->db, (int) $caller->staffUserId)];
    }

    /**
     * @param array{id: int, roles: list<string>} $me
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $line
     */
    private function checkMay(array $me, array $doc, array $line): void
    {
        $no = $this->refusal($me['id'], $me['roles'], ['delta' => $line['booked_delta'], 'counted_by' => $line['counted_by'] === null ? null : (int) $line['counted_by'],
            'created_by' => $doc['created_by'] === null ? null : (int) $doc['created_by'], 'posted_by' => $doc['posted_by'] === null ? null : (int) $doc['posted_by']]);
        if ($no !== null) {
            throw new CwException($no['code'], $no['message'], 403);
        }
    }

    /** @return array<string, mixed> the document row of a count sheet, X-locked (404 not_count_sheet) */
    private function lockSheet(int $documentId): array
    {
        $r = $this->db->one('SELECT d.id, d.doc_type, d.number, d.status, d.warehouse_id, d.created_by, d.posted_by, CAST(cs.kind AS CHAR) AS kind FROM document d '
            . 'LEFT JOIN count_sheet cs ON cs.document_id = d.id WHERE d.id = ? FOR UPDATE OF d', [$documentId]);
        if ($r === null || $r['doc_type'] !== CountSheets::TYPE || $r['kind'] !== 'sheet') {
            throw new CwException('not_count_sheet', 'this is not a count sheet', 404);
        }
        return $r;
    }

    /** @return array<string, mixed> a sheet line, X-locked (404 unknown_line) */
    private function lockLine(int $documentId, int $lineNo): array
    {
        return $this->db->one('SELECT line_no, sku_id, counted_by, booked_delta, CAST(outcome AS CHAR) AS outcome, CAST(review AS CHAR) AS review, reversal_document_id '
            . 'FROM count_sheet_line WHERE document_id = ? AND line_no = ? FOR UPDATE', [$documentId, $lineNo])
            ?? throw new CwException('unknown_line', 'there is no such line of a count sheet', 404);
    }

    /**
     * An open engine review with the sheet line it is about, locked: the sheet's document row first (found by an unlocked read of the
     * review, whose reference never changes), then the review (re-read under its lock), then the line.
     *
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>, 2: ?array<string, mixed>} [review, sheet document row, sheet line]
     */
    private function lockEngine(int $reviewId): array
    {
        $in = implode(', ', array_fill(0, count(self::LISTED_SOURCES), '?'));
        $peek = $this->db->one("SELECT r.ref, r.source, r.sku_id, r.warehouse_id FROM count_review r WHERE r.id = ? AND r.source IN ({$in})", [$reviewId, ...self::LISTED_SOURCES])
            ?? throw new CwException('unknown_review', 'there is no such count difference', 404);
        $docId = $peek['ref'] === null || in_array($peek['source'], self::NEAR_SOURCES, true) ? null : $this->db->value("SELECT d.id FROM document d JOIN count_sheet cs ON cs.document_id = d.id AND cs.kind = 'sheet' "
            . 'WHERE d.number = ? AND d.doc_type = ? AND d.warehouse_id = ?', [(string) $peek['ref'], CountSheets::TYPE, (int) $peek['warehouse_id']]);
        $doc = $docId === null ? null : $this->lockSheet((int) $docId);
        $review = $this->db->one('SELECT id, source, ref, sku_id, warehouse_id, CAST(status AS CHAR) AS status FROM count_review WHERE id = ? FOR UPDATE', [$reviewId])
            ?? throw new CwException('unknown_review', 'there is no such count difference', 404);
        if ($review['status'] !== 'open') {
            throw new CwException('not_open', 'this difference is not waiting any more', 409, ['status' => $review['status']]);
        }
        $line = null;
        if ($doc !== null) {
            $no = $this->db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND sku_id = ?', [(int) $doc['id'], (int) $review['sku_id']]);
            $line = $no === null ? null : $this->lockLine((int) $doc['id'], (int) $no);
        }
        return [$review, $line === null ? null : $doc, $line];
    }

    private function settle(int $reviewId, string $resolution, int $staffId, string $note): void
    {
        $this->db->exec("UPDATE count_review SET status = 'resolved', resolution = ?, resolved_by = ?, resolved_at = ?, note = ? WHERE id = ? AND status = 'open'",
            [$resolution, $staffId, Clock::db(($this->clock)()), $note, $reviewId]);
    }

    /** @return array{review_rule: string, review_limit_units: ?int} the rule of stock counts as it is now */
    private function rule(): array
    {
        $r = $this->db->one("SELECT CAST(review_rule AS CHAR) AS review_rule, review_limit_units FROM document_type WHERE code = ?", [CountSheets::TYPE]);
        return ['review_rule' => (string) ($r['review_rule'] ?? 'none'), 'review_limit_units' => ($r['review_limit_units'] ?? null) === null ? null : (int) $r['review_limit_units']];
    }

    private static function note(string $note): string
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', mb_check_encoding($note, 'UTF-8') ? $note : ''));
        if (mb_strlen($note) < self::NOTE_MIN || mb_strlen($note) > self::NOTE_MAX) {
            throw new CwException('note_required', 'say in ' . self::NOTE_MIN . ' to ' . self::NOTE_MAX . ' characters why', 400, ['field' => 'note']);
        }
        return $note;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function lineRow(array $r): array
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $held = (int) $r['system_qty'];
        $delta = (int) $r['booked_delta'];
        return ['kind' => 'line', 'document_id' => (int) $r['document_id'], 'line_no' => (int) $r['line_no'], 'number' => (string) $r['number'],
            'warehouse' => (string) ($r['warehouse'] ?? ''), 'sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'held' => $held,
            'counted' => (int) $r['counted_qty'], 'delta' => $delta, 'counted_at' => (string) $r['counted_at'], 'counted_by' => $int($r['counted_by']),
            'counted_by_name' => $r['counted_by_name'] === null ? null : (string) $r['counted_by_name'], 'created_by' => $int($r['created_by']), 'posted_by' => $int($r['posted_by']),
            'posted_at' => $r['posted_at'], 'review_units' => $int($r['review_units']), 'review_percent' => $int($r['review_percent']),
            'over' => CountTolerance::over($held, $delta, (int) $r['review_units'], (int) $r['review_percent'])];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function engineRow(array $r): array
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $detail = $r['detail'] === null ? [] : json_decode((string) $r['detail'], true);
        $near = in_array((string) $r['source'], self::NEAR_SOURCES, true);
        $onLine = !$near && $r['line_no'] !== null;
        // A flag about a count's time names a unit of an order: "<site>:<order>:<unit>" (Reservations).
        $parts = $r['ref'] === null ? [] : explode(':', (string) $r['ref']);
        $order = $near && count($parts) >= 3 ? implode(':', array_slice($parts, 1, -1)) : null;
        return ['kind' => 'engine', 'id' => (int) $r['id'], 'source' => (string) $r['source'], 'warehouse' => (string) $r['warehouse'], 'sku_id' => (int) $r['sku_id'], 'order' => $order,
            'code' => (string) $r['code'], 'name' => (string) $r['name'], 'proposed_qty' => $int($r['proposed_qty']), 'ref' => $r['ref'] === null ? null : (string) $r['ref'],
            'document_id' => $onLine ? (int) $r['document_id'] : null, 'line_no' => $onLine ? $int($r['line_no']) : null, 'delta' => $onLine ? $int($r['booked_delta']) : null,
            'counted_by' => $onLine ? $int($r['counted_by']) : null,
            'created_by' => $onLine ? $int($r['created_by']) : null, 'posted_by' => $onLine ? $int($r['posted_by']) : null, 'put_back' => $onLine && $r['reversal_document_id'] !== null,
            'detail' => is_array($detail) ? $detail : [], 'created_at' => (string) $r['created_at']];
    }
}
