<?php

declare(strict_types=1);

namespace CW\StockOps;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Matching\Gtin;
use CW\Movements;
use CW\Staff\StaffRoles;
use CW\Ui\Queries;

/**
 * The stock records of pack A1 (docs/decisions.md SO1-SO16): Stock In (SIN), Stock Out (SOUT), Adjustments (ADJ, write-offs included),
 * Transfers (TRF) and Releases from another account's warehouse (REL), on top of the document base (Documents: draft -> post (or a
 * reviewer's OK first, when a rule asks) -> reverse; never deleted) and their handler (StockOpHandler, which books the stock).
 *
 * A record is a `document` (its type, the warehouse it starts from, the reason of a stock in or out, a reference, its date, a note)
 * with a `stock_op` header (the optional place inside the warehouse, where a transfer or a release goes, who a stock out was given
 * to) and its lines (a product and a quantity in units; a unit cost on a stock in, the agreed price on a release; a reason on each
 * adjustment line). Places are optional everywhere (Q2).
 *
 * Every write: a staff caller allowed to post that type (doc.<TYPE>.post, never admin; roles re-read inside the transaction by
 * Documents), ONE transaction (joining an open one), the document row locked and its version checked by Documents, a draft changed
 * only by its creator. The posting's rules are the handler's (StockOpHandler::validate): this service refuses early what it can see
 * when a draft is saved, with the same codes.
 */
final class StockOps
{
    public const KINDS = StockOpHandler::KINDS;
    /** kind => its page (the Stock tabs). */
    public const PATHS = ['in' => '/ui/stock/in', 'out' => '/ui/stock/out', 'adjust' => '/ui/stock/adjustments', 'transfer' => '/ui/stock/transfers',
        'release' => '/ui/stock/releases'];
    /** kind => where its reasons are offered (reason_code.applies_to); a transfer and a release carry none. */
    public const REASON_USE = ['in' => 'stock_in', 'out' => 'stock_out', 'adjust' => 'adjustment'];
    public const MAX_LINES = 500;
    public const GIVEN_TO_MAX = 100;
    public const REF_MAX = 100;
    public const NOTE_MAX = 1000;
    public const SEARCH_LIMIT = 20;
    /** The most rows a list shows (newest first; the filters narrow it). */
    public const LIST_LIMIT = 200;
    /** Attachment role => the stored file's kind (FileStore::KINDS): a delivery note, an invoice, a photo, other evidence. */
    public const FILE_ROLES = ['delivery_note' => 'delivery_note', 'supplier_invoice' => 'supplier_invoice', 'photo' => 'photo', 'evidence' => 'other'];
    public const STATES = ['draft', 'awaiting_approval', 'posted', 'reversed', 'cancelled'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): FileStore)|null $files the file store (attach(): 503 file_store_unconfigured without one)
     * @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (the default date of a record)
     */
    public function __construct(private readonly Db $db, private readonly Documents $docs, private readonly ?\Closure $files = null, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /** The kind of a document type ('SIN' => 'in'), or null. */
    public static function kindOf(string $type): ?string
    {
        $k = array_search($type, self::KINDS, true);
        return $k === false ? null : $k;
    }

    // ------------------------------------------------------------------------------------------
    // Drafts
    // ------------------------------------------------------------------------------------------

    /**
     * A new draft of $kind. Header (all optional but the warehouse): warehouse, location, to_warehouse, to_location (ids), reason_code,
     * given_to, external_ref, doc_date (Y-m-d, default today in the UK), note. 400 unknown_kind; the place and owner rules
     * (StockOpHandler::checkPlacesOf) as far as the header says; Documents' refusals (role, reason). Audit stockop.create.
     *
     * @param array<string, mixed> $header
     */
    public function createDraft(Caller $caller, string $kind, array $header): Document
    {
        $type = self::KINDS[$kind] ?? throw new CwException('unknown_kind', 'there is no such kind of stock record', 400);
        return $this->db->transaction(function (Db $db) use ($caller, $kind, $type, $header): Document {
            self::mayPost($db, $caller, $type); // who first: the header's refusals are for someone who may keep the record
            $h = $this->header($kind, $header, null);
            if ($h['warehouse_id'] === null) {
                throw new CwException('warehouse_required', 'say which warehouse', 422, ['field' => 'warehouse']);
            }
            $doc = $this->docs->createDraft($caller, $type, $this->docHeader($h));
            $now = Clock::db(($this->clock)());
            $db->exec('INSERT INTO stock_op (document_id, kind, location_id, to_warehouse_id, to_location_id, given_to, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$doc->id, $kind, $h['location_id'], $h['to_warehouse_id'], $h['to_location_id'], $h['given_to'], $now, $now]);
            Audit::write($db, $caller, 'stockop.create', 'document', (string) $doc->id, null, ['kind' => $kind, 'type' => $type]
                + array_filter(['location' => $h['location_id'], 'to_warehouse' => $h['to_warehouse_id'], 'to_location' => $h['to_location_id'],
                    'given_to' => $h['given_to']], static fn (mixed $v): bool => $v !== null));
            return $doc;
        });
    }

    /**
     * Changes a draft's header (the keys given; '' or null clears an optional one). Creator only (Documents::updateDraft). Version + 1.
     *
     * @param array<string, mixed> $header
     */
    public function saveHeader(Caller $caller, int $id, int $version, array $header): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $header): Document {
            [$kind, $current] = $this->current($id);
            self::mayPost($db, $caller, self::KINDS[$kind]);
            $h = $this->header($kind, $header, $current);
            if ($h['warehouse_id'] === null) {
                throw new CwException('warehouse_required', 'say which warehouse', 422, ['field' => 'warehouse']);
            }
            $doc = $this->docs->updateDraft($caller, $id, $version, $this->docHeader($h));
            $db->exec('UPDATE stock_op SET location_id = ?, to_warehouse_id = ?, to_location_id = ?, given_to = ?, updated_at = ? WHERE document_id = ?',
                [$h['location_id'], $h['to_warehouse_id'], $h['to_location_id'], $h['given_to'], Clock::db(($this->clock)()), $id]);
            Audit::write($db, $caller, 'stockop.header', 'document', (string) $id, null, ['version' => $doc->version]
                + array_filter(['location' => $h['location_id'], 'to_warehouse' => $h['to_warehouse_id'], 'to_location' => $h['to_location_id'],
                    'given_to' => $h['given_to']], static fn (mixed $v): bool => $v !== null));
            return $doc;
        });
    }

    /**
     * Replaces every line of a draft: [{sku_id, qty, unit_cost?, reason_code?, description?}]. A quantity in units: above zero, but
     * signed on an adjustment (+ found, - lost); a unit cost only on a stock in or an adjustment's + line, the agreed price on a release;
     * a reason only on an adjustment's lines (a stock in or out has the header's). At most MAX_LINES lines. Creator only. Version + 1.
     *
     * @param list<array<string, mixed>> $lines
     */
    public function saveLines(Caller $caller, int $id, int $version, array $lines): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version, $lines): Document {
            [$kind] = $this->current($id);
            return $this->docs->setLines($caller, $id, $version, $this->normaliseLines($kind, $lines));
        });
    }

    /**
     * Adds what was scanned or typed (resolve()): a barcode (a case barcode counts the units it stands for), a CW number, or words of
     * the name (`choices` when several products match, `not_found` when none). The same product (and, on an adjustment, the same
     * reason and direction; the same price) already on the record gets the units added (`incremented`); else a new line (`added`). A
     * release's line without a price takes the suggested one (CostHints::suggested). Saves the draft (version + 1).
     *
     * @return array{status: string, document: Document, line_no?: int, units?: int, sku_id?: int, choices?: list<array<string, mixed>>, q: string}
     */
    public function addLine(Caller $caller, int $id, int $version, string $q, int $qty, ?string $cost = null, ?string $reason = null): array
    {
        $r = $this->resolve($q);
        if ($r['status'] !== 'found') {
            return $r + ['document' => $this->docs->get($id), 'q' => trim($q)];
        }
        return $this->addItem($caller, $id, $version, (int) $r['sku_id'], $qty * (int) $r['units'], $cost, $reason) + ['q' => trim($q)];
    }

    /**
     * Adds a product chosen from the list (addLine's `choices`), as addLine() adds a found one.
     *
     * @return array{status: string, document: Document, line_no: int, units: int, sku_id: int}
     */
    public function addItem(Caller $caller, int $id, int $version, int $skuId, int $qty, ?string $cost = null, ?string $reason = null): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $skuId, $qty, $cost, $reason): array {
            [$kind] = $this->current($id);
            if ($qty === 0 || abs($qty) > Documents::MAX_QTY || ($kind !== 'adjust' && $qty < 0)) {
                throw new CwException($kind === 'adjust' ? 'qty_nonzero' : 'qty_positive', 'the quantity is a whole number of units'
                    . ($kind === 'adjust' ? ' (+ in, - out), not 0' : ' above zero'), 422, ['field' => 'qty']);
            }
            $sku = $db->one('SELECT id, merged_into_sku_id FROM sku WHERE id = ?', [$skuId]) ?? throw new CwException('unknown_sku', 'there is no such product', 422);
            if ($sku['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', 'this product was joined into another one: use that one', 422, ['merged_into' => (int) $sku['merged_into_sku_id']]);
            }
            $cost = $cost === null || trim($cost) === '' ? null : Movements::normaliseCost(trim($cost), 'unit_cost');
            if ($kind === 'release' && $cost === null) {
                $cost = (new CostHints($db))->suggested([$skuId])[$skuId]['price'] ?? null;
            }
            $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
            $lines = $this->lineRows($id);
            $status = 'added';
            $lineNo = count($lines) + 1;
            foreach ($lines as $i => $l) {
                if ((int) $l['sku_id'] === $skuId && $l['reason_code'] === $reason && ($l['qty'] < 0) === ($qty < 0)
                    && ($cost === null || $l['unit_cost'] === null || bccomp((string) $l['unit_cost'], $cost, 6) === 0)) {
                    $lines[$i]['qty'] = $l['qty'] + $qty;
                    $lines[$i]['unit_cost'] ??= $cost;
                    $status = 'incremented';
                    $lineNo = $i + 1;
                    break;
                }
            }
            if ($status === 'added') {
                if (count($lines) >= self::MAX_LINES) {
                    throw new CwException('too_many_lines', 'a stock record has at most ' . self::MAX_LINES . ' lines: start another one', 422);
                }
                $lines[] = ['sku_id' => $skuId, 'qty' => $qty, 'unit_cost' => $cost, 'reason_code' => $reason, 'description' => null];
            }
            $doc = $this->docs->setLines($caller, $id, $version, $this->normaliseLines($kind, $lines));
            return ['status' => $status, 'document' => $doc, 'line_no' => $lineNo, 'units' => $qty, 'sku_id' => $skuId];
        });
    }

    /** Posts a draft (Documents::post: a reviewer's OK first when a rule asks; StockOpHandler books it). */
    public function post(Caller $caller, int $id, int $version): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version): Document {
            $this->current($id);
            return $this->docs->post($caller, $id, $version);
        });
    }

    /** Stops a draft before it is final ($reason 3-500 characters; Documents::cancelDraft). */
    public function cancel(Caller $caller, int $id, int $version, string $reason): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version, $reason): Document {
            $this->current($id);
            return $this->docs->cancelDraft($caller, $id, $version, $reason);
        });
    }

    /**
     * Cancels a final record by a new record that puts everything back (Documents::reverse; its reason is one offered for
     * cancellations). A cancellation that puts more stock back than the "stock put back without a supplier document" limit waits for a
     * reviewer's OK (I32). Returns the cancellation record.
     */
    public function reverse(Caller $caller, int $id, string $reasonCode, ?string $note): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $reasonCode, $note): Document {
            $this->kindOfDocument($id) ?? throw new CwException('not_stock_record', 'this is not a stock record', 404);
            return $this->docs->reverse($caller, $id, $reasonCode, $note);
        });
    }

    /** The requester takes back their record waiting for a reviewer's OK: it is a draft again (Documents::withdraw). */
    public function withdraw(Caller $caller, int $id): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id): Document {
            $this->kindOfDocument($id) ?? throw new CwException('not_stock_record', 'this is not a stock record', 404);
            $task = $db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND kind = 'approval' AND state = 'open'", [$id])
                ?? throw new CwException('not_withdrawable', 'only a record waiting for a reviewer\'s OK is taken back', 409);
            return $this->docs->withdraw($caller, (int) $task);
        });
    }

    /**
     * Stores a file (FileStore: type sniffed, deduplicated, kept 7 years) and attaches it under $role (FILE_ROLES): a delivery note or
     * an invoice (on a stock in it is the supplier document that keeps it from waiting for an OK), a photo, other evidence. Any record
     * but a stopped draft. Someone who may post records of its type.
     *
     * @return array{file_id: int, mime: string, attached: bool, deduped: bool}
     */
    public function attach(Caller $caller, int $id, string $path, string $name, string $role): array
    {
        if (!isset(self::FILE_ROLES[$role])) {
            throw new CwException('bad_file_role', 'attach the file as ' . implode(', ', array_keys(self::FILE_ROLES)), 400, ['field' => 'role']);
        }
        $files = $this->files === null ? throw new CwException('file_store_unconfigured', 'the file store is not set up on this server', 503) : ($this->files)();
        return $this->db->transaction(function (Db $db) use ($caller, $id, $path, $name, $role, $files): array {
            $row = $db->one('SELECT id, doc_type, status FROM document WHERE id = ? FOR UPDATE', [$id]) ?? throw new CwException('unknown_document', 'there is no such record', 404);
            if (self::kindOf((string) $row['doc_type']) === null) {
                throw new CwException('not_stock_record', 'this is not a stock record', 404);
            }
            self::mayPost($db, $caller, (string) $row['doc_type']);
            if ($row['status'] === 'cancelled') {
                throw new CwException('document_cancelled', 'a stopped record takes no files', 409);
            }
            $stored = $files->store($caller, $path, $name, self::FILE_ROLES[$role], null);
            $attached = $files->attach($caller, $id, $stored['id'], $role === 'evidence' ? 'evidence' : $role);
            return ['file_id' => $stored['id'], 'mime' => $stored['mime'], 'attached' => $attached, 'deduped' => $stored['deduped']];
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    /** The kind of a stock record ('in', ...), or null for any other document. */
    public function kindOfDocument(int $id): ?string
    {
        $t = $this->db->value('SELECT doc_type FROM document WHERE id = ?', [$id]);
        return $t === null ? null : self::kindOf((string) $t);
    }

    /**
     * Everything a record's page shows about its header: the document row, its kind, the names of its warehouses and places, its
     * reason (label and rules), who it was given to, the people, the record it cancels and the one that cancels it.
     *
     * @return array<string, mixed>|null
     */
    public function record(int $id): ?array
    {
        $r = $this->db->one(
            'SELECT d.*, op.kind, op.location_id, op.to_warehouse_id, op.to_location_id, op.given_to, op.account_name, w.code AS warehouse_code, w.name AS warehouse_name, '
            . 'CAST(w.stock_owner AS CHAR) AS warehouse_owner, w.owner_entity AS warehouse_entity, tw.code AS to_warehouse_code, tw.name AS to_warehouse_name, '
            . 'l.name AS location_name, tl.name AS to_location_name, r.label AS reason_label, r.needs_given_to, r.below_zero, '
            . 'cb.display_name AS created_by_name, sb.display_name AS submitted_by_name, pb.display_name AS posted_by_name, xb.display_name AS cancelled_by_name, '
            . 'od.number AS reverses_number, rv.id AS reversed_by_id, rv.number AS reversed_by_number, rv.status AS reversed_by_status '
            . 'FROM document d LEFT JOIN stock_op op ON op.document_id = d.id LEFT JOIN warehouse w ON w.id = d.warehouse_id LEFT JOIN warehouse tw ON tw.id = op.to_warehouse_id '
            . 'LEFT JOIN warehouse_location l ON l.id = op.location_id LEFT JOIN warehouse_location tl ON tl.id = op.to_location_id LEFT JOIN reason_code r ON r.code = d.reason_code '
            . 'LEFT JOIN staff_user cb ON cb.id = d.created_by LEFT JOIN staff_user sb ON sb.id = d.submitted_by LEFT JOIN staff_user pb ON pb.id = d.posted_by '
            . 'LEFT JOIN staff_user xb ON xb.id = d.cancelled_by LEFT JOIN document od ON od.id = d.reverses_id '
            . "LEFT JOIN document rv ON rv.reverses_id = d.id AND rv.status IN ('posted', 'awaiting_approval') WHERE d.id = ?",
            [$id],
        );
        if ($r === null || self::kindOf((string) $r['doc_type']) === null) {
            return null;
        }
        $r['kind'] = self::kindOf((string) $r['doc_type']);
        return $r;
    }

    /**
     * A record's lines as its page shows them: line_no, sku_id, code, name, brand, qty, unit_cost, reason_code, reason_label,
     * description, amount (qty x price on a release, qty x cost on a stock in), the average cost so far and the value at it (an
     * estimate), and the stock now at the record's warehouse (on hand, reserved, available).
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $id): array
    {
        $doc = $this->db->one('SELECT warehouse_id FROM document WHERE id = ?', [$id]);
        $rows = $this->db->all(
            'SELECT l.line_no, l.sku_id, s.code, s.name, s.brand, CAST(s.sell_policy AS CHAR) AS sell_policy, l.qty, l.unit_cost, l.reason_code, r.label AS reason_label, '
            . 'l.description FROM document_line l LEFT JOIN sku s ON s.id = l.sku_id LEFT JOIN reason_code r ON r.code = l.reason_code WHERE l.document_id = ? ORDER BY l.line_no',
            [$id],
        );
        $skus = array_values(array_unique(array_filter(array_map(static fn (array $l): ?int => $l['sku_id'] === null ? null : (int) $l['sku_id'], $rows))));
        $avg = $skus === [] ? [] : (new CostHints($this->db))->average($skus);
        $stock = [];
        if ($skus !== [] && $doc !== null && $doc['warehouse_id'] !== null) {
            foreach ($this->db->all('SELECT sku_id, on_hand, allocated, held FROM stock_balance WHERE warehouse_id = ? AND sku_id IN ('
                . implode(', ', array_fill(0, count($skus), '?')) . ')', [(int) $doc['warehouse_id'], ...$skus]) as $b) {
                $stock[(int) $b['sku_id']] = ['on_hand' => (int) $b['on_hand'], 'reserved' => (int) $b['allocated'] + (int) $b['held'],
                    'available' => (int) $b['on_hand'] - (int) $b['allocated'] - (int) $b['held']];
            }
        }
        $out = [];
        foreach ($rows as $l) {
            $sku = $l['sku_id'] === null ? null : (int) $l['sku_id'];
            $qty = (int) $l['qty'];
            $a = $sku === null ? null : ($avg[$sku] ?? null);
            $out[] = ['line_no' => (int) $l['line_no'], 'sku_id' => $sku, 'code' => (string) ($l['code'] ?? ''), 'name' => (string) ($l['name'] ?? ($l['description'] ?? '')),
                'brand' => $l['brand'] === null ? null : (string) $l['brand'], 'sell_policy' => (string) ($l['sell_policy'] ?? 'legacy'), 'qty' => $qty,
                'unit_cost' => $l['unit_cost'] === null ? null : (string) $l['unit_cost'], 'reason_code' => $l['reason_code'] === null ? null : (string) $l['reason_code'],
                'reason_label' => $l['reason_label'] === null ? null : (string) $l['reason_label'], 'description' => $l['description'] === null ? null : (string) $l['description'],
                'amount' => $l['unit_cost'] === null ? null : CostHints::amount($qty, (string) $l['unit_cost']),
                'average' => $a, 'value' => $a === null ? null : CostHints::amount($qty, $a),
                'stock' => $sku === null ? null : ($stock[$sku] ?? ['on_hand' => 0, 'reserved' => 0, 'available' => 0])];
        }
        return $out;
    }

    /**
     * The records of $kind for its list (newest first, at most LIST_LIMIT): q (a number, reference, note, "given to" or the other
     * account's name), state (STATES), warehouse (an id: from or to), cancelled (show the stopped drafts too).
     *
     * @param array{q?: string, state?: ?string, warehouse?: ?int, cancelled?: bool} $f
     * @return list<array<string, mixed>>
     */
    public function list(string $kind, array $f): array
    {
        $type = self::KINDS[$kind] ?? throw new \InvalidArgumentException("unknown kind {$kind}");
        $where = ['d.doc_type = ?'];
        $params = [$type];
        $state = $f['state'] ?? null;
        if ($state !== null && in_array($state, self::STATES, true)) {
            $where[] = 'd.status = ?';
            $params[] = $state;
        } elseif (!($f['cancelled'] ?? false)) {
            $where[] = "d.status <> 'cancelled'";
        }
        if (($f['warehouse'] ?? null) !== null) {
            $where[] = '(d.warehouse_id = ? OR op.to_warehouse_id = ?)';
            array_push($params, (int) $f['warehouse'], (int) $f['warehouse']);
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ? OR d.note LIKE ? OR op.given_to LIKE ? OR op.account_name LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return $this->db->all(
            'SELECT d.id, d.number, d.status, d.review_state, d.doc_date, d.created_at, d.posted_at, d.reverses_id, d.external_ref, d.note, d.reason_code, '
            . 'r.label AS reason_label, w.name AS warehouse, tw.name AS to_warehouse, l.name AS location, tl.name AS to_location, op.given_to, op.account_name, '
            . 'w.owner_entity AS warehouse_entity, cb.display_name AS created_by_name, od.number AS reverses_number, '
            . '(SELECT COUNT(DISTINCT x.sku_id) FROM document_line x WHERE x.document_id = d.id) AS products, '
            . '(SELECT COALESCE(SUM(ABS(x.qty)), 0) FROM document_line x WHERE x.document_id = d.id) AS units, '
            . '(SELECT SUM(ROUND(x.qty * x.unit_cost, 2)) FROM document_line x WHERE x.document_id = d.id AND x.unit_cost IS NOT NULL) AS priced, '
            . "(SELECT t.kind FROM review_task t WHERE t.subject_type = 'document' AND t.subject_id = d.id AND t.state = 'open' ORDER BY t.id LIMIT 1) AS open_task "
            . 'FROM document d LEFT JOIN stock_op op ON op.document_id = d.id LEFT JOIN warehouse w ON w.id = d.warehouse_id '
            . 'LEFT JOIN warehouse tw ON tw.id = op.to_warehouse_id LEFT JOIN warehouse_location l ON l.id = op.location_id '
            . 'LEFT JOIN warehouse_location tl ON tl.id = op.to_location_id LEFT JOIN reason_code r ON r.code = d.reason_code '
            . 'LEFT JOIN staff_user cb ON cb.id = d.created_by LEFT JOIN document od ON od.id = d.reverses_id '
            . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . self::LIST_LIMIT,
            $params,
        );
    }

    /**
     * What a form offers: every switched-on warehouse (id, code, name, whose stock, the other account's name, sellable) with its
     * switched-on places, and the reasons offered for the kind (code, label, direction, needs a note, needs "given to", may go below
     * zero), in the Reasons page's order.
     *
     * @return array{warehouses: list<array<string, mixed>>, reasons: list<array<string, mixed>>}
     */
    public function formChoices(string $kind): array
    {
        $places = [];
        foreach ($this->db->all('SELECT id, warehouse_id, code, name FROM warehouse_location WHERE is_active = 1 ORDER BY code') as $p) {
            $places[(int) $p['warehouse_id']][] = ['id' => (int) $p['id'], 'code' => (string) $p['code'], 'name' => (string) $p['name']];
        }
        $warehouses = [];
        foreach ($this->db->all('SELECT id, code, name, CAST(stock_owner AS CHAR) AS stock_owner, owner_entity, is_sellable FROM warehouse WHERE is_active = 1 ORDER BY sort_order, id') as $w) {
            $warehouses[] = ['id' => (int) $w['id'], 'code' => (string) $w['code'], 'name' => (string) $w['name'], 'own' => $w['stock_owner'] === 'own',
                'entity' => $w['owner_entity'] === null ? null : (string) $w['owner_entity'], 'sellable' => (int) $w['is_sellable'] === 1,
                'places' => $places[(int) $w['id']] ?? []];
        }
        $reasons = [];
        $use = self::REASON_USE[$kind] ?? null;
        if ($use !== null) {
            foreach ($this->db->all('SELECT code, label, CAST(direction AS CHAR) AS direction, needs_note, needs_given_to, below_zero FROM reason_code '
                . 'WHERE FIND_IN_SET(?, applies_to) > 0 AND is_active = 1 AND system_only = 0 ORDER BY sort_order, code', [$use]) as $r) {
                if (($kind === 'in' && $r['direction'] === 'decrease') || ($kind === 'out' && $r['direction'] === 'increase')) {
                    continue;
                }
                $reasons[] = ['code' => (string) $r['code'], 'label' => (string) $r['label'], 'direction' => (string) $r['direction'],
                    'needs_note' => (int) $r['needs_note'] === 1, 'needs_given_to' => (int) $r['needs_given_to'] === 1, 'below_zero' => (int) $r['below_zero'] === 1];
            }
        }
        return ['warehouses' => $warehouses, 'reasons' => $reasons];
    }

    /**
     * Resolves what was scanned or typed: a usable barcode of a product (`units`: what the barcode stands for, 1 for a unit's own, k
     * for an outer case), a CW number (CW-000123, or the number alone when it is no barcode), else the products whose name, brand or range has every word (`choices`, at most SEARCH_LIMIT),
     * else `not_found`.
     *
     * @return array{status: string, sku_id?: int, units?: int, choices?: list<array{sku_id: int, code: string, name: string, brand: ?string}>}
     */
    public function resolve(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return ['status' => 'not_found'];
        }
        if (preg_match('/^[0-9]{6,64}$/D', $q) === 1) {
            $b = $this->db->one('SELECT b.sku_id, b.units_per_scan FROM sku_barcode b JOIN sku s ON s.id = b.sku_id WHERE b.barcode IN (?, ?) AND b.is_usable = 1 '
                . 'AND s.merged_into_sku_id IS NULL ORDER BY b.barcode = ? DESC LIMIT 1', [$q, Gtin::key($q) ?? $q, $q]);
            if ($b !== null) {
                return ['status' => 'found', 'sku_id' => (int) $b['sku_id'], 'units' => max(1, (int) $b['units_per_scan'])];
            }
        }
        if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $q, $m) === 1) {
            $id = $this->db->value('SELECT id FROM sku WHERE id = ? AND merged_into_sku_id IS NULL', [(int) $m[1]]);
            if ($id !== null) {
                return ['status' => 'found', 'sku_id' => (int) $id, 'units' => 1];
            }
        }
        $found = (new Queries($this->db))->searchSkus($q, self::SEARCH_LIMIT);
        if ($found === []) {
            return ['status' => 'not_found'];
        }
        return ['status' => 'choices', 'choices' => array_map(static fn (array $s): array => ['sku_id' => (int) $s['id'], 'code' => (string) $s['code'],
            'name' => (string) $s['name'], 'brand' => $s['brand'] === null ? null : (string) $s['brand']], $found)];
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * A draft's kind and its header as stored (stock_op + document), locked (FOR UPDATE: Documents locks it again in the same
     * transaction). 404 for a document that is not a stock record.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function current(int $id): array
    {
        $r = $this->db->one('SELECT d.doc_type, d.warehouse_id, d.reason_code, d.external_ref, d.doc_date, d.note, op.location_id, op.to_warehouse_id, '
            . 'op.to_location_id, op.given_to FROM document d LEFT JOIN stock_op op ON op.document_id = d.id WHERE d.id = ? FOR UPDATE OF d', [$id])
            ?? throw new CwException('unknown_document', 'there is no such record', 404);
        $kind = self::kindOf((string) $r['doc_type']) ?? throw new CwException('not_stock_record', 'this is not a stock record', 404);
        return [$kind, $r];
    }

    /**
     * A header as the record will hold it: the given keys over the current values (or the defaults of a new draft), each checked.
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $current
     * @return array{warehouse_id: ?int, location_id: ?int, to_warehouse_id: ?int, to_location_id: ?int, reason_code: ?string, given_to: ?string,
     *               external_ref: ?string, doc_date: ?string, note: ?string}
     */
    private function header(string $kind, array $in, ?array $current): array
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $h = [
            'warehouse_id' => $int($current['warehouse_id'] ?? null), 'location_id' => $int($current['location_id'] ?? null),
            'to_warehouse_id' => $int($current['to_warehouse_id'] ?? null), 'to_location_id' => $int($current['to_location_id'] ?? null),
            'reason_code' => $current['reason_code'] ?? null, 'given_to' => $current['given_to'] ?? null, 'external_ref' => $current['external_ref'] ?? null,
            'doc_date' => $current['doc_date'] ?? ($current === null ? ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d') : null),
            'note' => $current['note'] ?? null,
        ];
        foreach (['warehouse' => 'warehouse_id', 'location' => 'location_id', 'to_warehouse' => 'to_warehouse_id', 'to_location' => 'to_location_id'] as $k => $col) {
            if (array_key_exists($k, $in)) {
                $v = $in[$k];
                if ($v === null || $v === '') {
                    $h[$col] = null;
                } elseif ((is_int($v) && $v > 0) || (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1)) {
                    $h[$col] = (int) $v;
                } else {
                    throw new CwException('bad_field', "{$k} is a warehouse or place of the list", 400, ['field' => $k]);
                }
            }
        }
        if (!in_array($kind, ['transfer', 'release'], true)) {
            $h['to_warehouse_id'] = null;
            $h['to_location_id'] = null;
        }
        if ($h['to_warehouse_id'] === null) {
            $h['to_location_id'] = null;
        }
        if (array_key_exists('reason_code', $in)) {
            $h['reason_code'] = is_string($in['reason_code']) && trim($in['reason_code']) !== '' ? trim($in['reason_code']) : null;
        }
        if (!in_array($kind, ['in', 'out'], true)) {
            $h['reason_code'] = null; // an adjustment's reasons are on its lines; a transfer and a release carry none
        }
        if (array_key_exists('given_to', $in)) {
            $h['given_to'] = self::text($in['given_to'], self::GIVEN_TO_MAX, 'given_to', 2);
        }
        if (array_key_exists('external_ref', $in)) {
            $h['external_ref'] = self::text($in['external_ref'], self::REF_MAX, 'external_ref', 1);
        }
        if (array_key_exists('note', $in)) {
            $h['note'] = self::text($in['note'], self::NOTE_MAX, 'note', 1);
        }
        if (array_key_exists('doc_date', $in)) {
            $d = $in['doc_date'];
            $h['doc_date'] = $d === null || $d === '' ? $h['doc_date'] : (string) $d;
        }
        if ($h['warehouse_id'] !== null) {
            StockOpHandler::checkPlacesOf($this->db, $kind, $h['warehouse_id'], $h['location_id'], $h['to_warehouse_id'], $h['to_location_id'], false);
        }
        return $h;
    }

    /**
     * The document header of $h (Documents' fields: the warehouse by code).
     *
     * @param array<string, mixed> $h
     * @return array<string, mixed>
     */
    private function docHeader(array $h): array
    {
        $code = $h['warehouse_id'] === null ? null : $this->db->value('SELECT code FROM warehouse WHERE id = ?', [$h['warehouse_id']]);
        return ['warehouse' => $code === null ? null : (string) $code, 'reason_code' => $h['reason_code'], 'external_ref' => $h['external_ref'],
            'doc_date' => $h['doc_date'], 'note' => $h['note']];
    }

    /**
     * Checks a record's lines for its kind and returns them as Documents::setLines takes them.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array{sku_id: int, qty: int, unit_cost?: string, reason_code?: string, description?: string}>
     */
    private function normaliseLines(string $kind, array $lines): array
    {
        if (!array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new CwException('too_many_lines', 'a stock record has at most ' . self::MAX_LINES . ' lines: start another one', 422);
        }
        $out = [];
        foreach ($lines as $i => $l) {
            $no = $i + 1;
            $sku = $l['sku_id'] ?? null;
            if (!is_int($sku) || $sku <= 0) {
                throw new CwException('bad_line', "line {$no}: a stock record's line names a product", 422, ['line' => $no]);
            }
            $qty = $l['qty'] ?? null;
            if (!is_int($qty) || $qty === 0 || abs($qty) > Documents::MAX_QTY || ($kind !== 'adjust' && $qty < 0)) {
                throw new CwException($kind === 'adjust' ? 'qty_nonzero' : 'qty_positive', "line {$no}: the quantity is a whole number of units"
                    . ($kind === 'adjust' ? ' (+ in, - out), not 0' : ' above zero'), 422, ['line' => $no, 'field' => 'qty']);
            }
            $n = ['sku_id' => $sku, 'qty' => $qty];
            $cost = $l['unit_cost'] ?? null;
            $cost = $cost === null || (is_string($cost) && trim($cost) === '') ? null : (is_string($cost) ? trim($cost) : $cost);
            if ($cost !== null) {
                if (!in_array($kind, ['in', 'release', 'adjust'], true) || ($kind === 'adjust' && $qty < 0)) {
                    throw new CwException('cost_not_allowed', "line {$no}: stock that goes out is valued at the average cost: it carries no cost of its own", 422,
                        ['line' => $no, 'field' => 'unit_cost']);
                }
                $n['unit_cost'] = Movements::normaliseCost($cost, "lines[{$no}].unit_cost");
            }
            $reason = $l['reason_code'] ?? null;
            if ($reason !== null && $reason !== '') {
                if ($kind !== 'adjust') {
                    throw new CwException('reason_not_applicable', "line {$no}: only an adjustment's lines carry a reason", 422, ['line' => $no]);
                }
                $n['reason_code'] = (string) $reason;
            }
            $desc = self::text($l['description'] ?? null, 255, "lines[{$no}].description", 1);
            if ($desc !== null) {
                $n['description'] = $desc;
            }
            $out[] = $n;
        }
        return $out;
    }

    /** @return list<array{sku_id: int, qty: int, unit_cost: ?string, reason_code: ?string, description: ?string}> a draft's lines as saveLines() takes them */
    private function lineRows(int $id): array
    {
        return array_map(static fn (array $l): array => ['sku_id' => (int) $l['sku_id'], 'qty' => (int) $l['qty'],
            'unit_cost' => $l['unit_cost'] === null ? null : (string) $l['unit_cost'], 'reason_code' => $l['reason_code'] === null ? null : (string) $l['reason_code'],
            'description' => $l['description'] === null ? null : (string) $l['description']],
            $this->db->all('SELECT sku_id, qty, unit_cost, reason_code, description FROM document_line WHERE document_id = ? AND sku_id IS NOT NULL ORDER BY line_no', [$id]));
    }

    /** 403 unless the caller (a person, never admin) may post records of $type (roles re-read in this transaction). */
    private static function mayPost(Db $db, Caller $caller, string $type): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'stock records are kept by staff', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (!Documents::mayPost($roles, $type)) {
            throw new CwException(in_array('admin', $roles, true) ? 'admin_cannot_post' : 'role_not_allowed', "you cannot keep {$type} records", 403);
        }
    }

    /** A trimmed text of $min to $max characters, or null (absent or blank). 400 bad_field. */
    private static function text(mixed $v, int $max, string $field, int $min): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1) {
            throw new CwException('bad_field', "{$field} must be text", 400, ['field' => $field]);
        }
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) < $min || mb_strlen($v) > $max) {
            throw new CwException('bad_field', "{$field} is {$min} to {$max} characters", 400, ['field' => $field]);
        }
        return $v;
    }
}
