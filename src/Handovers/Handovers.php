<?php

declare(strict_types=1);

namespace CW\Handovers;

use CW\Audit;
use CW\Caller;
use CW\Clock;
use CW\Company\CompanyDetails;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\Documents;
use CW\Movements;
use CW\Settings;
use CW\Staff\StaffRoles;
use CW\StockOps\CostHints;
use CW\StockOps\StockOps;

/**
 * Hand-overs (pack H, IM11; docs/decisions.md PH1-PH12): stock we hand to a sister shop, one of our shop units or a trade customer,
 * with a price on every line and an invoice PDF. The staff sales invoices of ERPNext (E26) and their price list (E47).
 *
 * A hand-over is a `document` of type TRD on the document base (draft -> make it final -> cancel by a new record that puts everything
 * back; a draft is stopped, never deleted) with a `handover` header (the customer, the VAT code chosen) and its lines (an item, units,
 * the price of a unit before VAT in document_line.unit_cost). HandoverHandler books it (trade_sale) and fixes the invoice's sums.
 *
 * Every write: a staff caller allowed to keep hand-overs (doc.TRD.post, never admin; roles re-read inside the transaction by
 * Documents), ONE transaction (joining an open one), a draft changed only by its creator (Documents), the version the form was drawn
 * with (409 when stale). A new line's price is the customer's remembered price, else (handovers.price_fallback = cost) the cost hint,
 * else the person types it.
 *
 * The invoice PDF (invoice()): our company's details as printed are confirmed, or the PDF is refused (409 company_not_confirmed) while
 * handovers.pdf_needs_confirmed_company is on (the default); off, it prints with the "do not send" line, as a purchase order does.
 */
final class Handovers
{
    public const TYPE = HandoverHandler::TYPE;
    public const PATH = '/ui/stock/handovers';
    public const MAX_LINES = 500;
    public const REF_MAX = 100;
    public const NOTE_MAX = 1000;
    public const LIST_LIMIT = 200;
    public const STATES = ['draft', 'awaiting_approval', 'posted', 'reversed', 'cancelled'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (a draft's default date) */
    public function __construct(private readonly Db $db, private readonly Documents $docs, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    // ------------------------------------------------------------------------------------------
    // Drafts
    // ------------------------------------------------------------------------------------------

    /**
     * A new draft. Header: counterparty (an id, required: a switched-on customer), warehouse (an id; default MAIN: one of ours),
     * vat_code ('' = the customer's, else the company's), external_ref (the customer's own reference), doc_date (Y-m-d, default today
     * in the UK), note. Audit handover.create.
     *
     * @param array<string, mixed> $header
     */
    public function createDraft(Caller $caller, array $header): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $header): Document {
            self::mayPost($db, $caller);
            $h = $this->header($header, null);
            if ($h['counterparty_id'] === null) {
                throw new CwException('customer_required', 'say who the stock is handed to', 422, ['field' => 'counterparty']);
            }
            $doc = $this->docs->createDraft($caller, self::TYPE, $this->docHeader($h));
            $now = Clock::db(($this->clock)());
            $db->exec('INSERT INTO handover (document_id, counterparty_id, vat_code, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                [$doc->id, $h['counterparty_id'], $h['vat_code'], $now, $now]);
            Audit::write($db, $caller, 'handover.create', 'document', (string) $doc->id, null, ['counterparty' => $h['counterparty_id'], 'vat_code' => $h['vat_code']]);
            return $doc;
        });
    }

    /**
     * Changes a draft's header (the keys given; '' clears an optional one). Creator only (Documents::updateDraft). Version + 1.
     *
     * @param array<string, mixed> $header
     */
    public function saveHeader(Caller $caller, int $id, int $version, array $header): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $header): Document {
            $current = $this->current($id);
            self::mayPost($db, $caller);
            $h = $this->header($header, $current);
            if ($h['counterparty_id'] === null) {
                throw new CwException('customer_required', 'say who the stock is handed to', 422, ['field' => 'counterparty']);
            }
            $doc = $this->docs->updateDraft($caller, $id, $version, $this->docHeader($h));
            $db->exec('UPDATE handover SET counterparty_id = ?, vat_code = ?, updated_at = ? WHERE document_id = ?',
                [$h['counterparty_id'], $h['vat_code'], Clock::db(($this->clock)()), $id]);
            Audit::write($db, $caller, 'handover.header', 'document', (string) $id, null, ['version' => $doc->version, 'counterparty' => $h['counterparty_id'],
                'vat_code' => $h['vat_code']]);
            return $doc;
        });
    }

    /**
     * Replaces every line of a draft: [{sku_id, qty (units above 0), unit_cost (the price of a unit before VAT, optional on a
     * draft)}]. At most MAX_LINES lines. Creator only. Version + 1.
     *
     * @param list<array<string, mixed>> $lines
     */
    public function saveLines(Caller $caller, int $id, int $version, array $lines): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $version, $lines): Document {
            $this->current($id);
            return $this->docs->setLines($caller, $id, $version, self::normaliseLines($lines));
        });
    }

    /**
     * Adds what was scanned or typed (StockOps::resolve: a barcode, a case barcode counting its units, a CW number, or words of the name:
     * `choices` when several match, `not_found` when none). The same item at the same price already on the hand-over gets the units
     * added (`incremented`); else a new line (`added`) at $price, or the customer's remembered price, or the fallback.
     *
     * @return array{status: string, document: Document, line_no?: int, units?: int, sku_id?: int, price?: ?string, price_from?: string, choices?: list<array<string, mixed>>, q: string}
     */
    public function addLine(Caller $caller, int $id, int $version, string $q, int $qty, ?string $price = null, ?Settings $settings = null): array
    {
        $r = (new StockOps($this->db, $this->docs))->resolve($q);
        if ($r['status'] !== 'found') {
            return $r + ['document' => $this->docs->get($id), 'q' => trim($q)];
        }
        return $this->addItem($caller, $id, $version, (int) $r['sku_id'], $qty * (int) $r['units'], $price, $settings) + ['q' => trim($q)];
    }

    /**
     * Adds an item chosen from the list, as addLine() adds a found one. `price_from`: typed, customer (remembered), cost (the fallback),
     * none (the person types it before making it final).
     *
     * @return array{status: string, document: Document, line_no: int, units: int, sku_id: int, price: ?string, price_from: string}
     */
    public function addItem(Caller $caller, int $id, int $version, int $skuId, int $qty, ?string $price = null, ?Settings $settings = null): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id, $version, $skuId, $qty, $price, $settings): array {
            $cur = $this->current($id);
            if ($qty <= 0 || $qty > Documents::MAX_QTY) {
                throw new CwException('qty_positive', 'the units are a whole number above zero', 422, ['field' => 'qty']);
            }
            $sku = $db->one('SELECT id, merged_into_sku_id FROM sku WHERE id = ?', [$skuId]) ?? throw new CwException('unknown_sku', 'there is no such item', 422);
            if ($sku['merged_into_sku_id'] !== null) {
                throw new CwException('merged_item', 'this item was joined into another one: use that one', 422, ['merged_into' => (int) $sku['merged_into_sku_id']]);
            }
            $from = 'typed';
            $price = $price === null || trim($price) === '' ? null : Movements::normaliseCost(trim($price), 'unit_cost');
            if ($price === null) {
                [$price, $from] = $this->defaultPrice((int) $cur['counterparty_id'], $skuId, $settings ?? new Settings($db));
            }
            $lines = $this->lineRows($id);
            $status = 'added';
            $lineNo = count($lines) + 1;
            foreach ($lines as $i => $l) {
                if ((int) $l['sku_id'] === $skuId && ($price === null || $l['unit_cost'] === null || bccomp((string) $l['unit_cost'], $price, 6) === 0)) {
                    $lines[$i]['qty'] = $l['qty'] + $qty;
                    $lines[$i]['unit_cost'] ??= $price;
                    $price = $lines[$i]['unit_cost'];
                    $status = 'incremented';
                    $lineNo = $i + 1;
                    break;
                }
            }
            if ($status === 'added') {
                if (count($lines) >= self::MAX_LINES) {
                    throw new CwException('too_many_lines', 'a hand-over has at most ' . self::MAX_LINES . ' lines: start another one', 422);
                }
                $lines[] = ['sku_id' => $skuId, 'qty' => $qty, 'unit_cost' => $price];
            }
            $doc = $this->docs->setLines($caller, $id, $version, self::normaliseLines($lines));
            return ['status' => $status, 'document' => $doc, 'line_no' => $lineNo, 'units' => $qty, 'sku_id' => $skuId, 'price' => $price,
                'price_from' => $price === null ? 'none' : $from];
        });
    }

    /** Makes a draft final (Documents::post: a reviewer's OK first when a rule asks; HandoverHandler books it). */
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
     * Cancels a final hand-over by a new record that puts the stock back and takes its amount off what the customer owes
     * (Documents::reverse; a reason offered for cancellations). A cancellation that puts more stock back than the "stock put back
     * without a supplier document" limit waits for a reviewer's OK (I32). Returns the cancellation record.
     */
    public function reverse(Caller $caller, int $id, string $reasonCode, ?string $note): Document
    {
        return $this->db->transaction(function () use ($caller, $id, $reasonCode, $note): Document {
            $this->isHandover($id) || throw new CwException('not_handover', 'this is not a hand-over', 404);
            return $this->docs->reverse($caller, $id, $reasonCode, $note);
        });
    }

    /**
     * A new draft with the same customer, warehouse, VAT choice, lines and prices as hand-over $id (any state): to make a cancelled or
     * wrong one again, or a hand-over made final before the company details were confirmed (its invoice PDF never prints: PH8). Audit
     * handover.copy. Returns the new draft.
     */
    public function copy(Caller $caller, int $id): Document
    {
        return $this->db->transaction(function (Db $db) use ($caller, $id): Document {
            $r = $this->db->one('SELECT d.id, d.doc_type, d.warehouse_id, d.reverses_id, d.external_ref, d.note, h.counterparty_id, h.vat_code, d.status '
                . 'FROM document d JOIN handover h ON h.document_id = d.id WHERE d.id = ?', [$id]);
            if ($r === null || $r['doc_type'] !== self::TYPE || $r['reverses_id'] !== null) {
                throw new CwException('not_handover', 'this is not a hand-over', 404);
            }
            // The VAT code: the one chosen on a draft, or the one a final hand-over was invoiced with.
            $doc = $this->createDraft($caller, ['counterparty' => (int) $r['counterparty_id'], 'warehouse' => $r['warehouse_id'] === null ? null : (int) $r['warehouse_id'],
                'vat_code' => $r['vat_code'] ?? '', 'external_ref' => $r['external_ref'], 'note' => $r['note']]);
            $lines = array_map(static fn (array $l): array => ['sku_id' => (int) $l['sku_id'], 'qty' => abs((int) $l['qty']),
                'unit_cost' => $l['unit_cost'] === null ? null : (string) $l['unit_cost']],
                $db->all('SELECT sku_id, qty, unit_cost FROM document_line WHERE document_id = ? AND sku_id IS NOT NULL ORDER BY line_no', [$id]));
            if ($lines !== []) {
                $doc = $this->docs->setLines($caller, $doc->id, $doc->version, self::normaliseLines($lines));
            }
            Audit::write($db, $caller, 'handover.copy', 'document', (string) $doc->id, null, ['from' => $id]);
            return $doc;
        });
    }

    // ------------------------------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------------------------------

    public function isHandover(int $id): bool
    {
        return $this->db->value('SELECT doc_type FROM document WHERE id = ?', [$id]) === self::TYPE;
    }

    /**
     * A hand-over's page header: the document row, the customer (name, kind, active), the warehouse, the VAT code chosen or used, the
     * posted sums and snapshots, the people, the record it cancels and the one that cancels it.
     *
     * @return array<string, mixed>|null
     */
    public function record(int $id): ?array
    {
        $r = $this->db->one(
            'SELECT d.*, c.id AS counterparty_id, h.vat_code, h.vat_rate, h.currency, h.net_amount, h.vat_amount, h.gross_amount, h.company_version, h.company_confirmed, '
            . 'h.company_snapshot, h.buyer_snapshot, c.name AS customer_name, c.kind AS customer_kind, c.is_active AS customer_active, c.vat_code AS customer_vat_code, '
            . 'w.code AS warehouse_code, w.name AS warehouse_name, cb.display_name AS created_by_name, sb.display_name AS submitted_by_name, '
            . 'pb.display_name AS posted_by_name, xb.display_name AS cancelled_by_name, od.number AS reverses_number, rv.id AS reversed_by_id, rv.number AS reversed_by_number, '
            . 'rv.status AS reversed_by_status FROM document d LEFT JOIN handover h ON h.document_id = d.id LEFT JOIN handover oh ON oh.document_id = d.reverses_id '
            . 'JOIN counterparty c ON c.id = COALESCE(h.counterparty_id, oh.counterparty_id) '
            . 'LEFT JOIN warehouse w ON w.id = d.warehouse_id LEFT JOIN staff_user cb ON cb.id = d.created_by LEFT JOIN staff_user sb ON sb.id = d.submitted_by '
            . 'LEFT JOIN staff_user pb ON pb.id = d.posted_by LEFT JOIN staff_user xb ON xb.id = d.cancelled_by LEFT JOIN document od ON od.id = d.reverses_id '
            . "LEFT JOIN document rv ON rv.reverses_id = d.id AND rv.status IN ('posted', 'awaiting_approval') WHERE d.id = ? AND d.doc_type = ?",
            [$id, self::TYPE],
        );
        return $r;
    }

    /**
     * A hand-over's lines as its page shows them: line_no, sku_id, code, name, qty, unit_cost (the price), amount, the customer's
     * remembered price, and the stock now at its warehouse (on hand, reserved, available).
     *
     * @return list<array<string, mixed>>
     */
    public function lines(int $id): array
    {
        $doc = $this->db->one('SELECT d.warehouse_id, h.counterparty_id FROM document d JOIN handover h ON h.document_id = d.id WHERE d.id = ?', [$id]);
        $rows = $this->db->all('SELECT l.line_no, l.sku_id, s.code, s.name, s.brand, CAST(s.sell_policy AS CHAR) AS sell_policy, l.qty, l.unit_cost '
            . 'FROM document_line l LEFT JOIN sku s ON s.id = l.sku_id WHERE l.document_id = ? ORDER BY l.line_no', [$id]);
        $skus = array_values(array_unique(array_filter(array_map(static fn (array $l): ?int => $l['sku_id'] === null ? null : (int) $l['sku_id'], $rows))));
        $remembered = $doc === null || $skus === [] ? [] : (new Counterparties($this->db))->lastPrices((int) $doc['counterparty_id'], $skus);
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
            $out[] = ['line_no' => (int) $l['line_no'], 'sku_id' => $sku, 'code' => (string) ($l['code'] ?? ''), 'name' => (string) ($l['name'] ?? ''),
                'brand' => $l['brand'] === null ? null : (string) $l['brand'], 'qty' => $qty, 'unit_cost' => $l['unit_cost'] === null ? null : (string) $l['unit_cost'],
                'amount' => $l['unit_cost'] === null ? null : CostHints::amount($qty, (string) $l['unit_cost']),
                'remembered' => $sku === null ? null : ($remembered[$sku] ?? null),
                'stock' => $sku === null ? null : ($stock[$sku] ?? ['on_hand' => 0, 'reserved' => 0, 'available' => 0])];
        }
        return $out;
    }

    /**
     * The hand-overs for the list (newest first, at most LIST_LIMIT): q (a number, the customer's reference, a note, the customer's name),
     * state, counterparty (an id), cancelled (show stopped drafts too). Each row: the document, the customer, units, items, net and gross
     * (the posted sums, else the lines' net so far).
     *
     * @param array{q?: string, state?: ?string, counterparty?: ?int, cancelled?: bool} $f
     * @return list<array<string, mixed>>
     */
    public function list(array $f): array
    {
        $where = ['d.doc_type = ?'];
        $params = [self::TYPE];
        $state = $f['state'] ?? null;
        if ($state !== null && in_array($state, self::STATES, true)) {
            $where[] = 'd.status = ?';
            $params[] = $state;
        } elseif (!($f['cancelled'] ?? false)) {
            $where[] = "d.status <> 'cancelled'";
        }
        if (($f['counterparty'] ?? null) !== null) {
            $where[] = 'c.id = ?';
            $params[] = (int) $f['counterparty'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(d.number LIKE ? OR d.external_ref LIKE ? OR d.note LIKE ? OR c.name LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        // Two steps (EXPLAIN, docs/decisions.md PH17): the page's ids first, then the figures of those rows only (one query sorted whole
        // counted every hand-over's lines before it sorted).
        $join = 'FROM document d LEFT JOIN handover h ON h.document_id = d.id LEFT JOIN handover oh ON oh.document_id = d.reverses_id '
            . 'JOIN counterparty c ON c.id = COALESCE(h.counterparty_id, oh.counterparty_id) ';
        $ids = array_map('intval', $this->db->column('SELECT d.id ' . $join . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . self::LIST_LIMIT,
            $params));
        if ($ids === []) {
            return [];
        }
        return $this->db->all(
            'SELECT d.id, d.number, d.status, d.review_state, d.doc_date, d.created_at, d.posted_at, d.reverses_id, d.external_ref, d.note, '
            . 'c.id AS counterparty_id, c.name AS customer_name, c.kind AS customer_kind, h.net_amount, h.gross_amount, h.vat_code, w.name AS warehouse, '
            . 'cb.display_name AS created_by_name, od.number AS reverses_number, '
            . '(SELECT COUNT(DISTINCT x.sku_id) FROM document_line x WHERE x.document_id = d.id) AS products, '
            . '(SELECT COALESCE(SUM(ABS(x.qty)), 0) FROM document_line x WHERE x.document_id = d.id) AS units, '
            . '(SELECT SUM(ROUND(x.qty * x.unit_cost, 2)) FROM document_line x WHERE x.document_id = d.id AND x.unit_cost IS NOT NULL) AS priced, '
            . "(SELECT t.kind FROM review_task t WHERE t.subject_type = 'document' AND t.subject_id = d.id AND t.state = 'open' ORDER BY t.id LIMIT 1) AS open_task "
            . $join . 'LEFT JOIN warehouse w ON w.id = d.warehouse_id LEFT JOIN staff_user cb ON cb.id = d.created_by LEFT JOIN document od ON od.id = d.reverses_id '
            . 'WHERE d.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY d.id DESC',
            $ids,
        );
    }

    /**
     * What a form offers: the switched-on customers (id, kind, name, their VAT code), our own switched-on warehouses (id, code, name),
     * the VAT codes in use, and the VAT code that applies when none is chosen on the hand-over, per customer (null: none known).
     *
     * @return array{customers: list<array<string, mixed>>, warehouses: list<array<string, mixed>>, vat_codes: list<array<string, string>>, company_vat_code: ?string, vat_registered: ?bool}
     */
    public function formChoices(): array
    {
        $company = (new CompanyDetails($this->db))->company();
        return [
            'customers' => array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'kind' => (string) $c['kind'], 'name' => (string) $c['name'],
                'vat_code' => $c['vat_code'] === null ? null : (string) $c['vat_code']],
                $this->db->all('SELECT id, kind, name, vat_code FROM counterparty WHERE is_active = 1 ORDER BY name')),
            'warehouses' => array_map(static fn (array $w): array => ['id' => (int) $w['id'], 'code' => (string) $w['code'], 'name' => (string) $w['name']],
                $this->db->all("SELECT id, code, name FROM warehouse WHERE is_active = 1 AND stock_owner = 'own' ORDER BY sort_order, id")),
            'vat_codes' => (new Counterparties($this->db))->vatCodes(),
            'company_vat_code' => ($company['vat_code'] ?? '') === '' ? null : (string) $company['vat_code'],
            'vat_registered' => $company['vat_registered'] ?? null,
        ];
    }

    /**
     * What the invoice PDF prints (HandoverPdf), or the refusal: a posted hand-over (or its cancellation) prints the details its posting
     * fixed; a draft prints the details in use now and its lines as they stand. While handovers.pdf_needs_confirmed_company is on, 409
     * company_not_confirmed when our company's details (as printed) were not confirmed, and 409 vat_code_missing when our company is VAT
     * registered and no VAT code applies. Off: `banner` says "do not send".
     *
     * @return array{record: array<string, mixed>, lines: list<array<string, mixed>>, company: array<string, mixed>, buyer: array<string, mixed>, totals: array{net: string, vat: string, gross: string}, vat_code: ?string, vat_rate: ?string, banner: bool, terms: string}
     */
    public function invoice(int $id, Settings $settings): array
    {
        $rec = $this->record($id) ?? throw new CwException('not_handover', 'this is not a hand-over', 404);
        if ($rec['reverses_id'] !== null) {
            // A cancellation record prints no invoice of its own: the cancelled hand-over's PDF says which record cancelled it (PH9).
            throw new CwException('no_invoice', 'a cancellation record has no invoice: open the hand-over it cancels', 409);
        }
        $lines = $this->lines($id);
        $posted = $rec['net_amount'] !== null;
        if ($posted) {
            $company = json_decode((string) $rec['company_snapshot'], true) ?: [];
            $buyer = json_decode((string) $rec['buyer_snapshot'], true) ?: [];
            $confirmed = (int) $rec['company_confirmed'] === 1;
            $code = $rec['vat_code'] === null ? null : (string) $rec['vat_code'];
            $rate = $rec['vat_rate'] === null ? null : (string) $rec['vat_rate'];
            $totals = ['net' => (string) $rec['net_amount'], 'vat' => (string) $rec['vat_amount'], 'gross' => (string) $rec['gross_amount']];
        } else {
            $company = (new CompanyDetails($this->db))->company();
            $c = $this->db->one('SELECT * FROM counterparty WHERE id = ?', [(int) $rec['counterparty_id']]) ?? [];
            $buyer = ['name' => (string) ($c['name'] ?? ''), 'address' => (string) ($c['address'] ?? ''), 'company_number' => (string) ($c['company_number'] ?? ''),
                'vat_number' => (string) ($c['vat_number'] ?? ''), 'email' => (string) ($c['email'] ?? ''), 'phone' => (string) ($c['phone'] ?? '')];
            $confirmed = (bool) ($company['confirmed'] ?? false);
            try {
                [$code, $rate] = (new HandoverHandler($this->db))->vatFor($this->db, ['vat_code' => $rec['vat_code'], 'counterparty_id' => $rec['counterparty_id']]);
            } catch (CwException) {
                [$code, $rate] = [null, null];
            }
            $totals = HandoverMath::totals($lines, $rate);
        }
        $gate = $settings->has('handovers.pdf_needs_confirmed_company') ? (bool) $settings->get('handovers.pdf_needs_confirmed_company') : true;
        $registered = ($company['vat_registered'] ?? null) === true;
        if ($gate && !$confirmed) {
            throw new CwException('company_not_confirmed', 'the company details printed on this invoice were not confirmed: the invoice is not printed', 409,
                ['posted' => $posted]);
        }
        if ($gate && $registered && $code === null) {
            throw new CwException('vat_code_missing', 'no VAT code applies to this invoice: the invoice is not printed', 409, ['posted' => $posted]);
        }
        return ['record' => $rec, 'lines' => $lines, 'company' => $company, 'buyer' => $buyer, 'totals' => $totals, 'vat_code' => $code, 'vat_rate' => $rate,
            'banner' => !$confirmed, 'terms' => $settings->has('handovers.invoice_terms') ? (string) $settings->get('handovers.invoice_terms') : ''];
    }

    // ------------------------------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------------------------------

    /**
     * The price a new line gets: the customer's remembered price, else the cost hint when handovers.price_fallback says cost, else none.
     *
     * @return array{0: ?string, 1: string} price, where it came from (customer, cost, none)
     */
    private function defaultPrice(int $counterpartyId, int $skuId, Settings $settings): array
    {
        $p = (new Counterparties($this->db))->lastPrices($counterpartyId, [$skuId])[$skuId] ?? null;
        if ($p !== null) {
            return [$p, 'customer'];
        }
        $fallback = $settings->has('handovers.price_fallback') ? (string) $settings->get('handovers.price_fallback') : 'none';
        if ($fallback === 'cost') {
            $hint = (new CostHints($this->db))->suggested([$skuId])[$skuId]['price'] ?? null;
            if ($hint !== null) {
                return [bcadd((string) $hint, '0', 6), 'cost'];
            }
        }
        return [null, 'none'];
    }

    /**
     * A draft's header as stored, locked (FOR UPDATE: Documents locks it again in the same transaction). 404 for a document that is not a
     * hand-over.
     *
     * @return array<string, mixed>
     */
    private function current(int $id): array
    {
        $r = $this->db->one('SELECT d.doc_type, d.warehouse_id, d.external_ref, d.doc_date, d.note, h.counterparty_id, h.vat_code FROM document d '
            . 'LEFT JOIN handover h ON h.document_id = d.id WHERE d.id = ? FOR UPDATE OF d', [$id])
            ?? throw new CwException('unknown_document', 'there is no such record', 404);
        if ($r['doc_type'] !== self::TYPE || $r['counterparty_id'] === null) {
            throw new CwException('not_handover', 'this is not a hand-over', 404);
        }
        return $r;
    }

    /**
     * A header as the hand-over will hold it: the given keys over the current values (or a new draft's defaults), each checked.
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed>|null $current
     * @return array{counterparty_id: ?int, warehouse_id: ?int, vat_code: ?string, external_ref: ?string, doc_date: ?string, note: ?string}
     */
    private function header(array $in, ?array $current): array
    {
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $h = [
            'counterparty_id' => $int($current['counterparty_id'] ?? null),
            'warehouse_id' => $int($current['warehouse_id'] ?? null) ?? ($current === null ? $int($this->db->value("SELECT id FROM warehouse WHERE code = 'MAIN'")) : null),
            'vat_code' => $current['vat_code'] ?? null,
            'external_ref' => $current['external_ref'] ?? null,
            'doc_date' => $current['doc_date'] ?? ($current === null ? ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d') : null),
            'note' => $current['note'] ?? null,
        ];
        foreach (['counterparty' => 'counterparty_id', 'warehouse' => 'warehouse_id'] as $k => $col) {
            if (array_key_exists($k, $in)) {
                $v = $in[$k];
                if ($v === null || $v === '') {
                    $h[$col] = null;
                } elseif ((is_int($v) && $v > 0) || (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1)) {
                    $h[$col] = (int) $v;
                } else {
                    throw new CwException('bad_field', "{$k} is one of the list", 400, ['field' => $k]);
                }
            }
        }
        if (array_key_exists('vat_code', $in)) {
            $code = is_string($in['vat_code']) ? strtoupper(trim($in['vat_code'])) : '';
            if ($code !== '' && $this->db->value('SELECT 1 FROM vat_code WHERE code = ? AND is_active = 1', [$code]) === null) {
                throw new CwException('vat_code_off', "there is no VAT code {$code} in use", 422, ['field' => 'vat_code']);
            }
            $h['vat_code'] = $code === '' ? null : $code;
        }
        if (array_key_exists('external_ref', $in)) {
            $h['external_ref'] = self::text($in['external_ref'], self::REF_MAX, 'external_ref');
        }
        if (array_key_exists('note', $in)) {
            $h['note'] = self::text($in['note'], self::NOTE_MAX, 'note');
        }
        if (array_key_exists('doc_date', $in)) {
            $d = $in['doc_date'];
            $h['doc_date'] = $d === null || $d === '' ? $h['doc_date'] : (string) $d;
        }
        if ($h['counterparty_id'] !== null) {
            $c = $this->db->one('SELECT name, is_active FROM counterparty WHERE id = ?', [$h['counterparty_id']])
                ?? throw new CwException('unknown_customer', 'there is no such customer', 422, ['field' => 'counterparty']);
            if ((int) $c['is_active'] !== 1 && (int) $h['counterparty_id'] !== $int($current['counterparty_id'] ?? null)) {
                throw new CwException('customer_off', "{$c['name']} is switched off", 422, ['field' => 'counterparty']);
            }
        }
        HandoverHandler::checkWarehouse($this->db, $h['warehouse_id']);
        return $h;
    }

    /** @param array<string, mixed> $h @return array<string, mixed> the document header (Documents' fields: the warehouse by code) */
    private function docHeader(array $h): array
    {
        $code = $h['warehouse_id'] === null ? null : $this->db->value('SELECT code FROM warehouse WHERE id = ?', [$h['warehouse_id']]);
        return ['warehouse' => $code === null ? null : (string) $code, 'reason_code' => null, 'external_ref' => $h['external_ref'], 'doc_date' => $h['doc_date'],
            'note' => $h['note']];
    }

    /**
     * Checks a hand-over's lines and returns them as Documents::setLines takes them.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array{sku_id: int, qty: int, unit_cost?: string}>
     */
    private static function normaliseLines(array $lines): array
    {
        if (!array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new CwException('too_many_lines', 'a hand-over has at most ' . self::MAX_LINES . ' lines: start another one', 422);
        }
        $out = [];
        foreach ($lines as $i => $l) {
            $no = $i + 1;
            $sku = $l['sku_id'] ?? null;
            if (!is_int($sku) || $sku <= 0) {
                throw new CwException('bad_line', "line {$no}: a hand-over's line names an item", 422, ['line' => $no]);
            }
            $qty = $l['qty'] ?? null;
            if (!is_int($qty) || $qty <= 0 || $qty > Documents::MAX_QTY) {
                throw new CwException('qty_positive', "line {$no}: the units are a whole number above zero", 422, ['line' => $no, 'field' => 'qty']);
            }
            $n = ['sku_id' => $sku, 'qty' => $qty];
            $price = $l['unit_cost'] ?? null;
            $price = $price === null || (is_string($price) && trim($price) === '') ? null : (is_string($price) ? trim($price) : $price);
            if ($price !== null) {
                $n['unit_cost'] = Movements::normaliseCost($price, "lines[{$no}].unit_cost");
            }
            $out[] = $n;
        }
        return $out;
    }

    /** @return list<array{sku_id: int, qty: int, unit_cost: ?string}> a draft's lines as saveLines() takes them */
    private function lineRows(int $id): array
    {
        return array_map(static fn (array $l): array => ['sku_id' => (int) $l['sku_id'], 'qty' => (int) $l['qty'],
            'unit_cost' => $l['unit_cost'] === null ? null : (string) $l['unit_cost']],
            $this->db->all('SELECT sku_id, qty, unit_cost FROM document_line WHERE document_id = ? AND sku_id IS NOT NULL ORDER BY line_no', [$id]));
    }

    /** 403 unless the caller (a person, never admin) may keep hand-overs (roles re-read in this transaction). */
    private static function mayPost(Db $db, Caller $caller): void
    {
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'hand-overs are kept by staff', 403);
        }
        $roles = StaffRoles::active($db, $caller->staffUserId);
        if (!Documents::mayPost($roles, self::TYPE)) {
            throw new CwException(in_array('admin', $roles, true) ? 'admin_cannot_post' : 'role_not_allowed', 'you cannot keep hand-overs', 403);
        }
    }

    /** A trimmed one-line text of at most $max characters, or null (absent or blank). 400 bad_field. */
    private static function text(mixed $v, int $max, string $field): ?string
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
        if (mb_strlen($v) > $max) {
            throw new CwException('bad_field', "{$field} is at most {$max} characters", 400, ['field' => $field]);
        }
        return $v;
    }
}
