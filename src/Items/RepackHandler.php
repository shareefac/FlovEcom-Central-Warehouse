<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Documents\SizeApproval;
use CW\Movements;
use CW\Settings;
use CW\StockOps\CostHints;
use CW\StockOps\StockOpHandler;

/**
 * What a Repack (RPK, pack B; owner answer Q3; docs/decisions.md PB9-PB12) does when it is posted or cancelled: it breaks packs of a
 * pre-packed multipack item into singles, or makes packs from singles, with the N of the pack item's definition (item_pack). Its
 * header (`repack`) names the definition, the way (`break`, `make`) and how many packs; its two lines are exactly
 *
 *   break   1: the pack item, -packs             2: the single item, +packs x N
 *   make    1: the single item, -packs x N       2: the pack item, +packs
 *
 * in the record's warehouse. validate() refuses anything else (422 repack_lines), a definition switched off since (422 pack_off), and
 * a line that would take a protected item (sell policy strict or stopped, StockOpHandler::PROTECTED) below nothing available (422
 * below_zero: no reason can allow it, as for a transfer). post() books ONE Movements::bookForDocument call of `adjustment` rows (the
 * signed movement a stock in already uses; Stock.php and Movements.php are not changed), so on hand moves per item by exactly its
 * line: the units taken out equal packs x N singles' worth on both sides. While items.repack_carry_cost is on (on by default) the
 * line that comes in carries the cost of what went out: the average cost so far of the item taken apart (CostHints::average) times
 * its units, divided by the units that come in (6 decimals); with no average known, or with the setting off, no cost (it is valued at
 * the average cost later, IM8). The cost is read after the booking's balances and all its item value clocks are locked, so receipts
 * at any warehouse finish before it is frozen. It checks the shortfall again on the balances it then holds locked. A cancellation is the document
 * base's exact negation of the ledger rows (Movements::reverseDocument): reverse() copies the header for it.
 *
 * Approvals: none blocks by default. The reviewer check after posting and the OK first for a big record (size()) are the Approval
 * rules page's (document_type RPK: none and off in 0031).
 */
final class RepackHandler implements DocumentHandler, SizeApproval
{
    public const TYPE = 'RPK';
    public const DIRECTIONS = ['break', 'make'];
    /** The cost setting (0031) and its value when the setting is missing. */
    public const COST_SETTING = 'items.repack_carry_cost';
    public const COST_DEFAULT = true;

    private readonly Movements $moves;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock CW's clock (the ledger's times) */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->moves = new Movements($db, null, $clock);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * The two lines of a repack (the order of the class docblock).
     *
     * @return list<array{sku_id: int, qty: int}>
     */
    public static function lines(string $direction, int $packSku, int $singleSku, int $packs, int $units): array
    {
        return $direction === 'break'
            ? [['sku_id' => $packSku, 'qty' => -$packs], ['sku_id' => $singleSku, 'qty' => $packs * $units]]
            : [['sku_id' => $singleSku, 'qty' => -$packs * $units], ['sku_id' => $packSku, 'qty' => $packs]];
    }

    /** @return array<string, mixed>|null the record's repack header */
    public static function header(Db $db, int $documentId): ?array
    {
        return $db->one('SELECT r.document_id, r.item_pack_id, r.direction, r.packs, r.units_per_pack, r.pack_sku_id, r.single_sku_id, p.is_active, p.units '
            . 'FROM repack r JOIN item_pack p ON p.id = r.item_pack_id WHERE r.document_id = ?', [$documentId]);
    }

    public function validate(Db $db, Document $doc, array $lines): void
    {
        $h = self::header($db, $doc->id) ?? throw new CwException('repack_lines', 'a repack names its pack item: start it from Stock > Repack', 422);
        if ((int) $h['is_active'] !== 1) {
            throw new CwException('pack_off', 'this pack item was switched off: switch it on again on Products > Multipacks, or cancel the draft', 422);
        }
        if ($doc->warehouseId === null) {
            throw new CwException('warehouse_required', 'say which warehouse', 422, ['field' => 'warehouse']);
        }
        $w = $db->one('SELECT name, is_active FROM warehouse WHERE id = ?', [$doc->warehouseId]);
        if ($w === null || (int) $w['is_active'] !== 1) {
            throw new CwException('warehouse_inactive', 'the warehouse is switched off', 422, ['field' => 'warehouse']);
        }
        $want = self::lines((string) $h['direction'], (int) $h['pack_sku_id'], (int) $h['single_sku_id'], (int) $h['packs'], (int) $h['units_per_pack']);
        $have = array_map(static fn (array $l): array => ['sku_id' => $l['sku_id'] === null ? null : (int) $l['sku_id'], 'qty' => $l['qty'] === null ? null : (int) $l['qty']],
            $lines);
        $other = array_filter($lines, static fn (array $l): bool => ($l['warehouse_id'] !== null && (int) $l['warehouse_id'] !== $doc->warehouseId)
            || $l['unit_cost'] !== null || $l['amount'] !== null || $l['reason_code'] !== null);
        if ($have !== $want || $other !== [] || (int) $h['units_per_pack'] !== (int) $h['units']) {
            throw new CwException('repack_lines', 'a repack has two lines: what is taken apart and what is made of it, in the right numbers', 422);
        }
        $short = $this->shortfall($db, $doc, $lines, false);
        if ($short !== null) {
            throw $short;
        }
    }

    /** No blocking approval: a repack puts back nothing that did not leave (its two sides are the same goods). */
    public function approvalUnits(Db $db, Document $doc, array $lines): int
    {
        return 0;
    }

    /** SizeApproval: the units of both lines, worth the average cost so far of what is taken apart (0 when not known). */
    public function size(Db $db, Document $doc, array $lines): array
    {
        $units = 0;
        $value = '0';
        $out = null;
        foreach ($lines as $l) {
            $units += abs((int) $l['qty']);
            if ((int) $l['qty'] < 0) {
                $out = $l;
            }
        }
        if ($out !== null && $out['sku_id'] !== null) {
            $avg = (new CostHints($db))->average([(int) $out['sku_id']])[(int) $out['sku_id']] ?? null;
            if ($avg !== null) {
                $value = bcmul((string) abs((int) $out['qty']), $avg, 6);
            }
        }
        return ['units' => $units, 'value' => CostHints::wholePounds($value)];
    }

    public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
    {
        $wh = (string) ($db->value('SELECT code FROM warehouse WHERE id = ?', [$doc->warehouseId]) ?? throw new \LogicException("repack {$doc->id} has no warehouse"));
        $moveLines = [];
        foreach ($lines as $l) {
            $q = (int) $l['qty'];
            $moveLines[] = ['document_line' => (int) $l['line_no'], 'sku_id' => (int) $l['sku_id'], 'qty' => $q];
        }
        $this->moves->bookForDocument($caller, ['document_id' => $doc->id, 'doc_ref' => (string) $doc->number], $opKey,
            [['type' => 'adjustment', 'warehouse' => $wh, 'lines' => $moveLines]], costs: function () use ($db, $lines): array {
                $cost = $this->carriedCost($db, $lines);
                $out = [];
                foreach ($lines as $l) {
                    if ((int) $l['qty'] > 0 && $cost !== null) {
                        $out[(int) $l['line_no']] = $cost;
                    }
                }
                return $out;
            });
        $short = $this->shortfall($db, $doc, $lines, true);
        if ($short !== null) {
            throw $short;
        }
        return array_sum(array_map(static fn (array $l): int => abs((int) $l['qty']), $lines));
    }

    public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
    {
        $db->exec('INSERT INTO repack (document_id, item_pack_id, direction, packs, units_per_pack, pack_sku_id, single_sku_id) '
            . 'SELECT ?, item_pack_id, direction, packs, units_per_pack, pack_sku_id, single_sku_id FROM repack WHERE document_id = ?', [$reversal->id, $original->id]);
    }

    /**
     * The unit cost the incoming line carries: the average cost so far of the item taken apart x its units / the units made, to 6
     * decimals (half up); null when the setting is off or no average is known.
     *
     * @param list<array<string, mixed>> $lines
     */
    public function carriedCost(Db $db, array $lines): ?string
    {
        if (!Settings::flag($db, self::COST_SETTING, self::COST_DEFAULT)) {
            return null;
        }
        $out = null;
        $in = null;
        foreach ($lines as $l) {
            if ((int) $l['qty'] < 0) {
                $out = $l;
            } elseif ((int) $l['qty'] > 0) {
                $in = $l;
            }
        }
        if ($out === null || $in === null) {
            return null;
        }
        $avg = (new CostHints($db))->average([(int) $out['sku_id']])[(int) $out['sku_id']] ?? null;
        if ($avg === null) {
            return null;
        }
        $total = bcmul((string) abs((int) $out['qty']), $avg, 7);
        $unit = bcdiv($total, (string) (int) $in['qty'], 7);
        return Movements::normaliseCost(bcadd($unit, '0.0000005', 6), 'unit_cost');
    }

    /**
     * The line that would take (or, $booked, took) a protected item below nothing available in the record's warehouse, as the
     * refusal; null when none does. Unprotected (legacy) items may go below zero, as on every other stock record (a count review opens).
     *
     * @param list<array<string, mixed>> $lines
     */
    private function shortfall(Db $db, Document $doc, array $lines, bool $booked): ?CwException
    {
        foreach ($lines as $l) {
            $q = (int) $l['qty'];
            if ($q >= 0 || $l['sku_id'] === null) {
                continue;
            }
            $sku = (int) $l['sku_id'];
            $item = $db->one('SELECT code, CAST(sell_policy AS CHAR) AS sell_policy FROM sku WHERE id = ?', [$sku]);
            if ($item === null || !in_array((string) $item['sell_policy'], StockOpHandler::PROTECTED, true)) {
                continue;
            }
            $b = $db->one('SELECT on_hand, allocated, held FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [$doc->warehouseId, $sku])
                ?? ['on_hand' => 0, 'allocated' => 0, 'held' => 0];
            $onHand = (int) $b['on_hand'] + ($booked ? 0 : $q);
            $available = $onHand - (int) $b['allocated'] - (int) $b['held'];
            if ($available < 0) {
                $have = $available - $q;
                return new CwException('below_zero', "line {$l['line_no']}: {$item['code']} would go below zero ({$have} available, " . abs($q) . ' going out)', 422,
                    ['line' => (int) $l['line_no'], 'sku_code' => (string) $item['code'], 'have' => $have, 'wanted' => abs($q)]);
            }
        }
        return null;
    }
}
