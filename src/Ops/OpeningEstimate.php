<?php

declare(strict_types=1);

namespace CW\Ops;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Movements;

/**
 * The opening on_hand ESTIMATE of one site's items (plan §8.1, D40, D40a): the site's own stock
 * figure per listing AS OF a stated moment, negatives as 0, x the link's units_per_item, summed per
 * central item and booked as one `adjustment` per item (actor system:opening_estimate, doc_ref
 * identifying the opening). It is an estimate, not a count: sku.counted_at is never touched, and a
 * count later replaces the figure.
 *
 * NOT included: the site's open paid-not-shipped units. /v1/opening_orders adds those to
 * `allocated` only, so at a site's T0 the opening is rebased to (site stock at T0 + open units)
 * — D40a, D40b: CW\Ops\OpeningRebase plans it, apply() below books it.
 *
 * Which items are booked:
 *   - only listings with status `mapped` contribute; a `quarantined` listing with stock > 0 stops
 *     the run (its link is in doubt, yet its sales move the item: a person decides first);
 *   - an item is left alone when it has been counted (sku / stock_balance counted_at, or a `count`
 *     movement), already carries an opening estimate under ANOTHER doc_ref, or has on_hand
 *     movements recorded before the as-of moment (the site's figure already includes those).
 *     Movements after the as-of moment are fine: the estimate sits under them.
 *   - an item already booked by THIS opening (same doc_ref, compared byte for byte) is skipped when
 *     the booked figure equals the planned one; a different figure stops the whole run.
 * So a re-run, or a resume after a partial run, books only what is missing, and a different file
 * under the same doc_ref is refused. Each item is its own call with the Idempotency-Key
 * "<doc_ref>:<sku_id>", so a failure leaves whole items booked or not, never half of one.
 */
final class OpeningEstimate
{
    public const MOVEMENT_TYPE = 'adjustment';
    public const ACTOR_JOB = 'opening_estimate';
    public const MAX_ITEM_UNITS = 10_000_000;

    public function __construct(private readonly Db $db, private readonly Movements $moves)
    {
    }

    /**
     * @param iterable<array{0: string, 1: int}> $rows  (site variant id, site stock figure as of $asOf)
     * @return array{lines: array<int, int>, report: array<string, mixed>}  lines: sku_id => units still to book
     */
    public function plan(string $channelCode, iterable $rows, string $docRef, \DateTimeImmutable $asOf): array
    {
        $channelId = $this->db->value('SELECT id FROM channel WHERE code = ?', [$channelCode]);
        if ($channelId === null) {
            throw new CwException('unknown_channel', "no channel {$channelCode}", 404);
        }
        $links = [];
        foreach ($this->db->all('SELECT external_variant_id v, sku_id, units_per_item u, status FROM channel_listing WHERE channel_id = ?', [(int) $channelId]) as $l) {
            $links[(string) $l['v']] = $l;
        }

        $report = ['rows' => 0, 'positive_rows' => 0, 'positive_units' => 0,
            'skipped' => ['zero_or_negative' => ['rows' => 0, 'units' => 0], 'no_listing' => ['rows' => 0, 'units' => 0]],
            'not_linked_by_status' => [], 'quarantined_with_stock' => []];
        $seen = [];
        $perSku = [];
        $listingsPerSku = [];
        foreach ($rows as [$variant, $qty]) {
            $report['rows']++;
            if (isset($seen[$variant])) {
                throw new CwException('duplicate_variant', "variant {$variant} appears twice in the input", 422);
            }
            $seen[$variant] = true;
            if ($qty <= 0) {
                $report['skipped']['zero_or_negative']['rows']++;
                $report['skipped']['zero_or_negative']['units'] += $qty;
                continue;
            }
            $report['positive_rows']++;
            $report['positive_units'] += $qty;
            $l = $links[$variant] ?? null;
            if ($l === null) {
                $report['skipped']['no_listing']['rows']++;
                $report['skipped']['no_listing']['units'] += $qty;
                continue;
            }
            if ($l['status'] === 'quarantined') {
                $report['quarantined_with_stock'][] = ['variant' => $variant, 'sku_id' => (int) $l['sku_id'], 'units' => $qty];
                continue;
            }
            if ($l['status'] !== 'mapped' || $l['sku_id'] === null) {
                $s = (string) $l['status'];
                $report['not_linked_by_status'][$s] ??= ['rows' => 0, 'units' => 0];
                $report['not_linked_by_status'][$s]['rows']++;
                $report['not_linked_by_status'][$s]['units'] += $qty;
                continue;
            }
            $sku = (int) $l['sku_id'];
            $perSku[$sku] = ($perSku[$sku] ?? 0) + $qty * (int) $l['u'];
            $listingsPerSku[$sku] = ($listingsPerSku[$sku] ?? 0) + 1;
        }
        if ($report['rows'] === 0) {
            throw new CwException('empty_input', 'the input has no data rows', 422);
        }
        if ($report['quarantined_with_stock'] !== []) {
            throw new CwException('quarantined_listings', sprintf('%d quarantined listing(s) have stock (%d units); decide their links first',
                count($report['quarantined_with_stock']), array_sum(array_column($report['quarantined_with_stock'], 'units'))), 409,
                ['listings' => array_slice($report['quarantined_with_stock'], 0, 50)]);
        }
        foreach ($perSku as $sku => $units) {
            if ($units > self::MAX_ITEM_UNITS) {
                throw new CwException('too_many_units', "item {$sku} would get {$units} units (max " . self::MAX_ITEM_UNITS . ')', 422);
            }
        }

        $asOfDb = Clock::db($asOf);
        $actor = 'system:' . self::ACTOR_JOB;
        $blocked = [];
        $booked = [];
        foreach (array_chunk(array_keys($perSku), 5000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->db->all(
                "SELECT id sku_id, 'counted' why FROM sku WHERE id IN ({$in}) AND counted_at IS NOT NULL
                 UNION SELECT sku_id, 'counted' FROM stock_balance WHERE sku_id IN ({$in}) AND counted_at IS NOT NULL
                 UNION SELECT sku_id, 'counted' FROM stock_ledger WHERE sku_id IN ({$in}) AND movement_type = 'count'
                 UNION SELECT sku_id, 'earlier_opening' FROM stock_ledger WHERE sku_id IN ({$in}) AND actor = ?
                       AND doc_ref COLLATE utf8mb4_0900_bin <> ?
                 UNION SELECT sku_id, 'moved_before_as_of' FROM stock_ledger WHERE sku_id IN ({$in}) AND bucket = 'on_hand'
                       AND actor <> ? AND created_at < ?",
                [...$chunk, ...$chunk, ...$chunk, ...$chunk, $actor, $docRef, ...$chunk, $actor, $asOfDb],
            ) as $r) {
                $blocked[(int) $r['sku_id']][(string) $r['why']] = true;
            }
            foreach ($this->db->all(
                "SELECT sku_id, SUM(qty_delta) q FROM stock_ledger WHERE sku_id IN ({$in}) AND actor = ? AND bucket = 'on_hand'
                   AND doc_ref COLLATE utf8mb4_0900_bin = ? GROUP BY sku_id",
                [...$chunk, $actor, $docRef],
            ) as $r) {
                $booked[(int) $r['sku_id']] = (int) $r['q'];
            }
        }

        $report['skipped']['has_stock_history'] = ['items' => 0, 'units' => 0, 'why' => []];
        foreach ($blocked as $sku => $why) {
            if (isset($booked[$sku])) {
                continue; // booked by this opening already: judged below
            }
            $report['skipped']['has_stock_history']['items']++;
            $report['skipped']['has_stock_history']['units'] += $perSku[$sku];
            foreach (array_keys($why) as $w) {
                $report['skipped']['has_stock_history']['why'][$w] = ($report['skipped']['has_stock_history']['why'][$w] ?? 0) + 1;
            }
            unset($perSku[$sku]);
        }
        $report['already_booked'] = ['items' => 0, 'units' => 0];
        $conflicts = [];
        foreach ($booked as $sku => $q) {
            if (!isset($perSku[$sku])) {
                $conflicts[] = ['sku_id' => $sku, 'booked' => $q, 'planned' => 0];
            } elseif ($perSku[$sku] !== $q) {
                $conflicts[] = ['sku_id' => $sku, 'booked' => $q, 'planned' => $perSku[$sku]];
            } else {
                $report['already_booked']['items']++;
                $report['already_booked']['units'] += $q;
                unset($perSku[$sku]);
            }
        }
        if ($conflicts !== []) {
            throw new CwException('opening_conflict', sprintf('%d item(s) were booked by this opening with other figures than this input: '
                . 'it is not the same opening (use another doc_ref only for a deliberate new opening)', count($conflicts)), 409,
                ['items' => array_slice($conflicts, 0, 50)]);
        }
        ksort($perSku);
        $report['items'] = count($perSku);
        $report['units'] = array_sum($perSku);
        $report['items_from_several_listings'] = count(array_filter($listingsPerSku, static fn (int $n): bool => $n > 1));
        return ['lines' => $perSku, 'report' => $report];
    }

    /**
     * Books the plan, one item per call (Idempotency-Key "<doc_ref>:<sku_id>"). The rebase (D40b) books its
     * signed plan through here too, with $docType OpeningRebase::DOC_TYPE; booked_units is then the net.
     *
     * @param array<int, int> $lines  sku_id => units (signed for a rebase; never 0)
     * @param ?\Closure(int, int): void $progress  called with (items done, items total)
     * @return array{booked_items: int, booked_units: int, replayed_items: int}
     */
    public function apply(Caller $caller, array $lines, string $docRef, string $note, string $warehouse = 'MAIN', ?\Closure $progress = null,
        string $docType = 'opening_estimate'): array
    {
        $out = ['booked_items' => 0, 'booked_units' => 0, 'replayed_items' => 0];
        $n = 0;
        foreach ($lines as $sku => $units) {
            $r = $this->moves->record($caller, ['type' => self::MOVEMENT_TYPE, 'doc_ref' => $docRef, 'doc_type' => $docType,
                'warehouse' => $warehouse, 'note' => $note, 'lines' => [['sku_id' => (int) $sku, 'qty' => (int) $units, 'line_index' => 0]]],
                $docRef . ':' . $sku);
            if (!$r->ok()) {
                throw new CwException((string) ($r->body['error'] ?? 'opening_failed'),
                    sprintf('item %d (after %d booked): %s', $sku, $out['booked_items'], (string) ($r->body['message'] ?? json_encode($r->body))), $r->status);
            }
            if ($r->replayed) {
                $out['replayed_items']++;
            } else {
                $out['booked_items']++;
                $out['booked_units'] += $units;
            }
            $n++;
            if ($progress !== null && ($n % 1000 === 0 || $n === count($lines))) {
                $progress($n, count($lines));
            }
        }
        return $out;
    }
}
