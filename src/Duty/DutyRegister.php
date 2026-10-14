<?php

declare(strict_types=1);

namespace CW\Duty;

use CW\Clock;
use CW\Db;
use CW\Receiving\ReceiptMath;
use CW\Settings;

/**
 * The vaping duty register (pack H, IM13; docs/decisions.md PH15-PH18), built from the receipts (IM6): one line per line of a final goods
 * receipt that needed the duty-stamp check (duty.register_lines = stamp; `all`: every receipt line), with when the goods arrived, the
 * supplier, their invoice and delivery note, the item and its barcode, the units on the paperwork and where they went, the liquid per unit
 * and the expected duty (for information), the bench's stamp check (on the outer pack and sealing it, its type, a scanned code), the
 * unstamped units and what happened to them, and the supplier's declaration that unstamped stock was made or brought in before 1 Oct
 * 2026 (pre_october_evidence, with whether a file of it is kept). It reads the receipts' own posted fields: nothing is copied, so the
 * register always says what the receipts say (H-test "the register lines match the receipts"). A reversed receipt stays listed, marked.
 *
 * Also the unstamped stock and where it is: what the warehouses named in duty.unstamped_warehouses hold now (the quarantine, UNSTAMPED),
 * and the units taken in unstamped on a supplier's declaration (into the receipt's warehouse; CW cannot tell how many of those are sold
 * since: they are counted). From duty.unstamped_zero_from (1 Apr 2027) anything held unstamped is an alarm.
 *
 * Read only. Every list keeps to an index (goods_receipt received_at; the stock balance by warehouse); EXPLAIN in docs/decisions.md PH17.
 */
final class DutyRegister
{
    /** The stamp states a line can be in (and the filter's choices). */
    public const STAMP_STATES = ['stamped', 'unstamped', 'declaration', 'not_checked', 'not_needed'];
    public const RECEIPT_STATES = ['posted', 'reversed'];
    public const UNSTAMPED_LIMIT = 500;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (today, for the alarm) */
    public function __construct(private readonly Db $db, private readonly Settings $settings, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * The filters as the register reads them: from / to (UK days, Y-m-d; the receipt's arrival), supplier (an id), stamp (STAMP_STATES),
     * state (posted = still in force, reversed), q (words of the item, its CW number, the supplier's invoice or delivery note, or the
     * receipt's number).
     *
     * @param array<string, mixed> $in
     * @return array{from: ?string, to: ?string, supplier: ?int, stamp: ?string, state: ?string, q: string}
     */
    public static function filters(array $in): array
    {
        $day = static function (mixed $v): ?string {
            $v = is_string($v) ? trim($v) : '';
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, Clock::utc());
            return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
        };
        $supplier = $in['supplier'] ?? null;
        return [
            'from' => $day($in['from'] ?? null),
            'to' => $day($in['to'] ?? null),
            'supplier' => is_string($supplier) && preg_match('/^[1-9][0-9]{0,9}$/D', $supplier) === 1 ? (int) $supplier : (is_int($supplier) && $supplier > 0 ? $supplier : null),
            'stamp' => in_array($in['stamp'] ?? null, self::STAMP_STATES, true) ? (string) $in['stamp'] : null,
            'state' => in_array($in['state'] ?? null, self::RECEIPT_STATES, true) ? (string) $in['state'] : null,
            'q' => mb_substr(trim(is_string($in['q'] ?? null) ? (string) $in['q'] : ''), 0, 100),
        ];
    }

    /**
     * The register's lines, newest arrival first: at most $limit from $offset.
     *
     * @param array{from: ?string, to: ?string, supplier: ?int, stamp: ?string, state: ?string, q: string} $f
     * @return list<array<string, mixed>>
     */
    public function rows(array $f, int $limit, int $offset = 0): array
    {
        // Two steps (EXPLAIN, docs/decisions.md PH17): the page's keys first, from the narrow columns the order needs (a sort of small
        // rows), then everything a line shows for those keys only; one query sorted whole computed every line's barcode, file and
        // incidents before it sorted (1.2 s on 50,000 receipt lines).
        [$where, $params] = $this->where($f);
        $keys = $this->db->all('SELECT g.document_id, g.line_no FROM ' . $this->from($f) . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY r.received_at DESC, r.document_id DESC, g.line_no LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
        if ($keys === []) {
            return [];
        }
        $pairs = [];
        foreach ($keys as $k) {
            array_push($pairs, (int) $k['document_id'], (int) $k['line_no']);
        }
        $rows = $this->db->all(
            'SELECT r.document_id, d.number, d.status, r.received_at, d.external_ref AS supplier_invoice, r.delivery_note, r.supplier_id, su.name AS supplier, '
            . 'g.line_no, dl.sku_id, s.code AS sku_code, s.name AS sku_name, g.packs, g.units_per_pack, dl.qty AS units, g.accepted_units, g.verify_units, '
            . 'g.quarantine_units, g.refused_units, g.short_units, g.damaged_units, g.wrong_item_units, g.over_units, g.unstamped_units, g.unstamped_action, '
            . 'g.pre_october_evidence, g.stamp_required, g.stamp_on_pack, g.stamp_type, g.stamp_code, g.duty_ml, g.expected_duty, ' . self::STAMP_SQL . ' AS stamp_state, '
            . 'cb.display_name AS checked_by, r.checked_at, '
            . "(SELECT MIN(b.barcode) FROM sku_barcode b WHERE b.sku_id = dl.sku_id AND b.is_usable = 1) AS barcode, "
            . "EXISTS (SELECT 1 FROM document_file f WHERE f.document_id = r.document_id AND f.role = 'evidence') AS evidence_file, "
            . "(SELECT COUNT(*) FROM incident i WHERE i.document_id = r.document_id AND i.line_no = g.line_no) AS incidents "
            . 'FROM grn_line g JOIN goods_receipt r ON r.document_id = g.document_id JOIN document d ON d.id = g.document_id '
            . 'JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no LEFT JOIN sku s ON s.id = dl.sku_id '
            . 'JOIN supplier su ON su.id = r.supplier_id LEFT JOIN staff_user cb ON cb.id = r.checked_by '
            . 'WHERE (g.document_id, g.line_no) IN (' . implode(', ', array_fill(0, count($keys), '(?, ?)')) . ') '
            . 'ORDER BY r.received_at DESC, r.document_id DESC, g.line_no',
            $pairs,
        );
        return array_map(static function (array $r): array {
            foreach (['document_id', 'line_no', 'packs', 'units_per_pack', 'units', 'unstamped_units', 'short_units', 'damaged_units', 'wrong_item_units', 'over_units',
                'incidents', 'supplier_id'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            foreach (['accepted_units', 'verify_units', 'quarantine_units', 'refused_units', 'sku_id'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            $r['stamp_required'] = $r['stamp_required'] === null ? null : (int) $r['stamp_required'] === 1;
            $r['stamp_on_pack'] = $r['stamp_on_pack'] === null ? null : (int) $r['stamp_on_pack'] === 1;
            $r['evidence_file'] = (int) $r['evidence_file'] === 1;
            // Stamped and unstamped as the receipt booked them (review finding 4 of 14 Oct): an unstamped delivery's damaged and over
            // units go with its unstamped units (ReceiptMath::extras, I167), so they are unstamped too; otherwise they went to VERIFY as
            // ordinary stock. Stamped = what came as this item (less short and wrong item, plus over) that is not unstamped, and only
            // when the bench found the stamp on the pack.
            ['stamped' => $r['stamped_units'], 'unstamped' => $r['unstamped_total']] = self::stampUnits($r);
            return $r;
        }, $rows);
    }

    /**
     * The stamped and unstamped units of a register line, by the receipt's own rules (ReceiptMath::extras: where an unstamped
     * delivery's damaged and over units went). Pure.
     *
     * @param array{stamp_required: ?bool, stamp_on_pack: ?bool, units: int, short_units: int, wrong_item_units: int, damaged_units: int, over_units: int, unstamped_units: int, unstamped_action: ?string} $r
     * @return array{stamped: int, unstamped: int}
     */
    public static function stampUnits(array $r): array
    {
        if ($r['stamp_required'] !== true) {
            return ['stamped' => 0, 'unstamped' => 0];
        }
        $action = $r['unstamped_units'] > 0 ? $r['unstamped_action'] : null;
        $onPack = $r['stamp_on_pack'] === null ? null : ($r['stamp_on_pack'] ? 1 : 0);
        $extras = ReceiptMath::extras(true, $onPack, $action);
        $unstamped = $r['unstamped_units'] + ($extras === null ? 0 : $r['damaged_units'] + $r['over_units']);
        $came = $r['units'] - $r['short_units'] - $r['wrong_item_units'] + $r['over_units'];
        return ['stamped' => $onPack === 1 ? max(0, $came - $unstamped) : 0, 'unstamped' => $unstamped];
    }

    /** How many lines the filters keep. @param array{from: ?string, to: ?string, supplier: ?int, stamp: ?string, state: ?string, q: string} $f */
    public function count(array $f): int
    {
        [$where, $params] = $this->where($f);
        return (int) $this->db->value('SELECT COUNT(*) FROM ' . $this->from($f) . ' WHERE ' . implode(' AND ', $where), $params);
    }

    /** The tables the filters need: the item's only when words are searched. @param array{q: string} $f */
    private function from(array $f): string
    {
        return 'goods_receipt r JOIN document d ON d.id = r.document_id JOIN grn_line g ON g.document_id = r.document_id'
            . ($f['q'] === '' ? '' : ' JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no LEFT JOIN sku s ON s.id = dl.sku_id');
    }

    /** @return list<array{id: int, name: string}> the suppliers with a final receipt (the filter's choices) */
    public function suppliers(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $this->db->all(
            "SELECT su.id, su.name FROM supplier su WHERE EXISTS (SELECT 1 FROM goods_receipt r JOIN document d ON d.id = r.document_id AND d.status IN ('posted', 'reversed') "
            . 'WHERE r.supplier_id = su.id) ORDER BY su.name'));
    }

    /**
     * The unstamped stock and where it is.
     *
     * held: per warehouse named in duty.unstamped_warehouses (in that order), what it holds now (item, units on hand), at most
     * UNSTAMPED_LIMIT rows, and the total units; accepted: per item and warehouse, the units taken in unstamped on a supplier's declaration
     * by receipts still in force, the last arrival, and what that warehouse holds of the item now; alarm: today (UK) is on or after
     * duty.unstamped_zero_from and something is held; from: that date (null: not set).
     *
     * held_more / accepted_more: the list stopped at UNSTAMPED_LIMIT rows (the totals still count everything).
     *
     * @return array{held: list<array<string, mixed>>, held_units: int, accepted: list<array<string, mixed>>, accepted_units: int, held_more: bool, accepted_more: bool, alarm: bool, from: ?string, warehouses: list<array{code: string, name: ?string}>}
     */
    public function unstamped(): array
    {
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) ($this->settings->has('duty.unstamped_warehouses')
            ? $this->settings->get('duty.unstamped_warehouses') : 'UNSTAMPED'))), static fn (string $c): bool => $c !== ''));
        $warehouses = [];
        $held = [];
        $heldUnits = 0;
        if ($codes !== []) {
            $in = implode(', ', array_fill(0, count($codes), '?'));
            $names = [];
            foreach ($this->db->all("SELECT id, code, name FROM warehouse WHERE code IN ({$in})", $codes) as $w) {
                $names[(string) $w['code']] = ['id' => (int) $w['id'], 'name' => (string) $w['name']];
            }
            foreach ($codes as $c) {
                $warehouses[] = ['code' => $c, 'name' => $names[$c]['name'] ?? null];
            }
            $ids = array_values(array_map(static fn (array $w): int => $w['id'], $names));
            if ($ids !== []) {
                $inIds = implode(', ', array_fill(0, count($ids), '?'));
                $heldUnits = (int) $this->db->value("SELECT COALESCE(SUM(b.on_hand), 0) FROM stock_balance b WHERE b.warehouse_id IN ({$inIds}) AND b.on_hand > 0", $ids);
                $held = array_map(static fn (array $r): array => ['warehouse' => (string) $r['warehouse'], 'warehouse_code' => (string) $r['warehouse_code'],
                    'sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'on_hand' => (int) $r['on_hand']],
                    $this->db->all("SELECT w.name AS warehouse, w.code AS warehouse_code, b.sku_id, s.code, s.name, b.on_hand FROM stock_balance b "
                        . "JOIN warehouse w ON w.id = b.warehouse_id JOIN sku s ON s.id = b.sku_id WHERE b.warehouse_id IN ({$inIds}) AND b.on_hand <> 0 "
                        . 'ORDER BY w.sort_order, w.id, s.code LIMIT ' . self::UNSTAMPED_LIMIT, $ids));
            }
        }
        $accepted = array_map(static fn (array $r): array => ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
            'warehouse' => (string) $r['warehouse'], 'units' => (int) $r['units'], 'receipts' => (int) $r['receipts'], 'last_received' => (string) $r['last_received'],
            'on_hand' => (int) $r['on_hand']],
            $this->db->all("SELECT dl.sku_id, s.code, s.name, w.name AS warehouse, SUM(g.unstamped_units) AS units, COUNT(DISTINCT r.document_id) AS receipts, "
                . 'MAX(r.received_at) AS last_received, COALESCE(MAX(b.on_hand), 0) AS on_hand FROM grn_line g '
                . "JOIN goods_receipt r ON r.document_id = g.document_id JOIN document d ON d.id = r.document_id AND d.status = 'posted' "
                . 'JOIN document_line dl ON dl.document_id = g.document_id AND dl.line_no = g.line_no JOIN sku s ON s.id = dl.sku_id JOIN warehouse w ON w.id = d.warehouse_id '
                . 'LEFT JOIN stock_balance b ON b.warehouse_id = d.warehouse_id AND b.sku_id = dl.sku_id '
                . "WHERE g.unstamped_action = 'accept_pre_october' GROUP BY dl.sku_id, s.code, s.name, w.name ORDER BY s.code LIMIT " . self::UNSTAMPED_LIMIT));
        // The totals count every line, not only the items listed (review finding 6 of 14 Oct: the list stops at UNSTAMPED_LIMIT).
        $acceptedTotal = (int) $this->db->value("SELECT COALESCE(SUM(g.unstamped_units), 0) FROM grn_line g JOIN document d ON d.id = g.document_id "
            . "AND d.status = 'posted' JOIN goods_receipt r ON r.document_id = g.document_id WHERE g.unstamped_action = 'accept_pre_october'");
        $from = $this->settings->has('duty.unstamped_zero_from') ? $this->settings->get('duty.unstamped_zero_from') : null;
        $today = ($this->clock)()->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
        return ['held' => $held, 'held_units' => $heldUnits, 'accepted' => $accepted, 'accepted_units' => $acceptedTotal,
            'held_more' => count($held) >= self::UNSTAMPED_LIMIT, 'accepted_more' => count($accepted) >= self::UNSTAMPED_LIMIT,
            'alarm' => is_string($from) && $from !== '' && $today >= $from && $heldUnits > 0, 'from' => is_string($from) && $from !== '' ? $from : null,
            'warehouses' => $warehouses];
    }

    /** Which lines the register lists (duty.register_lines): `stamp` (needed the stamp check) or `all`. */
    public function lines(): string
    {
        return $this->settings->has('duty.register_lines') && $this->settings->get('duty.register_lines') === 'all' ? 'all' : 'stamp';
    }

    /** The stamp state of a line, in SQL (STAMP_STATES): the bench's findings as the posting kept them. */
    private const STAMP_SQL = "(CASE WHEN g.stamp_required = 0 THEN 'not_needed' WHEN g.unstamped_action = 'accept_pre_october' THEN 'declaration' "
        . "WHEN g.unstamped_units > 0 THEN 'unstamped' WHEN g.stamp_on_pack = 1 THEN 'stamped' ELSE 'not_checked' END)";

    /**
     * @param array{from: ?string, to: ?string, supplier: ?int, stamp: ?string, state: ?string, q: string} $f
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private function where(array $f): array
    {
        $where = ["d.doc_type = 'GRN'", "d.status IN ('posted', 'reversed')", 'd.reverses_id IS NULL'];
        $params = [];
        if ($this->lines() === 'stamp') {
            $where[] = 'g.stamp_required = 1';
        }
        $uk = new \DateTimeZone('Europe/London');
        if ($f['from'] !== null) {
            $where[] = 'r.received_at >= ?';
            $params[] = Clock::db((new \DateTimeImmutable($f['from'] . ' 00:00:00', $uk))->setTimezone(Clock::utc()));
        }
        if ($f['to'] !== null) {
            $where[] = 'r.received_at < ?';
            $params[] = Clock::db((new \DateTimeImmutable($f['to'] . ' 00:00:00', $uk))->modify('+1 day')->setTimezone(Clock::utc()));
        }
        if ($f['supplier'] !== null) {
            $where[] = 'r.supplier_id = ?';
            $params[] = $f['supplier'];
        }
        if ($f['stamp'] !== null) {
            $where[] = self::STAMP_SQL . ' = ?';
            $params[] = $f['stamp'];
        }
        if ($f['state'] !== null) {
            $where[] = 'd.status = ?';
            $params[] = $f['state'];
        }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '\\%_') . '%';
            $where[] = '(s.name LIKE ? OR s.code LIKE ? OR d.number LIKE ? OR d.external_ref LIKE ? OR r.delivery_note LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return [$where, $params];
    }
}
