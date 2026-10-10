<?php

declare(strict_types=1);

namespace CW\StockOps;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Documents\SizeApproval;
use CW\Movements;

/**
 * What a stock record does when it is posted or reversed (pack A1; docs/decisions.md SO1-SO16): one handler class for the five kinds,
 * each registered under its document type (DocumentHandlers::all):
 *
 *   in        SIN   the warehouse's on hand + qty: an `adjustment` per line, at the line's unit cost when it has one (else valued at
 *                   the average cost later, IM8). Reasons: the header's, offered for stock in (opening stock, found, a trade
 *                   customer's return, other).
 *   out       SOUT  on hand - qty: a `stock_out` per line (document-only, no cost: it goes out at the average cost). Reasons: the
 *                   header's, offered for stock out; a reason that needs it (sample, staff use: Q5) needs the "given to" name. Only
 *                   from our own stock: another account's stock leaves its room by a release.
 *   adjust    ADJ   on hand +/- qty per line, each line with its reason (offered for adjustments): a decrease whose reason only takes
 *                   stock down and is also offered for write-offs is booked as a `write_off`, every other line as an `adjustment`
 *                   (a + line may carry a unit cost). The Reasons page decides which reasons those are, never the code.
 *   transfer  TRF   between two warehouses of the same owner (stock_owner and its account's name equal: a transfer never changes
 *                   whose stock it is): `transfer_out` of the one and `transfer_in` of the other, in one transaction. Between two
 *                   places of ONE warehouse (the shelf, the overflow room: Q2, Q6/Q7) it books nothing: stock is not split by place,
 *                   the record says where it went.
 *   release   REL   from another account's warehouse (the VPG 2 room) to ours, each line at its agreed unit price: `transfer_out` of
 *                   the other account's room and `goods_in` into ours AT that price (the stock enters our books as a purchase from
 *                   that account), and the amount (qty x price, to the penny) added to the balance owed to the account
 *                   (other_account_entry `release`). Only the release changes whose stock it is, and only from another account to
 *                   our own.
 *
 * Never below zero (the owner's rule, with Stock.php's notion of a protected product: sell policy strict or stopped): a line that takes
 * a protected product's available stock below zero is refused unless its reason allows it (reason_code.below_zero, the Reasons page);
 * a transfer has no reason, so it never may; a release never takes more than the other account's room holds, whatever the product.
 * validate() checks it on what it reads (the screen's early answer); post() checks it again after booking, on the balances this
 * transaction holds locked, so two records posted at once cannot both pass (a refusal rolls the whole posting back).
 *
 * Module rows (the balance owed, the reversal's header) are written before the stock, which is booked last in ONE
 * Movements::bookForDocument call (I21: nothing with a foreign key after the stock locks). A reversal is the generic exact negation of
 * the original's ledger rows (Movements::reverseDocument); reverse() copies the header for the reversal and takes the release's amount
 * off the balance owed.
 *
 * Approvals: the type's own (Documents: "stock put back without a supplier document" for ADJ and SIN, approvalUnits()) and the OK
 * first for a big record (SizeApproval: size()). Both are switched on the Approval Rules page; only ADJ's first one is on by default.
 */
final class StockOpHandler implements DocumentHandler, SizeApproval
{
    public const KINDS = ['in' => 'SIN', 'out' => 'SOUT', 'adjust' => 'ADJ', 'transfer' => 'TRF', 'release' => 'REL'];
    /** The kinds whose records put stock back without a supplier document (approvalUnits). */
    private const POSITIVE_KINDS = ['in', 'adjust'];
    /** Attachment roles that are a supplier document (Documents\DocumentHandler::approvalUnits: verifiable evidence). */
    public const SUPPLIER_DOC_ROLES = ['supplier_invoice', 'delivery_note'];
    /** Sell policies whose stock the websites must never oversell (Stock::flagShortfalls). */
    public const PROTECTED = ['strict', 'stopped'];

    private readonly Movements $moves;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (the day of the approval aggregate; the ledger's times) */
    public function __construct(private readonly Db $db, private readonly string $kind, private readonly ?\Closure $clock = null)
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException("unknown stock record kind {$kind}");
        }
        $this->moves = new Movements($db, null, $clock);
    }

    /** @return array<string, self> type code => handler, one per kind (DocumentHandlers::all) */
    public static function all(Db $db, ?\Closure $clock = null): array
    {
        $out = [];
        foreach (self::KINDS as $kind => $type) {
            $out[$type] = new self($db, $kind, $clock);
        }
        return $out;
    }

    public function type(): string
    {
        return self::KINDS[$this->kind];
    }

    public function kind(): string
    {
        return $this->kind;
    }

    // ------------------------------------------------------------------------------------------
    // DocumentHandler
    // ------------------------------------------------------------------------------------------

    public function validate(Db $db, Document $doc, array $lines): void
    {
        $op = self::op($db, $doc->id);
        $this->checkPlaces($db, $doc, $op);
        $reasons = self::reasons($db, $doc, $lines);
        $givenNeeded = false;
        foreach ($lines as $l) {
            $no = (int) $l['line_no'];
            if ($l['sku_id'] === null || $l['qty'] === null || (int) $l['qty'] === 0) {
                throw new CwException('bad_line', "line {$no}: a stock record's line names a product and a quantity", 422, ['line' => $no]);
            }
            if ($l['warehouse_id'] !== null && (int) $l['warehouse_id'] !== $doc->warehouseId) {
                throw new CwException('line_warehouse', "line {$no}: every line is in the record's warehouse", 422, ['line' => $no]);
            }
            $qty = (int) $l['qty'];
            if ($this->kind !== 'adjust' && $qty < 0) {
                throw new CwException('qty_positive', "line {$no}: the quantity is a whole number above zero", 422, ['line' => $no]);
            }
            $cost = $l['unit_cost'];
            if ($this->kind === 'release' && $cost === null) {
                throw new CwException('price_required', "line {$no}: a release says the agreed price of each unit", 422, ['line' => $no]);
            }
            if ($cost !== null && (in_array($this->kind, ['out', 'transfer'], true) || ($this->kind === 'adjust' && $qty < 0))) {
                throw new CwException('cost_not_allowed', "line {$no}: stock that goes out is valued at the average cost: it carries no cost of its own", 422, ['line' => $no]);
            }
            $code = $l['reason_code'] ?? $doc->reasonCode;
            if (in_array($this->kind, ['in', 'out', 'adjust'], true)) {
                if ($code === null) {
                    throw new CwException('reason_required', $this->kind === 'adjust' ? "line {$no}: every line of an adjustment says why" : 'say why the stock '
                        . ($this->kind === 'in' ? 'comes in' : 'goes out'), 422, ['line' => $no]);
                }
                $r = $reasons[$code] ?? throw new CwException('unknown_reason', "line {$no}: there is no reason {$code}", 422, ['line' => $no]);
                // What the line does to the stock: a stock out's units go down, a stock in's up, an adjustment's as signed.
                $effect = $this->kind === 'out' ? -$qty : $qty;
                if (($r['direction'] === 'increase' && $effect < 0) || ($r['direction'] === 'decrease' && $effect > 0)) {
                    throw new CwException('reason_direction', "line {$no}: the reason " . $r['label'] . ' takes stock the other way', 422, ['line' => $no, 'reason' => $code]);
                }
                $givenNeeded = $givenNeeded || $r['needs_given_to'];
            } elseif ($code !== null) {
                throw new CwException('reason_not_applicable', "line {$no}: a {$this->kind} carries no reason", 422, ['line' => $no]);
            }
        }
        if ($givenNeeded && ($op['given_to'] ?? null) === null) {
            throw new CwException('given_to_required', 'say who it was given to: the reason needs a name', 422, ['field' => 'given_to']);
        }
        $short = $this->shortfalls($db, $doc, $lines, $op, $reasons, false);
        if ($short !== null) {
            throw $short;
        }
    }

    /**
     * "Stock put back without a supplier document" (ADJ, and SIN when it is switched on): the units this record puts back, plus the
     * same person's other positive units of the UK day on stock-ins and adjustments already final (so five records of +10 are one of
     * +50: DocumentHandler I19, I37). 0 when the record carries a supplier document (an attached invoice or delivery note).
     */
    public function approvalUnits(Db $db, Document $doc, array $lines): int
    {
        if (!in_array($this->kind, self::POSITIVE_KINDS, true)) {
            return 0;
        }
        $mine = array_sum(array_map(static fn (array $l): int => max(0, (int) $l['qty']), $lines));
        if ($mine === 0) {
            return 0;
        }
        $roles = implode(', ', array_fill(0, count(self::SUPPLIER_DOC_ROLES), '?'));
        if ($db->value("SELECT 1 FROM document_file WHERE document_id = ? AND role IN ({$roles}) LIMIT 1", [$doc->id, ...self::SUPPLIER_DOC_ROLES]) !== null) {
            return 0;
        }
        $others = (int) $db->value(
            'SELECT COALESCE(SUM(GREATEST(l.qty, 0)), 0) FROM document d JOIN document_line l ON l.document_id = d.id '
            . "WHERE d.doc_type IN ('ADJ', 'SIN') AND d.status IN ('posted', 'reversed') AND d.reverses_id IS NULL AND d.created_by <=> ? AND d.posted_at >= ? AND d.id <> ?",
            [$doc->createdBy, Clock::db(self::ukDayStart($this->now())), $doc->id],
        );
        return $mine + $others;
    }

    /** SizeApproval: units moved and their worth in whole pounds (the line's price or cost, else the average cost so far, else 0). */
    public function size(Db $db, Document $doc, array $lines): array
    {
        $units = 0;
        $value = '0';
        $need = [];
        foreach ($lines as $l) {
            if ($l['sku_id'] !== null && $l['unit_cost'] === null) {
                $need[] = (int) $l['sku_id'];
            }
        }
        $avg = $need === [] ? [] : (new CostHints($db))->average($need);
        foreach ($lines as $l) {
            if ($l['sku_id'] === null || $l['qty'] === null) {
                continue;
            }
            $q = abs((int) $l['qty']);
            $units += $q;
            $unit = $l['unit_cost'] ?? ($avg[(int) $l['sku_id']] ?? null);
            if ($unit !== null) {
                $value = bcadd($value, bcmul((string) $q, (string) $unit, 6), 6);
            }
        }
        return ['units' => $units, 'value' => CostHints::wholePounds($value)];
    }

    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
    {
        $op = self::op($db, $doc->id);
        $units = array_sum(array_map(static fn (array $l): int => abs((int) $l['qty']), $lines));
        $codes = self::warehouseCodes($db);
        $from = $codes[(int) $doc->warehouseId] ?? throw new \LogicException("stock record {$doc->id} has no warehouse");
        $out = [];
        $in = [];
        $writeOff = [];
        foreach ($lines as $l) {
            $base = ['document_line' => (int) $l['line_no'], 'sku_id' => (int) $l['sku_id']];
            $q = (int) $l['qty'];
            switch ($this->kind) {
                case 'in':
                    $in['adjustment'][] = $base + ['qty' => $q] + ($l['unit_cost'] === null ? [] : ['unit_cost' => (string) $l['unit_cost']]);
                    break;
                case 'out':
                    $out['stock_out'][] = $base + ['qty' => $q];
                    break;
                case 'adjust':
                    $code = (string) ($l['reason_code'] ?? $doc->reasonCode);
                    if ($q < 0 && ($writeOff[$code] ??= self::isWriteOff($db, $code))) {
                        $out['write_off'][] = $base + ['qty' => -$q];
                    } else {
                        $in['adjustment'][] = $base + ['qty' => $q] + ($l['unit_cost'] === null ? [] : ['unit_cost' => (string) $l['unit_cost']]);
                    }
                    break;
                case 'transfer':
                case 'release':
                    $out['transfer_out'][] = $base + ['qty' => $q];
                    $in[$this->kind === 'release' ? 'goods_in' : 'transfer_in'][] = $base + ['qty' => $q]
                        + ($this->kind === 'release' ? ['unit_cost' => (string) $l['unit_cost']] : []);
                    break;
            }
        }
        $to = $op !== null && $op['to_warehouse_id'] !== null ? ($codes[(int) $op['to_warehouse_id']] ?? null) : null;
        // Module rows first (I21): the release's amount on the balance owed to the account, with its name as it is now.
        if ($this->kind === 'release') {
            $account = (string) $db->value('SELECT owner_entity FROM warehouse WHERE id = ?', [$doc->warehouseId]);
            $amount = self::releaseAmount($lines);
            $db->exec('UPDATE stock_op SET account_name = ?, updated_at = ? WHERE document_id = ?', [$account, Clock::db($this->now()), $doc->id]);
            $db->insert('INSERT INTO other_account_entry (warehouse_id, account_name, kind, amount, document_id, created_by, created_actor) '
                . "VALUES (?, ?, 'release', ?, ?, ?, ?)", [$doc->warehouseId, $account, $amount, $doc->id, $caller->staffUserId, $caller->actor]);
        }
        if ($this->kind === 'transfer' && $to === $from) {
            return $units; // between two places of one warehouse: nothing to book (stock is not split by place)
        }
        $moves = [];
        foreach ($out as $type => $ls) {
            $moves[] = ['type' => $type, 'warehouse' => $from, 'lines' => $ls];
        }
        foreach ($in as $type => $ls) {
            $moves[] = ['type' => $type, 'warehouse' => in_array($this->kind, ['transfer', 'release'], true) ? (string) $to : $from, 'lines' => $ls];
        }
        $this->moves->bookForDocument($caller, ['document_id' => $doc->id, 'doc_ref' => (string) $doc->number], $opKey, $moves);
        // Again, on the balances this transaction now holds: a record posted at the same moment cannot have slipped past validate().
        $short = $this->shortfalls($db, $doc, $lines, $op, self::reasons($db, $doc, $lines), true);
        if ($short !== null) {
            throw $short;
        }
        return $units;
    }

    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
    {
        // The reversal says the same places and people as the record it cancels (a missing header: a record made before the pack).
        $db->exec('INSERT INTO stock_op (document_id, kind, location_id, to_warehouse_id, to_location_id, given_to, account_name, created_at, updated_at) '
            . 'SELECT ?, kind, location_id, to_warehouse_id, to_location_id, given_to, account_name, ?, ? FROM stock_op WHERE document_id = ?',
            [$reversal->id, Clock::db($this->now()), Clock::db($this->now()), $original->id]);
        if ($this->kind === 'release') {
            $e = $db->one("SELECT warehouse_id, account_name, amount FROM other_account_entry WHERE document_id = ? AND kind = 'release'", [$original->id]);
            if ($e === null) {
                throw new \LogicException("release {$original->id} has no entry on the balance owed");
            }
            $db->insert('INSERT INTO other_account_entry (warehouse_id, account_name, kind, amount, document_id, created_by, created_actor) '
                . "VALUES (?, ?, 'release_reversal', ?, ?, ?, ?)",
                [(int) $e['warehouse_id'], (string) $e['account_name'], bcsub('0', (string) $e['amount'], 2), $reversal->id, $caller->staffUserId, $caller->actor]);
        }
    }

    // ------------------------------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------------------------------

    /**
     * The release's amount: the sum of each line's qty x agreed unit price, each to the penny (half up). Lines are a release's own
     * (positive) lines.
     *
     * @param list<array<string, mixed>> $lines
     */
    public static function releaseAmount(array $lines): string
    {
        $sum = '0.00';
        foreach ($lines as $l) {
            if ($l['sku_id'] !== null && $l['qty'] !== null && $l['unit_cost'] !== null) {
                $sum = bcadd($sum, CostHints::amount((int) $l['qty'], (string) $l['unit_cost']), 2);
            }
        }
        return $sum;
    }

    /** Whether a decrease with this reason is a write-off: the reason only takes stock down and is also offered for write-offs. */
    public static function isWriteOff(Db $db, string $code): bool
    {
        $r = $db->one('SELECT CAST(direction AS CHAR) AS direction, CAST(applies_to AS CHAR) AS applies_to FROM reason_code WHERE code = ?', [$code]);
        return $r !== null && $r['direction'] === 'decrease' && in_array('write_off', explode(',', (string) $r['applies_to']), true);
    }

    /** @return array<string, mixed>|null the record's stock_op row (null: a record made without one, e.g. a test's) */
    public static function op(Db $db, int $documentId): ?array
    {
        return $db->one('SELECT * FROM stock_op WHERE document_id = ?', [$documentId]);
    }

    /** @param array<string, mixed>|null $op */
    private function checkPlaces(Db $db, Document $doc, ?array $op): void
    {
        if ($doc->warehouseId === null) {
            throw new CwException('warehouse_required', 'say which warehouse', 422, ['field' => 'warehouse']);
        }
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        self::checkPlacesOf($db, $this->kind, $doc->warehouseId, $int($op['location_id'] ?? null), $int($op['to_warehouse_id'] ?? null),
            $int($op['to_location_id'] ?? null), true);
    }

    /**
     * The warehouses and places a record of $kind names, as they are now (the service's early answer when a draft is saved, and
     * validate() at posting): every warehouse active, every place inside its warehouse and switched on; a stock out only from our own
     * stock; a release from another account's warehouse into ours; a transfer between two warehouses of the same owner, or between two
     * places of one warehouse. $complete (posting): a transfer or a release must say where the stock goes; a draft may say it later.
     */
    public static function checkPlacesOf(Db $db, string $kind, int $warehouseId, ?int $locationId, ?int $toWarehouseId, ?int $toLocationId, bool $complete): void
    {
        $from = self::warehouse($db, $warehouseId, 'warehouse');
        if ($kind === 'out' && $from['stock_owner'] !== 'own') {
            throw new CwException('not_own_stock', "{$from['name']} holds another account's stock: it leaves that room by a release, not a stock out", 422,
                ['field' => 'warehouse']);
        }
        if ($kind === 'release' && $from['stock_owner'] !== 'other') {
            throw new CwException('release_from_own', "{$from['name']} holds our own stock: a release comes from another account's warehouse", 422, ['field' => 'warehouse']);
        }
        self::checkLocation($db, $locationId, $warehouseId, 'location');
        if (!in_array($kind, ['transfer', 'release'], true)) {
            return;
        }
        if ($toWarehouseId === null) {
            if ($complete) {
                throw new CwException('to_warehouse_required', 'say where the stock goes', 422, ['field' => 'to_warehouse']);
            }
            return;
        }
        $to = self::warehouse($db, $toWarehouseId, 'to_warehouse');
        self::checkLocation($db, $toLocationId, $toWarehouseId, 'to_location');
        if ($kind === 'release') {
            if ($to['stock_owner'] !== 'own') {
                throw new CwException('release_to_other', "{$to['name']} holds another account's stock: a release goes into our own warehouse", 422, ['field' => 'to_warehouse']);
            }
            return;
        }
        if ($toWarehouseId === $warehouseId) {
            if ($complete && ($locationId === null || $toLocationId === null || $locationId === $toLocationId)) {
                throw new CwException('same_place', 'a move inside one warehouse goes from one place to another: choose both places', 422, ['field' => 'to_location']);
            }
            return;
        }
        if ($from['stock_owner'] !== $to['stock_owner'] || $from['owner_entity'] !== $to['owner_entity']) {
            throw new CwException('owner_differs', "{$from['name']} and {$to['name']} hold different owners' stock: a transfer never changes whose stock it is "
                . '(stock comes from another account by a release)', 422, ['field' => 'to_warehouse']);
        }
    }

    /** @return array{name: string, stock_owner: string, owner_entity: ?string} an active warehouse (422 otherwise) */
    private static function warehouse(Db $db, int $id, string $field): array
    {
        $w = $db->one('SELECT name, is_active, CAST(stock_owner AS CHAR) AS stock_owner, owner_entity FROM warehouse WHERE id = ?', [$id])
            ?? throw new CwException('unknown_warehouse', 'there is no such warehouse', 422, ['field' => $field]);
        if ((int) $w['is_active'] !== 1) {
            throw new CwException('warehouse_inactive', "{$w['name']} is switched off", 422, ['field' => $field]);
        }
        return ['name' => (string) $w['name'], 'stock_owner' => (string) $w['stock_owner'], 'owner_entity' => $w['owner_entity'] === null ? null : (string) $w['owner_entity']];
    }

    private static function checkLocation(Db $db, mixed $locationId, int $warehouseId, string $field): void
    {
        if ($locationId === null) {
            return;
        }
        $l = $db->one('SELECT warehouse_id, name, is_active FROM warehouse_location WHERE id = ?', [(int) $locationId]);
        if ($l === null || (int) $l['warehouse_id'] !== $warehouseId) {
            throw new CwException('location_mismatch', 'the place is not inside that warehouse', 422, ['field' => $field]);
        }
        if ((int) $l['is_active'] !== 1) {
            throw new CwException('location_inactive', "the place {$l['name']} is switched off", 422, ['field' => $field]);
        }
    }

    /**
     * The reasons a record uses (its header's and its lines'), with what the stock rules need.
     *
     * @param list<array<string, mixed>> $lines
     * @return array<string, array{label: string, direction: string, needs_given_to: bool, below_zero: bool}>
     */
    private static function reasons(Db $db, Document $doc, array $lines): array
    {
        $codes = array_values(array_unique(array_filter([$doc->reasonCode, ...array_map(static fn (array $l): ?string => $l['reason_code'], $lines)],
            static fn (?string $c): bool => $c !== null)));
        if ($codes === []) {
            return [];
        }
        $out = [];
        foreach ($db->all('SELECT code, label, CAST(direction AS CHAR) AS direction, needs_given_to, below_zero FROM reason_code WHERE code IN ('
            . implode(', ', array_fill(0, count($codes), '?')) . ')', $codes) as $r) {
            $out[(string) $r['code']] = ['label' => (string) $r['label'], 'direction' => (string) $r['direction'],
                'needs_given_to' => (int) $r['needs_given_to'] === 1, 'below_zero' => (int) $r['below_zero'] === 1];
        }
        return $out;
    }

    /**
     * The first line that would take stock where it may not go, as the refusal (null: none). $booked: the record's stock is already
     * on the balances (post(), which holds them locked); otherwise the balances as they are now (validate()).
     *
     * Per (warehouse, product) the record takes down: a protected product (Stock: strict or stopped) may not end with less available
     * (on hand - reserved) than nothing, unless EVERY line of it that takes that product down has a reason that allows it (a transfer
     * has none); a release never takes more than the other account's room holds, whatever the product.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed>|null $op
     * @param array<string, array{label: string, direction: string, needs_given_to: bool, below_zero: bool}> $reasons
     */
    private function shortfalls(Db $db, Document $doc, array $lines, ?array $op, array $reasons, bool $booked): ?CwException
    {
        if ($this->kind === 'in' || ($this->kind === 'transfer' && $op !== null && (int) ($op['to_warehouse_id'] ?? 0) === $doc->warehouseId)) {
            return null;
        }
        $down = [];
        $allowed = [];
        $firstLine = [];
        foreach ($lines as $l) {
            $q = (int) $l['qty'];
            if ($l['sku_id'] === null || ($this->kind === 'adjust' ? $q >= 0 : $q <= 0)) {
                continue;
            }
            $sku = (int) $l['sku_id'];
            $down[$sku] = ($down[$sku] ?? 0) + abs($q);
            $code = $l['reason_code'] ?? $doc->reasonCode;
            $ok = $code !== null && ($reasons[$code]['below_zero'] ?? false);
            $allowed[$sku] = ($allowed[$sku] ?? true) && $ok;
            $firstLine[$sku] ??= (int) $l['line_no'];
        }
        if ($down === []) {
            return null;
        }
        $skus = array_keys($down);
        $in = implode(', ', array_fill(0, count($skus), '?'));
        $bal = [];
        foreach ($db->all("SELECT sku_id, on_hand, allocated, held FROM stock_balance WHERE warehouse_id = ? AND sku_id IN ({$in})", [$doc->warehouseId, ...$skus]) as $b) {
            $bal[(int) $b['sku_id']] = $b;
        }
        $items = [];
        foreach ($db->all("SELECT id, code, name, CAST(sell_policy AS CHAR) AS sell_policy FROM sku WHERE id IN ({$in})", $skus) as $s) {
            $items[(int) $s['id']] = $s;
        }
        foreach ($down as $sku => $n) {
            $b = $bal[$sku] ?? ['on_hand' => 0, 'allocated' => 0, 'held' => 0];
            $onHand = (int) $b['on_hand'] - ($booked ? 0 : $n);
            $available = $onHand - (int) $b['allocated'] - (int) $b['held'];
            $item = $items[$sku] ?? ['code' => (string) $sku, 'name' => '', 'sell_policy' => 'legacy'];
            $have = $onHand + $n;
            $detail = ['line' => $firstLine[$sku], 'sku_code' => (string) $item['code'], 'have' => $have - (int) $b['allocated'] - (int) $b['held'], 'on_hand' => $have,
                'wanted' => $n];
            if ($this->kind === 'release' && $onHand < 0) {
                return new CwException('not_enough_held', "line {$firstLine[$sku]}: the room holds only {$have} of {$item['code']}, not {$n}", 422, $detail);
            }
            if (in_array((string) $item['sell_policy'], self::PROTECTED, true) && $available < 0 && !($allowed[$sku] ?? false)) {
                return new CwException('below_zero', "line {$firstLine[$sku]}: {$item['code']} would go below zero (" . ($have - (int) $b['allocated'] - (int) $b['held'])
                    . " available, {$n} going out)", 422, $detail);
            }
        }
        return null;
    }

    /** @return array<int, string> warehouse id => code */
    private static function warehouseCodes(Db $db): array
    {
        $out = [];
        foreach ($db->all('SELECT id, code FROM warehouse') as $w) {
            $out[(int) $w['id']] = (string) $w['code'];
        }
        return $out;
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock ?? static fn (): \DateTimeImmutable => Clock::now())();
    }

    /** Midnight of the UK day $t is in, in UTC. */
    public static function ukDayStart(\DateTimeImmutable $t): \DateTimeImmutable
    {
        $uk = $t->setTimezone(new \DateTimeZone('Europe/London'));
        return $uk->setTime(0, 0)->setTimezone(Clock::utc());
    }
}
