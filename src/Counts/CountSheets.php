<?php

declare(strict_types=1);

namespace CW\Counts;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Matching\Gtin;
use CW\Movements;
use CW\Output\CsvReader;
use CW\Output\CsvWriter;
use CW\Settings;
use CW\Staff\StaffRoles;
use CW\StockOps\StockOpHandler;
use CW\Ui\StockViews;
use DateTimeImmutable;

/**
 * Count sheets (pack A2a, Stock > Counts; docs/decisions.md CT1-CT18), on top of the document base (Documents: draft -> posted;
 * never deleted; stopped while a draft, cancelled by a new record once posted) and their handler (CountSheetHandler, which books the
 * counts through the count engine).
 *
 * A sheet is a `document` of type CNT (the warehouse counted, the day it was opened, a note) with a `count_sheet` header (the optional
 * place) and its lines in `count_sheet_line`: one per item (an item is on a sheet once), with what the system held when the line was
 * put on the sheet, and the quantity counted. An empty quantity means "not counted yet": nothing is booked for it. 0 is a count of
 * nothing. A quantity is saved the moment it is entered, with that moment as the time the item was counted, and who entered it; an
 * upload says when its quantities were counted (a paper count typed in later keeps its true time). Entering a quantity books
 * nothing: only post() does, and only the counted lines.
 *
 * Every write: a staff caller allowed to post stock counts (doc.CNT.post, never admin; roles re-read inside the transaction), ONE
 * transaction (joining an open one), the document row locked first. A draft sheet is changed and posted only by the person who
 * opened it (the document base's rule for a draft, I19: its lines become the record's lines). Each change moves the document's
 * version, so the page "Before you post" is refused when a quantity changed after it was drawn (409 version_conflict).
 *
 * "Warn first" (the module plan's decision 6): a line whose stock moved after it was counted is shown with what moved
 * (differences()), and the posting is refused until the person either counts it again (recount(): the line is empty again) or
 * chooses to post it as counted (postAsCounted(): the engine's as-of rule then does the arithmetic). The choice names the newest
 * movement the person saw; a movement after it asks again. Nothing is adjusted behind the person's back.
 */
final class CountSheets
{
    public const TYPE = CountSheetHandler::TYPE;
    public const PATH = '/ui/stock/counts';
    /** The most lines one sheet holds (a posting books at most Movements::MAX_LINES; a sheet stays one a person can work through). */
    public const MAX_LINES = 500;
    public const NOTE_MAX = 1000;
    /** The largest quantity a line takes (the engine's own bound). */
    public const MAX_QTY = Documents::MAX_QTY;
    public const STATES = ['draft', 'posted', 'reversed', 'cancelled'];
    /** The columns an upload needs (any others are ignored). */
    public const UPLOAD_COLUMNS = ['cw_code', 'counted'];
    /** The most rows an upload's lists show of each kind (the counts are always whole). */
    public const UPLOAD_SHOWN = 50;

    /** @var \Closure(): DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): DateTimeImmutable)|null $clock CW's clock (the moment a quantity was entered; tests pin it) */
    public function __construct(private readonly Db $db, private readonly Documents $docs, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => Clock::now();
    }

    // ------------------------------------------------------------------------------------------
    // A draft sheet
    // ------------------------------------------------------------------------------------------

    /**
     * Opens a sheet for these items (none: an empty sheet, filled by the scan box). Header: warehouse (an id; required and switched
     * on), location (an id of a place inside it; optional), note. Each item once, in the order given; an item that does not exist
     * (422 unknown_sku) or was merged into another (422 merged_item) refuses the sheet. Each line keeps what the system holds now.
     * 422 too_many_lines over MAX_LINES. Audit count.open.
     *
     * @param array<string, mixed> $header
     * @param list<int> $skuIds
     */
    public function open(Caller $caller, array $header, array $skuIds): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $header, $skuIds): Document {
            self::mayCount($db, $caller);
            $warehouse = self::id($header['warehouse'] ?? null, 'warehouse') ?? throw new CwException('warehouse_required', 'say which warehouse is counted', 422, ['field' => 'warehouse']);
            $location = self::id($header['location'] ?? null, 'location');
            StockOpHandler::checkPlacesOf($db, 'in', $warehouse, $location, null, null, false);
            $note = self::text($header['note'] ?? null, self::NOTE_MAX, 'note');
            $skuIds = array_values(array_unique(array_map('intval', $skuIds)));
            if (count($skuIds) > self::MAX_LINES) {
                throw new CwException('too_many_lines', 'a count sheet has at most ' . self::MAX_LINES . ' lines: open another one for the rest', 422, ['max' => self::MAX_LINES]);
            }
            $this->checkItems($skuIds);
            $now = ($this->clock)();
            $doc = $this->docs->createDraft($caller, self::TYPE, ['warehouse' => (string) $db->value('SELECT code FROM warehouse WHERE id = ?', [$warehouse]),
                'doc_date' => $now->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d'), 'note' => $note]);
            $at = Clock::db($now);
            $db->exec("INSERT INTO count_sheet (document_id, kind, location_id, created_at, updated_at) VALUES (?, 'sheet', ?, ?, ?)", [$doc->id, $location, $at, $at]);
            $held = $this->onHand($warehouse, $skuIds);
            foreach (array_chunk($skuIds, 250, true) as $chunk) {
                $params = [];
                foreach ($chunk as $i => $sku) {
                    array_push($params, $doc->id, $i + 1, $sku, $held[$sku] ?? 0, $at);
                }
                $db->exec('INSERT INTO count_sheet_line (document_id, line_no, sku_id, expected_qty, added_at) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')), $params);
            }
            Audit::write($db, $caller, 'count.open', 'document', (string) $doc->id, null, ['warehouse' => $warehouse, 'lines' => count($skuIds)]
                + ($location === null ? [] : ['location' => $location]));
            return $doc;
        });
    }

    /**
     * Puts one more item on a draft sheet (the scan box's offer), with what the system holds now. An item already on it is not
     * added again (`added` false, its line). Opener only.
     *
     * @return array{line_no: int, added: bool}
     */
    public function addItem(Caller $caller, int $id, int $skuId): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $skuId): array {
            $row = $this->lockDraft($caller, $id);
            $line = $db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND sku_id = ?', [$id, $skuId]);
            if ($line !== null) {
                return ['line_no' => (int) $line, 'added' => false];
            }
            $this->checkItems([$skuId]);
            $n = (int) $db->value('SELECT COUNT(*) FROM count_sheet_line WHERE document_id = ?', [$id]);
            if ($n >= self::MAX_LINES) {
                throw new CwException('too_many_lines', 'a count sheet has at most ' . self::MAX_LINES . ' lines: open another one for the rest', 422, ['max' => self::MAX_LINES]);
            }
            $no = (int) $db->value('SELECT COALESCE(MAX(line_no), 0) + 1 FROM count_sheet_line WHERE document_id = ?', [$id]);
            $at = Clock::db(($this->clock)());
            $db->exec('INSERT INTO count_sheet_line (document_id, line_no, sku_id, expected_qty, added_at) VALUES (?, ?, ?, ?, ?)',
                [$id, $no, $skuId, $this->onHand((int) $row['warehouse_id'], [$skuId])[$skuId] ?? 0, $at]);
            $this->touch($id, $at);
            return ['line_no' => $no, 'added' => true];
        });
    }

    /**
     * Saves quantities of a draft sheet: line number => whole units from 0 to MAX_QTY, or null to empty the line again. A line whose
     * quantity is the one it already holds is left alone (it keeps the moment it was counted: a whole page saved again changes only
     * what changed). A changed line takes $countedAt (default: now) as the moment it was counted, the caller as who entered it, and
     * loses its "post as counted" mark. Opener only. 422 bad_quantity, 422 unknown_line. Returns how many lines changed.
     *
     * @param array<int, ?int> $quantities
     */
    public function setQuantities(Caller $caller, int $id, array $quantities, ?DateTimeImmutable $countedAt = null, string $how = 'typed'): int
    {
        if (!in_array($how, ['typed', 'upload'], true)) {
            throw new \InvalidArgumentException("unknown way of entering a quantity {$how}");
        }
        foreach ($quantities as $no => $qty) {
            if ($qty !== null && (!is_int($qty) || $qty < 0 || $qty > self::MAX_QTY)) {
                throw new CwException('bad_quantity', "line {$no}: a counted quantity is a whole number of units from 0 to " . self::MAX_QTY, 422, ['line' => (int) $no]);
            }
        }
        return $this->db->transaction(function (Db $db) use ($caller, $id, $quantities, $countedAt, $how): int {
            $this->lockDraft($caller, $id);
            if ($quantities === []) {
                return 0;
            }
            $now = ($this->clock)();
            $at = Clock::db($countedAt ?? $now);
            $nos = array_map('intval', array_keys($quantities));
            $have = [];
            foreach ($db->all('SELECT line_no, counted_qty FROM count_sheet_line WHERE document_id = ? AND line_no IN (' . implode(', ', array_fill(0, count($nos), '?'))
                . ') FOR UPDATE', [$id, ...$nos]) as $l) {
                $have[(int) $l['line_no']] = $l['counted_qty'] === null ? null : (int) $l['counted_qty'];
            }
            $changed = 0;
            foreach ($quantities as $no => $qty) {
                $no = (int) $no;
                if (!array_key_exists($no, $have)) {
                    throw new CwException('unknown_line', "this sheet has no line {$no}", 422, ['line' => $no]);
                }
                if ($have[$no] === $qty) {
                    continue;
                }
                if ($qty === null) {
                    $db->exec('UPDATE count_sheet_line SET counted_qty = NULL, counted_at = NULL, counted_by = NULL, entered_at = NULL, entered_how = NULL, ack_ledger_id = NULL, '
                        . 'ack_at = NULL WHERE document_id = ? AND line_no = ?', [$id, $no]);
                } else {
                    $db->exec('UPDATE count_sheet_line SET counted_qty = ?, counted_at = ?, counted_by = ?, entered_at = ?, entered_how = ?, ack_ledger_id = NULL, ack_at = NULL '
                        . 'WHERE document_id = ? AND line_no = ?', [$qty, $at, $caller->staffUserId, Clock::db($now), $how, $id, $no]);
                }
                $changed++;
            }
            if ($changed > 0) {
                $this->touch($id, Clock::db($now));
            }
            return $changed;
        });
    }

    /** "Count it again" (warn first): the line is empty again; the next quantity entered is a new count, made now. Opener only. */
    public function recount(Caller $caller, int $id, int $lineNo): void
    {
        $this->setQuantities($caller, $id, [$lineNo => null]);
    }

    /**
     * "Post it as counted" (warn first): the person saw what moved after the line was counted and wants the count booked as it is
     * (the engine takes dispatches after the count off it, and overwrites anything else: its own review then says what). $seen is the
     * newest movement the page showed; when it is not the newest there is now (something moved since the page was drawn, or the form
     * was made by hand), nothing is marked (409 moved_since: look again). Opener only.
     */
    public function postAsCounted(Caller $caller, int $id, int $lineNo, int $seen): void
    {
        $this->db->transaction(function (Db $db) use ($caller, $id, $lineNo, $seen): void {
            $this->lockDraft($caller, $id);
            $now = ($this->clock)();
            $line = null;
            foreach (CountPosting::plan($db, $id, $now)['lines'] as $l) {
                if ($l['line_no'] === $lineNo) {
                    $line = $l;
                }
            }
            if ($line === null) {
                throw new CwException('unknown_line', "this sheet has no counted line {$lineNo}", 422, ['line' => $lineNo]);
            }
            if ($line['stale'] || $line['moved']['n'] === 0 || $line['moved']['max_id'] !== $seen) {
                throw new CwException('moved_since', "stock of line {$lineNo} is not as this page showed it: look at the differences again", 409, ['lines' => [$lineNo]]);
            }
            $at = Clock::db($now);
            $db->exec('UPDATE count_sheet_line SET ack_ledger_id = ?, ack_at = ? WHERE document_id = ? AND line_no = ?', [$seen, $at, $id, $lineNo]);
            $this->touch($id, $at);
        });
    }

    /**
     * Posts a draft sheet at the version the page was drawn with: its counted lines become the record's lines (Documents::setLines:
     * opener only) and the record is posted (Documents::post; CountSheetHandler validates and books through the count engine), in one
     * transaction: a refusal leaves the sheet exactly as it was. 422 nothing_counted when every line is empty. An upload never posts.
     */
    public function post(Caller $caller, int $id, int $version): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version): Document {
            $this->lockSheet($id);
            $lines = array_map(static fn (array $l): array => ['sku_id' => (int) $l['sku_id'], 'qty' => (int) $l['counted_qty']],
                $db->all('SELECT sku_id, counted_qty FROM count_sheet_line WHERE document_id = ? AND counted_qty IS NOT NULL ORDER BY line_no', [$id]));
            $doc = $this->docs->get($id);
            if ($doc->status === 'draft' && $lines === []) {
                throw new CwException('nothing_counted', 'no line of this sheet has a quantity yet: enter what was counted first', 422);
            }
            $doc = $this->docs->setLines($caller, $id, $version, $lines);
            return $this->docs->post($caller, $id, $doc->version);
        });
    }

    /** Stops a draft sheet ($reason 3-500 characters; Documents::cancelDraft). The sheet and its lines are kept. */
    public function cancel(Caller $caller, int $id, int $version, string $reason): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version, $reason): Document {
            $this->lockSheet($id);
            return $this->docs->cancelDraft($caller, $id, $version, $reason);
        });
    }

    /**
     * Cancels a posted sheet as a whole by a new record that puts every figure back (Documents::reverse; a reason offered for
     * cancellations). 409 line_put_back when a line of it was already put back on its own (CountSheetHandler::reverse).
     */
    public function reverse(Caller $caller, int $id, string $reasonCode, ?string $note): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $reasonCode, $note): Document {
            $this->lockSheet($id);
            // Said at once, also when the cancellation would first wait for a reviewer's OK (the handler says it again at the booking).
            $back = $db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND reversal_document_id IS NOT NULL ORDER BY line_no LIMIT 1', [$id]);
            if ($back !== null && $this->docs->get($id)->status === 'posted') {
                throw new CwException('line_put_back', "line {$back} of this sheet was already put back on its own: the sheet cannot be cancelled as a whole "
                    . '(reject its other differences one by one)', 409, ['line' => (int) $back]);
            }
            return $this->docs->reverse($caller, $id, $reasonCode, $note);
        });
    }

    // ------------------------------------------------------------------------------------------
    // The scan box, the download and the upload
    // ------------------------------------------------------------------------------------------

    /**
     * What a scanned barcode or a typed CW number is, for this sheet. It never guesses:
     *   on_sheet      an item on the sheet: its line (`units`: what the barcode stands for; an outer case says how many)
     *   not_on_sheet  an item that is not on it: the page offers to add it (sku_id, code, name)
     *   ambiguous     a barcode found on more than one item (kept unusable until someone decides it): nothing is offered
     *   unknown       on no item
     * Digits are a barcode and nothing else; a CW number is typed with its letters (CW-000123, cw123).
     *
     * @return array{status: string, line_no?: int, sku_id?: int, units?: int, code?: string, name?: string}
     */
    public function find(int $id, string $scanned): array
    {
        $q = trim($scanned);
        $sku = null;
        $units = 1;
        if (preg_match('/^[0-9]{4,64}$/D', $q) === 1) {
            $rows = $this->db->all('SELECT b.sku_id, b.is_usable, b.units_per_scan FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode IN (?, ?) '
                . 'AND s.merged_into_sku_id IS NULL', [$q, Gtin::key($q) ?? $q]);
            if ($rows === []) {
                return ['status' => 'unknown'];
            }
            $items = array_unique(array_map(static fn (array $r): int => (int) $r['sku_id'], $rows));
            if (count($items) !== 1 || array_filter($rows, static fn (array $r): bool => (int) $r['is_usable'] !== 1) !== []) {
                return ['status' => 'ambiguous'];
            }
            $sku = $items[0];
            $units = max(1, (int) $rows[0]['units_per_scan']);
        } elseif (preg_match('/^cw-?0*([0-9]{1,10})$/iD', $q, $m) === 1) {
            $sku = (int) $m[1];
        }
        $item = $sku === null ? null : $this->db->one('SELECT id, code, name FROM sku WHERE id = ? AND merged_into_sku_id IS NULL', [$sku]);
        if ($item === null) {
            return ['status' => 'unknown'];
        }
        $line = $this->db->value('SELECT line_no FROM count_sheet_line WHERE document_id = ? AND sku_id = ?', [$id, $sku]);
        return $line !== null ? ['status' => 'on_sheet', 'line_no' => (int) $line, 'sku_id' => $sku, 'units' => $units]
            : ['status' => 'not_on_sheet', 'sku_id' => $sku, 'units' => $units, 'code' => (string) $item['code'], 'name' => (string) $item['name']];
    }

    /**
     * The sheet as a file to count on paper or elsewhere (CsvWriter: UTF-8, text cells safe against formulas): line, cw_code, item,
     * brand, barcodes, stock_now (only while the setting shows the system's figure on a sheet), counted (what the sheet holds; empty
     * for a line not counted yet). It comes back through readUpload() / applyUpload(), which read cw_code and counted.
     */
    public function csv(int $id): string
    {
        $this->sheet($id) ?? throw new CwException('not_count_sheet', 'this is not a count sheet', 404);
        $shows = Settings::flag($this->db, 'counts.sheet_shows_stock', true);
        $cols = [['line', 'number'], ['cw_code', 'text'], ['item', 'text'], ['brand', 'text'], ['barcodes', 'text']];
        if ($shows) {
            $cols[] = ['stock_now', 'number'];
        }
        $cols[] = ['counted', 'number'];
        $out = new CsvWriter($cols);
        foreach ($this->lines($id, ['show' => 'all', 'page' => 1, 'size' => self::MAX_LINES])['rows'] as $l) {
            $row = [$l['line_no'], $l['code'], $l['name'], $l['brand'] ?? '', implode(' ', $l['barcodes'])];
            if ($shows) {
                $row[] = $l['on_hand'];
            }
            $row[] = $l['counted_qty'];
            $out->add($row);
        }
        return $out->output();
    }

    /**
     * Reads an uploaded file against the sheet and says what it would do. Writes nothing. The file needs the columns cw_code and
     * counted (400 bad_file otherwise, or when it is no table at all). Row by row (the row numbers are the file's, without its header):
     *   fill          a line of the sheet gets this quantity (from what it holds to the new one)
     *   same          the line already holds it: left alone
     *   blank         the counted cell is empty: the line is left as it is
     *   not_on_sheet  the code is not a line of this sheet (another item, or no item at all): nothing is added
     *   bad           it cannot be read: no code (no_code), not a whole number (not_whole), below zero (negative), more than MAX_QTY
     *                 (too_large), or a second quantity for a line this file already gave one (twice)
     *
     * @return array{applied: bool, filled: int, rows: int, fill: list<array{row: int, line_no: int, code: string, name: string, from: ?int, to: int}>, same: int, blank: int,
     *               not_on_sheet: list<array{row: int, code: string}>, bad: list<array{row: int, code: string, why: string, value: string}>}
     */
    public function readUpload(int $id, string $path): array
    {
        $this->sheet($id) ?? throw new CwException('not_count_sheet', 'this is not a count sheet', 404);
        $table = CsvReader::table($path, CsvReader::DEFAULT_MAX_BYTES, self::MAX_LINES * 4);
        if (array_diff(self::UPLOAD_COLUMNS, $table['header']) !== []) {
            throw new CwException('bad_file', 'the file needs the columns ' . implode(' and ', self::UPLOAD_COLUMNS) . ' (as the sheet\'s own download has them)', 400,
                ['columns' => self::UPLOAD_COLUMNS]);
        }
        $lines = [];
        foreach ($this->db->all('SELECT l.line_no, l.counted_qty, s.code, s.name FROM count_sheet_line l JOIN sku s ON s.id = l.sku_id WHERE l.document_id = ?', [$id]) as $l) {
            $lines[strtoupper((string) $l['code'])] = ['line_no' => (int) $l['line_no'], 'name' => (string) $l['name'], 'have' => $l['counted_qty'] === null ? null : (int) $l['counted_qty']];
        }
        $out = ['applied' => false, 'filled' => 0, 'rows' => 0, 'fill' => [], 'same' => 0, 'blank' => 0, 'not_on_sheet' => [], 'bad' => []];
        $taken = [];
        foreach ($table['rows'] as $n => $row) {
            $out['rows']++;
            $code = mb_substr(strtoupper(trim((string) $row['cw_code'])), 0, 32);
            $value = trim((string) $row['counted']);
            $shown = mb_substr($value, 0, 32);
            if ($code === '') {
                $out['bad'][] = ['row' => $n, 'code' => '', 'why' => 'no_code', 'value' => $shown];
                continue;
            }
            if ($value === '') {
                $out['blank']++;
                continue;
            }
            $why = match (true) {
                preg_match('/^-[0-9]+$/D', $value) === 1 => 'negative',
                preg_match('/^\+?[0-9]+$/D', $value) !== 1 => 'not_whole',
                strlen(ltrim($value, '+0')) > 8 || (int) $value > self::MAX_QTY => 'too_large',
                default => null,
            };
            if ($why !== null) {
                $out['bad'][] = ['row' => $n, 'code' => $code, 'why' => $why, 'value' => $shown];
                continue;
            }
            $line = $lines[$code] ?? null;
            if ($line === null) {
                $out['not_on_sheet'][] = ['row' => $n, 'code' => $code];
                continue;
            }
            if (isset($taken[$code])) {
                $out['bad'][] = ['row' => $n, 'code' => $code, 'why' => 'twice', 'value' => $shown];
                continue;
            }
            $taken[$code] = true;
            $to = (int) $value;
            if ($line['have'] === $to) {
                $out['same']++;
                continue;
            }
            $out['fill'][] = ['row' => $n, 'line_no' => $line['line_no'], 'code' => $code, 'name' => $line['name'], 'from' => $line['have'], 'to' => $to];
        }
        return $out;
    }

    /**
     * Fills the sheet from an uploaded file: exactly readUpload()'s `fill` rows, read again inside the transaction (the sheet's own
     * lines only; nothing is added, nothing is posted). $countedAt: when the quantities were counted (default: now); not after now and
     * not older than the engine takes (400 bad_counted_at). Rows that cannot be read are skipped and reported, the rest is filled.
     * Opener only. Audit count.upload.
     *
     * @return array<string, mixed> readUpload()'s report with applied true and `filled`
     */
    public function applyUpload(Caller $caller, int $id, string $path, ?DateTimeImmutable $countedAt, ?string $fileName = null): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $path, $countedAt, $fileName): array {
            $this->lockDraft($caller, $id);
            $now = ($this->clock)();
            if ($countedAt !== null && (Clock::diff($now, $countedAt) > Clock::MAX_AHEAD_SEC || Clock::diff($countedAt, $now) > Movements::BACKDATED_MAX_AGE_SEC)) {
                throw new CwException('bad_counted_at', 'the time it was counted is not after now and not more than '
                    . intdiv(Movements::BACKDATED_MAX_AGE_SEC, 86_400) . ' days ago', 400, ['field' => 'counted_at', 'days' => intdiv(Movements::BACKDATED_MAX_AGE_SEC, 86_400)]);
            }
            $report = $this->readUpload($id, $path);
            $filled = $this->setQuantities($caller, $id, array_column($report['fill'], 'to', 'line_no'), $countedAt ?? $now, 'upload');
            Audit::write($db, $caller, 'count.upload', 'document', (string) $id, null, ['file' => $fileName === null ? null : mb_substr($fileName, 0, 120), 'rows' => $report['rows'],
                'filled' => $filled, 'not_on_sheet' => count($report['not_on_sheet']), 'bad' => count($report['bad']), 'counted_at' => Clock::db($countedAt ?? $now)]);
            return ['applied' => true, 'filled' => $filled] + $report;
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /**
     * Everything a sheet's page shows about its header: the document row, its kind, the warehouse and place, the people, the record it
     * cancels or puts a line back of, the record that cancels it, and how many lines it has and how many are counted.
     *
     * @return array<string, mixed>|null null for anything that is not a stock count record
     */
    public function sheet(int $id): ?array
    {
        $r = $this->db->one(
            'SELECT d.*, CAST(cs.kind AS CHAR) AS kind, cs.location_id, cs.reverses_document_id, cs.reverses_line_no, w.code AS warehouse_code, w.name AS warehouse_name, '
            . 'l.name AS location_name, cb.display_name AS created_by_name, pb.display_name AS posted_by_name, xb.display_name AS cancelled_by_name, '
            . 'od.number AS reverses_number, ld.number AS line_of_number, rv.id AS reversed_by_id, rv.number AS reversed_by_number, rv.status AS reversed_by_status, '
            . '(SELECT COUNT(*) FROM count_sheet_line x WHERE x.document_id = d.id) AS lines_n, '
            . '(SELECT COUNT(*) FROM count_sheet_line x WHERE x.document_id = d.id AND x.counted_qty IS NOT NULL) AS counted_n '
            . 'FROM document d JOIN count_sheet cs ON cs.document_id = d.id LEFT JOIN warehouse w ON w.id = d.warehouse_id '
            . 'LEFT JOIN warehouse_location l ON l.id = cs.location_id LEFT JOIN staff_user cb ON cb.id = d.created_by LEFT JOIN staff_user pb ON pb.id = d.posted_by '
            . 'LEFT JOIN staff_user xb ON xb.id = d.cancelled_by LEFT JOIN document od ON od.id = d.reverses_id LEFT JOIN document ld ON ld.id = cs.reverses_document_id '
            . "LEFT JOIN document rv ON rv.reverses_id = d.id AND rv.status IN ('posted', 'awaiting_approval') WHERE d.id = ? AND d.doc_type = ?",
            [$id, self::TYPE],
        );
        return $r;
    }

    /**
     * One page of a sheet's lines, in line order: line_no, sku_id, code, name, brand, its barcodes, the selling mode CW last set
     * (null: none), what the system holds now at the sheet's warehouse (on_hand, reserved), what it held when the line was put on the
     * sheet, the quantity counted with when and by whom, and what the posting booked with its check.
     *
     * @param array{show?: string, page?: int, size?: int} $f show: all | todo (not counted yet) | done (counted)
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function lines(int $id, array $f = []): array
    {
        $size = max(1, min(self::MAX_LINES, (int) ($f['size'] ?? 50)));
        $where = match ($f['show'] ?? 'all') {
            'todo' => ' AND l.counted_qty IS NULL',
            'done' => ' AND l.counted_qty IS NOT NULL',
            default => '',
        };
        $total = (int) $this->db->value('SELECT COUNT(*) FROM count_sheet_line l WHERE l.document_id = ?' . $where, [$id]);
        $pages = max(1, intdiv($total + $size - 1, $size));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $wh = (int) ($this->db->value('SELECT warehouse_id FROM document WHERE id = ?', [$id]) ?? 0);
        $rows = $this->db->all(
            'SELECT l.*, s.code, s.name, s.brand, b.on_hand, b.allocated, b.held, CAST(m.mode AS CHAR) AS selling_mode, cu.display_name AS counted_by_name, '
            . 'ru.display_name AS reviewed_by_name, rd.number AS reversal_number '
            . 'FROM count_sheet_line l JOIN sku s ON s.id = l.sku_id LEFT JOIN stock_balance b ON b.warehouse_id = ? AND b.sku_id = l.sku_id '
            . 'LEFT JOIN item_selling_mode m ON m.sku_id = l.sku_id LEFT JOIN staff_user cu ON cu.id = l.counted_by LEFT JOIN staff_user ru ON ru.id = l.reviewed_by '
            . 'LEFT JOIN document rd ON rd.id = l.reversal_document_id '
            . 'WHERE l.document_id = ?' . $where . ' ORDER BY l.line_no LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size),
            [$wh, $id],
        );
        $codes = [];
        $skus = array_map(static fn (array $r): int => (int) $r['sku_id'], $rows);
        if ($skus !== []) {
            foreach ($this->db->all('SELECT sku_id, barcode FROM sku_barcode WHERE sku_id IN (' . implode(', ', array_fill(0, count($skus), '?')) . ') AND is_usable = 1 '
                . 'ORDER BY sku_id, units_per_scan, barcode', $skus) as $b) {
                $codes[(int) $b['sku_id']][] = (string) $b['barcode'];
            }
        }
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $out = [];
        foreach ($rows as $r) {
            $sku = (int) $r['sku_id'];
            $out[] = ['line_no' => (int) $r['line_no'], 'sku_id' => $sku, 'code' => (string) $r['code'], 'name' => (string) $r['name'],
                'brand' => $r['brand'] === null ? null : (string) $r['brand'], 'barcodes' => $codes[$sku] ?? [], 'selling_mode' => $r['selling_mode'],
                'on_hand' => (int) ($r['on_hand'] ?? 0), 'reserved' => (int) ($r['allocated'] ?? 0) + (int) ($r['held'] ?? 0), 'expected_qty' => (int) $r['expected_qty'],
                'counted_qty' => $int($r['counted_qty']), 'counted_at' => $r['counted_at'], 'counted_by_name' => $r['counted_by_name'], 'entered_how' => $r['entered_how'],
                'doc_line_no' => $int($r['doc_line_no']), 'system_qty' => $int($r['system_qty']), 'booked_delta' => $int($r['booked_delta']), 'outcome' => $r['outcome'],
                'review' => (string) $r['review'], 'reviewed_by_name' => $r['reviewed_by_name'], 'reviewed_at' => $r['reviewed_at'], 'review_note' => $r['review_note'],
                'reversal_document_id' => $int($r['reversal_document_id']), 'reversal_number' => $r['reversal_number']];
        }
        return ['rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * "Before you post": every counted line with what posting it would do now (CountPosting::plan), the biggest difference first,
     * and how many lines need the person's choice (their stock moved after they were counted), are above the tolerance, or will be
     * left out (a later count of the item exists).
     *
     * @return array{lines: list<array<string, mixed>>, needs_choice: int, over: int, stale: int, too_old: int, too_many_times: bool, window: ?int,
     *               units_limit: int, percent_limit: int}
     */
    public function differences(int $id): array
    {
        $this->sheet($id) ?? throw new CwException('not_count_sheet', 'this is not a count sheet', 404);
        $plan = CountPosting::plan($this->db, $id, ($this->clock)());
        $lines = $plan['lines'];
        usort($lines, static fn (array $a, array $b): int => [abs((int) $b['delta']), $a['line_no']] <=> [abs((int) $a['delta']), $b['line_no']]);
        $n = static fn (string $k): int => count(array_filter($lines, static fn (array $l): bool => $l[$k] === true || (is_array($l[$k]) && $l[$k] !== [])));
        return ['lines' => $lines, 'needs_choice' => $n('needs_choice'), 'over' => $n('over'), 'stale' => $n('stale'), 'too_old' => $n('too_old'),
            'too_many_times' => $plan['too_many_times'], 'window' => $plan['window'], 'units_limit' => $plan['units_limit'], 'percent_limit' => $plan['percent_limit']];
    }

    /**
     * The count records for the History list, newest first by id: at most $size older than `before`, and the id to ask the next
     * (older) page with. Filters: state (STATES), warehouse (an id), q: a record's whole number (CNT-000123), or an item (a CW number,
     * or words of its name, brand or code: the records that have it on a line).
     *
     * @param array{q?: string, state?: ?string, warehouse?: ?int, before?: ?int} $f
     * @return array{rows: list<array<string, mixed>>, next: ?int, by_item: bool}
     */
    public function list(array $f, int $size = 50): array
    {
        $where = ['d.doc_type = ?'];
        $params = [self::TYPE];
        $state = $f['state'] ?? null;
        if ($state !== null && in_array($state, self::STATES, true)) {
            $where[] = 'd.status = ?';
            $params[] = $state;
        }
        if (($f['warehouse'] ?? null) !== null) {
            $where[] = 'd.warehouse_id = ?';
            $params[] = (int) $f['warehouse'];
        }
        if (($f['before'] ?? null) !== null) {
            $where[] = 'cs.document_id < ?';
            $params[] = (int) $f['before'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        $byItem = false;
        if ($q !== '') {
            if (preg_match('/^cnt-?0*([0-9]{1,12})$/iD', $q, $m) === 1) {
                $where[] = 'd.number = ?';
                $params[] = sprintf('%s-%06d', self::TYPE, (int) $m[1]);
            } else {
                [$skuWhere, $skuParams] = StockViews::skuMatch($q, 'sku');
                $ids = array_map('intval', $this->db->column('SELECT id FROM sku WHERE ' . implode(' AND ', $skuWhere) . ' ORDER BY id LIMIT ' . StockViews::SKU_LIMIT, $skuParams));
                if ($ids === []) {
                    return ['rows' => [], 'next' => null, 'by_item' => true];
                }
                // The lines of these items by the item's index (left alone, MySQL 8.4 walked the whole (document, item) index: CT13).
                $where[] = 'cs.document_id IN (SELECT x.document_id FROM count_sheet_line x FORCE INDEX (ix_count_sheet_line_sku) WHERE x.sku_id IN ('
                    . implode(', ', array_fill(0, count($ids), '?')) . '))';
                array_push($params, ...$ids);
                $byItem = true;
            }
        }
        // The ids first (the header table backwards by its key, cut at $size + 1: CT13), then those records' details: in one statement
        // the small joined tables made MySQL sort every record.
        // With a state, the record index (doc_type, status, id) is read backwards instead (the same order: the header's key is the id).
        $order = $state !== null && in_array($state, self::STATES, true) ? 'd.id' : 'cs.document_id';
        $ids = array_map('intval', $this->db->column('SELECT cs.document_id FROM count_sheet cs JOIN document d ON d.id = cs.document_id WHERE '
            . implode(' AND ', $where) . " ORDER BY {$order} DESC LIMIT " . ($size + 1), $params));
        $rows = $ids === [] ? [] : $this->db->all(
            'SELECT d.id, d.number, d.status, d.doc_date, d.created_at, d.posted_at, d.reverses_id, d.note, CAST(cs.kind AS CHAR) AS kind, cs.reverses_line_no, w.name AS warehouse, '
            . 'l.name AS location, cb.display_name AS created_by_name, od.number AS reverses_number, ld.number AS line_of_number, '
            . '(SELECT COUNT(*) FROM count_sheet_line x WHERE x.document_id = d.id) AS lines_n, '
            . '(SELECT COUNT(*) FROM count_sheet_line x WHERE x.document_id = d.id AND x.counted_qty IS NOT NULL) AS counted_n, '
            . "(SELECT COUNT(*) FROM count_sheet_line x WHERE x.document_id = d.id AND x.review = 'open') AS open_n, "
            . '(SELECT COALESCE(SUM(ABS(x.booked_delta)), 0) FROM count_sheet_line x WHERE x.document_id = d.id) AS units_changed '
            . 'FROM count_sheet cs JOIN document d ON d.id = cs.document_id LEFT JOIN warehouse w ON w.id = d.warehouse_id LEFT JOIN warehouse_location l ON l.id = cs.location_id '
            . 'LEFT JOIN staff_user cb ON cb.id = d.created_by LEFT JOIN document od ON od.id = d.reverses_id LEFT JOIN document ld ON ld.id = cs.reverses_document_id '
            . 'WHERE cs.document_id IN (' . implode(', ', $ids) . ') ORDER BY cs.document_id DESC',
        );
        $next = null;
        if (count($rows) > $size) {
            $rows = array_slice($rows, 0, $size);
            $next = (int) $rows[$size - 1]['id'];
        }
        return ['rows' => $rows, 'next' => $next, 'by_item' => $byItem];
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * Locks a count SHEET's document row (FOR UPDATE: Documents locks it again in the same transaction) and returns it with its
     * kind. 404 not_count_sheet for anything else (another kind of record, the record that puts a line back, a cancellation).
     *
     * @return array<string, mixed>
     */
    private function lockSheet(int $id): array
    {
        $r = $this->db->one('SELECT d.id, d.doc_type, d.status, d.warehouse_id, d.created_by, CAST(cs.kind AS CHAR) AS kind FROM document d '
            . 'LEFT JOIN count_sheet cs ON cs.document_id = d.id WHERE d.id = ? FOR UPDATE OF d', [$id]);
        if ($r === null || $r['doc_type'] !== self::TYPE || $r['kind'] !== 'sheet') {
            throw new CwException('not_count_sheet', 'this is not a count sheet', 404);
        }
        return $r;
    }

    /**
     * lockSheet() for a change of a draft: the caller may count (doc.CNT.post, never admin), the sheet is still a draft (409
     * not_draft) and the caller opened it (403 not_creator).
     *
     * @return array<string, mixed>
     */
    private function lockDraft(Caller $caller, int $id): array
    {
        $row = $this->lockSheet($id);
        self::mayCount($this->db, $caller);
        if ($row['status'] !== 'draft') {
            throw new CwException('not_draft', 'this sheet is ' . str_replace('_', ' ', (string) $row['status']) . ': only a sheet that is still open is changed', 409,
                ['status' => $row['status']]);
        }
        if ($row['created_by'] === null || (int) $row['created_by'] !== $caller->staffUserId) {
            throw new CwException('not_creator', 'only the person who opened a count sheet enters and posts it', 403);
        }
        return $row;
    }

    /** A change of a draft sheet moves the record's version (a page drawn before it is refused: 409 version_conflict). */
    private function touch(int $id, string $at): void
    {
        $this->db->exec('UPDATE document SET version = version + 1, updated_at = ? WHERE id = ?', [$at, $id]);
    }

    /** @param list<int> $skuIds each exists and is not merged (422 unknown_sku / merged_item) */
    private function checkItems(array $skuIds): void
    {
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $found = [];
            foreach ($this->db->all('SELECT id, merged_into_sku_id FROM sku WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $s) {
                $found[(int) $s['id']] = $s['merged_into_sku_id'];
            }
            foreach ($chunk as $sku) {
                if (!array_key_exists($sku, $found)) {
                    throw new CwException('unknown_sku', "there is no item {$sku}", 422, ['sku_id' => $sku]);
                }
                if ($found[$sku] !== null) {
                    throw new CwException('merged_item', "item {$sku} was merged into another one: count that one", 422, ['sku_id' => $sku, 'merged_into' => (int) $found[$sku]]);
                }
            }
        }
    }

    /**
     * @param list<int> $skuIds
     * @return array<int, int> item => on hand at the warehouse (absent: no figure, which is 0)
     */
    private function onHand(int $warehouseId, array $skuIds): array
    {
        $out = [];
        foreach (array_chunk($skuIds, 500) as $chunk) {
            foreach ($this->db->all('SELECT sku_id, on_hand FROM stock_balance WHERE warehouse_id = ? AND sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$warehouseId, ...$chunk]) as $b) {
                $out[(int) $b['sku_id']] = (int) $b['on_hand'];
            }
        }
        return $out;
    }

    /** 403 unless the caller (a person, never admin) may post stock counts (roles re-read in this transaction). */
    public static function mayCount(Db $db, Caller $caller): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'stock is counted by staff', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (!Documents::mayPost($roles, self::TYPE)) {
            throw new CwException(in_array('admin', $roles, true) ? 'admin_cannot_post' : 'role_not_allowed', 'you cannot count stock or clear count differences', 403);
        }
    }

    private static function id(mixed $v, string $field): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ((is_int($v) && $v > 0) || (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1)) {
            return (int) $v;
        }
        throw new CwException('bad_field', "{$field} is a warehouse or place of the list", 400, ['field' => $field]);
    }

    /** A trimmed text of at most $max characters, or null (absent or blank). 400 bad_field. */
    private static function text(mixed $v, int $max, string $field): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1) {
            throw new CwException('bad_field', "{$field} must be text", 400, ['field' => $field]);
        }
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));
        if (mb_strlen($v) > $max) {
            throw new CwException('bad_field', "{$field} is at most {$max} characters", 400, ['field' => $field]);
        }
        return $v === '' ? null : $v;
    }
}
